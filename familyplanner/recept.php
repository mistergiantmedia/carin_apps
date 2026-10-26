<?php
// One recipe (gerecht): ingredients, how to make it, who likes it, put it on the menu or the shopping list.
// ?id=<id> to view, &edit=1 to edit, ?new=1 to add.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/upload.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/meals.php';
$user = require_login();

$id = get_int('id');
$all = recipes();
$r = $id ? ($all[$id] ?? null) : null;
if ($id && !$r) {
    redirect('eten.php?tab=gerechten');
}
$edit = !$r || !empty($_GET['edit']);
$error = null;

if (is_post()) {
    $action = post('action');
    try {
        if ($action === 'import') {
            // Paste a link from a recipe site: name, ingredients, time, method and photo come along
            $url = trim(post('url'));
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Plak een hele link, die begint met https://');
            }
            $imp = import_recipe_from_url($url);
            $photo = download_photo($imp['image']);
            db()->prepare('INSERT INTO fp_recipes (name, emoji, prep_minutes, tags, method, url, photo) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([mb_cut($imp['name'], 160), null, $imp['minutes'], $imp['tags'], $imp['method'] !== '' ? $imp['method'] : null, mb_cut($url, 255), $photo]);
            $id = (int) db()->lastInsertId();
            foreach ($imp['ingredients'] as $n => [$amount, $name]) {
                db()->prepare('INSERT INTO fp_recipe_ingredients (recipe_id, amount, name, category, sort) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$id, $amount !== '' ? mb_cut($amount, 40) : null, mb_cut($name, 120), guess_category($name), $n]);
            }
            flash('🔗 ' . $imp['name'] . ' is opgehaald met ' . count($imp['ingredients']) . ' ingrediënten. Kijk even of alles klopt (✏️ Bewerken).');
            redirect('recept.php?id=' . $id);
        }
        if ($action === 'like' && $r && member(post_int('member_id'))) {
            $verdict = in_array(post('verdict'), ['LIKE', 'DISLIKE'], true) ? post('verdict') : '';
            if ($verdict === '' || ($r['likes'][post_int('member_id')] ?? '') === $verdict) {
                db()->prepare('DELETE FROM fp_recipe_likes WHERE recipe_id = ? AND member_id = ?')->execute([$id, post_int('member_id')]);
            } else {
                db()->prepare('REPLACE INTO fp_recipe_likes (recipe_id, member_id, verdict) VALUES (?, ?, ?)')->execute([$id, post_int('member_id'), $verdict]);
            }
            redirect('recept.php?id=' . $id . '#likes');
        }
        if ($action === 'plan' && $r && strtotime(post('day'))) {
            $day = date('Y-m-d', strtotime(post('day')));
            db()->prepare('INSERT INTO fp_meals (day, recipe_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE recipe_id = VALUES(recipe_id), title = NULL, on_list = 0')->execute([$day, $id]);
            wishes_fulfilled($id);
            flash('📅 ' . $r['name'] . ' staat op het menu voor ' . format_date($day));
            redirect('eten.php?week=' . (int) round((strtotime('monday this week', strtotime($day)) - strtotime('monday this week', strtotime(today()))) / 604800) . '#d' . $day);
        }
        if ($action === 'to_list' && $r) {
            foreach ($r['ingredients'] as $i) {
                add_shopping_item($i['name'], $i['amount'], $i['category'], $r['name']);
            }
            flash('🛒 De ingrediënten van ' . $r['name'] . ' staan op de boodschappenlijst');
            redirect('eten.php?tab=lijst');
        }
        if ($action === 'delete' && $r) {
            delete_photo($r['photo']);
            db()->prepare('DELETE FROM fp_recipes WHERE id = ?')->execute([$id]);
            flash($r['name'] . ' is verwijderd');
            redirect('eten.php?tab=gerechten');
        }
        if ($action === 'save') {
            if (post('name') === '') {
                throw new RuntimeException('Geef het gerecht een naam.');
            }
            $tags = implode(',', array_values(array_intersect(array_keys(RECIPE_TAGS), (array) ($_POST['tags'] ?? []))));
            $photo = posted_photo($r['photo'] ?? null);
            $data = [mb_cut(post('name'), 160), post('emoji') !== '' ? mb_cut(post('emoji'), 16) : null, post_int('prep_minutes') ?: null, $tags,
                post_or_null('method'), filter_var(post('url'), FILTER_VALIDATE_URL) ? mb_cut(post('url'), 255) : null, $photo];
            if ($r) {
                db()->prepare('UPDATE fp_recipes SET name = ?, emoji = ?, prep_minutes = ?, tags = ?, method = ?, url = ?, photo = ? WHERE id = ?')->execute(array_merge($data, [$id]));
            } else {
                db()->prepare('INSERT INTO fp_recipes (name, emoji, prep_minutes, tags, method, url, photo) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($data);
                $id = (int) db()->lastInsertId();
            }
            db()->prepare('DELETE FROM fp_recipe_ingredients WHERE recipe_id = ?')->execute([$id]);
            $n = 0;
            foreach (preg_split('/\r?\n/', post('ingredients')) as $line) {
                [$amount, $name] = parse_ingredient($line);
                if ($name === '') {
                    continue;
                }
                db()->prepare('INSERT INTO fp_recipe_ingredients (recipe_id, amount, name, category, sort) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$id, $amount !== '' ? mb_cut($amount, 40) : null, mb_cut($name, 120), guess_category($name), $n++]);
            }
            flash('✓ Opgeslagen');
            redirect('recept.php?id=' . $id);
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $edit = true;
    }
}

$f = $r ?: ['name' => '', 'emoji' => '', 'prep_minutes' => null, 'tags' => '', 'method' => '', 'url' => '', 'photo' => null, 'ingredients' => []];
$ingredientText = implode("\n", array_map(function ($i) {
    return trim(($i['amount'] ?? '') . ' ' . $i['name']);
}, $f['ingredients']));
if ($error) {
    $f = array_merge($f, array_intersect_key($_POST, $f));
    $ingredientText = (string) ($_POST['ingredients'] ?? '');
}

page_start($r ? $r['name'] : 'Nieuw gerecht', ['active' => 'eten.php', 'narrow' => true]);
?>
<p><a href="eten.php?tab=gerechten">← Gerechten</a></p>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>

<?php if (!$edit): $kids = recipe_kids($r); $warn = recipe_allergy_warnings($r); ?>
  <div class="card gezin-head">
    <?php if ($r['photo']): ?><img class="gezin-photo" src="foto.php?f=<?= e($r['photo']) ?>" alt=""><?php else: ?><div class="gezin-photo gezin-nophoto"><?= e($r['emoji'] ?: '🍽️') ?></div><?php endif; ?>
    <div class="grow">
      <h1><?= e($r['name']) ?></h1>
      <p><?php foreach (recipe_tags($r) as $t): ?><span class="chip small" style="--c:#2BA879"><?= RECIPE_TAGS[$t][0] ?> <?= e(RECIPE_TAGS[$t][1]) ?></span> <?php endforeach; ?>
        <?= $r['prep_minutes'] ? '<span class="chip small">⏱️ ' . (int) $r['prep_minutes'] . ' minuten</span>' : '' ?></p>
      <?php if ($kids['all']): ?><p><span class="badge ok">⭐ Alle kinderen lusten dit</span></p><?php endif; ?>
      <?php if ($warn): ?><p style="color:var(--bad)">⚠️ Let op allergie: <?= e(implode(', ', $warn)) ?></p><?php endif; ?>
      <?php if ($r['last']): ?><p class="muted small">Laatst gegeten op <?= e(format_date($r['last'])) ?></p><?php endif; ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
        <form method="post" class="inline" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="plan">
          <input type="date" name="day" value="<?= e(today()) ?>" style="width:auto;min-height:36px;padding:4px 8px"><button class="btn small">📅 Op het menu</button></form>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="to_list"><button class="btn small secondary">🛒 Op de boodschappenlijst</button></form>
        <?php if ($r['url']): ?><a class="btn small secondary" href="<?= e($r['url']) ?>" target="_blank" rel="noopener">🔗 Recept online</a><?php endif; ?>
        <a class="btn small secondary" href="recept.php?id=<?= $id ?>&amp;edit=1">✏️ Bewerken</a>
      </div>
    </div>
  </div>

  <div class="cols even" style="margin-top:14px">
    <div class="card">
      <h2>🧺 Ingrediënten <span class="muted small">(4 personen)</span></h2>
      <?php $byCat = [];
      foreach ($r['ingredients'] as $i) {
          $byCat[$i['category']][] = $i;
      }
      foreach (SHOP_CATEGORIES as $cat => [$ce, $cl]): if (empty($byCat[$cat])) { continue; } ?>
        <h3 class="muted small" style="margin:10px 0 4px"><?= $ce ?> <?= e(mb_strtoupper($cl)) ?></h3>
        <ul class="list compact">
          <?php foreach ($byCat[$cat] as $i): ?><li><div class="grow"><?= $i['amount'] ? '<b>' . e($i['amount']) . '</b> ' : '' ?><?= e($i['name']) ?></div></li><?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
      <?php if (!$r['ingredients']): ?><p class="muted">Nog geen ingrediënten. <a href="recept.php?id=<?= $id ?>&amp;edit=1">Toevoegen</a></p><?php endif; ?>
    </div>
    <div class="stack">
      <div class="card" id="likes">
        <h2>😋 Wie lust het?</h2>
        <p class="muted small" style="margin-top:0">Tik op 👍 of 👎. Gerechten die alle kinderen lusten komen bovenaan bij het kiezen.</p>
        <ul class="list compact">
          <?php foreach (members() as $m): $v = $r['likes'][(int) $m['id']] ?? ''; ?>
            <li><?= avatar($m, 32) ?><div class="grow"><span class="title"><?= e($m['name']) ?></span></div>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="like"><input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                <button class="btn small <?= $v === 'LIKE' ? 'ok' : 'secondary' ?>" name="verdict" value="LIKE" title="Lekker">👍</button>
                <button class="btn small <?= $v === 'DISLIKE' ? 'danger' : 'secondary' ?>" name="verdict" value="DISLIKE" title="Lust het niet">👎</button></form></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php if ($r['method'] || $r['url']): ?>
        <div class="card">
          <h2>👩‍🍳 Zo maak je het</h2>
          <?php if ($r['method']): ?><p style="white-space:pre-line"><?= e($r['method']) ?></p><?php endif; ?>
          <?php if ($r['url']): ?><p><a href="<?= e($r['url']) ?>" target="_blank" rel="noopener">🔗 Recept online bekijken</a></p><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <?php page_header($r ? '✏️ ' . e($r['name']) : '🍽️ Nieuw gerecht'); ?>
  <?php if (!$r): ?>
    <form method="post" class="card form" style="margin-bottom:14px"><?= csrf_field() ?><input type="hidden" name="action" value="import">
      <h2 style="margin-top:0">🔗 Recept van internet?</h2>
      <p class="muted small" style="margin-top:0">Plak de link (bijv. van Leukerecepten of 24Kitchen). De app haalt naam, ingrediënten, tijd, bereiding en foto op.</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><input name="url" type="url" required placeholder="https://…" style="flex:1;min-width:220px"><button class="btn">Ophalen</button></div>
    </form>
    <p class="muted small">…of vul het gerecht hieronder zelf in:</p>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="card form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div style="display:flex;gap:8px;align-items:flex-end">
      <div style="width:90px"><label for="emoji">Emoji</label><input id="emoji" name="emoji" value="<?= e($f['emoji']) ?>" data-emoji-picker data-default="🍽️"></div>
      <div style="flex:1"><label for="name">Naam van het gerecht</label><input id="name" name="name" value="<?= e($f['name']) ?>" required placeholder="bijv. Lasagne van oma"></div>
    </div>
    <label for="url">🔗 Link naar het recept <small>(optioneel)</small></label><input id="url" name="url" type="url" value="<?= e($f['url']) ?>" placeholder="https://www.leukerecepten.nl/…">
    <label for="prep">Hoe lang duurt het? <small>(minuten)</small></label><input id="prep" name="prep_minutes" type="number" min="0" max="600" value="<?= e($f['prep_minutes']) ?>" style="max-width:140px">
    <label>Wat voor gerecht?</label>
    <div class="picker"><?php foreach (RECIPE_TAGS as $k => [$te, $tl]): ?><label class="pick sm"><input type="checkbox" name="tags[]" value="<?= e($k) ?>"<?= in_array($k, explode(',', (string) $f['tags']), true) ? ' checked' : '' ?>><span><?= $te ?> <?= e($tl) ?></span></label><?php endforeach; ?></div>
    <p class="hint">Hiermee checkt het weekmenu of jullie gezond en gevarieerd eten (vis, peulvruchten, niet te vaak rood vlees).</p>
    <label for="ingredients">Ingrediënten <small>(één per regel, voor 4 personen)</small></label>
    <textarea id="ingredients" name="ingredients" rows="8" placeholder="250 g volkoren pasta&#10;1 blik tomatenblokjes&#10;2 wortels&#10;1 ui"><?= e($ingredientText) ?></textarea>
    <p class="hint">De app zet ze vanzelf in de goede afdeling van de boodschappenlijst (groente, zuivel, vlees…).</p>
    <label for="method">Zo maak je het <small>(optioneel)</small></label>
    <textarea id="method" name="method" rows="5"><?= e($f['method']) ?></textarea>
    <?= photo_field($f['photo'], 'Foto (optioneel)', 'Foto van het gerecht') ?>
    <div class="form-actions"><button class="btn">Opslaan</button><a class="btn secondary" href="<?= $r ? 'recept.php?id=' . $id : 'eten.php?tab=gerechten' ?>">Annuleren</a></div>
  </form>
  <?php if ($r): ?>
    <form method="post" data-confirm="<?= e($r['name']) ?> verwijderen? Op het menu blijft de dag staan, zonder gerecht." style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger">🗑 Gerecht verwijderen</button></form>
  <?php endif; ?>
<?php endif; ?>
<?php page_end();
