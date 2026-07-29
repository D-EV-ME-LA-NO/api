<?php
/**
 * api/anikuro/browse.php — تصفح كتالوج anikuro.ru
 * GET: ?page=1
 * Response: { ok, page, results:[{ak_id,title,poster,episodes,status,year,slug}] }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('AK_UA_BR',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('AK_BASE_BR',  'https://anikuro.ru/api/v1');
define('AK_CACHE_BR', __DIR__ . '/../../.cache/anikuro');

@mkdir(AK_CACHE_BR, 0755, true);

$page = max(1, (int)($_GET['page'] ?? 1));

$cache_file = AK_CACHE_BR . '/browse_p' . $page . '.json';
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 1800) {
    echo file_get_contents($cache_file);
    exit;
}

$url = AK_BASE_BR . '/discovery/trending?' . http_build_query(['page' => $page, 'limit' => 24]);
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        'User-Agent: ' . AK_UA_BR,
        'Referer: https://anikuro.ru/',
        'Accept: application/json',
    ],
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$raw) {
    echo json_encode(['ok' => false, 'results' => [], 'error' => 'anikuro unreachable (' . $code . ')']);
    exit;
}

$d         = json_decode($raw, true);
$raw_items = $d['data']['items'] ?? ($d['data'] ?? []);
if (!is_array($raw_items)) $raw_items = [];

$meta_dir = AK_CACHE_BR . '/meta';
@mkdir($meta_dir, 0755, true);

$items = [];
foreach ($raw_items as $item) {
    $ak_id  = (int)($item['id'] ?? 0);
    if (!$ak_id) continue;

    $title  = $item['title']['english']       ?? ($item['title']['romaji']        ?? ($item['title']['userPreferred'] ?? ''));
    $poster = $item['images']['cover']        ?? ($item['coverImage']['large']     ?? ($item['images']['thumbnail']    ?? ''));
    $ep_cnt = $item['episodes']               ?? null;
    $status = $item['status']                 ?? '';
    $year   = $item['seasonYear']             ?? null;
    $slug   = 'ak-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($title ?: 'anime')) . '-' . $ak_id;

    $entry  = [
        'ak_id'    => $ak_id,
        'title'    => $title,
        'poster'   => $poster,
        'episodes' => $ep_cnt,
        'status'   => $status,
        'year'     => $year,
        'slug'     => $slug,
    ];
    $items[] = $entry;

    // Cache individual meta for watch page
    $mf = $meta_dir . '/ak_' . $ak_id . '.json';
    if (!file_exists($mf)) file_put_contents($mf, json_encode($entry));
}

$out = json_encode(['ok' => true, 'page' => $page, 'results' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents($cache_file, $out);
echo $out;
