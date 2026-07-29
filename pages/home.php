<?php
$page_title = 'Home';
$active     = 'home';

// ── Existing data ────────────────────────────────────────────────────────────
$trending_all   = tmdb_trending('all', 'week')['results']      ?? [];
$trending_movie = tmdb_movie_list('popular')['results']        ?? [];
$top_rated      = tmdb_movie_list('top_rated')['results']      ?? [];
$upcoming       = tmdb_movie_list('upcoming')['results']       ?? [];
$tv_popular     = tmdb_tv_list('popular')['results']           ?? [];
$tv_top         = tmdb_tv_list('top_rated')['results']         ?? [];

// ── New data ─────────────────────────────────────────────────────────────────
$now_playing    = tmdb_movie_list('now_playing')['results']    ?? [];
$tv_trending    = tmdb_trending('tv', 'week')['results']       ?? [];
$tv_airing      = tmdb_tv_list('on_the_air')['results']        ?? [];

$action_movies  = tmdb_discover('movie', [
    'with_genres' => '28',
    'sort_by'     => 'popularity.desc',
    'include_adult' => 'false',
])['results'] ?? [];

$scifi_movies   = tmdb_discover('movie', [
    'with_genres' => '878',
    'sort_by'     => 'popularity.desc',
    'include_adult' => 'false',
])['results'] ?? [];

$horror_movies  = tmdb_discover('movie', [
    'with_genres' => '27',
    'sort_by'     => 'popularity.desc',
    'include_adult' => 'false',
])['results'] ?? [];

// ── Anime from AniKuro ───────────────────────────────────────────────────────
function home_ak_fetch(): array {
    $cache_file = __DIR__ . '/../.cache/anikuro/browse_p1.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 1800) {
        $d = json_decode(file_get_contents($cache_file), true);
        if (!empty($d['results'])) return $d['results'];
    }
    $ch = curl_init('https://anikuro.ru/api/v1/discovery/trending?page=1&limit=20');
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
    $meta_dir = __DIR__ . '/../.cache/anikuro/meta';
    @mkdir($meta_dir, 0755, true);
    foreach ($raw_items as $item) {
        $ak_id  = (int)($item['id'] ?? 0); if (!$ak_id) continue;
        $title  = $item['title']['english'] ?? ($item['title']['romaji'] ?? ($item['title']['userPreferred'] ?? ''));
        $poster = $item['images']['cover']  ?? ($item['coverImage']['large'] ?? '');
        $ep_cnt = $item['episodes'] ?? null;
        $slug   = 'ak-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($title ?: 'anime')) . '-' . $ak_id;
        $entry  = ['ak_id' => $ak_id, 'title' => $title, 'poster' => $poster, 'episodes' => $ep_cnt, 'slug' => $slug];
        $items[] = $entry;
        $mf = $meta_dir . '/ak_' . $ak_id . '.json';
        if (!file_exists($mf)) file_put_contents($mf, json_encode($entry));
    }
    return $items;
}
$anime_anikuro = home_ak_fetch();

