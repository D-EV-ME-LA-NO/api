<?php
/**
 * pages/drama-short-watch.php
 * Route: /drama-short/watch/{provider}/{book_id}/{episode}
 * $_GET['provider'], $_GET['book_id'], $_GET['episode'], $_GET['slug'], $_GET['lang']
 */

$provider_key = preg_replace('/[^a-z0-9_-]/i', '', $_GET['provider'] ?? '');
$book_id      = (int)($_GET['book_id'] ?? 0);
$episode      = max(1, (int)($_GET['episode'] ?? 1));
$lang         = preg_replace('/[^a-z0-9_-]/i', '', $_GET['lang'] ?? 'en-US');
$slug_in      = preg_replace('/[^a-z0-9_-]/i', '', $_GET['slug'] ?? '');

if (!$provider_key || !$book_id) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$page_title = 'Episode ' . $episode . ' · Drama Short';
$active     = 'drama-short';

$detail_url = '/drama-short/' . urlencode($provider_key) . '/' . $book_id
            . '?lang=' . urlencode($lang);

include __DIR__ . '/../includes/header.php';
?>

<div class="dsw-page">

  <!-- ════ Back ════ -->
  <a href="<?= htmlspecialchars($detail_url) ?>" class="ds-back-btn">
    <i class="fa-solid fa-arrow-left"></i> Back to Episodes
  </a>

  <!-- ════ Player ════ -->
  <div class="dsw-player-wrap" id="dswPlayerWrap">
    <div class="dsw-loading" id="dswLoading">
      <i class="fa-solid fa-circle-notch fa-spin"></i>
      <span>Loading video...</span>
    </div>
    <video id="dswVideo" class="dsw-video" controls playsinline preload="metadata" style="display:none">
      Your browser does not support HTML5 video.
    </video>
    <div class="dsw-error" id="dswError" style="display:none">
      <i class="fa-solid fa-triangle-exclamation" style="font-size:2.5rem;color:#ff5555;margin-bottom:12px"></i>
      <p id="dswErrorMsg">Could not load video. Tap Retry or try a different episode.</p>
      <div style="display:flex;gap:10px;margin-top:16px">
        <button class="btn btn-primary" id="dswRetry"><i class="fa-solid fa-rotate-right"></i> Retry</button>
        <a href="<?= htmlspecialchars($detail_url) ?>" class="btn btn-ghost">
          <i class="fa-solid fa-list"></i> Episodes
        </a>
      </div>
    </div>
  </div>

  <!-- ════ Episode Info ════ -->
  <div class="dsw-info">
    <h1 class="dsw-ep-title" id="dswTitle">
      Episode <?= $episode ?>
    </h1>
    <div class="dsw-meta">
      <span><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars(ucfirst($provider_key)) ?></span>
      <span><i class="fa-solid fa-closed-captioning"></i> <?= htmlspecialchars(strtoupper($lang)) ?></span>
    </div>
  </div>

  <!-- ════ Episode Navigation ════ -->
  <div class="dsw-nav" id="dswNav">
    <?php if ($episode > 1): ?>
      <a href="/drama-short/watch/<?= urlencode($provider_key) ?>/<?= $book_id ?>/<?= $episode - 1 ?>?lang=<?= urlencode($lang) ?>&slug=<?= urlencode($slug_in) ?>"
         class="btn btn-ghost dsw-nav-btn">
        <i class="fa-solid fa-chevron-left"></i> Ep <?= $episode - 1 ?>
      </a>
    <?php else: ?>
      <span></span>
    <?php endif; ?>

    <a href="<?= htmlspecialchars($detail_url) ?>" class="dsw-ep-badge">
      <i class="fa-solid fa-list"></i> All Episodes
    </a>

    <a href="/drama-short/watch/<?= urlencode($provider_key) ?>/<?= $book_id ?>/<?= $episode + 1 ?>?lang=<?= urlencode($lang) ?>&slug=<?= urlencode($slug_in) ?>"
       class="btn btn-ghost dsw-nav-btn" id="dswNextBtn">
      Ep <?= $episode + 1 ?> <i class="fa-solid fa-chevron-right"></i>
    </a>
  </div>

</div>

<style>
.dsw-page { padding: 12px 16px 100px; max-width: 960px; margin: 0 auto; }
.dsw-player-wrap { position: relative; background: #000; border-radius: 12px;
  overflow: hidden; aspect-ratio: 9/16; max-height: 82vh;
  display: flex; align-items: center; justify-content: center; margin-bottom: 18px; }
