<?php
// MCP server (Model Context Protocol, "Streamable HTTP", stateless) so someone can talk to Claude to
// add / change / delete things in the planner. Claude: Instellingen → Connectors → add custom connector with
// the link from instellingen.php (…/mcp.php?t=<secret token of an account>). The token is the key (like ics.php).
// Everything goes through the same functions as the pages and api.php, so Google Agenda sync etc. keep working.
// Keep this PHP 7.4 compatible.
define('API_REQUEST', true);
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/tasks.php';
require __DIR__ . '/lib/meals.php';
require_once __DIR__ . '/lib/freedays.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function rpc_out($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rpc_error($id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    rpc_out(rpc_error(null, -32000, 'Gebruik POST (MCP over HTTP).'), 405);
}

// ---------- Who is calling: the secret token of an account ----------
$token = (string) ($_GET['t'] ?? '');
if ($token === '' && preg_match('/^Bearer\s+(\S+)/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) {
    $token = $m[1];
}
$authed = false;
if (strlen($token) >= 20) {
    $stmt = db()->prepare("SELECT u.id FROM fp_users u JOIN fp_families f ON f.id = u.family_id AND f.status = 'ACTIVE' WHERE u.mcp_token = ? AND u.mcp_token IS NOT NULL");
    $stmt->execute([$token]);
    if ($uid = $stmt->fetchColumn()) {
        $_SESSION['user_id'] = (int) $uid;
        $authed = (bool) current_user(); // also switches to the family's tables
    }
}
// No browser session is wanted for this endpoint
@session_destroy();
header_remove('Set-Cookie');
if (!$authed) {
    rpc_out(rpc_error(null, -32001, 'Ongeldige of uitgezette link. Maak een nieuwe link in de planner onder Instellingen.'), 401);
}

// ---------- Helpers ----------

function mcp_date($v, string $what): string
{
    $v = trim((string) $v);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) {
        throw new InvalidArgumentException("$what moet een datum zijn als JJJJ-MM-DD (kreeg: '$v').");
    }
    return $v;
}

/** Local date-time text: "2026-11-28" or "2026-11-28 14:00" (also with a T). */
function mcp_datetime($v, string $what): string
{
    $v = trim(str_replace('T', ' ', (string) $v));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{1,2}:\d{2})?$/', $v) || !strtotime($v)) {
        throw new InvalidArgumentException("$what moet 'JJJJ-MM-DD' of 'JJJJ-MM-DD UU:MM' zijn (kreeg: '$v').");
    }
    return $v;
}

function mcp_ids($v): array
{
    return array_values(array_unique(array_filter(array_map('intval', (array) $v))));
}

function mcp_member_ids($v): array
{
    $ids = mcp_ids($v);
    foreach ($ids as $id) {
        if (!member($id)) {
            throw new InvalidArgumentException("Er is geen gezinslid met id $id (gebruik get_overview).");
        }
    }
    return $ids;
}

function mcp_names(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        if ($m = member((int) $id)) {
            $out[] = $m['name'];
        }
    }
    return $out;
}

/** Compact event for Claude. */
function mcp_event(array $e): array
{
    $j = event_json($e);
    $out = [
        'id' => $j['id'],
        'occ' => $j['occ'], // date of this occurrence (needed for edit/delete of one repeat)
        'title' => $j['title'],
        'type' => $j['type'],
        'start' => $j['start'],
        'end' => $j['end'],
        'all_day' => $j['allDay'],
        'location' => $j['location'],
        'members' => mcp_names($j['members']),
        'member_ids' => $j['members'],
        'guests' => array_map(function ($c) {
            return ['id' => $c['id'], 'name' => $c['fullName'], 'rsvp' => $c['rsvp']];
        }, $j['contacts']),
        'brings' => $j['dropName'] ?: ($j['dropEach'] ? '(per keer bepalen)' : null),
        'picks_up' => $j['pickupName'] ?: ($j['pickupEach'] ? '(per keer bepalen)' : null),
        'repeats' => $j['recurrence'] ?: null,
        'repeats_until' => $j['recurUntil'],
        'done' => $j['done'],
        'notes' => $j['description'],
    ];
    return array_filter($out, function ($v) {
        return $v !== null && $v !== '' && $v !== [];
    });
}

function mcp_task(array $t): array
{
    $j = task_json($t);
    return array_filter([
        'id' => $j['id'],
        'title' => $j['title'],
        'for' => $j['member'] ? (mcp_names([$j['member']])[0] ?? null) : null,
        'for_member_id' => $j['member'],
        'due' => $j['due'],
        'repeats' => $j['recurrence'] ?: null,
        'done' => $j['done'],
        'event' => $t['event_title'] ?? null,
    ], function ($v) {
        return $v !== null && $v !== '';
    });
}

/** The occurrence of event $id on $occ as compact JSON. */
function mcp_occurrence(int $id, string $occ): ?array
{
    foreach (load_events($occ, date('Y-m-d', strtotime("$occ +1 day")), ['ids' => [$id]]) as $ev) {
        if ($ev['occ'] === $occ) {
            return mcp_event($ev);
        }
    }
    $ev = find_event($id);
    if (!$ev) {
        return null;
    }
    $ev['occ'] = substr($ev['start_at'], 0, 10);
    return mcp_event($ev);
}

