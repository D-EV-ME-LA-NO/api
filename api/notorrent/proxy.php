<?php
// api/notorrent/proxy.php
// Secure M3U8 / TS proxy for NoTorrent streams.
// - Only forwards requests to an allowlisted set of CDN / streaming hostnames
// - Blocks private/loopback/link-local IP ranges (SSRF guard)
// - Rewrites ALL HLS segment and tag URIs (including EXT-X-KEY, EXT-X-MAP)
// - Carries the hdntl auth cookie across all hops

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── Security: allowlisted hostname patterns ────────────────────────────────────
// Each entry is a regex fragment matched against the lowercased hostname.
const NT_ALLOWED_HOSTS = [
    // NoTorrent redirect workers
    '\.notorrent2\.workers\.dev$',
    // Generic workers.dev CDN proxies used by notorrent
    '\.workers\.dev$',
    // Hostinger-hosted m3u8 servers
    '\.hostingersite\.com$',
    // 321moviesfree stream CDN
    '\.321moviesfree\.com$',
    '321moviesfree\.com$',
    // TS segment CDN
    '\.pkcdn\.org$',
    // aqua-vulture / scalable segment hosts
    'scalableimpactgroup\.site$',
    'aqua-vulture',
    // Rotating "content" CDN hostnames aqua-vulture's vid1.php redirects to
    // (segments served as .html pages with a token query param; require
    // Accept-Encoding: gzip below or the CDN blocks/returns bogus bytes)
    'mindfulwealthjourney\.site$',
    // Generic streaming CDNs that appear in segments
    '\.akamaized\.net$',
    '\.cloudflare\.com$',
];

function nt_is_allowed_host(string $hostname): bool {
    $h = strtolower(trim($hostname));
    foreach (NT_ALLOWED_HOSTS as $pattern) {
        if (preg_match('/' . $pattern . '/i', $h)) return true;
    }
    return false;
}

function nt_is_private_ip(string $hostname): bool {
    // Block if hostname resolves to a private / loopback / link-local address.
    // We do a quick syntactic check before any DNS lookup.
    if (preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.|::1|fc|fd)/i', $hostname)) {
        return true;
    }
    // Also block bare hostnames that look like internal names
    if (preg_match('/^(localhost|metadata\.google|169\.254|instance-data)/i', $hostname)) {
        return true;
    }
    return false;
}

function nt_validate_url(string $url): ?string {
    if (!preg_match('#^https?://#i', $url)) return 'scheme not allowed';
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return 'unparseable url';
    $host = strtolower($p['host']);
    if (nt_is_private_ip($host)) return 'private host blocked';
    if (!nt_is_allowed_host($host)) return 'host not in allowlist: ' . $host;
    return null;
}

// ── Inputs ─────────────────────────────────────────────────────────────────────
$url   = $_GET['url']   ?? '';
$hdntl = $_GET['hdntl'] ?? '';

if (!$url) { http_response_code(400); exit('missing url'); }
if ($err = nt_validate_url($url)) { http_response_code(403); exit('blocked: ' . $err); }

// ── Fetch ──────────────────────────────────────────────────────────────────────
$ua = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';

$req_headers = [
    'User-Agent: '   . $ua,
    'Connection: Keep-Alive',
    'Accept-Encoding: gzip',
];
if ($hdntl) $req_headers[] = 'Cookie: hdntl=' . $hdntl;
if (!empty($_SERVER['HTTP_RANGE'])) $req_headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    // NOTE: some segment CDNs (e.g. mindfulwealthjourney.site) only return real
    // TS data when the client explicitly accepts gzip — without it they either
    // block (403) or return a tiny bogus payload. CURLOPT_ENCODING => 'gzip'
    // both sends the header and auto-decompresses the response for us.
    CURLOPT_ENCODING       => 'gzip',
    CURLOPT_HTTPHEADER     => $req_headers,
]);

$raw       = curl_exec($ch);
$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$hdr_size  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$ct        = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if (!$raw || $http_code >= 400) {
    http_response_code($http_code ?: 502);
    exit('upstream error: ' . $http_code);
}

