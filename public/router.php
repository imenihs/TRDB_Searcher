<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fullPath = __DIR__ . $path;

if ($path !== '/' && is_file($fullPath)) {
    return false;
}

if (strpos($path, '/api') === 0) {
    require __DIR__ . '/api/index.php';
    return true;
}

return false;