/** Event input fields shared by add_event and update_event. */
function mcp_event_input(array $a): array
{
    $in = [];
    foreach (['title', 'type', 'location', 'description', 'host', 'emoji', 'recurrence', 'recur_until', 'cost'] as $k) {
        if (array_key_exists($k, $a) && $a[$k] !== null) {
            $in[$k] = $a[$k];
        }
    }
    if (isset($a['all_day'])) {
        $in['all_day'] = !empty($a['all_day']);
    }
    if (isset($a['paid'])) {
        $in['paid'] = !empty($a['paid']);
    }
    if (isset($in['type']) && !isset(EVENT_TYPES[$in['type']])) {
        throw new InvalidArgumentException('Onbekend type "' . $in['type'] . '". Kies uit: ' . implode(', ', array_keys(EVENT_TYPES)));
    }
    if (isset($in['recurrence']) && !isset(RECURRENCES[$in['recurrence']])) {
        throw new InvalidArgumentException('Onbekende herhaling. Kies uit: ' . implode(', ', array_keys(RECURRENCES)));
    }
    if (array_key_exists('member_ids', $a) && $a['member_ids'] !== null) {
        $in['members'] = mcp_member_ids($a['member_ids']);
    }
    if (array_key_exists('contact_ids', $a) && $a['contact_ids'] !== null) {
        $in['contacts'] = mcp_ids($a['contact_ids']);
    }
    foreach (['brings' => 'drop_member_id', 'picks_up' => 'pickup_member_id'] as $k => $col) {
        if (array_key_exists($k, $a) && $a[$k] !== null) {
            $in[$col] = (string) $a[$k]; // member id, "c<contact id>", "" (nobody) or "EACH"
        }
    }
    return $in;
}

/** The current values of an event in the shape normalise_event() takes. */
function mcp_event_as_input(array $ev): array
{
    return [
        'title' => $ev['title'], 'type' => $ev['type'], 'emoji' => $ev['emoji'],
        'start' => substr($ev['start_at'], 0, 16), 'end' => substr($ev['end_at'], 0, 16), 'all_day' => (bool) $ev['all_day'],
        'location' => $ev['location'], 'host' => $ev['host'], 'description' => $ev['description'], 'color' => $ev['color'],
        'recurrence' => $ev['recurrence'], 'recur_until' => $ev['recur_until'],
        'drop_member_id' => !empty($ev['drop_each']) ? 'EACH' : driver_value($ev['drop_member_id'], $ev['drop_contact_id'] ?? null),
        'pickup_member_id' => !empty($ev['pickup_each']) ? 'EACH' : driver_value($ev['pickup_member_id'], $ev['pickup_contact_id'] ?? null),
        'cost' => $ev['cost'], 'paid' => $ev['paid'], 'members' => $ev['members'],
        'contacts' => array_map(function ($c) {
            return ['id' => $c['id'], 'rsvp' => $c['rsvp']];
        }, $ev['contacts']),
    ];
}

// ---------- Tools ----------

function tool_get_overview(array $a): array
{
    $members = [];
    foreach (members() as $m) {
        $members[] = ['id' => (int) $m['id'], 'name' => $m['name'], 'role' => $m['role'] === 'CHILD' ? 'kind' : 'ouder',
            'birthday' => mcp_birthday_text($m),
            'allergies' => $m['allergies'] ?? null, 'phone' => $m['phone'] ?? null, 'email' => $m['email'] ?? null];
    }
    $types = [];
    foreach (EVENT_TYPES as $k => $t) {
        $types[$k] = $t[0];
    }
    return [
        'today' => today(),
        'weekday' => DAYS[(int) date('w')],
        'now' => date('H:i'),
        'timezone' => 'Europe/Amsterdam',
        'family_members' => $members,
        'event_types' => $types,
        'repeat_options' => array_filter(RECURRENCES, 'strlen', ARRAY_FILTER_USE_KEY),
        'task_repeat_options' => array_filter(TASK_RECURRENCES, 'strlen', ARRAY_FILTER_USE_KEY),
        'shopping_categories' => array_map(function ($c) {
            return $c[1];
        }, SHOP_CATEGORIES),
    ];
}

function tool_list_events(array $a): array
{
    $from = mcp_date($a['from'] ?? today(), 'from');
    $to = mcp_date($a['to'] ?? $from, 'to');
    if ($to < $from || (strtotime($to) - strtotime($from)) > 400 * 86400) {
        throw new InvalidArgumentException('Ongeldige periode (max. ruim een jaar).');
    }
    $end = date('Y-m-d', strtotime("$to +1 day"));
    $filter = [];
    if (!empty($a['member_ids'])) {
        $filter['members'] = mcp_member_ids($a['member_ids']);
    }
    $events = array_map('mcp_event', load_events($from, $end, $filter));
    $birthdays = array_map(function ($b) {
        $j = birthday_json($b);
        return ['date' => substr($j['start'], 0, 10), 'title' => $j['title']];
    }, load_birthdays($from, $end, $filter));
    $out = ['events' => array_slice($events, 0, 150)];
    if (count($events) > 150) {
        $out['note'] = 'Alleen de eerste 150 van ' . count($events) . ' afspraken getoond; vraag een kortere periode.';
    }
    if ($birthdays) {
        $out['birthdays'] = $birthdays;
    }
    return $out;
}

