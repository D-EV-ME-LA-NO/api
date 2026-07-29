<?php
/**
 * api/reels.php
 * Returns a batch of reels (random trending content with YouTube trailer keys).
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config.php';

$page = max(1, min(10, (int)($_GET['page'] ?? rand(1, 4))));

// ── Genre maps (cached 24h) ────────────────────────────────────────────────
function reel_tmdb(string $ep, array $p = []): array {
    $p['api_key'] = TMDB_API_KEY;
    $url   = TMDB_API_URL . $ep . '?' . http_build_query($p);
    $cache = sys_get_temp_dir() . '/reel_' . md5($url) . '.json';
    if (file_exists($cache) && time() - filemtime($cache) < 1800) {
        return json_decode(file_get_contents($cache), true) ?: [];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch); curl_close($ch);
    $data = $body ? (json_decode($body, true) ?: []) : [];
    if ($data) file_put_contents($cache, json_encode($data));
    return $data;
}

function reel_genre_map(string $type): array {
    $data = reel_tmdb('/genre/' . $type . '/list');
    $map  = [];
    foreach ($data['genres'] ?? [] as $g) $map[(int)$g['id']] = $g['name'];
    return $map;
}

$movie_genres = reel_genre_map('movie');
$tv_genres    = reel_genre_map('tv');

// ── Fetch trending and build reels ─────────────────────────────────────────
$trending = reel_tmdb('/trending/all/week', ['page' => $page]);
$items    = $trending['results'] ?? [];
shuffle($items);

$reels = [];
foreach ($items as $item) {
    if (count($reels) >= 8) break;
    $mtype = $item['media_type'] ?? 'movie';
    if ($mtype === 'person') continue;
    $id = (int)$item['id'];

    $vids = reel_tmdb('/' . $mtype . '/' . $id . '/videos');
    $key  = null;
    foreach ($vids['results'] ?? [] as $v) {
        if (($v['site'] ?? '') !== 'YouTube') continue;
        if (!in_array($v['type'] ?? '', ['Trailer', 'Teaser'])) continue;
        if (!$key) $key = $v['key'];
        if ($v['type'] === 'Trailer') { $key = $v['key']; break; }
    }
    if (!$key) continue;

    $title  = $item['title'] ?? $item['name'] ?? '';
    $slug   = $id . '-' . slugify($title);
    $year   = substr($item['release_date'] ?? $item['first_air_date'] ?? '', 0, 4);
    $rating = round((float)($item['vote_average'] ?? 0), 1);
    $gmap   = $mtype === 'tv' ? $tv_genres : $movie_genres;
    $genres = array_values(array_filter(
        array_map(fn($gid) => $gmap[(int)$gid] ?? null, array_slice($item['genre_ids'] ?? [], 0, 3))
    ));

    $reels[] = [
        'id'          => $id,
        'type'        => $mtype,
        'title'       => $title,
        'overview'    => $item['overview'] ?? '',
        'poster'      => img_url($item['poster_path'] ?? null, 'w342'),
        'backdrop'    => img_url($item['backdrop_path'] ?? null, 'w1280'),
        'year'        => $year,
        'rating'      => $rating,
        'genres'      => $genres,
        'trailer_key' => $key,
        'slug'        => $slug,
        'watch_url'   => '/' . ($mtype === 'tv' ? 'watch/tv' : 'watch/movie') . '/' . $slug . ($mtype === 'tv' ? '/1/1' : ''),
        'detail_url'  => '/' . ($mtype === 'tv' ? 'tv-show' : 'movie') . '/' . $slug,
    ];
}

echo json_encode(['ok' => true, 'reels' => array_values($reels)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
