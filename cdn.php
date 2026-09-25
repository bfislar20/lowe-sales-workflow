<?php
/*
 * Lowe Chemical Company - CDN Activity Report
 *
 * Uses the same Lowe Master workbook maintained by the predictive-order system.
 * Requires predictive-order-engine.php in the same folder so the existing
 * dependency-free XLSX reader can be reused.
 */

require_once __DIR__ . '/lowe-dashboard-common.php';

function cdn_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cdn_num($v, $d=0): string { return number_format((float)$v, $d); }
function cdn_money($v): string { return '$' . number_format((float)$v, 2); }
function cdn_date($v): string { if (!$v) return '-'; $t=strtotime((string)$v); return $t ? date('M j, Y',$t) : '-'; }
function cdn_norm($v): string {
    $s = strtoupper(trim((string)$v));
    $s = preg_replace('/\s+/', ' ', $s);
    $s = str_replace(['& CO., INC.','& COMPANY, INC.'], ['& CO','& COMPANY'], $s);
    return $s;
}

/*
 * Current CDN member roster is maintained here as canonical names + exact Lowe aliases.
 * Exact matching is intentional so unrelated companies with generic words such as
 * "Chemical" or "Resources" are not accidentally counted.
 */
$cdnMembers = [
    'Astro Chemicals, Inc.' => [
        'ASTRO CHEMICAL', 'ASTRO CHEMICALS', 'ASTRO CHEMICALS, INC.', 'ASTRO CHEMICALS INC.'
    ],
    'Barton Solvents' => [
        'BARTON SOLVENTS', 'BARTON SOLVENTS, INC.', 'BARTON SOLVENTS INC.'
    ],
    'Brown Chemical Co., Inc.' => [
        'BROWN CHEMICAL CO', 'BROWN CHEMICAL CO.', 'BROWN CHEMICAL CO., INC.', 'BROWN CHEMICAL CO INC.'
    ],
    'Buckley Oil' => [
        'BUCKLEY OIL', 'BUCKLEY OIL COMPANY', 'BUCKLEY OIL CO.'
    ],
    'ChemGroup' => [
        'CHEMGROUP', 'CHEM GROUP', 'BONDED CHEMICALS', 'BONDED CHEMICALS, INC.',
        'CHEMICAL SERVICES', 'CHEMICAL SERVICES, INC.', 'CHEMICALS INCORPORATED',
        'CHEMICALS INCORPORATED BAY CITY SITE', 'CHEMICALS, INC.', 'CHEMICAL RESOURCES INC.',
        'CHEMICAL RESOURCES, INC.', 'SPECIALTY CHEMICAL CO.', 'SPECIALTY CHEMICAL CO., LLC',
        'CHEMGROUP OPERATING COMPANY'
    ],
    'Colonial Chemical Solutions, Inc.' => [
        'COLONIAL CHEMICAL SOLUTIONS', 'COLONIAL CHEMICAL SOLUTIONS, INC',
        'COLONIAL CHEMICAL SOLUTIONS, INC.', 'COLONIAL CHEMICAL SOLUTIONS INC.'
    ],
    'JR Hess Company, Inc.' => [
        'JOHN R HESS', 'JOHN R. HESS', 'JOHN R HESS & COMPANY', 'JOHN R. HESS & COMPANY',
        'JOHN R. HESS & SON', 'JOHN R HESS & SON', 'JR HESS & COMPANY', 'J R HESS & COMPANY',
        'JR HESS COMPANY', 'J R HESS COMPANY'
    ],
    'Norman, Fox & Co.' => [
        'NORMAN, FOX & COMPANY', 'NORMAN FOX & COMPANY', 'NORMAN, FOX & CO.', 'NORMAN FOX & CO.'
    ],
    'Producers Chemical Company' => [
        'PRODUCERS CHEMICAL', 'PRODUCERS CHEMICAL COMPANY', 'PRODUCERS CHEMICAL CO.'
    ],
    'Riteks, Inc.' => [
        'RITEKS', 'RITEKS, INC.', 'RITEKS INC.'
    ],
    'Seeler Industries Incorporated' => [
        'SEELER INDUSTRIES', 'SEELER INDUSTRIES INCORPORATED', 'SEELER INDUSTRIES, INC.', 'SEELER INDUSTRIES INC.'
    ],
    'The Whitaker Company' => [
        'WHITAKER OIL COMPANY', 'WHITAKER OIL', 'THE WHITAKER COMPANY', 'WHITAKER COMPANY'
    ],
    'Viking Chemical Company' => [
        'VIKING CHEMICAL', 'VIKING CHEMICAL COMPANY', 'VIKING CHEMICAL CO.'
    ],
    'Webb Chemical Service Corporation' => [
        'WEBB CHEMICAL SERVICE CORP', 'WEBB CHEMICAL SERVICE CORP.', 'WEBB CHEMICAL SERVICE CORPORATION',
        'WEBB CHEMICAL'
    ],
    'CDN Network' => [
        'CHEMICAL DISTRIBUTION NETWORK'
    ],
];

