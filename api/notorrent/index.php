<?php
// api/notorrent/index.php — NoTorrent standalone source
// Returns: { ok, servers: [{ id, name, streams: [{label, url, type}] }] }

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Reuse central TMDB config (defines TMDB_API_KEY + TMDB_API_URL)
$_cfg = dirname(__DIR__, 2) . '/config.php';
if (file_exists($_cfg) && !defined('TMDB_API_KEY')) require_once $_cfg;
define('NT_TMDB_KEY', defined('TMDB_API_KEY') ? TMDB_API_KEY : '60a8d6ad3b8e5fbdbde539526b196d9b');
define('NT_TMDB_URL', defined('TMDB_API_URL') ? TMDB_API_URL : 'https://api.themoviedb.org/3');

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

// ── 1) IMDB ID من TMDB مع كاش ─────────────────────────────────────────────────
$tmdb_type   = $type === 'tv' ? 'tv' : 'movie';
$imdb_cache  = sys_get_temp_dir() . '/nt_imdb_' . $tmdb_type . '_' . $id . '.txt';
$imdb_id     = '';

if (file_exists($imdb_cache) && (time() - filemtime($imdb_cache) < 86400)) {
    $imdb_id = trim((string)file_get_contents($imdb_cache));
} else {
    $ch = curl_init(NT_TMDB_URL . '/' . $tmdb_type . '/' . $id . '/external_ids?api_key=' . NT_TMDB_KEY);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING       => '',
    ]);
    $ext = curl_exec($ch);
    curl_close($ch);
    if ($ext) {
        $d = json_decode($ext, true);
        $imdb_id = $d['imdb_id'] ?? '';
        if ($imdb_id) file_put_contents($imdb_cache, $imdb_id);
    }
}

if (!$imdb_id) {
    echo json_encode(['ok' => false, 'error' => 'imdb_id not found for tmdb ' . $id]);
    exit;
}

// ── 2) طلب addon.notorrent2 ───────────────────────────────────────────────────
$nt_url = $type === 'tv'
    ? "https://addon.notorrent2.workers.dev/stream/series/{$imdb_id}:{$season}:{$episode}.json"
    : "https://addon.notorrent2.workers.dev/stream/movie/{$imdb_id}.json";

$ua = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';
$nt_headers = [
    'user-agent: ' . $ua,
    'accept: */*',
    'origin: https://web.stremio.com',
    'sec-fetch-site: cross-site',
    'sec-fetch-mode: cors',
    'sec-fetch-dest: empty',
    'referer: https://web.stremio.com/',
    'accept-language: ar-IQ,ar;q=0.9,en-IQ;q=0.8,en;q=0.7,en-US;q=0.6',
];

$ch = curl_init($nt_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => $nt_headers,
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$body) {
    echo json_encode(['ok' => false, 'error' => 'notorrent api failed: http ' . $code]);
    exit;
}

$data    = json_decode($body, true);
$streams = $data['streams'] ?? [];

// ── 3) فلتر المجاني فقط (url موجود وما فيه paypal) ───────────────────────────
$free = [];
foreach ($streams as $s) {
    if (!isset($s['url'])) continue;
    if (strpos($s['url'], 'paypal') !== false) continue;
    $free[] = $s;
}

if (empty($free)) {
    echo json_encode(['ok' => false, 'error' => 'no free streams found']);
    exit;
}

// ── 4) متابعة الـ redirects بالتوازي للحصول على الـ URL الفعلي ────────────────
$mh      = curl_multi_init();
$handles = [];

foreach ($free as $i => $s) {
    $ch = curl_init($s['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_RANGE          => '0-0',          // أول بايت فقط لمعرفة الـ URL
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => [
            'origin: https://web.stremio.com',
            'referer: https://web.stremio.com/',
        ],
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = ['ch' => $ch, 'stream' => $s];
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    if ($running > 0) curl_multi_select($mh, 0.3);
} while ($running > 0);

// ── 5) بناء قائمة المصادر بعناوينها الأصلية من الـ API ──────────────────────
$all_streams = [];
$seen_urls   = [];

foreach ($handles as $i => $item) {
    $ch        = $item['ch'];
    $s         = $item['stream'];
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    // تجاهل إذا الـ redirect ما اشتغل أو رجع خطأ
    if (!$final_url || $final_url === $s['url']) continue;
    if ($http_code >= 400) continue;

    // لا نكرر نفس URL
    if (in_array($final_url, $seen_urls, true)) continue;
    $seen_urls[] = $final_url;

    $stream_type = str_contains($final_url, '.m3u8') ? 'm3u8' : 'mp4';

    // العنوان الأصلي من الـ API مباشرة
    $label = trim($s['title'] ?? '');
    if ($label === '') $label = $final_url;

    // مرّر عبر البروكسي ليعالج الـ cookies والـ CORS
    $proxy_url = '/api/notorrent/proxy.php?url=' . urlencode($final_url);

    $all_streams[] = [
        'label' => $label,
        'url'   => $proxy_url,
        'type'  => $stream_type,
    ];
}

curl_multi_close($mh);

if (empty($all_streams)) {
    echo json_encode(['ok' => false, 'error' => 'no streams resolved after redirect']);
    exit;
}

// ── 6) سيرفر واحد "NoTorrent" وكل المصادر داخل السهم ───────────────────────
$servers = [[
    'id'      => 'nt-main',
    'name'    => 'NoTorrent',
    'streams' => $all_streams,
]];

echo json_encode(['ok' => true, 'servers' => $servers], JSON_UNESCAPED_SLASHES);
