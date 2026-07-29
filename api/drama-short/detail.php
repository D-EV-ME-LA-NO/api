<?php
/**
 * api/drama-short/detail.php — Scrape drama detail from narto-drama.com
 * GET: ?provider=bibishort&book_id=557&lang=en-US&title=Drama+Title
 *      or ?slug=swallowed-whole-by-my-hot-stepbrother&lang=en-US
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

define('ND_UA_D',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('ND_BASE_D',  'https://narto-drama.com');
define('ND_CACHE_D', __DIR__ . '/../../.cache/drama-short');

@mkdir(ND_CACHE_D, 0755, true);

$provider = preg_replace('/[^a-z0-9_-]/i', '', $_GET['provider'] ?? '');
$book_id  = (int)($_GET['book_id'] ?? 0);
$slug_in  = preg_replace('/[^a-z0-9_-]/i', '', $_GET['slug'] ?? '');
$lang     = preg_replace('/[^a-z0-9_-]/i', '', $_GET['lang'] ?? 'en-US');
$title    = trim($_GET['title'] ?? '');

if (!$provider && !$book_id && !$slug_in) {
    echo json_encode(['ok' => false, 'error' => 'Missing provider/book_id or slug']); exit;
}

// Cache
$cache_id  = $slug_in ?: ($provider . '_' . $book_id);
$cache_key = ND_CACHE_D . '/detail_' . md5($cache_id . $lang) . '.json';

if (is_file($cache_key) && (time() - filemtime($cache_key)) < 7200) {
    $data = json_decode(file_get_contents($cache_key), true);
    if ($data && !empty($data['ok'])) { echo json_encode($data); exit; }
}

// ── Helper: curl GET ──────────────────────────────────────────────────────────
function nd_get(string $url, string $referer = ''): array {
    $jar = ND_CACHE_D . '/cookies.txt';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 18,
        CURLOPT_ENCODING       => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . ND_UA_D,
            'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
            'Accept-Language: ar-IQ,ar;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding: gzip, deflate, br',
            'Referer: ' . ($referer ?: ND_BASE_D . '/'),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'url' => $final_url];
}

// ── Step 1: Resolve slug ──────────────────────────────────────────────────────
$slug = $slug_in;

if (!$slug && $provider && $book_id) {
    // 1a. Check slug cache file
    $slug_cache = ND_CACHE_D . '/slug_' . $provider . '_' . $book_id . '.txt';
    if (is_file($slug_cache) && (time() - filemtime($slug_cache)) < 86400 * 7) {
        $slug = trim(file_get_contents($slug_cache));
    }

    // 1b. Scan sections cache files for matching item's watch_url
    if (!$slug) {
        $section_files = glob(ND_CACHE_D . '/sections_*.json') ?: [];
        foreach ($section_files as $sf) {
            $sd = json_decode(file_get_contents($sf), true);
            if (empty($sd['sections'])) continue;
            foreach ($sd['sections'] as $sec) {
                foreach (($sec['items'] ?? []) as $it) {
                    $item_id = $it['id'] ?? '';
                    // Match provider:book_id
                    if ($item_id === $provider . ':' . $book_id) {
                        $wurl = $it['watch_url'] ?? $it['url'] ?? '';
                        if ($wurl && preg_match('#/detail/watch/([^/?&#\s]+)#', $wurl, $sm)) {
                            $slug = $sm[1];
                            @file_put_contents($slug_cache, $slug);
                            break 3;
                        }
                        // Also check if item has direct slug field
                        if (!empty($it['slug'])) {
                            $slug = $it['slug'];
                            @file_put_contents($slug_cache, $slug);
                            break 3;
                        }
                    }
                }
            }
        }
    }

    // 1c. Try import URL redirect
    if (!$slug) {
        $import_url = ND_BASE_D . '/search/import?' . http_build_query([
            'provider'    => $provider,
            'book_id'     => $book_id,
            'title'       => $title,
            'lang'        => $lang,
            'target_lang' => $lang,
        ]);
        $res = nd_get($import_url, ND_BASE_D . '/');
        // Try multiple patterns for the final URL
        $final = $res['url'];
        if (preg_match('#/detail/watch/([^/?&#\s]+)#', $final, $m)) {
            $slug = $m[1];
            @file_put_contents($slug_cache, $slug);
        } elseif (preg_match('#/watch/([^/?&#\s]+)#', $final, $m)) {
            $slug = $m[1];
            @file_put_contents($slug_cache, $slug);
        }
        // Also try parsing JSON response for slug
        if (!$slug && $res['body']) {
            $jd = json_decode($res['body'], true);
            if (!empty($jd['slug'])) {
                $slug = $jd['slug'];
                @file_put_contents($slug_cache, $slug);
            } elseif (!empty($jd['watch_url']) && preg_match('#/detail/watch/([^/?&#\s]+)#', $jd['watch_url'], $m)) {
                $slug = $m[1];
                @file_put_contents($slug_cache, $slug);
            }
        }
    }
}

// ── Step 2: Scrape detail page (if slug available) ────────────────────────────
$drama_title    = $title ?: 'Drama Short';
$poster         = '';
$desc           = '';
$drama_id       = 0;
$total_episodes = 0;

if ($slug) {
    $detail_url = ND_BASE_D . '/detail/watch/' . $slug . '?lang=' . $lang;
    $res = nd_get($detail_url, ND_BASE_D . '/');

    if ($res['code'] === 200 && $res['body']) {
        $html = $res['body'];

        // Title
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $m)) {
            $drama_title = trim(html_entity_decode($m[1], ENT_QUOTES));
        } elseif (preg_match('/<title>([^<|]+)/i', $html, $m)) {
            $drama_title = trim(preg_replace('/\s*[-|].*$/', '', html_entity_decode($m[1])));
        }

        // Poster
        if (preg_match('/cover-prod\.crazytalkai\.com\/[^\s"\']+/', $html, $m)) {
            $poster = 'https://' . $m[0];
        } elseif (preg_match('/<meta\s+property="og:image"\s+content="([^"]+)"/i', $html, $m)) {
            $poster = $m[1];
        } elseif (preg_match('/<meta\s+content="([^"]+)"\s+property="og:image"/i', $html, $m)) {
            $poster = $m[1];
        }

        // Description
        if (preg_match('/<meta\s+name="description"\s+content="([^"]+)"/i', $html, $m)) {
            $desc = html_entity_decode($m[1], ENT_QUOTES);
        }

        // Drama ID
        if (preg_match('/media-prod\.crazytalkai\.com\/drama_(\d+)\//i', $html, $m)) {
            $drama_id = (int)$m[1];
        } elseif (preg_match('/["\']?drama_id["\']?\s*[=:]\s*["\']?(\d+)/i', $html, $m)) {
            $drama_id = (int)$m[1];
        }

        // ── Episode count — try many patterns ────────────────────────────────

        // Pattern A: episode links /detail/watch/{slug}/N
        if (!$total_episodes && preg_match_all('#/detail/watch/' . preg_quote($slug, '#') . '/(\d+)#', $html, $m)) {
            $ep_nums = array_map('intval', $m[1]);
            if ($ep_nums) $total_episodes = max($ep_nums);
        }

        // Pattern B: JSON fields like "total_episodes":24 or "totalEpisodes":24
        if (!$total_episodes && preg_match('/"(?:total_episodes|totalEpisodes|episode_count|episodeCount|totalEp)"\s*:\s*(\d+)/i', $html, $m)) {
            $total_episodes = (int)$m[1];
        }

        // Pattern C: data-total or data-count attribute
        if (!$total_episodes && preg_match('/data-(?:total|count|episodes)\s*=\s*["\']?(\d+)["\']?/i', $html, $m)) {
            $total_episodes = (int)$m[1];
        }

        // Pattern D: data-ep or data-episode attributes
        if (!$total_episodes && preg_match_all('/data-ep(?:isode)?\s*=\s*["\']?(\d+)["\']?/i', $html, $m)) {
            $ep_nums = array_map('intval', $m[1]);
            if ($ep_nums) $total_episodes = max($ep_nums);
        }

        // Pattern E: "X episodes" text
        if (!$total_episodes && preg_match('/(\d+)\s*(?:episodes?|حلقة|حلقات|ep\.?s?)/i', $html, $m)) {
            $ep = (int)$m[1];
            if ($ep >= 1 && $ep <= 999) $total_episodes = $ep;
        }

        // Pattern F: __NUXT__ / __NEXT_DATA__ / window.__STATE__ embedded JSON
        if (!$total_episodes && preg_match('/(?:__NUXT__|__NEXT_DATA__|__INITIAL_STATE__)[^{]*(\{.{0,5000})/s', $html, $m)) {
            if (preg_match('/"(?:total_episodes|totalEpisodes|episode_count|episodeCount)"\s*:\s*(\d+)/i', $m[1], $m2)) {
                $total_episodes = (int)$m2[1];
            }
        }

        // Pattern G: count episode-nav or episode-btn elements (rough count)
        if (!$total_episodes) {
            $ep_link_count = preg_match_all('/class="[^"]*(?:ep-item|episode-item|ep-btn|episode-btn)[^"]*"/i', $html);
            if ($ep_link_count > 0) $total_episodes = $ep_link_count;
        }
    }
}

// ── Default episode count ─────────────────────────────────────────────────────
// If we resolved a slug (meaning drama exists) but couldn't count episodes,
// default to 30 so users can at least browse and watch.
if ($slug && !$total_episodes) {
    $total_episodes = 30;
}
// Even without slug, provide a fallback so the page is usable
if (!$total_episodes) {
    $total_episodes = 30;
}

$result = [
    'ok'             => true,  // Always ok:true — page can always show something
    'slug'           => $slug,
    'title'          => $drama_title,
    'poster'         => $poster,
    'description'    => mb_strimwidth($desc, 0, 400, '…'),
    'drama_id'       => $drama_id,
    'total_episodes' => $total_episodes,
    'provider'       => $provider,
    'book_id'        => $book_id,
    'lang'           => $lang,
    'slug_resolved'  => (bool)$slug,
];

// Only cache when slug is resolved (otherwise retry next time)
if ($slug) {
    @file_put_contents($cache_key, json_encode($result));
}
echo json_encode($result);
