<?php
// Vandaag: the family dashboard. Today and tomorrow, each family member at a glance,
// tasks, birthdays, "attent zijn" suggestions and where the friend books are.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/tasks.php';
require __DIR__ . '/lib/suggestions.php';
$user = require_login();

$today = today();
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$weekAhead = load_events($today, date('Y-m-d', strtotime('+8 day')));
$now = date('Y-m-d H:i:s');

$byDay = [];
foreach ($weekAhead as $ev) {
    $start = max(substr($ev['start_at'], 0, 10), $today);
    for ($d = $start; $d <= substr($ev['end_at'], 0, 10) && $d < date('Y-m-d', strtotime('+8 day')); $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $byDay[$d][] = $ev;
    }
}
$birthdays = load_birthdays($today, date('Y-m-d', strtotime('+31 day')));
$bdayByDay = [];
foreach ($birthdays as $b) {
    $bdayByDay[$b['date']][] = $b;
}

// Next thing per family member
$nextFor = [];
foreach (members() as $m) {
    foreach ($weekAhead as $ev) {
        if (in_array((int) $m['id'], $ev['members'], true) && $ev['end_at'] > $now && !$ev['done']) {
            $nextFor[$m['id']] = $ev;
            break;
        }
    }
}
$tasks = load_tasks(['until' => $tomorrow]);
$suggestions = array_slice(build_suggestions(), 0, 6);
$books = open_friendbooks();

$hour = (int) date('G');
$greet = $hour < 6 ? 'Goedenacht' : ($hour < 12 ? 'Goedemorgen' : ($hour < 18 ? 'Goedemiddag' : 'Goedenavond'));

function day_events_html(array $events, array $bdays): string
{
    $html = '';
    foreach ($bdays as $b) {
        $html .= '<a class="ev" style="--c:#E0568A" href="' . ($b['kind'] === 'member' ? 'persoon.php?id=' : 'contact.php?id=') . (int) $b['person']['id'] . '">'
            . '<div class="ev-emoji">🎂</div><div class="ev-body"><span class="ev-title">' . e($b['name']) . ' is jarig' . ($b['age'] !== null ? ' en wordt ' . $b['age'] : '') . '!</span></div>' . avatar($b['person'], 36) . '</a>';
    }
    foreach ($events as $ev) {
        $t = event_type($ev['type']);
        $who = '';
        foreach ($ev['members'] as $mid) {
            if ($m = member($mid)) {
                $who .= avatar($m, 26);
            }
        }
        foreach (array_slice($ev['contacts'], 0, 5) as $c) {
            $who .= avatar($c, 26);
        }
        $meta = [$t[0]];
        if ($ev['location']) {
            $meta[] = '📍 ' . $ev['location'];
        }
        if ($ev['drop_member_id'] && member((int) $ev['drop_member_id'])) {
            $meta[] = '🚗 ' . member((int) $ev['drop_member_id'])['name'];
        }
        if ($ev['pickup_member_id'] && member((int) $ev['pickup_member_id'])) {
            $meta[] = '🏠 ' . member((int) $ev['pickup_member_id'])['name'];
        }
        $html .= '<a class="ev' . ($ev['done'] ? ' done' : '') . '" style="--c:' . e(event_color($ev)) . '" href="event.php?id=' . (int) $ev['id'] . '&amp;occ=' . e($ev['occ']) . '" data-edit-event="' . (int) $ev['id'] . '" data-occ="' . e($ev['occ']) . '">'
            . '<div class="ev-time">' . e($ev['all_day'] ? 'hele dag' : substr($ev['start_at'], 11, 5)) . '</div>'
            . '<div class="ev-body"><span class="ev-title">' . $t[1] . ' ' . e($ev['title']) . '</span><span class="ev-meta">' . e(implode(' · ', $meta)) . '</span>'
            . ($who ? '<div class="avatars">' . $who . '</div>' : '') . '</div></a>';
    }
    return $html;
}

page_start('Vandaag');
?>
<div class="hello">
  <div>
    <h1><?= e($greet) ?>, <?= e($user['name']) ?> 👋</h1>
    <div class="date"><?= e(ucfirst(format_date($today))) ?> · week <?= (int) date('W') ?></div>
  </div>
  <div class="quick">
    <a href="#" data-new-event='{}'>📅 Afspraak</a>
    <a href="#" data-new-event='<?= e(json_encode(['type' => 'PLAYDATE', 'host' => 'HOME'])) ?>'>🧸 Speelafspraak</a>
    <a href="taken.php#nieuw">✅ Taak</a>
    <a href="contact.php?new=1">👋 Persoon</a>
  </div>
</div>

<?php
$isEmpty = !db()->query('SELECT 1 FROM fp_contacts LIMIT 1')->fetchColumn() && !db()->query('SELECT 1 FROM fp_events LIMIT 1')->fetchColumn();
$noBirthdays = array_filter(members(), function ($m) {
    return !$m['birth_day'];
});
if ($isEmpty): ?>
  <div class="card" style="margin-bottom:18px;border:2px dashed var(--accent)">
    <h2>👋 Welkom in jullie Familie Planner!</h2>
    <p>De app is nog leeg. Wil je eerst zien hoe alles werkt met voorbeeldvriendjes, klassen, speelafspraken en taken? Je kunt ze later met één klik weer weghalen.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" action="instellingen.php" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="demo_load"><button class="btn">🧪 Laat voorbeelden zien</button></form>
      <a class="btn secondary" href="instellingen.php">⚙️ Zelf beginnen: gezin instellen</a>
    </div>
  </div>
