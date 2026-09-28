<?php
// Regular week: a friend's fixed activities per weekday (fp_contact_week) and on which days they can
// play (fp_contact_days); our own children's clubs come from their repeating events in the agenda.
// Weekdays are ISO: 1 = Monday … 7 = Sunday.

const WEEKDAYS = [1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag', 5 => 'vrijdag', 6 => 'zaterdag', 7 => 'zondag'];
const WEEKDAYS_SHORT = [1 => 'ma', 2 => 'di', 3 => 'wo', 4 => 'do', 5 => 'vr', 6 => 'za', 7 => 'zo'];

// Kinds of regular activity: label, default emoji. BSO/opvang means "not available that afternoon".
const WEEK_KINDS = [
    'BSO' => ['BSO / opvang', '🏠'],
    'SPORT' => ['Sport', '⚽'],
    'CLUB' => ['Clubje / hobby', '🎨'],
    'MUSIC' => ['Muziek', '🎵'],
    'LESSON' => ['Les (zwemmen, bijles…)', '🏊'],
    'FAMILY' => ['Familie / oppas', '👵'],
    'OTHER' => ['Overig', '📌'],
];

function week_emoji(array $item): string
{
    // item_emoji when the row was joined with a contact (their avatar must not pick up the activity's emoji)
    $own = $item['item_emoji'] ?? ($item['emoji'] ?? null);
    return !empty($own) ? $own : (WEEK_KINDS[$item['kind']][1] ?? '📌');
}

function week_time(array $item): string
{
    if (!$item['start_time']) {
        return '';
    }
    return substr($item['start_time'], 0, 5) . ($item['end_time'] ? '–' . substr($item['end_time'], 0, 5) : '');
}

/** A contact's fixed activities, per weekday. */
function contact_week(int $contactId): array
{
    $stmt = db()->prepare('SELECT * FROM fp_contact_week WHERE contact_id = ? ORDER BY weekday, start_time IS NULL, start_time');
    $stmt->execute([$contactId]);
    $out = [];
    foreach ($stmt as $r) {
        $out[(int) $r['weekday']][] = $r;
    }
    return $out;
}

/** On which weekdays a contact can play: weekday => YES | NO (missing = unknown). */
function contact_days(int $contactId): array
{
    $stmt = db()->prepare('SELECT weekday, status FROM fp_contact_days WHERE contact_id = ?');
    $stmt->execute([$contactId]);
    $out = [];
    foreach ($stmt as $r) {
        $out[(int) $r['weekday']] = $r['status'];
    }
    return $out;
}

/**
 * What to know when planning with these contacts on $date: can't that day, has an activity, or usually can.
 * Returns a list of ['contact' => id, 'name', 'level' => bad|warn|ok, 'text'].
 */
function availability_notes(array $contactIds, string $date): array
{
    $ids = array_values(array_filter(array_map('intval', $contactIds)));
    if (!$ids) {
        return [];
    }
    $wd = (int) date('N', strtotime($date));
    $in = implode(',', $ids);
    $names = [];
    foreach (db()->query("SELECT id, first_name, last_name, nickname FROM fp_contacts WHERE id IN ($in)") as $c) {
        $names[(int) $c['id']] = contact_name($c, false);
    }
    $status = [];
    foreach (db()->query("SELECT contact_id, status FROM fp_contact_days WHERE weekday = $wd AND contact_id IN ($in)") as $r) {
        $status[(int) $r['contact_id']] = $r['status'];
    }
    $items = [];
    foreach (db()->query("SELECT * FROM fp_contact_week WHERE weekday = $wd AND contact_id IN ($in) ORDER BY start_time") as $r) {
        $items[(int) $r['contact_id']][] = $r;
    }
    $out = [];
    foreach ($ids as $cid) {
        if (!isset($names[$cid])) {
            continue;
        }
        $name = $names[$cid];
        $day = WEEKDAYS[$wd];
        foreach ($items[$cid] ?? [] as $it) {
            $t = week_time($it);
            $out[] = ['contact' => $cid, 'name' => $name, 'level' => $it['kind'] === 'BSO' ? 'bad' : 'warn',
                'text' => week_emoji($it) . ' ' . $name . ' heeft op ' . $day . ' ' . $it['title'] . ($t ? ' (' . $t . ')' : '')];
        }
        if (($status[$cid] ?? '') === 'NO') {
            $out[] = ['contact' => $cid, 'name' => $name, 'level' => 'bad', 'text' => '❌ ' . $name . ' kan meestal niet op ' . $day];
        } elseif (($status[$cid] ?? '') === 'YES' && empty($items[$cid])) {
            $out[] = ['contact' => $cid, 'name' => $name, 'level' => 'ok', 'text' => '✅ ' . $name . ' kan meestal op ' . $day];
        }
    }
    return $out;
}

/**
 * Our child's clubs: repeating (weekly / every other week / weekdays) sport, activity and other events in the agenda.
 * Each: the event row + weekday (ISO) + time.
 */
function member_clubs(int $memberId): array
{
    $stmt = db()->prepare("SELECT e.* FROM fp_events e JOIN fp_event_members em ON em.event_id = e.id
        WHERE em.member_id = ? AND e.recurrence IN ('WEEKLY', 'BIWEEKLY') AND e.type IN ('SPORT', 'ACTIVITY', 'OTHER')
          AND (e.recur_until IS NULL OR e.recur_until >= CURDATE()) ORDER BY WEEKDAY(e.start_at), TIME(e.start_at)");
    $stmt->execute([$memberId]);
    $out = [];
    foreach ($stmt as $e) {
        $e['weekday'] = (int) date('N', strtotime($e['start_at']));
        $e['time'] = $e['all_day'] ? '' : substr($e['start_at'], 11, 5) . '–' . substr($e['end_at'], 11, 5);
        $out[] = $e;
    }
    return $out;
}

/** Normalised activity name for matching "Hockey" with "hockey training". */
function activity_key(string $title): string
{
    $t = mb_strtolower(trim($title));
    $t = preg_replace('/\b(training|les|lessen|club|clubje|proefles|op|de|het)\b/u', '', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

/** Friends (contacts) whose regular activities look like $title. Returns contact rows with weekday + item. */
function friends_doing(string $title): array
{
    $key = activity_key($title);
    if ($key === '') {
        return [];
    }
    $out = [];
    foreach (db()->query('SELECT w.id AS week_id, w.contact_id, w.weekday, w.start_time, w.end_time, w.kind, w.title, w.emoji AS item_emoji, c.id, c.first_name, c.last_name, c.nickname, c.photo, c.is_child FROM fp_contact_week w JOIN fp_contacts c ON c.id = w.contact_id') as $r) {
        $k = activity_key($r['title']);
        if ($k !== '' && (strpos($k, $key) !== false || strpos($key, $k) !== false)) {
            $out[] = $r;
        }
    }
    return $out;
}
