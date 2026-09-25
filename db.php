<?php
// Shared database connection. Usage: require 'db.php'; $pdo = db();

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }

    $config = __DIR__ . '/config.php';
    if (!is_file($config)) {
        throw new RuntimeException('config.php ontbreekt. Kopieer config.sample.php naar config.php en vul het wachtwoord in.');
    }
    require_once $config;

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}
