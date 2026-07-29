<?php
$q    = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));

$results     = [];
$total_pages = 0;
$anime_items = [];

if ($q !== '') {

    // ── TMDB ─────────────────────────────────────────────────────────────────
    $tmdb_raw = '';
    if (function_exists('curl_init')) {
        $tmdb_url = TMDB_API_URL . '/search/multi?' . http_build_query([
            'api_key'       => TMDB_API_KEY,
            'query'         => $q,
            'page'          => $page,
            'language'      => 'en-US',
            'include_adult' => 'false',
        ]);
        $ch = curl_init($tmdb_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $tmdb_raw = curl_exec($ch);
        if (curl_errno($ch)) $tmdb_raw = '';
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        // fallback: file_get_contents
        $tmdb_url  = TMDB_API_URL . '/search/multi?' . http_build_query([
            'api_key'       => TMDB_API_KEY,
            'query'         => $q,
            'page'          => $page,
            'language'      => 'en-US',
            'include_adult' => 'false',
        ]);
        $ctx = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true]]);
        $tmdb_raw = @file_get_contents($tmdb_url, false, $ctx) ?: '';
    }

    // Parse TMDB — نحذف الأنمي (genre 16 + JP) لأنه يجي من aniwaves
    $tmdb_data = $tmdb_raw ? (json_decode($tmdb_raw, true) ?? []) : [];
    $results   = array_values(array_filter(
        $tmdb_data['results'] ?? [],
        function ($r) {
            if (!in_array($r['media_type'] ?? '', ['movie', 'tv'], true)) return false;
            $genres  = $r['genre_ids']      ?? [];
            $origins = $r['origin_country'] ?? [];
            if (in_array(16, $genres) && in_array('JP', $origins)) return false;
            return true;
        }
    ));
    $total_pages = min((int)($tmdb_data['total_pages'] ?? 1), 50);

    // ── Aniwaves ─────────────────────────────────────────────────────────────
    // طلب منفصل — فشله لا يوقف الصفحة
    $aw_raw = '';
    try {
        if (function_exists('curl_init')) {
            $ch_aw = curl_init('https://aniwaves.ru/ajax/anime/search');
            curl_setopt_array($ch_aw, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['keyword' => $q]),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36',
                    'Referer: https://aniwaves.ru/',
                    'X-Requested-With: XMLHttpRequest',
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
            ]);
            $aw_raw = curl_exec($ch_aw);
            if (curl_errno($ch_aw)) $aw_raw = '';
            curl_close($ch_aw);
        }
    } catch (\Throwable $e) {
        $aw_raw = '';
    }

    // Parse aniwaves
    if ($aw_raw) {
        $aw_data = @json_decode($aw_raw, true);
        $aw_html = $aw_data['result']['html'] ?? '';
        if ($aw_html) {
            preg_match_all(
                '/<a class="item"\s+href="(\/watch\/[^"]+)"[^>]*>([\s\S]*?)<\/a>/U',
                $aw_html, $cards, PREG_SET_ORDER
            );
            foreach ($cards as $card) {
                $slug  = $card[1];
                $inner = $card[2];
                if (!preg_match('/(\d+)$/', $slug, $m)) continue;
                $aw_id = (int)$m[1];

                $en_title = '';
                if (preg_match('/data-jp="[^"]*"[^>]*>\s*([^<]+)\s*</', $inner, $m))
                    $en_title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
                if (!$en_title) continue;

                $poster = '';
                if (preg_match('/<img[^>]+src="([^"]+)"/', $inner, $m))
                    $poster = preg_replace('#/\d+x\d+/#', '/200x280/', $m[1]);

                $rating = '';
                if (preg_match('/<i class="fas fa-star"><\/i>\s*([0-9.]+)/', $inner, $m))
                    $rating = $m[1];

                $year = '';
                if (preg_match('/\b(19|20)\d{2}\b/', $inner, $m)) $year = $m[0];

                $type_dot = 'Anime';
                if (preg_match('/<span class="dot">(TV|Movie|OVA|ONA|TV Special|Special)<\/span>/', $inner, $m))
                    $type_dot = $m[1];

                $watch_href = '/api/anime-lookup.php?go=1&aw_id=' . $aw_id
                            . '&title=' . urlencode($en_title) . '&season=1&episode=1';

                $anime_items[] = compact('aw_id', 'en_title', 'poster', 'rating', 'year', 'type_dot', 'watch_href');
            }
        }
    }
}

$page_title = 'بحث · ' . htmlspecialchars($q);
$active     = '';
include __DIR__ . '/../includes/header.php';
?>