function tool_add_event(array $a): array
{
    if (empty($a['title'])) {
        throw new InvalidArgumentException('Geef een titel.');
    }
    $in = mcp_event_input($a);
    $in['start'] = mcp_datetime($a['start'] ?? '', 'start');
    if (!empty($a['end'])) {
        $in['end'] = mcp_datetime($a['end'], 'end');
    }
    if (!isset($in['all_day']) && strlen($in['start']) === 10) {
        $in['all_day'] = true; // only a date, no time: all day
    }
    $id = save_event(normalise_event($in));
    $ev = find_event($id);
    $ev['occ'] = substr($ev['start_at'], 0, 10);
    return ['added' => mcp_event($ev)];
}

function tool_update_event(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    $existing = find_event($id);
    if (!$existing) {
        throw new InvalidArgumentException('Deze afspraak bestaat niet (meer). Zoek hem eerst op met list_events.');
    }
    $scope = (string) ($a['scope'] ?? 'all');
    $occ = isset($a['occ']) ? mcp_date($a['occ'], 'occ') : '';
    $in = array_merge(mcp_event_as_input($existing), mcp_event_input($a));
    if (!empty($a['start'])) {
        $in['start'] = mcp_datetime($a['start'], 'start');
        if (empty($a['end'])) {
            // keep the length when only the start moves
            $len = max(900, strtotime($existing['end_at']) - strtotime($existing['start_at']));
            $in['end'] = date('Y-m-d H:i', strtotime($in['start']) + $len);
        }
    }
    if (!empty($a['end'])) {
        $in['end'] = mcp_datetime($a['end'], 'end');
    }
    if ($existing['recurring'] && $occ !== '' && empty($a['start'])) {
        // Times in $in are the series' first occurrence; shift them to the chosen occurrence so a title edit doesn't move anything
        $shift = strtotime($occ) - strtotime(substr($existing['start_at'], 0, 10));
        $in['start'] = date('Y-m-d H:i', strtotime($in['start']) + $shift);
        $in['end'] = date('Y-m-d H:i', strtotime($in['end']) + $shift);
    }
    $data = normalise_event($in);
    if ($existing['recurring']) {
        if ($scope === 'one') {
            if ($occ === '') {
                throw new InvalidArgumentException('Dit is een herhalende afspraak: geef occ (de datum van die keer) of kies scope "all".');
            }
            $id = detach_occurrence($existing, $occ);
            $data['recurrence'] = '';
            $data['recur_until'] = null;
        } elseif ($occ !== '' && $data['recurrence'] !== '') {
            // editing the series from one occurrence: keep the series' first date, apply new time and length
            $delta = strtotime(substr($data['start_at'], 0, 10)) - strtotime($occ);
            $seriesStart = date('Y-m-d', strtotime(substr($existing['start_at'], 0, 10)) + $delta);
            $len = strtotime($data['end_at']) - strtotime($data['start_at']);
            $data['start_at'] = $seriesStart . substr($data['start_at'], 10);
            $data['end_at'] = date('Y-m-d H:i:s', strtotime($data['start_at']) + $len);
        }
    }
    $newId = save_event($data, $id);
    return ['updated' => mcp_occurrence($newId, $occ !== '' && $scope !== 'one' ? $occ : substr($data['start_at'], 0, 10))];
}

function tool_delete_event(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    $ev = find_event($id);
    if (!$ev) {
        throw new InvalidArgumentException('Deze afspraak bestaat niet (meer).');
    }
    $scope = (string) ($a['scope'] ?? 'all');
    $occ = isset($a['occ']) ? mcp_date($a['occ'], 'occ') : '';
    if ($ev['recurring'] && $scope !== 'all' && $occ === '') {
        throw new InvalidArgumentException('Geef occ (de datum van die keer) bij scope "one" of "future".');
    }
    delete_event($id, $occ, $scope);
    return ['deleted' => $ev['title'], 'scope' => $ev['recurring'] ? $scope : 'all'];
}

function tool_find_contacts(array $a): array
{
    $q = trim((string) ($a['query'] ?? ''));
    $sql = 'SELECT c.*, h.name AS household_name FROM fp_contacts c LEFT JOIN fp_households h ON h.id = c.household_id';
    $params = [];
    if (!empty($a['id'])) {
        $sql .= ' WHERE c.id = ?';
        $params[] = (int) $a['id'];
    } elseif ($q !== '') {
        $sql .= ' WHERE CONCAT_WS(\' \', c.first_name, c.last_name, c.nickname, h.name) LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $stmt = db()->prepare($sql . ' ORDER BY c.is_favorite DESC, c.is_child DESC, c.first_name LIMIT 25');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt as $c) {
        $out[] = array_filter(['id' => (int) $c['id'], 'name' => contact_name($c), 'child' => (bool) $c['is_child'],
            'relation' => RELATIONS[$c['relation']][0] ?? null, 'family' => $c['household_name'], 'family_id' => $c['household_id'] ? (int) $c['household_id'] : null], function ($v) {
            return $v !== null && $v !== '';
        });
    }
    return ['contacts' => $out];
}

