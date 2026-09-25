<?php
// The calendar. Everything happens in calendar.js; this page only provides the frame.
require __DIR__ . '/lib/app.php';
require_login();

page_start('Agenda', ['wide' => true, 'bodyClass' => 'cal-page']);
?>
<div id="calendar"></div>
<p class="muted small no-print" style="margin-top:10px">Tip: sleep in een lege plek om iets te plannen, sleep een afspraak om hem te verplaatsen en trek aan de onderkant om hem langer of korter te maken. Op de telefoon: eerst even vasthouden, dan slepen. Toetsen: ← → bladeren, t vandaag, d/w/m/j dag/week/maand/jaar, n nieuw.</p>
<?php page_end(['calendar.js']);
