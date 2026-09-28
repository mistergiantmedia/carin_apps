<?php
// JSON endpoints for the calendar and quick actions. ?a=<action>
// POST bodies are JSON; the CSRF token comes in the X-CSRF-Token header.
define('API_REQUEST', true);
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/tasks.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!current_user()) {
    reply(['error' => 'Je bent uitgelogd. Vernieuw de pagina en log opnieuw in.'], 401);
}

$action = (string) ($_GET['a'] ?? '');
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    is_post();
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
}

function in_str(array $in, string $key, string $default = ''): string
{
    return isset($in[$key]) && is_scalar($in[$key]) ? trim((string) $in[$key]) : $default;
}

function valid_date(string $d): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
}

/**
 * What connecting two relation-web nodes means. Ids look like m3 (member), c12 (contact), g4 (group), h7 (household).
 * Returns [kind, first id, second id] or null: group-member [member, group], group-contact [contact, group],
 * household [contact, household], friend [contact, member].
 */
function graph_pair(string $a, string $b): ?array
{
    $parse = function ($s) {
        return preg_match('/^([mcgh])(\d+)$/', $s, $m) ? [$m[1], (int) $m[2]] : null;
    };
    $p = $parse($a);
    $q = $parse($b);
    if (!$p || !$q) {
        return null;
    }
    $by = [$p[0] => $p[1]];
    if (isset($by[$q[0]])) {
        return null; // same kind (two groups, two contacts…)
    }
    $by[$q[0]] = $q[1];
    if (isset($by['g'], $by['m'])) {
        return member($by['m']) ? ['group-member', $by['m'], $by['g']] : null;
    }
    if (isset($by['g'], $by['c'])) {
        return ['group-contact', $by['c'], $by['g']];
    }
    if (isset($by['h'], $by['c'])) {
        return ['household', $by['c'], $by['h']];
    }
    if (isset($by['m'], $by['c'])) {
        return member($by['m']) ? ['friend', $by['c'], $by['m']] : null;
    }
    return null;
}

/** The occurrence of event $id on $occ as calendar JSON (after a change). */
function occurrence_json(int $id, string $occ): ?array
{
    foreach (load_events($occ, date('Y-m-d', strtotime("$occ +1 day")), ['ids' => [$id]]) as $ev) {
        if ($ev['occ'] === $occ) {
            return event_json($ev);
        }
    }
    $ev = find_event($id);
    if (!$ev) {
        return null;
    }
    $ev['occ'] = substr($ev['start_at'], 0, 10);
    return event_json($ev);
}

