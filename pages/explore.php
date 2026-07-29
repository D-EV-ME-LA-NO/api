<?php
$page_title = 'Explore';
$active     = 'explore';

$movie_genres = tmdb_genres('movie');
$tv_genres    = tmdb_genres('tv');

include __DIR__ . '/../includes/header.php';
?>

<section class="browse">
  <div class="browse-head">
    <div>
      <h1 class="browse-title">Explore</h1>
      <p class="browse-sub">Browse by category and discover something new</p>
    </div>
  </div>

  <h2 class="section-title">Movie Genres</h2>
  <div class="genre-grid">
    <?php foreach ($movie_genres as $g): ?>
      <a class="genre-tile" href="/movies?genre=<?= $g['id'] ?>">
        <span><?= htmlspecialchars($g['name']) ?></span>
        <i class="fa-solid fa-arrow-right"></i>
      </a>
    <?php endforeach; ?>
  </div>

  <h2 class="section-title">TV Genres</h2>
  <div class="genre-grid">
    <?php foreach ($tv_genres as $g): ?>
      <a class="genre-tile" href="/tv?genre=<?= $g['id'] ?>">
        <span><?= htmlspecialchars($g['name']) ?></span>
        <i class="fa-solid fa-arrow-right"></i>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
