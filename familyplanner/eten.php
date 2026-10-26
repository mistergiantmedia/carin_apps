<?php
// Eten & boodschappen: the week menu (with a health check and suggestions), the shared shopping list, and the
// family's recipes. ?tab=menu (default) | lijst | gerechten. ?week=<offset> for the menu.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';
require __DIR__ . '/lib/meals.php';
$user = require_login();
seed_recipes();

$tab = in_array($_GET['tab'] ?? '', ['lijst', 'gerechten'], true) ? $_GET['tab'] : 'menu';
$weekOffset = (int) ($_GET['week'] ?? 0);
$monday = date('Y-m-d', strtotime('monday this week', strtotime(today())));
$weekStart = date('Y-m-d', strtotime("$monday +" . ($weekOffset * 7) . ' day'));
$weekEnd = date('Y-m-d', strtotime("$weekStart +6 day"));
$back = 'eten.php?tab=' . $tab . ($weekOffset ? '&week=' . $weekOffset : '');

if (is_post()) {
    $action = post('action');
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('day')) ? post('day') : null;
    $saveMeal = function (string $day, ?int $recipeId, ?string $title) {
        $cook = member(post_int('cook')) ? post_int('cook') : null;
        db()->prepare('INSERT INTO fp_meals (day, recipe_id, title, cook_member_id) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE recipe_id = VALUES(recipe_id), title = VALUES(title), cook_member_id = VALUES(cook_member_id), on_list = 0')
            ->execute([$day, $recipeId, $title, $cook]);
    };
    if ($action === 'set_meal' && $day) {
        $rid = isset(recipes()[post_int('recipe_id')]) ? post_int('recipe_id') : null;
        $title = post('title') !== '' ? mb_cut(post('title'), 160) : null;
        if ($rid || $title) {
            $saveMeal($day, $rid, $rid ? null : $title); // a chosen recipe wins over the typed text
        }
    } elseif ($action === 'clear_meal' && $day) {
        db()->prepare('DELETE FROM fp_meals WHERE day = ?')->execute([$day]);
    } elseif ($action === 'suggest' || $action === 'suggest_week') {
        $busy = busy_evenings($weekStart, $weekEnd);
        $days = $action === 'suggest' && $day ? [$day] : [];
        if ($action === 'suggest_week') {
            $have = meals_between($weekStart, $weekEnd);
            for ($i = 0; $i < 7; $i++) {
                $d = date('Y-m-d', strtotime("$weekStart +$i day"));
                if (!isset($have[$d]) && $d >= today()) {
                    $days[] = $d;
                }
            }
        }
        foreach ($days as $d) {
            $r = suggest_recipe($d, meals_between($weekStart, $weekEnd), isset($busy[$d]));
            if ($r) {
                $saveMeal($d, (int) $r['id'], null);
            }
        }
        flash($days ? '💡 ' . (count($days) === 1 ? 'Er is een gerecht gekozen' : count($days) . ' dagen ingevuld') . '. Niet goed? Kies gewoon iets anders.' : 'Alle dagen zijn al ingevuld.');
    } elseif ($action === 'to_list') {
        $n = add_meals_to_list($weekStart, $weekEnd);
        flash($n ? '🛒 ' . $n . ' ingrediënten op de boodschappenlijst gezet' : 'Alles van deze week staat al op de lijst.');
        redirect('eten.php?tab=lijst');
    } elseif ($action === 'shop_add') {
        foreach (preg_split('/\r?\n/', post('name')) as $line) {
            [$amount, $name] = parse_ingredient($line);
            add_shopping_item($name, post('amount') !== '' ? post('amount') : ($amount ?: null));
        }
    } elseif ($action === 'shop_toggle') {
        db()->prepare('UPDATE fp_shopping SET done_at = IF(done_at IS NULL, NOW(), NULL) WHERE id = ?')->execute([post_int('id')]);
    } elseif ($action === 'shop_delete') {
        db()->prepare('DELETE FROM fp_shopping WHERE id = ?')->execute([post_int('id')]);
    } elseif ($action === 'shop_clear_done') {
        db()->exec('DELETE FROM fp_shopping WHERE done_at IS NOT NULL');
        flash('Afgevinkte boodschappen weggehaald');
    }
    redirect($back . (post('anchor') ? '#' . post('anchor') : ''));
}

