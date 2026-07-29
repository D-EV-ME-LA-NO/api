<?php
$page_title = 'أنمي';
$active     = 'anime';

$page = max(1, (int)($_GET['page'] ?? 1));

// ── استدعاء browse API داخلياً ────────────────────────────────────────────────
function aw_browse(int $page): array {
    define('AW_UA_B',    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
    $cache_dir  = __DIR__ . '/../.cache/aniwaves/meta';
    @mkdir($cache_dir, 0755, true);
    $cache_file = $cache_dir . '/browse_p' . $page . '.json';

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 1800) {
        $d = json_decode(file_get_contents($cache_file), true);
        if (!empty($d['results'])) return $d;
    }

    $ch = curl_init('https://aniwaves.ru/filter?page=' . $page);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['User-Agent: ' . AW_UA_B, 'Accept: text/html,*/*'],
    ]);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$html) return ['ok' => false, 'results' => [], 'last_page' => 1];

    // Split by <div class="item" — each chunk is one anime card
    $chunks = preg_split('/<div class="item[^"]*">/', $html);
    $seen = []; $results = [];
    foreach (array_slice($chunks, 1) as $chunk) {
        // slug + ID
        if (!preg_match('/href="(\/watch\/([^"]+))"/', $chunk, $hm)) continue;
        $slug = $hm[1];
        if (!preg_match('/(\d+)$/', $slug, $im)) continue;
        $aw_id = $im[1];
        if (isset($seen[$aw_id])) continue; $seen[$aw_id] = true;
        // poster
        $poster = ''; if (preg_match('/src="(https:\/\/static\.aniwaves\.ru\/[^"]+)"/', $chunk, $pm)) $poster = $pm[1];
        // English title (from .name.d-title link text, not img alt)
        $en = '';
        if (preg_match('/class="name d-title"[^>]*>([^<]+)</', $chunk, $nm)) $en = trim($nm[1]);
        if (!$en && preg_match('/alt="([^"]+)"/', $chunk, $am)) $en = trim(preg_replace('/ Japanese english subbed$/i', '', $am[1]));
        // Japanese title
        $jp = ''; if (preg_match('/data-jp="([^"]+)"/', $chunk, $jm)) $jp = $jm[1];
        // type
        $type = 'TV'; if (preg_match('/class="right">([^<]+)<\/div>/', $chunk, $tm)) $type = trim($tm[1]);
        // episode counts
        $sub_eps = null; if (preg_match('/ep-status sub[^>]*>[\s\S]*?<span>\s*(\d+)\s*<\/span>/', $chunk, $em)) $sub_eps = (int)$em[1];
        $total_eps = null; if (preg_match('/ep-status total[^>]*>[\s\S]*?<span>([^<]+)<\/span>/', $chunk, $em)) { $t = trim($em[1]); $total_eps = is_numeric($t) ? (int)$t : null; }
        $item = ['aw_id' => $aw_id, 'slug' => $slug, 'title' => $en, 'jp_title' => $jp, 'poster' => $poster, 'type' => $type, 'sub_eps' => $sub_eps, 'total_eps' => $total_eps];
        $results[] = $item;
        $mf = $cache_dir . '/item_' . $aw_id . '.json';
        if (!file_exists($mf) || (time() - filemtime($mf)) > 86400) file_put_contents($mf, json_encode($item));
    }
    $last_page = 1;
    $pp = []; preg_match_all('/href="\/filter\?page=(\d+)"/', $html, $lm);
    foreach ($lm[1] as $p) $pp[] = (int)$p;
    if ($pp) $last_page = max($pp);

    $out = ['ok' => true, 'page' => $page, 'last_page' => $last_page, 'results' => $results];
    file_put_contents($cache_file, json_encode($out, JSON_UNESCAPED_SLASHES));
    return $out;
}

$data       = aw_browse($page);
$results    = $data['results']   ?? [];
$last_page  = (int)($data['last_page'] ?? 1);
$total_pages = min($last_page, 500);

// ── anikuro trending (صفحة 1 فقط في قسم منفصل) ───────────────────────────────
function ak_browse_home(): array {
    $cache_dir  = __DIR__ . '/../.cache/anikuro';
    $cache_file = $cache_dir . '/browse_p1.json';
    @mkdir($cache_dir, 0755, true);
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 1800) {
        $d = json_decode(file_get_contents($cache_file), true);
        if (!empty($d['results'])) return $d['results'];
    }
    $ch = curl_init('https://anikuro.ru/api/v1/discovery/trending?page=1&limit=24');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
            'Referer: https://anikuro.ru/', 'Accept: application/json',
        ],
    ]);
    $raw = curl_exec($ch); curl_close($ch);
    if (!$raw) return [];
    $d = json_decode($raw, true);
    $raw_items = $d['data']['items'] ?? [];
    $items = [];
    $meta_dir = $cache_dir . '/meta';
    @mkdir($meta_dir, 0755, true);
    foreach ($raw_items as $item) {
        $ak_id  = (int)($item['id'] ?? 0); if (!$ak_id) continue;
        $title  = $item['title']['english'] ?? ($item['title']['romaji'] ?? ($item['title']['userPreferred'] ?? ''));
        $poster = $item['images']['cover']  ?? ($item['coverImage']['large'] ?? '');
        $ep_cnt = $item['episodes'] ?? null;
        $year   = $item['seasonYear'] ?? null;
        $slug   = 'ak-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($title ?: 'anime')) . '-' . $ak_id;
        $entry  = ['ak_id' => $ak_id, 'title' => $title, 'poster' => $poster, 'episodes' => $ep_cnt, 'year' => $year, 'slug' => $slug];
        $items[] = $entry;
        $mf = $meta_dir . '/ak_' . $ak_id . '.json';
        if (!file_exists($mf)) file_put_contents($mf, json_encode($entry));
    }
    file_put_contents($cache_file, json_encode(['ok'=>true,'page'=>1,'results'=>$items], JSON_UNESCAPED_SLASHES));
    return $items;
}
$ak_results = ak_browse_home();

