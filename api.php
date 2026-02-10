<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing id"]);
    exit;
}

$id = intval($_GET['id']);
$url = "https://cinemana.shabakaty.com/api/android/allVideoInfo/id/$id";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_ENCODING => "",
    CURLOPT_HTTPHEADER => [
        "User-Agent: Android 12; Cinemana -1; HUAWEI STK-L21",
        "Accept: application/json",
        "Accept-Language: ar-IQ,ar;q=0.9,en;q=0.8"
    ]
]);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(500);
    echo json_encode([
        "error" => "curl_error",
        "message" => curl_error($ch)
    ]);
    curl_close($ch);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($httpCode);
echo $response;