$recipes = recipes();
// Recipes for the pickers: what every child likes first
$sorted = $recipes;
uasort($sorted, function ($a, $b) {
    $ka = recipe_kids($a);
    $kb = recipe_kids($b);
    return [count($ka['dislikes']), -$ka['likes'], $a['name']] <=> [count($kb['dislikes']), -$kb['likes'], $b['name']];
});

/** Little faces of the children with 👍/👎 for a recipe. */
function kid_likes_html(array $r): string
{
    $html = '';
    foreach (children() as $k) {
        $v = $r['likes'][(int) $k['id']] ?? '';
        if ($v !== '') {
            $html .= '<span class="kid-like" title="' . e($k['name'] . ($v === 'LIKE' ? ' vindt het lekker' : ' lust het niet')) . '">' . avatar($k, 22) . ($v === 'LIKE' ? '👍' : '👎') . '</span>';
        }
    }
    return $html;
}

/** Choose a dinner for a day: a recipe (kid favourites first), or type anything ("Uit eten", "Restjes"). */
function meal_form(string $d, ?array $meal, array $sorted): string
{
    $opts = '<option value="">Kies een gerecht…</option>';
    foreach ($sorted as $id => $r) {
        $kids = recipe_kids($r);
        $mark = $kids['all'] ? '⭐ ' : ($kids['dislikes'] ? '👎 ' : '');
        $opts .= '<option value="' . (int) $id . '"' . ($meal && (int) $meal['recipe_id'] === (int) $id ? ' selected' : '') . '>'
            . e($mark . ($r['emoji'] ? $r['emoji'] . ' ' : '') . $r['name']) . '</option>';
    }
    $cooks = '<option value="">Wie kookt? (optioneel)</option>';
    foreach (members() as $m) {
        if ($m['role'] === 'PARENT') {
            $cooks .= '<option value="' . (int) $m['id'] . '"' . ($meal && (int) $meal['cook_member_id'] === (int) $m['id'] ? ' selected' : '') . '>🧑‍🍳 ' . e($m['name']) . '</option>';
        }
    }
    $csrf = csrf_field();
    $html = '<form method="post" class="meal-form">' . $csrf . '<input type="hidden" name="action" value="set_meal"><input type="hidden" name="day" value="' . e($d) . '"><input type="hidden" name="anchor" value="d' . e($d) . '">'
        . '<select name="recipe_id" onchange="if(this.value)this.form.title.value=&quot;&quot;">' . $opts . '</select>'
        . '<input name="title" oninput="if(this.value)this.form.recipe_id.value=&quot;&quot;" placeholder="…of typ iets: Uit eten, Restjes" value="' . e($meal && !$meal['recipe_id'] ? (string) $meal['title'] : '') . '">'
        . '<select name="cook">' . $cooks . '</select>'
        . '<button class="btn small">Opslaan</button></form>'
        . '<div class="meal-actions"><form method="post" class="inline">' . $csrf . '<input type="hidden" name="action" value="suggest"><input type="hidden" name="day" value="' . e($d) . '"><input type="hidden" name="anchor" value="d' . e($d) . '">'
        . '<button class="btn small soft">💡 Kies voor mij</button></form>';
    if ($meal) {
        $html .= ' <form method="post" class="inline">' . $csrf . '<input type="hidden" name="action" value="clear_meal"><input type="hidden" name="day" value="' . e($d) . '"><input type="hidden" name="anchor" value="d' . e($d) . '"><button class="btn small secondary">✕ Leegmaken</button></form>';
    }
    return $html . '</div>';
}

function tag_icons(array $r): string
{
    return implode('', array_map(function ($t) {
        return '<span title="' . e(RECIPE_TAGS[$t][1]) . '">' . RECIPE_TAGS[$t][0] . '</span>';
    }, recipe_tags($r)));
}

