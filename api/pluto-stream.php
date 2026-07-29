<?php
/**
 * api/pluto-stream.php — بروكسي بث مخصص لسيرفر Pluto (torrentio provider "vpro778")
 *
 * ليش بروكسي خاص وليس عبر torrentio-stream.php العام:
 *  1) السيجمنتات الحقيقية (بيانات MPEG-TS صحيحة تبدأ بـ 0x47) يرجعها الـ CDN
 *     بترويسة Content-Type: image/jpeg (تمويه). لازم نصحح الترويسة حتى المشغلات الصارمة ما ترفض السيجمنت.
 *  2) السيجمنتات كبيرة الحجم (~3+ ميجا لكل 8 ثواني) — البروكسي العام يخزن الجسم
 *     كامل بالذاكرة (CURLOPT_RETURNTRANSFER) قبل ما يرسله، وهذا يخاطر بتجاوز
 *     memory_limit (128M) خصوصاً مع عدة طلبات متزامنة، فيسبب توقف صامت/تعليق
 *     بالتشغيل. هذا البروكسي يبث (stream) البيانات مباشرة بدون تخزينها كاملة.
 *
 * الاستخدام: /api/pluto-stream.php?url=<encoded_full_url>
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type');
header('Access-Control-Expose-Headers: Content-Range, Content-Length, Accept-Ranges');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── نطاقات مسموح ببروكستها ────────────────────────────────────────────────────
// s.torrentio.to: الحافة (edge) التي يمر عبرها بث Pluto فعلياً (vEdge)
// pluto.tv/plutotv.net/boltdns.net/brightcove.net: CDN الحقيقي لو صار تحويل مباشر له
const PLUTO_ALLOWED = [
    's.torrentio.to',
    'pluto.tv',
    'plutotv.net',
    'boltdns.net',
    'brightcove.net',
];

const STREAM_UA      = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';
const STREAM_REFERER = 'https://stream.vidplay.to/';
const STREAM_ORIGIN  = 'https://stream.vidplay.to';

function isAllowedHost(string $host): bool {
    $host = strtolower($host);
    foreach (PLUTO_ALLOWED as $a) {
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
$rawUrl = trim($_GET['url'] ?? '');
if (!$rawUrl) { http_response_code(400); echo json_encode(['error' => 'missing url']); exit; }
$targetUrl = strpos($rawUrl, '%') !== false ? urldecode($rawUrl) : $rawUrl;

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

// ── تحقق من هدف التحويل (SSRF) قبل تحميل أي بيانات ────────────────────────────
// نتبع أي redirect يدوياً (بدل FOLLOWLOCATION) عشان نتحقق من كل قفزة قبل ما
// نبدأ ببث البيانات — منعاً لتسريب بيانات من نطاق غير مسموح بعد ما نكون بدأنا
// الإرسال للمتصفح (لحظتها ما نكدر نلغي الاستجابة).
function resolveFinalUrl(string $url, array $headers, int $maxHops = 6): array {
    for ($i = 0; $i < $maxHops; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_HEADER         => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $loc  = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($code >= 300 && $code < 400 && $loc) {
            $host = strtolower(parse_url($loc, PHP_URL_HOST) ?? '');
            if (!$host || !isAllowedHost($host) || !isPublicIp($host)) {
                return ['ok' => false, 'error' => 'redirect target not allowed', 'host' => $host];
            }
            $url = $loc;
            continue;
        }
        // ليس تحويل (أو الطلب فشل) — هذا آخر رابط، الطلب الحقيقي (GET) سيتعامل مع أي خطأ
        return ['ok' => true, 'url' => $url];
    }
    return ['ok' => false, 'error' => 'too many redirects'];
}

$curlHeaders = [
    'User-Agent: '    . STREAM_UA,
    'Accept: */*',
    'Accept-Language: ar-IQ,ar;q=0.9,en;q=0.7',
    'Origin: '  . STREAM_ORIGIN,
    'Referer: ' . STREAM_REFERER,
];

$resolved = resolveFinalUrl($targetUrl, $curlHeaders);
if (!$resolved['ok']) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => $resolved['error'], 'host' => $resolved['host'] ?? null]);
    exit;
}
$finalTargetUrl = $resolved['url'];

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
if ($rangeHeader && preg_match('/^bytes=\d*-\d*$/', $rangeHeader)) {
    $curlHeaders[] = 'Range: ' . $rangeHeader;
}

