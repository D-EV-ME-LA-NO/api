<?php
/**
 * api/drama-short/all.php
 * Aggregates drama content from multiple providers using curl_multi.
 * GET: ?page=1&lang=en-US
 *
 * page=1 → providers[0-3]
 * page=2 → providers[4-7]  … etc.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

$lang     = preg_replace('/[^a-z0-9_-]/i', '', $_GET['lang'] ?? 'en-US');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 4; // providers per page

define('DS_ALL_UA',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('DS_ALL_BASE',  'https://narto-drama.com');
define('DS_ALL_CACHE', __DIR__ . '/../../.cache/drama-short');
@mkdir(DS_ALL_CACHE, 0755, true);

// ── Fetch ALL providers from any cached section file ──────────────────────────
function ds_all_get_providers(string $lang): array {
    // Check if we have any cached file with providers list
    $files = glob(DS_ALL_CACHE . '/sections_*.json') ?: [];
    foreach ($files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!empty($d['providers']) && is_array($d['providers'])) {
            return $d['providers'];
        }
    }
    // Fallback: fetch from API to get providers
    $url = DS_ALL_BASE . '/home/providers/sections?' . http_build_query([
        'provider' => 'bibishort', 'lang' => $lang, 'target_lang' => $lang,
        '_cb' => (string)(time() * 1000),
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . DS_ALL_UA,
            'Accept: */*', 'X-Requested-With: XMLHttpRequest',
            'Referer: ' . DS_ALL_BASE . '/?lang=' . $lang . '&tab-provider=bibishort',
        ],
    ]);
    $raw = curl_exec($ch); curl_close($ch);
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return $d['providers'] ?? [];
}

// ── Fetch one provider via curl handle ────────────────────────────────────────
function ds_all_make_ch(string $provider, string $lang): \CurlHandle|false {
    $cache_key = DS_ALL_CACHE . '/sections_' . md5($provider . $lang) . '.json';
    // Use cache if fresh (< 30 min)
    if (is_file($cache_key) && (time() - filemtime($cache_key)) < 1800) {
        return false; // signal: use cache
    }
    $url = DS_ALL_BASE . '/home/providers/sections?' . http_build_query([
        'provider' => $provider, 'lang' => $lang, 'target_lang' => $lang,
        '_cb' => (string)(time() * 1000),
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_ENCODING => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . DS_ALL_UA,
            'Accept: */*', 'X-Requested-With: XMLHttpRequest',
            'Referer: ' . DS_ALL_BASE . '/?lang=' . $lang . '&tab-provider=' . $provider,
        ],
    ]);
    return $ch;
}

function ds_all_parse_provider(string $provider, ?string $raw): array {
    if ($raw === null) {
        // Load from cache
        $cache_key = DS_ALL_CACHE . '/sections_' . md5($provider . 'en-US') . '.json';
        if (!is_file($cache_key)) return [];
        $raw = file_get_contents($cache_key);
    }
    $d = json_decode($raw, true);
    if (!is_array($d) || empty($d['sections'])) return [];
    // Save to cache
    $cache_key = DS_ALL_CACHE . '/sections_' . md5($provider . 'en-US') . '.json';
    @file_put_contents($cache_key, json_encode($d));
    // Flatten all sections items
    $items = [];
    foreach ($d['sections'] as $sec) {
        foreach (($sec['items'] ?? []) as $it) {
            $it['_provider'] = $provider;
            $it['_section']  = $sec['tab_label'] ?? '';

            // ── Cache slug from watch_url/url for use by detail.php ──────────
            $item_id = $it['id'] ?? '';
            $parts   = explode(':', $item_id);
            $bid     = $parts[1] ?? '';
            if (!$bid) {
                preg_match('/book_id=(\d+)/', $it['url'] ?? $it['watch_url'] ?? '', $bm);
                $bid = $bm[1] ?? '';
            }
            if ($bid) {
                $slug_file = DS_ALL_CACHE . '/slug_' . $provider . '_' . $bid . '.txt';
                if (!is_file($slug_file)) {
                    // Try watch_url field first, then url
                    $wurl = $it['watch_url'] ?? $it['url'] ?? '';
                    if ($wurl && preg_match('#/detail/watch/([^/?&#\s]+)#', $wurl, $sm)) {
                        @file_put_contents($slug_file, $sm[1]);
                        $it['_slug'] = $sm[1];
                    } elseif (!empty($it['slug'])) {
                        @file_put_contents($slug_file, $it['slug']);
                        $it['_slug'] = $it['slug'];
                    }
                }
            }

            $items[] = $it;
        }
    }
    return $items;
}

