<?php
// First login with a temporary password: choose your own before using the app.
require __DIR__ . '/lib/app.php';
$user = require_login();

$error = null;
if (is_post()) {
    $pw = (string) ($_POST['new'] ?? '');
    $stmt = db()->prepare('SELECT password_hash FROM fp_users WHERE id = ?');
    $stmt->execute([$user['id']]);
    if (strlen($pw) < 8) {
        $error = 'Kies een wachtwoord van minimaal 8 tekens.';
    } elseif ($pw !== (string) ($_POST['new2'] ?? '')) {
        $error = 'De wachtwoorden komen niet overeen.';
    } elseif (password_verify($pw, (string) $stmt->fetchColumn())) {
        $error = 'Kies een ander wachtwoord dan het tijdelijke.';
    } else {
        db()->prepare('UPDATE fp_users SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        flash('Je wachtwoord is gewijzigd. Welkom! 👋');
        redirect('./');
    }
}

page_start('Wachtwoord kiezen', ['narrow' => true, 'bodyClass' => 'login-page']);
?>
<div class="login">
  <div class="login-brand">familie<span>planner</span></div>
  <p class="sub">Welkom <?= e($user['name']) ?>! Je bent ingelogd met een tijdelijk wachtwoord. Kies eerst je eigen wachtwoord.</p>
  <form method="post" class="card form">
    <?= csrf_field() ?>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <label for="new">Nieuw wachtwoord <small>(minimaal 8 tekens)</small></label>
    <input id="new" name="new" type="password" minlength="8" autocomplete="new-password" required autofocus>
    <label for="new2">Herhaal wachtwoord</label>
    <input id="new2" name="new2" type="password" minlength="8" autocomplete="new-password" required>
    <button class="btn wide" type="submit">Opslaan en verder</button>
  </form>
  <p class="muted small" style="text-align:center;margin-top:12px"><a href="logout.php">Uitloggen</a></p>
</div>
<?php page_end();
