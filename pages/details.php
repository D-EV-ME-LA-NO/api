<?php
$type = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id   = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /'); exit; }

$d = tmdb_details($type, $id);
if (!$d || empty($d['id'])) { http_response_code(404); echo 'Not found'; exit; }

$title    = $d['title'] ?? $d['name'] ?? '';
$tagline  = $d['tagline'] ?? '';
$year     = fmt_year($d['release_date'] ?? $d['first_air_date'] ?? '');
$date     = $d['release_date'] ?? $d['first_air_date'] ?? '';
$runtime  = fmt_runtime($d['runtime'] ?? ($d['episode_run_time'][0] ?? null));
$lang     = strtoupper($d['original_language'] ?? '');
$rating   = (float)($d['vote_average'] ?? 0);
$votes    = (int)($d['vote_count']    ?? 0);
$genres   = $d['genres'] ?? [];
$cert     = '';
if ($type === 'movie') {
    foreach ($d['release_dates']['results'] ?? [] as $r) {
        if (($r['iso_3166_1'] ?? '') === 'US') { $cert = $r['release_dates'][0]['certification'] ?? ''; break; }
    }
} else {
    foreach ($d['content_ratings']['results'] ?? [] as $r) {
        if (($r['iso_3166_1'] ?? '') === 'US') { $cert = $r['rating'] ?? ''; break; }
    }
}

$cast      = array_slice($d['credits']['cast'] ?? [], 0, 12);
$director  = '';
$director_p= null;
foreach ($d['credits']['crew'] ?? [] as $c) {
    if (($c['job'] ?? '') === 'Director' || ($c['department'] ?? '') === 'Directing' && empty($director)) {
        $director  = $c['name'];
        $director_p= $c['profile_path'] ?? null;
        if (($c['job'] ?? '') === 'Director') break;
    }
}

$providers = $d['watch/providers']['results']['US']['flatrate'] ?? ($d['watch/providers']['results']['US']['buy'] ?? []);
$providers = array_slice($providers, 0, 6);

$similar      = $d['similar']['results']         ?? [];
$recs         = $d['recommendations']['results'] ?? [];

$trailer = trailer_key($d['videos'] ?? []);

$slug      = $id . '-' . slugify($title);
$watch_url = '/watch/' . $type . '/' . $slug;

$page_title = $title;
$active     = $type === 'movie' ? 'movies' : 'tv';

$views    = db_get_views($id, $type);
$comments = db_get_comments($id, $type);
$avg_user = db_avg_rating($id, $type);
$cur_user = auth_user();

$is_saved = $cur_user ? db_is_saved($cur_user['id'], $id, $type) : false;

include __DIR__ . '/../includes/header.php';
?>

