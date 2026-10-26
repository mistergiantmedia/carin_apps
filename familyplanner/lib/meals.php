<?php
// Eten & boodschappen: recipes (gerechten), the week menu, a health check after the Dutch "Schijf van Vijf"
// (fish once a week, legumes weekly, vegetables daily, little red meat), kid-friendliness from per-person
// likes, allergy warnings from fp_members.allergies, and the shared shopping list. Keep PHP 7.4 compatible.

// Supermarket order
const SHOP_CATEGORIES = [
    'GROENTE' => ['🥦', 'Groente & fruit'],
    'BROOD' => ['🍞', 'Brood'],
    'ZUIVEL' => ['🧀', 'Zuivel & eieren'],
    'VLEES' => ['🍗', 'Vlees, vis & vega'],
    'DROOG' => ['🍝', 'Pasta, rijst & houdbaar'],
    'DIEPVRIES' => ['🧊', 'Diepvries'],
    'OVERIG' => ['🛒', 'Overig'],
];

const RECIPE_TAGS = [
    'VEG' => ['🌱', 'Vegetarisch'],
    'FISH' => ['🐟', 'Vis'],
    'LEGUMES' => ['🫘', 'Peulvruchten'],
    'REDMEAT' => ['🥩', 'Rood vlees'],
    'WHOLEGRAIN' => ['🌾', 'Volkoren'],
    'QUICK' => ['⏱️', 'Snel klaar'],
];

// Exceptions that the word list below would get wrong (bloemkool is not flour, kokosmelk is not dairy…)
const CATEGORY_EXACT = [
    'bloemkool' => 'GROENTE', 'sperziebonen' => 'GROENTE', 'tuinbonen' => 'GROENTE', 'champignons' => 'GROENTE',
    'kokosmelk' => 'DROOG', 'pindakaas' => 'DROOG', 'tomatenblokjes' => 'DROOG', 'tomatenpuree' => 'DROOG',
];

// Words → shopping category (first match wins; checked in this order)
const CATEGORY_WORDS = [
    'DIEPVRIES' => ['diepvries', 'vissticks', 'doperwten', 'spinazie à la crème', 'ijs'],
    'VLEES' => ['kip', 'gehakt', 'worst', 'ham', 'spek', 'zalm', 'vis', 'kabeljauw', 'tonijn', 'garnalen', 'biefstuk', 'speklap', 'shoarma', 'tofu', 'tempeh', 'vegaburger', 'balletjes', 'filet', 'lapjes', 'vlees'],
    'ZUIVEL' => ['melk', 'kaas', 'yoghurt', 'kwark', 'room', 'boter', 'ei', 'eieren', 'crème fraîche', 'mozzarella', 'feta', 'parmezaan', 'roomkaas'],
    'BROOD' => ['brood', 'wrap', 'tortilla', 'pita', 'stokbrood', 'bolletjes', 'pizzabodem', 'naan'],
    'DROOG' => ['pasta', 'spaghetti', 'macaroni', 'penne', 'rijst', 'couscous', 'noedels', 'mie', 'bloem', 'meel', 'linzen', 'kikkererwten', 'bonen', 'blik', 'pesto', 'saus', 'bouillon', 'olie', 'kruiden', 'curry', 'passata', 'tomatenpuree', 'havermout', 'pindakaas', 'nasikruiden', 'spliterwten', 'ketjap', 'kokosmelk', 'zout', 'peper'],
    'GROENTE' => ['appel', 'banaan', 'peer', 'aardbei', 'citroen', 'limoen', 'avocado', 'ui', 'knoflook', 'tomaat', 'tomaten', 'paprika', 'wortel', 'courgette', 'broccoli', 'bloemkool', 'sla', 'komkommer', 'spinazie', 'prei', 'champignons', 'aardappel', 'krieltjes', 'zoete aardappel', 'boerenkool', 'andijvie', 'mais', 'maïs', 'sperziebonen', 'erwten', 'kool', 'pompoen', 'selderij', 'nasigroente', 'gember', 'peterselie', 'bieslook', 'basilicum', 'koriander', 'groente', 'fruit'],
];

