<?php
session_start();
require __DIR__ . '/config/db.php';

function post(string $key, string $default=''): string {
    return trim((string)($_POST[$key] ?? $default));
}

function fail(string $msg, int $code=400): never {
    http_response_code($code);
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
    body{font-family:Arial;background:#f4f7fa;padding:40px}
    .box{max-width:700px;margin:auto;background:#fff;padding:24px;border:1px solid #d9e0e6;border-radius:14px}
    </style>
    <div class="box">
    <h1>RFQ could not be updated</h1>
    <p>'.htmlspecialchars($msg,ENT_QUOTES,'UTF-8').'</p>
    <p><a href="javascript:history.back()">Return to the RFQ</a></p>
    </div>';
    exit;
}

/*
 * update-rfq.php is a form-processing endpoint.
 * If someone opens it directly, return them to the RFQ dashboard
 * instead of displaying an "Invalid request method" error.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: rfq-list.php');
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');

if (
    !$token ||
    empty($_SESSION['rfq_edit_csrf']) ||
    !hash_equals($_SESSION['rfq_edit_csrf'], $token)
) {
    fail('Your session expired. Reopen the RFQ and try again.');
}

unset($_SESSION['rfq_edit_csrf']);

$id = filter_input(INPUT_POST, 'rfq_id', FILTER_VALIDATE_INT);

if (!$id) {
    fail('Invalid RFQ.');
}

$pdo = db();

$q = $pdo->prepare('SELECT * FROM rfqs WHERE id=? LIMIT 1');
$q->execute([$id]);
$old = $q->fetch();

if (!$old) {
    fail('RFQ not found.', 404);
}

$names = $_POST['supplier_name'] ?? [];
$contacts = $_POST['supplier_contact'] ?? [];
$emails = $_POST['supplier_email'] ?? [];
$suppliers = [];

foreach ($names as $i => $nameRaw) {
    $name = trim((string)$nameRaw);

    if ($name === '') {
        continue;
    }

    $email = trim((string)($emails[$i] ?? ''));

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('One of the supplier email addresses is invalid.');
    }

    $suppliers[] = [
        'name' => $name,
        'contact' => trim((string)($contacts[$i] ?? '')),
        'email' => $email
    ];
}

if (!$suppliers) {
    fail('At least one supplier is required.');
}

$allHaveEmail = !array_filter(
    $suppliers,
    fn($s) => $s['email'] === ''
);

$newStatus = $old['status'] === 'Sent'
    ? 'Sent'
    : ($allHaveEmail ? 'Ready for Email' : 'Draft');

try {
    $pdo->beginTransaction();

    $sql = 'UPDATE rfqs SET
        pricing_needed_by=?,
        requester_name=?,
        requester_email=?,
        requester_phone=?,
        product_name=?,
        cas_number=?,
        product_grade=?,
        packaging=?,
        container_weight=?,
        container_weight_uom=?,
        quantity=?,
        quantity_uom=?,
        price_basis=?,
        estimated_annual_usage=?,
        requirement_type=?,
        ship_city=?,
        ship_state=?,
        ship_zip=?,
        special_requirements=?,
        cc_requester=?,
        cc_sourcing=?,
        additional_cc=?,
        email_format=?,
        email_subject=?,
        reply_to=?,
        status=?
        WHERE id=?';

    $pdo->prepare($sql)->execute([
        post('pricing_needed_by') ?: null,
        post('requester_name'),
        post('requester_email'),
        post('requester_phone') ?: null,
        post('product_name'),
        post('cas_number') ?: null,
        post('product_grade') ?: null,
        post('packaging') ?: null,
        post('container_weight') !== '' ? post('container_weight') : null,
        post('container_weight_uom') ?: null,
        post('quantity'),
        post('quantity_uom'),
        post('price_basis') ?: null,
        post('estimated_annual_usage') ?: null,
        post('requirement_type') ?: null,
        post('ship_city'),
        post('ship_state'),
        post('ship_zip'),
        post('special_requirements') ?: null,
        isset($_POST['cc_requester']) ? 1 : 0,
        isset($_POST['cc_sourcing']) ? 1 : 0,
        post('additional_cc') ?: null,
        post('email_format', 'email'),
        post('email_subject') ?: null,
        'Sourcing@lowechemical.com',
        $newStatus,
        $id
    ]);

    $pdo->prepare('DELETE FROM rfq_suppliers WHERE rfq_id=?')->execute([$id]);

    $lookup = $pdo->prepare(
        'SELECT id FROM suppliers WHERE supplier_name=? LIMIT 1'
    );

    $ins = $pdo->prepare(
        'INSERT INTO rfq_suppliers
        (rfq_id,supplier_id,supplier_name,contact_name,contact_email,sort_order,email_status)
        VALUES (?,?,?,?,?,?,?)'
    );

    foreach ($suppliers as $i => $s) {
        $lookup->execute([$s['name']]);
        $sid = $lookup->fetchColumn();

        $emailStatus = $s['email'] !== '' ? 'Ready' : 'Not Ready';

        $ins->execute([
            $id,
            $sid ?: null,
            $s['name'],
            $s['contact'] ?: null,
            $s['email'] ?: null,
            $i + 1,
            $emailStatus
        ]);
    }

    $pdo->prepare(
        'INSERT INTO rfq_status_history
        (rfq_id,old_status,new_status,changed_by,note)
        VALUES (?,?,?,?,?)'
    )->execute([
        $id,
        $old['status'],
        $newStatus,
        post('requester_email'),
        'RFQ details edited and saved.'
    ]);

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fail('Database update failed: '.$e->getMessage(), 500);
}

header('Location: rfq-view.php?id='.(int)$id.'&updated=1');
exit;