page_start('Eten & boodschappen');
page_header('🍽️ Eten & boodschappen', 'Wat eten we deze week, is het gezond en lusten de kinderen het? En wat moet er nog gehaald worden.');
?>
<div class="tabs" style="margin-bottom:16px">
  <a href="eten.php" class="<?= $tab === 'menu' ? 'on' : '' ?>">📅 Weekmenu</a>
  <a href="eten.php?tab=lijst" class="<?= $tab === 'lijst' ? 'on' : '' ?>">🛒 Boodschappen<?php $open = (int) db()->query('SELECT COUNT(*) FROM fp_shopping WHERE done_at IS NULL')->fetchColumn(); echo $open ? ' (' . $open . ')' : ''; ?></a>
  <a href="eten.php?tab=gerechten" class="<?= $tab === 'gerechten' ? 'on' : '' ?>">📖 Gerechten (<?= count($recipes) ?>)</a>
</div>

<?php if ($tab === 'menu'):
    $meals = meals_between($weekStart, $weekEnd);
    $busy = busy_evenings($weekStart, $weekEnd);
    $notOnList = count(array_filter($meals, function ($m) {
        return $m['recipe'] && !$m['on_list'];
    })); ?>
  <div class="section-title" style="margin-top:0">
    <h2>Week <?= (int) date('W', strtotime($weekStart)) ?> <span class="muted small"><?= e(format_date_short($weekStart)) ?> – <?= e(format_date_short($weekEnd)) ?></span></h2>
    <span><a class="btn small secondary" href="eten.php?week=<?= $weekOffset - 1 ?>">‹ Vorige</a> <?php if ($weekOffset): ?><a class="btn small secondary" href="eten.php">Deze week</a> <?php endif; ?><a class="btn small secondary" href="eten.php?week=<?= $weekOffset + 1 ?>">Volgende ›</a></span>
  </div>
  <div class="cols">
    <div class="stack">
      <?php for ($i = 0; $i < 7; $i++):
          $d = date('Y-m-d', strtotime("$weekStart +$i day"));
          $meal = $meals[$d] ?? null;
          $r = $meal['recipe'] ?? null; ?>
        <div class="card meal-day<?= $d === today() ? ' today' : '' ?><?= $d < today() ? ' past' : '' ?>" id="d<?= e($d) ?>">
          <div class="meal-head">
            <b><?= e(ucfirst(DAYS[(int) date('w', strtotime($d))])) ?> <?= (int) substr($d, 8) ?> <?= e(MONTHS[(int) substr($d, 5, 2)]) ?></b>
            <?php if ($d === today()): ?><span class="badge accent">vandaag</span><?php endif; ?>
            <?php if (!empty($busy[$d])): ?><span class="badge warn" title="<?= e(implode(', ', $busy[$d])) ?>">⏱️ Druk: <?= e(excerpt(implode(', ', $busy[$d]), 40)) ?></span><?php endif; ?>
          </div>
          <?php if ($meal): ?>
            <div class="meal-now">
              <span class="meal-emoji"><?= meal_emoji($meal) ?></span>
              <div class="grow">
                <?php if ($r): ?><a class="meal-title" href="recept.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a><?php else: ?><span class="meal-title"><?= e($meal['title'] ?: '(gerecht verwijderd)') ?></span><?php endif; ?>
                <div class="meal-meta">
                  <?= $r ? tag_icons($r) : '' ?>
                  <?= $r && $r['prep_minutes'] ? '<span class="muted small">' . (int) $r['prep_minutes'] . ' min</span>' : '' ?>
                  <?= $r ? kid_likes_html($r) : '' ?>
                  <?php if ($meal['cook_member_id'] && member((int) $meal['cook_member_id'])): ?><span class="small">🧑‍🍳 <?= e(member((int) $meal['cook_member_id'])['name']) ?> kookt</span><?php endif; ?>
                  <?php if ($r && $meal['on_list']): ?><span class="small muted">🛒 op de lijst</span><?php endif; ?>
                </div>
                <?php if ($r && ($warn = recipe_allergy_warnings($r))): ?><div class="small" style="color:var(--bad)">⚠️ Let op allergie: <?= e(implode(', ', $warn)) ?></div><?php endif; ?>
                <?php if ($r && ($kids = recipe_kids($r)) && $kids['dislikes']): ?><div class="small muted">👎 <?= e(implode(' & ', $kids['dislikes'])) ?> lust dit niet</div><?php endif; ?>
              </div>
              <details class="meal-edit"><summary class="btn small secondary">Wijzig</summary><?= meal_form($d, $meal, $sorted) ?></details>
            </div>
          <?php else: ?>
            <?= meal_form($d, null, $sorted) ?>
          <?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
    <div class="stack">
      <div class="card">
        <h2>🥗 Gezond deze week?</h2>
        <p class="muted small" style="margin-top:0">Volgens de Schijf van Vijf van het Voedingscentrum.</p>
        <ul class="list compact">
          <?php foreach (week_health($meals) as [$ok, $emoji, $text]): ?>
            <li><span><?= $emoji ?></span><div class="grow"><span class="<?= $ok === false ? 'warn-text' : ($ok ? '' : 'muted') ?>"><?= e($text) ?></span></div><span><?= $ok === true ? '✅' : ($ok === false ? '⚠️' : '·') ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="card">
        <h2>Snel klaar</h2>
        <form method="post" style="margin-bottom:8px"><?= csrf_field() ?><input type="hidden" name="action" value="suggest_week">
          <button class="btn wide">💡 Vul de lege dagen voor mij in</button></form>
        <p class="muted small">Kiest gerechten die de kinderen lusten, die deze week gezonder maken, en iets snels op drukke avonden.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="to_list">
          <button class="btn wide secondary"<?= $notOnList ? '' : ' disabled' ?>>🛒 Ingrediënten op de boodschappenlijst<?= $notOnList ? ' (' . $notOnList . ' gerechten)' : '' ?></button></form>
      </div>
    </div>
  </div>

