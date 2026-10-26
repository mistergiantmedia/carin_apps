<?php
// Class lists: parents and children per klas. Parents only see classes where they have a child;
// beheerders of the square see every class. Parents see birthdays without the year.
require __DIR__ . '/lib/app.php';

$user = require_login();
$school = current_school();
if (!$school) {
    redirect('./');
}
$isAdmin = is_school_admin($school);

if ($isAdmin) {
    $stmt = db()->prepare('SELECT id, name FROM classes WHERE school_id = ? ORDER BY name');
    $stmt->execute([$school['id']]);
} else {
    $stmt = db()->prepare('SELECT DISTINCT k.id, k.name FROM classes k JOIN children c ON c.class_id = k.id WHERE k.school_id = ? AND c.user_id = ? ORDER BY k.name');
    $stmt->execute([$school['id'], $user['id']]);
}
$classes = array_column($stmt->fetchAll(), null, 'id');

$selectedId = (int) ($_GET['klas'] ?? 0);
if (!isset($classes[$selectedId])) {
    $selectedId = (int) array_key_first_compat($classes);
}

$children = [];
$parents = [];
if ($selectedId) {
    $stmt = db()->prepare('SELECT c.first_name, c.last_name, c.address, c.birth_date, u.id AS parent_id, u.name AS parent_name
        FROM children c JOIN users u ON u.id = c.user_id
        WHERE c.class_id = ? ORDER BY c.last_name, c.first_name');
    $stmt->execute([$selectedId]);
    $children = $stmt->fetchAll();
    foreach ($children as $c) {
        $parents[$c['parent_id']] = $c['parent_name'];
    }
    asort($parents);
}

function birthday_text(?string $date, bool $withYear): string
{
    if (!$date) {
        return '';
    }
    $months = ['', 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    $t = strtotime($date);
    return date('j', $t) . ' ' . $months[(int) date('n', $t)] . ($withYear ? ' ' . date('Y', $t) : '');
}

page_start('Klassen', ['narrow' => true]);
?>
<p class="back"><a href="./">← Terug naar het plein</a></p>
<section class="hero"><h1>Klassen</h1><p><?= e($school['name']) ?></p></section>

<?php if (!$classes): ?>
  <article class="card">
    <p><?= $isAdmin ? 'Er zijn nog geen klassen. Maak ze aan bij <a href="beheer.php#klassen">Beheer</a>.' : 'Je kind zit nog in geen enkele klas. Kies de klas bij je kind in <a href="profiel.php#kinderen">je profiel</a>.' ?></p>
  </article>
<?php else: ?>
  <nav class="tabs">
    <?php foreach ($classes as $k): ?>
      <a href="?klas=<?= (int) $k['id'] ?>"<?= (int) $k['id'] === $selectedId ? ' class="active"' : '' ?>><?= e($k['name']) ?></a>
    <?php endforeach; ?>
  </nav>

  <article class="card">
    <h2>Kinderen (<?= count($children) ?>)</h2>
    <p class="hint">Deze gegevens zijn alleen zichtbaar voor de ouders van deze klas en de beheerders. Deel ze niet buiten de school.</p>
    <?php foreach ($children as $c): ?>
      <div class="list-row">
        <span>
          <b><?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?></b>
          <?php if ($c['birth_date']): ?><small>· jarig op <?= e(birthday_text($c['birth_date'], $isAdmin)) ?></small><?php endif; ?>
          <br><small>Ouder: <?= e($c['parent_name']) ?><?= $c['address'] ? ' · ' . e($c['address']) : '' ?></small>
        </span>
      </div>
    <?php endforeach; ?>
  </article>

  <article class="card">
    <h2>Ouders (<?= count($parents) ?>)</h2>
    <?php foreach ($parents as $name): ?>
      <div class="list-row"><span><?= e($name) ?></span></div>
    <?php endforeach; ?>
  </article>
<?php endif; ?>
<?php page_end();
