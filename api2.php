<?php
header('Content-Type: application/json; charset=utf-8');

function getEpisodeId($episodeId) {

    $url = "https://hdtodayz.to/ajax/episode/list/" . $episodeId;

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => "",
        CURLOPT_HTTPHEADER => [
            "x-requested-with: XMLHttpRequest",
            "referer: https://hdtodayz.to/",
            "user-agent: Mozilla/5.0"
        ]
    ]);

    $html = curl_exec($ch);

    if ($html === false) {
        return [
            "success" => false,
            "error" => curl_error($ch)
        ];
    }

    curl_close($ch);

    preg_match('/id="(\d+)"/', $html, $m1);
    preg_match('/id="watch-(\d+)"/', $html, $m2);

    return [
        "success" => true,
        "episode_id" => $episodeId,
        "id" => $m1[1] ?? null,
        "watch_id" => $m2[1] ?? null
    ];
}


// ✅ قراءة الـ ID من GET
$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode([
        "success" => false,
        "error" => "ارسل id عبر GET",
        "example" => "?id=140613"
    ]);
    exit;
}

echo json_encode(getEpisodeId($id), JSON_PRETTY_PRINT);

?>