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
        $data = [mb_cut(post('name'), 120), post_or_null('email'), post_or_null('notes'), $photo, $linked];
        if ($h) {
            db()->prepare('UPDATE fp_households SET name = ?, email = ?, notes = ?, photo = ?, linked_family = ? WHERE id = ?')->execute(array_merge($data, [$id]));
        } else {
            db()->prepare('INSERT INTO fp_households (name, email, notes, photo, linked_family) VALUES (?, ?, ?, ?, ?)')->execute($data);
            $id = (int) db()->lastInsertId();
        }
        save_gezin_contact($id, $_POST); // all addresses and phone numbers
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
$addresses = $h ? gezin_addresses($h) : [];
$phones = $h ? gezin_phones($h) : [];
if ($error) {
    $addresses = [];
    foreach ((array) ($_POST['addr_street'] ?? []) as $i => $street) {
        $addresses[] = ['label' => $_POST['addr_label'][$i] ?? '', 'street' => $street, 'postal_code' => $_POST['addr_postal'][$i] ?? '', 'city' => $_POST['addr_city'][$i] ?? '', 'country' => $_POST['addr_country'][$i] ?? ''];
    }
    $phones = [];
    foreach ((array) ($_POST['phone_number'] ?? []) as $i => $number) {
        $phones[] = ['label' => $_POST['phone_label'][$i] ?? '', 'phone' => $number];
    }
}
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
    <?php foreach ($addresses as $a): $line = address_line($a); ?>
      <p><?= $a['label'] ? '<b>' . e($a['label']) . ':</b> ' : '' ?><a href="https://maps.google.com/?q=<?= e(urlencode($line)) ?>" target="_blank" rel="noopener">📍 <?= e($line) ?></a></p>
    <?php endforeach; ?>
    <?php if ($phones): ?><p><?php foreach ($phones as $i => $ph): ?><?= $i ? ' · ' : '' ?><span class="nowrap">📞 <?= $ph['label'] ? e($ph['label']) . ': ' : '' ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $ph['phone'])) ?>"><?= e($ph['phone']) ?></a></span><?php endforeach; ?></p><?php endif; ?>
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
  <fieldset class="multi" data-multi="addr">
    <legend>📍 Adressen</legend>
    <p class="hint">Woont het gezin op meer plekken, bijvoorbeeld bij mama en bij papa? Voeg dan een adres toe. Het eerste adres is het hoofdadres.</p>
    <?php $rows = $addresses ?: [[]]; $rows[] = 'template'; foreach ($rows as $a): $tpl = $a === 'template'; $a = $tpl ? [] : $a; $dis = $tpl ? ' disabled' : ''; ?>
      <div class="multi-row"<?= $tpl ? ' data-template hidden' : '' ?>>
        <div class="row2">
          <div><label>Naam van dit adres <small>(optioneel)</small></label><input name="addr_label[]" value="<?= e($a['label'] ?? '') ?>" placeholder="bijv. Bij mama, Vakantiehuis"<?= $dis ?>></div>
          <div><label>Straat en huisnummer</label><input name="addr_street[]" value="<?= e($a['street'] ?? '') ?>"<?= $dis ?>></div>
        </div>
        <div class="row2">
          <div><label>Postcode</label><input name="addr_postal[]" value="<?= e($a['postal_code'] ?? '') ?>"<?= $dis ?>></div>
          <div><label>Plaats</label><input name="addr_city[]" value="<?= e($a['city'] ?? '') ?>"<?= $dis ?>></div>
        </div>
        <label>Land <small>(als het niet Nederland is)</small></label><input name="addr_country[]" value="<?= e($a['country'] ?? '') ?>"<?= $dis ?>>
        <button type="button" class="link muted small" data-remove>✕ Dit adres weghalen</button>
      </div>
    <?php endforeach; ?>
    <button type="button" class="btn small soft" data-add>＋ Nog een adres</button>
  </fieldset>
  <fieldset class="multi" data-multi="phone">
    <legend>📞 Telefoonnummers</legend>
    <?php $rows = $phones ?: [[]]; $rows[] = 'template'; foreach ($rows as $ph): $tpl = $ph === 'template'; $ph = $tpl ? [] : $ph; $dis = $tpl ? ' disabled' : ''; ?>
      <div class="multi-row row2"<?= $tpl ? ' data-template hidden' : '' ?>>
        <div><input name="phone_label[]" value="<?= e($ph['label'] ?? '') ?>" placeholder="Van wie? bijv. Mama, Papa, Thuis"<?= $dis ?>></div>
        <div style="display:flex;gap:6px;align-items:center"><input name="phone_number[]" type="tel" value="<?= e($ph['phone'] ?? '') ?>" placeholder="06 12345678"<?= $dis ?>><button type="button" class="link muted small" data-remove title="Weghalen">✕</button></div>
      </div>
    <?php endforeach; ?>
    <button type="button" class="btn small soft" data-add>＋ Nog een nummer</button>
  </fieldset>
  <label>E-mail</label><input name="email" type="email" value="<?= e($f['email']) ?>">
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
<script>
// "＋ Nog een adres / nummer": copy the hidden template row; ✕ removes a row (ES5 for old TVs)
(function () {
  var sets = document.querySelectorAll('[data-multi]');
  for (var i = 0; i < sets.length; i++) {
    (function (set) {
      var template = set.querySelector('[data-template]');
      set.querySelector('[data-add]').onclick = function () {
        var row = template.cloneNode(true);
        row.removeAttribute('data-template');
        row.hidden = false;
        var inputs = row.querySelectorAll('input');
        for (var j = 0; j < inputs.length; j++) { inputs[j].disabled = false; inputs[j].value = ''; }
        set.insertBefore(row, template);
        if (inputs[0]) inputs[0].focus();
      };
      set.addEventListener('click', function (e) {
        var el = e.target;
        while (el && el !== set && !(el.hasAttribute && el.hasAttribute('data-remove'))) el = el.parentNode;
        if (!el || el === set) return;
        var row = el.parentNode;
        while (row && row !== set && (' ' + row.className + ' ').indexOf(' multi-row ') < 0) row = row.parentNode;
        if (row && row !== set) row.parentNode.removeChild(row);
      });
    })(sets[i]);
  }
})();
</script>
<?php page_end();
