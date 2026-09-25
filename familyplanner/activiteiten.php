<?php
// Uitjes & feestjes: events per group (outings & holidays, parties & drinks, sport, parents out, appointments)
// with countdowns, guest answers and checklist progress. Sport shows the weekly schedule.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require_login();

$group = isset(EVENT_GROUPS[$_GET['g'] ?? '']) ? $_GET['g'] : 'uitjes';
[$groupLabel, $types] = EVENT_GROUPS[$group];
$showPast = !empty($_GET['past']);
$events = $showPast
    ? array_reverse(load_events(date('Y-m-d', strtotime('-1 year')), today(), ['types' => $types]))
    : load_events(today(), date('Y-m-d', strtotime('+1 year')), ['types' => $types]);
if ($group === 'ouders') {
    $parentIds = array_map('intval', array_keys(parents()));
    $events = array_values(array_filter($events, function ($ev) use ($parentIds) {
        return (bool) array_intersect($parentIds, $ev['members']);
    }));
}
// Repeating events (sport) only once in the list: the next occurrence
$seen = [];
$list = [];
foreach ($events as $ev) {
    if ($ev['recurring']) {
        if (isset($seen[$ev['id']])) {
            continue;
        }
        $seen[$ev['id']] = true;
    }
    $list[] = $ev;
}
$ids = array_unique(array_map('intval', array_column($list, 'id')));
$taskStats = [];
if ($ids) {
    foreach (db()->query('SELECT event_id, COUNT(*) AS n, SUM(done_at IS NOT NULL) AS done FROM fp_tasks WHERE event_id IN (' . implode(',', $ids) . ') GROUP BY event_id') as $r) {
        $taskStats[$r['event_id']] = $r;
    }
}

page_start('Uitjes & feestjes');
page_header('🎡 Uitjes & feestjes', 'Alles wat jullie plannen buiten de gewone week: van dagjes uit tot BBQ’s en vakanties.');
?>
<div class="filters">
  <div class="tabs">
    <?php foreach (EVENT_GROUPS as $k => [$label]): ?><a href="?g=<?= $k ?>" class="<?= $k === $group ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
  </div>
  <div class="tabs">
    <a href="?g=<?= $group ?>" class="<?= !$showPast ? 'on' : '' ?>">Komend</a>
    <a href="?g=<?= $group ?>&amp;past=1" class="<?= $showPast ? 'on' : '' ?>">Geweest</a>
  </div>
</div>
<div class="picker" style="margin-bottom:18px">
  <?php foreach (array_unique($types) as $t): [$label, $emoji, $color] = EVENT_TYPES[$t]; ?>
    <button type="button" class="btn small soft" style="--c:<?= e($color) ?>" data-new-event='<?= e(json_encode(['type' => $t, 'members' => in_array($t, ['PARENTS', 'BORREL', 'DINNER'], true) ? array_map('intval', array_keys(parents())) : array_map('intval', array_keys(members())), 'allDay' => $t === 'HOLIDAY'])) ?>'>＋ <?= $emoji ?> <?= e($label) ?></button>
  <?php endforeach; ?>
  <?php if ($group === 'uitjes'): ?><a class="btn small secondary" href="ideeen.php?cat=OUTING">💡 Ideeën voor uitjes</a><?php endif; ?>
  <?php if ($group === 'feestjes' || $group === 'ouders'): ?><a class="btn small secondary" href="ideeen.php?cat=DINNER">💡 Ideeën</a><?php endif; ?>
</div>