<?php elseif ($noBirthdays): ?>
  <div class="flash warn">🎂 Vul de verjaardagen van <?= e(implode(', ', array_column($noBirthdays, 'name'))) ?> in bij <a href="instellingen.php">Instellingen</a>, dan zie je hoe oud iedereen is en hoeveel nachtjes het nog is.</div>
<?php endif; ?>

<div class="family-strip">
  <?php foreach (members() as $m):
      $next = $nextFor[$m['id']] ?? null;
      $age = age($m); ?>
    <a class="fam" href="persoon.php?id=<?= (int) $m['id'] ?>" style="--c:<?= e($m['color']) ?>">
      <div class="fam-head"><?= avatar($m, 44) ?><div><b><?= e($m['name']) ?></b><?php if ($age !== null): ?><div class="muted small"><?= $age ?> jaar</div><?php endif; ?></div></div>
      <div class="fam-next">
        <?php if ($next): ?>
          <?= e(day_label($next['start_at'])) ?><?= $next['all_day'] ? '' : ' ' . e(substr($next['start_at'], 11, 5)) ?>:<br><b><?= event_type($next['type'])[1] ?> <?= e($next['title']) ?></b>
        <?php else: ?>
          Niets gepland deze week
        <?php endif; ?>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<div class="cols">
  <div>
    <?php
    $shown = 0;
    for ($i = 0; $i < 8; $i++):
        $d = date('Y-m-d', strtotime("+$i day"));
        $evs = $byDay[$d] ?? [];
        $bd = $bdayByDay[$d] ?? [];
        if ($i > 1 && !$evs && !$bd) {
            continue;
        }
        $shown++; ?>
      <div class="day-group">
        <h3 class="<?= $i === 0 ? 'today' : '' ?>"><?= e(day_label($d)) ?><?= $i < 2 ? ' · ' . e(format_date($d)) : '' ?></h3>
        <?= day_events_html($evs, $bd) ?>
        <?php if (!$evs && !$bd): ?>
          <div class="card flat tight muted"><?= $i === 0 ? 'Niets gepland vandaag. ' : 'Nog niets gepland. ' ?><a href="#" data-new-event='<?= e(json_encode(['start' => $d . 'T15:00', 'end' => $d . 'T16:00'])) ?>'>＋ Iets plannen</a></div>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
    <p><a class="btn secondary" href="agenda.php">📅 Naar de agenda</a></p>
  </div>

  <div class="stack">
    <div class="card">
      <div class="section-title" style="margin-top:0"><h2>💡 Attent zijn</h2><a href="ideeen.php?tab=attent">alles</a></div>
      <?php foreach ($suggestions as $s): ?><?= suggestion_html($s) ?><?php endforeach; ?>
      <?php if (!$suggestions): ?><p class="muted">Alles onder controle. 🌟</p><?php endif; ?>
    </div>

    <div class="card">
      <div class="section-title" style="margin-top:0"><h2>✅ Taken</h2><a href="taken.php">alles</a></div>
      <ul class="list">
        <?php foreach (array_slice($tasks, 0, 8) as $t): ?><?= task_item($t, 'index.php') ?><?php endforeach; ?>
      </ul>
      <?php if (!$tasks): ?><p class="muted">Geen taken voor vandaag of morgen.</p><?php endif; ?>
    </div>

    <div class="card">
      <div class="section-title" style="margin-top:0"><h2>🎂 Verjaardagen</h2><a href="verjaardagen.php">alles</a></div>
      <ul class="list compact">
        <?php foreach (array_slice($birthdays, 0, 6) as $b): $days = days_until($b['date']); ?>
          <li><?= avatar($b['person'], 34) ?><div class="grow"><span class="title"><?= e($b['name']) ?></span><span class="meta"><?= e(format_date_short($b['date'])) ?><?= $b['age'] !== null ? ' · wordt ' . $b['age'] : '' ?></span></div>
            <span class="badge <?= $days <= 3 ? 'warn' : '' ?>"><?= e(in_days_label($days)) ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$birthdays): ?><p class="muted">Geen verjaardagen de komende maand.</p><?php endif; ?>
    </div>

    <?php if ($books): ?>
    <div class="card">
      <div class="section-title" style="margin-top:0"><h2>📒 Vriendjesboeken</h2><a href="vriendjes.php#boek">bekijk</a></div>
      <ul class="list compact">
        <?php foreach ($books as $fb): $m = member((int) $fb['member_id']); ?>
          <li><?= $fb['contact_id'] ? avatar($fb, 32) : '📒' ?><div class="grow">
            <?php if ($fb['direction'] === 'OUT'): ?>
              <span class="title">Boek van <?= e($m['name'] ?? '') ?> is bij <?= e($fb['contact_id'] ? contact_name($fb, false) : '?') ?></span>
            <?php else: ?>
              <span class="title">Boek van <?= e($fb['contact_id'] ? contact_name($fb, false) : '?') ?> ligt hier (<?= e($m['name'] ?? '') ?>)</span>
            <?php endif; ?>
            <span class="meta">sinds <?= e(format_date_short($fb['given_on'])) ?> · <?= -days_until($fb['given_on']) ?> dagen</span></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php page_end();
