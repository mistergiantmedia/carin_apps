<?php
// Days off and special days: settings, Dutch public holidays and fun days (computed, not stored),
// school holidays (imported from the Rijksoverheid open data API as SCHOOLHOLIDAY events),
// study days / afternoons (STUDYDAY / STUDYPM events) and ideas for what to do on them.

const FREE_TYPES = ['STUDYDAY', 'STUDYPM', 'SCHOOLHOLIDAY'];
const SCHOOL_REGIONS = ['noord' => 'Noord', 'midden' => 'Midden', 'zuid' => 'Zuid'];

function setting(string $name, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT name, value FROM fp_settings') as $r) {
                $cache[$r['name']] = $r['value'];
            }
        } catch (Throwable $e) {
            // table not there yet (before migration 013)
        }
    }
    return $cache[$name] ?? $default;
}

function set_setting(string $name, ?string $value): void
{
    db()->prepare('INSERT INTO fp_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$name, $value]);
}

/** Easter Sunday (Y-m-d), anonymous Gregorian algorithm (no calendar extension needed). */
function easter_sunday(int $y): string
{
    $a = $y % 19;
    $b = intdiv($y, 100);
    $c = $y % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $y, $month, $day);
}

/**
 * Special days in [$from, $to): official Dutch holidays (official = usually no school/work) and fun days.
 * Each: date, key, title, emoji, official.
 */
function load_feasts(string $from, string $to): array
{
    $out = [];
    for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
        $easter = easter_sunday($y);
        $e = function (int $days) use ($easter) {
            return date('Y-m-d', strtotime("$easter $days day"));
        };
        $nthSunday = function (int $month, int $n) use ($y) {
            $d = strtotime(sprintf('%04d-%02d-01', $y, $month));
            $first = strtotime('sunday', $d); // the first Sunday on or after the 1st
            return date('Y-m-d', strtotime('+' . ($n - 1) . ' week', $first));
        };
        $kingsDay = sprintf('%04d-04-27', $y);
        if (date('w', strtotime($kingsDay)) === '0') {
            $kingsDay = sprintf('%04d-04-26', $y);
        }
        $list = [
            [sprintf('%04d-01-01', $y), 'NEWYEAR', 'Nieuwjaarsdag', '🎆', true],
            [sprintf('%04d-02-14', $y), 'VALENTINE', 'Valentijnsdag', '❤️', false],
            [$e(-49), 'CARNIVAL', 'Carnaval', '🎭', false],
            [$e(-2), 'GOODFRIDAY', 'Goede Vrijdag', '✝️', false],
            [$easter, 'EASTER', 'Eerste Paasdag', '🐣', true],
            [$e(1), 'EASTER', 'Tweede Paasdag', '🐣', true],
            [$kingsDay, 'KINGSDAY', 'Koningsdag', '👑', true],
            [sprintf('%04d-05-05', $y), 'LIBERATION', 'Bevrijdingsdag', '🕊️', true],
            [$nthSunday(5, 2), 'MOTHERSDAY', 'Moederdag', '💐', false],
            [$e(39), 'ASCENSION', 'Hemelvaartsdag', '☁️', true],
            [$e(49), 'PENTECOST', 'Eerste Pinksterdag', '🌷', true],
            [$e(50), 'PENTECOST', 'Tweede Pinksterdag', '🌷', true],
            [$nthSunday(6, 3), 'FATHERSDAY', 'Vaderdag', '👔', false],
            [sprintf('%04d-10-04', $y), 'ANIMALDAY', 'Dierendag', '🐶', false],
            [sprintf('%04d-10-31', $y), 'HALLOWEEN', 'Halloween', '🎃', false],
            [sprintf('%04d-11-11', $y), 'STMARTIN', 'Sint-Maarten', '🏮', false],
            [sprintf('%04d-12-05', $y), 'SINTERKLAAS', 'Pakjesavond', '🎁', false],
            [sprintf('%04d-12-25', $y), 'CHRISTMAS', 'Eerste Kerstdag', '🎄', true],
            [sprintf('%04d-12-26', $y), 'CHRISTMAS', 'Tweede Kerstdag', '🎄', true],
            [sprintf('%04d-12-31', $y), 'NEWYEARSEVE', 'Oudejaarsavond', '🎇', false],
        ];
        foreach ($list as [$date, $key, $title, $emoji, $official]) {
            if ($date >= $from && $date < $to) {
                $out[] = ['date' => $date, 'key' => $key, 'title' => $title, 'emoji' => $emoji, 'official' => $official];
            }
        }
    }
    usort($out, function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    return $out;
}

