<?php
// Beheer of the current square. Site admins (users.role = ADMIN) can also create squares.
require __DIR__ . '/lib/app.php';

$user = require_login();
$school = current_school();
if (!is_school_admin($school)) {
    redirect('./');
}
$schoolId = (int) $school['id'];
$error = null;

if (is_post()) {
    $action = post('action');
    $pdo = db();

    if ($action === 'approve' || $action === 'reject') {
        $activityId = (int) post('activity_id');
        if ($action === 'approve') {
            $pdo->prepare("UPDATE activities SET status = 'PUBLISHED' WHERE id = ? AND school_id = ?")->execute([$activityId, $schoolId]);
            flash('Activiteit goedgekeurd.');
        } else {
            $pdo->prepare("DELETE FROM activities WHERE id = ? AND school_id = ? AND status = 'PENDING'")->execute([$activityId, $schoolId]);
            flash('Activiteit afgewezen en verwijderd.');
        }
        redirect('beheer.php');
    } elseif ($action === 'settings') {
        $name = post('name');
        $activityMode = post('activity_mode');
        $registrationMode = post('registration_mode');
        $code = post('join_code');
        $stmt = $pdo->prepare('SELECT 1 FROM schools WHERE join_code = ? AND id <> ?');
        $stmt->execute([$code, $schoolId]);

        if ($name === '') {
            $error = 'Vul een naam in.';
        } elseif (!isset(ACTIVITY_MODES[$activityMode]) || !in_array($registrationMode, ['OPEN', 'CODE'], true)) {
            $error = 'Maak een keuze.';
        } elseif ($registrationMode === 'CODE' && strlen($code) < 4) {
            $error = 'Kies een schoolcode van minimaal 4 tekens.';
        } elseif ($code !== '' && $stmt->fetchColumn()) {
            $error = 'Deze schoolcode wordt al door een ander schoolplein gebruikt.';
        } else {
            $pdo->prepare('UPDATE schools SET name = ?, activity_mode = ?, registration_mode = ?, join_code = ? WHERE id = ?')
                ->execute([limit_text($name, 150), $activityMode, $registrationMode, $code !== '' ? limit_text($code, 40) : null, $schoolId]);
            flash('Instellingen opgeslagen.');
            redirect('beheer.php');
        }
    } elseif ($action === 'member_role' || $action === 'member_remove') {
        $memberId = (int) post('user_id');
        if ($memberId === (int) $user['id']) {
            $error = 'Je kunt je eigen rol hier niet aanpassen.';
        } elseif ($action === 'member_role') {
            $role = post('role') === 'ADMIN' ? 'ADMIN' : 'MEMBER';
            $pdo->prepare('UPDATE school_memberships SET role = ? WHERE school_id = ? AND user_id = ?')->execute([$role, $schoolId, $memberId]);
            flash('Rol aangepast.');
            redirect('beheer.php#leden');
        } else {
            $pdo->prepare('DELETE FROM school_memberships WHERE school_id = ? AND user_id = ?')->execute([$schoolId, $memberId]);
            flash('Lid verwijderd van dit schoolplein.');
            redirect('beheer.php#leden');
        }
    } elseif ($action === 'new_school' && is_site_admin()) {
        $name = post('new_name');
        if ($name === '') {
            $error = 'Vul een naam in voor het nieuwe schoolplein.';
        } else {
            $pdo->prepare('INSERT INTO schools (name) VALUES (?)')->execute([limit_text($name, 150)]);
            $_SESSION['school_id'] = (int) $pdo->lastInsertId();
            flash('Schoolplein "' . $name . '" aangemaakt. Je beheert nu dit schoolplein.');
            redirect('beheer.php');
        }
    }
}

$stmt = db()->prepare("SELECT a.id, a.title, a.start_at, a.end_at, u.name AS creator_name FROM activities a LEFT JOIN users u ON u.id = a.created_by WHERE a.school_id = ? AND a.status = 'PENDING' ORDER BY a.start_at");
$stmt->execute([$schoolId]);
$pending = $stmt->fetchAll();

