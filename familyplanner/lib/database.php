<?php
// Database connection + migrations for the family planner.
// Namespaced on purpose: webhook/deployer.php loads the migrations of every app into one
// PHP process, so nothing here may clash with another app's global db()/run_migrations().
// Keep this PHP 7.4 compatible.
//
// Several families: every family has its own copy of the family tables. Family 1 (the Reilmans)
// uses the original names (fp_events…), family 7 uses fp7_events… Pages always write fp_…;
// FamilyPDO rewrites those names to the logged-in family's tables, so a forgotten WHERE can never
// show another family's data. Shared tables (accounts, families, migrations) are never rewritten.
namespace Familie;

use PDO;
use PDOException;
use RuntimeException;

const CONFIG_FILE = __DIR__ . '/../config.php';
const MIGRATIONS_DIR = __DIR__ . '/../migrations';
const SHARED_TABLES = ['fp_users', 'fp_families', 'fp_schema_migrations', 'fp_family_links', 'fp_family_shares', 'fp_contact_links'];

/** Table prefix of a family: 1 → fp_, 7 → fp7_. */
function family_prefix(int $familyId): string
{
    return $familyId === 1 ? 'fp_' : 'fp' . $familyId . '_';
}

/** Rewrite fp_… family table names in $sql to $prefix (shared tables stay as they are). */
function rewrite_sql(string $sql, string $prefix): string
{
    if ($prefix === 'fp_') {
        return $sql;
    }
    return preg_replace('/\bfp_(?!users\b|families\b|schema_migrations\b|family_links\b|family_shares\b|contact_links\b)(?=[a-z])/', $prefix, $sql);
}

/**
 * PDO that sends fp_… queries to the current family's tables. Until a family is chosen the prefix
 * points at tables that don't exist, so a query without a logged-in family fails instead of leaking.
 */
class FamilyPDO extends PDO
{
    public static $prefix = 'fp0_';

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        return parent::prepare(rewrite_sql($query, self::$prefix), $options);
    }

    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$fetchModeArgs)
    {
        $query = rewrite_sql($query, self::$prefix);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        return parent::exec(rewrite_sql($statement, self::$prefix));
    }
}

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

/** Plain connection (migrations, install). $class = FamilyPDO::class for the pages. */
function connect(?array $config = null, string $class = PDO::class): PDO
{
    $c = $config ?? config();
    return new $class(
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS fp_schema_migrations (
        filename VARCHAR(190) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $applied = $pdo->query('SELECT filename FROM fp_schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = array_map('basename', glob(MIGRATIONS_DIR . '/*.sql') ?: []);
    sort($files, SORT_STRING);
    return array_values(array_diff($files, $applied));
}

/** Prefixes of the other approved families (their tables exist). */
function other_family_prefixes(PDO $pdo): array
{
    try {
        $ids = $pdo->query("SELECT id FROM fp_families WHERE id <> 1 AND status = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return []; // before fp_families exists
    }
    return array_map(function ($id) {
        return family_prefix((int) $id);
    }, $ids);
}

/**
 * Schema changes (CREATE/ALTER/DROP TABLE, indexes) on family tables must also be made to the other
 * families' copies. Data statements (INSERT, UPDATE…) are only for family 1: migrations holding data
 * are about the Reilman family (their school calendar, accounts).
 */
function is_family_ddl(string $statement): bool
{
    if (!preg_match('/^\s*(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|CREATE\s+(UNIQUE\s+)?INDEX|RENAME\s+TABLE)/i', $statement)) {
        return false;
    }
    foreach (SHARED_TABLES as $t) {
        if (preg_match('/\b' . $t . '\b/', $statement)) {
            return false;
        }
    }
    return true;
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
                    if (is_family_ddl($statement)) {
                        foreach (other_family_prefixes($pdo) as $prefix) {
                            $pdo->exec(rewrite_sql($statement, $prefix));
                        }
                    }
                } catch (PDOException $e) {
                    throw new RuntimeException("Migratie $file, statement " . ($i + 1) . ' mislukt: ' . $e->getMessage());
                }
            }
            $pdo->prepare('INSERT INTO fp_schema_migrations (filename) VALUES (?)')->execute([$file]);
            $log[] = "Uitgevoerd: $file";
        }
        return $log;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('familyplanner_migrate')");
    }
}