// ── الطلب الحقيقي: نبث المحتوى مباشرة بدون تخزينه كاملاً بالذاكرة ─────────────
// نحدد أول شي إذا كان m3u8 (نص، صغير الحجم دائماً) أو سيجمنت فيديو (نستقبله على
// دفعات ونمررها فوراً)، اعتماداً على Content-Type من الترويسة + أول بايتات الجسم.
$respHeaders   = [];
$decided       = null;   // null | 'm3u8' | 'binary'
$sniffBuffer   = '';
$headersSent   = false;
$httpCodeSeen  = null;
const SNIFF_CAP = 2 * 1024 * 1024; // إذا تجاوزنا هالحجم بدون تأكيد m3u8 → اعتبرها بيانات ثنائية

function sendBinaryHeaders(int $code, array $respHeaders): void {
    http_response_code($code);
    $passthrough = ['content-length', 'content-range', 'accept-ranges', 'cache-control', 'etag'];
    foreach ($passthrough as $h) {
        if (isset($respHeaders[$h])) header($h . ': ' . $respHeaders[$h], false);
    }
    // ── تصحيح النوع: الـ CDN يرجّع image/jpeg تمويهاً لسيجمنتات MPEG-TS حقيقية ──
    $ctype = strtolower($respHeaders['content-type'] ?? '');
    if ($ctype === '' || str_starts_with($ctype, 'image/') || str_starts_with($ctype, 'text/')) {
        header('Content-Type: video/mp2t');
    } else {
        header('Content-Type: ' . $respHeaders['content-type']);
    }
    while (ob_get_level()) ob_end_clean();
}

$ch = curl_init($finalTargetUrl);
curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => false, // تحققنا من التحويلات مسبقاً أعلاه
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 12,
    CURLOPT_HTTPHEADER     => $curlHeaders,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_NOBODY         => ($_SERVER['REQUEST_METHOD'] === 'HEAD'),
    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
        $len  = strlen($line);
        $trim = rtrim($line);
        if (preg_match('#^HTTP/#i', $trim)) { $respHeaders = []; return $len; }
        $pos = strpos($trim, ':');
        if ($pos !== false) {
            $k = strtolower(trim(substr($trim, 0, $pos)));
            $v = trim(substr($trim, $pos + 1));
            $respHeaders[$k] = $v;
        }
        return $len;
    },
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$decided, &$sniffBuffer, &$headersSent, &$respHeaders) {
        $len = strlen($chunk);

        if ($decided === 'binary') {
            if (!$headersSent) {
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 200;
                sendBinaryHeaders($code, $respHeaders);
                $headersSent = true;
            }
            echo $chunk;
            flush();
            return $len;
        }

        // لسه غير محدد النوع — اجمع بايتات للفحص
        $sniffBuffer .= $chunk;
        $ctype    = strtolower($respHeaders['content-type'] ?? '');
        $looksM3u8 = str_contains($ctype, 'mpegurl') || str_contains($ctype, 'm3u8')
                  || str_starts_with(ltrim($sniffBuffer), '#EXTM3U');

        if ($looksM3u8 && strlen($sniffBuffer) < SNIFF_CAP) {
            return $len; // ضل نجمع — ممكن لسا ما وصل نهاية الـ playlist
        }

        // إمّا تأكدنا إنه مو m3u8، أو تجاوزنا السقف بدون تأكيد → اعتبره بيانات ثنائية
        $decided = 'binary';
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 200;
        sendBinaryHeaders($code, $respHeaders);
        $headersSent = true;
        echo $sniffBuffer;
        flush();
        $sniffBuffer = '';
        return $len;
    },
]);

curl_exec($ch);
$errno    = curl_errno($ch);
$error    = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ── لو خرجنا وهو لسا "غير محدد" (يعني الجسم صغير وكان فعلاً m3u8) ─────────────
if ($decided === null) {
    if ($errno || $httpCode < 200 || $httpCode >= 400) {
        http_response_code($httpCode ?: 502);
        header('Content-Type: application/json');
        echo json_encode(['error' => $error ?: "upstream $httpCode"]);
        exit;
    }

    // ── أعد كتابة روابط الـ playlist لتمر عبر نفس هذا البروكسي ──────────────
    $baseDir  = preg_replace('#\?.*$#', '', $finalTargetUrl);
    $baseDir  = preg_replace('#[^/]+$#', '', $baseDir);
    $baseHost = (parse_url($finalTargetUrl, PHP_URL_SCHEME) ?: 'https')
              . '://' . (parse_url($finalTargetUrl, PHP_URL_HOST) ?: $parsedHost);

    $selfBase = '/api/pluto-stream.php';
    $lines    = explode("\n", rtrim($sniffBuffer));
    $out      = [];

    foreach ($lines as $line) {
        $line = rtrim($line);

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

if (!$headersSent) {
    // انقطع الاتصال قبل ما نستلم أي بايت (خطأ شبكة)
    http_response_code($httpCode ?: 502);
    header('Content-Type: application/json');
    echo json_encode(['error' => $error ?: "upstream $httpCode"]);
}
