<?php
// api/aniwaves/index.php — Aniwaves / EchoVideo streaming
// Flow: TMDB ID → search aniwaves (title+year match) → server list → echovideo m3u8
// All endpoints work without cf_clearance.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config.php';

define('AW_UA',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('AW_REF',   'https://aniwaves.ru/');
define('AW_CACHE', __DIR__ . '/../../.cache/aniwaves');
define('AW_PROXY', '/api/aniwaves/proxy.php?url=');

// Preferred echovideo server IDs (4=Vidplay most reliable)
define('AW_SV_PREF', [4, 1, 2, 3]);

$type    = ($_GET['type']    ?? 'tv');
$id      = (int)($_GET['id']    ?? 0);
$aw_id   = (int)($_GET['aw_id'] ?? 0);   // مباشر من صفحة anime_watch (يتجاوز البحث)
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

@mkdir(AW_CACHE, 0755, true);

// ── Steps 1+2: TMDB lookup + aniwaves search (skipped when aw_id provided directly) ──
if (!$aw_id) {
    if (!$id) {
        echo json_encode(['ok' => false, 'error' => 'missing id or aw_id', 'servers' => []]);
        exit;
    }

    // Step 1: TMDB lookup
    $tmdb_endpoint = ($type === 'movie')
        ? TMDB_API_URL . '/movie/' . $id . '?api_key=' . TMDB_API_KEY . '&language=en-US'
        : TMDB_API_URL . '/tv/'    . $id . '?api_key=' . TMDB_API_KEY . '&language=en-US';

    $ch = curl_init($tmdb_endpoint);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_ENCODING => '']);
    $tmdb = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!$tmdb) {
        echo json_encode(['ok' => false, 'error' => 'TMDB lookup failed', 'servers' => []]);
        exit;
    }

    $title      = ($type === 'movie') ? ($tmdb['title'] ?? '') : ($tmdb['name'] ?? '');
    $orig_title = ($type === 'movie') ? ($tmdb['original_title'] ?? $title) : ($tmdb['original_name'] ?? $title);
    $tmdb_type  = ($type === 'movie') ? 'Movie' : 'TV';

    $air_year = null;
    if ($type === 'movie') {
        $air_year = (int)substr($tmdb['release_date'] ?? '', 0, 4) ?: null;
    } elseif ($season > 1) {
        $sch = curl_init(TMDB_API_URL . '/tv/' . $id . '/season/' . $season . '?api_key=' . TMDB_API_KEY);
        curl_setopt_array($sch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_ENCODING => '']);
        $sdata = json_decode(curl_exec($sch), true);
        curl_close($sch);
        $air_year = (int)substr($sdata['air_date'] ?? '', 0, 4) ?: null;
    } else {
        $air_year = (int)substr($tmdb['first_air_date'] ?? '', 0, 4) ?: null;
    }

    if (!$title) {
        echo json_encode(['ok' => false, 'error' => 'no title from TMDB', 'servers' => []]);
        exit;
    }

    // Step 2: Find aniwaves internal ID (7-day cache)
    $id_cache_file = AW_CACHE . '/id_' . $id . '_' . $type . '_s' . $season . '.json';
    $aw_id = null;

    if (file_exists($id_cache_file) && (time() - filemtime($id_cache_file)) < 86400 * 7) {
        $cached = json_decode(file_get_contents($id_cache_file), true);
        $aw_id  = $cached['aw_id'] ?? null;
    }

    if (!$aw_id) {
    // Build search queries: season-qualified first, then plain
    $queries = [];
    if ($season > 1) {
        $queries[] = $title . ' Season ' . $season;
        $queries[] = $title . ' ' . $season;
    }
    $queries[] = $title;
    if ($orig_title !== $title) {
        $queries[] = $orig_title;
    }
    $queries = array_unique($queries);

    $best_id    = null;
    $best_score = -1;

    foreach ($queries as $q) {
        $ch = curl_init('https://aniwaves.ru/ajax/anime/search');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_ENCODING       => '',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['keyword' => $q]),
            CURLOPT_HTTPHEADER     => [
                'User-Agent: '       . AW_UA,
                'Referer: '          . AW_REF,
                'X-Requested-With: XMLHttpRequest',
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$raw) continue;

        $data = json_decode($raw, true);
        $html = $data['result']['html'] ?? '';
        if (!$html) continue;

        // Parse each result card
        preg_match_all(
            '/<a class="item" href="(\/watch\/[^"]+)"([\s\S]*?)<\/a>/U',
            $html,
            $cards,
            PREG_SET_ORDER
        );

        foreach ($cards as $card) {
            $slug   = $card[1];
            $inner  = $card[2];
            $aw_id_candidate = null;
            if (preg_match('/(\d+)$/', $slug, $m)) {
                $aw_id_candidate = $m[1];
            }
            if (!$aw_id_candidate) continue;

            // Extract fields
            $en_title = '';
            if (preg_match('/data-jp="[^"]*"[^>]*>([^<]+)</', $inner, $m)) {
                $en_title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
            }
            $jp_title = '';
            if (preg_match('/data-jp="([^"]+)"/', $inner, $m)) {
                $jp_title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            }
            // Year: first 4-digit year starting with 19xx or 20xx
            $result_year = null;
            if (preg_match('/\b(19|20)\d{2}\b/', $inner, $m)) {
                $result_year = (int)$m[0];
            }
            // Type: TV / Movie / OVA etc.
            $result_type = 'TV';
            if (preg_match('/<span class="dot">(TV|Movie|TV Special|OVA|ONA|Special)/', $inner, $m)) {
                $result_type = $m[1];
            }

            // ── Score ────────────────────────────────────────────────────────
            $score = 0;

            // Title similarity against English title (weight 50)
            similar_text(mb_strtolower($title), mb_strtolower($en_title), $pct_en);
            $score += $pct_en * 0.5;

            // Also try against Japanese / original title (weight 30)
            if ($jp_title) {
                similar_text(mb_strtolower($orig_title), mb_strtolower($jp_title), $pct_jp);
                $score += $pct_jp * 0.3;
            }

            // Year match (weight 30)
            if ($air_year && $result_year) {
                if ($air_year === $result_year)        $score += 30;
                elseif (abs($air_year - $result_year) === 1) $score += 10; // adjacent year tolerance
            }

            // Type match (weight 15)
            if ($tmdb_type === 'Movie' && str_contains($result_type, 'Movie')) $score += 15;
            elseif ($tmdb_type === 'TV' && $result_type === 'TV')              $score += 15;

            if ($score > $best_score) {
                $best_score = $score;
                $best_id    = $aw_id_candidate;
            }
        }

        // Stop after first query that gave results
        if ($best_id) break;
    }

    $aw_id = $best_id;
    if ($aw_id) {
        file_put_contents($id_cache_file, json_encode(['aw_id' => $aw_id, 'score' => $best_score]));
    }
}

    if (!$aw_id) {
        echo json_encode(['ok' => false, 'error' => 'anime not found on aniwaves', 'servers' => []]);
        exit;
    }
} // end if (!$aw_id) — steps 1+2

