/* HZ Flix — frontend interactivity */

(() => {
  'use strict';

  // -------- Sticky nav background --------
  const nav = document.getElementById('siteNav');
  const onScroll = () => {
    if (!nav) return;
    if (window.scrollY > 40) nav.classList.add('is-scrolled');
    else nav.classList.remove('is-scrolled');
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // -------- Search overlay --------
  const overlay = document.getElementById('searchOverlay');
  const input   = document.getElementById('searchInput');
  const results = document.getElementById('searchResults');
  const open    = () => { overlay?.classList.add('is-open'); setTimeout(() => input?.focus(), 50); };
  const close   = () => { overlay?.classList.remove('is-open'); if (input) input.value = ''; if (results) results.innerHTML = ''; };

  document.getElementById('searchToggle')?.addEventListener('click', open);
  document.getElementById('searchClose') ?.addEventListener('click', close);
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') close();
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); open(); }
  });

  let searchT = null;
  input?.addEventListener('input', () => {
    clearTimeout(searchT);
    const q = input.value.trim();
    if (!q) { results.innerHTML = ''; return; }
    searchT = setTimeout(async () => {
      try {
        const r  = await fetch('/api/search?q=' + encodeURIComponent(q));
        const j  = await r.json();
        if (!j.results || j.results.length === 0) {
          results.innerHTML = '<p style="text-align:center;color:#888;padding:30px">No results found.</p>';
          return;
        }
        const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        results.innerHTML = j.results.map(it => `
          <a class="sr-item" href="${esc(it.href)}">
            <img src="${esc(it.poster)}" alt="" />
            <div>
              <strong>${esc(it.title)}</strong>
              <small>${it.type === 'tv' ? 'TV Show' : 'Movie'}${it.year ? ' · ' + it.year : ''} · ⭐ ${it.rating}</small>
            </div>
          </a>
        `).join('');
      } catch (err) { /* silent */ }
    }, 250);
  });
  input?.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      const q = input.value.trim();
      if (q) location.href = '/search?q=' + encodeURIComponent(q);
    }
  });

  // -------- Hero slider --------
  const slides = document.querySelectorAll('.hero-slide');
  const dots   = document.querySelectorAll('.hero-dot');
  let curr = 0, heroT = null;
  const goTo = i => {
    if (!slides.length) return;
    slides.forEach(s => s.classList.remove('is-active'));
    dots.forEach(d => d.classList.remove('is-active'));
    curr = (i + slides.length) % slides.length;
    slides[curr].classList.add('is-active');
    dots[curr]?.classList.add('is-active');
  };
  const cycle = () => goTo(curr + 1);
  if (slides.length > 1) {
    heroT = setInterval(cycle, 6500);
    dots.forEach((d, i) => d.addEventListener('click', () => { goTo(i); clearInterval(heroT); heroT = setInterval(cycle, 6500); }));
  }

  // -------- Row arrows --------
  document.querySelectorAll('.row').forEach(row => {
    const track = row.querySelector('.row-track');
    if (!track) return;
    row.querySelectorAll('[data-arrow]').forEach(btn => {
      btn.addEventListener('click', () => {
        const dir = btn.dataset.arrow === 'left' ? -1 : 1;
        track.scrollBy({ left: dir * (track.clientWidth * 0.8), behavior: 'smooth' });
      });
    });
  });

  // -------- Saved list --------
  const KEY = 'hzflix_saved';
  const getSaved = () => { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch { return []; } };
  const setSaved = list => localStorage.setItem(KEY, JSON.stringify(list));
  const isSaved  = (id, type) => getSaved().some(i => String(i.id) === String(id) && i.type === type);

  const refreshSaveBtns = () => {
    document.querySelectorAll('[data-save]').forEach(b => {
      const on = isSaved(b.dataset.id, b.dataset.type);
      b.classList.toggle('is-saved', on);
      b.querySelector('i')?.classList.toggle('fa-plus',  !on);
      b.querySelector('i')?.classList.toggle('fa-check', on);
    });
  };
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-save]');
    if (!b) return;
    e.preventDefault();
    const list = getSaved();
    const idx  = list.findIndex(i => String(i.id) === String(b.dataset.id) && i.type === b.dataset.type);
    if (idx >= 0) list.splice(idx, 1);
    else list.unshift({ id: b.dataset.id, type: b.dataset.type, title: b.dataset.title, poster: b.dataset.poster, savedAt: Date.now() });
    setSaved(list);
    refreshSaveBtns();
  });
  refreshSaveBtns();

  // -------- Share button --------
  document.getElementById('shareBtn')?.addEventListener('click', async () => {
    const data = { title: document.title, url: location.href };
    try {
      if (navigator.share) await navigator.share(data);
      else {
        await navigator.clipboard.writeText(location.href);
        toast('Link copied to clipboard');
      }
    } catch {}
  });

  // -------- Tiny toast --------
  function toast(msg) {
    const el = document.createElement('div');
    el.textContent = msg;
    Object.assign(el.style, {
      position: 'fixed', bottom: '90px', left: '50%', transform: 'translateX(-50%)',
      background: '#ff2d2d', color: '#fff', padding: '12px 20px', borderRadius: '999px',
      fontSize: '.9rem', fontWeight: '600', zIndex: '999', boxShadow: '0 8px 24px rgba(255,45,45,.4)'
    });
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 2200);
  }

  function escape(str) {
    return String(str).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  }
})();
