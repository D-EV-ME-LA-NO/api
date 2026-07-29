<?php
/**
 * api/aniwaves/browse.php
 * Scrapes aniwaves.ru/filter?page=N and returns anime list as JSON.
 * Also writes a per-item metadata cache used by the watch page.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('AW_UA',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('AW_META',  __DIR__ . '/../../.cache/aniwaves/meta');
define('AW_BROWSE_TTL', 1800); // 30 min

$page = max(1, (int)($_GET['page'] ?? 1));
@mkdir(AW_META, 0755, true);

$cache_file = AW_META . '/browse_p' . $page . '.json';
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < AW_BROWSE_TTL) {
    echo file_get_contents($cache_file);
    exit;
}

$ch = curl_init('https://aniwaves.ru/filter?page=' . $page);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        'User-Agent: ' . AW_UA,
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ],
]);
$html = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$html) {
    echo json_encode(['ok' => false, 'error' => 'aniwaves filter failed (' . $code . ')']);
    exit;
}

// ── Parse cards ───────────────────────────────────────────────────────────────
// Each card: <a href="/watch/slug-ID">..poster..title..meta..</a>
// Split HTML by <div class="item" — each chunk is one anime card
$chunks  = preg_split('/<div class="item[^"]*">/', $html);
$seen    = [];
$results = [];

foreach (array_slice($chunks, 1) as $chunk) {
    // slug + AW ID
    if (!preg_match('/href="(\/watch\/([^"]+))"/', $chunk, $hm)) continue;
    $slug  = $hm[1];
    if (!preg_match('/(\d+)$/', $slug, $im)) continue;
    $aw_id = $im[1];
    if (isset($seen[$aw_id])) continue;
    $seen[$aw_id] = true;

    // Poster
    $poster = '';
    if (preg_match('/src="(https:\/\/static\.aniwaves\.ru\/[^"]+)"/', $chunk, $pm)) {
        $poster = $pm[1];
    }

    // English title from .name.d-title link (preferred) then img alt
    $en = '';
    if (preg_match('/class="name d-title"[^>]*>([^<]+)</', $chunk, $nm)) {
        $en = trim($nm[1]);
    }
    if (!$en && preg_match('/alt="([^"]+)"/', $chunk, $am)) {
        $en = trim(preg_replace('/ Japanese english subbed$/i', '', $am[1]));
    }

    // Japanese title
    $jp = '';
    if (preg_match('/data-jp="([^"]+)"/', $chunk, $jm)) $jp = $jm[1];

    // Type (TV / Movie / OVA …)
    $type = 'TV';
    if (preg_match('/class="right">([^<]+)<\/div>/', $chunk, $tm)) {
        $type = trim($tm[1]);
    }

    // Episode counts
    $sub_eps = null;
    if (preg_match('/ep-status sub[^>]*>[\s\S]*?<span>\s*(\d+)\s*<\/span>/', $chunk, $em)) {
        $sub_eps = (int)$em[1];
    }
    $total_eps = null;
    if (preg_match('/ep-status total[^>]*>[\s\S]*?<span>([^<]+)<\/span>/', $chunk, $em)) {
        $t = trim($em[1]);
        $total_eps = is_numeric($t) ? (int)$t : null;
    }

    $item = [
        'aw_id'     => $aw_id,
        'slug'      => $slug,
        'title'     => $en,
        'jp_title'  => $jp,
        'poster'    => $poster,
        'type'      => $type,
        'sub_eps'   => $sub_eps,
        'total_eps' => $total_eps,
    ];
    $results[] = $item;

    // Per-item metadata cache (used by watch page)
    $meta_file = AW_META . '/item_' . $aw_id . '.json';
    if (!file_exists($meta_file) || (time() - filemtime($meta_file)) > 86400) {
        file_put_contents($meta_file, json_encode($item));
    }
}

// ── Last page number ──────────────────────────────────────────────────────────
$last_page = 1;
$all_pages = [];
preg_match_all('/href="\/filter\?page=(\d+)"/', $html, $pm);
foreach ($pm[1] as $p) $all_pages[] = (int)$p;
if ($all_pages) $last_page = max($all_pages);

$output = json_encode([
    'ok'        => true,
    'page'      => $page,
    'last_page' => $last_page,
    'results'   => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

file_put_contents($cache_file, $output);
echo $output;
