<?php
session_start();
require __DIR__ . '/config/db.php';

$error = null;
$rows = [];

if (empty($_SESSION['rfq_delete_csrf'])) {
    $_SESSION['rfq_delete_csrf'] = bin2hex(random_bytes(24));
}

if (empty($_SESSION['rfq_export_csrf'])) {
    $_SESSION['rfq_export_csrf'] = bin2hex(random_bytes(24));
}

try {
    $rows = db()->query("
        SELECT
            r.id,r.rfq_number,r.request_date,r.pricing_needed_by,r.requester_name,
            r.product_name,r.quantity,r.quantity_uom,r.ship_city,r.ship_state,r.status,
            COUNT(rs.id) supplier_count,
            GROUP_CONCAT(DISTINCT rs.supplier_name ORDER BY rs.sort_order SEPARATOR ', ') AS supplier_names,
            MAX(rs.sent_at) last_sent_at,
            SUM(CASE WHEN rs.email_status='Sent' THEN 1 ELSE 0 END) sent_supplier_count,
            COALESCE(resp.response_count,0) response_count
        FROM rfqs r
        LEFT JOIN rfq_suppliers rs ON rs.rfq_id=r.id
        LEFT JOIN (
            SELECT rfq_id, COUNT(*) AS response_count
            FROM rfq_supplier_responses
            GROUP BY rfq_id
        ) resp ON resp.rfq_id=r.id
        GROUP BY r.id, resp.response_count
        ORDER BY r.id DESC
        LIMIT 200
    ")->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function sentDisplay(?string $value): string {
    if (!$value) return 'Not sent';

    try {
        // SiteGround/MySQL timestamps are treated as UTC here, then converted
        // to Lowe's Eastern Time. America/New_York automatically shows
        // EDT during daylight saving time and EST during standard time.
        $utc = new DateTimeZone('UTC');
        $eastern = new DateTimeZone('America/New_York');

        $dt = new DateTime($value, $utc);
        $dt->setTimezone($eastern);

        return $dt->format('M j, Y g:i A T');
    } catch (Throwable $e) {
        return $value;
    }
}

$deleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';
$deletedRfq = trim((string)($_GET['rfq'] ?? ''));

$exportStatus = trim((string)($_GET['export'] ?? ''));
$exportMessage = trim((string)($_GET['message'] ?? ''));

$responseFilter = trim((string)($_GET['response'] ?? ''));
if (!in_array($responseFilter, ['', 'received', 'awaiting'], true)) $responseFilter = '';

$totalResponses = 0;
$rfqsWithResponses = 0;
foreach ($rows as $row) {
    $count = (int)($row['response_count'] ?? 0);
    $totalResponses += $count;
    if ($count > 0) $rfqsWithResponses++;
}

if ($responseFilter === 'received') {
    $rows = array_values(array_filter($rows, static fn($row) => (int)($row['response_count'] ?? 0) > 0));
} elseif ($responseFilter === 'awaiting') {
    $rows = array_values(array_filter($rows, static fn($row) => (int)($row['response_count'] ?? 0) === 0));
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Supplier Pricing RFQs | Lowe Chemical</title>
<style>
body{margin:0;background:#f4f7fa;color:#17212b;font:14px/1.45 Arial,sans-serif}
.top{background:#0c2340;color:#fff;padding:18px}
.topin,.wrap{max-width:1280px;margin:auto}
.topin{display:flex;align-items:center;gap:14px}
.top img{max-height:42px;max-width:210px;background:#fff;padding:3px;border-radius:4px}
.wrap{padding:22px}
.card{background:#fff;border:1px solid #d9e0e6;border-radius:14px;overflow:hidden}
h1{color:#0c2340}
.toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center}
.btn{display:inline-block;border:0;text-decoration:none;padding:8px 11px;border-radius:8px;font-weight:700;white-space:nowrap;font-size:12px;cursor:pointer;font-family:inherit}
.btn.new{background:#0c2340;color:#fff;padding:10px 14px}.btn.export{background:#174f86;color:#fff;padding:10px 14px}.btn.download{background:#17613c;color:#fff;padding:10px 14px}.btn.workflow{background:#fff;color:#0c2340;border:1px solid #b9c7d3;padding:10px 14px}.toolbar-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.export-form{margin:0}
.btn.open{background:#eaf2f9;color:#174f86}
.btn.edit{background:#fff3d6;color:#6d4b00;border:1px solid #ecd390}
.btn.send{background:#17613c;color:#fff}
.btn.delete{background:#fff;color:#9b1c1c;border:1px solid #d9a3a3}
.actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.delete-form{margin:0}
table{width:100%;border-collapse:collapse}
th,td{padding:11px 10px;border-bottom:1px solid #e5eaee;text-align:left;vertical-align:middle}
th{background:#f8fafc;color:#51606f;font-size:11px;text-transform:uppercase}
.scroll{overflow:auto}
.rfq-link{color:#174f86;font-weight:700;text-decoration:none}
.pill{display:inline-block;padding:5px 8px;border-radius:999px;background:#eef3f7;font-weight:700}
.pill.sent{background:#e4f3ea;color:#17613c}
.pill.ready{background:#eaf2f9;color:#174f86}
.pill.draft{background:#f3f3f3;color:#555}
.email-sent{color:#17613c;font-weight:700}
.email-not-sent{color:#8a2424;font-weight:700}
.email-detail{display:block;color:#687684;font-size:11px;margin-top:2px}
.notice{background:#e7f4ec;border:1px solid #badcc7;color:#17613c;padding:11px 14px;border-radius:10px;margin:0 0 14px}
.response-summary{display:grid;grid-template-columns:repeat(2,minmax(0,220px));gap:10px;margin:0 0 14px}
.response-card{background:#fff;border:1px solid #d9e0e6;border-radius:10px;padding:12px 14px}
.response-card .label{font-size:10px;text-transform:uppercase;color:#687684;font-weight:700}
.response-card .value{font-size:22px;color:#0c2340;font-weight:800;margin-top:2px}
.response-filters{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 14px}
.filter-btn{display:inline-block;text-decoration:none;border:1px solid #c9d5df;background:#fff;color:#174f86;border-radius:999px;padding:7px 11px;font-weight:700;font-size:12px}
.filter-btn.active{background:#0c2340;color:#fff;border-color:#0c2340}
tr.has-response{background:#f1fbf5}
tr.has-response td:first-child{border-left:5px solid #2d8a55}
.response-badge{display:inline-block;background:#dff3e7;color:#17613c;border:1px solid #b9ddc6;border-radius:999px;padding:5px 8px;font-weight:800;font-size:11px;white-space:nowrap}
.response-none{color:#7a8792;font-size:12px}
.btn.responses{background:#17613c;color:#fff}
.response-cell{min-width:135px}
@media(max-width:800px){.wrap{padding:14px}.toolbar{align-items:flex-start;flex-direction:column}.toolbar-actions{width:100%;display:grid;grid-template-columns:1fr}.toolbar-actions .btn{width:100%}.response-summary{grid-template-columns:1fr 1fr}table{min-width:1580px}}
</style>
</head>
<body>

<div class="top"><div class="topin"><img src="images/lowe-logo.png" alt="Lowe Chemical Company"><strong>Internal Sourcing</strong></div></div>

<main class="wrap">
<div class="toolbar">
    <div><h1>Supplier Pricing RFQs</h1><p>Open, edit, send, resend, or delete saved requests.</p></div>
    <div class="toolbar-actions">
        <a class="btn workflow" href="salesworkflow.php">← Sales Workflow</a>
        <form class="export-form" method="post" action="download-rfqs-xlsx.php">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_export_csrf']) ?>">
            <button class="btn download" type="submit">Download All RFQs (.xlsx)</button>
        </form>
        <form class="export-form" method="post" action="email-rfqs-xlsx.php" onsubmit="return confirm('Email an Excel workbook containing all RFQs to Sourcing@lowechemical.com?');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_export_csrf']) ?>">
            <button class="btn export" type="submit">Email All RFQs (.xlsx)</button>
        </form>
        <a class="btn new" href="vendor-pricing-request.php">+ New Pricing Request</a>
    </div>
</div>

<?php if ($deleted): ?>
<div class="notice"><?= $deletedRfq !== '' ? h($deletedRfq).' was deleted.' : 'The RFQ was deleted.' ?></div>
<?php endif; ?>

<?php if ($exportStatus === 'sent'): ?>
<div class="notice">RFQ Excel workbook emailed to Sourcing@lowechemical.com.</div>
<?php elseif ($exportStatus === 'error'): ?>
<div class="card" style="padding:12px 14px;margin:0 0 14px;border-color:#e2b7b7;color:#8a2424;background:#fff6f6"><?= h($exportMessage !== '' ? $exportMessage : 'The RFQ Excel workbook could not be emailed.') ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="card" style="padding:18px">Database error: <?= h($error) ?></div>
<?php else: ?>

<div class="response-summary">
    <div class="response-card"><div class="label">RFQs With Responses</div><div class="value"><?= number_format($rfqsWithResponses) ?></div></div>
    <div class="response-card"><div class="label">Supplier Responses Received</div><div class="value"><?= number_format($totalResponses) ?></div></div>
</div>

<div class="response-filters">
    <a class="filter-btn <?= $responseFilter==='' ? 'active' : '' ?>" href="rfq-list.php">All RFQs</a>
    <a class="filter-btn <?= $responseFilter==='received' ? 'active' : '' ?>" href="rfq-list.php?response=received">Responses Received</a>
    <a class="filter-btn <?= $responseFilter==='awaiting' ? 'active' : '' ?>" href="rfq-list.php?response=awaiting">Awaiting Response</a>
</div>

<div class="card scroll">
<table>
<thead>
<tr>
<th>RFQ</th><th>Date</th><th>Needed By</th><th>Requester</th><th>Product</th><th>Qty</th>
<th>Destination</th><th>Supplier Name</th><th>Suppliers</th><th>Status</th><th>Email Activity</th><th>Responses</th><th>Actions</th>
</tr>
</thead>
<tbody>

<?php foreach ($rows as $r):
$status = (string)$r['status'];
$pill = $status==='Sent' ? 'sent' : ($status==='Ready for Email' ? 'ready' : 'draft');
$hasBeenSent = !empty($r['last_sent_at']) || (int)$r['sent_supplier_count'] > 0;
$responseCount = (int)($r['response_count'] ?? 0);
?>
<tr class="<?= $responseCount > 0 ? 'has-response' : '' ?>">
<td><a class="rfq-link" href="rfq-view.php?id=<?= (int)$r['id'] ?>"><?= h($r['rfq_number']) ?></a></td>
<td><?= h($r['request_date']) ?></td>
<td><?= h((string)$r['pricing_needed_by']) ?></td>
<td><?= h($r['requester_name']) ?></td>
<td><?= h($r['product_name']) ?></td>
<td><?= h($r['quantity'].' '.$r['quantity_uom']) ?></td>
<td><?= h($r['ship_city'].', '.$r['ship_state']) ?></td>
<td><?= h((string)($r['supplier_names'] ?? '')) ?></td>
<td><?= (int)$r['supplier_count'] ?></td>
<td><span class="pill <?= $pill ?>"><?= h($status) ?></span></td>
<td>
<?php if ($hasBeenSent): ?>
<span class="email-sent">Sent</span>
<span class="email-detail"><?= h(sentDisplay($r['last_sent_at'])) ?></span>
<?php else: ?>
<span class="email-not-sent">Not sent</span>
<?php endif; ?>
</td>
<td class="response-cell">
<?php if ($responseCount > 0): ?>
    <span class="response-badge"><?= $responseCount ?> RESPONSE<?= $responseCount === 1 ? '' : 'S' ?></span>
    <span class="email-detail"><a href="rfq-responses.php?id=<?= (int)$r['id'] ?>" style="color:#17613c;font-weight:700">View supplier pricing</a></span>
<?php else: ?>
    <span class="response-none">Awaiting response</span>
<?php endif; ?>
</td>
<td>
<div class="actions">
<a class="btn open" href="rfq-view.php?id=<?= (int)$r['id'] ?>">View</a>
<?php if ($responseCount > 0): ?>
<a class="btn responses" href="rfq-responses.php?id=<?= (int)$r['id'] ?>">Responses (<?= $responseCount ?>)</a>
<?php endif; ?>
<a class="btn edit" href="rfq-edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
<?php if ($hasBeenSent || $status==='Sent'): ?>
<a class="btn send" href="rfq-view.php?id=<?= (int)$r['id'] ?>#send" title="Review this RFQ before resending it">Review &amp; Resend</a>
<?php elseif ($status!=='Closed' && $status!=='Cancelled'): ?>
<a class="btn send" href="rfq-view.php?id=<?= (int)$r['id'] ?>#send" title="Review supplier, email format, and PDF option before sending">Review &amp; Send</a>
<?php endif; ?>

<form class="delete-form" method="post" action="delete-rfq.php"
onsubmit="return confirm('Permanently delete <?= h($r['rfq_number']) ?>? This will also delete its supplier recipients and status history. This cannot be undone.');">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_delete_csrf']) ?>">
<input type="hidden" name="rfq_id" value="<?= (int)$r['id'] ?>">
<button class="btn delete" type="submit">Delete</button>
</form>
</div>
</td>
</tr>
<?php endforeach; ?>

<?php if (!$rows): ?><tr><td colspan="13">No RFQs have been saved yet.</td></tr><?php endif; ?>

</tbody>
</table>
</div>
<?php endif; ?>
</main>
</body>
</html>
