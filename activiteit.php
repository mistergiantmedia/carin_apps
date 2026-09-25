<?php
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/chat.php';

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
    } elseif ($action === 'message' && $activity['status'] === 'PUBLISHED') {
        $body = limit_text(post('body'), 2000);
        $image = null;
        try {
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $image = store_uploaded_image($_FILES['image']);
            }
            if ($body !== '' || $image) {
                $pdo->prepare('INSERT INTO activity_messages (activity_id, user_id, body, image_file) VALUES (?, ?, ?, ?)')->execute([$id, $user['id'], $body, $image]);
            }
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect($self . '#gesprek');
    } elseif ($action === 'delete_message') {
        // Own message, or any message for a beheerder
        $where = 'id = ? AND activity_id = ?' . ($isAdmin ? '' : ' AND user_id = ?');
        $params = $isAdmin ? [(int) post('message_id'), $id] : [(int) post('message_id'), $id, $user['id']];
        delete_message_images($where, $params);
        $pdo->prepare("DELETE FROM activity_messages WHERE $where")->execute($params);
        redirect($self . '#gesprek');
    } elseif ($action === 'react' && in_array(post('emoji'), REACTIONS, true)) {
        // Toggle your own reaction; the page script asks for just the new chips (ajax=1)
        $messageId = (int) post('message_id');
        $stmt = $pdo->prepare('SELECT 1 FROM activity_messages WHERE id = ? AND activity_id = ?');
        $stmt->execute([$messageId, $id]);
        if ($stmt->fetchColumn()) {
            $params = [$messageId, $user['id'], post('emoji')];
            $deleted = $pdo->prepare('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?');
            $deleted->execute($params);
            if (!$deleted->rowCount()) {
                $pdo->prepare('INSERT INTO message_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)')->execute($params);
            }
        }
        if (post('ajax') === '1') {
            echo render_reactions($messageId, load_reactions([$messageId], (int) $user['id'])[$messageId] ?? []);
            exit;
        }
        redirect($self . '#m' . $messageId);
    } elseif ($action === 'approve' && $isAdmin) {
        $pdo->prepare("UPDATE activities SET status = 'PUBLISHED' WHERE id = ?")->execute([$id]);
        flash('Activiteit goedgekeurd en zichtbaar op het plein.');
        redirect($self);
    } elseif ($action === 'delete' && $canManage) {
        delete_message_images('activity_id = ?', [$id]);
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

$stmt = db()->prepare('SELECT m.id, m.body, m.image_file, m.created_at, m.user_id, u.name FROM activity_messages m JOIN users u ON u.id = m.user_id WHERE m.activity_id = ? ORDER BY m.created_at, m.id');
$stmt->execute([$id]);
$messages = $stmt->fetchAll();
$reactions = load_reactions(array_column($messages, 'id'), (int) $user['id']);

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

    <article class="card chat-card" id="gesprek">
      <h2>💬 Gesprek</h2>
      <p class="hint">Vragen, vervoer en praktische afspraken over deze activiteit.</p>
      <div class="chat" id="chat">
        <?php if (!$messages): ?>
          <div class="day"><span>Nog geen berichten. Stel gerust een vraag!</span></div>
        <?php endif; ?>
        <?php $prevDay = null; $prevUser = null; ?>
        <?php foreach ($messages as $m): ?>
          <?php
          $day = format_day($m['created_at']);
          if ($day !== $prevDay) {
              echo '<div class="day"><span>' . e($day) . '</span></div>';
              $prevDay = $day;
              $prevUser = null;
          }
          $mine = (int) $m['user_id'] === (int) $user['id'];
          $first = $prevUser !== (int) $m['user_id'];
          $prevUser = (int) $m['user_id'];
          $color = avatar_color((int) $m['user_id']);
          ?>
          <div class="msg<?= $mine ? ' mine' : '' ?><?= $first ? ' first' : '' ?>" id="m<?= (int) $m['id'] ?>">
            <?php if (!$mine): ?>
              <div class="msg-avatar<?= $first ? '' : ' blank' ?>" style="background:<?= $color ?>"><?= $first ? e(initial($m['name'])) : '' ?></div>
            <?php endif; ?>
            <div class="msg-body">
              <div class="bubble">
                <?php if (!$mine && $first): ?><div class="msg-name" style="color:<?= $color ?>"><?= e($m['name']) ?></div><?php endif; ?>
                <?php if ($m['image_file']): ?>
                  <a class="msg-image" href="afbeelding.php?id=<?= (int) $m['id'] ?>" target="_blank"><img src="afbeelding.php?id=<?= (int) $m['id'] ?>" loading="lazy" alt="Afbeelding van <?= e($m['name']) ?>"></a>
                <?php endif; ?>
                <?php if ($m['body'] !== ''): ?><span class="msg-text"><?= nl2br(e($m['body'])) ?></span><?php endif; ?>
                <span class="msg-time"><?= date('H:i', strtotime($m['created_at'])) ?></span>
                <?php if ($isAdmin || $mine): ?>
                  <form method="post" class="msg-delete" onsubmit="return confirm('Bericht verwijderen?')">
                    <?= csrf_field() ?><input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>">
                    <button name="action" value="delete_message" title="Bericht verwijderen" aria-label="Bericht verwijderen">🗑</button>
                  </form>
                <?php endif; ?>
              </div>
              <form method="post" class="react-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="react">
                <input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>">
                <div class="reactions"><?= render_reactions((int) $m['id'], $reactions[$m['id']] ?? []) ?></div>
                <button type="button" class="react-open" title="Reageer met een emoji" aria-label="Reageer met een emoji">☺+</button>
                <div class="picker" hidden>
                  <?php foreach (REACTIONS as $emoji): ?><button name="emoji" value="<?= e($emoji) ?>"><?= e($emoji) ?></button><?php endforeach; ?>
                </div>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($activity['status'] === 'PUBLISHED'): ?>
        <form method="post" enctype="multipart/form-data" class="composer" id="composer">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="message">
          <div class="composer-preview" hidden><img alt="Voorbeeld"><button type="button" class="preview-remove" aria-label="Afbeelding weghalen">×</button></div>
          <div class="composer-row">
            <label class="attach" title="Afbeelding toevoegen"><span aria-hidden="true">📎</span><input type="file" name="image" accept="image/*"></label>
            <textarea name="body" rows="1" maxlength="2000" placeholder="Bericht…"></textarea>
            <button class="send" title="Versturen" aria-label="Versturen">➤</button>
          </div>
          <p class="hint composer-hint">Enter = versturen · Shift+Enter = nieuwe regel · plak een afbeelding met Ctrl+V of sleep hem hierheen</p>
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
<script src="gesprek.js?v=1"></script>
<?php page_end();
