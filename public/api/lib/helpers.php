<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;

function loadEnv(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

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
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '') {
            $values[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    return $values;
}

function getEnvValue(string $key, string $default): string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

function resolvePath(string $path): string
{
    if ($path === '') {
        return $path;
    }
    if ($path[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
        return $path;
    }
    return rtrim(PROJECT_ROOT, '/') . '/' . ltrim($path, '/');
}

function withJson(Response $response, array $payload, int $status = 200): Response
{
    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
}

function loadUsers(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    $users = [];
    if (isset($data['users']) && is_array($data['users'])) {
        foreach ($data['users'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $username = (string)($entry['username'] ?? '');
            $hash = (string)($entry['password_hash'] ?? '');
            $isAdmin = !empty($entry['is_admin']);
            if ($username !== '' && $hash !== '') {
                $users[$username] = ['password_hash' => $hash, 'is_admin' => $isAdmin];
            }
        }
        return $users;
    }

    foreach ($data as $user => $hash) {
        if (is_string($user) && is_string($hash)) {
            $users[$user] = [
                'password_hash' => $hash,
                'is_admin' => $user === 'admin',
            ];
        }
    }

    return $users;
}

function saveUsers(string $path, array $users): bool
{
    $list = [];
    ksort($users);
    foreach ($users as $username => $info) {
        if (!is_string($username)) {
            continue;
        }
        $hash = (string)($info['password_hash'] ?? '');
        if ($hash === '') {
            continue;
        }
        $list[] = [
            'username' => $username,
            'password_hash' => $hash,
            'is_admin' => !empty($info['is_admin']),
        ];
    }

    $payload = ['users' => $list];
    return file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n") !== false;
}

function isAdminUser(array $users, ?string $username): bool
{
    if ($username === null || $username === '') {
        return false;
    }
    $record = $users[$username] ?? null;
    return $record ? (bool)$record['is_admin'] : false;
}

function loadPdfOffsetsFromMod(string $path, int $defaultStart = 4): array
{
    $default = 1 - $defaultStart;
    if (!is_file($path)) {
        return ['offsets' => [], 'default' => $default, 'default_start' => $defaultStart];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return ['offsets' => [], 'default' => $default, 'default_start' => $defaultStart];
    }

    $offsets = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = preg_split('/\s+/', $line);
        if (!$parts || count($parts) != 2) {
            continue;
        }
        $ym = $parts[0];
        $startPage = $parts[1];
        if (!preg_match('/^\d{6}$/', $ym)) {
            continue;
        }
        if (!is_numeric($startPage)) {
            continue;
        }
        $year = substr($ym, 0, 4);
        $month = substr($ym, 4, 2);
        $key = $year . '-' . $month;
        $startPage = (int)$startPage;
        if (!array_key_exists($key, $offsets) || $startPage < $offsets[$key]) {
            $offsets[$key] = $startPage;
        }
    }

    return ['offsets' => $offsets, 'default' => $default, 'default_start' => $defaultStart];
}

function loadMinStartPagesFromTr(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $minPages = [];
    fgets($handle);
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $line = mb_convert_encoding($line, 'UTF-8', TR_ENCODING);
        $row = str_getcsv($line);
        if (count($row) < 6) {
            continue;
        }
        $year = (int)($row[0] ?? 0);
        $month = (int)($row[1] ?? 0);
        $startPage = $row[5] ?? null;
        if ($year <= 0 || $month <= 0) {
            continue;
        }
        if (!is_numeric($startPage)) {
            continue;
        }
        $key = sprintf('%04d-%02d', $year, $month);
        $startPage = (int)$startPage;
        if (!isset($minPages[$key]) || $startPage < $minPages[$key]) {
            $minPages[$key] = $startPage;
        }
    }
    fclose($handle);

    return $minPages;
}

function buildRange(int $fromYear, int $fromMonth, int $toYear, int $toMonth): ?array
{
    $from = null;
    $to = null;

    if ($fromYear > 0) {
        $fromMonth = ($fromMonth >= 1 && $fromMonth <= 12) ? $fromMonth : 1;
        $from = $fromYear * 100 + $fromMonth;
    }

    if ($toYear > 0) {
        $toMonth = ($toMonth >= 1 && $toMonth <= 12) ? $toMonth : 12;
        $to = $toYear * 100 + $toMonth;
    }

    if ($from === null && $to === null) {
        return null;
    }

    if ($from === null) {
        $from = 0;
    }
    if ($to === null) {
        $to = 999912;
    }

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    return ['from' => $from, 'to' => $to];
}

function buildChecker(string $query, string $mode, bool $caseSensitive, array &$errors, callable $getTarget, string $label): callable
{
    if ($query === '') {
        return static function (): bool {
            return true;
        };
    }

    if ($mode === 'regex') {
        $pattern = makeRegex($query, $errors, $label, $caseSensitive);
        if ($pattern === null) {
            return static function (): bool {
                return false;
            };
        }

        return static function (array $record) use ($pattern, $getTarget): bool {
            $target = $getTarget($record);
            return preg_match($pattern, $target) === 1;
        };
    }

    $tokens = tokenizeQuery($query);
    if (empty($tokens)) {
        return static function (): bool {
            return true;
        };
    }

    $rpn = toRpn($tokens);

    return static function (array $record) use ($rpn, $getTarget, $caseSensitive): bool {
        $target = $getTarget($record);
        if (!$caseSensitive) {
            $target = mb_strtolower($target, 'UTF-8');
        }
        return evalRpn($rpn, static function (string $term) use ($target, $caseSensitive): bool {
            if (!$caseSensitive) {
                $term = mb_strtolower($term, 'UTF-8');
            }
            if ($term === '') {
                return true;
            }
            if ($caseSensitive) {
                return mb_strpos($target, $term, 0, 'UTF-8') !== false;
            }
            return mb_stripos($target, $term, 0, 'UTF-8') !== false;
        });
    };
}

function makeRegex(string $raw, array &$errors, string $label, bool $caseSensitive): ?string
{
    $delimiter = '~';
    $flags = $caseSensitive ? 'u' : 'iu';
    $pattern = $delimiter . str_replace($delimiter, '\\' . $delimiter, $raw) . $delimiter . $flags;
    if (@preg_match($pattern, '') === false) {
        $errors[] = sprintf('Invalid %s regex pattern.', $label);
        return null;
    }
    return $pattern;
}

function tokenizeQuery(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $query = preg_replace('/([&|!()])/u', ' $1 ', $query);
    $parts = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);

    $tokens = [];
    foreach ($parts as $part) {
        if ($part === '&') {
            $tokens[] = 'AND';
        } elseif ($part === '|') {
            $tokens[] = 'OR';
        } elseif ($part === '!') {
            $tokens[] = 'NOT';
        } elseif ($part === '(' || $part === ')') {
            $tokens[] = $part;
        } else {
            $tokens[] = $part;
        }
    }

    $final = [];
    $prevType = null;
    foreach ($tokens as $token) {
        $type = tokenType($token);
        $needsAnd = ($prevType === 'term' || $prevType === ')')
            && ($type === 'term' || $type === '(' || $token === 'NOT');
        if ($needsAnd) {
            $final[] = 'AND';
        }
        $final[] = $token;
        $prevType = $type;
    }

    return $final;
}

