<?php
/**
 * api/dulo/proxy.php
 *
 * HLS Proxy
 *
 * الاستخدام:
 * proxy.php?url=ENCODED_URL
 *
 * يدعم:
 * - Master M3U8
 * - Media M3U8
 * - TS / fMP4 segments
 * - Range Requests
 * - HLS URI="..."
 * - Origin / Referer
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range, Origin, Referer, User-Agent, Accept, Content-Type');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const DU_UA = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36';

$url = $_GET['url'] ?? '';

if ($url === '') {
    http_response_code(400);
    exit('missing url');
}

$url = trim($url);

/*
|--------------------------------------------------------------------------
| Validate URL
|--------------------------------------------------------------------------
*/

$parts = parse_url($url);

if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
    http_response_code(400);
    exit('invalid url');
}

$scheme = strtolower($parts['scheme']);
$host   = strtolower($parts['host']);

if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    exit('invalid scheme');
}

/*
|--------------------------------------------------------------------------
| Allowed hosts
|--------------------------------------------------------------------------
*/

$allowed = false;

$allowed_patterns = [
    '/(^|\.)dulo\.tv$/i',
    '/(^|\.)workers\.dev$/i',
    '/(^|\.)mediacache\.cc$/i',
    '/(^|\.)akamaized\.net$/i',
    '/(^|\.)cloudfront\.net$/i',
];

foreach ($allowed_patterns as $pattern) {
    if (preg_match($pattern, $host)) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    http_response_code(403);
    exit('blocked host');
}

/*
|--------------------------------------------------------------------------
| Request headers
|--------------------------------------------------------------------------
|
| حسب الاتصالات المرفوعة:
|
| Origin: https://dulo.tv
| Referer: https://dulo.tv/
| User-Agent: Chrome Android
|
*/

$requestHeaders = [
    'User-Agent: ' . DU_UA,
    'Accept: */*',
    'Origin: https://dulo.tv',
    'Referer: https://dulo.tv/',
];

if (!empty($_SERVER['HTTP_RANGE'])) {
    $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

/*
|--------------------------------------------------------------------------
| CURL
|--------------------------------------------------------------------------
*/

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,

    CURLOPT_HTTPHEADER     => $requestHeaders,

    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,

    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 60,

    CURLOPT_ENCODING       => '',

    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,

    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
]);

$response = curl_exec($ch);

