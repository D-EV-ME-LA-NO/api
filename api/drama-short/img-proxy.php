<?php
/**
 * api/drama-short/img-proxy.php
 * Proxies drama poster images that have hotlink protection.
 * GET: ?url=<encoded_image_url>
 */
require_once __DIR__ . '/../../config.php';

$url = trim($_GET['url'] ?? '');
if (!$url) { http_response_code(400); exit; }

// Validate URL
if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(400); exit; }

$host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

// Only proxy image domains used by drama providers
$allowed_suffixes = [
    'crazytalkai.com', 'narto-drama.com', 'hnivj.com',
    'dramabite.com', 'dramabox.com', 'dramawave.com',
    'bibishort.com', 'bilitv.com', 'shortmax.com',
    'goodshort.com', 'reelshort.com', 'flextv.com',
    'oss-cn-shenzhen.aliyuncs.com', 'aliyuncs.com',
    'cloudfront.net', 'akamaized.net', 'fastly.net',
    'imgix.net', 'shortdramatv.com', 'joyreels.com',
    'reelbuzz.com', 'netshort.com', 'moboreels.com',
];
$allowed = false;
foreach ($allowed_suffixes as $suffix) {
    if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
        $allowed = true;
        break;
    }
}
if (!$allowed) { http_response_code(403); exit; }

// Check cache
$cache_dir  = __DIR__ . '/../../.cache/drama-short/img';
@mkdir($cache_dir, 0755, true);
$cache_file = $cache_dir . '/' . md5($url);

if (is_file($cache_file) && (time() - filemtime($cache_file)) < 86400 * 3) {
    $meta_file = $cache_file . '.mime';
    $mime      = is_file($meta_file) ? file_get_contents($meta_file) : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=259200');
    readfile($cache_file);
    exit;
}

// Fetch
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_ENCODING       => '',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 4,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        'Accept-Encoding: gzip, deflate, br',
        'Referer: https://narto-drama.com/',
    ],
]);
$body  = curl_exec($ch);
$code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($code !== 200 || !$body) {
    http_response_code(404);
    exit;
}

// Determine MIME
$mime = 'image/jpeg';
if (str_contains($ctype, 'image/')) {
    $mime = explode(';', $ctype)[0];
}

// Cache to disk
@file_put_contents($cache_file, $body);
@file_put_contents($cache_file . '.mime', $mime);

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=259200');
echo $body;
