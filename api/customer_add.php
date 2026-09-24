<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request data.']);
    exit;
}

$customerName = trim((string)($data['customer_name'] ?? ''));
$customerNo   = strtoupper(trim((string)($data['customer_no'] ?? '')));
$phone        = trim((string)($data['phone'] ?? ''));
$loweRep      = trim((string)($data['lowe_rep'] ?? ''));
$address      = trim((string)($data['address'] ?? ''));
$city         = trim((string)($data['city'] ?? ''));
$state        = strtoupper(trim((string)($data['state'] ?? '')));
$zipcode      = trim((string)($data['zipcode'] ?? ''));

if ($customerName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Company name is required.']);
    exit;
}

if ($customerNo === '') {
    $customerNo = 'WEB' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

try {
    $pdo = db();

    $check = $pdo->prepare('SELECT customer_no, customer_name, phone, lowe_rep, address, city, state, zipcode FROM customers WHERE customer_no = ? LIMIT 1');
    $check->execute([$customerNo]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'That customer number already exists. Use the existing customer or enter a different customer number.']);
        exit;
    }

    // Warn against obvious accidental duplicates without blocking legitimate same-name locations.
    $dup = $pdo->prepare('SELECT customer_no, customer_name FROM customers WHERE LOWER(customer_name) = LOWER(?) AND zipcode = ? LIMIT 1');
    $dup->execute([$customerName, $zipcode]);
    $existing = $dup->fetch();
    if ($existing && $zipcode !== '') {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'A customer with this company name and ZIP already exists: ' . $existing['customer_no'] . ' - ' . $existing['customer_name'] . '.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO customers (customer_no, customer_name, phone, lowe_rep, address, city, state, zipcode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$customerNo, $customerName, $phone, $loweRep, $address, $city, $state, $zipcode]);

    echo json_encode([
        'ok' => true,
        'customer' => [
            'customer_no' => $customerNo,
            'customer_name' => $customerName,
            'phone' => $phone,
            'lowe_rep' => $loweRep,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'zipcode' => $zipcode
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to add customer to the database.']);
}
