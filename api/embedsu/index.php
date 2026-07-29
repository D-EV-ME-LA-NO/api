<?php
// api/embedsu/index.php — embed.su iframe embed
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); exit; }

$url = $type === 'tv'
    ? "https://embed.su/embed/tv/{$id}/{$season}/{$episode}"
    : "https://embed.su/embed/movie/{$id}";

echo json_encode(['ok' => true, 'source' => ['m3u8' => $url, 'type' => 'iframe', 'qualities' => [], 'subtitles' => []]], JSON_UNESCAPED_SLASHES);
