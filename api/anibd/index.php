<?php
// api/anibd/index.php — AniBD anime streaming
// Flow: TMDB ID → title → search animeapps.top → AniList ID → episode link → m3u8 via proxy
// CDN: ani4.nukitashith.top  |  Search: eng.animeapps.top  |  Episodes: epeng.animeapps.top

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config.php';

define('AB_UA',      'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('AB_REFERER', 'https://playeng.animeapps.top/');
define('AB_ORIGIN',  'https://playeng.animeapps.top');
define('AB_CACHE',   __DIR__ . '/../../.cache/anibd');
define('AB_PROXY',   '/api/anibd/proxy.php?url=');

$type      = ($_GET['type']      ?? 'tv');
$id        = (int)($_GET['id']   ?? 0);
$raw_title = trim($_GET['title'] ?? '');   // title-only path (anime_watch)
$season    = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode   = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

// AniBD only supports anime (TV type)
if ($type !== 'tv' && !$raw_title) {
    echo json_encode(['ok' => false, 'error' => 'anibd only supports anime TV series', 'servers' => []]);
    exit;
}

// ── Step 1: Get title ────────────────────────────────────────────────────────
if ($raw_title && !$id) {
    // Title-only path: skip TMDB lookup
    $title = $raw_title;
    $orig  = $raw_title;
} elseif (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id or title', 'servers' => []]);
    exit;
} else {
    $tmdb_ch = curl_init(TMDB_API_URL . '/tv/' . $id . '?api_key=' . TMDB_API_KEY . '&language=en-US');
    curl_setopt_array($tmdb_ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_ENCODING       => '',
    ]);
    $tmdb = json_decode(curl_exec($tmdb_ch), true);
    curl_close($tmdb_ch);

    if (!$tmdb || empty($tmdb['name'])) {
        echo json_encode(['ok' => false, 'error' => 'TMDB lookup failed', 'servers' => []]);
        exit;
    }

    $title = $tmdb['name'];
    $orig  = $tmdb['original_name'] ?? $title;
}

// ── Step 2: Find AniList ID via animeapps.top search (with 7-day cache) ──────
@mkdir(AB_CACHE, 0755, true);
// cache key: prefer TMDB id; fall back to md5 of title for title-only path
$cache_key  = $id ? $id . '_s' . $season : 'ttl_' . md5(strtolower($title));
$cache_file = AB_CACHE . '/' . $cache_key . '.json';
$anilist_id = null;

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 86400 * 7) {
    $cached     = json_decode(file_get_contents($cache_file), true);
    $anilist_id = $cached['anilist_id'] ?? null;
}

if (!$anilist_id) {
    // Build candidate queries: season-qualified first, then plain title, then original
    $queries = $season > 1
        ? [$title . ' Season ' . $season, $title . ' ' . $season, $title, $orig]
        : [$title, $orig];
    $queries = array_unique($queries);

    foreach ($queries as $q) {
        $ch = curl_init('https://eng.animeapps.top/api/search3.php?' . http_build_query([
            'keyword' => $q, 'page' => 1, 'limit' => 5,
        ]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . AB_UA,
                'Accept: application/json',
            ],
        ]);
        $results = json_decode(curl_exec($ch), true);
        curl_close($ch);

        // API wraps results in { "status": "success", "data": [...] }
        $items = $results['data'] ?? (is_array($results) ? $results : []);

        foreach ($items as $item) {
            if (!empty($item['anilist'])) {
                $anilist_id = (int)$item['anilist'];
                break 2;
            }
        }
    }

    if ($anilist_id) {
        file_put_contents($cache_file, json_encode(['anilist_id' => $anilist_id]));
    }
}

if (!$anilist_id) {
    echo json_encode(['ok' => false, 'error' => 'anime not found on AniBD', 'servers' => []]);
    exit;
}

// ── Step 3: Build episode link slug ─────────────────────────────────────────
// Formula confirmed: {anilist_id}eop{episode_number}web
$link = $anilist_id . 'eop' . $episode . 'web';
$m3u8 = 'https://ani4.nukitashith.top/' . $link . '/index.m3u8';

// ── Step 4: Verify stream exists ─────────────────────────────────────────────
$vch = curl_init($m3u8);
curl_setopt_array($vch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_NOBODY         => true,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: ' . AB_UA,
        'Origin: '     . AB_ORIGIN,
        'Referer: '    . AB_REFERER,
    ],
]);
curl_exec($vch);
$http_code = (int)curl_getinfo($vch, CURLINFO_HTTP_CODE);
curl_close($vch);

if ($http_code !== 200) {
    echo json_encode(['ok' => false, 'error' => 'episode not available (ep ' . $episode . ')', 'servers' => []]);
    exit;
}

// ── Return result ────────────────────────────────────────────────────────────
echo json_encode([
    'ok'      => true,
    'servers' => [[
        'id'      => 'anibd',
        'name'    => 'AniBD',
        'streams' => [[
            'label' => 'AniBD 1080p Sub',
            'url'   => AB_PROXY . urlencode($m3u8),
            'type'  => 'hls',
        ]],
    ]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
