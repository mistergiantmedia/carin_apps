<?php
// One person in the address book: details, household, playdates, gift ideas; add / edit / delete.
// ?id=<id> to view, &edit=1 to edit. ?new=1 (optional child=1, member, household, class, relation) to add.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/upload.php';
require __DIR__ . '/lib/friends.php';
require __DIR__ . '/lib/bucket.php';
require __DIR__ . '/lib/groups.php';
require_once __DIR__ . '/lib/week.php';
require_login();

$id = get_int('id');
$c = null;
if ($id) {
    $stmt = db()->prepare('SELECT c.*, h.name AS household_name, h.street AS h_street, h.postal_code AS h_postal, h.city AS h_city, h.phone AS h_phone, h.email AS h_email
        FROM fp_contacts c LEFT JOIN fp_households h ON h.id = c.household_id WHERE c.id = ?');
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if (!$c) {
        flash('Deze persoon bestaat niet meer.', 'warn');
        redirect('mensen.php');
    }
}
$error = null;

function contact_members_of(int $id): array
{
    $stmt = db()->prepare('SELECT member_id FROM fp_contact_members WHERE contact_id = ?');
    $stmt->execute([$id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}


if (is_post()) {
    $action = post('action');
    try {
        if ($action === 'save') {
            if (post('first_name') === '') {
                throw new RuntimeException('Vul een voornaam in.');
            }
            [$bd, $bm, $by] = posted_birthday();
            $householdId = post_int('household_id');
            if (post('new_household') !== '') {
                db()->prepare('INSERT INTO fp_households (name, street, postal_code, city) VALUES (?, ?, ?, ?)')
                    ->execute([mb_cut(post('new_household'), 120), post_or_null('street'), post_or_null('postal_code'), post_or_null('city')]);
                $householdId = (int) db()->lastInsertId();
            }
            $photo = posted_photo($c['photo'] ?? null);
            $rate = post('hourly_rate') !== '' ? round((float) str_replace(',', '.', post('hourly_rate')), 2) : null;
            $data = [
                mb_cut(post('first_name'), 80), post_or_null('last_name'), post_or_null('nickname'), post('is_child') ? 1 : 0,
                isset(RELATIONS[post('relation')]) ? post('relation') : 'OTHER', $bd, $bm, $by, $householdId ?: null,
                post_or_null('phone'), post_or_null('email'), post_or_null('street'), post_or_null('postal_code'), post_or_null('city'),
                $photo, post_or_null('allergies'), $rate, post_or_null('notes'), post('is_favorite') ? 1 : 0,
            ];
            if ($c) {
                db()->prepare('UPDATE fp_contacts SET first_name = ?, last_name = ?, nickname = ?, is_child = ?, relation = ?, birth_day = ?, birth_month = ?, birth_year = ?,
                    household_id = ?, phone = ?, email = ?, street = ?, postal_code = ?, city = ?, photo = ?, allergies = ?, hourly_rate = ?, notes = ?, is_favorite = ? WHERE id = ?')
                    ->execute(array_merge($data, [$id]));
            } else {
                db()->prepare('INSERT INTO fp_contacts (first_name, last_name, nickname, is_child, relation, birth_day, birth_month, birth_year,
                    household_id, phone, email, street, postal_code, city, photo, allergies, hourly_rate, notes, is_favorite) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute($data);
                $id = (int) db()->lastInsertId();
            }
            db()->prepare('DELETE FROM fp_contact_members WHERE contact_id = ?')->execute([$id]);
            foreach (post_ids('members') as $mid) {
                if (member($mid)) {
                    db()->prepare('INSERT INTO fp_contact_members (contact_id, member_id) VALUES (?, ?)')->execute([$id, $mid]);
                }
            }
            set_contact_groups($id, post_ids('groups'));
            flash($c ? 'Opgeslagen' : contact_name(['first_name' => post('first_name'), 'last_name' => post('last_name'), 'nickname' => null]) . ' staat in het adresboek');
            $next = post('next');
            redirect($next !== '' ? safe_next($next) : 'contact.php?id=' . $id);
        }
        if ($action === 'delete' && $c) {
            unlink_contact($id);
            delete_photo($c['photo']);
            db()->prepare('DELETE FROM fp_contacts WHERE id = ?')->execute([$id]);
            flash(contact_name($c) . ' is verwijderd');
            redirect('mensen.php');
        }
        if ($action === 'favorite' && $c) {
            db()->prepare('UPDATE fp_contacts SET is_favorite = 1 - is_favorite WHERE id = ?')->execute([$id]);
            redirect('contact.php?id=' . $id);
        }
        if ($action === 'day_status' && $c) {
            // Tap a day: ❓ unknown → ✅ can play → ❌ can't → ❓
            $wd = post_int('weekday');
            if ($wd >= 1 && $wd <= 7) {
                $cur = contact_days($id)[$wd] ?? '';
                $next = $cur === '' ? 'YES' : ($cur === 'YES' ? 'NO' : '');
                if ($next === '') {
                    db()->prepare('DELETE FROM fp_contact_days WHERE contact_id = ? AND weekday = ?')->execute([$id, $wd]);
                } else {
                    db()->prepare('INSERT INTO fp_contact_days (contact_id, weekday, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)')->execute([$id, $wd, $next]);
                }
            }
            redirect('contact.php?id=' . $id . '#week');
        }
        if ($action === 'week_add' && $c && post('title') !== '') {
            $days = array_values(array_filter(post_ids('weekdays'), function ($d) { return $d >= 1 && $d <= 7; }));
            if (!$days) {
                throw new RuntimeException('Kies op welke dag(en) het is.');
            }
            $time = function ($v) { return preg_match('/^\d{1,2}:\d{2}$/', $v) ? $v : null; };
            foreach ($days as $wd) {
                db()->prepare('INSERT INTO fp_contact_week (contact_id, weekday, start_time, end_time, kind, title, emoji) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
                    $id, $wd, $time(post('start_time')), $time(post('end_time')), isset(WEEK_KINDS[post('kind')]) ? post('kind') : 'CLUB',
                    mb_cut(post('title'), 80), post('emoji') !== '' ? mb_cut(post('emoji'), 16) : null,
                ]);
                // BSO / opvang means: can't play that day
                if (post('kind') === 'BSO') {
                    db()->prepare("INSERT INTO fp_contact_days (contact_id, weekday, status) VALUES (?, ?, 'NO') ON DUPLICATE KEY UPDATE status = 'NO'")->execute([$id, $wd]);
                }
            }
            flash('Toegevoegd aan de vaste week');
            redirect('contact.php?id=' . $id . '#week');
        }
        if ($action === 'week_delete' && $c) {
            db()->prepare('DELETE FROM fp_contact_week WHERE id = ? AND contact_id = ?')->execute([post_int('item_id'), $id]);
            redirect('contact.php?id=' . $id . '#week');
        }
        if ($action === 'add_gift' && $c && post('title') !== '') {
            db()->prepare("INSERT INTO fp_ideas (title, category, contact_id, cost) VALUES (?, 'GIFT', ?, ?)")->execute([mb_cut(post('title'), 190), $id, post_or_null('cost')]);
            redirect('contact.php?id=' . $id . '#cadeaus');
        }
        if ($action === 'gift_done' && $c) {
            db()->prepare('UPDATE fp_ideas SET done_at = IF(done_at IS NULL, NOW(), NULL) WHERE id = ? AND contact_id = ?')->execute([post_int('idea_id'), $id]);
            redirect('contact.php?id=' . $id . '#cadeaus');
        }
        if ($action === 'gift_delete' && $c) {
            db()->prepare('DELETE FROM fp_ideas WHERE id = ? AND contact_id = ?')->execute([post_int('idea_id'), $id]);
            redirect('contact.php?id=' . $id . '#cadeaus');
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$editing = !$c || !empty($_GET['edit']) || $error;
$households = db()->query('SELECT id, name FROM fp_households ORDER BY name')->fetchAll();
$allGroups = load_groups();

if ($editing) {
    $f = $c ?: [
        'first_name' => '', 'last_name' => '', 'nickname' => '', 'is_child' => !empty($_GET['child']) ? 1 : 0,
        'relation' => isset(RELATIONS[$_GET['relation'] ?? '']) ? $_GET['relation'] : (!empty($_GET['child']) ? 'FRIEND' : 'OWN_FRIEND'),
        'birth_day' => null, 'birth_month' => null, 'birth_year' => null, 'household_id' => get_int('household'), 'phone' => '', 'email' => '',
        'street' => '', 'postal_code' => '', 'city' => '', 'photo' => null, 'allergies' => '', 'hourly_rate' => null, 'notes' => '', 'is_favorite' => 0,
    ];
    if ($error) {
        $f = array_merge($f, array_intersect_key($_POST, $f));
    }
    $fMembers = $c ? contact_members_of($id) : (get_int('member') ? [get_int('member')] : []);
    $fGroups = $c ? array_keys(contact_group_roles($id)) : array_values(array_filter([get_int('group') ?: get_int('class')]));

    page_start($c ? contact_name($c) . ' bewerken' : 'Nieuwe persoon', ['active' => 'mensen.php', 'narrow' => true]);
    ?>
    <p><a href="<?= $c ? 'contact.php?id=' . $id : e(back_to('mensen.php')) ?>">← Terug</a></p>
    <?php page_header($c ? '✏️ ' . e(contact_name($c)) : '👋 Nieuwe persoon'); ?>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card form">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="next" value="<?= e((string) ($_GET['next'] ?? '')) ?>">
      <div class="row2">
        <div><label for="first_name">Voornaam</label><input id="first_name" name="first_name" value="<?= e($f['first_name']) ?>" required autofocus></div>
        <div><label for="last_name">Achternaam</label><input id="last_name" name="last_name" value="<?= e($f['last_name']) ?>"></div>
      </div>
      <div class="row2">
        <div><label>Soort contact</label><select name="relation"><?php foreach (RELATIONS as $k => [$label, $emoji]): ?><option value="<?= e($k) ?>"<?= $k === $f['relation'] ? ' selected' : '' ?>><?= $emoji ?> <?= e($label) ?></option><?php endforeach; ?></select></div>
        <div><label>Roepnaam <small>(optioneel)</small></label><input name="nickname" value="<?= e($f['nickname']) ?>"></div>
      </div>
      <label class="check"><input type="checkbox" name="is_child" value="1"<?= $f['is_child'] ? ' checked' : '' ?>> Dit is een kind</label>
      <label class="check"><input type="checkbox" name="is_favorite" value="1"<?= $f['is_favorite'] ? ' checked' : '' ?>> ⭐ Favoriet (vaak gezien; krijgt "attent zijn"-tips)</label>
      <div class="label">Hoort bij <small>(vriendje van, vriend van)</small></div>
      <?= member_picker('members', $fMembers) ?>
      <label>Verjaardag</label>
      <?= birthday_fields($f) ?>
      <?= photo_field($f['photo']) ?>
      <fieldset>
        <legend>🏠 Gezin & adres</legend>
        <p class="hint">Zet vriendjes, hun broers/zussen en ouders in hetzelfde gezin. Dan staat het adres er maar één keer in.</p>
        <div class="row2">
          <div><label>Gezin</label><select name="household_id"><option value="">— geen —</option><?php foreach ($households as $h): ?><option value="<?= (int) $h['id'] ?>"<?= (int) $h['id'] === (int) $f['household_id'] ? ' selected' : '' ?>><?= e($h['name']) ?></option><?php endforeach; ?></select></div>
          <div><label>…of nieuw gezin</label><input name="new_household" placeholder="bijv. Familie de Vries"></div>
        </div>
        <label>Adres <small>(alleen als het anders is dan het gezin)</small></label>
        <input name="street" value="<?= e($f['street']) ?>" placeholder="Straat en huisnummer">
        <div class="row2" style="margin-top:8px"><input name="postal_code" value="<?= e($f['postal_code']) ?>" placeholder="Postcode"><input name="city" value="<?= e($f['city']) ?>" placeholder="Plaats"></div>
      </fieldset>
      <div class="row2">
        <div><label>Telefoon</label><input name="phone" type="tel" value="<?= e($f['phone']) ?>"></div>
        <div><label>E-mail</label><input name="email" type="email" value="<?= e($f['email']) ?>"></div>
      </div>
      <?php if ($allGroups): ?>
        <div class="label">Groepen <small>(klas, sportteam, familie, werk… meerdere kan)</small></div>
        <div class="picker">
          <?php foreach ($allGroups as $g): [$gl, $ge, $gc] = group_type($g['type']); ?>
            <label class="pick sm" style="--c:<?= e($gc) ?>"><input type="checkbox" name="groups[]" value="<?= (int) $g['id'] ?>"<?= in_array((int) $g['id'], $fGroups, true) ? ' checked' : '' ?>><span><?= e(group_emoji($g)) ?> <?= e(group_label($g)) ?></span></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p class="hint"><a href="groepen.php?new=1">＋ Nieuwe groep maken</a></p>
      <label>Allergieën / dieet</label><input name="allergies" value="<?= e($f['allergies']) ?>" placeholder="bijv. geen noten, vegetarisch">
      <label>Uurtarief oppas <small>(alleen voor oppassers)</small></label><input name="hourly_rate" inputmode="decimal" value="<?= $f['hourly_rate'] !== null ? e(str_replace('.', ',', (string) $f['hourly_rate'])) : '' ?>" placeholder="bijv. 10,50">
      <label>Notities</label><textarea name="notes" rows="3" placeholder="Handige dingen om te onthouden: hobby's, lievelingseten, naam van de hond…"><?= e($f['notes']) ?></textarea>
      <div class="form-actions"><button class="btn">Opslaan</button><a class="btn secondary" href="<?= $c ? 'contact.php?id=' . $id : 'mensen.php' ?>">Annuleren</a></div>
    </form>
    <?php if ($c): ?>
      <form method="post" data-confirm="<?= e(contact_name($c)) ?> wordt uit het adresboek verwijderd (ook uit afspraken en klassen)." style="margin-top:14px">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger">🗑 Verwijderen</button>
      </form>
    <?php endif;
    page_end();
    exit;
}

// ---------- View ----------
$memberIds = contact_members_of($id);
$stmt = db()->prepare('SELECT * FROM fp_contacts WHERE household_id = ? AND id <> ? ORDER BY is_child, first_name');
$stmt->execute([(int) $c['household_id'], $id]);
$housemates = $c['household_id'] ? $stmt->fetchAll() : [];
$inGroups = load_groups(['contact' => $id]);
$myRoles = contact_group_roles($id);
$past = array_reverse(load_events(date('Y-m-d', strtotime('-2 year')), today(), ['contact' => $id]));
$upcoming = load_events(today(), date('Y-m-d', strtotime('+1 year')), ['contact' => $id]);
$stmt = db()->prepare("SELECT * FROM fp_ideas WHERE contact_id = ? ORDER BY done_at IS NOT NULL, id DESC");
$stmt->execute([$id]);
$gifts = $stmt->fetchAll();
$nb = next_birthday($c['birth_day'] ? (int) $c['birth_day'] : null, $c['birth_month'] ? (int) $c['birth_month'] : null);
$street = $c['street'] ?: $c['h_street'];
$postal = $c['street'] ? $c['postal_code'] : $c['h_postal'];
$city = $c['street'] ? $c['city'] : $c['h_city'];
$phone = $c['phone'] ?: $c['h_phone'];
$email = $c['email'] ?: $c['h_email'];
$address = trim(implode(', ', array_filter([$street, trim($postal . ' ' . $city)])));

page_start(contact_name($c), ['active' => 'mensen.php']);
?>
<p><a href="mensen.php">← Adresboek</a></p>
<div class="kid-hero" style="--c:<?= e(name_color(contact_name($c, false))) ?>">
  <?= avatar($c, 104, 'ring') ?>
  <div style="flex:1;min-width:200px">
    <h1><?= e(contact_name($c)) ?> <?= $c['is_favorite'] ? '⭐' : '' ?></h1>
    <p class="muted" style="margin:6px 0 0">
      <?= RELATIONS[$c['relation']][1] ?? '' ?> <?= e(RELATIONS[$c['relation']][0] ?? '') ?>
      <?php if ($memberIds): ?> van <?= e(implode(' & ', array_map(function ($mid) { return member($mid)['name'] ?? ''; }, $memberIds))) ?><?php endif; ?>
      <?php $a = age($c); if ($a !== null): ?> · <?= $a ?> jaar<?php endif; ?>
    </p>
    <?php $flink = contact_link($id); if ($flink): ?><p style="margin:6px 0 0"><a class="badge ok" href="vriendgezinnen.php">🔗 Gekoppeld aan <?= e($flink['family_name']) ?></a> <span class="muted small">foto, verjaardag en clubjes komen uit hun eigen planner</span></p><?php endif; ?>
    <?php if ($nb): ?><p style="margin:6px 0 0">🎂 <?= e(birthday_text($c)) ?> · <b><?= e(in_days_label(days_until($nb))) ?></b><?= $c['birth_year'] ? ' wordt ' . (int) (substr($nb, 0, 4) - $c['birth_year']) : '' ?></p><?php endif; ?>
  </div>
  <div class="head-actions">
    <?php if ($c['is_child']): ?><button type="button" class="btn" data-new-event='<?= e(json_encode(['type' => 'PLAYDATE', 'host' => 'HOME', 'members' => array_values(array_filter($memberIds, function ($m) { return (member($m)['role'] ?? '') === 'CHILD'; })), 'contacts' => [['id' => $id, 'name' => contact_name($c, false), 'fullName' => contact_name($c), 'photo' => $c['photo'], 'color' => name_color(contact_name($c, false)), 'rsvp' => '']], 'title' => 'Spelen met ' . contact_name($c, false)], JSON_UNESCAPED_UNICODE)) ?>'>🧸 Speelafspraak</button>
    <?php else: ?><button type="button" class="btn" data-new-event='<?= e(json_encode(['contacts' => [['id' => $id, 'name' => contact_name($c, false), 'fullName' => contact_name($c), 'photo' => $c['photo'], 'color' => name_color(contact_name($c, false)), 'rsvp' => '']]], JSON_UNESCAPED_UNICODE)) ?>'>📅 Afspraak</button><?php endif; ?>
    <a class="btn soft" href="contact.php?id=<?= $id ?>&amp;edit=1">✏️ Bewerken</a>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="favorite"><button class="btn secondary" title="Favoriet"><?= $c['is_favorite'] ? '★' : '☆' ?></button></form>
  </div>
</div>

<div class="cols">
  <div class="stack">
    <div class="card">
      <h2>📇 Gegevens</h2>
      <ul class="list">
        <?php if ($phone): ?><li>📞 <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a> <a class="btn small secondary" href="https://wa.me/<?= e(preg_replace(['/[^0-9]/', '/^06/'], ['', '316'], $phone)) ?>" target="_blank" rel="noopener">WhatsApp</a></li><?php endif; ?>
        <?php if ($email): ?><li>✉️ <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li><?php endif; ?>
        <?php if ($address): ?><li>📍 <a href="https://maps.google.com/?q=<?= e(urlencode($address)) ?>" target="_blank" rel="noopener"><?= e($address) ?></a></li><?php endif; ?>
        <?php if ($c['household_id']): ?><li>🏠 <a href="huishouden.php?id=<?= (int) $c['household_id'] ?>"><?= e($c['household_name']) ?></a></li><?php endif; ?>
        <?php if ($c['allergies']): ?><li>⚠️ <b><?= e($c['allergies']) ?></b></li><?php endif; ?>
        <?php if ($c['hourly_rate'] !== null): ?><li>💶 <?= e(money((float) $c['hourly_rate'])) ?> per uur</li><?php endif; ?>
        <?php if ($inGroups): ?><li style="flex-wrap:wrap;gap:6px"><?php foreach ($inGroups as $g): ?><a class="group-chip" href="groepen.php?id=<?= (int) $g['id'] ?>"><?= e(group_emoji($g)) ?> <?= e(group_label($g)) ?><?= !empty($myRoles[$g['id']]) ? ' <span class="muted">· ' . e($myRoles[$g['id']]) . '</span>' : '' ?></a><?php endforeach; ?> <a class="small" href="netwerk.php?contact=<?= $id ?>">🕸️ netwerk</a></li><?php endif; ?>
        <?php if ($c['notes']): ?><li style="white-space:pre-line;display:block">📝 <?= e($c['notes']) ?></li><?php endif; ?>
      </ul>
      <?php if (!$phone && !$email && !$address && !$c['notes']): ?><p class="muted">Nog geen gegevens. <a href="contact.php?id=<?= $id ?>&amp;edit=1">Aanvullen</a></p><?php endif; ?>
    </div>

    <?php if ($housemates): ?>
    <div class="card">
      <h2>🏠 <?= e($c['household_name']) ?></h2>
      <ul class="list compact">
        <?php foreach ($housemates as $h): ?>
          <li><?= avatar($h, 34) ?><div class="grow"><a class="title" href="contact.php?id=<?= (int) $h['id'] ?>" style="color:inherit"><?= e(contact_name($h)) ?></a><span class="meta"><?= e(RELATIONS[$h['relation']][0] ?? '') ?><?= $h['phone'] ? ' · ' . e($h['phone']) : '' ?></span></div></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2>📅 Samen</h2>
      <?php if ($upcoming): ?>
        <h3 class="muted small" style="margin:6px 0">GEPLAND</h3>
        <?php foreach (array_slice($upcoming, 0, 5) as $ev): $t = event_type($ev['type']); ?>
          <a class="ev" style="--c:<?= e(event_color($ev)) ?>" href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>"><div class="ev-emoji"><?= event_emoji($ev) ?></div><div class="ev-body"><span class="ev-title"><?= e($ev['title']) ?></span><span class="ev-meta"><?= e(format_date($ev['start_at'])) ?> · <?= e(format_time_range($ev)) ?><?= $ev['host'] ? ' · ' . e(HOSTS[$ev['host']]) : '' ?></span></div></a>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($past): ?>
        <h3 class="muted small" style="margin:10px 0 6px">EERDER (<?= count($past) ?>)</h3>
        <?php foreach (array_slice($past, 0, 8) as $ev): $t = event_type($ev['type']); ?>
          <a class="ev" style="--c:<?= e(event_color($ev)) ?>" href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>"><div class="ev-emoji"><?= event_emoji($ev) ?></div><div class="ev-body"><span class="ev-title"><?= e($ev['title']) ?></span><span class="ev-meta"><?= e(format_date($ev['start_at'])) ?><?= $ev['host'] ? ' · ' . e(HOSTS[$ev['host']]) : '' ?></span></div></a>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!$past && !$upcoming): ?><p class="muted">Nog niets samen in de agenda.</p><?php endif; ?>
    </div>
  </div>

  <div class="stack">
    <?php if ($c['is_child']):
        foreach ($memberIds as $mid): $kid = member($mid); if (!$kid || $kid['role'] !== 'CHILD') continue;
            $s = playdate_stats($mid)[$id] ?? ['home' => 0, 'away' => 0, 'other' => 0, 'planned' => 0, 'last' => null]; ?>
      <div class="card">
        <h2>🧸 Spelen met <?= e($kid['name']) ?></h2>
        <p class="muted small">Dit schooljaar</p>
        <div class="grid tiny" style="grid-template-columns:repeat(3,1fr)">
          <div class="stat"><b><?= $s['home'] ?></b><span>🏠 bij ons</span></div>
          <div class="stat"><b><?= $s['away'] ?></b><span>🚗 bij <?= e(contact_name($c, false)) ?></span></div>
          <div class="stat"><b><?= $s['planned'] ?></b><span>📅 gepland</span></div>
        </div>
        <?php if ($s['away'] > $s['home'] && !$s['planned']): ?><p class="flash warn" style="margin-top:12px">Tijd om <?= e(contact_name($c, false)) ?> terug uit te nodigen! 🔁</p><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>

    <?php $wishes = load_bucket(['contact' => $id]); $openWishes = array_values(array_filter($wishes, function ($w) { return !$w['done_on']; })); ?>
    <?php $week = contact_week($id); $days = contact_days($id);
      $known = db()->query('SELECT DISTINCT title FROM fp_contact_week ORDER BY title')->fetchAll(PDO::FETCH_COLUMN); ?>
    <div class="card" id="week">
      <h2>📅 Vaste week</h2>
      <p class="muted small" style="margin-top:0">Tik op een dag: ❓ weet niet → ✅ kan afspreken → ❌ kan niet. Vaste clubjes en BSO staan eronder.</p>
      <div class="week-grid">
        <?php foreach (WEEKDAYS_SHORT as $wd => $short): $st = $days[$wd] ?? ''; ?>
          <div class="week-day<?= $st === 'YES' ? ' yes' : ($st === 'NO' ? ' no' : '') ?>">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="day_status"><input type="hidden" name="weekday" value="<?= $wd ?>">
              <button class="week-status" title="<?= e(WEEKDAYS[$wd]) ?>: tik om te wisselen"><b><?= $short ?></b><span><?= $st === 'YES' ? '✅' : ($st === 'NO' ? '❌' : '❓') ?></span></button></form>
            <?php foreach ($week[$wd] ?? [] as $it): ?>
              <div class="week-item" title="<?= e(WEEK_KINDS[$it['kind']][0] ?? '') ?>"><span><?= e(week_emoji($it)) ?></span> <?= e($it['title']) ?><?php if (week_time($it)): ?><small><?= e(week_time($it)) ?></small><?php endif; ?>
                <?php if ($it['source'] === 'LINK'): ?><span class="muted small" title="Uit de planner van hun eigen gezin">🔗</span><?php else: ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="week_delete"><input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>"><button class="link muted small" title="Weghalen">✕</button></form><?php endif; ?></div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <details class="more"><summary>Vaste activiteit toevoegen (BSO, sport, clubje…)</summary>
        <form method="post" class="form">
          <?= csrf_field() ?><input type="hidden" name="action" value="week_add">
          <div style="display:flex;gap:8px;position:relative;margin-top:8px">
            <input name="emoji" value="" data-emoji-picker data-default="🎨" aria-label="Emoji">
            <input name="title" list="week-titles" placeholder="Bijv. Hockey, BSO, Zwemles, Scouting" required>
            <datalist id="week-titles"><?php foreach ($known as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
          </div>
          <div class="label">Soort</div>
          <div class="picker"><?php foreach (WEEK_KINDS as $k => [$label, $em]): ?><label class="pick sm"><input type="radio" name="kind" value="<?= $k ?>"<?= $k === 'CLUB' ? ' checked' : '' ?>><span><?= $em ?> <?= e($label) ?></span></label><?php endforeach; ?></div>
          <div class="label">Op welke dag(en)?</div>
          <div class="picker"><?php foreach (WEEKDAYS_SHORT as $wd => $short): ?><label class="pick sm"><input type="checkbox" name="weekdays[]" value="<?= $wd ?>"><span><?= $short ?></span></label><?php endforeach; ?></div>
          <div class="row2">
            <div><label>Van <small>(optioneel)</small></label><input type="time" name="start_time"></div>
            <div><label>Tot</label><input type="time" name="end_time"></div>
          </div>
          <button class="btn" style="margin-top:12px">Toevoegen</button>
        </form>
      </details>
    </div>

    <div class="card" id="bucket">
      <div class="section-title" style="margin-top:0"><h2>🌟 Samen op de bucketlist</h2><?php if ($wishes): ?><a href="bucketlist.php?f=c<?= $id ?>">alles</a><?php endif; ?></div>
      <ul class="list compact">
        <?php foreach ($openWishes as $w): ?>
          <li><span style="font-size:24px"><?= e($w['emoji'] ?: '🌟') ?></span><div class="grow"><a class="title" href="bucketlist.php?f=c<?= $id ?>#b<?= (int) $w['id'] ?>" style="color:inherit"><?= e($w['title']) ?></a>
            <span class="meta"><?= $w['event_start'] ? '📅 ' . e(format_date_short($w['event_start'])) : 'nog niet gepland' ?></span></div>
            <span class="avatars"><?php foreach ($w['voters'] as $vid): if ($vm = member($vid)): ?><?= avatar($vm, 24) ?><?php endif; endforeach; ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php $doneCount = count($wishes) - count($openWishes); if ($doneCount): ?><p class="muted small">🎉 Al <?= $doneCount ?> <?= $doneCount === 1 ? 'wens' : 'wensen' ?> samen gedaan</p><?php endif; ?>
      <?php if (!$openWishes): ?><p class="muted">Wat willen jullie graag samen met <?= e(contact_name($c, false)) ?> doen?</p><?php endif; ?>
      <a class="btn small soft" href="bucketlist.php?with=<?= $id ?>#nieuw">＋ Wens met <?= e(contact_name($c, false)) ?></a>
    </div>

    <div class="card" id="cadeaus">
      <h2>🎁 Cadeau-ideeën</h2>
      <ul class="list compact">
        <?php foreach ($gifts as $g): ?>
          <li class="<?= $g['done_at'] ? 'is-done' : '' ?>">
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="gift_done"><input type="hidden" name="idea_id" value="<?= (int) $g['id'] ?>"><input type="checkbox" class="tick" onchange="this.form.submit()"<?= $g['done_at'] ? ' checked' : '' ?> aria-label="Gegeven"></form>
            <div class="grow"><span class="title"><?= e($g['title']) ?></span><?php if ($g['cost']): ?><span class="meta"><?= e($g['cost']) ?></span><?php endif; ?></div>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="gift_delete"><input type="hidden" name="idea_id" value="<?= (int) $g['id'] ?>"><button class="link muted">✕</button></form>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$gifts): ?><p class="muted">Hoor je iets wat <?= e(contact_name($c, false)) ?> leuk vindt? Schrijf het hier op voor de volgende verjaardag.</p><?php endif; ?>
      <form method="post" style="display:flex;gap:8px;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="add_gift">
        <input name="title" placeholder="Idee…" required><input name="cost" placeholder="€" style="width:80px"><button class="btn">＋</button></form>
    </div>
  </div>
</div>
<?php page_end();
