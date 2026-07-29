<?php
/**
 * api/bingr/index.php — Bingr multi-scraper resolver
 *
 * يجلب مصادر الفيديو من api.bingr.one باستخدام عدة scrapers بالتوازي
 * ويجمع النتائج في صيغة MULTI_SOURCES.
 *
 * Response: { ok, servers: [{id, name, streams:[{label,url,type}]}] }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config.php';

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

// ── جلب العنوان والسنة من TMDB ────────────────────────────────────────────────
$meta = bingr_tmdb_meta($type, $id);
if (!$meta) {
    echo json_encode(['ok' => false, 'error' => 'could not fetch TMDB metadata']);
    exit;
}

// ── Scrapers / servers ────────────────────────────────────────────────────────
$scrapers = ['s1', 's2', 's3'];

$query = [
    'title' => $meta['title'],
    'year'  => (string)$meta['year'],
];
if ($type === 'tv') {
    $query['season']  = (string)$season;
    $query['episode'] = (string)$episode;
}

// ── جلب جميع الـ scrapers بالتوازي ───────────────────────────────────────────
$mh  = curl_multi_init();
$chs = [];

foreach ($scrapers as $srv) {
    $payload = json_encode([
        'srv'   => $srv,
        't'     => $type === 'tv' ? 'tv' : 'movie',
        'id'    => (string)$id,
        'query' => $query,
    ]);

    $ch = curl_init('https://api.bingr.one/api/stream');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 18,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_ENCODING       => 'gzip, deflate, br',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
            'Accept: */*',
            'Accept-Language: en-US,en;q=0.9',
            'Origin: https://bingr.one',
            'Referer: https://bingr.one/',
            'sec-fetch-site: same-site',
            'sec-fetch-mode: cors',
        ],
    ]);
    curl_multi_add_handle($mh, $ch);
    $chs[$srv] = $ch;
}

do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

// ── تجميع النتائج ─────────────────────────────────────────────────────────────
$streams = [];
$seen    = [];

foreach ($chs as $srv => $ch) {
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = curl_multi_getcontent($ch);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    if ($code !== 200 || !$body) continue;
    $j = json_decode($body, true);
    if (!is_array($j)) continue;

    $scraperName = $j['scraperName'] ?? $srv;
    $sources     = $j['sources']     ?? [];
    if (!is_array($sources)) continue;

    foreach ($sources as $src) {
        $url     = $src['url']     ?? '';
        $quality = $src['quality'] ?? 'Auto';
        $ctype   = strtolower($src['type'] ?? 'video/mp4');
        if (!$url) continue;

        // تجنب التكرار
        if (isset($seen[$url])) continue;
        $seen[$url] = true;

        $isHls = str_contains($url, '.m3u8') || str_contains($ctype, 'mpegurl');
        $streams[] = [
            'label' => $scraperName . ' · ' . $quality,
            'url'   => $url,
            'type'  => $isHls ? 'hls' : 'mp4',
        ];
    }
}

curl_multi_close($mh);

if (!$streams) {
    echo json_encode(['ok' => false, 'error' => 'no streams found', 'servers' => []]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'servers' => [[
        'id'      => 'bingr',
        'name'    => 'Bingr',
        'streams' => $streams,
    ]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ── Helper: جلب العنوان والسنة من TMDB ───────────────────────────────────────
function bingr_tmdb_meta(string $type, int $id): ?array
{
    $key = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
    if (!$key) return null;

    $url = 'https://api.themoviedb.org/3/' . $type . '/' . $id . '?api_key=' . $key;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $j = json_decode((string)$body, true);
    if (!is_array($j)) return null;

    $title = $j['title'] ?? $j['name'] ?? '';
    $date  = $j['release_date'] ?? $j['first_air_date'] ?? '';
    $year  = $date ? (int)substr($date, 0, 4) : 0;

    return ['title' => $title, 'year' => $year];
}
