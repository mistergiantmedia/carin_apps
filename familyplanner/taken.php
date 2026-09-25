<?php
// Wie doet wat wanneer: tasks with tick boxes, repeating chores, and a week grid per family member
// showing their events, who brings / picks up, and their tasks.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/tasks.php';
require_login();

if (is_post()) {
    $action = post('action');
    if ($action === 'toggle') {
        $next = toggle_task((int) post_int('id'), post('done') === '1');
        if ($next) {
            flash('Afgevinkt ✓ Volgende keer: ' . format_date($next));
        }
        redirect(back_to('taken.php'));
    }
    if ($action === 'add') {
        if (post('title') === '') {
            flash('Vul in wat er moet gebeuren.', 'error');
        } else {
            $ids = post_ids('members') ?: [null];
            foreach ($ids as $mid) {
                add_task(['title' => post('title'), 'notes' => post('notes'), 'member_id' => $mid, 'due_date' => post('due_date'), 'recurrence' => post('recurrence')]);
            }
            flash(count($ids) > 1 ? 'Taak toegevoegd voor ' . count($ids) . ' personen' : 'Taak toegevoegd');
        }
        redirect(back_to('taken.php'));
    }
    if ($action === 'update') {
        $due = post('due_date');
        db()->prepare('UPDATE fp_tasks SET title = ?, notes = ?, member_id = ?, due_date = ?, recurrence = ? WHERE id = ?')->execute([
            mb_cut(post('title'), 190) ?: 'Taak', post_or_null('notes'), member(post_int('member_id')) ? post_int('member_id') : null,
            $due !== '' && strtotime($due) ? date('Y-m-d', strtotime($due)) : null,
            isset(TASK_RECURRENCES[post('recurrence')]) ? post('recurrence') : '', post_int('id'),
        ]);
        flash('Opgeslagen');
        redirect(back_to('taken.php'));
    }
    if ($action === 'delete') {
        db()->prepare('DELETE FROM fp_tasks WHERE id = ?')->execute([post_int('id')]);
        flash('Taak verwijderd');
        redirect(back_to('taken.php'));
    }
    if ($action === 'clear_done') {
        db()->exec('DELETE FROM fp_tasks WHERE done_at IS NOT NULL AND event_id IS NULL AND done_at < NOW() - INTERVAL 7 DAY');
        flash('Oude afgevinkte taken opgeruimd');
        redirect('taken.php');
    }
}

$memberFilter = member(get_int('member')) ? get_int('member') : null;
$weekOffset = (int) ($_GET['week'] ?? 0);
$weekStart = date('Y-m-d', strtotime('monday this week', strtotime(today())) + $weekOffset * 7 * 86400);
$weekStart = date('Y-m-d', strtotime($weekStart));
$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $weekDays[] = date('Y-m-d', strtotime("$weekStart +$i day"));
}
$weekEnd = date('Y-m-d', strtotime("$weekStart +7 day"));

$tasks = load_tasks(['member' => $memberFilter, 'include_done_since' => date('Y-m-d', strtotime('-7 day'))]);
$groups = ['late' => [], 'today' => [], 'week' => [], 'later' => [], 'nodate' => [], 'done' => []];
foreach ($tasks as $t) {
    if ($t['done_at'] !== null) {
        $groups['done'][] = $t;
    } elseif (!$t['due_date']) {
        $groups['nodate'][] = $t;
    } elseif ($t['due_date'] < today()) {
        $groups['late'][] = $t;
    } elseif ($t['due_date'] === today()) {
        $groups['today'][] = $t;
    } elseif ($t['due_date'] <= date('Y-m-d', strtotime('+7 day'))) {
        $groups['week'][] = $t;
    } else {
        $groups['later'][] = $t;
    }
}
$groupLabels = ['late' => '⏰ Te laat', 'today' => '☀️ Vandaag', 'week' => '📅 Komende week', 'later' => '🗓 Later', 'nodate' => '📌 Zonder datum', 'done' => '✅ Afgevinkt (laatste week)'];

