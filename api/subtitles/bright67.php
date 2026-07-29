<?php
/**
 * api/subtitles/bright67.php
 * Fetches subtitles from subs.bright67.online using TMDB ID.
 *
 * GET params: id (TMDB numeric ID), type (movie|tv), season, episode
 */
require_once dirname(__DIR__, 2) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$tmdb_id = (int)($_GET['id'] ?? 0);
if (!$tmdb_id) {
    echo json_encode(['ok' => false, 'subtitles' => [], 'reason' => 'missing_id']);
    exit;
}

$CACHE_DIR = dirname(__DIR__, 2) . '/data/cache/subtitles';
@mkdir($CACHE_DIR, 0755, true);
$cache_key  = $tmdb_id;
$cache_file = $CACHE_DIR . '/bright67_list_' . $cache_key . '.json';
$cache_ttl  = 3600;

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    $cached = file_get_contents($cache_file);
    if ($cached !== false) { echo $cached; exit; }
}

$api_url = 'https://subs.bright67.online/search?id=' . $tmdb_id;

$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ],
    CURLOPT_SSL_VERIFYPEER => true,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false) {
    echo json_encode(['ok' => false, 'subtitles' => [], 'reason' => 'fetch_failed']);
    exit;
}
if ($code === 400) {
    echo json_encode(['ok' => true, 'subtitles' => [], 'source' => 'bright67']);
    exit;
}
if ($code !== 200) {
    echo json_encode(['ok' => false, 'subtitles' => [], 'reason' => 'http_' . $code]);
    exit;
}

$data = json_decode($body, true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'subtitles' => [], 'reason' => 'parse_failed']);
    exit;
}

$subtitles = [];
foreach ($data as $s) {
    $lang    = $s['language'] ?? 'und';
    $display = $s['display']  ?? $lang;
    $url     = $s['url']      ?? '';
    $sid     = $s['id']       ?? '';
    if (!$url || !$sid) continue;

    $subtitles[] = [
        'url'   => '/api/subtitles/bright67-proxy.php?id=' . urlencode($sid) . '&url=' . urlencode($url),
        'lang'  => $lang,
        'label' => $display,
    ];
}

$result = json_encode(
    ['ok' => true, 'subtitles' => $subtitles, 'source' => 'bright67'],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
file_put_contents($cache_file, $result);
echo $result;
