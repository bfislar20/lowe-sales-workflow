<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/database.php';

$customerNo = trim((string)($_GET['customer_no'] ?? ''));
if ($customerNo === '') {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}
if (strlen($customerNo) > 50) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Customer number is too long.']);
    exit;
}

try {
    $stmt = db()->prepare('SELECT h.product_number,
            COALESCE(p.product_description, h.product_name) AS product_description,
            h.product_name AS history_product_name,
            h.uom, h.lbs_per_uom, p.container, p.container_weight
        FROM customer_product_history h
        LEFT JOIN products p ON p.product_number = h.product_number
        WHERE h.customer_no = :customer_no
        ORDER BY h.product_name, h.product_number LIMIT 200');
    $stmt->execute([':customer_no' => $customerNo]);
    echo json_encode(['ok' => true, 'results' => $stmt->fetchAll()], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('Customer product history search failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Customer product history is unavailable.']);
}
