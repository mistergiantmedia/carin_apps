<?php
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/chat.php';

$user = require_login();
$school = current_school();
$error = null;

if (is_post()) {
    $action = post('action');
    $pdo = db();

    if ($action === 'details') {
        $name = post('name');
        $email = strtolower(post('email'));
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $user['id']]);
        if ($name === '') {
            $error = 'Vul je naam in.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Vul een geldig e-mailadres in.';
        } elseif ($stmt->fetchColumn()) {
            $error = 'Dit e-mailadres wordt al door een ander account gebruikt.';
        } else {
            $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([limit_text($name, 100), $email, $user['id']]);
            flash('Je gegevens zijn opgeslagen.');
            redirect('profiel.php');
        }
    } elseif ($action === 'add_child' || $action === 'edit_child') {
        $firstName = post('first_name');
        $birth = post('birth_date');
        $birthDate = $birth !== '' ? DateTime::createFromFormat('Y-m-d', $birth) : null;
        $classRow = null;
        if ((int) post('class_id') > 0 && $school) {
            $stmt = $pdo->prepare('SELECT id, name FROM classes WHERE id = ? AND school_id = ?');
            $stmt->execute([(int) post('class_id'), $school['id']]);
            $classRow = $stmt->fetch() ?: null;
        }
        if ($firstName === '') {
            $error = 'Vul de voornaam van je kind in.';
        } elseif ($birth !== '' && (!$birthDate || $birthDate->format('Y-m-d') !== $birth || $birth > date('Y-m-d'))) {
            $error = 'Vul een geldige geboortedatum in.';
        } else {
            $group = $classRow ? $classRow['name'] : post('group_name');
            $values = [
                limit_text($firstName, 80),
                post('last_name') !== '' ? limit_text(post('last_name'), 80) : null,
                post('address') !== '' ? limit_text(post('address'), 200) : null,
                $birth !== '' ? $birth : null,
                $classRow ? $classRow['id'] : null,
                $group !== '' ? limit_text($group, 80) : null,
            ];
            if ($action === 'add_child') {
                $pdo->prepare('INSERT INTO children (first_name, last_name, address, birth_date, class_id, group_name, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute(array_merge($values, [$user['id']]));
                flash(limit_text($firstName, 80) . ' is toegevoegd.');
            } else {
                $pdo->prepare('UPDATE children SET first_name = ?, last_name = ?, address = ?, birth_date = ?, class_id = ?, group_name = ? WHERE id = ? AND user_id = ?')
                    ->execute(array_merge($values, [(int) post('child_id'), $user['id']]));
                flash('Gegevens van ' . limit_text($firstName, 80) . ' zijn opgeslagen.');
            }
            redirect('profiel.php#kinderen');
        }
    } elseif ($action === 'delete_child') {
        $pdo->prepare('DELETE FROM children WHERE id = ? AND user_id = ?')->execute([(int) post('child_id'), $user['id']]);
        flash('Kind verwijderd.');
        redirect('profiel.php#kinderen');
    } elseif ($action === 'password') {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $stmt->fetchColumn())) {
            $error = 'Je huidige wachtwoord klopt niet.';
        } elseif (strlen($new) < 8) {
            $error = 'Kies een nieuw wachtwoord van minimaal 8 tekens.';
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            flash('Je wachtwoord is gewijzigd.');
            redirect('profiel.php');
        }
    } elseif ($action === 'delete_account') {
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        if (!password_verify((string) ($_POST['confirm_password'] ?? ''), (string) $stmt->fetchColumn())) {
            $error = 'Wachtwoord klopt niet. Je account is niet verwijderd.';
        } elseif ($user['role'] === 'ADMIN' && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN'")->fetchColumn() <= 1) {
            $error = 'Je bent de enige hoofdbeheerder. Maak eerst iemand anders hoofdbeheerder.';
        } else {
            // Children, memberships, participation and messages go with it (ON DELETE CASCADE);
            // activities they organised stay, without organiser.
            delete_message_images('user_id = ?', [$user['id']]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
            $_SESSION = [];
            session_destroy();
            redirect('login.php');
        }
    }
}

$stmt = db()->prepare('SELECT id, first_name, last_name, address, birth_date, class_id, group_name FROM children WHERE user_id = ? ORDER BY first_name');
$stmt->execute([$user['id']]);
$children = $stmt->fetchAll();

$classes = [];
if ($school) {
    $stmt = db()->prepare('SELECT id, name FROM classes WHERE school_id = ? ORDER BY name');
    $stmt->execute([$school['id']]);
    $classes = $stmt->fetchAll();
}

