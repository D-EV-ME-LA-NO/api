<?php
/**
 * pages/drama-short-detail.php
 * Route: /drama-short/{provider}/{book_id}
 * $_GET['provider'], $_GET['book_id'], $_GET['lang'], $_GET['title']
 */

$provider_key = preg_replace('/[^a-z0-9_-]/i', '', $_GET['provider'] ?? '');
$book_id      = (int)($_GET['book_id'] ?? 0);
$lang         = preg_replace('/[^a-z0-9_-]/i', '', $_GET['lang'] ?? 'en-US');
$raw_title    = trim($_GET['title'] ?? '');

if (!$provider_key || !$book_id) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// ── Fetch detail via API ───────────────────────────────────────────────────────
$detail_url = BASE_URL . '/api/drama-short/detail?' . http_build_query([
    'provider' => $provider_key,
    'book_id'  => $book_id,
    'lang'     => $lang,
    'title'    => $raw_title,
]);

$ch = curl_init($detail_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_ENCODING       => '',
    CURLOPT_FOLLOWLOCATION => true,
]);
$raw  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$detail = $raw ? (json_decode($raw, true) ?? []) : [];

// Fallback if API failed
if (empty($detail['ok'])) {
    $detail = [
        'ok'             => false,
        'title'          => $raw_title ?: 'Drama Short',
        'poster'         => '',
        'description'    => '',
        'drama_id'       => 0,
        'total_episodes' => 0,
        'slug'           => '',
        'provider'       => $provider_key,
        'book_id'        => $book_id,
        'lang'           => $lang,
    ];
}

$page_title  = htmlspecialchars($detail['title'] ?: 'Drama Short');
$active      = 'drama-short';
$drama_slug  = $detail['slug'] ?? '';
$total_eps   = (int)($detail['total_episodes'] ?? 0);
$poster      = $detail['poster'] ?? '';
$desc        = $detail['description'] ?? '';
$drama_id    = (int)($detail['drama_id'] ?? 0);

include __DIR__ . '/../includes/header.php';
?>