try {
    switch ($action) {
        case 'stamp':
            // Fingerprint of everything shown in the app; pages poll it and refresh when it changes.
            // Per table: row count + checksum of the columns that matter (add new tables here).
            $watch = [
                'fp_events' => 'id, title, type, emoji, start_at, end_at, done, updated_at, drop_member_id, pickup_member_id',
                'fp_event_members' => 'event_id, member_id',
                'fp_event_contacts' => 'event_id, contact_id, rsvp',
                'fp_event_done' => 'event_id, occurs_on',
                'fp_event_exceptions' => 'event_id, occurs_on',
                'fp_event_duties' => 'event_id, occurs_on, role, member_id',
                'fp_tasks' => 'id, title, due_date, member_id, recurrence, done_at',
                'fp_contacts' => 'id, first_name, last_name, nickname, photo, birth_day, birth_month, birth_year, household_id, relation, is_favorite',
                'fp_contact_members' => 'contact_id, member_id',
                'fp_members' => 'id, name, photo, color, emoji, birth_day, birth_month, birth_year, sort',
                'fp_households' => 'id, name, street, city',
                'fp_groups' => 'id, type, emoji, name, season',
                'fp_group_members' => 'group_id, member_id, role',
                'fp_group_contacts' => 'group_id, contact_id, role',
                'fp_ideas' => 'id, done_at',
                'fp_friendbook' => 'id, returned_on',
                'fp_birthday_checks' => 'subject, year, item',
                'fp_photos' => 'id',
                'fp_bucket' => 'id, title, emoji, event_id, done_on',
                'fp_bucket_votes' => 'bucket_id, member_id',
                'fp_bucket_contacts' => 'bucket_id, contact_id',
            ];
            $parts = [];
            foreach ($watch as $table => $cols) {
                $parts[] = "(SELECT CONCAT_WS('-', COUNT(*), SUM(CRC32(CONCAT_WS(',', $cols)))) FROM $table)";
            }
            $fingerprint = db()->query("SELECT CONCAT_WS('|', " . implode(', ', $parts) . ')')->fetchColumn();
            reply(['stamp' => md5((string) $fingerprint), 'today' => today()]);

        case 'graph':
            // Relations web: our family, groups, households, address book people and "friend of" links
            $nodes = [['id' => 'home', 'kind' => 'home', 'label' => 'Ons gezin', 'emoji' => '🏡', 'color' => '#6C5CE7']];
            $links = [];
            foreach (members() as $m) {
                $nodes[] = ['id' => 'm' . $m['id'], 'kind' => 'member', 'label' => $m['name'], 'emoji' => $m['emoji'], 'color' => $m['color'], 'photo' => $m['photo'], 'url' => 'persoon.php?id=' . $m['id'], 'sub' => $m['role'] === 'CHILD' ? 'Kind' : 'Ouder'];
                $links[] = ['source' => 'home', 'target' => 'm' . $m['id'], 'kind' => 'home'];
            }
            foreach (db()->query('SELECT * FROM fp_groups') as $g) {
                $t = GROUP_TYPES[$g['type']] ?? GROUP_TYPES['OTHER'];
                $nodes[] = ['id' => 'g' . $g['id'], 'kind' => 'group', 'type' => $g['type'], 'label' => $g['name'] . ($g['season'] ? ' ' . $g['season'] : ''), 'emoji' => ($g['emoji'] ?: $t[1]), 'color' => $t[2], 'url' => 'groepen.php?id=' . $g['id'], 'sub' => $t[0] . ($g['place'] ? ' · ' . $g['place'] : '')];
            }
            foreach (db()->query('SELECT group_id, member_id, role FROM fp_group_members') as $r) {
                $links[] = ['source' => 'm' . $r['member_id'], 'target' => 'g' . $r['group_id'], 'kind' => 'group', 'role' => $r['role']];
            }
            foreach (db()->query('SELECT group_id, contact_id, role FROM fp_group_contacts') as $r) {
                $links[] = ['source' => 'c' . $r['contact_id'], 'target' => 'g' . $r['group_id'], 'kind' => 'group', 'role' => $r['role']];
            }
            $households = [];
            foreach (db()->query('SELECT c.*, h.name AS household_name FROM fp_contacts c LEFT JOIN fp_households h ON h.id = c.household_id') as $c) {
                $nodes[] = ['id' => 'c' . $c['id'], 'kind' => 'contact', 'label' => contact_name($c, false), 'full' => contact_name($c), 'photo' => $c['photo'], 'color' => name_color(contact_name($c, false)),
                    'child' => (bool) $c['is_child'], 'url' => 'contact.php?id=' . $c['id'], 'sub' => (RELATIONS[$c['relation']][0] ?? '') . ($c['household_name'] ? ' · ' . $c['household_name'] : ''), 'relation' => $c['relation']];
                if ($c['household_id']) {
                    $households[$c['household_id']] = $c['household_name'];
                    $links[] = ['source' => 'c' . $c['id'], 'target' => 'h' . $c['household_id'], 'kind' => 'household'];
                }
            }
            foreach ($households as $hid => $name) {
                $nodes[] = ['id' => 'h' . $hid, 'kind' => 'household', 'label' => $name, 'emoji' => '🏠', 'color' => '#A0522D', 'url' => 'huishouden.php?id=' . $hid, 'sub' => 'Huishouden'];
            }
            foreach (db()->query('SELECT contact_id, member_id FROM fp_contact_members') as $r) {
                $links[] = ['source' => 'c' . $r['contact_id'], 'target' => 'm' . $r['member_id'], 'kind' => 'friend'];
            }
            reply(['nodes' => $nodes, 'links' => $links, 'groupTypes' => array_map(function ($t) {
                return ['label' => $t[0], 'emoji' => $t[1], 'color' => $t[2]];
            }, GROUP_TYPES)]);

        case 'graph.link':
        case 'graph.unlink':
            // Connect / disconnect two nodes of the relations web: person ↔ group, contact ↔ household, contact ↔ family member
            $a = in_str($in, 'a');
            $b = in_str($in, 'b');
            $pair = graph_pair($a, $b);
            if (!$pair) {
                reply(['error' => 'Deze twee kunnen niet met elkaar verbonden worden.'], 400);
            }
            [$kind, $x, $y] = $pair;
            $link = $action === 'graph.link';
            if ($kind === 'group-member') {
                $link ? db()->prepare('INSERT IGNORE INTO fp_group_members (group_id, member_id) VALUES (?, ?)')->execute([$y, $x])
                      : db()->prepare('DELETE FROM fp_group_members WHERE group_id = ? AND member_id = ?')->execute([$y, $x]);
            } elseif ($kind === 'group-contact') {
                $g = db()->prepare('SELECT type FROM fp_groups WHERE id = ?');
                $g->execute([$y]);
                $type = (string) $g->fetchColumn();
                $link ? db()->prepare('INSERT IGNORE INTO fp_group_contacts (group_id, contact_id, role) VALUES (?, ?, ?)')->execute([$y, $x, (GROUP_TYPES[$type][4] ?? '') ?: null])
                      : db()->prepare('DELETE FROM fp_group_contacts WHERE group_id = ? AND contact_id = ?')->execute([$y, $x]);
            } elseif ($kind === 'household') {
                $link ? db()->prepare('UPDATE fp_contacts SET household_id = ? WHERE id = ?')->execute([$y, $x])
                      : db()->prepare('UPDATE fp_contacts SET household_id = NULL WHERE id = ? AND household_id = ?')->execute([$x, $y]);
            } elseif ($kind === 'friend') {
                $link ? db()->prepare('INSERT IGNORE INTO fp_contact_members (contact_id, member_id) VALUES (?, ?)')->execute([$x, $y])
                      : db()->prepare('DELETE FROM fp_contact_members WHERE contact_id = ? AND member_id = ?')->execute([$x, $y]);
            }
            reply(['ok' => true]);

        case 'duty':
            // Who brings / picks up for one occurrence of a "per keer bepalen" event
            $occ = in_str($in, 'occ');
            if (!valid_date($occ)) {
                reply(['error' => 'Ongeldige datum.'], 400);
            }
            set_duty((int) ($in['id'] ?? 0), $occ, in_str($in, 'role'), !empty($in['member']) ? (int) $in['member'] : null);
            reply(['ok' => true, 'event' => occurrence_json((int) ($in['id'] ?? 0), $occ)]);

        case 'done':
            $occ = in_str($in, 'occ');
            set_event_done((int) ($in['id'] ?? 0), valid_date($occ) ? $occ : '', !empty($in['done']));
            reply(['ok' => true]);

        case 'duplicate':
            $ev = find_event((int) ($in['id'] ?? 0));
            if (!$ev) {
                reply(['error' => 'Deze afspraak bestaat niet meer.'], 404);
            }
            $occ = in_str($in, 'occ');
            $newId = copy_occurrence($ev, valid_date($occ) ? $occ : substr($ev['start_at'], 0, 10));
            reply(['ok' => true, 'id' => $newId]);

        case 'contacts':
            $q = trim((string) ($_GET['q'] ?? ''));
            $sql = 'SELECT c.*, h.name AS household_name FROM fp_contacts c LEFT JOIN fp_households h ON h.id = c.household_id';
            $params = [];
            if ($q !== '') {
                $sql .= ' WHERE CONCAT_WS(\' \', c.first_name, c.last_name, c.nickname, h.name) LIKE ?';
                $params[] = '%' . $q . '%';
            }
            $sql .= ' ORDER BY c.is_favorite DESC, c.is_child DESC, c.first_name LIMIT 40';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $friendOf = [];
            foreach (db()->query('SELECT contact_id, member_id FROM fp_contact_members') as $r) {
                $friendOf[$r['contact_id']][] = (int) $r['member_id'];
            }
            $out = [];
            foreach ($stmt as $c) {
                $out[] = [
                    'id' => (int) $c['id'],
                    'name' => contact_name($c, false),
                    'fullName' => contact_name($c),
                    'photo' => $c['photo'],
                    'color' => name_color(contact_name($c, false)),
                    'relation' => RELATIONS[$c['relation']][0] ?? '',
                    'household' => $c['household_name'],
                    'isChild' => (bool) $c['is_child'],
                    'members' => $friendOf[$c['id']] ?? [],
                ];
            }
            reply(['contacts' => $out]);

        case 'contact.quick':
            // Add someone new straight from the event editor
            $name = in_str($in, 'name');
            if ($name === '') {
                reply(['error' => 'Vul een naam in.'], 400);
            }
            $parts = preg_split('/\s+/', $name, 2);
            $isChild = !empty($in['isChild']);
            db()->prepare('INSERT INTO fp_contacts (first_name, last_name, is_child, relation) VALUES (?, ?, ?, ?)')
                ->execute([mb_cut($parts[0], 80), isset($parts[1]) ? mb_cut($parts[1], 80) : null, $isChild ? 1 : 0, $isChild ? 'FRIEND' : 'OWN_FRIEND']);
            $cid = (int) db()->lastInsertId();
            foreach ((array) ($in['members'] ?? []) as $mid) {
                if (member((int) $mid)) {
                    db()->prepare('INSERT IGNORE INTO fp_contact_members (contact_id, member_id) VALUES (?, ?)')->execute([$cid, (int) $mid]);
                }
            }
            reply(['contact' => ['id' => $cid, 'name' => $parts[0], 'fullName' => $name, 'photo' => null, 'color' => name_color($parts[0]), 'rsvp' => '']]);

        case 'task.toggle':
            toggle_task((int) ($in['id'] ?? 0), !empty($in['done']));
            reply(['ok' => true]);

        case 'task.add':
            $title = in_str($in, 'title');
            if ($title === '') {
                reply(['error' => 'Vul in wat er moet gebeuren.'], 400);
            }
            $id = add_task([
                'title' => $title,
                'member_id' => $in['member_id'] ?? null,
                'contact_id' => $in['contact_id'] ?? null,
                'event_id' => $in['event_id'] ?? null,
                'due_date' => $in['due_date'] ?? null,
            ]);
            reply(['ok' => true, 'task' => task_json(find_task($id))]);

        case 'task.delete':
            db()->prepare('DELETE FROM fp_tasks WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
            reply(['ok' => true]);

        case 'birthday.check':
            $subject = in_str($in, 'subject');
            $item = in_str($in, 'item');
            $year = (int) ($in['year'] ?? date('Y'));
            if (!preg_match('/^[cm]\d+$/', $subject) || !isset(BIRTHDAY_ITEMS[$item])) {
                reply(['error' => 'Ongeldig.'], 400);
            }
            if (!empty($in['done'])) {
                db()->prepare('INSERT IGNORE INTO fp_birthday_checks (subject, year, item) VALUES (?, ?, ?)')->execute([$subject, $year, $item]);
            } else {
                db()->prepare('DELETE FROM fp_birthday_checks WHERE subject = ? AND year = ? AND item = ?')->execute([$subject, $year, $item]);
            }
            reply(['ok' => true]);

        default:
            reply(['error' => 'Onbekende actie.'], 404);
    }
} catch (InvalidArgumentException $e) {
    reply(['error' => $e->getMessage()], 400);
}
