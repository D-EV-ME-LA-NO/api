<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── قراءة المدخلات ──────────────────────────────────────
$id   = '';
$type = 'movie';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = isset($body['id'])   ? trim($body['id'])   : '';
    $type = isset($body['type']) ? trim($body['type']) : 'movie';
} else {
    $id   = isset($_GET['id'])   ? trim($_GET['id'])   : '';
    $type = isset($_GET['type']) ? trim($_GET['type']) : 'movie';
}

if (empty($id)) {
    echo json_encode(['ok' => false, 'error' => 'id is required']);
    exit();
}

$embedUrl = "https://1embed.cc/embed/{$type}/{$id}";

// ── ملف الكوكيز المؤقت ──────────────────────────────────
$cookieFile = sys_get_temp_dir() . '/1embed_cookies_' . md5($id) . '.txt';

// ── هيدرز المتصفح ───────────────────────────────────────
$headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9',
    'Accept-Encoding: gzip, deflate, br',
    'Referer: https://1embed.cc/',
    'Sec-Fetch-Dest: iframe',
    'Sec-Fetch-Mode: navigate',
    'Sec-Fetch-Site: same-origin',
    'Sec-CH-UA: "Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
    'Sec-CH-UA-Mobile: ?0',
    'Sec-CH-UA-Platform: "Windows"',
    'Upgrade-Insecure-Requests: 1',
    'Cache-Control: no-cache',
    'Pragma: no-cache',
];

// ── دالة الطلب بـ cURL ───────────────────────────────────
function fetchUrl($url, $headers, $cookieFile) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',           // قبول gzip/br تلقائياً
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
    ]);
    $body    = curl_exec($ch);
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error   = curl_error($ch);
    curl_close($ch);

    return ['body' => $body, 'status' => $status, 'error' => $error];
}

// ── الطلب الأول: الصفحة الرئيسية لاستخراج الكوكيز ──────
fetchUrl('https://1embed.cc/', $headers, $cookieFile);

// ── الطلب الثاني: صفحة الـ embed ────────────────────────
$result = fetchUrl($embedUrl, $headers, $cookieFile);

if ($result['error']) {
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit();
}

$html   = $result['body'];
$status = $result['status'];

// ── استخراج الروابط من HTML ──────────────────────────────
$sources = [];

// روابط m3u8
if (preg_match_all('/https?:\/\/[^\s"\'<>]+\.m3u8[^\s"\'<>]*/i', $html, $m)) {
    $sources = array_merge($sources, $m[0]);
}

// روابط mp4
if (preg_match_all('/https?:\/\/[^\s"\'<>]+\.mp4[^\s"\'<>]*/i', $html, $m)) {
    $sources = array_merge($sources, $m[0]);
}

// نمط file: "URL" أو src: "URL" الشائع في players
if (preg_match_all('/(?:file|src|source)\s*[=:]\s*["\']+(https?:\/\/[^"\']+)["\']/i', $html, $m)) {
    $sources = array_merge($sources, $m[1]);
}

// نمط sources: [{file:"URL"}]
if (preg_match_all('/["\']file["\']\s*:\s*["\'](https?:\/\/[^"\']+)["\']/i', $html, $m)) {
    $sources = array_merge($sources, $m[1]);
}

// إزالة المكررات
$sources = array_values(array_unique($sources));

// ── قراءة الكوكيز المحفوظة ──────────────────────────────
$cfClearance = '';
if (file_exists($cookieFile)) {
    $cookieContent = file_get_contents($cookieFile);
    if (preg_match('/cf_clearance\s+(\S+)/i', $cookieContent, $cm)) {
        $cfClearance = $cm[1];
    }
}

// ── الرد ────────────────────────────────────────────────
if (!empty($sources)) {
    echo json_encode([
        'ok'          => true,
        'embedUrl'    => $embedUrl,
        'sources'     => $sources,
        'cf_clearance' => $cfClearance ?: null,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'ok'          => false,
        'embedUrl'    => $embedUrl,
        'cfStatus'    => $status,
        'cf_clearance' => $cfClearance ?: null,
        'message'     => 'no sources found — page may require JS execution',
        'htmlPreview' => mb_substr($html, 0, 3000),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// تنظيف ملف الكوكيز
@unlink($cookieFile);
