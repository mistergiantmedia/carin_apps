<?php
// Set a new password via a one-time link made by a site admin (beheer.php).
require __DIR__ . '/lib/app.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$reset = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare('SELECT r.id, r.user_id, u.name FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.expires_at > NOW()');
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch() ?: null;
}

$error = null;
if ($reset && is_post()) {
    $new = (string) ($_POST['new_password'] ?? '');
    if (strlen($new) < 8) {
        $error = 'Kies een wachtwoord van minimaal 8 tekens.';
    } elseif ($new !== (string) ($_POST['new_password2'] ?? '')) {
        $error = 'De twee wachtwoorden zijn niet gelijk.';
    } else {
        $pdo = db();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $reset['user_id']]);
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$reset['user_id']]);
        flash('Je nieuwe wachtwoord is ingesteld. Je kunt nu inloggen.');
        redirect('login.php');
    }
}

page_start('Nieuw wachtwoord', ['narrow' => true]);
?>
<article class="card">
  <?php if (!$reset): ?>
    <h2>Deze link werkt niet meer</h2>
    <p>De link is verlopen of al gebruikt. Vraag de beheerder om een nieuwe link.</p>
    <p><a href="login.php">← Naar inloggen</a></p>
  <?php else: ?>
    <h2>Nieuw wachtwoord voor <?= e($reset['name']) ?></h2>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label for="new_password">Nieuw wachtwoord <small>(minimaal 8 tekens)</small></label>
      <input id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required autofocus>
      <label for="new_password2">Herhaal wachtwoord</label>
      <input id="new_password2" name="new_password2" type="password" minlength="8" autocomplete="new-password" required>
      <button class="btn" type="submit">Wachtwoord opslaan</button>
    </form>
  <?php endif; ?>
</article>
<?php page_end();
