<?php
// The family at a glance: a card per member and this week side by side for everyone.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/views.php';
require_login();

$weekOffset = (int) ($_GET['week'] ?? 0);
$weekStart = date('Y-m-d', strtotime(date('Y-m-d', strtotime('monday this week', strtotime(today()))) . ' +' . ($weekOffset * 7) . ' day'));
$weekEnd = date('Y-m-d', strtotime("$weekStart +7 day"));
$events = load_events($weekStart, $weekEnd);
$birthdays = load_birthdays($weekStart, $weekEnd);

page_start('Gezin');
page_header('👨‍👩‍👧‍👦 Ons gezin', 'Klik op iemand voor een eigen overzicht: een weekplanning voor de kinderen, maand en jaar voor de ouders.');
?>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr))">
  <?php foreach (members() as $m): $age = age($m); $nb = next_birthday($m['birth_day'] ? (int) $m['birth_day'] : null, $m['birth_month'] ? (int) $m['birth_month'] : null); ?>
    <a class="fam" href="persoon.php?id=<?= (int) $m['id'] ?>" style="--c:<?= e($m['color']) ?>;align-items:center;text-align:center;padding:20px">
      <?= avatar($m, 88, 'ring') ?>
      <b style="font-size:20px"><?= e($m['name']) ?></b>
      <span class="muted small"><?= $m['role'] === 'PARENT' ? ($m['name'] === 'Carin' ? 'Mama' : ($m['name'] === 'Rene' ? 'Papa' : 'Ouder')) : 'Kind' ?><?= $age !== null ? ' · ' . $age . ' jaar' : '' ?></span>
      <?php if ($nb): ?><span class="badge">🎂 <?= e(in_days_label(days_until($nb))) ?></span><?php endif; ?>
      <span class="btn soft small" style="margin-top:4px"><?= $m['role'] === 'CHILD' ? '🗓 Weekplanning' : '📅 Maand & jaar' ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="section-title">
  <h2>🗓 <?= $weekOffset ? 'Week ' . (int) date('W', strtotime($weekStart)) : 'Deze week' ?> voor iedereen</h2>
  <div class="head-actions">
    <a class="btn secondary small" href="?week=<?= $weekOffset - 1 ?>">‹</a>
    <?php if ($weekOffset): ?><a class="btn secondary small" href="gezin.php">Deze week</a><?php endif; ?>
    <a class="btn secondary small" href="?week=<?= $weekOffset + 1 ?>">›</a>
    <button class="btn secondary small" onclick="window.print()">🖨</button>
  </div>
</div>
<div class="card matrix">
  <div class="matrix-row matrix-head" style="grid-template-columns:110px repeat(<?= count(members()) ?>,minmax(0,1fr))"><div></div>
    <?php foreach (members() as $m): ?><div class="matrix-cell" style="text-transform:none"><?= avatar($m, 30) ?><br><?= e($m['name']) ?></div><?php endforeach; ?>
  </div>
  <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime("$weekStart +$i day")); ?>
    <div class="matrix-row" style="grid-template-columns:110px repeat(<?= count(members()) ?>,minmax(0,1fr))">
      <div class="who" style="flex-direction:column;align-items:flex-start;gap:0"><span style="text-transform:capitalize"><?= e(DAYS[(int) date('w', strtotime($d))]) ?></span><small class="muted"><?= e(format_date_short($d)) ?></small></div>
      <?php foreach (members() as $m): ?>
        <div class="matrix-cell<?= $d === today() ? ' today' : '' ?>">
          <?php foreach ($birthdays as $b): if ($b['date'] === $d && ($b['kind'] !== 'member' || (int) $b['person']['id'] === (int) $m['id'])) :
              if ($b['kind'] === 'contact') { $links = db()->prepare('SELECT 1 FROM fp_contact_members WHERE contact_id = ? AND member_id = ?'); $links->execute([$b['person']['id'], $m['id']]); if (!$links->fetchColumn()) continue; } ?>
            <span class="mtag" style="--ec:#E0568A">🎂 <?= e($b['name']) ?></span>
          <?php endif; endforeach; ?>
          <?php foreach ($events as $ev):
              if (substr($ev['start_at'], 0, 10) > $d || substr($ev['end_at'], 0, 10) < $d) continue;
              $role = in_array((int) $m['id'], $ev['members'], true) ? '' : ((int) $ev['drop_member_id'] === (int) $m['id'] ? '🚗 brengen: ' : ((int) $ev['pickup_member_id'] === (int) $m['id'] ? '🏠 halen: ' : null));
              if ($role === null) continue; ?>
            <a class="mtag<?= $ev['done'] ? ' done' : '' ?>" style="--ec:<?= e(event_color($ev)) ?>;color:inherit" href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>" data-edit-event="<?= (int) $ev['id'] ?>" data-occ="<?= e($ev['occ']) ?>">
              <?= $ev['all_day'] ? '' : e(substr($ev['start_at'], 11, 5)) . ' ' ?><?= e($role) ?><?= event_type($ev['type'])[1] ?> <?= e($ev['title']) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endfor; ?>
</div>
<?php page_end();
