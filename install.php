<?php
// One-time setup: writes config.php and creates the first admin account.
// Locks itself as soon as an admin exists.
require __DIR__ . '/db.php';

const CONFIG_FILE = __DIR__ . '/config.php';

function write_config(string $host, string $name, string $user, string $pass): void
{
    $php = "<?php\n// Written by install.php. Not in git: holds the DB password.\n"
        . 'define(\'DB_HOST\', ' . var_export($host, true) . ");\n"
        . 'define(\'DB_NAME\', ' . var_export($name, true) . ");\n"
        . 'define(\'DB_USER\', ' . var_export($user, true) . ");\n"
        . 'define(\'DB_PASS\', ' . var_export($pass, true) . ");\n";
    if (@file_put_contents(CONFIG_FILE, $php, LOCK_EX) === false) {
        throw new RuntimeException('Kan config.php niet schrijven. Geef de webserver schrijfrechten op de map, of maak config.php handmatig aan.');
    }
}

function create_users_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('ADMIN','PARENT','STUDENT','FORMER_STUDENT','VOLUNTEER','ORGANIZER') NOT NULL DEFAULT 'PARENT',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function admin_exists(PDO $pdo): bool
{
    create_users_table($pdo);
    return (bool) $pdo->query("SELECT 1 FROM users WHERE role = 'ADMIN' LIMIT 1")->fetchColumn();
}

// Work out which step we are on
$pdo = null;
try {
    $pdo = is_file(CONFIG_FILE) ? db() : null;
} catch (Throwable $e) {
    $pdo = null; // config.php exists but doesn't work: let the user re-enter it
}
$error = null;
try {
    $step = !$pdo ? 'db' : (admin_exists($pdo) ? 'done' : 'admin');
} catch (Throwable $e) {
    $step = 'admin';
    $error = 'Kan de tabel users niet aanmaken: ' . $e->getMessage();
}
$values = ['host' => 'localhost', 'name' => 'jouwschoolplein', 'user' => 'jouwschoolplein', 'admin_name' => '', 'admin_email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step !== 'done') {
    $values = array_merge($values, array_map('trim', array_intersect_key($_POST, $values)));
    try {
        if ($step === 'db') {
            $pass = $_POST['pass'] ?? '';
            try {
                new PDO("mysql:host={$values['host']};dbname={$values['name']};charset=utf8mb4", $values['user'], $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (PDOException $e) {
                throw new RuntimeException('Verbinding mislukt: ' . $e->getMessage());
            }
            write_config($values['host'], $values['name'], $values['user'], $pass);
        } else {
            $pw = $_POST['admin_pass'] ?? '';
            if ($values['admin_name'] === '') {
                throw new RuntimeException('Vul een naam in.');
            }
            if (!filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Vul een geldig e-mailadres in.');
            }
            if (strlen($pw) < 10) {
                throw new RuntimeException('Het wachtwoord moet minimaal 10 tekens zijn.');
            }
            if ($pw !== ($_POST['admin_pass2'] ?? '')) {
                throw new RuntimeException('De wachtwoorden komen niet overeen.');
            }
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'ADMIN')");
            $stmt->execute([$values['admin_name'], strtolower($values['admin_email']), password_hash($pw, PASSWORD_DEFAULT)]);
        }
        header('Location: install.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function v(array $values, string $key): string
{
    return htmlspecialchars($values[$key] ?? '');
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Installatie</title>
<link rel="stylesheet" href="style.css">
<style>
.steps{display:flex;gap:8px;margin-bottom:18px}.steps span{padding:6px 12px;border-radius:99px;background:#fff;color:var(--muted);font-size:13px;font-weight:700}.steps .on{background:var(--green);color:#fff}
label{display:block;font-weight:700;font-size:14px;margin:14px 0 6px}
input{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:12px;font:inherit;background:#fff}
input:focus{outline:2px solid var(--green);border-color:transparent}
button.join{margin-top:20px}
.error{background:#fdf1ee;color:#9b3d28;padding:12px 14px;border-radius:12px;margin-bottom:6px}
.hint{color:var(--muted);font-size:13px}
</style>
</head>
<body>
<main class="shell" style="max-width:560px">
  <header><div><div class="logo">school<span>plein</span></div><div class="school">Installatie</div></div></header>
  <section style="padding:0 24px">
    <div class="steps">
      <span class="<?= $step === 'db' ? 'on' : '' ?>">1. Database</span>
      <span class="<?= $step === 'admin' ? 'on' : '' ?>">2. Beheerder</span>
      <span class="<?= $step === 'done' ? 'on' : '' ?>">3. Klaar</span>
    </div>
    <article class="card">
      <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <?php if ($step === 'db'): ?>
        <h2>Databaseverbinding</h2>
        <p class="hint">De gegevens worden getest en daarna opgeslagen in config.php op de server.</p>
        <form method="post">
          <label for="host">Host</label><input id="host" name="host" value="<?= v($values, 'host') ?>" required>
          <label for="name">Database</label><input id="name" name="name" value="<?= v($values, 'name') ?>" required>
          <label for="user">Gebruiker</label><input id="user" name="user" value="<?= v($values, 'user') ?>" required>
          <label for="pass">Wachtwoord</label><input id="pass" name="pass" type="password" autocomplete="off">
          <button class="join" type="submit">Verbinden en opslaan</button>
        </form>

      <?php elseif ($step === 'admin'): ?>
        <h2>Beheerdersaccount</h2>
        <p class="hint">✓ Database verbonden. Maak nu het eerste beheerdersaccount aan.</p>
        <form method="post">
          <label for="admin_name">Naam</label><input id="admin_name" name="admin_name" value="<?= v($values, 'admin_name') ?>" required>
          <label for="admin_email">E-mailadres</label><input id="admin_email" name="admin_email" type="email" value="<?= v($values, 'admin_email') ?>" autocomplete="username" required>
          <label for="admin_pass">Wachtwoord <span class="hint">(minimaal 10 tekens)</span></label><input id="admin_pass" name="admin_pass" type="password" minlength="10" autocomplete="new-password" required>
          <label for="admin_pass2">Herhaal wachtwoord</label><input id="admin_pass2" name="admin_pass2" type="password" minlength="10" autocomplete="new-password" required>
          <button class="join" type="submit">Account aanmaken</button>
        </form>

      <?php else: ?>
        <h2>✓ Installatie voltooid</h2>
        <p>De database is verbonden en er is een beheerdersaccount. Deze pagina is nu vergrendeld.</p>
        <p><a href="./">Naar Schoolplein →</a></p>
      <?php endif; ?>
    </article>
  </section>
</main>
</body>
</html>
