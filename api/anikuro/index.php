<?php
/**
 * api/anikuro/index.php — AniKuro streaming
 * Flow: TMDB ID → TMDB title → AniList ID → anikuro endpoints → m3u8
 * GET: ?type=tv&id=TMDB_ID&season=1&episode=1
 *      ?anilist_id=ID&episode=1       (bypass lookup)
 *      ?title=One+Piece&episode=1     (title-only, for anime_watch)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../../config.php';

define('AK_UA',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('AK_REF',   'https://anikuro.ru/');
define('AK_BASE',  'https://anikuro.ru/api/v1');
define('AK_CACHE', __DIR__ . '/../../.cache/anikuro');
define('AK_PROXY', '/api/anikuro/proxy.php?url=');

@mkdir(AK_CACHE, 0755, true);

$type       = $_GET['type']         ?? 'tv';
$tmdb_id    = (int)($_GET['id']     ?? 0);
$anilist_id = (int)($_GET['anilist_id'] ?? 0);
$ak_id      = (int)($_GET['ak_id']  ?? 0);    // ← bypass direct: anikuro native ID
$raw_title  = trim($_GET['title']   ?? '');   // title-only path (no TMDB)
$season     = max(1, (int)($_GET['season']  ?? 1));
$episode    = max(1, (int)($_GET['episode'] ?? 1));

// ── ak_id bypass: skip ALL lookups, jump straight to streams ─────────────────
if ($ak_id) { $anilist_id = $ak_id; }

// ── Helper: curl GET ──────────────────────────────────────────────────────────
function ak_get(string $url, array $extra_hdrs = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => array_merge([
            'User-Agent: ' . AK_UA,
            'Referer: '    . AK_REF,
            'Accept: application/json',
        ], $extra_hdrs),
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw];
}

// ── Step 1: resolve title (skip TMDB when title or anilist_id given directly) ──
if (!$anilist_id) {

    // Title-only path: skip TMDB, search AniList directly
    if ($raw_title && !$tmdb_id) {
        $title = $raw_title; $orig_title = $raw_title; $year = null;
    } elseif (!$tmdb_id) {
        echo json_encode(['ok'=>false,'error'=>'missing id','servers'=>[]]); exit;
    } else {

    $tmdb_ep = ($type === 'movie')
        ? TMDB_API_URL . '/movie/' . $tmdb_id . '?api_key=' . TMDB_API_KEY . '&language=en-US'
        : TMDB_API_URL . '/tv/'    . $tmdb_id . '?api_key=' . TMDB_API_KEY . '&language=en-US';

    [, $tmdb_raw] = ak_get($tmdb_ep, ['Accept: application/json']);
    $tmdb = json_decode($tmdb_raw, true);
    if (!$tmdb) { echo json_encode(['ok'=>false,'error'=>'TMDB lookup failed','servers'=>[]]); exit; }

        $title      = ($type === 'movie') ? ($tmdb['title'] ?? '') : ($tmdb['name'] ?? '');
        $orig_title = ($type === 'movie') ? ($tmdb['original_title'] ?? $title) : ($tmdb['original_name'] ?? $title);
        $year       = (int)substr(($tmdb['release_date'] ?? $tmdb['first_air_date'] ?? ''), 0, 4) ?: null;

        if (!$title) { echo json_encode(['ok'=>false,'error'=>'no title from TMDB','servers'=>[]]); exit; }
    } // end TMDB path

    // ── Step 2: title → AniList ID (7-day cache) ─────────────────────────────
    // cache key: prefer TMDB id; fall back to md5 of title for title-only path
    $al_key   = $tmdb_id ? $tmdb_id . '_' . $type : 'ttl_' . md5(strtolower($title));
    $al_cache = AK_CACHE . '/al_' . $al_key . '.json';
    if (file_exists($al_cache) && (time() - filemtime($al_cache)) < 86400 * 7) {
        $c = json_decode(file_get_contents($al_cache), true);
        $anilist_id = (int)($c['anilist_id'] ?? 0);
    }

    if (!$anilist_id) {
        // Try both English and original title
        $search_titles = array_unique([$title, $orig_title]);
        foreach ($search_titles as $st) {
            $gql = json_encode(['query' =>
                '{ Media(search: ' . json_encode($st) . ', type: ANIME, format_in: [TV, MOVIE, OVA, ONA, SPECIAL]) { id title { romaji english } seasonYear } }'
            ]);
            $ch = curl_init('https://graphql.anilist.co');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $gql,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $al = json_decode($raw, true);
                $mid = $al['data']['Media']['id'] ?? null;
                if ($mid) {
                    // Validate year match (±2 years)
                    $al_year = (int)($al['data']['Media']['seasonYear'] ?? 0);
                    if (!$year || !$al_year || abs($year - $al_year) <= 2) {
                        $anilist_id = (int)$mid;
                        break;
                    }
                }
            }
        }

        if ($anilist_id) {
            file_put_contents($al_cache, json_encode(['anilist_id' => $anilist_id, 'title' => $title]));
        }
    }

    if (!$anilist_id) {
        echo json_encode(['ok'=>false,'error'=>'anime not found on AniList','servers'=>[]]);
        exit;
    }
}

// ── Step 3: Fetch streams from anikuro endpoints in parallel ──────────────────
// episodeId format: "animeId:episode"
$ep_id = $anilist_id . ':' . $episode;

// Endpoint definitions: [url, label]
$sources = [
    'animepower' => [AK_BASE . '/animepower/video/' . $anilist_id . '/' . $episode, 'AniKuro'],
    'senshi'     => [AK_BASE . '/sources/senshi/'   . $ep_id,                        'Senshi'],
    'animix'     => [AK_BASE . '/sources/animix/'   . $ep_id,                        'AniMix'],
    'animepahe'  => [AK_BASE . '/sources/animepahe/'. $ep_id,                        'AnimePahe'],
];

// 4-hour cache per episode
$ep_cache = AK_CACHE . '/ep_' . $anilist_id . '_e' . $episode . '.json';
$cached_streams = null;

if (file_exists($ep_cache) && (time() - filemtime($ep_cache)) < 3600 * 4) {
    $c = json_decode(file_get_contents($ep_cache), true);
    if (!empty($c['streams'])) $cached_streams = $c['streams'];
}

if ($cached_streams !== null) {
    echo json_encode(['ok'=>true,'servers'=>[['id'=>'anikuro','name'=>'AniKuro','streams'=>$cached_streams]]],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// curl_multi — all sources in parallel
$mh = curl_multi_init();
$handles = [];
$ak_headers = ['User-Agent: ' . AK_UA, 'Referer: ' . AK_REF, 'Accept: application/json'];

foreach ($sources as $key => [$url, $label]) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => $ak_headers,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$key] = [$ch, $label];
}

do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

$streams = [];
foreach ($handles as $key => [$ch, $label]) {
    $raw  = curl_multi_getcontent($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    if ($code !== 200 || !$raw) continue;
    $d = json_decode($raw, true);
    if (!($d['ok'] ?? false)) continue;

    // Parse normalized[]
    $normalized = $d['data']['normalized'] ?? [];

    foreach ($normalized as $norm) {
        $variant = $norm['variant'] ?? 'sub'; // sub | dub
        foreach ($norm['sources'] ?? [] as $src) {
            $raw_url = $src['url'] ?? '';
            if (!$raw_url || !str_contains($raw_url, '.m3u8')) continue;

            // Resolve relative URLs (senshi proxy)
            if (str_starts_with($raw_url, '/')) {
                $raw_url = 'https://anikuro.ru' . $raw_url;
            }

            // Proxy the stream through our server
            $proxied = AK_PROXY . urlencode($raw_url);

            $suffix = strtoupper($variant) === 'DUB' ? ' [Dub]' : ' [Sub]';
            $streams[] = [
                'label' => $label . $suffix,
                'url'   => $proxied,
                'type'  => 'hls',
            ];
        }
    }
}
curl_multi_close($mh);

if (empty($streams)) {
    echo json_encode(['ok'=>false,'error'=>'no streams from anikuro','servers'=>[]]);
    exit;
}

// Cache
file_put_contents($ep_cache, json_encode(['streams' => $streams]));

echo json_encode([
    'ok'      => true,
    'servers' => [['id' => 'anikuro', 'name' => 'AniKuro', 'streams' => $streams]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
