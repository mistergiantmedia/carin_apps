<?php
// Admin switch between "ouder" (see the square as a parent) and "beheerder".
require __DIR__ . '/lib/app.php';

require_login();
if (is_post() && can_switch_role()) {
    $_SESSION['view_as_parent'] = post('role') === 'parent';
    flash(viewing_as_parent() ? 'Je bekijkt het plein nu als ouder.' : 'Je bent weer beheerder.');
}
$next = safe_next(post('next'));
// Beheer pages are off-limits as ouder, so land on the square instead
redirect(viewing_as_parent() && in_array(strtok($next, '?'), ['beheer.php', 'dbtest.php'], true) ? './' : $next);