// Allergy words in fp_members.allergies → ingredient words that contain it
const ALLERGY_WORDS = [
    'noot' => ['noot', 'noten', 'pinda', 'pesto', 'amandel', 'cashew', 'hazelnoot', 'walnoot', 'satésaus', 'satesaus'],
    'pinda' => ['pinda', 'satésaus', 'satesaus'],
    'lactose' => ['melk', 'kaas', 'room', 'boter', 'yoghurt', 'kwark', 'mozzarella', 'feta', 'parmezaan', 'crème fraîche'],
    'melk' => ['melk', 'kaas', 'room', 'boter', 'yoghurt', 'kwark', 'mozzarella', 'feta', 'parmezaan', 'crème fraîche'],
    'zuivel' => ['melk', 'kaas', 'room', 'boter', 'yoghurt', 'kwark', 'mozzarella', 'feta', 'parmezaan', 'crème fraîche'],
    'gluten' => ['bloem', 'meel', 'brood', 'pasta', 'spaghetti', 'macaroni', 'penne', 'wrap', 'tortilla', 'couscous', 'pizzabodem', 'paneermeel', 'mie', 'noedels'],
    'ei' => ['ei', 'eieren', 'mayonaise'],
    'vis' => ['vis', 'zalm', 'kabeljauw', 'tonijn', 'vissticks', 'garnalen'],
    'schaaldier' => ['garnalen', 'kreeft', 'mosselen'],
    'soja' => ['soja', 'tofu', 'tempeh', 'ketjap', 'sojasaus'],
    'selderij' => ['selderij', 'bleekselderij'],
    'sesam' => ['sesam'],
    'mosterd' => ['mosterd'],
];

