<?php
// Shared database connection for the pages. Usage: require 'db.php'; $pdo = db();
require_once __DIR__ . '/lib/database.php';

function db(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        $pdo = \Familie\connect();
    }
    return $pdo;
}
