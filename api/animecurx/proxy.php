<?php

$url = $_GET['url'] ?? '';

if (!$url) {
    http_response_code(400);
    exit('Missing URL');
}

$url = urldecode($url);

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'GET',

    CURLOPT_HTTPHEADER => [
        'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
        'Accept: */*',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Dest: empty',
        'Referer: https://embed.animecurx.tech/',
        'Origin: https://embed.animecurx.tech',
        'Accept-Language: ar-IQ,ar;q=0.9,en;q=0.7',
    ],

    CURLOPT_COOKIE =>
        'cinrift_playback_session=GwZEaDCYlMIk2RDLPGRSqawGKYKkMh62',

    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_ENCODING => '',
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(502);
    exit('Upstream error');
}

$httpCode = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

$contentType = curl_getinfo(
    $ch,
    CURLINFO_CONTENT_TYPE
);

curl_close($ch);

http_response_code(
    $httpCode ?: 200
);

if ($contentType) {
    header(
        'Content-Type: ' . $contentType
    );
}

echo $response;