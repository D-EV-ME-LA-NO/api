</main>

<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-brand">
      <span class="brand-text">HZ<span>Flix</span></span>
      <p>Stream unlimited movies and TV shows from around the world.</p>
    </div>
    <div class="footer-cols">
      <div>
        <h4>Browse</h4>
        <a href="/">Home</a>
        <a href="/movies">Movies</a>
        <a href="/tv">TV Shows</a>
        <a href="/explore">Explore</a>
      </div>
      <div>
        <h4>Lists</h4>
        <a href="/movies?list=popular">Popular</a>
        <a href="/movies?list=top_rated">Top Rated</a>
        <a href="/movies?list=upcoming">Upcoming</a>
        <a href="/movies?list=now_playing">Now Playing</a>
      </div>
      <div>
        <h4>الحساب</h4>
        <?php if(auth_user()): ?>
          <a href="/profile">حسابي</a>
          <a href="/profile">محفوظاتي</a>
          <a href="/logout">تسجيل الخروج</a>
        <?php else: ?>
          <a href="/login">تسجيل الدخول</a>
          <a href="/register">إنشاء حساب</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="footer-socials">
    <a href="https://www.instagram.com/" target="_blank" rel="noopener" class="fsoc-btn fsoc-instagram" aria-label="Instagram">
      <i class="fa-brands fa-instagram"></i>
    </a>
    <a href="https://www.tiktok.com/" target="_blank" rel="noopener" class="fsoc-btn fsoc-tiktok" aria-label="TikTok">
      <i class="fa-brands fa-tiktok"></i>
    </a>
    <a href="https://www.facebook.com/" target="_blank" rel="noopener" class="fsoc-btn fsoc-facebook" aria-label="Facebook">
      <i class="fa-brands fa-facebook-f"></i>
    </a>
    <a href="https://twitter.com/" target="_blank" rel="noopener" class="fsoc-btn fsoc-twitter" aria-label="Twitter / X">
      <i class="fa-brands fa-x-twitter"></i>
    </a>
    <a href="https://www.snapchat.com/" target="_blank" rel="noopener" class="fsoc-btn fsoc-snapchat" aria-label="Snapchat">
      <i class="fa-brands fa-snapchat"></i>
    </a>
  </div>
  <div class="footer-bottom">
    © <?= date('Y') ?> <?= SITE_NAME ?>. Powered by TMDB.
  </div>
</footer>

<nav class="bottom-nav">
  <a href="/" class="bn-item <?= ($active ?? '') === 'home' ? 'is-active' : '' ?>"><i class="fa-solid fa-house"></i><span>Home</span></a>
  <a href="/movies" class="bn-item <?= ($active ?? '') === 'movies' ? 'is-active' : '' ?>"><i class="fa-solid fa-film"></i><span>Movies</span></a>
  <a href="/drama-short" class="bn-item <?= ($active ?? '') === 'drama-short' ? 'is-active' : '' ?>"><i class="fa-solid fa-clapperboard"></i><span>Drama</span></a>
  <a href="/explore" class="bn-item <?= ($active ?? '') === 'explore' ? 'is-active' : '' ?>"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
  <a href="/saved" class="bn-item <?= ($active ?? '') === 'saved' ? 'is-active' : '' ?>"><i class="fa-regular fa-bookmark"></i><span>Saved</span></a>
</nav>

<script src="/assets/js/app.js?v=16"></script>
</body>
</html>
