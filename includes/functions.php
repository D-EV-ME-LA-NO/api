<?php
// includes/functions.php — TMDB helpers and small utilities

function tmdb_request(string $endpoint, array $params = []): array {
    $params['api_key']  = TMDB_API_KEY;
    $params['language'] = $params['language'] ?? 'en-US';
    $url = TMDB_API_URL . $endpoint . '?' . http_build_query($params);

    $cacheDir = __DIR__ . '/../.cache/tmdb';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $cacheKey = $cacheDir . '/' . md5($url) . '.json';
    if (is_file($cacheKey) && (time() - filemtime($cacheKey)) < 60 * 30) {
        $data = json_decode((string)file_get_contents($cacheKey), true);
        if (is_array($data)) return $data;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'HZFlixClone/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) return [];
    $data = json_decode($body, true);
    if (!is_array($data)) return [];
    @file_put_contents($cacheKey, $body);
    return $data;
}

function img_url(?string $path, string $size = 'w500'): string {
    if (!$path) {
        return 'data:image/svg+xml;utf8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 450">' .
            '<rect width="300" height="450" fill="#1a1a1a"/>' .
            '<text x="150" y="225" fill="#444" font-family="Arial" font-size="20" text-anchor="middle">No Image</text></svg>'
        );
    }
    return TMDB_IMG . '/' . $size . $path;
}

/**
 * Batch-lookup TMDB IDs for anime titles (parallel fetch, 7-day disk cache).
 * Returns [ 'title' => ['id'=>int,'slug'=>string] | null, ... ]
 */
function anime_tmdb_ids_batch(array $titles): array {
    $results   = [];
    $uncached  = [];
    $cache_dir = __DIR__ . '/../.cache/tmdb_anime_ids';
    @mkdir($cache_dir, 0755, true);

    foreach (array_unique(array_filter($titles)) as $title) {
        $key  = md5(strtolower(trim($title)));
        $file = $cache_dir . '/' . $key . '.json';
        if (file_exists($file) && (time() - filemtime($file)) < 604800) {
            $d = json_decode(file_get_contents($file), true);
            $results[$title] = is_array($d) ? $d : null;
        } else {
            $uncached[] = $title;
        }
    }
    if (!$uncached) return $results;

    foreach ($uncached as $t) {
        $url = TMDB_API_URL . '/search/tv?' . http_build_query([
            'api_key'  => TMDB_API_KEY,
            'query'    => $t,
            'page'     => 1,
            'language' => 'en-US',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_ENCODING       => '',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body  = curl_exec($ch);
        curl_close($ch);

        $d     = json_decode($body ?: '', true) ?? [];
        $key   = md5(strtolower(trim($t)));
        $file  = $cache_dir . '/' . $key . '.json';
        $found = null;
        foreach ($d['results'] ?? [] as $r) {
            if (in_array(16, $r['genre_ids'] ?? []) || in_array('JP', $r['origin_country'] ?? [])) {
                $id    = (int)$r['id'];
                $found = ['id' => $id, 'slug' => $id . '-' . slugify_str($r['name'] ?? $t)];
                break;
            }
        }
        if (!$found && !empty($d['results'][0])) {
            $r     = $d['results'][0];
            $id    = (int)$r['id'];
            $found = ['id' => $id, 'slug' => $id . '-' . slugify_str($r['name'] ?? $t)];
        }
        file_put_contents($file, $found ? json_encode($found) : 'null');
        $results[$t] = $found;
    }
    return $results;
}

// Internal helper so slugify is available before it's defined in the file
function slugify_str(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    return trim($text, '-');
}

function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    return trim($text, '-');
}

function fmt_runtime(?int $minutes): string {
    if (!$minutes) return '';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h && $m) return "{$h}h {$m}m";
    if ($h) return "{$h}h";
    return "{$m}m";
}

function fmt_year(?string $date): string {
    if (!$date) return '';
    return substr($date, 0, 4);
}

function star(float $rating): string {
    return number_format($rating, 1);
}

function safe_get(string $key, $default = '') {
    return isset($_GET[$key]) ? $_GET[$key] : $default;
}

function back_url(): string {
    return $_SERVER['HTTP_REFERER'] ?? '/';
}

// Trending / curated lists
function tmdb_trending(string $type = 'all', string $window = 'week', int $page = 1): array {
    return tmdb_request("/trending/{$type}/{$window}", ['page' => $page]);
}

function tmdb_movie_list(string $list = 'popular', int $page = 1): array {
    return tmdb_request("/movie/{$list}", ['page' => $page]);
}

function tmdb_tv_list(string $list = 'popular', int $page = 1): array {
    return tmdb_request("/tv/{$list}", ['page' => $page]);
}

function tmdb_details(string $type, int $id): array {
    return tmdb_request("/{$type}/{$id}", [
        'append_to_response' => 'credits,videos,images,recommendations,similar,watch/providers,external_ids'
    ]);
}

function tmdb_search(string $query, int $page = 1): array {
    return tmdb_request('/search/multi', ['query' => $query, 'page' => $page, 'include_adult' => 'false']);
}

function tmdb_genres(string $type = 'movie'): array {
    $data = tmdb_request("/genre/{$type}/list");
    return $data['genres'] ?? [];
}

function tmdb_discover(string $type, array $params = []): array {
    return tmdb_request("/discover/{$type}", $params);
}

function tmdb_season(int $tv_id, int $season): array {
    return tmdb_request("/tv/{$tv_id}/season/{$season}");
}

function trailer_key(array $videos): ?string {
    foreach ($videos['results'] ?? [] as $v) {
        if (($v['site'] ?? '') === 'YouTube' && in_array($v['type'] ?? '', ['Trailer', 'Teaser'], true)) {
            return $v['key'];
        }
    }
    return $videos['results'][0]['key'] ?? null;
}
