<?php
// A gezin (Familie de Vries): parents and children at one address, with a family photo. Can be linked to the
// friend family that uses the planner itself. (Stored in fp_households; the file keeps its old name for links.)
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
        redirect('mensen.php?view=gezinnen');
    }
}
$friends = friend_families();
$error = null;
if (is_post()) {
    $action = post('action');
    if ($action === 'delete' && $h) {
        db()->prepare('DELETE FROM fp_households WHERE id = ?')->execute([$id]);
        flash($h['name'] . ' is verwijderd (de mensen zelf staan nog in het adresboek)');
        redirect('mensen.php?view=gezinnen');
    }
    if ($action === 'add_person' && $h) {
        db()->prepare('UPDATE fp_contacts SET household_id = ? WHERE id = ?')->execute([$id, post_int('contact_id')]);
        redirect('huishouden.php?id=' . $id);
    }
    if ($action === 'remove_person' && $h) {
        db()->prepare('UPDATE fp_contacts SET household_id = NULL WHERE id = ? AND household_id = ?')->execute([post_int('contact_id'), $id]);
        redirect('huishouden.php?id=' . $id);
    }
    if (post('name') === '') {
        $error = 'Vul een naam in, bijvoorbeeld “Familie de Vries”.';
    } else {
        try {
            $photo = posted_photo($h['photo'] ?? null);
        } catch (RuntimeException $e) {
            $photo = $h['photo'] ?? null;
            flash($e->getMessage(), 'error');
        }
        $linked = isset($friends[post_int('linked_family')]) ? post_int('linked_family') : null;
        $data = [mb_cut(post('name'), 120), post_or_null('street'), post_or_null('postal_code'), post_or_null('city'), post_or_null('country'), post_or_null('phone'), post_or_null('email'), post_or_null('notes'), $photo, $linked];
        if ($h) {
            db()->prepare('UPDATE fp_households SET name = ?, street = ?, postal_code = ?, city = ?, country = ?, phone = ?, email = ?, notes = ?, photo = ?, linked_family = ? WHERE id = ?')->execute(array_merge($data, [$id]));
        } else {
            db()->prepare('INSERT INTO fp_households (name, street, postal_code, city, country, phone, email, notes, photo, linked_family) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($data);
            $id = (int) db()->lastInsertId();
        }
        flash('Opgeslagen');
        redirect('huishouden.php?id=' . $id);
    }
}
$f = $h ?: ['name' => '', 'street' => '', 'postal_code' => '', 'city' => '', 'country' => '', 'phone' => '', 'email' => '', 'notes' => '', 'photo' => null, 'linked_family' => null];
if ($error) {
    $f = array_merge($f, array_intersect_key($_POST, $f));
}
$people = [];
$others = [];
if ($h) {
    $stmt = db()->prepare('SELECT * FROM fp_contacts WHERE household_id = ? ORDER BY is_child, first_name');
    $stmt->execute([$id]);
    $people = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT c.id, c.first_name, c.last_name, c.nickname, h.name AS household_name FROM fp_contacts c
        LEFT JOIN fp_households h ON h.id = c.household_id WHERE c.household_id IS NULL OR c.household_id <> ? ORDER BY c.household_id IS NOT NULL, c.first_name');
    $stmt->execute([$id]);
    $others = $stmt->fetchAll();
}
$address = trim(implode(', ', array_filter([$f['street'], trim($f['postal_code'] . ' ' . $f['city'])])));
$linkedFam = $f['linked_family'] ? ($friends[(int) $f['linked_family']] ?? null) : null;
$photo = $h ? gezin_photo($h) : null;

page_start($h ? $h['name'] : 'Nieuw gezin', ['active' => 'mensen.php', 'narrow' => true]);
?>
<p><a href="mensen.php?view=gezinnen">← Gezinnen</a></p>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<?php if ($h): ?>
<div class="card gezin-head">
  <?php if ($photo): ?><img class="gezin-photo" src="foto.php?f=<?= e($photo) ?>" alt="Gezinsfoto van <?= e($h['name']) ?>"><?php else: ?><div class="gezin-photo gezin-nophoto">🏠</div><?php endif; ?>
  <div class="grow">
    <h1><?= e($h['name']) ?></h1>
    <?php if ($address !== ''): ?><p><a href="https://maps.google.com/?q=<?= e(urlencode($address)) ?>" target="_blank" rel="noopener">📍 <?= e($address) ?></a></p><?php endif; ?>
    <?php if ($h['phone']): ?><p>📞 <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $h['phone'])) ?>"><?= e($h['phone']) ?></a></p><?php endif; ?>
    <?php if ($linkedFam): ?><p><a class="badge ok" href="vriendgezinnen.php">🤝 Doet mee met de Familie Planner als <?= e($linkedFam['name']) ?></a></p><?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:14px">
  <div class="section-title" style="margin-top:0"><h2>👨‍👩‍👧 Wie horen erbij</h2>
    <span><a class="btn small soft" href="contact.php?new=1&amp;child=1&amp;household=<?= $id ?>">＋ Kind</a> <a class="btn small soft" href="contact.php?new=1&amp;relation=PARENT&amp;household=<?= $id ?>">＋ Ouder</a></span></div>
  <div class="faces">
    <?php foreach ($people as $p): ?>
      <div class="face"><a href="contact.php?id=<?= (int) $p['id'] ?>" style="color:inherit;display:contents"><?= avatar($p, 72) ?><b><?= e(contact_name($p, false)) ?></b></a><small><?= $p['is_child'] ? 'Kind' : 'Ouder / volwassene' ?></small>
        <form method="post" class="inline" data-confirm="<?= e(contact_name($p, false)) ?> uit dit gezin halen? (blijft in het adresboek)"><?= csrf_field() ?><input type="hidden" name="action" value="remove_person"><input type="hidden" name="contact_id" value="<?= (int) $p['id'] ?>"><button class="link muted small" title="Uit dit gezin halen">✕</button></form></div>
    <?php endforeach; ?>
  </div>
  <?php if (!$people): ?><p class="muted">Nog niemand. Voeg een kind of ouder toe, of kies iemand uit het adresboek.</p><?php endif; ?>
  <?php if ($others): ?>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="add_person">
      <label style="margin:0">👤 Uit het adresboek:</label>
      <select name="contact_id" required style="flex:1;min-width:200px"><option value="">kies iemand…</option>
        <?php foreach ($others as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e(contact_name($o)) ?><?= $o['household_name'] ? ' (nu bij ' . e($o['household_name']) . ')' : '' ?></option><?php endforeach; ?>
      </select>
      <button class="btn small">＋ Toevoegen</button>
    </form>
  <?php endif; ?>
</div>
<?php else: ?>
  <?php page_header('🏠 Nieuw gezin', 'Een gezin: ouders en kinderen op één adres. Mensen voeg je daarna toe.'); ?>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card form" style="margin-top:14px">
  <?= csrf_field() ?>
  <label for="name">Naam</label><input id="name" name="name" value="<?= e($f['name']) ?>" placeholder="Familie de Vries" required>
  <?= photo_field($f['photo'] ?? null, 'Gezinsfoto', 'Gezinsfoto') ?>
  <?php if ($linkedFam && !$f['photo']): ?><p class="hint">Nu wordt de gezinsfoto van <?= e($linkedFam['name']) ?> gebruikt.</p><?php endif; ?>
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
  <?php if ($friends): ?>
    <label>🤝 Doet dit gezin mee met de Familie Planner?</label>
    <select name="linked_family"><option value="">Nee / weet ik niet</option>
      <?php foreach ($friends as $fid => $fam): ?><option value="<?= (int) $fid ?>"<?= (int) $f['linked_family'] === (int) $fid ? ' selected' : '' ?>>Ja, als vriendgezin <?= e($fam['name']) ?></option><?php endforeach; ?>
    </select>
  <?php endif; ?>
  <label>Notities</label><textarea name="notes" rows="3" placeholder="Bijv. hond Max, parkeren om de hoek, bel werkt niet"><?= e($f['notes']) ?></textarea>
  <div class="form-actions"><button class="btn">Opslaan</button></div>
</form>
<?php if ($h): ?>
  <form method="post" data-confirm="Het gezin wordt verwijderd. De mensen blijven in het adresboek staan." style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger">🗑 Gezin verwijderen</button></form>
<?php endif; ?>
<?php page_end();
