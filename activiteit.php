<?php
require __DIR__ . '/lib/app.php';

$user = require_login();
$id = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT a.*, u.name AS creator_name FROM activities a LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ?');
$stmt->execute([$id]);
$activity = $stmt->fetch();

$schools = user_schools();
if (!$activity || !isset($schools[$activity['school_id']])) {
    http_response_code(404);
    page_start('Niet gevonden', ['narrow' => true]);
    echo '<article class="card"><h2>Activiteit niet gevonden</h2><p><a href="./">← Terug naar het plein</a></p></article>';
    page_end();
    exit;
}

$_SESSION['school_id'] = (int) $activity['school_id'];
$school = $schools[$activity['school_id']];
$isAdmin = is_school_admin($school);
$isOwner = (int) $activity['created_by'] === (int) $user['id'];
$canManage = $isAdmin || $isOwner;
$isPast = strtotime($activity['end_at'] ?: $activity['start_at']) < time();
$self = 'activiteit.php?id=' . $id;

if ($activity['status'] !== 'PUBLISHED' && !$canManage) {
    redirect('./');
}

// Children of this user, and which of them already come along
$stmt = db()->prepare('SELECT id, first_name, group_name FROM children WHERE user_id = ? ORDER BY first_name');
$stmt->execute([$user['id']]);
$myChildren = $stmt->fetchAll();

$stmt = db()->prepare('SELECT 1 FROM activity_participants WHERE activity_id = ? AND user_id = ?');
$stmt->execute([$id, $user['id']]);
$joined = (bool) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT pc.child_id FROM participant_children pc JOIN children c ON c.id = pc.child_id WHERE pc.activity_id = ? AND c.user_id = ?');
$stmt->execute([$id, $user['id']]);
$myJoinedChildIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$error = null;

if (is_post()) {
    $action = post('action');
    $pdo = db();

    if ($action === 'join' && $activity['status'] === 'PUBLISHED' && !$isPast) {
        $myIds = array_map('intval', array_column($myChildren, 'id'));
        $chosen = array_values(array_intersect($myIds, array_map('intval', (array) ($_POST['children'] ?? []))));

        if ($activity['max_children'] !== null) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM participant_children pc JOIN children c ON c.id = pc.child_id WHERE pc.activity_id = ? AND c.user_id <> ?');
            $stmt->execute([$id, $user['id']]);
            $free = (int) $activity['max_children'] - (int) $stmt->fetchColumn();
            if (count($chosen) > $free) {
                $error = $free > 0 ? "Er is nog plek voor $free " . ($free === 1 ? 'kind' : 'kinderen') . '.' : 'Deze activiteit zit vol.';
            }
        }

        if (!$error) {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT IGNORE INTO activity_participants (activity_id, user_id) VALUES (?, ?)')->execute([$id, $user['id']]);
            $pdo->prepare('DELETE pc FROM participant_children pc JOIN children c ON c.id = pc.child_id WHERE pc.activity_id = ? AND c.user_id = ?')->execute([$id, $user['id']]);
            $insert = $pdo->prepare('INSERT INTO participant_children (activity_id, child_id) VALUES (?, ?)');
            foreach ($chosen as $childId) {
                $insert->execute([$id, $childId]);
            }
            $pdo->commit();
            flash($joined ? 'Aangepast.' : 'Leuk, jullie doen mee!');
            redirect($self . '#meedoen');
        }
    } elseif ($action === 'leave') {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM activity_participants WHERE activity_id = ? AND user_id = ?')->execute([$id, $user['id']]);
        $pdo->prepare('DELETE pc FROM participant_children pc JOIN children c ON c.id = pc.child_id WHERE pc.activity_id = ? AND c.user_id = ?')->execute([$id, $user['id']]);
        $pdo->commit();
        flash('Jullie doen niet meer mee.');
        redirect($self . '#meedoen');
    } elseif ($action === 'message') {
        $body = limit_text(post('body'), 2000);
        if ($body !== '') {
            $pdo->prepare('INSERT INTO activity_messages (activity_id, user_id, body) VALUES (?, ?, ?)')->execute([$id, $user['id'], $body]);
        }
        redirect($self . '#gesprek');
    } elseif ($action === 'delete_message') {
        // Own message, or any message for a beheerder
        $sql = 'DELETE FROM activity_messages WHERE id = ? AND activity_id = ?' . ($isAdmin ? '' : ' AND user_id = ?');
        $params = $isAdmin ? [(int) post('message_id'), $id] : [(int) post('message_id'), $id, $user['id']];
        $pdo->prepare($sql)->execute($params);
        redirect($self . '#gesprek');
    } elseif ($action === 'approve' && $isAdmin) {
        $pdo->prepare("UPDATE activities SET status = 'PUBLISHED' WHERE id = ?")->execute([$id]);
        flash('Activiteit goedgekeurd en zichtbaar op het plein.');
        redirect($self);
    } elseif ($action === 'delete' && $canManage) {
        $pdo->prepare('DELETE FROM activities WHERE id = ?')->execute([$id]);
        flash('Activiteit verwijderd.');
        redirect('./');
    }
}

