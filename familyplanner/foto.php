<?php
// Serves an uploaded photo to logged-in family members only. ?f=<name>.jpg&s=t for the thumbnail.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/upload.php';

if (!current_user()) {
    http_response_code(403);
    exit;
}
$name = (string) ($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{24}\.jpg$/', $name)) {
    http_response_code(404);
    exit;
}
// Only photos that belong to the logged-in family
$own = db()->prepare('SELECT 1 FROM fp_members WHERE photo = ? UNION SELECT 1 FROM fp_contacts WHERE photo = ? UNION SELECT 1 FROM fp_photos WHERE file = ? LIMIT 1');
$own->execute([$name, $name, $name]);
if (!$own->fetchColumn()) {
    http_response_code(404);
    exit;
}
$file = UPLOAD_DIR . '/' . (($_GET['s'] ?? '') === 't' ? thumb_name($name) : $name);
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
session_write_close();
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=31536000, immutable');
if (!empty($_GET['download'])) {
    header('Content-Disposition: attachment; filename="foto-' . substr($name, 0, 8) . '.jpg"');
}
readfile($file);
