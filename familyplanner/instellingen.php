<?php
// Settings: family members (name, photo, colour, birthday), login accounts and passwords,
// the calendar link for phones, and the example data.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/upload.php';
require __DIR__ . '/lib/demo.php';
$user = require_login();

if (is_post()) {
    $action = post('action');
    try {
        if ($action === 'member') {
            $id = post_int('id');
            $old = member($id);
            if (post('name') === '') {
                throw new RuntimeException('Vul een naam in.');
            }
            [$bd, $bm, $by] = posted_birthday();
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', post('color')) ? post('color') : '#6C5CE7';
            $photo = posted_photo($old['photo'] ?? null);
            $data = [mb_cut(post('name'), 60), post('role') === 'PARENT' ? 'PARENT' : 'CHILD', $bd, $bm, $by, $color, mb_cut(post('emoji') ?: '🙂', 16), $photo];
            if ($old) {
                db()->prepare('UPDATE fp_members SET name = ?, role = ?, birth_day = ?, birth_month = ?, birth_year = ?, color = ?, emoji = ?, photo = ? WHERE id = ?')->execute(array_merge($data, [$id]));
            } else {
                $data[] = (int) db()->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM fp_members')->fetchColumn();
                db()->prepare('INSERT INTO fp_members (name, role, birth_day, birth_month, birth_year, color, emoji, photo, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($data);
            }
            flash(post('name') . ' opgeslagen');
            redirect('instellingen.php#m' . ($id ?: ''));
        }
        if ($action === 'member_delete') {
            $m = member(post_int('id'));
            if ($m) {
                delete_photo($m['photo']);
                db()->prepare('DELETE FROM fp_members WHERE id = ?')->execute([$m['id']]);
                flash($m['name'] . ' is verwijderd');
            }
            redirect('instellingen.php');
        }
        if ($action === 'account') {
            $email = strtolower(post('email'));
            $pw = (string) ($_POST['password'] ?? '');
            if (post('name') === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Vul een naam en een geldig e-mailadres in.');
            }
            if (strlen($pw) < 8) {
                throw new RuntimeException('Het wachtwoord moet minimaal 8 tekens zijn.');
            }
            $exists = db()->prepare('SELECT 1 FROM fp_users WHERE email = ?');
            $exists->execute([$email]);
            if ($exists->fetchColumn()) {
                throw new RuntimeException('Er is al een account met dit e-mailadres.');
            }
            db()->prepare('INSERT INTO fp_users (name, email, password_hash, member_id) VALUES (?, ?, ?, ?)')
                ->execute([mb_cut(post('name'), 100), $email, password_hash($pw, PASSWORD_DEFAULT), member(post_int('member_id')) ? post_int('member_id') : null]);
            flash('Account voor ' . post('name') . ' aangemaakt. Geef het wachtwoord persoonlijk door.');
            redirect('instellingen.php#accounts');
        }
        if ($action === 'account_member') {
            db()->prepare('UPDATE fp_users SET member_id = ? WHERE id = ?')->execute([member(post_int('member_id')) ? post_int('member_id') : null, post_int('id')]);
            flash('Opgeslagen');
            redirect('instellingen.php#accounts');
        }
        if ($action === 'account_delete') {
            if (post_int('id') === (int) $user['id']) {
                throw new RuntimeException('Je kunt je eigen account niet verwijderen.');
            }
            db()->prepare('DELETE FROM fp_users WHERE id = ?')->execute([post_int('id')]);
            flash('Account verwijderd');
            redirect('instellingen.php#accounts');
        }
        if ($action === 'password') {
            $stmt = db()->prepare('SELECT password_hash FROM fp_users WHERE id = ?');
            $stmt->execute([$user['id']]);
            if (!password_verify((string) ($_POST['current'] ?? ''), (string) $stmt->fetchColumn())) {
                throw new RuntimeException('Je huidige wachtwoord klopt niet.');
            }
            $pw = (string) ($_POST['new'] ?? '');
            if (strlen($pw) < 8) {
                throw new RuntimeException('Het nieuwe wachtwoord moet minimaal 8 tekens zijn.');
            }
            db()->prepare('UPDATE fp_users SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $user['id']]);
            flash('Wachtwoord gewijzigd');
            redirect('instellingen.php#wachtwoord');
        }
        if ($action === 'ics') {
            db()->prepare('UPDATE fp_users SET ics_token = ? WHERE id = ?')->execute([post('reset') === 'off' ? null : bin2hex(random_bytes(16)), $user['id']]);
            flash(post('reset') === 'off' ? 'Agendalink uitgezet' : 'Nieuwe agendalink gemaakt');
            redirect('instellingen.php#telefoon');
        }
        if ($action === 'demo_load') {
            load_demo();
            flash('Voorbeelddata geladen. Kijk rond in de agenda, vriendjes en het smoelenboek!');
            redirect('index.php');
        }
        if ($action === 'demo_remove') {
            remove_demo();
            flash('Alle voorbeelddata is verwijderd. Jullie eigen gegevens zijn gebleven.');
            redirect('instellingen.php#voorbeeld');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('instellingen.php');
    }
}

$users = db()->query('SELECT id, name, email, member_id, ics_token FROM fp_users ORDER BY id')->fetchAll();
$me = null;
foreach ($users as $u) {
    if ((int) $u['id'] === (int) $user['id']) {
        $me = $u;
    }
}
$host = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
$icsBase = $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/ics.php?t=' . ($me['ics_token'] ?? '');

function member_form(array $m): string
{
    ob_start(); ?>
    <form method="post" enctype="multipart/form-data" class="card form" id="m<?= e($m['id']) ?>" style="border-top:6px solid <?= e($m['color']) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="member"><input type="hidden" name="id" value="<?= e($m['id']) ?>">
      <div style="display:flex;gap:12px;align-items:center"><?= $m['id'] ? avatar($m, 56) : '<span style="font-size:40px">➕</span>' ?><h2 style="margin:0"><?= $m['id'] ? e($m['name']) : 'Gezinslid toevoegen' ?></h2></div>
      <div class="row2">
        <div><label>Naam</label><input name="name" value="<?= e($m['name']) ?>" required></div>
        <div><label>Rol</label><select name="role"><?= options(['PARENT' => 'Ouder', 'CHILD' => 'Kind'], $m['role']) ?></select></div>
      </div>
      <div class="row2">
        <div><label>Emoji</label><input name="emoji" value="<?= e($m['emoji']) ?>" maxlength="8"></div>
        <div><label>Kleur</label><input type="color" name="color" value="<?= e($m['color']) ?>"></div>
      </div>
      <label>Verjaardag</label><?= birthday_fields($m) ?>
      <?= photo_field($m['photo'] ?? null) ?>
      <div class="form-actions"><button class="btn">Opslaan</button></div>
    </form>
    <?php return (string) ob_get_clean();
}

page_start('Instellingen');
page_header('⚙️ Instellingen');
?>
<div class="section-title" style="margin-top:0"><h2>👨‍👩‍👧‍👦 Gezinsleden</h2></div>
<p class="muted">Vul de verjaardagen in (dan zie je hoe oud iedereen is en komen ze in de agenda) en upload een foto.</p>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr))">
  <?php foreach (members() as $m): ?><?= member_form($m) ?><?php endforeach; ?>
  <?= member_form(['id' => '', 'name' => '', 'role' => 'CHILD', 'emoji' => '🙂', 'color' => '#8E6CDF', 'birth_day' => null, 'birth_month' => null, 'birth_year' => null, 'photo' => null]) ?>
</div>
<details class="card" style="margin-top:12px">
  <summary style="cursor:pointer;font-weight:700">Gezinslid verwijderen</summary>
  <p class="muted small">Afspraken blijven bestaan, maar zijn dan niet meer aan deze persoon gekoppeld.</p>
  <?php foreach (members() as $m): ?>
    <form method="post" class="inline" data-confirm="<?= e($m['name']) ?> verwijderen uit het gezin?"><?= csrf_field() ?><input type="hidden" name="action" value="member_delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn small danger">🗑 <?= e($m['name']) ?></button></form>
  <?php endforeach; ?>
</details>

<div class="cols even" style="margin-top:18px">
  <div class="card" id="accounts">
    <h2>🔑 Inlogaccounts</h2>
    <ul class="list">
      <?php foreach ($users as $u): ?>
        <li><?= member($u['member_id'] ? (int) $u['member_id'] : null) ? avatar(member((int) $u['member_id']), 34) : '👤' ?>
          <div class="grow"><span class="title"><?= e($u['name']) ?><?= (int) $u['id'] === (int) $user['id'] ? ' (jij)' : '' ?></span><span class="meta"><?= e($u['email']) ?></span></div>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="account_member"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <select name="member_id" onchange="this.form.submit()" style="width:auto;min-height:34px;padding:4px 8px" aria-label="Is gezinslid"><?= options(member_options(), $u['member_id'], true, 'geen gezinslid') ?></select></form>
          <?php if ((int) $u['id'] !== (int) $user['id']): ?>
            <form method="post" class="inline" data-confirm="Account van <?= e($u['name']) ?> verwijderen?"><?= csrf_field() ?><input type="hidden" name="action" value="account_delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><button class="link danger">✕</button></form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <details class="more" <?= count($users) < 2 ? 'open' : '' ?>><summary>Account toevoegen (bijv. voor Rene)</summary>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="account">
        <div class="row2"><div><label>Naam</label><input name="name" required></div><div><label>Is gezinslid</label><select name="member_id"><?= options(member_options(), '', true) ?></select></div></div>
        <label>E-mailadres</label><input name="email" type="email" required autocomplete="off">
        <label>Wachtwoord <small>(minimaal 8 tekens)</small></label><input name="password" type="password" minlength="8" required autocomplete="new-password">
        <button class="btn" style="margin-top:12px">Account aanmaken</button>
      </form>
    </details>
  </div>

  <div class="stack">
    <form method="post" class="card form" id="wachtwoord">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <h2>🔒 Mijn wachtwoord</h2>
      <label>Huidig wachtwoord</label><input name="current" type="password" required autocomplete="current-password">
      <label>Nieuw wachtwoord</label><input name="new" type="password" minlength="8" required autocomplete="new-password">
      <button class="btn" style="margin-top:12px">Wijzigen</button>
    </form>

    <div class="card" id="telefoon">
      <h2>📱 Agenda op je telefoon</h2>
      <p class="muted small">Met deze geheime link zie je de familieplanner in de agenda-app van je telefoon (Google Agenda, Apple Agenda of Outlook). Wijzigingen verschijnen daar binnen een paar uur. Deel de link niet met anderen.</p>
      <?php if ($me && $me['ics_token']): ?>
        <label class="small"><b>Hele gezin</b></label>
        <input readonly value="<?= e($icsBase) ?>" onclick="this.select()">
        <p class="small" style="margin-top:8px"><a href="<?= e(preg_replace('~^https?://~', 'webcal://', $icsBase)) ?>">📲 Direct toevoegen op iPhone</a> · <a target="_blank" rel="noopener" href="https://calendar.google.com/calendar/r?cid=<?= e(urlencode(preg_replace('~^https?://~', 'webcal://', $icsBase))) ?>">Toevoegen aan Google Agenda</a></p>
        <details><summary class="small" style="cursor:pointer">Link per persoon</summary>
          <?php foreach (members() as $m): ?><label class="small"><?= e($m['emoji'] . ' ' . $m['name']) ?></label><input readonly value="<?= e($icsBase . '&m=' . $m['id']) ?>" onclick="this.select()" style="margin-bottom:6px"><?php endforeach; ?>
        </details>
        <form method="post" style="margin-top:10px;display:flex;gap:8px"><?= csrf_field() ?><input type="hidden" name="action" value="ics">
          <button class="btn small secondary">🔄 Nieuwe link (oude werkt niet meer)</button><button class="btn small danger" name="reset" value="off">Uitzetten</button></form>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ics"><button class="btn">Link maken</button></form>
      <?php endif; ?>
    </div>

    <div class="card" id="voorbeeld">
      <h2>🧪 Voorbeelddata</h2>
      <?php if (demo_loaded()): ?>
        <p class="muted small">Er staan voorbeeldvriendjes, klassen, afspraken en taken in de app, zodat je kunt zien hoe alles werkt. Klaar met rondkijken? Haal ze met één klik weg. Wat jullie zelf hebben toegevoegd blijft staan.</p>
        <form method="post" data-confirm="Alle voorbeeldmensen, -klassen, -afspraken en -taken worden verwijderd. Jullie eigen gegevens blijven."><?= csrf_field() ?><input type="hidden" name="action" value="demo_remove"><button class="btn danger">🧹 Voorbeelddata verwijderen</button></form>
      <?php else: ?>
        <p class="muted small">Wil je zien hoe de app eruitziet als hij vol staat? Laad voorbeeldvriendjes, klassen, speelafspraken en taken. Je kunt ze later met één klik weer verwijderen.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="demo_load"><button class="btn secondary">Voorbeelddata laden</button></form>
      <?php endif; ?>
    </div>
    <p class="small muted"><a href="dbtest.php">🛠 Databasestatus</a></p>
  </div>
</div>
<?php page_end();
