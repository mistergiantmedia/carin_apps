<?php
// Shared database connection for the pages. Usage: require 'db.php'; $pdo = db();
// It is a FamilyPDO: fp_… table names go to the logged-in family's tables (see lib/database.php).
require_once __DIR__ . '/lib/database.php';

function db(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        $pdo = \Familie\connect(null, \Familie\FamilyPDO::class);
    }
    return $pdo;
}

/** Switch to a family's tables (after login, or for the calendar feed of an account). */
function use_family(int $familyId): void
{
    \Familie\FamilyPDO::$prefix = \Familie\family_prefix($familyId);
}
