<?php
// PHP built-in server router. Serves static files directly, otherwise hands off
// to index.php so the front-controller can handle clean URLs.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false; // serve as-is
}

require __DIR__ . '/index.php';
