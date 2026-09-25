<?php
// Tasks: to-dos, chores, packing lists (event_id) and "wie neemt wat mee" (contact_id).
// A repeating task that is ticked off moves on to its next due date instead of staying done.

function find_task(int $id): ?array
{
    $stmt = db()->prepare('SELECT t.*, c.first_name, c.last_name, c.nickname, c.photo AS contact_photo, e.title AS event_title
        FROM fp_tasks t LEFT JOIN fp_contacts c ON c.id = t.contact_id LEFT JOIN fp_events e ON e.id = t.event_id WHERE t.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function add_task(array $t): int
{
    $due = !empty($t['due_date']) && strtotime((string) $t['due_date']) ? date('Y-m-d', strtotime((string) $t['due_date'])) : null;
    $member = !empty($t['member_id']) && member((int) $t['member_id']) ? (int) $t['member_id'] : null;
    $user = current_user();
    db()->prepare('INSERT INTO fp_tasks (title, notes, member_id, contact_id, event_id, due_date, recurrence, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        mb_cut(trim((string) $t['title']), 190),
        !empty($t['notes']) ? (string) $t['notes'] : null,
        $member,
        !empty($t['contact_id']) ? (int) $t['contact_id'] : null,
        !empty($t['event_id']) ? (int) $t['event_id'] : null,
        $due,
        isset(TASK_RECURRENCES[$t['recurrence'] ?? '']) ? (string) ($t['recurrence'] ?? '') : '',
        $user ? $user['id'] : null,
    ]);
    return (int) db()->lastInsertId();
}

function next_due(?string $due, string $rule): string
{
    $base = $due && $due > today() ? $due : today();
    $steps = ['DAILY' => '+1 day', 'WEEKLY' => '+1 week', 'MONTHLY' => '+1 month'];
    return date('Y-m-d', strtotime($base . ' ' . ($steps[$rule] ?? '+1 week')));
}

/** Tick a task on or off. Returns the new due date for a repeating task, otherwise null. */
function toggle_task(int $id, bool $done): ?string
{
    $task = find_task($id);
    if (!$task) {
        return null;
    }
    if ($done && $task['recurrence'] !== '') {
        $next = next_due($task['due_date'], $task['recurrence']);
        db()->prepare('UPDATE fp_tasks SET due_date = ?, done_at = NULL WHERE id = ?')->execute([$next, $id]);
        return $next;
    }
    db()->prepare('UPDATE fp_tasks SET done_at = ? WHERE id = ?')->execute([$done ? date('Y-m-d H:i:s') : null, $id]);
    return null;
}

function event_tasks(int $eventId): array
{
    $stmt = db()->prepare('SELECT t.*, c.first_name, c.last_name, c.nickname, c.photo AS contact_photo
        FROM fp_tasks t LEFT JOIN fp_contacts c ON c.id = t.contact_id WHERE t.event_id = ? ORDER BY t.sort, t.id');
    $stmt->execute([$eventId]);
    return array_map('task_json', $stmt->fetchAll());
}

function task_json(array $t): array
{
    return [
        'id' => (int) $t['id'],
        'title' => $t['title'],
        'member' => $t['member_id'] ? (int) $t['member_id'] : null,
        'contact' => $t['contact_id'] ? ['id' => (int) $t['contact_id'], 'name' => $t['nickname'] ?: $t['first_name']] : null,
        'due' => $t['due_date'],
        'done' => $t['done_at'] !== null,
        'recurrence' => $t['recurrence'],
    ];
}

/**
 * Open tasks (optionally only one member's), sorted by due date (no date last).
 * Options: member (id), include_done_since (Y-m-d), event (bool: include event checklists, default false), until (Y-m-d).
 */
function load_tasks(array $opt = []): array
{
    $where = [];
    $params = [];
    if (!empty($opt['member'])) {
        $where[] = 't.member_id = ?';
        $params[] = (int) $opt['member'];
    }
    if (empty($opt['event'])) {
        $where[] = 't.event_id IS NULL';
    }
    if (!empty($opt['until'])) {
        $where[] = '(t.due_date IS NULL OR t.due_date <= ?)';
        $params[] = $opt['until'];
    }
    if (!empty($opt['include_done_since'])) {
        $where[] = '(t.done_at IS NULL OR t.done_at >= ?)';
        $params[] = $opt['include_done_since'];
    } else {
        $where[] = 't.done_at IS NULL';
    }
    $sql = 'SELECT t.*, c.first_name, c.last_name, c.nickname, c.photo AS contact_photo, e.title AS event_title, e.start_at AS event_start
        FROM fp_tasks t LEFT JOIN fp_contacts c ON c.id = t.contact_id LEFT JOIN fp_events e ON e.id = t.event_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY t.done_at IS NOT NULL, t.due_date IS NULL, t.due_date, t.sort, t.id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** One task as a list item with a tick box (JS posts the toggle; works without JS through the form). */
function task_item(array $t, string $next = 'taken.php', bool $showMember = true): string
{
    $done = $t['done_at'] !== null;
    $m = member($t['member_id'] ? (int) $t['member_id'] : null);
    $meta = [];
    if ($t['due_date']) {
        $days = days_until($t['due_date']);
        $label = ucfirst(day_label($t['due_date']));
        $meta[] = !$done && $days < 0 ? '<span class="overdue">⏰ ' . e($label) . '</span>' : '📅 ' . e($label);
    }
    if ($t['recurrence'] !== '') {
        $meta[] = '🔁 ' . e(mb_strtolower(TASK_RECURRENCES[$t['recurrence']]));
    }
    if (!empty($t['event_title'])) {
        $meta[] = '📌 <a href="event.php?id=' . (int) $t['event_id'] . '">' . e($t['event_title']) . '</a>';
    }
    if ($t['contact_id']) {
        $meta[] = '👋 ' . e($t['nickname'] ?: $t['first_name']);
    }
    $html = '<li class="task' . ($done ? ' is-done' : '') . '" data-task="' . (int) $t['id'] . '">'
        . '<form method="post" action="taken.php" class="inline">' . csrf_field()
        . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . (int) $t['id'] . '">'
        . '<input type="hidden" name="done" value="' . ($done ? '0' : '1') . '"><input type="hidden" name="next" value="' . e($next) . '">'
        . '<input type="checkbox" class="tick js-task" aria-label="Afvinken"' . ($done ? ' checked' : '') . ' onchange="this.form.submit()"></form>'
        . '<div class="grow"><span class="title">' . e($t['title']) . '</span>'
        . ($meta ? '<span class="meta">' . implode(' · ', $meta) . '</span>' : '') . '</div>';
    if ($showMember && $m) {
        $html .= avatar($m, 28);
    }
    return $html . '</li>';
}
