<?php
/**
 * Lowe Chemical - Customer 13 Month Volume Report
 *
 * Uses the same Lowe Master workbook maintained by predictive-orders-admin.php.
 * Expected files in the same website folder:
 *   predictive-order-engine.php
 *   predictive-order-files/Lowe-Master-Latest.xlsx
 *
 * The page maintains a small JSON cache and automatically rebuilds it whenever
 * Lowe-Master-Latest.xlsx changes.
 */

declare(strict_types=1);

require_once __DIR__ . '/predictive-order-engine.php';

function c13_esc($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function c13_num($v, int $d = 0): string {
    return number_format((float)$v, $d);
}
function c13_date($v): string {
    if (!$v) return '-';
    $t = strtotime((string)$v);
    return $t ? date('M j, Y', $t) : '-';
}
function c13_contains($haystack, $needle): bool {
    if ($needle === '') return true;
    return function_exists('mb_stripos')
        ? mb_stripos((string)$haystack, (string)$needle) !== false
        : stripos((string)$haystack, (string)$needle) !== false;
}
function c13_field(array $r, array $names, $default='') {
    foreach ($names as $n) if (array_key_exists($n,$r) && $r[$n] !== '' && $r[$n] !== null) return $r[$n];
    return $default;
}

function c13_xml($v): string {
    return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function c13_col(int $n): string {
    $out = '';
    while ($n > 0) {
        $n--;
        $out = chr(65 + ($n % 26)) . $out;
        $n = intdiv($n, 26);
    }
    return $out;
}
function c13_download_xlsx(array $rows, array $displayMonths, int $monthsToShow, string $asOf): void {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        die('Excel download requires the PHP Zip extension (ZipArchive) on the server.');
    }

    $headers = ['Customer', 'Product Description', 'Sales Rep', 'No. of Customers'];
    foreach ($displayMonths as $m) $headers[] = $m['label'];
    $headers[] = 'Total ' . $monthsToShow . ' Mo';

    $sheetRows = [];
    $rnum = 1;
    $cells = [];
    foreach ($headers as $i => $h) {
        $ref = c13_col($i + 1) . $rnum;
        $cells[] = '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . c13_xml($h) . '</t></is></c>';
    }
    $sheetRows[] = '<row r="1">' . implode('', $cells) . '</row>';

    foreach ($rows as $r) {
        $rnum++;
        $vals = [$r['customer'], $r['product'], $r['rep'], (int)($r['product_customer_count'] ?? 0)];
        foreach ($displayMonths as $m) $vals[] = (float)($r['months'][$m['key']] ?? 0);
        $vals[] = (float)$r['period_total'];
        $cells = [];
        foreach ($vals as $i => $v) {
            $ref = c13_col($i + 1) . $rnum;
            if ($i >= 3) {
                $cells[] = '<c r="' . $ref . '" s="2"><v>' . (float)$v . '</v></c>';
            } else {
                $cells[] = '<c r="' . $ref . '" t="inlineStr"><is><t>' . c13_xml($v) . '</t></is></c>';
            }
        }
        $sheetRows[] = '<row r="' . $rnum . '">' . implode('', $cells) . '</row>';
    }

    $lastCol = c13_col(count($headers));
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols><col min="1" max="1" width="28" customWidth="1"/><col min="2" max="2" width="38" customWidth="1"/><col min="3" max="3" width="20" customWidth="1"/><col min="4" max="' . count($headers) . '" width="13" customWidth="1"/></cols>'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastCol . $rnum . '"/>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF061D3F"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border/><border><left style="thin"><color rgb="FFD9E1EA"/></left><right style="thin"><color rgb="FFD9E1EA"/></right><top style="thin"><color rgb="FFD9E1EA"/></top><bottom style="thin"><color rgb="FFD9E1EA"/></bottom></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf><xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right"/></xf></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Customer Volume" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $typesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';

    $tmp = tempnam(sys_get_temp_dir(), 'c13_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) die('Could not create Excel file.');
    $zip->addFromString('[Content_Types].xml', $typesXml);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $relsXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->close();

    $filename = 'customer-volume-' . $monthsToShow . 'mo-' . date('Y-m-d', strtotime($asOf)) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function c13_month_key(string $date): string {
    return date('Y-m', strtotime($date));
}
function c13_months(string $asOf): array {
    $end = new DateTimeImmutable(date('Y-m-01', strtotime($asOf)));
    $start = $end->modify('-12 months');
    $out = [];
    for ($i = 0; $i < 13; $i++) {
        $d = $start->modify('+' . $i . ' months');
        $out[] = [
            'key' => $d->format('Y-m'),
            'label' => $d->format('M y'),
            'full' => $d->format('F Y'),
        ];
    }
    return $out;
}
function c13_find_workbook(): ?string {
    $candidates = [
        __DIR__ . '/predictive-order-files/Lowe-Master-Latest.xlsx',
        __DIR__ . '/Lowe Master.xlsx',
        __DIR__ . '/Lowe-Master-Latest.xlsx',
    ];
    foreach ($candidates as $p) if (is_file($p)) return $p;
    return null;
}

const C13_CACHE_VERSION = '3-product-name-grouping';

function c13_build(string $xlsxPath, string $asOf): array {
    @set_time_limit(300);
    $raw = of_assoc(of_read_sheet($xlsxPath, 'Invoices'));
    of_require($raw, ['INV. Date','Doc Type','Cust Name','Cust#','Product Name','Product Number','LBS','REP'], 'Invoices');

    $months = c13_months($asOf);
    $monthKeys = array_column($months, 'key');
    $firstMonth = $monthKeys[0];
    $lastMonth = $monthKeys[count($monthKeys) - 1];

    $groups = [];
    $latestInvoiceDate = null;
    $includedRows = 0;
    $creditRows = 0;

    foreach ($raw as $r) {
        $date = of_date($r['INV. Date'] ?? '');
        if (!$date) continue;

        $type = strtolower(trim((string)($r['Doc Type'] ?? '')));
        if (!in_array($type, ['invoiced', 'credit'], true)) continue;

        $month = c13_month_key($date);
        if ($month < $firstMonth || $month > $lastMonth) continue;

        $cust = trim((string)($r['Cust Name'] ?? ''));
        $custCode = trim((string)($r['Cust#'] ?? ''));
        $product = trim((string)($r['Product Name'] ?? ''));
        $rep = trim((string)($r['REP'] ?? ''));
        $lbs = of_num($r['LBS'] ?? 0);

        if ($cust === '' || $product === '') continue;
        if ($type === 'credit') $creditRows++;
        $includedRows++;

        // Group strictly by customer + Product Name. Product Number is intentionally ignored.
        // This combines volume when the same product description has been sold under multiple item codes.
        $customerKey = $custCode !== '' ? $custCode : $cust;
        $productKey = preg_replace('/\s+/', ' ', strtoupper($product));
        $key = strtoupper($customerKey) . '|' . $productKey;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'customer' => $cust,
                'customer_code' => $custCode,
                'product' => $product,
                'rep' => $rep,
                'months' => array_fill_keys($monthKeys, 0.0),
                'total' => 0.0,
                'last_invoice' => null,
                'invoice_rows' => 0,
            ];
        }

        $groups[$key]['months'][$month] += $lbs;
        $groups[$key]['total'] += $lbs;
        $groups[$key]['invoice_rows']++;
        if ($rep !== '') $groups[$key]['rep'] = $rep;
        if ($groups[$key]['last_invoice'] === null || $date > $groups[$key]['last_invoice']) {
            $groups[$key]['last_invoice'] = $date;
        }
        if ($type === 'invoiced' && ($latestInvoiceDate === null || $date > $latestInvoiceDate)) {
            $latestInvoiceDate = $date;
        }
    }

    $rows = array_values($groups);
    foreach ($rows as &$r) {
        $activeMonths = 0;
        foreach ($r['months'] as $v) if (abs((float)$v) > 0.00001) $activeMonths++;
        $r['active_months'] = $activeMonths;
        $r['avg_monthly'] = $r['total'] / 13;
        $r['avg_active_month'] = $activeMonths ? $r['total'] / $activeMonths : 0;
    }
    unset($r);

    usort($rows, function($a, $b) {
        $c = strcasecmp($a['customer'], $b['customer']);
        if ($c) return $c;
        return strcasecmp($a['product'], $b['product']);
    });

    return [
        'cache_version' => C13_CACHE_VERSION,
        'generated_at' => date('c'),
        'as_of' => $asOf,
        'source_file' => basename($xlsxPath),
        'source_mtime' => filemtime($xlsxPath) ?: 0,
        'latest_invoice_date' => $latestInvoiceDate,
        'months' => $months,
        'invoice_rows_in_period' => $includedRows,
        'credit_rows_in_period' => $creditRows,
        'rows' => $rows,
    ];
}

$workbook = c13_find_workbook();
if (!$workbook) {
    http_response_code(500);
    die('Lowe Master workbook not found. Upload the workbook from predictive-orders-admin.php first.');
}

$cacheFile = __DIR__ . '/customer-13-data.json';
$asOf = date('Y-m-d');
$sourceMtime = filemtime($workbook) ?: 0;
$payload = null;

if (is_file($cacheFile)) {
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($cached)
        && ($cached['cache_version'] ?? '') === C13_CACHE_VERSION
        && (int)($cached['source_mtime'] ?? -1) === $sourceMtime
        && ($cached['as_of'] ?? '') === $asOf
        && isset($cached['rows'], $cached['months'])) {
        $payload = $cached;
    }
}