// ── Main ──────────────────────────────────────────────────────────────────────
$all_providers = ds_all_get_providers($lang);
if (empty($all_providers)) {
    echo json_encode(['ok' => false, 'error' => 'No providers found', 'items' => [], 'hasMore' => false]);
    exit;
}

$total_providers = count($all_providers);
$offset          = ($page - 1) * $per_page;
$batch           = array_slice($all_providers, $offset, $per_page);
$has_more        = ($offset + $per_page) < $total_providers;

if (empty($batch)) {
    echo json_encode(['ok' => true, 'items' => [], 'hasMore' => false, 'page' => $page, 'total_providers' => $total_providers]);
    exit;
}

// ── Parallel fetch using curl_multi ──────────────────────────────────────────
$mh         = curl_multi_init();
$handles    = [];
$cached     = []; // provider => items (from cache)

foreach ($batch as $prov_info) {
    $prov = $prov_info['key'];
    $ch   = ds_all_make_ch($prov, $lang);
    if ($ch === false) {
        // Already cached — read directly
        $cached[$prov] = ds_all_parse_provider($prov, null);
    } else {
        $handles[$prov] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
}

// Run multi-curl
$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$all_items = [];
// Collect cached items first
foreach ($cached as $prov => $items) {
    $all_items = array_merge($all_items, $items);
}
// Collect fetched results
foreach ($handles as $prov => $ch) {
    $raw = curl_multi_getcontent($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
    if ($code === 200 && $raw) {
        $items = ds_all_parse_provider($prov, $raw);
        // Save fresh cache
        $d = json_decode($raw, true);
        if (!empty($d['sections'])) {
            $cache_key = DS_ALL_CACHE . '/sections_' . md5($prov . $lang) . '.json';
            @file_put_contents($cache_key, $raw);
        }
        $all_items = array_merge($all_items, $items);
    }
}
curl_multi_close($mh);

// ── Format items for output ───────────────────────────────────────────────────
$out = [];
foreach ($all_items as $it) {
    $item_id = $it['id'] ?? '';
    $parts   = explode(':', $item_id);
    $prov    = $parts[0] ?: ($it['_provider'] ?? 'unknown');
    $bid     = $parts[1] ?? '';
    if (!$bid) {
        preg_match('/book_id=(\d+)/', $it['url'] ?? $it['watch_url'] ?? '', $m);
        $bid = $m[1] ?? '';
    }
    if (!$bid) continue;

    $out[] = [
        'id'          => $item_id,
        'provider'    => $prov,
        'book_id'     => $bid,
        'title'       => $it['title'] ?? '',
        'poster_url'  => $it['poster_url'] ?? '',
        'category'    => $it['category_name'] ?? ($it['_section'] ?? ''),
        'provider_label' => $it['_section'] ?? '',
        'is_adult'    => !empty($it['is_adult']),
    ];
}

// Deduplicate by provider:book_id
$seen = [];
$deduped = [];
foreach ($out as $item) {
    $key = $item['provider'] . ':' . $item['book_id'];
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $deduped[] = $item;
    }
}

echo json_encode([
    'ok'              => true,
    'items'           => $deduped,
    'hasMore'         => $has_more,
    'page'            => $page,
    'total_providers' => $total_providers,
    'providers_in_page' => array_column($batch, 'label'),
]);
