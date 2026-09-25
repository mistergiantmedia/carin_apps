<?php
// Address book: everyone outside the family, as faces or as an address list per household (printable).
require __DIR__ . '/lib/app.php';
require_login();

$q = trim((string) ($_GET['q'] ?? ''));
$rel = isset(RELATIONS[$_GET['rel'] ?? '']) ? $_GET['rel'] : (($_GET['rel'] ?? '') === 'kids' ? 'kids' : '');
$of = member(get_int('of')) ? get_int('of') : null;
$view = ($_GET['view'] ?? '') === 'adressen' ? 'adressen' : 'mensen';

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "CONCAT_WS(' ', c.first_name, c.last_name, c.nickname, h.name, c.city, h.city, c.notes) LIKE ?";
    $params[] = '%' . $q . '%';
}
if ($rel === 'kids') {
    $where[] = 'c.is_child = 1';
} elseif ($rel !== '') {
    $where[] = 'c.relation = ?';
    $params[] = $rel;
}
if ($of) {
    $where[] = 'EXISTS (SELECT 1 FROM fp_contact_members cm WHERE cm.contact_id = c.id AND cm.member_id = ?)';
    $params[] = $of;
}
$stmt = db()->prepare('SELECT c.*, h.name AS household_name, h.city AS h_city FROM fp_contacts c LEFT JOIN fp_households h ON h.id = c.household_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.is_favorite DESC, c.first_name, c.last_name');
$stmt->execute($params);
$people = $stmt->fetchAll();
$counts = [];
foreach (db()->query('SELECT relation, COUNT(*) AS n FROM fp_contacts GROUP BY relation') as $r) {
    $counts[$r['relation']] = (int) $r['n'];
}
$kidsCount = (int) db()->query('SELECT COUNT(*) FROM fp_contacts WHERE is_child = 1')->fetchColumn();
$friendOf = [];
foreach (db()->query('SELECT contact_id, member_id FROM fp_contact_members') as $r) {
    $friendOf[$r['contact_id']][] = (int) $r['member_id'];
}

$url = function (array $change) use ($q, $rel, $of, $view) {
    $p = array_filter(array_merge(['q' => $q, 'rel' => $rel, 'of' => $of, 'view' => $view === 'mensen' ? '' : $view], $change), function ($v) {
        return $v !== '' && $v !== null;
    });
    return 'mensen.php' . ($p ? '?' . http_build_query($p) : '');
};

page_start('Adresboek');
page_header('📇 Adresboek', count($people) . ' ' . (count($people) === 1 ? 'persoon' : 'mensen') . ($q !== '' ? ' gevonden' : ''),
    '<a class="btn" href="contact.php?new=1">＋ Persoon</a><a class="btn secondary" href="huishouden.php?new=1">＋ Huishouden</a>');
?>
<form class="filters" method="get">
  <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="🔍 Zoek naam, familie, plaats…">
  <?php if ($rel): ?><input type="hidden" name="rel" value="<?= e($rel) ?>"><?php endif; ?>
  <?php if ($of): ?><input type="hidden" name="of" value="<?= (int) $of ?>"><?php endif; ?>
  <?php if ($view !== 'mensen'): ?><input type="hidden" name="view" value="<?= e($view) ?>"><?php endif; ?>
  <div class="tabs">
    <a href="<?= e($url(['view' => ''])) ?>" class="<?= $view === 'mensen' ? 'on' : '' ?>">👤 Mensen</a>
    <a href="<?= e($url(['view' => 'adressen'])) ?>" class="<?= $view === 'adressen' ? 'on' : '' ?>">🏠 Adressen</a>
  </div>
  <select name="of" onchange="this.form.submit()" style="width:auto" aria-label="Van wie">
    <option value="">Van iedereen</option>
    <?php foreach (members() as $m): ?><option value="<?= (int) $m['id'] ?>"<?= $of === (int) $m['id'] ? ' selected' : '' ?>>van <?= e($m['name']) ?></option><?php endforeach; ?>
  </select>
