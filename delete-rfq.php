<?php
session_start();
require __DIR__ . '/config/db.php';

function fail(string $message, int $code=400): never {
    http_response_code($code);
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>body{margin:0;background:#f4f7fa;color:#17212b;font:15px Arial,sans-serif;padding:35px}.box{max-width:760px;margin:auto;background:#fff;border:1px solid #d9e0e6;border-radius:14px;padding:24px}a{color:#174f86}</style>
    <div class="box"><h1>RFQ could not be deleted</h1><p>'.$safe.'</p><p><a href="rfq-list.php">Return to RFQs</a></p></div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: rfq-list.php');
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!$token || empty($_SESSION['rfq_delete_csrf']) || !hash_equals($_SESSION['rfq_delete_csrf'], $token)) {
    fail('Your session expired. Return to the RFQ list and try again.', 403);
}

$id = filter_input(INPUT_POST, 'rfq_id', FILTER_VALIDATE_INT);
if (!$id) fail('Invalid RFQ.');

$pdo = db();

$stmt = $pdo->prepare('SELECT rfq_number FROM rfqs WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$rfqNumber = $stmt->fetchColumn();
if (!$rfqNumber) fail('RFQ not found.', 404);

try {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM rfq_supplier_responses WHERE rfq_id=?')->execute([$id]);
    $pdo->prepare('DELETE a FROM rfq_supplier_access a JOIN rfq_suppliers rs ON rs.id=a.rfq_supplier_id WHERE rs.rfq_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM rfq_status_history WHERE rfq_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM rfq_suppliers WHERE rfq_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM rfqs WHERE id=?')->execute([$id]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Database delete failed: '.$e->getMessage(), 500);
}

$_SESSION['rfq_delete_csrf'] = bin2hex(random_bytes(24));

header('Location: rfq-list.php?deleted=1&rfq='.rawurlencode((string)$rfqNumber));
exit;
