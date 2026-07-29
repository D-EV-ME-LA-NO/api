<?php
/**
 * api/pluto/index.php — Pluto TV (عبر torrentio، provider vpro778)
 *
 * الاستخدام:
 *   /api/pluto/index.php?type=movie&id=TMDB_ID
 *   /api/pluto/index.php?type=tv&id=TMDB_ID&season=N&episode=N
 *
 * Returns: {"ok":true,"source":{"m3u8":"...","type":"m3u8","qualities":[...],"subtitles":[]}}
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

// ── بناء رابط torrentio ────────────────────────────────────────────────────────
if ($type === 'tv') {
    $apiUrl = "https://s.torrentio.to/v1/tv/{$id}/seasons/{$season}/episodes/{$episode}";
} else {
    $apiUrl = "https://s.torrentio.to/v1/movies/{$id}";
}

$ua = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 18,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING       => 'gzip, deflate, br',
    CURLOPT_HTTPHEADER     => [
        'host: s.torrentio.to',
        'accept: application/json',
        'user-agent: ' . $ua,
        'origin: https://stream.vidplay.to',
        'referer: https://stream.vidplay.to/',
        'sec-fetch-site: cross-site',
        'sec-fetch-mode: cors',
        'sec-fetch-dest: empty',
        'accept-language: en-US,en;q=0.9',
    ],
]);

$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$body || $code !== 200) {
    echo json_encode(['ok' => false, 'error' => "torrentio returned {$code}"]);
    exit;
}

$data = json_decode($body, true);
if (!isset($data['sources']) || !is_array($data['sources'])) {
    echo json_encode(['ok' => false, 'error' => 'unexpected response']);
    exit;
}

// ── فلترة مصادر Pluto فقط (provider vpro778) ─────────────────────────────────
$qualities = [];
foreach ($data['sources'] as $src) {
    $provId  = $src['provider']['id'] ?? '';
    $provName = strtolower($src['provider']['name'] ?? '');
    $url     = $src['url'] ?? null;
    $stype   = $src['type'] ?? 'hls';
    $quality = $src['quality'] ?? 'Auto';

    if (!$url) continue;
    if ($provId !== 'vpro778' && stripos($provName, 'pluto') === false) continue;

    if ($stype === 'hls') $stype = 'm3u8';

    $proxyUrl    = '/api/pluto-stream.php?url=' . rawurlencode($url);
    $qualities[] = [
        'label'   => $quality,
        'url'     => $proxyUrl,
        'type'    => $stype,
        'default' => empty($qualities),
    ];
}

if (empty($qualities)) {
    echo json_encode(['ok' => false, 'error' => 'no pluto sources found']);
    exit;
}

echo json_encode([
    'ok'     => true,
    'source' => [
        'provider'  => 'pluto',
        'name'      => 'Pluto',
        'm3u8'      => $qualities[0]['url'],
        'type'      => $qualities[0]['type'],
        'qualities' => $qualities,
        'subtitles' => [],
    ],
], JSON_UNESCAPED_SLASHES);