// Healthy, kid-friendly starters (4 persons). [name, emoji, minutes, tags, ingredients "amount|name", method]
const STARTER_RECIPES = [
    ['Volkoren pannenkoeken met appel', '🥞', 30, 'VEG,WHOLEGRAIN', ['250 g|volkoren bloem', '500 ml|halfvolle melk', '2|eieren', '2|appels', '1 el|olie', '|kaneel'], 'Klop bloem, melk en eieren tot een glad beslag. Bak dunne pannenkoeken met dunne plakjes appel erin. Kinderen kunnen zelf de appel erop leggen.'],
    ['Spaghetti bolognese met verstopte groente', '🍝', 35, 'REDMEAT,WHOLEGRAIN', ['400 g|volkoren spaghetti', '300 g|rundergehakt', '1 blik|tomatenblokjes', '2|wortels', '1|courgette', '1|ui', '2 tenen|knoflook', '|geraspte kaas'], 'Rasp wortel en courgette heel fijn: zo zien kinderen de groente niet. Bak ui en gehakt, voeg groente en tomaat toe en laat 15 minuten pruttelen.'],
    ['Zalm uit de oven met krieltjes en broccoli', '🐟', 30, 'FISH,QUICK', ['4 stuks|zalmfilet', '750 g|krieltjes', '2|broccoli', '1|citroen', '2 el|olijfolie'], 'Krieltjes 25 minuten op 200 °C in de oven. Zalm de laatste 12 minuten erbij met citroen. Broccoli kort koken.'],
    ['Vissticks met puree en doperwten', '🐠', 25, 'FISH,QUICK', ['12|vissticks', '1 kg|kruimige aardappelen', '450 g|diepvries doperwten', '150 ml|melk', '1 klontje|boter'], 'Kook de aardappelen en stamp tot puree met melk. Bak de vissticks en kook de doperwten.'],
    ['Linzencurry met rijst', '🍛', 30, 'VEG,LEGUMES,QUICK', ['250 g|rode linzen', '1 blik|kokosmelk', '1 blik|tomatenblokjes', '1|ui', '2 tl|milde currypoeder', '200 g|spinazie', '300 g|zilvervliesrijst'], 'Fruit de ui met curry, voeg linzen, kokosmelk, tomaat en 300 ml water toe. 15 minuten koken, spinazie erdoor roeren. Lekker mild voor kinderen.'],
    ['Wraps met kip en groente', '🌯', 20, 'QUICK,WHOLEGRAIN', ['8|volkoren wraps', '300 g|kipfilet', '2|paprika', '1|komkommer', '1 krop|sla', '1 blik|mais', '200 g|yoghurt'], 'Bak de kip in reepjes. Zet alles in schaaltjes op tafel: iedereen maakt zijn eigen wrap.'],
    ['Groentecurry met kikkererwten', '🥘', 30, 'VEG,LEGUMES', ['2 blikken|kikkererwten', '1|bloemkool', '2|zoete aardappelen', '1 blik|kokosmelk', '2 tl|milde currypoeder', '1|ui', '300 g|zilvervliesrijst'], 'Snijd groente in blokjes en stoof met ui, curry en kokosmelk 20 minuten gaar. Kikkererwten erdoor.'],
    ['Burrito bowl met zwarte bonen', '🌮', 25, 'VEG,LEGUMES,QUICK', ['2 blikken|zwarte bonen', '300 g|zilvervliesrijst', '1 blik|mais', '2|avocado', '3|tomaten', '1|limoen', '100 g|geraspte kaas'], 'Kook de rijst, warm bonen en mais op. Iedereen vult zijn eigen kom.'],
    ['Pizza zelf beleggen', '🍕', 30, 'VEG', ['2|pizzabodems', '1 pak|passata', '2 bollen|mozzarella', '1|paprika', '250 g|champignons', '1 blik|mais'], 'Rol de bodems uit, smeer passata erop en laat de kinderen zelf beleggen. 15 minuten op 220 °C.'],
    ['Macaroni met ham en groente', '🧀', 25, 'QUICK,WHOLEGRAIN', ['400 g|volkoren macaroni', '150 g|hamblokjes', '1|prei', '1|paprika', '1 pak|passata', '100 g|geraspte kaas'], 'Kook de macaroni. Bak ham, prei en paprika, voeg passata toe en meng met de macaroni. Kaas erover.'],
    ['Hutspot met vegaworst', '🥕', 35, 'VEG', ['1 kg|kruimige aardappelen', '750 g|wortels', '3|uien', '2|vegarookworsten', '100 ml|melk'], 'Kook aardappel, wortel en ui samen gaar en stamp met wat melk. Worst erbij verwarmen.'],
    ['Erwtensoep', '🍲', 60, 'LEGUMES', ['500 g|spliterwten', '2|wortels', '1|prei', '1|knolselderij', '1|rookworst', '2|bouillonblokjes'], 'Kook de spliterwten met bouillon ongeveer 45 minuten. Groente in blokjes erbij, nog 15 minuten. Rookworst in plakjes.'],
    ['Nasi met kip en ei', '🍳', 30, 'QUICK', ['300 g|zilvervliesrijst', '300 g|kipfilet', '1 zak|nasigroente', '4|eieren', '2 el|ketjap'], 'Kook de rijst (liefst een dag eerder). Bak kip en groente, rijst erdoor met ketjap. Bak er een eitje bij.'],
    ['Stamppot boerenkool met balletjes', '🥬', 35, 'REDMEAT', ['1 kg|kruimige aardappelen', '500 g|boerenkool', '400 g|gehaktballetjes', '100 ml|melk'], 'Kook aardappelen met de boerenkool erop, stamp met melk. Bak de balletjes.'],
    ['Tomatensoep met balletjes en brood', '🍅', 30, 'QUICK', ['1 kg|tomaten', '1|ui', '1 l|groentebouillon', '200 g|soepballetjes', '1|volkoren stokbrood'], 'Kook tomaten en ui in bouillon, pureer. Balletjes erin gaar laten worden. Brood erbij.'],
    ['Shakshuka (eitjes in tomatensaus)', '🍳', 25, 'VEG,QUICK', ['6|eieren', '1 pak|passata', '2|paprika', '1|ui', '1|volkoren brood', '50 g|feta'], 'Bak ui en paprika, voeg passata toe. Maak kuiltjes en breek de eieren erin. Deksel erop tot de eieren gestold zijn.'],
];

function recipe_tags(array $r): array
{
    return array_values(array_filter(explode(',', (string) ($r['tags'] ?? '')), function ($t) {
        return isset(RECIPE_TAGS[$t]);
    }));
}

/** Does $word occur in $text? Short words (ham, ei, ui, ijs…) only at the start of a word: not in "champignons" or "rijst". */
function has_word(string $text, string $word): bool
{
    if (mb_strlen($word) <= 3) {
        return (bool) preg_match('/(^|[^\p{L}])' . preg_quote($word, '/') . '/u', $text);
    }
    return mb_strpos($text, $word) !== false;
}

function guess_category(string $name): string
{
    $n = mb_strtolower($name);
    foreach (CATEGORY_EXACT as $w => $cat) {
        if (mb_strpos($n, $w) !== false) {
            return $cat;
        }
    }
    foreach (CATEGORY_WORDS as $cat => $words) {
        foreach ($words as $w) {
            if (has_word($n, $w)) {
                return $cat;
            }
        }
    }
    return 'OVERIG';
}

