<?php
require __DIR__ . '/lib/app.php';

if (current_user()) {
    redirect('./');
}

// Squares anyone may join, and whether any square needs a code
$openSchools = db()->query("SELECT id, name FROM schools WHERE registration_mode = 'OPEN' ORDER BY name")->fetchAll();
$hasCodeSchools = (bool) db()->query("SELECT 1 FROM schools WHERE registration_mode = 'CODE' LIMIT 1")->fetchColumn();

$error = null;
$values = ['name' => '', 'email' => '', 'school_id' => '', 'code' => ''];

if (is_post()) {
    foreach ($values as $key => $unused) {
        $values[$key] = post($key);
    }
    $password = (string) ($_POST['password'] ?? '');
    $email = strtolower($values['email']);

    $schoolId = null;
    if ($values['code'] !== '') {
        $stmt = db()->prepare("SELECT id FROM schools WHERE registration_mode = 'CODE' AND join_code = ?");
        $stmt->execute([$values['code']]);
        $schoolId = $stmt->fetchColumn() ?: null;
    } elseif (count($openSchools) === 1) {
        $schoolId = $openSchools[0]['id'];
    } elseif (in_array((int) $values['school_id'], array_map('intval', array_column($openSchools, 'id')), true)) {
        $schoolId = (int) $values['school_id'];
    }

    if ($values['name'] === '') {
        $error = 'Vul je naam in.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Vul een geldig e-mailadres in.';
    } elseif (strlen($password) < 8) {
        $error = 'Kies een wachtwoord van minimaal 8 tekens.';
    } elseif (!$schoolId) {
        $error = $values['code'] !== '' ? 'Deze schoolcode kennen we niet. Controleer de code.' : 'Kies je schoolplein.';
    } else {
        $stmt = db()->prepare('SELECT 1 FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            $error = 'Er is al een account met dit e-mailadres. Log in.';
        }
    }

    if (!$error) {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'PARENT')")
            ->execute([limit_text($values['name'], 100), $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO school_memberships (school_id, user_id) VALUES (?, ?)')->execute([$schoolId, $userId]);
        $pdo->commit();

        log_in($userId);
        $_SESSION['school_id'] = $schoolId;
        flash('Welkom op het plein! Voeg in je profiel je kinderen toe, dan kun je met ze meedoen.');
        redirect('profiel.php');
    }
}

page_start('Account maken', ['narrow' => true]);
?>
<section class="hero"><h1>Doe mee op het plein</h1><p>Maak een account voor jouw gezin.</p></section>
<article class="card">
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$openSchools && !$hasCodeSchools): ?>
    <p>Aanmelden is op dit moment niet mogelijk.</p>
  <?php else: ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label for="name">Je naam</label>
    <input id="name" name="name" value="<?= e($values['name']) ?>" autocomplete="name" required>
    <label for="email">E-mailadres</label>
    <input id="email" name="email" type="email" value="<?= e($values['email']) ?>" autocomplete="email" required>
    <label for="password">Wachtwoord <small>(minimaal 8 tekens)</small></label>
    <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>

    <?php if (count($openSchools) > 1): ?>
      <label for="school_id">Schoolplein</label>
      <select id="school_id" name="school_id">
        <option value="">Kies…</option>
        <?php foreach ($openSchools as $s): ?>
          <option value="<?= (int) $s['id'] ?>"<?= $values['school_id'] == $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php elseif (count($openSchools) === 1): ?>
      <p class="hint">Je meldt je aan bij <b><?= e($openSchools[0]['name']) ?></b>.</p>
    <?php endif; ?>

    <?php if ($hasCodeSchools): ?>
      <label for="code">Schoolcode <?= $openSchools ? '<small>(als je die van school hebt gekregen)</small>' : '' ?></label>
      <input id="code" name="code" value="<?= e($values['code']) ?>" autocomplete="off" <?= $openSchools ? '' : 'required' ?>>
    <?php endif; ?>

    <button class="btn" type="submit">Account maken</button>
  </form>
  <?php endif; ?>
  <p class="hint">Heb je al een account? <a href="login.php">Log in</a></p>
</article>
<?php page_end();
