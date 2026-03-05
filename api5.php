<?php
header('Content-Type: application/json; charset=utf-8');

$videoId = $_GET['id'] ?? null;
$nonce   = $_GET['nonce'] ?? null;

if (!$videoId || !$nonce) {
    echo json_encode([
        "success" => false,
        "error" => "ارسل id و nonce عبر GET",
        "example" => "?id=TyGkkZzGRZc4&nonce=XXXX"
    ]);
    exit;
}

$url = "https://videostr.net/embed-1/v3/e-1/getSources?id={$videoId}&_k={$nonce}";

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING => "",
    CURLOPT_HTTPHEADER => [
        "x-requested-with: XMLHttpRequest",
        "accept: */*",
        "user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        "referer: https://videostr.net/embed-1/v3/e-1/{$videoId}?z="
    ]
]);

$response = curl_exec($ch);

if ($response === false) {
    echo json_encode([
        "success" => false,
        "error" => curl_error($ch)
    ]);
    exit;
}

curl_close($ch);

// ✅ يرجع JSON النهائي
echo $response;
?>