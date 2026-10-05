<?php
// The family at a glance: a card per member and this week side by side for everyone.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/views.php';
$user = require_login();

// Rename the family right here (also possible in Instellingen → Ons gezin)
if (is_post() && post('action') === 'rename') {
    if (post('family_name') === '') {
        flash('Vul een naam in voor jullie gezin.', 'error');
    } else {
        db()->prepare('UPDATE fp_families SET name = ? WHERE id = ?')->execute([mb_cut(post('family_name'), 120), $user['family_id']]);
        flash('✓ Gezinsnaam aangepast');
    }
    redirect('gezin.php');
}

$weekOffset = (int) ($_GET['week'] ?? 0);
$weekStart = date('Y-m-d', strtotime(date('Y-m-d', strtotime('monday this week', strtotime(today()))) . ' +' . ($weekOffset * 7) . ' day'));
$weekEnd = date('Y-m-d', strtotime("$weekStart +7 day"));
$events = with_shared_events(load_events($weekStart, $weekEnd), $weekStart, $weekEnd);
$birthdays = load_birthdays($weekStart, $weekEnd);

page_start('Gezin');
page_header('👨‍👩‍👧‍👦 ' . e($user['family_name']) . ' <button type="button" class="link small" onclick="var f=document.getElementById(\'rename\');f.hidden=!f.hidden;if(!f.hidden)f.querySelector(\'input[name=family_name]\').focus()" title="Naam aanpassen">✏️</button>');
?>
<form method="post" id="rename" class="card form" hidden style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
  <?= csrf_field() ?><input type="hidden" name="action" value="rename">
  <div style="flex:1;min-width:220px"><label for="family_name">Naam van jullie gezin</label><input id="family_name" name="family_name" value="<?= e($user['family_name']) ?>" required maxlength="120"></div>
  <button class="btn">Opslaan</button>
  <button type="button" class="btn secondary" onclick="this.form.hidden=true">Annuleren</button>
</form>
<?php
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
<?php
/** Everything of one family member on one day: birthdays, their events and what they bring / pick up. */
$cell = function (array $m, string $d) use ($events, $birthdays): string {
    $html = '';
    foreach ($birthdays as $b) {
        if ($b['date'] !== $d || ($b['kind'] === 'member' && (int) $b['person']['id'] !== (int) $m['id'])) {
            continue;
        }
        if ($b['kind'] === 'contact') {
            $links = db()->prepare('SELECT 1 FROM fp_contact_members WHERE contact_id = ? AND member_id = ?');
            $links->execute([$b['person']['id'], $m['id']]);
            if (!$links->fetchColumn()) {
                continue;
            }
        }
        $html .= '<span class="mtag" style="--ec:#E0568A">🎂 ' . e($b['name']) . '</span>';
    }
    foreach ($events as $ev) {
        if (substr($ev['start_at'], 0, 10) > $d || substr($ev['end_at'], 0, 10) < $d) {
            continue;
        }
        $role = in_array((int) $m['id'], $ev['members'], true) ? '' : ((int) $ev['drop_member_id'] === (int) $m['id'] ? '🚗 brengen: ' : ((int) $ev['pickup_member_id'] === (int) $m['id'] ? '🏠 halen: ' : null));
        if ($role === null) {
            continue;
        }
        $html .= '<a class="mtag' . ($ev['done'] ? ' done' : '') . '" style="--ec:' . e(event_color($ev)) . ';color:inherit" href="' . e(event_link($ev)) . '"' . (empty($ev['shared']) ? ' data-edit-event="' . (int) $ev['id'] . '" data-occ="' . e($ev['occ']) . '"' : '') . '>'
            . ($ev['all_day'] ? '' : e(substr($ev['start_at'], 11, 5)) . ' ') . e($role) . event_emoji($ev) . ' ' . e($ev['title']) . '</a>';
    }
    return $html;
};
$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[] = date('Y-m-d', strtotime("$weekStart +$i day"));
}
?>
<div class="card matrix">
  <div class="matrix-row matrix-head"><div></div>
    <?php foreach ($days as $d): ?>
      <div class="matrix-cell<?= $d === today() ? ' today-head' : '' ?>"><span style="text-transform:capitalize"><?= e(DAYS[(int) date('w', strtotime($d))]) ?></span><small class="muted" style="text-transform:none;font-weight:600"><?= e(format_date_short($d)) ?></small></div>
    <?php endforeach; ?>
  </div>
  <?php foreach (members() as $m): ?>
    <div class="matrix-row">
      <a class="who" href="persoon.php?id=<?= (int) $m['id'] ?>" style="color:inherit"><?= avatar($m, 30) ?> <?= e($m['name']) ?></a>
      <?php foreach ($days as $d): ?>
        <div class="matrix-cell<?= $d === today() ? ' today' : '' ?>"><?= $cell($m, $d) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php page_end();
