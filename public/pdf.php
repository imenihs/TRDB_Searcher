<?php

declare(strict_types=1);

ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 7);
ini_set('session.cookie_lifetime', 60 * 60 * 24 * 7);

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 7,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

$basePath = getenv('TR_PDF_FS_BASE') ?: '';
if ($basePath === '') {
    $envPath = __DIR__ . '/../.env';
    if (is_file($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                if ($key === 'TR_PDF_FS_BASE') {
                    $basePath = trim($value, " \t\n\r\0\x0B\"'");
                    break;
                }
            }
        }
    }
}

if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$year = $_GET['year'] ?? '';
$month = $_GET['month'] ?? '';
if (!preg_match('/^\d{4}$/', $year) || !preg_match('/^\d{1,2}$/', $month)) {
    http_response_code(400);
    echo 'Invalid parameters';
    exit;
}

$month = str_pad($month, 2, '0', STR_PAD_LEFT);
$ym = $year . $month;


$name = $_GET['name'] ?? '';
$validName = '';
if ($name !== '') {
    $pattern = '/^(TR' . $ym . '\.(?:PDF|pdf)|DPMTR' . $ym . '_toragimmrelease\.pdf)$/';
    if (preg_match($pattern, $name)) {
        $validName = $name;
    }
}

if ($basePath === '') {
    http_response_code(500);
    echo 'PDF base path not configured';
    exit;
}

$basePath = rtrim($basePath, '/');
$paths = [];
if ($validName !== '') {
    $paths[] = "$basePath/$year/$validName";
}
$paths[] = "$basePath/$year/TR$ym.PDF";
$paths[] = "$basePath/$year/TR$ym.pdf";
$paths[] = "$basePath/$year/DPMTR$ym_toragimmrelease.pdf";

$filePath = null;
foreach ($paths as $candidate) {
    if (is_file($candidate)) {
        $filePath = $candidate;
        break;
    }
}

if ($filePath === null) {
    http_response_code(404);
    echo 'PDF not found';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');

$fp = fopen($filePath, 'rb');
if ($fp === false) {
    http_response_code(500);
    echo 'Failed to open PDF';
    exit;
}

fpassthru($fp);
fclose($fp);
