<?php
/**
 * api/torrentio.php — Torrentio multi-provider stream fetcher
 * Returns each provider as a separate server with its streams as branches.
 *
 * Query params: type, id, season, episode (same as vyla-all.php)
 * Response: { ok: true, servers: [ {id, name, streams:[{label,url,type}]} ] }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$type    = $_GET['type']    ?? 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : null;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : null;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

// ── Build request URL ──────────────────────────────────────────────────────────
if ($type === 'tv' && $season !== null && $episode !== null) {
    $apiUrl = "https://s.torrentio.to/v1/tv/{$id}/seasons/{$season}/episodes/{$episode}";
} else {
    $apiUrl = "https://s.torrentio.to/v1/movies/{$id}";
}

$ua = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 18,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING       => 'gzip, deflate, br',
    CURLOPT_HTTPHEADER     => [
        'host: s.torrentio.to',
        'accept: application/json',
        'user-agent: ' . $ua,
        'origin: https://stream.vidplay.to',
        'referer: https://stream.vidplay.to/',
        'sec-fetch-site: cross-site',
        'sec-fetch-mode: cors',
        'sec-fetch-dest: empty',
        'accept-language: en-US,en;q=0.9',
    ],
]);

$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$body || $code !== 200) {
    echo json_encode(['ok' => false, 'error' => "torrentio returned {$code}"]);
    exit;
}

$data = json_decode($body, true);
if (!isset($data['sources']) || !is_array($data['sources'])) {
    echo json_encode(['ok' => false, 'error' => 'unexpected response']);
    exit;
}

// ── Group by provider ──────────────────────────────────────────────────────────
$providers = [];   // id → ['name' => ..., 'streams' => [...]]

foreach ($data['sources'] as $src) {
    $provId   = $src['provider']['id']   ?? 'unknown';
    $provName = $src['provider']['name'] ?? $provId;
    $url      = $src['url']              ?? null;
    $stype    = $src['type']             ?? 'hls';
    $quality  = $src['quality']          ?? 'Auto';

    if (!$url) continue;

    // Normalise type: torrentio says "hls" → we use "m3u8"
    if ($stype === 'hls') $stype = 'm3u8';

    // ── مرّر الرابط عبر البروكسي ────────────────────────────────────────────
    // s.torrentio.to يحتاج Origin: stream.vidplay.to — المتصفح لا يرسله
    // مزوّد Pluto (vpro778) له بروكسي خاص: يبث البيانات مباشرة (بدون تخزينها
    // كاملة بالذاكرة) ويصحح Content-Type لأن الـ CDN يرجّع السيجمنتات كـ
    // image/jpeg تمويهاً، وهذا كان يسبب تعليق/توقف التشغيل على هذا السيرفر.
    $isPluto  = ($provId === 'vpro778') || (stripos($provName, 'pluto') !== false);
    $proxyUrl = $isPluto
        ? '/api/pluto-stream.php?url='     . rawurlencode($url)
        : '/api/torrentio-stream.php?url=' . rawurlencode($url);

    if (!isset($providers[$provId])) {
        $providers[$provId] = ['name' => $provName, 'streams' => []];
    }

    $providers[$provId]['streams'][] = [
        'label' => $quality,
        'url'   => $proxyUrl,
        'type'  => $stype,
    ];
}

if (empty($providers)) {
    echo json_encode(['ok' => false, 'error' => 'no valid sources']);
    exit;
}

// ── Build server list ──────────────────────────────────────────────────────────
$servers = [];
foreach ($providers as $provId => $prov) {
    $servers[] = [
        'id'      => 't-' . $provId,           // prefix t- to avoid clashes
        'name'    => $prov['name'],
        'streams' => $prov['streams'],          // [{label, url, type}]
    ];
}

// Sort: most streams first so best providers appear at top
usort($servers, fn($a, $b) => count($b['streams']) - count($a['streams']));

echo json_encode([
    'ok'        => true,
    'servers'   => $servers,
    'expiresAt' => $data['expiresAt'] ?? null,
], JSON_UNESCAPED_SLASHES);
