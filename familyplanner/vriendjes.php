<?php
// Friends of one child: vriendjesbingo (play with everyone once this school year),
// playdate balance (bij ons / bij hen), the vriendjesboek tracker and all playdates.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/friends.php';
require_login();

$kids = children();
if (!$kids) {
    page_start('Vriendjes');
    echo empty_state('🧸', 'Voeg eerst kinderen toe bij Instellingen.');
    page_end();
    exit;
}
$kid = member(get_int('kid'));
if (!$kid || $kid['role'] !== 'CHILD') {
    $kid = reset($kids);
}
$kidId = (int) $kid['id'];
$here = 'vriendjes.php?kid=' . $kidId;

if (is_post()) {
    $action = post('action');
    if ($action === 'played') {
        // Tick a bingo square: record a playdate today (or on the chosen date)
        $cid = post_int('contact_id');
        $stmt = db()->prepare('SELECT * FROM fp_contacts WHERE id = ?');
        $stmt->execute([$cid]);
        $c = $stmt->fetch();
        if ($c) {
            $date = post('date') && strtotime(post('date')) ? date('Y-m-d', strtotime(post('date'))) : today();
            $host = post('host') === 'AWAY' ? 'AWAY' : 'HOME';
            $id = save_event(normalise_event([
                'title' => $host === 'HOME' ? contact_name($c, false) . ' kwam spelen' : 'Spelen bij ' . contact_name($c, false),
                'type' => 'PLAYDATE', 'start' => $date . ' 14:00', 'end' => $date . ' 17:00', 'host' => $host,
                'members' => [$kidId], 'contacts' => [$cid],
            ]));
            set_event_done($id, $date, true);
            flash('✓ ' . contact_name($c, false) . ' afgevinkt op de bingokaart!');
        }
        redirect($here . '#bingo');
    }
    if ($action === 'book_out' || $action === 'book_in') {
        $cid = post_int('contact_id');
        if (!$cid) {
            flash('Kies bij wie het boek is.', 'error');
        } else {
            db()->prepare('INSERT INTO fp_friendbook (member_id, contact_id, direction, given_on, notes) VALUES (?, ?, ?, ?, ?)')->execute([
                $kidId, $cid, $action === 'book_out' ? 'OUT' : 'IN',
                post('given_on') && strtotime(post('given_on')) ? date('Y-m-d', strtotime(post('given_on'))) : today(), post_or_null('notes'),
            ]);
            flash($action === 'book_out' ? 'Genoteerd: het boek is meegegeven.' : 'Genoteerd: het boek ligt nu bij jullie.');
        }
        redirect($here . '#boek');
    }
    if ($action === 'book_back') {
        db()->prepare('UPDATE fp_friendbook SET returned_on = ? WHERE id = ?')->execute([today(), post_int('id')]);
        flash('Genoteerd als terug 📒');
        redirect($here . '#boek');
    }
    if ($action === 'book_delete') {
        db()->prepare('DELETE FROM fp_friendbook WHERE id = ?')->execute([post_int('id')]);
        redirect($here . '#boek');
    }
    if ($action === 'add_friend') {
        $cid = post_int('contact_id');
        if ($cid) {
            db()->prepare('INSERT IGNORE INTO fp_contact_members (contact_id, member_id) VALUES (?, ?)')->execute([$cid, $kidId]);
            flash('Toegevoegd aan de vriendjes van ' . $kid['name']);
        }
        redirect($here);
    }
}