// ── Anime from Aniwaves (not TMDB) ───────────────────────────────────────────
function home_aw_fetch(int $page = 1): array {
    $cache_dir  = __DIR__ . '/../.cache/aniwaves/meta';
    @mkdir($cache_dir, 0755, true);
    $cache_file = $cache_dir . '/browse_p' . $page . '.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 1800) {
        $d = json_decode(file_get_contents($cache_file), true);
        if (!empty($d['results'])) return $d['results'];
    }
    $ch = curl_init('https://aniwaves.ru/filter?page=' . $page);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
            'Accept: text/html,*/*',
        ],
    ]);
    $html = curl_exec($ch); curl_close($ch);
    if (!$html) return [];
    $chunks = preg_split('/<div class="item[^"]*">/', $html);
    $seen = []; $results = [];
    foreach (array_slice($chunks, 1) as $chunk) {
        if (!preg_match('/href="(\/watch\/([^"]+))"/', $chunk, $hm)) continue;
        $slug = $hm[1];
        if (!preg_match('/(\d+)$/', $slug, $im)) continue;
        $aw_id = $im[1];
        if (isset($seen[$aw_id])) continue; $seen[$aw_id] = true;
        $poster = ''; if (preg_match('/src="(https:\/\/static\.aniwaves\.ru\/[^"]+)"/', $chunk, $pm)) $poster = $pm[1];
        $en = '';
        if (preg_match('/class="name d-title"[^>]*>([^<]+)</', $chunk, $nm)) $en = trim($nm[1]);
        if (!$en && preg_match('/alt="([^"]+)"/', $chunk, $am)) $en = trim(preg_replace('/ Japanese english subbed$/i', '', $am[1]));
        $jp = ''; if (preg_match('/data-jp="([^"]+)"/', $chunk, $jm)) $jp = $jm[1];
        $type_lbl = 'TV'; if (preg_match('/class="right">([^<]+)<\/div>/', $chunk, $tm)) $type_lbl = trim($tm[1]);
        $sub_eps = null; if (preg_match('/ep-status sub[^>]*>[\s\S]*?<span>\s*(\d+)\s*<\/span>/', $chunk, $em)) $sub_eps = (int)$em[1];
        $item = ['aw_id' => $aw_id, 'slug' => $slug, 'title' => $en, 'jp_title' => $jp, 'poster' => $poster, 'type' => $type_lbl, 'sub_eps' => $sub_eps];
        $results[] = $item;
        $mf = $cache_dir . '/item_' . $aw_id . '.json';
        if (!file_exists($mf) || (time() - filemtime($mf)) > 86400) file_put_contents($mf, json_encode($item));
    }
    return $results;
}
$anime_popular = home_aw_fetch(1);

// ── Batch TMDB ID lookup للأنمي (للربط بصفحة التفاصيل) ──────────────────────
$_all_anime_titles = array_filter(array_unique(array_merge(
    array_column($anime_anikuro, 'title'),
    array_column($anime_popular, 'title')
)));
$_anime_tmdb_map = anime_tmdb_ids_batch($_all_anime_titles);

$hero_items = array_slice(array_filter($trending_all, fn($i) => !empty($i['backdrop_path'])), 0, 5);