// Week grid data
$weekEvents = load_events($weekStart, $weekEnd);
$weekTasks = db()->prepare('SELECT t.*, e.title AS event_title FROM fp_tasks t LEFT JOIN fp_events e ON e.id = t.event_id WHERE t.due_date >= ? AND t.due_date < ? ORDER BY t.due_date');
$weekTasks->execute([$weekStart, $weekEnd]);
$weekTasks = $weekTasks->fetchAll();
$grid = [];
foreach (members() as $m) {
    foreach ($weekDays as $d) {
        $grid[$m['id']][$d] = [];
    }
}
foreach ($weekEvents as $ev) {
    $day = substr($ev['start_at'], 0, 10);
    if (!in_array($day, $weekDays, true)) {
        continue;
    }
    $t = event_type($ev['type']);
    $time = $ev['all_day'] ? '' : substr($ev['start_at'], 11, 5) . ' ';
    foreach ($ev['members'] as $mid) {
        if (isset($grid[$mid][$day])) {
            $grid[$mid][$day][] = ['kind' => 'event', 'text' => $time . $t[1] . ' ' . $ev['title'], 'color' => event_color($ev), 'done' => $ev['done'], 'id' => $ev['id'], 'occ' => $ev['occ']];
        }
    }
    if ($ev['drop_member_id'] && isset($grid[$ev['drop_member_id']][$day])) {
        $grid[$ev['drop_member_id']][$day][] = ['kind' => 'event', 'text' => '🚗 ' . $time . 'brengen: ' . $ev['title'], 'color' => '#607D8B', 'done' => $ev['done'], 'id' => $ev['id'], 'occ' => $ev['occ']];
    }
    if ($ev['pickup_member_id'] && isset($grid[$ev['pickup_member_id']][$day])) {
        $grid[$ev['pickup_member_id']][$day][] = ['kind' => 'event', 'text' => '🏠 ' . substr($ev['end_at'], 11, 5) . ' halen: ' . $ev['title'], 'color' => '#607D8B', 'done' => $ev['done'], 'id' => $ev['id'], 'occ' => $ev['occ']];
    }
}
foreach ($weekTasks as $t) {
    if ($t['member_id'] && isset($grid[$t['member_id']][$t['due_date']])) {
        $grid[$t['member_id']][$t['due_date']][] = ['kind' => 'task', 'text' => '☐ ' . $t['title'], 'done' => $t['done_at'] !== null];
    }
}

page_start('Wie doet wat');
page_header('✅ Wie doet wat wanneer', 'Taken, klusjes en wie brengt of haalt, voor het hele gezin.',
    '<a class="btn soft" href="#nieuw">＋ Taak</a>');
?>
<div class="card" id="nieuw">
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;align-items:end" class="task-add">
      <div><label for="t-title">Wat moet er gebeuren?</label><input id="t-title" name="title" value="<?= e(mb_cut((string) ($_GET['prefill'] ?? ''), 190)) ?>" placeholder="bijv. Gymtas inpakken, Cadeau kopen voor Noor, Oppas regelen" required<?= !empty($_GET['prefill']) ? ' autofocus' : '' ?>></div>
      <div><label>Wanneer</label><input type="date" name="due_date" value="<?= e(today()) ?>"></div>
      <div><label>Herhalen</label><select name="recurrence"><?= options(TASK_RECURRENCES, '') ?></select></div>
    </div>
    <div class="label">Wie <small>(kies er meer om voor iedereen een eigen taak te maken)</small></div>
    <?= member_picker('members', $memberFilter ? [$memberFilter] : []) ?>
    <details class="more"><summary>Notitie</summary><textarea name="notes" rows="2"></textarea></details>
    <button class="btn" style="margin-top:14px">Toevoegen</button>
  </form>
</div>
<style>@media(max-width:640px){.task-add{grid-template-columns:1fr 1fr!important}.task-add>div:first-child{grid-column:1/-1}}</style>

<div class="section-title">
  <h2>📋 Takenlijst</h2>
  <div class="tabs">
    <a href="taken.php" class="<?= !$memberFilter ? 'on' : '' ?>">Iedereen</a>
    <?php foreach (members() as $m): ?><a href="taken.php?member=<?= (int) $m['id'] ?>" class="<?= $memberFilter == $m['id'] ? 'on' : '' ?>"><?= e($m['emoji'] . ' ' . $m['name']) ?></a><?php endforeach; ?>
  </div>
