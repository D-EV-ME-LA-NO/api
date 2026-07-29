<?php
/**
 * api/aether/proxy.php — Secure proxy for Aether CDN (aether.bar / aether.cx)
 *
 * Two validation modes:
 *   strict — only *.aether.bar / *.aether.cx (initial entry-point)
 *   cdn    — any non-private public host (rewritten CDN segment/playlist URLs)
 *
 * The 'cdn' mode is activated by an HMAC-signed 'sig' parameter that this
 * script itself generates when rewriting m3u8 URIs — external callers
 * cannot forge it without knowing CDN_PROXY_SECRET.
 *
 * Handles:
 *   • Master/variant m3u8 — rewrites all URIs through self
 *   • Binary segments    — streamed directly back with Range support
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── Internal signing secret (never changes; not a user secret) ────────────────
if (!defined('CDN_PROXY_SECRET')) {
    define('CDN_PROXY_SECRET', 'ae-proxy-internal-v1');
}

function ae_cdn_sign(string $url): string {
    return substr(hash_hmac('sha256', $url, CDN_PROXY_SECRET), 0, 16);
}

function ae_cdn_verify(string $url, string $sig): bool {
    return hash_equals(ae_cdn_sign($url), $sig);
}

// ── Allowlist / validation ────────────────────────────────────────────────────
function ae_is_aether(string $host): bool {
    $h = strtolower($host);
    return str_ends_with($h, '.aether.bar')
        || str_ends_with($h, '.aether.cx')
        || $h === 'aether.bar'
        || $h === 'aether.cx';
}

function ae_is_private(string $host): bool {
    $h = strtolower($host);
    return (bool)(
        preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.|::1)/i', $h)
     || preg_match('/^(localhost|metadata\.google)/i', $h)
    );
}

/**
 * Validate $url.
 * $mode = 'strict' → must be aether host.
 * $mode = 'cdn'    → any non-private host (caller must already have verified sig).
 */
