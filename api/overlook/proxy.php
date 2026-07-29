<?php
// api/overlook/proxy.php
// Local re-proxy for stream.overlook.cx/v1/proxy.
//
// Overlook's own /v1/proxy endpoint only sends
// "Access-Control-Allow-Origin: https://overlook.cx", so browsers block it
// when our frontend (a different origin) requests it directly — even though
// server-side curl works fine. This proxy fetches it here (no CORS applies
// server-side) and re-serves the response with open CORS, rewriting any
// nested m3u8 lines (which overlook returns as paths relative to
// stream.overlook.cx) to route back through this same proxy.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

const OL_PROXY_HOST = 'stream.overlook.cx';

$url = $_GET['url'] ?? '';
if (!$url) { http_response_code(400); exit('missing url'); }

$host = parse_url($url, PHP_URL_HOST);
if (!$host || strcasecmp($host, OL_PROXY_HOST) !== 0) {
    http_response_code(403);
    exit('blocked: host not allowed');
}

$ua = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';

$req_headers = [
    'User-Agent: ' . $ua,
    'Accept: */*',
    'Origin: https://overlook.cx',
    'Referer: https://overlook.cx/',
];
if (!empty($_SERVER['HTTP_RANGE'])) $req_headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => $req_headers,
]);

$raw       = curl_exec($ch);
$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hdr_size  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$ct        = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if (!$raw || $http_code >= 400) {
    http_response_code($http_code ?: 502);
    exit('upstream error: ' . $http_code);
}

$headers_raw = substr($raw, 0, $hdr_size);
$body        = substr($raw, $hdr_size);

$ct_lower = strtolower($ct);
$is_m3u8  = str_contains($ct_lower, 'mpegurl')
         || str_starts_with(ltrim($body), '#EXTM3U');

if ($is_m3u8) {
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');

    $self  = '/api/overlook/proxy.php';
    $lines = explode("\n", $body);
    $out   = [];

    foreach ($lines as $raw_line) {
        $line = rtrim($raw_line);

        if ($line === '' || str_starts_with($line, '#')) {
            $out[] = $line;
            continue;
        }

        // Overlook returns nested lines as paths relative to stream.overlook.cx
        // (e.g. "/v1/proxy?data=..."), or occasionally absolute URLs.
        $abs = str_starts_with($line, '/')
            ? 'https://' . OL_PROXY_HOST . $line
            : $line;

        $abs_host = parse_url($abs, PHP_URL_HOST);
        $out[] = ($abs_host && strcasecmp($abs_host, OL_PROXY_HOST) === 0)
            ? $self . '?url=' . urlencode($abs)
            : $abs;
    }

    echo implode("\n", $out);
    exit;
}

// TS / binary segment: stream back to player
foreach (['Content-Type', 'Content-Length', 'Content-Range', 'Accept-Ranges'] as $h) {
    if (preg_match('/^' . preg_quote($h, '/') . ':\s*(.+)$/im', $headers_raw, $m)) {
        header($h . ': ' . trim($m[1]));
    }
}
if ($http_code === 206) http_response_code(206);

echo $body;
