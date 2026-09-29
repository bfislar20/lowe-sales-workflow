<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$query = trim((string)($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}
if (strlen($query) > 100) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Search text is too long.']);
    exit;
}

$path = __DIR__ . '/../data/lowe-personnel.csv';
if (!is_readable($path) || ($file = fopen($path, 'r')) === false) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Personnel file is unavailable.']);
    exit;
}

$results = [];
try {
    $headers = fgetcsv($file);
    if ($headers !== false) {
        $headers = array_map(static fn($header) => preg_replace('/^\xEF\xBB\xBF/', '', trim((string)$header)), $headers);
        while (($values = fgetcsv($file)) !== false && count($results) < 25) {
            $row = array_combine($headers, array_slice(array_pad($values, count($headers), ''), 0, count($headers)));
            if ($row === false) continue;
            $name = trim((string)($row['Sales Rep'] ?? $row['Name'] ?? $row['Full Name'] ?? ''));
            if ($name === '' || stripos($name, $query) === false) continue;
            $results[] = [
                'name' => $name,
                'email' => trim((string)($row['Email'] ?? $row['Email Address'] ?? '')),
                'phone' => trim((string)($row['Phone Number'] ?? $row['Phone'] ?? '')),
            ];
        }
    }
    echo json_encode(['ok' => true, 'results' => $results], JSON_INVALID_UTF8_SUBSTITUTE);
} finally {
    fclose($file);
}
