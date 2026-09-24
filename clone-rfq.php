<?php
session_start();
require __DIR__ . '/config/db.php';

function post(string $key, string $default=''): string {
    return trim((string)($_POST[$key] ?? $default));
}

function fail(string $message, int $code=400): void {
    http_response_code($code);
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>body{margin:0;background:#f4f7fa;color:#17212b;font:15px Arial,sans-serif;padding:35px}.box{max-width:760px;margin:auto;background:#fff;border:1px solid #d9e0e6;border-radius:14px;padding:24px}a{color:#174f86}</style>
    <div class="box"><h1>New RFQ could not be created</h1><p>'.$safe.'</p><p><a href="javascript:history.back()">Return to the RFQ</a></p></div>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: rfq-list.php');
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!$token || empty($_SESSION['rfq_edit_csrf']) || !hash_equals($_SESSION['rfq_edit_csrf'], $token)) {
    fail('Your session expired. Reopen the RFQ and try again.', 403);
}

$originalId = filter_input(INPUT_POST, 'rfq_id', FILTER_VALIDATE_INT);
if (!$originalId) fail('Invalid original RFQ.');

$newSupplierName = post('new_supplier_name');
$newSupplierContact = post('new_supplier_contact');
$newSupplierEmail = post('new_supplier_email');

if ($newSupplierName === '') fail('A new supplier is required.');
if ($newSupplierEmail === '' || !filter_var($newSupplierEmail, FILTER_VALIDATE_EMAIL)) {
    fail('A valid email address is required for the new supplier.');
}

$pdo = db();
$q = $pdo->prepare('SELECT * FROM rfqs WHERE id=? LIMIT 1');
$q->execute([$originalId]);
$old = $q->fetch(PDO::FETCH_ASSOC);
if (!$old) fail('The original RFQ could not be found.', 404);

try {
    $pdo->beginTransaction();

    // Reserve the next RFQ number using the same sequence used for normal new RFQs.
    $year = (int)date('Y');
    $seqStmt = $pdo->prepare('SELECT next_number FROM rfq_sequences WHERE sequence_year=? FOR UPDATE');
    $seqStmt->execute([$year]);
    $seq = $seqStmt->fetchColumn();
    if ($seq === false) {
        $number = 1;
        $pdo->prepare('INSERT INTO rfq_sequences (sequence_year,next_number) VALUES (?,2)')->execute([$year]);
    } else {
        $number = (int)$seq;
        $pdo->prepare('UPDATE rfq_sequences SET next_number=? WHERE sequence_year=?')->execute([$number + 1, $year]);
    }
    $newRfqNumber = sprintf('RFQ-%04d-%04d', $year, $number);

    // Start with every field from the original RFQ so fields added to the table later
    // continue to carry forward. Then replace fields currently editable on rfq-edit.php.
    $new = $old;
    $new['rfq_number'] = $newRfqNumber;
    $new['request_date'] = date('Y-m-d');
    $new['status'] = 'Ready for Email';

    $textOverrides = [
        'pricing_needed_by','requester_name','requester_email','requester_phone',
        'product_name','cas_number','product_grade','packaging','container_weight_uom',
        'quantity','quantity_uom','price_basis','estimated_annual_usage','requirement_type',
        'ship_city','ship_state','ship_zip','special_requirements','additional_cc','email_subject'
    ];
    foreach ($textOverrides as $field) {
        if (array_key_exists($field, $_POST) && array_key_exists($field, $new)) {
            $value = post($field);
            $new[$field] = $value === '' ? null : $value;
        }
    }

    if (array_key_exists('container_weight', $new) && array_key_exists('container_weight', $_POST)) {
        $weight = post('container_weight');
        $new['container_weight'] = $weight === '' ? null : $weight;
    }
    if (array_key_exists('cc_requester', $new)) $new['cc_requester'] = isset($_POST['cc_requester']) ? 1 : 0;
    if (array_key_exists('cc_sourcing', $new)) $new['cc_sourcing'] = isset($_POST['cc_sourcing']) ? 1 : 0;
    if (array_key_exists('reply_to', $new)) $new['reply_to'] = 'Sourcing@lowechemical.com';
    if (array_key_exists('email_format', $new)) {
        $fmt = post('new_email_format', post('email_format', 'email_pdf'));
        $new['email_format'] = in_array($fmt, ['email_only','email_pdf'], true) ? $fmt : 'email_pdf';
    }

    // New RFQs must not inherit send timestamps from the original record.
    foreach (['sent_at','last_sent_at','emailed_at'] as $field) {
        if (array_key_exists($field, $new)) $new[$field] = null;
    }

    // Build the INSERT from the live table definition. Skip auto/generated/timestamp audit fields.
    $columns = $pdo->query('SHOW COLUMNS FROM rfqs')->fetchAll(PDO::FETCH_ASSOC);
    $insertCols = [];
    $insertVals = [];
    foreach ($columns as $col) {
        $field = (string)$col['Field'];
        $extra = strtolower((string)($col['Extra'] ?? ''));
        if ($field === 'id' || in_array($field, ['created_at','updated_at'], true)) continue;
        if (str_contains($extra, 'auto_increment') || str_contains($extra, 'generated')) continue;
        $insertCols[] = $field;
        $insertVals[] = $new[$field] ?? null;
    }

    if (!in_array('rfq_number', $insertCols, true)) {
        throw new RuntimeException('The RFQ number column could not be prepared.');
    }

    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $sql = 'INSERT INTO rfqs (`'.implode('`,`', $insertCols).'`) VALUES ('.$placeholders.')';
    $pdo->prepare($sql)->execute($insertVals);
    $newId = (int)$pdo->lastInsertId();

    // Attach only the new supplier to the new RFQ.
    $lookup = $pdo->prepare('SELECT id FROM suppliers WHERE supplier_name=? LIMIT 1');
    $lookup->execute([$newSupplierName]);
    $supplierId = $lookup->fetchColumn();

    $ins = $pdo->prepare('INSERT INTO rfq_suppliers
        (rfq_id,supplier_id,supplier_name,contact_name,contact_email,sort_order,email_status)
        VALUES (?,?,?,?,?,?,?)');
    $ins->execute([
        $newId,
        $supplierId ?: null,
        $newSupplierName,
        $newSupplierContact !== '' ? $newSupplierContact : null,
        $newSupplierEmail,
        1,
        'Ready'
    ]);

    $history = $pdo->prepare('INSERT INTO rfq_status_history
        (rfq_id,old_status,new_status,changed_by,note) VALUES (?,NULL,?,?,?)');
    $history->execute([
        $newId,
        'Ready for Email',
        post('requester_email', (string)($old['requester_email'] ?? '')),
        'New RFQ created from '.$old['rfq_number'].' for supplier '.$newSupplierName.'. Original RFQ left unchanged.'
    ]);

    $pdo->commit();
    unset($_SESSION['rfq_edit_csrf']);

    header('Location: rfq-view.php?id='.$newId.'&created_from='.rawurlencode((string)$old['rfq_number']));
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Database creation failed: '.$e->getMessage(), 500);
}