<div class="ds-detail-page">

  <!-- ════ Back ════ -->
  <a href="/drama-short?provider=<?= urlencode($provider_key) ?>&lang=<?= urlencode($lang) ?>"
     class="ds-back-btn">
    <i class="fa-solid fa-arrow-left"></i> Back to Drama Short
  </a>

  <?php if (!empty($detail['ok']) || $raw_title): ?>

  <!-- ════ Drama Hero ════ -->
  <div class="ds-detail-hero">
    <?php if ($poster): ?>
    <div class="ds-detail-poster">
      <img src="<?= htmlspecialchars($poster) ?>" alt="<?= $page_title ?>" />
    </div>
    <?php endif; ?>
    <div class="ds-detail-info">
      <div class="ds-badge" style="margin-bottom:10px">Short Drama</div>
      <h1 class="ds-detail-title"><?= $page_title ?></h1>
      <div class="ds-detail-meta">
        <?php if ($total_eps > 0): ?>
          <span><i class="fa-solid fa-tv"></i> <?= $total_eps ?> Episode<?= $total_eps !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
        <span><i class="fa-solid fa-closed-captioning"></i> <?= htmlspecialchars(strtoupper($lang)) ?></span>
        <?php if ($provider_key): ?>
          <span><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars(ucfirst($provider_key)) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($desc): ?>
        <p class="ds-detail-desc"><?= htmlspecialchars($desc) ?></p>
      <?php endif; ?>

      <?php if ($drama_slug || $total_eps > 0): ?>
        <a href="/drama-short/watch/<?= urlencode($provider_key) ?>/<?= $book_id ?>/1?lang=<?= urlencode($lang) ?>&slug=<?= urlencode($drama_slug) ?>"
           class="btn btn-primary btn-lg" style="margin-top: 16px">
          <i class="fa-solid fa-play"></i> Watch Episode 1
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ════ Episodes Grid ════ -->
  <?php if ($total_eps > 0): ?>
  <div class="ds-episodes-section">
    <h2 class="ds-section-title">
      <i class="fa-solid fa-list"></i> Episodes
    </h2>
    <div class="ds-episodes-grid" id="dsEpisodesGrid">
      <?php for ($ep = 1; $ep <= $total_eps; $ep++):
        $watch_url = '/drama-short/watch/' . urlencode($provider_key) . '/' . $book_id . '/' . $ep
                   . '?lang=' . urlencode($lang)
                   . '&slug=' . urlencode($drama_slug);
      ?>
        <a class="ds-ep-card" href="<?= htmlspecialchars($watch_url) ?>">
          <div class="ds-ep-num">
            <i class="fa-solid fa-play ds-ep-play"></i>
            <span><?= $ep ?></span>
          </div>
          <div class="ds-ep-label">Episode <?= $ep ?></div>
        </a>
      <?php endfor; ?>
    </div>
  </div>
  <?php elseif ($drama_slug): ?>
    <!-- Episodes not yet counted — load dynamically -->
    <div class="ds-episodes-section">
      <h2 class="ds-section-title"><i class="fa-solid fa-list"></i> Episodes</h2>
      <div id="dsEpisodesGrid" class="ds-episodes-grid">
        <div class="ds-spinner"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading episodes...</div>
      </div>
    </div>
    <script>
    (function() {
      const prov  = <?= json_encode($provider_key) ?>;
      const bid   = <?= json_encode($book_id) ?>;
      const lang  = <?= json_encode($lang) ?>;
      const slug  = <?= json_encode($drama_slug) ?>;
      const grid  = document.getElementById('dsEpisodesGrid');
      if (!grid || !slug) return;
      // Show at least ep 1-12 as default until we know the count
      let html = '';
      for (let ep = 1; ep <= 12; ep++) {
        const url = '/drama-short/watch/' + encodeURIComponent(prov) + '/' + encodeURIComponent(bid) + '/' + ep
                  + '?lang=' + encodeURIComponent(lang) + '&slug=' + encodeURIComponent(slug);
        html += `<a class="ds-ep-card" href="${url}">
          <div class="ds-ep-num"><i class="fa-solid fa-play ds-ep-play"></i><span>${ep}</span></div>
          <div class="ds-ep-label">Episode ${ep}</div>
        </a>`;
      }
      grid.innerHTML = html;
    })();
    </script>
  <?php else: ?>
    <div class="ds-loading-detail" id="dsDetailLoader">
      <i class="fa-solid fa-circle-notch fa-spin"></i> Loading drama details...
    </div>
    <div class="ds-episodes-section" id="dsEpisodesSection" style="display:none">
      <h2 class="ds-section-title"><i class="fa-solid fa-list"></i> Episodes</h2>
      <div id="dsEpisodesGrid" class="ds-episodes-grid"></div>
    </div>
    <script>
    (function() {
      const prov  = <?= json_encode($provider_key) ?>;
      const bid   = <?= json_encode($book_id) ?>;
      const lang  = <?= json_encode($lang) ?>;
      const title = <?= json_encode($raw_title) ?>;
      fetch('/api/drama-short/detail?provider=' + encodeURIComponent(prov)
          + '&book_id=' + encodeURIComponent(bid)
          + '&lang=' + encodeURIComponent(lang)
          + '&title=' + encodeURIComponent(title))
        .then(r => r.json())
        .then(d => {
          document.getElementById('dsDetailLoader')?.remove();
          if (!d.ok) return;
          const total = d.total_episodes || 12;
          const slug  = d.slug || '';
          const grid  = document.getElementById('dsEpisodesGrid');
          const sec   = document.getElementById('dsEpisodesSection');
          if (!grid || !sec) return;
          sec.style.display = '';
          let html = '';
          for (let ep = 1; ep <= total; ep++) {
            const url = '/drama-short/watch/' + encodeURIComponent(prov) + '/' + encodeURIComponent(bid) + '/' + ep
                      + '?lang=' + encodeURIComponent(lang) + '&slug=' + encodeURIComponent(slug);
            html += `<a class="ds-ep-card" href="${url}">
              <div class="ds-ep-num"><i class="fa-solid fa-play ds-ep-play"></i><span>${ep}</span></div>
              <div class="ds-ep-label">Episode ${ep}</div>
            </a>`;
          }
          grid.innerHTML = html;
        })
        .catch(() => { document.getElementById('dsDetailLoader')?.remove(); });
    })();
    </script>
  <?php endif; ?>

  <?php else: ?>
    <div class="ds-empty">
      <i class="fa-solid fa-circle-exclamation" style="font-size:2.5rem;color:#ff5555;display:block;margin-bottom:1rem"></i>
      <p>Could not load drama details. Please try again.</p>
      <a href="/drama-short" class="btn btn-ghost" style="margin-top:16px">Back to Browse</a>
    </div>
  <?php endif; ?>

