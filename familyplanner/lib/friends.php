<?php
// Friends of the children: playdate counts (bij ons / bij hen), the vriendjesbingo and the vriendjesboek.

/** Contacts linked to a family member (friends), optionally only children. */
function friends_of(int $memberId, bool $kidsOnly = false): array
{
    $sql = 'SELECT c.*, h.name AS household_name FROM fp_contacts c JOIN fp_contact_members cm ON cm.contact_id = c.id
        LEFT JOIN fp_households h ON h.id = c.household_id WHERE cm.member_id = ?' . ($kidsOnly ? ' AND c.is_child = 1' : '') . ' ORDER BY c.first_name';
    $stmt = db()->prepare($sql);
    $stmt->execute([$memberId]);
    return $stmt->fetchAll();
}

/** Classmates of a member in the current school year (all classes of that year). */
function classmates_of(int $memberId, ?string $year = null): array
{
    $stmt = db()->prepare('SELECT DISTINCT c.*, h.name AS household_name FROM fp_contacts c JOIN fp_class_contacts cc ON cc.contact_id = c.id
        JOIN fp_classes k ON k.id = cc.class_id LEFT JOIN fp_households h ON h.id = c.household_id
        WHERE k.member_id = ? AND (k.school_year = ? OR k.school_year IS NULL OR k.school_year = \'\') ORDER BY c.first_name');
    $stmt->execute([$memberId, $year ?: school_year()]);
    return $stmt->fetchAll();
}

/**
 * Playdates of a member since $since: per contact id => [home, away, planned, last (date), next (date)].
 * Past playdates count as played; future ones as planned.
 */
function playdate_stats(int $memberId, ?string $since = null): array
{
    $since = $since ?: school_year_start();
    $until = date('Y-m-d', strtotime('+1 year'));
    $stats = [];
    $now = date('Y-m-d H:i:s');
    foreach (load_events($since, $until, ['types' => ['PLAYDATE']]) as $ev) {
        if (!in_array($memberId, $ev['members'], true)) {
            continue;
        }
        foreach ($ev['contacts'] as $c) {
            $s = &$stats[(int) $c['id']];
            $s = $s ?? ['home' => 0, 'away' => 0, 'other' => 0, 'planned' => 0, 'last' => null, 'next' => null];
            $date = substr($ev['start_at'], 0, 10);
            if ($ev['start_at'] > $now && !$ev['done']) {
                $s['planned']++;
                $s['next'] = $s['next'] && $s['next'] < $date ? $s['next'] : $date;
            } else {
                $key = $ev['host'] === 'HOME' ? 'home' : ($ev['host'] === 'AWAY' ? 'away' : 'other');
                $s[$key]++;
                $s['last'] = max($s['last'] ?? '', $date);
            }
            unset($s);
        }
    }
    return $stats;
}

/** Everyone on the bingo card: friends + classmates (children only), without duplicates. */
function bingo_people(int $memberId): array
{
    $people = [];
    foreach (array_merge(classmates_of($memberId), friends_of($memberId, true)) as $c) {
        $people[(int) $c['id']] = $c;
    }
    uasort($people, function ($a, $b) {
        return strcasecmp($a['first_name'], $b['first_name']);
    });
    return $people;
}

/** Where are the friend books now? Open handovers (not yet returned), newest first. */
function open_friendbooks(?int $memberId = null): array
{
    $sql = 'SELECT f.*, c.first_name, c.last_name, c.nickname, c.photo, c.id AS contact_id FROM fp_friendbook f
        LEFT JOIN fp_contacts c ON c.id = f.contact_id WHERE f.returned_on IS NULL' . ($memberId ? ' AND f.member_id = ?' : '') . ' ORDER BY f.given_on DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($memberId ? [$memberId] : []);
    return $stmt->fetchAll();
}
