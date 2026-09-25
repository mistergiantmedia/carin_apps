<?php
// Database status: connection, this app's tables (fp_*) and which migrations have run.
require __DIR__ . '/lib/app.php';
require_login();

$error = null;
$info = [];
$tables = [];
$applied = [];
$pending = [];
try {
    $pdo = db();
    $info = $pdo->query('SELECT DATABASE() AS db, VERSION() AS version, @@character_set_database AS charset')->fetch();
    foreach ($pdo->query("SHOW TABLES LIKE 'fp\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $tables[$t] = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $t) . '`')->fetchColumn();
    }
    $pending = \Familie\pending_migrations($pdo);
    $applied = $pdo->query('SELECT filename, applied_at FROM fp_schema_migrations ORDER BY filename')->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

page_start('Databasestatus', ['active' => 'instellingen.php', 'narrow' => true]);
page_header('🛠 Databasestatus');
?>
<div class="card">
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div>
  <?php else: ?>
    <p><span class="badge <?= $pending ? 'warn' : 'ok' ?>" style="font-size:14px"><?= $pending ? 'Nog ' . count($pending) . ' migratie(s) uit te voeren' : '✓ Database is up-to-date' ?></span></p>
    <p class="muted small">Database <b><?= e($info['db']) ?></b> · MySQL <?= e($info['version']) ?> · <?= e($info['charset']) ?></p>
    <h2 style="margin-top:16px">Tabellen</h2>
    <table class="data"><?php foreach ($tables as $t => $n): ?><tr><td><?= e($t) ?></td><td class="num"><?= $n ?> rijen</td></tr><?php endforeach; ?></table>
    <h2 style="margin-top:16px">Migraties</h2>
    <table class="data">
      <?php foreach ($applied as $a): ?><tr><td>✓ <?= e($a['filename']) ?></td><td class="muted small"><?= e($a['applied_at']) ?></td></tr><?php endforeach; ?>
      <?php foreach ($pending as $p): ?><tr><td>⏳ <?= e($p) ?></td><td class="muted small">wacht</td></tr><?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
<?php page_end();