/** Calendar item for a special day (like birthday_json). */
function feast_json(array $f): array
{
    return [
        'id' => 'f-' . $f['key'] . '-' . $f['date'],
        'feast' => true,
        'title' => $f['emoji'] . ' ' . $f['title'],
        'name' => $f['title'],
        'start' => $f['date'] . 'T00:00',
        'end' => $f['date'] . 'T23:59',
        'allDay' => true,
        'color' => $f['official'] ? '#E8A317' : '#8E6CDF',
        'official' => $f['official'],
        'link' => 'vrijedagen.php#f' . $f['date'],
        'members' => [],
    ];
}

/**
 * Fetch the official school holidays for a school year (e.g. "2026-2027") and region from the
 * Rijksoverheid open data API, and add the missing ones as SCHOOLHOLIDAY events for the children.
 * Returns the number added. Throws with a Dutch message when the API can't be reached.
 */
function import_school_holidays(string $schoolYear, string $region): int
{
    $url = 'https://opendata.rijksoverheid.nl/v1/infotypes/schoolholidays/schoolyear/' . rawurlencode($schoolYear) . '?output=json';
    $json = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => true]);
        $json = curl_exec($ch);
        curl_close($ch);
    }
    if (!$json) {
        $json = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 12]]));
    }
    $data = $json ? json_decode($json, true) : null;
    if (empty($data['content'][0]['vacations'])) {
        throw new RuntimeException('De schoolvakanties konden niet worden opgehaald bij de Rijksoverheid. Probeer het later nog eens, of voeg ze zelf toe.');
    }
    $kids = array_map('intval', array_keys(children()));
    $added = 0;
    foreach ($data['content'][0]['vacations'] as $v) {
        $name = trim($v['type']);
        foreach ($v['regions'] as $r) {
            $reg = mb_strtolower(trim($r['region']));
            if ($reg !== $region && $reg !== 'heel nederland') {
                continue;
            }
            $start = substr($r['startdate'], 0, 10);
            $end = substr($r['enddate'], 0, 10);
            $exists = db()->prepare("SELECT 1 FROM fp_events WHERE type = 'SCHOOLHOLIDAY' AND title = ? AND DATE(start_at) = ?");
            $exists->execute([$name, $start]);
            if ($exists->fetchColumn()) {
                continue;
            }
            save_event(normalise_event(['title' => $name, 'type' => 'SCHOOLHOLIDAY', 'all_day' => true, 'start' => $start, 'end' => $end, 'members' => $kids]));
            $added++;
        }
    }
    return $added;
}

/**
 * Days off coming up (from $from, $days ahead): study days/afternoons and school holidays (events)
 * plus official holidays and fun days. Each: kind (event|feast), date (first day), end, title, emoji, key, event.
 */
