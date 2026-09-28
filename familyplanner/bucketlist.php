<?php
// Bucketlist: things the children want to do together. Everyone taps their own face to say
// "ik wil ook!", the most wanted wishes come first; wishes can be planned in the agenda and ticked off.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/bucket.php';
$user = require_login();

if (is_post()) {
    $action = post('action');
    $id = post_int('id');
    if ($action === 'add') {
        if (post('title') === '') {
            flash('Wat wil je graag doen? Vul het even in.', 'error');
        } else {
            db()->prepare('INSERT INTO fp_bucket (title, emoji, notes, created_by) VALUES (?, ?, ?, ?)')
                ->execute([mb_cut(post('title'), 160), post('emoji') !== '' ? mb_cut(post('emoji'), 16) : null, post_or_null('notes'), $user['id']]);
            $newId = (int) db()->lastInsertId();
            foreach (post_ids('members') as $mid) {
                if (member($mid)) {
                    db()->prepare('INSERT IGNORE INTO fp_bucket_votes (bucket_id, member_id) VALUES (?, ?)')->execute([$newId, $mid]);
                }
            }
            flash('🌟 Op de bucketlist!');
            redirect('bucketlist.php#b' . $newId);
        }
        redirect('bucketlist.php');
    }
    if ($action === 'vote' && $id && member(post_int('member'))) {
        $stmt = db()->prepare('SELECT 1 FROM fp_bucket_votes WHERE bucket_id = ? AND member_id = ?');
        $stmt->execute([$id, post_int('member')]);
        if ($stmt->fetchColumn()) {
            db()->prepare('DELETE FROM fp_bucket_votes WHERE bucket_id = ? AND member_id = ?')->execute([$id, post_int('member')]);
        } else {
            db()->prepare('INSERT INTO fp_bucket_votes (bucket_id, member_id) VALUES (?, ?)')->execute([$id, post_int('member')]);
        }
    }
    if ($action === 'done' && $id) {
        $date = post('date') && strtotime(post('date')) ? date('Y-m-d', strtotime(post('date'))) : today();
        db()->prepare('UPDATE fp_bucket SET done_on = ? WHERE id = ?')->execute([$date, $id]);
        flash('🎉 Gedaan! Weer eentje van de lijst.');
    }
    if ($action === 'undone' && $id) {
        db()->prepare('UPDATE fp_bucket SET done_on = NULL WHERE id = ?')->execute([$id]);
    }
    if ($action === 'plan' && $id && post_int('event_id')) {
        db()->prepare('UPDATE fp_bucket SET event_id = ? WHERE id = ?')->execute([post_int('event_id'), $id]);
        flash('📅 In de agenda gezet!');
    }
    if ($action === 'edit' && $id && post('title') !== '') {
        db()->prepare('UPDATE fp_bucket SET title = ?, emoji = ?, notes = ? WHERE id = ?')
            ->execute([mb_cut(post('title'), 160), post('emoji') !== '' ? mb_cut(post('emoji'), 16) : null, post_or_null('notes'), $id]);
    }
    if ($action === 'delete' && $id) {
        db()->prepare('DELETE FROM fp_bucket WHERE id = ?')->execute([$id]);
        flash('Van de lijst gehaald');
        redirect('bucketlist.php');
    }
    redirect('bucketlist.php#b' . $id);
}

$open = load_bucket(['done' => false]);
$done = load_bucket(['done' => true]);
$kidIds = array_map('intval', array_keys(children()));

page_start('Bucketlist');
page_header('🌟 Onze bucketlist', count($open) . ' ' . (count($open) === 1 ? 'wens' : 'wensen') . ' · ' . count($done) . ' al gedaan 🎉',
    '<a class="btn" href="#nieuw">＋ Nieuwe wens</a>');
?>
<?php if ($open || $done): ?>
  <div class="card tight" style="margin-bottom:16px;display:flex;align-items:center;gap:12px">
    <span style="font-size:22px">🏆</span>
    <div class="progress" style="flex:1"><span style="width:<?= round(count($done) / max(1, count($open) + count($done)) * 100) ?>%"></span></div>
    <b><?= count($done) ?>/<?= count($open) + count($done) ?></b>
  </div>
<?php endif; ?>

<?php if (!$open): ?>
  <div class="card"><?= empty_state('🌟', $done ? 'Alles van de lijst gedaan! Tijd voor nieuwe wensen.' : 'De bucketlist is nog leeg. Wat willen jullie graag een keer samen doen?') ?></div>
