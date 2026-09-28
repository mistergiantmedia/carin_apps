<?php
// Friend families: invite another family, choose per family what you share with them, and link the
// friends in your address book to the real children in their family (their photo, birthday and clubs
// then come from their own planner, and playdates show up in both agendas).
require __DIR__ . '/lib/app.php';
$user = require_login();
$me = current_family_id();

if (is_post()) {
    $action = post('action');
    if ($action === 'invite') {
        if (!filter_var(post('email'), FILTER_VALIDATE_EMAIL)) {
            flash('Vul een geldig e-mailadres in.', 'error');
        } else {
            flash(request_friendship(post('email')));
        }
    } elseif ($action === 'accept') {
        accept_friendship(post_int('link_id'));
        flash('🤝 Jullie zijn nu vriendgezinnen! Kies hieronder wat jullie willen delen.');
    } elseif ($action === 'end') {
        end_friendship(post_int('link_id'));
        flash('De vriendschap is beëindigd. Er wordt niets meer gedeeld.');
    } elseif ($action === 'shares') {
        set_shares(post_int('family'), (array) ($_POST['what'] ?? []));
        flash('✓ Opgeslagen: dit delen jullie met ' . (friend_families()[post_int('family')]['name'] ?? 'dit gezin'));
    } elseif ($action === 'link') {
        link_contact(post_int('contact_id'), post_int('family'), post_int('member_id'));
        // Someone of their family: put them in the gezin of that family (when they're not in a gezin yet)
        if ($g = gezin_of_family(post_int('family'))) {
            db()->prepare('UPDATE fp_contacts SET household_id = ? WHERE id = ? AND household_id IS NULL')->execute([$g['id'], post_int('contact_id')]);
        }
        flash('🔗 Gekoppeld');
    } elseif ($action === 'unlink') {
        unlink_contact(post_int('contact_id'));
        flash('Koppeling verwijderd');
    } elseif ($action === 'link_gezin' && isset(friend_families()[post_int('family')])) {
        $fid = post_int('family');
        db()->prepare('UPDATE fp_households SET linked_family = NULL WHERE linked_family = ?')->execute([$fid]);
        if (post('household_id') === 'new') {
            $hid = ensure_gezin_of_family($fid);
        } elseif (post_int('household_id')) {
            db()->prepare('UPDATE fp_households SET linked_family = ? WHERE id = ?')->execute([$fid, post_int('household_id')]);
            $hid = post_int('household_id');
        }
        if (!empty($hid)) {
            // Their people that are already linked go into this gezin too (when not in another one)
            foreach (array_keys(contact_links_to($fid)) as $cid) {
                db()->prepare('UPDATE fp_contacts SET household_id = ? WHERE id = ? AND household_id IS NULL')->execute([$hid, $cid]);
            }
        }
        flash('✓ Opgeslagen');
    } elseif ($action === 'add_contact') {
        $fid = post_int('family');
        $m = null;
        foreach (friend_members($fid) as $row) {
            if ((int) $row['id'] === post_int('member_id')) {
                $m = $row;
            }
        }
        if ($m) {
            db()->prepare("INSERT INTO fp_contacts (first_name, is_child, relation, photo, birth_day, birth_month, birth_year) VALUES (?, ?, 'FRIEND', ?, ?, ?, ?)")
                ->execute([$m['name'], $m['role'] === 'CHILD' ? 1 : 0, $m['photo'], $m['birth_day'], $m['birth_month'], $m['birth_year']]);
            $cid = (int) db()->lastInsertId();
            db()->prepare('UPDATE fp_contacts SET household_id = ? WHERE id = ?')->execute([ensure_gezin_of_family($fid), $cid]);
            foreach (post_ids('friend_of') as $kid) {
                if (member($kid)) {
                    db()->prepare('INSERT IGNORE INTO fp_contact_members (contact_id, member_id) VALUES (?, ?)')->execute([$cid, $kid]);
                }
            }
            link_contact($cid, $fid, (int) $m['id']);
            flash('✓ ' . $m['name'] . ' staat nu in jullie adresboek, gekoppeld aan ' . friend_families()[$fid]['name']);
        }
    }
    redirect('vriendgezinnen.php');
}