<section class="browse">
  <div class="browse-head">
    <div>
      <h1 class="browse-title">بحث</h1>
      <p class="browse-sub">
        <?php if ($q !== ''): ?>
          نتائج &ldquo;<?= htmlspecialchars($q) ?>&rdquo;
          <?php if ($anime_items || $results): ?>
            &nbsp;—&nbsp;
            <?= count($results) ?> TMDB
            <?php if ($anime_items): ?>&nbsp;+&nbsp;<?= count($anime_items) ?> أنمي<?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
          اكتب في شريط البحث للبدء
        <?php endif; ?>
      </p>
    </div>
  </div>

  <?php if ($anime_items): ?>
  <!-- ═══════════ قسم الأنمي من aniwaves ═══════════ -->
  <div style="margin-bottom:32px">
    <h2 style="font-size:1rem;font-weight:700;color:#c084fc;margin-bottom:14px;display:flex;align-items:center;gap:8px">
      <i class="fa-solid fa-dragon"></i> أنمي — Aniwaves
    </h2>
    <div class="grid">
      <?php foreach ($anime_items as $it): ?>
        <a class="card card-grid" href="<?= htmlspecialchars($it['watch_href']) ?>">
          <div class="card-poster">
            <?php if ($it['poster']): ?>
              <img loading="lazy" src="<?= htmlspecialchars($it['poster']) ?>" alt="<?= htmlspecialchars($it['en_title']) ?>" />
            <?php else: ?>
              <div style="width:100%;height:100%;background:#1a1a2e;display:flex;align-items:center;justify-content:center">
                <i class="fa-solid fa-dragon" style="font-size:2rem;color:#444"></i>
              </div>
            <?php endif; ?>
            <div class="card-overlay"><div class="play-circle"><i class="fa-solid fa-play"></i></div></div>
            <?php if ($it['rating']): ?>
              <span class="card-rating"><i class="fa-solid fa-star"></i> <?= htmlspecialchars($it['rating']) ?></span>
            <?php endif; ?>
            <span class="card-type" style="background:#7c3aed"><?= htmlspecialchars($it['type_dot']) ?></span>
          </div>
          <div class="card-meta">
            <h3><?= htmlspecialchars($it['en_title']) ?></h3>
            <?php if ($it['year']): ?>
              <div class="card-sub"><?= htmlspecialchars($it['year']) ?></div>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($results): ?>
  <!-- ═══════════ قسم TMDB ═══════════ -->
  <?php if ($anime_items): ?>
    <h2 style="font-size:1rem;font-weight:700;color:#888;margin-bottom:14px;display:flex;align-items:center;gap:8px">
      <i class="fa-solid fa-film"></i> أفلام ومسلسلات — TMDB
    </h2>
  <?php endif; ?>
  <div class="grid">
    <?php foreach ($results as $it):
      $t  = $it['media_type'];
      $n  = $it['title'] ?? $it['name'] ?? '';
      $sg = $it['id'] . '-' . slugify($n);
      $h  = '/' . ($t === 'tv' ? 'tv-show' : 'movie') . '/' . $sg;
      $yr = fmt_year($it['release_date'] ?? $it['first_air_date'] ?? '');
      $rt = star((float)($it['vote_average'] ?? 0));
    ?>
      <a class="card card-grid" href="<?= $h ?>">
        <div class="card-poster">
          <img loading="lazy" src="<?= img_url($it['poster_path'] ?? null, 'w342') ?>" alt="<?= htmlspecialchars($n) ?>" />
          <div class="card-overlay"><div class="play-circle"><i class="fa-solid fa-play"></i></div></div>
          <span class="card-rating"><i class="fa-solid fa-star"></i> <?= $rt ?></span>
          <span class="card-type"><?= $t === 'tv' ? 'TV' : 'Movie' ?></span>
        </div>
        <div class="card-meta">
          <h3><?= htmlspecialchars($n) ?></h3>
          <div class="card-sub"><?= $yr ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($total_pages > 1): ?>
  <div class="pagination" style="margin-top:32px;display:flex;justify-content:center;gap:8px;flex-wrap:wrap">
    <?php if ($page > 1): ?>
      <a class="btn-outline" href="?q=<?= urlencode($q) ?>&page=<?= $page-1 ?>">&laquo; السابق</a>
    <?php endif; ?>
    <?php
      $start = max(1, $page - 2);
      $end   = min($total_pages, $page + 2);
      for ($p = $start; $p <= $end; $p++):
    ?>
      <a class="btn-outline<?= $p === $page ? ' active' : '' ?>"
         href="?q=<?= urlencode($q) ?>&page=<?= $p ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($page < $total_pages): ?>
      <a class="btn-outline" href="?q=<?= urlencode($q) ?>&page=<?= $page+1 ?>">التالي &raquo;</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php elseif ($q !== '' && !$anime_items): ?>
    <p style="text-align:center;color:#888;padding:60px 0">لا توجد نتائج لـ &ldquo;<?= htmlspecialchars($q) ?>&rdquo;</p>
  <?php endif; ?>

</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
