<?php
$apps = [
    ['dir' => 'app1', 'name' => 'Schoolplein — prototype', 'desc' => 'Eerste versie: activiteiten met doelgroep per schoolgroep, alumni.'],
    ['dir' => 'app2', 'name' => 'Schoolplein — v0.2', 'desc' => 'Eén open plein: activiteiten voor de hele community, oud-leerlingen.'],
];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Jouw Schoolplein</title>
<style>
:root{--cream:#FFF9F0;--green:#4F8A6D;--orange:#E8893A;--ink:#26352F;--muted:#6F7A74;--line:#E9E2D8}
*{box-sizing:border-box}
body{margin:0;background:var(--cream);color:var(--ink);font-family:Inter,system-ui,sans-serif}
main{max-width:720px;margin:auto;padding:48px 16px}
.logo{font-size:32px;font-weight:900;letter-spacing:-1.2px}.logo span{color:var(--orange)}
p.lead{color:var(--muted);margin:6px 0 32px}
a.app{display:flex;justify-content:space-between;align-items:center;gap:16px;background:#fff;border:1px solid var(--line);border-radius:22px;padding:20px 22px;margin-bottom:14px;color:inherit;text-decoration:none;box-shadow:0 5px 18px #57432a0a;transition:transform .15s,border-color .15s}
a.app:hover{transform:translateY(-2px);border-color:var(--green)}
a.app h2{margin:0 0 4px;font-size:20px}
a.app small{color:var(--muted)}
a.app .go{flex:none;background:var(--green);color:#fff;border-radius:12px;padding:10px 14px;font-weight:750}
</style>
</head>
<body>
<main>
  <div class="logo">school<span>plein</span></div>
  <p class="lead">Kies een app</p>
  <?php foreach ($apps as $app): ?>
  <a class="app" href="<?= htmlspecialchars($app['dir']) ?>/">
    <div><h2><?= htmlspecialchars($app['name']) ?></h2><small><?= htmlspecialchars($app['desc']) ?></small></div>
    <span class="go">Open →</span>
  </a>
  <?php endforeach; ?>
</main>
</body>
</html>
