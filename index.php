<?php
// ── Static file pass-through ──────────────────────────────────────────────────
$__path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$__file = __DIR__ . $__path;

// حماية: منع الوصول المباشر إلى الملفات والمجلدات الحساسة
$__blocked_dirs = [
    '/data/', '/includes/', '/.local/', '/.agents/',
    '/vendor/', '/node_modules/', '/extractor/', '/videasy/',
    '/.cache/', '/.git/',
];
$__blocked_exact = [
    '/config.php', '/router.php', '/.replit', '/replit.nix',
    '/php.ini', '/replit.md', '/composer.json', '/composer.lock',
];
// حجب الملفات الأرشيفية والحساسة حسب الامتداد
$__blocked_exts = ['.zip', '.tar', '.gz', '.sql', '.env', '.bak', '.log', '.json'];
$__path_ext     = strtolower(pathinfo($__path, PATHINFO_EXTENSION));

foreach ($__blocked_dirs as $__pfx) {
    if (str_starts_with($__path, $__pfx)) {
        http_response_code(403);
        exit('Forbidden');
    }
}
if (in_array($__path, $__blocked_exact, true)) {
    http_response_code(403);
    exit('Forbidden');
}
// حجب الملفات ذات الامتدادات الحساسة من الجذر
if ($__path_ext && in_array('.' . $__path_ext, $__blocked_exts, true)) {
    http_response_code(403);
    exit('Forbidden');
}

if ($__path !== '/' && is_file($__file)) {
    // ── مهم: لا تُقدِّم ملفات API/pages/includes مباشرة ──────────────────────
    // يجب أن تمر جميعها عبر الـ Router لتطبيق Security Middleware الكامل.
    // إذا خدمها الـ PHP server مباشرة فإن جميع فحوصات الأمان تُتجاوَز!
    $__force_route = false;
    foreach (['/api/', '/pages/', '/includes/'] as $__forced_dir) {
        if (str_starts_with($__path, $__forced_dir)) {
            $__force_route = true;
            break;
        }
    }
    if (!$__force_route) {
        return false; // PHP built-in server يُقدّم الملفات الثابتة (CSS, JS, images) مباشرة
    }
    // API/pages/includes → تكمل إلى الـ Router
}

require_once __DIR__ . '/config.php';
// security.php مُحمَّل عبر config.php

// ── Security middleware ───────────────────────────────────────────────────────
sec_send_headers();
sec_session_guard();

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = trim($uri, '/');
$seg  = $path === '' ? [''] : explode('/', $path);

$ip = get_client_ip();

// ── Bot Detection على API Endpoints ──────────────────────────────────────────
if ($seg[0] === 'api') {
    sec_check_bot(70); // طلبات الـ API أكثر حساسية
}

// ── Progressive Block Check ───────────────────────────────────────────────────
// نتحقق إذا كان الـ IP أو الجلسة محظورة قبل معالجة أي طلب API
if ($seg[0] === 'api') {
    if (!sec_block_check("ip:{$ip}")) {
        http_response_code(429);
        header('Content-Type: application/json');
        sec_log('BLOCKED_REQUEST', ['ip_hash' => substr(hash('sha256', $ip), 0, 8)]);
        echo json_encode(['ok' => false, 'error' => 'Temporarily blocked. Try again later.', 'code' => 429]);
        exit;
    }
}

// ── Rate Limiting بحسب نوع الطلب ─────────────────────────────────────────────
if ($seg[0] === 'login' || $seg[0] === 'register') {
    // Auth: 12 محاولة كل 10 دقائق لكل IP
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!sec_rate_limit("auth:{$ip}", 12, 600)) {
            // تطبيق حظر تصاعدي على محاولات auth الزائدة
            sec_block_set("ip:{$ip}");
            http_response_code(429);
            $err_msg = 'محاولات كثيرة. انتظر دقائق وحاول مجدداً.';
            $page_title = 'خطأ';
            require __DIR__ . '/includes/header.php';
            echo '<div style="text-align:center;padding:80px 20px;color:#ff6b6b;font-size:1.2rem">'
               . htmlspecialchars($err_msg) . '</div>';
            require __DIR__ . '/includes/footer.php';
            exit;
        }
    }
} elseif ($seg[0] === 'api') {
    $rl_seg1 = preg_replace('/[^a-z0-9_-]/i', '', $seg[1] ?? '');
    $rl_seg2 = $seg[2] ?? null;

    if ($rl_seg1 === 'search' || $rl_seg1 === 'anime-lookup') {
        // بحث: 60 طلب / دقيقة لكل IP + 120 / دقيقة لكل جلسة
        if (!sec_rate_limit("search:{$ip}", 60, 60)) {
            sec_block_set("ip:{$ip}");
            sec_rate_block($uri);
        }
        $sid = session_id();
        if ($sid && !sec_rate_limit("search_sess:{$sid}", 120, 60)) sec_rate_block($uri);

    } elseif ($rl_seg1 === 'subtitles') {
        // ترجمات: 40 طلب / دقيقة
        if (!sec_rate_limit("subs:{$ip}", 40, 60)) {
            sec_block_set("ip:{$ip}");
            sec_rate_block($uri);
        }
    } elseif ($rl_seg2 === 'index.php' || $rl_seg2 === null) {
        // Stream providers: 35 طلب / دقيقة لكل IP + 60 / دقيقة لكل جلسة
        if (!sec_rate_limit("stream:{$ip}", 35, 60)) {
            sec_block_set("ip:{$ip}");
            sec_rate_block($uri);
        }
        $sid = session_id();
        if ($sid && !sec_rate_limit("stream_sess:{$sid}", 60, 60)) sec_rate_block($uri);

    } else {
        // باقي API (proxies، إلخ): 120 طلب / دقيقة
        if (!sec_rate_limit("api:{$ip}", 120, 60)) {
            sec_block_set("ip:{$ip}");
            sec_rate_block($uri);
        }
    }
}

