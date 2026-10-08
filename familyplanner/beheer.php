<?php
// Beheer (Carin and René only): approve or reject new families, see who uses the planner,
// and the Google OAuth client for the Google Agenda koppeling.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/families.php';
$me = require_login();
if (!is_site_admin()) {
    redirect('./');
}

if (is_post() && post('action') === 'google_oauth') {
    // The Google OAuth client used by every family's Google Agenda koppeling (lib/gcal.php)
    $secret = post('client_secret') !== '' ? post('client_secret') : gcal_credentials()['client_secret'];
    gcal_save_credentials(post('client_id'), post('client_id') !== '' ? $secret : '');
    flash(post('client_id') !== '' ? 'Google-gegevens opgeslagen. Iedereen kan nu bij Instellingen Google Agenda koppelen.' : 'Google-koppeling uitgezet.');
    redirect('beheer.php#google');
}

if (is_post()) {
    $fid = post_int('family_id');
    $stmt = db()->prepare('SELECT * FROM fp_families WHERE id = ?');
    $stmt->execute([$fid]);
    $fam = $stmt->fetch();
    try {
        if (!$fam || $fid === 1) {
            throw new RuntimeException('Dit gezin kan hier niet worden aangepast.');
        }
        if (post('action') === 'approve') {
            approve_family($fid, (int) $me['id']);
            flash('✓ ' . $fam['name'] . ' is goedgekeurd en kan nu inloggen.');
        } elseif (post('action') === 'reject') {
            db()->prepare("UPDATE fp_families SET status = 'REJECTED' WHERE id = ?")->execute([$fid]);
            flash($fam['name'] . ' is afgewezen.');
        } elseif (post('action') === 'block') {
            db()->prepare("UPDATE fp_families SET status = 'BLOCKED' WHERE id = ?")->execute([$fid]);
            flash($fam['name'] . ' kan niet meer inloggen (gegevens blijven bewaard).');
        } elseif (post('action') === 'delete') {
            delete_family($fid);
            flash($fam['name'] . ' en al hun gegevens zijn verwijderd.');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('beheer.php');
}

$families = db()->query("SELECT f.*, (SELECT COUNT(*) FROM fp_users u WHERE u.family_id = f.id) AS accounts FROM fp_families f
    ORDER BY FIELD(f.status, 'PENDING', 'ACTIVE', 'BLOCKED', 'REJECTED'), f.created_at DESC")->fetchAll();
$accounts = [];
foreach (db()->query('SELECT family_id, name, last_name, email FROM fp_users ORDER BY id') as $u) {
    $accounts[$u['family_id']][] = $u;
}
$labels = ['PENDING' => ['⏳ Wacht op goedkeuring', 'warn'], 'ACTIVE' => ['✓ Actief', 'ok'], 'REJECTED' => ['Afgewezen', 'bad'], 'BLOCKED' => ['Geblokkeerd', 'bad']];

page_start('Beheer');
page_header('🛡️ Beheer', 'Gezinnen die de Familie Planner gebruiken. Nieuwe gezinnen kunnen pas inloggen als jullie ze goedkeuren.');
?>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr))">
  <?php foreach ($families as $f): [$label, $cls] = $labels[$f['status']] ?? [$f['status'], '']; ?>
    <div class="card"<?= $f['status'] === 'PENDING' ? ' style="border:2px solid var(--warn)"' : '' ?>>
      <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
        <div style="display:flex;gap:10px;align-items:center"><?= family_avatar($f, 48) ?><h2 style="margin:0"><?= e($f['name']) ?></h2></div><span class="badge <?= $cls ?>"><?= e($label) ?></span>
      </div>
      <p class="muted small">Aangemeld op <?= e(format_date(substr($f['created_at'], 0, 10))) ?><?= $f['id'] == 1 ? ' · jullie eigen gezin' : '' ?></p>
      <ul class="list compact">
        <?php foreach ($accounts[$f['id']] ?? [] as $u): ?>
          <li><span>👤</span><div class="grow"><span class="title"><?= e(trim($u['name'] . ' ' . $u['last_name'])) ?></span><span class="meta"><a href="mailto:<?= e($u['email']) ?>"><?= e($u['email']) ?></a></span></div></li>
        <?php endforeach; ?>
      </ul>
      <?php if ((int) $f['id'] !== 1): ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
          <?php if ($f['status'] !== 'ACTIVE'): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="family_id" value="<?= (int) $f['id'] ?>"><button class="btn small ok" name="action" value="approve">✓ Goedkeuren</button></form>
          <?php endif; ?>
          <?php if ($f['status'] === 'PENDING'): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="family_id" value="<?= (int) $f['id'] ?>"><button class="btn small secondary" name="action" value="reject">Afwijzen</button></form>
          <?php elseif ($f['status'] === 'ACTIVE'): ?>
            <form method="post" class="inline" data-confirm="<?= e($f['name']) ?> kan dan niet meer inloggen. Hun gegevens blijven bewaard."><?= csrf_field() ?><input type="hidden" name="family_id" value="<?= (int) $f['id'] ?>"><button class="btn small secondary" name="action" value="block">Blokkeren</button></form>
          <?php endif; ?>
          <form method="post" class="inline" data-confirm="<?= e($f['name']) ?> en AL hun gegevens (agenda, adresboek, foto's) worden definitief verwijderd."><?= csrf_field() ?><input type="hidden" name="family_id" value="<?= (int) $f['id'] ?>"><button class="btn small danger" name="action" value="delete">🗑 Verwijderen</button></form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php $creds = gcal_credentials(); ?>
<form method="post" class="card form" id="google" style="margin-top:18px;max-width:640px">
  <?= csrf_field() ?><input type="hidden" name="action" value="google_oauth">
  <h2>📆 Google Agenda-koppeling</h2>
  <p class="muted small">Hiermee kan iedereen bij Instellingen de planner koppelen aan de eigen Google Agenda. Maak in de Google Cloud Console een OAuth-client (type “Webapplicatie”), zet de Google Calendar API aan en voeg deze doorverwijs-URI toe:</p>
  <input readonly value="<?= e(gcal_redirect_uri()) ?>" onclick="this.select()">
  <label for="client_id">Client-ID</label><input id="client_id" name="client_id" value="<?= e($creds['client_id']) ?>" autocomplete="off">
  <label for="client_secret">Clientgeheim</label><input id="client_secret" name="client_secret" type="password" autocomplete="new-password" placeholder="<?= $creds['client_secret'] !== '' ? '•••••••• (bewaard, leeg laten om te houden)' : '' ?>">
  <p class="hint">Zet het OAuth-toestemmingsscherm op “In productie”: in de testmodus verloopt een koppeling na 7 dagen. Client-ID leegmaken zet de koppeling voor iedereen uit.</p>
  <div class="form-actions"><button class="btn">Opslaan</button></div>
</form>
<p class="muted small" style="margin-top:14px"><a href="dbtest.php">🛠 Databasestatus</a></p>
<?php page_end();
