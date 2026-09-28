<?php
// Groups (and the smoelenboek): family, school classes, sports teams, work, clubs…
// List per type (?type=), one group with everyone's face and role (?id=), add/edit (?new=1 / &edit=1).
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/upload.php';
require __DIR__ . '/lib/groups.php';
require_login();

/** Default address book label for someone added through a group. */
function relation_for(string $type, bool $child): string
{
    $map = ['FAMILY' => 'FAMILY', 'WORK' => 'COLLEAGUE', 'NEIGHBORHOOD' => 'NEIGHBOR', 'FRIENDS' => 'OWN_FRIEND'];
    if ($type === 'SCHOOL') {
        return $child ? 'CLASSMATE' : 'SCHOOL';
    }
    if ($type === 'SPORT' || $type === 'CLUB') {
        return $child ? 'FRIEND' : 'COACH';
    }
    return $map[$type] ?? 'OTHER';
}

$error = null;
if (is_post()) {
    $action = post('action');
    $gid = post_int('group_id');
    $group = $gid ? find_group($gid) : null;
    try {
        if ($action === 'save_group') {
            if (post('name') === '') {
                throw new RuntimeException('Geef de groep een naam, bijvoorbeeld “Groep 5” of “Voetbal JO9”.');
            }
            $type = isset(GROUP_TYPES[post('type')]) ? post('type') : 'OTHER';
            $data = [$type, mb_cut(post('name'), 120), post_or_null('place'), post_or_null('season'), post_or_null('leader'), post_or_null('notes')];
            if ($group) {
                db()->prepare('UPDATE fp_groups SET type = ?, name = ?, place = ?, season = ?, leader = ?, notes = ? WHERE id = ?')->execute(array_merge($data, [$gid]));
            } else {
                db()->prepare('INSERT INTO fp_groups (type, name, place, season, leader, notes) VALUES (?, ?, ?, ?, ?, ?)')->execute($data);
                $gid = (int) db()->lastInsertId();
            }
            // Which of us are in this group
            $old = [];
            $stmt = db()->prepare('SELECT member_id, role FROM fp_group_members WHERE group_id = ?');
            $stmt->execute([$gid]);
            foreach ($stmt as $r) {
                $old[(int) $r['member_id']] = $r['role'];
            }
            db()->prepare('DELETE FROM fp_group_members WHERE group_id = ?')->execute([$gid]);
            foreach (post_ids('members') as $mid) {
                if (member($mid)) {
                    db()->prepare('INSERT INTO fp_group_members (group_id, member_id, role) VALUES (?, ?, ?)')->execute([$gid, $mid, $old[$mid] ?? null]);
                }
            }
            flash('Groep opgeslagen');
            redirect('groepen.php?id=' . $gid);
        }
        if (!$group) {
            throw new RuntimeException('Groep niet gevonden.');
        }
        if ($action === 'delete_group') {
            db()->prepare('DELETE FROM fp_groups WHERE id = ?')->execute([$gid]);
            flash('Groep verwijderd (de mensen staan nog in het adresboek)');
            redirect('groepen.php?type=' . $group['type']);
        }
        if ($action === 'bulk_add') {
            $child = post('kind') === 'child';
            $added = 0;
            foreach (preg_split('/\r?\n|,/', post('names')) as $line) {
                $name = trim($line);
                if ($name === '') {
                    continue;
                }
                [$first, $last] = array_pad(preg_split('/\s+/', $name, 2), 2, null);
                // Reuse someone already in the address book with the same name
                $stmt = db()->prepare('SELECT id FROM fp_contacts WHERE first_name = ? AND (last_name <=> ?) AND is_child = ? LIMIT 1');
                $stmt->execute([$first, $last, $child ? 1 : 0]);
                $cid = (int) $stmt->fetchColumn();
                if (!$cid) {
                    db()->prepare('INSERT INTO fp_contacts (first_name, last_name, is_child, relation) VALUES (?, ?, ?, ?)')
                        ->execute([mb_cut($first, 80), $last ? mb_cut($last, 80) : null, $child ? 1 : 0, relation_for($group['type'], $child)]);
                    $cid = (int) db()->lastInsertId();
                }
                db()->prepare('INSERT IGNORE INTO fp_group_contacts (group_id, contact_id, role) VALUES (?, ?, ?)')
                    ->execute([$gid, $cid, post_or_null('role') ?: (group_type($group['type'])[4] ?: null)]);
                $added++;
            }
            flash($added . ' ' . ($added === 1 ? 'persoon' : 'mensen') . ' toegevoegd aan ' . $group['name']);
        }
        if ($action === 'add_existing' && post_int('contact_id')) {
            db()->prepare('INSERT IGNORE INTO fp_group_contacts (group_id, contact_id, role) VALUES (?, ?, ?)')
                ->execute([$gid, post_int('contact_id'), post_or_null('role') ?: (group_type($group['type'])[4] ?: null)]);
            flash('Toegevoegd');
        }
        if ($action === 'role') {
            $role = post_or_null('role') ? mb_cut(post('role'), 40) : null;
            if (post_int('contact_id')) {
                db()->prepare('UPDATE fp_group_contacts SET role = ? WHERE group_id = ? AND contact_id = ?')->execute([$role, $gid, post_int('contact_id')]);
            } elseif (post_int('member_id')) {
                db()->prepare('UPDATE fp_group_members SET role = ? WHERE group_id = ? AND member_id = ?')->execute([$role, $gid, post_int('member_id')]);
            }
        }
        if ($action === 'remove' && post_int('contact_id')) {
            db()->prepare('DELETE FROM fp_group_contacts WHERE group_id = ? AND contact_id = ?')->execute([$gid, post_int('contact_id')]);
            flash('Uit de groep gehaald (staat nog wel in het adresboek)');
        }
        if ($action === 'photo_for' && post_int('contact_id')) {
            $stmt = db()->prepare('SELECT photo FROM fp_contacts WHERE id = ?');
            $stmt->execute([post_int('contact_id')]);
            $old = $stmt->fetchColumn();
            if ($new = save_photo('photo')) {
                delete_photo($old ?: null);
                db()->prepare('UPDATE fp_contacts SET photo = ? WHERE id = ?')->execute([$new, post_int('contact_id')]);
                flash('Foto toegevoegd');
            }
        }
        if ($action === 'group_photo') {
            $kidIds = [];
            foreach (db()->query('SELECT member_id FROM fp_group_members WHERE group_id = ' . (int) $gid) as $r) {
                if ((member((int) $r['member_id'])['role'] ?? '') === 'CHILD') {
                    $kidIds[] = (int) $r['member_id'];
                }
            }
            foreach (save_photos('photos') as $file) {
                db()->prepare("INSERT INTO fp_photos (member_id, group_id, kind, school_year, title, file, taken_on) VALUES (?, ?, 'CLASS', ?, ?, ?, ?)")
                    ->execute([$kidIds[0] ?? null, $gid, $group['type'] === 'SCHOOL' ? ($group['season'] ?: school_year()) : null, $group['type'] === 'SCHOOL' ? 'Klassenfoto' : 'Groepsfoto', $file, today()]);
            }
            flash('Foto toegevoegd');
        }
        redirect('groepen.php?id=' . $gid);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$type = isset(GROUP_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$group = get_int('id') ? find_group(get_int('id')) : null;
$editing = !empty($_GET['new']) || ($group && !empty($_GET['edit'])) || $error;

page_start($group ? $group['name'] : 'Groepen', ['active' => 'groepen.php']);

// ---------- Add / edit ----------
if ($editing):
    $g = $group ?: ['id' => '', 'type' => $type ?: 'SCHOOL', 'name' => '', 'place' => '', 'season' => $type === 'SCHOOL' || !$type ? school_year() : '', 'leader' => '', 'notes' => ''];
    if ($error && !empty($_POST)) {
        $g = array_merge($g, array_intersect_key($_POST, $g));
    }
    $gMembers = [];
    if ($group) {
        foreach (db()->query('SELECT member_id FROM fp_group_members WHERE group_id = ' . (int) $group['id']) as $r) {
            $gMembers[] = (int) $r['member_id'];
        }
    } elseif (get_int('member')) {
        $gMembers[] = get_int('member');
    }
    ?>
  <p><a href="<?= $group ? 'groepen.php?id=' . (int) $group['id'] : 'groepen.php' ?>">← Terug</a></p>
  <?php page_header($group ? '✏️ ' . e($group['name']) : '＋ Nieuwe groep'); ?>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="card form" style="max-width:680px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_group"><input type="hidden" name="group_id" value="<?= e($g['id']) ?>">
    <div class="label">Soort groep</div>
    <div class="picker">
      <?php foreach (GROUP_TYPES as $k => [$label, $emoji, $color]): ?>
        <label class="pick" style="--c:<?= e($color) ?>"><input type="radio" name="type" value="<?= $k ?>"<?= $k === $g['type'] ? ' checked' : '' ?>><span><?= $emoji ?> <?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="row2">
      <div><label>Naam</label><input name="name" value="<?= e($g['name']) ?>" placeholder="Groep 5, Voetbal JO9, Familie Reilman…" required></div>
      <div><label>Waar <small>(school, club, bedrijf)</small></label><input name="place" value="<?= e($g['place']) ?>"></div>
    </div>
    <div class="row2">
      <div><label>Seizoen / schooljaar <small>(optioneel)</small></label><input name="season" value="<?= e($g['season']) ?>" placeholder="<?= e(school_year()) ?>"></div>
      <div><label>Juf / trainer / leiding <small>(optioneel)</small></label><input name="leader" value="<?= e($g['leader']) ?>"></div>
    </div>
    <div class="label">Wie van ons zit in deze groep?</div>
    <?= member_picker('members', $gMembers) ?>
    <label>Notities</label><textarea name="notes" rows="2" placeholder="Trainingstijden, groepsapp, gymdagen…"><?= e($g['notes']) ?></textarea>
    <div class="form-actions"><button class="btn">Opslaan</button></div>
  </form>
  <?php if ($group): ?>
    <form method="post" data-confirm="De groep wordt verwijderd. De mensen blijven in het adresboek." style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="delete_group"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>"><button class="btn danger small">🗑 Groep verwijderen</button></form>
  <?php endif;

// ---------- One group ----------
elseif ($group):
    [$tLabel, $tEmoji, $tColor, $leaderLabel, $defaultRole] = group_type($group['type']);
    $contacts = group_contacts((int) $group['id']);
    $ourMembers = [];
    foreach (db()->query('SELECT member_id, role FROM fp_group_members WHERE group_id = ' . (int) $group['id']) as $r) {
        if ($m = member((int) $r['member_id'])) {
            $ourMembers[] = $m + ['group_role' => $r['role']];
        }
    }
    $parentsBy = [];
    $hids = array_filter(array_column($contacts, 'household_id'));
    if ($hids) {
        foreach (db()->query('SELECT household_id, first_name FROM fp_contacts WHERE is_child = 0 AND household_id IN (' . implode(',', array_map('intval', $hids)) . ') ORDER BY first_name') as $p) {
            $parentsBy[$p['household_id']][] = $p['first_name'];
        }
    }
    $stmt = db()->prepare('SELECT * FROM fp_photos WHERE group_id = ? ORDER BY taken_on DESC, id DESC');
    $stmt->execute([(int) $group['id']]);
    $groupPhotos = $stmt->fetchAll();
    $inGroup = array_map('intval', array_column($contacts, 'id'));
    $everyone = db()->query('SELECT id, first_name, last_name, nickname, is_child FROM fp_contacts ORDER BY first_name')->fetchAll();
    $roleList = '<datalist id="roles"><option value="' . e($defaultRole) . '"><option value="ouder"><option value="juf"><option value="meester"><option value="trainer"><option value="coach"><option value="teamgenoot"><option value="collega"><option value="leiding"><option value="oma"><option value="opa"><option value="neef"><option value="nicht"><option value="tante"><option value="oom"></datalist>';
    ?>
  <p class="no-print"><a href="groepen.php?type=<?= e($group['type']) ?>">← <?= e($tLabel) ?></a></p>
  <div class="card" style="border-left:8px solid <?= e($tColor) ?>;margin-bottom:14px">
    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
      <div style="font-size:40px;line-height:1"><?= $tEmoji ?></div>
      <div style="flex:1;min-width:200px">
        <h1 style="font-size:24px"><?= e($group['name']) ?> <span class="muted" style="font-weight:500;font-size:16px"><?= e($group['season']) ?></span></h1>
        <p class="muted" style="margin:4px 0 0"><?= e(implode(' · ', array_filter([$tLabel, $group['place'], $group['leader'] ? ($leaderLabel ? $leaderLabel . ': ' : '') . $group['leader'] : null, count($contacts) + count($ourMembers) . ' mensen']))) ?></p>
        <?php if ($group['notes']): ?><p style="margin:6px 0 0;white-space:pre-line"><?= e($group['notes']) ?></p><?php endif; ?>
      </div>
      <div class="head-actions no-print">
        <button class="btn secondary small" type="button" id="practice">🙈 Namen oefenen</button>
        <a class="btn secondary small" href="netwerk.php?group=<?= (int) $group['id'] ?>">🕸️ Netwerk</a>
        <button class="btn secondary small" onclick="window.print()">🖨</button>
        <a class="btn secondary small" href="groepen.php?id=<?= (int) $group['id'] ?>&amp;edit=1">✏️</a>
      </div>
    </div>
  </div>

  <?php if ($groupPhotos): ?>
    <div class="timeline" style="margin-bottom:14px">
      <?php foreach ($groupPhotos as $p): ?><figure class="photo" style="flex-basis:280px;margin:0"><a href="foto.php?f=<?= e($p['file']) ?>" target="_blank"><img src="foto.php?f=<?= e($p['file']) ?>" alt="Groepsfoto" style="aspect-ratio:3/2"></a></figure><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?= $roleList ?>
  <div class="faces" id="faces">
    <?php foreach ($ourMembers as $m): ?>
      <div class="face" style="border-color:<?= e($m['color']) ?>">
        <a href="persoon.php?id=<?= (int) $m['id'] ?>" style="color:inherit;display:block"><?= avatar($m, 84) ?><b class="fname"><?= e($m['name']) ?></b></a>
        <form method="post" class="role-form no-print"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>"><input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
          <input name="role" value="<?= e($m['group_role']) ?>" placeholder="rol" list="roles" onchange="this.form.submit()"></form>
      </div>
    <?php endforeach; ?>
    <?php foreach ($contacts as $c): ?>
      <div class="face">
        <a href="contact.php?id=<?= (int) $c['id'] ?>" style="color:inherit;display:block"><?= avatar($c, 84) ?><b class="fname"><?= e(contact_name($c)) ?></b></a>
        <?php if (!empty($parentsBy[$c['household_id']]) && $c['is_child']): ?><small>👋 <?= e(implode(' & ', $parentsBy[$c['household_id']])) ?></small><?php endif; ?>
        <?php if ($c['birth_day'] && $c['birth_month']): ?><small>🎂 <?= e($c['birth_day'] . ' ' . MONTHS[(int) $c['birth_month']]) ?></small><?php endif; ?>
        <form method="post" class="role-form no-print"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>"><input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>">
          <input name="role" value="<?= e($c['role']) ?>" placeholder="rol" list="roles" onchange="this.form.submit()"></form>
        <?php if (!$c['photo']): ?>
          <form method="post" enctype="multipart/form-data" class="no-print" style="margin-top:4px">
            <?= csrf_field() ?><input type="hidden" name="action" value="photo_for"><input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
            <label class="btn small secondary" style="cursor:pointer">📷 Foto<input type="file" name="photo" accept="image/*" hidden onchange="this.form.submit()" data-photo-label="Foto van <?= e(contact_name($c, false)) ?>"></label>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$contacts && !$ourMembers): ?><div class="card"><?= empty_state($tEmoji, 'Nog niemand in deze groep. Voeg hieronder mensen toe.') ?></div><?php endif; ?>

  <div class="cols even no-print" style="margin-top:18px">
    <form method="post" class="card form">
      <?= csrf_field() ?><input type="hidden" name="action" value="bulk_add"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
      <h2>➕ Mensen toevoegen</h2>
      <p class="hint">Eén naam per regel (of met komma's). Plak gerust een hele lijst uit de mail of app.</p>
      <textarea name="names" rows="4" placeholder="Noor de Vries&#10;Sem Bakker&#10;Julia"></textarea>
      <div class="picker" style="margin-top:8px">
        <label class="pick sm"><input type="radio" name="kind" value="child"<?= in_array($group['type'], ['SCHOOL', 'SPORT', 'CLUB'], true) ? ' checked' : '' ?>><span>🧒 Kinderen</span></label>
        <label class="pick sm"><input type="radio" name="kind" value="adult"<?= in_array($group['type'], ['SCHOOL', 'SPORT', 'CLUB'], true) ? '' : ' checked' ?>><span>🧑 Volwassenen</span></label>
        <input name="role" list="roles" placeholder="rol, bijv. <?= e($defaultRole ?: 'ouder') ?>" style="width:auto;min-height:34px;padding:4px 10px">
      </div>
      <button class="btn" style="margin-top:10px">Toevoegen</button>
    </form>
    <form method="post" enctype="multipart/form-data" class="card form">
      <?= csrf_field() ?><input type="hidden" name="action" value="group_photo"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
      <h2>📸 <?= $group['type'] === 'SCHOOL' ? 'Klassenfoto' : 'Groepsfoto' ?></h2>
      <input type="file" name="photos[]" accept="image/*" multiple required data-photo-label="<?= $group['type'] === 'SCHOOL' ? 'Klassenfoto' : 'Groepsfoto' ?>" data-aspect="orig">
      <button class="btn" style="margin-top:10px">Uploaden</button>
    </form>
  </div>
  <form method="post" class="card form no-print" style="margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_existing"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
    <b style="margin-right:4px">👤 Uit het adresboek:</b>
    <select name="contact_id" style="width:auto;flex:1;min-width:180px" required><option value="">kies iemand…</option>
      <?php foreach ($everyone as $c): if (in_array((int) $c['id'], $inGroup, true)) continue; ?><option value="<?= (int) $c['id'] ?>"><?= e(contact_name($c)) ?><?= $c['is_child'] ? ' 🧒' : '' ?></option><?php endforeach; ?>
    </select>
    <input name="role" list="roles" placeholder="rol" style="width:140px">
    <button class="btn">＋</button>
  </form>
  <?php if ($contacts): ?>
    <details class="card no-print" style="margin-top:14px">
      <summary style="cursor:pointer;font-weight:700">Mensen uit deze groep halen</summary>
      <ul class="list compact" style="margin-top:8px">
        <?php foreach ($contacts as $c): ?>
          <li><?= avatar($c, 28) ?><span class="grow"><?= e(contact_name($c)) ?> <span class="muted small"><?= e($c['role']) ?></span></span>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>"><input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>"><button class="link danger">Uit groep halen</button></form></li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>
  <script>
  (function () {
    var btn = document.getElementById('practice');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var on = btn.className.indexOf(' on') === -1;
      btn.className = on ? btn.className + ' on' : btn.className.replace(' on', '');
      btn.textContent = on ? '👀 Namen tonen' : '🙈 Namen oefenen';
      var faces = document.querySelectorAll('#faces .face');
      for (var i = 0; i < faces.length; i++) {
        (function (f) {
          var n = f.querySelector('.fname');
          n.style.filter = on ? 'blur(7px)' : '';
          var extra = f.querySelectorAll('small, .role-form');
          for (var j = 0; j < extra.length; j++) extra[j].style.visibility = on ? 'hidden' : '';
          f.onclick = on ? function (e) { e.preventDefault(); n.style.filter = n.style.filter ? '' : 'blur(7px)'; } : null;
        })(faces[i]);
      }
    });
  })();
  </script>
<?php

// ---------- Overview ----------
else:
    $groups = load_groups($type ? ['type' => $type] : []);
    $counts = [];
    foreach (db()->query('SELECT type, COUNT(*) AS n FROM fp_groups GROUP BY type') as $r) {
        $counts[$r['type']] = (int) $r['n'];
    }
    page_header('🏫 Groepen & klassen', 'Klassen, sportteams, familie, werk en clubs: wie hoort waarbij. Iemand kan in meerdere groepen zitten.',
        '<a class="btn secondary" href="netwerk.php">🕸️ Netwerk</a><a class="btn" href="groepen.php?new=1' . ($type ? '&amp;type=' . e($type) : '') . '">＋ Groep</a>');
    ?>
  <div class="tabs" style="margin-bottom:16px">
    <a href="groepen.php" class="<?= !$type ? 'on' : '' ?>">Alle</a>
    <?php foreach (GROUP_TYPES as $k => [$label, $emoji]): if (empty($counts[$k]) && $type !== $k) continue; ?>
      <a href="groepen.php?type=<?= $k ?>" class="<?= $type === $k ? 'on' : '' ?>"><?= $emoji ?> <?= e($label) ?> <small><?= $counts[$k] ?? 0 ?></small></a>
    <?php endforeach; ?>
  </div>
  <?php if (!$groups): ?>
    <div class="card"><?= empty_state('🏫', 'Nog geen groepen. Maak er een voor de klas van Kaila, het voetbalteam van Bodi of de familie.', '<a class="btn" href="groepen.php?new=1' . ($type ? '&amp;type=' . e($type) : '') . '">＋ Groep</a>') ?></div>
  <?php else: ?>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(280px,1fr))">
      <?php foreach ($groups as $g): [$tLabel, $tEmoji, $tColor] = group_type($g['type']); $faces = array_slice(group_contacts((int) $g['id']), 0, 7); ?>
        <a class="card" href="groepen.php?id=<?= (int) $g['id'] ?>" style="color:inherit;text-decoration:none;border-left:6px solid <?= e($tColor) ?>;display:block">
          <div style="display:flex;gap:10px;align-items:center"><span style="font-size:30px"><?= $tEmoji ?></span>
            <div style="flex:1;min-width:0"><h3 style="margin:0"><?= e($g['name']) ?></h3><span class="muted small"><?= e(implode(' · ', array_filter([$tLabel, $g['place'], $g['season']]))) ?></span></div></div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:8px">
            <span class="avatars"><?php foreach ($g['members'] as $mid => $role): if ($m = member($mid)): ?><?= avatar($m, 30) ?><?php endif; endforeach; ?></span>
            <span class="avatars"><?php foreach ($faces as $c): ?><?= avatar($c, 26) ?><?php endforeach; ?><?php if ($g['contact_count'] > 7): ?><span class="badge">+<?= $g['contact_count'] - 7 ?></span><?php endif; ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php page_end();