<?php elseif ($tab === 'lijst'):
    [$open, $done] = shopping_list(); ?>
  <div class="cols">
    <div class="stack">
      <form method="post" class="card form shop-add"><?= csrf_field() ?><input type="hidden" name="action" value="shop_add">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <div style="flex:2;min-width:180px"><label for="shop-name">Wat moet er gehaald worden?</label><input id="shop-name" name="name" placeholder="bijv. 2 liter melk" required autocomplete="off" autofocus></div>
          <div style="flex:1;min-width:100px"><label for="shop-amount">Hoeveel <small>(optioneel)</small></label><input id="shop-amount" name="amount" placeholder="2 pakken" autocomplete="off"></div>
          <button class="btn">＋ Toevoegen</button>
        </div>
      </form>
      <?php if (!$open): ?>
        <div class="card"><?= empty_state('🛒', 'De boodschappenlijst is leeg.', '<a class="btn" href="eten.php">📅 Naar het weekmenu</a>') ?></div>
      <?php endif; ?>
      <?php foreach ($open as $cat => $items): [$cEmoji, $cLabel] = SHOP_CATEGORIES[$cat]; ?>
        <div class="card shop-cat">
          <h3><?= $cEmoji ?> <?= e($cLabel) ?> <span class="muted small">(<?= count($items) ?>)</span></h3>
          <ul class="shop-list">
            <?php foreach ($items as $s): ?>
              <li><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="shop_toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <input type="checkbox" class="tick" onchange="this.form.submit()" aria-label="<?= e($s['name']) ?> afvinken"></form>
                <div class="grow"><b><?= e($s['name']) ?></b><?= $s['amount'] ? ' <span class="muted">' . e($s['amount']) . '</span>' : '' ?>
                  <?php if ($s['for_meals']): ?><div class="small muted">voor <?= e($s['for_meals']) ?></div><?php endif; ?></div>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="shop_delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="link muted small" title="Weghalen">✕</button></form></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="stack">
      <?php if ($done): ?>
        <div class="card">
          <div class="section-title" style="margin-top:0"><h2>✅ In het mandje</h2>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="shop_clear_done"><button class="btn small secondary">Weghalen</button></form></div>
          <ul class="shop-list done">
            <?php foreach ($done as $s): ?>
              <li><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="shop_toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <input type="checkbox" class="tick" checked onchange="this.form.submit()" aria-label="<?= e($s['name']) ?> terugzetten"></form>
                <div class="grow"><s><?= e($s['name']) ?></s><?= $s['amount'] ? ' <span class="muted">' . e($s['amount']) . '</span>' : '' ?></div></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <div class="card">
        <h2>💡 Tip</h2>
        <p class="muted small" style="margin:0">Deze lijst is van het hele gezin. Wie in de winkel staat, vinkt af wat in het mandje ligt; thuis zie je het meteen verdwijnen. Meerdere dingen tegelijk? Zet ze onder elkaar in het vak.</p>
      </div>
    </div>
  </div>

