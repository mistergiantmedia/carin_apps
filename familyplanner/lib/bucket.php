<?php
// Bucketlist: wishes with votes ("Ik wil ook!"), optionally planned (event) and ticked off (done_on).

/**
 * Bucketlist items with votes (member ids) and the planned event (date), open ones sorted by votes.
 * Options: done (bool|null: null = all), member (id: only wishes this member wants).
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
    foreach ($items as &$it) {
        $it['voters'] = $voters[$it['id']] ?? [];
    }
    unset($it);
    return $items;
}
