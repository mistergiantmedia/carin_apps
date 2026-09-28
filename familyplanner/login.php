<?php
require __DIR__ . '/lib/app.php';

if (current_user()) {
    redirect('./');
}
$error = null;
$login = '';

/** "René " → "rene": lower case, no accents, single spaces, so names are easy to type on a TV remote. */
function plain_name(string $s): string
{
    $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
    return strtr($s, [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
    ]);
}

if (is_post()) {
    // Log in with a name (Rene, René) or an e-mail address
    $login = post('login');
    $password = (string) ($_POST['password'] ?? '');
    if (strpos($login, '@') !== false) {
        $stmt = db()->prepare('SELECT u.id, u.name, u.password_hash, f.status FROM fp_users u JOIN fp_families f ON f.id = u.family_id WHERE u.email = ?');
        $stmt->execute([strtolower($login)]);
        $candidates = $stmt->fetchAll();
    } else {
        $wanted = plain_name($login);
        // Logging in with just a first name only works for the founding family (Carin, René)
        $candidates = array_filter(db()->query("SELECT u.id, u.name, u.password_hash, f.status FROM fp_users u JOIN fp_families f ON f.id = u.family_id WHERE u.family_id = 1")->fetchAll(), function ($u) use ($wanted) {
            return $wanted !== '' && plain_name($u['name']) === $wanted;
        });
    }
    // Two accounts with the same name are fine: the password decides which one it is
    foreach ($candidates as $user) {
        if (password_verify($password, $user['password_hash'])) {
            if ($user['status'] === 'PENDING') {
                $error = 'Je gezin is aangemeld en wacht nog op goedkeuring. Je krijgt bericht zodra je kunt inloggen.';
                break;
            }
            if ($user['status'] !== 'ACTIVE') {
                $error = 'Dit account is niet (meer) actief.';
                break;
            }
            log_in((int) $user['id']);
            redirect(safe_next((string) ($_GET['next'] ?? '')));
        }
    }
    $error = $error ?: 'E-mailadres of wachtwoord klopt niet.';
    usleep(400000);
}

page_start('Inloggen', ['narrow' => true, 'bodyClass' => 'login-page']);
?>
<div class="login">
  <div class="login-brand">familie<span>planner</span></div>
  <p class="sub">De planner voor je hele gezin: agenda, vriendjes, verjaardagen en meer.</p>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <label for="login">E-mailadres</label>
    <input id="login" name="login" type="text" inputmode="email" value="<?= e($login) ?>" autocomplete="username" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="naam@voorbeeld.nl" required autofocus>
    <label for="password">Wachtwoord</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button class="btn wide" type="submit">Inloggen</button>
  </form>
  <p class="muted" style="text-align:center;margin-top:16px">Nog geen account? <a href="aanmelden.php"><b>Gezin aanmaken</b></a></p>
</div>
<?php page_end();