function tokenType(string $token): string
{
    if ($token === '(') {
        return '(';
    }
    if ($token === ')') {
        return ')';
    }
    if (in_array($token, ['AND', 'OR', 'NOT'], true)) {
        return 'op';
    }
    return 'term';
}

function toRpn(array $tokens): array
{
    $output = [];
    $ops = [];
    $precedence = ['NOT' => 3, 'AND' => 2, 'OR' => 1];
    $rightAssoc = ['NOT' => true];

    foreach ($tokens as $token) {
        $type = tokenType($token);
        if ($type === 'term') {
            $output[] = $token;
            continue;
        }
        if ($token === '(') {
            $ops[] = $token;
            continue;
        }
        if ($token === ')') {
            while (!empty($ops)) {
                $op = array_pop($ops);
                if ($op === '(') {
                    break;
                }
                $output[] = $op;
            }
            continue;
        }

        while (!empty($ops)) {
            $top = $ops[count($ops) - 1];
            if (!in_array($top, ['AND', 'OR', 'NOT'], true)) {
                break;
            }
            $topPrec = $precedence[$top];
            $tokPrec = $precedence[$token];
            $isRight = $rightAssoc[$token] ?? false;
            if (($isRight && $tokPrec < $topPrec) || (!$isRight && $tokPrec <= $topPrec)) {
                $output[] = array_pop($ops);
                continue;
            }
            break;
        }
        $ops[] = $token;
    }

    while (!empty($ops)) {
        $output[] = array_pop($ops);
    }

    return $output;
}

function evalRpn(array $rpn, callable $termCheck): bool
{
    if (empty($rpn)) {
        return true;
    }

    $stack = [];
    foreach ($rpn as $token) {
        if (tokenType($token) === 'term') {
            $stack[] = $termCheck($token);
            continue;
        }

        if ($token === 'NOT') {
            $val = array_pop($stack);
            if ($val === null) {
                return false;
            }
            $stack[] = !$val;
            continue;
        }

        $right = array_pop($stack);
        $left = array_pop($stack);
        if ($left === null || $right === null) {
            return false;
        }
        if ($token === 'AND') {
            $stack[] = $left && $right;
        } else {
            $stack[] = $left || $right;
        }
    }

    return (bool)array_pop($stack);
}
