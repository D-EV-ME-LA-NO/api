<?php
/**
 * pages/drama-short.php
 * صفحة الدراما القصيرة — عرض عمودي من جميع المصادر مع تحميل المزيد
 */
$page_title = 'Drama Short';
$active     = 'drama-short';
include __DIR__ . '/../includes/header.php';
?>
<style>
/* ════ Drama Page ════ */
.drama-page { max-width: 1400px; margin: 0 auto; padding: 20px 16px 100px; }

/* Header */
.drama-hd {
  display: flex; align-items: center; gap: 12px; margin-bottom: 6px;
}
.drama-hd h1 { font-size: 1.7rem; font-weight: 800; margin: 0; }
.drama-badge {
  background: var(--primary); color: #fff; font-size: .68rem; font-weight: 700;
  padding: 3px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: .05em;
}
.drama-desc { color: #aaa; font-size: .9rem; margin: 4px 0 18px; }

/* Search */
.drama-search-wrap { display: flex; gap: 8px; max-width: 520px; margin-bottom: 24px; }
.drama-search-inner {
  flex: 1; display: flex; align-items: center; gap: 8px;
  background: #1a1a2e; border: 1.5px solid #2a2a3e; border-radius: 10px;
  padding: 0 14px; transition: border-color .15s;
}
.drama-search-inner:focus-within { border-color: var(--primary); }
.drama-search-inner i { color: #666; }
.drama-search-inner input {
  flex: 1; background: none; border: none; outline: none; color: #fff;
  font-size: .95rem; padding: 11px 0;
}
.drama-search-btn {
  background: var(--primary); color: #fff; border: none; border-radius: 10px;
  padding: 11px 18px; font-size: .9rem; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; gap: 6px; white-space: nowrap;
}
.drama-search-btn:hover { background: #e02020; }
.drama-clear-btn {
  background: none; border: none; color: #666; cursor: pointer;
  padding: 4px; display: flex; align-items: center;
}
.drama-clear-btn:hover { color: #fff; }

/* Status bar */
.drama-status {
  font-size: .82rem; color: #888; margin-bottom: 14px; min-height: 22px;
}

/* Grid */
.drama-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 14px;
}
@media (max-width: 480px) {
  .drama-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; }
}

/* Card */
.drama-card {
  display: block; text-decoration: none; color: inherit;
  transition: transform .15s;
}
.drama-card:hover { transform: translateY(-3px); }
.drama-card-poster {
  position: relative; width: 100%; padding-bottom: 150%;
  border-radius: 10px; overflow: hidden;
  background: #1a1a2e;
  box-shadow: 0 4px 16px rgba(0,0,0,.4);
}
.drama-card-poster img {
  position: absolute; inset: 0; width: 100%; height: 100%;
  object-fit: cover; display: block;
}
.drama-card-poster .img-placeholder {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  background: #1a1a2e;
}
.drama-card-poster .img-placeholder i { font-size: 2rem; color: #333; }
.drama-card-overlay {
  position: absolute; inset: 0;
  background: linear-gradient(to top, rgba(0,0,0,.7) 0%, transparent 50%);
  opacity: 0; transition: opacity .2s;
  display: flex; align-items: center; justify-content: center;
}
.drama-card:hover .drama-card-overlay { opacity: 1; }
.drama-card-play {
  width: 42px; height: 42px; border-radius: 50%;
  background: rgba(255,45,45,.85); display: flex; align-items: center;
  justify-content: center; color: #fff; font-size: .9rem;
}
.drama-card-badge {
  position: absolute; top: 6px; right: 6px;
  background: rgba(255,45,45,.9); color: #fff;
  font-size: .6rem; font-weight: 700; padding: 2px 6px;
  border-radius: 4px; text-transform: uppercase;
}
.drama-card-adult {
  position: absolute; top: 6px; left: 6px;
  background: rgba(220,0,0,.85); color: #fff;
  font-size: .6rem; font-weight: 700; padding: 2px 6px; border-radius: 4px;
}
.drama-card-meta { padding: 8px 2px 0; }
.drama-card-meta h3 {
  font-size: .82rem; font-weight: 600; margin: 0 0 3px;
  line-height: 1.3;
  display: -webkit-box; -webkit-line-clamp: 2;
  -webkit-box-orient: vertical; overflow: hidden;
}
.drama-card-meta .card-prov {
  font-size: .72rem; color: #777;
  display: -webkit-box; -webkit-line-clamp: 1;
  -webkit-box-orient: vertical; overflow: hidden;
}

/* Load more */
.drama-load-wrap {
  text-align: center; margin: 40px 0 20px;
}
.drama-load-btn {
  background: #1a1a2e; border: 1.5px solid #2a2a3e;
  color: #ccc; border-radius: 10px;
  padding: 13px 36px; font-size: .95rem; font-weight: 600;
  cursor: pointer; transition: all .15s;
  display: inline-flex; align-items: center; gap: 8px;
}
.drama-load-btn:hover { background: var(--primary); border-color: var(--primary); color: #fff; }
.drama-load-btn:disabled { opacity: .5; cursor: not-allowed; }

/* Empty / Error */
.drama-empty {
  text-align: center; padding: 80px 20px; color: #666;
}
.drama-empty i { font-size: 3rem; display: block; margin-bottom: 14px; }
.drama-empty p { font-size: 1rem; }

/* Spinner */
.drama-spinner {
  text-align: center; padding: 60px 20px; color: #888;
}
.drama-spinner i { font-size: 2rem; margin-bottom: 12px; display: block; }

/* Search results mode */
.drama-section-tag {
  font-size: .7rem; color: var(--primary); font-weight: 600;
  margin-bottom: 8px; display: block; text-transform: uppercase; letter-spacing: .04em;
}
</style>

<div class="drama-page">

  <!-- Header -->
  <div class="drama-hd">
    <i class="fa-solid fa-clapperboard" style="color:var(--primary);font-size:1.4rem"></i>
    <h1>Drama Short</h1>
    <span class="drama-badge">Short Series</span>
  </div>
  <p class="drama-desc">مسلسلات قصيرة مترجمة من أفضل منصات الدراما حول العالم</p>

  <!-- Search -->
  <div class="drama-search-wrap">
    <div class="drama-search-inner">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="dramaSearchInput" placeholder="Search dramas..." autocomplete="off" />
      <button class="drama-clear-btn" id="dramaClearBtn" style="display:none" aria-label="Clear">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
    <button class="drama-search-btn" id="dramaSearchBtn">
      <i class="fa-solid fa-magnifying-glass"></i> Search
    </button>
  </div>

  <!-- Status -->
  <div class="drama-status" id="dramaStatus">Loading from all providers…</div>

  <!-- Grid -->
  <div class="drama-grid" id="dramaGrid">
    <div class="drama-spinner" style="grid-column:1/-1">
      <i class="fa-solid fa-circle-notch fa-spin"></i>
      <p>Fetching dramas…</p>
    </div>
  </div>

  <!-- Load More -->
  <div class="drama-load-wrap" id="dramaLoadWrap" style="display:none">
    <button class="drama-load-btn" id="dramaLoadBtn">
      <i class="fa-solid fa-rotate-right"></i> Load More Dramas
    </button>
  </div>

</div>

<script>
(function () {
  'use strict';

  const grid      = document.getElementById('dramaGrid');
  const loadWrap  = document.getElementById('dramaLoadWrap');
  const loadBtn   = document.getElementById('dramaLoadBtn');
  const status    = document.getElementById('dramaStatus');
  const searchIn  = document.getElementById('dramaSearchInput');
  const searchBtn = document.getElementById('dramaSearchBtn');
  const clearBtn  = document.getElementById('dramaClearBtn');

  let currentPage  = 1;
  let isLoading    = false;
  let hasMore      = true;
  let totalLoaded  = 0;
  let searchMode   = false;
  let searchQuery  = '';

  // ── Image proxy helper ──────────────────────────────────────────────────────
  function proxyImg(url) {
    if (!url) return '';
    return '/api/drama-short/img-proxy.php?url=' + encodeURIComponent(url);
  }

  // ── Render cards ────────────────────────────────────────────────────────────
  function renderCards(items, append = true) {
    if (!append) grid.innerHTML = '';
    if (!items.length && !append) {
      grid.innerHTML = '<div class="drama-empty" style="grid-column:1/-1"><i class="fa-solid fa-film"></i><p>No dramas found</p></div>';
      return;
    }
    items.forEach(item => {
      const href = '/drama-short/' + encodeURIComponent(item.provider) + '/' + encodeURIComponent(item.book_id)
                 + '?lang=en-US&title=' + encodeURIComponent(item.title || '');
      const poster = item.poster_url || '';
      const title  = (item.title || 'Unknown').replace(/</g, '&lt;');
      const prov   = (item.category || item.provider || '').replace(/</g, '&lt;');

      const card = document.createElement('a');
      card.className = 'drama-card';
      card.href = href;
      card.innerHTML = `
        <div class="drama-card-poster">
          ${poster
            ? `<img loading="lazy" src="${proxyImg(poster).replace(/"/g,'&quot;')}"
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"
                    alt="${title}" />`
            : ''}
          <div class="img-placeholder" ${poster ? 'style="display:none"' : ''}>
            <i class="fa-solid fa-clapperboard"></i>
          </div>
          <div class="drama-card-overlay">
            <div class="drama-card-play"><i class="fa-solid fa-circle-info"></i></div>
          </div>
          <span class="drama-card-badge">Short</span>
          ${item.is_adult ? '<span class="drama-card-adult">18+</span>' : ''}
        </div>
        <div class="drama-card-meta">
          <h3>${title}</h3>
          ${prov ? `<span class="card-prov">${prov}</span>` : ''}
        </div>`;
      grid.appendChild(card);
    });
  }

  // ── Fetch all providers page ─────────────────────────────────────────────────
  async function loadPage(page) {
    if (isLoading) return;
    isLoading = true;
    loadBtn.disabled = true;
    loadBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Loading…';

    try {
      const res  = await fetch('/api/drama-short/all.php?page=' + page + '&lang=en-US&_cb=' + Date.now());
      const data = await res.json();

      if (!data.ok) throw new Error(data.error || 'Failed');

      const items = data.items || [];
      renderCards(items, page > 1);
      totalLoaded += items.length;
      hasMore      = !!data.hasMore;

      const provLabels = (data.providers_in_page || []).join(', ');
      status.textContent = `Showing ${totalLoaded} dramas from ${data.total_providers || '?'} providers (loaded: ${provLabels})`;

      loadWrap.style.display = hasMore ? 'block' : 'none';
      currentPage = page + 1;
    } catch (e) {
      if (page === 1) {
        grid.innerHTML = '<div class="drama-empty" style="grid-column:1/-1"><i class="fa-solid fa-triangle-exclamation"></i><p>Failed to load dramas. Please try again.</p></div>';
      }
      status.textContent = 'Error loading dramas.';
      loadWrap.style.display = 'block';
    } finally {
      isLoading = false;
      loadBtn.disabled = false;
      loadBtn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Load More Dramas';
    }
  }

  // ── Search ───────────────────────────────────────────────────────────────────
  async function doSearch(q) {
    if (!q.trim()) { exitSearch(); return; }
    searchMode  = true;
    searchQuery = q.trim();
    grid.innerHTML = '<div class="drama-spinner" style="grid-column:1/-1"><i class="fa-solid fa-circle-notch fa-spin"></i><p>Searching…</p></div>';
    loadWrap.style.display = 'none';
    status.textContent = 'Searching for "' + q + '"…';
    clearBtn.style.display = 'flex';

    try {
      const res  = await fetch('/api/drama-short/sections.php?provider=bibishort&lang=en-US&q='
                              + encodeURIComponent(q) + '&_cb=' + Date.now());
      const data = await res.json();
      const raw  = (data.sections || []).flatMap(s => s.items || []);

      const items = raw.map(it => {
        const parts = (it.id || '').split(':');
        const prov  = parts[0] || 'bibishort';
        let bid     = parts[1] || '';
        if (!bid) { const m = (it.url || '').match(/book_id=(\d+)/); if (m) bid = m[1]; }
        return { provider: prov, book_id: bid, title: it.title, poster_url: it.poster_url,
                 category: it.category_name || '', is_adult: !!it.is_adult };
      }).filter(i => i.book_id);

      renderCards(items, false);
      status.textContent = items.length
        ? `Found ${items.length} results for "${q}"`
        : `No results for "${q}"`;
    } catch (e) {
      grid.innerHTML = '<div class="drama-empty" style="grid-column:1/-1"><i class="fa-solid fa-triangle-exclamation"></i><p>Search failed. Please try again.</p></div>';
    }
  }

  function exitSearch() {
    searchMode  = false;
    searchQuery = '';
    searchIn.value = '';
    clearBtn.style.display = 'none';
    // Re-render from page 1
    currentPage = 1;
    totalLoaded = 0;
    hasMore     = true;
    grid.innerHTML = '<div class="drama-spinner" style="grid-column:1/-1"><i class="fa-solid fa-circle-notch fa-spin"></i><p>Fetching dramas…</p></div>';
    loadPage(1);
  }

  // ── Events ───────────────────────────────────────────────────────────────────
  loadBtn.addEventListener('click', () => { if (hasMore) loadPage(currentPage); });

  searchBtn.addEventListener('click', () => doSearch(searchIn.value));
  searchIn.addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(searchIn.value); });
  searchIn.addEventListener('input', () => {
    clearBtn.style.display = searchIn.value ? 'flex' : 'none';
  });
  clearBtn.addEventListener('click', exitSearch);

  // ── Init ─────────────────────────────────────────────────────────────────────
  loadPage(1);

})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
