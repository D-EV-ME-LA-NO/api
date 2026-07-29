<?php
// api/aether/index.php — Aether multi-source streaming
// Sources: vidy.aether.cx · tiki.aether.cx · gallic.aether.bar · sol.aether.bar
// Returns: { ok, servers: [{ id, name, streams: [{label, url, type}] }] }

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('AE_UA',     'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('AE_ORIGIN', 'https://aether.bar');
define('AE_PROXY',  '/api/aether/proxy.php?url=');

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

// ── Build sub-source paths ─────────────────────────────────────────────────────
$mpath = '/movie/' . $id;
$tpath = '/tv/'   . $id . '/' . $season . '/' . $episode;
$path  = $type === 'tv' ? $tpath : $mpath;

$sub_sources = [
    // key → [url, m3u8_proxy_host_for_raw_stream or null]
    'vidy'   => ['url' => 'https://vidy.aether.cx'   . $path,             'host' => 'rem.aether.bar'],
    'tiki'   => ['url' => 'https://tiki.aether.cx'   . $path,             'host' => 'field.aether.bar'],
    'gallic' => ['url' => 'https://gallic.aether.bar' . $path,            'host' => null],
    'sol'    => ['url' => 'https://sol.aether.bar'   . $path . '?lang=sub', 'host' => null],
];

$common_hdrs = [
    'Accept: application/json, text/plain, */*',
    'Origin: '     . AE_ORIGIN,
    'Referer: '    . AE_ORIGIN . '/',
    'User-Agent: ' . AE_UA,
];

// ── Fetch all sub-sources in parallel ─────────────────────────────────────────
$mh  = curl_multi_init();
$chs = [];

foreach ($sub_sources as $key => $src) {
    $ch = curl_init($src['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $common_hdrs,
    ]);
    curl_multi_add_handle($mh, $ch);
    $chs[$key] = $ch;
}

do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

$raw = [];
foreach ($chs as $key => $ch) {
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = curl_multi_getcontent($ch);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
    if ($code === 200 && $body) {
        $j = json_decode($body, true);
        if ($j) $raw[$key] = $j;
    }
}
curl_multi_close($mh);

// ── Parse streams ──────────────────────────────────────────────────────────────
$streams = [];

// vidy / tiki: { "stream": "https://..." }
// نستخدم الـ URL مباشرة عبر بروكسينا — *.aether.cx في allowlist (strict mode)
// بدلاً من field.aether.bar/m3u8-proxy الذي يكون غير مستجيب أحياناً
foreach (['vidy' => 'Vidy', 'tiki' => 'Tiki'] as $key => $label) {
    $raw_url = $raw[$key]['stream'] ?? null;
    if (!$raw_url) continue;

    $streams[] = [
        'label' => 'Aether ' . $label,
        'url'   => AE_PROXY . urlencode($raw_url),
        'type'  => 'hls',
    ];
}

// gallic: { "source": { "stream_url": "https://rX.aether.cx/m3u8-proxy?url=...&headers=..." } }
// r*.aether.cx/m3u8-proxy يرجع 403 أحياناً — نستخرج الـ URL الحقيقي والـ headers مباشرة
$gallic_stream_url = $raw['gallic']['source']['stream_url'] ?? null;
if ($gallic_stream_url) {
    $gqs = [];
    parse_str(parse_url($gallic_stream_url, PHP_URL_QUERY) ?? '', $gqs);
    $real_url     = $gqs['url'] ?? null;
    $extra_hdrs   = json_decode($gqs['headers'] ?? '{}', true) ?? [];
    $g_origin     = $extra_hdrs['Origin']  ?? '';
    $g_referer    = $extra_hdrs['Referer'] ?? '';

    if ($real_url) {
        // CDN mode: sign + إرسال Origin/Referer الصحيح عبر ao/ar
        $sig = substr(hash_hmac('sha256', $real_url, 'ae-proxy-internal-v1'), 0, 16);
        $streams[] = [
            'label' => 'Aether Gallic',
            'url'   => AE_PROXY . urlencode($real_url)
                     . '&from=cdn&sig=' . $sig
                     . ($g_origin  ? '&ao=' . urlencode($g_origin)  : '')
                     . ($g_referer ? '&ar=' . urlencode($g_referer) : ''),
            'type'  => 'hls',
        ];
    } else {
        // fallback: مرر الـ URL كما هو (aether host → strict mode)
        $streams[] = [
            'label' => 'Aether Gallic',
            'url'   => AE_PROXY . urlencode($gallic_stream_url),
            'type'  => 'hls',
        ];
    }
}

// sol: { "streams": [{ "server": "Server X", "url": "https://sol.aether.bar/stream?b64=...", "type": "m3u8" }] }
$sol_streams = $raw['sol']['streams'] ?? [];
if (is_array($sol_streams)) {
    foreach ($sol_streams as $s) {
        $url    = $s['url']    ?? null;
        $server = $s['server'] ?? 'Sol';
        if (!$url) continue;

        // URLs from sol.aether.bar pass strict; raw CDN URLs need cdn mode + sig
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $is_aether = str_ends_with($host, '.aether.bar') || str_ends_with($host, '.aether.cx')
                  || $host === 'aether.bar' || $host === 'aether.cx';
        if ($is_aether) {
            $proxy_url = AE_PROXY . urlencode($url);
        } else {
            $sig       = substr(hash_hmac('sha256', $url, 'ae-proxy-internal-v1'), 0, 16);
            $proxy_url = AE_PROXY . urlencode($url) . '&from=cdn&sig=' . $sig;
        }

        $streams[] = [
            'label' => 'Aether ' . $server,
            'url'   => $proxy_url,
            'type'  => 'hls',
        ];
    }
}

if (!$streams) {
    echo json_encode(['ok' => false, 'error' => 'no streams found', 'servers' => []]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'servers' => [[
        'id'      => 'aether',
        'name'    => 'Aether',
        'streams' => $streams,
    ]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
