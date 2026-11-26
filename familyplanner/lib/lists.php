<?php
// Lijstjes: a list per thing to sort out ("Energie overstappen", "Dekbedden kopen") with
//  - TODO:        things to do (tick box, optional who)
//  - OPTION:      something to compare: link, price (+ "per maand"), pros and cons, "this is our pick"
//  - INSPIRATION: a link / product / concept worth remembering
// Used by lijsten.php and the MCP tools. Keep this PHP 7.4 compatible.

const LIST_KINDS = [
    'TODO' => ['✅', 'Te doen'],
    'OPTION' => ['🛒', 'Opties'],
    'INSPIRATION' => ['💡', 'Inspiratie'],
];

/** "€ 1.299,50", "12,5", "12.50", "1.299" → float, null when empty / not a number. */
function parse_price($v): ?float
{
    $v = trim(str_replace(['€', ' ', "\xc2\xa0"], '', (string) $v));
    if ($v === '') {
        return null;
    }
    if (strpos($v, ',') !== false) {
        $v = str_replace(',', '.', str_replace('.', '', $v));
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
        $v = str_replace('.', '', $v);
    }
    return is_numeric($v) && $v >= 0 && $v < 100000000 ? round((float) $v, 2) : null;
}

function clean_url($v): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    if (!preg_match('~^https?://~i', $v) && preg_match('~^[\w-]+(\.[\w-]+)+(/|$)~', $v)) {
        $v = 'https://' . $v;
    }
    return preg_match('~^https?://[^\s]+$~i', $v) ? mb_cut($v, 500) : null;
}

