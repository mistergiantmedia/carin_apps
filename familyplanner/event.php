<?php
// One event: all details, guests with their answers, checklist / "wie neemt wat mee", and a full edit form.
// ?id=<id>&occ=<Y-m-d> for an existing event, ?new=1 (optional type, member, date, contact) for a new one.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/tasks.php';
require_login();

$id = get_int('id');
$occ = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['occ'] ?? '')) ? $_GET['occ'] : null;
$ev = $id ? find_event($id) : null;
if ($id && !$ev) {
    flash('Deze afspraak bestaat niet meer.', 'warn');
    redirect('agenda.php');
}
$error = null;

if (is_post()) {
    $action = post('action');
    try {
        if ($action === 'save') {
            $in = $_POST;
            $in['all_day'] = !empty($_POST['all_day']);
            $in['start'] = post('start_date') . ($in['all_day'] ? '' : ' ' . (post('start_time') ?: '09:00'));
            $in['end'] = (post('end_date') ?: post('start_date')) . ($in['all_day'] ? '' : ' ' . (post('end_time') ?: post('start_time') ?: '10:00'));
            $in['contacts'] = json_decode((string) ($_POST['contacts_json'] ?? '[]'), true) ?: [];
            $in['members'] = post_ids('members');
            if (empty($_POST['use_color'])) {
                $in['color'] = '';
            }
            $data = normalise_event($in);
            $saveId = $id;
            if ($ev && $ev['recurring'] && post('scope') === 'one' && $occ) {
                $saveId = detach_occurrence($ev, $occ);
                $data['recurrence'] = '';
                $data['recur_until'] = null;
            }
            $saveId = save_event($data, $saveId);
            flash($id ? 'Opgeslagen' : 'Afspraak toegevoegd');
            redirect('event.php?id=' . $saveId . '&occ=' . substr($data['start_at'], 0, 10));
        }
        if ($action === 'add_task' && $ev) {
            if (post('title') !== '') {
                add_task(['title' => post('title'), 'member_id' => post_int('member_id'), 'contact_id' => post_int('contact_id'), 'event_id' => $id]);
            }
            redirect('event.php?id=' . $id . '&occ=' . $occ . '#lijstje');
        }
        if ($action === 'delete_task' && $ev) {
            db()->prepare('DELETE FROM fp_tasks WHERE id = ? AND event_id = ?')->execute([post_int('task_id'), $id]);
            redirect('event.php?id=' . $id . '&occ=' . $occ . '#lijstje');
        }
        if ($action === 'rsvp' && $ev) {
            db()->prepare('UPDATE fp_event_contacts SET rsvp = ? WHERE event_id = ? AND contact_id = ?')
                ->execute([isset(RSVPS[post('rsvp')]) ? post('rsvp') : '', $id, post_int('contact_id')]);
            redirect('event.php?id=' . $id . '&occ=' . $occ . '#gasten');
        }
        if ($action === 'done' && $ev) {
            set_event_done($id, $occ ?: substr($ev['start_at'], 0, 10), post('done') === '1');
            redirect('event.php?id=' . $id . '&occ=' . $occ);
        }
        if ($action === 'delete' && $ev) {
            delete_event($id, $occ ?: substr($ev['start_at'], 0, 10), post('scope') ?: 'all');
            flash('Verwijderd');
            redirect(back_to('agenda.php'));
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

// The occurrence we're looking at (for repeating events)
if ($ev && $occ && $ev['recurring']) {
    foreach (load_events($occ, date('Y-m-d', strtotime("$occ +1 day")), ['ids' => [$id]]) as $o) {
        if ($o['occ'] === $occ) {
            $ev['start_at'] = $o['start_at'];
            $ev['end_at'] = $o['end_at'];
            $ev['done'] = $o['done'];
        }
    }
}
$occ = $occ ?: ($ev ? substr($ev['start_at'], 0, 10) : null);

// Defaults for a new event
if (!$ev) {
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? $_GET['date'] : today();
    $type = isset(EVENT_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : 'OTHER';
    $preContacts = [];
    if (get_int('contact')) {
        $stmt = db()->prepare('SELECT *, \'\' AS rsvp FROM fp_contacts WHERE id = ?');
        $stmt->execute([get_int('contact')]);
        $preContacts = $stmt->fetchAll();
    }
    $ev = [
        'id' => null, 'title' => (string) ($_GET['title'] ?? ''), 'type' => $type,
        'start_at' => $date . ' ' . ($type === 'PLAYDATE' ? '14:00' : '09:00') . ':00',
        'end_at' => $date . ' ' . ($type === 'PLAYDATE' ? '17:00' : '10:00') . ':00',
        'all_day' => in_array($type, ['HOLIDAY'], true) ? 1 : 0, 'location' => '', 'host' => $type === 'PLAYDATE' ? 'HOME' : '',
        'description' => '', 'color' => null, 'recurrence' => '', 'recur_until' => null, 'drop_member_id' => null, 'pickup_member_id' => null,
        'cost' => null, 'paid' => 0, 'done' => 0, 'members' => get_int('member') ? [get_int('member')] : [], 'contacts' => $preContacts, 'recurring' => false,
    ];
}
$isNew = !$ev['id'];
$t = event_type($ev['type']);
$tasks = $isNew ? [] : event_tasks((int) $ev['id']);
$contactsJson = array_map(function ($c) {
    return ['id' => (int) $c['id'], 'name' => contact_name($c, false), 'fullName' => contact_name($c), 'photo' => $c['photo'], 'color' => name_color(contact_name($c, false)), 'rsvp' => $c['rsvp'] ?? ''];
}, $ev['contacts']);

page_start($isNew ? 'Nieuwe afspraak' : $ev['title'], ['active' => 'agenda.php']);
?>
<p class="no-print"><a href="<?= e(back_to('agenda.php?date=' . substr($ev['start_at'], 0, 10))) ?>">← Terug</a></p>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<?php if (!$isNew): ?>
<div class="card" style="border-top:6px solid <?= e(event_color($ev)) ?>">
  <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
    <div style="font-size:44px;line-height:1"><?= $t[1] ?></div>
    <div style="flex:1;min-width:220px">
      <h1<?= $ev['done'] ? ' style="text-decoration:line-through;opacity:.6"' : '' ?>><?= e($ev['title']) ?></h1>
      <p class="sub muted" style="margin-top:6px">
        <?= e(ucfirst(format_date(substr($ev['start_at'], 0, 10)))) ?> · <?= e(format_time_range($ev)) ?>
        <?php if ($ev['recurring']): ?><br>🔁 <?= e(RECURRENCES[$ev['recurrence']]) ?><?= $ev['recur_until'] ? ' t/m ' . e(format_date($ev['recur_until'], false)) : '' ?><?php endif; ?>
      </p>
      <div class="picker" style="margin-top:10px">
        <span class="chip" style="--c:<?= e($t[2]) ?>"><?= $t[1] ?> <?= e($t[0]) ?></span>
        <?php foreach ($ev['members'] as $mid): if ($m = member($mid)): ?><?= member_chip($m) ?><?php endif; endforeach; ?>
        <?php if ($ev['host']): ?><span class="badge"><?= e(HOSTS[$ev['host']]) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="head-actions no-print">
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="done"><input type="hidden" name="done" value="<?= $ev['done'] ? '0' : '1' ?>">
        <button class="btn <?= $ev['done'] ? 'secondary' : 'ok' ?>"><?= $ev['done'] ? '↺ Niet gedaan' : '✓ Afvinken' ?></button></form>
      <a class="btn soft" href="#bewerken">✏️ Bewerken</a>
      <button class="btn secondary" onclick="window.print()">🖨</button>
    </div>
  </div>
  <ul class="list" style="margin-top:14px">
    <?php if ($ev['location']): ?><li>📍 <a href="https://maps.google.com/?q=<?= e(urlencode($ev['location'])) ?>" target="_blank" rel="noopener"><?= e($ev['location']) ?></a></li><?php endif; ?>
    <?php if ($ev['drop_member_id'] || $ev['pickup_member_id']): ?>
      <li>🚗 Brengen: <b><?= e(member((int) $ev['drop_member_id'])['name'] ?? '—') ?></b> &nbsp; 🏠 Halen: <b><?= e(member((int) $ev['pickup_member_id'])['name'] ?? '—') ?></b></li>
    <?php endif; ?>
    <?php if ($ev['cost'] !== null): ?><li>💶 <?= e(money((float) $ev['cost'])) ?> <?= $ev['paid'] ? '<span class="badge ok">betaald</span>' : '<span class="badge warn">nog betalen</span>' ?></li><?php endif; ?>
    <?php if ($ev['description']): ?><li style="white-space:pre-line;display:block"><?= e($ev['description']) ?></li><?php endif; ?>
  </ul>
</div>

<div class="cols" style="margin-top:14px">
  <div class="card" id="lijstje">
    <h2>📝 Lijstje <small class="muted">wat moet er gebeuren, wie neemt wat mee</small></h2>
    <ul class="list">
      <?php foreach ($tasks as $task): ?>
        <li class="<?= $task['done'] ? 'is-done' : '' ?>" data-task="<?= $task['id'] ?>">
          <form method="post" action="taken.php" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $task['id'] ?>"><input type="hidden" name="done" value="<?= $task['done'] ? 0 : 1 ?>"><input type="hidden" name="next" value="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($occ) ?>">
            <input type="checkbox" class="tick js-task"<?= $task['done'] ? ' checked' : '' ?> onchange="this.form.submit()" aria-label="Afvinken"></form>
          <div class="grow"><span class="title"><?= e($task['title']) ?></span>
            <span class="meta"><?php if ($task['member'] && member($task['member'])): ?><?= e(member($task['member'])['emoji'] . ' ' . member($task['member'])['name']) ?><?php endif; ?><?php if ($task['contact']): ?>👋 <?= e($task['contact']['name']) ?><?php endif; ?></span></div>
          <form method="post" class="inline no-print"><?= csrf_field() ?><input type="hidden" name="action" value="delete_task"><input type="hidden" name="task_id" value="<?= $task['id'] ?>"><button class="link muted" title="Verwijderen">✕</button></form>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$tasks): ?><p class="muted">Nog niets op het lijstje. Denk aan: cadeautje kopen, tas inpakken, boodschappen, wie neemt de salade mee…</p><?php endif; ?>
    <form method="post" class="form no-print" style="display:grid;grid-template-columns:1fr auto auto auto;gap:8px;align-items:end;margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_task">
      <input name="title" placeholder="Nieuw punt…" required>
      <select name="member_id" style="width:auto"><?= options(member_options(), '', true, 'wie van ons?') ?></select>
      <select name="contact_id" style="width:auto"><option value="">of gast?</option><?php foreach ($ev['contacts'] as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e(contact_name($c, false)) ?></option><?php endforeach; ?></select>
      <button class="btn">＋</button>
    </form>
  </div>

  <div class="card" id="gasten">
    <h2>👥 Wie komen er?</h2>
    <?php if (!$ev['contacts']): ?><p class="muted">Nog geen gasten of vriendjes gekoppeld. Voeg ze toe bij bewerken.</p><?php endif; ?>
    <ul class="list">
      <?php foreach ($ev['contacts'] as $c): ?>
        <li class="person-row"><?= avatar($c, 38) ?>
          <div class="grow"><span class="title"><a href="contact.php?id=<?= (int) $c['id'] ?>"><?= e(contact_name($c)) ?></a></span>
            <?php if (!empty($c['allergies'])): ?><span class="meta">⚠️ <?= e($c['allergies']) ?></span><?php endif; ?>
            <?php if (!empty($c['phone'])): ?><span class="meta">📞 <a href="tel:<?= e($c['phone']) ?>"><?= e($c['phone']) ?></a></span><?php endif; ?></div>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="rsvp"><input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>">
            <select name="rsvp" onchange="this.form.submit()" style="width:auto;min-height:34px;padding:4px 8px"><?= options(RSVPS, $c['rsvp']) ?></select></form>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($ev['contacts']):
        $counts = array_count_values(array_column($ev['contacts'], 'rsvp')); ?>
      <p class="muted small" style="margin-top:8px">✅ <?= $counts['YES'] ?? 0 ?> komen · 🤔 <?= $counts['MAYBE'] ?? 0 ?> misschien · ❌ <?= $counts['NO'] ?? 0 ?> niet · ✉️ <?= $counts[''] ?? 0 ?> nog geen antwoord</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card no-print" id="bewerken" style="margin-top:14px">
  <h2><?= $isNew ? 'Nieuwe afspraak' : 'Bewerken' ?></h2>
  <form method="post" class="form" id="event-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <label for="title">Titel</label>
    <input id="title" name="title" value="<?= e($ev['title']) ?>" placeholder="Wat gaan jullie doen?" <?= $isNew ? 'autofocus' : '' ?>>
    <div class="label">Soort</div>
    <div class="type-picker">
      <?php foreach (EVENT_TYPES as $k => [$label, $emoji, $color]): ?>
        <label class="pick" style="--c:<?= e($color) ?>"><input type="radio" name="type" value="<?= e($k) ?>"<?= $k === $ev['type'] ? ' checked' : '' ?>><span><?= $emoji ?> <?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="label">Wie van ons</div>
    <?= member_picker('members', $ev['members']) ?>
    <label class="check" style="margin-top:14px"><input type="checkbox" name="all_day" value="1" id="all_day"<?= $ev['all_day'] ? ' checked' : '' ?>> Hele dag</label>
    <div class="row2">
      <div><label>Begin</label><div class="row2" style="gap:6px"><input type="date" name="start_date" value="<?= e(substr($ev['start_at'], 0, 10)) ?>" required><input type="time" name="start_time" class="t" value="<?= e(substr($ev['start_at'], 11, 5)) ?>"></div></div>
      <div><label>Eind</label><div class="row2" style="gap:6px"><input type="date" name="end_date" value="<?= e(substr($ev['end_at'], 0, 10)) ?>"><input type="time" name="end_time" class="t" value="<?= e(substr($ev['end_at'], 11, 5)) ?>"></div></div>
    </div>
    <div class="label">Met wie <small>(vriendjes, familie, gasten)</small></div>
    <div class="people-select" id="people" data-members="<?= e(implode(',', $ev['members'])) ?>"></div>
    <input type="hidden" name="contacts_json" id="contacts_json" value="<?= e(json_encode($contactsJson)) ?>">
    <div class="row2">
      <div><label>Waar</label><input name="location" value="<?= e($ev['location']) ?>"></div>
      <div><label>Bij wie</label><select name="host"><?= options(HOSTS, $ev['host']) ?></select></div>
    </div>
    <div class="row2">
      <div><label>Herhalen</label><select name="recurrence"><?= options(RECURRENCES, $ev['recurrence']) ?></select></div>
      <div><label>Tot en met</label><input type="date" name="recur_until" value="<?= e($ev['recur_until']) ?>"></div>
    </div>
    <div class="row2">
      <div><label>🚗 Wie brengt</label><select name="drop_member_id"><?= options(member_options(), $ev['drop_member_id'], true) ?></select></div>
      <div><label>🏠 Wie haalt op</label><select name="pickup_member_id"><?= options(member_options(), $ev['pickup_member_id'], true) ?></select></div>
    </div>
    <div class="row2">
      <div><label>Kosten (€)</label><input name="cost" inputmode="decimal" value="<?= $ev['cost'] !== null ? e(str_replace('.', ',', $ev['cost'])) : '' ?>"></div>
      <div><label>Eigen kleur</label><div style="display:flex;gap:10px;align-items:center"><input type="color" name="color" value="<?= e($ev['color'] ?: $t[2]) ?>"><label class="check"><input type="checkbox" name="use_color" value="1"<?= $ev['color'] ? ' checked' : '' ?>> gebruiken</label></div></div>
    </div>
    <label class="check"><input type="checkbox" name="paid" value="1"<?= $ev['paid'] ? ' checked' : '' ?>> Betaald</label>
    <label>Notities</label>
    <textarea name="description" rows="4"><?= e($ev['description']) ?></textarea>
    <?php if (!$isNew && $ev['recurring']): ?>
      <label>Dit is een herhalende afspraak. Wijzigen voor:</label>
      <select name="scope"><option value="all">Alle keren</option><option value="one">Alleen <?= e(format_date($occ)) ?></option></select>
    <?php endif; ?>
    <div class="form-actions">
      <button class="btn">Opslaan</button>
      <span class="spacer"></span>
    </div>
  </form>
  <?php if (!$isNew): ?>
    <form method="post" data-confirm="“<?= e($ev['title']) ?>” wordt verwijderd." style="margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <?php if ($ev['recurring']): ?>
        <select name="scope" style="width:auto"><option value="one">Alleen deze keer</option><option value="future">Deze en volgende</option><option value="all">Alle keren</option></select>
      <?php endif; ?>
      <button class="btn danger">🗑 Verwijderen</button>
    </form>
  <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var hidden = document.getElementById('contacts_json');
  var picker = FP.peopleSelect(document.getElementById('people'), JSON.parse(hidden.value || '[]'), true);
  document.getElementById('event-form').addEventListener('submit', function () { hidden.value = JSON.stringify(picker.value()); });
  var allDay = document.getElementById('all_day');
  var sync = function () { document.querySelectorAll('#event-form input.t').forEach(function (i) { i.hidden = allDay.checked; }); };
  allDay.addEventListener('change', sync); sync();
});
</script>
<?php page_end();