// ── Router ────────────────────────────────────────────────────────────────────
switch ($seg[0]) {
    case '':
    case 'home':
        require __DIR__ . '/pages/home.php';
        break;

    case 'movies':
        $_GET['type'] = 'movie';
        require __DIR__ . '/pages/browse.php';
        break;

    case 'tv':
        $_GET['type'] = 'tv';
        require __DIR__ . '/pages/browse.php';
        break;

    case 'anime':
        require __DIR__ . '/pages/anime.php';
        break;

    case 'anime-watch':
        header('Location: /', true, 302);
        exit;

    case 'explore':
        require __DIR__ . '/pages/explore.php';
        break;

    case 'saved':
        require __DIR__ . '/pages/saved.php';
        break;

    case 'movie':
    case 'tv-show':
        $type = $seg[0] === 'movie' ? 'movie' : 'tv';
        $slug = $seg[1] ?? '';
        $id   = (int) explode('-', $slug)[0];
        if (!$id) { header('Location: /'); exit; }
        $_GET['type'] = $type;
        $_GET['id']   = $id;
        require __DIR__ . '/pages/details.php';
        break;

    case 'watch':
        $type = $seg[1] ?? '';
        if ($type === 'anime') {
            $anime_slug = $seg[2] ?? '';
            if (preg_match('/^ak-(.+)-\d+$/', $anime_slug, $m)) {
                $q = urlencode(str_replace('-', ' ', $m[1]));
                header('Location: /search?q=' . $q, true, 302);
            } else { header('Location: /anime', true, 302); }
            exit;
        }
        $slug = $seg[2] ?? '';
        $id   = (int) explode('-', $slug)[0];
        if (!in_array($type, ['movie', 'tv'], true) || !$id) { header('Location: /'); exit; }
        $_GET['type']    = $type;
        $_GET['id']      = $id;
        $_GET['season']  = isset($seg[3]) ? (int) $seg[3] : null;
        $_GET['episode'] = isset($seg[4]) ? (int) $seg[4] : null;
        require __DIR__ . '/pages/watch.php';
        break;

    case 'reels':
        require __DIR__ . '/pages/reels.php';
        break;

    case 'drama-short':
        // /drama-short                             → browse
        // /drama-short/{provider}/{book_id}        → detail
        // /drama-short/watch/{provider}/{book_id}/{episode} → watch
        if (!isset($seg[1]) || $seg[1] === '') {
            require __DIR__ . '/pages/drama-short.php';
        } elseif ($seg[1] === 'watch') {
            $_GET['provider'] = preg_replace('/[^a-z0-9_-]/i', '', $seg[2] ?? '');
            $_GET['book_id']  = (int)($seg[3] ?? 0);
            $_GET['episode']  = max(1, (int)($seg[4] ?? 1));
            require __DIR__ . '/pages/drama-short-watch.php';
        } else {
            $_GET['provider'] = preg_replace('/[^a-z0-9_-]/i', '', $seg[1] ?? '');
            $_GET['book_id']  = (int)($seg[2] ?? 0);
            require __DIR__ . '/pages/drama-short-detail.php';
        }
        break;

    case 'login':
        require __DIR__ . '/pages/login.php';
        break;

    case 'register':
        require __DIR__ . '/pages/register.php';
        break;

    case 'logout':
        auth_logout();
        header('Location: /');
        exit;

    case 'profile':
        require __DIR__ . '/pages/profile.php';
        break;

    case 'search':
        require __DIR__ . '/pages/search.php';
        break;

    case 'api':
        // ── Segment Sanitization ──────────────────────────────────────────────
        // $apiSeg2 يُحدَّد أولاً لأن $apiSeg1 يعتمد على وجوده من عدمه
        $apiSeg2 = isset($seg[2]) ? preg_replace('/[^a-z0-9_.-]/i', '', $seg[2]) : null;

        if ($apiSeg2 === null) {
            // مسار جذري مثل /api/torrentio.php — نسمح بالنقطة في اسم الملف
            $apiSeg1 = preg_replace('/[^a-z0-9_.-]/i', '', $seg[1] ?? 'index');
            $apiSeg1 = str_replace('..', '', $apiSeg1); // منع path traversal
        } else {
            // مسار مجلد مثل /api/vidsrc/index.php — نُمنع النقطة في اسم المجلد
            $apiSeg1 = preg_replace('/[^a-z0-9_-]/i', '', $seg[1] ?? 'index');
        }

        // ── Same-Origin Check لجميع API endpoints ────────────────────────────
        // نرفض الطلبات التي تأتي من نطاق مختلف (Cross-Origin scraping)
        // استثناء: طلبات بدون Origin/Referer (مثل PHP internal أو server-side proxies)
        if (!sec_is_same_origin()) {
            sec_log('CROSS_ORIGIN_API', [
                'provider' => $apiSeg1,
                'origin'   => substr($_SERVER['HTTP_ORIGIN'] ?? '', 0, 80),
            ]);
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Access denied.', 'code' => 403]);
            exit;
        }

        // ── Stream Authentication ─────────────────────────────────────────────
        // محلّلات البث تحتاج: stream token + nonce + timestamp (جميعها إلزامية)
        //
        // النمطان المحميان:
        //   1. /api/{provider}/index.php  (ما عدا subtitles)
        //   2. /api/torrentio.php         (المحلّل الوحيد على root-level في SERVER_LIST)
        $is_dir_resolver  = ($apiSeg2 === 'index.php' && $apiSeg1 !== 'subtitles');
        $is_root_resolver = ($apiSeg2 === null && $apiSeg1 === 'torrentio.php');
        $needs_stream_auth = ($is_dir_resolver || $is_root_resolver);

        if ($needs_stream_auth) {
            $st        = $_GET['_st']    ?? '';
            $req_nonce = $_GET['_nonce'] ?? '';
            $req_ts    = (int)($_GET['_ts'] ?? 0);
            $req_type  = $_GET['type']   ?? '';
            $req_id    = (int)($_GET['id'] ?? 0);

            // ── Nonce + Timestamp إلزاميان — رفض الطلبات بدونهما ────────────
            if (!$req_nonce || !$req_ts) {
                sec_log('MISSING_SECURITY_PARAMS', [
                    'provider'  => $apiSeg1,
                    'has_nonce' => !empty($req_nonce) ? 'yes' : 'no',
                    'has_ts'    => $req_ts > 0 ? 'yes' : 'no',
                ]);
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Access denied. Please reload the watch page.',
                    'code'  => 403,
                ]);
                exit;
            }

            // ── Timestamp Validation — رفض الطلبات الأقدم من 5 دقائق ─────────
            if (!sec_validate_timestamp($req_ts, 300)) {
                sec_log('TIMESTAMP_EXPIRED', [
                    'provider' => $apiSeg1,
                    'age_sec'  => abs(time() - $req_ts),
                ]);
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Request expired. Please reload the watch page.',
                    'code'  => 403,
                ]);
                exit;
            }

            // ── Nonce Validation — منع Replay Attacks ────────────────────────
            if (!sec_nonce_consume($req_nonce)) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Duplicate request detected. Please reload.',
                    'code'  => 403,
                ]);
                exit;
            }

            // ── Stream Token Validation ───────────────────────────────────────
            if (!sec_verify_stream_token($st, $req_type, $req_id)) {
                sec_log('TOKEN_INVALID', [
                    'provider'  => $apiSeg1,
                    'has_token' => !empty($st) ? 'yes' : 'no',
                ]);
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Access denied. Please reload the watch page.',
                    'code'  => 403,
                ]);
                exit;
            }
        }

        // ── File Resolution ───────────────────────────────────────────────────
        if ($apiSeg2 !== null) {
            $apiFile = __DIR__ . '/api/' . $apiSeg1 . '/' . $apiSeg2;
        } elseif (str_ends_with(strtolower($apiSeg1), '.php')) {
            // $apiSeg1 يحمل الامتداد مسبقاً (مثل reels.php, torrentio.php)
            $apiFile = __DIR__ . '/api/' . $apiSeg1;
        } else {
            $apiFile = __DIR__ . '/api/' . $apiSeg1 . '.php';
        }

        if (is_file($apiFile)) {
            require $apiFile;
        } else {
            sec_log('API_NOT_FOUND', ['seg1' => $apiSeg1, 'seg2' => $apiSeg2 ?? '']);
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'unknown api']);
        }
        break;

    default:
        http_response_code(404);
        require __DIR__ . '/pages/404.php';
}
