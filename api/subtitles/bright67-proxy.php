<?php
/**
 * api/subtitles/bright67-proxy.php
 * Downloads an SRT from subs.bright67.online and serves it as WebVTT.
 *
 * GET params:
 *   id  — subtitle ID (used as cache key)
 *   url — full download URL (must be on subs.bright67.online)
 */
require_once dirname(__DIR__, 2) . '/config.php';

$url = trim($_GET['url'] ?? '');
if (!$url) { http_response_code(400); exit('missing url'); }

// Safety: only proxy from the trusted host
$parsed = parse_url($url);
if (!$parsed || ($parsed['host'] ?? '') !== 'subs.bright67.online') {
    http_response_code(403);
    exit('forbidden');
}

$sid       = preg_replace('/[^a-z0-9_\-]/i', '', $_GET['id'] ?? '');
$offset    = (float)($_GET['offset'] ?? 0);
$off_tag   = $offset != 0 ? '_o' . str_replace(['.', '-'], ['d', 'm'], sprintf('%.1f', $offset)) : '';
$CACHE_DIR = dirname(__DIR__, 2) . '/data/cache/subtitles';
@mkdir($CACHE_DIR, 0755, true);
$cache_file = $sid ? $CACHE_DIR . '/bright67_sub_' . $sid . $off_tag . '.vtt' : null;

// Serve cached VTT if available
if ($cache_file && file_exists($cache_file)) {
    header('Content-Type: text/vtt; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    readfile($cache_file);
    exit;
}

// Download SRT from bright67
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept: */*',
    ],
    CURLOPT_SSL_VERIFYPEER => true,
]);
$srt  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($srt === false || $code !== 200) {
    http_response_code(502);
    exit('subtitle download failed: ' . $code);
}

// Convert SRT → WebVTT
function srt_to_vtt(string $srt): string {
    $srt = str_replace(["\r\n", "\r"], "\n", $srt);
    $vtt = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $srt);
    return "WEBVTT\n\n" . trim($vtt) . "\n";
}

// Shift VTT timestamps by offset seconds
function shift_vtt(string $vtt, float $offset): string {
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

$vtt = srt_to_vtt($srt);
if ($offset != 0) $vtt = shift_vtt($vtt, $offset);

if ($cache_file) { file_put_contents($cache_file, $vtt); }

header('Content-Type: text/vtt; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo $vtt;
