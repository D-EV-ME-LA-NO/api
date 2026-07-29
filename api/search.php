<?php
/**
 * api/search.php — بحث موحّد: TMDB (أفلام/مسلسلات) + aniwaves (أنمي)
 * GET ?q=QUERY&page=1
 * Response: { results: [...], total_pages }
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$q    = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

if ($q === '') {
    echo json_encode(['results' => [], 'total_pages' => 0]);
    exit;
}

// ─────────────────────────────────────────────────
// 1. TMDB search (movies + TV)
// ─────────────────────────────────────────────────
function aw_curl(string $url, bool $post = false, string $body = '', array $extra_hdrs = []): array {
    $ch = curl_init($url);
    $hdrs = array_merge([
        'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
        'Referer: https://aniwaves.ru/',
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json',
    ], $extra_hdrs);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $hdrs,
    ];
    if ($post) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $body;
        $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
        $opts[CURLOPT_HTTPHEADER] = $hdrs;
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw];
}

// Run TMDB + aniwaves + anikuro in parallel via curl_multi
$mh = curl_multi_init();

// TMDB handle
$tmdb_url = TMDB_API_URL . '/search/multi?' . http_build_query([
    'api_key'       => TMDB_API_KEY,
    'query'         => $q,
    'page'          => $page,
    'language'      => 'en-US',
    'include_adult' => 'false',
]);
$ch_tmdb = curl_init($tmdb_url);
curl_setopt_array($ch_tmdb, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_ENCODING       => '',
]);
curl_multi_add_handle($mh, $ch_tmdb);

// aniwaves handle
$ch_aw = curl_init('https://aniwaves.ru/ajax/anime/search');
curl_setopt_array($ch_aw, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_ENCODING       => '',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['keyword' => $q]),
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
        'Referer: https://aniwaves.ru/',
        'X-Requested-With: XMLHttpRequest',
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ],
]);
curl_multi_add_handle($mh, $ch_aw);

// anikuro handle — بحث مباشر في anikuro.ru
$ch_ak = curl_init('https://anikuro.ru/api/v1/discovery/search?' . http_build_query(['q' => $q, 'limit' => 8]));
curl_setopt_array($ch_ak, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
        'Referer: https://anikuro.ru/',
        'Accept: application/json',
    ],
]);
curl_multi_add_handle($mh, $ch_ak);

// Execute all three in parallel
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

$tmdb_raw = curl_multi_getcontent($ch_tmdb);
$aw_raw   = curl_multi_getcontent($ch_aw);
$ak_raw   = curl_multi_getcontent($ch_ak);
curl_multi_remove_handle($mh, $ch_tmdb);
curl_multi_remove_handle($mh, $ch_aw);
curl_multi_remove_handle($mh, $ch_ak);
curl_close($ch_tmdb);
curl_close($ch_aw);
curl_close($ch_ak);
curl_multi_close($mh);

// ── Parse TMDB ──────────────────────────────────────────────────────────────
$tmdb_data   = json_decode($tmdb_raw, true) ?? [];
// نحذف الأنمي (genre 16 + JP origin) من نتايج TMDB — يجيها من aniwaves
// نُبقي جميع نتائج TMDB بما فيها الأنمي — التفاصيل من TMDB والبث من watch.php
$tmdb_results = array_filter($tmdb_data['results'] ?? [], function($r) {
    return in_array($r['media_type'] ?? '', ['movie', 'tv'], true);
});
$total_pages = min((int)($tmdb_data['total_pages'] ?? 1), 50);

$out = [];
foreach ($tmdb_results as $r) {
    $t    = $r['media_type'];
    $n    = $r['title'] ?? $r['name'] ?? '';
    $sg   = $r['id'] . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($n));
    $yr   = substr($r['release_date'] ?? $r['first_air_date'] ?? '', 0, 4);
    $rt   = number_format((float)($r['vote_average'] ?? 0), 1);
    $post = $r['poster_path'] ? (TMDB_IMG . '/w185' . $r['poster_path']) : '';

    $out[] = [
        'source' => 'tmdb',
        'href'   => '/' . ($t === 'tv' ? 'tv-show' : 'movie') . '/' . $sg,
        'poster' => $post,
        'title'  => $n,
        'type'   => $t,   // 'movie' | 'tv'
        'year'   => $yr,
        'rating' => $rt,
    ];
}

// ── Parse aniwaves ───────────────────────────────────────────────────────────
$aw_data = json_decode($aw_raw, true);
$aw_html = $aw_data['result']['html'] ?? '';

if ($aw_html) {
    preg_match_all(
        '/<a class="item"\s+href="(\/watch\/[^"]+)"[^>]*>([\s\S]*?)<\/a>/U',
        $aw_html,
        $cards,
        PREG_SET_ORDER
    );

    foreach ($cards as $card) {
        $slug  = $card[1];                        // /watch/one-piece-81553
        $inner = $card[2];

        // aw_id = trailing number in slug
        if (!preg_match('/(\d+)$/', $slug, $m)) continue;
        $aw_id = (int)$m[1];

        // English title
        $en_title = '';
        if (preg_match('/data-jp="[^"]*"[^>]*>\s*([^<]+)\s*</', $inner, $m)) {
            $en_title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
        }
        if (!$en_title) continue;

        // Poster (upgrade to 200×280 thumbnail)
        $poster = '';
        if (preg_match('/<img[^>]+src="([^"]+)"/', $inner, $m)) {
            $poster = preg_replace('#/\d+x\d+/#', '/200x280/', $m[1]);
        }

        // Rating
        $rating = '';
        if (preg_match('/<i class="fas fa-star"><\/i>\s*([0-9.]+)/', $inner, $m)) {
            $rating = $m[1];
        }

        // Year
        $year = '';
        if (preg_match('/\b(19|20)\d{2}\b/', $inner, $m)) {
            $year = $m[0];
        }

        // Type label
        $type_label = 'Anime';
        if (preg_match('/<span class="dot">(TV|Movie|OVA|ONA|TV Special|Special)<\/span>/', $inner, $m)) {
            $type_label = $m[1];
        }

        // Href → صفحة التفاصيل (TMDB lookup بالعنوان)
        $_tmdb_file = __DIR__ . '/../.cache/tmdb_anime_ids/' . md5(strtolower(trim($en_title))) . '.json';
        $_tmdb_d    = (file_exists($_tmdb_file) && (time() - filemtime($_tmdb_file)) < 604800)
                        ? (json_decode(file_get_contents($_tmdb_file), true) ?? null)
                        : null;
        $href = ($_tmdb_d && isset($_tmdb_d['id']))
            ? '/tv-show/' . $_tmdb_d['slug']
            : '/tv-show/search-' . $aw_id;

        $out[] = [
            'source'     => 'aniwaves',
            'href'       => $href,
            'poster'     => $poster,
            'title'      => $en_title,
            'type'       => 'anime',
            'type_label' => $type_label,
            'year'       => $year,
            'rating'     => $rating,
        ];
    }
}

// ── Parse anikuro ────────────────────────────────────────────────────────────
$ak_data  = json_decode($ak_raw, true);
$ak_items = $ak_data['data']['items'] ?? [];

foreach ($ak_items as $item) {
    $ak_id  = (int)($item['id'] ?? 0);
    if (!$ak_id) continue;

    $title  = $item['title']['english'] ?? ($item['title']['romaji'] ?? ($item['title']['userPreferred'] ?? ''));
    if (!$title) continue;

    $poster = $item['images']['cover']  ?? ($item['coverImage']['large'] ?? '');
    $year   = (string)($item['seasonYear'] ?? '');
    $slug   = 'ak-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) . '-' . $ak_id;

    $_ak_tmdb_file = __DIR__ . '/../.cache/tmdb_anime_ids/' . md5(strtolower(trim($title))) . '.json';
    $_ak_tmdb_d    = (file_exists($_ak_tmdb_file) && (time() - filemtime($_ak_tmdb_file)) < 604800)
                       ? (json_decode(file_get_contents($_ak_tmdb_file), true) ?? null)
                       : null;
    $_ak_href = ($_ak_tmdb_d && isset($_ak_tmdb_d['id']))
        ? '/tv-show/' . $_ak_tmdb_d['slug']
        : '/tv-show/search-' . $ak_id;

    $out[] = [
        'source'     => 'anikuro',
        'href'       => $_ak_href,
        'poster'     => $poster,
        'title'      => $title,
        'type'       => 'anime',
        'type_label' => 'AniKuro',
        'year'       => $year,
        'rating'     => '',
    ];
}

echo json_encode([
    'results'     => $out,
    'total_pages' => $total_pages,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