</div>

<style>
.ds-detail-page { padding: 16px 16px 100px; max-width: 1100px; margin: 0 auto; }
.ds-back-btn { display: inline-flex; align-items: center; gap: 8px; color: #aaa;
  font-size: .9rem; margin-bottom: 20px; transition: color .15s; }
.ds-back-btn:hover { color: #fff; }
.ds-detail-hero { display: flex; gap: 28px; margin-bottom: 40px; align-items: flex-start; }
.ds-detail-poster { flex-shrink: 0; width: 200px; border-radius: 12px; overflow: hidden;
  box-shadow: 0 8px 30px rgba(0,0,0,.5); }
.ds-detail-poster img { width: 100%; display: block; }
.ds-detail-info { flex: 1; }
.ds-detail-title { font-size: 1.8rem; font-weight: 800; margin: 0 0 12px; line-height: 1.25; }
.ds-detail-meta { display: flex; flex-wrap: wrap; gap: 14px; color: #aaa;
  font-size: .85rem; margin-bottom: 16px; }
.ds-detail-meta span { display: flex; align-items: center; gap: 5px; }
.ds-detail-desc { color: #bbb; font-size: .9rem; line-height: 1.6; max-width: 600px; }
.ds-section-title { display: flex; align-items: center; gap: 10px;
  font-size: 1.15rem; font-weight: 700; margin-bottom: 18px; }
.ds-section-title i { color: var(--primary); }
.ds-episodes-section { margin-top: 32px; }
.ds-episodes-grid { display: grid;
  grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
  gap: 10px; }
.ds-ep-card { background: #1a1a2e; border: 1.5px solid #2a2a3e;
  border-radius: 10px; padding: 14px 10px; text-align: center;
  cursor: pointer; transition: all .15s; }
.ds-ep-card:hover { background: var(--primary); border-color: var(--primary);
  transform: translateY(-2px); box-shadow: 0 6px 20px rgba(var(--primary-rgb),.35); }
.ds-ep-num { display: flex; align-items: center; justify-content: center;
  gap: 6px; font-size: 1.1rem; font-weight: 700; color: #fff; }
.ds-ep-play { font-size: .65rem; color: var(--primary); }
.ds-ep-card:hover .ds-ep-play { color: #fff; }
.ds-ep-label { font-size: .7rem; color: #888; margin-top: 5px; }
.ds-ep-card:hover .ds-ep-label { color: rgba(255,255,255,.8); }
.ds-loading-detail { text-align: center; padding: 60px; color: #aaa; font-size: 1rem; }
.ds-empty { text-align: center; padding: 80px 20px; color: #666; }
@media (max-width: 600px) {
  .ds-detail-hero { flex-direction: column; }
  .ds-detail-poster { width: 140px; }
  .ds-detail-title { font-size: 1.4rem; }
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
