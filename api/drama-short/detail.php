<?php
/**
 * api/drama-short/detail.php — Fetch drama detail from narto-drama.com
 * GET: ?provider=bibishort&book_id=557&lang=en-US&title=Drama+Title
 *      or ?slug=swallowed-whole-by-my-hot-stepbrother&lang=en-US
 *
 * How slug resolution works:
 *  1. Check slug disk-cache (valid 7 days)
 *  2. Scan sections JSON cache for matching item url → extract slug
 *  3. Warm browser-like cookies (visit homepage), then follow import-URL redirect
 *
 * How episode count works (from real HTML analysis):
 *  A. <h2>Episodes (33)</h2>          ← primary, most reliable
 *  B. episode-item links /watch/{slug}/N  ← count max N
 *  C. title="Episode N" attributes    ← count max N
 *  D. fallback: 30
 */
// NOTE: When called internally (from pages via include) pass internal=1 to avoid sending JSON headers.
if (empty($_GET['internal'])) header('Content-Type: application/json');
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

// ── Cache (detail result) ─────────────────────────────────────────────────────
$cache_id  = $slug_in ?: ($provider . '_' . $book_id);
$cache_key = ND_CACHE_D . '/detail_' . md5($cache_id . $lang) . '.json';

if (is_file($cache_key) && (time() - filemtime($cache_key)) < 7200) {
    $data = json_decode(file_get_contents($cache_key), true);
    if ($data && !empty($data['ok'])) { echo json_encode($data); if (empty($_GET['internal'])) exit; }
}

$jar = ND_CACHE_D . '/cookies.txt';

// ── Cookie warm-up (visit homepage once to get Laravel session + XSRF) ────────
function nd_warm_cookies(): void {
    global $jar;
    // Re-warm if jar is missing or older than 90 minutes
    if (is_file($jar) && (time() - filemtime($jar)) < 5400) return;

    $ch = curl_init(ND_BASE_D . '/?lang=en-US&tab-provider=bibishort');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_ENCODING       => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . ND_UA_D,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: ar-IQ,ar;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding: gzip, deflate, br',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Dest: document',
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
    // Touch the jar to update mtime even if nothing was returned
    @touch($jar);
}

// ── Helper: GET with cookie jar ───────────────────────────────────────────────
function nd_get_d(string $url, string $referer = ''): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_ENCODING       => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . ND_UA_D,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: ar-IQ,ar;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding: gzip, deflate, br',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-User: ?1',
            'Sec-Fetch-Dest: document',
            'Referer: ' . ($referer ?: ND_BASE_D . '/'),
        ],
    ]);
    $body      = (string)curl_exec($ch);
    $code      = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'url' => $final_url];
}

// ── Step 1: Resolve slug ──────────────────────────────────────────────────────
$slug = $slug_in;

