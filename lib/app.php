<?php
// Shared bootstrap for every page: session, auth, CSRF, current square and layout helpers.
// Keep this PHP 7.4 compatible (the server's PHP version is not guaranteed to be 8).
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
require_once APP_ROOT . '/db.php';

date_default_timezone_set('Europe/Amsterdam');

const CATEGORIES = [
    'ACTIVITY' => ['label' => 'Activiteit', 'color' => 'coral'],
    'OUTING' => ['label' => 'Uitje', 'color' => 'blue'],
    'COMMUNITY' => ['label' => 'Community', 'color' => 'orange'],
];

const ACTIVITY_MODES = [
    'EVERYONE' => 'Iedereen kan activiteiten plaatsen',
    'APPROVAL' => 'Iedereen kan activiteiten plaatsen, na goedkeuring door een beheerder',
    'ADMINS' => 'Alleen beheerders kunnen activiteiten plaatsen',
];

// Friendly error page instead of a blank screen; details go to the PHP error log.
set_exception_handler(function (Throwable $e) {
    error_log('[jouwschoolplein] ' . $e);
    http_response_code(500);
    echo '<!DOCTYPE html><meta charset="utf-8"><link rel="stylesheet" href="style.css">'
        . '<main class="shell narrow"><article class="card"><h2>Er ging iets mis</h2>'
        . '<p>Probeer het nog eens. Blijft het misgaan, laat het dan de beheerder weten.</p>'
        . '<p><a href="./">← Terug naar het plein</a></p></article></main>';
});

// Session cookie scoped to this app, so other apps on the same domain don't share it.
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
session_name('jsp_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $basePath,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

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

/**
 * True for a POST with a valid CSRF token. A POST with a bad token stops the request.
 */
function is_post(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Dit formulier is verlopen. Ga terug, vernieuw de pagina en probeer het opnieuw.');
    }
    return true;
}

