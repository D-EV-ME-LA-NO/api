<?php
/**
 * api/anikuro/proxy.php
 * Proxies m3u8 manifests and .ts segments from freevideoupload.xyz / ninstream / anikuro
 * with the correct Referer headers so Cloudflare doesn't block them.
 * GET: ?url=ENCODED_URL
 */
$raw_url = trim($_GET['url'] ?? '');
if (!$raw_url) { http_response_code(400); exit('missing url'); }

// Whitelist allowed hosts for security
$host = parse_url($raw_url, PHP_URL_HOST) ?? '';
$allowed = ['freevideoupload.xyz', 'ninstream.com', 'anikuro.ru'];
if (!array_filter($allowed, fn($h) => str_ends_with($host, $h))) {
    http_response_code(403); exit('host not allowed');
}

$ua  = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';
$ref = 'https://anikuro.ru/';

$ch = curl_init($raw_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_ENCODING       => '',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: '  . $ua,
        'Referer: '     . $ref,
        'Accept: */*',
        'Origin: https://anikuro.ru',
    ],
]);

$body    = curl_exec($ch);
$code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ct      = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
curl_close($ch);

if ($code !== 200 || $body === false) {
    http_response_code(502);
    exit('upstream error ' . $code);
}

// For m3u8 manifests: rewrite relative .ts/.m3u8 segment URLs so they pass through proxy
if (str_contains($ct, 'mpegurl') || str_contains($raw_url, '.m3u8')) {
    $base_url = substr($raw_url, 0, strrpos($raw_url, '/') + 1);
    // Rewrite each non-comment, non-empty line that is a relative URL
    $lines = explode("\n", $body);
    foreach ($lines as &$line) {
        $l = trim($line);
        if ($l === '' || str_starts_with($l, '#')) continue;
        // Absolute URL → proxy directly
        if (str_starts_with($l, 'http')) {
            $line = '/api/anikuro/proxy.php?url=' . urlencode($l);
        }
        // Relative URL → resolve against base then proxy
        elseif (!str_starts_with($l, '/')) {
            $line = '/api/anikuro/proxy.php?url=' . urlencode($base_url . $l);
        } else {
            $line = '/api/anikuro/proxy.php?url=' . urlencode('https://' . parse_url($raw_url, PHP_URL_HOST) . $l);
        }
    }
    unset($line);
    $body = implode("\n", $lines);
    $ct   = 'application/vnd.apple.mpegurl';
}

header('Content-Type: '   . $ct);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');
echo $body;
