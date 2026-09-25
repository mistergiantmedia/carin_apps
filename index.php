<?php
// App chooser: every folder next to this file is an app, except the ones below.
// An app can optionally describe itself with an app.json in its folder:
//   {"name": "Jouw Schoolplein", "description": "…", "icon": "🏫"}
// Without it, the folder name is used.

const NOT_APPS = ['webhook'];

$apps = [];
foreach (scandir(__DIR__) as $dir) {
    if ($dir[0] === '.' || in_array($dir, NOT_APPS, true) || !is_dir(__DIR__ . "/$dir")) {
        continue;
    }
    $meta = [];
    $json = __DIR__ . "/$dir/app.json";
    if (is_file($json)) {
        $meta = json_decode((string) file_get_contents($json), true) ?: [];
    }
    $apps[] = [
        'dir'         => $dir,
        'name'        => $meta['name'] ?? ucwords(str_replace(['-', '_'], ' ', $dir)),
        'description' => $meta['description'] ?? '',
        'icon'        => $meta['icon'] ?? '📁',
    ];
}
usort($apps, fn($a, $b) => strcasecmp($a['name'], $b['name']));

function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Apps</title>
<style>
:root{--bg:#FFF9F0;--card:#fff;--ink:#26352F;--muted:#6F7A74;--line:#E9E2D8;--accent:#4F8A6D}
@media (prefers-color-scheme:dark){:root{--bg:#1b211e;--card:#252d29;--ink:#eef2ef;--muted:#a3aea8;--line:#35403a;--accent:#7cc19e}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,sans-serif}
main{max-width:900px;margin:auto;padding:40px 16px}
h1{font-size:34px;letter-spacing:-1px;margin:0 0 6px}
.sub{color:var(--muted);margin:0 0 28px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px}
a.app{display:block;background:var(--card);border:1px solid var(--line);border-radius:20px;padding:20px;color:inherit;text-decoration:none;transition:transform .1s,border-color .1s}
a.app:hover{transform:translateY(-2px);border-color:var(--accent)}
.icon{font-size:34px;line-height:1}
.app h2{font-size:20px;margin:12px 0 4px}
.app p{color:var(--muted);margin:0;font-size:14px;line-height:1.5}
.empty{color:var(--muted)}
</style>
</head>
<body>
<main>
  <h1>Apps</h1>
  <p class="sub">Kies een app om te openen.</p>
  <?php if (!$apps): ?>
    <p class="empty">Er zijn nog geen apps.</p>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($apps as $app): ?>
        <a class="app" href="<?= e(rawurlencode($app['dir'])) ?>/">
          <div class="icon"><?= e($app['icon']) ?></div>
          <h2><?= e($app['name']) ?></h2>
          <?php if ($app['description'] !== ''): ?><p><?= e($app['description']) ?></p><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
