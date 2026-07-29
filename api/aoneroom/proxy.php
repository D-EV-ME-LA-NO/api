<?php
/**
 * api/aoneroom/proxy.php — بروكسي بث MovieBox (hakunaymatata CDN)
 *
 * يعيد توجيه طلبات MP4 مع الهيدرات الصحيحة التي يتطلبها CDN:
 *   Origin/Referer: https://themoviebox.xyz
 *   Sec-Fetch-Mode: cors  (لا no-cors)
 *   Range + If-Range مُعاد توجيههما بالكامل
 *
 * الاستخدام: /api/aoneroom/proxy.php?url=<encoded_url>
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ── CORS ──────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, If-Range, Content-Type');
header('Access-Control-Expose-Headers: Content-Range, Content-Length, Accept-Ranges');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Allowlist: فقط CDN domains الخاصة بـ MovieBox ───────────────────────────
const MB_ALLOWED = [
    'hakunaymatata.com',
];

function mb_allowed_host(string $host): bool {
    $host = strtolower($host);
    foreach (MB_ALLOWED as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) return true;
    }
    return false;
}

function mb_public_ip(string $host): bool {
    $ip = @gethostbyname($host);
    if (!$ip || $ip === $host) return false;
    return filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// ── تحقق من الرابط ────────────────────────────────────────────────────────────
$url = trim($_GET['url'] ?? '');
if (!$url) { http_response_code(400); echo '{"error":"missing url"}'; exit; }
if (strpos($url, '%') !== false) $url = urldecode($url);
if (!preg_match('#^https://#i', $url)) { http_response_code(400); echo '{"error":"https only"}'; exit; }

$host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
if (!$host || !mb_allowed_host($host)) { http_response_code(403); echo '{"error":"domain not allowed"}'; exit; }
if (!mb_public_ip($host))              { http_response_code(403); echo '{"error":"private ip blocked"}'; exit; }

// ── الهيدرات التي يتطلبها CDN بالضبط (من curl المُصدَّر) ────────────────────
const MB_UA     = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';
const MB_ORIGIN = 'https://themoviebox.xyz';
const MB_REF    = 'https://themoviebox.xyz/';

$curlHeaders = [
    'User-Agent: '       . MB_UA,
    'Accept: */*',
    'Accept-Language: ar-IQ,ar;q=0.9,en-IQ;q=0.8,en;q=0.7,en-US;q=0.6',
    'Accept-Encoding: identity;q=1, *;q=0',
    'Origin: '           . MB_ORIGIN,
    'Referer: '          . MB_REF,
    'Sec-Fetch-Site: cross-site',
    'Sec-Fetch-Mode: cors',
    'Sec-Fetch-Dest: video',
    'sec-ch-ua: "Chromium";v="137", "Not/A)Brand";v="24"',
    'sec-ch-ua-mobile: ?1',
    'sec-ch-ua-platform: "Android"',
    'Connection: keep-alive',
];

// ── Range ─────────────────────────────────────────────────────────────────────
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range && preg_match('/^bytes=\d*-\d*$/', $range)) {
    $curlHeaders[] = 'Range: ' . $range;
}

// ── If-Range (مهم لـ seek السريع) ────────────────────────────────────────────
$ifRange = $_SERVER['HTTP_IF_RANGE'] ?? '';
if ($ifRange) {
    $curlHeaders[] = 'If-Range: ' . $ifRange;
}

// ── curl ──────────────────────────────────────────────────────────────────────
$ch = curl_init($url);

$statusSent = false;

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_HTTPHEADER     => $curlHeaders,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_NOBODY         => ($_SERVER['REQUEST_METHOD'] === 'HEAD'),
    CURLOPT_BUFFERSIZE     => 256 * 1024,
]);

// ── إعادة إرسال هيدرات CDN للمتصفح ──────────────────────────────────────────
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$statusSent) {
    $len  = strlen($line);
    $line = rtrim($line);

    if (preg_match('#^HTTP/\S+ (\d+)#i', $line, $m)) {
        $code = (int)$m[1];
        if ($code >= 300 && $code < 400) {
            http_response_code(502);
            echo '{"error":"redirect blocked"}';
            exit;
        }
        http_response_code($code);
        $statusSent = true;
        return $len;
    }

    if (!$line) return $len;

    $allow = ['content-type','content-length','content-range','accept-ranges',
              'last-modified','etag','cache-control','expires'];
    $key   = strtolower(explode(':', $line)[0] ?? '');
    if (in_array($key, $allow, true)) header($line, false);

    return $len;
});

// ── بث البيانات مباشرةً ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    while (ob_get_level()) ob_end_clean();

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
        if (connection_status() !== CONNECTION_NORMAL) return -1;
        echo $data;
        flush();
        return strlen($data);
    });
}

$GLOBALS['_mb_ch'] = $ch;
curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
curl_close($ch);

if ($errno && !$statusSent) {
    http_response_code(502);
    echo json_encode(['error' => 'proxy failed', 'detail' => $error]);
}
