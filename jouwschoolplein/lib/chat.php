<?php
// Conversation helpers: image uploads, emoji reactions, avatars and day separators.

const REACTIONS = ['👍', '❤️', '😂', '😮', '🙏', '🎉'];
const UPLOAD_DIR = APP_ROOT . '/uploads';
const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
const MAX_IMAGE_SIDE = 1600;

/**
 * Validate and store an uploaded image; returns the stored file name.
 * With GD available the image is resized and re-encoded, which also strips EXIF data such as GPS location.
 */
function store_uploaded_image(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file['size'] ?? 0) > MAX_IMAGE_BYTES) {
        throw new RuntimeException('Deze afbeelding is te groot (maximaal 8 MB).');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Het uploaden van de afbeelding is mislukt. Probeer het opnieuw.');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Alleen foto\'s en plaatjes (JPG, PNG, GIF of WebP) kunnen worden geplaatst.');
    }
    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true)) {
        throw new RuntimeException('Afbeeldingen kunnen nu niet worden opgeslagen (map uploads ontbreekt).');
    }

    $name = bin2hex(random_bytes(16));
    $source = function_exists('imagecreatefromstring') ? @imagecreatefromstring(file_get_contents($file['tmp_name'])) : false;

    if ($source) {
        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = min(1, MAX_IMAGE_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $canvas = imagecreatetruecolor($nw, $nh);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // transparent PNGs get a white background
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $name .= '.jpg';
        $ok = imagejpeg($canvas, UPLOAD_DIR . '/' . $name, 85);
        imagedestroy($source);
        imagedestroy($canvas);
    } else {
        $name .= '.' . $types[$info[2]];
        $ok = move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name);
    }
    if (!$ok) {
        throw new RuntimeException('Afbeeldingen kunnen nu niet worden opgeslagen.');
    }
    return $name;
}

function delete_upload(?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $name)) {
        @unlink(UPLOAD_DIR . '/' . $name);
    }
}

/**
 * Remove the image files of the messages matching $where, before the rows are deleted.
 */
function delete_message_images(string $where, array $params): void
{
    $stmt = db()->prepare("SELECT image_file FROM activity_messages WHERE image_file IS NOT NULL AND $where");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        delete_upload($file);
    }
}

/**
 * Reactions per message id: [message_id => [emoji => ['count' => n, 'mine' => bool, 'names' => [...]]]]
 */
function load_reactions(array $messageIds, int $userId): array
{
    if (!$messageIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($messageIds), '?'));
    $stmt = db()->prepare("SELECT r.message_id, r.emoji, r.user_id, u.name FROM message_reactions r JOIN users u ON u.id = r.user_id WHERE r.message_id IN ($in) ORDER BY r.created_at");
    $stmt->execute(array_values($messageIds));
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $item = &$result[$r['message_id']][$r['emoji']];
        $item['count'] = ($item['count'] ?? 0) + 1;
        $item['mine'] = ($item['mine'] ?? false) || (int) $r['user_id'] === $userId;
        $item['names'][] = $r['name'];
        unset($item);
    }
    // Stable order: same as the picker
    foreach ($result as &$perMessage) {
        uksort($perMessage, function ($a, $b) {
            return array_search($a, REACTIONS, true) <=> array_search($b, REACTIONS, true);
        });
    }
    unset($perMessage);
    return $result;
}

/**
 * Reaction chips under a message. Each chip is a button that toggles your own reaction.
 */
function render_reactions(int $messageId, array $reactions): string
{
    $html = '';
    foreach ($reactions as $emoji => $r) {
        $html .= '<button class="reaction' . ($r['mine'] ? ' mine' : '') . '" name="emoji" value="' . e($emoji) . '" title="' . e(implode(', ', $r['names'])) . '">'
            . e($emoji) . ($r['count'] > 1 ? ' <span>' . (int) $r['count'] . '</span>' : '') . '</button>';
    }
    return $html;
}

function avatar_color(int $userId): string
{
    $colors = ['#4F8A6D', '#4F7FA3', '#E9826B', '#E8893A', '#8A6FB0', '#C2577A', '#3E9A9A', '#B08A2E'];
    return $colors[$userId % count($colors)];
}

function format_day(string $datetime): string
{
    $day = date('Y-m-d', strtotime($datetime));
    if ($day === date('Y-m-d')) {
        return 'Vandaag';
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return 'Gisteren';
    }
    $months = ['', 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    $t = strtotime($day);
    return date('j', $t) . ' ' . $months[(int) date('n', $t)] . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '');
}
