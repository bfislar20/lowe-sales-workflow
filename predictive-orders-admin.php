<?php
session_start();
require_once __DIR__ . '/predictive-order-engine.php';

const OF_MAX_UPLOAD_BYTES = 50 * 1024 * 1024;

// A private file on SiteGround supplies the password hash; it must stay out of GitHub.
$adminConfigFile = __DIR__ . '/config/forecast-admin.php';
$adminConfig = is_file($adminConfigFile) ? require $adminConfigFile : [];
$adminPasswordHash = is_array($adminConfig) ? (string)($adminConfig['password_hash'] ?? '') : '';
$adminConfigured = $adminPasswordHash !== '';
if (!$adminConfigured) {
    http_response_code(503);
    exit('The forecast admin login is not configured. Contact the site administrator.');
}
if (($_SESSION['of_admin_hash'] ?? '') !== hash('sha256', $adminPasswordHash)) {
    unset($_SESSION['of_admin'], $_SESSION['of_admin_hash']);
}

function up_esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Count data rows in a worksheet. The header row is not included.
 * Returns null if the sheet does not exist or cannot be read.
 */
function of_sheet_data_row_count(string $xlsxPath, string $sheetName): ?int {
    try {
        $rows = of_read_sheet($xlsxPath, $sheetName);
        if (!$rows) return 0;
        return max(0, count($rows) - 1);
    } catch (Throwable $e) {
        return null;
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['of_admin'], $_SESSION['of_admin_hash']);
    header('Location: predictive-orders-admin.php');
    exit;
}

$error = '';
$success = '';
$details = [];
$sheetCounts = [];

