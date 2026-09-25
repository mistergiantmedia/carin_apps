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
        case 'events':
            $from = (string) ($_GET['from'] ?? '');
            $to = (string) ($_GET['to'] ?? '');
            if (!valid_date($from) || !valid_date($to) || $to <= $from || (strtotime($to) - strtotime($from)) > 400 * 86400) {
                reply(['error' => 'Ongeldige periode.'], 400);
            }
            $filter = [];
            if (!empty($_GET['members'])) {
                $filter['members'] = array_filter(array_map('intval', explode(',', (string) $_GET['members'])));
            }
            if (!empty($_GET['types'])) {
                $filter['types'] = array_values(array_intersect(explode(',', (string) $_GET['types']), array_keys(EVENT_TYPES)));
            }
            $events = array_map('event_json', load_events($from, $to, $filter));
            $birthdays = empty($_GET['nobirthdays']) ? array_map('birthday_json', load_birthdays($from, $to, $filter)) : [];
            reply(['events' => $events, 'birthdays' => $birthdays]);

        case 'event':
            $id = (int) ($_GET['id'] ?? 0);
            $occ = (string) ($_GET['occ'] ?? '');
            $ev = valid_date($occ) ? occurrence_json($id, $occ) : null;
            if (!$ev) {
                $row = find_event($id);
                if (!$row) {
                    reply(['error' => 'Deze afspraak bestaat niet meer.'], 404);
                }
                $row['occ'] = substr($row['start_at'], 0, 10);
                $ev = event_json($row);
            }
            $ev['tasks'] = event_tasks($id);
            reply(['event' => $ev]);

        case 'save':
            $id = (int) ($in['id'] ?? 0);
            $scope = in_str($in, 'scope', 'all');
            $occ = in_str($in, 'occ');
            $data = normalise_event($in);
            if ($id) {
                $existing = find_event($id);
                if (!$existing) {
                    reply(['error' => 'Deze afspraak bestaat niet meer.'], 404);
                }
                if ($existing['recurring'] && $scope === 'one' && valid_date($occ)) {
                    // Edit just this occurrence: take it out of the series and save as its own event
                    $id = detach_occurrence($existing, $occ);
                    $data['recurrence'] = '';
                    $data['recur_until'] = null;
                } elseif ($existing['recurring'] && valid_date($occ) && $data['recurrence'] !== '') {
                    // Editing the series from one occurrence: keep the series' first date, apply the new time/length
                    $delta = strtotime(substr($data['start_at'], 0, 10)) - strtotime($occ);
                    $seriesStart = date('Y-m-d', strtotime(substr($existing['start_at'], 0, 10)) + $delta);
                    $len = strtotime($data['end_at']) - strtotime($data['start_at']);
                    $data['start_at'] = $seriesStart . substr($data['start_at'], 10);
                    $data['end_at'] = date('Y-m-d H:i:s', strtotime($data['start_at']) + $len);
                }
            }
            $newId = save_event($data, $id ?: null);
            $occDate = substr($data['start_at'], 0, 10);
            if ($id && valid_date($occ) && $scope !== 'one' && $data['recurrence'] !== '') {
                $occDate = date('Y-m-d', strtotime(substr($in['start'] ?? $occ, 0, 10)));
            }
            reply(['ok' => true, 'id' => $newId, 'event' => occurrence_json($newId, $occDate)]);

        case 'move':
            $id = (int) ($in['id'] ?? 0);
            $occ = in_str($in, 'occ');
            if (!valid_date($occ)) {
                reply(['error' => 'Ongeldige datum.'], 400);
            }
            $newId = move_event($id, $occ, in_str($in, 'start'), in_str($in, 'end'), !empty($in['allDay']), in_str($in, 'scope', 'all'));
            reply(['ok' => true, 'id' => $newId, 'event' => occurrence_json($newId, substr(str_replace('T', ' ', in_str($in, 'start')), 0, 10))]);

        case 'delete':
            $occ = in_str($in, 'occ');
            delete_event((int) ($in['id'] ?? 0), valid_date($occ) ? $occ : '', in_str($in, 'scope', 'all'));
            reply(['ok' => true]);

        case 'restore':
            // Undo of a delete: the client sends the full event back
            $data = normalise_event($in);
            $newId = save_event($data);
            reply(['ok' => true, 'id' => $newId]);

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
            $newId = detach_occurrence_copy($ev, valid_date($occ) ? $occ : substr($ev['start_at'], 0, 10));
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
