<?php
/**
 * api/aoneroom/index.php — MovieBox (aoneroom) resolver
 *
 * 1. يجلب عنوان + سنة المحتوى من TMDB
 * 2. يبحث عنه في h5-api.aoneroom.com
 * 3. يطابق أفضل نتيجة (عنوان + سنة + نوع)
 * 4. يجلب روابط البث من themoviebox.xyz
 * 5. يُرجع { ok, servers: [{id, name, streams}] }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config.php';

define('AR_UA',     'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36');
define('AR_ORIGIN', 'https://themoviebox.xyz');

// ── معاملات الطلب ─────────────────────────────────────────────────────────────
$type    = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
$id      = (int)($_GET['id'] ?? 0);
$season  = isset($_GET['season'])  ? (int)$_GET['season']  : 1;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 1;

if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

// ── 1. TMDB metadata ──────────────────────────────────────────────────────────
$meta = ar_tmdb_meta($type, $id);
if (!$meta || !$meta['title']) {
    echo json_encode(['ok' => false, 'error' => 'TMDB lookup failed']);
    exit;
}

// ── 2. Auth token ─────────────────────────────────────────────────────────────
$token = ar_get_token();
if (!$token) {
    echo json_encode(['ok' => false, 'error' => 'no auth token']);
    exit;
}

// ── 3. Search ─────────────────────────────────────────────────────────────────
// subjectType: 1=Movie, 2=Series
$subjectType = $type === 'tv' ? 2 : 1;
$items = ar_search($meta['title'], $token, $subjectType);

// إذا فشل البحث بالنوع، جرّب All
if (!$items) {
    $items = ar_search($meta['title'], $token, 0);
}

if (!$items) {
    echo json_encode(['ok' => false, 'error' => 'search returned no results']);
    exit;
}

// ── 4. Match ──────────────────────────────────────────────────────────────────
$match = ar_best_match($items, $meta['title'], $meta['year'], $subjectType);
if (!$match) {
    echo json_encode(['ok' => false, 'error' => 'no matching title found']);
    exit;
}

// ── 5. Play URLs ──────────────────────────────────────────────────────────────
// للأفلام: se=0&ep=0 | للمسلسلات: se=<season>&ep=<episode>
$se = $type === 'tv' ? $season  : 0;
$ep = $type === 'tv' ? $episode : 0;

$streams = ar_get_streams($match['subjectId'], $match['detailPath'], $se, $ep, $token);
if (!$streams) {
    echo json_encode(['ok' => false, 'error' => 'no streams available', 'servers' => []]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'servers' => [[
        'id'      => 'moviebox',
        'name'    => 'MovieBox',
        'streams' => $streams,
    ]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ═════════════════════════════════════════════════════════════════════════════
// Helper functions
// ═════════════════════════════════════════════════════════════════════════════

function ar_tmdb_meta(string $type, int $id): ?array
{
    $key = defined('TMDB_API_KEY') ? TMDB_API_KEY : '';
    if (!$key) return null;

    $url = 'https://api.themoviedb.org/3/' . $type . '/' . $id . '?api_key=' . $key;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $j = json_decode((string)$body, true);
    if (!is_array($j)) return null;

    $title = $j['title'] ?? $j['name'] ?? '';
    $date  = $j['release_date'] ?? $j['first_air_date'] ?? '';
    $year  = $date ? (int)substr($date, 0, 4) : 0;

    return ['title' => $title, 'year' => $year];
}

// ── Token management ──────────────────────────────────────────────────────────
function ar_get_token(): string
{
    $cacheFile = sys_get_temp_dir() . '/aoneroom_token.json';

    // قراءة الكاش
    if (file_exists($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (!empty($c['token']) && isset($c['exp']) && $c['exp'] > time() + 3600) {
            return $c['token'];
        }
    }

    // محاولة 1: تسجيل مجهول (guest) من themoviebox.xyz
    $token = ar_fetch_guest_token();

    // محاولة 2: fallback للتوكن المحفوظ في الملف (يمتد لأشهر)
    if (!$token) {
        $fallback = ar_hardcoded_token();
        if ($fallback) $token = $fallback;
    }

    // حفظ في الكاش
    if ($token) {
        $exp  = ar_token_exp($token);
        file_put_contents($cacheFile, json_encode(['token' => $token, 'exp' => $exp]));
    }

    return $token ?? '';
}

function ar_fetch_guest_token(): string
{
    // POST إلى نقطة التسجيل المجهول
    $endpoints = [
        'https://h5-api.aoneroom.com/wefeed-h5api-bff/user/register',
        'https://themoviebox.xyz/wefeed-h5api-bff/user/register',
    ];

    foreach ($endpoints as $url) {
        $payload = json_encode(['atp' => 3]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: ' . AR_UA,
                'Origin: ' . AR_ORIGIN,
                'Referer: ' . AR_ORIGIN . '/',
                'x-client-info: {"timezone":"Asia/Baghdad"}',
                'x-request-lang: en',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $body) {
            $d = json_decode($body, true);
            $t = $d['data']['token'] ?? $d['data']['jwt'] ?? $d['token'] ?? '';
            if ($t) return $t;
        }
    }
    return '';
}

function ar_hardcoded_token(): string
{
    // توكن مدته ~90 يوم من تاريخ الالتقاط — يُستبدل تلقائياً لو نجحت الطرق الأخرى
    $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'
           . '.eyJ1aWQiOjYxMTA1MDUxNzI1NTE1ODc4MTYsImF0cCI6MywiZXh0IjoiMTc4MzU3MDAyMSIsImV4cCI6MTc5MTM0NjAyMSwiaWF0IjoxNzgzNTY5NzIxfQ'
           . '.F6Tq1sCde0n2KTVqbSNRjOWDQPT3PvsSA4mPKLpuEkM';
    $exp = ar_token_exp($token);
    return ($exp > time() + 3600) ? $token : '';
}

function ar_token_exp(string $token): int
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return time() + 3600;
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    return (int)($payload['exp'] ?? (time() + 90 * 86400));
}

// ── Search ────────────────────────────────────────────────────────────────────
function ar_search(string $title, string $token, int $subjectType = 0): ?array
{
    $payload = json_encode([
        'keyword'     => $title,
        'page'        => 1,
        'perPage'     => 0,
        'subjectType' => $subjectType,
    ]);

    $ch = curl_init('https://h5-api.aoneroom.com/wefeed-h5api-bff/subject/search');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: ' . AR_UA,
            'Origin: ' . AR_ORIGIN,
            'Referer: ' . AR_ORIGIN . '/web/searchResult?keyword=' . urlencode($title),
            'x-client-info: {"timezone":"Asia/Baghdad"}',
            'x-request-lang: en',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) return null;
    $j = json_decode($body, true);
    if (($j['code'] ?? -1) !== 0) return null;
    return $j['data']['items'] ?? null;
}

// ── Match ─────────────────────────────────────────────────────────────────────
function ar_best_match(array $items, string $title, int $year, int $subjectType): ?array
{
    $needle = strtolower(trim($title));
    $best   = null;
    $bestScore = -999;

    foreach ($items as $item) {
        // فلتر النوع — إذا كان محدداً
        if ($subjectType > 0 && ($item['subjectType'] ?? 0) !== $subjectType) continue;
        // يجب أن يحتوي مورد قابل للتشغيل
        if (!($item['hasResource'] ?? false)) continue;

        $itemTitle = strtolower(trim($item['title'] ?? ''));
        $itemYear  = (int)substr($item['releaseDate'] ?? '0', 0, 4);

        // حساب تشابه العنوان
        if ($itemTitle === $needle) {
            $titleScore = 100;
        } elseif (str_contains($itemTitle, $needle) || str_contains($needle, $itemTitle)) {
            $titleScore = 60;
        } else {
            similar_text($needle, $itemTitle, $pct);
            if ($pct < 55) continue; // بعيد جداً
            $titleScore = (int)$pct;
        }

        // حساب تطابق السنة
        $yearScore = 0;
        if ($year > 0 && $itemYear > 0) {
            $diff = abs($itemYear - $year);
            if ($diff === 0)      $yearScore = 40;
            elseif ($diff === 1)  $yearScore = 15;
            elseif ($diff <= 2)   $yearScore = 5;
            else                   $yearScore = -30;
        }

        // مكافأة التطابق التام في النوع
        $typeBonus = ($subjectType > 0 && ($item['subjectType'] ?? 0) === $subjectType) ? 20 : 0;

        $score = $titleScore + $yearScore + $typeBonus;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best      = $item;
        }
    }

    return $best;
}

// ── Play ──────────────────────────────────────────────────────────────────────
function ar_get_streams(string $subjectId, string $detailPath, int $se, int $ep, string $token): ?array
{
    $url = 'https://themoviebox.xyz/wefeed-h5api-bff/subject/play'
         . '?subjectId=' . urlencode($subjectId)
         . '&se=' . $se
         . '&ep=' . $ep
         . '&detailPath=' . urlencode($detailPath)
         . '&streamSignType=1';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: ' . AR_UA,
            'sec-fetch-site: same-origin',
            'sec-fetch-mode: cors',
            'sec-fetch-dest: empty',
            'Referer: ' . AR_ORIGIN . '/movies/' . $detailPath,
            'x-client-info: {"timezone":"Asia/Baghdad"}',
            // التوكن يُرسل ككوكي (same-origin) وكـ Authorization للتأكيد
            'Cookie: token=' . $token . '; i18n_lang=en',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) return null;
    $j = json_decode($body, true);
    if (($j['code'] ?? -1) !== 0) return null;

    $rawStreams = $j['data']['streams'] ?? [];
    if (!$rawStreams) return null;

    // ترتيب تنازلي حسب الدقة
    $resOrder = ['1080' => 0, '720' => 1, '480' => 2, '360' => 3, '240' => 4];
    usort($rawStreams, static function ($a, $b) use ($resOrder) {
        $ra = $resOrder[$a['resolutions'] ?? ''] ?? 99;
        $rb = $resOrder[$b['resolutions'] ?? ''] ?? 99;
        return $ra <=> $rb;
    });

    $streams = [];
    foreach ($rawStreams as $s) {
        if (empty($s['url']) || ($s['vipLocked'] ?? false)) continue;

        $res   = $s['resolutions'] ?? '';
        $label = $res ? $res . 'p' : ($s['format'] ?? 'MP4');

        // لف الرابط عبر بروكسي MovieBox المخصص (origin/referer صحيحة + If-Range)
        $proxied = '/api/aoneroom/proxy.php?url=' . urlencode($s['url']);

        $streams[] = [
            'label' => 'MovieBox · ' . $label,
            'url'   => $proxied,
            'type'  => 'mp4',
        ];
    }

    return $streams ?: null;
}
