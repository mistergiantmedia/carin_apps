<?php
// Friend families: friendships (fp_family_links), what each family shares with a friend family
// (fp_family_shares) and links between a contact here and the real child/parent in the friend family
// (fp_contact_links). Every read from another family's tables goes through with_family() and is only
// done when that family shares it with us. Keep PHP 7.4 compatible.

const SHARE_OPTIONS = [
    'PROFILE' => ['👨‍👩‍👧', 'Namen, foto’s en adres van ons gezin', 'Nodig om jullie kinderen aan hun vriendjes te koppelen'],
    'BIRTHDAYS' => ['🎂', 'Verjaardagen', 'Zodat zij jullie verjaardagen in hun agenda zien'],
    'CLUBS' => ['🎯', 'Clubjes en vaste week van de kinderen', 'Zodat zij zien wanneer jullie kinderen kunnen spelen'],
    'PLAYDATES' => ['🧸', 'Speelafspraken met hun kinderen', 'Staan dan ook in hún agenda (tijd, plek, wie brengt)'],
    'CONTACT' => ['📞', 'Telefoon, e-mail en allergieën / dieet', 'Handig bij spelen, logeren en feestjes'],
    'EVENTS' => ['📅', 'Afspraken die jullie zelf delen', 'Per afspraak aan te vinken bij “Delen met”'],
];

function current_family_id(): int
{
    $u = current_user();
    return $u ? (int) $u['family_id'] : 0;
}

/** Run $fn against another family's tables and switch back afterwards. */
function with_family(int $familyId, callable $fn)
{
    $previous = \Familie\FamilyPDO::$prefix;
    use_family($familyId);
    try {
        return $fn();
    } finally {
        \Familie\FamilyPDO::$prefix = $previous;
    }
}

function family_name(int $familyId): string
{
    $stmt = db()->prepare('SELECT name FROM fp_families WHERE id = ?');
    $stmt->execute([$familyId]);
    return (string) $stmt->fetchColumn();
}

