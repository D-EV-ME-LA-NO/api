<?php
/**
 * api/animecurx/index.php — AnimeCurx embed resolver
 * مع جلسة كاملة من 7movies.in
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

const ACX_BASE    = 'https://embed.animecurx.tech';
const ACX_UA      = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';
const ACX_REFERER = 'https://7movies.in/';
const ACX_ORIGIN  = 'https://7movies.in';

// ── كاش 8 دقائق ───────────────────────────────────────────────────────────────
$cache_dir  = __DIR__ . '/../../.cache/animecurx';
if (!is_dir($cache_dir)) @mkdir($cache_dir, 0777, true);
$cache_key  = "{$type}-{$id}" . ($type === 'tv' ? "-{$season}-{$episode}" : '');
$cache_file = $cache_dir . '/' . md5($cache_key) . '.json';

if (is_file($cache_file) && (time() - filemtime($cache_file)) < 480) {
    readfile($cache_file);
    exit;
}

// ── 1) جلب Token + Cookies من 7movies.in ──────────────────────────────────
function acx_get_session(string $type, int $id, int $season = 1, int $episode = 1): ?array {
    $payload = json_encode([
        'tmdbId'  => $id,
        'type'    => $type,
        'season'  => $type === 'tv' ? $season : null,
        'episode' => $type === 'tv' ? $episode : null,
    ]);
    
    $ch = curl_init('https://7movies.in/api/playback-token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'User-Agent: ' . ACX_UA,
            'Origin: ' . ACX_ORIGIN,
            'Referer: ' . ACX_REFERER . 'movie/' . $id . '?autoplay=1',
            'Accept: application/json',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Dest: empty',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdr_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    
    if ($code !== 200 || !$raw) return null;
    
    $headers = substr($raw, 0, $hdr_size);
    $body = substr($raw, $hdr_size);
    $d = json_decode($body, true);
    $token = $d['token'] ?? null;
    
    // استخراج Cookie
    $cookies = [];
    if (preg_match_all('/set-cookie:\s*([^;\r\n]+)/i', $headers, $m)) {
        foreach ($m[1] as $c) {
            $cookies[] = trim($c);
        }
    }
    
    if (!$token) return null;
    
    return [
        'token' => $token,
        'cookies' => implode('; ', $cookies)
    ];
}

$session = acx_get_session($type, $id, $season, $episode);
if (!$session || !$session['token']) {
    echo json_encode(['ok' => false, 'error' => 'could not obtain playback session']);
    exit;
}

$token = $session['token'];
$cookie_string = $session['cookies'] . '; cinrift_playback_session=' . $token;

// ── 2) جلب صفحة embed باستخدام التوكن والكوكيز ──────────────────────────
$embedUrl = $type === 'tv'
    ? ACX_BASE . "/embed/tv/{$id}?season={$season}&episode={$episode}&source=0&mobileSheets=true&token=" . urlencode($token)
    : ACX_BASE . "/embed/movie/{$id}?source=0&mobileSheets=true&token=" . urlencode($token);

$ch = curl_init($embedUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        'User-Agent: ' . ACX_UA,
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'Accept-Language: ar-IQ,ar;q=0.9,en-IQ;q=0.8,en;q=0.7,en-US;q=0.6',
        'Accept-Encoding: gzip, deflate, br',
        'Sec-Ch-Ua: "Chromium";v="137", "Not/A)Brand";v="24"',
        'Sec-Ch-Ua-Mobile: ?1',
        'Sec-Ch-Ua-Platform: "Android"',
        'Upgrade-Insecure-Requests: 1',
        'Origin: ' . ACX_ORIGIN,
        'Referer: ' . ACX_REFERER . 'movie/' . $id . '?autoplay=1',
        'Sec-Fetch-Site: cross-site',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Dest: iframe',
        'Cookie: ' . $cookie_string,
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);

$html     = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

if ($httpCode !== 200 || !$html) {
    echo json_encode([
        'ok' => false, 
        'error' => "embed page returned http {$httpCode}",
        'curl_error' => $curlError,
        'url' => $embedUrl,
        'cookie_count' => strlen($cookie_string)
    ]);
    exit;
}

// ── 3) استخراج مصفوفة streams من JavaScript ────────────────────────────────
$streams = [];

// نمط: var streams = [ ... ];
if (preg_match('/(?:var\s+)?streams\s*=\s*(\[[\s\S]*?\]);/m', $html, $m)) {
    $jsonRaw = $m[1];
    $jsonRaw = preg_replace('/,\s*([\]}])/m', '$1', $jsonRaw);
    $parsed  = @json_decode($jsonRaw, true);
    if (is_array($parsed)) {
        $streams = $parsed;
    }
}

// نمط بديل: streams=[{label:'...',url:'...'}]
if (!$streams && preg_match_all('/\{[^{}]*label\s*:\s*[\'"]([^\'"]+)[\'"][^{}]*url\s*:\s*[\'"]([^\'"]+)[\'"][^{}]*\}/m', $html, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $streams[] = ['label' => $match[1], 'url' => $match[2]];
    }
}

// نمط ثالث: استخراج الروابط من داخل HTML
if (!$streams) {
    preg_match_all('/https?:\/\/[^\s"\'<>]+\.m3u8[^\s"\'<>]*/i', $html, $m);
    if (!empty($m[0])) {
        foreach (array_unique($m[0]) as $i => $url) {
            $streams[] = ['label' => 'Source ' . ($i + 1), 'url' => $url];
        }
    }
}

if (!$streams) {
    echo json_encode(['ok' => false, 'error' => 'could not extract streams from embed page']);
    exit;
}

// ── 4) بناء السيرفرات ───────────────────────────────────────────────────────
$allStreams = [];
foreach ($streams as $i => $srv) {
    $rawUrl = $srv['url'] ?? '';
    if (!$rawUrl) continue;

    if (str_starts_with($rawUrl, '/')) {
        $absUrl = ACX_BASE . $rawUrl;
    } elseif (!str_starts_with($rawUrl, 'http')) {
        $absUrl = ACX_BASE . '/' . $rawUrl;
    } else {
        $absUrl = $rawUrl;
    }

    $label = $srv['label'] ?? ('Source ' . ($i + 1));
    $proxyUrl = '/api/animecurx/proxy.php?url=' . rawurlencode($absUrl);

    $allStreams[] = ['label' => $label, 'url' => $proxyUrl, 'type' => 'm3u8'];
}

if (!$allStreams) {
    echo json_encode(['ok' => false, 'error' => 'no valid streams found']);
    exit;
}

$servers = [[
    'id'      => 'animecurx',
    'name'    => 'AnimeCurx',
    'streams' => $allStreams,
]];

$out = json_encode(['ok' => true, 'servers' => $servers], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@file_put_contents($cache_file, $out);
echo $out;
?>