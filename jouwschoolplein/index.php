<?php
require __DIR__ . '/lib/app.php';

$user = require_login();
$school = current_school();

$tabs = [
    'plein' => '🏠 Het Plein',
    'agenda' => '📅 Agenda',
    'community' => '🧡 Community',
    'meehelpen' => '🤝 Meehelpen',
];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'plein';

$activities = [];
$myChildren = [];
$myActivities = [];
$pendingCount = 0;

if ($school) {
    $where = "a.school_id = ? AND a.status = 'PUBLISHED' AND COALESCE(a.end_at, a.start_at) >= ?";
    if ($tab === 'community') {
        $where .= " AND a.category = 'COMMUNITY'";
    } elseif ($tab === 'meehelpen') {
        $where .= ' AND a.needs_volunteers = 1';
    }
    $stmt = db()->prepare("SELECT a.*,
            (SELECT COUNT(*) FROM activity_participants p WHERE p.activity_id = a.id) AS families,
            (SELECT COUNT(*) FROM participant_children pc WHERE pc.activity_id = a.id) AS kids,
            (SELECT COUNT(*) FROM activity_messages m WHERE m.activity_id = a.id) AS messages,
            EXISTS(SELECT 1 FROM activity_participants p WHERE p.activity_id = a.id AND p.user_id = ?) AS joined
        FROM activities a WHERE $where ORDER BY a.start_at LIMIT 200");
    $stmt->execute([$user['id'], $school['id'], date('Y-m-d H:i:s')]);
    $activities = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT first_name, group_name FROM children WHERE user_id = ? ORDER BY first_name');
    $stmt->execute([$user['id']]);
    $myChildren = $stmt->fetchAll();

    $myActivities = array_filter($activities, function ($a) {
        return $a['joined'];
    });

    if (is_school_admin($school)) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM activities WHERE school_id = ? AND status = 'PENDING'");
        $stmt->execute([$school['id']]);
        $pendingCount = (int) $stmt->fetchColumn();
    }
}

page_start('Het Plein');
?>
<section class="hero"><h1>Wat gebeurt er op het plein?</h1><p>Ontdek wat gezinnen, kinderen en oud-leerlingen samen doen — van school tot buurt.</p></section>

<?php if (!$school): ?>
  <article class="card"><p>Je bent nog niet aangesloten bij een schoolplein.</p></article>
<?php else: ?>

<nav class="tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="?tab=<?= $key ?>"<?= $key === $tab ? ' class="active"' : '' ?>><?= $label ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'plein'): ?>
  <section class="principle"><strong>Iedereen hoort bij het plein.</strong><span>Activiteiten zijn standaard open voor de hele Schoolplein-community. Er zijn geen activiteiten per schoolgroep.</span></section>
<?php elseif ($tab === 'meehelpen'): ?>
  <section class="principle"><strong>Helpende handen gezocht.</strong><span>Bij deze activiteiten zoekt de organisator vrijwilligers.</span></section>
<?php endif; ?>

<?php if ($pendingCount): ?>
  <div class="flash warn"><?= $pendingCount ?> <?= $pendingCount === 1 ? 'activiteit wacht' : 'activiteiten wachten' ?> op goedkeuring. <a href="beheer.php">Bekijk in Beheer →</a></div>
<?php endif; ?>

<section class="grid">
  <div>
    <?php if (!$activities): ?>
      <article class="card empty">
        <h2>Nog niets gepland</h2>
        <p>Er staan hier nog geen activiteiten.<?= can_post_activity($school) ? ' Zet jij de eerste op het plein?' : '' ?></p>
      </article>

    <?php elseif ($tab === 'agenda'): ?>
      <?php $month = null; ?>
      <?php foreach ($activities as $a): ?>
        <?php if (format_month($a['start_at']) !== $month): $month = format_month($a['start_at']); ?>
          <h3 class="month"><?= e($month) ?></h3>
        <?php endif; ?>
        <a class="agenda-row" href="activiteit.php?id=<?= (int) $a['id'] ?>">
          <span class="dot <?= CATEGORIES[$a['category']]['color'] ?>"></span>
          <span class="agenda-when"><?= e(format_when($a['start_at'], $a['end_at'])) ?></span>
          <span class="agenda-title"><?= e($a['title']) ?></span>
          <?php if ($a['joined']): ?><span class="tag">✓ Wij doen mee</span><?php endif; ?>
        </a>
      <?php endforeach; ?>

    <?php else: ?>
      <?php foreach ($activities as $a): ?>
        <article class="card">
          <div class="stripe <?= CATEGORIES[$a['category']]['color'] ?>"></div>
          <h2><a href="activiteit.php?id=<?= (int) $a['id'] ?>"><?= e($a['title']) ?></a></h2>
          <div class="meta"><?= e(format_when($a['start_at'], $a['end_at'])) ?><br><?= e($a['location']) ?></div>
          <div class="badges">
            <?php if ($a['price_description']): ?><span><?= e($a['price_description']) ?></span><?php endif; ?>
            <span><?= e(people_label((int) $a['families'], (int) $a['kids'])) ?></span>
            <?php if ($a['needs_volunteers']): ?><span class="help">🤝 Hulp gezocht</span><?php endif; ?>
          </div>
          <?php if ($a['description']): ?><p><?= e(excerpt($a['description'], 220)) ?></p><?php endif; ?>
          <div class="actions">
            <a class="btn <?= $a['joined'] ? 'joined' : '' ?>" href="activiteit.php?id=<?= (int) $a['id'] ?>#meedoen"><?= $a['joined'] ? '✓ Wij doen mee' : '＋ Wij doen mee' ?></a>
            <a class="btn secondary" href="activiteit.php?id=<?= (int) $a['id'] ?>#gesprek">💬 Gesprek<?= $a['messages'] ? ' (' . (int) $a['messages'] . ')' : '' ?></a>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <aside>
    <div class="card side">
      <h3>Jouw gezin</h3>
      <?php if ($myChildren): ?>
        <?php foreach ($myChildren as $c): ?>
          <p><b><?= e($c['first_name']) ?></b><?php if ($c['group_name']): ?><br><small><?= e($c['group_name']) ?></small><?php endif; ?></p>
        <?php endforeach; ?>
      <?php else: ?>
        <p><small>Voeg je kinderen toe, dan kun je aangeven wie er meegaat.</small></p>
      <?php endif; ?>
      <a class="small-link" href="profiel.php#kinderen">Kinderen beheren →</a>
    </div>
    <?php if ($myActivities): ?>
      <div class="card side">
        <h3>Jullie doen mee</h3>
        <?php foreach ($myActivities as $a): ?>
          <p><a href="activiteit.php?id=<?= (int) $a['id'] ?>"><b><?= e($a['title']) ?></b></a><br><small><?= e(format_when($a['start_at'])) ?></small></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="card side">
      <h3>Op het plein 🧡</h3>
      <p><b>Iedereen</b><br><small>alle schoolgroepen, gezinnen en kinderen</small></p>
      <p><b>Oud-leerlingen</b><br><small>kinderen die al van school zijn, zolang ze binnen de afgesproken leeftijd vallen</small></p>
      <p><b>Geen WhatsApp nodig</b><br><small>praktische gesprekken blijven bij de activiteit</small></p>
    </div>
  </aside>
</section>

<?php if (can_post_activity($school)): ?>
  <a class="fab" href="activiteit-bewerken.php">＋ Activiteit</a>
<?php endif; ?>

<?php endif; ?>
<?php page_end();
