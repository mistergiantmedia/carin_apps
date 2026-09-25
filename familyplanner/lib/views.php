<?php
// Shared overview blocks: kid week board, month table and year grid (server-rendered, printable).

/** Events + birthdays grouped per day (Y-m-d => list), for days in [$from, $to). */
function group_by_day(array $events, array $birthdays, string $from, string $to): array
{
    $days = [];
    foreach ($events as $ev) {
        $d = max(substr($ev['start_at'], 0, 10), $from);
        $last = substr($ev['end_at'], 0, 10);
        for ($guard = 0; $d <= $last && $d < $to && $guard < 400; $guard++, $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $days[$d]['events'][] = $ev;
        }
    }
    foreach ($birthdays as $b) {
        $days[$b['date']]['birthdays'][] = $b;
    }
    return $days;
}

/** Where a playdate happens, in words a child understands. */
function where_text(array $ev): string
{
    if ($ev['host'] === 'HOME') {
        return '🏠 bij ons thuis';
    }
    if ($ev['host'] === 'AWAY' && $ev['contacts']) {
        return '🚗 bij ' . contact_name($ev['contacts'][0], false);
    }
    return $ev['location'] ? '📍 ' . $ev['location'] : '';
}

/** Colourful 7-day board for a child, with big friend photos. */
function kid_week_board(array $kid, string $weekStart, bool $showDone = true): string
{
    $weekEnd = date('Y-m-d', strtotime("$weekStart +7 day"));
    $events = array_values(array_filter(load_events($weekStart, $weekEnd), function ($ev) use ($kid) {
        return in_array((int) $kid['id'], $ev['members'], true);
    }));
    $birthdays = load_birthdays($weekStart, $weekEnd, ['members' => [(int) $kid['id']]]);
    $byDay = group_by_day($events, $birthdays, $weekStart, $weekEnd);
    $html = '<div class="kid-week" style="--c:' . e($kid['color']) . '">';
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("$weekStart +$i day"));
        $w = (int) date('w', strtotime($d));
        $html .= '<div class="kid-day' . ($d === today() ? ' today' : '') . ($w === 0 || $w === 6 ? ' weekend' : '') . '">'
            . '<div class="kid-day-head"><div class="dn">' . ($d === today() ? 'Vandaag' : e(DAYS[$w])) . '</div><div class="dd">' . (int) substr($d, 8) . ' ' . e(MONTHS[(int) substr($d, 5, 2)]) . '</div></div>';
        $items = 0;
        foreach ($byDay[$d]['birthdays'] ?? [] as $b) {
            $items++;
            $html .= '<div class="kid-item" style="--ec:#E0568A"><div class="ki-emoji">🎂</div><div class="ki-title">' . e($b['person']['nickname'] ?? '' ?: ($b['kind'] === 'member' ? $b['name'] : $b['person']['first_name'])) . ' is jarig!</div>'
                . ($b['age'] !== null ? '<div class="ki-time">wordt ' . $b['age'] . '</div>' : '')
                . '<div class="ki-friends">' . avatar($b['person'], 48) . '</div></div>';
        }
        foreach ($byDay[$d]['events'] ?? [] as $ev) {
            if (!$showDone && $ev['done']) {
                continue;
            }
            $items++;
            $t = event_type($ev['type']);
            $friends = '';
            foreach ($ev['contacts'] as $c) {
                $friends .= '<span class="ki-friend">' . avatar($c, 52) . e(contact_name($c, false)) . '</span>';
            }
            $time = $ev['all_day'] ? '' : substr($ev['start_at'], 11, 5) . ' – ' . substr($ev['end_at'], 11, 5);
            $where = where_text($ev);
            $html .= '<a class="kid-item' . ($ev['done'] ? ' done' : '') . '" style="--ec:' . e(event_color($ev)) . '" href="event.php?id=' . (int) $ev['id'] . '&amp;occ=' . e($ev['occ']) . '">'
                . '<div class="ki-emoji">' . $t[1] . '</div><div class="ki-title">' . e($ev['title']) . '</div>'
                . ($time ? '<div class="ki-time">' . e($time) . '</div>' : '')
                . ($friends ? '<div class="ki-friends">' . $friends . '</div>' : '')
                . ($where ? '<div class="ki-where">' . e($where) . '</div>' : '')
                . ($ev['pickup_member_id'] && member((int) $ev['pickup_member_id']) ? '<div class="ki-where">' . e(member((int) $ev['pickup_member_id'])['emoji'] . ' ' . member((int) $ev['pickup_member_id'])['name']) . ' haalt je op</div>' : '')
                . '</a>';
        }
        if (!$items) {
            $html .= '<div class="kid-empty">' . ($w === 0 || $w === 6 ? '🌈 Vrij!' : '·') . '</div>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/** Month as a table with event labels. $events already filtered for who it's for. */
function month_table(int $year, int $month, array $events, array $birthdays, string $link = 'agenda.php'): string
{
    $first = sprintf('%04d-%02d-01', $year, $month);
    $start = date('Y-m-d', strtotime('monday this week', strtotime($first)));
    $end = date('Y-m-d', strtotime('+1 month', strtotime($first)));
    $to = date('Y-m-d', strtotime('monday next week', strtotime("$end -1 day")));
    $byDay = group_by_day($events, $birthdays, $start, $to);
    $html = '<table class="mini-month"><tr>';
    foreach (['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'] as $dn) {
        $html .= '<th>' . $dn . '</th>';
    }
    $html .= '</tr>';
    for ($d = $start, $i = 0; $d < $to; $d = date('Y-m-d', strtotime("$d +1 day")), $i++) {
        if ($i % 7 === 0) {
            $html .= '<tr>';
        }
        $cls = [];
        if ((int) substr($d, 5, 2) !== $month) {
            $cls[] = 'other';
        }
        if ($d === today()) {
            $cls[] = 'today';
        }
        $html .= '<td class="' . implode(' ', $cls) . '"><a class="dnum" href="' . e($link) . '?view=day&amp;date=' . $d . '" style="color:inherit">' . (int) substr($d, 8) . '</a>';
        foreach ($byDay[$d]['birthdays'] ?? [] as $b) {
            $html .= '<span class="mev bday" title="' . e($b['name']) . '">🎂 ' . e($b['kind'] === 'member' ? $b['name'] : $b['person']['first_name']) . '</span>';
        }
        foreach ($byDay[$d]['events'] ?? [] as $ev) {
            $t = event_type($ev['type']);
            $time = $ev['all_day'] ? '' : substr($ev['start_at'], 11, 5) . ' ';
            $html .= '<a class="mev" style="--ec:' . e(event_color($ev)) . '" href="event.php?id=' . (int) $ev['id'] . '&amp;occ=' . e($ev['occ']) . '" title="' . e($time . $ev['title']) . '">' . $t[1] . ' ' . e($time . $ev['title']) . '</a>';
        }
        $html .= '</td>';
        if ($i % 7 === 6) {
            $html .= '</tr>';
        }
    }
    return $html . '</table>';
}

/** Twelve small months with coloured days. */
function year_grid(int $year, array $events, array $birthdays): string
{
    $byDay = group_by_day($events, $birthdays, "$year-01-01", ($year + 1) . '-01-01');
    $html = '<div class="year-grid">';
    for ($m = 1; $m <= 12; $m++) {
        $first = sprintf('%04d-%02d-01', $year, $m);
        $start = date('Y-m-d', strtotime('monday this week', strtotime($first)));
        $html .= '<div class="year-month"><h3>' . e(MONTHS[$m]) . '</h3><table><tr>';
        foreach (['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'] as $dn) {
            $html .= '<th>' . $dn . '</th>';
        }
        $html .= '</tr>';
        for ($i = 0; $i < 42; $i++) {
            $d = date('Y-m-d', strtotime("$start +$i day"));
            if ($i % 7 === 0) {
                $html .= '<tr>';
            }
            $evs = $byDay[$d]['events'] ?? [];
            $bds = $byDay[$d]['birthdays'] ?? [];
            $cls = [];
            if ((int) substr($d, 5, 2) !== $m) {
                $cls[] = 'other';
            } else {
                if ($d === today()) {
                    $cls[] = 'today';
                }
                if ($evs) {
                    $cls[] = 'has';
                }
                if ($bds) {
                    $cls[] = 'bday';
                }
                foreach ($evs as $ev) {
                    if ($ev['type'] === 'HOLIDAY') {
                        $cls[] = 'holiday';
                        break;
                    }
                }
            }
            $tip = implode("\n", array_merge(array_map(function ($b) {
                return '🎂 ' . $b['name'];
            }, $bds), array_map(function ($ev) {
                return event_type($ev['type'])[1] . ' ' . $ev['title'];
            }, $evs)));
            $color = $evs ? event_color($evs[0]) : '';
            $html .= '<td class="' . implode(' ', array_unique($cls)) . '"><a href="agenda.php?view=day&amp;date=' . $d . '" title="' . e($tip) . '"' . ($color ? ' style="--ec:' . e($color) . '"' : '') . '>' . (int) substr($d, 8) . '</a></td>';
            if ($i % 7 === 6) {
                $html .= '</tr>';
            }
        }
        $html .= '</table></div>';
    }
    return $html . '</div>';
}
