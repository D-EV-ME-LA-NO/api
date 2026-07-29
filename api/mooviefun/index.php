<?php
/**
 * api/mooviefun/index.php — MoovieFun multi-provider resolver
 *
 * Queries proxy.moovie.fun across all enabled providers in parallel
 * and returns an expand-format response so each provider appears as
 * its own server entry in the player.
 *
 * GET params (from API_QS): type, id, season, episode, title, _st
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config.php';

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = max(1, (int)($_GET['season']  ?? 1));
$episode = max(1, (int)($_GET['episode'] ?? 1));
$title   = trim($_GET['title'] ?? '');

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

// ── Get title from TMDB if not supplied ───────────────────────────────────
if (!$title) {
    $meta = tmdb_details($type, $id);
    $title = $meta['title'] ?? $meta['name'] ?? '';
}
if (!$title) {
    echo json_encode(['ok' => false, 'error' => 'title not found']);
    exit;
}

// ── Provider list (enabled on proxy.moovie.fun, prio order) ──────────────
$providers = [
    ['key' => 'vaplayer',      'name' => 'Poseidon'],
    ['key' => 'streamvault',   'name' => 'Zeus'],
    ['key' => 'vidrift',       'name' => 'Hades'],
    ['key' => 'moovie-catalog','name' => 'Athena'],
];

$base_url = 'https://proxy.moovie.fun/api/search';
$qs_common = http_build_query([
    'q'      => $title,
    'type'   => $type,
    'tmdbId' => $id,
] + ($type === 'tv' ? ['season' => $season, 'episode' => $episode] : []));

$req_headers = [
    'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
    'Origin: https://m.moovie.fun',
    'Referer: https://m.moovie.fun/',
    'Accept: */*',
    'Accept-Encoding: gzip, deflate, br',
];

// ── Parallel curl_multi ───────────────────────────────────────────────────
$mh  = curl_multi_init();
$chs = [];
foreach ($providers as $prov) {
    $url = $base_url . '?' . $qs_common . '&provider=' . $prov['key'];
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_ENCODING       => '',  // auto-decompress br/gzip
        CURLOPT_HTTPHEADER     => $req_headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_multi_add_handle($mh, $ch);
    $chs[] = ['ch' => $ch, 'prov' => $prov];
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

// ── Build servers list ────────────────────────────────────────────────────
$servers = [];
foreach ($chs as $item) {
    $ch   = $item['ch'];
    $prov = $item['prov'];
    $body = curl_multi_getcontent($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    if (!$body || $code !== 200) continue;
    $data = json_decode($body, true);
    if (!is_array($data) || !($data['totalStreams'] ?? 0)) continue;

    foreach ($data['results'] ?? [] as $result) {
        $raw_streams = $result['streams'] ?? [];
        if (!$raw_streams) continue;

        $streams = [];
        foreach ($raw_streams as $s) {
            $raw_url = $s['url'] ?? '';
            // Prefer the moovie proxy URL (no custom headers needed)
            $proxy   = $s['proxyUrl'] ?? '';
            $use_url = $proxy ? 'https://proxy.moovie.fun' . $proxy : $raw_url;
            if (!$use_url) continue;

            $stream_type = strtolower($s['type'] ?? 'm3u8');
            // Normalise: moovie returns "mp4" and "m3u8"
            if (!in_array($stream_type, ['mp4', 'm3u8'], true)) $stream_type = 'm3u8';

            // Build quality label from title field
            $label = $s['quality'] ?? (preg_match('/\d{3,4}P/i', $s['title'] ?? '', $m) ? $m[0] : 'HD');

            $streams[] = ['url' => $use_url, 'type' => $stream_type, 'label' => $label];
        }

        if ($streams) {
            $servers[] = [
                'id'      => $prov['key'],
                'name'    => $prov['name'],
                'streams' => $streams,
            ];
            break; // one entry per provider
        }
    }
}
curl_multi_close($mh);

if (!$servers) {
    echo json_encode(['ok' => false, 'error' => 'no streams found']);
    exit;
}

echo json_encode(['ok' => true, 'servers' => $servers], JSON_UNESCAPED_SLASHES);
