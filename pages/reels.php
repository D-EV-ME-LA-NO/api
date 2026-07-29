<?php
require_once __DIR__ . '/../config.php';
$page_title = 'Reels · Discover';
$active     = 'reels';
$cur_user   = auth_user();
include __DIR__ . '/../includes/header.php';
?>
<style>
/* ── Reels page ─────────────────────────────────────────────────────────── */
body { overflow: hidden; }
.site-main { padding: 0 !important; }
.site-footer, .bottom-nav { display: none !important; }

.reels-page {
  position: fixed; inset: 0; background: #000; z-index: 10;
  display: flex; flex-direction: column;
}

/* Top bar */
.reels-topbar {
  position: absolute; top: 0; left: 0; right: 0; z-index: 50;
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 18px; padding-top: calc(14px + env(safe-area-inset-top));
  background: linear-gradient(to bottom, rgba(0,0,0,.7) 0%, transparent 100%);
}
.reels-back {
  width: 38px; height: 38px; border-radius: 50%;
  background: rgba(255,255,255,.12); border: none;
  color: #fff; font-size: 1rem; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  backdrop-filter: blur(8px);
}
.reels-topbar-title { font-size: 1rem; font-weight: 700; color: #fff; letter-spacing: .02em; }

/* Feed */
.reels-feed {
  flex: 1; overflow-y: scroll; overflow-x: hidden;
  scroll-snap-type: y mandatory; -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.reels-feed::-webkit-scrollbar { display: none; }

/* Individual reel */
.reel-slide {
  position: relative; width: 100%; height: 100dvh;
  scroll-snap-align: start; scroll-snap-stop: always;
  overflow: hidden; background: #000;
  display: flex; align-items: center; justify-content: center;
}

/* Blurred backdrop */
.reel-bg {
  position: absolute; inset: -20px;
  background-size: cover; background-position: center;
  filter: blur(20px) brightness(.35) saturate(1.3);
  transform: scale(1.1); pointer-events: none;
}

/* Poster image (shown before play) */
.reel-poster {
  position: absolute; inset: 0; z-index: 2;
  display: flex; align-items: center; justify-content: center;
  background-size: cover; background-position: center;
  transition: opacity .3s;
}
.reel-poster.hidden { opacity: 0; pointer-events: none; }

/* Big play button */
.reel-play-btn {
  width: 72px; height: 72px; border-radius: 50%;
  background: rgba(229,9,20,.85); border: 3px solid rgba(255,255,255,.7);
  color: #fff; font-size: 1.6rem; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  backdrop-filter: blur(6px);
  transition: transform .15s, background .15s;
  box-shadow: 0 4px 24px rgba(0,0,0,.5);
}
.reel-play-btn:active { transform: scale(.9); }
.reel-play-btn i { margin-left: 4px; }

/* YouTube iframe container */
.reel-player {
  position: absolute; inset: 0; z-index: 2;
  pointer-events: none; opacity: 0;
  transition: opacity .4s;
}
.reel-player.playing { opacity: 1; pointer-events: all; }
.reel-player iframe {
  width: 100%; height: 100%; border: none; display: block;
}

/* Bottom gradient for text */
.reel-bottom-fade {
  position: absolute; bottom: 0; left: 0; right: 0; z-index: 5;
  height: 65%; pointer-events: none;
  background: linear-gradient(to top, rgba(0,0,0,.9) 0%, rgba(0,0,0,.5) 50%, transparent 100%);
}

/* Info overlay (bottom-left) — hidden until tap */
.reel-info {
  position: absolute; bottom: 100px; left: 16px; right: 80px; z-index: 10;
  padding-bottom: env(safe-area-inset-bottom);
  opacity: 0; pointer-events: none;
  transform: translateY(10px);
  transition: opacity .25s ease, transform .25s ease;
}
.reel-slide.info-visible .reel-info {
  opacity: 1; pointer-events: all; transform: translateY(0);
}
/* Also fade the bottom gradient when info is hidden */
.reel-slide .reel-bottom-fade {
  opacity: 0; transition: opacity .25s ease;
}
.reel-slide.info-visible .reel-bottom-fade { opacity: 1; }
.reel-info h2 { font-size: 1.15rem; font-weight: 800; margin: 0 0 6px; line-height: 1.25; color: #fff; }
.reel-meta { font-size: .8rem; color: rgba(255,255,255,.7); margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
.reel-meta .reel-type-badge {
  background: var(--primary); color: #fff; font-size: .65rem; font-weight: 700;
  padding: 2px 7px; border-radius: 4px; text-transform: uppercase; letter-spacing: .05em;
}
.reel-genres { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 7px; }
.reel-genre-tag {
  font-size: .72rem; padding: 3px 9px; border-radius: 20px;
  background: rgba(255,255,255,.12); color: rgba(255,255,255,.85);
  border: 1px solid rgba(255,255,255,.18); backdrop-filter: blur(6px);
}
.reel-overview {
  font-size: .82rem; color: rgba(255,255,255,.7); line-height: 1.45;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}

/* Watch button */
.reel-watch-btn {
  display: inline-flex; align-items: center; gap: 7px;
  background: #fff; color: #000;
  padding: 9px 18px; border-radius: 999px;
  font-size: .85rem; font-weight: 700; margin-top: 10px;
  transition: background .15s;
}
.reel-watch-btn:hover { background: rgba(255,255,255,.88); color: #000; }
.reel-watch-btn i { font-size: .9rem; }

/* Action buttons (right side) */
.reel-actions {
  position: absolute; right: 12px; bottom: 110px; z-index: 10;
  display: flex; flex-direction: column; align-items: center; gap: 18px;
  padding-bottom: env(safe-area-inset-bottom);
}
.reel-action-btn {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  background: none; border: none; color: #fff; cursor: pointer;
  -webkit-tap-highlight-color: transparent; padding: 0;
}
.reel-action-icon {
  width: 46px; height: 46px; border-radius: 50%;
  background: rgba(255,255,255,.15); backdrop-filter: blur(10px);
  border: 1px solid rgba(255,255,255,.2);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.15rem; transition: background .15s, transform .1s;
}
.reel-action-btn:active .reel-action-icon { transform: scale(.88); }
.reel-action-btn.is-liked .reel-action-icon { background: rgba(229,9,20,.35); border-color: rgba(229,9,20,.6); }
.reel-action-btn.is-liked .reel-action-icon i { color: #ff4d5a; }
.reel-action-btn.is-saved .reel-action-icon { background: rgba(255,204,0,.2); border-color: rgba(255,204,0,.4); }
.reel-action-btn.is-saved .reel-action-icon i { color: #ffcc00; }
.reel-action-label { font-size: .65rem; color: rgba(255,255,255,.75); font-weight: 600; }

/* Mute button */
.reel-mute-btn {
  position: absolute; top: 68px; right: 14px; z-index: 20;
  width: 34px; height: 34px; border-radius: 50%;
  background: rgba(0,0,0,.45); backdrop-filter: blur(8px);
  border: 1px solid rgba(255,255,255,.2);
  color: #fff; font-size: .85rem; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .15s;
}

/* Loader slide */
.reel-loader {
  display: flex; align-items: center; justify-content: center;
  height: 100dvh; scroll-snap-align: start;
}
.reel-spinner {
  width: 40px; height: 40px; border-radius: 50%;
  border: 3px solid rgba(255,255,255,.12);
  border-top-color: var(--primary);
  animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Comment Sheet ──────────────────────────────────────────────────────── */
.reel-sheet-backdrop {
  position: fixed; inset: 0; background: rgba(0,0,0,.6);
  z-index: 200; opacity: 0; pointer-events: none; transition: opacity .3s;
}
.reel-sheet-backdrop.open { opacity: 1; pointer-events: all; }

.reel-sheet {
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 201;
  background: #141420; border-radius: 22px 22px 0 0;
  transform: translateY(100%); transition: transform .35s cubic-bezier(.16,1,.3,1);
  max-height: 75dvh; display: flex; flex-direction: column;
  padding-bottom: env(safe-area-inset-bottom);
}
.reel-sheet.open { transform: translateY(0); }
.reel-sheet-handle {
  width: 36px; height: 4px; background: rgba(255,255,255,.2);
  border-radius: 2px; margin: 12px auto 0; flex-shrink: 0;
}
.reel-sheet-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 18px 8px; border-bottom: 1px solid rgba(255,255,255,.07); flex-shrink: 0;
}
.reel-sheet-head h3 { font-size: .95rem; font-weight: 700; margin: 0; }
.reel-sheet-close {
  background: none; border: none; color: rgba(255,255,255,.5);
  font-size: 1.1rem; cursor: pointer; padding: 4px;
}
.reel-comments-list {
  flex: 1; overflow-y: auto; padding: 10px 16px;
  scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.1) transparent;
}
.reel-comment-item {
  display: flex; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,.05);
}
.reel-comment-avatar {
  width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
  background: var(--primary); display: flex; align-items: center; justify-content: center;
  font-size: .8rem; font-weight: 700; color: #fff;
}
.reel-comment-body { flex: 1; }
.reel-comment-user { font-size: .8rem; font-weight: 700; color: #fff; }
.reel-comment-text { font-size: .82rem; color: rgba(255,255,255,.7); margin-top: 2px; line-height: 1.4; }
.reel-sheet-input {
  display: flex; gap: 10px; padding: 10px 16px 12px;
  border-top: 1px solid rgba(255,255,255,.07); flex-shrink: 0;
}
.reel-sheet-input input {
  flex: 1; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
  border-radius: 999px; padding: 9px 16px; color: #fff; font-size: .88rem;
  font-family: inherit; outline: none;
}
.reel-sheet-input input::placeholder { color: rgba(255,255,255,.35); }
.reel-send-btn {
  width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
  background: var(--primary); border: none; color: #fff;
  font-size: .9rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: background .15s;
}
.reel-no-login {
  padding: 14px 16px; font-size: .85rem; color: rgba(255,255,255,.45); text-align: center;
}
.reel-no-login a { color: var(--primary); }
.reel-empty-comments {
  padding: 30px 0; text-align: center; color: rgba(255,255,255,.3); font-size: .85rem;
}
</style>

<div class="reels-page" id="reelsPage">

  <!-- Top bar -->
  <div class="reels-topbar">
    <button class="reels-back" onclick="history.back()"><i class="fa-solid fa-arrow-left"></i></button>
    <span class="reels-topbar-title"><i class="fa-solid fa-clapperboard" style="color:var(--primary)"></i> Reels</span>
    <div style="width:38px"></div>
  </div>

  <!-- Feed -->
  <div class="reels-feed" id="reelsFeed"></div>
</div>

<!-- Comment Sheet Backdrop -->
<div class="reel-sheet-backdrop" id="sheetBackdrop" onclick="closeSheet()"></div>

<!-- Comment Sheet -->
<div class="reel-sheet" id="reelSheet">
  <div class="reel-sheet-handle"></div>
  <div class="reel-sheet-head">
    <h3>Comments</h3>
    <button class="reel-sheet-close" onclick="closeSheet()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="reel-comments-list" id="commentsList"></div>
  <div class="reel-sheet-input" id="sheetInputArea">
    <?php if ($cur_user): ?>
      <input type="text" id="commentInput" placeholder="Add a comment…" maxlength="300" />
      <button class="reel-send-btn" id="sendCommentBtn"><i class="fa-solid fa-paper-plane"></i></button>
    <?php else: ?>
      <div class="reel-no-login" style="flex:1">
        <a href="/login">Sign in</a> to comment
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
'use strict';

const LOGGED_IN = <?= $cur_user ? 'true' : 'false' ?>;

// ── State ──────────────────────────────────────────────────────────────────
let reelsData   = [];
let currentIdx  = 0;
let apiPage     = Math.ceil(Math.random() * 4) + 1;
let loading     = false;
let muted       = true;
let activeFrame = null;
let sheetItemId = null;
let sheetItemType = null;
const feed      = document.getElementById('reelsFeed');

// ── Saved state (localStorage) ─────────────────────────────────────────────
function getSaved() { try { return JSON.parse(localStorage.getItem('reel_saved') || '{}'); } catch { return {}; } }
function setSaved(obj) { localStorage.setItem('reel_saved', JSON.stringify(obj)); }
function isSaved(id) { return !!getSaved()[id]; }

// ── Fetch reels ────────────────────────────────────────────────────────────
async function loadReels() {
  if (loading) return;
  loading = true;
  const loaderEl = document.getElementById('reelLoader');
  if (loaderEl) loaderEl.style.display = 'flex';
  try {
    const res  = await fetch('/api/reels.php?page=' + apiPage++);
    const data = await res.json();
    if (data.ok && data.reels.length) {
      const startIdx = reelsData.length;
      reelsData.push(...data.reels);
      data.reels.forEach((r, i) => feed.insertBefore(buildSlide(r, startIdx + i), loaderEl || null));
    }
  } catch(e) {}
  loading = false;
  if (loaderEl) loaderEl.style.display = 'none';
}

// ── Build one reel slide ───────────────────────────────────────────────────
function buildSlide(r, idx) {
  const genres = (r.genres || []).map(g => `<span class="reel-genre-tag">${esc(g)}</span>`).join('');
  const type_badge = r.type === 'tv' ? 'TV' : 'Movie';
  const saved_cls  = isSaved(r.id) ? ' is-saved' : '';
  const ov = r.overview ? r.overview.slice(0, 110) + (r.overview.length > 110 ? '…' : '') : '';
  const bg = r.backdrop || r.poster;

  const div = document.createElement('div');
  div.className = 'reel-slide info-visible';
  div.dataset.idx  = idx;
  div.dataset.key  = r.trailer_key;
  div.dataset.id   = r.id;
  div.dataset.type = r.type;
  div.innerHTML = `
    <div class="reel-bg" style="background-image:url('${bg}')"></div>

    <!-- Poster shown until user taps play -->
    <div class="reel-poster" id="poster-${idx}" style="background-image:url('${bg}')"
         onclick="startPlay(${idx})">
      <button class="reel-play-btn" aria-label="Play trailer">
        <i class="fa-solid fa-play"></i>
      </button>
    </div>

    <!-- YouTube iframe (injected on play tap) -->
    <div class="reel-player" id="player-${idx}"></div>

    <div class="reel-bottom-fade"></div>

    <!-- Mute toggle (shown only while playing) -->
    <button class="reel-mute-btn" id="mute-${idx}" style="display:none" onclick="toggleMute(${idx})">
      <i class="fa-solid fa-volume-xmark"></i>
    </button>

    <!-- Info (always visible) -->
    <div class="reel-info">
      <h2>${esc(r.title)}</h2>
      <div class="reel-meta">
        <span class="reel-type-badge">${type_badge}</span>
        ${r.year   ? `<span>${r.year}</span>`       : ''}
        ${r.rating > 0 ? `<span>⭐ ${r.rating}</span>` : ''}
      </div>
      ${genres ? `<div class="reel-genres">${genres}</div>` : ''}
      ${ov     ? `<p class="reel-overview">${esc(ov)}</p>` : ''}
      <a class="reel-watch-btn" href="${r.watch_url}">
        <i class="fa-solid fa-play"></i> Watch Now
      </a>
    </div>

    <!-- Actions -->
    <div class="reel-actions">
      <button class="reel-action-btn${saved_cls}" id="like-${idx}" onclick="toggleLike(${idx})">
        <div class="reel-action-icon"><i class="fa-solid fa-heart"></i></div>
        <span class="reel-action-label">Like</span>
      </button>
      <button class="reel-action-btn" onclick="openSheet(${idx})">
        <div class="reel-action-icon"><i class="fa-regular fa-comment"></i></div>
        <span class="reel-action-label">Comment</span>
      </button>
      <button class="reel-action-btn" onclick="shareReel(${idx})">
        <div class="reel-action-icon"><i class="fa-solid fa-share-nodes"></i></div>
        <span class="reel-action-label">Share</span>
      </button>
      <a class="reel-action-btn" href="${r.detail_url}">
        <div class="reel-action-icon"><i class="fa-solid fa-circle-info"></i></div>
        <span class="reel-action-label">Info</span>
      </a>
    </div>`;
  return div;
}

function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

// ── Info always visible — no tap-to-toggle needed ─────────────────────────

// ── Loader placeholder ─────────────────────────────────────────────────────
function addLoader() {
  const d = document.createElement('div');
  d.id = 'reelLoader';
  d.className = 'reel-loader';
  d.innerHTML = '<div class="reel-spinner"></div>';
  feed.appendChild(d);
}

// ── startPlay: triggered by user tap on poster (counts as gesture) ─────────
function startPlay(idx) {
  const r      = reelsData[idx];
  if (!r) return;
  const poster = document.getElementById('poster-' + idx);
  const cont   = document.getElementById('player-' + idx);
  const muteBtn = document.getElementById('mute-' + idx);
  if (!cont) return;

  // Hide poster, show mute button
  if (poster) { poster.style.opacity = '0'; poster.style.pointerEvents = 'none'; }
  if (muteBtn) muteBtn.style.display = 'flex';

  if (!cont.querySelector('iframe')) {
    const key = r.trailer_key;
    const fr  = document.createElement('iframe');
    // youtube-nocookie = privacy-enhanced mode, works on more hosting configs
    fr.src = `https://www.youtube-nocookie.com/embed/${key}?autoplay=1&mute=1&loop=1&playlist=${key}&controls=1&rel=0&iv_load_policy=3`;
    fr.allow = 'autoplay; fullscreen; encrypted-media; picture-in-picture';
    fr.setAttribute('allowfullscreen', '');
    fr.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
    cont.classList.add('playing');
    cont.appendChild(fr);
    activeFrame = fr;
    muted = true; // starts muted (required for autoplay)
    if (muteBtn) muteBtn.innerHTML = '<i class="fa-solid fa-volume-xmark"></i>';
  }
  activeFrame = cont.querySelector('iframe');
}

function stopReel(idx) {
  const cont   = document.getElementById('player-' + idx);
  const poster = document.getElementById('poster-' + idx);
  const muteBtn = document.getElementById('mute-' + idx);
  if (!cont) return;
  const fr = cont.querySelector('iframe');
  if (fr) { fr.src = ''; cont.innerHTML = ''; cont.classList.remove('playing'); }
  if (poster) { poster.style.opacity = '1'; poster.style.pointerEvents = 'auto'; }
  if (muteBtn) muteBtn.style.display = 'none';
}

function toggleMute(idx) {
  muted = !muted;
  const btn = document.getElementById('mute-' + idx);
  if (btn) btn.innerHTML = muted
    ? '<i class="fa-solid fa-volume-xmark"></i>'
    : '<i class="fa-solid fa-volume-high"></i>';
  // Reload iframe with new mute state
  const r    = reelsData[idx];
  const cont = document.getElementById('player-' + idx);
  if (!r || !cont) return;
  cont.innerHTML = '';
  const key = r.trailer_key;
  const fr  = document.createElement('iframe');
  fr.src = `https://www.youtube-nocookie.com/embed/${key}?autoplay=1&mute=${muted ? 1 : 0}&loop=1&playlist=${key}&controls=1&rel=0&iv_load_policy=3`;
  fr.allow = 'autoplay; fullscreen; encrypted-media; picture-in-picture';
  fr.setAttribute('allowfullscreen', '');
  fr.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
  cont.appendChild(fr);
  activeFrame = fr;
}

// ── IntersectionObserver: stop video when slide leaves view ───────────────
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    const idx = parseInt(entry.target.dataset.idx);
    if (entry.isIntersecting) {
      currentIdx = idx;
      // Load more when near end
      if (idx >= reelsData.length - 3) loadReels();
    } else {
      // Stop video when slide scrolls away
      stopReel(idx);
    }
  });
}, { root: feed, threshold: 0.5 });

// ── Like / Save ────────────────────────────────────────────────────────────
async function toggleLike(idx) {
  const r   = reelsData[idx];
  const btn = document.getElementById('like-' + idx);
  if (!r || !btn) return;

  const saved = isSaved(r.id);
  const obj   = getSaved();
  if (saved) delete obj[r.id]; else obj[r.id] = 1;
  setSaved(obj);
  btn.classList.toggle('is-saved', !saved);

  // Sync with server if logged in
  if (LOGGED_IN) {
    try {
      const fd = new FormData();
      fd.append('id',     r.id);
      fd.append('type',   r.type);
      fd.append('title',  r.title);
      fd.append('poster', r.poster);
      await fetch('/api/save', { method: 'POST', body: fd });
    } catch {}
  }
}

// ── Share ──────────────────────────────────────────────────────────────────
async function shareReel(idx) {
  const r = reelsData[idx];
  if (!r) return;
  const url = location.origin + r.detail_url;
  if (navigator.share) {
    try { await navigator.share({ title: r.title, url }); return; } catch {}
  }
  await navigator.clipboard?.writeText(url).catch(() => {});
  showToast('Link copied!');
}

function showToast(msg) {
  let t = document.getElementById('reelToast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'reelToast';
    Object.assign(t.style, {
      position:'fixed', bottom:'100px', left:'50%', transform:'translateX(-50%)',
      background:'rgba(255,255,255,.15)', backdropFilter:'blur(12px)',
      color:'#fff', padding:'8px 18px', borderRadius:'999px',
      fontSize:'.85rem', fontWeight:'600', zIndex:'999', transition:'opacity .3s',
      border:'1px solid rgba(255,255,255,.2)', whiteSpace:'nowrap'
    });
    document.body.appendChild(t);
  }
  t.textContent = msg; t.style.opacity = '1';
  clearTimeout(t._to);
  t._to = setTimeout(() => t.style.opacity = '0', 2000);
}

// ── Comment Sheet ──────────────────────────────────────────────────────────
async function openSheet(idx) {
  const r = reelsData[idx];
  if (!r) return;
  sheetItemId   = r.id;
  sheetItemType = r.type;

  document.getElementById('sheetBackdrop').classList.add('open');
  document.getElementById('reelSheet').classList.add('open');

  // Fetch comments
  const list = document.getElementById('commentsList');
  list.innerHTML = '<div class="reel-empty-comments"><i class="fa-solid fa-spinner fa-spin"></i></div>';
  try {
    const res  = await fetch(`/api/comments?id=${r.id}&type=${r.type}`);
    const data = await res.json();
    renderComments(data.comments || []);
  } catch { list.innerHTML = '<div class="reel-empty-comments">Could not load comments</div>'; }
}

function renderComments(comments) {
  const list = document.getElementById('commentsList');
  if (!comments.length) {
    list.innerHTML = '<div class="reel-empty-comments">No comments yet — be the first!</div>';
    return;
  }
  list.innerHTML = comments.map(c => `
    <div class="reel-comment-item">
      <div class="reel-comment-avatar">${(c.username || '?')[0].toUpperCase()}</div>
      <div class="reel-comment-body">
        <div class="reel-comment-user">${esc(c.username || 'User')}</div>
        <div class="reel-comment-text">${esc(c.body || '')}</div>
      </div>
    </div>`).join('');
}

function closeSheet() {
  document.getElementById('sheetBackdrop').classList.remove('open');
  document.getElementById('reelSheet').classList.remove('open');
}

// Send comment
const sendBtn = document.getElementById('sendCommentBtn');
const commentInput = document.getElementById('commentInput');
if (sendBtn && commentInput) {
  sendBtn.addEventListener('click', async () => {
    const text = commentInput.value.trim();
    if (!text || !sheetItemId) return;
    const fd = new FormData();
    fd.append('id',     sheetItemId);
    fd.append('type',   sheetItemType);
    fd.append('body',   text);
    fd.append('rating', '0');
    try {
      const res  = await fetch('/api/comments', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        commentInput.value = '';
        const list = document.getElementById('commentsList');
        const placeholder = list.querySelector('.reel-empty-comments');
        if (placeholder) placeholder.remove();
        const item = document.createElement('div');
        item.className = 'reel-comment-item';
        item.innerHTML = `
          <div class="reel-comment-avatar">${(data.comment?.username || '?')[0].toUpperCase()}</div>
          <div class="reel-comment-body">
            <div class="reel-comment-user">${esc(data.comment?.username || 'You')}</div>
            <div class="reel-comment-text">${esc(text)}</div>
          </div>`;
        list.appendChild(item);
        list.scrollTop = list.scrollHeight;
      }
    } catch {}
  });
  commentInput.addEventListener('keydown', e => { if (e.key === 'Enter') sendBtn.click(); });
}

// ── Init ───────────────────────────────────────────────────────────────────
addLoader();
loadReels().then(() => {
  feed.querySelectorAll('.reel-slide').forEach(s => observer.observe(s));
});

// Observe new slides as they're added
const mutObs = new MutationObserver(mutations => {
  mutations.forEach(m => {
    m.addedNodes.forEach(n => {
      if (n.classList?.contains('reel-slide')) observer.observe(n);
    });
  });
});
mutObs.observe(feed, { childList: true });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
