<?php
require __DIR__ . '/lib/app.php';

$_SESSION = [];
session_destroy();
redirect('login.php');
