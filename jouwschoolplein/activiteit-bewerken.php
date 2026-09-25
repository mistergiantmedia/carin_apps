<?php
// Create a new activity, or edit one (?id=) as its organiser or a beheerder.
require __DIR__ . '/lib/app.php';

$user = require_login();
$school = current_school();
$id = (int) ($_GET['id'] ?? 0);
$activity = null;

if ($id) {
    $stmt = db()->prepare('SELECT * FROM activities WHERE id = ?');
    $stmt->execute([$id]);
    $activity = $stmt->fetch();
    $schools = user_schools();
    if (!$activity || !isset($schools[$activity['school_id']])) {
        redirect('./');
    }
    $school = $schools[$activity['school_id']];
    if ((int) $activity['created_by'] !== (int) $user['id'] && !is_school_admin($school)) {
        redirect('activiteit.php?id=' . $id);
    }
} elseif (!$school || !can_post_activity($school)) {
    flash('Op dit schoolplein kunnen alleen beheerders activiteiten plaatsen.', 'error');
    redirect('./');
}

$values = [
    'title' => $activity['title'] ?? '',
    'description' => $activity['description'] ?? '',
    'location' => $activity['location'] ?? '',
    'date' => $activity ? date('Y-m-d', strtotime($activity['start_at'])) : '',
    'start_time' => $activity ? date('H:i', strtotime($activity['start_at'])) : '',
    'end_time' => $activity && $activity['end_at'] ? date('H:i', strtotime($activity['end_at'])) : '',
    'category' => $activity['category'] ?? 'ACTIVITY',
    'price_description' => $activity['price_description'] ?? '',
    'max_children' => $activity['max_children'] ?? '',
    'needs_volunteers' => $activity['needs_volunteers'] ?? 0,
];
$error = null;

if (is_post()) {
    foreach ($values as $key => $unused) {
        $values[$key] = post($key);
    }
    $values['needs_volunteers'] = isset($_POST['needs_volunteers']) ? 1 : 0;

    // Strict parse: reject overflowing input such as 2026-02-31
    $parse = function (string $date, string $time) {
        $dt = DateTime::createFromFormat('Y-m-d H:i', "$date $time");
        return $dt && $dt->format('Y-m-d H:i') === "$date $time" ? $dt : null;
    };
    $start = $parse($values['date'], $values['start_time']);
    $end = $values['end_time'] !== '' ? $parse($values['date'], $values['end_time']) : null;

    if ($values['title'] === '') {
        $error = 'Geef de activiteit een titel.';
    } elseif ($values['location'] === '') {
        $error = 'Vul in waar het is.';
    } elseif (!$start) {
        $error = 'Vul een geldige datum en starttijd in.';
    } elseif ($values['end_time'] !== '' && (!$end || $end <= $start)) {
        $error = 'De eindtijd moet na de starttijd liggen.';
    } elseif (!isset(CATEGORIES[$values['category']])) {
        $error = 'Kies een soort activiteit.';
    } elseif ($values['max_children'] !== '' && !ctype_digit($values['max_children'])) {
        $error = 'Het maximum aantal kinderen moet een getal zijn (of laat het leeg).';
    }

    if (!$error) {
        $data = [
            limit_text($values['title'], 150),
            $values['description'] !== '' ? limit_text($values['description'], 5000) : null,
            limit_text($values['location'], 150),
            $start->format('Y-m-d H:i:s'),
            $end ? $end->format('Y-m-d H:i:s') : null,
            $values['category'],
            $values['needs_volunteers'],
            $values['price_description'] !== '' ? limit_text($values['price_description'], 150) : null,
            $values['max_children'] !== '' ? (int) $values['max_children'] : null,
        ];
        if ($activity) {
            db()->prepare('UPDATE activities SET title = ?, description = ?, location = ?, start_at = ?, end_at = ?, category = ?, needs_volunteers = ?, price_description = ?, max_children = ? WHERE id = ?')
                ->execute(array_merge($data, [$id]));
            flash('Activiteit bijgewerkt.');
        } else {
            $status = $school['activity_mode'] === 'APPROVAL' && !is_school_admin($school) ? 'PENDING' : 'PUBLISHED';
            db()->prepare('INSERT INTO activities (title, description, location, start_at, end_at, category, needs_volunteers, price_description, max_children, school_id, created_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($data, [$school['id'], $user['id'], $status]));
            $id = (int) db()->lastInsertId();
            flash($status === 'PENDING' ? 'Bedankt! Je activiteit is verstuurd en wordt zichtbaar zodra een beheerder hem goedkeurt.' : 'Je activiteit staat op het plein!');
        }
        redirect('activiteit.php?id=' . $id);
    }
}

page_start($activity ? 'Activiteit bewerken' : 'Nieuwe activiteit', ['narrow' => true]);
?>
<p class="back"><a href="<?= $activity ? 'activiteit.php?id=' . $id : './' ?>">← Terug</a></p>
<article class="card">
  <h1 class="title"><?= $activity ? 'Activiteit bewerken' : 'Nieuwe activiteit' ?></h1>
  <?php if (!$activity && $school['activity_mode'] === 'APPROVAL' && !is_school_admin($school)): ?>
    <p class="hint">Op dit schoolplein keurt een beheerder nieuwe activiteiten eerst goed.</p>
  <?php endif; ?>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="form">
    <?= csrf_field() ?>
    <label for="title">Titel <small>(een emoji ervoor maakt het vrolijk, bijv. 🏃 of 🎨)</small></label>
    <input id="title" name="title" value="<?= e($values['title']) ?>" maxlength="150" required>

    <label>Soort</label>
    <div class="choice">
      <?php foreach (CATEGORIES as $key => $cat): ?>
        <label class="check"><input type="radio" name="category" value="<?= $key ?>"<?= $values['category'] === $key ? ' checked' : '' ?>> <span class="dot <?= $cat['color'] ?>"></span> <?= e($cat['label']) ?></label>
      <?php endforeach; ?>
    </div>

    <div class="row">
      <div><label for="date">Datum</label><input id="date" name="date" type="date" value="<?= e($values['date']) ?>" required></div>
      <div><label for="start_time">Begintijd</label><input id="start_time" name="start_time" type="time" value="<?= e($values['start_time']) ?>" required></div>
      <div><label for="end_time">Eindtijd <small>(optioneel)</small></label><input id="end_time" name="end_time" type="time" value="<?= e($values['end_time']) ?>"></div>
    </div>

    <label for="location">Waar</label>
    <input id="location" name="location" value="<?= e($values['location']) ?>" maxlength="150" required>

    <label for="description">Omschrijving</label>
    <textarea id="description" name="description" rows="5" maxlength="5000"><?= e($values['description']) ?></textarea>

    <div class="row">
      <div><label for="price_description">Kosten <small>(optioneel)</small></label><input id="price_description" name="price_description" value="<?= e($values['price_description']) ?>" placeholder="bijv. €5 per kind" maxlength="150"></div>
      <div><label for="max_children">Max. aantal kinderen <small>(optioneel)</small></label><input id="max_children" name="max_children" type="number" min="1" value="<?= e($values['max_children']) ?>"></div>
    </div>

    <label class="check"><input type="checkbox" name="needs_volunteers" value="1"<?= $values['needs_volunteers'] ? ' checked' : '' ?>> 🤝 We zoeken hulp van vrijwilligers</label>

    <button class="btn" type="submit"><?= $activity ? 'Opslaan' : 'Op het plein zetten' ?></button>
  </form>
</article>
<?php page_end();
