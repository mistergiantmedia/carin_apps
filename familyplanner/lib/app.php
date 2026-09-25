<?php
// Shared bootstrap for every page: session, login, CSRF, family members, formatting and layout.
// Keep this PHP 7.4 compatible.
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/constants.php';

date_default_timezone_set('Europe/Amsterdam');

if (!is_file(\Familie\CONFIG_FILE) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
    header('Location: install.php');
    exit;
}

// Friendly error page instead of a blank screen; details go to the PHP error log.
set_exception_handler(function (Throwable $e) {
    error_log('[familyplanner] ' . $e);
    http_response_code(500);
    if (defined('API_REQUEST')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Er ging iets mis op de server.']);
        return;
    }
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="style.css"><main class="page narrow"><div class="card"><h2>Er ging iets mis</h2>'
        . '<p>Probeer het nog eens. Blijft het misgaan, laat het Rene dan weten.</p>'
        . '<p><a href="./">← Terug naar vandaag</a></p></div></main>';
});

// Session cookie scoped to this app, so other apps on the same domain don't share it.
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
session_name('fp_session');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 60,
    'path' => $basePath,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 60));
session_start();

// ---------- Basics ----------

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** True for a POST with a valid CSRF token. A POST with a bad token stops the request. */
function is_post(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }
    $token = (string) ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Dit formulier is verlopen. Ga terug, vernieuw de pagina en probeer het opnieuw.');
    }
    return true;
}

function post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

/** Posted value or NULL when empty (for optional columns). */
function post_or_null(string $key): ?string
{
    $v = post($key);
    return $v === '' ? null : $v;
}

function post_int(string $key): ?int
{
    $v = post($key);
    return $v === '' || !is_numeric($v) ? null : (int) $v;
}

function post_ids(string $key): array
{
    $ids = (array) ($_POST[$key] ?? []);
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}

function get_int(string $key): ?int
{
    return isset($_GET[$key]) && is_numeric($_GET[$key]) ? (int) $_GET[$key] : null;
}

function mb_cut(string $text, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
}

function excerpt(string $text, int $length): string
{
    $short = mb_cut($text, $length);
    return $short === $text ? $text : rtrim($short) . '…';
}

function initial(string $name): string
{
    return preg_match('/\p{L}|\p{N}/u', $name, $m) ? mb_strtoupper($m[0]) : '?';
}

/** Only allow redirects to pages of this app. */
function safe_next(string $next): string
{
    return preg_match('/^[a-z-]+\.php(\?[A-Za-z0-9=&%_.-]*)?$/', $next) ? $next : './';
}

function back_to(string $fallback): string
{
    $next = post('next') ?: ($_GET['next'] ?? '');
    return $next !== '' ? safe_next($next) : $fallback;
}

// ---------- Login ----------

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $stmt = db()->prepare('SELECT id, name, email, member_id FROM fp_users WHERE id = ?');
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
        }
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php?next=' . urlencode(basename($_SERVER['REQUEST_URI'] ?? '')));
    }
    return $user;
}

function log_in(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

// ---------- Family members ----------

/** All family members, keyed by id, in display order. */
function members(): array
{
    static $members = null;
    if ($members === null) {
        $members = array_column(db()->query('SELECT * FROM fp_members ORDER BY sort, id')->fetchAll(), null, 'id');
    }
    return $members;
}

function member(?int $id): ?array
{
    return $id ? (members()[$id] ?? null) : null;
}

function children(): array
{
    return array_filter(members(), function ($m) {
        return $m['role'] === 'CHILD';
    });
}

function parents(): array
{
    return array_filter(members(), function ($m) {
        return $m['role'] === 'PARENT';
    });
}

// ---------- People helpers (members and contacts share name/photo/birthday fields) ----------

function contact_name(array $c, bool $full = true): string
{
    $first = $c['nickname'] ?: $c['first_name'];
    return $full && !empty($c['last_name']) ? $c['first_name'] . ' ' . $c['last_name'] : $first;
}

/** Stable soft colour for someone without a photo. */
function name_color(string $name): string
{
    $palette = ['#E0568A', '#3B7DD8', '#2BA879', '#F08C2E', '#8E6CDF', '#1FA2B8', '#D95F43', '#5B8C2A', '#C2489B', '#4A6FE3'];
    return $palette[abs(crc32($name)) % count($palette)];
}

/**
 * Round photo or coloured initial. $person: a member or contact row (needs photo + a name).
 * $size in px.
 */
function avatar(array $person, int $size = 40, string $class = ''): string
{
    $name = $person['name'] ?? contact_name($person, false);
    $style = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * 0.42) . 'px';
    $cls = trim('avatar ' . $class);
    if (!empty($person['photo'])) {
        return '<img class="' . e($cls) . '" style="' . $style . '" src="foto.php?f=' . e($person['photo']) . '&amp;s=t" alt="' . e($name) . '" loading="lazy">';
    }
    $color = $person['color'] ?? name_color($name);
    $label = isset($person['emoji']) && $size >= 28 ? $person['emoji'] : initial($name);
    return '<span class="' . e($cls) . '" style="' . $style . ';background:' . e($color) . '" title="' . e($name) . '">' . e($label) . '</span>';
}

