<?php
// Groups (circles): family, school class, sports team, work, club… Family members (fp_group_members)
// and address book contacts (fp_group_contacts) can be in any number of groups, each with an optional role.

function group_type(string $type): array
{
    return GROUP_TYPES[$type] ?? GROUP_TYPES['OTHER'];
}

function find_group(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM fp_groups WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Groups with their family members (id => role) and number of contacts.
 * Options: type, member (id), contact (id).
 */
function load_groups(array $opt = []): array
{
    $where = [];
    $params = [];
    if (!empty($opt['type'])) {
        $where[] = 'g.type = ?';
        $params[] = $opt['type'];
    }
    if (!empty($opt['member'])) {
        $where[] = 'EXISTS (SELECT 1 FROM fp_group_members gm WHERE gm.group_id = g.id AND gm.member_id = ?)';
        $params[] = (int) $opt['member'];
    }
    if (!empty($opt['contact'])) {
        $where[] = 'EXISTS (SELECT 1 FROM fp_group_contacts gc WHERE gc.group_id = g.id AND gc.contact_id = ?)';
        $params[] = (int) $opt['contact'];
    }
    $stmt = db()->prepare('SELECT g.*, (SELECT COUNT(*) FROM fp_group_contacts gc WHERE gc.group_id = g.id) AS contact_count
        FROM fp_groups g' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
        ORDER BY FIELD(g.type, ' . implode(',', array_map(function ($t) { return "'" . $t . "'"; }, array_keys(GROUP_TYPES))) . '), g.season DESC, g.name');
    $stmt->execute($params);
    $groups = $stmt->fetchAll();
    $members = [];
    foreach (db()->query('SELECT group_id, member_id, role FROM fp_group_members') as $r) {
        $members[$r['group_id']][(int) $r['member_id']] = $r['role'];
    }
    foreach ($groups as &$g) {
        $g['members'] = $members[$g['id']] ?? [];
    }
    unset($g);
    return $groups;
}

/** Contacts in a group (with role and household name), children first. */
function group_contacts(int $groupId): array
{
    $stmt = db()->prepare('SELECT c.*, gc.role, h.name AS household_name FROM fp_contacts c JOIN fp_group_contacts gc ON gc.contact_id = c.id
        LEFT JOIN fp_households h ON h.id = c.household_id WHERE gc.group_id = ? ORDER BY c.is_child DESC, c.first_name');
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

/** Group ids a contact is in (id => role). */
function contact_group_roles(int $contactId): array
{
    $stmt = db()->prepare('SELECT group_id, role FROM fp_group_contacts WHERE contact_id = ?');
    $stmt->execute([$contactId]);
    $out = [];
    foreach ($stmt as $r) {
        $out[(int) $r['group_id']] = $r['role'];
    }
    return $out;
}

/** Put a contact in exactly these groups, keeping the role where they already were. */
function set_contact_groups(int $contactId, array $groupIds): void
{
    $current = contact_group_roles($contactId);
    $groupIds = array_map('intval', $groupIds);
    foreach (array_diff(array_keys($current), $groupIds) as $gid) {
        db()->prepare('DELETE FROM fp_group_contacts WHERE group_id = ? AND contact_id = ?')->execute([$gid, $contactId]);
    }
    foreach (array_diff($groupIds, array_keys($current)) as $gid) {
        $g = find_group($gid);
        if ($g) {
            db()->prepare('INSERT IGNORE INTO fp_group_contacts (group_id, contact_id, role) VALUES (?, ?, ?)')
                ->execute([$gid, $contactId, group_type($g['type'])[4] ?: null]);
        }
    }
}

function group_label(array $g): string
{
    return trim($g['name'] . ($g['season'] ? ' · ' . $g['season'] : ''));
}