$stmt = db()->prepare('SELECT u.id, u.name, u.email, u.role AS site_role, m.role, m.created_at,
        (SELECT COUNT(*) FROM children c WHERE c.user_id = u.id) AS kids
    FROM school_memberships m JOIN users u ON u.id = m.user_id
    WHERE m.school_id = ? ORDER BY m.role, u.name');
$stmt->execute([$schoolId]);
$members = $stmt->fetchAll();

page_start('Beheer', ['narrow' => true]);
?>
<p class="back"><a href="./">← Terug naar het plein</a></p>
<section class="hero"><h1>Beheer</h1><p><?= e($school['name']) ?></p></section>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<article class="card">
  <h2>Wachten op goedkeuring</h2>
  <?php if (!$pending): ?>
    <p class="hint">Er wachten geen activiteiten.</p>
  <?php endif; ?>
  <?php foreach ($pending as $a): ?>
    <div class="list-row">
      <span><a href="activiteit.php?id=<?= (int) $a['id'] ?>"><b><?= e($a['title']) ?></b></a><br><small><?= e(format_when($a['start_at'], $a['end_at'])) ?> · door <?= e($a['creator_name'] ?: 'onbekend') ?></small></span>
      <form method="post" class="buttons">
        <?= csrf_field() ?><input type="hidden" name="activity_id" value="<?= (int) $a['id'] ?>">
        <button class="btn" name="action" value="approve">Goedkeuren</button>
        <button class="btn secondary" name="action" value="reject" onclick="return confirm('Activiteit afwijzen en verwijderen?')">Afwijzen</button>
      </form>
    </div>
  <?php endforeach; ?>
</article>

<article class="card">
  <h2>Instellingen</h2>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label for="name">Naam van het schoolplein</label>
    <input id="name" name="name" value="<?= e($school['name']) ?>" maxlength="150" required>

    <label>Wie mag activiteiten plaatsen?</label>
    <?php foreach (ACTIVITY_MODES as $key => $label): ?>
      <label class="check"><input type="radio" name="activity_mode" value="<?= $key ?>"<?= $school['activity_mode'] === $key ? ' checked' : '' ?>> <?= e($label) ?></label>
    <?php endforeach; ?>

    <label>Wie mag zich aanmelden?</label>
    <label class="check"><input type="radio" name="registration_mode" value="OPEN"<?= $school['registration_mode'] === 'OPEN' ? ' checked' : '' ?>> Iedereen (handig om te testen)</label>
    <label class="check"><input type="radio" name="registration_mode" value="CODE"<?= $school['registration_mode'] === 'CODE' ? ' checked' : '' ?>> Alleen met de schoolcode</label>

    <label for="join_code">Schoolcode <small>(deel deze bijvoorbeeld in de nieuwsbrief)</small></label>
    <input id="join_code" name="join_code" value="<?= e($school['join_code']) ?>" maxlength="40" autocomplete="off">

    <button class="btn" name="action" value="settings">Opslaan</button>
  </form>
</article>

<article class="card" id="leden">
  <h2>Leden (<?= count($members) ?>)</h2>
  <?php foreach ($members as $m): ?>
    <div class="list-row">
      <span>
        <b><?= e($m['name']) ?></b>
        <?php if ($m['role'] === 'ADMIN' || $m['site_role'] === 'ADMIN'): ?><span class="tag">beheerder</span><?php endif; ?>
        <br><small><?= e($m['email']) ?> · <?= (int) $m['kids'] ?> <?= (int) $m['kids'] === 1 ? 'kind' : 'kinderen' ?></small>
      </span>
      <?php if ((int) $m['id'] !== (int) $user['id']): ?>
        <form method="post" class="buttons">
          <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
          <?php if ($m['role'] === 'ADMIN'): ?>
            <input type="hidden" name="role" value="MEMBER"><button class="link" name="action" value="member_role">geen beheerder meer</button>
          <?php else: ?>
            <input type="hidden" name="role" value="ADMIN"><button class="link" name="action" value="member_role">maak beheerder</button>
          <?php endif; ?>
          <button class="link" name="action" value="member_remove" onclick="return confirm(<?= e(json_encode($m['name'] . ' van dit schoolplein verwijderen?')) ?>)">verwijderen</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</article>

<?php if (is_site_admin()): ?>
<article class="card">
  <h2>Nieuw schoolplein</h2>
  <p class="hint">Als hoofdbeheerder kun je meerdere schoolpleinen beheren. Wissel bovenaan tussen pleinen.</p>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label for="new_name">Naam</label><input id="new_name" name="new_name" maxlength="150">
    <button class="btn secondary" name="action" value="new_school">Schoolplein aanmaken</button>
  </form>
  <p class="hint"><a href="dbtest.php">Databasestatus bekijken →</a></p>
</article>
<?php endif; ?>
<?php page_end();
