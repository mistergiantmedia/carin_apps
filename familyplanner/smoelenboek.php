<?php
// Smoelenboek: the children's classes with photos of every classmate and their parents' names.
// Add a whole class at once (one name per line), upload a class photo, and practise the names.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/upload.php';
require_login();

$error = null;
if (is_post()) {
    $action = post('action');
    try {
        if ($action === 'save_class') {
            if (post('name') === '') {
                throw new RuntimeException('Geef de klas een naam, bijvoorbeeld “Groep 5”.');
            }
            $data = [member(post_int('member_id')) ? post_int('member_id') : null, mb_cut(post('name'), 80), post_or_null('school'), post_or_null('school_year') ?: school_year(), post_or_null('teacher'), post_or_null('notes')];
            if (post_int('id')) {
                db()->prepare('UPDATE fp_classes SET member_id = ?, name = ?, school = ?, school_year = ?, teacher = ?, notes = ? WHERE id = ?')->execute(array_merge($data, [post_int('id')]));
                $classId = post_int('id');
            } else {
                db()->prepare('INSERT INTO fp_classes (member_id, name, school, school_year, teacher, notes) VALUES (?, ?, ?, ?, ?, ?)')->execute($data);
                $classId = (int) db()->lastInsertId();
            }
            flash('Klas opgeslagen');
            redirect('smoelenboek.php?class=' . $classId);
        }
        if ($action === 'bulk_add') {
            $classId = post_int('class_id');
            $class = db()->prepare('SELECT * FROM fp_classes WHERE id = ?');
            $class->execute([$classId]);
            $class = $class->fetch();
            if (!$class) {
                throw new RuntimeException('Klas niet gevonden.');
            }
            $added = 0;
            foreach (preg_split('/\r?\n|,/', post('names')) as $line) {
                $name = trim($line);
                if ($name === '') {
                    continue;
                }
                [$first, $last] = array_pad(preg_split('/\s+/', $name, 2), 2, null);
                // Reuse someone who's already in the address book with the same name
                $stmt = db()->prepare('SELECT id FROM fp_contacts WHERE first_name = ? AND (last_name <=> ?) AND is_child = 1 LIMIT 1');
                $stmt->execute([$first, $last]);
                $cid = (int) $stmt->fetchColumn();
                if (!$cid) {
                    db()->prepare("INSERT INTO fp_contacts (first_name, last_name, is_child, relation) VALUES (?, ?, 1, 'CLASSMATE')")->execute([mb_cut($first, 80), $last ? mb_cut($last, 80) : null]);
                    $cid = (int) db()->lastInsertId();
                }
                db()->prepare('INSERT IGNORE INTO fp_class_contacts (class_id, contact_id) VALUES (?, ?)')->execute([$classId, $cid]);
                $added++;
            }
            flash($added . ' ' . ($added === 1 ? 'kind' : 'kinderen') . ' toegevoegd aan ' . $class['name']);
            redirect('smoelenboek.php?class=' . $classId);
        }
        if ($action === 'photo_for') {
            // Quick photo upload straight from the face grid
            $cid = post_int('contact_id');
            $stmt = db()->prepare('SELECT photo FROM fp_contacts WHERE id = ?');
            $stmt->execute([$cid]);
            $old = $stmt->fetchColumn();
            $new = save_photo('photo');
            if ($new) {
                delete_photo($old ?: null);
                db()->prepare('UPDATE fp_contacts SET photo = ? WHERE id = ?')->execute([$new, $cid]);
                flash('Foto toegevoegd');
            }
            redirect('smoelenboek.php?class=' . post_int('class_id'));
        }
        if ($action === 'class_photo') {
            foreach (save_photos('photos') as $file) {
                $stmt = db()->prepare('SELECT member_id, school_year FROM fp_classes WHERE id = ?');
                $stmt->execute([post_int('class_id')]);
                $k = $stmt->fetch();
                db()->prepare("INSERT INTO fp_photos (member_id, class_id, kind, school_year, title, file, taken_on) VALUES (?, ?, 'CLASS', ?, ?, ?, ?)")
                    ->execute([$k['member_id'] ?? null, post_int('class_id'), $k['school_year'] ?? school_year(), 'Klassenfoto', $file, today()]);
            }
            flash('Klassenfoto toegevoegd');
            redirect('smoelenboek.php?class=' . post_int('class_id'));
        }
        if ($action === 'remove') {
            db()->prepare('DELETE FROM fp_class_contacts WHERE class_id = ? AND contact_id = ?')->execute([post_int('class_id'), post_int('contact_id')]);
            flash('Uit de klas gehaald (staat nog wel in het adresboek)');
            redirect('smoelenboek.php?class=' . post_int('class_id'));
        }
        if ($action === 'delete_class') {
            db()->prepare('DELETE FROM fp_classes WHERE id = ?')->execute([post_int('class_id')]);
            flash('Klas verwijderd (de kinderen staan nog in het adresboek)');
            redirect('smoelenboek.php');
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$classes = db()->query('SELECT k.*, (SELECT COUNT(*) FROM fp_class_contacts cc WHERE cc.class_id = k.id) AS n FROM fp_classes k ORDER BY k.school_year DESC, k.member_id, k.name')->fetchAll();
$classId = get_int('class');
$current = null;
foreach ($classes as $k) {
    if ((int) $k['id'] === $classId || (!$classId && !$current)) {
        $current = $k;
    }
}
$editing = !empty($_GET['edit']) || !$classes || !empty($_GET['new']);

page_start('Smoelenboek', ['wide' => false]);
page_header('🏫 Smoelenboek', 'Wie zit er bij Kaila en Bodi in de klas? Met foto’s en namen van de ouders.',
    '<a class="btn secondary" href="?new=1">＋ Klas</a>');
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<?php if ($classes): ?>
  <div class="tabs" style="margin-bottom:16px">
    <?php foreach ($classes as $k): $kid = member($k['member_id'] ? (int) $k['member_id'] : null); ?>
      <a href="?class=<?= (int) $k['id'] ?>" class="<?= $current && (int) $current['id'] === (int) $k['id'] && !$editing ? 'on' : '' ?>"><?= $kid ? e($kid['emoji']) . ' ' : '' ?><?= e($k['name']) ?> <small><?= e($k['school_year']) ?></small></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($editing):
    $k = !empty($_GET['edit']) && $current ? $current : ['id' => '', 'member_id' => get_int('member'), 'name' => '', 'school' => $classes[0]['school'] ?? '', 'school_year' => school_year(), 'teacher' => '', 'notes' => '']; ?>
  <form method="post" class="card form" style="max-width:640px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_class"><input type="hidden" name="id" value="<?= e($k['id']) ?>">
    <h2><?= $k['id'] ? 'Klas bewerken' : 'Nieuwe klas' ?></h2>
    <div class="row2">
      <div><label>Van wie</label><select name="member_id"><?= options(array_map(function ($m) { return $m['emoji'] . ' ' . $m['name']; }, children()), $k['member_id'], true) ?></select></div>
      <div><label>Klas</label><input name="name" value="<?= e($k['name']) ?>" placeholder="Groep 5" required></div>
    </div>
    <div class="row2">
      <div><label>Schooljaar</label><input name="school_year" value="<?= e($k['school_year']) ?>" placeholder="<?= e(school_year()) ?>"></div>
      <div><label>Juf / meester</label><input name="teacher" value="<?= e($k['teacher']) ?>"></div>
    </div>
    <label>School</label><input name="school" value="<?= e($k['school']) ?>">
    <label>Notities</label><textarea name="notes" rows="2" placeholder="Gymdagen, klassenouder, groepsapp…"><?= e($k['notes']) ?></textarea>
    <div class="form-actions"><button class="btn">Opslaan</button><?php if ($classes): ?><a class="btn secondary" href="smoelenboek.php">Annuleren</a><?php endif; ?></div>
  </form>
  <?php if ($k['id']): ?>
    <form method="post" data-confirm="De klas wordt verwijderd. De kinderen blijven in het adresboek." style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="delete_class"><input type="hidden" name="class_id" value="<?= (int) $k['id'] ?>"><button class="btn danger small">🗑 Klas verwijderen</button></form>
  <?php endif; ?>

<?php elseif ($current):
    $stmt = db()->prepare('SELECT c.*, h.name AS household_name FROM fp_contacts c JOIN fp_class_contacts cc ON cc.contact_id = c.id LEFT JOIN fp_households h ON h.id = c.household_id WHERE cc.class_id = ? ORDER BY c.first_name');
    $stmt->execute([(int) $current['id']]);
    $kids = $stmt->fetchAll();
    $parentsBy = [];
    $hids = array_filter(array_column($kids, 'household_id'));
    if ($hids) {
        foreach (db()->query("SELECT household_id, first_name, phone FROM fp_contacts WHERE is_child = 0 AND household_id IN (" . implode(',', array_map('intval', $hids)) . ') ORDER BY first_name') as $p) {
            $parentsBy[$p['household_id']][] = $p['first_name'];
        }
    }
    $stmt = db()->prepare("SELECT * FROM fp_photos WHERE class_id = ? ORDER BY taken_on DESC, id DESC");
    $stmt->execute([(int) $current['id']]);
    $classPhotos = $stmt->fetchAll();
    $kid = member($current['member_id'] ? (int) $current['member_id'] : null);
?>
  <div class="card" style="margin-bottom:14px">
    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
      <?php if ($kid): ?><?= avatar($kid, 56) ?><?php endif; ?>
      <div style="flex:1">
        <h2 style="margin:0"><?= e($current['name']) ?> <span class="muted" style="font-weight:500"><?= e($current['school_year']) ?></span></h2>
        <p class="muted" style="margin:4px 0 0"><?= e(implode(' · ', array_filter([$kid ? 'klas van ' . $kid['name'] : null, $current['teacher'], $current['school'], count($kids) . ' kinderen']))) ?></p>
        <?php if ($current['notes']): ?><p style="margin:6px 0 0;white-space:pre-line"><?= e($current['notes']) ?></p><?php endif; ?>
      </div>
      <div class="head-actions no-print">
        <button class="btn secondary small" type="button" id="practice">🙈 Namen oefenen</button>
        <button class="btn secondary small" onclick="window.print()">🖨</button>
        <a class="btn secondary small" href="?class=<?= (int) $current['id'] ?>&amp;edit=1">✏️</a>
      </div>
    </div>
  </div>

  <?php if ($classPhotos): ?>
    <div class="timeline" style="margin-bottom:14px">
      <?php foreach ($classPhotos as $p): ?><figure class="photo" style="flex-basis:280px;margin:0"><a href="foto.php?f=<?= e($p['file']) ?>" target="_blank"><img src="foto.php?f=<?= e($p['file']) ?>" alt="Klassenfoto" style="aspect-ratio:3/2"></a></figure><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($kids): ?>
    <div class="faces" id="faces">
      <?php foreach ($kids as $c): ?>
        <div class="face">
          <a href="contact.php?id=<?= (int) $c['id'] ?>" style="color:inherit;display:block">
            <?= avatar($c, 84) ?>
            <b class="fname"><?= e(contact_name($c)) ?></b>
          </a>
          <?php if (!empty($parentsBy[$c['household_id']])): ?><small>👋 <?= e(implode(' & ', $parentsBy[$c['household_id']])) ?></small><?php endif; ?>
          <?php $nb = next_birthday($c['birth_day'] ? (int) $c['birth_day'] : null, $c['birth_month'] ? (int) $c['birth_month'] : null); if ($nb): ?><small>🎂 <?= e(birthday_text(['birth_day' => $c['birth_day'], 'birth_month' => $c['birth_month'], 'birth_year' => null])) ?></small><?php endif; ?>
          <?php if (!$c['photo']): ?>
            <form method="post" enctype="multipart/form-data" class="no-print" style="margin-top:6px">
              <?= csrf_field() ?><input type="hidden" name="action" value="photo_for"><input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="class_id" value="<?= (int) $current['id'] ?>">
              <label class="btn small secondary" style="cursor:pointer">📷 Foto<input type="file" name="photo" accept="image/*" hidden onchange="this.form.submit()" data-photo-label="Foto van <?= e(contact_name($c, false)) ?>"></label>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="card"><?= empty_state('🏫', 'Nog geen kinderen in deze klas. Plak hieronder de namen uit de klassenlijst.') ?></div>
  <?php endif; ?>

  <div class="cols even no-print" style="margin-top:18px">
    <form method="post" class="card form">
      <?= csrf_field() ?><input type="hidden" name="action" value="bulk_add"><input type="hidden" name="class_id" value="<?= (int) $current['id'] ?>">
      <h2>➕ Kinderen toevoegen</h2>
      <p class="hint">Eén naam per regel (of met komma's). Plak gerust de hele klassenlijst uit de mail of de schoolapp.</p>
      <textarea name="names" rows="5" placeholder="Noor de Vries&#10;Sem Bakker&#10;Julia"></textarea>
      <button class="btn" style="margin-top:10px">Toevoegen</button>
      <p class="hint">Of <a href="contact.php?new=1&amp;child=1&amp;relation=CLASSMATE&amp;class=<?= (int) $current['id'] ?>&amp;next=<?= e(urlencode('smoelenboek.php?class=' . $current['id'])) ?>">voeg één kind toe met alle details</a>.</p>
    </form>
    <form method="post" enctype="multipart/form-data" class="card form">
      <?= csrf_field() ?><input type="hidden" name="action" value="class_photo"><input type="hidden" name="class_id" value="<?= (int) $current['id'] ?>">
      <h2>📸 Klassenfoto</h2>
      <p class="hint">Upload de klassenfoto. Hij komt ook bij de schoolfoto's van <?= e($kid['name'] ?? 'je kind') ?>.</p>
      <input type="file" name="photos[]" accept="image/*" multiple required data-photo-label="Klassenfoto">
      <button class="btn" style="margin-top:10px">Uploaden</button>
    </form>
  </div>
  <?php if ($kids): ?>
  <details class="card no-print" style="margin-top:14px">
    <summary style="cursor:pointer;font-weight:700">Kinderen uit deze klas halen</summary>
    <ul class="list compact" style="margin-top:8px">
      <?php foreach ($kids as $c): ?>
        <li><?= avatar($c, 28) ?><span class="grow"><?= e(contact_name($c)) ?></span>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="class_id" value="<?= (int) $current['id'] ?>"><input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>"><button class="link danger">Uit klas halen</button></form></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
  <script>
  document.getElementById('practice') && document.getElementById('practice').addEventListener('click', function () {
    var on = this.classList.toggle('on');
    this.textContent = on ? '👀 Namen tonen' : '🙈 Namen oefenen';
    document.querySelectorAll('#faces .face').forEach(function (f) {
      var n = f.querySelector('.fname');
      n.style.filter = on ? 'blur(7px)' : '';
      f.querySelectorAll('small').forEach(function (s) { s.style.visibility = on ? 'hidden' : ''; });
      f.onclick = on ? function (e) { e.preventDefault(); n.style.filter = n.style.filter ? '' : 'blur(7px)'; } : null;
    });
  });
  </script>
<?php endif; ?>
<?php page_end();
