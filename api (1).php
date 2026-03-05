<?php
header('Content-Type: application/json; charset=utf-8');

function callAPI($url) {

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            "User-Agent: Mozilla/5.0"
        ]
    ]);

    $res = curl_exec($ch);

    if ($res === false) {
        return [
            "success" => false,
            "error" => curl_error($ch)
        ];
    }

    curl_close($ch);

    return json_decode($res, true);
}


// =======================
// قراءة البحث
// =======================

$q = $_GET['q'] ?? null;
$y = $_GET['y'] ?? null;

if (!$q || !$y) {
    echo json_encode([
        "success" => false,
        "error" => "ارسل q و y",
        "example" => "?q=Avatar&y=2009"
    ]);
    exit;
}

$base = "https://dev-melanokora.pantheonsite.io/wp-admin/MELANO-KORA/veom/";


// =======================
// API 1
// =======================

$api1 = callAPI($base . "api1.php?q=" . urlencode($q) . "&y=" . $y);

if (!($api1['success'] ?? false)) {
    echo json_encode(["success" => false, "steps" => ["api1" => $api1]]);
    exit;
}

$movieId = $api1['movie']['id'];


// =======================
// API 2
// =======================

$api2 = callAPI($base . "api2.php?id=" . $movieId);

if (!($api2['success'] ?? false)) {
    echo json_encode([
        "success" => false,
        "steps" => ["api1"=>$api1,"api2"=>$api2]
    ]);
    exit;
}

$episodeId = $api2['id'];


// =======================
// API 3
// =======================

$api3 = callAPI($base . "api3.php?id=" . $episodeId);

if (!($api3['success'] ?? false)) {
    echo json_encode([
        "success" => false,
        "steps" => ["api1"=>$api1,"api2"=>$api2,"api3"=>$api3]
    ]);
    exit;
}

$videoId = $api3['video_id'];


// =======================
// API 4
// =======================

$api4 = callAPI($base . "api4.php?id=" . $videoId);

if (!($api4['success'] ?? false)) {
    echo json_encode([
        "success" => false,
        "steps" => [
            "api1"=>$api1,
            "api2"=>$api2,
            "api3"=>$api3,
            "api4"=>$api4
        ]
    ]);
    exit;
}

$nonce = $api4['merged_code'];


// =======================
// API 5 (النهائي)
// =======================

$api5 = callAPI(
    $base . "api5.php?id=" . $videoId . "&nonce=" . $nonce
);


// =======================
// الرد النهائي
// =======================

echo json_encode([
    "success" => true,
    "steps" => [
        "api1" => $api1,
        "api2" => $api2,
        "api3" => $api3,
        "api4" => $api4,
        "api5" => $api5
    ],
    "final" => $api5
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

?>