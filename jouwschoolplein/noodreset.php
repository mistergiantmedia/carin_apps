<?php
// One-time emergency reset for a locked-out admin. Needs a secret key (only its hash is stored here,
// because the repo is public), expires on a fixed date and deletes itself after use.
require __DIR__ . '/lib/app.php';

const KEY_HASH = 'ec2b205438e7b7d245ccdfe78c2ab9bba98663bfce09550eb05a30c1ac670e72';
const VALID_UNTIL = '2026-10-29';

$key = (string) ($_GET['key'] ?? '');
if (!hash_equals(KEY_HASH, hash('sha256', $key)) || date('Y-m-d') > VALID_UNTIL) {
    http_response_code(404);
    exit('Niet gevonden.');
}

$email = strtolower(trim((string) ($_GET['email'] ?? 'carin@mistergiant.com')));
$stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$userId = $stmt->fetchColumn();

if (!$userId) {
    $emails = db()->query('SELECT email FROM users ORDER BY email')->fetchAll(PDO::FETCH_COLUMN);
    page_start('Noodreset', ['narrow' => true]);
    echo '<article class="card"><h2>Dit e-mailadres bestaat niet</h2><p>Klik op het juiste adres:</p>';
    foreach ($emails as $m) {
        echo '<p><a href="?key=' . e($key) . '&email=' . e(urlencode($m)) . '">' . e($m) . '</a></p>';
    }
    echo '</article>';
    page_end();
    exit;
}

$token = bin2hex(random_bytes(32));
$pdo = db();
$pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
$pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))')
    ->execute([$userId, hash('sha256', $token)]);
@unlink(__FILE__);
redirect('reset.php?token=' . $token);
