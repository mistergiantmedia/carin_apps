<?php
// Families: registration, approval and removal. Family 1 (the Reilmans) uses the original fp_ tables;
// an approved family gets its own copy of every family table (fp7_events…), cloned from the current
// structure of family 1's tables (same columns, indexes and foreign keys, but empty).

/** Family tables of family 1 (everything fp_… except the shared and retired tables). */
function base_family_tables(PDO $raw): array
{
    $skip = array_merge(\Familie\SHARED_TABLES, ['fp_classes', 'fp_class_contacts']);
    $out = [];
    foreach ($raw->query("SHOW TABLES LIKE 'fp\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (!in_array($t, $skip, true)) {
            $out[] = $t;
        }
    }
    return $out;
}

/** Create the (empty) tables of a family. Safe to repeat: existing tables are left alone. */
function create_family_tables(int $familyId): void
{
    if ($familyId === 1) {
        return;
    }
    $raw = \Familie\connect(); // plain connection: we build the new names ourselves
    $prefix = \Familie\family_prefix($familyId);
    $raw->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach (base_family_tables($raw) as $table) {
            $create = $raw->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            $create = preg_replace('/\sAUTO_INCREMENT=\d+/', '', $create);
            $create = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $create);
            $raw->exec(\Familie\rewrite_sql($create, $prefix));
        }
    } finally {
        $raw->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

/** Remove a family completely: its tables, accounts and the family itself (never family 1). */
function delete_family(int $familyId): void
{
    if ($familyId === 1) {
        throw new RuntimeException('Het eerste gezin kan niet worden verwijderd.');
    }
    $raw = \Familie\connect();
    $prefix = \Familie\family_prefix($familyId);
    $raw->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($raw->query("SHOW TABLES LIKE '" . str_replace('_', '\\_', $prefix) . "%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $raw->exec('DROP TABLE `' . $t . '`');
        }
    } finally {
        $raw->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    $raw->prepare('DELETE FROM fp_users WHERE family_id = ?')->execute([$familyId]);
    $raw->prepare('DELETE FROM fp_families WHERE id = ?')->execute([$familyId]);
}

/**
 * Approve a family: create its tables and give it a start: the person who registered as first family
 * member (linked to their account), the idea bank and default settings.
 */
function approve_family(int $familyId, int $approvedBy): void
{
    create_family_tables($familyId);
    $previous = \Familie\FamilyPDO::$prefix;
    use_family($familyId);
    try {
        if (!db()->query('SELECT 1 FROM fp_members LIMIT 1')->fetchColumn()) {
            $stmt = db()->prepare('SELECT id, name FROM fp_users WHERE family_id = ? ORDER BY id');
            $stmt->execute([$familyId]);
            $colors = ['#E0568A', '#3B7DD8'];
            foreach ($stmt->fetchAll() as $i => $u) {
                db()->prepare("INSERT INTO fp_members (name, role, color, emoji, sort) VALUES (?, 'PARENT', ?, '🙂', ?)")->execute([$u['name'], $colors[$i % 2], $i + 1]);
                db()->prepare('UPDATE fp_users SET member_id = ? WHERE id = ?')->execute([(int) db()->lastInsertId(), $u['id']]);
            }
            // The starter idea bank (same list as migration 003)
            foreach (\Familie\migration_statements((string) file_get_contents(\Familie\MIGRATIONS_DIR . '/003_seed_ideas.sql')) as $sql) {
                db()->exec($sql);
            }
            db()->exec("INSERT IGNORE INTO fp_settings (name, value) VALUES ('school_region', 'midden'), ('city', '')");
        }
    } finally {
        \Familie\FamilyPDO::$prefix = $previous;
    }
    db()->prepare("UPDATE fp_families SET status = 'ACTIVE', approved_at = NOW(), approved_by = ? WHERE id = ?")->execute([$approvedBy, $familyId]);
}
