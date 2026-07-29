<?php
/**
 * api/torrentio-stream.php — بروكسي بث خاص بسيرفرات torrentio
 *
 * الاستخدام:
 *   /api/torrentio-stream.php?url=<encoded_full_url>
 *   /api/torrentio-stream.php?session=<session_token>   (s.torrentio.to فقط)
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type');
header('Access-Control-Expose-Headers: Content-Range, Content-Length, Accept-Ranges');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── نطاقات مسموح ببروكستها ────────────────────────────────────────────────────
const TORRENT_ALLOWED = [
    // torrentio
    's.torrentio.to',
    'torrentio.to',
    'fmovies4u.com',
    'vidapi.to',
    'vidnest.to',
    // debrid services
    'api.real-debrid.com',
    'api.alldebrid.com',
    'api.premiumize.me',
    'api.torbox.app',
    'debridlink.com',
    'api.debridlink.com',
    // Pluto TV CDN (torrentio redirects here for pluto streams)
    'pluto.tv',
    'plutotv.net',
    'boltdns.net',
    'brightcove.net',
];

// نطاقات لا تحتاج Origin/Referer من vidplay (مثل Pluto CDN)
const NO_VIDPLAY_ORIGIN = ['pluto.tv', 'plutotv.net', 'brightcove.net', 'boltdns.net'];

const STREAM_UA      = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';
const STREAM_REFERER = 'https://stream.vidplay.to/';
const STREAM_ORIGIN  = 'https://stream.vidplay.to';

function isAllowedHost(string $host): bool {
    $host = strtolower($host);
    foreach (TORRENT_ALLOWED as $a) {
        if ($host === $a || str_ends_with($host, '.' . $a)) return true;
    }
    return false;
}

function isPublicIp(string $host): bool {
    $ip = @gethostbyname($host);
    if (!$ip || $ip === $host) return false;
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    return (bool)filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// ── بناء رابط الهدف ───────────────────────────────────────────────────────────
$session = trim($_GET['session'] ?? '');
$rawUrl  = trim($_GET['url']     ?? '');

if ($session) {
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $session)) {
        http_response_code(400); echo json_encode(['error' => 'invalid session']); exit;
    }
    $targetUrl = 'https://s.torrentio.to/server/stream?session=' . $session;
} elseif ($rawUrl) {
    $targetUrl = strpos($rawUrl, '%') !== false ? urldecode($rawUrl) : $rawUrl;
} else {
    http_response_code(400); echo json_encode(['error' => 'missing session or url']); exit;
}

if (!preg_match('#^https://#i', $targetUrl)) {
    http_response_code(400); echo json_encode(['error' => 'https only']); exit;
}

$parsedHost = strtolower(parse_url($targetUrl, PHP_URL_HOST) ?? '');
if (!$parsedHost || !isAllowedHost($parsedHost)) {
    http_response_code(403); echo json_encode(['error' => 'domain not allowed', 'host' => $parsedHost]); exit;
}
if (!isPublicIp($parsedHost)) {
    http_response_code(403); echo json_encode(['error' => 'private ip rejected']); exit;
}

// ── إعداد cURL ────────────────────────────────────────────────────────────────
// هل النطاق الحالي يحتاج Origin من vidplay؟
function needsVidplayOrigin(string $host): bool {
    foreach (NO_VIDPLAY_ORIGIN as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) return false;
    }
    return true;
}

$curlHeaders = [
    'User-Agent: '    . STREAM_UA,
    'Accept: */*',
    'Accept-Language: ar-IQ,ar;q=0.9,en;q=0.7',
    'sec-ch-ua: "Chromium";v="137", "Not/A)Brand";v="24"',
    'sec-ch-ua-mobile: ?1',
    'sec-ch-ua-platform: "Android"',
    'Sec-Fetch-Dest: empty',
    'Sec-Fetch-Mode: cors',
    'Sec-Fetch-Site: cross-site',
];
if (needsVidplayOrigin($parsedHost)) {
    $curlHeaders[] = 'Origin: '  . STREAM_ORIGIN;
    $curlHeaders[] = 'Referer: ' . STREAM_REFERER;
}

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
if ($rangeHeader && preg_match('/^bytes=\d*-\d*$/', $rangeHeader)) {
    $curlHeaders[] = 'Range: ' . $rangeHeader;
}

$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,   // نجمع الجسم دائماً لفحص النوع
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 6,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 12,
    CURLOPT_HTTPHEADER     => $curlHeaders,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_ENCODING       => 'gzip, deflate, br',
    CURLOPT_NOBODY         => ($_SERVER['REQUEST_METHOD'] === 'HEAD'),
]);

