<?php
session_start();
require __DIR__ . '/config/db.php';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$pdo = db();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$rfqNumber = trim((string)($_GET['rfq'] ?? ''));

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM rfqs WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
} elseif ($rfqNumber !== '') {
    $stmt = $pdo->prepare('SELECT * FROM rfqs WHERE rfq_number=? LIMIT 1');
    $stmt->execute([$rfqNumber]);
} else {
    header('Location: rfq-list.php?message=' . urlencode('Choose an RFQ to open.'));
    exit;
}

$rfq = $stmt->fetch();
if (!$rfq) {
    http_response_code(404);
    exit('RFQ not found. <a href="rfq-list.php">Return to RFQ list</a>.');
}
$id = (int)$rfq['id'];
$s = $pdo->prepare('SELECT * FROM rfq_suppliers WHERE rfq_id=? ORDER BY sort_order,id');
$s->execute([$id]); $suppliers = $s->fetchAll();
$hist = $pdo->prepare('SELECT * FROM rfq_status_history WHERE rfq_id=? ORDER BY id DESC LIMIT 30');
$hist->execute([$id]); $history = $hist->fetchAll();
$_SESSION['rfq_send_csrf'] = bin2hex(random_bytes(24));
$canSend = count($suppliers) > 0;
foreach ($suppliers as $supplier) {
    if (empty($supplier['contact_email']) || !filter_var($supplier['contact_email'], FILTER_VALIDATE_EMAIL)) $canSend = false;
}
$isResend = $rfq['status'] === 'Sent';
function showv($v): string { $v=trim((string)$v); return $v!=='' ? h($v) : '<span class="muted">Not specified</span>'; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($rfq['rfq_number']) ?> | Lowe Chemical</title>
<style>
body{margin:0;background:#f4f7fa;color:#17212b;font:14px/1.45 Arial,sans-serif}.top{background:#0c2340;color:#fff;padding:18px}.topin,.wrap{max-width:1100px;margin:auto}.topin{display:flex;align-items:center;gap:14px}.top img{max-height:46px;max-width:220px;background:#fff;padding:3px;border-radius:4px}.wrap{padding:22px}.head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.card{background:#fff;border:1px solid #d9e0e6;border-radius:14px;padding:20px;margin:14px 0}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 24px}.field{padding:9px 0;border-bottom:1px solid #edf0f2}.label{font-size:11px;text-transform:uppercase;color:#687684;font-weight:700}.value{margin-top:3px}.muted{color:#8a959f}.status{display:inline-block;padding:6px 10px;border-radius:999px;background:#eaf2f9;color:#174f86;font-weight:700}.btn{display:inline-block;border:0;border-radius:9px;padding:10px 15px;font-weight:700;text-decoration:none;cursor:pointer}.primary{background:#0c2340;color:#fff}.send{background:#17613c;color:#fff}.secondary{background:#e9eef3;color:#243646}.dangerbox{background:#fff5f5;border-left:4px solid #a62929;padding:12px 14px}.info{background:#eef5fb;border-left:4px solid #174f86;padding:12px 14px}.supplier{display:grid;grid-template-columns:1.4fr 1fr 1.4fr .8fr;gap:10px;padding:10px 0;border-bottom:1px solid #edf0f2}.history{width:100%;border-collapse:collapse}.history td,.history th{padding:9px;border-bottom:1px solid #edf0f2;text-align:left}.history th{font-size:11px;text-transform:uppercase;color:#687684}.actions{display:flex;gap:9px;flex-wrap:wrap}@media(max-width:720px){.wrap{padding:14px}.head{flex-direction:column}.grid{grid-template-columns:1fr}.supplier{grid-template-columns:1fr}.history-wrap{overflow:auto}.history{min-width:760px}}
</style></head><body>
<div class="top"><div class="topin"><img src="images/lowe-logo.png" alt="Lowe Chemical Company"><strong>Internal Sourcing</strong></div></div>
<main class="wrap">
<div class="head"><div><h1 style="color:#0c2340;margin-bottom:5px"><?= h($rfq['rfq_number']) ?></h1><span class="status"><?= h($rfq['status']) ?></span></div><div class="actions"><a class="btn secondary" href="rfq-list.php">Back to RFQs</a><a class="btn primary" href="vendor-pricing-request.php">New Pricing Request</a></div></div>
<div class="card"><h2 style="color:#0c2340;margin-top:0">Request Details</h2><div class="grid">
<?php
$fields = [
'Request Date'=>$rfq['request_date'],'Pricing Needed By'=>$rfq['pricing_needed_by'],'Requested By'=>$rfq['requester_name'],'Requester Email'=>$rfq['requester_email'],
'Product Name'=>$rfq['product_name'],'CAS Number'=>$rfq['cas_number'],'Product Grade'=>$rfq['product_grade'],'Packaging'=>$rfq['packaging'],
'Container Weight'=>trim((string)$rfq['container_weight'].' '.(string)$rfq['container_weight_uom']),'Quantity'=>trim((string)$rfq['quantity'].' '.(string)$rfq['quantity_uom']),
'Price Basis'=>$rfq['price_basis'],'Annual Usage'=>$rfq['estimated_annual_usage'],'Requirement Type'=>$rfq['requirement_type'],
'Delivery'=>trim($rfq['ship_city'].', '.$rfq['ship_state'].' '.$rfq['ship_zip']),'Email Format'=>$rfq['email_format'],'Reply-To'=>$rfq['reply_to']
];
foreach($fields as $label=>$value): ?><div class="field"><div class="label"><?= h($label) ?></div><div class="value"><?= showv($value) ?></div></div><?php endforeach; ?>
</div><?php if(trim((string)$rfq['special_requirements'])!==''): ?><div class="field"><div class="label">Special Requirements / Notes</div><div class="value"><?= nl2br(h($rfq['special_requirements'])) ?></div></div><?php endif; ?></div>
<div class="card"><h2 style="color:#0c2340;margin-top:0">Supplier Recipients</h2>
<?php foreach($suppliers as $supplier): ?><div class="supplier"><div><div class="label">Supplier</div><strong><?= h($supplier['supplier_name']) ?></strong></div><div><div class="label">Contact</div><?= showv($supplier['contact_name']) ?></div><div><div class="label">Email</div><?= showv($supplier['contact_email']) ?></div><div><div class="label">Email Status</div><?= h($supplier['email_status']) ?></div></div><?php endforeach; ?>
<?php if(!$suppliers): ?><p>No suppliers are attached to this RFQ.</p><?php endif; ?></div>
<div class="card" id="send"><h2 style="color:#0c2340;margin-top:0"><?= $isResend ? 'Resend RFQ' : 'Send RFQ' ?></h2>
<?php if($canSend): ?>
<div class="info">The supplier addresses above will be sent as <strong>BCC</strong>. Reply-To will be <strong>Sourcing@lowechemical.com</strong>. <?= $rfq['email_format']==='email_pdf' ? 'The Lowe-branded RFQ PDF will be attached.' : 'This RFQ is configured for email only.' ?></div>
<form method="post" action="send-rfq.php" style="margin-top:15px" onsubmit="return confirm('<?= $isResend ? 'Resend' : 'Send' ?> <?= h($rfq['rfq_number']) ?> to the listed supplier recipient<?= count($suppliers)===1?'':'s' ?>?');">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_send_csrf']) ?>"><input type="hidden" name="rfq_id" value="<?= (int)$rfq['id'] ?>">
<button class="btn send" type="submit"><?= $isResend ? 'Resend RFQ Now' : 'Send RFQ Now' ?></button>
</form>
<?php else: ?><div class="dangerbox"><strong>This RFQ cannot be sent yet.</strong><br>Every selected supplier must have a valid email address. Add the missing supplier contact information before sending.</div><?php endif; ?>
</div>
<div class="card"><h2 style="color:#0c2340;margin-top:0">History</h2><div class="history-wrap"><table class="history"><thead><tr><th>Date/Time</th><th>From</th><th>To</th><th>Changed By</th><th>Note</th></tr></thead><tbody><?php foreach($history as $row): ?><tr><td><?= h($row['created_at']) ?></td><td><?= showv($row['old_status']) ?></td><td><?= h($row['new_status']) ?></td><td><?= showv($row['changed_by']) ?></td><td><?= showv($row['note']) ?></td></tr><?php endforeach; ?><?php if(!$history): ?><tr><td colspan="5">No history recorded.</td></tr><?php endif; ?></tbody></table></div></div>
</main></body></html>
