<?php
// Calendar logic: loading events (with repeating events expanded into occurrences),
// birthdays as virtual all-day items, and saving / moving / deleting.
// Times are local (Europe/Amsterdam) wall-clock DATETIMEs; all-day events run from
// 00:00 on the first day to 23:59 on the last day.

function event_type(string $type): array
{
    return EVENT_TYPES[$type] ?? EVENT_TYPES['OTHER'];
}

/** The event's own emoji, or the emoji of its type. */
function event_emoji(array $ev): string
{
    return !empty($ev['emoji']) ? $ev['emoji'] : event_type($ev['type'])[1];
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
        case 'WEEKDAYS': // weekend days are skipped by load_events()
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
    $days = ['DAILY' => 1, 'WEEKDAYS' => 1, 'WEEKLY' => 7, 'BIWEEKLY' => 14, 'MONTHLY' => 31, 'YEARLY' => 366];
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
    $shares = [];
    foreach (db()->query("SELECT event_id, friend_family FROM fp_event_shares WHERE event_id IN ($ids)") as $r) {
        $shares[$r['event_id']][] = (int) $r['friend_family'];
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

    // Who brings / picks up, per occurrence, for events set to "per keer bepalen"
    $duties = [];
    $eachIds = [];
    foreach ($events as $ev) {
        if (!empty($ev['drop_each']) || !empty($ev['pickup_each'])) {
            $eachIds[] = (int) $ev['id'];
        }
    }
    if ($eachIds) {
        foreach (db()->query('SELECT event_id, occurs_on, role, member_id, contact_id FROM fp_event_duties WHERE event_id IN (' . implode(',', $eachIds) . ')') as $r) {
            $duties[$r['event_id']][$r['occurs_on']][$r['role']] = [$r['member_id'] ? (int) $r['member_id'] : null, $r['contact_id'] ? (int) $r['contact_id'] : null];
        }
    }

    $out = [];
    foreach ($events as $ev) {
        $ev['members'] = $members[$ev['id']] ?? [];
        $ev['contacts'] = $contacts[$ev['id']] ?? [];
        $ev['shares'] = $shares[$ev['id']] ?? [];
        $ev['recurring'] = $ev['recurrence'] !== '';
        if (!$ev['recurring']) {
            $ev['occ'] = substr($ev['start_at'], 0, 10);
            $ev['done'] = (bool) $ev['done'];
            $out[] = apply_duties($ev, $duties);
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
            if ($ev['recurrence'] === 'WEEKDAYS' && (int) $s->format('N') >= 6) {
                continue; // Monday to Friday only
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
            $out[] = apply_duties($o, $duties);
        }
    }
    $out = apply_school_free($out, $from, $to);
    usort($out, function ($a, $b) {
        return [$b['all_day'], $a['start_at']] <=> [$a['all_day'], $b['start_at']];
    });
    return $out;
}

/**
 * No school on days off: repeating SCHOOL events are left out on study days, school holidays and
 * official public holidays, and end early on a study afternoon. A day off only counts for the children
 * it is for (no children chosen = everyone).
 */
function apply_school_free(array $out, string $from, string $to): array
{
    $hasSchool = false;
    foreach ($out as $o) {
        if ($o['type'] === 'SCHOOL' && $o['recurring']) {
            $hasSchool = true;
            break;
        }
    }
    if (!$hasSchool) {
        return $out;
    }
    require_once __DIR__ . '/freedays.php';
    $stmt = db()->prepare("SELECT e.id, e.type, e.start_at, e.end_at, e.all_day, GROUP_CONCAT(em.member_id) AS members FROM fp_events e
        LEFT JOIN fp_event_members em ON em.event_id = e.id
        WHERE e.type IN ('STUDYDAY', 'STUDYPM', 'SCHOOLHOLIDAY') AND e.start_at < ? AND e.end_at >= ? GROUP BY e.id");
    $stmt->execute([$to . ' 00:00:00', $from . ' 00:00:00']);
    $free = $stmt->fetchAll();
    $official = [];
    foreach (load_feasts($from, $to) as $f) {
        if ($f['official']) {
            $official[$f['date']] = true;
        }
    }
    $result = [];
    foreach ($out as $o) {
        if ($o['type'] !== 'SCHOOL' || !$o['recurring']) {
            $result[] = $o;
            continue;
        }
        $day = substr($o['start_at'], 0, 10);
        if (isset($official[$day])) {
            continue;
        }
        $drop = false;
        foreach ($free as $f) {
            if ($day < substr($f['start_at'], 0, 10) || $day > substr($f['end_at'], 0, 10)) {
                continue;
            }
            $fm = $f['members'] ? array_map('intval', explode(',', $f['members'])) : [];
            if ($fm && array_diff($o['members'], $fm)) {
                continue; // not everyone in this school event is free
            }
            if ($f['type'] === 'STUDYPM') {
                $cut = $day . ' ' . substr($f['start_at'], 11, 8);
                if ($cut > $o['start_at'] && $cut < $o['end_at']) {
                    $o['end_at'] = $cut;
                    $o['short_day'] = true;
                } elseif ($cut <= $o['start_at']) {
                    $drop = true;
                }
            } else {
                $drop = true;
            }
        }
        if (!$drop) {
            $result[] = $o;
        }
    }
    return $result;
}

/**
 * For "per keer bepalen": fill drop/pickup of this occurrence from fp_event_duties, and mark what is
 * still undecided (drop_open / pickup_open).
 */
function apply_duties(array $o, array $duties): array
{
    $o['drop_open'] = false;
    $o['pickup_open'] = false;
    foreach (['drop' => 'DROP', 'pickup' => 'PICKUP'] as $key => $role) {
        if (empty($o[$key . '_each'])) {
            continue;
        }
        [$member, $contact] = $duties[$o['id']][$o['occ']][$role] ?? [null, null];
        $o[$key . '_member_id'] = $member;
        $o[$key . '_contact_id'] = $contact;
        $o[$key . '_open'] = $member === null && $contact === null;
    }
    return $o;
}

/**
 * Decide who brings (DROP) or picks up (PICKUP) for one occurrence; '' = undecided again.
 * $who: a member id ("3") or a contact ("c12"), see parse_driver().
 */
function set_duty(int $id, string $occ, string $role, string $who): void
{
    if (!in_array($role, ['DROP', 'PICKUP'], true)) {
        throw new InvalidArgumentException('Ongeldige taak.');
    }
    [$member, $contact] = parse_driver($who);
    if ($member || $contact) {
        db()->prepare('INSERT INTO fp_event_duties (event_id, occurs_on, role, member_id, contact_id) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE member_id = VALUES(member_id), contact_id = VALUES(contact_id)')
            ->execute([$id, $occ, $role, $member, $contact]);
    } else {
        db()->prepare('DELETE FROM fp_event_duties WHERE event_id = ? AND occurs_on = ? AND role = ?')->execute([$id, $occ, $role]);
    }
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
    $stmt = db()->prepare('SELECT friend_family FROM fp_event_shares WHERE event_id = ?');
    $stmt->execute([$id]);
    $ev['shares'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $ev['recurring'] = $ev['recurrence'] !== '';
    return $ev;
}

/**
 * Validate and normalise event input (from the API or a form).
 * Input keys: title, type, start (Y-m-d or Y-m-d H:i), end, all_day, location, host, description, color,
 * recurrence, recur_until, drop_member_id, pickup_member_id (member id, "c<contact id>" or EACH), cost, paid,
 * members (ids), contacts (ids or id=>rsvp).
 */
function normalise_event(array $in): array
{
    $allDay = !empty($in['all_day']);
    $title = trim((string) ($in['title'] ?? ''));
    $type = (string) ($in['type'] ?? '');
    $type = isset(EVENT_TYPES[$type]) ? $type : 'OTHER';
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
    $recurrence = (string) ($in['recurrence'] ?? '');
    $recurrence = isset(RECURRENCES[$recurrence]) ? $recurrence : '';
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
    $each = function (string $key) use ($in, $recurrence): bool {
        return ($in[$key . '_member_id'] ?? '') === 'EACH' && $recurrence !== '';
    };
    [$dropMember, $dropContact] = $each('drop') ? [null, null] : parse_driver($in['drop_member_id'] ?? '');
    [$pickupMember, $pickupContact] = $each('pickup') ? [null, null] : parse_driver($in['pickup_member_id'] ?? '');
    $cost = isset($in['cost']) && $in['cost'] !== '' && $in['cost'] !== null ? round((float) str_replace(',', '.', (string) $in['cost']), 2) : null;
    return [
        'title' => mb_cut($title, 160),
        'type' => $type,
        'emoji' => trim((string) ($in['emoji'] ?? '')) !== '' ? mb_cut(trim((string) $in['emoji']), 16) : null,
        'start_at' => $startAt,
        'end_at' => $endAt,
        'all_day' => $allDay ? 1 : 0,
        'location' => trim((string) ($in['location'] ?? '')) ?: null,
        'host' => isset(HOSTS[(string) ($in['host'] ?? '')]) ? (string) ($in['host'] ?? '') : '',
        'description' => trim((string) ($in['description'] ?? '')) ?: null,
        'color' => $color,
        'recurrence' => $recurrence,
        'recur_until' => $recurrence ? $until : null,
        // A member id, a contact ("c12") or 'EACH' = decide per occurrence (only for repeating events)
        'drop_member_id' => $dropMember,
        'pickup_member_id' => $pickupMember,
        'drop_contact_id' => $dropContact,
        'pickup_contact_id' => $pickupContact,
        'drop_each' => $each('drop') ? 1 : 0,
        'pickup_each' => $each('pickup') ? 1 : 0,
        'cost' => $cost,
        'paid' => !empty($in['paid']) ? 1 : 0,
        '_members' => $memberIds,
        '_contacts' => $contacts,
        // Friend families this event is shared with (null = leave as it is)
        '_shares' => isset($in['shares']) ? event_share_targets((array) $in['shares']) : null,
    ];
}

/** Keep only friend families we share single events with. */
function event_share_targets(array $ids): array
{
    $ok = [];
    foreach (array_unique(array_map('intval', $ids)) as $fid) {
        if (isset(friend_families()[$fid]) && in_array('EVENTS', shares_from(current_family_id(), $fid), true)) {
            $ok[] = $fid;
        }
    }
    return $ok;
}

/** Insert or update an event from normalised data. Returns the id. */
function save_event(array $data, ?int $id = null): int
{
    $pdo = db();
    $members = $data['_members'];
    $contacts = $data['_contacts'];
    $shares = $data['_shares'] ?? null;
    unset($data['_members'], $data['_contacts'], $data['_shares']);
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
        if ($shares !== null) {
            $pdo->prepare('DELETE FROM fp_event_shares WHERE event_id = ?')->execute([$id]);
            foreach ($shares as $fid) {
                $pdo->prepare('INSERT INTO fp_event_shares (event_id, friend_family) VALUES (?, ?)')->execute([$id, $fid]);
            }
        }
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
    db()->prepare('INSERT IGNORE INTO fp_event_exceptions (event_id, occurs_on) VALUES (?, ?)')->execute([$ev['id'], $occ]);
    return copy_occurrence($ev, $occ);
}

/** Who does $role for one occurrence, as a driver value ('' = undecided). */
function duty_of(int $id, string $occ, string $role): string
{
    $stmt = db()->prepare('SELECT member_id, contact_id FROM fp_event_duties WHERE event_id = ? AND occurs_on = ? AND role = ?');
    $stmt->execute([$id, $occ, $role]);
    $r = $stmt->fetch();
    return $r ? driver_value($r['member_id'], $r['contact_id']) : '';
}

/**
 * Who brings / picks up: one of us (member id) or someone from the address book ("c" + contact id).
 * parse_driver() returns [member id, contact id] (both null = nobody), driver_value() the other way round.
 */
function parse_driver($value): array
{
    $value = (string) $value;
    if (preg_match('/^c(\d+)$/', $value, $m)) {
        return [null, find_contact_row((int) $m[1]) ? (int) $m[1] : null];
    }
    return [$value !== '' && member((int) $value) ? (int) $value : null, null];
}

function driver_value($memberId, $contactId): string
{
    return $contactId ? 'c' . (int) $contactId : ($memberId ? (string) (int) $memberId : '');
}

/** Name of who brings ($key 'drop') or picks up ('pickup'), '' when nobody. */
function driver_name(array $ev, string $key): string
{
    $p = driver_person($ev, $key);
    return $p ? $p['name'] : '';
}

/** ['name', 'emoji', 'value'] of who brings / picks up, or null. */
function driver_person(array $ev, string $key): ?array
{
    static $contacts = [];
    if (!empty($ev[$key . '_contact_id'])) {
        $cid = (int) $ev[$key . '_contact_id'];
        if (!array_key_exists($cid, $contacts)) {
            $contacts[$cid] = find_contact_row($cid);
        }
        $c = $contacts[$cid];
        return $c ? ['name' => contact_name($c, false), 'emoji' => RELATIONS[$c['relation']][1] ?? '👤', 'value' => 'c' . $cid] : null;
    }
    $m = !empty($ev[$key . '_member_id']) ? member((int) $ev[$key . '_member_id']) : null;
    return $m ? ['name' => $m['name'], 'emoji' => $m['emoji'], 'value' => (string) (int) $m['id']] : null;
}

/**
 * Grown-ups who can bring / pick up for these guests: the adults in their gezin (a friend's parents)
 * and adult guests themselves. Children are left out.
 */
function guest_adult_options(array $contactIds): array
{
    $ids = array_filter(array_map('intval', $contactIds));
    if (!$ids) {
        return [];
    }
    $in = implode(',', $ids);
    $rows = db()->query("SELECT c.*, h.name AS household_name FROM fp_contacts c LEFT JOIN fp_households h ON h.id = c.household_id
        WHERE c.is_child = 0 AND (c.id IN ($in) OR c.household_id IN (SELECT household_id FROM fp_contacts WHERE id IN ($in) AND household_id IS NOT NULL))
        ORDER BY h.name, c.first_name")->fetchAll();
    $kids = [];
    foreach (db()->query("SELECT first_name, nickname, household_id FROM fp_contacts WHERE id IN ($in) AND is_child = 1 AND household_id IS NOT NULL") as $k) {
        $kids[$k['household_id']][] = $k['nickname'] ?: $k['first_name'];
    }
    $out = [];
    foreach ($rows as $c) {
        $note = isset($kids[$c['household_id']]) ? 'van ' . implode(' & ', $kids[$c['household_id']]) : (string) $c['household_name'];
        $out[] = driver_option($c, in_array((int) $c['id'], $ids, true) ? '' : $note);
    }
    return $out;
}

/** <option>s for "Wie brengt / haalt op" (server-rendered forms); app.js adds "Iemand anders…". */
function driver_options_html(string $selected, array $guestIds, ?bool $each = null, string $blank = '—'): string
{
    $html = '<option value="">' . e($blank) . '</option>';
    if ($each !== null) {
        $html .= '<option value="EACH"' . ($each ? ' selected' : '') . '>🔁 Per keer bepalen</option>';
    }
    $sel = $each ? '' : $selected;
    $seen = [];
    $group = function (string $label, array $opts) use ($sel, &$seen): string {
        $h = '';
        foreach ($opts as $o) {
            if (isset($seen[$o['value']])) {
                continue;
            }
            $seen[$o['value']] = true;
            $h .= '<option value="' . e($o['value']) . '"' . ($o['value'] === $sel ? ' selected' : '') . '>' . e($o['label']) . '</option>';
        }
        return $h !== '' ? '<optgroup label="' . e($label) . '">' . $h . '</optgroup>' : '';
    };
    $mine = [];
    foreach (members() as $m) {
        $mine[] = ['value' => (string) (int) $m['id'], 'label' => $m['emoji'] . ' ' . $m['name']];
    }
    $html .= $group('Ons gezin', $mine);
    $html .= $group('Gezin van de gasten', guest_adult_options($guestIds));
    $html .= $group('Oppas', sitter_options());
    if (strpos($sel, 'c') === 0 && !isset($seen[$sel])) {
        $c = find_contact_row((int) substr($sel, 1));
        $html .= $c ? $group('Anderen', [driver_option($c)]) : '';
    }
    return $html . '<option value="OTHER">👤 Iemand anders…</option>';
}

/** A new single event with everything of $ev (guests, checklist) on the date of occurrence $occ. */
function copy_occurrence(array $ev, string $occ): int
{
    $pdo = db();
    $startTime = substr($ev['start_at'], 11);
    $durationDays = (int) round((strtotime(substr($ev['end_at'], 0, 10)) - strtotime(substr($ev['start_at'], 0, 10))) / 86400);
    $duration = strtotime($ev['end_at']) - strtotime($ev['start_at']);
    $start = $occ . ' ' . $startTime;
    $end = $ev['all_day']
        ? date('Y-m-d 23:59:00', strtotime("$occ +$durationDays day"))
        : date('Y-m-d H:i:s', strtotime($start) + $duration);
    $data = [
        'title' => $ev['title'], 'type' => $ev['type'], 'emoji' => $ev['emoji'], 'start' => $start, 'end' => $end, 'all_day' => $ev['all_day'],
        'location' => $ev['location'], 'host' => $ev['host'], 'description' => $ev['description'], 'color' => $ev['color'],
        'drop_member_id' => $ev['drop_each'] ? duty_of((int) $ev['id'], $occ, 'DROP') : driver_value($ev['drop_member_id'], $ev['drop_contact_id'] ?? null),
        'pickup_member_id' => $ev['pickup_each'] ? duty_of((int) $ev['id'], $occ, 'PICKUP') : driver_value($ev['pickup_member_id'], $ev['pickup_contact_id'] ?? null),
        'cost' => $ev['cost'], 'paid' => $ev['paid'], 'members' => $ev['members'],
        'contacts' => array_map(function ($c) {
            return ['id' => $c['id'], 'rsvp' => $c['rsvp']];
        }, $ev['contacts']),
        'shares' => $ev['shares'] ?? [],
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
        $days = (int) round($shift / 86400);
        if ($days !== 0) {
            // Moving a series to another day: move the per-date ticks and decisions along with it
            $order = $days > 0 ? 'DESC' : 'ASC';
            db()->prepare("UPDATE fp_event_done SET occurs_on = DATE_ADD(occurs_on, INTERVAL ? DAY) WHERE event_id = ? ORDER BY occurs_on $order")->execute([$days, $id]);
            db()->prepare("UPDATE fp_event_duties SET occurs_on = DATE_ADD(occurs_on, INTERVAL ? DAY) WHERE event_id = ? ORDER BY occurs_on $order")->execute([$days, $id]);
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

/** Link to an event's page; a friend family's shared event opens the agenda (it can't be edited here). */
function event_link(array $ev): string
{
    return !empty($ev['shared']) ? 'agenda.php' : 'event.php?id=' . (int) $ev['id'] . '&occ=' . $ev['occ'];
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
        'emoji' => event_emoji($ev),
        'customEmoji' => $ev['emoji'] ?? null,
        'typeLabel' => $t[0],
        'color' => event_color($ev),
        'customColor' => $ev['color'],
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
        // Driver values: member id as string or "c<contact id>"; names for display
        'dropWho' => driver_value($ev['drop_member_id'], $ev['drop_contact_id'] ?? null),
        'pickupWho' => driver_value($ev['pickup_member_id'], $ev['pickup_contact_id'] ?? null),
        'dropName' => driver_name($ev, 'drop'),
        'pickupName' => driver_name($ev, 'pickup'),
        'dropEach' => !empty($ev['drop_each']),
        'pickupEach' => !empty($ev['pickup_each']),
        'dropOpen' => !empty($ev['drop_open']),
        'pickupOpen' => !empty($ev['pickup_open']),
        'cost' => $ev['cost'] !== null ? (float) $ev['cost'] : null,
        'paid' => (bool) $ev['paid'],
        'shares' => array_map('intval', $ev['shares'] ?? []),
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
