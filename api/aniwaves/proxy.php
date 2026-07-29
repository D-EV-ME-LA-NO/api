<?php
/**
 * api/aniwaves/proxy.php — HLS proxy for EchoVideo CDN
 *
 * Allowed upstream hosts: echovideo CDN domains
 * Sets Referer: aniwaves.ru/ required by the CDN
 * Rewrites m3u8 relative URIs through self
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// Known EchoVideo CDN hosts — wildcard via suffix match, add root domains here
define('AW_CDN_ROOTS', [
    'echovideo.to',
    'echovideo.ru',
    'dpopdrop89.store',
    'roburnt10.store',
]);
define('AW_UA',      'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('AW_REFERER', 'https://play.echovideo.ru/');
define('AW_ORIGIN',  'https://play.echovideo.ru');

$url = trim($_GET['url'] ?? '');
if (!$url) { http_response_code(400); exit('missing url'); }

if (!preg_match('#^https?://#i', $url)) { http_response_code(403); exit('scheme not allowed'); }

$host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

// Allow exact match OR any subdomain of known CDN root domains
$allowed = false;
foreach (AW_CDN_ROOTS as $root) {
    if ($host === $root || str_ends_with($host, '.' . $root)) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    exit('host not allowed: ' . $host);
}

// Block private IPs
if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.|::1|localhost)/i', $host)) {
    http_response_code(403); exit('private host blocked');
}

// ── Fetch ─────────────────────────────────────────────────────────────────────
$req_hdrs = [
    'User-Agent: ' . AW_UA,
    'Origin: '     . AW_ORIGIN,
    'Referer: '    . AW_REFERER,
    'Accept: */*',
];
if (!empty($_SERVER['HTTP_RANGE'])) {
    $req_hdrs[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => $req_hdrs,
]);

$raw       = curl_exec($ch);
$code      = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hdr_size  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$ct_raw    = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
curl_close($ch);

if (!$raw || $code >= 400) {
    http_response_code(502);
    exit('upstream error: ' . $code);
}

$resp_hdrs = substr($raw, 0, $hdr_size);
$body      = substr($raw, $hdr_size);

// ── Detect m3u8 ───────────────────────────────────────────────────────────────
$ct      = strtolower($ct_raw);
$is_m3u8 = str_contains($ct, 'mpegurl')
         || str_contains(strtolower($url), '.m3u8')
         || str_starts_with(ltrim($body), '#EXTM3U');

header('Access-Control-Allow-Origin: *');

// ── M3U8: rewrite relative URIs through self ──────────────────────────────────
if ($is_m3u8) {
    $pu     = parse_url($final_url);
    $scheme = $pu['scheme'] ?? 'https';
    $origin = $scheme . '://' . ($pu['host'] ?? $host);
    $dir    = rtrim(dirname($pu['path'] ?? '/'), '/') . '/';
    $base   = $origin . $dir;

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');

    $self  = '/api/aniwaves/proxy.php';
    $lines = explode("\n", $body);
    $out   = [];

    foreach ($lines as $raw_line) {
        $line = rtrim($raw_line);
        if ($line === '') { $out[] = ''; continue; }

        if (str_starts_with($line, '#')) {
            $line = preg_replace_callback(
                '/\bURI="([^"]+)"/i',
                fn($m) => 'URI="' . aw_proxy_uri($m[1], $scheme, $origin, $base, $self) . '"',
                $line
            );
            $out[] = $line;
            continue;
        }

        $out[] = aw_proxy_uri($line, $scheme, $origin, $base, $self);
    }

    echo implode("\n", $out);
    exit;
}

// ── Binary segment: stream back ───────────────────────────────────────────────
foreach (['Content-Type', 'Content-Length', 'Content-Range', 'Accept-Ranges'] as $h) {
    if (preg_match('/^' . preg_quote($h, '/') . ':\s*(.+)$/im', $resp_hdrs, $m)) {
        header($h . ': ' . trim($m[1]));
    }
}
if (!str_contains(strtolower($ct_raw), 'video') && !str_contains(strtolower($ct_raw), 'octet')) {
    header('Content-Type: video/MP2T');
}
if (preg_match('/^HTTP\/[12]\S*\s+206/m', $resp_hdrs)) http_response_code(206);
echo $body;

// ── Helper ────────────────────────────────────────────────────────────────────
function aw_proxy_uri(string $uri, string $scheme, string $origin, string $base, string $self): string
{
    if (preg_match('#^https?://#i', $uri)) {
        $abs = $uri;
    } elseif (str_starts_with($uri, '//')) {
        $abs = $scheme . ':' . $uri;
    } elseif (str_starts_with($uri, '/')) {
        $abs = $origin . $uri;
    } else {
        $abs = $base . $uri;
    }

    // Only proxy known CDN roots
    $h = strtolower(parse_url($abs, PHP_URL_HOST) ?? '');
    $ok = false;
    foreach (AW_CDN_ROOTS as $root) {
        if ($h === $root || str_ends_with($h, '.' . $root)) { $ok = true; break; }
    }
    if (!$ok) return $uri;

    return $self . '?url=' . urlencode($abs);
}