</form>
<div class="picker" style="margin-bottom:16px">
  <a class="chip" style="--c:<?= $rel === '' ? 'var(--accent)' : '#999' ?>" href="<?= e($url(['rel' => ''])) ?>">Iedereen</a>
  <a class="chip" style="--c:<?= $rel === 'kids' ? 'var(--accent)' : '#999' ?>" href="<?= e($url(['rel' => 'kids'])) ?>">🧒 Kinderen (<?= $kidsCount ?>)</a>
  <?php foreach (RELATIONS as $k => [$label, $emoji]): if (empty($counts[$k])) continue; ?>
    <a class="chip" style="--c:<?= $rel === $k ? 'var(--accent)' : '#999' ?>" href="<?= e($url(['rel' => $k])) ?>"><?= $emoji ?> <?= e($label) ?> (<?= $counts[$k] ?>)</a>
  <?php endforeach; ?>
</div>

<?php if (!$people): ?>
  <div class="card"><?= empty_state('📇', $q !== '' ? 'Niemand gevonden voor “' . e($q) . '”.' : 'Het adresboek is nog leeg.', '<a class="btn" href="contact.php?new=1">＋ Eerste persoon toevoegen</a>') ?></div>
<?php elseif ($view === 'mensen'): ?>
  <div class="faces">
    <?php foreach ($people as $p): ?>
      <a class="face" href="contact.php?id=<?= (int) $p['id'] ?>">
        <?= avatar($p, 72) ?>
        <b><?= e(contact_name($p)) ?><?= $p['is_favorite'] ? ' ⭐' : '' ?></b>
        <small><?= RELATIONS[$p['relation']][1] ?? '' ?> <?= e($p['household_name'] ?: (RELATIONS[$p['relation']][0] ?? '')) ?></small>
        <?php if (!empty($friendOf[$p['id']])): ?>
          <small><?php foreach ($friendOf[$p['id']] as $mid): if ($m = member($mid)): ?><span class="dot" style="--c:<?= e($m['color']) ?>" title="<?= e($m['name']) ?>"></span> <?php endif; endforeach; ?></small>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php else:
    // Group by household; people without one get their own row
    $groups = [];
    foreach ($people as $p) {
        $key = $p['household_id'] ? 'h' . $p['household_id'] : 'c' . $p['id'];
        $groups[$key][] = $p;
    }
    $hh = [];
    foreach (db()->query('SELECT * FROM fp_households') as $h) {
        $hh[$h['id']] = $h;
    }
    uasort($groups, function ($a, $b) use ($hh) {
        $na = $a[0]['household_id'] ? $hh[$a[0]['household_id']]['name'] : contact_name($a[0]);
        $nb = $b[0]['household_id'] ? $hh[$b[0]['household_id']]['name'] : contact_name($b[0]);
        return strcasecmp($na, $nb);
    });
?>
  <p class="no-print"><button class="btn secondary small" onclick="window.print()">🖨 Adressenlijst printen</button> <span class="muted small">Handig voor verjaardagskaarten, kerstkaarten of de uitnodigingen van een kinderfeestje.</span></p>
  <div class="card">
    <div class="table-wrap">
      <table class="data">
        <tr><th>Wie</th><th>Adres</th><th>Telefoon</th><th>E-mail</th></tr>
        <?php foreach ($groups as $list):
            $first = $list[0];
            $h = $first['household_id'] ? $hh[$first['household_id']] : null;
            $street = $h ? $h['street'] : $first['street'];
            $pc = trim(($h ? $h['postal_code'] . ' ' . $h['city'] : $first['postal_code'] . ' ' . $first['city'])); ?>
          <tr>
            <td>
              <?php if ($h): ?><a href="huishouden.php?id=<?= (int) $h['id'] ?>"><b><?= e($h['name']) ?></b></a><br><?php endif; ?>
              <span class="avatars"><?php foreach ($list as $p): ?><a href="contact.php?id=<?= (int) $p['id'] ?>"><?= avatar($p, 26) ?></a><?php endforeach; ?></span>
              <span class="muted small"><?= e(implode(', ', array_map(function ($p) { return contact_name($p, false); }, $list))) ?></span>
            </td>
            <td><?= e($street) ?><?= $street && $pc ? '<br>' : '' ?><?= e($pc) ?></td>
            <td class="nowrap"><?php $ph = array_filter(array_merge([$h['phone'] ?? null], array_column($list, 'phone'))); foreach (array_unique($ph) as $x): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $x)) ?>"><?= e($x) ?></a><br><?php endforeach; ?></td>
            <td><?php $em = array_filter(array_merge([$h['email'] ?? null], array_column($list, 'email'))); foreach (array_unique($em) as $x): ?><a href="mailto:<?= e($x) ?>"><?= e($x) ?></a><br><?php endforeach; ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php page_end();
