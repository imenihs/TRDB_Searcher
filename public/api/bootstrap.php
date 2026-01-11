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

require __DIR__ . '/../../vendor/autoload.php';

use Slim\Factory\AppFactory;

const TR_ENCODING = 'CP932';

define('PROJECT_ROOT', findProjectRoot());

$app = AppFactory::create();
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = rtrim(str_replace('/index.php', '', $scriptName), '/');
$app->setBasePath($basePath);
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

function findProjectRoot(): string
{
    $candidates = [
        __DIR__,
        realpath(__DIR__) ?: __DIR__,
        dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__),
    ];

    foreach ($candidates as $startDir) {
        $dir = $startDir;
        for ($i = 0; $i < 6; $i++) {
            if (is_file($dir . '/.env') || is_file($dir . '/composer.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }

    return __DIR__ . '/../..';
}
