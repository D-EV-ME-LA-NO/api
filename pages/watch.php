<?php
$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  && $_GET['season']  !== '' ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) && $_GET['episode'] !== '' ? (int)$_GET['episode'] : 1;

if (!$id) { header('Location: /'); exit; }

$d = tmdb_details($type, $id);
if (!$d || empty($d['id'])) { http_response_code(404); echo 'Not found'; exit; }

$title      = $d['title'] ?? $d['name'] ?? '';
$slug       = $id . '-' . slugify($title);
$tagline    = $d['tagline'] ?? '';
$overview   = $d['overview'] ?? '';
$rating     = round((float)($d['vote_average'] ?? 0), 1);
$year       = substr($d['release_date'] ?? $d['first_air_date'] ?? '', 0, 4);
$genres     = array_column($d['genres'] ?? [], 'name');
$runtime    = fmt_runtime($d['runtime'] ?? ($d['episode_run_time'][0] ?? null));
$ep_title   = '';
$episodes   = [];
$num_seasons = (int)($d['number_of_seasons'] ?? 0);

if ($type === 'tv') {
    $season_data = tmdb_season($id, $season);
    $episodes    = $season_data['episodes'] ?? [];
    foreach ($episodes as $e) {
        if ((int)$e['episode_number'] === $episode) {
            $ep_title = $e['name'] ?? '';
            break;
        }
    }
}

$backdrop_url = $d['backdrop_path'] ? img_url($d['backdrop_path'], 'original') : '';
$poster_url   = $d['poster_path']   ? img_url($d['poster_path'],   'w500')     : '';
$page_title   = 'Watch · ' . $title;
db_increment_view($id, $type);
$active = $type === 'movie' ? 'movies' : 'tv';

$genre_ids      = array_column($d['genres'] ?? [], 'id');
$origin_country = $d['origin_country'] ?? [];
$is_anime       = $type === 'tv' && in_array(16, $genre_ids) && in_array('JP', $origin_country);

// Stream access token — مرتبط بـ (type, id) وصالح 6 ساعات
$stream_token = sec_stream_token($type, (int)$id);

$api_qs = 'type=' . $type . '&id=' . $id
    . ($type === 'tv' ? '&season=' . $season . '&episode=' . $episode : '')
    . '&title=' . urlencode($title)
    . '&_st=' . urlencode($stream_token);

$imdb_id = $d['external_ids']['imdb_id'] ?? '';

$cast = array_slice($d['credits']['cast'] ?? [], 0, 8);

$similar  = array_slice($d['similar']['results']         ?? [], 0, 12);
$recs     = array_slice($d['recommendations']['results'] ?? [], 0, 12);
$seen_ids = [];
$more_items = [];
foreach (array_merge($recs, $similar) as $it) {
    if (!isset($it['id']) || in_array($it['id'], $seen_ids)) continue;
    $seen_ids[]   = $it['id'];
    $more_items[] = $it;
    if (count($more_items) >= 12) break;
}

$back_url = $type === 'movie' ? '/movie/' . $slug : '/tv-show/' . $slug;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"/>
<title><?= htmlspecialchars($page_title) ?> · <?= SITE_NAME ?></title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='80' font-size='90' font-family='Arial' font-weight='900' fill='%23e50914'>HZ</text></svg>"/>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
<style>
/* ── Reset & Base ────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{background:#08080f;color:#fff;font-family:'Inter',-apple-system,sans-serif;-webkit-font-smoothing:antialiased;overflow-x:hidden;min-height:100vh}
img{max-width:100%;display:block}
a{color:inherit;text-decoration:none}
button{font-family:inherit;cursor:pointer;border:none;background:none}

/* ── Variables ───────────────────────────────────────────────────────── */
:root{
  --red:#e50914;
  --red-dim:rgba(229,9,20,.18);
  --red-glow:rgba(229,9,20,.35);
  --panel:#0e0e1b;
  --panel-2:#13131f;
  --line:rgba(255,255,255,.07);
  --muted:rgba(255,255,255,.42);
  --muted-2:rgba(255,255,255,.65);
  --radius:10px;
}

/* ── Top Bar ─────────────────────────────────────────────────────────── */
.w-topbar{
  position:fixed;top:0;left:0;right:0;z-index:200;
  height:54px;
  background:rgba(8,8,15,.94);
  backdrop-filter:blur(18px);
  border-bottom:1px solid var(--line);
  display:flex;align-items:center;gap:0;
}
.w-topbar-inner{
  display:flex;align-items:center;gap:12px;
  padding:0 20px;width:100%;
}
.w-back-btn{
  display:inline-flex;align-items:center;gap:7px;
  color:var(--muted-2);font-size:.85rem;font-weight:500;
  padding:6px 12px;border-radius:7px;
  background:rgba(255,255,255,.05);
  border:1px solid var(--line);
  transition:background .15s,color .15s;
  flex-shrink:0;
}
.w-back-btn:hover{background:rgba(255,255,255,.1);color:#fff}
.w-topbar-brand{
  font-weight:900;font-size:1.2rem;letter-spacing:-.02em;
  color:var(--red);text-shadow:0 0 16px var(--red-glow);
  flex-shrink:0;
}
.w-topbar-brand span{color:#fff}
.w-topbar-sep{color:var(--line);font-size:1.1rem;flex-shrink:0}
.w-topbar-crumb{
  font-size:.82rem;font-weight:500;color:var(--muted);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  flex:1;min-width:0;
}
.w-topbar-crumb strong{color:var(--muted-2)}
.w-topbar-actions{display:flex;align-items:center;gap:8px;flex-shrink:0}
.w-topbar-action{
  width:34px;height:34px;border-radius:8px;
  display:inline-flex;align-items:center;justify-content:center;
  color:var(--muted-2);font-size:.9rem;
  background:rgba(255,255,255,.04);
  border:1px solid var(--line);
  transition:background .15s,color .15s;
}
.w-topbar-action:hover{background:rgba(255,255,255,.1);color:#fff}

/* ── Main Layout ─────────────────────────────────────────────────────── */
.w-page{padding-top:54px;min-height:100vh;display:flex;flex-direction:column}

/* ── Player Stage ────────────────────────────────────────────────────── */
.w-stage{
  width:100%;
  background:#000;
  position:relative;
}
.w-player-wrap{
  width:100%;max-width:1400px;margin:0 auto;
  padding:18px 20px 0;
}
#playerWrap{
  position:relative;
  width:100%;aspect-ratio:16/9;
  background:#000;
  border-radius:12px;overflow:hidden;
  box-shadow:0 0 0 1px rgba(255,255,255,.06),
             0 24px 80px rgba(0,0,0,.85),
             0 0 0 1px var(--red-dim) inset;
}
#art{width:100%;height:100%}

/* ── Skeleton ────────────────────────────────────────────────────────── */
.player-skeleton{
  position:absolute;inset:0;z-index:5;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;
  background:linear-gradient(160deg,#0a0a18 0%,#0e0b14 100%);
  transition:opacity .35s ease;
}
.player-skeleton.hidden{opacity:0;pointer-events:none}
.sk-ring{
  position:relative;width:64px;height:64px;
}
.sk-ring-track{
  position:absolute;inset:0;border-radius:50%;
  border:2px solid rgba(255,255,255,.06);
}
.sk-ring-spin{
  position:absolute;inset:-2px;border-radius:50%;
  border:3px solid transparent;
  border-top-color:var(--red);
  border-right-color:rgba(229,9,20,.3);
  animation:skSpin .75s linear infinite;
}
@keyframes skSpin{to{transform:rotate(360deg)}}
.sk-icon{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  font-size:1.4rem;color:var(--red);
  filter:drop-shadow(0 0 10px var(--red-glow));
}
.sk-label{font-size:.88rem;font-weight:600;color:rgba(255,255,255,.55);letter-spacing:.02em;text-align:center}
.sk-sub-label{font-size:.73rem;color:rgba(255,255,255,.22);margin-top:-10px;text-align:center}
.sk-dots{display:flex;gap:5px;flex-wrap:wrap;justify-content:center;max-width:280px}
.sk-dot{
  width:7px;height:7px;border-radius:50%;
  background:rgba(255,255,255,.1);flex-shrink:0;
  transition:background .25s,box-shadow .25s;
}
.sk-dot.probing{background:#fbbf24;animation:dotPulse .65s ease-in-out infinite}
.sk-dot.ok{background:#4ade80;box-shadow:0 0 4px rgba(74,222,128,.5)}
.sk-dot.fail{background:#f87171}
@keyframes dotPulse{0%,100%{opacity:1}50%{opacity:.2}}

/* ── Idle / error overlay ────────────────────────────────────────────── */
#idleOverlay{
  position:absolute;inset:0;z-index:3;
  display:flex;align-items:center;justify-content:center;
  transition:opacity .3s;
}
#idleOverlay.hidden{opacity:0;pointer-events:none}
.idle-bg{
  position:absolute;inset:0;
  background-size:cover;background-position:center;
  filter:brightness(.32) blur(3px);
  transform:scale(1.04);
}
.idle-fg{
  position:relative;z-index:1;
  display:flex;flex-direction:column;align-items:center;gap:12px;
  padding:24px;text-align:center;pointer-events:all;
}
.idle-play-btn{
  font-size:5.2rem;color:rgba(255,255,255,.88);line-height:1;
  filter:drop-shadow(0 0 28px rgba(229,9,20,.55));
  transition:transform .2s,color .2s;
}
.idle-play-btn:hover{transform:scale(1.09);color:#fff}
.idle-play-btn.idle-scanning i{animation:idlePulse 1.6s ease-in-out infinite}
@keyframes idlePulse{0%,100%{opacity:.9}50%{opacity:.35}}
.idle-msg{font-size:.88rem;font-weight:600;color:rgba(255,255,255,.55);letter-spacing:.02em}
.idle-submsg{font-size:.76rem;color:rgba(255,255,255,.28);margin-top:-4px}
.idle-retry-btn{
  margin-top:4px;padding:7px 18px;border-radius:8px;
  background:rgba(229,9,20,.85);color:#fff;font-size:.8rem;font-weight:600;
  border:none;cursor:pointer;transition:background .15s;
}
.idle-retry-btn:hover{background:var(--red)}

/* ── Pre-buffer overlay ──────────────────────────────────────────────── */
#preBuffer{
  position:absolute;inset:0;z-index:6;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;
  background:rgba(6,6,14,.75);backdrop-filter:blur(4px);
  opacity:0;pointer-events:none;transition:opacity .2s;
}
#preBuffer.visible{opacity:1;pointer-events:all}
.pb-track{
  width:160px;height:3px;border-radius:2px;
  background:rgba(255,255,255,.08);overflow:hidden;
}
#pbFill{height:100%;background:linear-gradient(90deg,var(--red),#ff6b35);border-radius:2px;transition:width .3s ease}
#pbLabel{font-size:.75rem;color:rgba(255,255,255,.4);letter-spacing:.04em}

/* ── Tap to play ─────────────────────────────────────────────────────── */
#tapPlay{
  position:absolute;inset:0;z-index:10;
  display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,.45);cursor:pointer;backdrop-filter:blur(2px);
}
.tap-inner{display:flex;flex-direction:column;align-items:center;gap:12px;color:#fff;pointer-events:none}
.tap-inner i{font-size:72px;color:var(--red);filter:drop-shadow(0 0 28px var(--red-glow));animation:tapPulse 2s ease-in-out infinite}
@keyframes tapPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.06)}}
.tap-inner span{font-size:.95rem;font-weight:600;opacity:.75}

/* ── Stall Banner ────────────────────────────────────────────────────── */
#stallBanner{
  position:absolute;bottom:60px;left:50%;transform:translateX(-50%) translateY(10px);
  z-index:12;
  display:flex;align-items:center;gap:10px;
  background:rgba(14,14,27,.92);backdrop-filter:blur(12px);
  border:1px solid rgba(229,9,20,.25);border-radius:10px;
  padding:10px 16px;
  opacity:0;pointer-events:none;transition:opacity .25s,transform .25s;
  white-space:nowrap;font-size:.82rem;
}
#stallBanner.visible{opacity:1;pointer-events:all;transform:translateX(-50%) translateY(0)}
#stallMsg{color:rgba(255,255,255,.7)}
#stallSwitch{
  padding:5px 12px;border-radius:6px;font-size:.78rem;font-weight:600;
  background:var(--red);color:#fff;
  transition:background .15s;
}
#stallSwitch:hover{background:#c00812}

/* ── Skip Intro ──────────────────────────────────────────────────────── */
.skip-intro-btn{
  position:absolute;bottom:70px;right:16px;z-index:12;
  display:inline-flex;align-items:center;gap:7px;
  padding:9px 18px;border-radius:8px;font-size:.82rem;font-weight:700;
  background:rgba(14,14,27,.88);backdrop-filter:blur(10px);
  border:1px solid rgba(255,255,255,.12);color:#fff;
  opacity:0;pointer-events:none;transition:opacity .25s;
}
.skip-intro-btn.si-visible{opacity:1;pointer-events:all}

