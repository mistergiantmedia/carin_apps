<?php
// Vrije dagen & vakanties: study days / afternoons, school holidays (imported per region from the
// Rijksoverheid) and public holidays / fun days, each with ideas: occasion ideas, the bucketlist,
// the idea bank, friends who are usually free that day and a Kidsproof search.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/freedays.php';
require_login();

$kidIds = array_map('intval', array_keys(children()));

if (is_post()) {
    $action = post('action');
    try {
        if ($action === 'settings') {
            set_setting('school_region', isset(SCHOOL_REGIONS[post('region')]) ? post('region') : 'midden');
            set_setting('city', mb_cut(post('city'), 60));
            flash('Opgeslagen');
        }
        if ($action === 'import') {
            $region = setting('school_region', 'midden');
            $year = school_year();
            $next = (substr($year, 0, 4) + 1) . '-' . (substr($year, 0, 4) + 2);
            $added = import_school_holidays($year, $region);
            try {
                $added += import_school_holidays($next, $region); // next school year is usually published already
            } catch (RuntimeException $e) {
                // not published yet: fine
            }
            flash($added ? $added . ' schoolvakantie' . ($added === 1 ? '' : 's') . ' toegevoegd (regio ' . SCHOOL_REGIONS[$region] . ')' : 'Alle schoolvakanties stonden er al in.');
        }
        if ($action === 'add') {
            $type = post('kind') === 'STUDYPM' ? 'STUDYPM' : 'STUDYDAY';
            $members = post_ids('members') ?: $kidIds;
            // Dates: the date field and/or a pasted list like "9-10-2026, 12/11"
            $dates = [];
            if (post('date') && strtotime(post('date'))) {
                $dates[] = date('Y-m-d', strtotime(post('date')));
            }
            foreach (preg_split('/[\s,;]+/', post('dates')) as $part) {
                if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})(?:[-\/.](\d{2,4}))?$/', $part, $m)) {
                    $y = !empty($m[3]) ? ((int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]) : (int) date('Y');
                    if (checkdate((int) $m[2], (int) $m[1], $y)) {
                        $d = sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
                        if (empty($m[3]) && $d < today()) {
                            $d = sprintf('%04d-%02d-%02d', $y + 1, $m[2], $m[1]); // "12/2" in autumn means next year
                        }
                        $dates[] = $d;
                    }
                }
            }
            $dates = array_unique($dates);
            if (!$dates) {
                throw new RuntimeException('Vul een datum in (of plak meerdere datums, bijv. 9-10, 12-11-2026).');
            }
            $from = preg_match('/^\d{1,2}:\d{2}$/', post('from')) ? post('from') : '12:00';
            foreach ($dates as $d) {
                save_event(normalise_event([
                    'title' => post('title') ?: ($type === 'STUDYPM' ? 'Studiemiddag' : 'Studiedag'), 'type' => $type, 'members' => $members,
                    'all_day' => $type === 'STUDYDAY', 'start' => $type === 'STUDYDAY' ? $d : "$d $from", 'end' => $type === 'STUDYDAY' ? $d : "$d 18:00",
                ]));
            }
            flash(count($dates) . ' ' . ($type === 'STUDYPM' ? 'studiemiddag' : 'studiedag') . (count($dates) === 1 ? '' : 'en') . ' toegevoegd');
        }
        if ($action === 'delete') {
            db()->prepare("DELETE FROM fp_events WHERE id = ? AND type IN ('STUDYDAY', 'STUDYPM', 'SCHOOLHOLIDAY')")->execute([post_int('id')]);
            flash('Verwijderd');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('vrijedagen.php');
}

$filter = in_array($_GET['f'] ?? '', ['school', 'feest'], true) ? $_GET['f'] : '';
$items = array_values(array_filter(upcoming_free_days(today(), 400), function ($it) use ($filter) {
    if ($filter === 'school') {
        return $it['kind'] === 'event' || $it['official'];
    }
    if ($filter === 'feest') {
        return $it['kind'] === 'feast';
    }
    return true;
}));
$region = setting('school_region', 'midden');
$hasHolidays = (bool) db()->query("SELECT 1 FROM fp_events WHERE type = 'SCHOOLHOLIDAY' AND end_at >= NOW() LIMIT 1")->fetchColumn();

