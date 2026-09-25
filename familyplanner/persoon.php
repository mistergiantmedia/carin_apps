<?php
// One family member's own overview.
// Children: a colourful week board with photos of the friends they play with, their chores and friends.
// Parents: month or year calendar, their own social life, friends' birthdays and their tasks.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/tasks.php';
require __DIR__ . '/lib/friends.php';
require __DIR__ . '/lib/views.php';
require_login();

$m = member(get_int('id'));
if (!$m) {
    redirect('gezin.php');
}
$id = (int) $m['id'];
$isKid = $m['role'] === 'CHILD';
$age = age($m);
$nextBday = next_birthday($m['birth_day'] ? (int) $m['birth_day'] : null, $m['birth_month'] ? (int) $m['birth_month'] : null);
$mine = function (array $ev) use ($id) {
    return in_array($id, $ev['members'], true) || (int) $ev['drop_member_id'] === $id || (int) $ev['pickup_member_id'] === $id;
};

page_start($m['name'], ['active' => 'gezin.php']);
?>
<div class="kid-hero no-print-bg" style="--c:<?= e($m['color']) ?>">
  <?= avatar($m, 96, 'ring') ?>
  <div style="flex:1;min-width:200px">
    <h1><?= e($m['emoji']) ?> <?= e($m['name']) ?></h1>
    <p class="muted" style="margin:4px 0 0">
      <?= $age !== null ? $age . ' jaar' : ($isKid ? 'Kind' : 'Ouder') ?>
      <?php if ($nextBday): $days = days_until($nextBday); ?>
        · 🎂 <?= $days === 0 ? '<b>Vandaag jarig! 🎉</b>' : 'jarig ' . e(in_days_label($days)) . ' (' . e(format_date_short($nextBday)) . ')' ?>
      <?php endif; ?>
    </p>
  </div>
  <div class="head-actions no-print">
    <button type="button" class="btn" data-new-event='<?= e(json_encode(['members' => [$id]] + ($isKid ? ['type' => 'PLAYDATE', 'host' => 'HOME'] : []))) ?>'>＋ <?= $isKid ? 'Speelafspraak' : 'Afspraak' ?></button>
    <a class="btn secondary" href="agenda.php?member=<?= $id ?>">📅 Agenda</a>
    <button class="btn secondary" onclick="window.print()">🖨</button>
    <a class="btn secondary" href="instellingen.php#m<?= $id ?>" title="Naam, foto, kleur en verjaardag">⚙️</a>
  </div>
</div>

<?php if ($isKid):
    $weekOffset = (int) ($_GET['week'] ?? 0);
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime(today())) + $weekOffset * 7 * 86400);
    $weekStart = date('Y-m-d', strtotime($weekStart));
    $tasks = load_tasks(['member' => $id, 'until' => date('Y-m-d', strtotime("$weekStart +6 day"))]);
    $friends = friends_of($id, true);
    $stats = playdate_stats($id);
?>
  <div class="section-title">
    <h2>🗓 <?= $weekOffset === 0 ? 'Mijn week' : ($weekOffset === 1 ? 'Volgende week' : 'Week ' . (int) date('W', strtotime($weekStart))) ?></h2>
    <div class="head-actions no-print">
      <a class="btn secondary small" href="?id=<?= $id ?>&amp;week=<?= $weekOffset - 1 ?>">‹ vorige</a>
      <?php if ($weekOffset): ?><a class="btn secondary small" href="?id=<?= $id ?>">deze week</a><?php endif; ?>
      <a class="btn secondary small" href="?id=<?= $id ?>&amp;week=<?= $weekOffset + 1 ?>">volgende ›</a>
    </div>
  </div>
  <?= kid_week_board($m, $weekStart) ?>

  <div class="cols" style="margin-top:18px">
    <div class="card">
      <h2>⭐ Mijn klusjes</h2>
      <ul class="list"><?php foreach ($tasks as $t): ?><?= task_item($t, 'persoon.php?id=' . $id, false) ?><?php endforeach; ?></ul>
      <?php if (!$tasks): ?><p class="muted">Geen klusjes deze week. 🎉</p><?php endif; ?>
      <form method="post" action="taken.php" class="form no-print" style="display:flex;gap:8px;margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="members[]" value="<?= $id ?>"><input type="hidden" name="next" value="persoon.php?id=<?= $id ?>">
        <input name="title" placeholder="Nieuw klusje, bijv. Kamer opruimen" required><input type="hidden" name="due_date" value="<?= e(today()) ?>">
        <button class="btn">＋</button>
      </form>
    </div>
    <div class="card">
      <div class="section-title" style="margin-top:0"><h2>🧸 Mijn vriendjes</h2><a href="vriendjes.php?kid=<?= $id ?>">bingo & meer</a></div>
      <?php if ($friends): ?>
        <div class="faces" style="grid-template-columns:repeat(auto-fill,minmax(92px,1fr))">
          <?php foreach ($friends as $f): $s = $stats[(int) $f['id']] ?? null; ?>
            <a class="face" href="contact.php?id=<?= (int) $f['id'] ?>"><?= avatar($f, 56) ?><b><?= e(contact_name($f, false)) ?></b>
              <small><?= $s ? ($s['home'] + $s['away'] + $s['other']) . '× gespeeld' : 'nog niet gespeeld' ?></small></a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <?= empty_state('🧸', 'Nog geen vriendjes toegevoegd.', '<a class="btn soft" href="contact.php?new=1&amp;child=1&amp;member=' . $id . '">＋ Vriendje toevoegen</a>') ?>
      <?php endif; ?>
    </div>
  </div>

