<?php
// School photos per child, as a timeline through the school years (plus class photos and others).
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/upload.php';
require_login();

if (is_post()) {
    $action = post('action');
    try {
        if ($action === 'upload') {
            $files = save_photos('photos');
            if (!$files) {
                throw new RuntimeException('Kies een of meer foto’s.');
            }
            $year = preg_match('/^\d{4}-\d{4}$/', post('school_year')) ? post('school_year') : school_year();
            foreach ($files as $file) {
                db()->prepare('INSERT INTO fp_photos (member_id, kind, school_year, title, file, taken_on) VALUES (?, ?, ?, ?, ?, ?)')->execute([
                    member(post_int('member_id')) ? post_int('member_id') : null, isset(PHOTO_KINDS[post('kind')]) ? post('kind') : 'SCHOOL',
                    $year, post_or_null('title'), $file, post('taken_on') && strtotime(post('taken_on')) ? date('Y-m-d', strtotime(post('taken_on'))) : null,
                ]);
            }
            flash(count($files) . ' foto' . (count($files) === 1 ? '' : '’s') . ' toegevoegd 📸');
        }
        if ($action === 'delete') {
            $stmt = db()->prepare('SELECT file FROM fp_photos WHERE id = ?');
            $stmt->execute([post_int('id')]);
            $file = $stmt->fetchColumn();
            db()->prepare('DELETE FROM fp_photos WHERE id = ?')->execute([post_int('id')]);
            delete_photo($file ?: null);
            flash('Foto verwijderd');
        }
        if ($action === 'profile') {
            // Use a school photo as profile photo: copy it, so deleting one never removes the other
            $stmt = db()->prepare('SELECT * FROM fp_photos WHERE id = ?');
            $stmt->execute([post_int('id')]);
            $p = $stmt->fetch();
            $m = $p ? member($p['member_id'] ? (int) $p['member_id'] : null) : null;
            if ($m) {
                $new = bin2hex(random_bytes(12)) . '.jpg';
                copy(UPLOAD_DIR . '/' . $p['file'], UPLOAD_DIR . '/' . $new);
                copy(UPLOAD_DIR . '/' . thumb_name($p['file']), UPLOAD_DIR . '/' . thumb_name($new));
                delete_photo($m['photo']);
                db()->prepare('UPDATE fp_members SET photo = ? WHERE id = ?')->execute([$new, $m['id']]);
                flash('Profielfoto van ' . $m['name'] . ' aangepast');
            }
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('fotos.php' . (get_int('kid') ? '?kid=' . get_int('kid') : ''));
}

$kid = member(get_int('kid'));
$photos = db()->query('SELECT p.*, k.name AS class_name FROM fp_photos p LEFT JOIN fp_classes k ON k.id = p.class_id ORDER BY p.school_year DESC, p.taken_on DESC, p.id DESC')->fetchAll();
$years = [];
$y = (int) substr(school_year(), 0, 4);
for ($i = 0; $i < 12; $i++) {
    $years[] = ($y - $i) . '-' . ($y - $i + 1);
}

page_start("Schoolfoto's");
page_header("📸 Schoolfoto's", 'Elk jaar een nieuwe schoolfoto: zo zie je ze groeien.');
?>
<div class="tabs" style="margin-bottom:16px">
  <a href="fotos.php" class="<?= !$kid ? 'on' : '' ?>">Iedereen</a>
  <?php foreach (members() as $m): ?><a href="?kid=<?= (int) $m['id'] ?>" class="<?= $kid && (int) $kid['id'] === (int) $m['id'] ? 'on' : '' ?>"><?= e($m['emoji'] . ' ' . $m['name']) ?></a><?php endforeach; ?>
</div>

<?php
$shownMembers = $kid ? [$kid] : children();
if (!$kid) {
    foreach (parents() as $p) {
        foreach ($photos as $ph) {
            if ((int) $ph['member_id'] === (int) $p['id']) {
                $shownMembers[$p['id']] = $p;
                break;
            }
        }
    }
}
foreach ($shownMembers as $m):
    $mine = array_filter($photos, function ($p) use ($m) {
        return (int) $p['member_id'] === (int) $m['id'];
    });
    $byYear = [];
    foreach ($mine as $p) {
        $byYear[$p['school_year'] ?: 'Onbekend'][] = $p;
    }
?>
  <div class="section-title"><h2><?= avatar($m, 34) ?> <?= e($m['name']) ?></h2><span class="muted small"><?= count($mine) ?> foto's</span></div>
  <?php if (!$byYear): ?>
    <div class="card flat"><p class="muted" style="margin:0">Nog geen foto's van <?= e($m['name']) ?>. Upload hieronder de eerste schoolfoto.</p></div>
  <?php else: ?>
    <div class="timeline">
      <?php foreach ($byYear as $year => $list): foreach ($list as $p): ?>
        <figure class="photo" style="margin:0">
          <a href="foto.php?f=<?= e($p['file']) ?>" target="_blank"><img src="foto.php?f=<?= e($p['file']) ?>&amp;s=<?= $p['kind'] === 'CLASS' ? '' : 't' ?>" alt="<?= e($p['title'] ?: PHOTO_KINDS[$p['kind']]) ?>" loading="lazy"<?= $p['kind'] === 'CLASS' ? ' style="aspect-ratio:4/5;object-fit:cover"' : '' ?>></a>
          <figcaption><b><?= e($year) ?></b><span class="muted small"><?= e($p['title'] ?: PHOTO_KINDS[$p['kind']]) ?><?= $p['class_name'] ? ' · ' . e($p['class_name']) : '' ?></span>
            <div style="display:flex;gap:4px;margin-top:6px" class="no-print">
              <a class="btn small secondary" href="foto.php?f=<?= e($p['file']) ?>&amp;download=1" title="Downloaden">⬇</a>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="profile"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn small secondary" title="Als profielfoto gebruiken">👤</button></form>
              <form method="post" class="inline" data-confirm="Deze foto verwijderen?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn small secondary" title="Verwijderen">🗑</button></form>
            </div>
          </figcaption>
        </figure>
      <?php endforeach; endforeach; ?>
    </div>
  <?php endif; ?>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="card form no-print" style="margin-top:22px;max-width:640px">
  <?= csrf_field() ?><input type="hidden" name="action" value="upload">
  <h2>⬆️ Foto's toevoegen</h2>
  <div class="row2">
    <div><label>Van wie</label><select name="member_id"><?= options(member_options(), $kid['id'] ?? (array_key_first(children()) ?? '')) ?></select></div>
    <div><label>Soort</label><select name="kind"><?= options(PHOTO_KINDS, 'SCHOOL') ?></select></div>
  </div>
  <div class="row2">
    <div><label>Schooljaar</label><select name="school_year"><?= options(array_combine($years, $years), school_year()) ?></select></div>
    <div><label>Datum <small>(optioneel)</small></label><input type="date" name="taken_on"></div>
  </div>
  <label>Titel <small>(optioneel, bijv. “Groep 5”)</small></label><input name="title">
  <label>Foto's</label><input type="file" name="photos[]" accept="image/*" multiple required>
  <button class="btn" style="margin-top:14px">Uploaden</button>
</form>
<?php page_end();
