<?php
header('Content-Type: application/json; charset=utf-8');

function clean($text) {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $text));
}

function searchMovie($movieName, $targetYear) {

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => "https://hdtodayz.to/ajax/search",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['keyword' => $movieName]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_ENCODING => "", // auto gzip
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0',
            'x-requested-with: XMLHttpRequest',
            'content-type: application/x-www-form-urlencoded'
        ]
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        return [
            'success' => false,
            'error' => curl_error($ch)
        ];
    }

    curl_close($ch);

    $pattern = '/<a href="\/movie\/watch-([^"]+)-hd-(\d+)" class="nav-item">(.*?)<\/a>/s';
    preg_match_all($pattern, $response, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {

        $slug = $match[1];
        $id = $match[2];
        $content = $match[3];

        $title = '';
        $year = '';

        if (preg_match('/<h3 class="film-name">(.*?)<\/h3>/', $content, $t))
            $title = trim($t[1]);

        if (preg_match('/<span>(\d{4})<\/span>/', $content, $y))
            $year = $y[1];

        // ✅ إذا تطابق الاسم والسنة نرجع فوراً
        if ($year == $targetYear && clean($title) === clean($movieName)) {

            return [
                'success' => true,
                'movie' => [
                    'id' => $id,
                    'title' => $title,
                    'year' => $year,
                    'url' => "https://hdtodayz.to/movie/watch-{$slug}-hd-{$id}"
                ]
            ];
        }
    }

    return [
        'success' => false,
        'error' => 'لم يتم العثور على فيلم مطابق'
    ];
}

// ===== main =====

$name = $_GET['q'] ?? '';
$year = $_GET['y'] ?? '';

if (!$name || !$year) {
    echo json_encode([
        'success' => false,
        'example' => '?q=Avatar&y=2009'
    ]);
    exit;
}

echo json_encode(searchMovie($name, $year), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>