/** "250 g volkoren bloem" → ['250 g', 'volkoren bloem']; "2 eieren" → ['2', 'eieren']; "zout" → ['', 'zout']. */
function parse_ingredient(string $line): array
{
    $line = trim(preg_replace('/^[-•*]\s*/u', '', trim($line)));
    $units = 'g|gr|gram|kg|ml|l|liter|el|tl|stuks?|blikk?(?:en)?|pak(?:ken)?|zak(?:ken)?|bos(?:jes)?|tenen|teen|krop|bollen|bol|klontje|snufje|plakjes|potje|pot|bakje|scheutje';
    if (preg_match('/^((?:\d+[\d.,\/]*|½|¼|¾)\s*(?:(?:' . $units . ')\b)?)\s+(.+)$/iu', $line, $m)) {
        return [trim($m[1]), trim($m[2])];
    }
    return ['', $line];
}

/** Add the starter recipes once per planner family (also for families that join later). */
function seed_recipes(): void
{
    $done = db()->query("SELECT value FROM fp_settings WHERE name = 'recipes_v1'")->fetchColumn();
    if ($done) {
        return;
    }
    foreach (STARTER_RECIPES as [$name, $emoji, $minutes, $tags, $ingredients, $method]) {
        db()->prepare('INSERT INTO fp_recipes (name, emoji, prep_minutes, tags, method, is_starter) VALUES (?, ?, ?, ?, ?, 1)')->execute([$name, $emoji, $minutes, $tags, $method]);
        $rid = (int) db()->lastInsertId();
        foreach ($ingredients as $i => $line) {
            [$amount, $ing] = explode('|', $line, 2);
            db()->prepare('INSERT INTO fp_recipe_ingredients (recipe_id, amount, name, category, sort) VALUES (?, ?, ?, ?, ?)')
                ->execute([$rid, $amount !== '' ? $amount : null, $ing, guess_category($ing), $i]);
        }
    }
    db()->exec("INSERT INTO fp_settings (name, value) VALUES ('recipes_v1', '1') ON DUPLICATE KEY UPDATE value = '1'");
}

/** All recipes, keyed by id, with 'ingredients' (rows), 'likes' (member id => LIKE|DISLIKE) and 'last' (last date on the menu). */
function recipes(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach (db()->query('SELECT r.*, (SELECT MAX(m.day) FROM fp_meals m WHERE m.recipe_id = r.id AND m.day <= CURDATE()) AS last FROM fp_recipes r ORDER BY r.name') as $r) {
        $r['ingredients'] = [];
        $r['likes'] = [];
        $cache[(int) $r['id']] = $r;
    }
    foreach (db()->query('SELECT * FROM fp_recipe_ingredients ORDER BY sort, id') as $i) {
        if (isset($cache[(int) $i['recipe_id']])) {
            $cache[(int) $i['recipe_id']]['ingredients'][] = $i;
        }
    }
    foreach (db()->query('SELECT * FROM fp_recipe_likes') as $l) {
        if (isset($cache[(int) $l['recipe_id']])) {
            $cache[(int) $l['recipe_id']]['likes'][(int) $l['member_id']] = $l['verdict'];
        }
    }
    return $cache;
}

/** Kid-friendliness: ['likes' => n, 'dislikes' => [names], 'all' => bool (every child likes it)]. */
function recipe_kids(array $r): array
{
    $kids = children();
    $likes = 0;
    $dislikes = [];
    foreach ($kids as $k) {
        $v = $r['likes'][(int) $k['id']] ?? '';
        if ($v === 'LIKE') {
            $likes++;
        } elseif ($v === 'DISLIKE') {
            $dislikes[] = $k['name'];
        }
    }
    return ['likes' => $likes, 'dislikes' => $dislikes, 'all' => $kids && $likes === count($kids)];
}

/** Allergy warnings for a recipe: list of "Bodi: pesto (noten)". */
function recipe_allergy_warnings(array $r): array
{
    $out = [];
    foreach (members() as $m) {
        $allergy = mb_strtolower((string) ($m['allergies'] ?? ''));
        if ($allergy === '') {
            continue;
        }
        foreach (ALLERGY_WORDS as $key => $words) {
            if (mb_strpos($allergy, $key) === false) {
                continue;
            }
            foreach ($r['ingredients'] as $i) {
                $ing = ' ' . mb_strtolower($i['name']) . ' ';
                foreach ($words as $w) {
                    if (has_word($ing, $w)) {
                        $out[$m['name'] . $i['name']] = $m['name'] . ': ' . $i['name'];
                        break;
                    }
                }
            }
        }
    }
    return array_values($out);
}

