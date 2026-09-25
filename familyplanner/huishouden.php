<?php
// A household (Familie de Vries): one address for parents and children together.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/upload.php';
require_login();

$id = get_int('id');
$h = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM fp_households WHERE id = ?');
    $stmt->execute([$id]);
    $h = $stmt->fetch();
    if (!$h) {
        redirect('mensen.php?view=adressen');
    }
}
$error = null;
if (is_post()) {
    if (post('action') === 'delete' && $h) {
        db()->prepare('DELETE FROM fp_households WHERE id = ?')->execute([$id]);
        flash($h['name'] . ' is verwijderd (de mensen zelf staan nog in het adresboek)');
        redirect('mensen.php?view=adressen');
    }
    if (post('name') === '') {
        $error = 'Vul een naam in, bijvoorbeeld “Familie de Vries”.';
    } else {
        $data = [mb_cut(post('name'), 120), post_or_null('street'), post_or_null('postal_code'), post_or_null('city'), post_or_null('country'), post_or_null('phone'), post_or_null('email'), post_or_null('notes')];
        if ($h) {
            db()->prepare('UPDATE fp_households SET name = ?, street = ?, postal_code = ?, city = ?, country = ?, phone = ?, email = ?, notes = ? WHERE id = ?')->execute(array_merge($data, [$id]));
        } else {
            db()->prepare('INSERT INTO fp_households (name, street, postal_code, city, country, phone, email, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($data);
            $id = (int) db()->lastInsertId();
        }
        flash('Opgeslagen');
        redirect('huishouden.php?id=' . $id);
    }
}
$f = $h ?: ['name' => '', 'street' => '', 'postal_code' => '', 'city' => '', 'country' => '', 'phone' => '', 'email' => '', 'notes' => ''];
if ($error) {
    $f = array_merge($f, array_intersect_key($_POST, $f));
}
$people = [];
if ($h) {
    $stmt = db()->prepare('SELECT * FROM fp_contacts WHERE household_id = ? ORDER BY is_child, first_name');
    $stmt->execute([$id]);
    $people = $stmt->fetchAll();
}
$address = trim(implode(', ', array_filter([$f['street'], trim($f['postal_code'] . ' ' . $f['city'])])));

page_start($h ? $h['name'] : 'Nieuw huishouden', ['active' => 'mensen.php', 'narrow' => true]);
?>
<p><a href="mensen.php?view=adressen">← Adressen</a></p>
<?php page_header('🏠 ' . e($h ? $h['name'] : 'Nieuw huishouden'), $address !== '' ? '<a href="https://maps.google.com/?q=' . e(urlencode($address)) . '" target="_blank" rel="noopener">📍 ' . e($address) . '</a>' : ''); ?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<?php if ($h): ?>
<div class="card">
  <div class="section-title" style="margin-top:0"><h2>👨‍👩‍👧 Wonen hier</h2>
    <span><a class="btn small soft" href="contact.php?new=1&amp;child=1&amp;household=<?= $id ?>">＋ Kind</a> <a class="btn small soft" href="contact.php?new=1&amp;relation=PARENT&amp;household=<?= $id ?>">＋ Ouder</a></span></div>
  <div class="faces">
    <?php foreach ($people as $p): ?>
      <a class="face" href="contact.php?id=<?= (int) $p['id'] ?>"><?= avatar($p, 64) ?><b><?= e(contact_name($p, false)) ?></b><small><?= e(RELATIONS[$p['relation']][0] ?? '') ?></small></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$people): ?><p class="muted">Nog niemand gekoppeld.</p><?php endif; ?>
</div>
<?php endif; ?>

<form method="post" class="card form" style="margin-top:14px">
  <?= csrf_field() ?>
  <label for="name">Naam</label><input id="name" name="name" value="<?= e($f['name']) ?>" placeholder="Familie de Vries" required>
  <label>Straat en huisnummer</label><input name="street" value="<?= e($f['street']) ?>">
  <div class="row2">
    <div><label>Postcode</label><input name="postal_code" value="<?= e($f['postal_code']) ?>"></div>
    <div><label>Plaats</label><input name="city" value="<?= e($f['city']) ?>"></div>
  </div>
  <label>Land <small>(als het niet Nederland is)</small></label><input name="country" value="<?= e($f['country']) ?>">
  <div class="row2">
    <div><label>Telefoon (thuis)</label><input name="phone" value="<?= e($f['phone']) ?>"></div>
    <div><label>E-mail</label><input name="email" type="email" value="<?= e($f['email']) ?>"></div>
  </div>
  <label>Notities</label><textarea name="notes" rows="3" placeholder="Bijv. hond Max, parkeren om de hoek, bel werkt niet"><?= e($f['notes']) ?></textarea>
  <div class="form-actions"><button class="btn">Opslaan</button></div>
</form>
<?php if ($h): ?>
  <form method="post" data-confirm="Het huishouden wordt verwijderd. De mensen blijven in het adresboek staan." style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger">🗑 Huishouden verwijderen</button></form>
<?php endif; ?>
<?php page_end();