if (!($_SESSION['of_admin'] ?? false)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (password_verify((string)$_POST['password'], $adminPasswordHash)) {
            $_SESSION['of_admin'] = true;
            $_SESSION['of_admin_hash'] = hash('sha256', $adminPasswordHash);
            session_regenerate_id(true);
            header('Location: predictive-orders-admin.php');
            exit;
        }
        $error = 'Incorrect password.';
    }
} else {
    if (empty($_SESSION['of_csrf'])) {
        $_SESSION['of_csrf'] = bin2hex(random_bytes(24));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['master_file'])) {
        try {
            if (!hash_equals($_SESSION['of_csrf'], (string)($_POST['csrf'] ?? ''))) {
                throw new RuntimeException('Security token expired. Refresh the page and try again.');
            }

            $f = $_FILES['master_file'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('The file did not upload. Error code: ' . ($f['error'] ?? 'unknown'));
            }
            if (($f['size'] ?? 0) <= 0 || $f['size'] > OF_MAX_UPLOAD_BYTES) {
                throw new RuntimeException('The workbook must be between 1 byte and 50 MB.');
            }

            $original = basename((string)$f['name']);
            if (strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new RuntimeException('Please upload an .xlsx file.');
            }

            $dataDir = __DIR__ . '/predictive-order-files';
            if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true)) {
                throw new RuntimeException('Could not create the predictive-order-files folder.');
            }
            if (!is_writable($dataDir)) {
                throw new RuntimeException('The predictive-order-files folder is not writable.');
            }

            $staged = $dataDir . '/upload-' . date('Ymd-His') . '.xlsx';
            if (!move_uploaded_file($f['tmp_name'], $staged)) {
                throw new RuntimeException('Could not save the uploaded workbook.');
            }

            @set_time_limit(300);

            // Count rows in every Lowe Master worksheet before processing.
            $sheetsToCount = [
                'Invoices',
                'Inventory',
                'Purchases',
                'Open Sales Orders',
                'Open Purchase Orders',
                'Part Master',
            ];

            foreach ($sheetsToCount as $sheetName) {
                $sheetCounts[$sheetName] = of_sheet_data_row_count($staged, $sheetName);
            }

            // Build first. The live report remains untouched if processing fails.
            $payload = of_build_payload($staged, date('Y-m-d'));
            if (empty($payload['rows'])) {
                throw new RuntimeException('No customer/product forecasts were produced. The live report was not changed.');
            }

            of_write_atomic($payload, __DIR__ . '/predictive-order-data.json');
            @copy($staged, $dataDir . '/Lowe-Master-Latest.xlsx');
            @unlink($staged);

            $success = 'Order forecast updated.';
            $details = [
                'Source file' => $original,
                'Forecast date' => $payload['generated_as_of'],
                'Invoice history through' => $payload['latest_invoice_date'] ?: 'Unknown',
                'Customer/product pairs' => number_format(count($payload['rows'])),
                'Reversed invoices removed' => number_format((int)($payload['reversed_invoices_removed'] ?? 0)),
            ];
        } catch (Throwable $e) {
            if (isset($staged) && is_file($staged)) @unlink($staged);
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Update Order Forecast | Lowe Chemical</title>
<style>
:root{--navy:#061d3f;--red:#c8102e;--bg:#eef1f5;--border:#d6dde6;--text:#1f2d3d;--muted:#66768a;--green:#146c43;--green-bg:#e1f4e9;--blue:#1d5e91}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif}
.header{background:var(--navy);color:#fff;padding:18px 22px;border-bottom:4px solid var(--red)}
.header .inner{max-width:960px;margin:auto;display:flex;justify-content:space-between;align-items:center;gap:16px}
.header h1{margin:0;font-size:22px}.header p{margin:4px 0 0;color:#c9d6e6;font-size:13px}.header a{color:#fff}
.wrap{max-width:960px;margin:26px auto;padding:0 16px}
.panel{background:#fff;border:1px solid var(--border);border-radius:8px;padding:22px;margin-bottom:16px}
.panel h2{margin:0 0 8px;color:var(--navy);font-size:19px}.panel p{line-height:1.55}
.field{margin:16px 0}.field label{display:block;font-size:12px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.field input{width:100%;padding:11px;border:1px solid #b9c5d3;border-radius:5px;background:#fff}
.btn{display:inline-block;border:0;border-radius:5px;background:var(--red);color:#fff;padding:11px 17px;font-weight:700;text-decoration:none;cursor:pointer}
.btn.secondary{background:var(--navy)}
.alert{padding:15px 16px;border-radius:7px;margin-bottom:16px;line-height:1.45}.alert.error{background:#fde3e3;color:#8a1c1c}.alert.success{background:var(--green-bg);color:var(--green)}
.warning{background:#fff5dc;border-left:4px solid #d28a00;padding:12px 14px;font-size:13px;line-height:1.5;margin-top:18px}
.details{display:grid;grid-template-columns:220px 1fr;border-top:1px solid #dfe8e3;margin-top:14px}.details div{padding:8px 0;border-bottom:1px solid #dfe8e3}.details .k{font-weight:700}
.sheet-title{margin:18px 0 8px;font-size:14px;font-weight:800;color:var(--navy)}
.sheet-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin-top:8px}
.sheet-card{background:#fff;border:1px solid #cce2d5;border-radius:7px;padding:11px 12px;color:var(--text)}
.sheet-card .name{font-size:12px;font-weight:700;color:var(--muted);margin-bottom:3px}
.sheet-card .count{font-size:22px;font-weight:800;color:var(--navy)}
.sheet-card .label{font-size:11px;color:var(--muted)}
.sheet-card.missing{background:#fff6f6;border-color:#efcaca}.sheet-card.missing .count{font-size:14px;color:#9b1c1c;margin-top:5px}
.steps{margin:14px 0 0;padding-left:21px;line-height:1.65}.small{font-size:12px;color:var(--muted)}code{background:#f0f3f7;padding:2px 5px;border-radius:4px}
@media(max-width:760px){.header .inner{display:block}.details{grid-template-columns:1fr}.sheet-grid{grid-template-columns:1fr 1fr}}
@media(max-width:500px){.sheet-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="header"><div class="inner">
<div><h1>Update Order Forecast</h1><p>Lowe Chemical Company</p></div>
<?php if ($_SESSION['of_admin'] ?? false): ?><div><a href="predictive-orders.php">View forecast</a> &nbsp;|&nbsp; <a href="?logout=1">Log out</a></div><?php endif; ?>
</div></div>

<div class="wrap">
<?php if ($error): ?><div class="alert error"><?= up_esc($error) ?></div><?php endif; ?>

<?php if (!($_SESSION['of_admin'] ?? false)): ?>
<div class="panel">
<h2>Administrator Login</h2>
<form method="post"><div class="field"><label>Password</label><input type="password" name="password" required autofocus></div><button class="btn" type="submit">Log In</button></form>
<div class="warning"><strong>Important:</strong> before uploading this revision, replace <code>CHANGE-ME-NOW</code> in this file with the same private password you currently use.</div>
</div>
<?php else: ?>

<?php if ($success): ?>
<div class="alert success">
<strong><?= up_esc($success) ?></strong>
<div class="details">
<?php foreach ($details as $k => $v): ?><div class="k"><?= up_esc($k) ?></div><div><?= up_esc($v) ?></div><?php endforeach; ?>
</div>

<?php if ($sheetCounts): ?>
<div class="sheet-title">Rows loaded from each worksheet</div>
<div class="sheet-grid">
<?php foreach ($sheetCounts as $sheetName => $rowCount): ?>
<div class="sheet-card<?= $rowCount === null ? ' missing' : '' ?>">
<div class="name"><?= up_esc($sheetName) ?></div>
<?php if ($rowCount === null): ?>
<div class="count">Not found</div>
<div class="label">worksheet could not be read</div>
<?php else: ?>
<div class="count"><?= number_format($rowCount) ?></div>
<div class="label">data rows</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
<h2>Upload Updated Lowe Master Workbook</h2>
<p>Upload the current <strong>.xlsx</strong>. The forecast is rebuilt and only replaces the live report if it succeeds.</p>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= up_esc($_SESSION['of_csrf']) ?>">
<div class="field"><label>Lowe Master .xlsx File</label><input type="file" name="master_file" accept=".xlsx" required></div>
<button class="btn" type="submit">Process File &amp; Update Forecast</button>
<a class="btn secondary" href="predictive-orders.php">Cancel</a>
</form>
<p class="small">Your SiteGround PHP upload limit must also allow the file size.</p>
</div>

<div class="panel">
<h2>Workbook Tabs</h2>
<p class="small">After each successful upload, the green confirmation panel will show the number of data rows found on each of these worksheets. Header rows are not included in the counts.</p>
<ul class="steps">
<li><strong>Invoices</strong></li>
<li><strong>Inventory</strong></li>
<li><strong>Purchases</strong></li>
<li><strong>Open Sales Orders</strong></li>
<li><strong>Open Purchase Orders</strong></li>
<li><strong>Part Master</strong></li>
</ul>
</div>

<?php endif; ?>
</div>
</body>
</html>