<?php else:
    $view = in_array($_GET['view'] ?? '', ['month', 'year'], true) ? $_GET['view'] : 'month';
    $ym = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
    $year = (int) substr($ym, 0, 4);
    $month = (int) substr($ym, 5, 2);
    if ($view === 'year') {
        $from = "$year-01-01";
        $to = ($year + 1) . '-01-01';
    } else {
        $from = date('Y-m-d', strtotime('monday this week', strtotime("$ym-01")));
        $to = date('Y-m-d', strtotime('+6 week', strtotime($from)));
    }
    $events = array_values(array_filter(load_events($from, $to), $mine));
    $birthdays = load_birthdays($from, $to, ['members' => [$id]]);
    $prev = date('Y-m', strtotime(($view === 'year' ? '-1 year' : '-1 month'), strtotime("$ym-01")));
    $next = date('Y-m', strtotime(($view === 'year' ? '+1 year' : '+1 month'), strtotime("$ym-01")));
    $social = array_values(array_filter(load_events(today(), date('Y-m-d', strtotime('+90 day')), ['types' => SOCIAL_TYPES]), $mine));
    $pastSocial = array_values(array_filter(load_events(date('Y-m-d', strtotime('-180 day')), today(), ['types' => SOCIAL_TYPES]), $mine));
    $friendBdays = array_values(array_filter(load_birthdays(today(), date('Y-m-d', strtotime('+60 day')), ['members' => [$id]]), function ($b) {
        return $b['kind'] === 'contact' && empty($b['person']['is_child']);
    }));
    $tasks = load_tasks(['member' => $id]);
?>
  <div class="section-title">
    <div class="tabs">
      <a href="?id=<?= $id ?>&amp;view=month&amp;m=<?= e($ym) ?>" class="<?= $view === 'month' ? 'on' : '' ?>">Maand</a>
      <a href="?id=<?= $id ?>&amp;view=year&amp;m=<?= e($ym) ?>" class="<?= $view === 'year' ? 'on' : '' ?>">Jaar</a>
    </div>
    <div class="head-actions">
      <a class="btn secondary small" href="?id=<?= $id ?>&amp;view=<?= $view ?>&amp;m=<?= e($prev) ?>">‹</a>
      <b style="min-width:140px;text-align:center"><?= $view === 'year' ? $year : e(ucfirst(MONTHS[$month])) . ' ' . $year ?></b>
      <a class="btn secondary small" href="?id=<?= $id ?>&amp;view=<?= $view ?>&amp;m=<?= e($next) ?>">›</a>
      <a class="btn secondary small" href="?id=<?= $id ?>&amp;view=<?= $view ?>">Vandaag</a>
    </div>
  </div>
  <div class="cols">
    <div class="card" style="padding:12px"><?= $view === 'year' ? year_grid($year, $events, $birthdays) : month_table($year, $month, $events, $birthdays) ?></div>
    <div class="stack">
      <div class="card">
        <h2>🥂 Mijn sociale leven</h2>
        <?php $last = $pastSocial ? end($pastSocial) : null; ?>
        <p class="muted small"><?= $last ? 'Laatst: ' . e(event_type($last['type'])[1] . ' ' . $last['title']) . ' (' . e(format_date_short($last['start_at'])) . ')' : 'Nog geen avondjes uit in de agenda.' ?></p>
        <ul class="list compact">
          <?php foreach (array_slice($social, 0, 6) as $ev): ?>
            <li><span style="font-size:20px"><?= event_type($ev['type'])[1] ?></span><div class="grow"><a class="title" href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>" style="color:inherit"><?= e($ev['title']) ?></a><span class="meta"><?= e(format_date_short($ev['start_at'])) ?><?= $ev['all_day'] ? '' : ' · ' . e(substr($ev['start_at'], 11, 5)) ?></span></div>
              <span class="avatars"><?php foreach (array_slice($ev['contacts'], 0, 3) as $c): ?><?= avatar($c, 26) ?><?php endforeach; ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!$social): ?><p>Niets gepland.</p><?php endif; ?>
        <div class="s-actions" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
          <button type="button" class="btn small soft" data-new-event='<?= e(json_encode(['type' => 'PARENTS', 'members' => array_map('intval', array_keys(parents())), 'title' => 'Avondje uit'])) ?>'>💑 Date</button>
          <button type="button" class="btn small soft" data-new-event='<?= e(json_encode(['type' => 'BORREL', 'members' => [$id]])) ?>'>🥂 Borrel</button>
          <button type="button" class="btn small soft" data-new-event='<?= e(json_encode(['type' => 'DINNER', 'members' => [$id]])) ?>'>🍽️ Etentje</button>
          <button type="button" class="btn small soft" data-new-event='<?= e(json_encode(['type' => 'BBQ', 'members' => array_map('intval', array_keys(members()))])) ?>'>🍖 BBQ</button>
        </div>
      </div>
      <div class="card">
        <h2>🎂 Verjaardagen van vrienden</h2>
        <ul class="list compact">
          <?php foreach (array_slice($friendBdays, 0, 6) as $b): ?>
            <li><?= avatar($b['person'], 30) ?><div class="grow"><span class="title"><?= e($b['name']) ?></span><span class="meta"><?= e(format_date_short($b['date'])) ?></span></div><span class="badge"><?= e(in_days_label(days_until($b['date']))) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!$friendBdays): ?><p class="muted">Geen verjaardagen de komende twee maanden.</p><?php endif; ?>
      </div>
      <div class="card">
        <div class="section-title" style="margin-top:0"><h2>✅ Mijn taken</h2><a href="taken.php?member=<?= $id ?>">alles</a></div>
        <ul class="list"><?php foreach (array_slice($tasks, 0, 8) as $t): ?><?= task_item($t, 'persoon.php?id=' . $id, false) ?><?php endforeach; ?></ul>
        <?php if (!$tasks): ?><p class="muted">Geen open taken.</p><?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php page_end();
