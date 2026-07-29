<?php
$page_title = 'My Saved';
$active     = 'saved';
include __DIR__ . '/../includes/header.php';
?>

<section class="browse">
  <div class="browse-head">
    <div>
      <h1 class="browse-title">My List</h1>
      <p class="browse-sub">Your saved movies and shows, stored locally on this device</p>
    </div>
  </div>

  <div id="savedEmpty" class="empty-state hidden">
    <i class="fa-regular fa-bookmark"></i>
    <h3>Nothing saved yet</h3>
    <p>Click the <i class="fa-solid fa-plus"></i> button on any title to add it here.</p>
    <a class="btn btn-primary" href="/">Browse Home</a>
  </div>

  <div id="savedGrid" class="grid"></div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
(function () {
  const list = JSON.parse(localStorage.getItem('hzflix_saved') || '[]');
  const grid = document.getElementById('savedGrid');
  const empty = document.getElementById('savedEmpty');
  if (!list.length) { empty.classList.remove('hidden'); return; }
  grid.innerHTML = list.map(item => {
    const slug = `${item.id}-${(item.title||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')}`;
    const href = `/${item.type === 'tv' ? 'tv-show' : 'movie'}/${slug}`;
    return `
      <a class="card card-grid" href="${href}">
        <div class="card-poster">
          <img loading="lazy" src="${item.poster}" alt="${item.title}" />
          <div class="card-overlay"><div class="play-circle"><i class="fa-solid fa-play"></i></div></div>
          <button class="card-remove" data-remove="${item.type}:${item.id}" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="card-meta">
          <h3>${item.title}</h3>
          <div class="card-sub">${item.type === 'tv' ? 'TV Show' : 'Movie'}</div>
        </div>
      </a>`;
  }).join('');

  grid.addEventListener('click', e => {
    const btn = e.target.closest('[data-remove]');
    if (!btn) return;
    e.preventDefault(); e.stopPropagation();
    const [t, id] = btn.dataset.remove.split(':');
    const next = list.filter(i => !(String(i.id) === id && i.type === t));
    localStorage.setItem('hzflix_saved', JSON.stringify(next));
    location.reload();
  });
})();
</script>