if (!$slug && $provider && $book_id) {

    // 1a. Check slug disk-cache
    $slug_cache = ND_CACHE_D . '/slug_' . $provider . '_' . $book_id . '.txt';
    if (is_file($slug_cache) && (time() - filemtime($slug_cache)) < 86400 * 7) {
        $slug = trim(file_get_contents($slug_cache));
    }

    // 1b. Scan sections JSON cache for matching item — url field contains import URL
    //     from which we can infer nothing directly, but we check for slug field too
    if (!$slug) {
        $section_files = glob(ND_CACHE_D . '/sections_*.json') ?: [];
        foreach ($section_files as $sf) {
            $sd = json_decode((string)file_get_contents($sf), true);
            if (empty($sd['sections'])) continue;
            foreach ($sd['sections'] as $sec) {
                foreach (($sec['items'] ?? []) as $it) {
                    if (($it['id'] ?? '') !== ($provider . ':' . $book_id)) continue;
                    // direct slug field
                    if (!empty($it['slug'])) {
                        $slug = $it['slug'];
                        @file_put_contents($slug_cache, $slug);
                        break 3;
                    }
                    // watch_url field (if present)
                    $wurl = $it['watch_url'] ?? '';
                    if ($wurl && preg_match('#/detail/watch/([^/?&#\s]+)#', $wurl, $sm)) {
                        $slug = $sm[1];
                        @file_put_contents($slug_cache, $slug);
                        break 3;
                    }
                }
            }
        }
    }

    // 1c. Follow the import URL with proper browser-like cookies
    if (!$slug) {
        nd_warm_cookies(); // ensure we have a Laravel session

        $import_url = ND_BASE_D . '/search/import?' . http_build_query([
            'provider'    => $provider,
            'book_id'     => $book_id,
            'title'       => $title,
            'lang'        => $lang,
            'target_lang' => $lang,
        ]);

        $res = nd_get_d($import_url, ND_BASE_D . '/?lang=' . $lang . '&tab-provider=' . $provider);

        // The import URL redirects to /detail/watch/{slug}?lang=...
        $final = $res['url'];
        if (preg_match('#/detail/watch/([^/?&#\s]+)#', $final, $m)) {
            $slug = $m[1];
            @file_put_contents($slug_cache, $slug);
        }

        // Fallback: try to get slug from response body (JSON or redirect hint)
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

// ── Step 2: Fetch detail page ─────────────────────────────────────────────────
$drama_title    = $title ?: 'Drama Short';
$poster         = '';
$desc           = '';
$drama_id       = 0;
$total_episodes = 0;

if ($slug) {
    $detail_url = ND_BASE_D . '/detail/watch/' . $slug . '?lang=' . $lang . '&from=home';
    $res = nd_get_d($detail_url, ND_BASE_D . '/');

    if ($res['code'] === 200 && strlen($res['body']) > 500) {
        $html = $res['body'];

        // ── Title ─────────────────────────────────────────────────────────
        if (preg_match('/<h1[^>]*class="[^"]*title[^"]*"[^>]*>\s*([^<]+)/i', $html, $m)) {
            $drama_title = trim(html_entity_decode($m[1], ENT_QUOTES));
        } elseif (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $m)) {
            $drama_title = trim(html_entity_decode($m[1], ENT_QUOTES));
        } elseif (preg_match('/<title>([^<|]+)/i', $html, $m)) {
            $drama_title = trim(preg_replace('/\s*[-|].*$/', '', html_entity_decode($m[1])));
        }

        // ── Poster (check og:image and narto poster path first) ──────────────
        // og:image: <meta property="og:image" content="https://narto-drama.com/assets/poster/XXXXX.jpg">
        if (preg_match('/<meta\s+property="og:image"\s+content="([^"]+)"/i', $html, $m)) {
            $poster = $m[1];
        } elseif (preg_match('/<meta\s+content="([^"]+)"\s+property="og:image"/i', $html, $m)) {
            $poster = $m[1];
        }
        // Poster from narto's own /assets/poster/ path (relative → absolute)
        if (!$poster && preg_match('#["\'](?:https://narto-drama\.com)?(/assets/poster/[^\s"'<>]+)#i', $html, $m)) {
            $poster = 'https://narto-drama.com' . $m[1];
        }
        // Poster from crazytalkai CDN cover
        if (!$poster && preg_match('/cover-prod\.crazytalkai\.com\/[^"]+/', $html, $m)) {
            $poster = 'https://' . $m[0];
        }

        // ── Description ──────────────────────────────────────────────────────
        if (preg_match('/<meta\s+name="description"\s+content="([^"]+)"/i', $html, $m)) {
            $raw_desc = html_entity_decode($m[1], ENT_QUOTES);
            // narto appends site name after the drama desc — strip it
            $raw_desc = preg_replace('/\.\s+Narto Drama\s*[-–].*/s', '.', $raw_desc);
            $desc = trim($raw_desc);
        }

        // ── Drama ID ────────────────────────────────────────────────────────
        if (preg_match('/media-prod\.crazytalkai\.com\/drama_(\d+)\//i', $html, $m)) {
            $drama_id = (int)$m[1];
        } elseif (preg_match('/["\']?drama_id["\']?\s*[=:]\s*["\']?(\d+)/i', $html, $m)) {
            $drama_id = (int)$m[1];
        }

        // ── Episode count — multiple patterns from real HTML ──────────────────

        // Pattern A (PRIMARY): <h2>Episodes (33)</h2>  or  Episodes&nbsp;(33)
        if (preg_match('/Episodes\s*[\x{00a0}\s]*\((\d+)\)/ui', $html, $m)) {
            $total_episodes = (int)$m[1];
        }

        // Pattern B: episode-item links like /detail/watch/{slug}/N
        if (!$total_episodes && preg_match_all(
            '#class="episode-item"[^>]*href="[^"]+/detail/watch/' . preg_quote($slug, '#') . '/(\d+)#',
            $html, $m
        )) {
            $ep_nums = array_map('intval', $m[1]);
            if ($ep_nums) $total_episodes = max($ep_nums);
        }

        // Pattern B2: any /detail/watch/{slug}/N in the document
        if (!$total_episodes && preg_match_all(
            '#/detail/watch/' . preg_quote($slug, '#') . '/(\d+)#',
            $html, $m
        )) {
            $ep_nums = array_map('intval', $m[1]);
            if ($ep_nums) $total_episodes = max($ep_nums);
        }

        // Pattern C: title="Episode N" attributes on episode-item links
        if (!$total_episodes && preg_match_all('/class="episode-item"[^>]+title="Episode\s+(\d+)"/i', $html, $m)) {
            $ep_nums = array_map('intval', $m[1]);
            if ($ep_nums) $total_episodes = max($ep_nums);
        }

        // Pattern D: JSON fields embedded in page
        if (!$total_episodes && preg_match(
            '/"(?:total_episodes|totalEpisodes|episode_count|episodeCount)"\s*:\s*(\d+)/i',
            $html, $m
        )) {
            $total_episodes = (int)$m[1];
        }

        // Pattern E: "N episodes" or "N حلقة" text
        if (!$total_episodes && preg_match('/(\d+)\s*(?:episodes?|حلقة|حلقات)/i', $html, $m)) {
            $ep = (int)$m[1];
            if ($ep >= 1 && $ep <= 999) $total_episodes = $ep;
        }
    }
}

// ── Fallback: always provide something usable ─────────────────────────────────
if (!$total_episodes) {
    $total_episodes = 30;
}

$result = [
    'ok'             => true,
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

// Cache only when slug was resolved
if ($slug) {
    @file_put_contents($cache_key, json_encode($result));
}

// If this is internal include, avoid echoing JSON headers twice — just return the JSON string
if (!empty($_GET['internal'])) {
    echo json_encode($result);
} else {
    echo json_encode($result);
}
