<?php
// Birthdays for the coming year, per month, with a checklist (card / gift / call / party) per person
// and gift ideas. People without a birthday can be filled in quickly at the bottom.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require_login();

if (is_post() && post('action') === 'quick_birthdays') {
    $n = 0;
    foreach ((array) ($_POST['bd'] ?? []) as $cid => $v) {
        $d = (int) ($v['d'] ?? 0);
        $m = (int) ($v['m'] ?? 0);
        $y = (int) ($v['y'] ?? 0);
        if ($d && $m && checkdate($m, $d, $y ?: 2000)) {
            db()->prepare('UPDATE fp_contacts SET birth_day = ?, birth_month = ?, birth_year = ? WHERE id = ?')->execute([$d, $m, $y ?: null, (int) $cid]);
            $n++;
        }
    }
    flash($n . ' verjaardag' . ($n === 1 ? '' : 'en') . ' opgeslagen');
    redirect('verjaardagen.php#zonder');
}

$filter = in_array($_GET['f'] ?? '', ['kids', 'family', 'friends', 'gezin'], true) ? $_GET['f'] : '';
$all = load_birthdays(today(), date('Y-m-d', strtotime('+1 year')));
$list = array_values(array_filter($all, function ($b) use ($filter) {
    $p = $b['person'];
    switch ($filter) {
        case 'gezin': return $b['kind'] === 'member';
        case 'kids': return $b['kind'] === 'contact' && !empty($p['is_child']);
        case 'family': return $b['kind'] === 'contact' && $p['relation'] === 'FAMILY';
        case 'friends': return $b['kind'] === 'contact' && empty($p['is_child']) && $p['relation'] !== 'FAMILY';
    }
    return true;
}));
$checks = [];
foreach (db()->query('SELECT subject, year, item FROM fp_birthday_checks WHERE year >= ' . ((int) date('Y') - 1)) as $r) {
    $checks[$r['subject']][$r['year']][$r['item']] = true;
}
$giftCount = [];
foreach (db()->query("SELECT contact_id, COUNT(*) AS n FROM fp_ideas WHERE category = 'GIFT' AND contact_id IS NOT NULL AND done_at IS NULL GROUP BY contact_id") as $r) {
    $giftCount[$r['contact_id']] = (int) $r['n'];
}
$friendOf = [];
foreach (db()->query('SELECT contact_id, member_id FROM fp_contact_members') as $r) {
    $friendOf[$r['contact_id']][] = (int) $r['member_id'];
}
$without = db()->query('SELECT c.*, h.name AS household_name FROM fp_contacts c LEFT JOIN fp_households h ON h.id = c.household_id WHERE c.birth_day IS NULL OR c.birth_month IS NULL ORDER BY c.is_favorite DESC, c.is_child DESC, c.first_name LIMIT 60')->fetchAll();
$membersWithout = array_filter(members(), function ($m) {
    return !$m['birth_day'] || !$m['birth_month'];
});

page_start('Verjaardagen');
page_header('🎂 Verjaardagen', 'Het komende jaar. Vink af wat je al geregeld hebt, dan krijg je daar geen herinnering meer voor.');
?>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));margin-bottom:18px">
  <?php foreach (members() as $m): $nb = next_birthday($m['birth_day'] ? (int) $m['birth_day'] : null, $m['birth_month'] ? (int) $m['birth_month'] : null); ?>
    <div class="fam" style="--c:<?= e($m['color']) ?>;flex-direction:row;align-items:center">
      <?= avatar($m, 48) ?>
      <div><b><?= e($m['name']) ?></b><br>
        <?php if ($nb): $d = days_until($nb); ?><span class="small"><?= $d === 0 ? '🎉 Vandaag jarig!' : '🎂 nog <b>' . $d . '</b> ' . ($d === 1 ? 'nachtje' : 'nachtjes') ?></span><br><span class="muted small"><?= e(format_date_short($nb)) ?><?= $m['birth_year'] ? ' · wordt ' . ((int) substr($nb, 0, 4) - (int) $m['birth_year']) : '' ?></span>
        <?php else: ?><a class="small" href="instellingen.php#m<?= (int) $m['id'] ?>">Verjaardag invullen</a><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="picker" style="margin-bottom:16px">
  <?php foreach (['' => 'Iedereen', 'gezin' => '🏡 Ons gezin', 'kids' => '🧸 Vriendjes & klasgenoten', 'family' => '👵 Familie', 'friends' => '🥂 Vrienden & overige'] as $k => $label): ?>
    <a class="chip" style="--c:<?= $filter === $k ? 'var(--accent)' : '#999' ?>" href="verjaardagen.php<?= $k ? '?f=' . $k : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php
$byMonth = [];
foreach ($list as $b) {
    $byMonth[substr($b['date'], 0, 7)][] = $b;
}
if (!$byMonth): ?>
  <div class="card"><?= empty_state('🎈', 'Nog geen verjaardagen bekend. Vul ze hieronder in of bij de mensen in het adresboek.') ?></div>