$headers_raw = substr($raw, 0, $hdr_size);
$body        = substr($raw, $hdr_size);

// ── Extract hdntl cookie — capture LAST occurrence across all redirect hops ───
$new_hdntl = $hdntl;
if (preg_match_all('/set-cookie:\s*hdntl=([^;\r\n]+)/i', $headers_raw, $matches)) {
    $last_val  = trim(end($matches[1]));
    if ($last_val) $new_hdntl = $last_val;
}

// ── Content-type detection ─────────────────────────────────────────────────────
$ct_lower = strtolower($ct);
$is_m3u8  = str_contains($ct_lower, 'mpegurl')
         || str_contains(strtolower($url), '.m3u8')
         || str_contains(strtolower($final_url), '.m3u8')
         || str_starts_with(ltrim($body), '#EXTM3U');

header('Access-Control-Allow-Origin: *');

// ── M3U8: rewrite ALL segment and tag URIs through this proxy ─────────────────
if ($is_m3u8) {
    // Resolve base URL from the FINAL (post-redirect) URL
    $purl   = parse_url($final_url ?: $url);
    $scheme = $purl['scheme'] ?? 'https';
    $host   = $purl['host']   ?? '';
    $port   = isset($purl['port']) ? ':' . $purl['port'] : '';
    $origin = $scheme . '://' . $host . $port;
    $dir    = rtrim(dirname($purl['path'] ?? '/'), '/') . '/';
    $base   = $origin . $dir;

    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache');

    $self  = '/api/notorrent/proxy.php';
    $lines = explode("\n", $body);
    $out   = [];

    foreach ($lines as $raw_line) {
        $line = rtrim($raw_line);

        if ($line === '') { $out[] = ''; continue; }

        if (str_starts_with($line, '#')) {
            // Rewrite URI="..." attributes (absolute OR relative) in any EXT tag
            $line = preg_replace_callback(
                '/\bURI="([^"]+)"/i',
                function ($m) use ($scheme, $origin, $base, $self, $new_hdntl): string {
                    $uri = $m[1];
                    $abs = nt_resolve($uri, $scheme, $origin, $base);
                    return 'URI="' . nt_proxy($self, $abs, $new_hdntl) . '"';
                },
                $line
            );
            $out[] = $line;
            continue;
        }

        // Segment / child-playlist line
        $abs   = nt_resolve($line, $scheme, $origin, $base);
        $out[] = nt_proxy($self, $abs, $new_hdntl);
    }

    echo implode("\n", $out);
    exit;
}

// ── TS / binary segment: stream back to player ────────────────────────────────
foreach (['Content-Type', 'Content-Length', 'Content-Range', 'Accept-Ranges'] as $h) {
    if (preg_match('/^' . preg_quote($h, '/') . ':\s*(.+)$/im', $headers_raw, $m)) {
        header($h . ': ' . trim($m[1]));
    }
}
if ($http_code === 206) http_response_code(206);

echo $body;

// ── Helpers ────────────────────────────────────────────────────────────────────

/** Resolve a URI (relative or absolute) against the playlist's base. */
function nt_resolve(string $uri, string $scheme, string $origin, string $base): string {
    if (preg_match('#^https?://#i', $uri)) return $uri;
    if (str_starts_with($uri, '//'))       return $scheme . ':' . $uri;
    if (str_starts_with($uri, '/'))        return $origin . $uri;
    return $base . $uri;
}

/** Wrap an absolute URL through the proxy (only if host is allowlisted). */
function nt_proxy(string $self, string $abs_url, string $hdntl): string {
    // Skip if somehow not in allowlist — return as-is so playback at least attempts directly
    $p = parse_url($abs_url);
    if (empty($p['host']) || !nt_is_allowed_host($p['host'])) return $abs_url;

    $q = $self . '?url=' . urlencode($abs_url);
    if ($hdntl !== '') $q .= '&hdntl=' . urlencode($hdntl);
    return $q;
}
