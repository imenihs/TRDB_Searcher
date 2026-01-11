<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

const TR_ENCODING = 'CP932';
const PROJECT_ROOT = __DIR__ . '/../../';

$env = loadEnv(PROJECT_ROOT . '.env');

$app = AppFactory::create();
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = rtrim(str_replace('/index.php', '', $scriptName), '/');
$app->setBasePath($basePath);
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->get('/search', function (Request $request, Response $response): Response {
    $params = $request->getQueryParams();
    $dataPath = resolvePath(getEnvValue('TR_DATA_PATH', 'tr-book/TRDB/TR.txt'));
    $pdfBasePath = getEnvValue('TR_PDF_BASE', '/tr-book');

    if (!is_file($dataPath)) {
        $payload = [
            'errors' => ['TR.txt not found.'],
            'items' => [],
            'meta' => [
                'total' => 0,
                'returned' => 0,
                'offset' => 0,
                'limit' => 0,
                'last_data' => null,
                'pdf_base' => $pdfBasePath,
            ],
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(500);
    }

    $titleQuery = trim((string)($params['title'] ?? ''));
    $titleMode = (string)($params['title_mode'] ?? 'keyword');
    $authorQuery = trim((string)($params['author'] ?? ''));
    $authorMode = (string)($params['author_mode'] ?? 'keyword');

    $fromYear = (int)($params['from_year'] ?? 0);
    $fromMonth = (int)($params['from_month'] ?? 0);
    $toYear = (int)($params['to_year'] ?? 0);
    $toMonth = (int)($params['to_month'] ?? 0);

    $limit = (int)($params['limit'] ?? 200);
    $offset = (int)($params['offset'] ?? 0);
    $limit = max(1, min(1000, $limit));
    $offset = max(0, $offset);

    $errors = [];
    $titleChecker = buildChecker($titleQuery, $titleMode, $errors, static function (array $record): string {
        return trim(($record['title'] ?? '') . ' ' . ($record['subtitle'] ?? ''));
    }, 'title');
    $authorChecker = buildChecker($authorQuery, $authorMode, $errors, static function (array $record): string {
        return (string)($record['author'] ?? '');
    }, 'author');

    $range = buildRange($fromYear, $fromMonth, $toYear, $toMonth);

    $handle = fopen($dataPath, 'rb');
    if ($handle === false) {
        $errors[] = 'Failed to open TR.txt.';
    } else {
        fgets($handle);
    }

    $items = [];
    $total = 0;
    $lastData = null;

    if ($handle !== false) {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $line = mb_convert_encoding($line, 'UTF-8', TR_ENCODING);
            $row = str_getcsv($line);
            if (count($row) < 2) {
                continue;
            }

            $record = [
                'year' => (int)($row[0] ?? 0),
                'month' => (int)($row[1] ?? 0),
                'title' => (string)($row[2] ?? ''),
                'subtitle' => (string)($row[3] ?? ''),
                'type' => (string)($row[4] ?? ''),
                'start_page' => (string)($row[5] ?? ''),
                'page_count' => (string)($row[6] ?? ''),
                'author' => (string)($row[7] ?? ''),
            ];

            if ($record['year'] > 0 && $record['month'] > 0) {
                $lastData = ['year' => $record['year'], 'month' => $record['month']];
            }

            $ym = $record['year'] * 100 + $record['month'];
            if ($range !== null && ($ym < $range['from'] || $ym > $range['to'])) {
                continue;
            }

            if (!$titleChecker($record)) {
                continue;
            }
            if (!$authorChecker($record)) {
                continue;
            }

            $total++;
            if ($total <= $offset) {
                continue;
            }
            if (count($items) >= $limit) {
                continue;
            }

            $items[] = $record;
        }
        fclose($handle);
    }

    $payload = [
        'meta' => [
            'total' => $total,
            'returned' => count($items),
            'offset' => $offset,
            'limit' => $limit,
            'last_data' => $lastData,
            'pdf_base' => $pdfBasePath,
        ],
        'items' => $items,
    ];

    if (!empty($errors)) {
        $payload['errors'] = $errors;
    }

    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->run();

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
    return PROJECT_ROOT . ltrim($path, '/');
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

function buildChecker(string $query, string $mode, array &$errors, callable $getTarget, string $label): callable
{
    if ($query === '') {
        return static function (): bool {
            return true;
        };
    }

    if ($mode === 'regex') {
        $pattern = makeRegex($query, $errors, $label);
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

    return static function (array $record) use ($rpn, $getTarget): bool {
        $target = mb_strtolower($getTarget($record), 'UTF-8');
        return evalRpn($rpn, static function (string $term) use ($target): bool {
            $term = mb_strtolower($term, 'UTF-8');
            if ($term === '') {
                return true;
            }
            return mb_stripos($target, $term, 0, 'UTF-8') !== false;
        });
    };
}

function makeRegex(string $raw, array &$errors, string $label): ?string
{
    $delimiter = '~';
    $pattern = $delimiter . str_replace($delimiter, '\\' . $delimiter, $raw) . $delimiter . 'u';
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