/* ── Below-player info bar ───────────────────────────────────────────── */
.w-info-bar{
  width:100%;max-width:1400px;margin:0 auto;
  padding:14px 20px;
  display:flex;align-items:flex-start;gap:14px;
  flex-wrap:wrap;
}
.w-title-block{flex:1;min-width:0}
.w-title{font-size:1.35rem;font-weight:800;letter-spacing:-.02em;line-height:1.2;margin-bottom:5px}
.w-meta{display:flex;align-items:center;flex-wrap:wrap;gap:6px 12px;font-size:.8rem;color:var(--muted)}
.w-meta-pill{
  display:inline-flex;align-items:center;gap:4px;
  padding:2px 8px;border-radius:5px;
  background:rgba(255,255,255,.06);border:1px solid var(--line);
  color:var(--muted-2);font-size:.76rem;font-weight:500;
}
.w-meta-rating{color:#fbbf24;font-weight:700}
.w-server-chip{
  display:inline-flex;align-items:center;gap:6px;
  padding:4px 11px;border-radius:7px;
  background:var(--red-dim);border:1px solid rgba(229,9,20,.2);
  font-size:.75rem;font-weight:600;color:rgba(255,255,255,.7);
  white-space:nowrap;
}
.w-server-chip-dot{width:6px;height:6px;border-radius:50%;background:var(--red);animation:servPulse 2s ease-in-out infinite}
@keyframes servPulse{0%,100%{opacity:1}50%{opacity:.35}}

/* ── Divider ─────────────────────────────────────────────────────────── */
.w-divider{
  width:100%;max-width:1400px;margin:0 auto;
  height:1px;background:var(--line);
}

/* ── Episode Section ─────────────────────────────────────────────────── */
.w-eps-section{
  width:100%;max-width:1400px;margin:0 auto;
  padding:20px 20px 0;
}
.w-eps-header{
  display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;
}
.w-eps-header h2{font-size:1rem;font-weight:700;color:rgba(255,255,255,.85)}
.w-season-sel{
  appearance:none;-webkit-appearance:none;
  background:var(--panel-2);border:1px solid rgba(255,255,255,.1);
  color:#fff;border-radius:7px;padding:5px 30px 5px 12px;
  font-size:.8rem;font-weight:600;font-family:inherit;cursor:pointer;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23888'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 6px center;background-size:18px;
}
.w-eps-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
  gap:8px;
}
.ep-card{
  display:flex;align-items:center;gap:10px;
  padding:8px 10px;border-radius:9px;
  background:var(--panel);border:1px solid var(--line);
  cursor:pointer;transition:background .15s,border-color .15s;
  text-decoration:none;color:inherit;
}
.ep-card:hover{background:var(--panel-2);border-color:rgba(255,255,255,.14)}
.ep-card.is-current{background:var(--red-dim);border-color:rgba(229,9,20,.3)}
.ep-card.is-current .ep-num{color:var(--red)}
.ep-thumb{
  width:90px;height:52px;border-radius:6px;overflow:hidden;
  background:#111;flex-shrink:0;position:relative;
}
.ep-thumb img{width:100%;height:100%;object-fit:cover}
.ep-play-icon{
  position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,.4);opacity:0;transition:opacity .15s;
}
.ep-card:hover .ep-play-icon{opacity:1}
.ep-card.is-current .ep-play-icon{opacity:1;background:rgba(229,9,20,.2)}
.ep-play-icon i{font-size:1.1rem;color:#fff}
.ep-info{flex:1;min-width:0}
.ep-num{font-size:.7rem;font-weight:700;color:var(--muted);letter-spacing:.04em;text-transform:uppercase}
.ep-name{font-size:.82rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}

/* ── Cast ────────────────────────────────────────────────────────────── */
.w-cast-section{
  width:100%;max-width:1400px;margin:0 auto;
  padding:20px 20px 0;
}
.w-section-head{
  font-size:.9rem;font-weight:700;color:var(--muted);
  letter-spacing:.05em;text-transform:uppercase;margin-bottom:12px;
}
.w-cast-row{
  display:flex;gap:10px;overflow-x:auto;padding-bottom:6px;
  scrollbar-width:none;
}
.w-cast-row::-webkit-scrollbar{display:none}
.cast-pill{
  display:flex;align-items:center;gap:8px;
  background:var(--panel);border:1px solid var(--line);
  border-radius:8px;padding:7px 12px 7px 7px;
  flex-shrink:0;
}
.cast-avatar{
  width:32px;height:32px;border-radius:50%;overflow:hidden;background:#1a1a2e;flex-shrink:0;
}
.cast-avatar img{width:100%;height:100%;object-fit:cover}
.cast-name{font-size:.75rem;font-weight:600;white-space:nowrap}
.cast-char{font-size:.68rem;color:var(--muted);white-space:nowrap}

/* ── More Like This ──────────────────────────────────────────────────── */
.w-more-section{
  width:100%;max-width:1400px;margin:0 auto;
  padding:20px 20px 32px;
}
.w-more-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(130px,1fr));
  gap:12px;
}
.more-card{display:block;border-radius:9px;overflow:hidden;position:relative;background:#111;aspect-ratio:2/3}
.more-card img{width:100%;height:100%;object-fit:cover;transition:transform .3s}
.more-card:hover img{transform:scale(1.04)}
.more-card-overlay{
  position:absolute;inset:0;
  background:linear-gradient(to top,rgba(0,0,0,.85) 0%,transparent 55%);
  padding:8px;display:flex;flex-direction:column;justify-content:flex-end;
}
.more-card-title{font-size:.72rem;font-weight:700;line-height:1.3}
.more-card-year{font-size:.66rem;color:var(--muted);margin-top:2px}

/* ── Settings Panel ──────────────────────────────────────────────────── */
#spOverlay{
  position:fixed;inset:0;z-index:500;
  background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
  display:flex;align-items:flex-end;justify-content:center;
  opacity:0;pointer-events:none;transition:opacity .22s;
}
#spOverlay.sp-open{opacity:1;pointer-events:all}
.sp-sheet{
  width:100%;max-width:480px;
  background:#0f0f1e;border-radius:18px 18px 0 0;
  border:1px solid rgba(255,255,255,.08);border-bottom:none;
  overflow:hidden;
  transform:translateY(100%);transition:transform .28s cubic-bezier(.4,0,.2,1);
  max-height:82vh;
}
#spOverlay.sp-open .sp-sheet{transform:translateY(0)}
.sp-drag{
  width:36px;height:4px;border-radius:2px;
  background:rgba(255,255,255,.15);
  margin:12px auto 8px;
}
.sp-pages{
  display:flex;width:500%;overflow:hidden;
  transition:transform .22s cubic-bezier(.4,0,.2,1);
}
.sp-page{
  flex-shrink:0;overflow:hidden;display:flex;flex-direction:column;
}
.sp-page-hd{
  display:flex;align-items:center;gap:8px;
  padding:14px 18px 10px;border-bottom:1px solid rgba(255,255,255,.06);
}
.sp-back{
  color:rgba(255,255,255,.5);font-size:1.2rem;padding:2px 6px;
  border-radius:6px;transition:background .15s;
}
.sp-back:hover{background:rgba(255,255,255,.08);color:#fff}
.sp-page-title{font-size:.95rem;font-weight:700;flex:1}
.sp-page-extra{font-size:.75rem;color:rgba(255,255,255,.35)}
.sp-scroll{overflow-y:auto;flex:1;max-height:55vh;scrollbar-width:none}
.sp-scroll::-webkit-scrollbar{display:none}
.sp-list{padding:6px 0}
.sp-divider{height:1px;background:rgba(255,255,255,.06);margin:4px 0}
.sp-section-label{
  padding:10px 18px 4px;
  font-size:.68rem;font-weight:700;color:rgba(255,255,255,.3);
  letter-spacing:.06em;text-transform:uppercase;
}
.sp-row{
  display:flex;align-items:center;justify-content:space-between;
  padding:12px 18px;cursor:pointer;gap:10px;
  transition:background .15s;
}
.sp-row:hover{background:rgba(255,255,255,.05)}
.sp-row.sp-selected{background:rgba(229,9,20,.08)}
.sp-row.sp-row-fail{opacity:.5;cursor:default}
.sp-row-left{font-size:.88rem;font-weight:500;flex:1;min-width:0;overflow:hidden}
.sp-row-right{display:flex;align-items:center;gap:6px;flex-shrink:0}
.sp-check-icon{color:var(--red);font-size:.9rem;font-weight:700}
.sp-arrow{color:rgba(255,255,255,.3);font-size:1rem}
.sp-card-row{
  display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:10px 14px;
}
.sp-card{
  display:flex;flex-direction:column;align-items:center;gap:4px;
  padding:12px 8px;border-radius:10px;background:rgba(255,255,255,.05);
  border:1px solid rgba(255,255,255,.07);cursor:pointer;transition:background .15s;
  text-align:center;
}
.sp-card:hover{background:rgba(255,255,255,.09)}
.sp-card-icon{font-size:1.2rem;color:rgba(255,255,255,.6)}
.sp-card-lbl{font-size:.72rem;font-weight:600;color:rgba(255,255,255,.6)}
.sp-card-val{font-size:.8rem;font-weight:700;color:#fff}
.sp-switch{position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0}
.sp-switch input{opacity:0;width:0;height:0}
.sp-slider{
  position:absolute;inset:0;border-radius:24px;
  background:rgba(255,255,255,.15);cursor:pointer;transition:background .2s;
}
.sp-slider::before{
  content:'';position:absolute;left:3px;top:3px;
  width:18px;height:18px;border-radius:50%;background:#fff;
  transition:transform .2s;
}
input:checked+.sp-slider{background:var(--red)}
input:checked+.sp-slider::before{transform:translateX(18px)}
.sp-slider-row{
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 18px;gap:14px;
}
.sp-slider-row span{font-size:.85rem;font-weight:500;flex-shrink:0;min-width:80px}
.sp-range{
  flex:1;accent-color:var(--red);height:3px;cursor:pointer;
}
.sp-sub-note{font-size:.7rem;color:rgba(255,255,255,.3);line-height:1.4;margin-top:2px}
.sp-radio-row{gap:12px}
.sp-radio-row input[type=radio]{accent-color:var(--red)}
.sp-close-btn{
  width:calc(100% - 28px);margin:8px 14px;padding:11px;border-radius:9px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);
  color:rgba(255,255,255,.6);font-size:.85rem;font-weight:600;
  transition:background .15s;
}
.sp-close-btn:hover{background:rgba(255,255,255,.1);color:#fff}

/* Server list styles */
.xp-item-dot{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.18);flex-shrink:0;transition:background .2s}
.xp-item-dot.xp-active{background:#4ade80;box-shadow:0 0 4px #4ade80}
.xp-item-dot.xp-probing{background:#fbbf24;animation:dotPulse .65s ease-in-out infinite}
.xp-item-dot.xp-fail{background:#f87171}
.srv-latency{font-size:.65rem;font-weight:700;padding:2px 6px;border-radius:4px;flex-shrink:0;white-space:nowrap}
.srv-latency-fast{background:rgba(74,222,128,.12);color:#4ade80}
.srv-latency-med{background:rgba(251,191,36,.1);color:#fbbf24}
.srv-latency-slow{background:rgba(248,113,113,.1);color:#f87171}
.srv-expand-btn{
  background:none;border:none;cursor:pointer;color:rgba(255,255,255,.4);
  font-size:1.1rem;padding:0 5px;line-height:1;transition:transform .18s,color .15s;flex-shrink:0;
}
.srv-expand-btn:hover{color:#fff}
.srv-expand-btn.srv-expand-open{transform:rotate(90deg);color:rgba(255,255,255,.7)}
.srv-branch-row{padding-left:8px !important;background:rgba(255,255,255,.02);font-size:.82rem}
.srv-branch-row:hover{background:rgba(255,255,255,.05) !important}
.srv-branch-badge{
  display:inline-flex;align-items:center;justify-content:center;
  min-width:20px;height:20px;padding:0 5px;border-radius:6px;
  background:rgba(255,255,255,.08);color:rgba(255,255,255,.5);
  font-size:.72rem;font-weight:700;flex-shrink:0;
}
.srv-cur-branch-lbl{font-size:.68rem;color:rgba(255,255,255,.3);margin-left:5px;white-space:nowrap;flex-shrink:0}
.srv-probe-track{height:2px;background:rgba(255,255,255,.05);overflow:hidden;flex-shrink:0}
.srv-probe-fill{height:100%;background:linear-gradient(90deg,var(--red),#ff6b35);transition:width .35s ease;border-radius:1px}

/* Subtitle lang rows */
.sp-lang-row{display:flex;align-items:center;gap:7px;flex:1;min-width:0;overflow:hidden}
.sp-lang-flag{font-size:1.1rem;flex-shrink:0}
.sp-lang-name{font-size:.85rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* Subtitle sync delay */
.sp-sync-row{display:flex;align-items:center;justify-content:center;gap:8px;padding:6px 14px 10px}
.sp-sync-btn{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);color:#fff;border-radius:7px;padding:6px 12px;font-size:.78rem;font-weight:700;cursor:pointer;transition:background .15s;flex-shrink:0}
.sp-sync-btn:hover{background:rgba(255,255,255,.16)}
.sp-sync-val{font-size:.9rem;font-weight:700;color:#fff;min-width:48px;text-align:center;letter-spacing:.02em}

/* ArtPlayer overrides */
.artplayer-app{width:100%!important;height:100%!important}

/* ── Double-tap seek ripple ──────────────────────────────────────────── */
.dt-ripple{position:absolute;top:0;bottom:0;width:40%;z-index:20;display:flex;align-items:center;justify-content:center;pointer-events:none;opacity:0;transition:opacity .15s}
.dt-ripple-left{left:0;background:radial-gradient(circle at 30% 50%,rgba(255,255,255,.18) 0%,transparent 70%)}
.dt-ripple-right{right:0;background:radial-gradient(circle at 70% 50%,rgba(255,255,255,.18) 0%,transparent 70%)}
.dt-ripple-show{opacity:1}
.dt-ripple-inner{display:flex;flex-direction:column;align-items:center;gap:4px;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.6)}
.dt-ripple-inner i{font-size:2rem}
.dt-ripple-inner span{font-size:.9rem;font-weight:700}

/* ── Watch page footer ───────────────────────────────────────────────── */
.w-footer{background:#0d0d0f;border-top:1px solid rgba(255,255,255,.06);padding:32px 20px 100px;text-align:center}
.w-footer-socials{display:flex;justify-content:center;gap:14px;margin-bottom:18px}
.w-footer-soc{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;font-size:1.1rem;color:#fff;text-decoration:none;transition:transform .2s,opacity .2s}
.w-footer-soc:hover{transform:translateY(-3px);opacity:.85}
.w-footer-soc.instagram{background:linear-gradient(135deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888)}
.w-footer-soc.tiktok{background:#010101}
.w-footer-soc.facebook{background:#1877f2}
.w-footer-soc.twitter{background:#000}
.w-footer-soc.snapchat{background:#fffc00;color:#000}
.w-footer-soc.telegram{background:#2ca5e0}
.w-footer-copy{font-size:.78rem;color:rgba(255,255,255,.3);line-height:1.6}
.w-footer-copy a{color:rgba(255,255,255,.45);text-decoration:none}
.w-footer-copy a:hover{color:#fff}

/* ── Responsive ──────────────────────────────────────────────────────── */
@media(max-width:640px){
  .w-topbar-inner{padding:0 12px;gap:8px}
  .w-topbar-brand{display:none}
  .w-topbar-sep{display:none}
  .w-player-wrap{padding:10px 10px 0}
  #playerWrap{border-radius:8px}
  .w-info-bar{padding:10px 12px;gap:10px}
  .w-title{font-size:1.1rem}
  .w-eps-section,.w-cast-section,.w-more-section{padding-left:12px;padding-right:12px}
  .w-eps-grid{grid-template-columns:1fr}
  .w-more-grid{grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px}
}
/* ── Bottom nav ── */
.bottom-nav{display:none;position:fixed;bottom:0;left:0;right:0;z-index:90;background:rgba(10,10,10,.96);backdrop-filter:blur(14px);border-top:1px solid rgba(255,255,255,.07);padding:8px 4px;justify-content:space-around}
.bn-item{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 4px;color:rgba(255,255,255,.45);font-size:.68rem;font-weight:500;border-radius:8px;text-decoration:none}
.bn-item i{font-size:1.1rem}
.bn-item.is-active{color:#e50914}
.bn-reels{position:relative}
.bn-reels i{width:44px;height:44px;border-radius:14px;background:#e50914;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.15rem;margin-top:-10px;box-shadow:0 4px 16px rgba(229,9,20,.45)}
.bn-reels span{color:rgba(255,255,255,.7);margin-top:2px}
.bn-reels.is-active i{background:#c00}
.bn-reels.is-active span{color:#fff}
@media(max-width:768px){.bottom-nav{display:flex}.w-footer{padding-bottom:90px}}
</style>
</head>
<body>

<!-- ═══ TOP BAR ═════════════════════════════════════════════════════════════ -->
<div class="w-topbar">
  <div class="w-topbar-inner">
    <a href="<?= htmlspecialchars($back_url) ?>" class="w-back-btn">
      <i class="fa-solid fa-arrow-left"></i> Back
    </a>
    <span class="w-topbar-brand">HZ<span>Flix</span></span>
    <span class="w-topbar-sep">/</span>
    <div class="w-topbar-crumb">
      <strong><?= htmlspecialchars($title) ?></strong>
      <?php if ($type === 'tv'): ?>
        &nbsp;· S<?= $season ?> E<?= $episode ?><?= $ep_title ? ' · ' . htmlspecialchars($ep_title) : '' ?>
      <?php endif; ?>
    </div>
    <div class="w-topbar-actions">
      <a href="/search" class="w-topbar-action" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></a>
      <a href="/" class="w-topbar-action" aria-label="Home"><i class="fa-solid fa-house"></i></a>
    </div>
  </div>
</div>

<!-- ═══ PAGE ═════════════════════════════════════════════════════════════════ -->
<div class="w-page">

  <!-- Player area -->
  <div class="w-stage">
    <div class="w-player-wrap">
      <div id="playerWrap">
        <div id="art"></div>

        <!-- Idle / error overlay — always present, hidden when player is active -->
        <div id="idleOverlay">
          <div class="idle-bg" id="idleBg"></div>
          <div class="idle-fg">
            <button id="idlePlayBtn" class="idle-play-btn" aria-label="Play">
              <i class="fa-solid fa-circle-play"></i>
            </button>
            <div id="idleMsg" class="idle-msg">جاري فحص السيرفرات…</div>
            <div id="idleSubmsg" class="idle-submsg"></div>
          </div>
        </div>

        <!-- Loading skeleton -->
        <div class="player-skeleton" id="playerSkeleton">
          <div class="sk-ring">
            <div class="sk-ring-track"></div>
            <div class="sk-ring-spin"></div>
            <div class="sk-icon"><i class="fa-solid fa-play"></i></div>
          </div>
          <span class="sk-label" id="skText">Scanning servers…</span>
          <span class="sk-sub-label" id="skSub"></span>
          <div class="sk-dots" id="skServerDots"></div>
        </div>

        <!-- Pre-buffer overlay -->
        <div id="preBuffer">
          <div class="pb-track"><div id="pbFill" style="width:0%"></div></div>
          <span id="pbLabel">Buffering…</span>
        </div>

        <!-- Stall banner -->
        <div id="stallBanner">
          <span id="stallMsg">Connection slow — switching server…</span>
          <button id="stallSwitch">Switch Now</button>
        </div>

        <!-- Skip intro (TV) -->
        <?php if ($type === 'tv'): ?>
        <button class="skip-intro-btn" id="skipIntroBtn"><i class="fa-solid fa-forward"></i> Skip Intro</button>
        <?php endif; ?>

        <!-- Settings overlay -->
        <div id="spOverlay">
          <div class="sp-sheet">
            <div class="sp-drag"></div>
            <div id="spPages" class="sp-pages">

              <!-- Page 0: Main ─────────────────────────────────────────── -->
              <div class="sp-page" id="spMainPage">
                <div class="sp-card-row">
                  <div class="sp-card" id="spQualCard">
                    <span class="sp-card-icon"><i class="fa-solid fa-film"></i></span>
                    <span class="sp-card-lbl">Quality</span>
                    <span class="sp-card-val" id="spQualValCard">Auto</span>
                  </div>
                  <div class="sp-card" id="spSubCard">
                    <span class="sp-card-icon"><i class="fa-solid fa-closed-captioning"></i></span>
                    <span class="sp-card-lbl">Subtitles</span>
                    <span class="sp-card-val" id="spSubValCard">Off</span>
                  </div>
                  <div class="sp-card" id="spSrcCard">
                    <span class="sp-card-icon"><i class="fa-solid fa-server"></i></span>
                    <span class="sp-card-lbl">Source</span>
                    <span class="sp-card-val" id="spSrcValCard">—</span>
                  </div>
                  <div class="sp-card" id="spVideoCard">
                    <span class="sp-card-icon"><i class="fa-solid fa-sliders"></i></span>
                    <span class="sp-card-lbl">Video</span>
                    <span class="sp-card-val" id="spVideoValCard">Auto</span>
                  </div>
                </div>
                <div class="sp-divider"></div>
                <div class="sp-list">
                  <div class="sp-row" id="spSpeedHoldRow">
                    <span class="sp-row-left">Speed boost on hold</span>
                    <label class="sp-switch" onclick="event.stopPropagation()">
                      <input type="checkbox" id="spSpeedHold">
                      <span class="sp-slider"></span>
                    </label>
                  </div>
                </div>
                <button class="sp-close-btn" id="spCloseMain">Close</button>
              </div>

              <!-- Page 1: Quality ─────────────────────────────────────── -->
              <div class="sp-page" id="spQualPage">
                <div class="sp-page-hd">
                  <button class="sp-back" data-back="0">←</button>
                  <span class="sp-page-title">Quality</span>
                </div>
                <div class="sp-scroll sp-list" id="spQualList"></div>
                <div class="sp-list">
                  <div class="sp-row">
                    <span class="sp-row-left">Automatic quality</span>
                    <label class="sp-switch" onclick="event.stopPropagation()">
                      <input type="checkbox" id="spAutoQual" checked>
                      <span class="sp-slider"></span>
                    </label>
                  </div>
                </div>
                <button class="sp-close-btn" data-close>Close</button>
              </div>

              <!-- Page 2: Subtitles ───────────────────────────────────── -->
              <div class="sp-page" id="spSubsPage">
                <div class="sp-page-hd">
                  <button class="sp-back" data-back="0">←</button>
                  <span class="sp-page-title">Subtitles</span>
                </div>
                <div class="sp-scroll">
                  <div class="sp-list" id="spSubTopList">
                    <div class="sp-row sp-selected" id="spSubOffRow"  data-sub="-1"><span class="sp-row-left">Off</span><span class="sp-check-icon">✓</span></div>
                    <div class="sp-row"             id="spSubAutoRow" data-sub="0"><span class="sp-row-left">Auto select</span></div>
                    <div class="sp-row"             id="spSubUploadRow"><span class="sp-row-left">Drop or upload file</span></div>
                  </div>
                  <div class="sp-divider"></div>
                  <div class="sp-list" id="spSubLangList"></div>
                  <div class="sp-divider"></div>
                  <div class="sp-section-label" style="padding:6px 14px 2px">SYNC DELAY</div>
                  <div class="sp-sync-row">
                    <button class="sp-sync-btn" id="spSubDelayMinus">−0.5s</button>
                    <span class="sp-sync-val" id="spSubDelayVal">0.0s</span>
                    <button class="sp-sync-btn" id="spSubDelayReset">Reset</button>
                    <button class="sp-sync-btn" id="spSubDelayPlus">+0.5s</button>
                  </div>
                </div>
                <button class="sp-close-btn" data-close>Close</button>
              </div>

              <!-- Page 3: Source ─────────────────────────────────────── -->
              <div class="sp-page" id="spSourcePage">
                <div class="sp-page-hd">
                  <button class="sp-back" data-back="0">←</button>
                  <span class="sp-page-title">Source</span>
                  <span class="sp-page-extra" id="spSrcCount"></span>
                </div>
                <div class="srv-probe-track"><div class="srv-probe-fill" id="probeBar"></div></div>
                <div class="sp-scroll sp-list" id="spSrcList"></div>
                <button class="sp-close-btn" data-close>Close</button>
              </div>

              <!-- Page 4: Video ──────────────────────────────────────── -->
              <div class="sp-page" id="spVideoPage">
                <div class="sp-page-hd">
                  <button class="sp-back" data-back="0">←</button>
                  <span class="sp-page-title">Video</span>
                </div>
                <div class="sp-list">
                  <div class="sp-slider-row"><span>Brightness</span><input type="range" class="sp-range" id="spBrightness" min="0" max="200" value="100"></div>
                  <div class="sp-slider-row"><span>Contrast</span>  <input type="range" class="sp-range" id="spContrast"   min="0" max="200" value="100"></div>
                  <div class="sp-slider-row"><span>Saturation</span><input type="range" class="sp-range" id="spSaturation" min="0" max="200" value="100"></div>
                </div>
                <div class="sp-divider"></div>
                <div class="sp-list">
                  <div class="sp-row">
                    <div class="sp-row-left">
                      <div>ASCII Mode</div>
                      <div class="sp-sub-note">Inspired by CLI Player<br>May cause lag on some devices.</div>
                    </div>
                    <label class="sp-switch" onclick="event.stopPropagation()"><input type="checkbox" id="spAsciiMode"><span class="sp-slider"></span></label>
                  </div>
                  <div class="sp-row">
                    <span class="sp-row-left">ASCII Color</span>
                    <label class="sp-switch" onclick="event.stopPropagation()"><input type="checkbox" id="spAsciiColor" checked><span class="sp-slider"></span></label>
                  </div>
                </div>
                <div class="sp-divider"></div>
                <div class="sp-section-label">ASPECT RATIO</div>
                <div class="sp-list">
                  <label class="sp-row sp-radio-row"><input type="radio" name="spAspect" value="fit" checked><span class="sp-row-left">Fit</span></label>
                  <label class="sp-row sp-radio-row"><input type="radio" name="spAspect" value="fill"><span class="sp-row-left">Fill</span></label>
                </div>
                <button class="sp-close-btn" data-close>Close</button>
              </div>

            </div><!-- /.sp-pages -->
          </div><!-- /.sp-sheet -->
        </div><!-- /#spOverlay -->

      </div><!-- /#playerWrap -->
    </div><!-- /.w-player-wrap -->

    <!-- Info bar -->
    <div class="w-info-bar">
      <div class="w-title-block">
        <div class="w-title"><?= htmlspecialchars($title) ?><?= $type === 'tv' ? ' <span style="color:var(--muted);font-weight:500;font-size:.9em">S' . $season . ' E' . $episode . '</span>' : '' ?></div>
        <div class="w-meta">
          <?php if ($rating > 0): ?><span class="w-meta-pill w-meta-rating"><i class="fa-solid fa-star" style="font-size:.7rem"></i> <?= $rating ?></span><?php endif; ?>
          <?php if ($year): ?><span class="w-meta-pill"><?= $year ?></span><?php endif; ?>
          <?php if ($runtime): ?><span class="w-meta-pill"><?= htmlspecialchars($runtime) ?></span><?php endif; ?>
          <?php foreach (array_slice($genres, 0, 3) as $g): ?><span class="w-meta-pill"><?= htmlspecialchars($g) ?></span><?php endforeach; ?>
          <?php if ($ep_title): ?><span style="color:var(--muted);font-size:.8rem">· <?= htmlspecialchars($ep_title) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="w-server-chip" id="activeServerChip" style="display:none">
        <span class="w-server-chip-dot"></span>
        <span id="activeServerName">—</span>
      </div>
    </div>

  </div><!-- /.w-stage -->

  <div class="w-divider"></div>

  <!-- ═══ EPISODE SECTION (TV only) ══════════════════════════════════════════ -->
  <?php if ($type === 'tv' && $episodes): ?>
  <div class="w-eps-section">
    <div class="w-eps-header">
      <h2>Episodes</h2>
      <?php if ($num_seasons > 1): ?>
      <select class="w-season-sel" id="seasonSel">
        <?php for ($s = 1; $s <= $num_seasons; $s++): ?>
          <option value="<?= $s ?>" <?= $s === $season ? 'selected' : '' ?>>Season <?= $s ?></option>
        <?php endfor; ?>
      </select>
      <?php endif; ?>
      <span style="margin-left:auto;font-size:.78rem;color:var(--muted)"><?= count($episodes) ?> episodes</span>
    </div>
    <div class="w-eps-grid">
      <?php foreach ($episodes as $ep):
        $ep_num   = (int)$ep['episode_number'];
        $ep_name  = $ep['name'] ?? '';
        $ep_still = $ep['still_path'] ? img_url($ep['still_path'], 'w300') : '';
        $is_cur   = $ep_num === $episode;
        $ep_url   = '/watch/tv/' . $slug . '/' . $season . '/' . $ep_num;
      ?>
      <a href="<?= htmlspecialchars($ep_url) ?>" class="ep-card <?= $is_cur ? 'is-current' : '' ?>">
        <div class="ep-thumb">
          <?php if ($ep_still): ?><img src="<?= htmlspecialchars($ep_still) ?>" alt="" loading="lazy"/><?php endif; ?>
          <div class="ep-play-icon"><i class="fa-solid fa-play"></i></div>
        </div>
        <div class="ep-info">
          <div class="ep-num">Episode <?= $ep_num ?></div>
          <div class="ep-name"><?= htmlspecialchars($ep_name ?: 'Episode ' . $ep_num) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══ CAST ═════════════════════════════════════════════════════════════════ -->
  <?php if ($cast): ?>
  <div class="w-cast-section">
    <div class="w-section-head">Cast</div>
    <div class="w-cast-row">
      <?php foreach ($cast as $c): ?>
      <div class="cast-pill">
        <div class="cast-avatar">
          <?php if ($c['profile_path']): ?><img src="<?= img_url($c['profile_path'], 'w92') ?>" alt="" loading="lazy"/><?php endif; ?>
        </div>
        <div>
          <div class="cast-name"><?= htmlspecialchars($c['name'] ?? '') ?></div>
          <div class="cast-char"><?= htmlspecialchars(substr($c['character'] ?? '', 0, 22)) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══ MORE LIKE THIS ════════════════════════════════════════════════════════ -->
  <?php if ($more_items): ?>
  <div class="w-more-section">
    <div class="w-section-head">More Like This</div>
    <div class="w-more-grid">
      <?php foreach ($more_items as $it):
        $it_id    = $it['id'];
        $it_title = $it['title'] ?? $it['name'] ?? '';
        $it_year  = substr($it['release_date'] ?? $it['first_air_date'] ?? '', 0, 4);
        $it_type  = isset($it['title']) ? 'movie' : 'tv';
        $it_slug  = $it_id . '-' . slugify($it_title);
        $it_url   = $it_type === 'movie' ? '/movie/' . $it_slug : '/tv-show/' . $it_slug;
        $it_img   = $it['poster_path'] ? img_url($it['poster_path'], 'w300') : '';
      ?>
      <a href="<?= htmlspecialchars($it_url) ?>" class="more-card">
        <?php if ($it_img): ?><img src="<?= htmlspecialchars($it_img) ?>" alt="" loading="lazy"/><?php endif; ?>
        <div class="more-card-overlay">
          <div class="more-card-title"><?= htmlspecialchars($it_title) ?></div>
          <?php if ($it_year): ?><div class="more-card-year"><?= $it_year ?></div><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══ WATCH PAGE FOOTER ═══════════════════════════════════════════════════ -->
  <footer class="w-footer">
    <div class="w-footer-socials">
      <a href="https://t.me/" target="_blank" rel="noopener" class="w-footer-soc telegram" aria-label="Telegram"><i class="fa-brands fa-telegram"></i></a>
      <a href="https://www.instagram.com/" target="_blank" rel="noopener" class="w-footer-soc instagram" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
      <a href="https://www.tiktok.com/" target="_blank" rel="noopener" class="w-footer-soc tiktok" aria-label="TikTok"><i class="fa-brands fa-tiktok"></i></a>
      <a href="https://www.facebook.com/" target="_blank" rel="noopener" class="w-footer-soc facebook" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
      <a href="https://twitter.com/" target="_blank" rel="noopener" class="w-footer-soc twitter" aria-label="X / Twitter"><i class="fa-brands fa-x-twitter"></i></a>
      <a href="https://www.snapchat.com/" target="_blank" rel="noopener" class="w-footer-soc snapchat" aria-label="Snapchat"><i class="fa-brands fa-snapchat"></i></a>
    </div>
    <div class="w-footer-copy">
      © <?= date('Y') ?> <strong><?= SITE_NAME ?></strong>. جميع الحقوق محفوظة.<br>
      هذا الموقع لا يستضيف أي ملفات — يعتمد على مصادر خارجية.<br>
      بيانات الأفلام والمسلسلات مقدّمة من <a href="https://www.themoviedb.org" target="_blank" rel="noopener">TMDB</a>.
    </div>
  </footer>

</div><!-- /.w-page -->

<!-- ═══ PLAYER JS ════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5/dist/hls.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/artplayer/dist/artplayer.js"></script>
<script>
'use strict';
(function () { window.open = function () { return null; }; })();

// ══════════════════════════════════════════════════════════════════════════════
//  Constants
// ══════════════════════════════════════════════════════════════════════════════
const API_QS       = '<?= $api_qs ?>';
const POSTER_URL   = <?= json_encode($poster_url) ?>;
const TV_SLUG      = <?= json_encode($slug) ?>;
const CONTENT_TYPE = '<?= $type ?>';
const CONTENT_ID   = <?= (int)$id ?>;
const IS_ANIME     = <?= $is_anime ? 'true' : 'false' ?>;
const IMDB_ID      = <?= json_encode($imdb_id) ?>;
const SEASON       = <?= (int)$season ?>;
const EPISODE      = <?= (int)$episode ?>;

const SERVER_LIST = [
  { id: 'animecurx',   name: 'AnimeCurx',   type: 'multi',  resolver: '/api/animecurx/index.php?'   + API_QS },
  { id: 'dulo',        name: 'Dulo',        type: 'multi',  resolver: '/api/dulo/index.php?'         + API_QS },
  ...(IS_ANIME ? [
    { id: 'anikuro',   name: 'AniKuro',   type: 'multi',  resolver: '/api/anikuro/index.php?'   + API_QS },
    { id: 'anibd',     name: 'AniBD',     type: 'multi',  resolver: '/api/anibd/index.php?'     + API_QS },
    { id: 'aniwaves',  name: 'EchoVideo', type: 'multi',  resolver: '/api/aniwaves/index.php?'  + API_QS },
  ] : []),
  { id: 'lookmovie', name: 'LookMovie', type: 'direct', resolver: '/api/lookmovie/index.php?' + API_QS },
  { id: 'pluto',     name: 'Pluto',     type: 'direct', resolver: '/api/pluto/index.php?'     + API_QS },
  { id: 'torrentio', name: 'Torrentio', type: 'multi',  resolver: '/api/torrentio.php?'       + API_QS },
  { id: 'moviebox',  name: 'MovieBox',  type: 'multi',  resolver: '/api/aoneroom/index.php?'  + API_QS },
  { id: 'notorrent', name: 'NoTorrent', type: 'multi',  resolver: '/api/notorrent/index.php?' + API_QS },
  { id: 'aether',    name: 'Aether',    type: 'multi',  resolver: '/api/aether/index.php?'    + API_QS },
  { id: 'bingr',     name: 'Bingr',     type: 'multi',  resolver: '/api/bingr/index.php?'     + API_QS },
  { id: 'overlook',  name: 'Overlook',  type: 'multi',  resolver: '/api/overlook/index.php?'  + API_QS },
  { id: 'popcorn',      name: 'Popcorn',      type: 'expand', resolver: '/api/popcorn/index.php?'      + API_QS },
  { id: 'cinextream',   name: 'CineXtream',   type: 'direct', resolver: '/api/cinextream/index.php?'  + API_QS },
  { id: 'mooviefun',   name: 'MoovieFun',    type: 'expand', resolver: '/api/mooviefun/index.php?'   + API_QS },
];

// ══════════════════════════════════════════════════════════════════════════════
//  DOM refs
// ══════════════════════════════════════════════════════════════════════════════
const skeletonEl  = document.getElementById('playerSkeleton');
const skText      = document.getElementById('skText');
const skSub       = document.getElementById('skSub');
const skDots      = document.getElementById('skServerDots');
const probeBarEl  = document.getElementById('probeBar');
const stallBanner = document.getElementById('stallBanner');
const preBufferEl = document.getElementById('preBuffer');
const pbFillEl    = document.getElementById('pbFill');
const pbLabelEl   = document.getElementById('pbLabel');
const idleOverlay = document.getElementById('idleOverlay');
const idlePlayBtn = document.getElementById('idlePlayBtn');
const idleMsgEl   = document.getElementById('idleMsg');
const idleSubmsg  = document.getElementById('idleSubmsg');
const idleBg      = document.getElementById('idleBg');
// Set idle background image
if (idleBg) idleBg.style.backgroundImage = `url(${JSON.stringify(<?= json_encode($backdrop_url ?: $poster_url) ?>)})`;
// Click idle play btn → play best server or retry
if (idlePlayBtn) idlePlayBtn.addEventListener('click', () => {
  const best = servers.find(s => s.status === 'ok' && s.source);
  if (best) { selectServer(best.id, { manual: true }); return; }
  // retry all
  servers.forEach(s => { if (s.type !== '_pre') { s.status = 'idle'; s.source = null; } });
  srvBranches = {}; srvBranchIdx = {};
  renderServers();
  autoLoad();
});

// ══════════════════════════════════════════════════════════════════════════════
//  State
// ══════════════════════════════════════════════════════════════════════════════
let servers       = [];
let srvBranches   = {};
let srvBranchIdx  = {};
let expandedId    = null;
let activeId      = null;
let artInst       = null;
let hlsInst       = null;
let subtitles     = [];
let activeSubs    = null;
let subOffset     = 0;  // subtitle timing offset in seconds
let activeQuals   = [];
let activeQualIdx = 0;
let selectToken   = 0;
let liveFetchCtrl = null;
let played        = false;
let autoLoadToken = 0;
let stallTimer        = null;
let stallGen          = 0;
let stallBannerTimer  = null;
let fallbackTimer     = null;
let fallbackGen       = 0;
let fallbackActive    = false;

// ══════════════════════════════════════════════════════════════════════════════
//  Subtitle offset helper
// ══════════════════════════════════════════════════════════════════════════════
function applySubOffset(url) {
  if (!url || subOffset === 0) return url;
  // Only our proxy files understand ?offset=
  if (!url.startsWith('/api/subtitles/')) return url;
  const sep = url.includes('?') ? '&' : '?';
  return url + sep + 'offset=' + subOffset;
}

// ══════════════════════════════════════════════════════════════════════════════
//  Subtitle prefetch — bright67 + opensubtitles (parallel)
// ══════════════════════════════════════════════════════════════════════════════
(async function prefetchSubs() {
  if (!CONTENT_ID) return;

  // Build opensubtitles URL (needs IMDB ID)
  const osUrl = IMDB_ID
    ? '/api/subtitles/opensubtitles.php?imdb=' + encodeURIComponent(IMDB_ID)
        + '&type=' + CONTENT_TYPE
        + (CONTENT_TYPE === 'tv' ? '&season=' + SEASON + '&episode=' + EPISODE : '')
    : null;

  // Fire both requests in parallel
  const [b67Resp, osResp] = await Promise.allSettled([
    fetch('/api/subtitles/bright67.php?id=' + CONTENT_ID + '&type=' + CONTENT_TYPE, { cache: 'no-store' })
      .then(r => r.json()).catch(() => null),
    osUrl
      ? fetch(osUrl, { cache: 'no-store' }).then(r => r.json()).catch(() => null)
      : Promise.resolve(null),
  ]);

  const b67Data = b67Resp.status === 'fulfilled' ? b67Resp.value : null;
  const osData  = osResp.status  === 'fulfilled' ? osResp.value  : null;

  const allSubs = [
    ...(b67Data?.subtitles || []),
    ...(osData?.subtitles  || []),
  ];
  if (!allSubs.length) return;

  // Arabic first, then English, then rest — dedupe by url
  const merged = { ar: [], en: [], other: [] };
  const seen   = new Set();
  for (const s of allSubs) {
    if (seen.has(s.url)) continue;
    seen.add(s.url);
    if (s.lang === 'ar')      merged.ar.push(s);
    else if (s.lang === 'en') merged.en.push(s);
    else                      merged.other.push(s);
  }
  subtitles = [...merged.ar, ...merged.en, ...merged.other];

  if (subtitles.length && artInst && !activeSubs?.length) {
    activeSubs = subtitles;
    loadSubtitleWithFallback(0);
  }
})();

// ══════════════════════════════════════════════════════════════════════════════
//  Utilities
// ══════════════════════════════════════════════════════════════════════════════
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function showSkeleton(msg, sub) {
  skText.textContent = msg || 'Loading…';
  skSub.textContent  = sub || '';
  skeletonEl.classList.remove('hidden');
}
function hideSkeleton() { skeletonEl.classList.add('hidden'); }

// ── Idle overlay helpers ──────────────────────────────────────────────────────
function showIdle(msg = '', isError = false, submsg = '') {
  if (!idleOverlay) return;
  idleOverlay.classList.remove('hidden');
  if (idleMsgEl) idleMsgEl.textContent = msg;
  if (idleSubmsg) idleSubmsg.textContent = submsg;
  if (idlePlayBtn) idlePlayBtn.classList.toggle('idle-scanning', !isError);
}
function hideIdle() { idleOverlay?.classList.add('hidden'); }

// ══════════════════════════════════════════════════════════════════════════════
//  Smart pre-buffering
// ══════════════════════════════════════════════════════════════════════════════
function bufferedAhead(video) {
  if (!video || !video.buffered || !video.buffered.length) return 0;
  const t = video.currentTime || 0;
  for (let i = 0; i < video.buffered.length; i++) {
    if (video.buffered.start(i) <= t + 0.1 && video.buffered.end(i) >= t)
      return video.buffered.end(i) - t;
  }
  return 0;
}
function smartStartPlayback(art) {
  const video = art.video;
  if (!video) { art.play().catch(()=>{}); return; }
  const target    = targetBufferSeconds();
  const maxWaitMs = 8000;
  const startedAt = performance.now();
  preBufferEl?.classList.add('visible');
  if (pbLabelEl) pbLabelEl.textContent = 'Buffering…';
  let settled = false;
  const finish = () => {
    if (settled) return;
    settled = true;
    clearInterval(poll);
    preBufferEl?.classList.remove('visible');
    art.play().catch(() => {
      const wrap = document.getElementById('playerWrap');
      if (document.getElementById('tapPlay')) return;
      const ov = document.createElement('div');
      ov.id = 'tapPlay';
      ov.innerHTML = '<div class="tap-inner"><i class="fa-solid fa-circle-play"></i><span>Tap to Play</span></div>';
      wrap.appendChild(ov);
      ov.addEventListener('click', () => { art.play(); ov.remove(); });
    });
  };
  const poll = setInterval(() => {
    const ahead    = bufferedAhead(video);
    const dur      = video.duration || 0;
    const pct      = Math.min(100, Math.round((ahead / target) * 100));
    if (pbFillEl) pbFillEl.style.width = pct + '%';
    const enough   = ahead >= target || (dur > 0 && ahead >= dur - 0.5);
    const timedOut = (performance.now() - startedAt) > maxWaitMs;
    if (enough || timedOut) finish();
  }, 150);
}
function preloadManifest(url, type) {
  if (!url || type === 'iframe' || type === 'mp4') return;
  try { fetch(url, { cache: 'force-cache', mode: 'cors', credentials: 'omit' }).catch(() => {}); } catch(_){}
}

// ══════════════════════════════════════════════════════════════════════════════
//  Bandwidth estimate
// ══════════════════════════════════════════════════════════════════════════════
let bwSamples  = [];
let bwEstimate = null;
function recordThroughputSample(bps) {
  bwSamples.push(bps);
  if (bwSamples.length > 6) bwSamples.shift();
  bwEstimate = bwSamples.reduce((a,b) => a+b, 0) / bwSamples.length;
}
function initialBandwidthGuess() {
  const nc = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  if (nc?.downlink) return nc.downlink * 1_000_000;
  return 5_000_000;
}
function targetBufferSeconds() {
  const bps = bwEstimate || initialBandwidthGuess();
  if (bps >= 8_000_000) return 25;
  if (bps >= 3_000_000) return 14;
  return 6;
}

function loadSubtitleWithFallback(idx) {
  const subs = activeSubs || [];
  if (idx >= subs.length || !artInst?.subtitle) return;
  const sub = subs[idx];
  const url = applySubOffset(sub.url);
  fetch(url, { method: 'HEAD' }).then(r => {
    if (r.ok) {
      try { artInst.subtitle.url = url; artInst.subtitle.show = true; } catch(_){}
      activeSubIdx = idx;
    } else { loadSubtitleWithFallback(idx + 1); }
  }).catch(() => loadSubtitleWithFallback(idx + 1));
}

// ══════════════════════════════════════════════════════════════════════════════
//  Render server list
// ══════════════════════════════════════════════════════════════════════════════
function latencyBadge(ms) {
  if (ms == null) return '';
  const cls = ms < 1000 ? 'fast' : ms < 3000 ? 'med' : 'slow';
  const lbl = ms < 1000 ? ms + 'ms' : (ms/1000).toFixed(1) + 's';
  return `<span class="srv-latency srv-latency-${cls}">${lbl}</span>`;
}

function renderServers() {
  if (skDots) {
    skDots.innerHTML = '';
    servers.slice(0, 20).forEach(s => {
      const dot = document.createElement('div');
      dot.className = 'sk-dot ' + (s.status === 'idle' ? '' : s.status);
      dot.title = s.name;
      skDots.appendChild(dot);
    });
  }
  // probe bar
  const done = servers.filter(s => s.status !== 'idle' && s.status !== 'probing').length;
  if (probeBarEl) probeBarEl.style.width = (servers.length ? Math.round((done/servers.length)*100) : 0) + '%';
  // active server chip
  const chip = document.getElementById('activeServerChip');
  const chipName = document.getElementById('activeServerName');
  if (chip && chipName) {
    if (activeId) {
      const srv = servers.find(s => s.id === activeId);
      chipName.textContent = srv?.name || activeId;
      chip.style.display = 'inline-flex';
    } else { chip.style.display = 'none'; }
  }
  const list  = document.getElementById('spSrcList');
  const count = document.getElementById('spSrcCount');
  if (!list) return;
  const ok = servers.filter(s => s.status === 'ok').length;
  if (count) count.textContent = servers.length ? `${ok}/${servers.length} online` : '';
  list.innerHTML = '';
  servers.forEach(srv => {
    const isAct    = srv.id === activeId;
    const branches = srvBranches[srv.id] || [];
    const hasBr    = branches.length > 0;
    const isExp    = expandedId === srv.id;
    const curBIdx  = srvBranchIdx[srv.id] ?? 0;
    const dotCls   = isAct ? 'xp-active' : srv.status === 'probing' ? 'xp-probing' : srv.status === 'fail' ? 'xp-fail' : srv.status === 'ok' ? 'xp-active' : '';
    let right = '';
    if (hasBr) {
      right = `<span class="srv-branch-badge">${branches.length}</span>
               <button class="srv-expand-btn${isExp?' srv-expand-open':''}" aria-label="Streams">›</button>`;
    } else if (isAct) {
      right = '<span class="sp-check-icon">✓</span>';
    } else if (srv.status === 'fail') {
      right = `<span style="color:#f87171;font-size:.78rem">Offline</span>`;
    } else if (srv.latencyMs != null && srv.status === 'ok') {
      right = latencyBadge(srv.latencyMs);
    }
    const row = document.createElement('div');
    row.className = 'sp-row' + (isAct ? ' sp-selected' : '') + (srv.status === 'fail' ? ' sp-row-fail' : '');
    row.innerHTML = `
      <span class="sp-row-left" style="flex:1;min-width:0;overflow:hidden;display:flex;align-items:center;gap:8px">
        <span class="xp-item-dot ${dotCls}"></span>
        <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;${srv.status==='fail'?'opacity:.45;':''}">${esc(srv.name)}</span>
        ${isAct && hasBr ? `<span class="srv-cur-branch-lbl">${esc(branches[curBIdx]?.label||'')}</span>` : ''}
      </span>
      <span style="display:flex;align-items:center;gap:6px;flex-shrink:0">${right}</span>`;
    row.addEventListener('click', e => {
      if (e.target.closest('.srv-expand-btn')) return;
      selectServer(srv.id, { manual: true });
      window.spGoMain && window.spGoMain();
    });
    const expBtn = row.querySelector('.srv-expand-btn');
    if (expBtn) {
      expBtn.addEventListener('click', e => {
        e.stopPropagation();
        expandedId = expandedId === srv.id ? null : srv.id;
        renderServers();
      });
    }
    list.appendChild(row);
    if (isExp && hasBr) {
      branches.forEach((b, i) => {
        const isActBr = isAct && i === curBIdx;
        const brow = document.createElement('div');
        brow.className = 'sp-row srv-branch-row' + (isActBr ? ' sp-selected' : '');
        brow.innerHTML = `
          <span class="sp-row-left" style="display:flex;align-items:center;gap:6px">
            <span style="color:rgba(255,255,255,.22);font-size:.85rem;flex-shrink:0">↳</span>${esc(b.label)}
          </span>
          ${isActBr ? '<span class="sp-check-icon">✓</span>' : (b.latencyMs ? latencyBadge(b.latencyMs) : '')}`;
        brow.addEventListener('click', async () => {
          if (isAct) { switchBranch(srv.id, i); }
          else {
            srvBranchIdx[srv.id] = i;
            if (b.url) {
              const srv2 = servers.find(s => s.id === srv.id);
              if (srv2) srv2.source = { m3u8: b.url, type: b.type||'m3u8', subtitles: [], qualities: [] };
            }
            await selectServer(srv.id, { manual: true });
          }
          window.spGoMain && window.spGoMain();
        });
        list.appendChild(brow);
      });
    }
  });
  // update src card value
  const srcCard = document.getElementById('spSrcValCard');
  if (srcCard && activeId) {
    const srv = servers.find(s => s.id === activeId);
    if (srv) srcCard.textContent = srv.name;
  }
}

// ══════════════════════════════════════════════════════════════════════════════
//  Stall watchdog
// ══════════════════════════════════════════════════════════════════════════════
function startStallWatcher() {
  stopStallWatcher();
  let lastTime = null;
  let lastProg = performance.now();
  const gen    = ++stallGen;
  stallTimer = setInterval(() => {
    if (gen !== stallGen) return;
    if (!artInst || !artInst.video) return;
    const vid = artInst.video;
    if (vid.paused || vid.ended || vid.readyState < 2) return;
    const cur = vid.currentTime;
    if (lastTime === null) { lastTime = cur; return; }
    if (cur !== lastTime) { lastTime = cur; lastProg = performance.now(); hideStallBanner(); }
    else if (performance.now() - lastProg > 7000) {
      showStallBanner();
      if (performance.now() - lastProg > 12000) { autoSwitchServer(); lastProg = performance.now(); }
    }
  }, 1500);
}
function stopStallWatcher() {
  stallGen++;
  if (stallTimer) { clearInterval(stallTimer); stallTimer = null; }
  hideStallBanner();
}
function showStallBanner() {
  const nextSrv = getBestAlternative();
  if (!nextSrv) return;
  stallBanner?.classList.add('visible');
  const msgEl = document.getElementById('stallMsg');
  if (msgEl) msgEl.textContent = `Stream stalled — ${nextSrv.name} is ready`;
  const sw = document.getElementById('stallSwitch');
  if (sw) { sw.onclick = () => { hideStallBanner(); autoSwitchServer(); }; }
}
function hideStallBanner() { stallBanner?.classList.remove('visible'); }
function getBestAlternative() {
  return servers.find(s => s.id !== activeId && s.status === 'ok' && s.source);
}
function autoSwitchServer() {
  const next = getBestAlternative();
  if (next) selectServer(next.id);
}

// ══════════════════════════════════════════════════════════════════════════════
//  Branch fallback
// ══════════════════════════════════════════════════════════════════════════════
function cancelFallback() {
  fallbackGen++;
  fallbackActive = false;
  if (fallbackTimer !== null) { clearTimeout(fallbackTimer); fallbackTimer = null; }
}
function switchBranch(srvId, idx) {
  cancelFallback();
  const branches = srvBranches[srvId];
  if (!branches?.[idx]) return;
  srvBranchIdx[srvId] = idx;
  const br  = branches[idx];
  const srv = servers.find(s => s.id === srvId);
  if (srv) srv.source = { m3u8: br.url, type: br.type||'m3u8', subtitles: [], qualities: [] };
  renderServers();
  showSkeleton('Loading · ' + (br.label || ''));
  reinitPlayer(br.url, activeSubs, br.type || 'm3u8');
}
function tryNextBranch() {
  if (fallbackActive) return true;
  const srvId    = activeId;
  const branches = srvBranches[srvId] || [];
  if (!srvId || branches.length === 0) return false;
  const cur  = srvBranchIdx[srvId] ?? 0;
  const next = cur + 1;
  if (next >= branches.length) return true;
  fallbackActive = true;
  const gen = ++fallbackGen;
  showSkeleton('Branch failed · trying ' + (branches[next]?.label || String(next + 1)) + '…');
  fallbackTimer = setTimeout(() => {
    fallbackTimer = null; fallbackActive = false;
    if (fallbackGen !== gen || activeId !== srvId) return;
    switchBranch(srvId, next);
  }, 1200);
  return true;
}

// ══════════════════════════════════════════════════════════════════════════════
//  HLS attach
// ══════════════════════════════════════════════════════════════════════════════
function attachHls(video, src, art) {
  if (!Hls.isSupported()) {
    if (video.canPlayType('application/vnd.apple.mpegurl')) video.src = src;
    return;
  }
  hlsInst = new Hls({
    enableWorker:true,lowLatencyMode:false,startFragPrefetch:true,progressive:true,
    testBandwidth:true,abrEwmaDefaultEstimate:5_000_000,abrBandWidthFactor:.95,
    abrBandWidthUpFactor:.7,startLevel:-1,maxBufferLength:120,maxMaxBufferLength:1800,
    maxBufferSize:200*1024*1024,maxBufferHole:.3,backBufferLength:30,
    highBufferWatchdogPeriod:5,nudgeMaxRetry:6,
    manifestLoadingTimeOut:15000,manifestLoadingMaxRetry:4,manifestLoadingRetryDelay:500,
    levelLoadingTimeOut:15000,levelLoadingMaxRetry:4,levelLoadingRetryDelay:500,
    fragLoadingTimeOut:30000,fragLoadingMaxRetry:8,fragLoadingRetryDelay:500,
    xhrSetup(xhr, xhrUrl) {
      if (xhrUrl.includes('/api/lookmovie/') || xhrUrl.includes('/api/notorrent/') || xhrUrl.includes('/api/aether/'))
        xhr.withCredentials = false;
    },
  });
  hlsInst.loadSource(src);
  hlsInst.attachMedia(video);
  hlsInst.on(Hls.Events.FRAG_LOADED, (_, data) => {
    cancelFallback(); hideStallBanner();
    const st = data?.frag?.stats;
    if (st && st.total > 0 && st.loading?.end > st.loading?.start) {
      const secs = (st.loading.end - st.loading.start) / 1000;
      if (secs > 0.05) recordThroughputSample((st.total * 8) / secs);
    }
  });
  hlsInst.on(Hls.Events.MANIFEST_PARSED, () => {
    const lvls = hlsInst.levels || [];
    if (lvls.length < 2) return;
    const seen  = new Set();
    const quals = [{ label: 'Auto', hlsIdx: -1 }];
    [...lvls].sort((a,b)=>(b.height||b.bitrate||0)-(a.height||a.bitrate||0)).forEach(l => {
      const lbl = l.height ? l.height+'p' : Math.round((l.bitrate||0)/1000)+'k';
      if (seen.has(lbl)) return; seen.add(lbl);
      quals.push({ label: lbl, hlsIdx: lvls.indexOf(l) });
    });
    activeQuals = quals;
  });
  hlsInst.on(Hls.Events.ERROR, (_, d) => {
    if (!d.fatal) return;
    if (d.type === Hls.ErrorTypes.NETWORK_ERROR) {
      try { hlsInst.startLoad(); } catch(_){}
      if (!tryNextBranch()) autoSwitchServer();
    } else if (d.type === Hls.ErrorTypes.MEDIA_ERROR) {
      try { hlsInst.recoverMediaError(); } catch(_){}
    } else { if (!tryNextBranch()) autoSwitchServer(); }
  });
  art.on('destroy', () => { if (hlsInst) { hlsInst.destroy(); hlsInst = null; } });
}

// ══════════════════════════════════════════════════════════════════════════════
//  Iframe player
// ══════════════════════════════════════════════════════════════════════════════
function initIframePlayer(url) {
  destroyPlayer();
  const container = document.getElementById('art');
  const iframe = document.createElement('iframe');
  iframe.src = url;
  iframe.style.cssText = 'width:100%;height:100%;border:none;display:block;';
  iframe.setAttribute('allowfullscreen', '');
  iframe.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture; encrypted-media');
  iframe.setAttribute('referrerpolicy', 'no-referrer');
  container.appendChild(iframe);
  hideSkeleton();
  stopStallWatcher();
}

// ══════════════════════════════════════════════════════════════════════════════
//  ArtPlayer init / destroy
// ══════════════════════════════════════════════════════════════════════════════
function destroyPlayer() {
  stopStallWatcher();
  if (artInst) { try { artInst.destroy(true); } catch(_){} artInst = null; }
  if (hlsInst) { try { hlsInst.destroy();     } catch(_){} hlsInst = null; }
  const container = document.getElementById('art');
  if (container) container.innerHTML = ''; // Artplayer DOM فقط — idleOverlay أخ وليس ابن
  activeQuals = [];
  // أظهر الـ overlay الخامل عند تدمير المشغل
  showIdle(played ? 'اختار سيرفر' : 'جاري التحميل…');
}

function reinitPlayer(url, subs, streamType = 'm3u8') {
  if (streamType === 'iframe') { initIframePlayer(url); return; }
  destroyPlayer();
  const container = document.getElementById('art');
  const firstSub  = subs?.length ? subs[0] : null;
  const opts = {
    container,
    url,
    autoplay:false,fullscreen:true,fullscreenWeb:true,playbackRate:true,
    setting:false,hotkey:true,pip:true,volume:1,
    poster:POSTER_URL,theme:'#e50914',lang:'en',
    style:{ width:'100%', height:'100%' },
    controls: [{
      position:'right',name:'hz-settings',index:10,
      html:'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 15.5A3.5 3.5 0 0 1 8.5 12 3.5 3.5 0 0 1 12 8.5a3.5 3.5 0 0 1 3.5 3.5 3.5 3.5 0 0 1-3.5 3.5m7.43-2.92c.04-.34.07-.68.07-1.08s-.03-.74-.07-1.08l2.33-1.82c.2-.16.26-.46.13-.7l-2.2-3.82c-.13-.24-.42-.32-.65-.24l-2.75 1.1c-.57-.44-1.18-.79-1.85-1.05l-.41-2.93A.55.55 0 0 0 14 2h-4a.55.55 0 0 0-.54.46l-.41 2.93c-.67.26-1.28.61-1.85 1.05L4.45 5.34c-.23-.08-.52 0-.65.24L1.6 9.4c-.13.24-.07.54.13.7l2.33 1.82C4.03 12.26 4 12.6 4 13s.03.74.07 1.08L1.73 15.9c-.2.16-.26.46-.13.7l2.2 3.82c.13.24.42.32.65.24l2.75-1.1c.57.44 1.18.79 1.85 1.05l.41 2.93c.08.28.28.46.54.46h4c.26 0 .46-.18.54-.46l.41-2.93c.67-.26 1.28-.61 1.85-1.05l2.75 1.1c.23.08.52 0 .65-.24l2.2-3.82c.13-.24.07-.54-.13-.7l-2.33-1.82Z"/></svg>',
      tooltip:'Settings',
      click: function () { window.spOpen && window.spOpen(); },
    }],
    ...(firstSub ? { subtitle:{ url:firstSub.url, type:'vtt', name:firstSub.label||'Subtitle', style:{ color:'#fff','font-size':'24px' }, escape:false } } : {}),
    ...(streamType === 'm3u8' ? { type:'m3u8', customType:{ m3u8: attachHls } } : {}),
  };
  artInst = new Artplayer(opts);
  artInst.on('video:playing', () => { cancelFallback(); hideStallBanner(); hideIdle(); });
  artInst.on('ready', () => { hideSkeleton(); hideIdle(); startStallWatcher(); setupSkipIntro(); smartStartPlayback(artInst); });
  artInst.on('error', () => { if (!tryNextBranch()) autoSwitchServer(); });
  async function lockLandscape() { try { if (screen.orientation?.lock) await screen.orientation.lock('landscape'); } catch(_){} }
  function unlockOrientation() { try { if (screen.orientation?.unlock) screen.orientation.unlock(); } catch(_){} }
  artInst.on('fullscreen',    s => { s ? lockLandscape() : unlockOrientation(); });
  artInst.on('fullscreenWeb', s => { s ? lockLandscape() : unlockOrientation(); });
  artInst.on('destroy', () => { unlockOrientation(); stopStallWatcher(); });
  if (subs?.length > 1) {
    artInst.on('ready', () => {
      const video = artInst.video;
      if (!video) return;
      video.setAttribute('crossorigin', 'anonymous');
      subs.slice(1).forEach((s, i) => {
        const track = document.createElement('track');
        track.kind = 'subtitles'; track.label = s.label||('Sub '+(i+2)); track.srclang = s.lang||'en'; track.src = s.url;
        video.appendChild(track);
      });
    });
  }
}

// ══════════════════════════════════════════════════════════════════════════════
//  initPlayer / setupSkipIntro / resetPlayerToIdle
// ══════════════════════════════════════════════════════════════════════════════
function initPlayer(src, subs, srvId, srvName) {
  activeSubs = subs;
  cancelFallback();
  const quals = src.qualities || [];
  if (quals.length > 1) {
    srvBranches[srvId]  = quals.map(q => ({ label: q.label||'', url: q.url, type: src.type||'m3u8' }));
    srvBranchIdx[srvId] = Math.max(0, quals.findIndex(q => q.default));
  }
  preloadManifest(src.m3u8, src.type || 'm3u8');
  reinitPlayer(src.m3u8, subs, src.type || 'm3u8');
}

function setupSkipIntro() {
  const btn = document.getElementById('skipIntroBtn');
  if (!btn || CONTENT_TYPE !== 'tv' || !artInst) return;
  const fresh = btn.cloneNode(true);
  btn.replaceWith(fresh);
  fresh.classList.remove('si-visible');
  let dismissed = false;
  artInst.on('video:timeupdate', () => {
    if (dismissed) { fresh.classList.remove('si-visible'); return; }
    const t = artInst?.currentTime || 0;
    fresh.classList.toggle('si-visible', t >= 15 && t <= 300);
  });
  fresh.addEventListener('click', () => {
    if (!artInst) return;
    artInst.currentTime = Math.min((artInst.currentTime||0)+85, artInst.duration-1);
    dismissed = true; fresh.classList.remove('si-visible');
  });
}

function resetPlayerToIdle() {
  destroyPlayer();
  // لا نمسح الـ container — الـ idleOverlay يغطي الفراغ
}

// ══════════════════════════════════════════════════════════════════════════════
//  Security helpers — nonce + timestamp per API request
//  يُضيف _ts (Unix timestamp) و _nonce (عشوائي مرة واحدة) لكل طلب API
//  هذا يمنع Replay Attacks ويرفض الطلبات القديمة على الـ Backend
// ══════════════════════════════════════════════════════════════════════════════
function _secUrl(baseUrl) {
  const ts    = Math.floor(Date.now() / 1000);
  const nonce = (function() {
    if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
      return Array.from(crypto.getRandomValues(new Uint8Array(16)),
             b => b.toString(16).padStart(2,'0')).join('');
    }
    // Fallback for older browsers
    return Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2) +
           Date.now().toString(36);
  })();
  const sep = baseUrl.includes('?') ? '&' : '?';
  return baseUrl + sep + '_ts=' + ts + '&_nonce=' + encodeURIComponent(nonce);
}

// ══════════════════════════════════════════════════════════════════════════════
//  fetchStream
// ══════════════════════════════════════════════════════════════════════════════
async function fetchStream(srv, timeoutMs = 25000, signal = null) {
  const entry = servers.find(s => s.id === srv.id);
  if (entry) { entry.status = 'probing'; renderServers(); }
  if (entry?.source?.m3u8) {
    if (entry) { entry.status = 'ok'; renderServers(); }
    return entry.source;
  }
  async function timedFetch(url, ms) {
    const ctrl = new AbortController();
    const tid  = setTimeout(() => ctrl.abort(), ms);
    if (signal) signal.addEventListener('abort', () => ctrl.abort(), { once: true });
    try {
      const r = await fetch(url, { cache: 'no-store', signal: ctrl.signal });
      clearTimeout(tid); return r;
    } catch(e) { clearTimeout(tid); throw e; }
  }
  const t0 = performance.now();
  try {
    let source = null;
    if (srv.type === '_pre') {
      // سيرفر محقون مسبقاً — المصدر موجود في entry.source
      source = entry?.source || null;
    } else if (srv.type === 'direct') {
      const r = await timedFetch(_secUrl(srv.resolver), timeoutMs);
      const j = await r.json();
      if (j?.ok && j.source?.m3u8) source = j.source;
    } else if (srv.type === 'multi') {
      const r = await timedFetch(_secUrl(srv.resolver), timeoutMs);
      const j = await r.json();
      if (j?.ok && Array.isArray(j.servers)) {
        for (const s of j.servers) {
          if (!Array.isArray(s.streams) || !s.streams.length) continue;
          srvBranches[srv.id]  = s.streams;
          srvBranchIdx[srv.id] = 0;
          const first = s.streams[0];
          source = { m3u8: first.url, type: first.type||'m3u8', subtitles: [], qualities: [] };
          break;
        }
      }
    } else if (srv.type === 'expand') {
      // يجلب عدة سيرفرات: الأول يكمّل الإدخال الرئيسي، الباقي يُحقن كإدخالات مستقلة
      const r = await timedFetch(_secUrl(srv.resolver), timeoutMs);
      const j = await r.json();
      if (j?.ok && Array.isArray(j.servers) && j.servers.length) {
        const idx = servers.findIndex(x => x.id === srv.id);
        let insertAt = idx + 1;
        let firstDone = false;
        for (let si = 0; si < j.servers.length; si++) {
          const s = j.servers[si];
          if (!Array.isArray(s.streams) || !s.streams.length) continue;
          const preSource = { m3u8: s.streams[0].url, type: s.streams[0].type || 'm3u8', subtitles: [], qualities: [] };
          if (!firstDone) {
            // السيرفر الأول → يكمّل الإدخال الرئيسي
            source = preSource;
            if (entry && s.name) entry.name = s.name;
            if (s.streams.length > 1) { srvBranches[srv.id] = s.streams; srvBranchIdx[srv.id] = 0; }
            firstDone = true;
          } else {
            // السيرفرات الإضافية → حقن كإدخالات _pre مستقلة
            const dynId = srv.id + '__' + si;
            if (!servers.find(x => x.id === dynId)) {
              const newSrv = {
                id: dynId, name: s.name || (srv.name + ' · ' + (si + 1)),
                type: '_pre', status: 'ok',
                latencyMs: Math.round(performance.now() - t0),
                source: preSource, resolver: null,
              };
              servers.splice(insertAt, 0, newSrv);
              if (s.streams.length > 1) { srvBranches[dynId] = s.streams; srvBranchIdx[dynId] = 0; }
            }
            insertAt++;
          }
        }
      }
    }
    if (source) {
      if (entry) { entry.status = 'ok'; entry.latencyMs = Math.round(performance.now()-t0); entry.source = source; }
      renderServers(); return source;
    }
  } catch(_){}
  if (entry) { entry.status = 'fail'; entry.latencyMs = Math.round(performance.now()-t0); }
  renderServers(); return null;
}

// ══════════════════════════════════════════════════════════════════════════════
//  Manual server selection
// ══════════════════════════════════════════════════════════════════════════════
async function selectServer(id, { manual = false } = {}) {
  if (!id) return;
  cancelFallback();
  if (manual) autoLoadToken = -1;
  const myToken = ++selectToken;
  activeId      = id;
  if (liveFetchCtrl) { try { liveFetchCtrl.abort(); } catch(_){} }
  const myCtrl  = new AbortController();
  liveFetchCtrl = myCtrl;
  resetPlayerToIdle();
  renderServers();
  const srv = servers.find(s => s.id === id);
  if (!srv) return;
  showSkeleton('Loading · ' + srv.name + '…');
  const src = await fetchStream(srv, 30000, myCtrl.signal);
  if (myToken !== selectToken) return;
  renderServers();
  if (src) {
    played = true;
    hideIdle();
    const subs = src.subtitles?.length ? src.subtitles : subtitles;
    initPlayer(src, subs, id, srv.name);
  } else {
    hideSkeleton();
    showIdle('السيرفر مو شغّال — اختار سيرفر ثاني', true, srv.name);
  }
}

// ══════════════════════════════════════════════════════════════════════════════
//  Auto-load — sequential: try each server one by one, 5s delay between them.
//  Once a server works → start playing immediately, probe rest in background.
// ══════════════════════════════════════════════════════════════════════════════
async function probeRemainingBackground(list, token) {
  for (const def of list) {
    if (autoLoadToken !== token) return;
    await new Promise(r => setTimeout(r, 5000));
    if (autoLoadToken !== token) return;
    const entry = servers.find(s => s.id === def.id);
    if (!entry || entry.status !== 'idle') continue; // already probed/active
    await fetchStream(def, 28000);
    if (autoLoadToken !== token) return;
    renderServers();
  }
}

async function autoLoad() {
  const myToken = ++autoLoadToken;
  showSkeleton('جاري التحميل…');
  showIdle('جاري التحميل…');

  let won = false;

  for (let i = 0; i < SERVER_LIST.length; i++) {
    if (autoLoadToken !== myToken) return;
    const def = SERVER_LIST[i];

    // 5-second gap between server attempts (not before the very first)
    if (i > 0) {
      await new Promise(r => setTimeout(r, 5000));
      if (autoLoadToken !== myToken) return;
    }

    const source = await fetchStream(def, 28000);
    if (autoLoadToken !== myToken) return;
    renderServers();

    if (source && !won) {
      won      = true;
      played   = true;
      activeId = def.id;
      hideSkeleton();
      hideIdle();
      const subs = source.subtitles?.length ? source.subtitles : subtitles;
      initPlayer(source, subs, def.id, def.name);
      // Continue probing remaining servers in background
      probeRemainingBackground(SERVER_LIST.slice(i + 1), myToken);
      return;
    }
  }

  if (!won && autoLoadToken === myToken) {
    hideSkeleton();
    showIdle('كل السيرفرات مو شغّالة', true, 'اضغط للمحاولة مرة ثانية');
  }
}

// Boot
servers = SERVER_LIST.map(s => ({ ...s, status: 'idle', latencyMs: null, source: null }));
renderServers();
autoLoad();

// ══════════════════════════════════════════════════════════════════════════════
//  Season selector
// ══════════════════════════════════════════════════════════════════════════════
const seasonSel = document.getElementById('seasonSel');
if (seasonSel) {
  seasonSel.addEventListener('change', () => {
    location.href = '/watch/tv/' + TV_SLUG + '/' + seasonSel.value + '/1';
  });
}

// ══════════════════════════════════════════════════════════════════════════════
//  Settings Panel
// ══════════════════════════════════════════════════════════════════════════════
(function () {
  const overlay = document.getElementById('spOverlay');
  const pages   = document.getElementById('spPages');
  if (!overlay || !pages) return;

  const OFFSETS = ['0%', '-20%', '-40%', '-60%', '-80%'];
  pages.querySelectorAll('.sp-page').forEach(p => p.style.width = '20%');

  let currentPage     = 0;
  let activeSubIdx    = -1;
  let expandedSubLang = null;

  const LANG_FLAGS = {
    arabic:'🇸🇦',english:'🇺🇸',french:'🇫🇷',german:'🇩🇪',spanish:'🇪🇸',
    portuguese:'🇧🇷',italian:'🇮🇹',russian:'🇷🇺',chinese:'🇨🇳',japanese:'🇯🇵',
    korean:'🇰🇷',hindi:'🇮🇳',turkish:'🇹🇷',dutch:'🇳🇱',polish:'🇵🇱',
    romanian:'🇷🇴',hungarian:'🇭🇺',czech:'🇨🇿',greek:'🇬🇷',hebrew:'🇮🇱',
    bulgarian:'🇧🇬',croatian:'🇭🇷',danish:'🇩🇰',finnish:'🇫🇮',norwegian:'🇳🇴',
    swedish:'🇸🇪',slovak:'🇸🇰',slovenian:'🇸🇮',ukrainian:'🇺🇦',vietnamese:'🇻🇳',
    thai:'🇹🇭',indonesian:'🇮🇩',malay:'🇲🇾',bengali:'🇧🇩',serbian:'🇷🇸',
  };
  const LANG_CODE_MAP = {
    ar:'arabic',en:'english',fr:'french',de:'german',es:'spanish',pt:'portuguese',
    it:'italian',ru:'russian',zh:'chinese',ja:'japanese',ko:'korean',hi:'hindi',
    tr:'turkish',nl:'dutch',pl:'polish',ro:'romanian',hu:'hungarian',cs:'czech',
    el:'greek',he:'hebrew',bg:'bulgarian',hr:'croatian',da:'danish',fi:'finnish',
    no:'norwegian',sv:'swedish',sk:'slovak',sl:'slovenian',uk:'ukrainian',vi:'vietnamese',
    th:'thai',id:'indonesian',ms:'malay',bn:'bengali',sr:'serbian',
  };
  const LANG_NAMES_MAP = {
    ar:'العربية',en:'English',fr:'Français',de:'Deutsch',es:'Español',pt:'Português',
    it:'Italiano',ru:'Русский',zh:'中文',ja:'日本語',ko:'한국어',hi:'हिन्दी',
    tr:'Türkçe',nl:'Nederlands',pl:'Polski',ro:'Română',hu:'Magyar',cs:'Čeština',
    el:'Ελληνικά',he:'עברית',bg:'Български',hr:'Hrvatski',da:'Dansk',fi:'Suomi',
    no:'Norsk',sv:'Svenska',sk:'Slovenčina',sl:'Slovenščina',uk:'Українська',vi:'Tiếng Việt',
    th:'ภาษาไทย',id:'Bahasa Indonesia',ms:'Bahasa Melayu',bn:'বাংলা',sr:'Српски',
  };

  function subLangFlag(code) {
    const name = LANG_CODE_MAP[code] || code.toLowerCase();
    return LANG_FLAGS[name] || '🌐';
  }
  function subLangName(code) {
    return LANG_NAMES_MAP[code] || code.toUpperCase();
  }

  function goPage(n) {
    currentPage = n;
    pages.style.transform = `translateX(${OFFSETS[n]})`;
    if (n === 1) renderQualityPage();
    if (n === 2) renderSubsPage();
    if (n === 3) renderServers();
  }
  function spOpen()  { overlay.classList.add('sp-open'); goPage(0); }
  function spClose() { overlay.classList.remove('sp-open'); }
  window.spOpen  = spOpen;
  window.spClose = spClose;
  window.spGoMain = () => goPage(0);

  overlay.addEventListener('click', e => { if (e.target === overlay) spClose(); });
  document.getElementById('spCloseMain')?.addEventListener('click', spClose);
  overlay.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', spClose));
  overlay.querySelectorAll('[data-back]').forEach(el => {
    el.addEventListener('click', () => goPage(parseInt(el.dataset.back) || 0));
  });

  document.getElementById('spQualCard')?.addEventListener('click', () => goPage(1));
  document.getElementById('spSubCard')?.addEventListener('click',  () => goPage(2));
  document.getElementById('spSrcCard')?.addEventListener('click',  () => goPage(3));
  document.getElementById('spVideoCard')?.addEventListener('click',() => goPage(4));

  // ── Quality ───────────────────────────────────────────────────────────────
  function renderQualityPage() {
    const list = document.getElementById('spQualList');
    if (!list) return;
    list.innerHTML = '';
    const quals = activeQuals || [];
    if (!quals.length) {
      list.innerHTML = '<div class="sp-row" style="color:rgba(255,255,255,.35);cursor:default">No quality options available</div>';
      return;
    }
    quals.forEach((q, i) => {
      const isAct = i === activeQualIdx;
      const row   = document.createElement('div');
      row.className = 'sp-row' + (isAct ? ' sp-selected' : '');
      row.innerHTML = `<span class="sp-row-left">${esc(q.label)}</span>${isAct ? '<span class="sp-check-icon">✓</span>' : ''}`;
      row.addEventListener('click', () => {
        if (!hlsInst) return;
        activeQualIdx = i;
        hlsInst.currentLevel = q.hlsIdx === -1 ? -1 : q.hlsIdx;
        const autoToggle = document.getElementById('spAutoQual');
        if (autoToggle) autoToggle.checked = (q.label === 'Auto' || q.hlsIdx === -1);
        const qualCard = document.getElementById('spQualValCard');
        if (qualCard) qualCard.textContent = q.label;
        renderQualityPage();
      });
      list.appendChild(row);
    });
  }
  document.getElementById('spAutoQual')?.addEventListener('change', function() {
    if (!hlsInst) return;
    if (this.checked) {
      hlsInst.currentLevel = -1;
      activeQualIdx = (activeQuals||[]).findIndex(q => q.hlsIdx === -1 || q.label === 'Auto');
      if (activeQualIdx < 0) activeQualIdx = 0;
      renderQualityPage();
    }
  });

  // ── Subtitles ─────────────────────────────────────────────────────────────
  function renderSubsPage() {
    const langList = document.getElementById('spSubLangList');
    if (!langList) return;
    langList.innerHTML = '';
    const subs = activeSubs || [];
    document.getElementById('spSubOffRow')?.classList.toggle('sp-selected', activeSubIdx < 0);
    document.getElementById('spSubAutoRow')?.classList.toggle('sp-selected', activeSubIdx === 0 && subs.length > 0);
    if (!subs.length) {
      langList.innerHTML = '<div class="sp-row" style="color:rgba(255,255,255,.35);cursor:default">No subtitles available</div>';
      return;
    }
    const grouped = {};
    subs.forEach((s, i) => { const k = s.lang || 'und'; (grouped[k] = grouped[k] || []).push({ ...s, idx: i }); });
    Object.entries(grouped).forEach(([lang, items]) => {
      const isAct  = items.some(it => it.idx === activeSubIdx);
      const isExp  = expandedSubLang === lang;
      const multi  = items.length > 1;
      const row = document.createElement('div');
      row.className = 'sp-row' + (isAct ? ' sp-selected' : '');
      row.innerHTML = `
        <div class="sp-lang-row sp-row-left">
          <span class="sp-lang-flag">${subLangFlag(lang)}</span>
          <span class="sp-lang-name">${esc(subLangName(lang))}</span>
          ${multi ? `<span class="srv-branch-badge">${items.length}</span>` : ''}
        </div>
        <span style="display:flex;align-items:center;gap:4px;flex-shrink:0">
          ${isAct && !multi ? '<span class="sp-check-icon">✓</span>' : ''}
          ${multi ? `<button class="srv-expand-btn${isExp?' srv-expand-open':''}" aria-label="subtitles">›</button>` : (!isAct ? '<span class="sp-arrow">›</span>' : '')}
        </span>`;
      row.addEventListener('click', e => {
        if (e.target.closest('.srv-expand-btn')) return;
        if (multi) { expandedSubLang = isExp ? null : lang; renderSubsPage(); }
        else { switchSubtitle(items[0].idx); }
      });
      const expBtn = row.querySelector('.srv-expand-btn');
      if (expBtn) {
        expBtn.addEventListener('click', e => {
          e.stopPropagation(); expandedSubLang = isExp ? null : lang; renderSubsPage();
        });
      }
      langList.appendChild(row);
      if (isExp && multi) {
        items.forEach(item => {
          const isActItem = item.idx === activeSubIdx;
          const title = (item.label||'').replace(/^[^—–\-]+[—–\-]\s*/,'').trim() || item.label || ('Sub '+(item.idx+1));
          const brow = document.createElement('div');
          brow.className = 'sp-row srv-branch-row' + (isActItem ? ' sp-selected' : '');
          brow.innerHTML = `
            <span class="sp-row-left" style="display:flex;align-items:center;gap:6px;overflow:hidden">
              <span style="color:rgba(255,255,255,.22);font-size:.85rem;flex-shrink:0">↳</span>
              <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(title)}</span>
            </span>
            ${isActItem ? '<span class="sp-check-icon">✓</span>' : ''}`;
          brow.addEventListener('click', () => switchSubtitle(item.idx));
          langList.appendChild(brow);
        });
      }
    });
  }

  function switchSubtitle(idx) {
    activeSubIdx = idx;
    const video  = artInst?.video;
    const subCard = document.getElementById('spSubValCard');
    if (subCard) {
      if (idx < 0) subCard.textContent = 'Off';
      else { const s = (activeSubs||[])[idx]; subCard.textContent = s ? subLangName(s.lang || 'und') : 'On'; }
    }
    if (!video) { renderSubsPage(); return; }
    if (artInst?.subtitle) { try { artInst.subtitle.show = false; } catch(_){} }
    Array.from(video.textTracks).forEach(t => { t.mode = 'disabled'; });
    if (idx >= 0) {
      const sub = (activeSubs||[])[idx];
      if (!sub) { renderSubsPage(); return; }
      const subUrl = applySubOffset(sub.url);
      if (idx === 0 && artInst?.subtitle) {
        try { artInst.subtitle.url = subUrl; artInst.subtitle.show = true; } catch(_){}
      } else {
        const tracks   = Array.from(video.textTracks);
        const trackIdx = idx - 1;
        if (tracks[trackIdx]) {
          tracks[trackIdx].src  = subUrl;   // reload with new offset
          tracks[trackIdx].mode = 'showing';
        } else {
          const t = document.createElement('track');
          t.kind = 'subtitles'; t.label = sub.label||('Sub '+(idx+1)); t.srclang = sub.lang||'und'; t.src = subUrl;
          video.appendChild(t);
          setTimeout(() => {
            const all = Array.from(video.textTracks);
            const tgt = all[trackIdx] || all[all.length-1];
            if (tgt) tgt.mode = 'showing';
          }, 120);
        }
      }
    }
    renderSubsPage();
  }

  document.getElementById('spSubOffRow')?.addEventListener('click', () => switchSubtitle(-1));
  document.getElementById('spSubAutoRow')?.addEventListener('click', () => {
    if ((activeSubs||[]).length) switchSubtitle(0);
  });

  // ── Subtitle sync delay controls ──────────────────────────────────────────
  function updateDelayUI() {
    const el = document.getElementById('spSubDelayVal');
    if (el) el.textContent = (subOffset >= 0 ? '+' : '') + subOffset.toFixed(1) + 's';
  }
  function applyNewOffset(delta) {
    subOffset = Math.round((subOffset + delta) * 10) / 10; // step 0.5, avoid float drift
    updateDelayUI();
    // Re-load active subtitle with new offset
    if (activeSubIdx >= 0) switchSubtitle(activeSubIdx);
  }
  document.getElementById('spSubDelayMinus')?.addEventListener('click', (e) => { e.stopPropagation(); applyNewOffset(-0.5); });
  document.getElementById('spSubDelayPlus') ?.addEventListener('click', (e) => { e.stopPropagation(); applyNewOffset(+0.5); });
  document.getElementById('spSubDelayReset')?.addEventListener('click', (e) => {
    e.stopPropagation();
    subOffset = 0;
    updateDelayUI();
    if (activeSubIdx >= 0) switchSubtitle(activeSubIdx);
  });
  document.getElementById('spSubUploadRow')?.addEventListener('click', () => {
    const inp = document.createElement('input');
    inp.type = 'file'; inp.accept = '.vtt,.srt,.ass,.ssa';
    inp.onchange = () => {
      const file = inp.files?.[0];
      if (!file) return;
      const url = URL.createObjectURL(file);
      activeSubs = [{ url, label: file.name, lang: 'custom' }, ...(activeSubs||[])];
      switchSubtitle(0);
    };
    inp.click();
  });

  // ── Video filters ─────────────────────────────────────────────────────────
  function applyFilter() {
    const b = document.getElementById('spBrightness')?.value ?? 100;
    const c = document.getElementById('spContrast')?.value   ?? 100;
    const s = document.getElementById('spSaturation')?.value ?? 100;
    const v = artInst?.video;
    if (v) v.style.filter = `brightness(${b}%) contrast(${c}%) saturate(${s}%)`;
  }
  ['spBrightness','spContrast','spSaturation'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', applyFilter);
  });

  // ── Aspect ratio ──────────────────────────────────────────────────────────
  document.querySelectorAll('input[name="spAspect"]').forEach(r => {
    r.addEventListener('change', () => {
      const v = artInst?.video;
      if (v) v.style.objectFit = r.value === 'fill' ? 'fill' : 'contain';
    });
  });

  // ── ASCII Mode ────────────────────────────────────────────────────────────
  let asciiCanvas = null;
  let asciiCtx    = null;
  let asciiTimer  = null;
  function startAscii() {
    const v = artInst?.video;
    if (!v) return;
    if (!asciiCanvas) {
      asciiCanvas = document.createElement('canvas');
      asciiCanvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;z-index:4;font-family:monospace;pointer-events:none;background:#000';
      document.getElementById('playerWrap')?.appendChild(asciiCanvas);
      asciiCtx = asciiCanvas.getContext('2d');
    }
    asciiCanvas.style.display = 'block';
    const chars = '@#S%?*+;:,.';
    function draw() {
      const cols = 120; const rows = 40;
      asciiCanvas.width  = asciiCanvas.offsetWidth;
      asciiCanvas.height = asciiCanvas.offsetHeight;
      const cw = Math.ceil(asciiCanvas.width  / cols);
      const ch = Math.ceil(asciiCanvas.height / rows);
      const tmp = document.createElement('canvas');
      tmp.width = cols; tmp.height = rows;
      const tc = tmp.getContext('2d');
      tc.drawImage(v, 0, 0, cols, rows);
      const data = tc.getImageData(0, 0, cols, rows).data;
      asciiCtx.fillStyle = '#000';
      asciiCtx.fillRect(0, 0, asciiCanvas.width, asciiCanvas.height);
      const useColor = document.getElementById('spAsciiColor')?.checked;
      asciiCtx.font = `${ch}px monospace`;
      for (let r = 0; r < rows; r++) {
        for (let c = 0; c < cols; c++) {
          const i = (r * cols + c) * 4;
          const [R,G,B] = [data[i], data[i+1], data[i+2]];
          const lum = 0.299*R + 0.587*G + 0.114*B;
          const ch_ = chars[Math.floor((lum/255)*(chars.length-1))];
          asciiCtx.fillStyle = useColor ? `rgb(${R},${G},${B})` : `rgb(${Math.round(lum)},${Math.round(lum)},${Math.round(lum)})`;
          asciiCtx.fillText(ch_, c*cw, (r+1)*ch);
        }
      }
      asciiTimer = requestAnimationFrame(draw);
    }
    draw();
  }
  function stopAscii() {
    if (asciiTimer) { cancelAnimationFrame(asciiTimer); asciiTimer = null; }
    if (asciiCanvas) asciiCanvas.style.display = 'none';
  }
  document.getElementById('spAsciiMode')?.addEventListener('change', e => {
    e.target.checked ? startAscii() : stopAscii();
  });


  // ── Keyboard ──────────────────────────────────────────────────────────────
  document.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (e.key === 's' || e.key === 'S') {
      overlay.classList.contains('sp-open') ? spClose() : spOpen();
    }
    if (e.key === 'ArrowRight' && artInst) {
      e.preventDefault();
      artInst.currentTime = Math.min((artInst.currentTime||0)+10, (artInst.duration||0)-1);
    }
    if (e.key === 'ArrowLeft' && artInst) {
      e.preventDefault();
      artInst.currentTime = Math.max((artInst.currentTime||0)-10, 0);
    }
  });

  // ── Double-tap seek (touch: left zone = -10s, right zone = +10s) ──────────
  (function setupDoubleTap() {
    const wrap = document.getElementById('playerWrap');
    if (!wrap) return;
    let tapTimer = null;
    let tapCount = 0;
    let tapSide  = null;

    function showSeekRipple(side, secs) {
      const existing = wrap.querySelector('.dt-ripple-' + side);
      if (existing) existing.remove();
      const el = document.createElement('div');
      el.className = 'dt-ripple dt-ripple-' + side;
      el.innerHTML = `<div class="dt-ripple-inner">
        <i class="fa-solid fa-${side === 'left' ? 'backward' : 'forward'}"></i>
        <span>${secs > 0 ? '+' : ''}${secs}s</span>
      </div>`;
      wrap.appendChild(el);
      requestAnimationFrame(() => el.classList.add('dt-ripple-show'));
      setTimeout(() => { el.classList.remove('dt-ripple-show'); setTimeout(() => el.remove(), 350); }, 700);
    }

    function doSeek(side) {
      if (!artInst) return;
      const delta = side === 'right' ? 10 : -10;
      artInst.currentTime = Math.max(0, Math.min((artInst.currentTime||0) + delta, (artInst.duration||Infinity) - 1));
      showSeekRipple(side, delta);
    }

    wrap.addEventListener('touchend', e => {
      if (overlay.classList.contains('sp-open')) return;
      // Ignore if touch moved significantly (scrolling)
      const t = e.changedTouches[0];
      if (!t) return;
      const rect = wrap.getBoundingClientRect();
      const relX = t.clientX - rect.left;
      const side  = relX < rect.width / 2 ? 'left' : 'right';

      if (tapTimer && tapSide === side) {
        // Second tap on same side — double tap!
        clearTimeout(tapTimer); tapTimer = null; tapCount = 0; tapSide = null;
        e.preventDefault();
        doSeek(side);
      } else {
        // First tap — wait to see if double
        tapCount = 1; tapSide = side;
        tapTimer = setTimeout(() => { tapTimer = null; tapCount = 0; tapSide = null; }, 300);
      }
    }, { passive: false });
  })();

})();
</script>

<nav class="bottom-nav">
  <a href="/" class="bn-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
  <a href="/movies" class="bn-item"><i class="fa-solid fa-film"></i><span>Movies</span></a>
  <a href="/reels" class="bn-item bn-reels"><i class="fa-solid fa-clapperboard"></i><span>Reels</span></a>
  <a href="/explore" class="bn-item"><i class="fa-solid fa-compass"></i><span>Explore</span></a>
  <a href="/saved" class="bn-item"><i class="fa-regular fa-bookmark"></i><span>Saved</span></a>
</nav>
</body>
</html>
