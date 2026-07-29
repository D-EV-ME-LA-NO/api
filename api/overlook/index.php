<?php
/**
 * api/overlook/index.php — Overlook.cx SSE resolver
 *
 * Endpoints:
 *   Movie : GET https://stream.overlook.cx/v1/movies/{tmdb_id}
 *   TV    : GET https://stream.overlook.cx/v1/tv/{id}/seasons/{s}/episodes/{e}
 *
 * Consumes SSE stream, parses provider_result + cache_hit events,
 * returns MULTI_SOURCES: { ok, servers:[{id,name,streams:[{label,url,type}]}] }
 *
 * No proxy needed — overlook proxies all streams via their own /v1/proxy.
 */

header('Content-Type: application/json');
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

define('OL_UA',     'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('OL_ORIGIN', 'https://overlook.cx');
define('OL_BASE',   'https://stream.overlook.cx');

/**
 * Wrap a raw source URL through overlook's own /v1/proxy, THEN through our
 * local proxy.php. overlook's /v1/proxy only sends
 * Access-Control-Allow-Origin: https://overlook.cx, so the browser blocks it
 * cross-origin from our own domain — our proxy.php fetches it server-side
 * (no CORS involved) and re-serves it with open CORS + m3u8 rewriting.
 */
function ol_wrap_proxy(string $url): string {
    // Some providers (e.g. Icefy) already return URLs pre-wrapped through
    // overlook's own /v1/proxy — don't double-wrap those, just route through ours.
    $host = parse_url($url, PHP_URL_HOST);
    if ($host && strcasecmp($host, parse_url(OL_BASE, PHP_URL_HOST)) === 0) {
        return '/api/overlook/proxy.php?url=' . urlencode($url);
    }
    $payload = [
        'url'     => $url,
        'headers' => [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150 Safari/537.36',
            'Accept'          => 'application/json, text/javascript, */*; q=0.01',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer'         => 'https://streams.icefy.top',
            'Origin'          => 'https://streams.icefy.top',
        ],
    ];
    $ol_proxy_url = OL_BASE . '/v1/proxy?data=' . rawurlencode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    return '/api/overlook/proxy.php?url=' . urlencode($ol_proxy_url);
}

$endpoint = $type === 'tv'
    ? OL_BASE . '/v1/tv/' . $id . '/seasons/' . $season . '/episodes/' . $episode
    : OL_BASE . '/v1/movies/' . $id;

// ── Fetch SSE stream ───────────────────────────────────────────────────────────
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 35,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        'Accept: text/event-stream',
        'Origin: ' . OL_ORIGIN,
        'Referer: ' . OL_ORIGIN . '/',
        'User-Agent: ' . OL_UA,
        'sec-ch-ua: "Chromium";v="137", "Not/A)Brand";v="24"',
        'sec-ch-ua-mobile: ?1',
        'sec-fetch-site: same-site',
        'sec-fetch-mode: cors',
        'sec-fetch-dest: empty',
    ],
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$raw || $code !== 200) {
    echo json_encode(['ok' => false, 'error' => "upstream returned http {$code}"]);
    exit;
}

// ── Parse SSE events ──────────────────────────────────────────────────────────
// Blocks are separated by blank lines
$blocks   = preg_split('/\r?\n\r?\n/', $raw);
$sources  = [];   // [{url, quality, type, provider_name}]

foreach ($blocks as $block) {
    $block = trim($block);
    if (!$block) continue;

    $event_name = '';
    $data_lines = [];

    foreach (explode("\n", $block) as $line) {
        $line = rtrim($line);
        if (str_starts_with($line, 'event:')) {
            $event_name = trim(substr($line, 6));
        } elseif (str_starts_with($line, 'data:')) {
            $data_lines[] = trim(substr($line, 5));
        } elseif (str_starts_with($line, '...')) {
            // Non-standard: overlook sends cache_hit data as "...{json}" without data: prefix
            $data_lines[] = $line;
        }
    }

    if (!$event_name) continue;
    $data_str = implode('', $data_lines);

    // ── provider_result: one provider's sources ───────────────────────────────
    if ($event_name === 'provider_result') {
        $ev = @json_decode($data_str, true);
        if (!is_array($ev) || empty($ev['sources'])) continue;
        $provider_name = $ev['provider']['name'] ?? 'Unknown';
        foreach ($ev['sources'] as $src) {
            $url = $src['url'] ?? '';
            if (!$url) continue;
            $sources[] = [
                'url'      => $url,
                'quality'  => $src['quality'] ?? '',
                'type'     => $src['type']    ?? 'hls',
                'provider' => $provider_name,
            ];
        }
        continue;
    }

    // ── cache_hit: all sources at once ───────────────────────────────────────
    if ($event_name === 'cache_hit') {
        $ev = @json_decode($data_str, true);
        if (!is_array($ev)) continue;

        // Structure: { response: { sources:[...], ... } }
        // Fallback:  { sources:[...] }
        $hit_sources = $ev['response']['sources'] ?? $ev['sources'] ?? [];
        foreach ($hit_sources as $src) {
            $url = $src['url'] ?? '';
            if (!$url) continue;
            $pname = $src['provider']['name'] ?? 'Unknown';
            $sources[] = [
                'url'      => $url,
                'quality'  => $src['quality'] ?? '',
                'type'     => $src['type']    ?? 'hls',
                'provider' => $pname,
            ];
        }
    }
}

if (!$sources) {
    echo json_encode(['ok' => false, 'error' => 'no sources found from any provider']);
    exit;
}

// ── Build MULTI_SOURCES ───────────────────────────────────────────────────────
// Group by provider, label = "ProviderName Quality"
$servers = [];
$by_prov = [];
foreach ($sources as $src) {
    $by_prov[$src['provider']][] = $src;
}

foreach ($by_prov as $prov_name => $srcs) {
    $streams = [];
    // If provider has multiple qualities, show each; else just show provider name
    $multi_q = count($srcs) > 1;
    foreach ($srcs as $i => $src) {
        $q = trim((string)$src['quality']);
        if ($multi_q && $q) {
            $label = $prov_name . ' ' . $q;
        } elseif ($multi_q) {
            $label = $prov_name . ' ' . ($i + 1);
        } else {
            $label = $prov_name . ($q ? ' ' . $q : '');
        }
        $streams[] = [
            'label' => $label,
            'url'   => ol_wrap_proxy($src['url']),
            'type'  => $src['type'],
        ];
    }
    $servers[] = [
        'id'      => 'overlook_' . strtolower(preg_replace('/\W+/', '_', $prov_name)),
        'name'    => 'Overlook · ' . $prov_name,
        'streams' => $streams,
    ];
}

echo json_encode([
    'ok'      => true,
    'servers' => $servers,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
