<?php
// Operational weather for the office. London conditions affect every division —
// carers travelling to home visits, guards on patrol, cleaners on rounds — so
// the dashboard surfaces current weather plus a plain-English shift advisory.
//
// The OpenWeather key stays server-side and never reaches the browser.
// GET /weather.php?city=London,GB  ->  { city, temp, ... , advisory: {level, note} }
require __DIR__ . '/lib.php';

// Internal tool — logged-in staff only, which now means what it says. It was
// require_auth() alone, so a client portal or worker login could spend the
// OpenWeather quota on arbitrary city lookups.
$user = require_auth($conn);
require_staff($user);
if (method() !== 'GET') fail('Method not allowed.', 405);

if (!defined('OPENWEATHER_API_KEY') || OPENWEATHER_API_KEY === 'CHANGE_ME' || OPENWEATHER_API_KEY === '') {
    fail('Weather is not configured. Set OPENWEATHER_API_KEY in config.php.', 503);
}

$units = defined('OPENWEATHER_UNITS') ? OPENWEATHER_UNITS : 'metric';
if (!in_array($units, ['metric', 'imperial', 'standard'], true)) $units = 'metric';

// Accept a simple "City,CC" string; default to London. Keep it conservative so
// it can't be abused to call arbitrary hosts (we only ever hit OpenWeather).
$city = trim((string) ($_GET['city'] ?? 'London,GB'));
if ($city === '' || mb_strlen($city) > 60 || !preg_match('/^[A-Za-z .,\'-]+$/', $city)) {
    $city = 'London,GB';
}

// ---- 15-minute file cache (avoids hammering the API / rate limits) ----
$cacheKey = 'rg_weather_' . md5($city . '|' . $units) . '.json';
$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $cacheKey;
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
    $cached = file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') {
        echo $cached;
        exit;
    }
}

$url = 'https://api.openweathermap.org/data/2.5/weather?q=' . rawurlencode($city)
    . '&units=' . $units
    . '&appid=' . OPENWEATHER_API_KEY;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($response === false) fail('Could not reach the weather service: ' . $curlErr, 502);
if ($status === 401) fail('The weather API key was rejected. Check OPENWEATHER_API_KEY.', 502);
if ($status === 404) fail('That location was not found.', 404);
if ($status >= 400) fail('The weather service returned an error.', 502);

$w = json_decode($response, true);
if (!is_array($w) || !isset($w['main'])) fail('The weather service returned an unexpected response.', 502);

$main = $w['weather'][0]['main'] ?? '';
$temp = (float) ($w['main']['temp'] ?? 0);
$feels = (float) ($w['main']['feels_like'] ?? $temp);
$windRaw = (float) ($w['wind']['speed'] ?? 0);      // m/s (metric/standard) or mph (imperial)
$gustRaw = (float) ($w['wind']['gust'] ?? $windRaw);

// Normalise temp to °C and wind to mph so the advisory rules are unit-agnostic.
$tempC = $units === 'imperial' ? ($temp - 32) * 5 / 9 : ($units === 'standard' ? $temp - 273.15 : $temp);
$windMph = $units === 'imperial' ? max($windRaw, $gustRaw) : max($windRaw, $gustRaw) * 2.23694;

// ---- Operational advisory: what does this mean for shifts? ----
$advisory = ['level' => 'ok', 'note' => 'Clear running — no weather impact expected on shifts.'];
if (in_array($main, ['Snow'], true) || $tempC <= 1) {
    $advisory = ['level' => 'alert', 'note' => 'Icy conditions likely — allow extra travel time for carers and patrols, and check on vulnerable service users.'];
} elseif ($windMph >= 31) {
    $advisory = ['level' => 'alert', 'note' => 'High winds — review outdoor security and event cover, and watch for travel disruption.'];
} elseif (in_array($main, ['Thunderstorm'], true)) {
    $advisory = ['level' => 'alert', 'note' => 'Storms forecast — expect travel delays on home visits and rounds.'];
} elseif (in_array($main, ['Rain', 'Drizzle'], true) || $windMph >= 20) {
    $advisory = ['level' => 'watch', 'note' => 'Wet and breezy — build in extra travel time for visits and rounds.'];
} elseif ($tempC >= 28) {
    $advisory = ['level' => 'watch', 'note' => 'Hot — check on vulnerable service users and keep outdoor staff hydrated.'];
}

$out = json_encode([
    'city' => $w['name'] ?? $city,
    'units' => $units,
    'temp' => round($temp),
    'feels_like' => round($feels),
    'main' => $main,
    'description' => $w['weather'][0]['description'] ?? '',
    'icon' => $w['weather'][0]['icon'] ?? '',
    'humidity' => (int) ($w['main']['humidity'] ?? 0),
    'wind_mph' => round($windMph),
    'advisory' => $advisory,
    'fetched_at' => gmdate('c'),
]);

// Best-effort cache write; ignore failures (read-only temp dirs etc).
@file_put_contents($cacheFile, $out);

echo $out;
