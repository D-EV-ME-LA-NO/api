<?php
$page_title = $page_title ?? SITE_NAME;
$active     = $active ?? 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
<title><?= htmlspecialchars($page_title) ?> · <?= SITE_NAME ?></title>
<meta name="description" content="<?= SITE_DESC ?>" />
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='80' font-size='90' font-family='Arial' font-weight='900' fill='%23ff2d2d'>HZ</text></svg>" />
<link rel="preconnect" href="https://image.tmdb.org" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="stylesheet" href="/assets/css/style.css?v=16" />
</head>
<body data-page="<?= htmlspecialchars($active) ?>">

<header class="site-nav" id="siteNav">
  <div class="nav-inner">
    <a href="/" class="brand" aria-label="HZ Flix">
      <span class="brand-text">HZ<span>Flix</span></span>
    </a>

    <nav class="nav-links" id="navLinks">
      <a href="/" class="nav-link <?= $active === 'home' ? 'is-active' : '' ?>">
        <i class="fa-solid fa-house"></i><span>Home</span>
      </a>
      <a href="/movies" class="nav-link <?= $active === 'movies' ? 'is-active' : '' ?>">
        <i class="fa-solid fa-film"></i><span>Movies</span>
      </a>
      <a href="/tv" class="nav-link <?= $active === 'tv' ? 'is-active' : '' ?>">
        <i class="fa-solid fa-tv"></i><span>TV</span>
      </a>
      <a href="/anime" class="nav-link <?= $active === 'anime' ? 'is-active' : '' ?>">
        <i class="fa-solid fa-dragon"></i><span>Anime</span>
      </a>
      <a href="/reels" class="nav-link <?= $active === 'reels' ? 'is-active' : '' ?>">
        <i class="fa-solid fa-film"></i><span>Reels</span>
      </a>
      <a href="/explore" class="nav-link <?= $active === 'explore' ? 'is-active' : '' ?>">
        <i class="fa-solid fa-compass"></i><span>Explore</span>
      </a>
      <a href="/saved" class="nav-link <?= $active === 'saved' ? 'is-active' : '' ?>">
        <i class="fa-regular fa-bookmark"></i><span>Saved</span>
      </a>
    </nav>

    <div class="nav-actions">
      <button class="icon-btn" id="searchToggle" aria-label="Search">
        <i class="fa-solid fa-magnifying-glass"></i>
      </button>
      <?php $__u = auth_user(); if ($__u): ?>
        <a href="/profile" class="nav-user-btn">
          <?php if (!empty($__u['avatar']) && file_exists(__DIR__ . '/..' . $__u['avatar'])): ?>
            <img class="nav-avatar nav-avatar-img" src="<?= htmlspecialchars($__u['avatar']) ?>?v=<?= filemtime(__DIR__ . '/..' . $__u['avatar']) ?>" alt="avatar" />
          <?php else: ?>
            <span class="nav-avatar"><?= mb_strtoupper(mb_substr($__u['username'], 0, 1)) ?></span>
          <?php endif; ?>
          <span class="nav-username"><?= htmlspecialchars($__u['username']) ?></span>
        </a>
      <?php else: ?>
        <a href="/login" class="download-btn">
          <i class="fa-solid fa-right-to-bracket"></i><span>دخول</span>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="search-overlay" id="searchOverlay">
    <div class="search-wrap">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="searchInput" placeholder="Search movies, TV shows, people..." autocomplete="off" />
      <button class="icon-btn" id="searchClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="search-results" id="searchResults"></div>
  </div>
</header>

<!-- ===== Telegram Channel Popup ===== -->
<div class="tg-popup" id="tgPopup" hidden>
  <div class="tg-popup-inner">
    <button class="tg-popup-close" id="tgClose" aria-label="إغلاق">❌</button>
    <div class="tg-popup-icon">
      <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" width="48" height="48">
        <circle cx="24" cy="24" r="24" fill="#29b6f6"/>
        <path d="M10.5 23.5l5.5 2 2 6.5 3.5-3 5 3.5 7-16.5-23 7.5z" fill="#fff"/>
        <path d="M16 25.5l1.5 6.5 3.5-3" fill="#b0bec5"/>
        <path d="M16 25.5l12-7.5" fill="#fff"/>
      </svg>
    </div>
    <div class="tg-popup-text">
      <strong>انضم لقناتنا على تيليجرام!</strong>
      <span>كن أول من يعلم بالإضافات والأفلام الجديدة</span>
    </div>
    <a class="tg-popup-btn" href="https://t.me/GVVVV6" target="_blank" rel="noopener">
      <i class="fa-brands fa-telegram"></i> انضم الآن
    </a>
  </div>
</div>
<script>
(function(){
  var KEY = 'tg_dismissed_v1';
  if (!localStorage.getItem(KEY)) {
    setTimeout(function(){
      var p = document.getElementById('tgPopup');
      if (p) p.hidden = false;
    }, 1200);
  }
  document.addEventListener('click', function(e){
    if (e.target.closest('#tgClose')) {
      var p = document.getElementById('tgPopup');
      if (p) { p.classList.add('tg-out'); setTimeout(function(){ p.hidden = true; p.classList.remove('tg-out'); }, 350); }
      localStorage.setItem(KEY, '1');
    }
  });
})();
</script>

<main class="site-main">
<?php if (ob_get_level()) { ob_end_flush(); } flush(); ?>
