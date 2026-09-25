<?php
// Photo uploads: resized and re-encoded as JPEG (strips GPS/EXIF), random file names,
// stored in uploads/ (server only, gitignored) and served to logged-in users by foto.php.
// Every photo gets a large version (name.jpg) and a square thumbnail (name_t.jpg).

const UPLOAD_DIR = __DIR__ . '/../uploads';

/**
 * Save an uploaded image from $_FILES[$field]. Returns the file name, or null when nothing was uploaded.
 * Throws with a Dutch message when the file is not a usable image.
 */
function save_photo(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'De foto is te groot.' : 'Uploaden mislukt, probeer het nog eens.');
    }
    return save_photo_file($f['tmp_name']);
}

/** Save multiple uploaded images from $_FILES[$field][] (e.g. several school photos at once). */
function save_photos(string $field): array
{
    $f = $_FILES[$field] ?? null;
    $names = [];
    if (!$f || !is_array($f['name'])) {
        return $names;
    }
    foreach ($f['tmp_name'] as $i => $tmp) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $names[] = save_photo_file($tmp);
        }
    }
    return $names;
}

function save_photo_file(string $path): string
{
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException("Foto's uploaden kan niet: de GD-extensie van PHP ontbreekt op de server.");
    }
    $info = @getimagesize($path);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Dit bestand is geen foto (gebruik jpg, png, gif of webp).');
    }
    if ($info[0] * $info[1] > 50000000) {
        throw new RuntimeException('Deze foto is te groot.');
    }
    $img = @imagecreatefromstring((string) file_get_contents($path));
    if (!$img) {
        throw new RuntimeException('Deze foto kon niet worden gelezen.');
    }
    $img = fix_orientation($img, $path, $info[2]);

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true)) {
        throw new RuntimeException('De map uploads kan niet worden aangemaakt op de server.');
    }
    $name = bin2hex(random_bytes(12)) . '.jpg';
    write_jpeg(resize_image($img, 1600, false), UPLOAD_DIR . '/' . $name, 85);
    write_jpeg(resize_image($img, 400, true), UPLOAD_DIR . '/' . thumb_name($name), 82);
    imagedestroy($img);
    return $name;
}

function thumb_name(string $name): string
{
    return preg_replace('/\.jpg$/', '_t.jpg', $name);
}

/** Phone photos are often stored sideways with an EXIF hint; turn them upright. */
function fix_orientation($img, string $path, int $type)
{
    if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($path);
    $o = (int) ($exif['Orientation'] ?? 1);
    $angles = [3 => 180, 6 => -90, 8 => 90];
    if (isset($angles[$o])) {
        $rotated = imagerotate($img, $angles[$o], 0);
        if ($rotated) {
            imagedestroy($img);
            return $rotated;
        }
    }
    return $img;
}

/** Scale down to fit $max (longest side), or crop to a centred $max square. */
function resize_image($img, int $max, bool $square)
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($square) {
        $side = min($w, $h);
        $size = min($max, $side);
        $out = imagecreatetruecolor($size, $size);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        // Faces tend to be in the upper part of portrait photos
        $sy = $h > $w ? (int) (($h - $side) * 0.25) : 0;
        imagecopyresampled($out, $img, 0, 0, (int) (($w - $side) / 2), $sy, $size, $size, $side, $side);
        return $out;
    }
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $out;
}

function write_jpeg($img, string $file, int $quality): void
{
    imageinterlace($img, true);
    if (!imagejpeg($img, $file, $quality)) {
        throw new RuntimeException('De foto kon niet worden opgeslagen op de server.');
    }
    imagedestroy($img);
}

function delete_photo(?string $name): void
{
    if (!$name || !preg_match('/^[a-f0-9]{24}\.jpg$/', $name)) {
        return;
    }
    @unlink(UPLOAD_DIR . '/' . $name);
    @unlink(UPLOAD_DIR . '/' . thumb_name($name));
}

/** File input + preview of the current photo, with an option to remove it. */
function photo_field(?string $current, string $label = 'Foto'): string
{
    $html = '<label>' . e($label) . '</label><div class="photo-field">';
    if ($current) {
        $html .= '<img src="foto.php?f=' . e($current) . '&amp;s=t" alt="">'
            . '<label class="check"><input type="checkbox" name="remove_photo" value="1"> Foto verwijderen</label>';
    }
    return $html . '<input type="file" name="photo" accept="image/*"></div>';
}

/**
 * Handle photo_field() on save: returns the photo name to store (new, unchanged or null).
 * Removes the old file when it is replaced or removed.
 */
function posted_photo(?string $current): ?string
{
    $new = save_photo('photo');
    if ($new) {
        delete_photo($current);
        return $new;
    }
    if (!empty($_POST['remove_photo'])) {
        delete_photo($current);
        return null;
    }
    return $current;
}