// ── Batch TMDB ID lookup (للربط بصفحة التفاصيل) ──────────────────────────────
$_all_anime_titles = array_filter(array_unique(array_merge(
    array_column($ak_results, 'title'),
    array_column($results,    'title')
)));
$_anime_tmdb_map = anime_tmdb_ids_batch($_all_anime_titles);

include __DIR__ . '/../includes/header.php';

// ── دمج النتائج: صفحة 1 = AniKuro أولاً ثم Aniwaves ، بقية الصفحات = Aniwaves فقط
$_merged = [];
if ($page === 1) {
    foreach ($ak_results as $it) {
        $title = $it['title'] ?: ('Anime ' . $it['ak_id']);
        $_tmdb = $_anime_tmdb_map[$title] ?? null;
        $href  = $_tmdb ? '/tv-show/' . $_tmdb['slug'] : '/watch/anime/' . htmlspecialchars($it['slug']) . '/1';
        $ep_lbl = $it['episodes'] ? $it['episodes'] . ' حلقة' : '';
        $_merged[] = ['poster' => $it['poster'], 'title' => $title, 'href' => $href, 'ep_lbl' => $ep_lbl, 'type' => 'Anime'];
    }
}
foreach ($results as $it) {
    $title = $it['title'] ?: 'Anime ' . $it['aw_id'];
    $_tmdb = $_anime_tmdb_map[$title] ?? null;
    $href  = $_tmdb ? '/tv-show/' . $_tmdb['slug'] : '/watch/anime/aw-' . $it['aw_id'] . '/1';
    $ep_lbl = $it['sub_eps'] !== null ? $it['sub_eps'] . ' حلقة' : '';
    $_merged[] = ['poster' => $it['poster'], 'title' => $title, 'href' => $href, 'ep_lbl' => $ep_lbl, 'type' => ($it['type'] ?: 'Anime')];
}
?>

<!-- ═══════════════════ Anime ═══════════════════ -->
<section class="browse">
  <div class="browse-head">
    <div>
      <h1 class="browse-title"><i class="fa-solid fa-dragon" style="color:var(--primary)"></i> أنمي</h1>
      <p class="browse-sub"><?= count($_merged) ?> عنوان في هذه الصفحة</p>
    </div>
  </div>

  <div class="grid">
    <?php foreach ($_merged as $it): ?>
      <a class="card card-grid" href="<?= htmlspecialchars($it['href']) ?>">
        <div class="card-poster">
          <?php if ($it['poster']): ?>
            <img loading="lazy" src="<?= htmlspecialchars($it['poster']) ?>" alt="<?= htmlspecialchars($it['title']) ?>" />
          <?php else: ?>
            <div style="width:100%;height:100%;background:#1a1a2e;display:flex;align-items:center;justify-content:center;">
              <i class="fa-solid fa-dragon" style="font-size:2rem;color:#444"></i>
            </div>
          <?php endif; ?>
          <div class="card-overlay">
            <div class="play-circle"><i class="fa-solid fa-play"></i></div>
          </div>
          <?php if ($it['ep_lbl']): ?>
            <span class="card-rating"><i class="fa-solid fa-tv"></i> <?= htmlspecialchars($it['ep_lbl']) ?></span>
          <?php endif; ?>
          <span class="card-type"><?= htmlspecialchars($it['type']) ?></span>
        </div>
        <div class="card-meta">
          <h3><?= htmlspecialchars($it['title']) ?></h3>
        </div>
      </a>
    <?php endforeach; ?>

    <?php if (empty($results)): ?>
      <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:#888">
        <i class="fa-solid fa-circle-exclamation" style="font-size:2rem;margin-bottom:12px;display:block"></i>
        تعذّر تحميل الأنمي — حاول مجدداً
      </div>
    <?php endif; ?>
  </div>

  <?php if ($total_pages > 1): ?>
  <div class="pager">
    <?php $build = fn($p) => '/anime?page=' . $p; ?>
    <?php if ($page > 1): ?>
      <a class="pager-btn" href="<?= $build($page - 1) ?>"><i class="fa-solid fa-chevron-left"></i> السابق</a>
    <?php endif; ?>
    <span class="pager-info">صفحة <?= $page ?> من <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?>
      <a class="pager-btn" href="<?= $build($page + 1) ?>">التالي <i class="fa-solid fa-chevron-right"></i></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
