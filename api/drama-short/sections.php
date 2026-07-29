<?php
/**
 * api/drama-short/sections.php — Proxy for narto-drama.com sections + search
 * GET: ?provider=bibishort&lang=en-US&q=query (q is optional for search)
 *      ?lang=en-US  (no provider → returns providers list only)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

define('ND_UA',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('ND_BASE',  'https://narto-drama.com');
define('ND_CACHE', __DIR__ . '/../../.cache/drama-short');

@mkdir(ND_CACHE, 0755, true);

$provider = preg_replace('/[^a-z0-9_-]/i', '', $_GET['provider'] ?? 'bibishort');
$lang     = preg_replace('/[^a-z0-9_-]/i', '', $_GET['lang'] ?? 'en-US');
$query    = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));

// Cache key
$cache_ttl  = $query ? 300 : 1800; // 5 min for search, 30 min for browse
$cache_key  = ND_CACHE . '/sections_' . md5($provider . $lang . $query . $page) . '.json';

if (!$query && is_file($cache_key) && (time() - filemtime($cache_key)) < $cache_ttl) {
    $data = json_decode(file_get_contents($cache_key), true);
    if ($data) { echo json_encode($data); exit; }
}

// Build URL
$params = [
    'provider'    => $provider,
    'lang'        => $lang,
    'target_lang' => $lang,
    '_cb'         => (string)(time() * 1000),
];
if ($query) $params['q'] = $query;
if ($page > 1) $params['page'] = $page;

$url = ND_BASE . '/home/providers/sections?' . http_build_query($params);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_ENCODING       => '',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: '      . ND_UA,
        'Accept: */*',
        'X-Requested-With: XMLHttpRequest',
        'Referer: '         . ND_BASE . '/?lang=' . $lang . '&tab-provider=' . $provider,
        'Accept-Language: ar-IQ,ar;q=0.9,en-US;q=0.8,en;q=0.7',
    ],
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$raw) {
    echo json_encode(['ok' => false, 'error' => 'Failed to fetch sections', 'code' => $code]);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid response']);
    exit;
}

// Cache browse results (not search)
if (!$query) {
    @file_put_contents($cache_key, json_encode($data));
}

echo json_encode($data);
