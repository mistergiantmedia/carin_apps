<?php
// Register a new family. The family waits for approval by Carin or René (beheer.php) before anyone can log in.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/upload.php';

if (current_user()) {
    redirect('./');
}
$done = false;
$error = null;
$f = ['family' => '', 'first' => '', 'last' => '', 'email' => ''];
if (is_post()) {
    $f = array_map('trim', array_intersect_key($_POST, $f)) + $f;
    $email = strtolower($f['email']);
    $pw = (string) ($_POST['password'] ?? '');
    if (post('website') !== '') {
        $done = true; // spam bot filled the hidden field: pretend it worked
    } elseif ($f['family'] === '' || $f['first'] === '' || $f['last'] === '') {
        $error = 'Vul de naam van je gezin en je voor- en achternaam in.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Vul een geldig e-mailadres in.';
    } elseif (strlen($pw) < 8) {
        $error = 'Kies een wachtwoord van minimaal 8 tekens.';
    } elseif ($pw !== (string) ($_POST['password2'] ?? '')) {
        $error = 'De wachtwoorden komen niet overeen.';
    } else {
        $exists = db()->prepare('SELECT 1 FROM fp_users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetchColumn()) {
            $error = 'Er is al een account met dit e-mailadres. Probeer in te loggen.';
        } else {
            try {
                $photo = save_photo('photo');
            } catch (RuntimeException $e) {
                $photo = null; // the photo is optional: can be added later in Instellingen
            }
            db()->prepare("INSERT INTO fp_families (name, photo, status) VALUES (?, ?, 'PENDING')")->execute([mb_cut($f['family'], 120), $photo]);
            $familyId = (int) db()->lastInsertId();
            db()->prepare('INSERT INTO fp_users (family_id, name, last_name, email, password_hash) VALUES (?, ?, ?, ?, ?)')
                ->execute([$familyId, mb_cut($f['first'], 100), mb_cut($f['last'], 100), $email, password_hash($pw, PASSWORD_DEFAULT)]);
            $done = true;
        }
    }
}

page_start('Gezin aanmaken', ['narrow' => true, 'bodyClass' => 'login-page']);
?>
<div class="login">
  <div class="login-brand">familie<span>planner</span></div>
  <?php if ($done): ?>
    <div class="card" style="text-align:center">
      <div style="font-size:44px">🎉</div>
      <h2>Bedankt voor je aanmelding!</h2>
      <p class="muted">Je gezin wordt zo snel mogelijk goedgekeurd. Daarna kun je inloggen met je e-mailadres en wachtwoord, en de andere ouder of verzorger toevoegen via Instellingen.</p>
      <a class="btn" href="login.php">Naar inloggen</a>
    </div>
  <?php else: ?>
    <p class="sub">Maak een planner aan voor je eigen gezin.</p>
    <form method="post" enctype="multipart/form-data" class="card form">
      <?= csrf_field() ?>
      <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
      <label for="family">Naam gezin</label>
      <input id="family" name="family" value="<?= e($f['family']) ?>" placeholder="bijv. Gezin de Vries" required autofocus>
      <div class="row2">
        <div><label for="first">Voornaam</label><input id="first" name="first" value="<?= e($f['first']) ?>" autocomplete="given-name" required></div>
        <div><label for="last">Achternaam</label><input id="last" name="last" value="<?= e($f['last']) ?>" autocomplete="family-name" required></div>
      </div>
      <label for="email">E-mailadres</label>
      <input id="email" name="email" type="email" value="<?= e($f['email']) ?>" autocomplete="email" required>
      <div class="row2">
        <div><label for="password">Wachtwoord</label><input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required></div>
        <div><label for="password2">Herhaal wachtwoord</label><input id="password2" name="password2" type="password" minlength="8" autocomplete="new-password" required></div>
      </div>
      <?= photo_field(null, 'Gezinsfoto (mag ook later)', 'Gezinsfoto') ?>
      <p class="hint" style="margin:14px 0 0">👫 Vul hier alleen je eigen gegevens in. Een andere ouder of verzorger kun je later toevoegen via <b>Instellingen</b>. Die krijgt dan een eigen e-mailadres en wachtwoord.</p>
      <input name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <button class="btn wide" type="submit">Gezin aanmaken</button>
    </form>
    <p class="muted" style="text-align:center;margin-top:16px">Al een account? <a href="login.php"><b>Inloggen</b></a></p>
  <?php endif; ?>
</div>
<?php page_end();
