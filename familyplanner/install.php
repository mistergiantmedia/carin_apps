<?php
// One-time setup: tests the DB credentials, writes config.php, runs the migrations
// and creates the first login account. Locks itself as soon as an account exists.
require_once __DIR__ . '/lib/database.php';

use function Familie\connect;
use function Familie\run_migrations;
use const Familie\CONFIG_FILE;

function write_config(array $c): void
{
    $php = "<?php\n// Written by install.php. Not in git: holds the DB password.\nreturn " . var_export($c, true) . ";\n";
    if (@file_put_contents(CONFIG_FILE, $php, LOCK_EX) === false) {
        throw new RuntimeException('Kan config.php niet schrijven. Geef de webserver schrijfrechten op de map familyplanner.');
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(CONFIG_FILE, true);
    }
}

$pdo = null;
$error = null;
try {
    $pdo = is_file(CONFIG_FILE) ? connect() : null;
} catch (Throwable $e) {
    $pdo = null; // config.php exists but doesn't work: let the user re-enter it
}
try {
    if (!$pdo) {
        $step = 'db';
    } else {
        run_migrations($pdo);
        $step = $pdo->query('SELECT 1 FROM users LIMIT 1')->fetchColumn() ? 'done' : 'account';
    }
} catch (Throwable $e) {
    $step = 'account';
    $error = 'Database bijwerken mislukt: ' . $e->getMessage();
}

$values = ['host' => 'localhost', 'name' => 'familyplanner', 'user' => 'familyplanner', 'acc_name' => 'Carin', 'acc_email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step !== 'done') {
    $values = array_merge($values, array_map('trim', array_intersect_key($_POST, $values)));
    try {
        if ($step === 'db') {
            $config = ['host' => $values['host'], 'name' => $values['name'], 'user' => $values['user'], 'pass' => $_POST['pass'] ?? ''];
            try {
                connect($config);
            } catch (PDOException $e) {
                throw new RuntimeException('Verbinding mislukt: ' . $e->getMessage());
            }
            write_config($config);
        } else {
            $pw = $_POST['acc_pass'] ?? '';
            if ($values['acc_name'] === '') {
                throw new RuntimeException('Vul een naam in.');
            }
            if (!filter_var($values['acc_email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Vul een geldig e-mailadres in.');
            }
            if (strlen($pw) < 8) {
                throw new RuntimeException('Het wachtwoord moet minimaal 8 tekens zijn.');
            }
            if ($pw !== ($_POST['acc_pass2'] ?? '')) {
                throw new RuntimeException('De wachtwoorden komen niet overeen.');
            }
            $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')
                ->execute([$values['acc_name'], strtolower($values['acc_email']), password_hash($pw, PASSWORD_DEFAULT)]);
        }
        header('Location: install.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function v(array $values, string $key): string
{
    return htmlspecialchars($values[$key] ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Familie Planner installeren</title>
<style>
:root{--bg:#FFF8F1;--card:#fff;--ink:#2B2A33;--muted:#76737F;--line:#ECE5DC;--accent:#6C5CE7;--err-bg:#fdf1ee;--err:#9b3d28}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,sans-serif}
main{max-width:520px;margin:auto;padding:40px 16px}
.logo{font-size:28px;font-weight:900;letter-spacing:-1px;margin-bottom:18px}.logo span{color:var(--accent)}
.steps{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.steps span{padding:6px 12px;border-radius:99px;background:var(--card);color:var(--muted);font-size:13px;font-weight:700}
.steps .on{background:var(--accent);color:#fff}
.card{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:22px}
h2{margin:0 0 6px}
label{display:block;font-weight:700;font-size:14px;margin:14px 0 6px}
input{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:12px;font:inherit;background:#fff;color:var(--ink)}
input:focus{outline:2px solid var(--accent);border-color:transparent}
button{margin-top:20px;padding:12px 16px;border:0;border-radius:12px;background:var(--accent);color:#fff;font:inherit;font-weight:750;cursor:pointer}
.error{background:var(--err-bg);color:var(--err);padding:12px 14px;border-radius:12px;margin-bottom:10px}
.hint{color:var(--muted);font-size:14px;line-height:1.5}
a{color:var(--accent);font-weight:700}
</style>
</head>
<body>
<main>
  <div class="logo">familie<span>planner</span></div>
  <div class="steps">
    <span class="<?= $step === 'db' ? 'on' : '' ?>">1. Database</span>
    <span class="<?= $step === 'account' ? 'on' : '' ?>">2. Account</span>
    <span class="<?= $step === 'done' ? 'on' : '' ?>">3. Klaar</span>
  </div>
  <div class="card">
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($step === 'db'): ?>
      <h2>Databaseverbinding</h2>
      <p class="hint">Maak in aaPanel (Databases → Add database) een lege database aan, bijvoorbeeld <b>familyplanner</b>, en vul hier dezelfde gegevens in. Ze worden getest en daarna veilig op de server bewaard.</p>
      <form method="post">
        <label for="host">Host</label><input id="host" name="host" value="<?= v($values, 'host') ?>" required>
        <label for="name">Naam database</label><input id="name" name="name" value="<?= v($values, 'name') ?>" required>
        <label for="user">Gebruikersnaam</label><input id="user" name="user" value="<?= v($values, 'user') ?>" required>
        <label for="pass">Wachtwoord</label><input id="pass" name="pass" type="password" autocomplete="off">
        <button type="submit">Verbinden en opslaan</button>
      </form>

    <?php elseif ($step === 'account'): ?>
      <h2>Jouw account</h2>
      <p class="hint">✓ Database verbonden. Maak nu het eerste inlogaccount aan. Later kun je in de app een account voor de ander toevoegen.</p>
      <form method="post">
        <label for="acc_name">Naam</label><input id="acc_name" name="acc_name" value="<?= v($values, 'acc_name') ?>" required>
        <label for="acc_email">E-mailadres</label><input id="acc_email" name="acc_email" type="email" value="<?= v($values, 'acc_email') ?>" autocomplete="username" required>
        <label for="acc_pass">Wachtwoord <span class="hint">(minimaal 8 tekens)</span></label><input id="acc_pass" name="acc_pass" type="password" minlength="8" autocomplete="new-password" required>
        <label for="acc_pass2">Herhaal wachtwoord</label><input id="acc_pass2" name="acc_pass2" type="password" minlength="8" autocomplete="new-password" required>
        <button type="submit">Account aanmaken</button>
      </form>

    <?php else: ?>
      <h2>✓ Installatie voltooid</h2>
      <p>De database is verbonden en er is een account. Deze pagina is nu vergrendeld.</p>
      <p><a href="./">Naar de Familie Planner →</a></p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