// ── اجمع response headers ──────────────────────────────────────────────────────
$respHeaders = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$respHeaders) {
    $len  = strlen($line);
    $line = rtrim($line);
    if (preg_match('#^HTTP/#i', $line)) {
        $respHeaders = []; // reset on redirect
        return $len;
    }
    $pos = strpos($line, ':');
    if ($pos !== false) {
        $k = strtolower(trim(substr($line, 0, $pos)));
        $v = trim(substr($line, $pos + 1));
        $respHeaders[$k] = $v;
    }
    return $len;
});

$body     = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errno    = curl_errno($ch);
$error    = curl_error($ch);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

// ── تحقق من نطاق الـ redirect النهائي (SSRF) ─────────────────────────────────
if ($finalUrl && $finalUrl !== $targetUrl) {
    $finalHost = strtolower(parse_url($finalUrl, PHP_URL_HOST) ?? '');
    if (!$finalHost || !isAllowedHost($finalHost) || !isPublicIp($finalHost)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'redirect target not allowed', 'host' => $finalHost]);
        exit;
    }
}

if ($errno || $httpCode < 200 || $httpCode >= 400) {
    http_response_code($httpCode ?: 502);
    header('Content-Type: application/json');
    echo json_encode(['error' => $error ?: "upstream $httpCode"]);
    exit;
}

// ── هل هو m3u8؟ (بالـ content-type أو المحتوى) ───────────────────────────────
$ctype  = strtolower($respHeaders['content-type'] ?? '');
$isM3u8 = str_contains($ctype, 'mpegurl')
       || str_contains($ctype, 'm3u8')
       || (is_string($body) && str_starts_with(ltrim($body), '#EXTM3U'));

if ($isM3u8 && $body) {
    // ── إعادة كتابة روابط الـ playlist لتمر عبر البروكسي ─────────────────────
    $baseDir  = preg_replace('#\?.*$#', '', $finalUrl ?: $targetUrl);
    $baseDir  = preg_replace('#[^/]+$#', '', $baseDir);
    $baseHost = (parse_url($finalUrl ?: $targetUrl, PHP_URL_SCHEME) ?: 'https')
              . '://' . (parse_url($finalUrl ?: $targetUrl, PHP_URL_HOST) ?: $parsedHost);

    $selfBase = '/api/torrentio-stream.php';
    $lines    = explode("\n", rtrim((string)$body));
    $out      = [];

    foreach ($lines as $line) {
        $line = rtrim($line);

        // أعد كتابة URI داخل tags مثل EXT-X-MEDIA:URI="..."
        if (str_starts_with($line, '#') && preg_match('/URI="([^"]+)"/', $line, $m)) {
            $uri    = $m[1];
            $absUri = preg_match('#^https?://#', $uri) ? $uri : $baseHost . '/' . ltrim($uri, '/');
            $line   = str_replace('URI="' . $m[1] . '"',
                                  'URI="' . $selfBase . '?url=' . rawurlencode($absUri) . '"',
                                  $line);
            $out[] = $line;
            continue;
        }

        if ($line === '' || $line[0] === '#') { $out[] = $line; continue; }

        // رابط segment أو child playlist
        if (preg_match('#^https?://#', $line)) {
            $absUrl = $line;
        } elseif (str_starts_with($line, '/')) {
            $absUrl = $baseHost . $line;
        } else {
            $absUrl = $baseDir . $line;
        }

        $out[] = $selfBase . '?url=' . rawurlencode($absUrl);
    }

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');
    echo implode("\n", $out);
    exit;
}

// ── ليس m3u8 — بثّه مباشرة (فيديو / صوت) ────────────────────────────────────
http_response_code($httpCode);

$passthroughHeaders = ['content-type','content-length','content-range','accept-ranges','cache-control','etag'];
foreach ($passthroughHeaders as $h) {
    if (isset($respHeaders[$h])) {
        header($h . ': ' . $respHeaders[$h], false);
    }
}

// إن كان الجسم صغيراً (buffered) أرسله مباشرة
if (is_string($body) && strlen($body) > 0) {
    while (ob_get_level()) ob_end_clean();
    echo $body;
    flush();
    exit;
}

// للملفات الكبيرة: أعد الطلب بـ streaming
while (ob_get_level()) ob_end_clean();
$ch2 = curl_init($targetUrl);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 6,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_CONNECTTIMEOUT => 12,
    CURLOPT_HTTPHEADER     => $curlHeaders,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
        if (connection_status() !== CONNECTION_NORMAL) return -1;
        echo $data; flush();
        return strlen($data);
    },
]);
curl_exec($ch2);
curl_close($ch2);
