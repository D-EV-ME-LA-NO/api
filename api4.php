<?php
header('Content-Type: application/json; charset=utf-8');

function fetchToken($id) {

    $url = "https://videostr.net/embed-1/v3/e-1/$id?z=";

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "gzip",
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            "referer: https://1flix.to/",
            "user-agent: Mozilla/5.0"
        ]
    ]);

    $html = curl_exec($ch);

    if ($html === false) {
        return ["success"=>false,"error"=>curl_error($ch)];
    }

    curl_close($ch);

    // 🔥 استخراج كل النصوص الطويلة
    preg_match_all('/["\']([A-Za-z0-9]{16,})["\']/', $html, $m);

    $codes = array_unique($m[1]);

    if (empty($codes)) {
        return [
            "success"=>false,
            "error"=>"لم يتم العثور على أكواد",
            "debug_html"=>substr($html,0,800) // أول 800 حرف للتشخيص
        ];
    }

    return [
        "success"=>true,
        "codes"=>$codes,
        "merged_code"=>implode("",$codes)
    ];
}

$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(["success"=>false,"error"=>"ارسل id"]);
    exit;
}

echo json_encode(fetchToken($id), JSON_PRETTY_PRINT);