<?php
$type = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$list = $_GET['list'] ?? ($type === 'tv' ? 'popular' : 'popular');
$page = max(1, (int)($_GET['page'] ?? 1));

$valid_lists = $type === 'movie'
    ? ['popular' => 'Popular', 'top_rated' => 'Top Rated', 'upcoming' => 'Upcoming', 'now_playing' => 'Now Playing']
    : ['popular' => 'Popular', 'top_rated' => 'Top Rated', 'on_the_air' => 'On The Air', 'airing_today' => 'Airing Today'];

if (!isset($valid_lists[$list])) $list = 'popular';

$genre_id  = (int)($_GET['genre'] ?? 0);
$year      = (int)($_GET['year']  ?? 0);
$sort      = $_GET['sort']  ?? '';

if ($genre_id || $year || $sort) {
    $params = [
        'page'  => $page,
        'sort_by' => $sort ?: 'popularity.desc',
        'include_adult' => 'false',
    ];
    if ($genre_id) $params['with_genres'] = $genre_id;
    if ($year)     $params[$type === 'movie' ? 'primary_release_year' : 'first_air_date_year'] = $year;
    $data = tmdb_discover($type, $params);
    $list_label = 'Discover';
} else {
    $data = ($type === 'movie') ? tmdb_movie_list($list, $page) : tmdb_tv_list($list, $page);
    $list_label = $valid_lists[$list];
}

$results = $data['results'] ?? [];
$total_pages = min((int)($data['total_pages'] ?? 1), 50);

$genres     = tmdb_genres($type);
$page_title = ($type === 'movie' ? 'Movies' : 'TV Shows') . ' · ' . $list_label;
$active     = $type === 'movie' ? 'movies' : 'tv';

include __DIR__ . '/../includes/header.php';
?>

<section class="browse">
  <div class="browse-head">
    <div>
      <h1 class="browse-title"><?= $type === 'movie' ? 'Movies' : 'TV Shows' ?></h1>
      <p class="browse-sub"><?= htmlspecialchars($list_label) ?></p>
    </div>
  </div>

  <div class="filter-bar">
    <div class="chip-row">
      <?php foreach ($valid_lists as $key => $label):
        $url = '/' . ($type === 'movie' ? 'movies' : 'tv') . '?list=' . $key;
      ?>
        <a class="chip <?= ($list === $key && !$genre_id && !$year) ? 'is-active' : '' ?>" href="<?= $url ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <form class="filter-form" method="get" action="">
      <input type="hidden" name="type" value="<?= $type ?>" />
      <select name="genre" onchange="this.form.submit()">
        <option value="">Genre</option>
        <?php foreach ($genres as $g): ?>
          <option value="<?= $g['id'] ?>" <?= $genre_id === (int)$g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="year" onchange="this.form.submit()">
        <option value="">Year</option>
        <?php for ($y = (int)date('Y') + 1; $y >= 1960; $y--): ?>
          <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
      <select name="sort" onchange="this.form.submit()">
        <option value="">Sort</option>
        <option value="popularity.desc"   <?= $sort === 'popularity.desc'   ? 'selected' : '' ?>>Popularity</option>
        <option value="vote_average.desc" <?= $sort === 'vote_average.desc' ? 'selected' : '' ?>>Rating</option>
        <option value="release_date.desc" <?= $sort === 'release_date.desc' ? 'selected' : '' ?>>Newest</option>
      </select>
    </form>
  </div>

  <div class="grid">
    <?php foreach ($results as $it):
      $name = $it['title'] ?? $it['name'] ?? '';
      $slug = $it['id'] . '-' . slugify($name);
      $href = '/' . ($type === 'tv' ? 'tv-show' : 'movie') . '/' . $slug;
      $year_l = fmt_year($it['release_date'] ?? $it['first_air_date'] ?? '');
      $rating = star((float)($it['vote_average'] ?? 0));
    ?>
      <a class="card card-grid" href="<?= $href ?>">
        <div class="card-poster">
          <img loading="lazy" src="<?= img_url($it['poster_path'] ?? null, 'w342') ?>" alt="<?= htmlspecialchars($name) ?>" />
          <div class="card-overlay">
            <div class="play-circle"><i class="fa-solid fa-play"></i></div>
          </div>
          <span class="card-rating"><i class="fa-solid fa-star"></i> <?= $rating ?></span>
        </div>
        <div class="card-meta">
          <h3><?= htmlspecialchars($name) ?></h3>
          <div class="card-sub"><?= $year_l ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($total_pages > 1): ?>
  <div class="pager">
    <?php
      $base = strtok($_SERVER['REQUEST_URI'], '?');
      $qs   = $_GET;
      $build = function ($p) use ($base, $qs) {
          $qs['page'] = $p;
          unset($qs['type']);
          return $base . '?' . http_build_query($qs);
      };
    ?>
    <?php if ($page > 1): ?><a class="pager-btn" href="<?= $build($page - 1) ?>"><i class="fa-solid fa-chevron-left"></i> Prev</a><?php endif; ?>
    <span class="pager-info">Page <?= $page ?> of <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?><a class="pager-btn" href="<?= $build($page + 1) ?>">Next <i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
