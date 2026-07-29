<?php
// Site configuration
session_start();

define('SITE_NAME', 'HZ Flix');
define('SITE_DESC', 'Watch unlimited movies and TV shows online');

// ── Security ──────────────────────────────────────────────────────────────────
// APP_SECRET: used for HMAC signing of stream tokens and security hashes.
// Set SESSION_SECRET in Replit Secrets (or any env var) to a long random string.
define('APP_SECRET', getenv('SESSION_SECRET') ?: 'fallback_CHANGE_THIS_in_production');

// TMDB API
define('TMDB_API_KEY', '60a8d6ad3b8e5fbdbde539526b196d9b');

// Subdl subtitle API
define('SUBDL_API_KEY', getenv('SUBDL_API_KEY') ?: '');
define('TMDB_API_URL', 'https://api.themoviedb.org/3');
define('TMDB_IMG', 'https://image.tmdb.org/t/p');

// Base URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', $scheme . '://' . $host);

// ── EmbedMaster (embdmstrplayer.com) ────────────────────────────────────────
// cf_clearance: cookie من embdmstrplayer.com (تنتهي كل ~30 دقيقة، جدّدها من المتصفح)
// cf-turnstile-response: رمز Turnstile من الصفحة (اختياري — يُساعد في تجاوز التحقق)
define('EMBEDMASTER_CF_CLEARANCE', getenv('EMBEDMASTER_CF_CLEARANCE') ?: '');
define('EMBEDMASTER_TURNSTILE',    getenv('EMBEDMASTER_TURNSTILE')    ?: '');

// xpass.top credentials — update these when they expire.
// auth_token: found in browser cookies on play.xpass.top
// cf_clearance: Cloudflare bot-challenge cookie (expires ~30 min, update as needed)
define('XPASS_AUTH_TOKEN',  '7a5b8c68786ca8d2dd012e78e9756dd30bfe0aa095d5f1ef8581b266dc75bf85');
define('XPASS_CF_CLEARANCE', 'HO_Cp3C7EP_x_9N2L4kYHkpqvCDVyrvChSJN_Xu7Yqk-1778017313-1.2.1.1-hNcAbxe0aA8foMLTQXk8rS5Vdv_L0RGPxlNYMfm5bRfWXiLHY7YsrCyRIR3ZtKZP.LaKWq2R10E4SusiqgAOvq.ZLqRooZx2XwyBaQXlOzPZLpnvWOpvaSW1d8Fc59e.UOoTSW4MlqrBes3sJB.a045v9.GvMTYI5vrg5JSL829V6GQsr7tjMAlyEQ5BDeejAK2TeAd4Ifrm2lWkkt7DX_d3XNDl0GqRd4c5ljsdC8p06dvVYFA.N2Y.bCrkRWravrd7Gs5ku09hgHF0BuUAXTMBGfmUglLCWY.KpvNbf2g_z5Q6DRDxQs4BS20MJE7hpau4MzltPXKigpcze4dhQ');

error_reporting(E_ALL);
ini_set('display_errors', 0);   // لا نكشف أخطاء داخلية للمستخدم
ini_set('log_errors', 1);
date_default_timezone_set('UTC');

// Security middleware يجب أن يُحمَّل أولاً
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