if ($response === false) {

    $error = curl_error($ch);

    curl_close($ch);

    http_response_code(502);

    exit('proxy connection failed');
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

curl_close($ch);

/*
|--------------------------------------------------------------------------
| Validate response
|--------------------------------------------------------------------------
*/

if ($httpCode < 200 || $httpCode >= 400) {

    http_response_code(
        $httpCode > 0 ? $httpCode : 502
    );

    exit('upstream error');
}

/*
|--------------------------------------------------------------------------
| Split Headers / Body
|--------------------------------------------------------------------------
*/

$headersRaw = substr(
    $response,
    0,
    $headerSize
);

$body = substr(
    $response,
    $headerSize
);

$bodyTrim = ltrim($body);

$contentTypeLower = strtolower(
    (string)$contentType
);

/*
|--------------------------------------------------------------------------
| Detect M3U8
|--------------------------------------------------------------------------
*/

$isM3U8 =
    str_contains($contentTypeLower, 'mpegurl') ||
    str_contains($contentTypeLower, 'm3u8') ||
    str_starts_with($bodyTrim, '#EXTM3U');

/*
|--------------------------------------------------------------------------
| M3U8
|--------------------------------------------------------------------------
*/

if ($isM3U8) {

    header(
        'Content-Type: application/vnd.apple.mpegurl'
    );

    header(
        'Cache-Control: no-store, no-cache, must-revalidate'
    );

    header(
        'Pragma: no-cache'
    );

    header(
        'Expires: 0'
    );

    /*
    |--------------------------------------------------------------------------
    | Proxy URL
    |--------------------------------------------------------------------------
    |
    | نستخدم المسار الحالي بدلاً من hard-code للدومين.
    |
    */

    $proxyPath = $_SERVER['SCRIPT_NAME'];

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    */

    $baseUrl = getBaseUrl(
        $effectiveUrl
    );

    /*
    |--------------------------------------------------------------------------
    | Parse playlist
    |--------------------------------------------------------------------------
    */

    $lines = preg_split(
        '/\r\n|\r|\n/',
        $body
    );

    $output = [];

    foreach ($lines as $line) {

        $line = trim($line);

        /*
        |--------------------------------------------------------------------------
        | Empty
        |--------------------------------------------------------------------------
        */

        if ($line === '') {
            $output[] = '';
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | HLS Tags
        |--------------------------------------------------------------------------
        */

        if (str_starts_with($line, '#')) {

            /*
             * معالجة:
             *
             * #EXT-X-MAP:URI="..."
             *
             * #EXT-X-KEY:URI="..."
             *
             * وأي URI داخل Tags
             */

            if (
                preg_match_all(
                    '/URI="([^"]+)"/i',
                    $line,
                    $matches
                )
            ) {

                foreach ($matches[1] as $uri) {

                    $absoluteUrl = resolveUrl(
                        $uri,
                        $baseUrl
                    );

                    /*
                     * مهم:
                     * rawurlencode يحافظ على الرابط الأصلي
                     * كقيمة Query Parameter.
                     */

                    $proxyUrl =
                        $proxyPath .
                        '?url=' .
                        rawurlencode(
                            $absoluteUrl
                        );

                    $line = str_replace(
                        'URI="' . $uri . '"',
                        'URI="' . $proxyUrl . '"',
                        $line
                    );
                }
            }

            $output[] = $line;

            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Playlist URI
        |--------------------------------------------------------------------------
        |
        | هنا توجد روابط مثل:
        |
        | https://vidapi-sabrina-proxy....workers.dev/v/.../index.m3u8
        |
        | لا نغير الرابط نفسه.
        | فقط نمرره إلى البروكسي.
        |
        */

        $absoluteUrl = resolveUrl(
            $line,
            $baseUrl
        );

        $proxyUrl =
            $proxyPath .
            '?url=' .
            rawurlencode(
                $absoluteUrl
            );

        $output[] = $proxyUrl;
    }

    echo implode(
        "\n",
        $output
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Binary Stream
|--------------------------------------------------------------------------
|
| TS / fMP4 / AAC / MP4
|--------------------------------------------------------------------------
*/

$forwardHeaders = [
    'Content-Type',
    'Content-Length',
    'Content-Range',
    'Accept-Ranges',
    'Content-Disposition',
];

foreach ($forwardHeaders as $headerName) {

    /*
     * البحث عن آخر Header فعلي.
     * مفيد إذا CURL رجع أكثر من مجموعة Headers بسبب Redirect.
     */

    if (
        preg_match_all(
            '/^' .
            preg_quote($headerName, '/') .
            ':\s*(.+)$/im',
            $headersRaw,
            $matches
        )
    ) {

        $lastValue = end(
            $matches[1]
        );

        if ($lastValue !== false) {

            header(
                $headerName .
                ': ' .
                trim($lastValue)
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Status Code
|--------------------------------------------------------------------------
*/

if ($httpCode === 206) {

    http_response_code(206);

} elseif ($httpCode >= 200 && $httpCode < 300) {

    http_response_code(
        $httpCode
    );
}

/*
|--------------------------------------------------------------------------
| Output
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

echo $body;


/*
|--------------------------------------------------------------------------
| Resolve URL
|--------------------------------------------------------------------------
*/

function resolveUrl(
    string $url,
    string $base
): string {

    $url = trim($url);

    /*
     * Absolute
     */

    if (
        str_starts_with(
            $url,
            'https://'
        ) ||
        str_starts_with(
            $url,
            'http://'
        )
    ) {
        return $url;
    }

    /*
     * Protocol relative
     */

    if (
        str_starts_with(
            $url,
            '//'
        )
    ) {
        return 'https:' . $url;
    }

    /*
     * Absolute path
     */

    if (
        str_starts_with(
            $url,
            '/'
        )
    ) {

        $parts = parse_url(
            $base
        );

        $scheme =
            $parts['scheme']
            ?? 'https';

        $host =
            $parts['host']
            ?? '';

        $port = '';

        if (
            !empty(
                $parts['port']
            )
        ) {
            $port =
                ':' .
                $parts['port'];
        }

        return
            $scheme .
            '://' .
            $host .
            $port .
            $url;
    }

    /*
     * Relative URL
     */

    return $base . $url;
}


/*
|--------------------------------------------------------------------------
| Get Base URL
|--------------------------------------------------------------------------
*/

function getBaseUrl(
    string $url
): string {

    $position = strrpos(
        $url,
        '/'
    );

    if ($position === false) {
        return $url . '/';
    }

    return substr(
        $url,
        0,
        $position + 1
    );
}