function mcp_gezin_json(int $hid): ?array
{
    $stmt = db()->prepare('SELECT * FROM fp_households WHERE id = ?');
    $stmt->execute([$hid]);
    if (!($h = $stmt->fetch())) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM fp_contacts WHERE household_id = ? ORDER BY is_child, first_name');
    $stmt->execute([$hid]);
    $people = [];
    foreach ($stmt as $c) {
        $people[] = ['id' => (int) $c['id'], 'name' => contact_name($c), 'child' => (bool) $c['is_child']];
    }
    return ['id' => (int) $h['id'], 'name' => $h['name'], 'people' => $people];
}

function tool_list_gezinnen(array $a): array
{
    $out = [];
    foreach (db()->query('SELECT id FROM fp_households ORDER BY name') as $r) {
        $out[] = mcp_gezin_json((int) $r['id']);
    }
    return ['gezinnen' => $out];
}

function tool_link_to_gezin(array $a): array
{
    $ids = mcp_ids($a['contact_ids'] ?? []);
    if (count($ids) < 1) {
        throw new InvalidArgumentException('Geef contact_ids (bijv. de moeder en haar kinderen).');
    }
    $rows = [];
    foreach ($ids as $id) {
        $c = find_contact_row($id);
        if (!$c) {
            throw new InvalidArgumentException("Er is geen contact met id $id (gebruik find_contacts).");
        }
        $rows[$id] = $c;
    }
    $hid = !empty($a['gezin_id']) ? (int) $a['gezin_id'] : 0;
    if ($hid && !mcp_gezin_json($hid)) {
        throw new InvalidArgumentException("Er is geen gezin met id $hid (gebruik list_gezinnen).");
    }
    if (!$hid) {
        $existing = array_values(array_unique(array_filter(array_map(function ($c) {
            return (int) $c['household_id'];
        }, $rows))));
        if (count($existing) > 1) {
            throw new InvalidArgumentException('Deze mensen zitten al in verschillende gezinnen (' . implode(', ', $existing) . '). Kies er een met gezin_id.');
        }
        if ($existing) {
            $hid = $existing[0];
        } else {
            $name = trim((string) ($a['gezin_name'] ?? ''));
            if ($name === '') {
                $kids = array_map(function ($c) {
                    return contact_name($c, false);
                }, array_filter($rows, function ($c) {
                    return $c['is_child'];
                }));
                $name = implode('/', $kids) ?: implode('/', array_map(function ($c) {
                    return contact_name($c, false);
                }, $rows));
            }
            db()->prepare('INSERT INTO fp_households (name) VALUES (?)')->execute([mb_cut($name, 120)]);
            $hid = (int) db()->lastInsertId();
        }
    }
    foreach ($ids as $id) {
        db()->prepare('UPDATE fp_contacts SET household_id = ? WHERE id = ?')->execute([$hid, $id]);
    }
    return ['gezin' => mcp_gezin_json($hid)];
}

function tool_rename_gezin(array $a): array
{
    $hid = (int) ($a['gezin_id'] ?? 0);
    $name = trim((string) ($a['name'] ?? ''));
    if (!mcp_gezin_json($hid) || $name === '') {
        throw new InvalidArgumentException('Geef een bestaand gezin_id (list_gezinnen) en een nieuwe naam.');
    }
    db()->prepare('UPDATE fp_households SET name = ? WHERE id = ?')->execute([mb_cut($name, 120), $hid]);
    return ['gezin' => mcp_gezin_json($hid)];
}

function tool_list_tasks(array $a): array
{
    $opt = [];
    if (!empty($a['member_id'])) {
        $opt['member'] = (int) $a['member_id'];
    }
    if (!empty($a['include_done'])) {
        $opt['include_done_since'] = date('Y-m-d', strtotime('-7 day'));
    }
    return ['tasks' => array_map('mcp_task', load_tasks($opt))];
}