$aliasToMember = [];
foreach ($cdnMembers as $member => $aliases) {
    foreach ($aliases as $alias) $aliasToMember[cdn_norm($alias)] = $member;
}
function cdn_member_for($name, $aliasMap): ?string {
    $n = cdn_norm($name);
    return $aliasMap[$n] ?? null;
}
function cdn_url(array $over=[]): string {
    $base = [];
    foreach (['from','to','detail','member','po','invoice'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = (string)$_GET[$k];
    }
    foreach ($over as $k=>$v) {
        if ($v === null || $v === '') unset($base[$k]); else $base[$k] = (string)$v;
    }
    return 'cdn.php?' . http_build_query($base);
}
function cdn_gp($profit,$sales): float { return abs((float)$sales)>0.0001 ? (float)$profit/(float)$sales : 0.0; }
function cdn_field(array $r,array $names,$default=''){ foreach($names as $n){ if(array_key_exists($n,$r) && $r[$n]!=='' && $r[$n]!==null) return $r[$n]; } return $default; }
function cdn_purchase_total(array $r): float { return ld_purchase_total($r); }
function cdn_purchase_cost_lb(array $r): float { return ld_purchase_cost_lb($r); }


$xlsx = ld_master();

try {
    $purchaseRows = ld_rows('Purchases');
    $invoiceRows  = ld_rows('Invoices');
} catch (Throwable $e) {
    http_response_code(500);
    die('The Lowe Master workbook could not be read: ' . cdn_h($e->getMessage()));
}

// Determine overall source date range.
$minDate = null; $maxDate = null;
foreach ($purchaseRows as $r) {
    $d = of_date($r['Receipt Date'] ?? '');
    if (!$d) continue;
    if ($minDate===null || $d<$minDate) $minDate=$d;
    if ($maxDate===null || $d>$maxDate) $maxDate=$d;
}
foreach ($invoiceRows as $r) {
    $d = of_date($r['INV. Date'] ?? '');
    if (!$d) continue;
    if ($minDate===null || $d<$minDate) $minDate=$d;
    if ($maxDate===null || $d>$maxDate) $maxDate=$d;
}
$minDate = $minDate ?: date('Y-01-01');
$maxDate = $maxDate ?: date('Y-m-d');

$from = trim($_GET['from'] ?? $minDate);
$to   = trim($_GET['to'] ?? $maxDate);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from=$minDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) $to=$maxDate;
if ($from>$to) { $tmp=$from; $from=$to; $to=$tmp; }

$detail = $_GET['detail'] ?? '';
if (!in_array($detail,['purchase-pos','purchase-po','sales-invoices','sales-invoice'],true)) $detail='';
$selectedMember = trim((string)($_GET['member'] ?? ''));
$selectedPO = trim((string)($_GET['po'] ?? ''));
$selectedInvoice = trim((string)($_GET['invoice'] ?? ''));

$purchases = [];
$sales = [];
foreach (array_keys($cdnMembers) as $m) {
    $purchases[$m] = ['lbs'=>0.0,'spend'=>0.0,'transactions'=>0];
    $sales[$m] = ['lbs'=>0.0,'sales'=>0.0,'profit'=>0.0,'transactions'=>0];
}

// Purchases from CDN members.
foreach ($purchaseRows as $r) {
    $member = cdn_member_for($r['Supplier Name'] ?? '', $aliasToMember);
    if (!$member) continue;
    $d = of_date($r['Receipt Date'] ?? '');
    if (!$d || $d<$from || $d>$to) continue;
    $lbs = of_num($r['LBs Received'] ?? 0);
    $spend = cdn_purchase_total($r);
    $purchases[$member]['lbs'] += $lbs;
    $purchases[$member]['spend'] += $spend;
    $purchases[$member]['transactions']++;
}

