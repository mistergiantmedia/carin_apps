<?php
// Relations web: who is connected to whom through family, school classes, sports teams, work,
// households and "friend of". Drawn by network.js. ?member= / ?contact= / ?group= focus on someone.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/upload.php';
require_login();

// Quick add: just a name, child or adult, and a photo. Connections are made by dragging afterwards.
if (is_post() && post('action') === 'quick_person') {
    if (post('first_name') === '') {
        flash('Vul een voornaam in.', 'error');
        redirect('netwerk.php');
    }
    $isChild = post('kind') === 'child';
    try {
        $photo = save_photo('photo');
    } catch (RuntimeException $e) {
        $photo = null;
        flash($e->getMessage(), 'error');
    }
    db()->prepare('INSERT INTO fp_contacts (first_name, last_name, is_child, relation, photo) VALUES (?, ?, ?, ?, ?)')
        ->execute([mb_cut(post('first_name'), 80), post('last_name') !== '' ? mb_cut(post('last_name'), 80) : null, $isChild ? 1 : 0, $isChild ? 'FRIEND' : 'OWN_FRIEND', $photo]);
    $cid = (int) db()->lastInsertId();
    flash('✓ ' . post('first_name') . ' toegevoegd. Sleep ' . ($isChild ? 'het kind' : 'deze persoon') . ' nu op een groep, huishouden of gezinslid om te verbinden.');
    redirect('netwerk.php?new=' . $cid);
}

$focus = '';
$new = get_int('new') ? 'c' . get_int('new') : '';
foreach (['member' => 'm', 'contact' => 'c', 'group' => 'g'] as $param => $prefix) {
    if (get_int($param)) {
        $focus = $prefix . get_int($param);
    }
}

page_start('Netwerk', ['wide' => true, 'bodyClass' => 'net-page']);
?>
<div class="net-toolbar">
  <h1>🕸️ Netwerk</h1>
  <input type="search" id="net-search" placeholder="🔍 Zoek iemand…" autocomplete="off">
  <div class="picker" id="net-filters"></div>
  <span class="spacer"></span>
  <button class="btn secondary small" id="net-fit" title="Alles in beeld">⤢ Alles</button>
  <button type="button" class="btn small" onclick="document.getElementById('quick-person').showModal()">＋ Persoon</button>
  <a class="btn small secondary" href="groepen.php?new=1">＋ Groep</a>
</div>
<div class="net-wrap">
  <svg id="net" data-focus="<?= e($focus) ?>" data-new="<?= e($new) ?>" aria-label="Netwerk van relaties"></svg>
  <aside class="net-panel card" id="net-panel" hidden></aside>
  <div class="net-legend small muted">Klik op iemand voor de verbindingen · sleep iemand op een groep, huishouden of gezinslid om ze te verbinden · dubbelklik om te openen · scroll om te zoomen</div>
</div>
<dialog class="modal" id="quick-person">
  <form method="post" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="quick_person">
    <div class="modal-head"><h2>＋ Persoon toevoegen</h2><button type="button" class="x" onclick="this.closest('dialog').close()" aria-label="Sluiten">×</button></div>
    <div class="modal-body">
      <div class="row2">
        <div><label for="qp-first">Voornaam</label><input id="qp-first" name="first_name" required autocomplete="off"></div>
        <div><label for="qp-last">Achternaam</label><input id="qp-last" name="last_name" autocomplete="off"></div>
      </div>
      <div class="picker" style="margin-top:12px">
        <label class="pick"><input type="radio" name="kind" value="child" checked><span>🧒 Kind</span></label>
        <label class="pick"><input type="radio" name="kind" value="adult"><span>🧑 Volwassen</span></label>
      </div>
      <?= photo_field(null, 'Foto (mag ook later)') ?>
    </div>
    <div class="modal-foot"><span class="spacer"></span>
      <button type="button" class="btn secondary" onclick="this.closest('dialog').close()">Annuleren</button>
      <button type="submit" class="btn">Toevoegen</button>
    </div>
  </form>
</dialog>
<?php page_end(['network.js']);