<?php else:
    $q = trim((string) ($_GET['q'] ?? ''));
    $tag = isset(RECIPE_TAGS[$_GET['tag'] ?? '']) ? $_GET['tag'] : '';
    $shown = array_filter($sorted, function ($r) use ($q, $tag) {
        if ($tag !== '' && !in_array($tag, recipe_tags($r), true)) {
            return false;
        }
        if ($q === '') {
            return true;
        }
        $hay = mb_strtolower($r['name'] . ' ' . implode(' ', array_column($r['ingredients'], 'name')));
        return mb_strpos($hay, mb_strtolower($q)) !== false;
    }); ?>
  <form class="filters" method="get">
    <input type="hidden" name="tab" value="gerechten">
    <input class="search" type="search" name="q" value="<?= e($q) ?>" placeholder="🔍 Zoek gerecht of ingrediënt…">
    <div class="picker">
      <a class="pick sm<?= $tag === '' ? ' on' : '' ?>" href="eten.php?tab=gerechten"><span>Alles</span></a>
      <?php foreach (RECIPE_TAGS as $k => [$te, $tl]): ?><a class="chip<?= $tag === $k ? '' : '' ?>" style="<?= $tag === $k ? '--c:var(--accent)' : '--c:#8a8a8a' ?>" href="eten.php?tab=gerechten&amp;tag=<?= e($k) ?>"><?= $te ?> <?= e($tl) ?></a> <?php endforeach; ?>
    </div>
    <a class="btn" href="recept.php?new=1">＋ Nieuw gerecht</a>
  </form>
  <div class="grid recipe-grid">
    <?php foreach ($shown as $r): $kids = recipe_kids($r); $warn = recipe_allergy_warnings($r); ?>
      <a class="card recipe-card" href="recept.php?id=<?= (int) $r['id'] ?>">
        <?php if ($r['photo']): ?><img src="foto.php?f=<?= e($r['photo']) ?>&amp;s=t" alt=""><?php else: ?><span class="recipe-emoji"><?= e($r['emoji'] ?: '🍽️') ?></span><?php endif; ?>
        <b><?= e($r['name']) ?></b>
        <div class="meal-meta"><?= tag_icons($r) ?><?= $r['prep_minutes'] ? '<span class="muted small">' . (int) $r['prep_minutes'] . ' min</span>' : '' ?><?= kid_likes_html($r) ?></div>
        <?php if ($kids['all']): ?><span class="badge ok">⭐ Iedereen lust het</span><?php endif; ?>
        <?php if ($warn): ?><span class="small" style="color:var(--bad)">⚠️ <?= e(implode(', ', $warn)) ?></span><?php endif; ?>
        <?php if ($r['last']): ?><span class="small muted">Laatst gegeten <?= e(format_date_short($r['last'])) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (!$shown): ?><div class="card"><?= empty_state('📖', 'Geen gerechten gevonden.', '<a class="btn" href="recept.php?new=1">＋ Nieuw gerecht</a>') ?></div><?php endif; ?>
<?php endif; ?>
<?php page_end();
