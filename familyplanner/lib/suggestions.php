<?php
// "Attent zijn": suggestions worked out from the planner's own data.
// Each suggestion: icon, text (html), actions (list of [label, href] or [label, null, newEventPreset]), prio (lower = first).
require_once __DIR__ . '/friends.php';

function suggestion(string $icon, string $text, array $actions = [], int $prio = 50, string $key = ''): array
{
    return ['icon' => $icon, 'text' => $text, 'actions' => $actions, 'prio' => $prio, 'key' => $key ?: md5($text)];
}

function build_suggestions(): array
{
    $out = [];
    $today = today();
    $now = date('Y-m-d H:i:s');

    // 1. Birthdays in the next 2 weeks without card / gift ticked off
    $year = (int) date('Y');
    $checks = [];
    foreach (db()->query('SELECT subject, year, item FROM fp_birthday_checks WHERE year >= ' . ($year - 1)) as $r) {
        $checks[$r['subject'] . '@' . $r['year']][$r['item']] = true;
    }
    foreach (load_birthdays($today, date('Y-m-d', strtotime('+15 day'))) as $b) {
        $done = $checks[$b['subject'] . '@' . substr($b['date'], 0, 4)] ?? [];
        if (!empty($done['GIFT']) || !empty($done['CARD'])) {
            continue;
        }
        $days = days_until($b['date']);
        $own = $b['kind'] === 'member';
        $text = '<b>' . e($b['name']) . '</b> ' . ($b['age'] !== null ? 'wordt ' . $b['age'] . ' ' : 'is jarig ') . in_days_label($days) . ' (' . e(format_date_short($b['date'])) . ').'
            . ($own ? ' Feestje, taart of versiering regelen?' : ' Kaartje, appje of cadeautje?');
        $out[] = suggestion($days <= 2 ? '🎂' : '🎁', $text, [
            ['Cadeau-ideeën', 'verjaardagen.php#' . $b['subject']],
            ['＋ Taak', 'taken.php?prefill=' . urlencode(($own ? 'Verjaardag voorbereiden: ' : 'Cadeau / kaartje voor ') . $b['name'])],
        ], $days <= 3 ? 5 : 15);
    }

    // 2. Playdates: return the invitation, and friends not seen for a long time
    foreach (children() as $kid) {
        $stats = playdate_stats((int) $kid['id']);
        $friends = friends_of((int) $kid['id'], true);
        $byId = array_column($friends, null, 'id');
        foreach ($stats as $cid => $s) {
            if (!isset($byId[$cid]) && ($c = contact_by_id($cid))) {
                $byId[$cid] = $c;
            }
            if (!isset($byId[$cid])) {
                continue;
            }
            $name = contact_name($byId[$cid], false);
            if ($s['away'] > $s['home'] && $s['planned'] === 0) {
                $out[] = suggestion('🔁', e($kid['name']) . ' speelde ' . $s['away'] . '× bij <b>' . e($name) . '</b>' . ($s['home'] ? ' en ' . $name . ' ' . $s['home'] . '× bij jullie' : ', ' . e($name) . ' nog niet bij jullie') . '. Tijd om terug uit te nodigen?', [
                    ['🧸 Plan speelafspraak', null, ['type' => 'PLAYDATE', 'host' => 'HOME', 'members' => [(int) $kid['id']], 'contacts' => [contact_json_small($byId[$cid])], 'title' => 'Spelen met ' . $name]],
                ], 20, 'return-' . $kid['id'] . '-' . $cid);
            }
        }
        // Good friends not played with for 6+ weeks
        foreach ($friends as $f) {
            if (empty($f['is_favorite'])) {
                continue;
            }
            $s = $stats[(int) $f['id']] ?? null;
            if ($s && ($s['planned'] || ($s['last'] && $s['last'] > date('Y-m-d', strtotime('-42 day'))))) {
                continue;
            }
            $out[] = suggestion('💛', e($kid['name']) . ' heeft ' . ($s && $s['last'] ? 'sinds ' . e(format_date_short($s['last'])) : 'dit schooljaar nog') . ' niet met <b>' . e(contact_name($f, false)) . '</b> gespeeld.', [
                ['🧸 Plan speelafspraak', null, ['type' => 'PLAYDATE', 'host' => 'HOME', 'members' => [(int) $kid['id']], 'contacts' => [contact_json_small($f)], 'title' => 'Spelen met ' . contact_name($f, false)]],
            ], 30, 'miss-' . $kid['id'] . '-' . $f['id']);
        }
    }

    // 3. Vriendjesboek away too long / a friend's book waiting to be filled in
    foreach (open_friendbooks() as $fb) {
        $m = member((int) $fb['member_id']);
        $days = -days_until($fb['given_on']);
        $who = $fb['contact_id'] ? contact_name($fb, false) : 'iemand';
        if ($fb['direction'] === 'OUT' && $days >= 14) {
            $out[] = suggestion('📒', 'Het vriendjesboek van ' . e($m['name'] ?? '') . ' is al ' . $days . ' dagen bij <b>' . e($who) . '</b>. Even vragen of het terug kan?', [['Vriendjesboek', 'vriendjes.php?kid=' . (int) $fb['member_id'] . '#boek']], 25);
        }
        if ($fb['direction'] === 'IN' && $days >= 5) {
            $out[] = suggestion('✏️', 'Het vriendjesboek van <b>' . e($who) . '</b> ligt al ' . $days . ' dagen bij jullie. Samen met ' . e($m['name'] ?? '') . ' invullen en teruggeven?', [['Vriendjesboek', 'vriendjes.php?kid=' . (int) $fb['member_id'] . '#boek']], 20);
        }
    }

    // 4. Parents' own social life: last and next evening out
    $parentIds = array_map('intval', array_keys(parents()));
    $social = load_events(date('Y-m-d', strtotime('-120 day')), date('Y-m-d', strtotime('+30 day')), ['types' => SOCIAL_TYPES]);
    $lastDate = null;
    $nextDate = null;
    foreach ($social as $ev) {
        if (!array_intersect($parentIds, $ev['members'])) {
            continue;
        }
        if ($ev['start_at'] < $now) {
            $lastDate = max($lastDate ?? '', substr($ev['start_at'], 0, 10));
        } elseif (!$nextDate) {
            $nextDate = substr($ev['start_at'], 0, 10);
        }
    }
    if (!$nextDate) {
        $since = $lastDate ? -days_until($lastDate) : null;
        $out[] = suggestion('💑', ($since ? 'Jullie laatste avondje uit was ' . $since . ' dagen geleden.' : 'Er staat nog geen avondje uit voor jullie tweeën gepland.') . ' Tijd voor een date, etentje of borrel met vrienden?', [
            ['💑 Plan een avond', null, ['type' => 'PARENTS', 'members' => $parentIds, 'title' => 'Avondje uit']],
            ['Date-ideeën', 'ideeen.php?cat=DATE'],
        ], $since && $since > 45 ? 18 : 40, 'date-night');
    }

    // 5. Evenings out without a babysitter
    $upcoming = load_events($today, date('Y-m-d', strtotime('+21 day')));
    $babysits = array_filter($upcoming, function ($ev) {
        return $ev['type'] === 'BABYSIT';
    });
    foreach ($upcoming as $ev) {
        if ($ev['type'] !== 'PARENTS' || $ev['all_day'] || (int) substr($ev['start_at'], 11, 2) < 17) {
            continue;
        }
        $parentsGoing = array_intersect($parentIds, $ev['members']);
        if (count($parentsGoing) < count($parentIds)) {
            continue;
        }
        $covered = false;
        foreach ($babysits as $b) {
            if ($b['start_at'] < $ev['end_at'] && $b['end_at'] > $ev['start_at']) {
                $covered = true;
            }
        }
        if (!$covered) {
            $out[] = suggestion('🍼', 'Oppas geregeld voor <b>' . e($ev['title']) . '</b> op ' . e(format_date_short($ev['start_at'])) . '?', [
                ['🍼 Oppas plannen', null, ['type' => 'BABYSIT', 'start' => str_replace(' ', 'T', substr($ev['start_at'], 0, 16)), 'end' => str_replace(' ', 'T', substr($ev['end_at'], 0, 16)), 'title' => 'Oppas', 'members' => array_map('intval', array_keys(children()))]],
                ['Oppassers', 'oppas.php'],
            ], 8, 'sitter-' . $ev['id'] . $ev['occ']);
        }
    }

    // 6. Who brings / picks up? Kids' events away from home without a driver
    foreach ($upcoming as $ev) {
        if ($ev['start_at'] < $now || $ev['start_at'] > date('Y-m-d H:i:s', strtotime('+7 day')) || $ev['all_day']) {
            continue;
        }
        $kids = array_intersect(array_map('intval', array_keys(children())), $ev['members']);
        if (!$kids || $ev['drop_member_id'] || $ev['pickup_member_id'] || $ev['host'] === 'HOME' || !in_array($ev['type'], ['PLAYDATE', 'PARTY', 'SPORT', 'ACTIVITY'], true)) {
            continue;
        }
        $names = implode(' & ', array_map(function ($id) {
            return member($id)['name'];
        }, $kids));
        $out[] = suggestion('🚗', 'Wie brengt en haalt ' . e($names) . ' bij <b>' . e($ev['title']) . '</b> (' . e(day_label($ev['start_at'])) . ' ' . substr($ev['start_at'], 11, 5) . ')?', [
            ['Regelen', 'event.php?id=' . $ev['id'] . '&occ=' . $ev['occ'] . '#bewerken'],
        ], 12, 'drive-' . $ev['id'] . $ev['occ']);
    }

    // 7. Family not seen for a long time
    $familyStmt = db()->query("SELECT c.*, (SELECT MAX(e.start_at) FROM fp_events e JOIN fp_event_contacts ec ON ec.event_id = e.id WHERE ec.contact_id = c.id AND e.start_at <= NOW()) AS last_seen,
        (SELECT MIN(e.start_at) FROM fp_events e JOIN fp_event_contacts ec ON ec.event_id = e.id WHERE ec.contact_id = c.id AND e.start_at > NOW()) AS next_seen
        FROM fp_contacts c WHERE c.relation = 'FAMILY' AND c.is_favorite = 1");
    foreach ($familyStmt as $c) {
        if ($c['next_seen'] || ($c['last_seen'] && $c['last_seen'] > date('Y-m-d', strtotime('-45 day')))) {
            continue;
        }
        $out[] = suggestion('👵', ($c['last_seen'] ? 'Jullie zagen <b>' . e(contact_name($c, false)) . '</b> voor het laatst op ' . e(format_date_short($c['last_seen'])) . '.' : 'Nog geen bezoek aan <b>' . e(contact_name($c, false)) . '</b> in de agenda.') . ' Even langsgaan of bellen?', [
            ['👵 Plan bezoek', null, ['type' => 'FAMILY', 'members' => array_map('intval', array_keys(members())), 'contacts' => [contact_json_small($c)], 'title' => 'Bezoek ' . contact_name($c, false)]],
        ], 35, 'family-' . $c['id']);
    }

    // 8. Empty weekend coming up
    $sat = date('Y-m-d', strtotime('saturday this week', strtotime($today)));
    if ($sat < $today) {
        $sat = date('Y-m-d', strtotime("$sat +7 day"));
    }
    $weekend = load_events($sat, date('Y-m-d', strtotime("$sat +2 day")));
    if (!$weekend) {
        $idea = db()->query("SELECT * FROM fp_ideas WHERE category = 'OUTING' AND done_at IS NULL ORDER BY RAND() LIMIT 1")->fetch();
        $out[] = suggestion('🌤️', 'Het weekend van ' . e(format_date_short($sat)) . ' is nog leeg.' . ($idea ? ' Idee: <b>' . e($idea['title']) . '</b>' : ''), [
            ['🎡 Plan uitje', null, ['type' => 'OUTING', 'members' => array_map('intval', array_keys(members())), 'start' => $sat . 'T10:00', 'end' => $sat . 'T16:00', 'title' => $idea ? $idea['title'] : '']],
            ['Meer ideeën', 'ideeen.php?cat=OUTING'],
        ], 45, 'weekend');
    }

    // 9. Money still to pay
    $unpaid = db()->query("SELECT id, title, cost, start_at FROM fp_events WHERE cost > 0 AND paid = 0 AND start_at < NOW() ORDER BY start_at")->fetchAll();
    if ($unpaid) {
        $sum = array_sum(array_column($unpaid, 'cost'));
        $out[] = suggestion('💶', 'Nog te betalen: <b>' . e(money((float) $sum)) . '</b> (' . e(implode(', ', array_slice(array_column($unpaid, 'title'), 0, 3))) . (count($unpaid) > 3 ? '…' : '') . ').', [['Bekijken', 'oppas.php#betalen']], 22, 'unpaid');
    }

    // 10. Overdue tasks
    $late = (int) db()->query("SELECT COUNT(*) FROM fp_tasks WHERE done_at IS NULL AND due_date < CURDATE()")->fetchColumn();
    if ($late) {
        $out[] = suggestion('⏰', $late . ($late === 1 ? ' taak is' : ' taken zijn') . ' over de datum.', [['Naar de taken', 'taken.php']], 10, 'late-tasks');
    }

    // 11. A small attentive idea of the week
    $week = (int) date('W');
    $att = db()->query("SELECT * FROM fp_ideas WHERE category IN ('ATTENTION','ENGAGEMENT') AND done_at IS NULL ORDER BY id")->fetchAll();
    if ($att) {
        $pick = $att[$week % count($att)];
        $out[] = suggestion(IDEA_CATEGORIES[$pick['category']][1], 'Tip van de week: <b>' . e($pick['title']) . '</b>' . ($pick['description'] ? ' · ' . e(excerpt($pick['description'], 90)) : ''), [['Meer ideeën', 'ideeen.php?cat=' . $pick['category']]], 60, 'tip');
    }

    usort($out, function ($a, $b) {
        return $a['prio'] <=> $b['prio'];
    });
    return $out;
}

function contact_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM fp_contacts WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function contact_json_small(array $c): array
{
    return ['id' => (int) $c['id'], 'name' => contact_name($c, false), 'fullName' => contact_name($c), 'photo' => $c['photo'], 'color' => name_color(contact_name($c, false)), 'rsvp' => ''];
}

function suggestion_html(array $s): string
{
    $html = '<div class="suggest"><div class="s-icon">' . $s['icon'] . '</div><div class="grow"><div>' . $s['text'] . '</div>';
    if ($s['actions']) {
        $html .= '<div class="s-actions">';
        foreach ($s['actions'] as $a) {
            if (!empty($a[2])) {
                $html .= '<button type="button" class="btn small soft" data-new-event="' . e(json_encode($a[2], JSON_UNESCAPED_UNICODE)) . '">' . e($a[0]) . '</button>';
            } else {
                $html .= '<a class="btn small secondary" href="' . e($a[1]) . '">' . e($a[0]) . '</a>';
            }
        }
        $html .= '</div>';
    }
    return $html . '</div></div>';
}
