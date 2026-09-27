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
        $stmt = db()->prepare('SELECT id, name, password_hash FROM fp_users WHERE email = ?');
        $stmt->execute([strtolower($login)]);
        $candidates = $stmt->fetchAll();
    } else {
        $wanted = plain_name($login);
        $candidates = array_filter(db()->query('SELECT id, name, password_hash FROM fp_users')->fetchAll(), function ($u) use ($wanted) {
            return $wanted !== '' && plain_name($u['name']) === $wanted;
        });
    }
    // Two accounts with the same name are fine: the password decides which one it is
    foreach ($candidates as $user) {
        if (password_verify($password, $user['password_hash'])) {
            log_in((int) $user['id']);
            redirect(safe_next((string) ($_GET['next'] ?? '')));
        }
    }
    $error = 'Naam of wachtwoord klopt niet.';
    usleep(400000);
}

page_start('Inloggen', ['narrow' => true, 'bodyClass' => 'login-page']);
?>
<div class="login">
  <div class="login-brand">familie<span>planner</span></div>
  <p class="sub">Het gezinsplan van Carin, Rene, Kaila en Bodi.</p>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <label for="login">Naam <small>(of e-mailadres)</small></label>
    <input id="login" name="login" type="text" value="<?= e($login) ?>" autocomplete="username" autocapitalize="words" autocorrect="off" spellcheck="false" placeholder="bijv. Carin" required autofocus>
    <label for="password">Wachtwoord</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button class="btn wide" type="submit">Inloggen</button>
  </form>
</div>
<?php page_end();
