<?php
/**
 * api/popcorn/index.php — PopcornMovies.io stream resolver
 *
 * الآلية:
 *  1. نجيب صفحة watch من popcornmovies.io لنستخرج playToken المدمج في SSR HTML
 *  2. نستخدم التوكن في طلب /api/sources لنحصل على روابط HLS
 *  3. نُعيد كل مصدر كـ server مستقل (type: expand في watch.php)
 *
 * Response: { ok: true, servers: [{id, name, streams:[{url, type, label}]}] }
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config.php';

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  && $_GET['season']  !== '' ? (int)$_GET['season']  : null;
$episode = isset($_GET['episode']) && $_GET['episode'] !== '' ? (int)$_GET['episode'] : null;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

$UA = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';

// ── Step 1: get title + year from TMDB ───────────────────────────────────────
function popcorn_tmdb_meta(string $type, int $id): array
{
    $key = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
    if (!$key) return ['title' => '', 'year' => 0];

    $url = 'https://api.themoviedb.org/3/' . $type . '/' . $id . '?api_key=' . $key;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_ENCODING       => 'gzip',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $j    = json_decode((string)$body, true);
    $title = $j['title'] ?? $j['name'] ?? '';
    $date  = $j['release_date'] ?? $j['first_air_date'] ?? '';
    $year  = $date ? (int)substr($date, 0, 4) : 0;
    return ['title' => $title, 'year' => $year];
}

$meta  = popcorn_tmdb_meta($type, $id);
$title = $meta['title'];
$year  = $meta['year'];

if (!$title) {
    echo json_encode(['ok' => false, 'error' => 'could not resolve title']);
    exit;
}

// ── Step 2: fetch popcornmovies watch page to extract playToken ───────────────
function popcorn_fetch(string $url, array $headers = [], int $timeout = 15): ?string
{
    global $UA;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_ENCODING       => 'gzip, deflate, br',
        CURLOPT_HTTPHEADER     => array_merge([
            'user-agent: ' . $UA,
            'accept: text/html,application/xhtml+xml,*/*;q=0.9',
            'accept-language: en-US,en;q=0.9',
            'sec-fetch-site: same-origin',
            'sec-fetch-mode: navigate',
            'sec-fetch-dest: document',
        ], $headers),
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body ?: null;
}

// بناء مسار الصفحة حسب النوع
$watchPath = $type === 'tv' && $season !== null && $episode !== null
    ? "/watch/tv/{$id}?season={$season}&episode={$episode}"
    : "/watch/movie/{$id}";

$pageHtml = popcorn_fetch('https://popcornmovies.io' . $watchPath);

if (!$pageHtml) {
    echo json_encode(['ok' => false, 'error' => 'failed to load popcorn page']);
    exit;
}

// استخراج playToken من SSR JSON المدمج في __next_f
// الشكل في الـ HTML: \"playToken\":\"1784789711345.caf0c5c...\"
preg_match('/playToken\\\\":\\\\"(\d{13}\.[a-f0-9]{64})/', $pageHtml, $m);
$playToken = $m[1] ?? '';

if (!$playToken) {
    echo json_encode(['ok' => false, 'error' => 'playToken not found']);
    exit;
}

// ── Step 3: call /api/sources ─────────────────────────────────────────────────
$qs = http_build_query([
    'type'   => $type,
    'tmdbId' => $id,
    'title'  => $title,
    'year'   => $year,
]);
if ($type === 'tv' && $season !== null && $episode !== null) {
    $qs .= '&season=' . $season . '&episode=' . $episode;
}

$sourcesUrl = 'https://popcornmovies.io/api/sources?' . $qs;

$ch = curl_init($sourcesUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_ENCODING       => 'gzip, deflate, br',
    CURLOPT_HTTPHEADER     => [
        'host: popcornmovies.io',
        'user-agent: ' . $UA,
        'x-play-token: ' . $playToken,
        'accept: */*',
        'origin: https://popcornmovies.io',
        'referer: https://popcornmovies.io' . $watchPath,
        'sec-fetch-site: same-origin',
        'sec-fetch-mode: cors',
        'sec-fetch-dest: empty',
        'accept-language: en-US,en;q=0.9',
    ],
]);
$raw  = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

$data = json_decode((string)$raw, true);
if (!is_array($data) || empty($data['sources'])) {
    echo json_encode(['ok' => false, 'error' => 'no sources returned', 'http' => $info['http_code'] ?? 0]);
    exit;
}

// ── Step 4: بناء الـ servers array ───────────────────────────────────────────
// كل source → server مستقل (يُعرض في watch.php كـ expand)
$servers = [];

foreach ($data['sources'] as $src) {
    $url     = $src['url']     ?? '';
    $label   = $src['label']   ?? ucfirst($src['provider'] ?? 'Stream');
    $quality = $src['quality'] ?? '';
    $kind    = $src['kind']    ?? 'hls';   // hls | mp4

    if (!$url) continue;

    $streamType = ($kind === 'mp4') ? 'mp4' : 'm3u8';
    $name       = 'Popcorn · ' . $label . ($quality && $quality !== 'auto' ? ' ' . $quality . 'p' : '');
    $sid        = 'popcorn_' . preg_replace('/[^a-z0-9]/i', '_', strtolower($src['provider'] ?? $label));

    $servers[] = [
        'id'      => $sid,
        'name'    => $name,
        'streams' => [
            ['url' => $url, 'type' => $streamType, 'label' => $quality ?: 'auto'],
        ],
    ];
}

// نضيف الترجمات للسيرفر الأول فقط
if (!empty($servers) && !empty($data['subtitles'])) {
    $subs = [];
    foreach ($data['subtitles'] as $sub) {
        if (empty($sub['url'])) continue;
        $subs[] = [
            'url'     => $sub['url'],
            'label'   => $sub['label'] ?? strtoupper($sub['language'] ?? 'UNK'),
            'default' => !empty($sub['default']),
        ];
    }
    if ($subs) {
        $servers[0]['streams'][0]['subtitles'] = $subs;
    }
}

if (empty($servers)) {
    echo json_encode(['ok' => false, 'error' => 'no valid streams']);
    exit;
}

echo json_encode(['ok' => true, 'servers' => $servers], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
