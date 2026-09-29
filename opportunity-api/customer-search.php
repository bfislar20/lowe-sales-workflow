<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/database.php';

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

try {
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
    $stmt = db()->prepare("SELECT customer_no, customer_name, phone, lowe_rep, address, city, state, zipcode
        FROM customers
        WHERE customer_name LIKE :name ESCAPE '!'
           OR customer_no LIKE :number ESCAPE '!'
           OR city LIKE :city ESCAPE '!'
           OR zipcode LIKE :zip ESCAPE '!'
        ORDER BY customer_name, customer_no LIMIT 25");
    $stmt->execute([':name' => $like, ':number' => $like, ':city' => $like, ':zip' => $like]);
    echo json_encode(['ok' => true, 'results' => $stmt->fetchAll()], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('Opportunity customer search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Customer search is unavailable.']);
}
