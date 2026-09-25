<?php
// Calendar logic: loading events (with repeating events expanded into occurrences),
// birthdays as virtual all-day items, and saving / moving / deleting.
// Times are local (Europe/Amsterdam) wall-clock DATETIMEs; all-day events run from
// 00:00 on the first day to 23:59 on the last day.

function event_type(string $type): array
{
    return EVENT_TYPES[$type] ?? EVENT_TYPES['OTHER'];
}

function event_color(array $ev): string
{
    return $ev['color'] ?: event_type($ev['type'])[2];
}

/** The n-th occurrence start of a repeating event, or null when that date doesn't exist (31 Feb). */
function occurrence_start(DateTime $base, string $rule, int $n): ?DateTime
{
    $d = clone $base;
    switch ($rule) {
        case 'DAILY':
            return $d->modify("+$n day");
        case 'WEEKLY':
            return $d->modify('+' . (7 * $n) . ' day');
        case 'BIWEEKLY':
            return $d->modify('+' . (14 * $n) . ' day');
        case 'MONTHLY':
        case 'YEARLY':
            $months = $rule === 'MONTHLY' ? $n : 12 * $n;
            $y = (int) $base->format('Y');
            $m = (int) $base->format('n') + $months;
            $y += intdiv($m - 1, 12);
            $m = ($m - 1) % 12 + 1;
            $day = (int) $base->format('j');
            if (!checkdate($m, $day, $y)) {
                return null; // Google skips months without this day, so do we
            }
            return $d->setDate($y, $m, $day);
    }
    return null;
}

/** Rough period in days, to jump close to the requested range without looping from the start. */
function rule_days(string $rule): int
{
    // Rounded up for months/years so the jump never passes an occurrence in range
    $days = ['DAILY' => 1, 'WEEKLY' => 7, 'BIWEEKLY' => 14, 'MONTHLY' => 31, 'YEARLY' => 366];
    return $days[$rule] ?? 1;
}

/**
 * Event occurrences overlapping [$from, $to) (dates Y-m-d).
 * Filters: members (ids; events without members are always included), types, contact (id), ids.
 * Each occurrence: the event row + occ (original date of this occurrence, Y-m-d), start_at/end_at of
 * the occurrence, members (ids), contacts (rows with rsvp), recurring, done.
 */
