<?php
/**
 * api/subtitles/opensubtitles.php
 * Fetches subtitles from rest.opensubtitles.org using IMDB ID.
 *
 * GET params:
 *   imdb    — IMDB ID (with or without "tt" prefix)
 *   type    — movie | tv
 *   season  — TV season (default 1)
 *   episode — TV episode (default 1)
 */
require_once dirname(__DIR__, 2) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$imdb_raw = trim($_GET['imdb'] ?? '');
if (!$imdb_raw) {
    echo json_encode(['ok' => false, 'subtitles' => [], 'reason' => 'missing_imdb']);
    exit;
}

// Normalise: strip "tt" prefix → numeric ID only (opensubtitles wants numeric)
$imdb_num = preg_replace('/^tt/', '', $imdb_raw);
if (!preg_match('/^\d+$/', $imdb_num)) {
    echo json_encode(['ok' => false, 'subtitles' => [], 'reason' => 'invalid_imdb']);
    exit;
}

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$season  = max(1, (int)($_GET['season']  ?? 1));
$episode = max(1, (int)($_GET['episode'] ?? 1));

// ── Cache ────────────────────────────────────────────────────────────────────
$CACHE_DIR = dirname(__DIR__, 2) . '/data/cache/subtitles';
@mkdir($CACHE_DIR, 0755, true);
$cache_key  = 'osubs_' . $imdb_num . ($type === 'tv' ? "_s{$season}e{$episode}" : '');
$cache_file = $CACHE_DIR . '/' . $cache_key . '.json';
$cache_ttl  = 43200; // 12 h

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    $cached = file_get_contents($cache_file);
    if ($cached !== false) { echo $cached; exit; }
}

// ── Fetch multiple languages in parallel ──────────────────────────────────
$langs = ['eng', 'ara', 'fre', 'deu', 'spa', 'ita', 'por', 'tur'];
$base  = 'https://rest.opensubtitles.org/search/imdbid-' . $imdb_num;
$suffix = $type === 'tv' ? "/season-{$season}/episode-{$episode}" : '';

$headers = [
    'User-Agent: novaapp v1.0.0',
    'Accept-Encoding: gzip',
    'Accept: */*',
];

$mh   = curl_multi_init();
$chs  = [];
foreach ($langs as $lang) {
    $url = $base . '/sublanguageid-' . $lang . $suffix;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',   // auto-decompress
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_multi_add_handle($mh, $ch);
    $chs[$lang] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

// ── Parse results ─────────────────────────────────────────────────────────
$lang_map = [
    'eng' => 'en', 'ara' => 'ar', 'fre' => 'fr', 'deu' => 'de',
    'spa' => 'es', 'ita' => 'it', 'por' => 'pt', 'tur' => 'tr',
];

$subtitles = [];
foreach ($chs as $lang => $ch) {
    $body = curl_multi_getcontent($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    if (!$body || $code !== 200) continue;
    $items = json_decode($body, true);
    if (!is_array($items)) continue;

    // Pick up to 3 best results per language (by Score if available)
    $picked = 0;
    foreach ($items as $it) {
        if ($picked >= 3) break;
        $file_id = (string)($it['IDSubtitleFile'] ?? '');
        $dl_url  = (string)($it['SubDownloadLink'] ?? '');
        $fmt     = strtolower($it['SubFormat'] ?? 'srt');
        $release = (string)($it['MovieReleaseName'] ?? $it['SubFileName'] ?? '');
        if (!$file_id || !$dl_url) continue;
        if (!in_array($fmt, ['srt', 'vtt', 'sub'])) continue; // skip exotic formats

        $subtitles[] = [
            'url'   => '/api/subtitles/opensubtitles-proxy.php?id=' . urlencode($file_id) . '&url=' . urlencode($dl_url),
            'lang'  => $lang_map[$lang] ?? $lang,
            'label' => trim($release) ?: strtoupper($lang),
        ];
        $picked++;
    }
}
curl_multi_close($mh);

$result = json_encode(
    ['ok' => true, 'subtitles' => $subtitles, 'source' => 'opensubtitles'],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
file_put_contents($cache_file, $result);
echo $result;
