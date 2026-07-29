<?php
// api/cinextream/index.php — CineXtream iframe embed
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); exit; }

$url = $type === 'tv'
    ? "https://cinextream.net/api/embed/tv/{$id}?season={$season}&episode={$episode}&noads=0&autoPlay=0"
    : "https://cinextream.net/api/embed/movie/{$id}?noads=0&autoPlay=0";

echo json_encode(['ok' => true, 'source' => ['m3u8' => $url, 'type' => 'iframe', 'qualities' => [], 'subtitles' => []]], JSON_UNESCAPED_SLASHES);