<section class="detail">
  <div class="detail-backdrop" style="background-image:url('<?= img_url($d['backdrop_path'] ?? null, 'original') ?>')">
    <div class="detail-backdrop-fade"></div>
  </div>

  <div class="detail-body">
    <div class="detail-poster">
      <img src="<?= img_url($d['poster_path'] ?? null, 'w500') ?>" alt="<?= htmlspecialchars($title) ?>" />
    </div>

    <div class="detail-info">
      <h1 class="detail-title"><?= htmlspecialchars($title) ?></h1>
      <?php if ($tagline): ?><p class="detail-tagline">“<?= htmlspecialchars($tagline) ?>”</p><?php endif; ?>

      <div class="detail-meta">
        <?php if ($cert):    ?><span class="meta-pill"><?= htmlspecialchars($cert) ?></span><?php endif; ?>
        <span class="meta-rating"><i class="fa-solid fa-star"></i> <?= star($rating) ?> <small>(<?= number_format($votes) ?>)</small></span>
        <span class="meta-sep">/</span>
        <?php if ($date):    ?><span><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars(date('M j, Y', strtotime($date))) ?></span><?php endif; ?>
        <span class="meta-sep">/</span>
        <?php if ($runtime): ?><span><i class="fa-regular fa-clock"></i> <?= htmlspecialchars($runtime) ?></span><span class="meta-sep">/</span><?php endif; ?>
        <span><i class="fa-solid fa-globe"></i> <?= htmlspecialchars($lang) ?></span>
      </div>

      <div class="genre-pills">
        <?php foreach ($genres as $g): ?>
          <a class="g-pill" href="/<?= $type === 'tv' ? 'tv' : 'movies' ?>?genre=<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></a>
        <?php endforeach; ?>
      </div>

      <h3 class="detail-h3">Overview</h3>
      <p class="detail-overview"><?= nl2br(htmlspecialchars($d['overview'] ?? '')) ?></p>

      <div class="detail-actions">
        <a class="btn btn-primary btn-lg" href="<?= $watch_url ?>"><i class="fa-solid fa-play"></i> Watch Now</a>
        <?php if ($trailer): ?>
        <button class="btn btn-ghost btn-lg" id="trailerBtn" data-yt="<?= htmlspecialchars($trailer) ?>"><i class="fa-solid fa-play"></i> Trailer</button>
        <?php endif; ?>
        <button class="btn btn-icon btn-lg <?= $is_saved ? 'is-saved' : '' ?>" id="saveBtn" data-id="<?= $id ?>" data-type="<?= $type ?>" data-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>" data-poster="<?= htmlspecialchars(img_url($d['poster_path'] ?? null, 'w342'), ENT_QUOTES) ?>">
          <i class="fa-solid <?= $is_saved ? 'fa-check' : 'fa-plus' ?>"></i>
        </button>
        <button class="btn btn-icon btn-lg" id="shareBtn"><i class="fa-solid fa-share-nodes"></i></button>
      </div>
      <div style="display:flex;align-items:center;gap:16px;margin-top:8px;flex-wrap:wrap;">
        <span class="views-badge"><i class="fa-solid fa-eye"></i> <span id="viewCount"><?= number_format($views) ?></span> مشاهدة</span>
        <?php if ($avg_user): ?>
          <span class="views-badge"><i class="fa-solid fa-star" style="color:#ffce3a"></i> <?= $avg_user ?> تقييم المستخدمين</span>
        <?php endif; ?>
      </div>

      <?php if ($providers): ?>
      <div class="provider-block">
        <h4 class="block-label">AVAILABLE ON</h4>
        <div class="provider-row">
          <?php foreach ($providers as $p): ?>
            <img class="provider-logo" src="<?= img_url($p['logo_path'], 'w92') ?>" alt="<?= htmlspecialchars($p['provider_name']) ?>" title="<?= htmlspecialchars($p['provider_name']) ?>" />
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($director): ?>
      <div class="director-block">
        <h4 class="block-label">DIRECTOR</h4>
        <div class="director-card">
          <img src="<?= img_url($director_p, 'w185') ?>" alt="<?= htmlspecialchars($director) ?>" />
          <strong><?= htmlspecialchars($director) ?></strong>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($cast): ?>
  <section class="row">
    <div class="row-head"><h2>Top Cast</h2></div>
    <div class="row-track">
      <?php foreach ($cast as $c): ?>
        <div class="cast-card">
          <img loading="lazy" src="<?= img_url($c['profile_path'] ?? null, 'w185') ?>" alt="<?= htmlspecialchars($c['name']) ?>" />
          <strong><?= htmlspecialchars($c['name']) ?></strong>
          <small><?= htmlspecialchars($c['character'] ?? '') ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($type === 'tv' && !empty($d['seasons'])): ?>
  <section class="row">
    <div class="row-head"><h2>Seasons</h2></div>
    <div class="row-track">
      <?php foreach ($d['seasons'] as $s):
        if (($s['season_number'] ?? 0) < 1) continue;
      ?>
        <a class="card" href="/watch/tv/<?= $slug ?>/<?= (int)$s['season_number'] ?>/1">
          <div class="card-poster">
            <img loading="lazy" src="<?= img_url($s['poster_path'] ?? $d['poster_path'] ?? null, 'w342') ?>" alt="" />
            <div class="card-overlay"><div class="play-circle"><i class="fa-solid fa-play"></i></div></div>
          </div>
          <div class="card-meta">
            <h3>Season <?= (int)$s['season_number'] ?></h3>
            <div class="card-sub"><?= (int)($s['episode_count'] ?? 0) ?> Episodes</div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php
  function rec_row($title, $items, $type) {
      if (!$items) return;
      ?>
      <section class="row">
        <div class="row-head"><h2><?= htmlspecialchars($title) ?></h2></div>
        <div class="row-track">
          <?php foreach (array_slice($items, 0, 18) as $it):
            $n  = $it['title'] ?? $it['name'] ?? '';
            $sg = $it['id'] . '-' . slugify($n);
            $h  = '/' . ($type === 'tv' ? 'tv-show' : 'movie') . '/' . $sg;
            $r  = star((float)($it['vote_average'] ?? 0));
          ?>
            <a class="card" href="<?= $h ?>">
              <div class="card-poster">
                <img loading="lazy" src="<?= img_url($it['poster_path'] ?? null, 'w342') ?>" alt="<?= htmlspecialchars($n) ?>" />
                <div class="card-overlay"><div class="play-circle"><i class="fa-solid fa-play"></i></div></div>
                <span class="card-rating"><i class="fa-solid fa-star"></i> <?= $r ?></span>
              </div>
              <div class="card-meta"><h3><?= htmlspecialchars($n) ?></h3></div>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php
  }
  rec_row('More Like This', $similar, $type);
  rec_row('Recommended',    $recs,    $type);
  ?>

  <!-- ===== Comments Section ===== -->
  <div style="max-width:900px;margin:0 auto;padding:0 30px 60px;" class="comments-section">
    <h3><i class="fa-solid fa-comments" style="color:var(--primary)"></i> التعليقات
      <?php if ($comments): ?><small style="font-size:.9rem;color:var(--muted);font-weight:500;margin-right:8px;">(<?= count($comments) ?>)</small><?php endif; ?>
    </h3>

    <?php if ($cur_user): ?>
    <form class="comment-form" id="commentForm">
      <textarea name="body" placeholder="شاركنا رأيك في هذا الفيلم..." maxlength="1000" required></textarea>
      <div class="comment-form-row">
        <div class="star-picker">
          <input type="radio" name="rating" id="s5" value="5"><label for="s5">★</label>
          <input type="radio" name="rating" id="s4" value="4"><label for="s4">★</label>
          <input type="radio" name="rating" id="s3" value="3" checked><label for="s3">★</label>
          <input type="radio" name="rating" id="s2" value="2"><label for="s2">★</label>
          <input type="radio" name="rating" id="s1" value="1"><label for="s1">★</label>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> إرسال</button>
      </div>
    </form>
    <?php else: ?>
    <div class="login-prompt">
      <i class="fa-solid fa-lock" style="font-size:1.5rem;color:var(--primary);display:block;margin-bottom:8px;"></i>
      <a href="/login">سجّل دخولك</a> لكتابة تعليق وتقييم هذا المحتوى
    </div>
    <?php endif; ?>

    <div class="comments-list" id="commentsList">
      <?php foreach ($comments as $c): ?>
      <div class="comment-card" data-cid="<?= htmlspecialchars($c['id']) ?>">
        <div class="comment-top">
          <span class="comment-user"><i class="fa-solid fa-circle-user"></i> <?= htmlspecialchars($c['username']) ?></span>
          <span class="comment-rating"><?= str_repeat('★', (int)$c['rating']) ?><?= str_repeat('☆', 5-(int)$c['rating']) ?></span>
          <span class="comment-date"><?= date('Y/m/d', (int)$c['created_at']) ?></span>
          <?php if ($cur_user && $cur_user['id'] === (string)$c['user_id']): ?>
            <button class="comment-del" onclick="deleteComment('<?= htmlspecialchars($c['id'], ENT_QUOTES) ?>')"><i class="fa-solid fa-trash"></i></button>
          <?php endif; ?>
        </div>
        <p class="comment-body"><?= nl2br(htmlspecialchars($c['body'])) ?></p>
      </div>
      <?php endforeach; ?>
      <?php if (!$comments): ?>
        <p style="color:var(--muted);text-align:center;padding:30px 0;">لا توجد تعليقات بعد، كن أول من يعلّق!</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<script>
