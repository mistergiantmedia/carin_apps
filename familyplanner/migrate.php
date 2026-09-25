<?php
// Database migrations: applies every migrations/*.sql file that hasn't run yet, in filename order.
// webhook/deployer.php includes this file after each push and calls the function it returns.
// From the command line: php migrate.php
require_once __DIR__ . '/lib/database.php';

$runner = static function (): array {
    if (!is_file(\Familie\CONFIG_FILE)) {
        return ['config.php ontbreekt (nog niet geïnstalleerd), overgeslagen.'];
    }
    return \Familie\run_migrations(\Familie\connect());
};

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    try {
        echo implode(PHP_EOL, $runner()), PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

return $runner;
