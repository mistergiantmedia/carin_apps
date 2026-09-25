<?php
// Database connection check. Development only: remove or protect before going live.
require __DIR__ . '/db.php';

$ok = false;
$error = null;
$info = [];
$tables = [];

try {
    $pdo = db();
    $ok = true;
    $info = $pdo->query('SELECT DATABASE() AS db, VERSION() AS version, CURRENT_USER() AS user, @@character_set_database AS charset')->fetch();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>DB-verbinding</title>
<link rel="stylesheet" href="style.css">
<style>
.status{display:inline-block;padding:8px 14px;border-radius:12px;font-weight:800;color:#fff}
.status.ok{background:var(--green)}.status.fail{background:var(--coral)}
dl{display:grid;grid-template-columns:max-content 1fr;gap:8px 18px;margin:18px 0 0}dt{color:var(--muted)}dd{margin:0;font-family:ui-monospace,monospace}
pre{white-space:pre-wrap;background:#fdf1ee;padding:12px;border-radius:12px}
</style>
</head>
<body>
<main class="shell" style="max-width:720px">
  <header><div><div class="logo">school<span>plein</span></div><div class="school">Databaseverbinding</div></div></header>
  <section style="padding:0 24px">
    <article class="card">
      <?php if ($ok): ?>
        <span class="status ok">✓ Verbonden</span>
        <dl>
          <dt>Database</dt><dd><?= htmlspecialchars($info['db']) ?></dd>
          <dt>Gebruiker</dt><dd><?= htmlspecialchars($info['user']) ?></dd>
          <dt>Server</dt><dd><?= htmlspecialchars($info['version']) ?></dd>
          <dt>Charset</dt><dd><?= htmlspecialchars($info['charset']) ?></dd>
          <dt>Tabellen</dt><dd><?= $tables ? htmlspecialchars(implode(', ', $tables)) : '(nog geen)' ?></dd>
        </dl>
      <?php else: ?>
        <span class="status fail">✗ Geen verbinding</span>
        <pre><?= htmlspecialchars($error) ?></pre>
      <?php endif; ?>
    </article>
  </section>
</main>
</body>
</html>
