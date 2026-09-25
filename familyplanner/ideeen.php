<?php
// Ideas & being attentive: the automatic suggestions, and an idea bank per category
// (outings, school engagement, attentive gestures, dates, dinners, gifts, rainy days, holidays).
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/tasks.php';
require __DIR__ . '/lib/suggestions.php';
require_login();

const IDEA_EVENT_TYPES = ['OUTING' => 'OUTING', 'DATE' => 'PARENTS', 'DINNER' => 'DINNER', 'ACTIVITY' => 'ACTIVITY', 'HOLIDAY' => 'HOLIDAY', 'ENGAGEMENT' => 'SCHOOL'];

$cat = isset(IDEA_CATEGORIES[$_GET['cat'] ?? '']) ? $_GET['cat'] : '';
$tab = $cat ? 'bank' : (($_GET['tab'] ?? '') === 'bank' ? 'bank' : 'attent');
$here = 'ideeen.php' . ($cat ? '?cat=' . $cat : '?tab=' . $tab);

if (is_post()) {
    $action = post('action');
    if ($action === 'add' && post('title') !== '') {
        db()->prepare('INSERT INTO fp_ideas (title, description, category, member_id, url, cost, season) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
            mb_cut(post('title'), 190), post_or_null('description'), isset(IDEA_CATEGORIES[post('category')]) ? post('category') : 'OUTING',
            member(post_int('member_id')) ? post_int('member_id') : null,
            preg_match('~^https?://~', post('url')) ? mb_cut(post('url'), 255) : null, post_or_null('cost'), post_or_null('season'),
        ]);
        flash('Idee bewaard 💡');
    }
    if ($action === 'done') {
        db()->prepare('UPDATE fp_ideas SET done_at = IF(done_at IS NULL, NOW(), NULL) WHERE id = ?')->execute([post_int('id')]);
    }
    if ($action === 'delete') {
        db()->prepare('DELETE FROM fp_ideas WHERE id = ?')->execute([post_int('id')]);
        flash('Idee verwijderd');
    }
    if ($action === 'task') {
        add_task(['title' => post('title'), 'member_id' => post_int('member_id'), 'due_date' => date('Y-m-d', strtotime('+3 day'))]);
        flash('Op de takenlijst gezet ✅');
    }
    redirect(back_to($here));
}

page_start('Ideeën & attent');
page_header('💡 Ideeën & attent zijn', 'Suggesties op basis van jullie agenda, en een ideeënbank voor uitjes, school, dates en kleine gebaren.');
?>
<div class="tabs" style="margin-bottom:16px">
  <a href="ideeen.php?tab=attent" class="<?= $tab === 'attent' ? 'on' : '' ?>">💌 Attent zijn</a>
  <a href="ideeen.php?tab=bank" class="<?= $tab === 'bank' && !$cat ? 'on' : '' ?>">💡 Alle ideeën</a>
  <?php foreach (IDEA_CATEGORIES as $k => [$label, $emoji]): ?><a href="ideeen.php?cat=<?= $k ?>" class="<?= $cat === $k ? 'on' : '' ?>"><?= $emoji ?> <?= e($label) ?></a><?php endforeach; ?>
</div>

<?php if ($tab === 'attent'):
    $suggestions = build_suggestions(); ?>
  <div class="cols">
    <div class="card">
      <h2>💌 Nu handig om te doen</h2>
      <p class="muted small">Automatisch bedacht op basis van verjaardagen, speelafspraken, het vriendjesboek, jullie avondjes uit, oppas en de agenda.</p>
      <?php foreach ($suggestions as $s): ?><?= suggestion_html($s) ?><?php endforeach; ?>
      <?php if (!$suggestions): ?><?= empty_state('🌟', 'Alles is geregeld. Goed bezig!') ?><?php endif; ?>
    </div>
    <div class="stack">
      <div class="card">
        <h2>🎲 Verras me</h2>
        <?php $r = db()->query("SELECT * FROM fp_ideas WHERE done_at IS NULL AND category IN ('ATTENTION','ENGAGEMENT','DATE','OUTING') ORDER BY RAND() LIMIT 3")->fetchAll(); ?>
        <?php foreach ($r as $i): ?>
          <div class="suggest"><div class="s-icon"><?= IDEA_CATEGORIES[$i['category']][1] ?></div><div class="grow"><b><?= e($i['title']) ?></b><?php if ($i['description']): ?><div class="muted small"><?= e($i['description']) ?></div><?php endif; ?></div></div>
        <?php endforeach; ?>
        <a class="btn secondary small" href="ideeen.php?tab=attent" style="margin-top:8px">🎲 Andere ideeën</a>
      </div>
      <div class="card">
        <h2>🙋 Betrokken zijn</h2>
        <p class="muted small">Op school en bij de club. Klaar met iets? Vink het af in de ideeënbank.</p>
        <?php foreach (db()->query("SELECT * FROM fp_ideas WHERE category = 'ENGAGEMENT' AND done_at IS NULL ORDER BY id LIMIT 5") as $i): ?>
          <div class="suggest"><div class="s-icon">🙋</div><div class="grow"><?= e($i['title']) ?></div></div>
        <?php endforeach; ?>
        <a class="btn secondary small" href="ideeen.php?cat=ENGAGEMENT" style="margin-top:8px">Alle ideeën</a>
      </div>
    </div>
  </div>

