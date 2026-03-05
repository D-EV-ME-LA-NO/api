<?php
// ====== API عام - يعيد نص التقسيمات فقط ======

$url = $_GET['url'] ?? '';

if (!$url) {
    http_response_code(400);
    die('خطأ: الرابط مطلوب');
}

// استخراج الـ Host من الرابط
$host = parse_url($url, PHP_URL_HOST);
$port = parse_url($url, PHP_URL_PORT);
$hostHeader = $host . ($port ? ":$port" : '');

// هيدرز ثابتة
$headers = [
    "Host: $hostHeader",
    'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
    'Accept-Encoding: identity;q=1, *;q=0',
    'sec-ch-ua: "Chromium";v="137", "Not/A)Brand";v="24"',
    'sec-ch-ua-mobile: ?1',
    'sec-ch-ua-platform: "Android"',
    'Sec-Fetch-Site: cross-site',
    'Sec-Fetch-Mode: no-cors',
    'Sec-Fetch-Dest: video',
    'Referer: https://anym3u8player.com/',
    'Accept-Language: ar-IQ,ar;q=0.9,en-IQ;q=0.8,en;q=0.7,en-US;q=0.6',
    'Range: bytes=0-',
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    http_response_code(500);
    die('خطأ: ' . curl_error($ch));
}

curl_close($ch);

// ====== تعديل المسارات النسبية إلى مطلقة ======
$lines = explode("\n", $response);
$newLines = [];

// استخراج base URL للتعديل
$parsed = parse_url($url);
$baseUrl = $parsed['scheme'] . '://' . $parsed['host'];
if (isset($parsed['port'])) {
    $baseUrl .= ':' . $parsed['port'];
}
$basePath = dirname($url) . '/';

foreach ($lines as $line) {
    $line = rtrim($line);
    
    // إذا كان السطر يحتوي على ملف وليس تعليقاً
    if (!empty($line) && $line[0] !== '#' && !preg_match('/^https?:\/\//', $line)) {
        // تحويل المسار النسبي إلى مطلق
        if ($line[0] === '/') {
            $newLines[] = $baseUrl . $line; // مسار مطلق من جذر السيرفر
        } else {
            $newLines[] = $basePath . $line; // مسار نسبي
        }
    } else {
        $newLines[] = $line; // سطر عادي (تعليق أو رابط كامل)
    }
}

$modifiedResponse = implode("\n", $newLines);

// ====== إرجاع النص فقط (نوع المحتوى text/plain) ======
header('Content-Type: text/plain; charset=utf-8');
http_response_code($httpCode);
echo $modifiedResponse;
?>