/** Meals between two dates (inclusive), keyed by day, with 'recipe' (row or null). */
function meals_between(string $from, string $to): array
{
    $stmt = db()->prepare('SELECT * FROM fp_meals WHERE day BETWEEN ? AND ? ORDER BY day');
    $stmt->execute([$from, $to]);
    $out = [];
    $all = recipes();
    foreach ($stmt as $m) {
        $m['recipe'] = $m['recipe_id'] ? ($all[(int) $m['recipe_id']] ?? null) : null;
        $out[$m['day']] = $m;
    }
    return $out;
}

function meal_title(array $meal): string
{
    return $meal['recipe'] ? $meal['recipe']['name'] : ((string) $meal['title'] ?: '(gerecht verwijderd)');
}

function meal_emoji(array $meal): string
{
    return $meal['recipe'] && $meal['recipe']['emoji'] ? $meal['recipe']['emoji'] : '🍽️';
}

/**
 * Health check of a week of meals after the Schijf van Vijf. Returns rows [ok (bool|null), emoji, text].
 * null = not enough planned yet to say.
 */
function week_health(array $meals): array
{
    $count = ['FISH' => 0, 'LEGUMES' => 0, 'REDMEAT' => 0, 'VEG' => 0, 'veggies' => 0, 'planned' => 0];
    foreach ($meals as $m) {
        if (!$m['recipe']) {
            continue;
        }
        $count['planned']++;
        foreach (recipe_tags($m['recipe']) as $t) {
            if (isset($count[$t])) {
                $count[$t]++;
            }
        }
        foreach ($m['recipe']['ingredients'] as $i) {
            if ($i['category'] === 'GROENTE' && !preg_match('/appel|banaan|peer|citroen|limoen|aardappel|krieltjes|ui$|knoflook/u', mb_strtolower($i['name']))) {
                $count['veggies']++;
                break;
            }
        }
    }
    $p = $count['planned'];
    return [
        [$count['FISH'] >= 1 ? true : ($p >= 5 ? false : null), '🐟', $count['FISH'] >= 1 ? 'Vis deze week ✓' : 'Nog geen vis (1x per week is gezond)'],
        [$count['LEGUMES'] >= 1 ? true : ($p >= 5 ? false : null), '🫘', $count['LEGUMES'] >= 1 ? 'Peulvruchten deze week ✓' : 'Nog geen peulvruchten (bonen, linzen, kikkererwten)'],
        [$count['REDMEAT'] <= 2, '🥩', $count['REDMEAT'] <= 2 ? 'Rood vlees: ' . $count['REDMEAT'] . 'x (max 2x per week)' : 'Rood vlees ' . $count['REDMEAT'] . 'x: liever max 2x per week'],
        [$count['VEG'] >= 2 ? true : ($p >= 5 ? false : null), '🌱', $count['VEG'] >= 2 ? $count['VEG'] . ' vegetarische dagen ✓' : $count['VEG'] . ' vegetarische dag(en), 2 of meer is fijn'],
        [$p === 0 ? null : $count['veggies'] >= $p, '🥦', $p === 0 ? 'Elke dag groente' : 'Groente in ' . $count['veggies'] . ' van de ' . $p . ' maaltijden'],
    ];
}

/** Evenings with something in the agenda between 16:30 and 19:30: day => [titles] (then pick something quick). */
function busy_evenings(string $from, string $to): array
{
    $out = [];
    foreach (load_events($from, date('Y-m-d', strtotime("$to +1 day"))) as $ev) {
        if ($ev['all_day'] || in_array($ev['type'], ['SCHOOL', 'WORK', 'STUDYDAY', 'STUDYPM', 'SCHOOLHOLIDAY', 'HOLIDAY'], true)) {
            continue;
        }
        $day = substr($ev['start_at'], 0, 10);
        if ($ev['start_at'] < $day . ' 19:30:00' && $ev['end_at'] > $day . ' 16:30:00') {
            $out[$day][] = $ev['title'];
        }
    }
    return $out;
}

/**
 * A suggestion for an empty day: no child dislikes it, not eaten in the last 10 days, not already this week, and
 * it fills a gap in the week (fish, legumes, vegetarian) or is quick on a busy evening.
 */
