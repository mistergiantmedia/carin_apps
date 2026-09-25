<?php
require __DIR__ . '/lib/app.php';

$next = safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
if (current_user()) {
    redirect($next);
}

$error = null;
$email = '';

if (is_post()) {
    $email = strtolower(post('email'));
    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
        log_in((int) $user['id']);
        redirect($next);
    }
    $error = 'E-mailadres of wachtwoord klopt niet.';
}

page_start('Inloggen', ['narrow' => true]);
?>
<section class="hero"><h1>Wat gebeurt er op het plein?</h1><p>Ontdek wat gezinnen, kinderen en oud-leerlingen samen doen — van school tot buurt.</p></section>
<article class="card">
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label for="email">E-mailadres</label>
    <input id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
    <label for="password">Wachtwoord</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button class="btn" type="submit">Inloggen</button>
  </form>
  <p class="hint">Nog geen account? <a href="register.php">Maak er een aan</a></p>
</article>
<?php page_end();
