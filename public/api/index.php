<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/helpers.php';

$envPath = rtrim(PROJECT_ROOT, '/') . '/.env';
loadEnv($envPath);
$usersPath = rtrim(PROJECT_ROOT, '/') . '/users.json';

require __DIR__ . '/routes/auth.php';
require __DIR__ . '/routes/search.php';

$app->run();