(function(){
  const TMDB_ID = <?= $id ?>;
  const TYPE    = '<?= $type ?>';

  // ---- View counter (once per user/session) ----
  (function(){
    const fd = new FormData();
    fd.append('id', TMDB_ID);
    fd.append('type', TYPE);
    fetch('/api/views', {method:'POST', body:fd})
      .then(r=>r.json())
      .then(j=>{ if(j.count !== undefined) document.getElementById('viewCount').textContent = j.count.toLocaleString('ar-SA'); })
      .catch(()=>{});
  })();

  // ---- Save button ----
  const saveBtn = document.getElementById('saveBtn');
  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      <?php if (!$cur_user): ?>
        location.href = '/login?redirect=' + encodeURIComponent(location.pathname);
        return;
      <?php endif; ?>
      const fd = new FormData();
      fd.append('id', saveBtn.dataset.id);
      fd.append('type', saveBtn.dataset.type);
      fd.append('title', saveBtn.dataset.title);
      fd.append('poster', saveBtn.dataset.poster);
      const r = await fetch('/api/save', {method:'POST', body:fd});
      const j = await r.json();
      if (j.ok !== undefined) {
        saveBtn.classList.toggle('is-saved', j.saved);
        saveBtn.querySelector('i').className = j.saved ? 'fa-solid fa-check' : 'fa-solid fa-plus';
      }
      if (j.error === 'login_required') location.href = '/login';
    });
  }

  // ---- Comments ----
  const form = document.getElementById('commentForm');
  if (form) {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const body   = form.querySelector('textarea').value.trim();
      const rating = form.querySelector('input[name=rating]:checked')?.value || 5;
      if (!body) return;
      const fd = new FormData();
      fd.append('id', TMDB_ID); fd.append('type', TYPE);
      fd.append('body', body); fd.append('rating', rating);
      const r = await fetch('/api/comments', {method:'POST', body:fd});
      const j = await r.json();
      if (j.ok && j.comment) {
        const c = j.comment;
        const stars = '★'.repeat(+c.rating) + '☆'.repeat(5-+c.rating);
        const d = new Date(+c.created_at*1000);
        const dateStr = d.getFullYear()+'/'+(d.getMonth()+1)+'/'+d.getDate();
        const el = document.createElement('div');
        el.className = 'comment-card'; el.dataset.cid = c.id;
        el.innerHTML = `<div class="comment-top">
          <span class="comment-user"><i class="fa-solid fa-circle-user"></i> ${escH(c.username)}</span>
          <span class="comment-rating">${stars}</span>
          <span class="comment-date">${dateStr}</span>
          <button class="comment-del" onclick="deleteComment('${escH(c.id)}')"><i class="fa-solid fa-trash"></i></button>
        </div><p class="comment-body">${escH(c.body).replace(/\n/g,'<br>')}</p>`;
        const list = document.getElementById('commentsList');
        const empty = list.querySelector('p');
        if (empty) empty.remove();
        list.prepend(el);
        form.reset();
      } else if (j.error) alert(j.error);
    });
  }

  window.deleteComment = async (cid) => {
    if (!confirm('حذف هذا التعليق؟')) return;
    const fd = new FormData();
    fd.append('action','delete');
    fd.append('comment_id', String(cid));
    fd.append('id', TMDB_ID);
    fd.append('type', TYPE);
    const r = await fetch('/api/comments', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) document.querySelector(`[data-cid="${cid}"]`)?.remove();
  };

  function escH(s){ return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
})();
</script>