page_start('Vrije dagen & vakanties');
page_header('🎒 Vrije dagen & vakanties', 'Studiedagen, schoolvakanties en feestdagen, met ideeën om er iets leuks van te maken.');
?>
<div class="cols even" style="margin-bottom:14px">
  <form method="post" class="card form">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <h2>📚 Studiedag of studiemiddag toevoegen</h2>
    <div class="picker">
      <label class="pick sm"><input type="radio" name="kind" value="STUDYDAY" checked><span>📚 Studiedag (hele dag vrij)</span></label>
      <label class="pick sm"><input type="radio" name="kind" value="STUDYPM"><span>🕐 Studiemiddag</span></label>
    </div>
    <div class="row2">
      <div><label>Datum</label><input type="date" name="date"></div>
      <div><label>Vrij vanaf <small>(studiemiddag)</small></label><input type="time" name="from" value="12:00"></div>
    </div>
    <label>…of meerdere datums tegelijk <small>(uit de schoolkalender)</small></label>
    <input name="dates" placeholder="bijv. 9-10, 12-11-2026, 5-2">
    <div class="label">Wie is er vrij?</div>
    <?= member_picker('members', $kidIds) ?>
    <label>Naam <small>(optioneel)</small></label><input name="title" placeholder="Studiedag">
    <button class="btn" style="margin-top:12px">Toevoegen</button>
  </form>
  <div class="card">
    <h2>🏖️ Schoolvakanties</h2>
    <p class="muted small" style="margin-top:0">De officiële schoolvakanties van de Rijksoverheid, voor regio <b><?= e(SCHOOL_REGIONS[$region]) ?></b>. Ze komen in de agenda als vrij voor <?= e(children_names()) ?>; de gewone schoolafspraken vallen dan vanzelf weg.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="import"><button class="btn<?= $hasHolidays ? ' secondary' : '' ?>">⬇️ Schoolvakanties <?= $hasHolidays ? 'bijwerken' : 'ophalen' ?></button></form>
    <details class="more"><summary>Regio en woonplaats</summary>
      <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="settings">
        <div class="row2">
          <div><label>Regio schoolvakanties</label><select name="region"><?= options(SCHOOL_REGIONS, $region) ?></select></div>
          <div><label>Woonplaats <small>(voor tips)</small></label><input name="city" value="<?= e(setting('city', '')) ?>"></div>
        </div>
        <button class="btn small" style="margin-top:10px">Opslaan</button>
      </form>
    </details>
    <?php if (setting('school_url')): ?><p style="margin:12px 0 0"><a class="btn small secondary" href="<?= e(setting('school_url')) ?>" target="_blank" rel="noopener">📄 Schoolkalender <?= e(setting('school', 'school')) ?></a> <span class="muted small">Studiedagen voor een nieuw schooljaar voeg je links toe (meerdere datums tegelijk kan).</span></p><?php endif; ?>
    <p class="muted small" style="margin:12px 0 0">🎉 Feestdagen (Pasen, Koningsdag, Sinterklaas, Kerst…) worden automatisch berekend en staan ook in de agenda.</p>
  </div>
</div>

<div class="tabs" style="margin-bottom:14px">
  <a href="vrijedagen.php" class="<?= $filter === '' ? 'on' : '' ?>">Alles</a>
  <a href="?f=school" class="<?= $filter === 'school' ? 'on' : '' ?>">🎒 Vrij van school</a>
  <a href="?f=feest" class="<?= $filter === 'feest' ? 'on' : '' ?>">🎉 Feestdagen</a>
</div>

