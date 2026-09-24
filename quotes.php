<?php
session_start();
require_once __DIR__ . '/config/database.php';
date_default_timezone_set('America/Chicago');

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return '$' . number_format((float)$v, 2); }

function eastern_time($value): string {
    if (!$value) return '-';
    try {
        // MySQL quote timestamps are stored in UTC. Convert UTC to Eastern Time for display.
        $dt = new DateTime((string)$value, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/New_York'));
        return $dt->format('M j, Y g:i A') . ' EST';
    } catch (Throwable $e) {
        return (string)$value;
    }
}

$pdo = db();
$message = '';
$messageType = 'success';
$allowedStatuses = ['Draft','Sent','Won','Lost','Expired'];

if (empty($_SESSION['quotes_csrf_token'])) {
    $_SESSION['quotes_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['quotes_csrf_token'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedToken)) {
        $message = 'Your session expired. Refresh the page and try again.';
        $messageType = 'error';
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $quoteNumber = trim((string)($_POST['quote_number'] ?? ''));
        if ($quoteNumber === '') {
            $message = 'Unable to delete the quote.';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare('DELETE FROM quote_records WHERE quote_number = ?');
            $stmt->execute([$quoteNumber]);
            if ($stmt->rowCount() > 0) {
                $message = 'Quote ' . $quoteNumber . ' deleted.';
            } else {
                $message = 'Quote not found. It may have already been deleted.';
                $messageType = 'error';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'status') {
        $quoteNumber = trim((string)($_POST['quote_number'] ?? ''));
        $status = trim((string)($_POST['status'] ?? ''));
        if ($quoteNumber !== '' && in_array($status, $allowedStatuses, true)) {
            $stmt = $pdo->prepare("UPDATE quote_records SET status = ?, sent_at = CASE WHEN ? = 'Sent' THEN COALESCE(sent_at, NOW()) ELSE sent_at END WHERE quote_number = ?");
            $stmt->execute([$status, $status, $quoteNumber]);
            $message = 'Quote status updated.';
        } else {
            $message = 'Unable to update quote status.';
            $messageType = 'error';
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$rep = trim((string)($_GET['rep'] ?? ''));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(quote_number LIKE :q OR customer_company LIKE :q OR customer_name LIKE :q OR customer_email LIKE :q OR sales_rep LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
if ($from !== '') {
    $where[] = 'quote_date >= :from';
    $params[':from'] = $from;
}
if ($to !== '') {
    $where[] = 'quote_date <= :to';
    $params[':to'] = $to;
}
if ($rep !== '') {
    $where[] = 'sales_rep = :rep';
    $params[':rep'] = $rep;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT * FROM quote_records {$whereSql} ORDER BY quote_date DESC, updated_at DESC LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$quotes = $stmt->fetchAll();

$summary = $pdo->query("SELECT
    COUNT(*) AS quote_count,
    COALESCE(SUM(total),0) AS quoted_total,
    SUM(status='Draft') AS draft_count,
    SUM(status='Sent') AS sent_count,
    SUM(status='Won') AS won_count
    FROM quote_records")->fetch();

$reps = $pdo->query("SELECT DISTINCT sales_rep FROM quote_records WHERE sales_rep IS NOT NULL AND sales_rep <> '' ORDER BY sales_rep")->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lowe Chemical Quote History</title>
<style>
:root{--navy:#0B2A5B;--blue:#174F8A;--red:#D71920;--bg:#f4f6f8;--line:#d7e0e7;--muted:#65737e;--green:#237a45}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:#1d2935}.page{max-width:1500px;margin:auto;padding:24px}.topbar{background:linear-gradient(135deg,var(--navy),#123d78);border-top:5px solid var(--red);color:#fff;border-radius:14px;padding:22px 26px;display:flex;justify-content:space-between;align-items:center;gap:18px}.topbar h1{margin:0;font-size:27px}.topbar p{margin:5px 0 0;color:#dbe6ee}.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:8px;padding:11px 15px;font-weight:700;cursor:pointer;text-decoration:none;background:#fff;color:var(--navy)}.btn.blue{background:#1d5a92;color:#fff}.message{margin:16px 0;padding:12px 14px;border-radius:9px;background:#e8f5ec;color:#23683a}.message.error{background:#fbe9e9;color:#962828}.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:18px 0}.metric{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px}.metric .label{font-size:12px;text-transform:uppercase;color:var(--muted);font-weight:700}.metric .value{font-size:25px;color:var(--navy);font-weight:800;margin-top:4px}.filters{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:16px;display:grid;grid-template-columns:minmax(220px,2fr) repeat(4,minmax(140px,1fr)) auto;gap:10px;align-items:end}.field label{display:block;font-size:12px;font-weight:700;color:#455564;margin-bottom:5px}.field input,.field select{width:100%;padding:10px;border:1px solid #bac8d3;border-radius:7px;font-size:14px;background:#fff}.table-wrap{background:#fff;border:1px solid var(--line);border-radius:12px;overflow:auto}.quotes{width:100%;border-collapse:collapse;min-width:1100px}.quotes th{background:#eef3f7;color:var(--navy);font-size:12px;text-transform:uppercase;letter-spacing:.02em;text-align:left;padding:11px;border-bottom:1px solid var(--line)}.quotes td{padding:11px;border-bottom:1px solid #e6edf2;vertical-align:middle;font-size:13px}.quotes tr:hover{background:#f8fbfd}.qnum{font-weight:800;color:var(--navy)}.customer{font-weight:700}.subtle{font-size:11px;color:var(--muted);margin-top:3px}.right{text-align:right!important}.status-form{display:flex;gap:6px;align-items:center}.status-form select{padding:7px;border:1px solid #bdc9d2;border-radius:6px;background:#fff}.status-form button{padding:7px 9px;border:0;border-radius:6px;background:#eef3f7;color:var(--navy);font-weight:700;cursor:pointer}.actions{display:flex;gap:6px;flex-wrap:wrap}.action{padding:7px 10px;border:0;border-radius:6px;background:var(--navy);color:#fff;text-decoration:none;font-weight:700;font-size:12px;cursor:pointer}.action.delete{background:#fff;color:#a51f25;border:1px solid #d8a7a9}.delete-form{margin:0}.empty{padding:38px;text-align:center;color:var(--muted)}
@media(max-width:900px){.page{padding:10px}.topbar{align-items:flex-start;flex-direction:column}.cards{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr}.table-wrap{border-radius:9px}}
</style>
</head>
<body>
<div class="page">
  <div class="topbar">
    <div><h1>Lowe Chemical Quote History</h1><p>Search, reopen, revise, and track quotes created in the Lowe quote system.</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn" href="salesworkflow.php">&larr; Sales Workflow</a><a class="btn" href="pricequote.php">+ New Quote</a></div>
  </div>

  <?php if ($message): ?><div class="message <?= $messageType === 'error' ? 'error' : '' ?>"><?= h($message) ?></div><?php endif; ?>

  <div class="cards">
    <div class="metric"><div class="label">Saved Quotes</div><div class="value"><?= number_format((int)($summary['quote_count'] ?? 0)) ?></div></div>
    <div class="metric"><div class="label">Total Quoted</div><div class="value"><?= money($summary['quoted_total'] ?? 0) ?></div></div>
    <div class="metric"><div class="label">Draft</div><div class="value"><?= number_format((int)($summary['draft_count'] ?? 0)) ?></div></div>
    <div class="metric"><div class="label">Sent</div><div class="value"><?= number_format((int)($summary['sent_count'] ?? 0)) ?></div></div>
    <div class="metric"><div class="label">Won</div><div class="value"><?= number_format((int)($summary['won_count'] ?? 0)) ?></div></div>
  </div>

  <form class="filters" method="get">
    <div class="field"><label>Search</label><input name="q" value="<?= h($q) ?>" placeholder="Quote #, customer, contact, email, rep..."></div>
    <div class="field"><label>Status</label><select name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $s): ?><option value="<?= h($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Sales Rep</label><select name="rep"><option value="">All reps</option><?php foreach ($reps as $r): ?><option value="<?= h($r) ?>" <?= $rep===$r?'selected':'' ?>><?= h($r) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>From</label><input type="date" name="from" value="<?= h($from) ?>"></div>
    <div class="field"><label>To</label><input type="date" name="to" value="<?= h($to) ?>"></div>
    <button class="btn blue" type="submit">Search</button>
  </form>

  <div class="table-wrap">
    <?php if (!$quotes): ?>
      <div class="empty">No quotes match the current search.</div>
    <?php else: ?>
    <table class="quotes">
      <thead><tr><th>Quote</th><th>Date</th><th>Customer</th><th>Sales Rep</th><th class="right">Total</th><th>Status</th><th>Last Updated</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($quotes as $row): ?>
        <tr>
          <td><div class="qnum"><?= h($row['quote_number']) ?></div><div class="subtle">Valid through <?= h($row['valid_through'] ?: '-') ?></div></td>
          <td><?= h(date('M j, Y', strtotime($row['quote_date']))) ?></td>
          <td><div class="customer"><?= h($row['customer_company'] ?: 'Customer') ?></div><?php if ($row['customer_name']): ?><div class="subtle"><?= h($row['customer_name']) ?></div><?php endif; ?></td>
          <td><?= h($row['sales_rep'] ?: '-') ?></td>
          <td class="right"><strong><?= money($row['total']) ?></strong></td>
          <td>
            <form class="status-form" method="post">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="quote_number" value="<?= h($row['quote_number']) ?>">
              <select name="status"><?php foreach ($allowedStatuses as $s): ?><option value="<?= h($s) ?>" <?= $row['status']===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?></select>
              <button type="submit">Save</button>
            </form>
          </td>
          <td><?= h(eastern_time($row['updated_at'])) ?></td>
          <td><div class="actions">
            <a class="action" href="pricequote.php?load=<?= urlencode($row['quote_number']) ?>">Open / Edit</a>
            <form class="delete-form" method="post" onsubmit="return confirm(<?= h(json_encode('Delete quote ' . $row['quote_number'] . ' for ' . ($row['customer_company'] ?: 'this customer') . '? This permanently removes the quote and cannot be undone.')) ?>);">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="quote_number" value="<?= h($row['quote_number']) ?>">
              <button class="action delete" type="submit">Delete</button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