// The fields of the child form, filled with $c when editing.
function child_fields(?array $c, array $classes, string $prefix): void
{
    $v = function (string $key) use ($c) {
        return e($c[$key] ?? '');
    };
    ?>
    <div class="row">
      <div><label for="<?= $prefix ?>first">Voornaam</label><input id="<?= $prefix ?>first" name="first_name" maxlength="80" value="<?= $v('first_name') ?>" required></div>
      <div><label for="<?= $prefix ?>last">Achternaam</label><input id="<?= $prefix ?>last" name="last_name" maxlength="80" value="<?= $v('last_name') ?>"></div>
    </div>
    <div class="row">
      <div>
        <?php if ($classes): ?>
          <label for="<?= $prefix ?>class">Klas</label>
          <select id="<?= $prefix ?>class" name="class_id">
            <option value="">— kies een klas —</option>
            <?php foreach ($classes as $k): ?>
              <option value="<?= (int) $k['id'] ?>"<?= $c && (int) $c['class_id'] === (int) $k['id'] ? ' selected' : '' ?>><?= e($k['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <label for="<?= $prefix ?>group">Groep <small>(optioneel)</small></label>
          <input id="<?= $prefix ?>group" name="group_name" maxlength="80" value="<?= $v('group_name') ?>" placeholder="bijv. Grachtenvaarders, groep 5">
        <?php endif; ?>
      </div>
      <div><label for="<?= $prefix ?>birth">Geboortedatum</label><input id="<?= $prefix ?>birth" name="birth_date" type="date" max="<?= date('Y-m-d') ?>" value="<?= $v('birth_date') ?>"></div>
    </div>
    <label for="<?= $prefix ?>address">Adres <small>(straat, huisnummer, plaats)</small></label>
    <input id="<?= $prefix ?>address" name="address" maxlength="200" value="<?= $v('address') ?>" autocomplete="off">
    <?php
}

page_start('Mijn profiel', ['narrow' => true]);
?>
<p class="back"><a href="./">← Terug naar het plein</a></p>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<article class="card" id="kinderen">
  <h2>Jouw kinderen</h2>
  <p class="hint">Alleen de voornaam is verplicht. Adres en geboortedatum zijn alleen zichtbaar voor de ouders van dezelfde klas en de beheerders (zie <a href="klassen.php">Klassen</a>). Bij een activiteit kies je wie er meegaat.</p>
  <?php foreach ($children as $c): ?>
    <details class="list-row" style="display:block">
      <summary><b><?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?></b><?= $c['group_name'] ? ' <small>' . e($c['group_name']) . '</small>' : '' ?> <small>— gegevens wijzigen</small></summary>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="child_id" value="<?= (int) $c['id'] ?>">
        <?php child_fields($c, $classes, 'c' . (int) $c['id']); ?>
        <button class="btn" name="action" value="edit_child">Opslaan</button>
      </form>
      <form method="post" onsubmit="return confirm(<?= e(json_encode($c['first_name'] . ' verwijderen? Aanmeldingen voor activiteiten vervallen ook.')) ?>)">
        <?= csrf_field() ?><input type="hidden" name="child_id" value="<?= (int) $c['id'] ?>">
        <button class="link" name="action" value="delete_child">verwijderen</button>
      </form>
    </details>
  <?php endforeach; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <?php child_fields(null, $classes, 'new'); ?>
    <button class="btn" name="action" value="add_child">＋ Kind toevoegen</button>
  </form>
</article>

<article class="card">
  <h2>Jouw gegevens</h2>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label for="name">Voor- en achternaam</label><input id="name" name="name" value="<?= e($user['name']) ?>" maxlength="100" required>
    <label for="email">E-mailadres</label><input id="email" name="email" type="email" value="<?= e($user['email']) ?>" required>
    <button class="btn" name="action" value="details">Opslaan</button>
  </form>
</article>

<article class="card">
  <h2>Wachtwoord wijzigen</h2>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label for="current_password">Huidig wachtwoord</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
    <label for="new_password">Nieuw wachtwoord <small>(minimaal 8 tekens)</small></label><input id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
    <button class="btn secondary" name="action" value="password">Wachtwoord wijzigen</button>
  </form>
</article>

<article class="card">
  <form method="post" action="logout.php"><?= csrf_field() ?><button class="btn secondary">Uitloggen</button></form>
  <details class="danger-zone">
    <summary>Account verwijderen</summary>
    <p class="hint">Je account, kinderen, aanmeldingen en berichten worden verwijderd. Dit kan niet ongedaan worden gemaakt.</p>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label for="confirm_password">Bevestig met je wachtwoord</label><input id="confirm_password" name="confirm_password" type="password" required>
      <button class="btn danger" name="action" value="delete_account">Account definitief verwijderen</button>
    </form>
  </details>
</article>
<?php page_end();