function suggest_recipe(string $day, array $weekMeals, bool $busy): ?array
{
    $used = [];
    foreach ($weekMeals as $m) {
        if ($m['recipe_id']) {
            $used[(int) $m['recipe_id']] = true;
        }
    }
    $health = [];
    foreach (week_health($weekMeals) as [$ok, $emoji]) {
        $health[$emoji] = $ok;
    }
    $best = null;
    $bestScore = -999;
    foreach (recipes() as $id => $r) {
        if (isset($used[$id])) {
            continue;
        }
        $kids = recipe_kids($r);
        $tags = recipe_tags($r);
        $score = mt_rand(0, 30) / 10 + $kids['likes'] * 2 - count($kids['dislikes']) * 6 - count(recipe_allergy_warnings($r)) * 10;
        if ($r['last'] && $r['last'] > date('Y-m-d', strtotime("$day -10 day"))) {
            $score -= 5;
        }
        if (in_array('FISH', $tags, true) && $health['🐟'] !== true) {
            $score += 4;
        }
        if (in_array('LEGUMES', $tags, true) && $health['🫘'] !== true) {
            $score += 3;
        }
        if (in_array('VEG', $tags, true) && $health['🌱'] !== true) {
            $score += 2;
        }
        if (in_array('REDMEAT', $tags, true) && !$health['🥩']) {
            $score -= 6;
        }
        if ($busy) {
            $score += in_array('QUICK', $tags, true) ? 4 : (($r['prep_minutes'] ?? 30) > 35 ? -4 : 0);
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    return $best;
}

/** Put the ingredients of the meals in [$from, $to] that are not on the list yet onto the shopping list. Returns items added/updated. */
function add_meals_to_list(string $from, string $to): int
{
    $n = 0;
    foreach (meals_between($from, $to) as $meal) {
        if ($meal['on_list'] || !$meal['recipe']) {
            continue;
        }
        foreach ($meal['recipe']['ingredients'] as $i) {
            $n += add_shopping_item($i['name'], $i['amount'], $i['category'], $meal['recipe']['name']);
        }
        db()->prepare('UPDATE fp_meals SET on_list = 1 WHERE id = ?')->execute([$meal['id']]);
    }
    return $n;
}

/** Add to the shopping list; the same open item gets the amount added ("2 + 1") instead of a second line. */
function add_shopping_item(string $name, ?string $amount, ?string $category = null, ?string $forMeal = null): int
{
    $name = mb_cut(trim($name), 120);
    if ($name === '') {
        return 0;
    }
    $category = isset(SHOP_CATEGORIES[$category ?? '']) ? $category : guess_category($name);
    $stmt = db()->prepare('SELECT * FROM fp_shopping WHERE done_at IS NULL AND LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$name]);
    $user = current_user();
    if ($row = $stmt->fetch()) {
        $amounts = array_values(array_filter([(string) $row['amount'], (string) $amount], 'strlen'));
        $meals = array_values(array_unique(array_filter(array_merge(explode(', ', (string) $row['for_meals']), [$forMeal]))));
        db()->prepare('UPDATE fp_shopping SET amount = ?, for_meals = ? WHERE id = ?')
            ->execute([$amounts ? mb_cut(implode(' + ', $amounts), 80) : null, $meals ? mb_cut(implode(', ', $meals), 255) : null, $row['id']]);
        return 1;
    }
    db()->prepare('INSERT INTO fp_shopping (name, amount, category, for_meals, created_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$name, $amount ? mb_cut($amount, 80) : null, $category, $forMeal ? mb_cut($forMeal, 255) : null, $user ? $user['id'] : null]);
    return 1;
}

/** Open shopping items grouped by category (supermarket order), plus the ticked ones (last 24 hours). */
function shopping_list(): array
{
    $open = [];
    foreach (array_keys(SHOP_CATEGORIES) as $c) {
        $open[$c] = [];
    }
    foreach (db()->query('SELECT * FROM fp_shopping WHERE done_at IS NULL ORDER BY name') as $s) {
        $open[isset(SHOP_CATEGORIES[$s['category']]) ? $s['category'] : 'OVERIG'][] = $s;
    }
    $done = db()->query('SELECT * FROM fp_shopping WHERE done_at IS NOT NULL ORDER BY done_at DESC LIMIT 40')->fetchAll();
    return [array_filter($open), $done];
}
