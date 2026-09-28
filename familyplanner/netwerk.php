<?php
// Relations web: who is connected to whom through family, school classes, sports teams, work,
// households and "friend of". Drawn by network.js. ?member= / ?contact= / ?group= focus on someone.
require __DIR__ . '/lib/app.php';
require_login();

$focus = '';
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
  <a class="btn small" href="groepen.php?new=1">＋ Groep</a>
</div>
<div class="net-wrap">
  <svg id="net" data-focus="<?= e($focus) ?>" aria-label="Netwerk van relaties"></svg>
  <aside class="net-panel card" id="net-panel" hidden></aside>
  <div class="net-legend small muted">Klik op iemand om de verbindingen te zien · dubbelklik om te openen · sleep om te verplaatsen · scroll om te zoomen</div>
</div>
<?php page_end(['network.js']);