<?php if ($group === 'sport' && !$showPast):
    // Weekly schedule of repeating sport/activities
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime(today())));
    $week = load_events($weekStart, date('Y-m-d', strtotime("$weekStart +7 day")), ['types' => ['SPORT', 'ACTIVITY']]); ?>
  <div class="card" style="margin-bottom:18px">
    <h2>🗓 Vaste weekplanning</h2>
    <div class="table-wrap"><table class="data">
      <tr><th>Dag</th><th>Tijd</th><th>Wat</th><th>Wie</th><th>🚗 Brengen</th><th>🏠 Halen</th></tr>
      <?php foreach ($week as $ev): if (!$ev['recurring']) continue; ?>
        <tr>
          <td style="text-transform:capitalize"><?= e(DAYS[(int) date('w', strtotime($ev['start_at']))]) ?></td>
          <td class="nowrap"><?= e(substr($ev['start_at'], 11, 5) . '–' . substr($ev['end_at'], 11, 5)) ?></td>
          <td><a href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>"><?= event_type($ev['type'])[1] ?> <?= e($ev['title']) ?></a><?= $ev['location'] ? '<br><span class="muted small">' . e($ev['location']) . '</span>' : '' ?></td>
          <td><?php foreach ($ev['members'] as $mid): if ($m = member($mid)): ?><?= member_chip($m, true) ?> <?php endif; endforeach; ?></td>
          <td><?= e(member((int) $ev['drop_member_id'])['name'] ?? '—') ?></td>
          <td><?= e(member((int) $ev['pickup_member_id'])['name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
    <p class="hint">Vaste lessen en trainingen maak je met “Herhalen: elke week” in de agenda.</p>
  </div>
<?php endif; ?>

<?php if (!$list): ?>
  <div class="card"><?= empty_state(EVENT_TYPES[$types[0]][1], $showPast ? 'Nog niets geweest in deze categorie.' : 'Nog niets gepland. Klik hierboven op een knop om iets te plannen.') ?></div>
<?php else: ?>
  <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr))">
    <?php foreach ($list as $ev):
        [$label, $emoji, $color] = event_type($ev['type']);
        $days = days_until($ev['start_at']);
        $ts = $taskStats[$ev['id']] ?? null;
        $rsvp = array_count_values(array_column($ev['contacts'], 'rsvp')); ?>
      <a class="card" href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>" style="color:inherit;border-top:6px solid <?= e(event_color($ev)) ?>;text-decoration:none;display:block">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
          <div style="font-size:34px;line-height:1"><?= $emoji ?></div>
          <?php if (!$showPast): ?><span class="badge <?= $days <= 7 ? 'accent' : '' ?>"><?= $days === 0 ? 'vandaag' : ($ev['type'] === 'HOLIDAY' ? 'nog ' . $days . ' nachtjes' : e(in_days_label($days))) ?></span><?php endif; ?>
        </div>
        <h2 style="margin:10px 0 4px<?= $ev['done'] ? ';text-decoration:line-through' : '' ?>"><?= e($ev['title']) ?></h2>
        <p class="muted small" style="margin:0"><?= e(ucfirst(format_date($ev['start_at']))) ?> · <?= e(format_time_range($ev)) ?><?= $ev['recurring'] ? ' · 🔁 ' . e(mb_strtolower(RECURRENCES[$ev['recurrence']])) : '' ?></p>
        <?php if ($ev['location']): ?><p class="muted small" style="margin:4px 0 0">📍 <?= e($ev['location']) ?></p><?php endif; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:8px;flex-wrap:wrap">
          <span class="avatars"><?php foreach ($ev['members'] as $mid): if ($m = member($mid)): ?><?= avatar($m, 28) ?><?php endif; endforeach; ?><?php foreach (array_slice($ev['contacts'], 0, 6) as $c): ?><?= avatar($c, 28) ?><?php endforeach; ?></span>
          <span class="picker" style="gap:4px">
            <?php if ($ev['contacts']): ?><span class="badge">✅ <?= $rsvp['YES'] ?? 0 ?>/<?= count($ev['contacts']) ?></span><?php endif; ?>
            <?php if ($ts): ?><span class="badge <?= (int) $ts['done'] === (int) $ts['n'] ? 'ok' : '' ?>">📝 <?= (int) $ts['done'] ?>/<?= (int) $ts['n'] ?></span><?php endif; ?>
            <?php if ($ev['cost'] !== null): ?><span class="badge <?= $ev['paid'] ? 'ok' : 'warn' ?>">💶 <?= e(money((float) $ev['cost'])) ?></span><?php endif; ?>
          </span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_end();
