<?php
require __DIR__ . '/lib/app.php';

if (current_user()) {
    redirect('./');
}
$error = null;
$email = '';
if (is_post()) {
    $email = strtolower(post('email'));
    $stmt = db()->prepare('SELECT id, password_hash FROM fp_users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
        log_in((int) $user['id']);
        redirect(safe_next((string) ($_GET['next'] ?? '')));
    }
    $error = 'E-mailadres of wachtwoord klopt niet.';
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
    <label for="email">E-mailadres</label>
    <input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
    <label for="password">Wachtwoord</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button class="btn wide" type="submit">Inloggen</button>
  </form>
</div>
<?php page_end();