function load_events(string $from, string $to, array $filter = []): array
{
    $where = ["e.start_at < :to", "((e.recurrence = '' AND e.end_at >= :from) OR (e.recurrence <> '' AND (e.recur_until IS NULL OR e.recur_until >= :from2)))"];
    $params = [':from' => $from . ' 00:00:00', ':from2' => $from, ':to' => $to . ' 00:00:00'];
    if (!empty($filter['types'])) {
        $in = [];
        foreach (array_values($filter['types']) as $i => $t) {
            $in[] = ":t$i";
            $params[":t$i"] = $t;
        }
        $where[] = 'e.type IN (' . implode(',', $in) . ')';
    }
    if (!empty($filter['members'])) {
        $ids = implode(',', array_map('intval', $filter['members']));
        $where[] = "(EXISTS (SELECT 1 FROM fp_event_members em WHERE em.event_id = e.id AND em.member_id IN ($ids))
            OR NOT EXISTS (SELECT 1 FROM fp_event_members em2 WHERE em2.event_id = e.id))";
    }
    if (!empty($filter['contact'])) {
        $where[] = 'EXISTS (SELECT 1 FROM fp_event_contacts ec WHERE ec.event_id = e.id AND ec.contact_id = :contact)';
        $params[':contact'] = (int) $filter['contact'];
    }
    if (!empty($filter['ids'])) {
        $where[] = 'e.id IN (' . implode(',', array_map('intval', $filter['ids'])) . ')';
    }
    $stmt = db()->prepare('SELECT e.* FROM fp_events e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.start_at');
    $stmt->execute($params);
    $events = $stmt->fetchAll();
    if (!$events) {
        return [];
    }
    $ids = implode(',', array_map('intval', array_column($events, 'id')));

    $members = [];
    foreach (db()->query("SELECT event_id, member_id FROM fp_event_members WHERE event_id IN ($ids)") as $r) {
        $members[$r['event_id']][] = (int) $r['member_id'];
    }
    $contacts = [];
    foreach (db()->query("SELECT ec.event_id, ec.rsvp, c.id, c.first_name, c.last_name, c.nickname, c.photo, c.is_child, c.household_id
            FROM fp_event_contacts ec JOIN fp_contacts c ON c.id = ec.contact_id WHERE ec.event_id IN ($ids) ORDER BY c.first_name") as $r) {
        $contacts[$r['event_id']][] = $r;
    }
    $exceptions = [];
    $doneDates = [];
    $recurringIds = [];
    foreach ($events as $ev) {
        if ($ev['recurrence'] !== '') {
            $recurringIds[] = (int) $ev['id'];
        }
    }
    if ($recurringIds) {
        $rids = implode(',', $recurringIds);
        foreach (db()->query("SELECT event_id, occurs_on FROM fp_event_exceptions WHERE event_id IN ($rids)") as $r) {
            $exceptions[$r['event_id']][$r['occurs_on']] = true;
        }
        foreach (db()->query("SELECT event_id, occurs_on FROM fp_event_done WHERE event_id IN ($rids)") as $r) {
            $doneDates[$r['event_id']][$r['occurs_on']] = true;
        }
    }

    $out = [];
    foreach ($events as $ev) {
        $ev['members'] = $members[$ev['id']] ?? [];
        $ev['contacts'] = $contacts[$ev['id']] ?? [];
        $ev['recurring'] = $ev['recurrence'] !== '';
        if (!$ev['recurring']) {
            $ev['occ'] = substr($ev['start_at'], 0, 10);
            $ev['done'] = (bool) $ev['done'];
            $out[] = $ev;
            continue;
        }
        $base = new DateTime($ev['start_at']);
        $duration = strtotime($ev['end_at']) - strtotime($ev['start_at']);
        $durationDays = (int) round((strtotime(substr($ev['end_at'], 0, 10)) - strtotime(substr($ev['start_at'], 0, 10))) / 86400);
        $until = $ev['recur_until'] ?: '9999-12-31';
        // Start a little before the range so long events that began earlier still show
        $skip = max(0, (int) floor((strtotime($from) - strtotime($ev['start_at']) - $duration) / 86400 / rule_days($ev['recurrence'])) - 2);
        for ($n = $skip, $guard = 0; $guard < 1000; $n++, $guard++) {
            $s = occurrence_start($base, $ev['recurrence'], $n);
            if ($s === null) {
                continue;
            }
            $occ = $s->format('Y-m-d');
            if ($occ >= $to || $occ > $until) {
                break;
            }
            $e = clone $s;
            if ($ev['all_day']) {
                $e->modify("+$durationDays day")->setTime(23, 59);
            } else {
                $e->modify("+$duration second");
            }
            if ($e->format('Y-m-d H:i:s') < $from . ' 00:00:00' || isset($exceptions[$ev['id']][$occ])) {
                continue;
            }
            $o = $ev;
            $o['occ'] = $occ;
            $o['start_at'] = $s->format('Y-m-d H:i:s');
            $o['end_at'] = $e->format('Y-m-d H:i:s');
            $o['done'] = isset($doneDates[$ev['id']][$occ]);
            $out[] = $o;
        }
    }
    usort($out, function ($a, $b) {
        return [$b['all_day'], $a['start_at']] <=> [$a['all_day'], $b['start_at']];
    });
    return $out;
}

/** Events of one day, all-day first. */
function events_on(string $date, array $filter = []): array
{
    return load_events($date, date('Y-m-d', strtotime($date . ' +1 day')), $filter);
}

/**
 * Birthdays of contacts and family members between $from and $to (exclusive), sorted.
 * Each: date, age (turning, or null), person (row), kind 'member'|'contact', subject key.
 */
