<?php
// Gezinnen: families in the address book (table fp_households: one family at one address, with a photo).
// Earlier, "a family" could also be a group of type FAMILY; those groups without any of our own members are
// merged into gezinnen once (merge_family_groups). Groups with our own members ("Familie van Carin": grandparents,
// aunts, uncles) stay groups of type FAMILY. Keep PHP 7.4 compatible.

/** The photo to show for a gezin: its own, or the family photo of the linked friend family. */
function gezin_photo(array $h): ?string
{
    if (!empty($h['photo'])) {
        return $h['photo'];
    }
    if (!empty($h['linked_family']) && isset(friend_families()[(int) $h['linked_family']])) {
        return friend_families()[(int) $h['linked_family']]['photo'] ?? null;
    }
    return null;
}

/** Round gezin avatar (photo or 🏠). */
function gezin_avatar(array $h, int $size = 40): string
{
    return family_avatar(['name' => $h['name'] ?? '', 'photo' => gezin_photo($h)], $size);
}

/** The gezin in our address book linked to friend family $fid, or null. */
function gezin_of_family(int $fid): ?array
{
    $stmt = db()->prepare('SELECT * FROM fp_households WHERE linked_family = ? ORDER BY id LIMIT 1');
    $stmt->execute([$fid]);
    return $stmt->fetch() ?: null;
}

/** The gezin for friend family $fid, created (named after them) when there is none yet. Returns its id. */
function ensure_gezin_of_family(int $fid): int
{
    $h = gezin_of_family($fid);
    if ($h) {
        return (int) $h['id'];
    }
    db()->prepare('INSERT INTO fp_households (name, linked_family) VALUES (?, ?)')->execute([friend_families()[$fid]['name'] ?? 'Gezin', $fid]);
    return (int) db()->lastInsertId();
}

/**
 * One-time conversion (per planner family): FAMILY groups without our own members become gezinnen.
 * People already in one gezin bring the group into that gezin; the group's photo, place, notes and roles
 * are kept on the gezin. A group whose people live in different gezinnen is left alone.
 */
function merge_family_groups(): void
{
    $done = db()->query("SELECT value FROM fp_settings WHERE name = 'gezinnen_v1'")->fetchColumn();
    if ($done) {
        return;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $groups = $pdo->query("SELECT g.* FROM fp_groups g WHERE g.type = 'FAMILY'
            AND NOT EXISTS (SELECT 1 FROM fp_group_members gm WHERE gm.group_id = g.id)")->fetchAll();
        foreach ($groups as $g) {
            $stmt = $pdo->prepare('SELECT c.id, c.first_name, c.household_id, gc.role FROM fp_contacts c JOIN fp_group_contacts gc ON gc.contact_id = c.id WHERE gc.group_id = ?');
            $stmt->execute([$g['id']]);
            $people = $stmt->fetchAll();
            $homes = array_values(array_unique(array_filter(array_map('intval', array_column($people, 'household_id')))));
            if (count($homes) > 1) {
                continue; // they live in different gezinnen: keep the group
            }
            $extra = [];
            if ($g['place']) {
                $extra[] = 'Plek: ' . $g['place'];
            }
            $roles = [];
            foreach ($people as $p) {
                if ($p['role']) {
                    $roles[] = $p['first_name'] . ' (' . $p['role'] . ')';
                }
            }
            if ($roles) {
                $extra[] = 'Rollen: ' . implode(', ', $roles);
            }
            if ($g['notes']) {
                $extra[] = $g['notes'];
            }
            $photo = $pdo->prepare('SELECT file FROM fp_photos WHERE group_id = ? ORDER BY COALESCE(taken_on, DATE(created_at)) DESC, id DESC LIMIT 1');
            $photo->execute([$g['id']]);
            $photo = $photo->fetchColumn() ?: null;
            if ($homes) {
                $hid = $homes[0];
                $pdo->prepare('UPDATE fp_households SET photo = COALESCE(photo, ?), notes = TRIM(CONCAT_WS(?, NULLIF(notes, ?), NULLIF(?, ?))) WHERE id = ?')
                    ->execute([$photo, "\n", '', implode("\n", $extra), '', $hid]);
            } else {
                $pdo->prepare('INSERT INTO fp_households (name, photo, notes, is_demo) VALUES (?, ?, ?, ?)')
                    ->execute([$g['name'], $photo, $extra ? implode("\n", $extra) : null, $g['is_demo']]);
                $hid = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('UPDATE fp_contacts SET household_id = ? WHERE household_id IS NULL AND id IN (SELECT contact_id FROM fp_group_contacts WHERE group_id = ?)')
                ->execute([$hid, $g['id']]);
            // The group's photos stay in fp_photos (group_id becomes NULL); the newest is now the gezin photo
            $pdo->prepare('DELETE FROM fp_groups WHERE id = ?')->execute([$g['id']]);
        }
        $pdo->prepare("INSERT INTO fp_settings (name, value) VALUES ('gezinnen_v1', '1') ON DUPLICATE KEY UPDATE value = '1'")->execute();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[familyplanner] merge_family_groups: ' . $e->getMessage());
    }
}
