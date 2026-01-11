<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;


function getClientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return $ip !== '' ? $ip : 'unknown';
}

function loadRateLimitData(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function saveRateLimitData(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function pruneFailures(array $timestamps, int $windowSeconds, int $now): array
{
    $filtered = [];
    foreach ($timestamps as $ts) {
        $ts = (int) $ts;
        if ($ts > 0 && ($now - $ts) <= $windowSeconds) {
            $filtered[] = $ts;
        }
    }
    return $filtered;
}

function recordFailure(array &$entry, int $now, int $windowSeconds, int $limit, int $cooldownSeconds): bool
{
    $entry['failures'] = pruneFailures((array)($entry['failures'] ?? []), $windowSeconds, $now);
    $entry['failures'][] = $now;
    if (count($entry['failures']) >= $limit) {
        $entry['cooldown_until'] = $now + $cooldownSeconds;
        $entry['failures'] = [];
        return true;
    }
    return false;
}
$app->post('/login', function (Request $request, Response $response) use ($usersPath): Response {
    $rateLimitPath = rtrim(PROJECT_ROOT, '/') . '/login_rate_limit.json';
    $ip = getClientIp();
    $now = time();
    $rateData = loadRateLimitData($rateLimitPath);
    $entry = $rateData[$ip] ?? ['failures' => [], 'cooldown_until' => 0];
    $entry['failures'] = pruneFailures((array)($entry['failures'] ?? []), 60, $now);
    if (!empty($entry['cooldown_until']) && (int) $entry['cooldown_until'] > $now) {
        $rateData[$ip] = $entry;
        saveRateLimitData($rateLimitPath, $rateData);
        return withJson($response, ['ok' => false, 'error' => 'ログインが制限されています。'], 404);
    }

    $data = (array)($request->getParsedBody() ?? []);
    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');

    $users = loadUsers($usersPath);
    $record = $users[$username] ?? null;
    if ($username === '' || $password === '' || $record === null) {
        $blocked = recordFailure($entry, $now, 60, 5, 60 * 30);
        $rateData[$ip] = $entry;
        saveRateLimitData($rateLimitPath, $rateData);
        if ($blocked) {
            return withJson($response, ['ok' => false, 'error' => 'ログインが制限されています。'], 404);
        }
        return withJson($response, ['ok' => false, 'error' => 'ユーザー名またはパスワードが違います。'], 401);
    }

    if (!password_verify($password, $record['password_hash'])) {
        $blocked = recordFailure($entry, $now, 60, 5, 60 * 30);
        $rateData[$ip] = $entry;
        saveRateLimitData($rateLimitPath, $rateData);
        if ($blocked) {
            return withJson($response, ['ok' => false, 'error' => 'ログインが制限されています。'], 404);
        }
        return withJson($response, ['ok' => false, 'error' => 'ユーザー名またはパスワードが違います。'], 401);
    }

    unset($rateData[$ip]);
    saveRateLimitData($rateLimitPath, $rateData);
    $_SESSION['user'] = $username;

    return withJson($response, ['ok' => true, 'user' => $username, 'is_admin' => (bool)$record['is_admin']]);
});

$app->post('/logout', function (Request $request, Response $response): Response {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();

    return withJson($response, ['ok' => true]);
});

$app->get('/me', function (Request $request, Response $response) use ($usersPath): Response {
    $user = $_SESSION['user'] ?? null;
    $isAdmin = false;
    if ($user !== null) {
        $users = loadUsers($usersPath);
        $record = $users[$user] ?? null;
        $isAdmin = $record ? (bool)$record['is_admin'] : false;
    }
    return withJson($response, ['ok' => true, 'user' => $user, 'logged_in' => $user !== null, 'is_admin' => $isAdmin]);
});

$app->get('/admin/users', function (Request $request, Response $response) use ($usersPath): Response {
    $current = $_SESSION['user'] ?? null;
    $users = loadUsers($usersPath);
    if (!isAdminUser($users, $current)) {
        return withJson($response, ['ok' => false, 'error' => 'Forbidden.'], 403);
    }

    $list = [];
    foreach ($users as $name => $info) {
        $list[] = [
            'username' => $name,
            'password_hash' => $info['password_hash'],
            'is_admin' => (bool)$info['is_admin'],
        ];
    }
    return withJson($response, ['ok' => true, 'users' => $list]);
});

$app->post('/admin/users', function (Request $request, Response $response) use ($usersPath): Response {
    $current = $_SESSION['user'] ?? null;
    $users = loadUsers($usersPath);
    if (!isAdminUser($users, $current)) {
        return withJson($response, ['ok' => false, 'error' => 'Forbidden.'], 403);
    }

    $data = (array)($request->getParsedBody() ?? []);
    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $isAdmin = !empty($data['is_admin']);

    if ($username === '' || $password === '') {
        return withJson($response, ['ok' => false, 'error' => 'Username and password required.'], 400);
    }
    if (isset($users[$username])) {
        return withJson($response, ['ok' => false, 'error' => 'User already exists.'], 400);
    }

    $users[$username] = [
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'is_admin' => $isAdmin,
    ];

    if (!saveUsers($usersPath, $users)) {
        return withJson($response, ['ok' => false, 'error' => 'Failed to save users.'], 500);
    }

    return withJson($response, ['ok' => true]);
});

$app->put('/admin/users/{username}', function (Request $request, Response $response, array $args) use ($usersPath): Response {
    $current = $_SESSION['user'] ?? null;
    $users = loadUsers($usersPath);
    if (!isAdminUser($users, $current)) {
        return withJson($response, ['ok' => false, 'error' => 'Forbidden.'], 403);
    }

    $target = (string)($args['username'] ?? '');
    if ($target === $current) {
        return withJson($response, ['ok' => false, 'error' => 'Cannot delete yourself.'], 400);
    }
        if ($target === '' || !isset($users[$target])) {
        return withJson($response, ['ok' => false, 'error' => 'User not found.'], 404);
    }

    $data = (array)($request->getParsedBody() ?? []);
    $newUsername = trim((string)($data['username'] ?? $target));
    $newPassword = (string)($data['password'] ?? '');
    $isAdmin = array_key_exists('is_admin', $data) ? !empty($data['is_admin']) : $users[$target]['is_admin'];

    if ($newUsername === '') {
        return withJson($response, ['ok' => false, 'error' => 'Username required.'], 400);
    }

    if ($target === 'admin') {
        $isAdmin = true;
    }

    if ($newUsername !== $target && isset($users[$newUsername])) {
        return withJson($response, ['ok' => false, 'error' => 'Username already exists.'], 400);
    }

    $record = $users[$target];
    $record['is_admin'] = $isAdmin;
    if ($newPassword !== '') {
        $record['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    unset($users[$target]);
    $users[$newUsername] = $record;

    if (!saveUsers($usersPath, $users)) {
        return withJson($response, ['ok' => false, 'error' => 'Failed to save users.'], 500);
    }

    return withJson($response, ['ok' => true]);
});

$app->delete('/admin/users/{username}', function (Request $request, Response $response, array $args) use ($usersPath): Response {
    $current = $_SESSION['user'] ?? null;
    $users = loadUsers($usersPath);
    if (!isAdminUser($users, $current)) {
        return withJson($response, ['ok' => false, 'error' => 'Forbidden.'], 403);
    }

    $target = (string)($args['username'] ?? '');
    if ($target === $current) {
        return withJson($response, ['ok' => false, 'error' => 'Cannot delete yourself.'], 400);
    }
        if ($target === '' || !isset($users[$target])) {
        return withJson($response, ['ok' => false, 'error' => 'User not found.'], 404);
    }
    if (!empty($users[$target]['is_admin'])) {
        $adminCount = 0;
        foreach ($users as $info) {
            if (!empty($info['is_admin'])) {
                $adminCount++;
            }
        }
        if ($adminCount <= 1) {
            return withJson($response, ['ok' => false, 'error' => 'Cannot delete last admin.'], 400);
        }
    }

    unset($users[$target]);
    if (!saveUsers($usersPath, $users)) {
        return withJson($response, ['ok' => false, 'error' => 'Failed to save users.'], 500);
    }

    return withJson($response, ['ok' => true]);
});
