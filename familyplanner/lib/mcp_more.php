<?php
// More MCP tools (see mcp.php): the address book with all its relations (vriendje van, gezin, groepen, vaste week),
// family members, ideas, bucketlist, lijstjes and recipes. Everything the pages can do, through the same tables.
// Loaded by mcp.php after its own helpers. Keep this PHP 7.4 compatible.
require_once __DIR__ . '/groups.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/bucket.php';
require_once __DIR__ . '/week.php';
require_once __DIR__ . '/lists.php';
require_once __DIR__ . '/friends.php';

function mcp_clean(array $a): array
{
    return array_filter($a, function ($v) {
        return $v !== null && $v !== '' && $v !== [];
    });
}

/** "YYYY-MM-DD" or "MM-DD" (no year) → [day, month, year]; "" → clear. */
function mcp_birthday($v): array
{
    $v = trim((string) $v);
    if ($v === '') {
        return [null, null, null];
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return [(int) $m[3], (int) $m[2], (int) $m[1]];
    }
    if (preg_match('/^(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[1], (int) $m[2], 2000)) {
        return [(int) $m[2], (int) $m[1], null];
    }
    throw new InvalidArgumentException("Verjaardag moet 'JJJJ-MM-DD' zijn, of 'MM-DD' als het jaar onbekend is (kreeg: '$v').");
}

function mcp_birthday_text(array $r): ?string
{
    if (!$r['birth_day'] || !$r['birth_month']) {
        return null;
    }
    return ($r['birth_year'] ? sprintf('%04d-', $r['birth_year']) : '') . sprintf('%02d-%02d', $r['birth_month'], $r['birth_day']);
}

function mcp_need_contact(int $id): array
{
    $c = find_contact_row($id);
    if (!$c) {
        throw new InvalidArgumentException("Er is geen contact met id $id (gebruik find_contacts).");
    }
    return $c;
}

function mcp_need_group(int $id): array
{
    $g = $id ? find_group($id) : null;
    if (!$g) {
        throw new InvalidArgumentException("Er is geen groep met id $id (gebruik list_groups).");
    }
    return $g;
}

// ---------- Contacts ----------

function mcp_contact_full(int $id): array
{
    $c = mcp_need_contact($id);
    $out = mcp_clean([
        'id' => (int) $c['id'], 'name' => contact_name($c), 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'nickname' => $c['nickname'],
        'child' => (bool) $c['is_child'], 'relation' => $c['relation'], 'relation_label' => RELATIONS[$c['relation']][0] ?? null,
        'birthday' => mcp_birthday_text($c), 'phone' => $c['phone'], 'email' => $c['email'], 'street' => $c['street'], 'postal_code' => $c['postal_code'],
        'city' => $c['city'], 'allergies' => $c['allergies'], 'hourly_rate' => $c['hourly_rate'] !== null ? (float) $c['hourly_rate'] : null,
        'notes' => $c['notes'], 'favorite' => (bool) $c['is_favorite'],
    ]);
    if ($c['household_id']) {
        $out['gezin'] = mcp_gezin_json((int) $c['household_id']);
    }
    $stmt = db()->prepare('SELECT member_id FROM fp_contact_members WHERE contact_id = ?');
    $stmt->execute([$id]);
    $out['friend_of'] = mcp_names(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    $groups = [];
    foreach (contact_group_roles($id) as $gid => $role) {
        if ($g = find_group((int) $gid)) {
            $groups[] = mcp_clean(['id' => (int) $gid, 'name' => group_label($g), 'role' => $role]);
        }
    }
    $out['groups'] = $groups;
    $week = [];
    foreach (contact_week($id) as $items) {
        foreach ($items as $w) {
            $week[] = mcp_clean(['id' => (int) $w['id'], 'day' => DAYS[((int) $w['weekday']) % 7], 'weekday' => (int) $w['weekday'], 'title' => $w['title'], 'kind' => $w['kind'],
                'from' => $w['start_time'] ? substr($w['start_time'], 0, 5) : null, 'until' => $w['end_time'] ? substr($w['end_time'], 0, 5) : null]);
        }
    }
    $out['regular_week'] = $week;
    $days = [];
    foreach (contact_days($id) as $wd => $st) {
        $days[DAYS[((int) $wd) % 7]] = $st === 'YES' ? 'kan spelen' : 'kan niet spelen';
    }
    $out['play_days'] = $days;
    return $out;
}

function tool_get_contact(array $a): array
{
    return ['contact' => mcp_contact_full((int) ($a['id'] ?? 0))];
}

/** Columns of fp_contacts from tool arguments (only what was passed). */
function mcp_contact_fields(array $a, bool $isNew): array
{
    $f = [];
    foreach (['first_name' => 80, 'last_name' => 80, 'nickname' => 80, 'phone' => 40, 'email' => 190, 'street' => 160, 'postal_code' => 12, 'city' => 80, 'allergies' => 255, 'notes' => 65000] as $col => $max) {
        if (array_key_exists($col, $a) && $a[$col] !== null) {
            $v = trim((string) $a[$col]);
            $f[$col] = $v === '' ? null : mb_cut($v, $max);
        }
    }
    if (isset($f['first_name']) === false && array_key_exists('first_name', $f)) {
        throw new InvalidArgumentException('De voornaam mag niet leeg zijn.');
    }
    if (array_key_exists('first_name', $f) && $f['first_name'] === null) {
        throw new InvalidArgumentException('De voornaam mag niet leeg zijn.');
    }
    if (isset($a['is_child'])) {
        $f['is_child'] = !empty($a['is_child']) ? 1 : 0;
    }
    if (isset($a['is_favorite'])) {
        $f['is_favorite'] = !empty($a['is_favorite']) ? 1 : 0;
    }
    if (!empty($a['relation'])) {
        if (!isset(RELATIONS[$a['relation']])) {
            throw new InvalidArgumentException('Onbekende relatie. Kies uit: ' . implode(', ', array_keys(RELATIONS)));
        }
        $f['relation'] = $a['relation'];
    }
    if (array_key_exists('birthday', $a) && $a['birthday'] !== null) {
        [$f['birth_day'], $f['birth_month'], $f['birth_year']] = mcp_birthday($a['birthday']);
    }
    if (array_key_exists('hourly_rate', $a) && $a['hourly_rate'] !== null) {
        $f['hourly_rate'] = $a['hourly_rate'] === '' ? null : round((float) str_replace(',', '.', (string) $a['hourly_rate']), 2);
    }
    if (array_key_exists('gezin_id', $a) && $a['gezin_id'] !== null) {
        $hid = (int) $a['gezin_id'];
        if ($hid && !mcp_gezin_json($hid)) {
            throw new InvalidArgumentException("Er is geen gezin met id $hid (gebruik list_gezinnen).");
        }
        $f['household_id'] = $hid ?: null;
    }
    return $f;
}

function mcp_contact_relations(int $id, array $a): void
{
    if (array_key_exists('friend_of_member_ids', $a) && $a['friend_of_member_ids'] !== null) {
        $ids = mcp_member_ids($a['friend_of_member_ids']);
        db()->prepare('DELETE FROM fp_contact_members WHERE contact_id = ?')->execute([$id]);
        foreach ($ids as $mid) {
            db()->prepare('INSERT IGNORE INTO fp_contact_members (contact_id, member_id) VALUES (?, ?)')->execute([$id, $mid]);
        }
    }
    if (array_key_exists('group_ids', $a) && $a['group_ids'] !== null) {
        $gids = mcp_ids($a['group_ids']);
        foreach ($gids as $gid) {
            mcp_need_group($gid);
        }
        set_contact_groups($id, $gids);
    }
}

function tool_add_contact(array $a): array
{
    $name = trim((string) ($a['name'] ?? ''));
    if ($name !== '' && empty($a['first_name'])) {
        $parts = preg_split('/\s+/', $name, 2);
        $a['first_name'] = $parts[0];
        if (isset($parts[1]) && !array_key_exists('last_name', $a)) {
            $a['last_name'] = $parts[1];
        }
    }
    if (empty($a['first_name'])) {
        throw new InvalidArgumentException('Geef een naam (name, of first_name en last_name).');
    }
    $isChild = !empty($a['is_child']);
    $f = mcp_contact_fields($a, true);
    $f += ['is_child' => $isChild ? 1 : 0, 'relation' => $isChild ? 'FRIEND' : 'OWN_FRIEND'];
    $cols = implode(', ', array_keys($f));
    db()->prepare("INSERT INTO fp_contacts ($cols) VALUES (" . implode(', ', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    $id = (int) db()->lastInsertId();
    mcp_contact_relations($id, $a);
    return ['added' => mcp_contact_full($id)];
}

function tool_update_contact(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    mcp_need_contact($id);
    $f = mcp_contact_fields($a, false);
    if ($f) {
        $sets = implode(', ', array_map(function ($k) {
            return "$k = ?";
        }, array_keys($f)));
        db()->prepare("UPDATE fp_contacts SET $sets WHERE id = ?")->execute(array_merge(array_values($f), [$id]));
    }
    mcp_contact_relations($id, $a);
    if (!$f && !array_key_exists('friend_of_member_ids', $a) && !array_key_exists('group_ids', $a)) {
        throw new InvalidArgumentException('Geef minstens één veld om te wijzigen.');
    }
    return ['contact' => mcp_contact_full($id)];
}

function tool_delete_contact(array $a): array
{
    $c = mcp_need_contact((int) ($a['id'] ?? 0));
    unlink_contact((int) $c['id']);
    delete_photo($c['photo'] ?: null);
    db()->prepare('DELETE FROM fp_contacts WHERE id = ?')->execute([$c['id']]);
    return ['deleted' => contact_name($c)];
}

/** Whose friend is this person ("vriendje van Kaila"). mode: add | remove | set (default add). */
function tool_set_friend_of(array $a): array
{
    $c = mcp_need_contact((int) ($a['contact_id'] ?? 0));
    $ids = mcp_member_ids($a['member_ids'] ?? []);
    $mode = (string) ($a['mode'] ?? 'add');
    if (!in_array($mode, ['add', 'remove', 'set'], true)) {
        throw new InvalidArgumentException('mode moet add, remove of set zijn.');
    }
    if ($mode === 'set') {
        db()->prepare('DELETE FROM fp_contact_members WHERE contact_id = ?')->execute([$c['id']]);
    }
    foreach ($ids as $mid) {
        if ($mode === 'remove') {
            db()->prepare('DELETE FROM fp_contact_members WHERE contact_id = ? AND member_id = ?')->execute([$c['id'], $mid]);
        } else {
            db()->prepare('INSERT IGNORE INTO fp_contact_members (contact_id, member_id) VALUES (?, ?)')->execute([$c['id'], $mid]);
        }
    }
    $stmt = db()->prepare('SELECT member_id FROM fp_contact_members WHERE contact_id = ?');
    $stmt->execute([$c['id']]);
    return ['contact' => contact_name($c), 'friend_of' => mcp_names(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))];
}

// ---------- Gezinnen ----------

function tool_remove_from_gezin(array $a): array
{
    $ids = mcp_ids($a['contact_ids'] ?? []);
    foreach ($ids as $id) {
        mcp_need_contact($id);
        db()->prepare('UPDATE fp_contacts SET household_id = NULL WHERE id = ?')->execute([$id]);
    }
    return ['removed_from_gezin' => count($ids)];
}

function tool_delete_gezin(array $a): array
{
    $g = mcp_gezin_json((int) ($a['gezin_id'] ?? 0));
    if (!$g) {
        throw new InvalidArgumentException('Er is geen gezin met dit id (gebruik list_gezinnen).');
    }
    db()->prepare('DELETE FROM fp_households WHERE id = ?')->execute([$g['id']]);
    return ['deleted' => $g['name'], 'note' => 'De mensen zelf staan nog in het adresboek.'];
}

// ---------- Groups ----------

function mcp_group_json(array $g, bool $people = false): array
{
    $out = mcp_clean(['id' => (int) $g['id'], 'name' => group_label($g), 'type' => $g['type'], 'type_label' => GROUP_TYPES[$g['type']][0] ?? null,
        'place' => $g['place'], 'leader' => $g['leader'], 'notes' => $g['notes']]);
    if ($people) {
        $stmt = db()->prepare('SELECT member_id, role FROM fp_group_members WHERE group_id = ?');
        $stmt->execute([$g['id']]);
        $out['family_members'] = array_map(function ($r) {
            return mcp_clean(['id' => (int) $r['member_id'], 'name' => mcp_names([$r['member_id']])[0] ?? '', 'role' => $r['role']]);
        }, $stmt->fetchAll());
        $out['contacts'] = array_map(function ($c) {
            return mcp_clean(['id' => (int) $c['id'], 'name' => contact_name($c), 'child' => (bool) $c['is_child'], 'role' => $c['role']]);
        }, group_contacts((int) $g['id']));
    }
    return $out;
}

function tool_list_groups(array $a): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM fp_groups ORDER BY type, name') as $g) {
        if (empty($a['type']) || $g['type'] === $a['type']) {
            $out[] = mcp_group_json($g);
        }
    }
    return ['groups' => $out, 'types' => array_map(function ($t) {
        return $t[0];
    }, GROUP_TYPES)];
}

function tool_get_group(array $a): array
{
    return ['group' => mcp_group_json(mcp_need_group((int) ($a['id'] ?? 0)), true)];
}

function tool_save_group(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    $old = $id ? mcp_need_group($id) : null;
    $name = array_key_exists('name', $a) ? trim((string) $a['name']) : ($old['name'] ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('Geef de groep een naam, bijvoorbeeld "Groep 5" of "Voetbal JO9".');
    }
    $type = $a['type'] ?? ($old['type'] ?? 'OTHER');
    if (!isset(GROUP_TYPES[$type])) {
        throw new InvalidArgumentException('Onbekend type. Kies uit: ' . implode(', ', array_keys(GROUP_TYPES)));
    }
    $pick = function (string $k, int $max) use ($a, $old) {
        $v = array_key_exists($k, $a) ? trim((string) $a[$k]) : (string) ($old[$k] ?? '');
        return $v === '' ? null : mb_cut($v, $max);
    };
    $data = [$type, $pick('emoji', 16), mb_cut($name, 120), $pick('place', 120), $pick('season', 20), $pick('leader', 120), $pick('notes', 65000)];
    if ($old) {
        db()->prepare('UPDATE fp_groups SET type = ?, emoji = ?, name = ?, place = ?, season = ?, leader = ?, notes = ? WHERE id = ?')->execute(array_merge($data, [$id]));
    } else {
        db()->prepare('INSERT INTO fp_groups (type, emoji, name, place, season, leader, notes) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($data);
        $id = (int) db()->lastInsertId();
    }
    if (array_key_exists('member_ids', $a) && $a['member_ids'] !== null) {
        $ids = mcp_member_ids($a['member_ids']);
        $roles = [];
        foreach (db()->query('SELECT member_id, role FROM fp_group_members WHERE group_id = ' . $id) as $r) {
            $roles[(int) $r['member_id']] = $r['role'];
        }
        db()->prepare('DELETE FROM fp_group_members WHERE group_id = ?')->execute([$id]);
        foreach ($ids as $mid) {
            db()->prepare('INSERT INTO fp_group_members (group_id, member_id, role) VALUES (?, ?, ?)')->execute([$id, $mid, $roles[$mid] ?? null]);
        }
    }
    return ['group' => mcp_group_json(find_group($id), true)];
}

function tool_delete_group(array $a): array
{
    $g = mcp_need_group((int) ($a['id'] ?? 0));
    db()->prepare('DELETE FROM fp_groups WHERE id = ?')->execute([$g['id']]);
    return ['deleted' => group_label($g), 'note' => 'De mensen staan nog in het adresboek.'];
}

function tool_group_add(array $a): array
{
    $g = mcp_need_group((int) ($a['group_id'] ?? 0));
    $role = isset($a['role']) && trim((string) $a['role']) !== '' ? mb_cut(trim((string) $a['role']), 40) : (group_type($g['type'])[4] ?: null);
    $n = 0;
    foreach (mcp_ids($a['contact_ids'] ?? []) as $cid) {
        mcp_need_contact($cid);
        db()->prepare('INSERT IGNORE INTO fp_group_contacts (group_id, contact_id, role) VALUES (?, ?, ?)')->execute([$g['id'], $cid, $role]);
        $n++;
    }
    foreach (mcp_member_ids($a['member_ids'] ?? []) as $mid) {
        db()->prepare('INSERT IGNORE INTO fp_group_members (group_id, member_id) VALUES (?, ?)')->execute([$g['id'], $mid]);
        $n++;
    }
    if (!$n) {
        throw new InvalidArgumentException('Geef contact_ids en/of member_ids.');
    }
    return ['group' => mcp_group_json($g, true)];
}

function tool_group_remove(array $a): array
{
    $g = mcp_need_group((int) ($a['group_id'] ?? 0));
    foreach (mcp_ids($a['contact_ids'] ?? []) as $cid) {
        db()->prepare('DELETE FROM fp_group_contacts WHERE group_id = ? AND contact_id = ?')->execute([$g['id'], $cid]);
    }
    foreach (mcp_ids($a['member_ids'] ?? []) as $mid) {
        db()->prepare('DELETE FROM fp_group_members WHERE group_id = ? AND member_id = ?')->execute([$g['id'], $mid]);
    }
    return ['group' => mcp_group_json($g, true)];
}

function tool_group_set_role(array $a): array
{
    $g = mcp_need_group((int) ($a['group_id'] ?? 0));
    $role = isset($a['role']) && trim((string) $a['role']) !== '' ? mb_cut(trim((string) $a['role']), 40) : null;
    if (!empty($a['contact_id'])) {
        db()->prepare('UPDATE fp_group_contacts SET role = ? WHERE group_id = ? AND contact_id = ?')->execute([$role, $g['id'], (int) $a['contact_id']]);
    } elseif (!empty($a['member_id'])) {
        db()->prepare('UPDATE fp_group_members SET role = ? WHERE group_id = ? AND member_id = ?')->execute([$role, $g['id'], (int) $a['member_id']]);
    } else {
        throw new InvalidArgumentException('Geef contact_id of member_id.');
    }
    return ['group' => mcp_group_json($g, true)];
}

// ---------- Regular week of a contact ----------

function tool_add_contact_week(array $a): array
{
    $c = mcp_need_contact((int) ($a['contact_id'] ?? 0));
    $title = trim((string) ($a['title'] ?? ''));
    $days = array_values(array_filter(mcp_ids($a['weekdays'] ?? []), function ($d) {
        return $d >= 1 && $d <= 7;
    }));
    if ($title === '' || !$days) {
        throw new InvalidArgumentException('Geef een title en weekdays (1 = maandag … 7 = zondag).');
    }
    $kind = (string) ($a['kind'] ?? 'CLUB');
    if (!isset(WEEK_KINDS[$kind])) {
        throw new InvalidArgumentException('Onbekend kind. Kies uit: ' . implode(', ', array_keys(WEEK_KINDS)));
    }
    $time = function ($v) {
        return preg_match('/^\d{1,2}:\d{2}$/', (string) $v) ? $v : null;
    };
    foreach ($days as $wd) {
        db()->prepare('INSERT INTO fp_contact_week (contact_id, weekday, start_time, end_time, kind, title, emoji) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            $c['id'], $wd, $time($a['start_time'] ?? ''), $time($a['end_time'] ?? ''), $kind, mb_cut($title, 80), !empty($a['emoji']) ? mb_cut((string) $a['emoji'], 16) : null]);
        if ($kind === 'BSO') {
            db()->prepare("INSERT INTO fp_contact_days (contact_id, weekday, status) VALUES (?, ?, 'NO') ON DUPLICATE KEY UPDATE status = 'NO'")->execute([$c['id'], $wd]);
        }
    }
    return ['contact' => mcp_contact_full((int) $c['id'])];
}

function tool_delete_contact_week(array $a): array
{
    $c = mcp_need_contact((int) ($a['contact_id'] ?? 0));
    db()->prepare('DELETE FROM fp_contact_week WHERE id = ? AND contact_id = ?')->execute([(int) ($a['item_id'] ?? 0), $c['id']]);
    return ['contact' => mcp_contact_full((int) $c['id'])];
}

/** status: YES (can play), NO (can't) or "" (unknown) on a weekday (1 = Monday … 7 = Sunday). */
function tool_set_contact_day(array $a): array
{
    $c = mcp_need_contact((int) ($a['contact_id'] ?? 0));
    $wd = (int) ($a['weekday'] ?? 0);
    $st = (string) ($a['status'] ?? '');
    if ($wd < 1 || $wd > 7 || !in_array($st, ['YES', 'NO', ''], true)) {
        throw new InvalidArgumentException('weekday 1-7 (1 = maandag) en status YES, NO of "".');
    }
    if ($st === '') {
        db()->prepare('DELETE FROM fp_contact_days WHERE contact_id = ? AND weekday = ?')->execute([$c['id'], $wd]);
    } else {
        db()->prepare('INSERT INTO fp_contact_days (contact_id, weekday, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)')->execute([$c['id'], $wd, $st]);
    }
    return ['contact' => mcp_contact_full((int) $c['id'])];
}

// ---------- Family members ----------

function tool_update_member(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    $old = member($id);
    if (!$old) {
        throw new InvalidArgumentException("Er is geen gezinslid met id $id (gebruik get_overview).");
    }
    $f = [];
    foreach (['name' => 60, 'emoji' => 16, 'phone' => 40, 'email' => 190, 'allergies' => 255] as $col => $max) {
        if (array_key_exists($col, $a) && $a[$col] !== null) {
            $v = trim((string) $a[$col]);
            if ($col === 'name' && $v === '') {
                throw new InvalidArgumentException('De naam mag niet leeg zijn.');
            }
            if ($col === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Dat e-mailadres klopt niet.');
            }
            $f[$col] = $v === '' ? null : mb_cut($v, $max);
        }
    }
    if (!empty($a['color'])) {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $a['color'])) {
            throw new InvalidArgumentException('color moet er zo uitzien: #E0568A');
        }
        $f['color'] = $a['color'];
    }
    if (!empty($a['role'])) {
        $f['role'] = $a['role'] === 'PARENT' ? 'PARENT' : 'CHILD';
    }
    if (array_key_exists('birthday', $a) && $a['birthday'] !== null) {
        [$f['birth_day'], $f['birth_month'], $f['birth_year']] = mcp_birthday($a['birthday']);
    }
    if (!$f) {
        throw new InvalidArgumentException('Geef minstens één veld om te wijzigen.');
    }
    $sets = implode(', ', array_map(function ($k) {
        return "$k = ?";
    }, array_keys($f)));
    db()->prepare("UPDATE fp_members SET $sets WHERE id = ?")->execute(array_merge(array_values($f), [$id]));
    $n = db()->prepare('SELECT * FROM fp_members WHERE id = ?');
    $n->execute([$id]);
    $m = $n->fetch();
    return ['member' => mcp_clean(['id' => (int) $m['id'], 'name' => $m['name'], 'role' => $m['role'], 'birthday' => mcp_birthday_text($m),
        'phone' => $m['phone'], 'email' => $m['email'], 'allergies' => $m['allergies'], 'emoji' => $m['emoji'], 'color' => $m['color']])];
}

// ---------- Ideas (uitjes, cadeaus, attent zijn…) ----------

function mcp_idea(array $i): array
{
    return mcp_clean(['id' => (int) $i['id'], 'title' => $i['title'], 'category' => $i['category'], 'for' => $i['member_id'] ? (mcp_names([$i['member_id']])[0] ?? null) : null,
        'for_contact_id' => $i['contact_id'] ? (int) $i['contact_id'] : null, 'description' => $i['description'], 'url' => $i['url'], 'cost' => $i['cost'],
        'season' => $i['season'], 'done' => $i['done_at'] !== null]);
}

function tool_list_ideas(array $a): array
{
    $sql = 'SELECT * FROM fp_ideas';
    $where = [];
    $params = [];
    if (!empty($a['category'])) {
        $where[] = 'category = ?';
        $params[] = (string) $a['category'];
    }
    if (empty($a['include_done'])) {
        $where[] = 'done_at IS NULL';
    }
    $stmt = db()->prepare($sql . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC LIMIT 100');
    $stmt->execute($params);
    return ['ideas' => array_map('mcp_idea', $stmt->fetchAll()), 'categories' => array_map(function ($c) {
        return $c[0];
    }, IDEA_CATEGORIES)];
}

function tool_add_idea(array $a): array
{
    $title = trim((string) ($a['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('Geef een title.');
    }
    $cat = (string) ($a['category'] ?? 'OUTING');
    if (!isset(IDEA_CATEGORIES[$cat])) {
        throw new InvalidArgumentException('Onbekende categorie. Kies uit: ' . implode(', ', array_keys(IDEA_CATEGORIES)));
    }
    $member = !empty($a['member_id']) ? mcp_member_ids([$a['member_id']])[0] : null;
    $contact = !empty($a['contact_id']) ? (int) mcp_need_contact((int) $a['contact_id'])['id'] : null;
    $url = !empty($a['url']) && preg_match('~^https?://~', (string) $a['url']) ? mb_cut((string) $a['url'], 255) : null;
    db()->prepare('INSERT INTO fp_ideas (title, description, category, member_id, contact_id, url, cost, season) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        mb_cut($title, 190), !empty($a['description']) ? (string) $a['description'] : null, $cat, $member, $contact, $url,
        !empty($a['cost']) ? mb_cut((string) $a['cost'], 40) : null, !empty($a['season']) ? mb_cut((string) $a['season'], 20) : null]);
    $stmt = db()->prepare('SELECT * FROM fp_ideas WHERE id = ?');
    $stmt->execute([db()->lastInsertId()]);
    return ['added' => mcp_idea($stmt->fetch())];
}

function tool_update_idea(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM fp_ideas WHERE id = ?');
    $stmt->execute([$id]);
    if (!($i = $stmt->fetch())) {
        throw new InvalidArgumentException('Dit idee bestaat niet (gebruik list_ideas).');
    }
    foreach (['title' => 190, 'description' => 65000, 'cost' => 40, 'season' => 20] as $col => $max) {
        if (array_key_exists($col, $a) && $a[$col] !== null) {
            $v = trim((string) $a[$col]);
            if ($col === 'title' && $v === '') {
                throw new InvalidArgumentException('De titel mag niet leeg zijn.');
            }
            db()->prepare("UPDATE fp_ideas SET $col = ? WHERE id = ?")->execute([$v === '' ? null : mb_cut($v, $max), $id]);
        }
    }
    if (isset($a['done'])) {
        db()->prepare('UPDATE fp_ideas SET done_at = ? WHERE id = ?')->execute([!empty($a['done']) ? date('Y-m-d H:i:s') : null, $id]);
    }
    $stmt->execute([$id]);
    return ['idea' => mcp_idea($stmt->fetch())];
}

function tool_delete_idea(array $a): array
{
    $stmt = db()->prepare('SELECT title FROM fp_ideas WHERE id = ?');
    $stmt->execute([(int) ($a['id'] ?? 0)]);
    if (!($t = $stmt->fetchColumn())) {
        throw new InvalidArgumentException('Dit idee bestaat niet.');
    }
    db()->prepare('DELETE FROM fp_ideas WHERE id = ?')->execute([(int) $a['id']]);
    return ['deleted' => $t];
}

// ---------- Bucketlist ----------

function mcp_bucket(array $b): array
{
    return mcp_clean(['id' => (int) $b['id'], 'title' => $b['title'], 'emoji' => $b['emoji'], 'notes' => $b['notes'],
        'wanted_by' => mcp_names($b['voters']), 'with_friends' => array_map(function ($f) {
            return contact_name($f);
        }, $b['friends']), 'done_on' => $b['done_on'], 'planned_event' => $b['event_title'] ?? null, 'planned_event_id' => $b['event_id'] ? (int) $b['event_id'] : null]);
}

function mcp_bucket_one(int $id): array
{
    foreach (load_bucket() as $b) {
        if ((int) $b['id'] === $id) {
            return mcp_bucket($b);
        }
    }
    throw new InvalidArgumentException('Dit bucketlist-item bestaat niet (gebruik list_bucket).');
}

function tool_list_bucket(array $a): array
{
    $opt = [];
    if (isset($a['done'])) {
        $opt['done'] = !empty($a['done']);
    }
    return ['bucketlist' => array_map('mcp_bucket', load_bucket($opt))];
}

function tool_save_bucket(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    $old = null;
    if ($id) {
        foreach (load_bucket() as $b) {
            if ((int) $b['id'] === $id) {
                $old = $b;
            }
        }
        if (!$old) {
            throw new InvalidArgumentException('Dit bucketlist-item bestaat niet (gebruik list_bucket).');
        }
    }
    $title = array_key_exists('title', $a) ? trim((string) $a['title']) : ($old['title'] ?? '');
    if ($title === '') {
        throw new InvalidArgumentException('Wat wil je graag doen? Geef een title.');
    }
    $emoji = array_key_exists('emoji', $a) ? trim((string) $a['emoji']) : (string) ($old['emoji'] ?? '');
    $notes = array_key_exists('notes', $a) ? trim((string) $a['notes']) : (string) ($old['notes'] ?? '');
    if ($old) {
        db()->prepare('UPDATE fp_bucket SET title = ?, emoji = ?, notes = ? WHERE id = ?')->execute([mb_cut($title, 160), $emoji !== '' ? mb_cut($emoji, 16) : null, $notes !== '' ? $notes : null, $id]);
    } else {
        db()->prepare('INSERT INTO fp_bucket (title, emoji, notes, created_by) VALUES (?, ?, ?, ?)')
            ->execute([mb_cut($title, 160), $emoji !== '' ? mb_cut($emoji, 16) : null, $notes !== '' ? $notes : null, current_user()['id']]);
        $id = (int) db()->lastInsertId();
    }
    if (array_key_exists('member_ids', $a) && $a['member_ids'] !== null) {
        db()->prepare('DELETE FROM fp_bucket_votes WHERE bucket_id = ?')->execute([$id]);
        foreach (mcp_member_ids($a['member_ids']) as $mid) {
            db()->prepare('INSERT IGNORE INTO fp_bucket_votes (bucket_id, member_id) VALUES (?, ?)')->execute([$id, $mid]);
        }
    }
    if (array_key_exists('contact_ids', $a) && $a['contact_ids'] !== null) {
        save_bucket_friends($id, json_encode(mcp_ids($a['contact_ids'])));
    }
    if (isset($a['done'])) {
        db()->prepare('UPDATE fp_bucket SET done_on = ? WHERE id = ?')->execute([!empty($a['done']) ? (!empty($a['done_on']) ? mcp_date($a['done_on'], 'done_on') : today()) : null, $id]);
    }
    if (!empty($a['event_id'])) {
        if (!find_event((int) $a['event_id'])) {
            throw new InvalidArgumentException('Die afspraak bestaat niet.');
        }
        db()->prepare('UPDATE fp_bucket SET event_id = ? WHERE id = ?')->execute([(int) $a['event_id'], $id]);
    }
    return ['bucket' => mcp_bucket_one($id)];
}

function tool_delete_bucket(array $a): array
{
    $b = mcp_bucket_one((int) ($a['id'] ?? 0));
    db()->prepare('DELETE FROM fp_bucket WHERE id = ?')->execute([$b['id']]);
    return ['deleted' => $b['title']];
}

// ---------- Lijstjes (to-do dossiers with options, links, prices, pros and cons) ----------

function mcp_list_item(array $i): array
{
    return mcp_clean(['id' => (int) $i['id'], 'kind' => $i['kind'], 'title' => $i['title'], 'url' => $i['url'],
        'price' => $i['price'] !== null ? (float) $i['price'] : null, 'price_note' => $i['price_note'],
        'pros' => list_lines($i['pros']), 'cons' => list_lines($i['cons']), 'notes' => $i['notes'],
        'who' => !empty($i['member_id']) ? ($i['member_id'] && member((int) $i['member_id']) ? member((int) $i['member_id'])['name'] : null) : null,
        'our_pick' => !empty($i['chosen']) ? true : null, 'done' => $i['kind'] === 'TODO' ? $i['done_at'] !== null : null]);
}

function mcp_list(array $l, bool $withItems = false): array
{
    $out = mcp_clean(['id' => (int) $l['id'], 'title' => $l['title'], 'emoji' => $l['emoji'], 'notes' => $l['notes'], 'finished' => $l['done_at'] !== null ? true : null]);
    if ($withItems) {
        $items = load_list_items((int) $l['id']);
        $cheap = cheapest_options($items['OPTION']);
        $out['todos'] = array_map('mcp_list_item', $items['TODO']);
        $out['options'] = array_map(function ($o) use ($cheap) {
            $r = mcp_list_item($o);
            if (in_array((int) $o['id'], $cheap, true)) {
                $r['cheapest'] = true;
            }
            return $r;
        }, $items['OPTION']);
        $out['inspiration'] = array_map('mcp_list_item', $items['INSPIRATION']);
    } else {
        $out['todos_open'] = (int) $l['todos'] - (int) $l['todos_done'];
        $out['options'] = (int) $l['options'];
        $out['inspiration'] = (int) $l['inspiration'];
    }
    return $out;
}

function tool_list_lists(array $a): array
{
    return ['lists' => array_map('mcp_list', load_lists(!empty($a['finished'])))];
}

function tool_get_list(array $a): array
{
    return ['list' => mcp_list(resolve_list(isset($a['id']) ? (int) $a['id'] : null, (string) ($a['name'] ?? '')), true)];
}

function tool_save_list(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    $old = $id ? find_list($id) : null;
    if ($id && !$old) {
        throw new InvalidArgumentException('Deze lijst bestaat niet (gebruik list_lists).');
    }
    $title = array_key_exists('title', $a) ? trim((string) $a['title']) : (string) ($old['title'] ?? '');
    if ($title === '') {
        throw new InvalidArgumentException('Geef een title voor de lijst.');
    }
    $emoji = array_key_exists('emoji', $a) ? trim((string) $a['emoji']) : (string) ($old['emoji'] ?? '');
    $notes = array_key_exists('notes', $a) ? trim((string) $a['notes']) : (string) ($old['notes'] ?? '');
    $emoji = $emoji !== '' ? mb_cut($emoji, 16) : null;
    $notes = $notes !== '' ? $notes : null;
    if ($old) {
        db()->prepare('UPDATE fp_lists SET title = ?, emoji = ?, notes = ? WHERE id = ?')->execute([mb_cut($title, 160), $emoji, $notes, $id]);
        if (isset($a['finished'])) {
            db()->prepare('UPDATE fp_lists SET done_at = ? WHERE id = ?')->execute([!empty($a['finished']) ? date('Y-m-d H:i:s') : null, $id]);
        }
    } else {
        // Don't make a second list with the same name: return the existing one
        $stmt = db()->prepare('SELECT id FROM fp_lists WHERE LOWER(title) = LOWER(?) AND done_at IS NULL LIMIT 1');
        $stmt->execute([mb_cut($title, 160)]);
        if ($dup = $stmt->fetchColumn()) {
            return ['list' => mcp_list(find_list((int) $dup), true), 'note' => 'Deze lijst bestond al; er is geen nieuwe gemaakt.'];
        }
        db()->prepare('INSERT INTO fp_lists (title, emoji, notes, created_by) VALUES (?, ?, ?, ?)')->execute([mb_cut($title, 160), $emoji, $notes, current_user()['id']]);
        $id = (int) db()->lastInsertId();
    }
    return ['list' => mcp_list(find_list($id), true)];
}

function tool_delete_list(array $a): array
{
    $l = resolve_list((int) ($a['id'] ?? 0));
    db()->prepare('DELETE FROM fp_lists WHERE id = ?')->execute([$l['id']]);
    return ['deleted' => $l['title']];
}

function tool_add_list_item(array $a): array
{
    $created = false;
    try {
        $list = resolve_list(isset($a['list_id']) ? (int) $a['list_id'] : null, (string) ($a['list_name'] ?? ''));
    } catch (InvalidArgumentException $e) {
        // "Add this to the list X" for a list that doesn't exist yet: make it when asked to
        if (empty($a['create_list']) || trim((string) ($a['list_name'] ?? '')) === '' || !empty($a['list_id'])) {
            throw $e;
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM fp_lists WHERE done_at IS NULL AND LOWER(title) LIKE ?');
        $stmt->execute(['%' . mb_strtolower(trim((string) $a['list_name'])) . '%']);
        if ((int) $stmt->fetchColumn() > 0) {
            throw $e; // several candidates: ask which one instead of creating a duplicate
        }
        db()->prepare('INSERT INTO fp_lists (title, created_by) VALUES (?, ?)')->execute([mb_cut(trim((string) $a['list_name']), 160), current_user()['id']]);
        $list = find_list((int) db()->lastInsertId());
        $created = true;
    }
    $d = $a;
    if (isset($d['kind']) || !isset($a['url'])) {
        $d['kind'] = $d['kind'] ?? 'TODO';
    } else {
        $d['kind'] = 'INSPIRATION'; // only a link: inspiration unless a price / pros / cons say it is an option
        if (isset($a['price']) || isset($a['pros']) || isset($a['cons'])) {
            $d['kind'] = 'OPTION';
        }
    }
    foreach (['pros', 'cons'] as $k) {
        if (isset($d[$k]) && is_array($d[$k])) {
            $d[$k] = implode("\n", array_map('strval', $d[$k]));
        }
    }
    $itemId = save_list_item((int) $list['id'], $d);
    $item = find_list_item($itemId);
    return ['added_to' => $list['title'], 'list_id' => (int) $list['id'], 'list_created' => $created ?: null, 'item' => mcp_list_item($item)];
}

function tool_update_list_item(array $a): array
{
    $item = find_list_item((int) ($a['id'] ?? 0));
    if (!$item) {
        throw new InvalidArgumentException('Dit onderdeel bestaat niet (gebruik get_list).');
    }
    $d = $a;
    unset($d['id']);
    foreach (['pros', 'cons'] as $k) {
        if (isset($d[$k]) && is_array($d[$k])) {
            $d[$k] = implode("\n", array_map('strval', $d[$k]));
        }
    }
    if (isset($d['list_id']) && (int) $d['list_id'] !== (int) $item['list_id']) { // move to another list
        if (!find_list((int) $d['list_id'])) {
            throw new InvalidArgumentException('Die lijst bestaat niet.');
        }
        db()->prepare('UPDATE fp_list_items SET list_id = ?, chosen = 0 WHERE id = ?')->execute([(int) $d['list_id'], $item['id']]);
        $item['list_id'] = (int) $d['list_id'];
    }
    unset($d['list_id']);
    save_list_item((int) $item['list_id'], $d, (int) $item['id']);
    return ['item' => mcp_list_item(find_list_item((int) $item['id']))];
}

function tool_delete_list_item(array $a): array
{
    $item = find_list_item((int) ($a['id'] ?? 0));
    if (!$item) {
        throw new InvalidArgumentException('Dit onderdeel bestaat niet (gebruik get_list).');
    }
    db()->prepare('DELETE FROM fp_list_items WHERE id = ?')->execute([$item['id']]);
    return ['deleted' => $item['title']];
}

// ---------- Recipes ----------

function mcp_recipe(array $r): array
{
    return mcp_clean(['id' => (int) $r['id'], 'name' => $r['name'], 'emoji' => $r['emoji'], 'minutes' => $r['prep_minutes'] ? (int) $r['prep_minutes'] : null,
        'tags' => $r['tags'], 'url' => $r['url'], 'method' => $r['method'],
        'ingredients' => array_map(function ($i) {
            return trim(($i['amount'] ?? '') . ' ' . $i['name']);
        }, $r['ingredients']),
        'likes' => array_map(function ($v) {
            return $v === 'LIKE' ? 'lust het' : 'lust het niet';
        }, array_combine(mcp_names(array_keys($r['likes'])), array_values($r['likes'])) ?: [])]);
}

function mcp_save_ingredients(int $rid, string $text): void
{
    db()->prepare('DELETE FROM fp_recipe_ingredients WHERE recipe_id = ?')->execute([$rid]);
    $n = 0;
    foreach (preg_split('/\r?\n/', $text) as $line) {
        [$amount, $name] = parse_ingredient($line);
        if ($name === '') {
            continue;
        }
        db()->prepare('INSERT INTO fp_recipe_ingredients (recipe_id, amount, name, category, sort) VALUES (?, ?, ?, ?, ?)')
            ->execute([$rid, $amount !== '' ? mb_cut($amount, 40) : null, mb_cut($name, 120), guess_category($name), $n++]);
    }
}

function tool_get_recipe(array $a): array
{
    seed_recipes();
    $r = recipes()[(int) ($a['id'] ?? 0)] ?? null;
    if (!$r) {
        throw new InvalidArgumentException('Dit recept bestaat niet (gebruik list_recipes).');
    }
    return ['recipe' => mcp_recipe($r)];
}

function tool_save_recipe(array $a): array
{
    seed_recipes();
    $id = (int) ($a['id'] ?? 0);
    $old = $id ? (recipes()[$id] ?? null) : null;
    if ($id && !$old) {
        throw new InvalidArgumentException('Dit recept bestaat niet (gebruik list_recipes).');
    }
    $name = array_key_exists('name', $a) ? trim((string) $a['name']) : ($old['name'] ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('Geef het gerecht een naam.');
    }
    $tags = $old['tags'] ?? '';
    if (array_key_exists('tags', $a)) {
        $tags = implode(',', array_values(array_intersect(array_keys(RECIPE_TAGS), array_map('strval', (array) $a['tags']))));
    }
    $emoji = array_key_exists('emoji', $a) ? trim((string) $a['emoji']) : (string) ($old['emoji'] ?? '');
    $minutes = array_key_exists('minutes', $a) ? ((int) $a['minutes'] ?: null) : ($old['prep_minutes'] ?? null);
    $method = array_key_exists('method', $a) ? trim((string) $a['method']) : (string) ($old['method'] ?? '');
    $url = array_key_exists('url', $a) ? (string) $a['url'] : (string) ($old['url'] ?? '');
    $data = [mb_cut($name, 160), $emoji !== '' ? mb_cut($emoji, 16) : null, $minutes, $tags, $method !== '' ? $method : null,
        filter_var($url, FILTER_VALIDATE_URL) ? mb_cut($url, 255) : null];
    if ($old) {
        db()->prepare('UPDATE fp_recipes SET name = ?, emoji = ?, prep_minutes = ?, tags = ?, method = ?, url = ? WHERE id = ?')->execute(array_merge($data, [$id]));
    } else {
        db()->prepare('INSERT INTO fp_recipes (name, emoji, prep_minutes, tags, method, url) VALUES (?, ?, ?, ?, ?, ?)')->execute($data);
        $id = (int) db()->lastInsertId();
    }
    if (array_key_exists('ingredients', $a)) {
        mcp_save_ingredients($id, implode("\n", array_map('strval', (array) $a['ingredients'])));
    }
    $stmt = db()->prepare('SELECT * FROM fp_recipes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $row['ingredients'] = db()->query('SELECT * FROM fp_recipe_ingredients WHERE recipe_id = ' . $id . ' ORDER BY sort, id')->fetchAll();
    $row['likes'] = [];
    return ['recipe' => mcp_recipe($row)];
}

function tool_import_recipe(array $a): array
{
    $url = trim((string) ($a['url'] ?? ''));
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Geef een hele link die begint met https://');
    }
    try {
        $imp = import_recipe_from_url($url);
    } catch (RuntimeException $e) {
        throw new InvalidArgumentException($e->getMessage());
    }
    $photo = download_photo($imp['image']);
    db()->prepare('INSERT INTO fp_recipes (name, emoji, prep_minutes, tags, method, url, photo) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([mb_cut($imp['name'], 160), null, $imp['minutes'], $imp['tags'], $imp['method'] !== '' ? $imp['method'] : null, mb_cut($url, 255), $photo]);
    $id = (int) db()->lastInsertId();
    foreach ($imp['ingredients'] as $n => [$amount, $name]) {
        db()->prepare('INSERT INTO fp_recipe_ingredients (recipe_id, amount, name, category, sort) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $amount !== '' ? mb_cut($amount, 40) : null, mb_cut($name, 120), guess_category($name), $n]);
    }
    return tool_get_recipe(['id' => $id]);
}

function tool_delete_recipe(array $a): array
{
    seed_recipes();
    $r = recipes()[(int) ($a['id'] ?? 0)] ?? null;
    if (!$r) {
        throw new InvalidArgumentException('Dit recept bestaat niet.');
    }
    delete_photo($r['photo'] ?: null);
    db()->prepare('DELETE FROM fp_recipes WHERE id = ?')->execute([$r['id']]);
    return ['deleted' => $r['name']];
}

/** verdict: LIKE, DISLIKE or "" (no opinion) for one family member. */
function tool_set_recipe_like(array $a): array
{
    seed_recipes();
    $rid = (int) ($a['recipe_id'] ?? 0);
    if (!isset(recipes()[$rid])) {
        throw new InvalidArgumentException('Dit recept bestaat niet.');
    }
    $mid = mcp_member_ids([$a['member_id'] ?? 0])[0] ?? 0;
    if (!$mid) {
        throw new InvalidArgumentException('Geef member_id.');
    }
    $v = (string) ($a['verdict'] ?? '');
    if (!in_array($v, ['LIKE', 'DISLIKE', ''], true)) {
        throw new InvalidArgumentException('verdict moet LIKE, DISLIKE of "" zijn.');
    }
    if ($v === '') {
        db()->prepare('DELETE FROM fp_recipe_likes WHERE recipe_id = ? AND member_id = ?')->execute([$rid, $mid]);
    } else {
        db()->prepare('REPLACE INTO fp_recipe_likes (recipe_id, member_id, verdict) VALUES (?, ?, ?)')->execute([$rid, $mid, $v]);
    }
    return ['recipe' => recipes()[$rid]['name'], 'member' => mcp_names([$mid])[0], 'verdict' => $v];
}

// ---------- Events: ticking off and who brings ----------

function tool_set_event_done(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    if (!find_event($id)) {
        throw new InvalidArgumentException('Deze afspraak bestaat niet (meer).');
    }
    $occ = isset($a['occ']) ? mcp_date($a['occ'], 'occ') : '';
    set_event_done($id, $occ, !isset($a['done']) || !empty($a['done']));
    return ['ok' => true];
}

/** Who brings / picks up one occurrence of an event whose "who" is decided per time. role: DROP | PICKUP; who: member id, "c<contact id>" or "" (undecided). */
function tool_set_duty(array $a): array
{
    $id = (int) ($a['id'] ?? 0);
    if (!find_event($id)) {
        throw new InvalidArgumentException('Deze afspraak bestaat niet (meer).');
    }
    $role = strtoupper((string) ($a['role'] ?? ''));
    if (!in_array($role, ['DROP', 'PICKUP'], true)) {
        throw new InvalidArgumentException('role moet DROP (brengen) of PICKUP (halen) zijn.');
    }
    set_duty($id, mcp_date($a['occ'] ?? '', 'occ'), $role, (string) ($a['who'] ?? ''));
    return ['occurrence' => mcp_occurrence($id, (string) $a['occ'])];
}

// ---------- Registration ----------

function mcp_more_tools(): array
{
    $o = function (array $props, array $required = []) {
        return ['type' => 'object', 'properties' => $props ?: new stdClass(), 'required' => $required];
    };
    $int = ['type' => 'integer'];
    $str = ['type' => 'string'];
    $ids = function (string $d) {
        return ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => $d];
    };
    $contact = [
        'first_name' => $str, 'last_name' => $str, 'nickname' => $str, 'is_child' => ['type' => 'boolean'], 'is_favorite' => ['type' => 'boolean'],
        'relation' => ['type' => 'string', 'description' => 'FRIEND (vriendje), CLASSMATE, PARENT (ouder van vriendje), FAMILY, OWN_FRIEND (eigen vrienden), NEIGHBOR, BABYSITTER, SCHOOL, COACH, COLLEAGUE, OTHER.'],
        'birthday' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or MM-DD when the year is unknown; "" clears it.'],
        'phone' => $str, 'email' => $str, 'street' => $str, 'postal_code' => $str, 'city' => $str, 'allergies' => $str, 'hourly_rate' => ['type' => 'number', 'description' => 'For babysitters, euro per hour.'], 'notes' => $str,
        'gezin_id' => ['type' => 'integer', 'description' => 'Put this person in this gezin (0 = take out of any gezin). Or use link_to_gezin.'],
        'friend_of_member_ids' => $ids('Whose friend this is ("vriendje van"): REPLACES the list. Use set_friend_of to add/remove one.'),
        'group_ids' => $ids('Groups this person is in: REPLACES the list. Use group_add / group_remove for single changes.'),
    ];
    return [
        ['name' => 'get_contact', 'handler' => 'tool_get_contact', 'description' => 'Everything about one address-book person: details, gezin, whose friend (vriendje van), groups, regular week and play days.', 'inputSchema' => $o(['id' => $int], ['id'])],
        ['name' => 'add_contact', 'handler' => 'tool_add_contact', 'description' => 'Add a person to the address book. Give name ("Maria Psaraki") or first_name/last_name. Search first (find_contacts) to avoid duplicates.', 'inputSchema' => $o(array_merge(['name' => ['type' => 'string', 'description' => 'Full name; first word = first name.']], $contact), [])],
        ['name' => 'update_contact', 'handler' => 'tool_update_contact', 'description' => 'Change an address-book person; only the fields you pass change.', 'inputSchema' => $o(array_merge(['id' => $int], $contact), ['id'])],
        ['name' => 'delete_contact', 'handler' => 'tool_delete_contact', 'description' => 'DELETE a person from the address book for good (also from events and groups). Always confirm with the user first.', 'inputSchema' => $o(['id' => $int], ['id'])],
        ['name' => 'set_friend_of', 'handler' => 'tool_set_friend_of', 'description' => 'Say whose friend ("vriendje van") an address-book person is: add or remove family members (mode add | remove | set).', 'inputSchema' => $o(['contact_id' => $int, 'member_ids' => $ids('Family members (Kaila, Bodi…)'), 'mode' => ['type' => 'string', 'enum' => ['add', 'remove', 'set']]], ['contact_id', 'member_ids'])],
        ['name' => 'remove_from_gezin', 'handler' => 'tool_remove_from_gezin', 'description' => 'Take people out of their gezin (they stay in the address book).', 'inputSchema' => $o(['contact_ids' => $ids('Contacts')], ['contact_ids'])],
        ['name' => 'delete_gezin', 'handler' => 'tool_delete_gezin', 'description' => 'Delete a gezin (the people stay in the address book). Confirm first.', 'inputSchema' => $o(['gezin_id' => $int], ['gezin_id'])],
        ['name' => 'list_groups', 'handler' => 'tool_list_groups', 'description' => 'Groups: school classes, sports teams, clubs, work, neighbourhood, friends, big family. Optionally one type.', 'inputSchema' => $o(['type' => ['type' => 'string', 'description' => 'FAMILY, SCHOOL, SPORT, CLUB, WORK, NEIGHBORHOOD, FRIENDS, OTHER']])],
        ['name' => 'get_group', 'handler' => 'tool_get_group', 'description' => 'One group with all its people and roles.', 'inputSchema' => $o(['id' => $int], ['id'])],
        ['name' => 'save_group', 'handler' => 'tool_save_group', 'description' => 'Create a group (no id) or change one (id). member_ids = which of our own family members are in it (replaces the list).', 'inputSchema' => $o(['id' => $int, 'name' => $str, 'type' => $str, 'emoji' => $str, 'place' => $str, 'season' => ['type' => 'string', 'description' => 'e.g. 2026-2027'], 'leader' => $str, 'notes' => $str, 'member_ids' => $ids('Our family members in this group')])],
        ['name' => 'delete_group', 'handler' => 'tool_delete_group', 'description' => 'Delete a group (people stay in the address book). Confirm first.', 'inputSchema' => $o(['id' => $int], ['id'])],
        ['name' => 'group_add', 'handler' => 'tool_group_add', 'description' => 'Put address-book people and/or our family members into a group, with an optional role (e.g. "juf", "trainer").', 'inputSchema' => $o(['group_id' => $int, 'contact_ids' => $ids('Address-book people'), 'member_ids' => $ids('Our family members'), 'role' => $str], ['group_id'])],
        ['name' => 'group_remove', 'handler' => 'tool_group_remove', 'description' => 'Take people out of a group.', 'inputSchema' => $o(['group_id' => $int, 'contact_ids' => $ids('Address-book people'), 'member_ids' => $ids('Our family members')], ['group_id'])],
        ['name' => 'group_set_role', 'handler' => 'tool_group_set_role', 'description' => 'Set or clear the role of one person in a group (contact_id or member_id).', 'inputSchema' => $o(['group_id' => $int, 'contact_id' => $int, 'member_id' => $int, 'role' => $str], ['group_id'])],
        ['name' => 'add_contact_week', 'handler' => 'tool_add_contact_week', 'description' => 'Add a fixed weekly activity of a contact (BSO, sport, club…) on one or more weekdays; used for "can they play?" warnings.', 'inputSchema' => $o(['contact_id' => $int, 'weekdays' => $ids('1 = Monday … 7 = Sunday'), 'title' => $str, 'kind' => ['type' => 'string', 'enum' => ['BSO', 'SPORT', 'CLUB', 'MUSIC', 'LESSON', 'FAMILY', 'OTHER']], 'start_time' => ['type' => 'string', 'description' => 'HH:MM'], 'end_time' => ['type' => 'string', 'description' => 'HH:MM'], 'emoji' => $str], ['contact_id', 'weekdays', 'title'])],
        ['name' => 'delete_contact_week', 'handler' => 'tool_delete_contact_week', 'description' => 'Remove a fixed weekly activity (item_id from get_contact → regular_week).', 'inputSchema' => $o(['contact_id' => $int, 'item_id' => $int], ['contact_id', 'item_id'])],
        ['name' => 'set_contact_day', 'handler' => 'tool_set_contact_day', 'description' => 'Mark a weekday as "can play" (YES), "can\'t play" (NO) or unknown ("") for a contact.', 'inputSchema' => $o(['contact_id' => $int, 'weekday' => ['type' => 'integer', 'description' => '1 = Monday … 7 = Sunday'], 'status' => ['type' => 'string', 'enum' => ['YES', 'NO', '']]], ['contact_id', 'weekday', 'status'])],
        ['name' => 'update_member', 'handler' => 'tool_update_member', 'description' => 'Change one of our own family members (name, birthday, phone, email, allergies, emoji, colour, role PARENT/CHILD).', 'inputSchema' => $o(['id' => $int, 'name' => $str, 'role' => ['type' => 'string', 'enum' => ['PARENT', 'CHILD']], 'birthday' => ['type' => 'string', 'description' => 'YYYY-MM-DD or MM-DD'], 'phone' => $str, 'email' => $str, 'allergies' => $str, 'emoji' => $str, 'color' => ['type' => 'string', 'description' => '#RRGGBB']], ['id'])],
        ['name' => 'list_ideas', 'handler' => 'tool_list_ideas', 'description' => 'Idea list: outings, gift ideas, "attent zijn", date ideas… (categories in the result).', 'inputSchema' => $o(['category' => $str, 'include_done' => ['type' => 'boolean']])],
        ['name' => 'add_idea', 'handler' => 'tool_add_idea', 'description' => 'Add an idea. category: OUTING, ENGAGEMENT, ATTENTION, DATE, DINNER, GIFT, ACTIVITY… A gift idea can be for an address-book person (contact_id).', 'inputSchema' => $o(['title' => $str, 'category' => $str, 'member_id' => $int, 'contact_id' => $int, 'description' => $str, 'url' => $str, 'cost' => $str, 'season' => $str], ['title'])],
        ['name' => 'update_idea', 'handler' => 'tool_update_idea', 'description' => 'Change an idea or tick it off (done).', 'inputSchema' => $o(['id' => $int, 'title' => $str, 'description' => $str, 'cost' => $str, 'season' => $str, 'done' => ['type' => 'boolean']], ['id'])],
        ['name' => 'delete_idea', 'handler' => 'tool_delete_idea', 'description' => 'Delete an idea.', 'inputSchema' => $o(['id' => $int], ['id'])],
        ['name' => 'list_bucket', 'handler' => 'tool_list_bucket', 'description' => 'The bucketlist (things we want to do), with who wants it and who joins.', 'inputSchema' => $o(['done' => ['type' => 'boolean', 'description' => 'true = only done, false = only open']])],
        ['name' => 'save_bucket', 'handler' => 'tool_save_bucket', 'description' => 'Add (no id) or change (id) a bucketlist wish. member_ids = who wants it, contact_ids = friends who join, done = tick off, event_id = planned event.', 'inputSchema' => $o(['id' => $int, 'title' => $str, 'emoji' => $str, 'notes' => $str, 'member_ids' => $ids('Who wants it'), 'contact_ids' => $ids('Friends who join'), 'done' => ['type' => 'boolean'], 'done_on' => $str, 'event_id' => $int])],
        ['name' => 'delete_bucket', 'handler' => 'tool_delete_bucket', 'description' => 'Delete a bucketlist wish.', 'inputSchema' => $o(['id' => $int], ['id'])],
        ['name' => 'list_lists', 'handler' => 'tool_list_lists', 'description' => 'The "Lijstjes": one list per thing to sort out (energie overstappen, dekbedden kopen…), each with to-dos, options to compare and inspiration. Counts only; use get_list for the content.', 'inputSchema' => $o(['finished' => ['type' => 'boolean', 'description' => 'true = the finished lists']])],
        ['name' => 'get_list', 'handler' => 'tool_get_list', 'description' => 'One list with all its to-dos, options (link, price, pros, cons, our_pick, cheapest) and inspiration. Give id or (part of) the name.', 'inputSchema' => $o(['id' => $int, 'name' => $str])],
        ['name' => 'save_list', 'handler' => 'tool_save_list', 'description' => 'Create a list (no id) or change one (id): title, emoji, notes, finished (true = done, hidden from the active lists).', 'inputSchema' => $o(['id' => $int, 'title' => $str, 'emoji' => $str, 'notes' => $str, 'finished' => ['type' => 'boolean']])],
        ['name' => 'delete_list', 'handler' => 'tool_delete_list', 'description' => 'DELETE a list with everything in it. Confirm first.', 'inputSchema' => $o(['id' => $int], ['id'])],
        ['name' => 'add_list_item', 'handler' => 'tool_add_list_item', 'description' => 'Add something to a list (list_id, or list_name = part of its title; create_list: true makes the list when it does not exist). kind TODO = a to-do (default), OPTION = something to compare (give url, price, price_note like "per maand", pros, cons), INSPIRATION = a link/product/concept to remember. With only a url (no kind) it becomes INSPIRATION, with a price/pros/cons an OPTION. Without a title the page title of the link is used.', 'inputSchema' => $o([
            'list_id' => $int, 'list_name' => $str, 'create_list' => ['type' => 'boolean'],
            'kind' => ['type' => 'string', 'enum' => ['TODO', 'OPTION', 'INSPIRATION']], 'title' => $str, 'url' => $str,
            'price' => ['type' => 'number', 'description' => 'Euro, e.g. 49.95'], 'price_note' => ['type' => 'string', 'description' => 'e.g. "per maand", "eenmalig"'],
            'pros' => ['type' => 'array', 'items' => $str, 'description' => 'Advantages, one string each'], 'cons' => ['type' => 'array', 'items' => $str, 'description' => 'Disadvantages, one string each'],
            'notes' => $str, 'member_id' => ['type' => 'integer', 'description' => 'Who does this to-do']])],
        ['name' => 'update_list_item', 'handler' => 'tool_update_list_item', 'description' => 'Change an item (id from get_list): any field, done (tick a to-do), chosen (mark an option as our pick), list_id (move to another list). Only the fields you pass change; pros/cons REPLACE the old ones.', 'inputSchema' => $o([
            'id' => $int, 'title' => $str, 'url' => $str, 'price' => ['type' => 'number'], 'price_note' => $str, 'kind' => ['type' => 'string', 'enum' => ['TODO', 'OPTION', 'INSPIRATION']],
            'pros' => ['type' => 'array', 'items' => $str], 'cons' => ['type' => 'array', 'items' => $str], 'notes' => $str, 'member_id' => $int,
            'done' => ['type' => 'boolean'], 'chosen' => ['type' => 'boolean'], 'list_id' => $int], ['id'])],
        ['name' => 'delete_list_item', 'handler' => 'tool_delete_list_item', 'description' => 'Remove one item from a list.', 'inputSchema' => $o(['id' => $int], ['id'])],
        ['name' => 'get_recipe', 'handler' => 'tool_get_recipe', 'description' => 'One recipe with ingredients, method and who likes it.', 'inputSchema' => $o(['id' => $int], ['id'])],
        ['name' => 'save_recipe', 'handler' => 'tool_save_recipe', 'description' => 'Add (no id) or change (id) a recipe. ingredients: one string per line, e.g. "500 g gehakt". tags: VEG, FISH, LEGUMES, REDMEAT, QUICK… (the app guesses categories for the shopping list).', 'inputSchema' => $o(['id' => $int, 'name' => $str, 'emoji' => $str, 'minutes' => $int, 'tags' => ['type' => 'array', 'items' => $str], 'method' => $str, 'url' => $str, 'ingredients' => ['type' => 'array', 'items' => $str]])],
        ['name' => 'import_recipe', 'handler' => 'tool_import_recipe', 'description' => 'Add a recipe from a web link (name, ingredients, time, method, photo are read from the page).', 'inputSchema' => $o(['url' => $str], ['url'])],
        ['name' => 'delete_recipe', 'handler' => 'tool_delete_recipe', 'description' => 'Delete a recipe. Confirm first.', 'inputSchema' => $o(['id' => $int], ['id'])],
        ['name' => 'set_recipe_like', 'handler' => 'tool_set_recipe_like', 'description' => 'Record that a family member likes (LIKE) or does not like (DISLIKE) a recipe; "" clears it.', 'inputSchema' => $o(['recipe_id' => $int, 'member_id' => $int, 'verdict' => ['type' => 'string', 'enum' => ['LIKE', 'DISLIKE', '']]], ['recipe_id', 'member_id', 'verdict'])],
        ['name' => 'set_event_done', 'handler' => 'tool_set_event_done', 'description' => 'Tick an agenda item off as done (or not done). For a repeating event pass occ.', 'inputSchema' => $o(['id' => $int, 'occ' => $str, 'done' => ['type' => 'boolean']], ['id'])],
        ['name' => 'set_duty', 'handler' => 'tool_set_duty', 'description' => 'For events where who brings/picks up is decided per time: set it for one occurrence (role DROP or PICKUP; who = member id, "c<contact id>" or "").', 'inputSchema' => $o(['id' => $int, 'occ' => $str, 'role' => ['type' => 'string', 'enum' => ['DROP', 'PICKUP']], 'who' => $str], ['id', 'occ', 'role'])],
    ];
}
