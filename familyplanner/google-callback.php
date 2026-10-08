<?php
// Google sends people back here after they allowed (or refused) the connection: store the tokens on the
// account, make the "Familie Planner" calendar and start filling it (after the response, see gcal_schedule()).
require __DIR__ . '/lib/app.php';
$user = require_login();
$back = 'instellingen.php#google';

$expected = (string) ($_SESSION['fp_gcal_state'] ?? '');
unset($_SESSION['fp_gcal_state']);
if (!gcal_configured() || $expected === '' || !hash_equals($expected, (string) ($_GET['state'] ?? ''))) {
    flash('Het koppelen is niet gelukt. Probeer het nog eens.', 'error');
    redirect($back);
}
if (isset($_GET['error']) || empty($_GET['code'])) {
    flash('Koppelen met Google Agenda is geannuleerd.', 'warn');
    redirect($back);
}

$t = gcal_token(['code' => (string) $_GET['code'], 'redirect_uri' => gcal_redirect_uri(), 'grant_type' => 'authorization_code']);
$u = gcal_user((int) $user['id']);
$refresh = $t['refresh_token'] ?? ($u['google_refresh_token'] ?? null);
if (empty($t['access_token']) || !$refresh) {
    error_log('[familyplanner] Google code exchange failed: ' . json_encode($t));
    flash('Google gaf geen toegang. Probeer het nog eens.', 'error');
    redirect($back);
}

[$code, $info] = gcal_http('GET', 'https://openidconnect.googleapis.com/v1/userinfo', $t['access_token']);
$email = $code === 200 && !empty($info['email']) ? (string) $info['email'] : null;
if ($u['google_calendar_id'] && $email !== $u['google_email']) {
    gcal_forget((int) $u['id']); // another Google account than before: start with a new calendar there
}
db()->prepare('UPDATE fp_users SET google_email = ?, google_refresh_token = ?, google_access_token = ?, google_token_expires_at = ?, google_error = NULL WHERE id = ?')
    ->execute([$email, $refresh, $t['access_token'], date('Y-m-d H:i:s', time() + (int) ($t['expires_in'] ?? 3600)), $user['id']]);

// Make the calendar now, so a problem shows right away instead of in the background
$u = gcal_user((int) $user['id']);
if (!gcal_calendar($u, $t['access_token'])) {
    flash('Gekoppeld, maar de agenda kon niet worden gemaakt: ' . gcal_user((int) $user['id'])['google_error'], 'error');
    redirect($back);
}
gcal_schedule(true);
flash('✓ Gekoppeld' . ($email ? ' met ' . $email : '') . '! De afspraken komen nu in de agenda “' . gcal_calendar_title($u) . '” in Google Agenda. De eerste keer kan dat een paar minuten duren.');
redirect($back);
