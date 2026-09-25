<?php
// Serves a conversation image, only to logged-in members of that activity's square.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/chat.php';

require_login();

$stmt = db()->prepare('SELECT m.image_file, a.school_id FROM activity_messages m JOIN activities a ON a.id = m.activity_id WHERE m.id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$row = $stmt->fetch();

$path = $row && $row['image_file'] && preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $row['image_file'], $m)
    ? UPLOAD_DIR . '/' . $row['image_file'] : null;

if (!$path || !isset(user_schools()[$row['school_id']]) || !is_file($path)) {
    http_response_code(404);
    exit('Afbeelding niet gevonden.');
}

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[$m[1]]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=604800');
header('X-Content-Type-Options: nosniff');
readfile($path);