<?php
$month = '';
foreach ($items as $i => $it):
    $m = substr($it['date'], 0, 7);
    if ($m !== $month):
        $month = $m; ?>
      <div class="section-title"><h2 style="text-transform:capitalize"><?= e(MONTHS[(int) substr($m, 5, 2)]) ?> <span class="muted" style="font-weight:500"><?= substr($m, 0, 4) ?></span></h2></div>
    <?php endif;
    $ev = $it['event'];
    $days = days_until($it['date']);
    $anchor = $ev ? 'e' . $ev['id'] : 'f' . $it['date'];
    $multi = $it['end'] > $it['date'];
    $who = $ev ? array_filter(array_map('member', $ev['members'])) : [];
    $single = !$multi && (int) date('N', strtotime($it['date'])) <= 5;
    $open = $i < 4 && $days <= 60; ?>
  <details class="card free-day<?= $it['official'] ? ' official' : '' ?>" id="<?= e($anchor) ?>"<?= $open ? ' open' : '' ?>>
    <summary>
      <span class="fd-emoji"><?= e($it['emoji']) ?></span>
      <span class="grow"><b><?= e($it['title']) ?></b>
        <span class="muted small"><?= e(ucfirst(format_date($it['date']))) ?><?= $multi ? ' t/m ' . e(format_date($it['end'])) : '' ?><?= $ev && $ev['type'] === 'STUDYPM' && !$ev['all_day'] ? ' · vrij vanaf ' . e(substr($ev['start_at'], 11, 5)) : '' ?></span></span>
      <?php foreach ($who as $wm): ?><?= avatar($wm, 26) ?><?php endforeach; ?>
      <span class="badge <?= $days <= 7 ? 'accent' : '' ?>"><?= $days <= 0 ? ($multi && $it['end'] >= today() ? 'nu!' : 'vandaag') : 'nog ' . $days . ' ' . ($days === 1 ? 'nachtje' : 'nachtjes') ?></span>
    </summary>
    <?php
    $sugg = free_day_suggestions($it['date'], 3);
    $friends = $single ? friends_free_on($it['date']) : [];
    $preset = ['type' => $multi ? 'OUTING' : 'ACTIVITY', 'start' => $it['date'] . 'T10:00', 'end' => $it['date'] . 'T15:00', 'members' => array_map('intval', array_keys(members()))]; ?>
    <div class="fd-body">
      <div class="fd-col">
        <h4>💡 Ideeën voor <?= e(mb_strtolower($it['title'])) ?></h4>
        <ul><?php foreach (occasion_ideas($it['key']) as $idea): ?><li><?= e($idea) ?></li><?php endforeach; ?></ul>
      </div>
      <?php if ($sugg['wishes']): ?>
        <div class="fd-col">
          <h4>🌟 Van de bucketlist</h4>
          <ul><?php foreach ($sugg['wishes'] as $w): ?><li><a href="bucketlist.php#b<?= (int) $w['id'] ?>"><?= e(($w['emoji'] ?: '🌟') . ' ' . $w['title']) ?></a></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <?php if ($sugg['ideas']): ?>
        <div class="fd-col">
          <h4>🎡 Uit de ideeënbank</h4>
          <ul><?php foreach ($sugg['ideas'] as $idea): ?><li><?= e($idea['title']) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <?php if ($friends): ?>
        <div class="fd-col">
          <h4>🧸 Vriendjes die meestal kunnen op <?= e(DAYS[(int) date('w', strtotime($it['date']))]) ?></h4>
          <div class="avatars" style="flex-wrap:wrap;gap:6px"><?php foreach ($friends as $f): ?>
            <button type="button" class="friend-tag" style="cursor:pointer" data-new-event='<?= e(json_encode(['type' => 'PLAYDATE', 'host' => 'HOME', 'members' => $kidIds, 'start' => $it['date'] . 'T10:00', 'end' => $it['date'] . 'T14:00', 'title' => 'Spelen met ' . contact_name($f, false),
                'contacts' => [['id' => (int) $f['id'], 'name' => contact_name($f, false), 'fullName' => contact_name($f), 'photo' => $f['photo'], 'color' => name_color(contact_name($f, false)), 'rsvp' => '']]], JSON_UNESCAPED_UNICODE)) ?>'><?= avatar($f, 24) ?><?= e(contact_name($f, false)) ?></button>
          <?php endforeach; ?></div>
        </div>
      <?php endif; ?>
    </div>
    <div class="s-actions" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px">
      <button type="button" class="btn small" data-new-event='<?= e(json_encode($preset)) ?>'>📅 Iets plannen</button>
      <?php if ($it['official'] || $ev): ?><button type="button" class="btn small soft" data-new-event='<?= e(json_encode(['type' => 'BABYSIT', 'title' => 'Oppas', 'members' => $kidIds, 'start' => $it['date'] . 'T08:30', 'end' => $it['date'] . 'T17:00'])) ?>'>🍼 Oppas regelen</button><?php endif; ?>
      <a class="btn small secondary" href="<?= e(kidsproof_url($it['title'])) ?>" target="_blank" rel="noopener">🔎 Tips op Kidsproof</a>
      <?php if ($ev): ?>
        <a class="btn small secondary" href="event.php?id=<?= (int) $ev['id'] ?>&amp;occ=<?= e($ev['occ']) ?>">✏️</a>
        <form method="post" class="inline" data-confirm="“<?= e($it['title']) ?>” verwijderen?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $ev['id'] ?>"><button class="btn small danger" title="Verwijderen">🗑</button></form>
      <?php endif; ?>
    </div>
  </details>
<?php endforeach; ?>
<?php if (!$items): ?><div class="card"><?= empty_state('🎒', 'Geen vrije dagen gevonden.') ?></div><?php endif; ?>
<?php page_end();
