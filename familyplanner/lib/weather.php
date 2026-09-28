<?php
// Weather for the dashboard from Open-Meteo (free, no account or key). The place is the family's city
// (Instellingen → Ons gezin). Place lookup and forecast are cached in fp_settings (forecast: 30 minutes),
// so a page view never waits more than a few seconds and usually not at all. Keep PHP 7.4 compatible.
require_once __DIR__ . '/freedays.php';

// WMO weather codes → [emoji, Dutch text]
const WEATHER_CODES = [
    0 => ['☀️', 'Zonnig'], 1 => ['🌤️', 'Vooral zonnig'], 2 => ['⛅', 'Half bewolkt'], 3 => ['☁️', 'Bewolkt'],
    45 => ['🌫️', 'Mist'], 48 => ['🌫️', 'Mist met rijp'],
    51 => ['🌦️', 'Lichte motregen'], 53 => ['🌦️', 'Motregen'], 55 => ['🌧️', 'Dichte motregen'], 56 => ['🌧️', 'IJzel'], 57 => ['🌧️', 'IJzel'],
    61 => ['🌦️', 'Lichte regen'], 63 => ['🌧️', 'Regen'], 65 => ['🌧️', 'Zware regen'], 66 => ['🌧️', 'IJzel'], 67 => ['🌧️', 'IJzel'],
    71 => ['🌨️', 'Lichte sneeuw'], 73 => ['🌨️', 'Sneeuw'], 75 => ['❄️', 'Veel sneeuw'], 77 => ['🌨️', 'Sneeuwkorrels'],
    80 => ['🌦️', 'Buien'], 81 => ['🌧️', 'Regenbuien'], 82 => ['⛈️', 'Zware buien'], 85 => ['🌨️', 'Sneeuwbuien'], 86 => ['🌨️', 'Zware sneeuwbuien'],
    95 => ['⛈️', 'Onweer'], 96 => ['⛈️', 'Onweer met hagel'], 99 => ['⛈️', 'Zwaar onweer met hagel'],
];

function weather_code(?int $code): array
{
    return WEATHER_CODES[$code ?? -1] ?? ['🌡️', 'Weer'];
}

function http_json(string $url, int $timeout = 4): ?array
{
    $json = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true]);
        $json = curl_exec($ch);
        curl_close($ch);
    }
    if (!$json) {
        $json = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout]]));
    }
    $data = $json ? json_decode($json, true) : null;
    return is_array($data) ? $data : null;
}

/** Coordinates of the family's city: ['name', 'lat', 'lon'], or null when no city is set / not found. */
function weather_place(): ?array
{
    $city = trim((string) setting('city', ''));
    if ($city === '') {
        return null;
    }
    $cached = json_decode((string) setting('weather_place', ''), true);
    if (is_array($cached) && ($cached['city'] ?? '') === $city) {
        return $cached['lat'] !== null ? $cached : null;
    }
    $geo = http_json('https://geocoding-api.open-meteo.com/v1/search?count=1&language=nl&name=' . rawurlencode($city));
    if ($geo === null) {
        return null; // try again next time
    }
    $r = $geo['results'][0] ?? null;
    $place = ['city' => $city, 'name' => $r['name'] ?? $city, 'lat' => $r['latitude'] ?? null, 'lon' => $r['longitude'] ?? null];
    set_setting('weather_place', json_encode($place));
    return $place['lat'] !== null ? $place : null;
}

/** Forecast for today and tomorrow (cached 30 minutes), or null. Never throws: the weather may not break the page. */
function weather_forecast(): ?array
{
    try {
        return weather_forecast_cached();
    } catch (Throwable $e) {
        error_log('[familyplanner] weather: ' . $e->getMessage());
        return null;
    }
}

function weather_forecast_cached(): ?array
{
    $place = weather_place();
    if (!$place) {
        return null;
    }
    $cached = json_decode((string) setting('weather_cache', ''), true);
    $fresh = is_array($cached) && ($cached['city'] ?? '') === $place['city'] && ($cached['at'] ?? 0) > time() - 1800 && ($cached['day'] ?? '') === today();
    if ($fresh) {
        return $cached['data'];
    }
    $data = http_json('https://api.open-meteo.com/v1/forecast?latitude=' . $place['lat'] . '&longitude=' . $place['lon']
        . '&current=temperature_2m,apparent_temperature,weather_code,wind_speed_10m'
        . '&hourly=temperature_2m,weather_code,precipitation_probability'
        . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,uv_index_max,wind_speed_10m_max'
        . '&timezone=Europe%2FAmsterdam&forecast_days=2');
    if (empty($data['daily']['time'])) {
        return is_array($cached) && ($cached['city'] ?? '') === $place['city'] ? $cached['data'] : null; // keep showing the last one
    }
    $data['place'] = $place['name'];
    set_setting('weather_cache', json_encode(['at' => time(), 'day' => today(), 'city' => $place['city'], 'data' => $data]));
    return $data;
}

/** Handy tips for the family for a day: [emoji, text]. $d: index in the daily arrays. */
function weather_tips(array $w, int $d): array
{
    $max = $w['daily']['temperature_2m_max'][$d] ?? null;
    $min = $w['daily']['temperature_2m_min'][$d] ?? null;
    $rain = $w['daily']['precipitation_probability_max'][$d] ?? 0;
    $uv = $w['daily']['uv_index_max'][$d] ?? 0;
    $wind = $w['daily']['wind_speed_10m_max'][$d] ?? 0;
    $code = $w['daily']['weather_code'][$d] ?? 0;
    $tips = [];
    if ($code >= 95) {
        $tips[] = ['⛈️', 'Onweer: liever binnen spelen'];
    }
    if ($code >= 71 && $code <= 86 && !in_array($code, [80, 81, 82], true)) {
        $tips[] = ['⛄', 'Sneeuw! Laarzen aan'];
    }
    if ($rain >= 50) {
        $tips[] = ['☂️', 'Regenjas of paraplu mee'];
    }
    if ($min !== null && $min <= 0) {
        $tips[] = ['🧤', 'Muts en handschoenen'];
    } elseif ($max !== null && $max <= 12) {
        $tips[] = ['🧥', 'Warme jas aan'];
    }
    if ($uv >= 5) {
        $tips[] = ['🧴', 'Zonnebrand smeren'];
    }
    if ($max !== null && $max >= 25) {
        $tips[] = ['💧', 'Extra drinken mee'];
    }
    if ($wind >= 45) {
        $tips[] = ['💨', 'Harde wind'];
    }
    return $tips;
}

/** Temperature and emoji at an hour today (hourly arrays), for morning / afternoon / evening. */
function weather_at(array $w, string $date, int $hour): ?array
{
    $i = array_search($date . 'T' . sprintf('%02d', $hour) . ':00', $w['hourly']['time'] ?? [], true);
    if ($i === false) {
        return null;
    }
    return ['temp' => $w['hourly']['temperature_2m'][$i], 'code' => $w['hourly']['weather_code'][$i], 'rain' => $w['hourly']['precipitation_probability'][$i] ?? null];
}