.dsw-video { width: 100%; height: 100%; object-fit: contain; background: #000; }
.dsw-loading { display: flex; flex-direction: column; align-items: center;
  justify-content: center; gap: 12px; color: #aaa; font-size: 1rem; position: absolute; }
.dsw-loading i { font-size: 2.5rem; color: var(--primary); }
.dsw-error { display: flex; flex-direction: column; align-items: center;
  justify-content: center; text-align: center; padding: 40px 20px;
  color: #ccc; position: absolute; }
.dsw-info { margin-bottom: 16px; }
.dsw-ep-title { font-size: 1.3rem; font-weight: 700; margin: 0 0 8px; }
.dsw-meta { display: flex; gap: 16px; color: #aaa; font-size: .85rem; flex-wrap: wrap; }
.dsw-meta span { display: flex; align-items: center; gap: 5px; }
.dsw-nav { display: flex; align-items: center; justify-content: space-between;
  gap: 10px; padding: 12px 0; }
.dsw-nav-btn { min-width: 90px; }
.dsw-ep-badge { background: #1a1a2e; border: 1.5px solid #2a2a3e;
  border-radius: 8px; padding: 8px 16px; font-size: .85rem; color: #ccc;
  display: flex; align-items: center; gap: 6px; transition: all .15s; }
.dsw-ep-badge:hover { border-color: var(--primary); color: #fff; }
@media (min-width: 769px) {
  .dsw-player-wrap { aspect-ratio: 9/16; max-width: 440px; margin: 0 auto 18px; }
}
</style>

<script>
(function() {
  const prov    = <?= json_encode($provider_key) ?>;
  const bid     = <?= json_encode($book_id) ?>;
  const ep      = <?= $episode ?>;
  const lang    = <?= json_encode($lang) ?>;
  const slugIn  = <?= json_encode($slug_in) ?>;
  const video   = document.getElementById('dswVideo');
  const loading = document.getElementById('dswLoading');
  const errEl   = document.getElementById('dswError');
  const errMsg  = document.getElementById('dswErrorMsg');
  const retryBtn = document.getElementById('dswRetry');
  const titleEl = document.getElementById('dswTitle');

  function showError(msg) {
    loading.style.display  = 'none';
    video.style.display    = 'none';
    errEl.style.display    = 'flex';
    if (msg) errMsg.textContent = msg;
  }

  function loadVideo(slug) {
    const apiUrl = '/api/drama-short/stream?episode=' + ep
                 + '&lang=' + encodeURIComponent(lang)
                 + '&slug=' + encodeURIComponent(slug);

    fetch(apiUrl)
      .then(r => r.json())
      .then(data => {
        if (!data.ok || !data.video_url) {
          showError(data.error || 'Video not found for this episode. It may not be available yet.');
          return;
        }
        // Update next episode button based on total if available
        video.src = data.video_url;
        video.style.display = 'block';
        loading.style.display = 'none';
        errEl.style.display = 'none';
        video.play().catch(() => {});
      })
      .catch(e => {
        showError('Network error loading video. Please check your connection and retry.');
      });
  }

  function resolveAndLoad() {
    loading.style.display = 'flex';
    video.style.display   = 'none';
    errEl.style.display   = 'none';

    if (slugIn) {
      loadVideo(slugIn);
      return;
    }

    // Resolve slug via detail API first
    fetch('/api/drama-short/detail?provider=' + encodeURIComponent(prov)
        + '&book_id=' + encodeURIComponent(bid)
        + '&lang=' + encodeURIComponent(lang))
      .then(r => r.json())
      .then(d => {
        if (!d.ok || !d.slug) {
          showError('Could not resolve drama. Please go back and try again.');
          return;
        }
        if (d.title && titleEl) titleEl.textContent = 'Episode ' + ep + ' · ' + d.title;
        // Update nav: if total_episodes known, hide next btn when on last ep
        if (d.total_episodes && ep >= d.total_episodes) {
          const nextBtn = document.getElementById('dswNextBtn');
          if (nextBtn) nextBtn.style.display = 'none';
        }
        loadVideo(d.slug);
      })
      .catch(() => showError('Failed to load drama info.'));
  }

  retryBtn?.addEventListener('click', resolveAndLoad);
  resolveAndLoad();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