function member_chip(array $m, bool $small = false): string
{
    return '<span class="chip' . ($small ? ' small' : '') . '" style="--c:' . e($m['color']) . '">' . e($m['emoji']) . ' ' . e($m['name']) . '</span>';
}

// ---------- Dates ----------

const DAYS = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
const DAYS_SHORT = ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'];
const MONTHS = ['', 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

function today(): string
{
    return date('Y-m-d');
}

/** "maandag 3 november" (+ year when not this year) */
function format_date(string $date, bool $withDay = true): string
{
    $t = strtotime($date);
    $text = ($withDay ? DAYS[(int) date('w', $t)] . ' ' : '') . date('j', $t) . ' ' . MONTHS[(int) date('n', $t)];
    if (date('Y', $t) !== date('Y')) {
        $text .= ' ' . date('Y', $t);
    }
    return $text;
}

function format_date_short(string $date): string
{
    $t = strtotime($date);
    return DAYS_SHORT[(int) date('w', $t)] . ' ' . date('j', $t) . ' ' . mb_cut(MONTHS[(int) date('n', $t)], 3);
}

/** Relative day name: vandaag, morgen, gisteren, or a date. */
function day_label(string $date): string
{
    $d = substr($date, 0, 10);
    $diff = (int) round((strtotime($d) - strtotime(today())) / 86400);
    if ($diff === 0) {
        return 'Vandaag';
    }
    if ($diff === 1) {
        return 'Morgen';
    }
    if ($diff === -1) {
        return 'Gisteren';
    }
    if ($diff > 1 && $diff < 7) {
        return ucfirst(DAYS[(int) date('w', strtotime($d))]);
    }
    return ucfirst(format_date($d));
}

function format_time_range(array $ev): string
{
    if (!empty($ev['all_day'])) {
        $s = substr($ev['start_at'], 0, 10);
        $end = substr($ev['end_at'], 0, 10);
        return $s === $end ? 'Hele dag' : 't/m ' . format_date_short($end);
    }
    $s = strtotime($ev['start_at']);
    $en = strtotime($ev['end_at']);
    $text = date('H:i', $s) . '–' . date('H:i', $en);
    if (date('Y-m-d', $s) !== date('Y-m-d', $en)) {
        $text = date('H:i', $s) . ' – ' . format_date_short(date('Y-m-d', $en)) . ' ' . date('H:i', $en);
    }
    return $text;
}

function days_until(string $date): int
{
    return (int) round((strtotime(substr($date, 0, 10)) - strtotime(today())) / 86400);
}

function in_days_label(int $days): string
{
    if ($days === 0) {
        return 'vandaag';
    }
    if ($days === 1) {
        return 'morgen';
    }
    if ($days < 0) {
        return abs($days) . ' dagen geleden';
    }
    if ($days < 14) {
        return 'over ' . $days . ' dagen';
    }
    if ($days < 60) {
        return 'over ' . round($days / 7) . ' weken';
    }
    return 'over ' . round($days / 30) . ' maanden';
}

/** Next birthday date (Y-m-d) on or after $from for a day/month, or null. */
function next_birthday(?int $day, ?int $month, ?string $from = null): ?string
{
    if (!$day || !$month) {
        return null;
    }
    $from = $from ?: today();
    $year = (int) substr($from, 0, 4);
    foreach ([$year, $year + 1] as $y) {
        $d = ($month === 2 && $day === 29 && !checkdate(2, 29, $y)) ? 28 : $day;
        $date = sprintf('%04d-%02d-%02d', $y, $month, $d);
        if ($date >= $from) {
            return $date;
        }
    }
    return null;
}

/** Age on a date (default today), or null when the birth year is unknown. */
function age(array $p, ?string $on = null): ?int
{
    if (empty($p['birth_year']) || empty($p['birth_month']) || empty($p['birth_day'])) {
        return empty($p['birth_year']) ? null : (int) substr($on ?: today(), 0, 4) - (int) $p['birth_year'];
    }
    $on = $on ?: today();
    $age = (int) substr($on, 0, 4) - (int) $p['birth_year'];
    if (substr($on, 5) < sprintf('%02d-%02d', $p['birth_month'], $p['birth_day'])) {
        $age--;
    }
    return $age;
}

function birthday_text(array $p): string
{
    if (empty($p['birth_day']) || empty($p['birth_month'])) {
        return '';
    }
    $text = $p['birth_day'] . ' ' . MONTHS[(int) $p['birth_month']];
    return $p['birth_year'] ? $text . ' ' . $p['birth_year'] : $text;
}

/** School year label for a date: "2026-2027" (starts 1 August). */
function school_year(?string $date = null): string
{
    $t = strtotime($date ?: today());
    $y = (int) date('Y', $t);
    $start = (int) date('n', $t) >= 8 ? $y : $y - 1;
    return $start . '-' . ($start + 1);
}

function school_year_start(?string $label = null): string
{
    $label = $label ?: school_year();
    return substr($label, 0, 4) . '-08-01';
}

function money(?float $amount): string
{
    return $amount === null ? '' : '€ ' . number_format($amount, 2, ',', '.');
}

// ---------- Layout ----------

function nav_items(): array
{
    return [
        ['index.php', '🏠', 'Vandaag'],
        ['agenda.php', '📅', 'Agenda'],
        ['gezin.php', '👨‍👩‍👧‍👦', 'Gezin'],
        ['vriendjes.php', '🧸', 'Vriendjes'],
        ['taken.php', '✅', 'Wie doet wat'],
        ['verjaardagen.php', '🎂', 'Verjaardagen'],
        ['mensen.php', '📇', 'Adresboek'],
        ['smoelenboek.php', '🏫', 'Smoelenboek'],
        ['activiteiten.php', '🎡', 'Uitjes & feestjes'],
        ['ideeen.php', '💡', 'Ideeën & attent'],
        ['oppas.php', '🍼', 'Oppas'],
        ['fotos.php', '📸', "Schoolfoto's"],
        ['instellingen.php', '⚙️', 'Instellingen'],
    ];
}

/**
 * Options: active (nav file), wide (no max width), narrow, bodyClass, scripts (list of js files), head (extra html).
 */
function page_start(string $title, array $options = []): void
{
    $user = current_user();
    $active = $options['active'] ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    $me = $user ? member($user['member_id'] ? (int) $user['member_id'] : null) : null;
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#6C5CE7">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · Familie Planner</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏡</text></svg>">
<link rel="stylesheet" href="style.css?v=<?= ASSET_VERSION ?>">
<?= $options['head'] ?? '' ?>
</head>
<body class="<?= e($options['bodyClass'] ?? '') ?>">
<?php if ($user): ?>
<nav class="sidebar" aria-label="Hoofdmenu">
  <a class="brand" href="./">familie<span>planner</span></a>
  <?php foreach (nav_items() as [$file, $icon, $label]): ?>
    <a class="nav<?= $active === $file ? ' on' : '' ?>" href="<?= e($file) ?>"><span class="ni"><?= $icon ?></span><span><?= e($label) ?></span></a>
  <?php endforeach; ?>
  <div class="sidebar-family">
    <?php foreach (members() as $m): ?>
      <a href="persoon.php?id=<?= (int) $m['id'] ?>" title="<?= e($m['name']) ?>"><?= avatar($m, 34) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="sidebar-user">
    <?= $me ? avatar($me, 26) : '' ?> <span><?= e($user['name']) ?></span>
    <a href="logout.php" class="muted">Uitloggen</a>
  </div>
</nav>
<nav class="bottombar" aria-label="Snelmenu">
  <a href="./" class="<?= $active === 'index.php' ? 'on' : '' ?>"><span>🏠</span>Vandaag</a>
  <a href="agenda.php" class="<?= $active === 'agenda.php' ? 'on' : '' ?>"><span>📅</span>Agenda</a>
  <a href="event.php?new=1" class="add" data-new-event="{}" aria-label="Nieuwe afspraak"><span>＋</span></a>
  <a href="taken.php" class="<?= $active === 'taken.php' ? 'on' : '' ?>"><span>✅</span>Taken</a>
  <button type="button" class="menu-toggle" onclick="document.body.classList.toggle('menu-open')"><span>☰</span>Meer</button>
</nav>
<div class="menu-backdrop" onclick="document.body.classList.remove('menu-open')"></div>
<?php endif; ?>
<main class="page<?= !empty($options['wide']) ? ' wide' : '' ?><?= !empty($options['narrow']) ? ' narrow' : '' ?>">
  <?php foreach ($flashes as $f): ?>
    <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
    <?php
}

function page_end(array $scripts = []): void
{
    echo "\n</main>\n";
    if (current_user()) {
        echo '<script>window.FP_DATA = ' . json_encode(front_end_data(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n";
    }
    echo '<script src="app.js?v=' . ASSET_VERSION . '"></script>' . "\n";
    foreach ($scripts as $s) {
        echo '<script src="' . e($s) . '?v=' . ASSET_VERSION . '"></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

/** Members and fixed lists for app.js / calendar.js. */
function front_end_data(): array
{
    $types = [];
    foreach (EVENT_TYPES as $k => [$label, $emoji, $color]) {
        $types[$k] = ['label' => $label, 'emoji' => $emoji, 'color' => $color];
    }
    $members = [];
    foreach (members() as $m) {
        $members[] = ['id' => (int) $m['id'], 'name' => $m['name'], 'role' => $m['role'], 'color' => $m['color'], 'emoji' => $m['emoji'], 'photo' => $m['photo']];
    }
    $user = current_user();
    return [
        'members' => $members,
        'me' => $user && $user['member_id'] ? (int) $user['member_id'] : null,
        'types' => $types,
        'recurrences' => RECURRENCES,
        'hosts' => HOSTS,
        'rsvps' => RSVPS,
        'today' => today(),
    ];
}

/** Page header with title, optional subtitle and action buttons (html). */
function page_header(string $title, string $subtitle = '', string $actions = ''): void
{
    echo '<header class="page-head"><div><h1>' . $title . '</h1>';
    if ($subtitle !== '') {
        echo '<p class="sub">' . $subtitle . '</p>';
    }
    echo '</div>';
    if ($actions !== '') {
        echo '<div class="head-actions">' . $actions . '</div>';
    }
    echo '</header>';
}

function empty_state(string $icon, string $text, string $action = ''): string
{
    return '<div class="empty"><div class="empty-icon">' . $icon . '</div><p>' . $text . '</p>' . $action . '</div>';
}

/** <option> list. $options: value => label */
function options(array $options, $selected, bool $blank = false, string $blankLabel = '—'): string
{
    $html = $blank ? '<option value="">' . e($blankLabel) . '</option>' : '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . ((string) $value === (string) $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

function member_options(): array
{
    $out = [];
    foreach (members() as $m) {
        $out[$m['id']] = $m['emoji'] . ' ' . $m['name'];
    }
    return $out;
}

/** Toggle chips for choosing family members in a form. */
function member_picker(string $name, array $selected, bool $multiple = true): string
{
    $html = '<div class="picker">';
    foreach (members() as $m) {
        $checked = in_array((int) $m['id'], array_map('intval', $selected), true) ? ' checked' : '';
        $html .= '<label class="pick" style="--c:' . e($m['color']) . '"><input type="' . ($multiple ? 'checkbox' : 'radio') . '" name="' . e($name) . ($multiple ? '[]' : '') . '" value="' . (int) $m['id'] . '"' . $checked . '><span>' . e($m['emoji']) . ' ' . e($m['name']) . '</span></label>';
    }
    return $html . '</div>';
}

function birthday_fields(array $p): string
{
    $days = ['' => 'dag'];
    for ($i = 1; $i <= 31; $i++) {
        $days[$i] = (string) $i;
    }
    $months = ['' => 'maand'];
    for ($i = 1; $i <= 12; $i++) {
        $months[$i] = MONTHS[$i];
    }
    return '<div class="row3"><select name="birth_day" aria-label="Dag">' . options($days, $p['birth_day'] ?? '') . '</select>'
        . '<select name="birth_month" aria-label="Maand">' . options($months, $p['birth_month'] ?? '') . '</select>'
        . '<input name="birth_year" type="number" min="1900" max="2100" placeholder="jaar (optioneel)" value="' . e($p['birth_year'] ?? '') . '" aria-label="Jaar"></div>';
}

/** Birthday fields from POST, validated. Returns [day, month, year]. */
function posted_birthday(): array
{
    $d = post_int('birth_day');
    $m = post_int('birth_month');
    $y = post_int('birth_year');
    if (!$d || !$m || !checkdate($m, $d, $y ?: 2000)) {
        $d = $m = null;
    }
    if ($y !== null && ($y < 1900 || $y > 2100)) {
        $y = null;
    }
    return [$d, $m, $y];
}
