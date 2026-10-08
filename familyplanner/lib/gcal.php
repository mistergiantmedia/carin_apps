<?php
// Google Agenda koppeling. Every account can connect its own Google account: we create a calendar
// "Familie Planner" there (scope calendar.app.created: we can only see and change calendars we made ourselves)
// and keep it equal to the planner.
//
// Push: like the ics.php feed, repeating events go in as separate occurrences, so study days, exceptions and
// "per keer" drivers come out right. What should be there (occurrences + birthdays in the window) is compared
// with fp_gcal_events (what we sent before, per account) and only the differences go to Google.
// Pull: changes in the Google calendar since the last sync token. A planner event moved, renamed or deleted in Google
// is changed here too (just that occurrence); an event made in Google becomes a planner event (a Google series becomes
// a repeating planner event, and the series in Google is replaced by the planner's occurrences).
// Runs after the response has been sent (gcal_schedule(), needs PHP-FPM's fastcgi_finish_request): after every
// POST, otherwise at most every GCAL_THROTTLE seconds. What doesn't fit in GCAL_BUDGET is done next time.
// The OAuth client is app-wide (fp_app_settings, set in beheer.php). Keep this PHP 7.4 compatible.

const GCAL_API = 'https://www.googleapis.com/calendar/v3';
const GCAL_THROTTLE = 180;
const GCAL_BUDGET = 25;
const GCAL_PAST_DAYS = 7;
const GCAL_FUTURE_MONTHS = 12;

// ---------- OAuth client and tokens ----------

function gcal_credentials(): array
{
    static $creds = null;
    if ($creds === null) {
        $creds = ['client_id' => '', 'client_secret' => ''];
        try {
            $data = json_decode((string) db()->query("SELECT value FROM fp_app_settings WHERE name = 'google_oauth'")->fetchColumn(), true);
            if (is_array($data)) {
                $creds = array_merge($creds, array_intersect_key($data, $creds));
            }
        } catch (PDOException $e) {
            // before migration 023
        }
    }
    return $creds;
}

function gcal_configured(): bool
{
    $c = gcal_credentials();
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

function gcal_save_credentials(string $clientId, string $clientSecret): void
{
    db()->prepare("INSERT INTO fp_app_settings (name, value) VALUES ('google_oauth', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
        ->execute([json_encode(['client_id' => $clientId, 'client_secret' => $clientSecret])]);
}

/** Address of this app, ending in / (e.g. https://carinreilman.com/apps/familyplanner/). */
function app_base_url(): string
{
    $host = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $host . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
}

/** Has to be listed exactly like this at the OAuth client in the Google Cloud Console. */
function gcal_redirect_uri(): string
{
    return app_base_url() . 'google-callback.php';
}

function gcal_auth_url(string $state): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => gcal_credentials()['client_id'],
        'redirect_uri' => gcal_redirect_uri(),
        'response_type' => 'code',
        // Only calendars this app made, not the rest of someone's agenda; e-mail to show which account is linked
        'scope' => 'https://www.googleapis.com/auth/calendar.app.created openid email',
        'access_type' => 'offline',
        'prompt' => 'consent', // always hand out a refresh token, also when connecting again
        'state' => $state,
    ]);
}

/** One call to Google. $json is sent as JSON body, $form as form fields. Returns [HTTP status, decoded body]. */
function gcal_http(string $method, string $url, ?string $token = null, ?array $json = null, ?array $form = null): array
{
    $ch = curl_init($url);
    $headers = $token ? ['Authorization: Bearer ' . $token] : [];
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } elseif ($form !== null) {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($raw) && $raw !== '' ? json_decode($raw, true) : null];
}

/** Token endpoint (code exchange or refresh). Returns Google's answer, with 'error' when it failed. */
function gcal_token(array $params): array
{
    $c = gcal_credentials();
    [, $body] = gcal_http('POST', 'https://oauth2.googleapis.com/token', null, null, $params + ['client_id' => $c['client_id'], 'client_secret' => $c['client_secret']]);
    return is_array($body) ? $body : ['error' => 'no_response'];
}

