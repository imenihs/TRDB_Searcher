<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->get('/search', function (Request $request, Response $response) use ($envPath): Response {
    $params = $request->getQueryParams();
    $dataPath = resolvePath(getEnvValue('TR_DATA_PATH', 'tr-book/TRDB/TR.txt'));
    $pdfBasePath = getEnvValue('TR_PDF_BASE', '/tr-book');
    $pdfFsBase = resolvePath(getEnvValue('TR_PDF_FS_BASE', 'tr-book'));
    $modPath = resolvePath(getEnvValue('TR_PDF_MOD_PATH', 'tr-book/TRDB/TRmod.txt'));
    $offsetData = loadPdfOffsetsFromMod($modPath);
    $offsets = $offsetData['offsets'];
    $offsetDefault = $offsetData['default'];
    $defaultStart = $offsetData['default_start'];
    $minPages = loadMinStartPagesFromTr($dataPath);
    foreach ($minPages as $key => $minStart) {
        if (array_key_exists($key, $offsets)) {
            $offsets[$key] = $offsets[$key] - $minStart;
            continue;
        }
        $offsets[$key] = $defaultStart - $minStart;
    }
    $debug = (string) ($params['debug'] ?? '') === '1';
    $debugInfo = null;
    if ($debug) {
        $trBookPath = rtrim(PROJECT_ROOT, '/') . '/tr-book';
        $linkTarget = is_link($trBookPath) ? readlink($trBookPath) : null;
        $sharePath = '/share';
        $shareFilePath = '/share/file';
        $debugInfo = [
            'project_root' => rtrim(PROJECT_ROOT, '/'),
            'env_path' => $envPath,
            'env_exists' => is_file($envPath),
            'data_path' => $dataPath,
            'realpath' => realpath($dataPath) ?: null,
            'is_file' => is_file($dataPath),
            'is_readable' => is_readable($dataPath),
            'file_exists' => file_exists($dataPath),
            'dir_exists' => is_dir(dirname($dataPath)),
            'dir_readable' => is_readable(dirname($dataPath)),
            'tr_book_path' => $trBookPath,
            'tr_book_is_link' => is_link($trBookPath),
            'tr_book_link_target' => $linkTarget,
            'tr_book_realpath' => realpath($trBookPath) ?: null,
            'link_target_exists' => $linkTarget ? file_exists($linkTarget) : null,
            'link_target_readable' => $linkTarget ? is_readable($linkTarget) : null,
            'link_target_dir_exists' => $linkTarget ? is_dir($linkTarget) : null,
            'share_exists' => file_exists($sharePath),
            'share_is_dir' => is_dir($sharePath),
            'share_readable' => is_readable($sharePath),
            'share_file_exists' => file_exists($shareFilePath),
            'share_file_is_dir' => is_dir($shareFilePath),
            'share_file_readable' => is_readable($shareFilePath),
            'php_sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'doc_root' => (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),
            'script_filename' => (string)($_SERVER['SCRIPT_FILENAME'] ?? ''),
            'cwd' => getcwd() ?: null,
            'open_basedir' => (string) ini_get('open_basedir'),
        ];
    }

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
                'min_data' => null,
                'pdf_base' => $pdfBasePath,
                'pdf_offsets' => $offsets,
                'pdf_offset_default' => $offsetDefault,
                'file_offsets' => $offsets,
                'file_offset_default' => $offsetDefault,
            ],
        ];
        if ($debugInfo !== null) {
            $payload['debug'] = $debugInfo;
        }
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(500);
    }

    $titleQuery = trim((string) ($params['title'] ?? ''));
    $titleMode = (string) ($params['title_mode'] ?? 'keyword');
    $authorQuery = trim((string) ($params['author'] ?? ''));
    $authorMode = (string) ($params['author_mode'] ?? 'keyword');
    $caseSensitive = ($params['case_sensitive'] ?? '0') === '1';
    $wordMatch = ($params['word_match'] ?? '0') === '1';

    $fromYear = (int) ($params['from_year'] ?? 0);
    $fromMonth = (int) ($params['from_month'] ?? 0);
    $toYear = (int) ($params['to_year'] ?? 0);
    $toMonth = (int) ($params['to_month'] ?? 0);

    $limit = (int) ($params['limit'] ?? 200);
    $offset = (int) ($params['offset'] ?? 0);
    $limit = max(1, min(1000, $limit));
    $offset = max(0, $offset);

    $errors = [];
    $titleChecker = buildChecker($titleQuery, $titleMode, $caseSensitive, $wordMatch, $errors, static function (array $record): string {
        return trim(($record['title'] ?? '') . ' ' . ($record['subtitle'] ?? ''));
    }, 'title');
    $authorChecker = buildChecker($authorQuery, $authorMode, $caseSensitive, $wordMatch, $errors, static function (array $record): string {
        return (string) ($record['author'] ?? '');
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
    $firstData = null;

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
                'year' => (int) ($row[0] ?? 0),
                'month' => (int) ($row[1] ?? 0),
                'title' => (string) ($row[2] ?? ''),
                'subtitle' => (string) ($row[3] ?? ''),
                'type' => (string) ($row[4] ?? ''),
                'start_page' => (string) ($row[5] ?? ''),
                'page_count' => (string) ($row[6] ?? ''),
                'author' => (string) ($row[7] ?? ''),
            ];
            if ($record['year'] > 0 && $record['month'] > 0) {
                $ym = sprintf('%04d%02d', $record['year'], $record['month']);
                $yearDir = rtrim($pdfFsBase, '/') . '/' . sprintf('%04d', $record['year']);
                $candidates = [
                    $yearDir . '/TR' . $ym . '.PDF',
                    $yearDir . '/TR' . $ym . '.pdf',
                    $yearDir . '/DPMTR' . $ym . '_toragimmrelease.pdf',
                ];
                $record['pdf_exists'] = false;
                $record['file_exists'] = false;
                foreach ($candidates as $candidate) {
                    if (is_file($candidate)) {
                        $record['pdf_exists'] = true;
                        $record['pdf_name'] = basename($candidate);
                        $record['file_exists'] = true;
                        $record['file_name'] = basename($candidate);
                        break;
                    }
                }
                if (!isset($record['pdf_name'])) {
                    $record['pdf_name'] = null;
                }
                if (!isset($record['file_name'])) {
                    $record['file_name'] = null;
                }
            } else {
                $record['pdf_exists'] = false;
                $record['pdf_name'] = null;
                $record['file_exists'] = false;
                $record['file_name'] = null;
            }

            if ($record['year'] > 0 && $record['month'] > 0) {
                if ($firstData === null) {
                    $firstData = ['year' => $record['year'], 'month' => $record['month']];
                }
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
            'min_data' => $firstData,
            'pdf_base' => $pdfBasePath,
            'pdf_offsets' => $offsets,
            'pdf_offset_default' => $offsetDefault,
            'file_offsets' => $offsets,
            'file_offset_default' => $offsetDefault,
        ],
        'items' => $items,
    ];
    if ($debugInfo !== null) {
        $payload['debug'] = $debugInfo;
    }

    if (!empty($errors)) {
        $payload['errors'] = $errors;
    }

    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});


