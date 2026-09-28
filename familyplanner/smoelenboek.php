<?php
// The smoelenboek is now part of Groepen (school classes are groups of type SCHOOL). Old links keep working.
$id = isset($_GET['class']) && is_numeric($_GET['class']) ? (int) $_GET['class'] : 0;
header('Location: ' . ($id ? 'groepen.php?id=' . $id : 'groepen.php?type=SCHOOL'));