/** Accepted friend families of the current family: id => ['id', 'name', 'link_id', 'since']. */
function friend_families(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $me = current_family_id();
    $stmt = db()->prepare("SELECT l.id AS link_id, l.accepted_at, IF(l.family_a = ?, l.family_b, l.family_a) AS fid, f.name, f.photo
        FROM fp_family_links l JOIN fp_families f ON f.id = IF(l.family_a = ?, l.family_b, l.family_a)
        WHERE (l.family_a = ? OR l.family_b = ?) AND l.status = 'ACCEPTED' AND f.status = 'ACTIVE' ORDER BY f.name");
    $stmt->execute([$me, $me, $me, $me]);
    $cache = [];
    foreach ($stmt as $r) {
        $cache[(int) $r['fid']] = ['id' => (int) $r['fid'], 'name' => $r['name'], 'photo' => $r['photo'], 'link_id' => (int) $r['link_id'], 'since' => $r['accepted_at']];
    }
    return $cache;
}

/** Pending invitations: incoming (for us to answer) and outgoing (waiting for them). */
function friend_requests(): array
{
    $me = current_family_id();
    $stmt = db()->prepare("SELECT l.*, IF(l.family_a = ?, l.family_b, l.family_a) AS fid, f.name, f.photo FROM fp_family_links l
        JOIN fp_families f ON f.id = IF(l.family_a = ?, l.family_b, l.family_a)
        WHERE (l.family_a = ? OR l.family_b = ?) AND l.status = 'PENDING' ORDER BY l.created_at DESC");
    $stmt->execute([$me, $me, $me, $me]);
    $out = ['in' => [], 'out' => []];
    foreach ($stmt as $r) {
        $out[(int) $r['requested_by'] === $me ? 'out' : 'in'][] = $r;
    }
    return $out;
}

/** Invite the family of the person with this e-mail address. Returns a message for the user. */
function request_friendship(string $email): string
{
    $me = current_family_id();
    $stmt = db()->prepare("SELECT u.family_id FROM fp_users u JOIN fp_families f ON f.id = u.family_id AND f.status = 'ACTIVE' WHERE u.email = ?");
    $stmt->execute([strtolower(trim($email))]);
    $other = (int) $stmt->fetchColumn();
    // The same answer whether or not the address exists, so nobody can find out who uses the planner
    $answer = 'Als dit e-mailadres bij een gezin in de Familie Planner hoort, krijgen ze jullie uitnodiging te zien.';
    if (!$other || $other === $me) {
        return $answer;
    }
    [$a, $b] = $me < $other ? [$me, $other] : [$other, $me];
    db()->prepare('INSERT IGNORE INTO fp_family_links (family_a, family_b, requested_by) VALUES (?, ?, ?)')->execute([$a, $b, $me]);
    return $answer;
}

function link_row(int $linkId): ?array
{
    $me = current_family_id();
    $stmt = db()->prepare('SELECT * FROM fp_family_links WHERE id = ? AND (family_a = ? OR family_b = ?)');
    $stmt->execute([$linkId, $me, $me]);
    return $stmt->fetch() ?: null;
}

function accept_friendship(int $linkId): void
{
    $l = link_row($linkId);
    if ($l && $l['status'] === 'PENDING' && (int) $l['requested_by'] !== current_family_id()) {
        db()->prepare("UPDATE fp_family_links SET status = 'ACCEPTED', accepted_at = NOW() WHERE id = ?")->execute([$linkId]);
    }
}

/** Decline an invitation or end a friendship: removes shares, links and shared events both ways. */
function end_friendship(int $linkId): void
{
    $l = link_row($linkId);
    if (!$l) {
        return;
    }
    $a = (int) $l['family_a'];
    $b = (int) $l['family_b'];
    if ($l['status'] === 'ACCEPTED') {
        foreach ([[$a, $b], [$b, $a]] as [$owner, $friend]) {
            $stmt = db()->prepare('SELECT contact_id FROM fp_contact_links WHERE family_id = ? AND other_family = ?');
            $stmt->execute([$owner, $friend]);
            $linked = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            with_family($owner, function () use ($friend, $linked) {
                db()->prepare('DELETE FROM fp_event_shares WHERE friend_family = ?')->execute([$friend]);
                if ($linked) {
                    db()->exec("DELETE FROM fp_contact_week WHERE source = 'LINK' AND contact_id IN (" . implode(',', $linked) . ')');
                }
            });
        }
    }
    db()->prepare('DELETE FROM fp_family_shares WHERE (owner_family = ? AND friend_family = ?) OR (owner_family = ? AND friend_family = ?)')->execute([$a, $b, $b, $a]);
    db()->prepare('DELETE FROM fp_contact_links WHERE (family_id = ? AND other_family = ?) OR (family_id = ? AND other_family = ?)')->execute([$a, $b, $b, $a]);
    db()->prepare('DELETE FROM fp_family_links WHERE id = ?')->execute([$linkId]);
}

/** What $owner shares with $friend (list of SHARE_OPTIONS keys). */
function shares_from(int $owner, int $friend): array
{
    $stmt = db()->prepare('SELECT what FROM fp_family_shares WHERE owner_family = ? AND friend_family = ?');
    $stmt->execute([$owner, $friend]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function set_shares(int $friend, array $whats): void
{
    $me = current_family_id();
    if (!isset(friend_families()[$friend])) {
        return;
    }
    db()->prepare('DELETE FROM fp_family_shares WHERE owner_family = ? AND friend_family = ?')->execute([$me, $friend]);
    foreach (array_intersect($whats, array_keys(SHARE_OPTIONS)) as $w) {
        db()->prepare('INSERT INTO fp_family_shares (owner_family, friend_family, what) VALUES (?, ?, ?)')->execute([$me, $friend, $w]);
    }
    if (!in_array('EVENTS', $whats, true)) {
        db()->prepare('DELETE FROM fp_event_shares WHERE friend_family = ?')->execute([$friend]);
    }
}

/** The friend family's members, if they share their profile with us (birthdays only when shared too). */
function friend_members(int $friend): array
{
    $me = current_family_id();
    if (!isset(friend_families()[$friend])) {
        return [];
    }
    $shares = shares_from($friend, $me);
    if (!in_array('PROFILE', $shares, true)) {
        return [];
    }
    $rows = with_family($friend, function () {
        return db()->query('SELECT id, name, role, photo, color, emoji, birth_day, birth_month, birth_year, phone, email, allergies FROM fp_members ORDER BY sort, id')->fetchAll();
    });
    if (!in_array('CONTACT', $shares, true)) {
        foreach ($rows as &$r) {
            $r['phone'] = $r['email'] = $r['allergies'] = null;
        }
        unset($r);
    }
    if (!in_array('BIRTHDAYS', $shares, true)) {
        foreach ($rows as &$r) {
            $r['birth_day'] = $r['birth_month'] = $r['birth_year'] = null;
        }
        unset($r);
    }
    return $rows;
}

/** Address of a friend family (from their settings), when they share PROFILE with us. Keys: street, postal_code, city, phone. */
function friend_address(int $friend): ?array
{
    if (!isset(friend_families()[$friend]) || !in_array('PROFILE', shares_from($friend, current_family_id()), true)) {
        return null;
    }
    $rows = with_family($friend, function () {
        return db()->query("SELECT name, value FROM fp_settings WHERE name IN ('home_street', 'home_postal', 'city', 'home_phone')")->fetchAll(PDO::FETCH_KEY_PAIR);
    });
    $a = ['street' => $rows['home_street'] ?? '', 'postal_code' => $rows['home_postal'] ?? '', 'city' => $rows['city'] ?? '', 'phone' => $rows['home_phone'] ?? ''];
    return array_filter($a) ? $a : null;
}

/** Our contacts linked to members of $friend: contact_id => member_id. */
function contact_links_to(int $friend): array
{
    $stmt = db()->prepare('SELECT contact_id, member_id FROM fp_contact_links WHERE family_id = ? AND other_family = ?');
    $stmt->execute([current_family_id(), $friend]);
    $out = [];
    foreach ($stmt as $r) {
        $out[(int) $r['contact_id']] = (int) $r['member_id'];
    }
    return $out;
}

/** The friend family's member a contact of ours is linked to, or null: ['family' => id, 'family_name', 'member_id']. */
function contact_link(int $contactId): ?array
{
    $stmt = db()->prepare('SELECT l.other_family, l.member_id, f.name FROM fp_contact_links l JOIN fp_families f ON f.id = l.other_family WHERE l.family_id = ? AND l.contact_id = ?');
    $stmt->execute([current_family_id(), $contactId]);
    $r = $stmt->fetch();
    return $r ? ['family' => (int) $r['other_family'], 'family_name' => $r['name'], 'member_id' => (int) $r['member_id']] : null;
}

function link_contact(int $contactId, int $friend, int $memberId): void
{
    if (!isset(friend_families()[$friend])) {
        return;
    }
    $ok = false;
    foreach (friend_members($friend) as $m) {
        if ((int) $m['id'] === $memberId) {
            $ok = true;
        }
    }
    $exists = db()->prepare('SELECT 1 FROM fp_contacts WHERE id = ?');
    $exists->execute([$contactId]);
    if (!$ok || !$exists->fetchColumn()) {
        return;
    }
    db()->prepare('REPLACE INTO fp_contact_links (family_id, contact_id, other_family, member_id) VALUES (?, ?, ?, ?)')
        ->execute([current_family_id(), $contactId, $friend, $memberId]);
    sync_links(true);
}

function unlink_contact(int $contactId): void
{
    db()->prepare('DELETE FROM fp_contact_links WHERE family_id = ? AND contact_id = ?')->execute([current_family_id(), $contactId]);
    db()->prepare("DELETE FROM fp_contact_week WHERE contact_id = ? AND source = 'LINK'")->execute([$contactId]);
}

/**
 * Bring linked contacts up to date with what their own family shares: photo (PROFILE), birthday
 * (BIRTHDAYS) and their clubs as weekly items (CLUBS). Runs at most every 15 minutes per session.
 */
function sync_links(bool $force = false): void
{
    if (!$force && isset($_SESSION['fp_sync']) && $_SESSION['fp_sync'] > time() - 900) {
        return;
    }
    $_SESSION['fp_sync'] = time();
    $me = current_family_id();
    // Gezinnen linked to a friend family: take over their address (only fields still empty here)
    foreach (friend_families() as $fid => $fam) {
        $g = gezin_of_family($fid);
        $addr = $g ? friend_address($fid) : null;
        if ($addr) {
            db()->prepare("UPDATE fp_households SET street = COALESCE(NULLIF(street, ''), ?), postal_code = COALESCE(NULLIF(postal_code, ''), ?),
                city = COALESCE(NULLIF(city, ''), ?), phone = COALESCE(NULLIF(phone, ''), ?) WHERE id = ?")
                ->execute([$addr['street'] ?: null, $addr['postal_code'] ?: null, $addr['city'] ?: null, $addr['phone'] ?: null, $g['id']]);
        }
    }
    $stmt = db()->prepare('SELECT l.contact_id, l.other_family, l.member_id FROM fp_contact_links l
        JOIN fp_family_links fl ON fl.status = \'ACCEPTED\' AND ((fl.family_a = l.family_id AND fl.family_b = l.other_family) OR (fl.family_b = l.family_id AND fl.family_a = l.other_family))
        WHERE l.family_id = ?');
    $stmt->execute([$me]);
    require_once __DIR__ . '/week.php';
    foreach ($stmt->fetchAll() as $link) {
        $friend = (int) $link['other_family'];
        $shares = shares_from($friend, $me);
        if (!in_array('CLUBS', $shares, true)) {
            db()->prepare("DELETE FROM fp_contact_week WHERE contact_id = ? AND source = 'LINK'")->execute([$link['contact_id']]);
        }
        if (!array_intersect(['PROFILE', 'BIRTHDAYS', 'CLUBS', 'CONTACT'], $shares)) {
            continue;
        }
        $remote = with_family($friend, function () use ($link, $shares) {
            $m = db()->prepare('SELECT * FROM fp_members WHERE id = ?');
            $m->execute([$link['member_id']]);
            $member = $m->fetch();
            $clubs = $member && in_array('CLUBS', $shares, true) ? member_clubs((int) $member['id']) : [];
            return [$member, $clubs];
        });
        [$member, $clubs] = $remote;
        if (!$member) {
            continue;
        }
        $cid = (int) $link['contact_id'];
        if (in_array('PROFILE', $shares, true) && $member['photo']) {
            db()->prepare('UPDATE fp_contacts SET photo = ? WHERE id = ?')->execute([$member['photo'], $cid]);
        }
        if (in_array('CONTACT', $shares, true)) {
            // Their own details win; empty there = keep what we have
            db()->prepare("UPDATE fp_contacts SET phone = COALESCE(?, phone), email = COALESCE(?, email), allergies = COALESCE(?, allergies) WHERE id = ?")
                ->execute([$member['phone'] ?: null, $member['email'] ?: null, $member['allergies'] ?: null, $cid]);
        }
        if (in_array('BIRTHDAYS', $shares, true) && $member['birth_day'] && $member['birth_month']) {
            db()->prepare('UPDATE fp_contacts SET birth_day = ?, birth_month = ?, birth_year = ? WHERE id = ?')
                ->execute([$member['birth_day'], $member['birth_month'], $member['birth_year'], $cid]);
        }
        db()->prepare("DELETE FROM fp_contact_week WHERE contact_id = ? AND source = 'LINK'")->execute([$cid]);
        foreach ($clubs as $club) {
            db()->prepare("INSERT INTO fp_contact_week (contact_id, weekday, start_time, end_time, kind, title, emoji, source) VALUES (?, ?, ?, ?, ?, ?, ?, 'LINK')")->execute([
                $cid, $club['weekday'], $club['all_day'] ? null : substr($club['start_at'], 11, 5), $club['all_day'] ? null : substr($club['end_at'], 11, 5),
                $club['type'] === 'SPORT' ? 'SPORT' : 'CLUB', mb_cut($club['title'], 80), $club['emoji'] ?? null,
            ]);
        }
    }
}

/**
 * Events of friend families that concern us, for [$from, $to): playdates with our children (when they
 * share PLAYDATES) and events they explicitly shared with us (EVENTS). Read-only, shown from our side:
 * our children as members, their children as guests, "bij ons"/"bij hen" swapped.
 */
function shared_events(string $from, string $to): array
{
    $me = current_family_id();
    $out = [];
    foreach (friend_families() as $fid => $fam) {
        $shares = shares_from($fid, $me);
        if (!array_intersect(['PLAYDATES', 'EVENTS'], $shares)) {
            continue;
        }
        // Their contacts that are our members: their contact id => our member id
        $stmt = db()->prepare('SELECT contact_id, member_id FROM fp_contact_links WHERE family_id = ? AND other_family = ?');
        $stmt->execute([$fid, $me]);
        $theirContactToOurMember = [];
        foreach ($stmt as $r) {
            $theirContactToOurMember[(int) $r['contact_id']] = (int) $r['member_id'];
        }
        $data = with_family($fid, function () use ($from, $to, $shares, $theirContactToOurMember, $me) {
            $ids = [];
            if (in_array('PLAYDATES', $shares, true) && $theirContactToOurMember) {
                $in = implode(',', array_keys($theirContactToOurMember));
                foreach (db()->query("SELECT DISTINCT e.id FROM fp_events e JOIN fp_event_contacts ec ON ec.event_id = e.id WHERE e.type = 'PLAYDATE' AND ec.contact_id IN ($in)") as $r) {
                    $ids[(int) $r['id']] = 'PLAYDATE';
                }
            }
            if (in_array('EVENTS', $shares, true)) {
                $st = db()->prepare('SELECT event_id FROM fp_event_shares WHERE friend_family = ?');
                $st->execute([$me]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $ids[(int) $id] = $ids[(int) $id] ?? 'SHARED';
                }
            }
            $events = $ids ? load_events($from, $to, ['ids' => array_keys($ids)]) : [];
            $members = [];
            foreach (db()->query('SELECT id, name, photo, color, emoji FROM fp_members') as $m) {
                $members[(int) $m['id']] = $m;
            }
            return [$events, $ids, $members];
        });
        [$events, $why, $theirMembers] = $data;
        $ourContactFor = array_flip(contact_links_to($fid)); // their member id => our contact id
        $showProfile = in_array('PROFILE', $shares, true);
        foreach ($events as $ev) {
            $ourKids = [];
            foreach ($ev['contacts'] as $c) {
                if (isset($theirContactToOurMember[(int) $c['id']])) {
                    $ourKids[] = $theirContactToOurMember[(int) $c['id']];
                }
            }
            // Their family members at this event, as guests on our side
            $guests = [];
            foreach ($ev['members'] as $mid) {
                $m = $theirMembers[$mid] ?? null;
                if (!$m) {
                    continue;
                }
                $local = isset($ourContactFor[$mid]) ? find_contact_row($ourContactFor[$mid]) : null;
                $guests[] = $local ?: ['id' => 0, 'first_name' => $showProfile ? $m['name'] : $fam['name'], 'last_name' => null, 'nickname' => null,
                    'photo' => $showProfile ? $m['photo'] : null, 'rsvp' => '', 'is_child' => 1, 'household_id' => null];
            }
            $isPlaydate = ($why[(int) $ev['id']] ?? '') === 'PLAYDATE';
            $host = $ev['host'] === 'HOME' ? 'AWAY' : ($ev['host'] === 'AWAY' ? 'HOME' : '');
            $names = implode(' & ', array_map(function ($g) {
                return $g['nickname'] ?: $g['first_name'];
            }, $guests));
            $out[] = array_merge($ev, [
                'id' => 'x' . $fid . '-' . $ev['id'],
                'title' => $isPlaydate && $names ? ($host === 'AWAY' ? 'Spelen bij ' : 'Spelen met ') . $names : $ev['title'],
                'members' => $isPlaydate ? array_values(array_unique($ourKids)) : [],
                'contacts' => $guests,
                'host' => $isPlaydate ? $host : $ev['host'],
                'description' => $ev['description'],
                'drop_member_id' => null, 'pickup_member_id' => null, 'drop_contact_id' => null, 'pickup_contact_id' => null, 'drop_each' => 0, 'pickup_each' => 0, 'drop_open' => false, 'pickup_open' => false,
                'cost' => null, 'paid' => 0, 'recurring' => false, 'recurrence' => '', 'recur_until' => null,
                'shared' => true, 'owner_family' => $fam['name'], 'owner_family_id' => $fid,
            ]);
        }
    }
    return $out;
}

/** $events (from load_events) plus what friend families share with us in [$from, $to), sorted like load_events. */
function with_shared_events(array $events, string $from, string $to): array
{
    $all = array_merge($events, shared_events($from, $to));
    usort($all, function ($a, $b) {
        return [$b['all_day'], $a['start_at']] <=> [$a['all_day'], $b['start_at']];
    });
    return $all;
}

function find_contact_row(int $id): ?array
{
    $stmt = db()->prepare("SELECT *, '' AS rsvp FROM fp_contacts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Calendar JSON for a shared event (read-only). */
function shared_event_json(array $ev): array
{
    $j = event_json(array_merge($ev, ['id' => 0]));
    $j['id'] = $ev['id'];
    $j['readOnly'] = true;
    $j['ownerFamily'] = $ev['owner_family'];
    return $j;
}

/** A family photo may be seen by that family, families it has a friendship (or invitation) with, and the site admins. */
function family_photo_visible(string $file): bool
{
    $stmt = db()->prepare('SELECT id FROM fp_families WHERE photo = ?');
    $stmt->execute([$file]);
    $fid = (int) $stmt->fetchColumn();
    if (!$fid) {
        return false;
    }
    $me = current_family_id();
    if ($fid === $me || is_site_admin()) {
        return true;
    }
    $stmt = db()->prepare('SELECT 1 FROM fp_family_links WHERE (family_a = ? AND family_b = ?) OR (family_a = ? AND family_b = ?)');
    $stmt->execute([$fid, $me, $me, $fid]);
    return (bool) $stmt->fetchColumn();
}

/** Photos of friend families' members that are shared with us may be shown here too. */
function photo_shared_with_me(string $file): bool
{
    $me = current_family_id();
    foreach (friend_families() as $fid => $fam) {
        if (!in_array('PROFILE', shares_from($fid, $me), true)) {
            continue;
        }
        $found = with_family($fid, function () use ($file) {
            $s = db()->prepare('SELECT 1 FROM fp_members WHERE photo = ?');
            $s->execute([$file]);
            return (bool) $s->fetchColumn();
        });
        if ($found) {
            return true;
        }
    }
    return false;
}