// Sales to CDN members. Include invoices and credits so volume, sales and profit are net activity.
foreach ($invoiceRows as $r) {
    $member = cdn_member_for($r['Cust Name'] ?? '', $aliasToMember);
    if (!$member) continue;
    $d = of_date($r['INV. Date'] ?? '');
    if (!$d || $d<$from || $d>$to) continue;
    $type = strtolower(trim((string)($r['Doc Type'] ?? '')));
    if ($type !== 'invoiced' && $type !== 'credit') continue;
    $lbs = of_num($r['LBS'] ?? 0);
    $salesD = of_num($r['Sales $$'] ?? 0);
    $profit = of_num($r['Profit $$'] ?? 0);
    $sales[$member]['lbs'] += $lbs;
    $sales[$member]['sales'] += $salesD;
    $sales[$member]['profit'] += $profit;
    $sales[$member]['transactions']++;
}

$purchases = array_filter($purchases, fn($r)=>abs($r['lbs'])>0.0001 || abs($r['spend'])>0.005);
$sales = array_filter($sales, fn($r)=>abs($r['lbs'])>0.0001 || abs($r['sales'])>0.005 || abs($r['profit'])>0.005);

uasort($purchases, fn($a,$b)=>$b['spend']<=>$a['spend']);
uasort($sales, fn($a,$b)=>$b['sales']<=>$a['sales']);

$pTot = ['lbs'=>0.0,'spend'=>0.0,'transactions'=>0];
foreach ($purchases as $r) { foreach ($pTot as $k=>$v) $pTot[$k]+=$r[$k]; }
$sTot = ['lbs'=>0.0,'sales'=>0.0,'profit'=>0.0,'transactions'=>0];
foreach ($sales as $r) { foreach ($sTot as $k=>$v) $sTot[$k]+=$r[$k]; }
$gpPct = abs($sTot['sales'])>0.0001 ? $sTot['profit']/$sTot['sales'] : 0;

// Drill-down data is generated only for the selected member/PO/invoice.
$purchasePOs = [];
$purchaseLines = [];
if (($detail==='purchase-pos' || $detail==='purchase-po') && isset($cdnMembers[$selectedMember])) {
    foreach ($purchaseRows as $r) {
        $member = cdn_member_for($r['Supplier Name'] ?? '', $aliasToMember);
        if ($member !== $selectedMember) continue;
        $d = of_date($r['Receipt Date'] ?? '');
        if (!$d || $d<$from || $d>$to) continue;
        $po = trim((string)cdn_field($r,['PO Number','PO#'],''));
        if ($po==='') $po='(No PO Number)';
        $lbs=of_num($r['LBs Received'] ?? 0); $cost=cdn_purchase_total($r);
        if (!isset($purchasePOs[$po])) $purchasePOs[$po]=['po'=>$po,'first_date'=>$d,'last_date'=>$d,'lbs'=>0.0,'spend'=>0.0,'lines'=>0,'products'=>[]];
        $x=&$purchasePOs[$po];
        if ($d<$x['first_date']) $x['first_date']=$d; if ($d>$x['last_date']) $x['last_date']=$d;
        $x['lbs']+=$lbs; $x['spend']+=$cost; $x['lines']++; $x['products'][trim((string)($r['Product Name']??''))]=true;
        unset($x);
        if ($detail==='purchase-po' && $po===$selectedPO) $purchaseLines[]=$r;
    }
    uasort($purchasePOs, fn($a,$b)=>strcmp($b['last_date'],$a['last_date']));
    usort($purchaseLines, fn($a,$b)=>strcmp((string)of_date($b['Receipt Date']??''),(string)of_date($a['Receipt Date']??'')));
}