<?php else:
    $sql = 'SELECT i.*, c.first_name, c.last_name, c.nickname FROM fp_ideas i LEFT JOIN fp_contacts c ON c.id = i.contact_id'
        . ($cat ? ' WHERE i.category = ?' : '') . ' ORDER BY i.done_at IS NOT NULL, i.category, i.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($cat ? [$cat] : []);
    $ideas = $stmt->fetchAll(); ?>
  <?php if ($cat): ?><p class="muted"><?= IDEA_CATEGORIES[$cat][1] ?> <?= e(IDEA_CATEGORIES[$cat][2]) ?>.</p><?php endif; ?>
  <div class="cols">
    <div>
      <?php if (!$ideas): ?><div class="card"><?= empty_state('💡', 'Nog geen ideeën in deze categorie. Voeg er een toe!') ?></div><?php endif; ?>
      <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(250px,1fr))">
        <?php foreach ($ideas as $i): [$label, $emoji] = IDEA_CATEGORIES[$i['category']] ?? ['Idee', '💡']; $m = member($i['member_id'] ? (int) $i['member_id'] : null); ?>
          <div class="card" style="<?= $i['done_at'] ? 'opacity:.55' : '' ?>">
            <div style="display:flex;justify-content:space-between;gap:8px"><span class="badge"><?= $emoji ?> <?= e($label) ?></span><span><?= e($i['cost'] ?? '') ?></span></div>
            <h3 style="margin:10px 0 4px<?= $i['done_at'] ? ';text-decoration:line-through' : '' ?>"><?= e($i['title']) ?></h3>
            <?php if ($i['description']): ?><p class="muted small" style="margin:0 0 6px"><?= e($i['description']) ?></p><?php endif; ?>
            <p class="small muted" style="margin:0">
              <?= $i['season'] ? '🗓 ' . e($i['season']) : '' ?>
              <?= $m ? ' · voor ' . e($m['name']) : '' ?>
              <?= $i['contact_id'] ? ' · 🎁 <a href="contact.php?id=' . (int) $i['contact_id'] . '#cadeaus">' . e(contact_name($i, false)) . '</a>' : '' ?>
              <?= $i['url'] ? ' · <a href="' . e($i['url']) . '" target="_blank" rel="noopener">link ↗</a>' : '' ?>
            </p>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">
              <?php if (isset(IDEA_EVENT_TYPES[$i['category']]) && !$i['done_at']): ?>
                <button type="button" class="btn small soft" data-new-event='<?= e(json_encode(['type' => IDEA_EVENT_TYPES[$i['category']], 'title' => $i['title'], 'description' => $i['description'], 'members' => $i['category'] === 'DATE' ? array_map('intval', array_keys(parents())) : array_map('intval', array_keys(members())), 'allDay' => $i['category'] === 'HOLIDAY'], JSON_UNESCAPED_UNICODE)) ?>'>📅 Plannen</button>
              <?php elseif (!$i['done_at']): ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="task"><input type="hidden" name="title" value="<?= e($i['title']) ?>"><input type="hidden" name="next" value="<?= e($here) ?>"><button class="btn small soft">✅ Op takenlijst</button></form>
              <?php endif; ?>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="done"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><input type="hidden" name="next" value="<?= e($here) ?>"><button class="btn small <?= $i['done_at'] ? 'secondary' : 'ok' ?>"><?= $i['done_at'] ? '↺' : '✓ Gedaan' ?></button></form>
              <form method="post" class="inline" data-confirm="Dit idee verwijderen?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>"><input type="hidden" name="next" value="<?= e($here) ?>"><button class="btn small secondary" title="Verwijderen">🗑</button></form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <form method="post" class="card form">
      <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="next" value="<?= e($here) ?>">
      <h2>＋ Nieuw idee</h2>
      <label>Idee</label><input name="title" required placeholder="bijv. Speeltuin De Kikker">
      <label>Soort</label><select name="category"><?php foreach (IDEA_CATEGORIES as $k => [$label, $emoji]): ?><option value="<?= $k ?>"<?= $k === ($cat ?: 'OUTING') ? ' selected' : '' ?>><?= $emoji ?> <?= e($label) ?></option><?php endforeach; ?></select>
      <label>Toelichting</label><textarea name="description" rows="3"></textarea>
      <div class="row2">
        <div><label>Kosten</label><input name="cost" placeholder="gratis, €, €€"></div>
        <div><label>Seizoen</label><input name="season" placeholder="zomer, herfst…"></div>
      </div>
      <label>Voor wie <small>(optioneel)</small></label><select name="member_id"><?= options(member_options(), '', true, 'iedereen') ?></select>
      <label>Link <small>(optioneel)</small></label><input name="url" type="url" placeholder="https://">
      <button class="btn" style="margin-top:14px">Bewaren</button>
    </form>
  </div>
<?php endif; ?>
<?php page_end();
