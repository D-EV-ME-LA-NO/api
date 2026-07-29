<?php
/**
 * api/dulo/index.php — Dulo.tv multi-source resolver
 *
 * الآلية:
 *   1) جلب session تلقائياً من /api/session
 *   2) POST /api/source بـ SSE → تجميع كل sources
 *   3) إرجاع MULTI_SOURCES بصيغتنا المعيارية
 *
 * Returns: { ok, servers: [{ id, name, streams: [{label, url, type}] }] }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

const DU_BASE   = 'https://dulo.tv';
const DU_UA     = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';
const DU_PROXY  = '/api/dulo/proxy.php?url=';
const DU_TIMEOUT = 22;

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

// ── كاش 8 دقائق ───────────────────────────────────────────────────────────────
$cache_dir  = __DIR__ . '/../../.cache/dulo';
if (!is_dir($cache_dir)) @mkdir($cache_dir, 0777, true);
$cache_key  = "{$type}-{$id}" . ($type === 'tv' ? "-{$season}-{$episode}" : '');
$cache_file = $cache_dir . '/' . md5($cache_key) . '.json';

if (is_file($cache_file) && (time() - filemtime($cache_file)) < 480) {
    readfile($cache_file);
    exit;
}

// ── 1) جلب session ───────────────────────────────────────────────────────────
function du_fetch_session(): ?string {
    $ch = curl_init(DU_BASE . '/api/session');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: '    . DU_UA,
            'Accept: application/json',
            'Origin: '        . DU_BASE,
            'Referer: '       . DU_BASE . '/',
        ],
    ]);
    $raw      = curl_exec($ch);
    $hdr_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || !$raw) return null;

    $headers = substr($raw, 0, $hdr_size);
    if (preg_match('/set-cookie:\s*(__Host-amri_session=[^;\r\n]+)/i', $headers, $m)) {
        return trim($m[1]);
    }
    return null;
}

$session = du_fetch_session();
if (!$session) {
    echo json_encode(['ok' => false, 'error' => 'could not obtain session']);
    exit;
}

// ── 2) POST /api/source → SSE ─────────────────────────────────────────────────
$body = json_encode(
    $type === 'tv'
        ? ['type' => 'tv',    'tmdbId' => $id, 'season' => $season, 'episode' => $episode]
        : ['type' => 'movie', 'tmdbId' => $id]
);

$sse_buffer = '';
$all_sources = [];

$ch = curl_init(DU_BASE . '/api/source');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_TIMEOUT        => DU_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        'User-Agent: '    . DU_UA,
        'Accept: text/event-stream',
        'Content-Type: application/json',
        'Origin: '        . DU_BASE,
        'Referer: '       . DU_BASE . '/',
        'Cookie: '        . $session,
    ],
    CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$sse_buffer, &$all_sources) {
        $sse_buffer .= $chunk;
        // معالجة كل سطر مكتمل
        while (($pos = strpos($sse_buffer, "\n")) !== false) {
            $line       = substr($sse_buffer, 0, $pos);
            $sse_buffer = substr($sse_buffer, $pos + 1);
            $line       = rtrim($line, "\r");

            if (!str_starts_with($line, 'data:')) continue;
            $json = trim(substr($line, 5));
            $ev   = @json_decode($json, true);
            if (!is_array($ev)) continue;

            // حدث sources: أضف كل مصدر جديد
            if (isset($ev['sources']) && is_array($ev['sources'])) {
                foreach ($ev['sources'] as $src) {
                    $url = $src['url'] ?? '';
                    if (!$url) continue;
                    // تجنب التكرار
                    $already = array_filter($all_sources, fn($s) => $s['url'] === $url);
                    if ($already) continue;
                    $all_sources[] = [
                        'url'     => $url,
                        'label'   => $src['title']   ?? ('Source ' . (count($all_sources) + 1)),
                        'quality' => $src['quality'] ?? null,
                        'type'    => strtolower($src['type'] ?? 'hls') === 'hls' ? 'm3u8' : 'mp4',
                    ];
                }
            }
        }
        return strlen($chunk);
    },
]);

curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$all_sources) {
    echo json_encode(['ok' => false, 'error' => "no sources found (http {$http})"]);
    exit;
}

// ── 3) بناء MULTI_SOURCES ─────────────────────────────────────────────────────
$streams = [];
foreach ($all_sources as $i => $src) {
    $label = $src['label'];
    if ($src['quality']) $label .= ' · ' . $src['quality'];

    $streams[] = [
        'label' => $label,
        'url'   => DU_PROXY . rawurlencode($src['url']),
        'type'  => $src['type'],
    ];
}

$out = json_encode([
    'ok'      => true,
    'servers' => [[
        'id'      => 'dulo',
        'name'    => 'Dulo',
        'streams' => $streams,
    ]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

@file_put_contents($cache_file, $out);
echo $out;