$salesInvoices = [];
$salesLines = [];
if (($detail==='sales-invoices' || $detail==='sales-invoice') && isset($cdnMembers[$selectedMember])) {
    foreach ($invoiceRows as $r) {
        $member = cdn_member_for($r['Cust Name'] ?? '', $aliasToMember);
        if ($member !== $selectedMember) continue;
        $d=of_date($r['INV. Date']??''); if(!$d || $d<$from || $d>$to) continue;
        $type=strtolower(trim((string)($r['Doc Type']??''))); if($type!=='invoiced' && $type!=='credit') continue;
        $inv=trim((string)($r['INV#']??'')); if($inv==='') $inv='(No Invoice Number)';
        $lbs=of_num($r['LBS']??0); $salesD=of_num($r['Sales $$']??0); $profit=of_num($r['Profit $$']??0);
        if(!isset($salesInvoices[$inv])) $salesInvoices[$inv]=['invoice'=>$inv,'date'=>$d,'customer'=>trim((string)($r['Cust Name']??'')),'customer_po'=>trim((string)($r['Cust PO#']??'')),'lbs'=>0.0,'sales'=>0.0,'profit'=>0.0,'lines'=>0,'products'=>[],'types'=>[]];
        $x=&$salesInvoices[$inv]; if($d>$x['date'])$x['date']=$d; $x['lbs']+=$lbs; $x['sales']+=$salesD; $x['profit']+=$profit; $x['lines']++; $x['products'][trim((string)($r['Product Name']??''))]=true; $x['types'][$type]=true; unset($x);
        if($detail==='sales-invoice' && $inv===$selectedInvoice) $salesLines[]=$r;
    }
    uasort($salesInvoices, fn($a,$b)=>strcmp($b['date'],$a['date']));
    usort($salesLines, fn($a,$b)=>strcmp((string)($a['Product Name']??''),(string)($b['Product Name']??'')));
}

$y = date('Y', strtotime($maxDate));
$ytdFrom = $y.'-01-01';
$last12From = date('Y-m-d', strtotime($maxDate.' -11 months -'.(date('j',strtotime($maxDate))-1).' days'));