$years = [];
foreach (db()->query('SELECT DISTINCT school_year FROM fp_classes WHERE school_year IS NOT NULL ORDER BY school_year DESC') as $r) {
    $years[] = $r['school_year'];
}
$year = in_array($_GET['year'] ?? '', $years, true) ? $_GET['year'] : school_year();
if (!in_array($year, $years, true)) {
    array_unshift($years, $year);
}
$since = school_year_start($year);
$bingoPeople = [];
foreach (array_merge(classmates_of($kidId, $year), friends_of($kidId, true)) as $c) {
    $bingoPeople[(int) $c['id']] = $c;
}
uasort($bingoPeople, function ($a, $b) {
    return strcasecmp($a['first_name'], $b['first_name']);
});
$stats = playdate_stats($kidId, $since);
$friends = friends_of($kidId, true);
$hits = 0;
foreach ($bingoPeople as $cid => $c) {
    $s = $stats[$cid] ?? null;
    if ($s && ($s['home'] + $s['away'] + $s['other']) > 0) {
        $hits++;
    }
}
$total = count($bingoPeople);
$books = db()->prepare('SELECT f.*, c.first_name, c.last_name, c.nickname, c.photo FROM fp_friendbook f LEFT JOIN fp_contacts c ON c.id = f.contact_id WHERE f.member_id = ? ORDER BY f.returned_on IS NOT NULL, f.given_on DESC');
$books->execute([$kidId]);
$books = $books->fetchAll();
$openOut = array_values(array_filter($books, function ($b) {
    return $b['direction'] === 'OUT' && !$b['returned_on'];
}));
$openIn = array_values(array_filter($books, function ($b) {
    return $b['direction'] === 'IN' && !$b['returned_on'];
}));
$allKids = db()->query('SELECT id, first_name, last_name, nickname FROM fp_contacts WHERE is_child = 1 ORDER BY first_name')->fetchAll();
$kidOptions = [];
foreach ($allKids as $c) {
    $kidOptions[$c['id']] = contact_name($c);
}
$playdates = array_values(array_filter(load_events(date('Y-m-d', strtotime('-1 year')), date('Y-m-d', strtotime('+6 month')), ['types' => ['PLAYDATE']]), function ($ev) use ($kidId) {
    return in_array($kidId, $ev['members'], true);
}));
$upcoming = array_values(array_filter($playdates, function ($ev) {
    return $ev['start_at'] >= date('Y-m-d H:i:s');
}));
$past = array_reverse(array_values(array_filter($playdates, function ($ev) {
    return $ev['start_at'] < date('Y-m-d H:i:s');
})));

function playdate_preset(int $kidId, array $c, string $host = 'HOME'): string
{
    return e(json_encode(['type' => 'PLAYDATE', 'host' => $host, 'members' => [$kidId], 'title' => ($host === 'HOME' ? 'Spelen met ' : 'Spelen bij ') . contact_name($c, false),
        'contacts' => [['id' => (int) $c['id'], 'name' => contact_name($c, false), 'fullName' => contact_name($c), 'photo' => $c['photo'], 'color' => name_color(contact_name($c, false)), 'rsvp' => '']]], JSON_UNESCAPED_UNICODE));
}

page_start('Vriendjes');
?>
<header class="page-head">
  <div><h1>🧸 Vriendjes</h1><p class="sub">Speelafspraken, vriendjesbingo en het vriendjesboek.</p></div>
  <div class="tabs">
    <?php foreach ($kids as $k): ?><a href="vriendjes.php?kid=<?= (int) $k['id'] ?>" class="<?= (int) $k['id'] === $kidId ? 'on' : '' ?>"><?= e($k['emoji'] . ' ' . $k['name']) ?></a><?php endforeach; ?>
  </div>
</header>

<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr));margin-bottom:6px">
  <div class="stat"><b><?= $hits ?>/<?= $total ?></b><span>🎯 bingo <?= e($year) ?></span></div>
  <div class="stat"><b><?= array_sum(array_column($stats, 'home')) ?></b><span>🏠 keer bij ons</span></div>
  <div class="stat"><b><?= array_sum(array_column($stats, 'away')) ?></b><span>🚗 keer bij vriendjes</span></div>
  <div class="stat"><b><?= count($upcoming) ?></b><span>📅 gepland</span></div>
</div>