function post(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

// ---------- Users ----------

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
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

/**
 * Site-wide beheerder by account, regardless of the ouder/beheerder switch.
 */
function is_real_site_admin(): bool
{
    $user = current_user();
    return $user && $user['role'] === 'ADMIN';
}

/**
 * Beheerder of the site or of any square: may use the ouder/beheerder switch.
 */
function can_switch_role(): bool
{
    if (is_real_site_admin()) {
        return true;
    }
    foreach (user_schools() as $school) {
        if ($school['member_role'] === 'ADMIN') {
            return true;
        }
    }
    return false;
}

/**
 * Admin chose "view as ouder": all admin rights and admin UI are hidden until switched back.
 */
function viewing_as_parent(): bool
{
    return !empty($_SESSION['view_as_parent']);
}

function is_site_admin(): bool
{
    return is_real_site_admin() && !viewing_as_parent();
}

/**
 * Only allow redirects to pages of this app (no other sites).
 */
function safe_next(string $next): string
{
    if (preg_match('/^\?[A-Za-z0-9=&%_.-]+$/', $next)) {
        return './' . $next; // the square with a tab, e.g. ?tab=agenda
    }
    return preg_match('/^[a-z-]+\.php(\?[A-Za-z0-9=&%_.-]*)?$/', $next) ? $next : './';
}

// ---------- Squares (schools) ----------

/**
 * Squares the current user can see: their memberships, or all squares for a site admin.
 */
function user_schools(): array
{
    static $schools = null;
    if ($schools === null) {
        $user = current_user();
        if (!$user) {
            return [];
        }
        // Real role here, so switching to ouder keeps the same squares available
        if (is_real_site_admin()) {
            $schools = db()->query("SELECT s.*, 'ADMIN' AS member_role FROM schools s ORDER BY s.name")->fetchAll();
        } else {
            $stmt = db()->prepare('SELECT s.*, m.role AS member_role FROM schools s JOIN school_memberships m ON m.school_id = s.id WHERE m.user_id = ? ORDER BY s.name');
            $stmt->execute([$user['id']]);
            $schools = $stmt->fetchAll();
        }
        $schools = array_column($schools, null, 'id');
    }
    return $schools;
}

function current_school(): ?array
{
    $schools = user_schools();
    if (isset($_GET['school'], $schools[(int) $_GET['school']])) {
        $_SESSION['school_id'] = (int) $_GET['school'];
    }
    $id = $_SESSION['school_id'] ?? null;
    if (!isset($schools[$id])) {
        $id = array_key_first_compat($schools);
        $_SESSION['school_id'] = $id;
    }
    return $id === null ? null : $schools[$id];
}

function array_key_first_compat(array $array)
{
    foreach ($array as $key => $unused) {
        return $key;
    }
    return null;
}

function is_school_admin(?array $school): bool
{
    return $school && !viewing_as_parent() && (is_site_admin() || $school['member_role'] === 'ADMIN');
}

function can_post_activity(array $school): bool
{
    return $school['activity_mode'] !== 'ADMINS' || is_school_admin($school);
}

// ---------- Formatting ----------

function format_when(string $start, ?string $end = null): string
{
    $days = ['Zondag', 'Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag'];
    $months = ['', 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    $t = strtotime($start);
    $text = $days[(int) date('w', $t)] . ' ' . date('j', $t) . ' ' . $months[(int) date('n', $t)] . ' · ' . date('H:i', $t);
    if ($end) {
        $te = strtotime($end);
        $text .= date('Y-m-d', $te) === date('Y-m-d', $t) ? '–' . date('H:i', $te) : ' t/m ' . date('j', $te) . ' ' . $months[(int) date('n', $te)];
    }
    return $text;
}

function format_month(string $date): string
{
    $months = ['', 'Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni', 'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December'];
    $t = strtotime($date);
    return $months[(int) date('n', $t)] . ' ' . date('Y', $t);
}

function format_ago(string $datetime): string
{
    $t = strtotime($datetime);
    $diff = time() - $t;
    if ($diff < 60) {
        return 'zojuist';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' min geleden';
    }
    if (date('Y-m-d', $t) === date('Y-m-d')) {
        return 'vandaag ' . date('H:i', $t);
    }
    return date('j-n H:i', $t);
}

function people_label(int $families, int $children): string
{
    if ($families === 0) {
        return 'Nog niemand, wees de eerste';
    }
    $parts = [];
    if ($children > 0) {
        $parts[] = $children . ($children === 1 ? ' kind' : ' kinderen');
    }
    $parts[] = $families . ($families === 1 ? ' gezin' : ' gezinnen');
    return implode(' · ', $parts);
}

function limit_text(string $text, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
}

function excerpt(string $text, int $length): string
{
    $short = limit_text($text, $length);
    return $short === $text ? $text : rtrim($short) . '…';
}

function initial(string $name): string
{
    return preg_match('/\p{L}|\p{N}/u', $name, $m) ? strtoupper($m[0]) : '?';
}

// ---------- Layout ----------

function page_start(string $title, array $options = []): void
{
    $user = current_user();
    $school = $user ? current_school() : null;
    $schools = $user ? user_schools() : [];
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    ?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Schoolplein</title>
<meta name="description" content="Het digitale dorpsplein rond school">
<link rel="stylesheet" href="style.css?v=3">
</head>
<body>
<main class="shell<?= !empty($options['narrow']) ? ' narrow' : '' ?>">
  <header>
    <div>
      <a class="logo" href="./">school<span>plein</span></a>
      <?php if (count($schools) > 1): ?>
        <form class="school" method="get" action="./">
          <select name="school" onchange="this.form.submit()" aria-label="Kies schoolplein">
            <?php foreach ($schools as $s): ?>
              <option value="<?= (int) $s['id'] ?>"<?= $school && $s['id'] == $school['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php elseif ($school): ?>
        <div class="school"><?= e($school['name']) ?></div>
      <?php endif; ?>
    </div>
    <?php if ($user): ?>
      <nav class="usernav">
        <?php if (can_switch_role()): ?>
          <form class="role-switch" method="post" action="wissel-rol.php" title="Bekijk het plein als ouder of als beheerder">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e(basename($_SERVER['REQUEST_URI'] ?? '')) ?>">
            <button name="role" value="parent"<?= viewing_as_parent() ? ' class="on" disabled' : '' ?>>👤 Ouder</button>
            <button name="role" value="admin"<?= viewing_as_parent() ? '' : ' class="on" disabled' ?>>🛠 Beheerder</button>
          </form>
        <?php endif; ?>
        <?php if (is_school_admin($school)): ?><a href="beheer.php">Beheer</a><?php endif; ?>
        <a class="avatar" href="profiel.php" title="Mijn profiel"><?= e(initial($user['name'])) ?></a>
      </nav>
    <?php endif; ?>
  </header>
  <?php if ($user && viewing_as_parent() && can_switch_role()): ?>
    <div class="flash info">Je bekijkt het plein als ouder. Beheerfuncties zijn verborgen tot je terugschakelt naar 🛠 Beheerder.</div>
  <?php endif; ?>
  <?php foreach ($flashes as $f): ?>
    <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
    <?php
}

function page_end(): void
{
    echo "\n</main>\n</body>\n</html>\n";
}