function cdn_qs($from,$to): string { return '?'.http_build_query(['from'=>$from,'to'=>$to]); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>CDN Activity Report | Lowe Chemical</title>
<style>
:root{--navy:#061d3f;--navy2:#0d315f;--red:#c8102e;--bg:#eef2f6;--card:#fff;--line:#cfd9e4;--text:#213142;--muted:#66788a;--green:#177245;--blue:#1d5e91;--gold:#a96400}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:var(--text);font-size:14px}
a{color:var(--blue)}
.header{background:var(--navy);color:#fff;border-bottom:5px solid var(--red)}
.header .inner{max-width:1500px;margin:auto;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:16px}.logo{width:190px;max-height:66px;object-fit:contain;background:#fff;border-radius:7px;padding:8px 12px}
.brand h1{margin:0;font-size:24px}.brand p{margin:4px 0 0;color:#cbd8e7;font-size:12.5px}
.meta{text-align:right;font-size:12px;line-height:1.6;color:#dce6f1}
.wrap{max-width:1500px;margin:18px auto;padding:0 18px 30px}
.controls{background:#fff;border:1px solid var(--line);border-radius:9px;padding:13px 14px;margin-bottom:14px;display:flex;align-items:end;gap:10px;flex-wrap:wrap}
.field label{display:block;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:4px}.field input{padding:8px 9px;border:1px solid #b8c4d0;border-radius:5px;font:inherit}
.btn{display:inline-block;padding:9px 13px;border:0;border-radius:5px;background:var(--red);color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.btn.alt{background:#5a697a}.quick{display:flex;gap:6px;flex-wrap:wrap}.quick a{padding:8px 10px;border:1px solid var(--line);border-radius:5px;background:#f7f9fb;text-decoration:none;color:var(--navy);font-size:12px;font-weight:700}
.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}.sum{background:#fff;border:1px solid var(--line);border-left:5px solid var(--navy2);border-radius:8px;padding:12px 14px}.sum .v{font-size:22px;font-weight:800;color:var(--navy)}.sum .k{font-size:11px;text-transform:uppercase;color:var(--muted);margin-top:3px}.sum.buy{border-left-color:var(--blue)}.sum.sell{border-left-color:var(--green)}.sum.profit{border-left-color:var(--gold)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start}.panel{background:#fff;border:1px solid var(--line);border-radius:9px;overflow:hidden}.panel-head{padding:14px 16px;background:#f8fafc;border-bottom:1px solid var(--line)}.panel-head h2{margin:0;color:var(--navy);font-size:19px}.panel-head p{margin:4px 0 0;color:var(--muted);font-size:12px;line-height:1.45}
.tw{overflow:auto}table{border-collapse:collapse;width:100%;background:#fff}th,td{padding:9px 10px;border-right:1px solid #dce3ea;border-bottom:1px solid #dce3ea}th:last-child,td:last-child{border-right:0}th{background:#e8eef5;color:var(--navy);font-size:10.5px;text-transform:uppercase;letter-spacing:.03em;text-align:left;white-space:nowrap}td{font-size:13px}.r{text-align:right;white-space:nowrap}.member{font-weight:700}.total td{font-weight:800;background:#f1f5f9;border-top:2px solid #aebbc9}.pos{color:var(--green);font-weight:700}.neg{color:#a11f18;font-weight:700}
.drill{display:inline-block;padding:6px 9px;border-radius:5px;background:var(--navy2);color:#fff;text-decoration:none;font-size:11px;font-weight:700;white-space:nowrap}.drill:hover{background:var(--blue)}
.detail-panel{margin-top:14px;background:#fff;border:1px solid var(--line);border-radius:9px;overflow:hidden}.detail-head{padding:14px 16px;background:#f8fafc;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.detail-head h2{margin:0;color:var(--navy);font-size:19px}.detail-head p{margin:4px 0 0;color:var(--muted);font-size:12px}.back{display:inline-block;padding:7px 10px;border:1px solid var(--line);border-radius:5px;background:#fff;color:var(--navy);text-decoration:none;font-weight:700;font-size:12px}.subtle{color:var(--muted);font-size:11px}.nowrap{white-space:nowrap}
.note{margin-top:14px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px 14px;color:#526476;font-size:12px;line-height:1.5}
.empty{padding:28px;text-align:center;color:var(--muted)}
.workflow-link{display:inline-block;margin-top:6px;padding:7px 10px;border-radius:6px;background:#fff;color:#061d3f!important;text-decoration:none;font-weight:800;font-size:12px;border:1px solid #d8e1eb}.workflow-link:hover,.workflow-link:focus{background:#eef4fa}
@media(max-width:950px){.grid{grid-template-columns:1fr}.summary{grid-template-columns:1fr 1fr}.meta{text-align:left}}
@media(max-width:600px){.wrap{padding:0 8px 20px}.header .inner{padding:14px}.brand{align-items:flex-start;flex-direction:column}.logo{width:170px}.brand h1{font-size:21px}.summary{grid-template-columns:1fr 1fr}.controls{align-items:stretch}.field{flex:1 1 135px}.field input{width:100%}.btn{width:100%;text-align:center}.quick{width:100%}.quick a{flex:1;text-align:center}.panel{border-radius:7px}th,td{padding:8px 7px;font-size:12px}.member{min-width:150px}}
</style>
</head>
<body>
<header class="header"><div class="inner">
  <div class="brand"><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><div><h1>CDN Activity Report</h1><p>Purchases from and sales to Chemical Distribution Network members</p></div></div>
  <div class="meta">Source: <strong><?=cdn_h(basename($xlsx))?></strong><br>Period: <strong><?=cdn_h(cdn_date($from))?> through <?=cdn_h(cdn_date($to))?></strong><br><a class="workflow-link" href="salesworkflow.php">Back to Sales Workflow</a></div>
</div></header>
<main class="wrap">
<form class="controls" method="get">
  <div class="field"><label>From</label><input type="date" name="from" value="<?=cdn_h($from)?>"></div>
  <div class="field"><label>Through</label><input type="date" name="to" value="<?=cdn_h($to)?>"></div>
  <button class="btn" type="submit">Run Report</button>
  <a class="btn alt" href="cdn.php">All Data</a>
  <div class="quick">
    <a href="<?=cdn_h(cdn_qs($ytdFrom,$maxDate))?>">Current YTD</a>
    <a href="<?=cdn_h(cdn_qs($last12From,$maxDate))?>">Last 12 Months</a>
  </div>
</form>

<section class="summary">
  <div class="sum buy"><div class="v"><?=cdn_num($pTot['lbs'])?> lb</div><div class="k">CDN Purchase Volume</div></div>
  <div class="sum buy"><div class="v"><?=cdn_money($pTot['spend'])?></div><div class="k">CDN Purchase Spend</div></div>
  <div class="sum sell"><div class="v"><?=cdn_money($sTot['sales'])?></div><div class="k">Sales to CDN Members</div></div>
  <div class="sum profit"><div class="v"><?=cdn_money($sTot['profit'])?></div><div class="k">Profit on CDN Sales · <?=number_format($gpPct*100,1)?>% GP</div></div>
</section>

<section class="grid">
  <div class="panel">
    <div class="panel-head"><h2>Purchases from CDN Members</h2><p>Sorted by dollar spend. Volume is pounds received; spend is total purchase cost.</p></div>
    <div class="tw"><table>
      <thead><tr><th>CDN Member</th><th class="r">Volume (lb)</th><th class="r">Dollar Spend</th><th>Drill Down</th></tr></thead>
      <tbody>
      <?php if(!$purchases): ?><tr><td colspan="4" class="empty">No CDN purchases found for this period.</td></tr><?php endif; ?>
      <?php foreach($purchases as $member=>$r): ?>
        <tr><td class="member"><?=cdn_h($member)?></td><td class="r"><?=cdn_num($r['lbs'])?></td><td class="r"><?=cdn_money($r['spend'])?></td><td><a class="drill" href="<?=cdn_h(cdn_url(['detail'=>'purchase-pos','member'=>$member,'po'=>null,'invoice'=>null]))?>">View POs</a></td></tr>
      <?php endforeach; ?>
      <?php if($purchases): ?><tr class="total"><td>Total</td><td class="r"><?=cdn_num($pTot['lbs'])?></td><td class="r"><?=cdn_money($pTot['spend'])?></td><td></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <div class="panel">
    <div class="panel-head"><h2>Sales to CDN Members</h2><p>Sorted by sales dollars. Credits are included so volume, sales and profit are shown net.</p></div>
    <div class="tw"><table>
      <thead><tr><th>CDN Member</th><th class="r">Volume (lb)</th><th class="r">Sales</th><th class="r">Profit</th><th class="r">GP %</th><th>Drill Down</th></tr></thead>
      <tbody>
      <?php if(!$sales): ?><tr><td colspan="6" class="empty">No CDN sales found for this period.</td></tr><?php endif; ?>
      <?php foreach($sales as $member=>$r): $gp=abs($r['sales'])>0.0001?$r['profit']/$r['sales']:0; ?>
        <tr><td class="member"><?=cdn_h($member)?></td><td class="r"><?=cdn_num($r['lbs'])?></td><td class="r"><?=cdn_money($r['sales'])?></td><td class="r <?=$r['profit']<0?'neg':'pos'?>"><?=cdn_money($r['profit'])?></td><td class="r"><?=number_format($gp*100,1)?>%</td><td><a class="drill" href="<?=cdn_h(cdn_url(['detail'=>'sales-invoices','member'=>$member,'po'=>null,'invoice'=>null]))?>">View Invoices</a></td></tr>
      <?php endforeach; ?>
      <?php if($sales): ?><tr class="total"><td>Total</td><td class="r"><?=cdn_num($sTot['lbs'])?></td><td class="r"><?=cdn_money($sTot['sales'])?></td><td class="r"><?=cdn_money($sTot['profit'])?></td><td class="r"><?=number_format($gpPct*100,1)?>%</td><td></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</section>

<?php if($detail==='purchase-pos' && isset($cdnMembers[$selectedMember])): ?>
<section class="detail-panel">
  <div class="detail-head"><div><h2>Purchase Orders · <?=cdn_h($selectedMember)?></h2><p><?=cdn_num(count($purchasePOs))?> PO<?=count($purchasePOs)==1?'':'s'?> in the selected period. Click a PO for receipt/product detail.</p></div><a class="back" href="<?=cdn_h(cdn_url(['detail'=>null,'member'=>null,'po'=>null]))?>">Close Drill-Down</a></div>
  <div class="tw"><table><thead><tr><th>PO #</th><th>Receipt Date(s)</th><th class="r">Products</th><th class="r">Lines</th><th class="r">Volume (lb)</th><th class="r">Dollar Spend</th><th>Detail</th></tr></thead><tbody>
  <?php if(!$purchasePOs): ?><tr><td colspan="7" class="empty">No purchase orders found.</td></tr><?php endif; ?>
  <?php foreach($purchasePOs as $po=>$r): ?>
    <tr><td class="member nowrap"><?=cdn_h($po)?></td><td class="nowrap"><?=cdn_h(cdn_date($r['first_date']))?><?=$r['last_date']!==$r['first_date']?' – '.cdn_h(cdn_date($r['last_date'])):''?></td><td class="r"><?=cdn_num(count($r['products']))?></td><td class="r"><?=cdn_num($r['lines'])?></td><td class="r"><?=cdn_num($r['lbs'])?></td><td class="r"><?=cdn_money($r['spend'])?></td><td><a class="drill" href="<?=cdn_h(cdn_url(['detail'=>'purchase-po','member'=>$selectedMember,'po'=>$po,'invoice'=>null]))?>">View PO</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php elseif($detail==='purchase-po' && isset($cdnMembers[$selectedMember])): ?>
<section class="detail-panel">
  <div class="detail-head"><div><h2>PO <?=cdn_h($selectedPO)?> · <?=cdn_h($selectedMember)?></h2><p>Individual receipt and product lines from the Lowe Master purchase history.</p></div><div><a class="back" href="<?=cdn_h(cdn_url(['detail'=>'purchase-pos','member'=>$selectedMember,'po'=>null]))?>">Back to POs</a> <a class="back" href="<?=cdn_h(cdn_url(['detail'=>null,'member'=>null,'po'=>null]))?>">Close</a></div></div>
  <div class="tw"><table><thead><tr><th>Receipt Date</th><th>PO #</th><th>Release</th><th>Product</th><th>Prod No.</th><th class="r">Qty Rec.</th><th>UOM</th><th class="r">LBs Received</th><th class="r">Cost/LB</th><th class="r">Total Cost</th></tr></thead><tbody>
  <?php $plbs=0;$pcost=0; foreach($purchaseLines as $r): $plbs+=of_num($r['LBs Received']??0);$pcost+=cdn_purchase_total($r); ?>
    <tr><td class="nowrap"><?=cdn_h(cdn_date(of_date($r['Receipt Date']??'')))?></td><td><?=cdn_h(cdn_field($r,['PO Number','PO#'],''))?></td><td><?=cdn_h(cdn_field($r,['Release Number','Rel. No.'],''))?></td><td><?=cdn_h($r['Product Name']??'')?></td><td><?=cdn_h(cdn_field($r,['Product Number','Prod No.'],''))?></td><td class="r"><?=cdn_num(of_num(cdn_field($r,['Qty Received','Qty Rec.'],0)),2)?></td><td><?=cdn_h(cdn_field($r,['Purchasing UOM','UOM'],''))?></td><td class="r"><?=cdn_num(of_num($r['LBs Received']??0))?></td><td class="r"><?=cdn_money(cdn_purchase_cost_lb($r))?></td><td class="r"><?=cdn_money(cdn_purchase_total($r))?></td></tr>
  <?php endforeach; ?>
  <?php if($purchaseLines): ?><tr class="total"><td colspan="7">PO Total</td><td class="r"><?=cdn_num($plbs)?></td><td></td><td class="r"><?=cdn_money($pcost)?></td></tr><?php else: ?><tr><td colspan="10" class="empty">No receipt lines found for this PO.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php elseif($detail==='sales-invoices' && isset($cdnMembers[$selectedMember])): ?>
<section class="detail-panel">
  <div class="detail-head"><div><h2>Invoices · <?=cdn_h($selectedMember)?></h2><p><?=cdn_num(count($salesInvoices))?> invoice<?=count($salesInvoices)==1?'':'s'?> / credit document<?=count($salesInvoices)==1?'':'s'?> in the selected period. Click an invoice for line-level detail.</p></div><a class="back" href="<?=cdn_h(cdn_url(['detail'=>null,'member'=>null,'invoice'=>null]))?>">Close Drill-Down</a></div>
  <div class="tw"><table><thead><tr><th>Invoice #</th><th>Date</th><th>Customer PO</th><th class="r">Products</th><th class="r">Volume (lb)</th><th class="r">Sales</th><th class="r">Profit</th><th class="r">GP %</th><th>Detail</th></tr></thead><tbody>
  <?php if(!$salesInvoices): ?><tr><td colspan="9" class="empty">No invoices found.</td></tr><?php endif; ?>
  <?php foreach($salesInvoices as $inv=>$r): $igp=cdn_gp($r['profit'],$r['sales']); ?>
    <tr><td class="member nowrap"><?=cdn_h($inv)?></td><td class="nowrap"><?=cdn_h(cdn_date($r['date']))?></td><td><?=cdn_h($r['customer_po'])?></td><td class="r"><?=cdn_num(count($r['products']))?></td><td class="r"><?=cdn_num($r['lbs'])?></td><td class="r"><?=cdn_money($r['sales'])?></td><td class="r <?=$r['profit']<0?'neg':'pos'?>"><?=cdn_money($r['profit'])?></td><td class="r"><?=number_format($igp*100,1)?>%</td><td><a class="drill" href="<?=cdn_h(cdn_url(['detail'=>'sales-invoice','member'=>$selectedMember,'invoice'=>$inv,'po'=>null]))?>">View Invoice</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php elseif($detail==='sales-invoice' && isset($cdnMembers[$selectedMember])): ?>
<section class="detail-panel">
  <div class="detail-head"><div><h2>Invoice <?=cdn_h($selectedInvoice)?> · <?=cdn_h($selectedMember)?></h2><p>Individual invoice/product lines. Credits are shown with their signed values from the master file.</p></div><div><a class="back" href="<?=cdn_h(cdn_url(['detail'=>'sales-invoices','member'=>$selectedMember,'invoice'=>null]))?>">Back to Invoices</a> <a class="back" href="<?=cdn_h(cdn_url(['detail'=>null,'member'=>null,'invoice'=>null]))?>">Close</a></div></div>
  <div class="tw"><table><thead><tr><th>Date</th><th>Type</th><th>Invoice #</th><th>Customer PO</th><th>Product</th><th>Product Code</th><th class="r">Qty</th><th>UOM</th><th class="r">LBs</th><th class="r">Price/LB</th><th class="r">Sales</th><th class="r">Cost/LB</th><th class="r">Profit</th><th class="r">GP %</th><th>BOL #</th></tr></thead><tbody>
  <?php $ilbs=0;$isales=0;$iprofit=0; foreach($salesLines as $r): $ilbs+=of_num($r['LBS']??0);$isales+=of_num($r['Sales $$']??0);$iprofit+=of_num($r['Profit $$']??0); ?>
    <tr><td class="nowrap"><?=cdn_h(cdn_date(of_date($r['INV. Date']??'')))?></td><td><?=cdn_h($r['Doc Type']??'')?></td><td><?=cdn_h($r['INV#']??'')?></td><td><?=cdn_h($r['Cust PO#']??'')?></td><td><?=cdn_h($r['Product Name']??'')?></td><td><?=cdn_h($r['Product Number']??'')?></td><td class="r"><?=cdn_num(of_num($r['QTY']??0),2)?></td><td><?=cdn_h($r['UOM']??'')?></td><td class="r"><?=cdn_num(of_num($r['LBS']??0))?></td><td class="r"><?=cdn_money(of_num($r['Price/LB']??0))?></td><td class="r"><?=cdn_money(of_num($r['Sales $$']??0))?></td><td class="r"><?=cdn_money(of_num($r['Cost/lb']??0))?></td><td class="r <?=of_num($r['Profit $$']??0)<0?'neg':'pos'?>"><?=cdn_money(of_num($r['Profit $$']??0))?></td><td class="r"><?=number_format(of_num($r['GP %']??0)*100,1)?>%</td><td><?=cdn_h($r['BOL#']??'')?></td></tr>
  <?php endforeach; ?>
  <?php if($salesLines): ?><tr class="total"><td colspan="8">Invoice Total</td><td class="r"><?=cdn_num($ilbs)?></td><td></td><td class="r"><?=cdn_money($isales)?></td><td></td><td class="r"><?=cdn_money($iprofit)?></td><td class="r"><?=number_format(cdn_gp($iprofit,$isales)*100,1)?>%</td><td></td></tr><?php else: ?><tr><td colspan="15" class="empty">No invoice lines found for this invoice.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<div class="note"><strong>Matching note:</strong> Lowe supplier/customer names are normalized to the current CDN member roster using exact aliases. ChemGroup activity includes Lowe records booked to known ChemGroup companies such as Bonded Chemicals, Chemicals Incorporated, and Chemical Resources. Transactions booked directly to <em>Chemical Distribution Network</em> are shown separately as <strong>CDN Network</strong>. The page intentionally does not use broad fuzzy matching, which prevents unrelated companies with similar names from being counted as CDN activity.</div>
</main>
</body>
</html>