// ── Step 3: Get server list → all link-ids (4-hour cache) ────────────────────
$ep_cache_file = AW_CACHE . '/ep_' . $aw_id . '_e' . $episode . '.json';
$sv_map = [];  // sv_id => ['link_id'=>..., 'name'=>...]

if (file_exists($ep_cache_file) && (time() - filemtime($ep_cache_file)) < 3600 * 4) {
    $cached = json_decode(file_get_contents($ep_cache_file), true);
    $sv_map = $cached['sv_map'] ?? [];
}

if (empty($sv_map)) {
    $aw_eps = ($type === 'movie') ? 0 : $episode;

    $ch = curl_init('https://aniwaves.ru/ajax/server/list?' . http_build_query([
        'servers' => $aw_id,
        'eps'     => $aw_eps,
    ]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            'User-Agent: '       . AW_UA,
            'Referer: '          . AW_REF,
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$raw) {
        echo json_encode(['ok' => false, 'error' => 'aniwaves server/list failed (' . $code . ')', 'servers' => []]);
        exit;
    }

    $data = json_decode($raw, true);
    $html = $data['result'] ?? '';

    // Parse all <li data-sv-id="N" data-link-id="...">NAME</li>
    preg_match_all(
        '/<li[^>]*data-ep-id="[^"]*"[^>]*data-sv-id="(\d+)"[^>]*data-link-id="([^"]+)"[^>]*>\s*([^<]+)\s*<\/li>/',
        $html,
        $li_matches,
        PREG_SET_ORDER
    );
    // Fallback: simpler pattern
    if (empty($li_matches)) {
        preg_match_all('/data-sv-id="(\d+)"[^>]*data-link-id="([^"]+)"[^>]*>\s*([^<]+)\s*</', $html, $m2, PREG_SET_ORDER);
        $li_matches = $m2;
    }

    if (empty($li_matches)) {
        echo json_encode(['ok' => false, 'error' => 'no servers in aniwaves response (ep ' . $episode . ')', 'servers' => []]);
        exit;
    }

    foreach ($li_matches as $m) {
        $sv_id   = (int)$m[1];
        $link_id = trim($m[2]);
        $sv_name = trim(strip_tags($m[3]));
        if (!$sv_name) $sv_name = 'Server ' . $sv_id;
        $sv_map[$sv_id] = ['link_id' => $link_id, 'name' => $sv_name];
    }

    file_put_contents($ep_cache_file, json_encode(['sv_map' => $sv_map]));
}

if (empty($sv_map)) {
    echo json_encode(['ok' => false, 'error' => 'empty server map', 'servers' => []]);
    exit;
}

// ── Step 4+5: Resolve all link-ids → tokens → m3u8 in parallel ───────────────
// Sort by preferred sv_id order
$ordered = [];
foreach (AW_SV_PREF as $sv) {
    if (isset($sv_map[$sv])) $ordered[$sv] = $sv_map[$sv];
}
foreach ($sv_map as $sv => $info) {
    if (!isset($ordered[$sv])) $ordered[$sv] = $info;
}

// Build curl_multi for ajax/sources for all servers
$mh       = curl_multi_init();
$handles  = [];
$sv_hdrs  = [
    'User-Agent: '       . AW_UA,
    'Referer: '          . AW_REF,
    'X-Requested-With: XMLHttpRequest',
    'Accept: application/json',
];

foreach ($ordered as $sv_id => $info) {
    $ch = curl_init('https://aniwaves.ru/ajax/sources?' . http_build_query([
        'id'       => $info['link_id'],
        'asi'      => 0,
        'autoPlay' => 0,
    ]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $sv_hdrs,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$sv_id] = $ch;
}

// Execute all sources requests
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

// Collect tokens
$tokens = [];  // sv_id => ['token'=>..., 'name'=>...]
foreach ($handles as $sv_id => $ch) {
    $raw  = curl_multi_getcontent($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    if ($code !== 200 || !$raw) continue;
    $src       = json_decode($raw, true);
    $embed_url = $src['result']['url'] ?? '';
    if (!$embed_url) continue;
    if (!preg_match('#/embed-\d+/([^?]+)#', $embed_url, $tm)) continue;

    $tokens[$sv_id] = ['token' => $tm[1], 'name' => $ordered[$sv_id]['name']];
}
curl_multi_close($mh);

if (empty($tokens)) {
    @unlink($ep_cache_file);
    echo json_encode(['ok' => false, 'error' => 'all aniwaves sources failed', 'servers' => []]);
    exit;
}

// ── Step 5: getSources for all tokens in parallel ─────────────────────────────
$mh2      = curl_multi_init();
$handles2 = [];
$echo_hdrs = [
    'User-Agent: ' . AW_UA,
    'Referer: '    . AW_REF,
    'Accept: application/json, */*',
];

foreach ($tokens as $sv_id => $tinfo) {
    $ch = curl_init('https://play.echovideo.ru/embed-1/getSources?id=' . rawurlencode($tinfo['token']));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $echo_hdrs,
    ]);
    curl_multi_add_handle($mh2, $ch);
    $handles2[$sv_id] = $ch;
}

do {
    curl_multi_exec($mh2, $running);
    curl_multi_select($mh2);
} while ($running > 0);

// Collect m3u8 streams
$streams = [];
foreach ($handles2 as $sv_id => $ch) {
    $raw  = curl_multi_getcontent($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_multi_remove_handle($mh2, $ch);
    curl_close($ch);

    if ($code !== 200 || !$raw) continue;
    $gsrc = json_decode($raw, true);
    $m3u8 = $gsrc['sources'] ?? '';
    if (!$m3u8 || !str_contains($m3u8, '.m3u8')) continue;

    $streams[] = [
        'label' => $tokens[$sv_id]['name'] . ' 1080p',
        'url'   => AW_PROXY . urlencode($m3u8),
        'type'  => 'hls',
    ];
}
curl_multi_close($mh2);

if (empty($streams)) {
    echo json_encode(['ok' => false, 'error' => 'no valid streams from echovideo', 'servers' => []]);
    exit;
}

// ── Return ────────────────────────────────────────────────────────────────────
echo json_encode([
    'ok'      => true,
    'servers' => [[
        'id'      => 'aniwaves',
        'name'    => 'EchoVideo',
        'streams' => $streams,
    ]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
