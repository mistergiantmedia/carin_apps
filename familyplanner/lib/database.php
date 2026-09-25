<?php
// Database connection + migrations for the family planner.
// Namespaced on purpose: webhook/deployer.php loads the migrations of every app into one
// PHP process, so nothing here may clash with another app's global db()/run_migrations().
// Keep this PHP 7.4 compatible.
namespace Familie;

use PDO;
use PDOException;
use RuntimeException;

const CONFIG_FILE = __DIR__ . '/../config.php';
const MIGRATIONS_DIR = __DIR__ . '/../migrations';

/** Settings from config.php (written by install.php). It returns an array, no global constants. */
function config(): array
{
    if (!is_file(CONFIG_FILE)) {
        throw new RuntimeException('config.php ontbreekt. Open install.php om de database in te stellen.');
    }
    $config = require CONFIG_FILE;
    if (!is_array($config)) {
        throw new RuntimeException('config.php is ongeldig. Open install.php om de database opnieuw in te stellen.');
    }
    return $config;
}

function connect(?array $config = null): PDO
{
    $c = $config ?? config();
    return new PDO(
        'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4',
        $c['user'],
        $c['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/** Split a migration file into statements. A statement ends with ; at the end of a line. */
function migration_statements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
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
    if (!$pdo->query("SELECT GET_LOCK('familyplanner_migrate', 30)")->fetchColumn()) {
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
        $pdo->query("SELECT RELEASE_LOCK('familyplanner_migrate')");
    }
}
