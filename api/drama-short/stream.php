<?php
/**
 * api/drama-short/stream.php — Extract video URL from narto-drama.com watch page
 * GET: ?slug=swallowed-whole-by-my-hot-stepbrother&episode=3&lang=en-US
 *      ?drama_id=563&episode=3&lang=en-US  (direct CDN construct, no scraping needed)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

define('ND_UA_S',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('ND_BASE_S',  'https://narto-drama.com');
define('ND_CACHE_S', __DIR__ . '/../../.cache/drama-short');

@mkdir(ND_CACHE_S, 0755, true);

$slug     = preg_replace('/[^a-z0-9_-]/i', '', $_GET['slug'] ?? '');
$episode  = max(1, (int)($_GET['episode'] ?? 1));
$lang     = preg_replace('/[^a-z0-9_-]/i', '', $_GET['lang'] ?? 'en-US');
$drama_id = (int)($_GET['drama_id'] ?? 0);

if (!$slug && !$drama_id) {
    echo json_encode(['ok' => false, 'error' => 'Missing slug or drama_id']); exit;
}

// ── If we have drama_id, try cached video URL first ───────────────────────────
$video_cache_key = ND_CACHE_S . '/stream_' . ($slug ?: 'id' . $drama_id) . '_ep' . $episode . '_' . $lang . '.json';
if (is_file($video_cache_key) && (time() - filemtime($video_cache_key)) < 300) {
    $cached = json_decode(file_get_contents($video_cache_key), true);
    if (!empty($cached['video_url'])) { echo json_encode($cached); exit; }
}

$jar = ND_CACHE_S . '/cookies.txt';

// ── Cookie warm-up (visit homepage first to get Laravel session) ──────────────
if (!is_file($jar) || (time() - filemtime($jar)) > 5400) {
    $wch = curl_init(ND_BASE_S . '/?lang=' . $lang . '&tab-provider=bibishort');
    curl_setopt_array($wch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_ENCODING       => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . ND_UA_S,
            'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
            'Accept-Language: ar-IQ,ar;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding: gzip, deflate, br',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Dest: document',
        ],
    ]);
    curl_exec($wch);
    curl_close($wch);
    @touch($jar);
}

// ── Fetch watch page ──────────────────────────────────────────────────────────
$watch_url = ND_BASE_S . '/detail/watch/' . $slug . '/' . $episode . '?lang=' . $lang . '&from=home';

$ch = curl_init($watch_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_ENCODING       => '',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_COOKIEFILE     => $jar,
    CURLOPT_COOKIEJAR      => $jar,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: '           . ND_UA_S,
        'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
        'Accept-Language: ar-IQ,ar;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept-Encoding: gzip, deflate, br',
        'Referer: '              . ND_BASE_S . '/detail/watch/' . $slug . '?lang=' . $lang . '&from=home',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-User: ?1',
        'Sec-Fetch-Dest: document',
    ],
]);
$html = (string)curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$html) {
    echo json_encode(['ok' => false, 'error' => 'Watch page fetch failed', 'code' => $code]); exit;
}

// ── Extract video URL from HTML ───────────────────────────────────────────────
$video_url = '';

// Pattern 1: full CDN URL with auth_key in src/data attributes
if (preg_match(
    '#https://media-prod\.crazytalkai\.com/drama_\d+/[a-z\-]{2,10}/\d+\.mp4\?auth_key=[^\s"\'<>]+#i',
    $html, $m
)) {
    $video_url = $m[0];
}

// Pattern 2: video tag src
if (!$video_url && preg_match('/<video[^>]+src\s*=\s*["\']([^"\']+crazytalkai[^"\']+)["\']/', $html, $m)) {
    $video_url = $m[1];
}

// Pattern 3: source tag
if (!$video_url && preg_match('/<source[^>]+src\s*=\s*["\']([^"\']+crazytalkai[^"\']+)["\']/', $html, $m)) {
    $video_url = $m[1];
}

// Pattern 4: JS variable assignment
if (!$video_url && preg_match('/["\']?(videoSrc|video_url|src)["\']?\s*[=:]\s*["\']([^"\']+crazytalkai[^"\']+)["\']/', $html, $m)) {
    $video_url = $m[2];
}

// Pattern 5: any URL from the CDN domain (less strict)
if (!$video_url && preg_match(
    '#https://media-prod\.crazytalkai\.com/[^\s"\'<>]+\.mp4[^\s"\'<>]*#i',
    $html, $m
)) {
    $video_url = $m[0];
}

// ── Also try to extract drama_id from page if not passed ─────────────────────
if (!$drama_id && preg_match('/media-prod\.crazytalkai\.com\/drama_(\d+)\//i', $html, $m)) {
    $drama_id = (int)$m[1];
}

$result = [
    'ok'        => (bool)$video_url,
    'video_url' => $video_url,
    'drama_id'  => $drama_id,
    'slug'      => $slug,
    'episode'   => $episode,
    'lang'      => $lang,
];

if (!$video_url) {
    $result['error']   = 'Video URL not found in page source';
    $result['hint']    = 'The video may require browser-level JS execution to load';
}

if ($video_url) {
    @file_put_contents($video_cache_key, json_encode($result));
}

echo json_encode($result);