/** Best-effort title + picture of a link (og:title / <title>, og:image). Never throws; keys can be null. */
function link_meta(string $url): array
{
    $out = ['title' => null, 'image' => null];
    try {
        require_once __DIR__ . '/meals.php';
        $html = fetch_public_url($url, 600000);
        if ($html === null) {
            return $out;
        }
        $og = function ($prop) use ($html) {
            if (preg_match('~<meta[^>]+(?:property|name)=["\']' . $prop . '["\'][^>]+content=["\']([^"\']+)~i', $html, $m)
                || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $prop . '["\']~i', $html, $m)) {
                return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            return null;
        };
        $t = $og('og:title');
        if ($t === null && preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
            $t = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $out['title'] = $t !== null && $t !== '' ? mb_cut(preg_replace('/\s+/', ' ', $t), 190) : null;
        $img = $og('og:image') ?: $og('twitter:image');
        if ($img) {
            $p = parse_url($url);
            if (strpos($img, '//') === 0) {
                $img = $p['scheme'] . ':' . $img;
            } elseif (isset($img[0]) && $img[0] === '/') {
                $img = $p['scheme'] . '://' . $p['host'] . $img;
            }
            $out['image'] = clean_url($img);
        }
    } catch (Throwable $e) {
        // best effort only
    }
    return $out;
}

/** Specs text ("Label: value" per line) → ordered [label => value]. A line without colon is a yes-feature. */
function list_specs(?string $text): array
{
    $out = [];
    foreach (preg_split('/\R/u', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^([^:]{1,60}):\s*(.*)$/u', $line, $m)) {
            $out[trim($m[1])] = trim($m[2]) !== '' ? trim($m[2]) : '✓';
        } else {
            $out[$line] = '✓';
        }
    }
    return $out;
}

/** Rows for the comparison: label => [item id => value], in first-seen order. */
function spec_matrix(array $options): array
{
    $rows = [];
    $key = [];
    foreach ($options as $o) {
        foreach (list_specs($o['specs']) as $label => $val) {
            $k = mb_strtolower($label);
            $key[$k] = $key[$k] ?? $label;
            $rows[$k][(int) $o['id']] = $val;
        }
    }
    $out = [];
    foreach ($rows as $k => $vals) {
        $out[$key[$k]] = $vals;
    }
    return $out;
}

function link_host(?string $url): string
{
    $h = (string) parse_url((string) $url, PHP_URL_HOST);
    return preg_replace('/^www\./', '', $h);
}

/** Pros / cons text (one per line, "+ " / "- " allowed) → clean list of lines. */
function list_lines(?string $text): array
{
    $out = [];
    foreach (preg_split('/\R/u', (string) $text) as $line) {
        $line = trim(preg_replace('/^\s*[-+•*]\s*/u', '', $line));
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** Picture stored on our own server (file name from lib/upload.php) instead of a link? */
function is_local_image(?string $v): bool
{
    return $v !== null && (bool) preg_match('/^[a-f0-9]{24}\.jpg$/', $v);
}

/** src for an item's picture: foto.php for our own files, the link otherwise. */
function item_image_src(?string $v): string
{
    return is_local_image($v) ? 'foto.php?f=' . $v : (string) $v;
}

function find_list(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM fp_lists WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Find a list by id or by (part of) its title. Throws InvalidArgumentException when unclear (for MCP). */
function resolve_list(?int $id, string $name = ''): array
{
    if ($id) {
        if ($l = find_list($id)) {
            return $l;
        }
        throw new InvalidArgumentException('Deze lijst bestaat niet (gebruik list_lists).');
    }
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Geef list_id of list_name (gebruik list_lists).');
    }
    $all = db()->query('SELECT * FROM fp_lists WHERE done_at IS NULL ORDER BY title')->fetchAll();
    $exact = $part = [];
    $needle = mb_strtolower($name);
    foreach ($all as $l) {
        $t = mb_strtolower($l['title']);
        if ($t === $needle) {
            $exact[] = $l;
        } elseif (mb_strpos($t, $needle) !== false || mb_strpos($needle, $t) !== false) {
            $part[] = $l;
        }
    }
    $hit = $exact ?: $part;
    if (count($hit) === 1) {
        return $hit[0];
    }
    if (!$hit) {
        throw new InvalidArgumentException("Geen lijst gevonden die lijkt op '$name'. Bestaande lijsten: "
            . implode(', ', array_column($all, 'title')) . '. Maak anders eerst een lijst met save_list.');
    }
    throw new InvalidArgumentException("Meerdere lijsten lijken op '$name': " . implode(', ', array_map(function ($l) {
        return $l['title'] . ' (id ' . $l['id'] . ')';
    }, $hit)) . '. Geef list_id.');
}

/** Lists with counts. $done: false = active lists, true = finished lists. */
function load_lists(bool $done = false): array
{
    return db()->query('SELECT l.*,
            (SELECT COUNT(*) FROM fp_list_items i WHERE i.list_id = l.id AND i.kind = \'TODO\') AS todos,
            (SELECT COUNT(*) FROM fp_list_items i WHERE i.list_id = l.id AND i.kind = \'TODO\' AND i.done_at IS NOT NULL) AS todos_done,
            (SELECT COUNT(*) FROM fp_list_items i WHERE i.list_id = l.id AND i.kind = \'OPTION\') AS options,
            (SELECT COUNT(*) FROM fp_list_items i WHERE i.list_id = l.id AND i.kind = \'INSPIRATION\') AS inspiration
        FROM fp_lists l WHERE l.done_at IS ' . ($done ? 'NOT NULL' : 'NULL') . ' ORDER BY l.created_at DESC, l.id DESC')->fetchAll();
}

/** All items of a list, per kind. Options: cheapest first (no price last), picked one on top. */
function load_list_items(int $listId): array
{
    $stmt = db()->prepare('SELECT * FROM fp_list_items WHERE list_id = ? ORDER BY id');
    $stmt->execute([$listId]);
    $by = ['TODO' => [], 'OPTION' => [], 'INSPIRATION' => []];
    foreach ($stmt->fetchAll() as $i) {
        $by[isset($by[$i['kind']]) ? $i['kind'] : 'TODO'][] = $i;
    }
    usort($by['TODO'], function ($a, $b) {
        return [$a['done_at'] !== null, (int) $a['id']] <=> [$b['done_at'] !== null, (int) $b['id']];
    });
    usort($by['OPTION'], function ($a, $b) {
        $pa = $a['price'] === null ? PHP_INT_MAX : (float) $a['price'];
        $pb = $b['price'] === null ? PHP_INT_MAX : (float) $b['price'];
        return [-(int) $a['chosen'], $pa, (int) $a['id']] <=> [-(int) $b['chosen'], $pb, (int) $b['id']];
    });
    return $by;
}

function find_list_item(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM fp_list_items WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Ids of the options that are the cheapest of their price kind (same "per maand" note), only when there is something to compare. */
function cheapest_options(array $options): array
{
    $groups = [];
    foreach ($options as $o) {
        if ($o['price'] !== null) {
            $groups[mb_strtolower((string) $o['price_note'])][] = $o;
        }
    }
    $ids = [];
    foreach ($groups as $g) {
        if (count($g) < 2) {
            continue;
        }
        $min = min(array_map(function ($o) {
            return (float) $o['price'];
        }, $g));
        foreach ($g as $o) {
            if ((float) $o['price'] === $min) {
                $ids[] = (int) $o['id'];
            }
        }
    }
    return $ids;
}

/**
 * Insert or update an item from an array (page form or MCP). Only keys that are present change on update.
 * Returns the item id. Throws InvalidArgumentException on bad input.
 */
function save_list_item(int $listId, array $d, ?int $id = null): int
{
    $old = $id ? find_list_item($id) : null;
    if ($id && (!$old || (int) $old['list_id'] !== $listId)) {
        throw new InvalidArgumentException('Dit onderdeel bestaat niet (gebruik get_list).');
    }
    $has = function ($k) use ($d) {
        return array_key_exists($k, $d) && $d[$k] !== null;
    };
    $kind = $has('kind') ? strtoupper(trim((string) $d['kind'])) : ($old['kind'] ?? 'TODO');
    if (!isset(LIST_KINDS[$kind])) {
        throw new InvalidArgumentException('kind moet TODO, OPTION of INSPIRATION zijn.');
    }
    $url = $has('url') ? clean_url($d['url']) : ($old['url'] ?? null);
    if ($has('url') && trim((string) $d['url']) !== '' && $url === null) {
        throw new InvalidArgumentException('Dit is geen geldige link (begin met https://).');
    }
    $title = $has('title') ? trim((string) $d['title']) : (string) ($old['title'] ?? '');
    $image = $has('image_url') ? clean_url($d['image_url']) : ($old['image_url'] ?? null);
    if (!empty($d['image_file']) && preg_match('/^[a-f0-9]{24}\.jpg$/', (string) $d['image_file'])) {
        $image = (string) $d['image_file']; // an uploaded / pasted picture (lib/upload.php)
    }
    $imageCleared = $has('image_url') && trim((string) $d['image_url']) === '' && !empty($old['image_url']);
    if ($url && ($title === '' || ($image === null && $kind !== 'TODO' && !$imageCleared)) && (!$old || $url !== $old['url'] || $image === null)) {
        $meta = link_meta($url);
        $title = $title !== '' ? $title : ($meta['title'] ?: link_host($url));
        $image = $image ?: ($imageCleared ? null : $meta['image']);
    }
    if ($title === '') {
        throw new InvalidArgumentException('Geef een titel (of een link).');
    }
    $text = function ($k) use ($has, $d, $old) {
        $v = $has($k) ? trim((string) $d[$k]) : (string) ($old[$k] ?? '');
        return $v !== '' ? $v : null;
    };
    $price = $has('price') ? parse_price($d['price']) : ($old['price'] ?? null);
    if ($has('price') && trim((string) $d['price']) !== '' && $price === null) {
        throw new InvalidArgumentException('Dit bedrag begrijp ik niet. Gebruik bijvoorbeeld 49,95.');
    }
    $member = $has('member_id') ? (member((int) $d['member_id']) ? (int) $d['member_id'] : null) : ($old['member_id'] ?? null);
    $rating = $has('rating') ? (int) $d['rating'] : (isset($old['rating']) ? (int) $old['rating'] : 0);
    $rating = $rating >= 1 && $rating <= 5 ? $rating : null;
    $row = [$kind, mb_cut($title, 190), $url, $image, $rating, $text('specs'), $price, $text('price_note') !== null ? mb_cut($text('price_note'), 60) : null,
        $text('pros'), $text('cons'), $text('notes'), $member];
    if ($old) {
        db()->prepare('UPDATE fp_list_items SET kind = ?, title = ?, url = ?, image_url = ?, rating = ?, specs = ?, price = ?, price_note = ?, pros = ?, cons = ?, notes = ?, member_id = ? WHERE id = ?')
            ->execute(array_merge($row, [$id]));
    } else {
        $user = current_user();
        db()->prepare('INSERT INTO fp_list_items (kind, title, url, image_url, rating, specs, price, price_note, pros, cons, notes, member_id, list_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge($row, [$listId, $user ? $user['id'] : null]));
        $id = (int) db()->lastInsertId();
    }
    if ($old && $old['image_url'] !== $image && is_local_image($old['image_url'])) {
        require_once __DIR__ . '/upload.php';
        delete_photo($old['image_url']);
    }
    if ($has('done')) {
        db()->prepare('UPDATE fp_list_items SET done_at = ? WHERE id = ?')->execute([!empty($d['done']) ? date('Y-m-d H:i:s') : null, $id]);
    }
    if ($has('chosen')) {
        set_list_choice($listId, $id, !empty($d['chosen']));
    }
    return $id;
}

/** Mark an option as "our pick" (only one pick per list); false removes the mark. */
function set_list_choice(int $listId, int $itemId, bool $on): void
{
    if ($on) {
        db()->prepare("UPDATE fp_list_items SET chosen = 0 WHERE list_id = ? AND kind = 'OPTION'")->execute([$listId]);
    }
    db()->prepare("UPDATE fp_list_items SET chosen = ? WHERE id = ? AND list_id = ? AND kind = 'OPTION'")->execute([$on ? 1 : 0, $itemId, $listId]);
}

function toggle_list_item(int $id): void
{
    db()->prepare('UPDATE fp_list_items SET done_at = IF(done_at IS NULL, NOW(), NULL) WHERE id = ?')->execute([$id]);
}