<?php if ($trailer): ?>
<div class="modal" id="trailerModal" hidden>
  <div class="modal-backdrop" data-close></div>
  <div class="modal-body">
    <button class="modal-close" data-close><i class="fa-solid fa-xmark"></i></button>
    <div class="modal-video"><iframe id="trailerFrame" allow="autoplay; encrypted-media" allowfullscreen></iframe></div>
  </div>
</div>
<script>
(function () {
  const btn   = document.getElementById('trailerBtn');
  const modal = document.getElementById('trailerModal');
  const frame = document.getElementById('trailerFrame');
  if (!btn || !modal) return;
  const open  = () => { frame.src = 'https://www.youtube.com/embed/' + btn.dataset.yt + '?autoplay=1'; modal.hidden = false; document.body.style.overflow='hidden'; };
  const close = () => { frame.src = ''; modal.hidden = true; document.body.style.overflow=''; };
  btn.addEventListener('click', open);
  modal.addEventListener('click', e => { if (e.target.dataset.close !== undefined) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
})();
</script>
<?php endif; ?>

<script>
(function () {
  var type = <?= json_encode($type) ?>;
  var id   = <?= json_encode((string)$id) ?>;
  var base = 'type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id);
  var qs   = type === 'tv' ? base + '&season=1&episode=1' : base;

  var PREFETCH = [
    { id: 'lookmovie',    url: '/api/lookmovie/index.php?'        + qs },
  ];

  PREFETCH.forEach(function (srv) {
    fetch(srv.url, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j && j.ok && j.source && j.source.m3u8) {
          try {
            var key = 'hz_pf_' + srv.id + '_' + qs;
            sessionStorage.setItem(key, JSON.stringify({ ok: true, source: j.source, ts: Date.now() }));
          } catch (e) {}
        }
      })
      .catch(function () {});
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
