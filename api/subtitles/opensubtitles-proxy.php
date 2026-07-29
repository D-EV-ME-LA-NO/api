<?php
/**
 * api/subtitles/opensubtitles-proxy.php
 * Downloads a subtitle from rest.opensubtitles.org (.gz SRT),
 * decompresses it, converts SRT → WebVTT, applies timing offset,
 * and serves the result with caching.
 *
 * GET params:
 *   id     — IDSubtitleFile (cache key)
 *   url    — full download URL (must be on dl.opensubtitles.org)
 *   offset — optional float, seconds to shift timestamps (+/-)
 */
require_once dirname(__DIR__, 2) . '/config.php';

$url    = trim($_GET['url'] ?? '');
$offset = (float)($_GET['offset'] ?? 0);
if (!$url) { http_response_code(400); exit('missing url'); }

$parsed = parse_url($url);
$host   = $parsed['host'] ?? '';
if (!in_array($host, ['dl.opensubtitles.org', 'dl.opensubtitles.com'], true)) {
    http_response_code(403); exit('forbidden');
}

$id        = preg_replace('/[^a-z0-9_\-]/i', '', $_GET['id'] ?? '');
$off_tag   = $offset != 0 ? '_o' . str_replace(['.', '-'], ['d', 'm'], sprintf('%.1f', $offset)) : '';
$CACHE_DIR = dirname(__DIR__, 2) . '/data/cache/subtitles';
@mkdir($CACHE_DIR, 0755, true);
$cache_file = $id ? $CACHE_DIR . '/osubs_' . $id . $off_tag . '.vtt' : null;

if ($cache_file && file_exists($cache_file)) {
    header('Content-Type: text/vtt; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    readfile($cache_file);
    exit;
}

// ── Download .gz subtitle ─────────────────────────────────────────────────
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: novaapp v1.0.0',
        'Accept: */*',
    ],
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $code !== 200) {
    http_response_code(502); exit('download failed: ' . $code);
}

// ── Decompress (gzip) ─────────────────────────────────────────────────────
$srt = @gzdecode($raw);
if ($srt === false) {
    // Maybe the server already decompressed it
    $srt = $raw;
}

// ── SRT → VTT ─────────────────────────────────────────────────────────────
function os_srt_to_vtt(string $srt): string {
    $srt = str_replace(["\r\n", "\r"], "\n", $srt);
    // SRT uses comma for ms; VTT uses dot
    $vtt = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $srt);
    return "WEBVTT\n\n" . trim($vtt) . "\n";
}

// ── Shift timestamps ──────────────────────────────────────────────────────
function os_shift_vtt(string $vtt, float $offset): string {
    if ($offset == 0.0) return $vtt;
    return preg_replace_callback(
        '/\b(\d{2,}):(\d{2}):(\d{2})\.(\d{3})\b/',
        function ($m) use ($offset) {
            $secs = (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3] + (int)$m[4] / 1000.0 + $offset;
            if ($secs < 0) $secs = 0.0;
            $h   = (int)($secs / 3600);
            $min = (int)(fmod($secs, 3600) / 60);
            $sec = (int)fmod($secs, 60);
            $ms  = (int)round(fmod($secs, 1) * 1000);
            return sprintf('%02d:%02d:%02d.%03d', $h, $min, $sec, $ms);
        },
        $vtt
    );
}

$vtt = os_srt_to_vtt($srt);
if ($offset != 0) $vtt = os_shift_vtt($vtt, $offset);

if ($cache_file) file_put_contents($cache_file, $vtt);

header('Content-Type: text/vtt; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo $vtt;