</div>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr))">
  <?php $any = false; foreach ($groups as $key => $list): if (!$list) continue; $any = true; ?>
    <div class="card">
      <h2 style="font-size:16px"><?= $groupLabels[$key] ?> <span class="badge"><?= count($list) ?></span></h2>
      <ul class="list">
        <?php foreach ($list as $t): ?>
          <?= task_item($t, 'taken.php' . ($memberFilter ? '?member=' . $memberFilter : ''), !$memberFilter) ?>
        <?php endforeach; ?>
      </ul>
      <?php if ($key === 'done'): ?>
        <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="clear_done"><button class="link muted small">Opruimen (ouder dan een week)</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php if (!$any): ?><div class="card"><?= empty_state('🎉', 'Geen open taken. Lekker bezig!') ?></div><?php endif; ?>

<details class="card" style="margin-top:14px">
  <summary style="cursor:pointer;font-weight:700">✏️ Taken bewerken of verwijderen</summary>
  <div class="table-wrap" style="margin-top:10px">
    <table class="data">
      <tr><th>Taak</th><th>Wie</th><th>Wanneer</th><th>Herhalen</th><th></th></tr>
      <?php foreach ($tasks as $t): if ($t['done_at'] !== null) continue; ?>
        <tr>
          <td colspan="5">
            <form method="post" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto auto;gap:6px;align-items:center">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="notes" value="<?= e($t['notes']) ?>">
              <input name="title" value="<?= e($t['title']) ?>" aria-label="Taak">
              <select name="member_id" aria-label="Wie"><?= options(member_options(), $t['member_id'], true) ?></select>
              <input type="date" name="due_date" value="<?= e($t['due_date']) ?>" aria-label="Wanneer">
              <select name="recurrence" aria-label="Herhalen"><?= options(TASK_RECURRENCES, $t['recurrence']) ?></select>
              <button class="btn small" name="action" value="update">Opslaan</button>
              <button class="btn small danger" name="action" value="delete" title="Verwijderen">🗑</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</details>

<div class="section-title">
  <h2>🗓 Weekschema: wie doet wat</h2>
  <div class="head-actions">
    <a class="btn secondary small" href="?week=<?= $weekOffset - 1 ?>">‹</a>
    <span class="muted small">week <?= (int) date('W', strtotime($weekStart)) ?> · <?= e(format_date_short($weekStart)) ?> – <?= e(format_date_short($weekDays[6])) ?></span>
    <a class="btn secondary small" href="?week=<?= $weekOffset + 1 ?>">›</a>
    <?php if ($weekOffset): ?><a class="btn secondary small" href="taken.php">Deze week</a><?php endif; ?>
    <button class="btn secondary small" onclick="window.print()">🖨 Printen</button>
  </div>
</div>
<div class="card matrix">
  <div class="matrix-row matrix-head"><div></div>
    <?php foreach ($weekDays as $d): ?><div class="matrix-cell"><?= e(DAYS_SHORT[(int) date('w', strtotime($d))]) ?> <?= (int) substr($d, 8) ?></div><?php endforeach; ?>
  </div>
  <?php foreach (members() as $m): ?>
    <div class="matrix-row">
      <div class="who"><?= avatar($m, 30) ?> <?= e($m['name']) ?></div>
      <?php foreach ($weekDays as $d): ?>
        <div class="matrix-cell<?= $d === today() ? ' today' : '' ?>">
          <?php foreach ($grid[$m['id']][$d] as $item): ?>
            <?php if ($item['kind'] === 'event'): ?>
              <a class="mtag<?= $item['done'] ? ' done' : '' ?>" style="--ec:<?= e($item['color']) ?>;color:inherit" href="event.php?id=<?= (int) $item['id'] ?>&amp;occ=<?= e($item['occ']) ?>" data-edit-event="<?= (int) $item['id'] ?>" data-occ="<?= e($item['occ']) ?>"><?= e($item['text']) ?></a>
            <?php else: ?>
              <span class="mtag task<?= $item['done'] ? ' done' : '' ?>"><?= e($item['text']) ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php page_end();
