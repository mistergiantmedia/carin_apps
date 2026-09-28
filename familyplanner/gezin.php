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
page_header('👨‍👩‍👧‍👦 Ons gezin');
?>
<div class="family-strip">
  <?php foreach (members() as $m):
      $age = age($m);
      $nb = next_birthday($m['birth_day'] ? (int) $m['birth_day'] : null, $m['birth_month'] ? (int) $m['birth_month'] : null);
      $lower = mb_strtolower($m['name']);
      $role = $m['role'] === 'CHILD' ? null : (strpos($lower, 'carin') === 0 ? 'Mama' : (strpos($lower, 'ren') === 0 ? 'Papa' : 'Ouder'));
      $info = array_filter([$role, $age !== null ? $age . ' jaar' : null, $nb ? '🎂 ' . in_days_label(days_until($nb)) : null]); ?>
    <a class="fam" href="persoon.php?id=<?= (int) $m['id'] ?>" style="--c:<?= e($m['color']) ?>" title="<?= $m['role'] === 'CHILD' ? 'Weekplanning' : 'Maand & jaar' ?>">
      <div class="fam-head"><?= avatar($m, 44) ?><div><b><?= e($m['name']) ?></b><div class="muted small"><?= e(implode(' · ', $info)) ?></div></div></div>
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
    <?php foreach (members() as $m): ?><div class="matrix-cell" style="text-transform:none;flex-direction:row;align-items:center;justify-content:center;gap:8px"><?= avatar($m, 26) ?> <?= e($m['name']) ?></div><?php endforeach; ?>
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
              <?= $ev['all_day'] ? '' : e(substr($ev['start_at'], 11, 5)) . ' ' ?><?= e($role) ?><?= event_emoji($ev) ?> <?= e($ev['title']) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endfor; ?>
</div>
<?php page_end();