function load_birthdays(string $from, string $to, array $filter = []): array
{
    $out = [];
    $people = [];
    foreach (members() as $m) {
        $people[] = ['kind' => 'member', 'row' => $m];
    }
    $sql = 'SELECT c.*, h.name AS household_name FROM fp_contacts c LEFT JOIN fp_households h ON h.id = c.household_id WHERE c.birth_day IS NOT NULL AND c.birth_month IS NOT NULL';
    if (!empty($filter['members'])) {
        $ids = implode(',', array_map('intval', $filter['members']));
        $sql .= " AND (c.relation IN ('FAMILY','OWN_FRIEND') OR EXISTS (SELECT 1 FROM fp_contact_members cm WHERE cm.contact_id = c.id AND cm.member_id IN ($ids)))";
    }
    foreach (db()->query($sql) as $c) {
        $people[] = ['kind' => 'contact', 'row' => $c];
    }
    foreach ($people as $p) {
        $row = $p['row'];
        if (empty($row['birth_day']) || empty($row['birth_month'])) {
            continue;
        }
        $cursor = $from;
        while ($cursor < $to) {
            $date = next_birthday((int) $row['birth_day'], (int) $row['birth_month'], $cursor);
            if ($date === null || $date >= $to) {
                break;
            }
            $out[] = [
                'date' => $date,
                'age' => $row['birth_year'] ? (int) substr($date, 0, 4) - (int) $row['birth_year'] : null,
                'person' => $row,
                'kind' => $p['kind'],
                'subject' => ($p['kind'] === 'member' ? 'm' : 'c') . $row['id'],
                'name' => $p['kind'] === 'member' ? $row['name'] : contact_name($row),
            ];
            $cursor = date('Y-m-d', strtotime($date . ' +1 day'));
        }
    }
    usort($out, function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    return $out;
}

function find_event(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM fp_events WHERE id = ?');
    $stmt->execute([$id]);
    $ev = $stmt->fetch();
    if (!$ev) {
        return null;
    }
    $stmt = db()->prepare('SELECT member_id FROM fp_event_members WHERE event_id = ?');
    $stmt->execute([$id]);
    $ev['members'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = db()->prepare('SELECT ec.rsvp, c.* FROM fp_event_contacts ec JOIN fp_contacts c ON c.id = ec.contact_id WHERE ec.event_id = ? ORDER BY c.first_name');
    $stmt->execute([$id]);
    $ev['contacts'] = $stmt->fetchAll();
    $ev['recurring'] = $ev['recurrence'] !== '';
    return $ev;
}

/**
 * Validate and normalise event input (from the API or a form).
 * Input keys: title, type, start (Y-m-d or Y-m-d H:i), end, all_day, location, host, description, color,
 * recurrence, recur_until, drop_member_id, pickup_member_id, cost, paid, members (ids), contacts (ids or id=>rsvp).
 */
function normalise_event(array $in): array
{
    $allDay = !empty($in['all_day']);
    $title = trim((string) ($in['title'] ?? ''));
    $type = isset(EVENT_TYPES[$in['type'] ?? '']) ? $in['type'] : 'OTHER';
    if ($title === '') {
        $title = event_type($type)[0];
    }
    $start = strtotime(str_replace('T', ' ', (string) ($in['start'] ?? '')));
    $end = strtotime(str_replace('T', ' ', (string) ($in['end'] ?? '')));
    if (!$start) {
        throw new InvalidArgumentException('Kies een begindatum.');
    }
    if (!$end) {
        $end = $allDay ? $start : $start + 3600;
    }
    if ($allDay) {
        $startAt = date('Y-m-d 00:00:00', $start);
        $endAt = date('Y-m-d 23:59:00', max($end, $start));
    } else {
        if ($end <= $start) {
            $end = $start + 15 * 60;
        }
        $startAt = date('Y-m-d H:i:00', $start);
        $endAt = date('Y-m-d H:i:00', $end);
    }
    $recurrence = isset(RECURRENCES[$in['recurrence'] ?? '']) ? (string) $in['recurrence'] : '';
    $until = !empty($in['recur_until']) && strtotime($in['recur_until']) ? date('Y-m-d', strtotime($in['recur_until'])) : null;
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($in['color'] ?? '')) ? $in['color'] : null;
    $memberIds = array_values(array_intersect(array_map('intval', (array) ($in['members'] ?? [])), array_keys(members())));
    // contacts: list of ids or of {id, rsvp}; rsvp: optional map id => rsvp (forms)
    $contacts = [];
    $rsvpMap = (array) ($in['rsvp'] ?? []);
    foreach ((array) ($in['contacts'] ?? []) as $v) {
        $cid = (int) (is_array($v) ? ($v['id'] ?? 0) : $v);
        $rsvp = is_array($v) ? ($v['rsvp'] ?? '') : ($rsvpMap[$cid] ?? '');
        if ($cid > 0) {
            $contacts[$cid] = isset(RSVPS[$rsvp]) ? $rsvp : '';
        }
    }
    $memberOrNull = function ($v) {
        return $v && member((int) $v) ? (int) $v : null;
    };
    $cost = isset($in['cost']) && $in['cost'] !== '' && $in['cost'] !== null ? round((float) str_replace(',', '.', (string) $in['cost']), 2) : null;
    return [
        'title' => mb_cut($title, 160),
        'type' => $type,
        'start_at' => $startAt,
        'end_at' => $endAt,
        'all_day' => $allDay ? 1 : 0,
        'location' => trim((string) ($in['location'] ?? '')) ?: null,
        'host' => isset(HOSTS[$in['host'] ?? '']) ? (string) $in['host'] : '',
        'description' => trim((string) ($in['description'] ?? '')) ?: null,
        'color' => $color,
        'recurrence' => $recurrence,
        'recur_until' => $recurrence ? $until : null,
        'drop_member_id' => $memberOrNull($in['drop_member_id'] ?? null),
        'pickup_member_id' => $memberOrNull($in['pickup_member_id'] ?? null),
        'cost' => $cost,
        'paid' => !empty($in['paid']) ? 1 : 0,
        '_members' => $memberIds,
        '_contacts' => $contacts,
    ];
}

/** Insert or update an event from normalised data. Returns the id. */
function save_event(array $data, ?int $id = null): int
{
    $pdo = db();
    $members = $data['_members'];
    $contacts = $data['_contacts'];
    unset($data['_members'], $data['_contacts']);
    $pdo->beginTransaction();
    try {
        if ($id) {
            $sets = implode(', ', array_map(function ($k) {
                return "$k = :$k";
            }, array_keys($data)));
            $stmt = $pdo->prepare("UPDATE fp_events SET $sets WHERE id = :id");
            $stmt->execute(array_merge($data, ['id' => $id]));
        } else {
            $user = current_user();
            $data['created_by'] = $user ? $user['id'] : null;
            $cols = implode(', ', array_keys($data));
            $vals = ':' . implode(', :', array_keys($data));
            $pdo->prepare("INSERT INTO fp_events ($cols) VALUES ($vals)")->execute($data);
            $id = (int) $pdo->lastInsertId();
        }
        set_event_people($id, $members, $contacts);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

function set_event_people(int $id, array $members, array $contacts): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM fp_event_members WHERE event_id = ?')->execute([$id]);
    $ins = $pdo->prepare('INSERT INTO fp_event_members (event_id, member_id) VALUES (?, ?)');
    foreach ($members as $m) {
        $ins->execute([$id, $m]);
    }
    $pdo->prepare('DELETE FROM fp_event_contacts WHERE event_id = ?')->execute([$id]);
    $ins = $pdo->prepare('INSERT IGNORE INTO fp_event_contacts (event_id, contact_id, rsvp) SELECT ?, id, ? FROM fp_contacts WHERE id = ?');
    foreach ($contacts as $cid => $rsvp) {
        $ins->execute([$id, $rsvp, $cid]);
    }
}

/** Copy one occurrence of a repeating event into its own single event and hide it in the series. */
function detach_occurrence(array $ev, string $occ): int
{
    $pdo = db();
    $startTime = substr($ev['start_at'], 11);
    $durationDays = (int) round((strtotime(substr($ev['end_at'], 0, 10)) - strtotime(substr($ev['start_at'], 0, 10))) / 86400);
    $duration = strtotime($ev['end_at']) - strtotime($ev['start_at']);
    $start = $occ . ' ' . $startTime;
    $end = $ev['all_day']
        ? date('Y-m-d 23:59:00', strtotime("$occ +$durationDays day"))
        : date('Y-m-d H:i:s', strtotime($start) + $duration);
    $pdo->prepare('INSERT IGNORE INTO fp_event_exceptions (event_id, occurs_on) VALUES (?, ?)')->execute([$ev['id'], $occ]);
    $data = [
        'title' => $ev['title'], 'type' => $ev['type'], 'start' => $start, 'end' => $end, 'all_day' => $ev['all_day'],
        'location' => $ev['location'], 'host' => $ev['host'], 'description' => $ev['description'], 'color' => $ev['color'],
        'drop_member_id' => $ev['drop_member_id'], 'pickup_member_id' => $ev['pickup_member_id'],
        'cost' => $ev['cost'], 'paid' => $ev['paid'], 'members' => $ev['members'],
        'contacts' => array_map(function ($c) {
            return ['id' => $c['id'], 'rsvp' => $c['rsvp']];
        }, $ev['contacts']),
    ];
    $newId = save_event(normalise_event($data));
    $stmt = $pdo->prepare('SELECT 1 FROM fp_event_done WHERE event_id = ? AND occurs_on = ?');
    $stmt->execute([$ev['id'], $occ]);
    if ($stmt->fetchColumn()) {
        $pdo->prepare('UPDATE fp_events SET done = 1 WHERE id = ?')->execute([$newId]);
    }
    // Checklist items belong to the series; copy them so the single event keeps its list
    $pdo->prepare('INSERT INTO fp_tasks (title, notes, member_id, contact_id, event_id, due_date, sort, created_by)
        SELECT title, notes, member_id, contact_id, ?, due_date, sort, created_by FROM fp_tasks WHERE event_id = ?')->execute([$newId, $ev['id']]);
    return $newId;
}

/**
 * Move/resize: new start and end for occurrence $occ. $scope 'one' or 'all' (only matters when repeating).
 * Returns the id of the event that now holds this occurrence.
 */
function move_event(int $id, string $occ, string $newStart, string $newEnd, bool $allDay, string $scope): int
{
    $ev = find_event($id);
    if (!$ev) {
        throw new InvalidArgumentException('Deze afspraak bestaat niet meer.');
    }
    if ($ev['recurring'] && $scope === 'one') {
        $id = detach_occurrence($ev, $occ);
        $ev = find_event($id);
        $occ = substr($ev['start_at'], 0, 10);
    }
    $ns = strtotime(str_replace('T', ' ', $newStart));
    $ne = strtotime(str_replace('T', ' ', $newEnd));
    if (!$ns || !$ne) {
        throw new InvalidArgumentException('Ongeldige tijd.');
    }
    if ($ev['recurring']) {
        // Shift the whole series by how far this occurrence moved
        $occStart = strtotime($occ . ' ' . substr($ev['start_at'], 11));
        $shift = $ns - $occStart;
        $ns = strtotime($ev['start_at']) + $shift;
        $ne = $ns + ($ne - strtotime(str_replace('T', ' ', $newStart)));
        if ($shift !== 0 && abs($shift) >= 86400) {
            // Moving a series to another day: done/exception dates no longer line up
            db()->prepare('DELETE FROM fp_event_done WHERE event_id = ?')->execute([$id]);
        }
    }
    if ($allDay) {
        $start = date('Y-m-d 00:00:00', $ns);
        $end = date('Y-m-d 23:59:00', max($ne, $ns));
    } else {
        if ($ne <= $ns) {
            $ne = $ns + 15 * 60;
        }
        $start = date('Y-m-d H:i:00', $ns);
        $end = date('Y-m-d H:i:00', $ne);
    }
    db()->prepare('UPDATE fp_events SET start_at = ?, end_at = ?, all_day = ? WHERE id = ?')->execute([$start, $end, $allDay ? 1 : 0, $id]);
    return $id;
}

/** $scope: 'one' (just this occurrence), 'future' (this and later), 'all'. */
function delete_event(int $id, string $occ, string $scope): void
{
    $ev = find_event($id);
    if (!$ev) {
        return;
    }
    if ($ev['recurring'] && $scope === 'one') {
        db()->prepare('INSERT IGNORE INTO fp_event_exceptions (event_id, occurs_on) VALUES (?, ?)')->execute([$id, $occ]);
        return;
    }
    if ($ev['recurring'] && $scope === 'future' && $occ > substr($ev['start_at'], 0, 10)) {
        db()->prepare('UPDATE fp_events SET recur_until = ? WHERE id = ?')->execute([date('Y-m-d', strtotime("$occ -1 day")), $id]);
        return;
    }
    db()->prepare('DELETE FROM fp_events WHERE id = ?')->execute([$id]);
}

function set_event_done(int $id, string $occ, bool $done): void
{
    $ev = find_event($id);
    if (!$ev) {
        return;
    }
    if ($ev['recurring']) {
        if ($done) {
            db()->prepare('INSERT IGNORE INTO fp_event_done (event_id, occurs_on) VALUES (?, ?)')->execute([$id, $occ]);
        } else {
            db()->prepare('DELETE FROM fp_event_done WHERE event_id = ? AND occurs_on = ?')->execute([$id, $occ]);
        }
    } else {
        db()->prepare('UPDATE fp_events SET done = ? WHERE id = ?')->execute([$done ? 1 : 0, $id]);
    }
}

/** Compact array for the calendar front-end. */
function event_json(array $ev): array
{
    $t = event_type($ev['type']);
    return [
        'id' => (int) $ev['id'],
        'occ' => $ev['occ'] ?? substr($ev['start_at'], 0, 10),
        'title' => $ev['title'],
        'type' => $ev['type'],
        'emoji' => $t[1],
        'typeLabel' => $t[0],
        'color' => event_color($ev),
        'start' => substr($ev['start_at'], 0, 16),
        'end' => substr($ev['end_at'], 0, 16),
        'allDay' => (bool) $ev['all_day'],
        'location' => $ev['location'],
        'host' => $ev['host'],
        'description' => $ev['description'],
        'recurrence' => $ev['recurrence'],
        'recurUntil' => $ev['recur_until'],
        'recurring' => (bool) $ev['recurring'],
        'done' => (bool) $ev['done'],
        'members' => array_map('intval', $ev['members']),
        'dropMember' => $ev['drop_member_id'] ? (int) $ev['drop_member_id'] : null,
        'pickupMember' => $ev['pickup_member_id'] ? (int) $ev['pickup_member_id'] : null,
        'cost' => $ev['cost'] !== null ? (float) $ev['cost'] : null,
        'paid' => (bool) $ev['paid'],
        'contacts' => array_map(function ($c) {
            return [
                'id' => (int) $c['id'],
                'name' => contact_name($c, false),
                'fullName' => contact_name($c),
                'photo' => $c['photo'],
                'rsvp' => $c['rsvp'] ?? '',
                'color' => name_color(contact_name($c, false)),
            ];
        }, $ev['contacts']),
    ];
}

function birthday_json(array $b): array
{
    $p = $b['person'];
    return [
        'id' => 'b-' . $b['subject'] . '-' . $b['date'],
        'birthday' => true,
        'subject' => $b['subject'],
        'title' => '🎂 ' . $b['name'] . ($b['age'] !== null ? ' wordt ' . $b['age'] : ''),
        'name' => $b['name'],
        'age' => $b['age'],
        'start' => $b['date'] . 'T00:00',
        'end' => $b['date'] . 'T23:59',
        'allDay' => true,
        'color' => '#E0568A',
        'photo' => $p['photo'] ?? null,
        'link' => $b['kind'] === 'member' ? 'persoon.php?id=' . $p['id'] : 'contact.php?id=' . $p['id'],
        'members' => $b['kind'] === 'member' ? [(int) $p['id']] : [],
    ];
}
