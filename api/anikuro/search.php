<?php
/**
 * api/anikuro/search.php — بحث في anikuro.ru
 * GET: ?q=QUERY
 * Response: { ok, results:[{ak_id,title,poster,year,slug,href}] }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('AK_UA_S',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('AK_BASE_S',  'https://anikuro.ru/api/v1');
define('AK_CACHE_S', __DIR__ . '/../../.cache/anikuro');

@mkdir(AK_CACHE_S, 0755, true);

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['ok' => false, 'results' => []]);
    exit;
}

$cache_file = AK_CACHE_S . '/search_' . md5($q) . '.json';
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
    echo file_get_contents($cache_file);
    exit;
}

$ch = curl_init(AK_BASE_S . '/discovery/search?' . http_build_query(['q' => $q, 'limit' => 10]));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        'User-Agent: ' . AK_UA_S,
        'Referer: https://anikuro.ru/',
        'Accept: application/json',
    ],
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$raw) {
    echo json_encode(['ok' => false, 'results' => []]);
    exit;
}

$d         = json_decode($raw, true);
$raw_items = $d['data']['items'] ?? [];

$results = [];
foreach ($raw_items as $item) {
    $ak_id  = (int)($item['id'] ?? 0);
    if (!$ak_id) continue;

    $title  = $item['title']['english'] ?? ($item['title']['romaji'] ?? ($item['title']['userPreferred'] ?? ''));
    $poster = $item['images']['cover']  ?? ($item['coverImage']['large'] ?? '');
    $year   = $item['seasonYear']       ?? null;
    $slug   = 'ak-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($title ?: 'anime')) . '-' . $ak_id;

    $results[] = [
        'source'     => 'anikuro',
        'href'       => '/anime-watch/' . $slug . '/1',
        'poster'     => $poster,
        'title'      => $title,
        'type'       => 'anime',
        'type_label' => 'AniKuro',
        'year'       => (string)($year ?? ''),
        'rating'     => '',
        'ak_id'      => $ak_id,
        'slug'       => $slug,
    ];
}

$out = json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents($cache_file, $out);
echo $out;