<!-- Bingo -->
<div class="section-title" id="bingo">
  <h2>🎯 Vriendjesbingo <?= e($kid['name']) ?></h2>
  <div class="head-actions">
    <?php if (count($years) > 1): ?>
      <select onchange="location='<?= e($here) ?>&year='+this.value+'#bingo'" style="width:auto;min-height:34px;padding:4px 10px"><?php foreach ($years as $y): ?><option<?= $y === $year ? ' selected' : '' ?>><?= e($y) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <button class="btn secondary small" onclick="window.print()">🖨 Kaart printen</button>
  </div>
</div>
<div class="card">
  <p class="muted" style="margin-top:0">Doel: dit schooljaar met iedereen uit de klas (en alle vriendjes) één keer spelen. Een vakje wordt groen zodra er een speelafspraak is geweest; oranje = gepland.</p>
  <?php if ($total): ?>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px"><div class="progress" style="flex:1"><span style="width:<?= round($hits / max(1, $total) * 100) ?>%"></span></div><b><?= round($hits / max(1, $total) * 100) ?>%</b></div>
    <?php if ($hits === $total): ?><div class="flash" style="text-align:center;font-size:18px">🎉 BINGO! <?= e($kid['name']) ?> heeft met iedereen gespeeld! 🎉</div><?php endif; ?>
    <div class="bingo">
      <?php foreach ($bingoPeople as $cid => $c):
          $s = $stats[$cid] ?? null;
          $played = $s && ($s['home'] + $s['away'] + $s['other']) > 0;
          $planned = $s && $s['planned'] > 0; ?>
        <div class="bingo-cell<?= $played ? ' hit' : ($planned ? ' half' : '') ?>">
          <a href="contact.php?id=<?= $cid ?>" style="color:inherit"><?= avatar($c, 64) ?><b><?= e(contact_name($c, false)) ?></b></a>
          <small><?= $played ? ($s['home'] ? '🏠' . $s['home'] . ' ' : '') . ($s['away'] ? '🚗' . $s['away'] . ' ' : '') . ($s['other'] ? '📍' . $s['other'] : '') : ($planned ? '📅 ' . e(format_date_short($s['next'])) : 'nog niet') ?></small>
          <?php if (!$played): ?>
            <div class="no-print" style="display:flex;gap:4px;justify-content:center;margin-top:6px;flex-wrap:wrap">
              <?php if (!$planned): ?><button type="button" class="btn small soft" data-new-event='<?= playdate_preset($kidId, $c) ?>' title="Plan een speelafspraak">📅</button><?php endif; ?>
              <details style="display:inline-block"><summary class="btn small secondary" style="list-style:none" title="Al gespeeld">✓</summary>
                <form method="post" style="position:absolute;z-index:5;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px;box-shadow:var(--shadow);text-align:left;width:190px">
                  <?= csrf_field() ?><input type="hidden" name="action" value="played"><input type="hidden" name="contact_id" value="<?= $cid ?>">
                  <label class="check small"><input type="radio" name="host" value="HOME" checked> bij ons</label>
                  <label class="check small"><input type="radio" name="host" value="AWAY"> bij <?= e(contact_name($c, false)) ?></label>
                  <input type="date" name="date" value="<?= e(today()) ?>" style="margin:6px 0">
                  <button class="btn small ok" style="width:100%">Afvinken</button>
                </form>
              </details>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?= empty_state('🎯', 'Nog niemand op de bingokaart. Voeg de klas van ' . e($kid['name']) . ' toe in het smoelenboek, of koppel vriendjes.', '<a class="btn soft" href="smoelenboek.php?new=1&amp;member=' . $kidId . '">🏫 Klas toevoegen</a>') ?>
  <?php endif; ?>
</div>