function upcoming_free_days(string $from, int $days = 365): array
{
    $to = date('Y-m-d', strtotime("$from +$days day"));
    $out = [];
    $seen = [];
    foreach (load_events($from, $to, ['types' => FREE_TYPES]) as $ev) {
        if (isset($seen[$ev['id'] . '@' . $ev['occ']])) {
            continue;
        }
        $seen[$ev['id'] . '@' . $ev['occ']] = true;
        $out[] = ['kind' => 'event', 'date' => substr($ev['start_at'], 0, 10), 'end' => substr($ev['end_at'], 0, 10), 'title' => $ev['title'],
            'emoji' => event_emoji($ev), 'key' => $ev['type'] === 'SCHOOLHOLIDAY' ? holiday_key($ev['title']) : $ev['type'], 'event' => $ev, 'official' => true];
    }
    foreach (load_feasts($from, $to) as $f) {
        $out[] = ['kind' => 'feast', 'date' => $f['date'], 'end' => $f['date'], 'title' => $f['title'], 'emoji' => $f['emoji'], 'key' => $f['key'], 'event' => null, 'official' => $f['official']];
    }
    usort($out, function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    return $out;
}

function holiday_key(string $title): string
{
    $t = mb_strtolower($title);
    foreach (['herfst' => 'AUTUMN', 'kerst' => 'XMASBREAK', 'voorjaar' => 'SPRINGBREAK', 'krokus' => 'SPRINGBREAK', 'mei' => 'MAYBREAK', 'zomer' => 'SUMMER'] as $needle => $key) {
        if (strpos($t, $needle) !== false) {
            return $key;
        }
    }
    return 'HOLIDAY';
}

/** Ready-made ideas per kind of day. */
function occasion_ideas(string $key): array
{
    $ideas = [
        'STUDYDAY' => ['🛝 Speeltuin of kinderboerderij (lekker rustig doordeweeks)', '🍪 Samen koekjes bakken', '🏛️ Museum of bibliotheek', '🧸 Een vriendje uitnodigen dat ook vrij is', '🌲 Hutten bouwen in het bos'],
        'STUDYPM' => ['🧺 Picknick in het park', '📚 Naar de bibliotheek', '✂️ Knutselmiddag', '🏊 Naar het zwembad', '🍿 Film kijken met popcorn'],
        'AUTUMN' => ['🌰 Kastanjes en paddenstoelen zoeken', '🎃 Pompoen uithollen', '🌊 Uitwaaien op het strand', '🦕 Museum (bijv. Naturalis of NEMO)', '🍂 Bladeren-knutselwerk maken'],
        'XMASBREAK' => ['🍪 Kerstkoekjes bakken en versieren', '⛸️ Schaatsen', '✨ Lichtjeswandeling', '🎬 Kerstfilm met warme chocomel', '🍩 Oliebollen bakken'],
        'SPRINGBREAK' => ['🏊 Zwemparadijs', '🤸 Indoor speeltuin of trampolinepark', '🏛️ Museum', '🎲 Bordspellendag', '🥾 Winterwandeling met thermoskan'],
        'MAYBREAK' => ['🚲 Fietstocht met picknick', '🐑 Lammetjes kijken op de kinderboerderij', '🌷 Tulpenvelden bekijken', '🧗 Klimbos of touwbaan', '🏰 Kasteel bezoeken'],
        'SUMMER' => ['🏖️ Naar het strand', '⛺ Kamperen (of in de tuin!)', '💦 Waterspeeltuin', '🎢 Pretpark', '🍦 Fietsen naar de ijssalon'],
        'HOLIDAY' => ['🎡 Dagje uit', '🏊 Zwembad', '🧸 Logeerpartijtje', '🎨 Knutseldag', '🌳 Speurtocht in het bos'],
        'NEWYEAR' => ['🎨 Voornemens-poster maken', '🥾 Nieuwjaarswandeling', '🥞 Pannenkoeken-ontbijt'],
        'VALENTINE' => ['💌 Samen kaartjes maken voor opa, oma of juf', '🍓 Hartjes-pannenkoeken'],
        'CARNIVAL' => ['🎭 Verkleedfeestje thuis', '🎺 Carnavalsoptocht kijken'],
        'GOODFRIDAY' => ['🥚 Paaseieren beschilderen'],
        'EASTER' => ['🥚 Paaseieren zoeken', '🎨 Eieren verven', '🐣 Paasbrunch met opa en oma', '🐰 Paashaas-speurtocht'],
        'KINGSDAY' => ['🧡 Vrijmarkt: speelgoed verkopen', '🎲 Oudhollandse spelletjes', '🎈 Oranje verkleden'],
        'LIBERATION' => ['🎶 Bevrijdingsfestival bezoeken', '🕊️ Vlieger maken'],
        'MOTHERSDAY' => ['🥐 Ontbijt op bed voor mama', '💐 Bloemen plukken', '🎨 Kaart of cadeautje knutselen'],
        'ASCENSION' => ['🚲 Fietstocht', '🧺 Picknick', '🏕️ Lang weekend weg'],
        'PENTECOST' => ['🌳 Pinksterfeest of -markt', '🎡 Dagje uit', '🚲 Fietsen door de bollenvelden'],
        'FATHERSDAY' => ['🥐 Ontbijt op bed voor papa', '🔧 Samen iets bouwen', '🎨 Cadeautje knutselen'],
        'ANIMALDAY' => ['🐐 Naar de kinderboerderij', '🐶 Dierenasiel bezoeken', '🦒 Naar de dierentuin'],
        'HALLOWEEN' => ['🎃 Pompoen snijden', '👻 Griezelig verkleden', '🍬 Halloween-speurtocht in de buurt'],
        'STMARTIN' => ['🏮 Lampion maken', '🎶 Liedjes oefenen en langs de deuren', '🍊 Mandarijnen en snoep uitdelen'],
        'SINTERKLAAS' => ['👞 Schoen zetten', '🎁 Surprise knutselen', '🍪 Pepernoten bakken', '✍️ Gedichten schrijven'],
        'CHRISTMAS' => ['🎄 Kerstboom versieren', '🍽️ Kerstdiner samen koken', '🎲 Spelletjes met familie', '🌟 Kerstverhaal voorlezen'],
        'NEWYEARSEVE' => ['🍩 Oliebollen bakken', '🎇 Kinder-aftelmoment om 20:00', '🎉 Terugblik: mooiste momenten van het jaar'],
    ];
    return $ideas[$key] ?? $ideas['HOLIDAY'];
}

/** Season words used in the idea bank (fp_ideas.season), for matching ideas to a date. */
function season_words(string $date): array
{
    $m = (int) substr($date, 5, 2);
    foreach ([[12, 1, 2], [3, 4, 5], [6, 7, 8], [9, 10, 11]] as $i => $months) {
        if (in_array($m, $months, true)) {
            return [['winter', 'december'], ['lente'], ['zomer'], ['herfst']][$i];
        }
    }
    return [];
}

/** Suggestions for a free day: open bucketlist wishes and seasonal ideas from the idea bank. */
function free_day_suggestions(string $date, int $limit = 3): array
{
    $wishes = db()->query('SELECT b.*, (SELECT COUNT(*) FROM fp_bucket_votes v WHERE v.bucket_id = b.id) AS votes FROM fp_bucket b
        WHERE b.done_on IS NULL AND b.event_id IS NULL ORDER BY votes DESC, b.created_at DESC LIMIT ' . (int) $limit)->fetchAll();
    $words = season_words($date);
    $where = "category IN ('OUTING', 'ACTIVITY') AND done_at IS NULL AND (season IS NULL OR season = '' OR season LIKE '%hele jaar%'";
    foreach ($words as $w) {
        $where .= " OR season LIKE " . db()->quote('%' . $w . '%');
    }
    $ideas = db()->query("SELECT * FROM fp_ideas WHERE $where) ORDER BY RAND() LIMIT " . (int) $limit)->fetchAll();
    return ['wishes' => $wishes, 'ideas' => $ideas];
}

/** Children's friends who can usually play on that weekday (their regular week says YES). */
function friends_free_on(string $date): array
{
    $wd = (int) date('N', strtotime($date));
    $stmt = db()->prepare("SELECT DISTINCT c.* FROM fp_contacts c JOIN fp_contact_days d ON d.contact_id = c.id
        WHERE d.weekday = ? AND d.status = 'YES' AND c.is_child = 1 ORDER BY c.first_name");
    $stmt->execute([$wd]);
    return $stmt->fetchAll();
}

/** Web search for outing tips on Kidsproof for this occasion near home. */
function kidsproof_url(string $occasion): string
{
    $city = setting('city', '') ?: '';
    return 'https://www.google.com/search?q=' . rawurlencode('site:kidsproof.nl ' . trim($city . ' ' . $occasion));
}
