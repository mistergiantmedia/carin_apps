<?php
// Clubs & sport: what our children do (their repeating activities in the agenda) and what their
// friends do (the friends' regular week), so the children can discover clubs they might like and
// put a trial lesson on the bucketlist together with that friend.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require_once __DIR__ . '/lib/week.php';
require __DIR__ . '/lib/bucket.php';
require_login();

$kids = children();
$kidIds = array_map('intval', array_keys($kids));

// Our children's clubs
$ours = [];
$ourKeys = [];
foreach ($kids as $k) {
    $ours[$k['id']] = member_clubs((int) $k['id']);
    foreach ($ours[$k['id']] as $club) {
        $ourKeys[activity_key($club['title'])][] = $k['name'];
    }
}

// Friends' activities, grouped by activity (BSO and family/babysitting are not clubs)
$friendOf = [];
foreach (db()->query('SELECT contact_id, member_id FROM fp_contact_members') as $r) {
    $friendOf[$r['contact_id']][] = (int) $r['member_id'];
}
$activities = [];
foreach (db()->query("SELECT w.id AS week_id, w.contact_id, w.weekday, w.start_time, w.end_time, w.kind, w.title, w.emoji AS item_emoji, c.first_name, c.last_name, c.nickname, c.photo, c.is_child FROM fp_contact_week w
        JOIN fp_contacts c ON c.id = w.contact_id WHERE w.kind NOT IN ('BSO', 'FAMILY') ORDER BY w.title, w.weekday") as $r) {
    $key = activity_key($r['title']) ?: mb_strtolower($r['title']);
    if (!isset($activities[$key])) {
        $activities[$key] = ['title' => $r['title'], 'emoji' => week_emoji($r), 'people' => []];
    }
    $cid = (int) $r['contact_id'];
    if (!isset($activities[$key]['people'][$cid])) {
        $activities[$key]['people'][$cid] = ['contact' => $r, 'days' => []];
    }
    $activities[$key]['people'][$cid]['days'][] = WEEKDAYS_SHORT[(int) $r['weekday']] . (week_time($r) ? ' ' . substr($r['start_time'], 0, 5) : '');
}
uasort($activities, function ($a, $b) {
    return count($b['people']) <=> count($a['people']) ?: strcasecmp($a['title'], $b['title']);
});
$wishes = [];
foreach (load_bucket(['done' => false]) as $w) {
    $wishes[activity_key(preg_replace('/^proefles\s+/iu', '', $w['title']))] = $w;
}

page_start('Clubjes & sport');
page_header('🎯 Clubjes & sport', 'Wat doen ' . e(children_names()) . ', en wat doen de vriendjes? Misschien is er iets wat ze ook leuk vinden.');
?>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr));margin-bottom:10px">
  <?php foreach ($kids as $k): ?>
    <div class="card" style="border-top:5px solid <?= e($k['color']) ?>">
      <div class="section-title" style="margin-top:0"><h2><?= avatar($k, 30) ?> <?= e($k['name']) ?></h2>
        <button type="button" class="btn small soft" data-new-event='<?= e(json_encode(['type' => 'SPORT', 'recurrence' => 'WEEKLY', 'members' => [(int) $k['id']]])) ?>'>＋ Clubje</button></div>
      <?php if ($ours[$k['id']]): ?>
        <ul class="list compact">
          <?php foreach ($ours[$k['id']] as $club): $mates = array_filter(friends_doing($club['title']), function ($r) { return $r['is_child']; }); ?>
            <li><span style="font-size:24px"><?= e(event_emoji($club)) ?></span>
              <div class="grow"><a class="title" href="event.php?id=<?= (int) $club['id'] ?>" style="color:inherit"><?= e($club['title']) ?></a>
                <span class="meta"><?= e(WEEKDAYS[$club['weekday']]) ?><?= $club['time'] ? ' · ' . e($club['time']) : '' ?><?= $club['recurrence'] === 'BIWEEKLY' ? ' · om de week' : '' ?><?= $club['location'] ? ' · ' . e($club['location']) : '' ?></span></div>
              <?php if ($mates): ?><span class="avatars" title="Vriendjes die dit ook doen"><?php foreach (array_slice($mates, 0, 4) as $mt): ?><?= avatar($mt, 26) ?><?php endforeach; ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted">Nog geen vaste clubjes. Voeg ze toe als afspraak die elke week herhaalt, dan staan ze hier vanzelf.</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="section-title"><h2>👫 Wat doen de vriendjes?</h2></div>
<?php if (!$activities): ?>
  <div class="card"><?= empty_state('🎯', 'Nog niets ingevuld. Zet bij een vriendje in het adresboek onder “📅 Vaste week” welke clubjes of sport het doet.', '<a class="btn soft" href="mensen.php?rel=kids">Naar de vriendjes</a>') ?></div>
<?php else: ?>
  <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(270px,1fr))">
    <?php foreach ($activities as $key => $a):
        $alsoUs = $ourKeys[$key] ?? [];
        $wish = $wishes[$key] ?? null;
        $friendsJson = array_map(function ($p) { return bucket_contact_json(array_merge($p['contact'], ['id' => $p['contact']['contact_id']])); }, array_values($a['people'])); ?>
      <div class="card">
        <div style="display:flex;gap:10px;align-items:center"><span style="font-size:34px"><?= e($a['emoji']) ?></span><h3 style="margin:0;flex:1"><?= e($a['title']) ?></h3></div>
        <ul class="list compact" style="margin-top:6px">
          <?php foreach ($a['people'] as $cid => $p): ?>
            <li><a href="contact.php?id=<?= (int) $cid ?>#week"><?= avatar($p['contact'], 30) ?></a>
              <div class="grow"><span class="title"><?= e(contact_name($p['contact'], false)) ?></span><span class="meta"><?= e(implode(', ', array_unique($p['days']))) ?>
                <?php if (!empty($friendOf[$cid])): ?> · vriendje van <?= e(implode(' & ', array_map(function ($m) { return member($m)['name'] ?? ''; }, $friendOf[$cid]))) ?><?php endif; ?></span></div></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($alsoUs): ?>
          <p class="badge ok" style="margin-top:8px">⭐ <?= e(implode(' & ', array_unique($alsoUs))) ?> doet dit ook!</p>
        <?php elseif ($wish): ?>
          <a class="badge accent" style="margin-top:8px" href="bucketlist.php#b<?= (int) $wish['id'] ?>">🌟 Staat op de bucketlist</a>
        <?php else: ?>
          <form method="post" action="bucketlist.php" style="margin-top:10px">
            <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="next" value="clubjes.php">
            <input type="hidden" name="title" value="Proefles <?= e(mb_strtolower($a['title'])) ?>"><input type="hidden" name="emoji" value="<?= e($a['emoji']) ?>">
            <input type="hidden" name="contacts_json" value="<?= e(json_encode($friendsJson, JSON_UNESCAPED_UNICODE)) ?>">
            <div class="picker" style="gap:4px">
              <?php foreach ($kids as $k): ?><label class="pick sm" style="--c:<?= e($k['color']) ?>"><input type="checkbox" name="members[]" value="<?= (int) $k['id'] ?>"><span><?= e($k['emoji'] . ' ' . $k['name']) ?></span></label><?php endforeach; ?>
              <button class="btn small soft">💡 Lijkt me leuk!</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_end();