<div class="cols" style="margin-top:18px">
  <!-- Balance -->
  <div class="card">
    <h2>⚖️ Wie speelde waar?</h2>
    <p class="muted small">Sinds <?= e(format_date($since, false)) ?>. Als <?= e($kid['name']) ?> vaker bij een vriendje was dan andersom, is het leuk om terug uit te nodigen.</p>
    <?php
    $rows = [];
    foreach ($stats as $cid => $s) {
        $c = $bingoPeople[$cid] ?? null;
        if (!$c) {
            foreach ($friends as $f) {
                if ((int) $f['id'] === $cid) {
                    $c = $f;
                }
            }
        }
        if (!$c) {
            $st = db()->prepare('SELECT * FROM fp_contacts WHERE id = ?');
            $st->execute([$cid]);
            $c = $st->fetch();
        }
        if ($c) {
            $rows[] = [$c, $s];
        }
    }
    usort($rows, function ($a, $b) {
        return ($b[1]['away'] - $b[1]['home']) <=> ($a[1]['away'] - $a[1]['home']);
    });
    ?>
    <?php if ($rows): ?>
      <ul class="list">
        <?php foreach ($rows as [$c, $s]): $owe = $s['away'] > $s['home'] && !$s['planned']; ?>
          <li><?= avatar($c, 38) ?>
            <div class="grow"><a class="title" href="contact.php?id=<?= (int) $c['id'] ?>" style="color:inherit"><?= e(contact_name($c, false)) ?></a>
              <span class="meta">🏠 <?= $s['home'] ?>× bij ons · 🚗 <?= $s['away'] ?>× bij <?= e(contact_name($c, false)) ?><?= $s['last'] ? ' · laatst ' . e(format_date_short($s['last'])) : '' ?><?= $s['next'] ? ' · 📅 ' . e(format_date_short($s['next'])) : '' ?></span></div>
            <?php if ($owe): ?><button type="button" class="btn small soft" data-new-event='<?= playdate_preset($kidId, $c) ?>'>🔁 Terug uitnodigen</button>
            <?php elseif ($s['home'] === $s['away'] && $s['home'] > 0): ?><span class="badge ok">in balans</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?><p class="muted">Nog geen speelafspraken dit schooljaar.</p><?php endif; ?>
    <button type="button" class="btn" style="margin-top:12px" data-new-event='<?= e(json_encode(['type' => 'PLAYDATE', 'host' => 'HOME', 'members' => [$kidId]])) ?>'>🧸 Nieuwe speelafspraak</button>
  </div>

  <!-- Friend book -->
  <div class="card" id="boek">
    <h2>📒 Vriendjesboek</h2>
    <?php if ($openOut): foreach ($openOut as $b): $days = -days_until($b['given_on']); ?>
      <div class="book-status" style="margin-bottom:10px">
        <div class="big">📒</div>
        <div class="grow">Het boek van <?= e($kid['name']) ?> is bij <b><?= e(contact_name($b, false)) ?></b><br><span class="muted small">sinds <?= e(format_date_short($b['given_on'])) ?> · <?= $days ?> dagen<?= $days >= 14 ? ' · even navragen?' : '' ?></span></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="book_back"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn small ok">✓ Terug</button></form>
      </div>
    <?php endforeach; else: ?>
      <div class="book-status" style="margin-bottom:10px"><div class="big">🏠</div><div class="grow">Het boek van <?= e($kid['name']) ?> is <b>thuis</b>.</div></div>
    <?php endif; ?>
    <?php foreach ($openIn as $b): $days = -days_until($b['given_on']); ?>
      <div class="book-status" style="margin-bottom:10px;background:var(--warn-soft)">
        <div class="big">✏️</div>
        <div class="grow">Het boek van <b><?= e(contact_name($b, false)) ?></b> ligt hier om in te vullen<br><span class="muted small">sinds <?= e(format_date_short($b['given_on'])) ?> · <?= $days ?> dagen</span></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="book_back"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn small ok">✓ Teruggegeven</button></form>
      </div>
    <?php endforeach; ?>

    <details class="more"><summary>Boek meegegeven of gekregen</summary>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <div class="row2">
          <div><label>Wie</label><select name="contact_id" required><?= options($kidOptions, '', true, 'kies een kind') ?></select></div>
          <div><label>Datum</label><input type="date" name="given_on" value="<?= e(today()) ?>"></div>
        </div>
        <label>Notitie</label><input name="notes" placeholder="bijv. in de rugzak gedaan">
        <div class="form-actions">
          <button class="btn" name="action" value="book_out">📒 Ons boek meegegeven</button>
          <button class="btn secondary" name="action" value="book_in">✏️ Hun boek gekregen</button>
        </div>
      </form>
    </details>
    <?php $history = array_filter($books, function ($b) { return $b['returned_on']; }); if ($history): ?>
      <h3 class="muted small" style="margin:16px 0 6px">GESCHIEDENIS</h3>
      <ul class="list compact">
        <?php foreach ($history as $b): ?>
          <li><?= avatar($b, 26) ?><span class="grow small"><?= $b['direction'] === 'OUT' ? 'Bij ' . e(contact_name($b, false)) : 'Boek van ' . e(contact_name($b, false)) . ' ingevuld' ?> · <?= e(format_date_short($b['given_on'])) ?> – <?= e(format_date_short($b['returned_on'])) ?></span>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="book_delete"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="link muted small">✕</button></form></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<div class="cols" style="margin-top:18px">
  <div class="card">
    <h2>📅 Speelafspraken</h2>
    <?php if ($upcoming): ?><h3 class="muted small" style="margin:6px 0">GEPLAND</h3><?php endif; ?>
    <?php foreach ($upcoming as $ev): ?>
      <a class="ev" style="--c:<?= e(event_color($ev)) ?>" href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>" data-edit-event="<?= (int) $ev['id'] ?>" data-occ="<?= e($ev['occ']) ?>">
        <div class="ev-time"><?= e(format_date_short($ev['start_at'])) ?><br><?= e(substr($ev['start_at'], 11, 5)) ?></div>
        <div class="ev-body"><span class="ev-title"><?= e($ev['title']) ?></span><span class="ev-meta"><?= e(HOSTS[$ev['host']] ?? '') ?></span>
          <div class="avatars"><?php foreach ($ev['contacts'] as $c): ?><?= avatar($c, 28) ?><?php endforeach; ?></div></div></a>
    <?php endforeach; ?>
    <?php if ($past): ?><h3 class="muted small" style="margin:12px 0 6px">GEWEEST</h3><?php endif; ?>
    <?php foreach (array_slice($past, 0, 12) as $ev): ?>
      <a class="ev" style="--c:<?= e(event_color($ev)) ?>" href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>">
        <div class="ev-time"><?= e(format_date_short($ev['start_at'])) ?></div>
        <div class="ev-body"><span class="ev-title"><?= e($ev['title']) ?></span><span class="ev-meta"><?= e(HOSTS[$ev['host']] ?? '') ?></span></div>
        <span class="avatars"><?php foreach ($ev['contacts'] as $c): ?><?= avatar($c, 26) ?><?php endforeach; ?></span></a>
    <?php endforeach; ?>
    <?php if (!$playdates): ?><p class="muted">Nog geen speelafspraken.</p><?php endif; ?>
  </div>
  <div class="card">
    <h2>💛 Vriendjes van <?= e($kid['name']) ?></h2>
    <div class="faces" style="grid-template-columns:repeat(auto-fill,minmax(96px,1fr))">
      <?php foreach ($friends as $f): ?>
        <a class="face" href="contact.php?id=<?= (int) $f['id'] ?>"><?= avatar($f, 56) ?><b><?= e(contact_name($f, false)) ?><?= $f['is_favorite'] ? ' ⭐' : '' ?></b></a>
      <?php endforeach; ?>
    </div>
    <form method="post" style="display:flex;gap:8px;margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="add_friend">
      <select name="contact_id" required><?= options($kidOptions, '', true, 'Kies een kind uit het adresboek…') ?></select><button class="btn">＋</button></form>
    <p class="hint"><a href="contact.php?new=1&amp;child=1&amp;member=<?= $kidId ?>&amp;next=<?= e(urlencode($here)) ?>">Nieuw vriendje toevoegen</a> · ⭐ = beste vriendjes: dan krijg je een seintje als ze lang niet zijn geweest.</p>
  </div>
</div>
<?php page_end();