// Participants: everyone sees totals; organiser and beheerders see who
$stmt = db()->prepare('SELECT u.id, u.name,
        GROUP_CONCAT(c.first_name ORDER BY c.first_name SEPARATOR \', \') AS kids, COUNT(c.id) AS kid_count
    FROM activity_participants p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN children c ON c.user_id = u.id AND c.id IN (SELECT child_id FROM participant_children WHERE activity_id = p.activity_id)
    WHERE p.activity_id = ?
    GROUP BY u.id, u.name ORDER BY MIN(p.created_at)');
$stmt->execute([$id]);
$participants = $stmt->fetchAll();
$totalKids = array_sum(array_column($participants, 'kid_count'));

$stmt = db()->prepare('SELECT m.id, m.body, m.created_at, m.user_id, u.name FROM activity_messages m JOIN users u ON u.id = m.user_id WHERE m.activity_id = ? ORDER BY m.created_at, m.id');
$stmt->execute([$id]);
$messages = $stmt->fetchAll();

page_start($activity['title']);
?>
<p class="back"><a href="./">← Terug naar het plein</a></p>

<?php if ($activity['status'] === 'PENDING'): ?>
  <div class="flash warn">Deze activiteit wacht op goedkeuring van een beheerder en is nog niet zichtbaar op het plein.</div>
<?php endif; ?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<section class="grid">
  <div>
    <article class="card">
      <div class="stripe <?= CATEGORIES[$activity['category']]['color'] ?>"></div>
      <h1 class="title"><?= e($activity['title']) ?></h1>
      <div class="meta"><?= e(format_when($activity['start_at'], $activity['end_at'])) ?><br><?= e($activity['location']) ?></div>
      <div class="badges">
        <span><?= e(CATEGORIES[$activity['category']]['label']) ?></span>
        <?php if ($activity['price_description']): ?><span><?= e($activity['price_description']) ?></span><?php endif; ?>
        <span><?= e(people_label(count($participants), (int) $totalKids)) ?></span>
        <?php if ($activity['max_children'] !== null): ?><span>Max. <?= (int) $activity['max_children'] ?> kinderen</span><?php endif; ?>
        <?php if ($activity['needs_volunteers']): ?><span class="help">🤝 Hulp gezocht</span><?php endif; ?>
      </div>
      <?php if ($activity['description']): ?><p class="description"><?= nl2br(e($activity['description'])) ?></p><?php endif; ?>
      <p class="hint">Georganiseerd door <?= e($activity['creator_name'] ?: 'onbekend') ?></p>

      <?php if ($canManage): ?>
        <div class="actions">
          <?php if ($isAdmin && $activity['status'] === 'PENDING'): ?>
            <form method="post"><?= csrf_field() ?><button class="btn" name="action" value="approve">✓ Goedkeuren</button></form>
          <?php endif; ?>
          <a class="btn secondary" href="activiteit-bewerken.php?id=<?= $id ?>">✎ Bewerken</a>
          <form method="post" onsubmit="return confirm('Weet je zeker dat je deze activiteit wilt verwijderen? Aanmeldingen en het gesprek verdwijnen ook.')">
            <?= csrf_field() ?><button class="btn danger" name="action" value="delete">Verwijderen</button>
          </form>
        </div>
      <?php endif; ?>
    </article>

    <article class="card" id="gesprek">
      <h2>💬 Gesprek</h2>
      <p class="hint">Vragen, vervoer en praktische afspraken over deze activiteit.</p>
      <?php foreach ($messages as $m): ?>
        <div class="message">
          <div class="message-head">
            <b><?= e($m['name']) ?></b> <small><?= e(format_ago($m['created_at'])) ?></small>
            <?php if ($isAdmin || (int) $m['user_id'] === (int) $user['id']): ?>
              <form method="post" class="inline" onsubmit="return confirm('Bericht verwijderen?')">
                <?= csrf_field() ?><input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>">
                <button class="link" name="action" value="delete_message">verwijderen</button>
              </form>
            <?php endif; ?>
          </div>
          <p><?= nl2br(e($m['body'])) ?></p>
        </div>
      <?php endforeach; ?>
      <?php if ($activity['status'] === 'PUBLISHED'): ?>
        <form method="post" class="form">
          <?= csrf_field() ?>
          <textarea name="body" rows="3" maxlength="2000" placeholder="Schrijf een bericht…" required></textarea>
          <button class="btn" name="action" value="message">Versturen</button>
        </form>
      <?php endif; ?>
    </article>
  </div>

  <aside>
    <div class="card side" id="meedoen">
      <h3>Meedoen</h3>
      <?php if ($activity['status'] !== 'PUBLISHED'): ?>
        <p><small>Aanmelden kan zodra de activiteit is goedgekeurd.</small></p>
      <?php elseif ($isPast): ?>
        <p><small>Deze activiteit is al geweest.</small></p>
      <?php else: ?>
        <form method="post" class="form">
          <?= csrf_field() ?>
          <?php if ($myChildren): ?>
            <p><small>Wie gaat er mee?</small></p>
            <?php foreach ($myChildren as $c): ?>
              <label class="check">
                <input type="checkbox" name="children[]" value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $myJoinedChildIds, true) ? ' checked' : '' ?>>
                <?= e($c['first_name']) ?><?= $c['group_name'] ? ' <small>(' . e($c['group_name']) . ')</small>' : '' ?>
              </label>
            <?php endforeach; ?>
          <?php else: ?>
            <p><small>Je hebt nog geen kinderen toegevoegd. <a href="profiel.php#kinderen">Voeg ze toe</a> om aan te geven wie er meegaat. Je kunt ook zonder kinderen meedoen.</small></p>
          <?php endif; ?>
          <button class="btn <?= $joined ? 'joined' : '' ?>" name="action" value="join"><?= $joined ? '✓ Opslaan' : '＋ Wij doen mee' ?></button>
        </form>
        <?php if ($joined): ?>
          <form method="post"><?= csrf_field() ?><button class="link" name="action" value="leave">We doen toch niet mee</button></form>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card side">
      <h3>Wie doen er mee</h3>
      <p><b><?= e(people_label(count($participants), (int) $totalKids)) ?></b></p>
      <?php if ($canManage): ?>
        <?php foreach ($participants as $p): ?>
          <p><?= e($p['name']) ?><?php if ($p['kids']): ?><br><small>met <?= e($p['kids']) ?></small><?php endif; ?></p>
        <?php endforeach; ?>
        <?php if ($participants): ?><p><small>Alleen jij als organisator of beheerder ziet deze namen.</small></p><?php endif; ?>
      <?php endif; ?>
    </div>
  </aside>
</section>
<?php page_end();