function tool_add_task(array $a): array
{
    $title = trim((string) ($a['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('Geef in wat er moet gebeuren.');
    }
    $rec = (string) ($a['repeats'] ?? '');
    if ($rec !== '' && !isset(TASK_RECURRENCES[$rec])) {
        throw new InvalidArgumentException('Onbekende herhaling. Kies uit: DAILY, WEEKLY, MONTHLY.');
    }
    $member = !empty($a['member_id']) ? mcp_member_ids([$a['member_id']])[0] : null;
    $id = add_task(['title' => $title, 'member_id' => $member, 'due_date' => !empty($a['due']) ? mcp_date($a['due'], 'due') : null,
        'recurrence' => $rec, 'notes' => $a['notes'] ?? null, 'event_id' => !empty($a['event_id']) && find_event((int) $a['event_id']) ? (int) $a['event_id'] : null]);
    return ['added' => mcp_task(find_task($id))];
}

function tool_update_task(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    $t = find_task($id);
    if (!$t) {
        throw new InvalidArgumentException('Deze taak bestaat niet (meer).');
    }
    if (array_key_exists('title', $a) && trim((string) $a['title']) !== '') {
        db()->prepare('UPDATE fp_tasks SET title = ? WHERE id = ?')->execute([mb_cut(trim((string) $a['title']), 190), $id]);
    }
    if (array_key_exists('member_id', $a)) {
        $m = $a['member_id'] ? mcp_member_ids([$a['member_id']])[0] : null;
        db()->prepare('UPDATE fp_tasks SET member_id = ? WHERE id = ?')->execute([$m, $id]);
    }
    if (array_key_exists('due', $a)) {
        db()->prepare('UPDATE fp_tasks SET due_date = ? WHERE id = ?')->execute([$a['due'] ? mcp_date($a['due'], 'due') : null, $id]);
    }
    if (isset($a['done'])) {
        toggle_task($id, !empty($a['done'])); // a repeating task moves on to its next date
    }
    return ['task' => mcp_task(find_task($id))];
}

function tool_delete_task(array $a): array
{
    $t = find_task((int) ($a['id'] ?? 0));
    if (!$t) {
        throw new InvalidArgumentException('Deze taak bestaat niet (meer).');
    }
    db()->prepare('DELETE FROM fp_tasks WHERE id = ?')->execute([$t['id']]);
    return ['deleted' => $t['title']];
}

function tool_get_shopping_list(array $a): array
{
    [$open, $done] = shopping_list();
    $fmt = function ($s) {
        return array_filter(['id' => (int) $s['id'], 'name' => $s['name'], 'amount' => $s['amount']], 'strlen');
    };
    $out = [];
    foreach ($open as $cat => $items) {
        $out[SHOP_CATEGORIES[$cat][1] ?? $cat] = array_map($fmt, $items);
    }
    return ['open' => $out, 'recently_ticked' => array_map($fmt, array_slice($done, 0, 15))];
}

function tool_add_shopping(array $a): array
{
    $items = (array) ($a['items'] ?? []);
    if (!$items) {
        throw new InvalidArgumentException('Geef minstens één item.');
    }
    $n = 0;
    foreach ($items as $i) {
        $name = trim((string) (is_array($i) ? ($i['name'] ?? '') : $i));
        $amount = is_array($i) && isset($i['amount']) && trim((string) $i['amount']) !== '' ? trim((string) $i['amount']) : null;
        $n += add_shopping_item($name, $amount);
    }
    return ['added' => $n, 'list' => tool_get_shopping_list([])['open']];
}

function mcp_shop_find(array $a): array
{
    if (!empty($a['id'])) {
        $stmt = db()->prepare('SELECT * FROM fp_shopping WHERE id = ?');
        $stmt->execute([(int) $a['id']]);
    } else {
        $name = trim((string) ($a['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Geef id of name van het item.');
        }
        $stmt = db()->prepare('SELECT * FROM fp_shopping WHERE LOWER(name) LIKE LOWER(?) ORDER BY done_at IS NOT NULL, id LIMIT 1');
        $stmt->execute(['%' . $name . '%']);
    }
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Dat staat niet op de boodschappenlijst.');
    }
    return $row;
}

function tool_tick_shopping(array $a): array
{
    $row = mcp_shop_find($a);
    $done = !isset($a['done']) || !empty($a['done']);
    db()->prepare('UPDATE fp_shopping SET done_at = ? WHERE id = ?')->execute([$done ? date('Y-m-d H:i:s') : null, $row['id']]);
    return ['item' => $row['name'], 'done' => $done];
}

function tool_remove_shopping(array $a): array
{
    if (!empty($a['clear_ticked'])) {
        $n = db()->exec('DELETE FROM fp_shopping WHERE done_at IS NOT NULL');
        return ['removed_ticked_items' => (int) $n];
    }
    $row = mcp_shop_find($a);
    db()->prepare('DELETE FROM fp_shopping WHERE id = ?')->execute([$row['id']]);
    return ['removed' => $row['name']];
}

function tool_get_menu(array $a): array
{
    seed_recipes();
    $from = mcp_date($a['from'] ?? today(), 'from');
    $to = mcp_date($a['to'] ?? date('Y-m-d', strtotime("$from +6 day")), 'to');
    $out = [];
    foreach (meals_between($from, $to) as $day => $m) {
        $out[] = array_filter(['day' => $day, 'dinner' => meal_title($m), 'recipe_id' => $m['recipe_id'] ? (int) $m['recipe_id'] : null,
            'cook' => $m['cook_member_id'] ? (mcp_names([$m['cook_member_id']])[0] ?? null) : null], function ($v) {
            return $v !== null && $v !== '';
        });
    }
    return ['menu' => $out];
}

function tool_list_recipes(array $a): array
{
    seed_recipes();
    $q = mb_strtolower(trim((string) ($a['query'] ?? '')));
    $out = [];
    foreach (recipes() as $id => $r) {
        if ($q === '' || mb_strpos(mb_strtolower($r['name']), $q) !== false) {
            $out[] = ['id' => $id, 'name' => $r['name']];
        }
    }
    return ['recipes' => array_slice($out, 0, 60)];
}

function tool_set_dinner(array $a): array
{
    seed_recipes();
    $day = mcp_date($a['day'] ?? '', 'day');
    $rid = !empty($a['recipe_id']) ? (int) $a['recipe_id'] : null;
    if ($rid && !isset(recipes()[$rid])) {
        throw new InvalidArgumentException('Dat recept bestaat niet (gebruik list_recipes).');
    }
    $title = trim((string) ($a['title'] ?? ''));
    if (!$rid && $title === '') {
        throw new InvalidArgumentException('Geef een recipe_id of een title (bijv. "Pannenkoeken", "Uit eten").');
    }
    $cook = !empty($a['cook_member_id']) ? mcp_member_ids([$a['cook_member_id']])[0] : null;
    db()->prepare('INSERT INTO fp_meals (day, recipe_id, title, cook_member_id) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE recipe_id = VALUES(recipe_id), title = VALUES(title), cook_member_id = VALUES(cook_member_id), on_list = 0')
        ->execute([$day, $rid, $rid ? null : mb_cut($title, 160), $cook]);
    if ($rid) {
        wishes_fulfilled($rid);
    }
    return ['dinner_set' => ['day' => $day, 'dinner' => $rid ? recipes()[$rid]['name'] : $title]];
}

function tool_clear_dinner(array $a): array
{
    $day = mcp_date($a['day'] ?? '', 'day');
    db()->prepare('DELETE FROM fp_meals WHERE day = ?')->execute([$day]);
    return ['cleared' => $day];
}

function tool_menu_to_shopping(array $a): array
{
    $from = mcp_date($a['from'] ?? today(), 'from');
    $to = mcp_date($a['to'] ?? date('Y-m-d', strtotime("$from +6 day")), 'to');
    return ['ingredients_added' => add_meals_to_list($from, $to)];
}

const MCP_MEMBER_IDS = ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Family member ids (from get_overview).'];

function mcp_event_props(): array
{
    return [
        'title' => ['type' => 'string', 'description' => 'Short title, e.g. "Voetbaltraining" or "Speelafspraak met Noah".'],
        'type' => ['type' => 'string', 'description' => 'Event type key from get_overview (PLAYDATE, PARTY, SPORT, ACTIVITY, OUTING, HOLIDAY, SCHOOL, FAMILY, BORREL, BBQ, DINNER, PARENTS, BABYSIT, APPOINTMENT, OTHER…).'],
        'location' => ['type' => 'string'],
        'description' => ['type' => 'string', 'description' => 'Notes.'],
        'all_day' => ['type' => 'boolean'],
        'member_ids' => MCP_MEMBER_IDS,
        'contact_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Guests from the address book (find_contacts).'],
        'recurrence' => ['type' => 'string', 'description' => 'DAILY, WEEKDAYS, WEEKLY, BIWEEKLY, MONTHLY, YEARLY or "" for no repeat.'],
        'recur_until' => ['type' => 'string', 'description' => 'Last date of a repeating event, YYYY-MM-DD.'],
        'brings' => ['type' => 'string', 'description' => 'Who brings: a member id, "c<contact id>", or "" for nobody.'],
        'picks_up' => ['type' => 'string', 'description' => 'Who picks up: a member id, "c<contact id>", or "" for nobody.'],
        'cost' => ['type' => 'number'],
    ];
}

function mcp_tools(): array
{
    return array_merge(mcp_core_tools(), mcp_more_tools());
}

function mcp_core_tools(): array
{
    $obj = function (array $props, array $required = []) {
        return ['type' => 'object', 'properties' => $props ?: new stdClass(), 'required' => $required];
    };
    $ev = mcp_event_props();
    return [
        ['name' => 'get_overview', 'handler' => 'tool_get_overview', 'description' => 'START HERE. Today\'s date, the family members with their ids, event types and options. Call this first in a conversation so you know the dates and ids.', 'inputSchema' => $obj([])],
        ['name' => 'list_events', 'handler' => 'tool_list_events', 'description' => 'Agenda: events (with repeats expanded) and birthdays between two dates, inclusive. Use before changing or deleting to find the event id and occ.', 'inputSchema' => $obj(['from' => ['type' => 'string', 'description' => 'YYYY-MM-DD (default today)'], 'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD inclusive (default = from)'], 'member_ids' => MCP_MEMBER_IDS])],
        ['name' => 'add_event', 'handler' => 'tool_add_event', 'description' => 'Add an agenda item. Times are Dutch local time. Resolve relative dates ("zaterdag") with get_overview\'s today. Check list_events first for conflicts when it matters.', 'inputSchema' => $obj(array_merge($ev, ['start' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM (or only YYYY-MM-DD for an all-day item).'], 'end' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM (default: start + 1 hour).']]), ['title', 'start'])],
        ['name' => 'update_event', 'handler' => 'tool_update_event', 'description' => 'Change an agenda item; only the fields you pass change. For a repeating event pass occ (date of the occurrence from list_events) and scope: "one" (just that day) or "all" (the whole series).', 'inputSchema' => $obj(array_merge($ev, ['id' => ['type' => 'integer'], 'occ' => ['type' => 'string', 'description' => 'YYYY-MM-DD of the occurrence, for repeating events.'], 'scope' => ['type' => 'string', 'enum' => ['one', 'all']], 'start' => ['type' => 'string', 'description' => 'New start; the length is kept unless end is also given.'], 'end' => ['type' => 'string']]), ['id'])],
        ['name' => 'delete_event', 'handler' => 'tool_delete_event', 'description' => 'Delete an agenda item. For repeating events: scope "one" (only occ), "future" (occ and later) or "all". Confirm with the user first when it is not completely clear which item.', 'inputSchema' => $obj(['id' => ['type' => 'integer'], 'occ' => ['type' => 'string', 'description' => 'YYYY-MM-DD, needed for scope one/future.'], 'scope' => ['type' => 'string', 'enum' => ['one', 'future', 'all']]], ['id'])],
        ['name' => 'find_contacts', 'handler' => 'tool_find_contacts', 'description' => 'Search the address book (friends, classmates, family, babysitters) by name to get contact ids for guests.', 'inputSchema' => $obj(['query' => ['type' => 'string'], 'id' => ['type' => 'integer', 'description' => 'Look up one contact by id.']])],
        ['name' => 'list_gezinnen', 'handler' => 'tool_list_gezinnen', 'description' => 'All gezinnen (one family at one address in the address book) with their people.', 'inputSchema' => $obj([])],
        ['name' => 'link_to_gezin', 'handler' => 'tool_link_to_gezin', 'description' => 'Put address-book people (e.g. a mother and her children) together in one gezin. Uses the gezin they are already in, a given gezin_id, or creates a new one (gezin_name, default: the children\'s names). Always verify the contact ids with find_contacts first.', 'inputSchema' => $obj(['contact_ids' => ['type' => 'array', 'items' => ['type' => 'integer']], 'gezin_id' => ['type' => 'integer'], 'gezin_name' => ['type' => 'string']], ['contact_ids'])],
        ['name' => 'rename_gezin', 'handler' => 'tool_rename_gezin', 'description' => 'Rename a gezin (e.g. to the family surname).', 'inputSchema' => $obj(['gezin_id' => ['type' => 'integer'], 'name' => ['type' => 'string']], ['gezin_id', 'name'])],
        ['name' => 'list_tasks', 'handler' => 'tool_list_tasks', 'description' => 'Open to-dos / chores ("wie doet wat"), optionally for one family member.', 'inputSchema' => $obj(['member_id' => ['type' => 'integer'], 'include_done' => ['type' => 'boolean', 'description' => 'Also tasks ticked off in the last 7 days.']])],
        ['name' => 'add_task', 'handler' => 'tool_add_task', 'description' => 'Add a to-do or chore.', 'inputSchema' => $obj(['title' => ['type' => 'string'], 'member_id' => ['type' => 'integer', 'description' => 'Who does it.'], 'due' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'repeats' => ['type' => 'string', 'enum' => ['', 'DAILY', 'WEEKLY', 'MONTHLY']], 'notes' => ['type' => 'string'], 'event_id' => ['type' => 'integer', 'description' => 'Put it on the checklist of this event.']], ['title'])],
        ['name' => 'update_task', 'handler' => 'tool_update_task', 'description' => 'Change a task or tick it off (done: true). A repeating task moves on to its next date. Pass member_id 0 / due "" to clear them.', 'inputSchema' => $obj(['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'member_id' => ['type' => 'integer'], 'due' => ['type' => 'string'], 'done' => ['type' => 'boolean']], ['id'])],
        ['name' => 'delete_task', 'handler' => 'tool_delete_task', 'description' => 'Delete a task.', 'inputSchema' => $obj(['id' => ['type' => 'integer']], ['id'])],
        ['name' => 'get_shopping_list', 'handler' => 'tool_get_shopping_list', 'description' => 'The shared shopping list (boodschappen), by supermarket category.', 'inputSchema' => $obj([])],
        ['name' => 'add_shopping', 'handler' => 'tool_add_shopping', 'description' => 'Put items on the shopping list. The same open item gets its amount added instead of a duplicate.', 'inputSchema' => $obj(['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'amount' => ['type' => 'string', 'description' => 'e.g. "2" or "500 g"']], 'required' => ['name']]]], ['items'])],
        ['name' => 'tick_shopping', 'handler' => 'tool_tick_shopping', 'description' => 'Tick an item on the shopping list as bought (done: false to untick). Give id or (part of) the name.', 'inputSchema' => $obj(['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'done' => ['type' => 'boolean']])],
        ['name' => 'remove_shopping', 'handler' => 'tool_remove_shopping', 'description' => 'Remove one item from the shopping list (id or name), or all ticked items with clear_ticked: true.', 'inputSchema' => $obj(['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'clear_ticked' => ['type' => 'boolean']])],
        ['name' => 'get_menu', 'handler' => 'tool_get_menu', 'description' => 'What is planned for dinner (default: the next 7 days).', 'inputSchema' => $obj(['from' => ['type' => 'string'], 'to' => ['type' => 'string']])],
        ['name' => 'list_recipes', 'handler' => 'tool_list_recipes', 'description' => 'The family\'s recipes (id + name), optionally filtered by a word.', 'inputSchema' => $obj(['query' => ['type' => 'string']])],
        ['name' => 'set_dinner', 'handler' => 'tool_set_dinner', 'description' => 'Plan dinner for a day: a recipe_id or a free title ("Uit eten", "Restjes"). Replaces what was planned.', 'inputSchema' => $obj(['day' => ['type' => 'string'], 'recipe_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'cook_member_id' => ['type' => 'integer']], ['day'])],
        ['name' => 'clear_dinner', 'handler' => 'tool_clear_dinner', 'description' => 'Remove the planned dinner of a day.', 'inputSchema' => $obj(['day' => ['type' => 'string']], ['day'])],
        ['name' => 'menu_to_shopping', 'handler' => 'tool_menu_to_shopping', 'description' => 'Put the ingredients of the planned dinners (default: next 7 days) on the shopping list.', 'inputSchema' => $obj(['from' => ['type' => 'string'], 'to' => ['type' => 'string']])],
    ];
}

require __DIR__ . '/lib/mcp_more.php'; // address book relations, groups, ideas, bucketlist, recipes…

const MCP_INSTRUCTIONS = 'This is the Familie Planner of a Dutch family (parents and children). The user usually speaks Dutch: answer in Dutch, short and friendly. '
    . 'Call get_overview first to learn today\'s date and the member ids. Convert relative dates ("morgen", "zaterdag") to YYYY-MM-DD yourself and use 24h local time. '
    . 'Before editing or deleting, look the item up (list_events / list_tasks / get_shopping_list) to get the right id; if several items match, ask which one. '
    . 'The address book has relations: a person can be in a gezin (one family at one address), a vriendje/vriendin OF a family member (set_friend_of), and in groups (class, team, club). Search (find_contacts) before adding a person to avoid duplicates, and check ids with get_contact. After a change, tell the user in one sentence what you did (title, day, time). Never invent ids.';

function mcp_call(string $name, array $args): array
{
    foreach (mcp_tools() as $t) {
        if ($t['name'] === $name) {
            try {
                $res = $t['handler']($args);
                return ['content' => [['type' => 'text', 'text' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]]];
            } catch (InvalidArgumentException $e) {
                return ['isError' => true, 'content' => [['type' => 'text', 'text' => $e->getMessage()]]];
            } catch (Throwable $e) {
                error_log('[familyplanner mcp] ' . $e);
                return ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Er ging iets mis op de server.']]];
            }
        }
    }
    return ['isError' => true, 'content' => [['type' => 'text', 'text' => "Onbekende tool: $name"]]];
}

/** One JSON-RPC message → response array, or null for notifications. */
function mcp_handle($msg): ?array
{
    if (!is_array($msg) || ($msg['jsonrpc'] ?? '') !== '2.0' || !isset($msg['method'])) {
        return rpc_error($msg['id'] ?? null, -32600, 'Ongeldig verzoek.');
    }
    $id = $msg['id'] ?? null;
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    if (!array_key_exists('id', $msg)) {
        return null; // notification (e.g. notifications/initialized)
    }
    switch ($msg['method']) {
        case 'initialize':
            $supported = ['2025-06-18', '2025-03-26', '2024-11-05'];
            $ver = in_array($params['protocolVersion'] ?? '', $supported, true) ? $params['protocolVersion'] : $supported[0];
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => $ver,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'familie-planner', 'version' => '1.0.0'],
                'instructions' => MCP_INSTRUCTIONS,
            ]];
        case 'ping':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()];
        case 'tools/list':
            $tools = array_map(function ($t) {
                unset($t['handler']);
                return $t;
            }, mcp_tools());
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => $tools]];
        case 'tools/call':
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $result = mcp_call((string) ($params['name'] ?? ''), $args);
            if (empty($result['isError']) && !in_array((string) ($params['name'] ?? ''), ['get_overview'], true)) {
                gcal_schedule(true); // keep connected Google calendars in step (after the response)
            }
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        case 'resources/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => []]];
        case 'prompts/list':
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => []]];
        default:
            return rpc_error($id, -32601, 'Onbekende methode.');
    }
}

$body = json_decode((string) file_get_contents('php://input'), true);
if ($body === null) {
    rpc_out(rpc_error(null, -32700, 'Ongeldige JSON.'), 400);
}
if (is_array($body) && isset($body[0])) {
    $out = array_values(array_filter(array_map('mcp_handle', $body)));
    if (!$out) {
        http_response_code(202);
        exit;
    }
    rpc_out($out);
}
$res = mcp_handle($body);
if ($res === null) {
    http_response_code(202);
    exit;
}
rpc_out($res);
