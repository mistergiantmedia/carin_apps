<?php
// Calendar subscription (iCalendar) for phones: Google Calendar / Apple Calendar / Outlook.
// ics.php?t=<secret token of an account>[&m=<member id>] — no login, the token is the key.
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/events.php';

$token = (string) ($_GET['t'] ?? '');
$stmt = db()->prepare('SELECT id FROM fp_users WHERE ics_token = ? AND ics_token IS NOT NULL');
$stmt->execute([$token]);
if (strlen($token) < 20 || !$stmt->fetchColumn()) {
    http_response_code(403);
    exit('Ongeldige link');
}
session_write_close();
$member = member(get_int('m'));

function ics_escape(string $s): string
{
    return str_replace(["\\", "\r\n", "\n", ',', ';'], ["\\\\", '\\n', '\\n', '\\,', '\\;'], $s);
}

/** Lines longer than 75 bytes are folded, as the format requires. */
function ics_line(string $line): string
{
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--; // don't split a UTF-8 character
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line . "\r\n";
}

$from = date('Y-m-d', strtotime('-3 month'));
$to = date('Y-m-d', strtotime('+18 month'));
$filter = $member ? ['members' => [(int) $member['id']]] : [];
$host = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$base = $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
$stamp = gmdate('Ymd\THis\Z');

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="familieplanner.ics"');
$out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Familie Planner//NL\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
$out .= ics_line('X-WR-CALNAME:' . ics_escape('Familie Planner' . ($member ? ' · ' . $member['name'] : '')));
$out .= "X-WR-TIMEZONE:Europe/Amsterdam\r\nREFRESH-INTERVAL;VALUE=DURATION:PT1H\r\nX-PUBLISHED-TTL:PT1H\r\n";
$out .= "BEGIN:VTIMEZONE\r\nTZID:Europe/Amsterdam\r\nBEGIN:DAYLIGHT\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nTZNAME:CEST\r\nDTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nEND:DAYLIGHT\r\nBEGIN:STANDARD\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nTZNAME:CET\r\nDTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";

foreach (load_events($from, $to, $filter) as $ev) {
    $t = event_type($ev['type']);
    $who = array_map(function ($id) {
        return member($id)['name'] ?? '';
    }, $ev['members']);
    $guests = array_map(function ($c) {
        return contact_name($c, false);
    }, $ev['contacts']);
    $desc = trim(implode("\n", array_filter([
        $who ? 'Wie: ' . implode(', ', $who) : '',
        $guests ? 'Met: ' . implode(', ', $guests) : '',
        $ev['drop_member_id'] ? 'Brengen: ' . (member((int) $ev['drop_member_id'])['name'] ?? '') : '',
        $ev['pickup_member_id'] ? 'Halen: ' . (member((int) $ev['pickup_member_id'])['name'] ?? '') : '',
        (string) $ev['description'],
        $base . 'event.php?id=' . $ev['id'] . '&occ=' . $ev['occ'],
    ])));
    $out .= "BEGIN:VEVENT\r\n";
    $out .= 'UID:fp-' . $ev['id'] . '-' . $ev['occ'] . '@familieplanner' . "\r\n";
    $out .= 'DTSTAMP:' . $stamp . "\r\n";
    if ($ev['all_day']) {
        $out .= 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($ev['start_at'])) . "\r\n";
        $out .= 'DTEND;VALUE=DATE:' . date('Ymd', strtotime(substr($ev['end_at'], 0, 10) . ' +1 day')) . "\r\n";
    } else {
        $out .= 'DTSTART;TZID=Europe/Amsterdam:' . date('Ymd\THis', strtotime($ev['start_at'])) . "\r\n";
        $out .= 'DTEND;TZID=Europe/Amsterdam:' . date('Ymd\THis', strtotime($ev['end_at'])) . "\r\n";
    }
    $out .= ics_line('SUMMARY:' . ics_escape($t[1] . ' ' . $ev['title'] . ($ev['done'] ? ' ✓' : '')));
    if ($ev['location']) {
        $out .= ics_line('LOCATION:' . ics_escape($ev['location']));
    }
    $out .= ics_line('DESCRIPTION:' . ics_escape($desc));
    $out .= ics_line('CATEGORIES:' . ics_escape($t[0]));
    $out .= "END:VEVENT\r\n";
}
foreach (load_birthdays($from, $to, $filter) as $b) {
    $out .= "BEGIN:VEVENT\r\n";
    $out .= 'UID:fp-bday-' . $b['subject'] . '-' . $b['date'] . '@familieplanner' . "\r\n";
    $out .= 'DTSTAMP:' . $stamp . "\r\n";
    $out .= 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($b['date'])) . "\r\n";
    $out .= 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($b['date'] . ' +1 day')) . "\r\n";
    $out .= ics_line('SUMMARY:' . ics_escape('🎂 ' . $b['name'] . ($b['age'] !== null ? ' wordt ' . $b['age'] : ' jarig')));
    $out .= "TRANSP:TRANSPARENT\r\nEND:VEVENT\r\n";
}
echo $out . "END:VCALENDAR\r\n";
