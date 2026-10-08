<?php
// Start connecting Google Agenda (button in Instellingen): off to Google's consent screen, back via google-callback.php.
require __DIR__ . '/lib/app.php';
require_login();

if (!gcal_configured()) {
    flash('De koppeling met Google Agenda is nog niet ingesteld door de beheerder.', 'error');
    redirect('instellingen.php#google');
}
$_SESSION['fp_gcal_state'] = bin2hex(random_bytes(16));
redirect(gcal_auth_url($_SESSION['fp_gcal_state']));