$app->get('/export', function (Request $request, Response $response) use ($envPath): Response {
    $params = $request->getQueryParams();
    $dataPath = resolvePath(getEnvValue('TR_DATA_PATH', 'tr-book/TRDB/TR.txt'));

    if (!is_file($dataPath)) {
        return withJson($response, ['ok' => false, 'error' => 'TR.txt not found.'], 500);
    }

    $titleQuery = trim((string) ($params['title'] ?? ''));
    $titleMode = (string) ($params['title_mode'] ?? 'keyword');
    $authorQuery = trim((string) ($params['author'] ?? ''));
    $authorMode = (string) ($params['author_mode'] ?? 'keyword');
    $caseSensitive = ($params['case_sensitive'] ?? '0') === '1';
    $wordMatch = ($params['word_match'] ?? '0') === '1';

    $fromYear = (int) ($params['from_year'] ?? 0);
    $fromMonth = (int) ($params['from_month'] ?? 0);
    $toYear = (int) ($params['to_year'] ?? 0);
    $toMonth = (int) ($params['to_month'] ?? 0);

    $errors = [];
    $titleChecker = buildChecker($titleQuery, $titleMode, $caseSensitive, $wordMatch, $errors, static function (array $record): string {
        return trim(($record['title'] ?? '') . ' ' . ($record['subtitle'] ?? ''));
    }, 'title');
    $authorChecker = buildChecker($authorQuery, $authorMode, $caseSensitive, $wordMatch, $errors, static function (array $record): string {
        return (string) ($record['author'] ?? '');
    }, 'author');

    $range = buildRange($fromYear, $fromMonth, $toYear, $toMonth);

    $handle = fopen($dataPath, 'rb');
    if ($handle === false) {
        return withJson($response, ['ok' => false, 'error' => 'Failed to open TR.txt.'], 500);
    }

    fgets($handle);
    $csvHandle = fopen('php://temp', 'r+');
    if ($csvHandle === false) {
        fclose($handle);
        return withJson($response, ['ok' => false, 'error' => 'Failed to create export buffer.'], 500);
    }

    fputcsv($csvHandle, ['year', 'month', 'title', 'subtitle', 'type', 'start_page', 'page_count', 'author']);

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
            'year' => (int) ($row[0] ?? 0),
            'month' => (int) ($row[1] ?? 0),
            'title' => (string) ($row[2] ?? ''),
            'subtitle' => (string) ($row[3] ?? ''),
            'type' => (string) ($row[4] ?? ''),
            'start_page' => (string) ($row[5] ?? ''),
            'page_count' => (string) ($row[6] ?? ''),
            'author' => (string) ($row[7] ?? ''),
        ];

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

        fputcsv($csvHandle, [
            $record['year'],
            $record['month'],
            $record['title'],
            $record['subtitle'],
            $record['type'],
            $record['start_page'],
            $record['page_count'],
            $record['author'],
        ]);
    }

    fclose($handle);
    rewind($csvHandle);
    $csv = stream_get_contents($csvHandle);
    fclose($csvHandle);

    if ($csv === false) {
        return withJson($response, ['ok' => false, 'error' => 'Failed to read export buffer.'], 500);
    }

    $csv = mb_convert_encoding($csv, 'SJIS-win', 'UTF-8');
    $response->getBody()->write($csv);

    return $response
        ->withHeader('Content-Type', 'text/csv; charset=Shift_JIS')
        ->withHeader('Content-Disposition', 'attachment; filename="tr_search_all.csv"');
});

