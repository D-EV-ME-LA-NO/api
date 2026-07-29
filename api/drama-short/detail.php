<?php
/**
 * api/drama-short/detail.php — Scrape drama detail from narto-drama.com
 * GET: ?provider=bibishort&book_id=557&lang=en-US&title=Drama+Title
 *      or ?slug=swallowed-whole-by-my-hot-stepbrother&lang=en-US
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

define('ND_UA_D',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('ND_BASE_D',  'https://narto-drama.com');
define('ND_CACHE_D', __DIR__ . '/../../.cache/drama-short');

@mkdir(ND_CACHE_D, 0755, true);

$provider = preg_replace('/[^a-z0-9_-]/i', '', $_GET['provider'] ?? '');
$book_id  = (int)($_GET['book_id'] ?? 0);
$slug_in  = preg_replace('/[^a-z0-9_-]/i', '', $_GET['slug'] ?? '');
$lang     = preg_replace('/[^a-z0-9_-]/i', '', $_GET['lang'] ?? 'en-US');
$title    = trim($_GET['title'] ?? '');

if (!$provider && !$book_id && !$slug_in) {
    echo json_encode(['ok' => false, 'error' => 'Missing provider/book_id or slug']); exit;
}

// Cache
$cache_id  = $slug_in ?: ($provider . '_' . $book_id);
$cache_key = ND_CACHE_D . '/detail_' . md5($cache_id . $lang) . '.json';

if (is_file($cache_key) && (time() - filemtime($cache_key)) < 7200) {
    $data = json_decode(file_get_contents($cache_key), true);
    if ($data && !empty($data['ok'])) { echo json_encode($data); exit; }
}

// ── Helper: curl GET ──────────────────────────────────────────────────────────
function nd_get(string $url, string $referer = ''): array {
    $jar = ND_CACHE_D . '/cookies.txt';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 18,
        CURLOPT_ENCODING       => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . ND_UA_D,
            'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
            'Accept-Language: ar-IQ,ar;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding: gzip, deflate, br',
            'Referer: ' . ($referer ?: ND_BASE_D . '/'),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'url' => $final_url];
}

// ── Step 1: Resolve slug via import URL ───────────────────────────────────────
$slug = $slug_in;

if (!$slug && $provider && $book_id) {
    // Try slug cache first
    $slug_cache = ND_CACHE_D . '/slug_' . $provider . '_' . $book_id . '.txt';
    if (is_file($slug_cache) && (time() - filemtime($slug_cache)) < 86400 * 7) {
        $slug = trim(file_get_contents($slug_cache));
    }

    if (!$slug) {
        $import_url = ND_BASE_D . '/search/import?' . http_build_query([
            'provider'    => $provider,
            'book_id'     => $book_id,
            'title'       => $title,
            'lang'        => $lang,
            'target_lang' => $lang,
        ]);
        $res = nd_get($import_url, ND_BASE_D . '/');
        // Extract slug from final URL: /detail/watch/{slug}
        if (preg_match('#/detail/watch/([^/?&#]+)#', $res['url'], $m)) {
            $slug = $m[1];
            @file_put_contents($slug_cache, $slug);
        }
    }
}

if (!$slug) {
    echo json_encode(['ok' => false, 'error' => 'Could not resolve drama slug']); exit;
}

// ── Step 2: Scrape detail page ────────────────────────────────────────────────
$detail_url = ND_BASE_D . '/detail/watch/' . $slug . '?lang=' . $lang;
$res = nd_get($detail_url, ND_BASE_D . '/');

if ($res['code'] !== 200 || !$res['body']) {
    echo json_encode(['ok' => false, 'error' => 'Detail page fetch failed', 'code' => $res['code']]); exit;
}

$html = $res['body'];

// ── Parse metadata ────────────────────────────────────────────────────────────

// Title
$drama_title = $title;
if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $m)) {
    $drama_title = trim(html_entity_decode($m[1], ENT_QUOTES));
} elseif (preg_match('/<title>([^<|]+)/i', $html, $m)) {
    $drama_title = trim(preg_replace('/\s*[-|].*$/', '', html_entity_decode($m[1])));
}

// Poster
$poster = '';
if (preg_match('/cover-prod\.crazytalkai\.com\/[^\s"\']+/', $html, $m)) {
    $poster = 'https://' . $m[0];
} elseif (preg_match('/<meta\s+property="og:image"\s+content="([^"]+)"/i', $html, $m)) {
    $poster = $m[1];
}

// Description
$desc = '';
if (preg_match('/<meta\s+name="description"\s+content="([^"]+)"/i', $html, $m)) {
    $desc = html_entity_decode($m[1], ENT_QUOTES);
}

// Drama ID (from CDN URLs embedded in page)
$drama_id = 0;
if (preg_match('/media-prod\.crazytalkai\.com\/drama_(\d+)\//i', $html, $m)) {
    $drama_id = (int)$m[1];
}

// Total episodes — look for episode links or count indicators
$total_episodes = 0;
// Pattern: episode buttons/links like /detail/watch/{slug}/1, /2, etc.
if (preg_match_all('#/detail/watch/' . preg_quote($slug, '#') . '/(\d+)#', $html, $m)) {
    $ep_nums = array_map('intval', $m[1]);
    if ($ep_nums) $total_episodes = max($ep_nums);
}
// Fallback: look for "X episodes" text
if (!$total_episodes && preg_match('/(\d+)\s*(?:episodes?|حلقة|حلقات)/i', $html, $m)) {
    $total_episodes = (int)$m[1];
}
// Fallback: look for JSON-embedded episode data
if (!$total_episodes && preg_match('/"total_episodes"\s*:\s*(\d+)/i', $html, $m)) {
    $total_episodes = (int)$m[1];
}
// Fallback: look for episode selector/list items
if (!$total_episodes && preg_match_all('/data-ep(?:isode)?\s*=\s*["\']?(\d+)["\']?/i', $html, $m)) {
    $ep_nums = array_map('intval', $m[1]);
    if ($ep_nums) $total_episodes = max($ep_nums);
}

// If drama_id found but no episodes, default to at least 1
if ($drama_id && !$total_episodes) $total_episodes = 1;

$result = [
    'ok'             => true,
    'slug'           => $slug,
    'title'          => $drama_title,
    'poster'         => $poster,
    'description'    => mb_strimwidth($desc, 0, 400, '…'),
    'drama_id'       => $drama_id,
    'total_episodes' => $total_episodes,
    'provider'       => $provider,
    'book_id'        => $book_id,
    'lang'           => $lang,
];

@file_put_contents($cache_key, json_encode($result));
echo json_encode($result);
