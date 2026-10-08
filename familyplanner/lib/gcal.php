<?php
// Google Agenda koppeling. Every account can connect its own Google account: we create a calendar
// "Familie Planner" there (scope calendar.app.created: we can only see and change calendars we made ourselves)
// and keep it equal to the planner.
//
// Push: like the ics.php feed, repeating events go in as separate occurrences, so study days, exceptions and
// "per keer" drivers come out right. What should be there (occurrences + birthdays in the window) is compared
// with fp_gcal_events (what we sent before, per account) and only the differences go to Google.
// Pull: changes in the Google calendar since the last sync token. A planner event moved or deleted in Google
// is moved / deleted here too (just that occurrence); text edits made in Google are not taken over.
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
        ]);
    }
    foreach (load_birthdays($from, $to, $filter) as $b) {
        $out['b' . $b['subject'] . '-' . $b['date']] = gcal_item($b['date'] . ' 00:00:00', $b['date'] . ' 23:59:00', true, [
            'summary' => '🎂 ' . $b['name'] . ($b['age'] !== null ? ' wordt ' . $b['age'] : ' is jarig'),
            'description' => $base . ($b['kind'] === 'member' ? 'persoon.php?id=' : 'contact.php?id=') . (int) $b['person']['id'],
            'location' => '',
            'colorId' => gcal_color('#E0568A'),
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
    $save = db()->prepare('INSERT INTO fp_gcal_events (user_id, item, google_id, hash, start_at, end_at, all_day) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE google_id = VALUES(google_id), hash = VALUES(hash), start_at = VALUES(start_at), end_at = VALUES(end_at), all_day = VALUES(all_day)');

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
        } elseif ($sent[$key]['hash'] !== $it['hash']) {
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
                $save->execute([$u['id'], $key, $body['id'], $it['hash'], $it['start_at'], $it['end_at'], $it['all_day']]);
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
 * One changed Google event. Our own changes come back here too: they have the times we stored, so nothing happens.
 * A planner event moved in Google is moved here (only that occurrence), one deleted in Google is deleted here.
 * Birthdays are changed in the address book: moved or deleted in Google, they are put back.
 */
function gcal_take_over(array $u, array $event): void
{
    $stmt = db()->prepare('SELECT * FROM fp_gcal_events WHERE user_id = ? AND google_id = ?');
    $stmt->execute([$u['id'], (string) ($event['id'] ?? '')]);
    $row = $stmt->fetch();
    if (!$row) {
        return; // made in Google itself, or already handled
    }
    $cancelled = ($event['status'] ?? '') === 'cancelled';
    $times = $cancelled ? null : gcal_event_times($event);
    $moved = $times && ($times[0] !== $row['start_at'] || $times[1] !== $row['end_at'] || $times[2] !== (int) $row['all_day']);
    if (!$cancelled && !$moved) {
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
    // An occurrence of a repeating event becomes its own single event; the Google event stays and now stands for that one
    $id = move_event((int) $ev['id'], $occ, $times[0], $times[1], (bool) $times[2], 'one');
    db()->prepare("UPDATE fp_gcal_events SET item = ?, hash = '', start_at = ?, end_at = ?, all_day = ? WHERE user_id = ? AND item = ?")
        ->execute(['e' . $id, $times[0], $times[1], $times[2], $u['id'], $row['item']]);
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
                gcal_take_over($u, $event);
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
