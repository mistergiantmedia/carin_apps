<?php
// Bucketlist: wishes with votes ("Ik wil ook!"), friends who join (fp_bucket_contacts),
// optionally planned (event) and ticked off (done_on).

/**
 * Bucketlist items with votes (member ids), friends (contact rows) and the planned event, open ones by votes.
 * Options: done (bool|null: null = all), member (id: wishes this member wants),
 * contact (id: wishes with this friend), family_only (bool: wishes without friends).
 */
function load_bucket(array $opt = []): array
{
    $where = [];
    $params = [];
    if (isset($opt['done'])) {
        $where[] = $opt['done'] ? 'b.done_on IS NOT NULL' : 'b.done_on IS NULL';
    }
    if (!empty($opt['member'])) {
        $where[] = 'EXISTS (SELECT 1 FROM fp_bucket_votes v WHERE v.bucket_id = b.id AND v.member_id = ?)';
        $params[] = (int) $opt['member'];
    }
    if (!empty($opt['contact'])) {
        $where[] = 'EXISTS (SELECT 1 FROM fp_bucket_contacts bc WHERE bc.bucket_id = b.id AND bc.contact_id = ?)';
        $params[] = (int) $opt['contact'];
    }
    if (!empty($opt['family_only'])) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM fp_bucket_contacts bc WHERE bc.bucket_id = b.id)';
    }
    $stmt = db()->prepare('SELECT b.*, e.start_at AS event_start, e.title AS event_title,
            (SELECT COUNT(*) FROM fp_bucket_votes v WHERE v.bucket_id = b.id) AS votes
        FROM fp_bucket b LEFT JOIN fp_events e ON e.id = b.event_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY b.done_on IS NOT NULL, b.done_on DESC, votes DESC, b.created_at DESC');
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    if (!$items) {
        return [];
    }
    $voters = [];
    foreach (db()->query('SELECT bucket_id, member_id FROM fp_bucket_votes ORDER BY created_at') as $r) {
        $voters[$r['bucket_id']][] = (int) $r['member_id'];
    }
    $friends = [];
    foreach (db()->query('SELECT bc.bucket_id, c.id, c.first_name, c.last_name, c.nickname, c.photo
            FROM fp_bucket_contacts bc JOIN fp_contacts c ON c.id = bc.contact_id ORDER BY c.first_name') as $r) {
        $friends[$r['bucket_id']][] = $r;
    }
    foreach ($items as &$it) {
        $it['voters'] = $voters[$it['id']] ?? [];
        $it['friends'] = $friends[$it['id']] ?? [];
    }
    unset($it);
    return $items;
}

/** A contact in the shape the people picker (app.js) and the event editor use. */
function bucket_contact_json(array $c): array
{
    return ['id' => (int) $c['id'], 'name' => contact_name($c, false), 'fullName' => contact_name($c), 'photo' => $c['photo'], 'color' => name_color(contact_name($c, false)), 'rsvp' => ''];
}

/** Replace the friends of a wish with the ids posted as contacts_json by the people picker. */
function save_bucket_friends(int $bucketId, string $json): void
{
    db()->prepare('DELETE FROM fp_bucket_contacts WHERE bucket_id = ?')->execute([$bucketId]);
    $ins = db()->prepare('INSERT IGNORE INTO fp_bucket_contacts (bucket_id, contact_id) SELECT ?, id FROM fp_contacts WHERE id = ?');
    foreach ((array) (json_decode($json, true) ?: []) as $c) {
        $cid = (int) (is_array($c) ? ($c['id'] ?? 0) : $c);
        if ($cid > 0) {
            $ins->execute([$bucketId, $cid]);
        }
    }
}