<?php else: ?>
  <div class="bucket-grid">
    <?php foreach ($open as $b): ?>
      <div class="bucket-card" id="b<?= (int) $b['id'] ?>">
        <div class="bucket-emoji"><?= e($b['emoji'] ?: '🌟') ?></div>
        <h3><?= e($b['title']) ?></h3>
        <?php if ($b['notes']): ?><p class="muted small"><?= e($b['notes']) ?></p><?php endif; ?>
        <?php if ($b['event_id'] && $b['event_start']): ?>
          <a class="badge accent" href="event.php?id=<?= (int) $b['event_id'] ?>">📅 <?= e(format_date_short($b['event_start'])) ?><?= $b['event_start'] < date('Y-m-d H:i:s') ? ' · geweest' : '' ?></a>
        <?php endif; ?>
        <form method="post" class="bucket-votes">
          <?= csrf_field() ?><input type="hidden" name="action" value="vote"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
          <span class="small muted">Wie wil dit?</span>
          <div>
            <?php foreach (members() as $m): $on = in_array((int) $m['id'], $b['voters'], true); ?>
              <button name="member" value="<?= (int) $m['id'] ?>" class="vote<?= $on ? ' on' : '' ?>" title="<?= e($m['name']) ?><?= $on ? ' wil dit!' : ': tik als je dit ook wilt' ?>" style="--c:<?= e($m['color']) ?>"><?= avatar($m, 38) ?><?php if ($on): ?><span class="heart">💜</span><?php endif; ?></button>
            <?php endforeach; ?>
          </div>
        </form>
        <div class="bucket-actions">
          <?php if (!$b['event_id'] || !$b['event_start']): ?>
            <button type="button" class="btn small soft" data-plan-bucket="<?= (int) $b['id'] ?>" data-preset='<?= e(json_encode(['type' => 'OUTING', 'title' => $b['title'], 'customEmoji' => $b['emoji'], 'members' => $b['voters'] ?: array_map('intval', array_keys(members()))], JSON_UNESCAPED_UNICODE)) ?>'>📅 Plannen</button>
          <?php endif; ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="done"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn small ok">✓ Gedaan!</button></form>
          <details class="bucket-more">
            <summary class="btn small secondary" title="Aanpassen">✏️</summary>
            <form method="post" class="form bucket-edit">
              <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <div style="display:flex;gap:8px;position:relative"><input name="emoji" value="<?= e($b['emoji'] ?? '') ?>" data-emoji-picker data-default="🌟"><input name="title" value="<?= e($b['title']) ?>" required></div>
              <textarea name="notes" rows="2" placeholder="Toelichting" style="margin-top:8px"><?= e($b['notes']) ?></textarea>
              <div style="display:flex;gap:6px;margin-top:8px"><button class="btn small">Opslaan</button>
                <button class="btn small danger" name="action" value="delete" onclick="return confirm('Van de bucketlist halen?')">🗑</button></div>
            </form>
          </details>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" class="card form" id="nieuw" style="margin-top:18px">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <h2>🌟 Nieuwe wens</h2>
  <div style="display:flex;gap:8px;position:relative;margin-top:8px">
    <input name="emoji" value="" data-emoji-picker data-default="🌟" aria-label="Emoji">
    <input name="title" placeholder="Wat willen jullie graag samen doen? Bijv. kamperen in de tuin" required style="font-size:17px">
  </div>
  <div class="label">Wie wil dit?</div>
  <?= member_picker('members', $kidIds) ?>
  <details class="more"><summary>Toelichting</summary><textarea name="notes" rows="2" placeholder="Bijv. in de zomer, met opa en oma, als het mooi weer is"></textarea></details>
  <button class="btn" style="margin-top:14px">Op de bucketlist!</button>
</form>

<?php if ($done): ?>
  <div class="section-title"><h2>🎉 Al gedaan (<?= count($done) ?>)</h2></div>
  <div class="bucket-grid done">
    <?php foreach ($done as $b): ?>
      <div class="bucket-card done" id="b<?= (int) $b['id'] ?>">
        <div class="bucket-emoji"><?= e($b['emoji'] ?: '🌟') ?></div>
        <h3><?= e($b['title']) ?></h3>
        <p class="small muted">✓ <?= e(format_date($b['done_on'])) ?></p>
        <div class="avatars"><?php foreach ($b['voters'] as $mid): if ($m = member($mid)): ?><?= avatar($m, 26) ?><?php endif; endforeach; ?></div>
        <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="undone"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="link muted small">↺ toch nog niet gedaan</button></form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" id="plan-form" hidden><?= csrf_field() ?><input type="hidden" name="action" value="plan"><input type="hidden" name="id"><input type="hidden" name="event_id"></form>
<script>
// Plan a wish: open the event editor, then remember which event it became (ES5 for old TVs)
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('[data-plan-bucket]') : null;
  if (!btn) return;
  var preset = JSON.parse(btn.getAttribute('data-preset'));
  FP.openEditor(preset).then(function (saved) {
    if (!saved || !saved.id) return;
    var f = document.getElementById('plan-form');
    f.elements.id.value = btn.getAttribute('data-plan-bucket');
    f.elements.event_id.value = saved.id;
    f.submit();
  });
});
</script>
<?php page_end();