$requests = friend_requests();
$friends = friend_families();
$contacts = db()->query('SELECT id, first_name, last_name, nickname, photo, is_child FROM fp_contacts ORDER BY first_name')->fetchAll();
$contactById = array_column($contacts, null, 'id');
$allGezinnen = db()->query('SELECT id, name FROM fp_households ORDER BY name')->fetchAll();

page_start('Vriendgezinnen');
page_header('🤝 Vriendgezinnen', 'Word vrienden met gezinnen die ook de Familie Planner gebruiken. Jullie bepalen zelf wat je deelt, per gezin.');
?>
<?php foreach ($requests['in'] as $r):
    // First names of their parents: last names are quickly forgotten on the schoolyard
    $who = db()->prepare('SELECT name FROM fp_users WHERE family_id = ? GROUP BY name ORDER BY MIN(id)');
    $who->execute([$r['fid']]);
    $parents = $who->fetchAll(PDO::FETCH_COLUMN);
?>
  <div class="card invite-card">
    <?php if ($r['photo']): ?>
      <img class="invite-photo" src="foto.php?f=<?= e($r['photo']) ?>" alt="Gezinsfoto van <?= e($r['name']) ?>">
    <?php else: ?>
      <div class="invite-photo invite-nophoto">🏡</div>
    <?php endif; ?>
    <div class="invite-body">
      <h2><?= e($r['name']) ?></h2>
      <?php if ($parents): ?><p class="invite-parents">👋 <?= e(implode(' & ', $parents)) ?></p><?php endif; ?>
      <p class="muted">wil vrienden met jullie worden. Daarna kiezen jullie allebei zelf wat je deelt.</p>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="link_id" value="<?= (int) $r['id'] ?>">
        <button class="btn ok" name="action" value="accept">✓ Accepteren</button> <button class="btn secondary" name="action" value="end">Nee, bedankt</button></form>
    </div>
  </div>
<?php endforeach; ?>
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr));margin-bottom:16px">
  <form method="post" class="card form">
    <?= csrf_field() ?><input type="hidden" name="action" value="invite">
    <h2 style="margin-top:0">➕ Gezin uitnodigen</h2>
    <p class="muted small">Vul het e-mailadres in waarmee een ouder van dat gezin inlogt. Zij zien jullie uitnodiging dan bovenaan hun planner.</p>
    <label for="email">E-mailadres van de andere ouder</label>
    <input id="email" name="email" type="email" required placeholder="naam@voorbeeld.nl">
    <button class="btn" type="submit" style="margin-top:10px">Uitnodiging sturen</button>
  </form>

  <?php if ($requests['out']): ?>
    <div class="card">
      <h2 style="margin-top:0">✉️ Uitnodigingen</h2>
      <ul class="list compact">
        <?php foreach ($requests['out'] as $r): ?>
          <li><?= family_avatar($r, 36) ?><div class="grow"><span class="title"><?= e($r['name']) ?></span><span class="meta">wacht op hun antwoord</span></div>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="link_id" value="<?= (int) $r['id'] ?>"><button class="btn small secondary" name="action" value="end">Intrekken</button></form></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>

<?php if (!$friends): ?>
  <div class="card"><?= empty_state('🤝', 'Nog geen vriendgezinnen. Nodig een gezin uit met het e-mailadres van een van de ouders.<br><span class="muted small">Tip: als het gezin van een vriendje ook meedoet, kun je het vriendje koppelen. Dan komt de foto, verjaardag en clubjes vanzelf uit hun planner, en staan speelafspraken in beide agenda\'s.</span>') ?></div>
<?php endif; ?>