if (!$payload) {
    try {
        $payload = c13_build($workbook, $asOf);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) @file_put_contents($cacheFile, $json, LOCK_EX);
    } catch (Throwable $e) {
        http_response_code(500);
        die('Could not build the 13-month customer report: ' . c13_esc($e->getMessage()));
    }
}

$allRows = $payload['rows'];
$months = $payload['months'];

$monthsToShow = max(1, min(13, (int)($_GET['months'] ?? 13)));
$displayMonths = array_slice(array_reverse($months), 0, $monthsToShow); // newest to oldest
$displayMonthKeys = array_column($displayMonths, 'key');

// Recalculate period metrics for the selected number of months.
foreach ($allRows as &$r) {
    $periodTotal = 0.0;
    $periodActive = 0;
    foreach ($displayMonthKeys as $mk) {
        $v = (float)($r['months'][$mk] ?? 0);
        $periodTotal += $v;
        if (abs($v) > 0.00001) $periodActive++;
    }
    $r['period_total'] = $periodTotal;
    $r['period_active_months'] = $periodActive;
    $r['period_avg_monthly'] = $monthsToShow ? $periodTotal / $monthsToShow : 0;
}
unset($r);

// Product-level customer count for the currently selected month window.
// A customer is counted when its net volume for the product is non-zero in the displayed period.
$productCustomerSets = [];
foreach ($allRows as $r) {
    if (abs((float)($r['period_total'] ?? 0)) < 0.00001) continue;
    $pk = preg_replace('/\s+/', ' ', strtoupper(trim((string)$r['product'])));
    $ck = trim((string)$r['customer_code']) !== '' ? trim((string)$r['customer_code']) : strtoupper(trim((string)$r['customer']));
    $productCustomerSets[$pk][$ck] = true;
}
foreach ($allRows as &$r) {
    $pk = preg_replace('/\s+/', ' ', strtoupper(trim((string)$r['product'])));
    $r['product_customer_count'] = count($productCustomerSets[$pk] ?? []);
}
unset($r);

$productCustomerDetail = trim((string)($_GET['product_customers'] ?? ''));