/** The account row with its Google columns. */
function gcal_user(int $userId): ?array
{
    $stmt = db()->prepare('SELECT id, family_id, name, email, google_email, google_refresh_token, google_access_token, google_token_expires_at,
            google_calendar_id, google_member_id, google_sync_token, google_synced_at, google_pending, google_error
        FROM fp_users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/** A valid access token, refreshed when needed; null when the connection no longer works. */
function gcal_access_token(array &$u): ?string
{
    if (empty($u['google_refresh_token'])) {
        return null;
    }
    if (!empty($u['google_access_token']) && strtotime((string) $u['google_token_expires_at']) > time() + 60) {
        return $u['google_access_token'];
    }
    $t = gcal_token(['refresh_token' => $u['google_refresh_token'], 'grant_type' => 'refresh_token']);
    if (empty($t['access_token'])) {
        if (($t['error'] ?? '') === 'invalid_grant') {
            // Access withdrawn in the Google account (or the OAuth app is still in test mode: 7 days)
            gcal_forget((int) $u['id'], 'De koppeling met Google is verlopen of ingetrokken. Koppel opnieuw.');
        } else {
            gcal_error($u, 0, $t, 'Kon niet inloggen bij Google');
        }
        return null;
    }
    $u['google_access_token'] = $t['access_token'];
    $u['google_token_expires_at'] = date('Y-m-d H:i:s', time() + (int) ($t['expires_in'] ?? 3600));
    db()->prepare('UPDATE fp_users SET google_access_token = ?, google_token_expires_at = ? WHERE id = ?')
        ->execute([$u['google_access_token'], $u['google_token_expires_at'], $u['id']]);
    return $u['google_access_token'];
}

/** Remember a problem to show in Instellingen (and in the PHP error log). */
function gcal_error(array $u, int $code, $body, string $what): void
{
    $detail = $body['error']['message'] ?? ($body['error_description'] ?? ($body['error'] ?? ''));
    $msg = $what . ($code ? ' (HTTP ' . $code . ')' : '') . (is_string($detail) && $detail !== '' ? ': ' . $detail : '');
    error_log('[familyplanner] Google Agenda, account ' . $u['id'] . ': ' . $msg);
    db()->prepare('UPDATE fp_users SET google_error = ? WHERE id = ?')->execute([mb_cut($msg, 255), $u['id']]);
    gcal_error_count(true);
}

function gcal_error_count(bool $add = false): int
{
    static $n = 0;
    return $add ? ++$n : $n;
}

/** Drop the connection here (tokens, calendar, what was sent). The e-mail stays to show which account it was. */
function gcal_forget(int $userId, ?string $error = null): void
{
    db()->prepare('UPDATE fp_users SET google_refresh_token = NULL, google_access_token = NULL, google_token_expires_at = NULL, google_calendar_id = NULL,
        google_sync_token = NULL, google_synced_at = NULL, google_pending = 0, google_error = ? WHERE id = ?')->execute([$error, $userId]);
    db()->prepare('DELETE FROM fp_gcal_events WHERE user_id = ?')->execute([$userId]);
}

/** Disconnect: remove the Familie Planner calendar from Google, withdraw our access and forget everything. */
function gcal_disconnect(array $u): void
{
    $locked = gcal_lock((int) $u['id'], 20); // not while a sync of this account is running
    try {
        $token = gcal_access_token($u);
        if ($token && !empty($u['google_calendar_id'])) {
            gcal_http('DELETE', GCAL_API . '/calendars/' . rawurlencode($u['google_calendar_id']), $token);
        }
        if (!empty($u['google_refresh_token'])) {
            gcal_http('POST', 'https://oauth2.googleapis.com/revoke', null, null, ['token' => $u['google_refresh_token']]);
        }
        gcal_forget((int) $u['id']);
        db()->prepare('UPDATE fp_users SET google_email = NULL, google_member_id = NULL WHERE id = ?')->execute([$u['id']]);
    } finally {
        if ($locked) {
            gcal_unlock((int) $u['id']);
        }
    }
}

function gcal_lock(int $userId, int $wait): bool
{
    $stmt = db()->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute(['familyplanner_gcal_' . $userId, $wait]);
    return (bool) $stmt->fetchColumn();
}

function gcal_unlock(int $userId): void
{
    db()->prepare('SELECT RELEASE_LOCK(?)')->execute(['familyplanner_gcal_' . $userId]);
}

// ---------- The calendar ----------

function gcal_calendar_title(array $u): string
{
    $m = member($u['google_member_id'] ? (int) $u['google_member_id'] : null);
    return 'Familie Planner' . ($m ? ' · ' . $m['name'] : '');
}

/** The Familie Planner calendar of this account; made when it isn't there yet. */
function gcal_calendar(array &$u, string $token): ?string
{
    if (!empty($u['google_calendar_id'])) {
        return $u['google_calendar_id'];
    }
    [$code, $body] = gcal_http('POST', GCAL_API . '/calendars', $token, [
        'summary' => gcal_calendar_title($u),
        'description' => 'Wordt bijgehouden door de Familie Planner. Afspraken wijzig je het best in de planner zelf.',
        'timeZone' => 'Europe/Amsterdam',
    ]);
    if ($code !== 200 || empty($body['id'])) {
        gcal_error($u, $code, $body, 'Kon de agenda "Familie Planner" niet maken in Google');
        return null;
    }
    $u['google_calendar_id'] = $body['id'];
    $u['google_sync_token'] = null;
    db()->prepare('UPDATE fp_users SET google_calendar_id = ?, google_sync_token = NULL WHERE id = ?')->execute([$body['id'], $u['id']]);
    db()->prepare('DELETE FROM fp_gcal_events WHERE user_id = ?')->execute([$u['id']]);
    return $body['id'];
}

/** Whose events go in (NULL = the whole family); renames the Google calendar to match. */
function gcal_set_member(array $u, ?int $memberId): void
{
    $u['google_member_id'] = member($memberId) ? $memberId : null;
    db()->prepare('UPDATE fp_users SET google_member_id = ? WHERE id = ?')->execute([$u['google_member_id'], $u['id']]);
    $token = gcal_access_token($u);
    if ($token && !empty($u['google_calendar_id'])) {
        gcal_http('PATCH', GCAL_API . '/calendars/' . rawurlencode($u['google_calendar_id']), $token, ['summary' => gcal_calendar_title($u)]);
    }
}

// ---------- What goes in ----------

/** Google has 11 event colours: the one closest to ours. */
function gcal_color(string $hex): string
{
    $google = [1 => '7986cb', 2 => '33b679', 3 => '8e24aa', 4 => 'e67c73', 5 => 'f6bf26', 6 => 'f4511e', 7 => '039be5', 8 => '616161', 9 => '3f51b5', 10 => '0b8043', 11 => 'd50000'];
    $hex = preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m) ? $m[1] : '6C5CE7';
    $ours = array_map('hexdec', str_split($hex, 2));
    $best = 1;
    $bestDist = PHP_INT_MAX;
    foreach ($google as $id => $g) {
        $c = array_map('hexdec', str_split($g, 2));
        $dist = ($ours[0] - $c[0]) ** 2 + ($ours[1] - $c[1]) ** 2 + ($ours[2] - $c[2]) ** 2;
        if ($dist < $bestDist) {
            [$best, $bestDist] = [$id, $dist];
        }
    }
    return (string) $best;
}

/** A Google event body for planner times (local Y-m-d H:i:s; all-day = 00:00 first day to 23:59 last day). */
function gcal_item(string $start, string $end, bool $allDay, array $fields): array
{
    if ($allDay) {
        $fields['start'] = ['date' => substr($start, 0, 10)];
        $fields['end'] = ['date' => date('Y-m-d', strtotime(substr($end, 0, 10) . ' +1 day'))]; // Google: end day is exclusive
        $fields['transparency'] = 'transparent'; // a birthday or holiday doesn't make you "busy"
    } else {
        $fields['start'] = ['dateTime' => str_replace(' ', 'T', $start), 'timeZone' => 'Europe/Amsterdam'];
        $fields['end'] = ['dateTime' => str_replace(' ', 'T', $end), 'timeZone' => 'Europe/Amsterdam'];
        $fields['transparency'] = 'opaque';
    }
    $fields['status'] = 'confirmed';
    return ['payload' => $fields, 'hash' => md5((string) json_encode($fields)), 'start_at' => $start, 'end_at' => $end, 'all_day' => $allDay ? 1 : 0];
}

/**
 * A PATCH merges start/end with what Google has: an all-day event that gets a time would keep its 'date' next to
 * the new 'dateTime' (Google: "Invalid start time"). So clear the other kind explicitly. Not part of the hash.
 */
function gcal_patch_body(array $payload): array
{
    foreach (['start', 'end'] as $k) {
        if (isset($payload[$k]['date'])) {
            $payload[$k] += ['dateTime' => null, 'timeZone' => null];
        } else {
            $payload[$k] += ['date' => null];
        }
    }
    return $payload;
}

/**
 * What should be in this account's calendar between $from and $to: item key => gcal_item().
 * Keys: e12 (single event 12), e12-2026-10-08 (occurrence of repeating event 12), bm3-… / bc7-… (birthdays).
 */
function gcal_wanted(array $u, string $from, string $to): array
{
    require_once __DIR__ . '/events.php';
    $filter = member($u['google_member_id'] ? (int) $u['google_member_id'] : null) ? ['members' => [(int) $u['google_member_id']]] : [];
    $base = app_base_url();
    $out = [];
    foreach (load_events($from, $to, $filter) as $ev) {
        [$summary, $description] = event_feed_text($ev, $base);
        $out['e' . $ev['id'] . ($ev['recurring'] ? '-' . $ev['occ'] : '')] = gcal_item($ev['start_at'], $ev['end_at'], (bool) $ev['all_day'], [
            'summary' => $summary,
            'description' => $description,
            'location' => (string) $ev['location'],
            'colorId' => gcal_color(event_color($ev)),
            'extendedProperties' => ['private' => ['fp' => '1']], // made by the planner (not to be imported)
        ]);
    }
    foreach (load_birthdays($from, $to, $filter) as $b) {
        $out['b' . $b['subject'] . '-' . $b['date']] = gcal_item($b['date'] . ' 00:00:00', $b['date'] . ' 23:59:00', true, [
            'summary' => '🎂 ' . $b['name'] . ($b['age'] !== null ? ' wordt ' . $b['age'] : ' is jarig'),
            'description' => $base . ($b['kind'] === 'member' ? 'persoon.php?id=' : 'contact.php?id=') . (int) $b['person']['id'],
            'location' => '',
            'colorId' => gcal_color('#E0568A'),
            'extendedProperties' => ['private' => ['fp' => '1']],
        ]);
    }
    return $out;
}

// ---------- Sync ----------

/** Send the differences to Google, soonest first. Returns how many are still waiting (time ran out or Google said stop). */
function gcal_push(array $u, string $token, string $cal, float $deadline): int
{
    $from = date('Y-m-d', strtotime('-' . GCAL_PAST_DAYS . ' day'));
    $wanted = gcal_wanted($u, $from, date('Y-m-d', strtotime('+' . GCAL_FUTURE_MONTHS . ' month')));
    $stmt = db()->prepare('SELECT * FROM fp_gcal_events WHERE user_id = ?');
    $stmt->execute([$u['id']]);
    $sent = array_column($stmt->fetchAll(), null, 'item');
    $forget = db()->prepare('DELETE FROM fp_gcal_events WHERE user_id = ? AND item = ?');
    $save = db()->prepare('INSERT INTO fp_gcal_events (user_id, item, google_id, hash, start_at, end_at, all_day, sent_summary, sent_location, sent_description)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE google_id = VALUES(google_id), hash = VALUES(hash), start_at = VALUES(start_at), end_at = VALUES(end_at), all_day = VALUES(all_day),
            sent_summary = VALUES(sent_summary), sent_location = VALUES(sent_location), sent_description = VALUES(sent_description)');

    $ops = [];
    foreach ($sent as $key => $row) {
        if (isset($wanted[$key])) {
            continue;
        }
        if ($row['end_at'] < $from . ' 00:00:00') {
            $forget->execute([$u['id'], $key]); // over: stays in Google as history
        } else {
            $ops[] = ['delete', $key, $row['start_at']];
        }
    }
    foreach ($wanted as $key => $it) {
        if (!isset($sent[$key])) {
            $ops[] = ['insert', $key, $it['start_at']];
        } elseif ($sent[$key]['hash'] !== $it['hash'] || $sent[$key]['sent_summary'] === null) {
            $ops[] = ['update', $key, $it['start_at']];
        }
    }
    usort($ops, function ($a, $b) {
        return strcmp($a[2], $b[2]);
    });

    $url = GCAL_API . '/calendars/' . rawurlencode($cal) . '/events';
    foreach ($ops as $i => [$op, $key]) {
        if (microtime(true) > $deadline) {
            return count($ops) - $i;
        }
        if ($op === 'delete') {
            [$code, $body] = gcal_http('DELETE', $url . '/' . rawurlencode($sent[$key]['google_id']), $token);
            if (in_array($code, [200, 204, 404, 410], true)) {
                $forget->execute([$u['id'], $key]);
                continue;
            }
        } else {
            $it = $wanted[$key];
            $code = 0;
            $body = null;
            if ($op === 'update') {
                $eventUrl = $url . '/' . rawurlencode($sent[$key]['google_id']);
                [$code, $body] = gcal_http('PATCH', $eventUrl, $token, gcal_patch_body($it['payload']));
                if ($code === 400) {
                    [$code, $body] = gcal_http('PUT', $eventUrl, $token, $it['payload']); // replace it as a whole instead
                }
            }
            if ($op === 'insert' || $code === 404 || $code === 410) {
                [$code, $body] = gcal_http('POST', $url, $token, $it['payload']);
            }
            if ($code === 200 && !empty($body['id'])) {
                $p = $it['payload'];
                $save->execute([$u['id'], $key, $body['id'], $it['hash'], $it['start_at'], $it['end_at'], $it['all_day'], mb_cut($p['summary'], 255), mb_cut($p['location'], 255), $p['description']]);
                continue;
            }
        }
        $what = $op === 'delete' ? 'het weghalen van een afspraak'
            : '“' . ($wanted[$key]['payload']['summary'] ?? $key) . '” (' . format_date_short($wanted[$key]['start_at']) . ')';
        gcal_error($u, $code, $body, 'Google weigerde ' . $what);
        if ($code !== 400) {
            return count($ops) - $i; // rate limit, no access, Google down…: try again next time
        }
    }
    return 0;
}

/** Planner times (local) of a Google event: [start, end, all day] or null. */
function gcal_event_times(array $event): ?array
{
    try {
        if (!empty($event['start']['date'])) {
            $start = $event['start']['date'];
            $last = !empty($event['end']['date']) ? date('Y-m-d', strtotime($event['end']['date'] . ' -1 day')) : $start;
            return [$start . ' 00:00:00', max($start, $last) . ' 23:59:00', 1];
        }
        if (!empty($event['start']['dateTime']) && !empty($event['end']['dateTime'])) {
            $tz = new DateTimeZone('Europe/Amsterdam');
            return [
                (new DateTime($event['start']['dateTime']))->setTimezone($tz)->format('Y-m-d H:i:00'),
                (new DateTime($event['end']['dateTime']))->setTimezone($tz)->format('Y-m-d H:i:00'),
                0,
            ];
        }
    } catch (Exception $e) {
        // unreadable time: ignore this change
    }
    return null;
}

/**
 * One changed Google event. Our own changes come back here too: they have the times and text we stored, so nothing happens.
 * A planner event moved, renamed or deleted in Google is changed here too (an occurrence of a repeating event becomes
 * its own single event, as in Google). An event made in Google becomes a planner event (gcal_import()).
 * Birthdays are changed in the address book: moved or deleted in Google, they are put back.
 */
function gcal_take_over(array $u, array $event, string $token, string $url): void
{
    $stmt = db()->prepare('SELECT * FROM fp_gcal_events WHERE user_id = ? AND google_id = ?');
    $stmt->execute([$u['id'], (string) ($event['id'] ?? '')]);
    $row = $stmt->fetch();
    $cancelled = ($event['status'] ?? '') === 'cancelled';
    if (!$row) {
        if (!$cancelled) {
            gcal_import($u, $event, $token, $url);
        }
        return;
    }
    $times = $cancelled ? null : gcal_event_times($event);
    $moved = $times && ($times[0] !== $row['start_at'] || $times[1] !== $row['end_at'] || $times[2] !== (int) $row['all_day']);
    $text = $cancelled || $row['sent_summary'] === null ? [] : gcal_text_changes($row, $event);
    if (!$cancelled && !$moved && !$text) {
        return;
    }
    $forget = function () use ($u, $row) {
        db()->prepare('DELETE FROM fp_gcal_events WHERE user_id = ? AND item = ?')->execute([$u['id'], $row['item']]);
    };
    $ev = preg_match('/^e(\d+)(?:-(\d{4}-\d{2}-\d{2}))?$/', $row['item'], $m) ? find_event((int) $m[1]) : null;
    if (!$ev) {
        if ($cancelled) {
            $forget(); // made again on the next push (if it's still in the planner)
        } else {
            db()->prepare("UPDATE fp_gcal_events SET hash = '' WHERE user_id = ? AND item = ?")->execute([$u['id'], $row['item']]); // sent again as it is here
        }
        return;
    }
    $occ = $m[2] ?? substr($ev['start_at'], 0, 10);
    if ($cancelled) {
        delete_event((int) $ev['id'], $occ, 'one');
        $forget();
        return;
    }
    if ($moved) {
        $id = move_event((int) $ev['id'], $occ, $times[0], $times[1], (bool) $times[2], 'one');
    } else {
        $times = [$row['start_at'], $row['end_at'], (int) $row['all_day']];
        $id = $ev['recurring'] ? detach_occurrence($ev, $occ) : (int) $ev['id'];
    }
    gcal_apply_text($id, $text);
    // The Google event stays and now stands for this (possibly detached) single event; sent again in the planner's words
    db()->prepare("UPDATE fp_gcal_events SET item = ?, hash = '', start_at = ?, end_at = ?, all_day = ? WHERE user_id = ? AND item = ?")
        ->execute(['e' . $id, $times[0], $times[1], $times[2], $u['id'], $row['item']]);
}

/** Plain text of a Google description: edited in Google's website it becomes HTML. */
function gcal_plain(string $text): string
{
    if (preg_match('~<(br|p|div|a|b|i|u|span|ul|ol|li)\b~i', $text)) {
        $text = (string) preg_replace('~<br\s*/?>|</(p|div|li)>~i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return trim(str_replace("\r\n", "\n", $text));
}

/** The notes in a description: without the lines the planner writes itself (Wie, Met, Brengen, Halen, the link). */
function gcal_notes(string $text): ?string
{
    $keep = [];
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\s*(Wie|Met|Brengen|Halen): /u', $line) || strpos($line, 'event.php?id=') !== false) {
            continue;
        }
        $keep[] = rtrim($line);
    }
    $notes = trim(implode("\n", $keep));
    return $notes !== '' ? $notes : null;
}

/** Title, place and description that differ from what we sent: changed in Google. */
function gcal_text_changes(array $row, array $event): array
{
    $now = ['summary' => (string) ($event['summary'] ?? ''), 'location' => (string) ($event['location'] ?? ''), 'description' => gcal_plain((string) ($event['description'] ?? ''))];
    $sent = ['summary' => (string) $row['sent_summary'], 'location' => (string) $row['sent_location'], 'description' => gcal_plain((string) $row['sent_description'])];
    $out = [];
    foreach ($now as $k => $v) {
        if (trim($v) !== trim($sent[$k])) {
            $out[$k] = $v;
        }
    }
    return $out;
}

/** [emoji or null, title] of a Google title: a leading emoji is the event's emoji, the "done" ✓ at the end goes. */
function gcal_split_title(string $summary): array
{
    $s = trim((string) preg_replace('/\s*✓$/u', '', trim($summary)));
    if (preg_match('/^([^\p{L}\p{N}\s]{1,12})\s+(\S.*)$/us', $s, $m) && preg_match('/\p{So}/u', $m[1])) {
        return [$m[1], trim($m[2])];
    }
    return [null, $s];
}

/** Put title / place / notes changed in Google into planner event $id. */
function gcal_apply_text(int $id, array $changes): void
{
    $ev = $changes ? find_event($id) : null;
    if (!$ev) {
        return;
    }
    $set = [];
    if (isset($changes['summary'])) {
        [$emoji, $title] = gcal_split_title($changes['summary']);
        if ($title !== '') {
            $set['title'] = mb_cut($title, 160);
        }
        if ($emoji !== null && $emoji !== event_emoji($ev)) {
            $set['emoji'] = mb_cut($emoji, 16);
        }
    }
    if (isset($changes['location'])) {
        $set['location'] = trim($changes['location']) !== '' ? mb_cut(trim($changes['location']), 190) : null;
    }
    if (isset($changes['description'])) {
        $set['description'] = gcal_notes($changes['description']);
    }
    if ($set) {
        $sql = implode(', ', array_map(function ($k) {
            return "$k = :$k";
        }, array_keys($set)));
        db()->prepare("UPDATE fp_events SET $sql WHERE id = :id")->execute($set + ['id' => $id]);
    }
}

/**
 * How a Google series repeats, as planner events: list of [start, end, recurrence, recur_until], or null when the
 * planner can't repeat like that (every 3 weeks, the 2nd Tuesday of the month…). Weekly on several days
 * ("Mon and Wed") becomes one weekly event per day. Not repeating: one item with recurrence ''.
 */
function gcal_rules(array $event, array $times): ?array
{
    [$start, $end] = $times;
    $rrule = null;
    foreach ($event['recurrence'] ?? [] as $line) {
        if (stripos((string) $line, 'RRULE:') === 0) {
            $rrule = substr($line, 6);
        }
    }
    if ($rrule === null) {
        return [[$start, $end, '', null]];
    }
    $p = [];
    foreach (explode(';', strtoupper($rrule)) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $p[$k] = $v;
    }
    $freq = $p['FREQ'] ?? '';
    $interval = max(1, (int) ($p['INTERVAL'] ?? 1));
    $days = ($p['BYDAY'] ?? '') !== '' ? explode(',', $p['BYDAY']) : [];
    $codes = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    $workdays = count($days) === 5 && !array_diff(['MO', 'TU', 'WE', 'TH', 'FR'], $days);
    $rules = [];
    if ($interval === 1 && $workdays && in_array($freq, ['DAILY', 'WEEKLY'], true)) {
        $rules[] = ['WEEKDAYS', $start];
    } elseif ($freq === 'DAILY' && $interval === 1 && !$days) {
        $rules[] = ['DAILY', $start];
    } elseif ($freq === 'WEEKLY' && $interval <= 2) {
        $startDay = (int) date('N', strtotime($start));
        foreach ($days ?: [array_search($startDay, $codes, true)] as $d) {
            if (!isset($codes[$d])) {
                return null;
            }
            $shift = ($codes[$d] - $startDay + 7) % 7;
            $rules[] = [$interval === 1 ? 'WEEKLY' : 'BIWEEKLY', date('Y-m-d H:i:s', strtotime("$start +$shift day"))];
        }
    } elseif (in_array($freq, ['MONTHLY', 'YEARLY'], true) && $interval === 1 && !$days && !isset($p['BYSETPOS'])) {
        $rules[] = [$freq, $start];
    } else {
        return null;
    }

    $until = null;
    if (!empty($p['UNTIL'])) {
        $u = $p['UNTIL'];
        $until = strpos($u, 'T') !== false
            ? (new DateTime(substr($u, 0, 15), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d')
            : substr($u, 0, 4) . '-' . substr($u, 4, 2) . '-' . substr($u, 6, 2);
    } elseif (!empty($p['COUNT'])) {
        // The day of the last time, over all days together
        $count = max(1, (int) $p['COUNT']);
        $dates = [];
        foreach ($rules as [$rule, $s]) {
            $base = new DateTime($s);
            for ($n = 0, $found = 0; $found < $count && $n < 3 * $count + 10; $n++) {
                $d = occurrence_start($base, $rule, $n);
                if ($d && !($rule === 'WEEKDAYS' && (int) $d->format('N') >= 6)) {
                    $dates[] = $d->format('Y-m-d');
                    $found++;
                }
            }
        }
        sort($dates);
        $until = $dates[min($count, count($dates)) - 1] ?? null;
    }
    $length = strtotime($end) - strtotime($start);
    return array_map(function ($r) use ($length, $until) {
        return [$r[1], date('Y-m-d H:i:s', strtotime($r[1]) + $length), $r[0], $until];
    }, $rules);
}

/**
 * An event made in Google (in the Familie Planner calendar) becomes a planner event, for the account's member when the
 * account follows one person. A single event keeps its Google event; a Google series is deleted in Google, because
 * the planner puts in its own occurrences.
 */
function gcal_import(array $u, array $event, string $token, string $url): void
{
    if (!empty($event['recurringEventId']) || !empty($event['extendedProperties']['private']['fp']) || empty($event['id'])) {
        return; // one time of a Google series (comes with the series), or made by the planner
    }
    $times = gcal_event_times($event);
    if (!$times) {
        return;
    }
    [$emoji, $title] = gcal_split_title((string) ($event['summary'] ?? ''));
    $rules = gcal_rules($event, $times);
    if ($rules === null) {
        gcal_error($u, 0, null, '“' . $title . '” herhaalt op een manier die de planner niet kent (bijv. om de 3 weken), en staat daarom alleen in Google');
        return;
    }
    $members = member($u['google_member_id'] ? (int) $u['google_member_id'] : null) ? [(int) $u['google_member_id']] : [];
    $ids = [];
    foreach ($rules as [$start, $end, $recurrence, $until]) {
        $ids[] = save_event(normalise_event([
            'title' => $title,
            'emoji' => $emoji,
            'type' => 'OTHER',
            'start' => $start,
            'end' => $end,
            'all_day' => $times[2],
            'location' => (string) ($event['location'] ?? ''),
            'description' => (string) gcal_notes(gcal_plain((string) ($event['description'] ?? ''))),
            'recurrence' => $recurrence,
            'recur_until' => $until,
            'members' => $members,
        ]));
    }
    if ($rules[0][2] === '') {
        // The Google event stays and stands for the new planner event; the next push writes it in the planner's words
        db()->prepare("INSERT INTO fp_gcal_events (user_id, item, google_id, hash, start_at, end_at, all_day) VALUES (?, ?, ?, '', ?, ?, ?)")
            ->execute([$u['id'], 'e' . $ids[0], $event['id'], $times[0], $times[1], $times[2]]);
    } else {
        gcal_http('DELETE', $url . '/' . rawurlencode($event['id']), $token);
    }
}

/**
 * Take over the changes made in Google since the last sync token (the first time only fetch a token).
 * Returns false when the calendar is gone: deleted in Google means disconnected.
 */
function gcal_pull(array &$u, string $token, string $cal): bool
{
    require_once __DIR__ . '/events.php';
    $url = GCAL_API . '/calendars/' . rawurlencode($cal) . '/events';
    $syncToken = $u['google_sync_token'] ?: null;
    $page = null;
    $next = null;
    while (true) {
        $params = $syncToken ? ['syncToken' => $syncToken] : ['showDeleted' => 'true', 'maxResults' => 2500];
        if ($page) {
            $params['pageToken'] = $page;
        }
        [$code, $body] = gcal_http('GET', $url . '?' . http_build_query($params), $token);
        if ($code === 410 && $syncToken) {
            [$syncToken, $page] = [null, null]; // token too old: start again from the current state
            continue;
        }
        if ($code === 404) {
            gcal_forget((int) $u['id'], 'De agenda "Familie Planner" is uit Google Agenda verwijderd. Koppel opnieuw om hem terug te zetten.');
            return false;
        }
        if ($code !== 200) {
            gcal_error($u, $code, $body, 'Kon de wijzigingen uit Google niet ophalen');
            return true;
        }
        if ($syncToken) {
            foreach ($body['items'] ?? [] as $event) {
                gcal_take_over($u, $event, $token, $url);
            }
        }
        $next = $body['nextSyncToken'] ?? $next;
        $page = $body['nextPageToken'] ?? null;
        if (!$page) {
            break;
        }
    }
    if ($next) {
        $u['google_sync_token'] = $next;
        db()->prepare('UPDATE fp_users SET google_sync_token = ? WHERE id = ?')->execute([$next, $u['id']]);
    }
    return true;
}

/** Bring one account's Google calendar up to date: first what changed there, then what changed here. */
function gcal_sync_user(array $u, bool $force): void
{
    if (!gcal_lock((int) $u['id'], $force ? 10 : 0)) {
        return; // a sync of this account is already running
    }
    try {
        $errors = gcal_error_count();
        $token = gcal_access_token($u);
        $cal = $token ? gcal_calendar($u, $token) : null;
        if (!$cal || !gcal_pull($u, $token, $cal)) {
            return;
        }
        $left = gcal_push($u, $token, $cal, microtime(true) + GCAL_BUDGET);
        db()->prepare('UPDATE fp_users SET google_synced_at = ?, google_pending = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $left, $u['id']]); // PHP time: MySQL runs in UTC
        if (gcal_error_count() === $errors) {
            db()->prepare('UPDATE fp_users SET google_error = NULL WHERE id = ?')->execute([$u['id']]);
        }
    } finally {
        gcal_unlock((int) $u['id']);
    }
}

/** Accounts of the logged-in family that are connected (and, unless $force, due for a sync). */
function gcal_due_accounts(bool $force): array
{
    $user = current_user();
    if (!$user) {
        return [];
    }
    $sql = 'SELECT id FROM fp_users WHERE family_id = ? AND google_refresh_token IS NOT NULL';
    $params = [$user['family_id']];
    if (!$force) {
        $sql .= ' AND (google_synced_at IS NULL OR google_synced_at < ? OR google_pending > 0)';
        $params[] = date('Y-m-d H:i:s', time() - GCAL_THROTTLE);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Called on every logged-in request. When a connected account is due (always after a POST: something may have
 * changed), its Google calendar is synced after the response has gone to the browser. $force: sync now anyway.
 */
function gcal_schedule(bool $force = false): void
{
    static $run = null;
    $force = $force || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($run) {
        $run->force = $run->force || $force;
        return;
    }
    if (!gcal_configured() || !gcal_due_accounts($force)) {
        return;
    }
    $run = (object) ['force' => $force];
    register_shutdown_function(function () use ($run) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // don't keep the next page of this browser waiting
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        @set_time_limit(30 + 2 * GCAL_BUDGET);
        try {
            foreach (gcal_due_accounts($run->force) as $id) {
                $u = gcal_user($id);
                if ($u) {
                    gcal_sync_user($u, $run->force);
                }
            }
        } catch (Throwable $e) {
            error_log('[familyplanner] Google Agenda sync: ' . $e);
        }
    });
}
