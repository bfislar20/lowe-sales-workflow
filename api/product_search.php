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
    $stmt = db()->prepare("SELECT product_number, product_description, container, container_weight
        FROM products
        WHERE product_number LIKE :number ESCAPE '!'
           OR product_description LIKE :description ESCAPE '!'
        ORDER BY product_description, product_number LIMIT 25");
    $stmt->execute([':number' => $like, ':description' => $like]);
    echo json_encode(['ok' => true, 'results' => $stmt->fetchAll()], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('Product search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Product search is unavailable.']);
}