// ── Spotlight: pick the highest-rated upcoming movie with a backdrop ──────────
$spotlight = null;
foreach ($top_rated as $m) {
    if (!empty($m['backdrop_path']) && !empty($m['overview'])) {
        $spotlight = $m;
        break;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<!-- ════════════════════════ HERO ════════════════════════ -->
<section class="hero">
  <div class="hero-slides">
    <?php foreach ($hero_items as $idx => $item):
      $type  = $item['media_type'] ?? 'movie';
      $title = $item['title'] ?? $item['name'] ?? '';
      $year  = fmt_year($item['release_date'] ?? $item['first_air_date'] ?? '');
      $slug  = $item['id'] . '-' . slugify($title);
      $detail_url = '/' . ($type === 'tv' ? 'tv-show' : 'movie') . '/' . $slug;
      $watch_url  = '/watch/' . $type . '/' . $slug;
      $genres_raw = $item['genre_ids'] ?? [];
      $genre_map  = [28=>'Action',12=>'Adventure',16=>'Animation',35=>'Comedy',80=>'Crime',99=>'Documentary',18=>'Drama',10751=>'Family',14=>'Fantasy',36=>'History',27=>'Horror',10402=>'Music',9648=>'Mystery',10749=>'Romance',878=>'Sci-Fi',10770=>'TV Movie',53=>'Thriller',10752=>'War',37=>'Western',10759=>'Action',10762=>'Kids',10763=>'News',10764=>'Reality',10765=>'Sci-Fi',10766=>'Soap',10767=>'Talk',10768=>'War'];
    ?>
    <div class="hero-slide <?= $idx === 0 ? 'is-active' : '' ?>" data-index="<?= $idx ?>"
         style="background-image:url('<?= img_url($item['backdrop_path'], 'original') ?>')">
      <div class="hero-overlay"></div>
      <div class="hero-content">
        <div class="hero-meta">
          <span class="badge-trending">TRENDING #<?= $idx + 1 ?></span>
          <span class="badge-rating"><i class="fa-solid fa-star"></i> <?= star((float)($item['vote_average'] ?? 0)) ?></span>
          <?php if ($genres_raw): $gname = $genre_map[$genres_raw[0]] ?? ''; if ($gname): ?>
            <span class="badge-genre"><?= htmlspecialchars($gname) ?></span>
          <?php endif; endif; ?>
        </div>
        <h1 class="hero-title"><?= htmlspecialchars($title) ?></h1>
        <div class="hero-info">
          <?php if ($year): ?><span><i class="fa-regular fa-calendar"></i> <?= $year ?></span><?php endif; ?>
          <span class="dot"></span>
          <span><?= $type === 'tv' ? 'TV Show' : 'Movie' ?></span>
        </div>
        <p class="hero-overview"><?= htmlspecialchars(mb_strimwidth($item['overview'] ?? '', 0, 200, '…')) ?></p>
        <div class="hero-actions">
          <a href="<?= $watch_url ?>" class="btn btn-primary"><i class="fa-solid fa-play"></i> Watch Now</a>
          <a href="<?= $detail_url ?>" class="btn btn-ghost"><i class="fa-solid fa-circle-info"></i> More Info</a>
          <button class="btn btn-icon" data-save data-id="<?= $item['id'] ?>" data-type="<?= $type ?>"
            data-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>"
            data-poster="<?= htmlspecialchars(img_url($item['poster_path'] ?? null, 'w342'), ENT_QUOTES) ?>">
            <i class="fa-solid fa-plus"></i>
          </button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="hero-dots">
    <?php foreach ($hero_items as $i => $_): ?>
      <button class="hero-dot <?= $i === 0 ? 'is-active' : '' ?>" data-go="<?= $i ?>"></button>
    <?php endforeach; ?>
  </div>
</section>

<!-- ════════════════════════ GENRE STRIP ════════════════════════ -->
<div class="genre-strip-wrap">
  <div class="genre-strip">
    <a href="/movies?genre=28"  class="gs-chip"><i class="fa-solid fa-explosion"></i> Action</a>
    <a href="/movies?genre=35"  class="gs-chip"><i class="fa-solid fa-face-laugh"></i> Comedy</a>
    <a href="/movies?genre=18"  class="gs-chip"><i class="fa-solid fa-masks-theater"></i> Drama</a>
    <a href="/movies?genre=27"  class="gs-chip"><i class="fa-solid fa-skull"></i> Horror</a>
    <a href="/movies?genre=878" class="gs-chip"><i class="fa-solid fa-rocket"></i> Sci-Fi</a>
    <a href="/movies?genre=10749" class="gs-chip"><i class="fa-solid fa-heart"></i> Romance</a>
    <a href="/movies?genre=53"  class="gs-chip"><i class="fa-solid fa-magnifying-glass"></i> Thriller</a>
    <a href="/movies?genre=12"  class="gs-chip"><i class="fa-solid fa-map"></i> Adventure</a>
    <a href="/movies?genre=14"  class="gs-chip"><i class="fa-solid fa-hat-wizard"></i> Fantasy</a>
    <a href="/movies?genre=16"  class="gs-chip"><i class="fa-solid fa-wand-sparkles"></i> Animation</a>
    <a href="/anime"            class="gs-chip gs-chip--anime"><i class="fa-solid fa-dragon"></i> Anime</a>
  </div>
</div>

<?php
/* ── Helper functions ──────────────────────────────────────────────────────── */

function sec_hd(string $label_ar, string $label_en, string $icon, string $all_url): void { ?>
<div class="sec-hd">
  <div class="sec-title">
    <span class="sec-accent"></span>
    <i class="<?= $icon ?>"></i>
    <span><?= htmlspecialchars($label_en) ?></span>
  </div>
</div>
<?php }

function row(string $title, array $items, string $type_default = 'movie', string $all_link = '', string $badge_override = ''): void {
    // Track IDs shown across ALL rows so nothing appears twice on the page
    static $seen_ids = [];

    if (!$items) return;

    // Filter to unique items not shown in any previous row
    $unique = [];
    foreach ($items as $it) {
        $t = $it['media_type'] ?? $type_default;
        if (!in_array($t, ['movie','tv'], true)) continue;
        $id = (int)($it['id'] ?? 0);
        if (!$id || isset($seen_ids[$id])) continue;
        $unique[] = $it;
        $seen_ids[$id] = true;
    }

    if (!$unique) return;
    ?>
    <section class="row">
      <div class="row-head">
        <h2><?= htmlspecialchars($title) ?></h2>
        <div style="display:flex;align-items:center;gap:12px;">
          <?php if ($all_link): ?>
            <a href="<?= htmlspecialchars($all_link) ?>" class="row-all">All <i class="fa-solid fa-chevron-right"></i></a>
          <?php endif; ?>
          <div class="row-arrows">
            <button class="arrow" data-arrow="left"><i class="fa-solid fa-chevron-left"></i></button>
            <button class="arrow" data-arrow="right"><i class="fa-solid fa-chevron-right"></i></button>
          </div>
        </div>
      </div>
      <div class="row-track">
        <?php foreach ($unique as $it):
          $t      = $it['media_type'] ?? $type_default;
          $name   = $it['title'] ?? $it['name'] ?? '';
          $year   = fmt_year($it['release_date'] ?? $it['first_air_date'] ?? '');
          $slug   = $it['id'] . '-' . slugify($name);
          $href   = '/' . ($t === 'tv' ? 'tv-show' : 'movie') . '/' . $slug;
          $rating = star((float)($it['vote_average'] ?? 0));
          $badge  = $badge_override ?: ($t === 'tv' ? 'TV' : 'Movie');
        ?>
          <a class="card" href="<?= $href ?>">
            <div class="card-poster">
              <img loading="lazy" src="<?= img_url($it['poster_path'] ?? null, 'w342') ?>" alt="<?= htmlspecialchars($name) ?>" />
              <div class="card-overlay">
                <div class="play-circle"><i class="fa-solid fa-play"></i></div>
              </div>
              <span class="card-rating"><i class="fa-solid fa-star"></i> <?= $rating ?></span>
              <span class="card-type"><?= htmlspecialchars($badge) ?></span>
            </div>
            <div class="card-meta">
              <h3><?= htmlspecialchars($name) ?></h3>
              <div class="card-sub"><?= $year ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
}
?>

<!-- ════════════════════════ TRENDING ════════════════════════ -->
<div class="home-block">
  <?php row('Trending This Week', $trending_all, 'movie', '/explore'); ?>
</div>

<!-- ════════════════════════ MOVIES ════════════════════════ -->
<div class="home-block">
  <?php sec_hd('أفلام', 'Movies', 'fa-solid fa-film', '/movies'); ?>
  <?php row('Now Playing in Theaters', $now_playing, 'movie', '/movies?list=now_playing', '🎬 In Theaters'); ?>
  <?php row('Most Popular', $trending_movie, 'movie', '/movies'); ?>
  <?php row('Top Rated', $top_rated, 'movie', '/movies?list=top_rated'); ?>
  <?php row('Coming Soon', $upcoming, 'movie', '/movies?list=upcoming', '🔜 Coming Soon'); ?>
</div>

<!-- ════════════════════════ SPOTLIGHT BANNER ════════════════════════ -->
<?php if ($spotlight):
  $sp_title = $spotlight['title'] ?? $spotlight['name'] ?? '';
  $sp_slug  = $spotlight['id'] . '-' . slugify($sp_title);
  $sp_year  = fmt_year($spotlight['release_date'] ?? $spotlight['first_air_date'] ?? '');
  $sp_score = star((float)($spotlight['vote_average'] ?? 0));
?>
<div class="spotlight-wrap">
  <div class="spotlight" style="background-image:url('<?= img_url($spotlight['backdrop_path'], 'w1280') ?>')">
    <div class="spotlight-overlay"></div>
    <div class="spotlight-body">
      <span class="spotlight-tag"><i class="fa-solid fa-fire"></i> Top Rated Pick</span>
      <h2 class="spotlight-title"><?= htmlspecialchars($sp_title) ?></h2>
      <div class="spotlight-meta">
        <span><i class="fa-solid fa-star" style="color:#ffce3a"></i> <?= $sp_score ?></span>
        <?php if ($sp_year): ?><span class="dot"></span><span><?= $sp_year ?></span><?php endif; ?>
      </div>
      <p class="spotlight-overview"><?= htmlspecialchars(mb_strimwidth($spotlight['overview'] ?? '', 0, 180, '…')) ?></p>
      <div class="spotlight-actions">
        <a href="/watch/movie/<?= $sp_slug ?>" class="btn btn-primary btn-lg"><i class="fa-solid fa-play"></i> Watch Now</a>
        <a href="/movie/<?= $sp_slug ?>"       class="btn btn-ghost  btn-lg"><i class="fa-solid fa-circle-info"></i> Details</a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ════════════════════════ GENRE ROWS ════════════════════════ -->
<div class="home-block">
  <div class="sec-hd">
    <div class="sec-title">
      <span class="sec-accent"></span>
      <i class="fa-solid fa-layer-group"></i>
      <span>By Genre</span>
    </div>
  </div>
  <?php row('💥 Action & Adventure', $action_movies, 'movie', '/movies?genre=28'); ?>
  <?php row('🚀 Sci-Fi & Fantasy',   $scifi_movies,  'movie', '/movies?genre=878'); ?>
  <?php row('💀 Horror & Thriller',  $horror_movies, 'movie', '/movies?genre=27'); ?>
</div>

<!-- ════════════════════════ TV SHOWS ════════════════════════ -->
<div class="home-block">
  <?php sec_hd('مسلسلات', 'TV Shows', 'fa-solid fa-tv', '/tv'); ?>
  <?php row('Trending TV This Week', $tv_trending, 'tv', '/tv'); ?>
  <?php row('Most Popular',      $tv_popular,  'tv', '/tv'); ?>
  <?php row('Currently Airing', $tv_airing,   'tv', '/tv?list=on_the_air', '📡 On Air'); ?>
  <?php row('Top Rated',        $tv_top,      'tv', '/tv?list=top_rated'); ?>
</div>

<!-- ════════════════════════ DRAMA SHORT ════════════════════════ -->
<?php
// جلب الدراما من الكاش (أسرع مزود متاح)
function home_ds_fetch(): array {
    $cache_dir = __DIR__ . '/../.cache/drama-short';
    // ابحث في الكاش عن أول مزود يحتوي على بيانات
    $files = glob($cache_dir . '/sections_*.json') ?: [];
    foreach ($files as $f) {
        if ((time() - filemtime($f)) > 3600) continue; // تجاهل الكاش القديم جداً
        $d = json_decode(file_get_contents($f), true);
        if (empty($d['sections'])) continue;
        $items = [];
        foreach ($d['sections'] as $sec) {
            $prov_key = $d['active_provider'] ?? 'bibishort';
            foreach (($sec['items'] ?? []) as $it) {
                $item_id = $it['id'] ?? '';
                $parts   = explode(':', $item_id);
                $it_prov = $parts[0] ?: $prov_key;
                $bid     = $parts[1] ?? '';
                if (!$bid) { $m=[]; preg_match('/book_id=(\d+)/', $it['url'] ?? '', $m); $bid = $m[1] ?? ''; }
                if (!$bid) continue;
                $items[] = [
                    'prov'   => $it_prov,
                    'bid'    => $bid,
                    'title'  => $it['title'] ?? '',
                    'poster' => $it['poster_url'] ?? '',
                    'cat'    => $it['category_name'] ?? '',
                ];
            }
        }
        if ($items) return array_slice($items, 0, 20);
    }
    // Fallback: fetch from API (non-blocking short timeout)
    @mkdir($cache_dir, 0755, true);
    $cache_key = $cache_dir . '/sections_' . md5('bibishort' . 'en-US') . '.json';
    $ch = curl_init('https://narto-drama.com/home/providers/sections?' . http_build_query([
        'provider' => 'bibishort', 'lang' => 'en-US', 'target_lang' => 'en-US',
        '_cb' => (string)(time() * 1000),
    ]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
            'Accept: */*', 'X-Requested-With: XMLHttpRequest',
            'Referer: https://narto-drama.com/?lang=en-US&tab-provider=bibishort',
        ],
    ]);
    $raw = curl_exec($ch); curl_close($ch);
    if (!$raw) return [];
    $d = json_decode($raw, true);
    if (!is_array($d) || empty($d['sections'])) return [];
    @file_put_contents($cache_key, json_encode($d));
    $items = [];
    foreach ($d['sections'] as $sec) {
        foreach (($sec['items'] ?? []) as $it) {
            $parts = explode(':', $it['id'] ?? '');
            $bid   = $parts[1] ?? '';
            if (!$bid) { $m=[]; preg_match('/book_id=(\d+)/', $it['url'] ?? '', $m); $bid = $m[1] ?? ''; }
            if (!$bid) continue;
            $items[] = ['prov' => $parts[0] ?: 'bibishort', 'bid' => $bid,
                        'title' => $it['title'] ?? '', 'poster' => $it['poster_url'] ?? '',
                        'cat' => $it['category_name'] ?? ''];
        }
    }
    return array_slice($items, 0, 20);
}
$_ds_items = home_ds_fetch();
?>
<?php if ($_ds_items): ?>
<div class="home-block">
  <div class="sec-hd">
    <div class="sec-title">
      <span class="sec-accent"></span>
      <i class="fa-solid fa-clapperboard"></i>
      <span>Drama Short</span>
    </div>
    <a href="/drama-short" class="sec-all-link">View All <i class="fa-solid fa-chevron-right"></i></a>
  </div>
  <section class="row">
    <div class="row-head">
      <h2><i class="fa-solid fa-fire" style="color:var(--primary);font-size:.9em"></i> Popular Short Dramas</h2>
      <div style="display:flex;align-items:center;gap:12px;">
        <a href="/drama-short" class="row-all">View All <i class="fa-solid fa-chevron-right"></i></a>
        <div class="row-arrows">
          <button class="arrow" data-arrow="left"><i class="fa-solid fa-chevron-left"></i></button>
          <button class="arrow" data-arrow="right"><i class="fa-solid fa-chevron-right"></i></button>
        </div>
      </div>
    </div>
    <div class="row-track">
      <?php foreach ($_ds_items as $it):
        $href = '/drama-short/' . urlencode($it['prov']) . '/' . urlencode($it['bid'])
              . '?lang=en-US&title=' . urlencode($it['title']);
        $proxy_poster = $it['poster']
          ? '/api/drama-short/img-proxy.php?url=' . urlencode($it['poster'])
          : '';
      ?>
        <a class="card" href="<?= htmlspecialchars($href) ?>">
          <div class="card-poster">
            <?php if ($proxy_poster): ?>
              <img loading="lazy" src="<?= htmlspecialchars($proxy_poster) ?>"
                   onerror="this.style.display='none'"
                   alt="<?= htmlspecialchars($it['title']) ?>" />
            <?php endif; ?>
            <div style="width:100%;height:100%;background:#1a1a2e;display:<?= $proxy_poster ? 'none' : 'flex' ?>;align-items:center;justify-content:center;position:absolute;inset:0;">
              <i class="fa-solid fa-clapperboard" style="font-size:2rem;color:#444"></i>
            </div>
            <div class="card-overlay">
              <div class="play-circle"><i class="fa-solid fa-circle-info"></i></div>
            </div>
            <span class="card-type">Short</span>
          </div>
          <div class="card-meta">
            <h3><?= htmlspecialchars($it['title']) ?></h3>
            <?php if ($it['cat']): ?><div class="card-sub"><?= htmlspecialchars($it['cat']) ?></div><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<?php endif; ?>

<!-- ════════════════════════ ANIME ════════════════════════ -->
<div class="home-block">
  <?php sec_hd('أنمي', 'Anime', 'fa-solid fa-dragon', '/anime'); ?>

  <?php
  // دمج المصدرين في قائمة واحدة مخلوطة (AK أولاً ثم AW)
  $_anime_merged = [];
  foreach (array_slice($anime_anikuro, 0, 20) as $it) {
      $title = $it['title'] ?: ('Anime ' . $it['ak_id']);
      $_tmdb = $_anime_tmdb_map[$title] ?? null;
      $href  = $_tmdb ? '/tv-show/' . $_tmdb['slug'] : '/watch/anime/' . htmlspecialchars($it['slug']) . '/1';
      $ep_lbl = $it['episodes'] ? $it['episodes'] . ' ep' : '';
      $_anime_merged[] = ['poster' => $it['poster'], 'title' => $title, 'href' => $href, 'ep_lbl' => $ep_lbl, 'type' => 'Anime'];
  }
  foreach (array_slice($anime_popular, 0, 20) as $it) {
      $title = $it['title'] ?: ('Anime ' . $it['aw_id']);
      $_tmdb = $_anime_tmdb_map[$title] ?? null;
      $href  = $_tmdb ? '/tv-show/' . $_tmdb['slug'] : '/watch/anime/aw-' . $it['aw_id'] . '/1';
      $ep_lbl = $it['sub_eps'] !== null ? $it['sub_eps'] . ' ep' : '';
      $_anime_merged[] = ['poster' => $it['poster'], 'title' => $title, 'href' => $href, 'ep_lbl' => $ep_lbl, 'type' => ($it['type'] ?: 'Anime')];
  }
  ?>
  <?php if ($_anime_merged): ?>
  <section class="row">
    <div class="row-head">
      <h2><i class="fa-solid fa-fire" style="color:var(--primary);font-size:.9em"></i> الأكثر رواجاً</h2>
      <div style="display:flex;align-items:center;gap:12px;">
        <a href="/anime" class="row-all">الكل <i class="fa-solid fa-chevron-right"></i></a>
        <div class="row-arrows">
          <button class="arrow" data-arrow="left"><i class="fa-solid fa-chevron-left"></i></button>
          <button class="arrow" data-arrow="right"><i class="fa-solid fa-chevron-right"></i></button>
        </div>
      </div>
    </div>
    <div class="row-track">
      <?php foreach ($_anime_merged as $it): ?>
        <a class="card" href="<?= htmlspecialchars($it['href']) ?>">
          <div class="card-poster">
            <?php if ($it['poster']): ?>
              <img loading="lazy" src="<?= htmlspecialchars($it['poster']) ?>" alt="<?= htmlspecialchars($it['title']) ?>" />
            <?php else: ?>
              <div style="width:100%;height:100%;background:#1a1a2e;display:flex;align-items:center;justify-content:center;">
                <i class="fa-solid fa-dragon" style="font-size:2rem;color:#444"></i>
              </div>
            <?php endif; ?>
            <div class="card-overlay"><div class="play-circle"><i class="fa-solid fa-play"></i></div></div>
            <?php if ($it['ep_lbl']): ?>
              <span class="card-rating"><i class="fa-solid fa-tv"></i> <?= htmlspecialchars($it['ep_lbl']) ?></span>
            <?php endif; ?>
            <span class="card-type"><?= htmlspecialchars($it['type']) ?></span>
          </div>
          <div class="card-meta"><h3><?= htmlspecialchars($it['title']) ?></h3></div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
