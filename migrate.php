<?php
// Database migrations: applies every migrations/*.sql file that hasn't run yet, in filename order.
// Runs automatically after each push (webhook/deployer.php) and during install.php.
// From the command line: php migrate.php

const MIGRATIONS_DIR = __DIR__ . '/migrations';

/**
 * Split a migration file into statements. A statement ends with ; at the end of a line.
 */
function migration_statements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql); // strip comment lines
    $parts = preg_split('/;\s*(\r?\n|$)/', $sql);
    return array_values(array_filter(array_map('trim', $parts), 'strlen'));
}

function pending_migrations(PDO $pdo): array
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(190) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = array_map('basename', glob(MIGRATIONS_DIR . '/*.sql') ?: []);
    sort($files, SORT_STRING);
    return array_values(array_diff($files, $applied));
}

/**
 * Apply all pending migrations. Returns log lines; throws on the first failure
 * (that migration is not recorded, so it runs again on the next push once fixed).
 */
function run_migrations(PDO $pdo): array
{
    // Only one runner at a time (two quick pushes, or install.php during a deploy)
    if (!$pdo->query("SELECT GET_LOCK('jouwschoolplein_migrate', 30)")->fetchColumn()) {
        throw new RuntimeException('Kon migratie-lock niet krijgen: er draait al een migratie.');
    }
    try {
        $pending = pending_migrations($pdo);
        if (!$pending) {
            return ['Database is up-to-date.'];
        }
        $log = [];
        foreach ($pending as $file) {
            foreach (migration_statements(file_get_contents(MIGRATIONS_DIR . '/' . $file)) as $i => $statement) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    throw new RuntimeException("Migratie $file, statement " . ($i + 1) . ' mislukt: ' . $e->getMessage());
                }
            }
            $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$file]);
            $log[] = "Uitgevoerd: $file";
        }
        return $log;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('jouwschoolplein_migrate')");
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    require_once __DIR__ . '/db.php';
    try {
        echo implode(PHP_EOL, run_migrations(db())), PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