<?php foreach ($friends as $fid => $fam):
    $mine = shares_from($me, $fid);
    $theirs = shares_from($fid, $me);
    $theirMembers = friend_members($fid);
    $links = contact_links_to($fid); // our contact id => their member id
    $linkedBy = array_flip($links);
?>
  <section class="card" style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;flex-wrap:wrap">
      <div style="display:flex;gap:12px;align-items:center"><?= family_avatar($fam, 64) ?><h2 style="margin:0"><?= e($fam['name']) ?></h2></div>
      <span class="badge ok">🤝 Vrienden sinds <?= e(format_date(substr((string) $fam['since'], 0, 10), false)) ?></span>
    </div>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));margin-top:12px">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="shares"><input type="hidden" name="family" value="<?= (int) $fid ?>">
        <h3 style="margin-top:0">Wat wij delen met <?= e($fam['name']) ?></h3>
        <?php foreach (SHARE_OPTIONS as $key => [$icon, $label, $help]): ?>
          <label class="check" style="align-items:flex-start"><input type="checkbox" name="what[]" value="<?= e($key) ?>"<?= in_array($key, $mine, true) ? ' checked' : '' ?>>
            <span><?= $icon ?> <?= e($label) ?><br><span class="muted small"><?= e($help) ?></span></span></label>
        <?php endforeach; ?>
        <button class="btn small" type="submit" style="margin-top:8px">Opslaan</button>
      </form>
      <div>
        <h3 style="margin-top:0">Wat zij delen met jullie</h3>
        <?php if (!$theirs): ?>
          <p class="muted">Nog niets. Zij kiezen zelf wat ze met jullie delen.</p>
        <?php else: ?>
          <ul class="list compact">
            <?php foreach ($theirs as $key): [$icon, $label] = SHARE_OPTIONS[$key] ?? ['•', $key]; ?>
              <li><span><?= $icon ?></span><div class="grow"><span class="title"><?= e($label) ?></span></div></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <?php $gezin = gezin_of_family($fid); ?>
    <form method="post" class="gezin-link" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px">
      <?= csrf_field() ?><input type="hidden" name="action" value="link_gezin"><input type="hidden" name="family" value="<?= (int) $fid ?>">
      <?php if ($gezin): ?>
        <span>🏠 In jullie adresboek: <a href="huishouden.php?id=<?= (int) $gezin['id'] ?>"><b><?= e($gezin['name']) ?></b></a></span>
      <?php else: ?>
        <span>🏠 Staat dit gezin al in jullie adresboek?</span>
      <?php endif; ?>
      <select name="household_id" onchange="this.form.submit()" style="width:auto">
        <option value=""><?= $gezin ? 'Ander gezin kiezen…' : 'Kies het gezin…' ?></option>
        <?php if (!$gezin): ?><option value="new">＋ Nieuw gezin “<?= e($fam['name']) ?>”</option><?php endif; ?>
        <?php foreach ($allGezinnen as $hg): if ($gezin && (int) $hg['id'] === (int) $gezin['id']) { continue; } ?><option value="<?= (int) $hg['id'] ?>"><?= e($hg['name']) ?></option><?php endforeach; ?>
      </select>
    </form>

    <h3>Hun gezin</h3>
    <?php if (!$theirMembers): ?>
      <p class="muted small">Zodra <?= e($fam['name']) ?> “Namen en foto’s” met jullie deelt, zie je hier hun gezin en kun je hun kinderen aan jullie vriendjes koppelen.</p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($theirMembers as $m):
            $mid = (int) $m['id'];
            $linked = isset($linkedBy[$mid]) ? ($contactById[$linkedBy[$mid]] ?? null) : null;
            // Suggestions: our contacts with the same first name that are not linked yet
            $suggest = [];
            foreach ($contacts as $c) {
                if (!isset($links[(int) $c['id']]) && mb_strtolower(trim($c['first_name'])) === mb_strtolower(trim($m['name']))) {
                    $suggest[] = $c;
                }
            }
        ?>
          <li style="flex-wrap:wrap">
            <?= avatar($m, 44) ?>
            <div class="grow">
              <span class="title"><?= e($m['emoji'] ?? '') ?> <?= e($m['name']) ?></span>
              <span class="meta"><?= $m['role'] === 'CHILD' ? 'Kind' : 'Ouder' ?><?= $m['birth_day'] ? ' · 🎂 ' . e(birthday_text($m)) : '' ?></span>
            </div>
            <?php if ($linked): ?>
              <span class="badge ok">🔗 <a href="contact.php?id=<?= (int) $linked['id'] ?>"><?= e(contact_name($linked)) ?></a> in jullie adresboek</span>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="contact_id" value="<?= (int) $linked['id'] ?>"><button class="btn small secondary">Ontkoppelen</button></form>
            <?php else: ?>
              <div style="display:flex;gap:6px;flex-wrap:wrap;width:100%;margin-top:6px">
                <form method="post" class="inline" style="display:flex;gap:6px;align-items:center">
                  <?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="family" value="<?= (int) $fid ?>"><input type="hidden" name="member_id" value="<?= $mid ?>">
                  <select name="contact_id" required>
                    <option value="">Koppel aan iemand uit jullie adresboek…</option>
                    <?php foreach ($suggest as $c): ?><option value="<?= (int) $c['id'] ?>">⭐ <?= e(contact_name($c)) ?></option><?php endforeach; ?>
                    <?php foreach ($contacts as $c): if (isset($links[(int) $c['id']]) || in_array($c, $suggest, true)) { continue; } ?><option value="<?= (int) $c['id'] ?>"><?= e(contact_name($c)) ?></option><?php endforeach; ?>
                  </select>
                  <button class="btn small">🔗 Koppelen</button>
                </form>
                <details>
                  <summary class="btn small secondary">➕ Nieuw in adresboek</summary>
                  <form method="post" style="margin-top:6px">
                    <?= csrf_field() ?><input type="hidden" name="action" value="add_contact"><input type="hidden" name="family" value="<?= (int) $fid ?>"><input type="hidden" name="member_id" value="<?= $mid ?>">
                    <?php if ($m['role'] === 'CHILD' && children()): ?>
                      <p class="muted small" style="margin:0 0 4px">Vriendje van:</p>
                      <div class="picker"><?php foreach (children() as $k): ?><label class="pick sm" style="--c:<?= e($k['color']) ?>"><input type="checkbox" name="friend_of[]" value="<?= (int) $k['id'] ?>"><span><?= e($k['emoji'] . ' ' . $k['name']) ?></span></label><?php endforeach; ?></div>
                    <?php endif; ?>
                    <button class="btn small" style="margin-top:6px">Toevoegen</button>
                  </form>
                </details>
              </div>
              <?php if ($suggest): ?><p class="muted small" style="width:100%;margin:4px 0 0">💡 Is dit jullie <?= e(contact_name($suggest[0])) ?>? Kies ⭐ en klik Koppelen.</p><?php endif; ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="muted small">Gekoppeld? Dan komen foto<?= in_array('BIRTHDAYS', $theirs, true) ? ', verjaardag' : '' ?><?= in_array('CLUBS', $theirs, true) ? ', clubjes' : '' ?> vanzelf uit hun planner<?= in_array('PLAYDATES', $theirs, true) ? ', en hun speelafspraken met jullie kinderen staan ook in jullie agenda' : '' ?>.</p>
    <?php endif; ?>

    <form method="post" class="inline" data-confirm="Stoppen als vriendgezin met <?= e($fam['name']) ?>? Er wordt dan niets meer gedeeld, in beide richtingen." style="display:block;margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="link_id" value="<?= (int) $fam['link_id'] ?>"><button class="btn small danger" name="action" value="end">Vriendschap beëindigen</button>
    </form>
  </section>
<?php endforeach; ?>
<?php page_end();
