<?php
require __DIR__ . '/lib/app.php';

if (is_post()) {
    $_SESSION = [];
    session_destroy();
}
redirect('login.php');