$rep = trim((string)($_GET['rep'] ?? ''));
$customerFilter = $_GET['customers'] ?? [];
if (!is_array($customerFilter)) $customerFilter = [$customerFilter];
$customerFilter = array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$customerFilter),fn($v)=>$v!=='')));
$customerLookup = array_fill_keys($customerFilter,true);
$productFilter = $_GET['products'] ?? [];
if (!is_array($productFilter)) $productFilter = [$productFilter];
$productFilter = array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$productFilter),fn($v)=>$v!=='')));
$productLookup = array_fill_keys($productFilter,true);
$sort = (string)($_GET['sort'] ?? 'customer');
$minVol = max(0.0, (float)($_GET['minvol'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$detailCustomer = trim((string)($_GET['detail_customer'] ?? ''));
$detailCustomerCode = trim((string)($_GET['detail_customer_code'] ?? ''));
$detailProduct = trim((string)($_GET['detail_product'] ?? ''));

$reps=[]; $customers=[]; $products=[];
foreach($allRows as $r){
    if($r['rep']!=='') $reps[$r['rep']]=true;
    $customers[$r['customer']]=true;
    $products[$r['product']]=true;
}
$reps=array_keys($reps); sort($reps,SORT_NATURAL|SORT_FLAG_CASE);
$customers=array_keys($customers); sort($customers,SORT_NATURAL|SORT_FLAG_CASE);
$products=array_keys($products); sort($products,SORT_NATURAL|SORT_FLAG_CASE);

$rows=array_values(array_filter($allRows,function($r)use($rep,$customerFilter,$customerLookup,$productFilter,$productLookup,$minVol){
    if($rep!=='' && $r['rep']!==$rep) return false;
    if($customerFilter && !isset($customerLookup[$r['customer']])) return false;
    if($productFilter && !isset($productLookup[$r['product']])) return false;
    if($minVol>0 && abs((float)$r['period_total'])<$minVol) return false;
    return true;
}));

usort($rows, function($a, $b) use ($sort) {
    if ($sort === 'volume') {
        $c = $b['period_total'] <=> $a['period_total'];
        if ($c) return $c;
    } elseif ($sort === 'product') {
        $c = strcasecmp($a['product'], $b['product']);
        if ($c) return $c;
    } elseif ($sort === 'recent') {
        $c = strcmp((string)$b['last_invoice'], (string)$a['last_invoice']);
        if ($c) return $c;
    }
    $c = strcasecmp($a['customer'], $b['customer']);
    if ($c) return $c;
    return strcasecmp($a['product'], $b['product']);
});

// Standalone product/customer monthly detail view. Opened in a new browser tab from the No. of Customers column.
if ($productCustomerDetail !== '') {
    $productDetailRows = [];
    foreach ($allRows as $r) {
        if (strcasecmp((string)$r['product'], $productCustomerDetail) !== 0) continue;
        if (abs((float)($r['period_total'] ?? 0)) < 0.00001) continue;
        $productDetailRows[] = $r;
    }
    usort($productDetailRows, function($a,$b){
        $c = $b['period_total'] <=> $a['period_total'];
        return $c ?: strcasecmp($a['customer'],$b['customer']);
    });
    $pdTotal = array_sum(array_column($productDetailRows,'period_total'));
    $pdCustomers = count($productDetailRows);
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive">
    <title><?=c13_esc($productCustomerDetail)?> Customers | Lowe Chemical</title>
    <style>
    :root{--navy:#061d3f;--red:#c8102e;--bg:#f3f5f8;--line:#dbe2ea;--muted:#66768a;--blue:#1d5e91}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:#1f2d3d;font-size:14px}.header{background:var(--navy);color:#fff;border-bottom:4px solid var(--red)}.inner{max-width:1700px;margin:auto;padding:15px 22px;display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:14px}.logo{width:180px;max-height:58px;object-fit:contain;background:#fff;border-radius:6px;padding:7px 10px}.brand h1{margin:0;font-size:22px}.brand p{margin:4px 0 0;color:#cbd8e8;font-size:12px}.meta{text-align:right;font-size:12px;color:#d9e3ef;line-height:1.5}.wrap{max-width:1700px;margin:18px auto;padding:0 16px}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}.card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px 14px;border-left:5px solid var(--blue)}.card .n{font-size:22px;font-weight:800;color:var(--navy)}.card .l{font-size:10px;text-transform:uppercase;color:var(--muted);margin-top:3px}.panel{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px}.tw{overflow:auto;border:1px solid var(--line);border-radius:7px}table{border-collapse:collapse;width:max-content;min-width:100%;background:#fff}th,td{border:1px solid #d8e0e8;padding:8px}th{background:#e8eef5;color:var(--navy);font-size:10px;text-transform:uppercase;white-space:nowrap;text-align:right}th.left,td.left{text-align:left}.month{min-width:82px}.total{font-weight:800;background:#f7f9fb}.current{background:#fff8e7}.zero{color:#b3bdc8}.neg{color:#a61b1b}.cust{font-weight:700;color:var(--navy)}.btn{display:inline-block;background:#fff;color:var(--navy);text-decoration:none;font-weight:800;padding:8px 11px;border-radius:6px}.sub{font-size:11px;color:var(--muted);margin:0 0 10px}@media(max-width:800px){.cards{grid-template-columns:1fr}.inner{display:block}.meta{margin-top:10px;text-align:left}.tw{overflow:auto}table{min-width:1100px}}
    </style></head><body>
    <header class="header"><div class="inner"><div class="brand"><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><div><h1>Product Customer Detail</h1><p><?=c13_esc($productCustomerDetail)?></p></div></div><div class="meta"><?=$monthsToShow?> month<?=$monthsToShow===1?'':'s'?> shown<br>Report through: <strong><?=c13_esc(c13_date($asOf))?></strong><br><a class="btn" href="customer-13.php">Customer Volume Report</a></div></div></header>
    <main class="wrap"><div class="cards"><div class="card"><div class="n"><?=c13_num($pdCustomers)?></div><div class="l">Customers Buying Product</div></div><div class="card"><div class="n"><?=c13_num($pdTotal)?> lb</div><div class="l">Total Volume · <?=$monthsToShow?> Mo</div></div><div class="card"><div class="n"><?=c13_esc($displayMonths[0]['label'])?> to <?=c13_esc($displayMonths[count($displayMonths)-1]['label'])?></div><div class="l">Displayed Period · Newest to Oldest</div></div></div>
    <section class="panel"><div class="sub">Customers are included when net volume for this product is non-zero in the selected displayed period. Credits are included in the monthly net pounds.</div><div class="tw"><table><thead><tr><th class="left">Customer</th><th class="left">Customer Code</th><th class="left">Sales Rep</th><?php foreach($displayMonths as $m):?><th class="month <?=$m['key']===$currentMonthKey?'current':''?>"><?=c13_esc($m['label'])?></th><?php endforeach;?><th>Total <?=$monthsToShow?> Mo</th></tr></thead><tbody>
    <?php if(!$productDetailRows):?><tr><td colspan="<?=4+$monthsToShow?>" style="padding:28px;text-align:center;color:#66768a">No customer volume was found for this product in the selected period.</td></tr><?php endif;?>
    <?php foreach($productDetailRows as $r):?><tr><td class="left"><span class="cust"><?=c13_esc($r['customer'])?></span></td><td class="left"><?=c13_esc($r['customer_code']?:'-')?></td><td class="left"><?=c13_esc($r['rep']?:'-')?></td><?php foreach($displayMonths as $m):$v=(float)($r['months'][$m['key']]??0);$cls=abs($v)<0.00001?'zero':($v<0?'neg':'');if($m['key']===$currentMonthKey)$cls.=' current';?><td class="<?=$cls?>"><?=$v==0?'-':c13_num($v)?></td><?php endforeach;?><td class="total"><?=c13_num($r['period_total'])?></td></tr><?php endforeach;?>
    </tbody></table></div></section></main></body></html>
    <?php
    exit;
}

$detailInvoices=[]; $detailOpenOrders=[]; $detailInvoiceDocs=[]; $detailOpenDocs=[];
$detailInvoiceLbs=0.0; $detailSales=0.0; $detailProfit=0.0; $detailOpenLbs=0.0; $detailOpenSales=0.0;
if($detailCustomer!=='' && $detailProduct!==''){
    try{
        $rawInvoices=of_assoc(of_read_sheet($workbook,'Invoices'));
        $openSalesRows=of_assoc(of_read_sheet($workbook,'Open Sales Orders'));
        $periodStart=$displayMonths[count($displayMonths)-1]['key'].'-01';
        $periodEnd=(new DateTimeImmutable($displayMonths[0]['key'].'-01'))->modify('last day of this month')->format('Y-m-d');
        foreach($rawInvoices as $ri){
            $d=of_date($ri['INV. Date']??''); if(!$d||$d<$periodStart||$d>$periodEnd) continue;
            $type=strtolower(trim((string)($ri['Doc Type']??''))); if(!in_array($type,['invoiced','credit'],true)) continue;
            $cust=trim((string)($ri['Cust Name']??'')); $cc=trim((string)($ri['Cust#']??'')); $prod=trim((string)($ri['Product Name']??''));
            if($prod!==$detailProduct) continue;
            if($detailCustomerCode!==''){ if($cc!==$detailCustomerCode) continue; } elseif($cust!==$detailCustomer) continue;
            $lbs=of_num($ri['LBS']??0); $sales=of_num(c13_field($ri,['Sales $$','Sales','Sales Dollars'],0)); $profit=of_num(c13_field($ri,['Profit $$','Profit','Profit Dollars'],0));
            $inv=trim((string)c13_field($ri,['INV#','Invoice Number','Invoice #'],''));
            $detailInvoices[]=['date'=>$d,'type'=>$type,'invoice'=>$inv,'customer_po'=>trim((string)c13_field($ri,['Cust PO#','Customer PO','Customer PO#'],'')),'product_no'=>trim((string)($ri['Product Number']??'')),'lbs'=>$lbs,'sales'=>$sales,'profit'=>$profit,'rep'=>trim((string)($ri['REP']??''))];
            $detailInvoiceLbs+=$lbs; $detailSales+=$sales; $detailProfit+=$profit; if($inv!=='')$detailInvoiceDocs[$inv]=true;
        }
        usort($detailInvoices,fn($a,$b)=>strcmp($b['date'],$a['date'])?:strcasecmp($a['invoice'],$b['invoice']));
        foreach($openSalesRows as $so){
            $cust=trim((string)($so['Customer Name']??'')); $cc=trim((string)($so['Customer Number']??'')); $prod=trim((string)($so['Product Name']??''));
            if($prod!==$detailProduct) continue;
            if($detailCustomerCode!==''){ if($cc!==$detailCustomerCode) continue; } elseif($cust!==$detailCustomer) continue;
            $lbs=of_num($so['Total LBS']??0); $sales=of_num(c13_field($so,['Total Sales','Sales $$','Sales Dollars'],0));
            $ord=trim((string)c13_field($so,['Order Number','Order #','SO Number','SO#'],''));
            $detailOpenOrders[]=['order'=>$ord,'release'=>trim((string)c13_field($so,['Release Number','Release','Rel. No.'],'')),'order_date'=>of_date($so['Order Date']??''),'ship_date'=>of_date($so['Ship Date']??''),'product_no'=>trim((string)($so['Product Number']??'')),'lbs'=>$lbs,'sales'=>$sales,'rep'=>trim((string)($so['Rep Name']??''))];
            $detailOpenLbs+=$lbs; $detailOpenSales+=$sales; if($ord!=='')$detailOpenDocs[$ord]=true;
        }
        usort($detailOpenOrders,fn($a,$b)=>strcmp((string)$a['ship_date'],(string)$b['ship_date'])?:strcmp((string)$a['order_date'],(string)$b['order_date']));
    }catch(Throwable $e){}
}

if (($_GET['download'] ?? '') === 'xlsx') {
    c13_download_xlsx($rows, $displayMonths, $monthsToShow, $asOf);
}

$pdfMode = (($_GET['pdf'] ?? '') === '1');

$totalPairs = count($rows);
if ($pdfMode) {
    // The PDF/print view includes every row matching the current filters.
    $totalPages = 1;
    $page = 1;
    $slice = $rows;
} else {
    $totalPages = max(1, (int)ceil($totalPairs / $perPage));
    $page = min($page, $totalPages);
    $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
}

$summaryTotal = array_sum(array_column($rows, 'period_total'));
$summaryCustomers = [];
$summaryProducts = [];
$monthTotals = array_fill_keys(array_column($months, 'key'), 0.0);
foreach ($rows as $r) {
    $summaryCustomers[$r['customer_code'] !== '' ? $r['customer_code'] : $r['customer']] = true;
    $summaryProducts[strtoupper($r['product'])] = true;
    foreach ($monthTotals as $mk => $ignore) $monthTotals[$mk] += (float)($r['months'][$mk] ?? 0);
}
$currentMonthKey = $months[count($months)-1]['key'];
$currentMonthTotal = $monthTotals[$currentMonthKey] ?? 0;

function c13_qs(array $overrides = []): string {
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) if ($v === '' || $v === null) unset($q[$k]);
    return '?' . http_build_query($q);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Customer Volume History | Lowe Chemical</title>
<style>
:root{--navy:#061d3f;--navy2:#0c2c5a;--red:#c8102e;--blue:#1d5e91;--green:#15803d;--bg:#f3f5f8;--card:#fff;--line:#dbe2ea;--text:#1f2d3d;--muted:#66768a;--light:#e9eef4}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;font-size:14px}
a{color:var(--blue)}
.header{background:var(--navy);border-bottom:4px solid var(--red);color:#fff}.header .inner{max-width:1680px;margin:auto;padding:15px 22px;display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:16px}.logo{width:180px;max-height:58px;object-fit:contain;background:#fff;border-radius:6px;padding:7px 10px}.brand h1{margin:0;font-size:23px}.brand p{margin:4px 0 0;color:#cbd8e8;font-size:12.5px}.meta{text-align:right;font-size:12px;line-height:1.55;color:#d9e3ef}.meta a{color:#fff}
.wrap{max-width:1680px;margin:18px auto;padding:0 16px}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}.card{background:#fff;border:1px solid var(--line);border-left:5px solid var(--blue);border-radius:8px;padding:12px 14px}.card:nth-child(2){border-left-color:var(--green)}.card:nth-child(3){border-left-color:#d97706}.card:nth-child(4){border-left-color:var(--red)}.card .n{font-size:23px;font-weight:800;color:var(--navy)}.card .l{font-size:11px;text-transform:uppercase;color:var(--muted);margin-top:3px;letter-spacing:.03em}
.panel{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px;margin-bottom:14px}.filters{display:grid;grid-template-columns:minmax(235px,1.35fr) minmax(250px,1.45fr) .8fr 1fr 1fr 1fr auto;gap:9px;align-items:end}.field label{display:block;margin-bottom:4px;font-size:10.5px;text-transform:uppercase;font-weight:700;color:var(--muted)}.field input,.field select{width:100%;padding:9px 10px;border:1px solid #b9c5d1;border-radius:6px;background:#fff;font-size:13px}.btn{display:inline-block;border:0;border-radius:6px;background:var(--red);color:#fff;text-decoration:none;font-weight:700;padding:9px 14px;cursor:pointer}.btn.alt{background:#56667a}.btn.navy{background:var(--navy)}.btn.pdf{background:#7f1d1d}.report-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.info{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:9px}.info .title{font-size:15px;font-weight:800;color:var(--navy)}.info .sub{font-size:11.5px;color:var(--muted);margin-top:3px}.multi{position:relative}.multi summary{list-style:none;width:100%;padding:9px 30px 9px 10px;border:1px solid #b9c5d1;border-radius:6px;background:#fff;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:relative;font-size:13px}.multi summary::-webkit-details-marker{display:none}.multi summary:after{content:'▾';position:absolute;right:10px;color:var(--muted)}.multi[open] summary{border-color:var(--blue)}.multi-menu{position:absolute;z-index:50;top:calc(100% + 5px);left:0;width:min(440px,92vw);background:#fff;border:1px solid #b9c5d1;border-radius:7px;box-shadow:0 10px 24px rgba(6,29,63,.18);padding:9px}.multi-tools{display:flex;gap:6px;margin-bottom:7px}.multi-tools input{flex:1;padding:8px 9px;border:1px solid #b9c5d1;border-radius:5px}.mini-btn{border:1px solid var(--line);background:#f7f9fb;color:var(--navy);border-radius:5px;padding:7px 9px;font-weight:700;cursor:pointer}.multi-list{max-height:280px;overflow:auto;border-top:1px solid #e5eaf0;padding-top:5px}.multi-opt{display:flex;align-items:flex-start;gap:8px;padding:6px 4px;font-size:12px;line-height:1.3}.multi-opt:hover{background:#f7f9fb}.multi-opt input{width:auto;margin-top:2px}.selected-note{margin-top:6px;color:var(--muted);font-size:10.5px}.detail-panel{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px;margin:12px 0}.detail-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}.detail-head h2{margin:0;color:var(--navy);font-size:18px}.detail-head p{margin:4px 0 0;color:var(--muted);font-size:11px}.detail-block{margin-top:12px}.btn.sm{padding:6px 9px;font-size:11px}
.tablewrap{overflow:auto;border:1px solid var(--line);border-radius:7px;max-height:68vh}table{border-collapse:separate;border-spacing:0;width:max-content;min-width:0;background:#fff}th{position:sticky;top:0;z-index:4;background:var(--light);color:var(--navy);padding:8px 7px;border-right:1px solid #c5d1dd;border-bottom:2px solid #c5d1dd;font-size:10px;text-transform:uppercase;white-space:nowrap;text-align:right}th.left{text-align:left}td{padding:8px 7px;border-right:1px solid #dfe5eb;border-bottom:1px solid #dfe5eb;font-size:12px;text-align:right;white-space:nowrap}td.left{text-align:left}th:last-child,td:last-child{border-right:0}tbody tr:hover td{background:#f8fafc}.cust{font-weight:700;color:var(--navy)}.code{font-family:Consolas,monospace}.zero{color:#b3bdc8}.neg{color:#a61b1b}.total{font-weight:800;background:#f8fafc}.current{background:#fff8e7}.prod{max-width:360px;white-space:normal;line-height:1.25}.sticky1{position:sticky;left:0;z-index:2;background:#fff}.sticky2{position:sticky;left:200px;z-index:2;background:#fff}.sticky3{position:sticky;left:500px;z-index:2;background:#fff}.sticky4{position:sticky;left:620px;z-index:2;background:#fff}th.sticky1,th.sticky2,th.sticky3,th.sticky4{z-index:6;background:var(--light)}th.sticky1{left:0}th.sticky2{left:200px}th.sticky3{left:500px}th.sticky4{left:620px}.w-cust{min-width:200px;width:200px}.w-prod{min-width:300px;width:300px}.w-code{min-width:90px;width:90px}.w-rep{min-width:120px;width:120px}th:not(.left){min-width:82px;width:82px}td:not(.left){min-width:82px;width:82px}
.pager{display:flex;gap:6px;justify-content:center;align-items:center;margin:13px 0 2px}.pager a,.pager span{padding:6px 10px;border:1px solid var(--line);border-radius:5px;background:#fff;text-decoration:none;color:var(--navy)}.pager span.on{background:var(--navy);color:#fff}.foot{font-size:11px;color:var(--muted);line-height:1.45;margin:10px 2px 22px}.emptyrow td::before{display:none}
.workflow-link{display:inline-block;margin-top:6px;padding:7px 10px;border-radius:6px;background:#fff;color:#061d3f!important;text-decoration:none;font-weight:800;font-size:12px;border:1px solid #d8e1eb}.workflow-link:hover,.workflow-link:focus{background:#eef4fa}
.customer-count{min-width:92px;width:92px;text-align:center!important;white-space:normal!important}.customer-count strong{display:block;font-size:14px;color:var(--navy);margin-bottom:4px}.customer-count .btn{padding:5px 8px;font-size:10px;width:auto}
@media(max-width:1050px){
 .cards{grid-template-columns:repeat(2,1fr)}
 .filters{grid-template-columns:1fr 1fr}
 .meta{text-align:left}
 .header .inner{align-items:flex-start}
}
@media(max-width:700px){
 body{font-size:13px}
 .header .inner{padding:14px 12px;display:block}
 .brand{align-items:flex-start;gap:10px}
 .logo{width:145px;max-height:52px}
 .brand h1{font-size:20px;line-height:1.15}
 .brand p{font-size:11.5px;line-height:1.35}
 .meta{margin-top:12px;text-align:left;font-size:11.5px}
 .wrap{margin:12px auto;padding:0 8px}
 .cards{grid-template-columns:1fr 1fr;gap:8px}
 .card{padding:10px 11px}
 .card .n{font-size:19px}
 .card .l{font-size:9.5px}
 .panel{padding:10px;border-radius:7px}
 .filters{grid-template-columns:1fr;gap:8px}
 .field label{font-size:10px}
 .field input,.field select{font-size:16px;padding:11px 10px}
 .filters>div:last-child{display:grid;grid-template-columns:1fr 1fr;gap:8px}
 .btn{width:100%;text-align:center;padding:11px 12px}
 .info{display:block}
 .info .title{font-size:14px;line-height:1.3}
 .info .sub{line-height:1.45}
 .info .btn{margin-top:9px}
 .report-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}
 .tablewrap{overflow:visible;max-height:none;border:0;background:transparent}
 table{display:block;min-width:0;width:100%;background:transparent}
 thead{display:none}
 tbody{display:block}
 tbody tr{display:block;background:#fff;border:1px solid var(--line);border-radius:8px;margin:0 0 10px;overflow:hidden;box-shadow:0 1px 2px rgba(6,29,63,.04)}
 tbody tr:hover td{background:inherit}
 td,td.left{display:grid;grid-template-columns:minmax(118px,42%) 1fr;gap:10px;align-items:start;width:100%!important;min-width:0!important;max-width:none!important;padding:8px 10px;border-right:0;border-bottom:1px solid #e6ebf0;text-align:right;white-space:normal;line-height:1.35;background:#fff}
 td:last-child{border-bottom:0}
 td::before{content:attr(data-label);font-size:10px;line-height:1.25;font-weight:800;text-transform:uppercase;letter-spacing:.02em;color:var(--muted);text-align:left}
 td.left{text-align:right}
 td .cust{display:block;text-align:right}
 td.prod{max-width:none}
 td.current{background:#fff8e7}
 td.total{background:#eef4fa;font-size:14px;color:var(--navy)}
 .sticky1,.sticky2,.sticky3,.sticky4{position:static!important;left:auto!important;z-index:auto!important}
 .code{font-family:Consolas,monospace;overflow-wrap:anywhere}
 .pager{margin-top:10px}
 .pager a,.pager span{padding:7px 9px}
 .foot{font-size:10.5px;margin:8px 2px 16px}
}
@media(max-width:430px){
 .cards{grid-template-columns:1fr}
 .brand{display:block}
 .logo{margin-bottom:10px}
 td,td.left{grid-template-columns:112px 1fr;gap:8px}
}
@page{size:Letter landscape;margin:.28in}
@media print{
 html,body{background:#fff!important;color:#000!important;font-size:8px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
 .filters,.pager,.report-actions,.header a{display:none!important}
 .header{background:#fff!important;color:#000!important;border-bottom:2px solid #c8102e;margin:0 0 7px}
 .header .inner{max-width:none;padding:0 0 7px;display:flex;flex-wrap:nowrap}
 .logo{display:block;width:110px;max-height:40px;padding:3px 6px;border:1px solid #ddd}
 .brand{gap:9px}.brand h1{font-size:15px;color:#061d3f}.brand p{font-size:8px;color:#333}.meta{font-size:7px;color:#333;text-align:right}
 .wrap{max-width:none;margin:0;padding:0}.cards{grid-template-columns:repeat(4,1fr);gap:5px;margin-bottom:7px}.card{padding:5px 7px;border-radius:0}.card .n{font-size:12px}.card .l{font-size:6.5px}
 .panel{border:0;border-radius:0;padding:0;margin:0}.info{margin:0 0 5px}.info .title{font-size:9px}.info .sub{font-size:6.5px}
 .tablewrap{overflow:visible!important;max-height:none!important;border:1px solid #b9c5d1;border-radius:0}
 table{display:table!important;width:100%!important;min-width:0!important;table-layout:fixed!important;border-collapse:collapse!important;background:#fff;font-size:6.5px}
 thead{display:table-header-group!important}tbody{display:table-row-group!important}tbody tr{display:table-row!important;border:0!important;box-shadow:none!important;break-inside:avoid}
 th,td,td.left{display:table-cell!important;position:static!important;left:auto!important;z-index:auto!important;padding:2px 2px!important;border:1px solid #cfd7df!important;line-height:1.12!important;vertical-align:middle!important}
 th{background:#e9eef4!important;color:#061d3f!important;font-size:6px!important;white-space:nowrap!important}
 td{font-size:6.4px!important;white-space:nowrap!important;text-align:right!important}
 td.left{text-align:left!important;white-space:normal!important;overflow-wrap:anywhere!important}
 td::before{display:none!important}.w-cust{width:15%!important;min-width:0!important}.w-prod{width:20%!important;min-width:0!important}.w-rep{width:9%!important;min-width:0!important}.prod{max-width:none!important}
 th:not(.left),td:not(.left){width:auto!important;min-width:0!important}.total{font-weight:800!important;background:#f3f6f9!important}.current{background:#fff8e7!important}
 .foot{font-size:6px;margin:5px 0 0;line-height:1.25}
}
.pdf-mode .filters,.pdf-mode .pager,.pdf-mode .report-actions{display:none}
</style>
</head>
<body class="<?=$pdfMode?'pdf-mode':''?>">
<header class="header"><div class="inner">
 <div class="brand"><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><div><h1>Customer Volume History</h1><p>Rolling pounds by customer and product description</p></div></div>
 <div class="meta">Report through: <strong><?=c13_esc(c13_date($asOf))?></strong><br>Invoice history through: <strong><?=c13_esc(c13_date($payload['latest_invoice_date'] ?? null))?></strong><br><a href="predictive-orders.php">Predictive Orders</a> &nbsp;|&nbsp; <a href="predictive-orders-admin.php">Update from Excel</a><br><a class="workflow-link" href="salesworkflow.php">Back to Sales Workflow</a></div>
</div></header>

<main class="wrap">
 <div class="cards">
  <div class="card"><div class="n"><?=c13_num(count($summaryCustomers))?></div><div class="l">Customers shown</div></div>
  <div class="card"><div class="n"><?=c13_num($totalPairs)?></div><div class="l">Customer / product pairs</div></div>
  <div class="card"><div class="n"><?=c13_num($summaryTotal)?> lb</div><div class="l"><?=$monthsToShow?>-month volume</div></div>
  <div class="card"><div class="n"><?=c13_num($currentMonthTotal)?> lb</div><div class="l"><?=c13_esc($months[count($months)-1]['full'])?> volume to date</div></div>
 </div>

 <section class="panel">
  <form method="get" class="filters">
   <div class="field"><label>Customers</label><details class="multi" id="customerMulti"><summary id="customerSummary"><?=$customerFilter ? c13_num(count($customerFilter)).' customer'.(count($customerFilter)===1?'':'s').' selected' : 'All customers'?></summary><div class="multi-menu"><div class="multi-tools"><input type="text" id="customerSearch" placeholder="Type any part of customer name"><button class="mini-btn" type="button" id="selectCustomers">Select visible</button><button class="mini-btn" type="button" id="clearCustomers">Clear</button></div><div class="multi-list" id="customerList"><?php foreach($customers as $c): ?><label class="multi-opt" data-search="<?=c13_esc(strtolower($c))?>"><input type="checkbox" name="customers[]" value="<?=c13_esc($c)?>" <?=isset($customerLookup[$c])?'checked':''?>><span><?=c13_esc($c)?></span></label><?php endforeach; ?></div><div class="selected-note">Leave all unchecked to include every customer.</div></div></details></div>
   <div class="field"><label>Products</label><details class="multi" id="productMulti"><summary id="productSummary"><?=$productFilter ? c13_num(count($productFilter)).' product'.(count($productFilter)===1?'':'s').' selected' : 'All products'?></summary><div class="multi-menu"><div class="multi-tools"><input type="text" id="productSearch" placeholder="Type any part of product name"><button class="mini-btn" type="button" id="selectProducts">Select visible</button><button class="mini-btn" type="button" id="clearProducts">Clear</button></div><div class="multi-list" id="productList"><?php foreach($products as $p): ?><label class="multi-opt" data-search="<?=c13_esc(strtolower($p))?>"><input type="checkbox" name="products[]" value="<?=c13_esc($p)?>" <?=isset($productLookup[$p])?'checked':''?>><span><?=c13_esc($p)?></span></label><?php endforeach; ?></div><div class="selected-note">Leave all unchecked to include every product.</div></div></details></div>
   <div class="field"><label>Months to Show</label><select name="months"><?php for($m=1;$m<=13;$m++): ?><option value="<?=$m?>" <?=$monthsToShow===$m?'selected':''?>><?=$m?> month<?=$m===1?'':'s'?></option><?php endfor; ?></select></div>
   <div class="field"><label>Sales rep</label><select name="rep"><option value="">All reps</option><?php foreach($reps as $r): ?><option value="<?=c13_esc($r)?>" <?=$rep===$r?'selected':''?>><?=c13_esc($r)?></option><?php endforeach; ?></select></div>
   <div class="field"><label>Minimum <?=$monthsToShow?>-mo lb</label><select name="minvol"><?php foreach([0=>'Any',1000=>'1,000+',5000=>'5,000+',10000=>'10,000+',50000=>'50,000+',100000=>'100,000+'] as $k=>$v): ?><option value="<?=$k?>" <?=(float)$minVol===(float)$k?'selected':''?>><?=c13_esc($v)?></option><?php endforeach; ?></select></div>
   <div class="field"><label>Sort by</label><select name="sort"><option value="customer" <?=$sort==='customer'?'selected':''?>>Customer</option><option value="volume" <?=$sort==='volume'?'selected':''?>><?=$monthsToShow?>-month volume</option><option value="product" <?=$sort==='product'?'selected':''?>>Product description</option><option value="recent" <?=$sort==='recent'?'selected':''?>>Most recent invoice</option></select></div>
   <div><button class="btn" type="submit">Run</button> <a class="btn alt" href="customer-13.php">Reset</a></div>
  </form>
 </section>

 <section class="panel">
  <div class="info"><div><div class="title"><?=c13_num($totalPairs)?> customer/product rows · <?=$monthsToShow?> month<?=$monthsToShow===1?'':'s'?> shown</div><div class="sub">Volumes are net pounds grouped by customer and Product Description, regardless of product code. Months run from most recent to oldest. The current partial month is highlighted.</div></div><div class="report-actions"><a class="btn navy" href="<?=c13_esc(c13_qs(['download'=>'xlsx','page'=>null,'pdf'=>null]))?>">Download .xlsx</a><a class="btn pdf" target="_blank" href="<?=c13_esc(c13_qs(['pdf'=>'1','page'=>null,'download'=>null]))?>">Save PDF</a></div></div>
  <div class="tablewrap">
   <table>
    <thead><tr>
     <th class="left sticky1 w-cust">Customer</th>
     <th class="left sticky2 w-prod">Product Description</th>
     <th class="left sticky3 w-rep">Rep</th>
     <th class="customer-count">No. of<br>Customers</th>
     <?php foreach($displayMonths as $m): ?><th class="<?=$m['key']===$currentMonthKey?'current':''?>" title="<?=c13_esc($m['full'])?>"><?=c13_esc($m['label'])?></th><?php endforeach; ?>
     <th>Total <?=$monthsToShow?> Mo</th><th>Detail</th>
    </tr></thead>
    <tbody>
    <?php if(!$slice): ?><tr class="emptyrow"><td colspan="<?=6+$monthsToShow?>" data-label="" style="text-align:center;padding:32px;color:#66768a">No customer/product rows match these filters.</td></tr><?php endif; ?>
    <?php foreach($slice as $r): ?>
     <tr>
      <td class="left sticky1 w-cust" data-label="Customer"><span class="cust"><?=c13_esc($r['customer'])?></span><?php if($r['customer_code']!==''): ?><br><span style="font-size:10px;color:#7a8896"><?=c13_esc($r['customer_code'])?></span><?php endif; ?></td>
      <td class="left sticky2 w-prod prod" data-label="Product Description"><?=c13_esc($r['product'])?></td>
      <td class="left sticky3 w-rep" data-label="Sales Rep"><?=c13_esc($r['rep'])?></td>
      <td class="customer-count" data-label="No. of Customers"><strong><?=c13_num($r['product_customer_count']??0)?></strong><a class="btn navy sm" target="_blank" rel="noopener" href="<?=c13_esc(c13_qs(['product_customers'=>$r['product'],'detail_customer'=>null,'detail_customer_code'=>null,'detail_product'=>null,'page'=>null,'download'=>null,'pdf'=>null]))?>">Detail</a></td>
      <?php foreach($displayMonths as $m): $v=(float)($r['months'][$m['key']]??0); $cls=abs($v)<0.00001?'zero':($v<0?'neg':''); if($m['key']===$currentMonthKey) $cls.=' current'; ?>
       <td class="<?=$cls?>" data-label="<?=c13_esc($m['label'])?>"><?=$v==0?'-':c13_num($v)?></td>
      <?php endforeach; ?>
      <td class="total" data-label="Total <?=$monthsToShow?> Mo"><?=c13_num($r['period_total'])?></td>
      <td data-label="Detail"><a class="btn navy sm" href="<?=c13_esc(c13_qs(['detail_customer'=>$r['customer'],'detail_customer_code'=>$r['customer_code'],'detail_product'=>$r['product'],'page'=>$page,'download'=>null,'pdf'=>null]))?>#invoice-detail">Detail</a></td>
     </tr>
    <?php endforeach; ?>
    </tbody>
   </table>
  </div>
  <?php if($totalPages>1): ?><div class="pager"><?php if($page>1): ?><a href="<?=c13_esc(c13_qs(['page'=>$page-1]))?>">Previous</a><?php endif; ?><span class="on">Page <?=$page?> of <?=$totalPages?></span><?php if($page<$totalPages): ?><a href="<?=c13_esc(c13_qs(['page'=>$page+1]))?>">Next</a><?php endif; ?></div><?php endif; ?>
 </section>
 <?php if($detailCustomer!=='' && $detailProduct!==''): ?>
 <section class="detail-panel" id="invoice-detail">
  <div class="detail-head"><div><h2>Customer / Product Detail</h2><p><strong><?=c13_esc($detailCustomer)?></strong> · <?=c13_esc($detailProduct)?> · <?=$monthsToShow?> month<?=$monthsToShow===1?'':'s'?> shown · <?=c13_num(count($detailInvoiceDocs))?> invoice/credit document<?=count($detailInvoiceDocs)===1?'':'s'?> · <?=c13_num($detailInvoiceLbs)?> lb net</p></div><a class="btn alt sm" href="<?=c13_esc(c13_qs(['detail_customer'=>null,'detail_customer_code'=>null,'detail_product'=>null]))?>">Close Detail</a></div>
  <div class="detail-block"><div class="info"><div><div class="title">Invoice History</div><div class="sub">Invoices and credits for this customer/product during the selected displayed period.</div></div></div>
   <div class="tablewrap" style="max-height:420px"><table><thead><tr><th class="left">Date</th><th class="left">Document</th><th class="left">Type</th><th class="left">Customer PO</th><th class="left">Product Number</th><th>LBS</th><th>Sales</th><th>Profit</th><th class="left">Rep</th></tr></thead><tbody>
   <?php if(!$detailInvoices): ?><tr><td colspan="9" style="text-align:center;padding:24px;color:#66768a">No invoice detail was found for the selected period.</td></tr><?php endif; ?>
   <?php foreach($detailInvoices as $d): ?><tr><td class="left" data-label="Date"><?=c13_esc(c13_date($d['date']))?></td><td class="left" data-label="Document"><?=c13_esc($d['invoice']?:'-')?></td><td class="left" data-label="Type"><?=c13_esc(ucfirst($d['type']))?></td><td class="left" data-label="Customer PO"><?=c13_esc($d['customer_po']?:'-')?></td><td class="left code" data-label="Product Number"><?=c13_esc($d['product_no']?:'-')?></td><td data-label="LBS"><?=c13_num($d['lbs'])?></td><td data-label="Sales">$<?=c13_num($d['sales'],2)?></td><td data-label="Profit">$<?=c13_num($d['profit'],2)?></td><td class="left" data-label="Rep"><?=c13_esc($d['rep']?:'-')?></td></tr><?php endforeach; ?>
   <?php if($detailInvoices): ?><tr><td colspan="5" class="left"><strong>Period Total</strong></td><td><strong><?=c13_num($detailInvoiceLbs)?></strong></td><td><strong>$<?=c13_num($detailSales,2)?></strong></td><td><strong>$<?=c13_num($detailProfit,2)?></strong></td><td></td></tr><?php endif; ?>
   </tbody></table></div>
  </div>
  <div class="detail-block"><div class="info"><div><div class="title">Open Sales Orders</div><div class="sub">Current open orders for this customer/product, regardless of invoice-history period.</div></div></div>
   <div class="tablewrap" style="max-height:360px"><table><thead><tr><th class="left">Order</th><th class="left">Release</th><th class="left">Order Date</th><th class="left">Ship Date</th><th class="left">Product Number</th><th>Open LBS</th><th>Open Sales</th><th class="left">Rep</th></tr></thead><tbody>
   <?php if(!$detailOpenOrders): ?><tr><td colspan="8" style="text-align:center;padding:24px;color:#66768a">No open sales orders were found for this customer/product.</td></tr><?php endif; ?>
   <?php foreach($detailOpenOrders as $d): ?><tr><td class="left" data-label="Order"><?=c13_esc($d['order']?:'-')?></td><td class="left" data-label="Release"><?=c13_esc($d['release']?:'-')?></td><td class="left" data-label="Order Date"><?=c13_esc(c13_date($d['order_date']))?></td><td class="left" data-label="Ship Date"><?=c13_esc(c13_date($d['ship_date']))?></td><td class="left code" data-label="Product Number"><?=c13_esc($d['product_no']?:'-')?></td><td data-label="Open LBS"><?=c13_num($d['lbs'])?></td><td data-label="Open Sales">$<?=c13_num($d['sales'],2)?></td><td class="left" data-label="Rep"><?=c13_esc($d['rep']?:'-')?></td></tr><?php endforeach; ?>
   <?php if($detailOpenOrders): ?><tr><td colspan="5" class="left"><strong>Open Order Total</strong></td><td><strong><?=c13_num($detailOpenLbs)?></strong></td><td><strong>$<?=c13_num($detailOpenSales,2)?></strong></td><td></td></tr><?php endif; ?>
   </tbody></table></div>
  </div>
 </section>
 <?php endif; ?>
 <div class="foot">Source: <?=c13_esc($payload['source_file'] ?? basename($workbook))?>. Included invoice rows in the source 13-month period: <?=c13_num($payload['invoice_rows_in_period'] ?? 0)?>, including <?=c13_num($payload['credit_rows_in_period'] ?? 0)?> credit rows. Credits are netted against pounds so returned or reversed volume does not inflate the report. Product codes are ignored for grouping; identical Product Descriptions are combined into one customer/product row. The report cache automatically refreshes when the master workbook changes.</div>
</main>
<script>
function setupMulti(listId,searchId,summaryId,selectId,clearId,singular){
 const list=document.getElementById(listId),search=document.getElementById(searchId),summary=document.getElementById(summaryId),selectBtn=document.getElementById(selectId),clearBtn=document.getElementById(clearId);
 if(!list||!search||!summary)return;
 const opts=[...list.querySelectorAll('.multi-opt')],checks=()=>[...list.querySelectorAll('input[type="checkbox"]')];
 const normalize=v=>(v||'').toString().toLowerCase().replace(/[^a-z0-9]+/g,' ').trim();
 const update=()=>{const n=checks().filter(c=>c.checked).length;summary.textContent=n?n+' '+singular+(n===1?'':'s')+' selected':'All '+singular+'s';};
 const applySearch=()=>{const q=normalize(search.value),terms=q?q.split(/\s+/).filter(Boolean):[];opts.forEach(o=>{const hay=normalize(o.dataset.search||o.textContent);o.style.display=(!terms.length||terms.every(t=>hay.includes(t)))?'flex':'none';});};
 search.addEventListener('input',applySearch);search.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();applySearch();}});
 list.addEventListener('change',update);selectBtn.addEventListener('click',()=>{applySearch();opts.filter(o=>o.style.display!=='none').forEach(o=>{const c=o.querySelector('input');if(c)c.checked=true;});update();});clearBtn.addEventListener('click',()=>{checks().forEach(c=>c.checked=false);update();});update();
}
setupMulti('customerList','customerSearch','customerSummary','selectCustomers','clearCustomers','customer');
setupMulti('productList','productSearch','productSummary','selectProducts','clearProducts','product');
</script>
<?php if($pdfMode): ?>
<script>
window.addEventListener('load', function(){
  setTimeout(function(){ window.print(); }, 250);
});
</script>
<?php endif; ?>
</body>
</html>