<?php endif; ?>
<?php foreach ($byMonth as $ym => $items): ?>
  <div class="section-title"><h2 style="text-transform:capitalize"><?= e(MONTHS[(int) substr($ym, 5, 2)]) ?> <span class="muted" style="font-weight:500"><?= substr($ym, 0, 4) ?></span></h2></div>
  <div class="card">
    <ul class="list">
      <?php foreach ($items as $b):
          $p = $b['person'];
          $days = days_until($b['date']);
          $year = (int) substr($b['date'], 0, 4);
          $done = $checks[$b['subject']][$year] ?? [];
          $link = $b['kind'] === 'member' ? 'persoon.php?id=' . $p['id'] : 'contact.php?id=' . $p['id'];
          $of = $b['kind'] === 'contact' ? array_map(function ($id) { return member($id)['name'] ?? ''; }, $friendOf[$p['id']] ?? []) : []; ?>
        <li id="<?= e($b['subject']) ?>" style="flex-wrap:wrap">
          <div style="text-align:center;min-width:46px"><b style="font-size:20px;display:block;line-height:1"><?= (int) substr($b['date'], 8) ?></b><span class="muted small"><?= e(DAYS_SHORT[(int) date('w', strtotime($b['date']))]) ?></span></div>
          <?= avatar($p, 44) ?>
          <div class="grow" style="min-width:160px">
            <a class="title" href="<?= e($link) ?>" style="color:inherit"><?= e($b['name']) ?></a>
            <span class="meta"><?= $b['age'] !== null ? 'wordt <b>' . $b['age'] . '</b> · ' : '' ?><?= $b['kind'] === 'member' ? 'ons gezin' : e(RELATIONS[$p['relation']][0] ?? '') ?><?= $of ? ' van ' . e(implode(' & ', $of)) : '' ?></span>
          </div>
          <span class="badge <?= $days <= 3 ? 'warn' : ($days <= 14 ? 'accent' : '') ?>"><?= e(in_days_label($days)) ?></span>
          <div class="picker" style="flex-basis:100%;padding-left:58px;gap:4px">
            <?php foreach (BIRTHDAY_ITEMS as $item => $label): ?>
              <label class="pick sm"><input type="checkbox" class="js-bday" data-subject="<?= e($b['subject']) ?>" data-item="<?= e($item) ?>" data-year="<?= $year ?>"<?= !empty($done[$item]) ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
            <?php if ($b['kind'] === 'contact'): ?>
              <a class="btn small secondary" href="contact.php?id=<?= (int) $p['id'] ?>#cadeaus">🎁 Ideeën<?= !empty($giftCount[$p['id']]) ? ' (' . $giftCount[$p['id']] . ')' : '' ?></a>
            <?php endif; ?>
            <?php if ($b['kind'] === 'member'): ?>
              <button type="button" class="btn small soft" data-new-event='<?= e(json_encode(['type' => 'PARTY', 'title' => 'Verjaardag ' . $b['name'], 'start' => $b['date'] . 'T14:00', 'end' => $b['date'] . 'T17:00', 'members' => array_map('intval', array_keys(members()))])) ?>'>🎈 Feest plannen</button>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endforeach; ?>

<?php if ($without || $membersWithout): ?>
  <div class="section-title" id="zonder"><h2>❓ Verjaardag nog onbekend</h2></div>
  <?php if ($membersWithout): ?><p class="flash warn">Vul ook de verjaardagen van <?= e(implode(', ', array_column($membersWithout, 'name'))) ?> in bij <a href="instellingen.php">Instellingen</a>.</p><?php endif; ?>
  <?php if ($without): ?>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="quick_birthdays">
    <p class="hint">Snel invullen: dag, maand en (als je het weet) het geboortejaar.</p>
    <ul class="list compact">
      <?php foreach ($without as $c): ?>
        <li style="flex-wrap:wrap"><?= avatar($c, 30) ?><span class="grow" style="min-width:140px"><a href="contact.php?id=<?= (int) $c['id'] ?>"><?= e(contact_name($c)) ?></a> <span class="muted small"><?= e(RELATIONS[$c['relation']][0] ?? '') ?></span></span>
          <span style="display:flex;gap:6px">
            <input name="bd[<?= (int) $c['id'] ?>][d]" type="number" min="1" max="31" placeholder="dag" style="width:74px" aria-label="Dag">
            <select name="bd[<?= (int) $c['id'] ?>][m]" style="width:120px" aria-label="Maand"><option value="">maand</option><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"><?= e(MONTHS[$i]) ?></option><?php endfor; ?></select>
            <input name="bd[<?= (int) $c['id'] ?>][y]" type="number" min="1900" max="2100" placeholder="jaar" style="width:90px" aria-label="Jaar">
          </span></li>
      <?php endforeach; ?>
    </ul>
    <button class="btn" style="margin-top:12px">Opslaan</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
<?php page_end();