function ae_validate(string $url, string $mode): ?string {
    if (!preg_match('#^https?://#i', $url)) return 'scheme not allowed';
    $p = parse_url($url);
    if (!$p || empty($p['host']))            return 'unparseable url';
    $h = strtolower($p['host']);
    if (ae_is_private($h))                   return 'private host blocked';
    if ($mode === 'strict' && !ae_is_aether($h)) return 'host not in allowlist: ' . $h;
    return null;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$url  = $_GET['url'] ?? '';
$from = $_GET['from'] ?? 'strict';
$sig  = $_GET['sig']  ?? '';
// ao/ar: custom Origin/Referer لـ CDN mode (مثل gallic الذي يحتاج origin مختلف)
$ao   = $_GET['ao']   ?? '';   // custom Origin  (CDN mode فقط)
$ar   = $_GET['ar']   ?? '';   // custom Referer (CDN mode فقط)

if (!$url) { http_response_code(400); exit('missing url'); }

// Determine mode: cdn only if signature matches
if ($from === 'cdn' && ae_cdn_verify($url, $sig)) {
    $mode = 'cdn';
} else {
    $mode   = 'strict';
    $ao = $ar = '';   // لا نقبل custom headers إلا في CDN mode موقّع
}

if ($err = ae_validate($url, $mode)) { http_response_code(403); exit('blocked: ' . $err); }

// ── Fetch ─────────────────────────────────────────────────────────────────────
$body = ae_fetch($url, $mode, 0, $final_url, $ct_raw, $resp_hdrs, $ao, $ar);
if ($body === null) { http_response_code(502); exit('upstream error'); }

// ── Content-type detection ────────────────────────────────────────────────────
$ct      = strtolower((string)$ct_raw);
$is_m3u8 = str_contains($ct, 'mpegurl')
        || str_contains(strtolower($url), '.m3u8')
        || str_contains(strtolower($final_url ?? ''), '.m3u8')
        || str_contains(strtolower($url), '.txt')        // riverstonecreativehub-style
        || str_starts_with(ltrim($body), '#EXTM3U');

header('Access-Control-Allow-Origin: *');

// ── M3U8: rewrite all URIs through self ───────────────────────────────────────
if ($is_m3u8) {
    $pu     = parse_url($final_url ?: $url);
    $scheme = $pu['scheme'] ?? 'https';
    $host   = $pu['host']   ?? '';
    $port   = isset($pu['port']) ? ':' . $pu['port'] : '';
    $origin = $scheme . '://' . $host . $port;
    $dir    = rtrim(dirname($pu['path'] ?? '/'), '/') . '/';
    $base   = $origin . $dir;

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');

    $self  = '/api/aether/proxy.php';
    $lines = explode("\n", $body);
    $out   = [];

    foreach ($lines as $raw_line) {
        $line = rtrim($raw_line);
        if ($line === '') { $out[] = ''; continue; }

        if (str_starts_with($line, '#')) {
            $line = preg_replace_callback(
                '/\bURI="([^"]+)"/i',
                fn($m) => 'URI="' . ae_proxy_uri($m[1], $scheme, $origin, $base, $self, $ao, $ar) . '"',
                $line
            );
            $out[] = $line;
            continue;
        }

        $out[] = ae_proxy_uri($line, $scheme, $origin, $base, $self, $ao, $ar);
    }

    echo implode("\n", $out);
    exit;
}

// ── Binary segment: stream back ───────────────────────────────────────────────
foreach (['Content-Type', 'Content-Length', 'Content-Range', 'Accept-Ranges'] as $h) {
    if (preg_match('/^' . preg_quote($h, '/') . ':\s*(.+)$/im', $resp_hdrs, $m)) {
        header($h . ': ' . trim($m[1]));
    }
}
if (preg_match('/^HTTP\/[12]\S*\s+206/m', $resp_hdrs)) http_response_code(206);
echo $body;

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Resolve a URI and wrap it through this proxy with appropriate mode+sig. */
function ae_proxy_uri(string $uri, string $scheme, string $origin, string $base, string $self, string $ao = '', string $ar = ''): string
{
    // Resolve to absolute URL
    if (preg_match('#^https?://#i', $uri)) {
        $abs = $uri;
    } elseif (str_starts_with($uri, '//')) {
        $abs = $scheme . ':' . $uri;
    } elseif (str_starts_with($uri, '/')) {
        $abs = $origin . $uri;
    } else {
        $abs = $base . $uri;
    }

    $p    = parse_url($abs);
    $host = strtolower($p['host'] ?? '');

    if (ae_is_private($host)) return $uri;   // never proxy private IPs

    if (ae_is_aether($host)) {
        // Aether host — strict mode, no signature needed
        return $self . '?url=' . urlencode($abs);
    }

    // CDN host — sign the URL so cdn mode is granted, propagate custom
    // Origin/Referer (e.g. gallic's non-aether CDN) to nested playlist/segment URLs.
    $sig = ae_cdn_sign($abs);
    $out = $self . '?url=' . urlencode($abs) . '&from=cdn&sig=' . $sig;
    if ($ao !== '') $out .= '&ao=' . urlencode($ao);
    if ($ar !== '') $out .= '&ar=' . urlencode($ar);
    return $out;
}

/**
 * Fetch URL, following redirects manually.
 * $mode propagates through all redirect hops.
 */
function ae_fetch(
    string  $url,
    string  $mode,
    int     $depth,
    ?string &$final_url,
    ?string &$ct,
    ?string &$hdrs,
    string  $ao = '',
    string  $ar = ''
): ?string {
    if ($depth > 6) return null;

    $req_hdrs = [
        'Accept: */*',
        'Origin: '  . ($ao ?: 'https://aether.bar'),
        'Referer: ' . ($ar ?: 'https://aether.bar/'),
        'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
    ];
    if (!empty($_SERVER['HTTP_RANGE'])) $req_hdrs[] = 'Range: ' . $_SERVER['HTTP_RANGE'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => $req_hdrs,
    ]);

    $raw      = curl_exec($ch);
    $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdr_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $ct       = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if (!$raw) return null;

    $hdrs = substr($raw, 0, $hdr_size);
    $body = substr($raw, $hdr_size);

    // Follow redirects — propagate $mode unchanged
    if ($code >= 301 && $code <= 308) {
        if (!preg_match('/^location:\s*(.+)$/im', $hdrs, $m)) return null;
        $location = trim($m[1]);

        // RFC 3986-compliant resolution
        $location = ae_resolve_uri($url, $location);
        if (!$location) return null;

        // Validate the redirect destination under the same mode
        if ($err = ae_validate($location, $mode)) return null;

        return ae_fetch($location, $mode, $depth + 1, $final_url, $ct, $hdrs, $ao, $ar);
    }

    if ($code >= 400) return null;

    $final_url = $url;
    return $body;
}

/**
 * RFC 3986 URI resolution: resolve $ref relative to $base.
 * Returns null on failure.
 */
function ae_resolve_uri(string $base, string $ref): ?string
{
    // Already absolute
    if (preg_match('#^https?://#i', $ref)) return $ref;

    $b = parse_url($base);
    if (!$b || empty($b['host'])) return null;

    $scheme = $b['scheme'] ?? 'https';
    $auth   = $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

    // Protocol-relative
    if (str_starts_with($ref, '//')) return $scheme . ':' . $ref;

    // Root-relative
    if (str_starts_with($ref, '/')) return $scheme . '://' . $auth . $ref;

    // Relative path — merge with base path
    $base_path = $b['path'] ?? '/';
    $dir       = rtrim(dirname($base_path), '/') . '/';
    $merged    = $dir . $ref;

    // Normalize dot segments
    $parts  = explode('/', $merged);
    $result = [];
    foreach ($parts as $seg) {
        if ($seg === '..') { array_pop($result); }
        elseif ($seg !== '.') { $result[] = $seg; }
    }

    return $scheme . '://' . $auth . implode('/', $result);
}
