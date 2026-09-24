<?php
/**
 * Lowe Chemical - Basic YTD Dashboard
 * Compares current YTD to prior-year-to-date using the latest Lowe Master workbook.
 */
declare(strict_types=1);

require_once __DIR__ . '/predictive-order-engine.php';

function yd_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function yd_num($v, int $d=0): string { return number_format((float)$v,$d); }
function yd_money($v): string { return '$'.number_format((float)$v,0); }
function yd_date($v): string { if(!$v) return '-'; $t=strtotime((string)$v); return $t?date('M j, Y',$t):'-'; }
function yd_pct_change(float $cur,float $prev): ?float {
    if (abs($prev) < 0.000001) return abs($cur) < 0.000001 ? 0.0 : null;
    return ($cur-$prev)/abs($prev);
}
function yd_var_class(float $v): string { return $v>0.000001?'pos':($v<-0.000001?'neg':'neutral'); }

function yd_find_workbook(): ?string {
    $candidates = [
        __DIR__ . '/predictive-order-files/Lowe-Master-Latest.xlsx',
        __DIR__ . '/order-forecast-files/Lowe-Master-Latest.xlsx',
        __DIR__ . '/Lowe Master.xlsx',
        __DIR__ . '/Lowe Master(1).xlsx',
        __DIR__ . '/Lowe-Master-Latest.xlsx',
    ];
    foreach($candidates as $p) if(is_file($p)) return $p;
    return null;
}

$workbook = yd_find_workbook();
if(!$workbook){
    http_response_code(500);
    die('Lowe Master workbook not found. Upload the latest workbook through predictive-orders-admin.php first.');
}

try {
    @set_time_limit(300);
    $invoiceRows = of_assoc(of_read_sheet($workbook,'Invoices'));
    of_require($invoiceRows,['INV. Date','Doc Type','Cust Name','Cust#','Product Name','LBS','Sales $$','Profit $$','REP'],'Invoices');
} catch(Throwable $e){
    http_response_code(500);
    die('Could not read Lowe Master workbook: '.yd_h($e->getMessage()));
}

$latest = null;
$reps = [];
foreach($invoiceRows as $r){
    $type = strtolower(trim((string)($r['Doc Type']??'')));
    if(!in_array($type,['invoiced','credit'],true)) continue;
    $d = of_date($r['INV. Date']??'');
    if(!$d) continue;
    if($latest===null || $d>$latest) $latest=$d;
    $rep=trim((string)($r['REP']??''));
    if($rep!=='') $reps[$rep]=true;
}
if(!$latest) die('No valid invoice history was found.');
$reps=array_keys($reps); sort($reps,SORT_NATURAL|SORT_FLAG_CASE);

$repFilter=trim((string)($_GET['rep']??''));
if($repFilter!=='' && !in_array($repFilter,$reps,true)) $repFilter='';

$cutoff = new DateTimeImmutable($latest);
$currentYear=(int)$cutoff->format('Y');
$priorYear=$currentYear-1;
$ytdStart=sprintf('%04d-01-01',$currentYear);
$ytdEnd=$cutoff->format('Y-m-d');
$pyCutoff=$cutoff->modify('-1 year');
$pytdStart=sprintf('%04d-01-01',$priorYear);
$pytdEnd=$pyCutoff->format('Y-m-d');

$periods = [
    'ytd'=>['pounds'=>0.0,'sales'=>0.0,'profit'=>0.0,'customers'=>[],'products'=>[],'rows'=>0],
    'pytd'=>['pounds'=>0.0,'sales'=>0.0,'profit'=>0.0,'customers'=>[],'products'=>[],'rows'=>0],
];

foreach($invoiceRows as $r){
    $type=strtolower(trim((string)($r['Doc Type']??'')));
    if(!in_array($type,['invoiced','credit'],true)) continue;
    if($repFilter!=='' && trim((string)($r['REP']??''))!==$repFilter) continue;
    $date=of_date($r['INV. Date']??'');
    if(!$date) continue;
    $period=null;
    if($date>=$ytdStart && $date<=$ytdEnd) $period='ytd';
    elseif($date>=$pytdStart && $date<=$pytdEnd) $period='pytd';
    if(!$period) continue;

    $lbs=of_num($r['LBS']??0);
    $sales=of_num($r['Sales $$']??0);
    $profit=of_num($r['Profit $$']??0);
    $cust=trim((string)($r['Cust Name']??''));
    $custNo=trim((string)($r['Cust#']??''));
    $prod=trim((string)($r['Product Name']??''));

    $periods[$period]['pounds'] += $lbs;
    $periods[$period]['sales'] += $sales;
    $periods[$period]['profit'] += $profit;
    $periods[$period]['rows']++;
    if($cust!=='') $periods[$period]['customers'][strtoupper($custNo!==''?$custNo:$cust)]=true;
    if($prod!=='') $periods[$period]['products'][strtoupper(preg_replace('/\s+/',' ',$prod))]=true;
}

foreach(['ytd','pytd'] as $p){
    $periods[$p]['customer_count']=count($periods[$p]['customers']);
    $periods[$p]['product_count']=count($periods[$p]['products']);
    $periods[$p]['gp_pct']=abs($periods[$p]['sales'])>0.000001?$periods[$p]['profit']/$periods[$p]['sales']:0.0;
}

$metrics = [
    ['key'=>'pounds','label'=>'Pounds','format'=>'num'],
    ['key'=>'sales','label'=>'Sales','format'=>'money'],
    ['key'=>'profit','label'=>'Profit','format'=>'money'],
    ['key'=>'customer_count','label'=>'Customers','format'=>'num'],
    ['key'=>'product_count','label'=>'Unique Products','format'=>'num'],
];

function yd_fmt($v,string $format): string { return $format==='money'?yd_money($v):yd_num($v); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>YTD Dashboard | Lowe Chemical</title>
<style>
:root{--navy:#061d3f;--red:#c8102e;--blue:#1d5e91;--green:#15803d;--orange:#d97706;--bg:#eef2f6;--card:#fff;--line:#d5dee8;--text:#1f2d3d;--muted:#68788b;--head:#e9eef4}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;font-size:14px}a{color:var(--blue)}
.header{background:var(--navy);color:#fff;border-bottom:4px solid var(--red)}.header .in{max-width:1500px;margin:auto;padding:15px 22px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:15px}.logo{width:175px;max-height:58px;object-fit:contain;background:#fff;border-radius:6px;padding:7px 10px}.brand h1{margin:0;font-size:23px}.brand p{margin:4px 0 0;color:#cbd8e8;font-size:12px}.meta{text-align:right;font-size:12px;line-height:1.55;color:#dbe6f2}.workflow{display:inline-block;margin-top:6px;padding:7px 10px;border-radius:6px;background:#fff;color:var(--navy)!important;text-decoration:none;font-weight:800;border:1px solid #d8e1eb}
.wrap{max-width:1500px;margin:18px auto;padding:0 16px 28px}.controls{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px 14px;margin-bottom:14px;display:flex;align-items:end;gap:10px;flex-wrap:wrap}.field label{display:block;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:4px}.field select{min-width:220px;padding:9px 10px;border:1px solid #b9c5d1;border-radius:6px;background:#fff}.btn{display:inline-block;border:0;border-radius:6px;background:var(--red);color:#fff;padding:9px 14px;font-weight:700;text-decoration:none;cursor:pointer}.btn.alt{background:#56667a}
.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px}.card{background:#fff;border:1px solid var(--line);border-radius:9px;padding:14px 15px;border-left:5px solid var(--blue)}.card:nth-child(2){border-left-color:var(--green)}.card:nth-child(3){border-left-color:var(--orange)}.card:nth-child(4){border-left-color:#6d28d9}.card:nth-child(5){border-left-color:var(--red)}.card .label{font-size:10.5px;text-transform:uppercase;color:var(--muted);font-weight:700;letter-spacing:.03em}.card .value{font-size:24px;font-weight:800;color:var(--navy);margin:4px 0}.card .prior{font-size:11.5px;color:var(--muted)}.change{margin-top:7px;font-weight:800;font-size:13px}.pos{color:var(--green)}.neg{color:#a61b1b}.neutral{color:#68788b}
.panel{background:#fff;border:1px solid var(--line);border-radius:9px;overflow:hidden}.panel-head{padding:13px 15px;background:#f8fafc;border-bottom:1px solid var(--line)}.panel-head h2{margin:0;color:var(--navy);font-size:18px}.panel-head p{margin:4px 0 0;color:var(--muted);font-size:12px}.tw{overflow:auto}table{border-collapse:collapse;width:100%;min-width:720px}th,td{padding:10px 12px;border-right:1px solid #dce3ea;border-bottom:1px solid #dce3ea}th:last-child,td:last-child{border-right:0}th{background:var(--head);color:var(--navy);font-size:10.5px;text-transform:uppercase;text-align:right;white-space:nowrap}th:first-child,td:first-child{text-align:left}td{text-align:right;font-size:13px}.metric{font-weight:800;color:var(--navy)}.foot{margin-top:10px;color:var(--muted);font-size:11px;line-height:1.45}
@media(max-width:1000px){.cards{grid-template-columns:repeat(2,1fr)}.meta{text-align:left}}
@media(max-width:650px){.header .in{display:block;padding:13px}.brand{align-items:flex-start}.logo{width:145px}.brand h1{font-size:20px}.meta{margin-top:11px}.wrap{padding:0 8px;margin-top:10px}.cards{grid-template-columns:1fr}.controls{display:block}.field select,.btn{width:100%}.btn{margin-top:8px;text-align:center}.panel{border-radius:7px}}
</style>
</head>
<body>
<header class="header"><div class="in">
  <div class="brand"><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><div><h1>YTD Performance Dashboard</h1><p>Current year-to-date performance compared with the same period last year</p></div></div>
  <div class="meta">YTD through: <strong><?=yd_h(yd_date($ytdEnd))?></strong><br>PYTD through: <strong><?=yd_h(yd_date($pytdEnd))?></strong><br><a class="workflow" href="salesworkflow.php">Back to Sales Workflow</a></div>
</div></header>
<main class="wrap">
  <form class="controls" method="get">
    <div class="field"><label>Sales Rep</label><select name="rep"><option value="">All Sales Reps</option><?php foreach($reps as $r): ?><option value="<?=yd_h($r)?>" <?=$repFilter===$r?'selected':''?>><?=yd_h($r)?></option><?php endforeach; ?></select></div>
    <button class="btn" type="submit">Run</button>
    <a class="btn alt" href="ytd-dashboard.php">Reset</a>
  </form>

  <section class="cards">
  <?php foreach($metrics as $m):
      $cur=(float)$periods['ytd'][$m['key']]; $prev=(float)$periods['pytd'][$m['key']]; $var=$cur-$prev; $pct=yd_pct_change($cur,$prev);
  ?>
    <div class="card">
      <div class="label"><?=yd_h($m['label'])?></div>
      <div class="value"><?=yd_h(yd_fmt($cur,$m['format']))?></div>
      <div class="prior"><?=$priorYear?> PYTD: <?=yd_h(yd_fmt($prev,$m['format']))?></div>
      <div class="change <?=yd_var_class($var)?>"><?php if($var>0): ?>+<?php endif; ?><?=yd_h(yd_fmt($var,$m['format']))?><?php if($pct!==null): ?> &nbsp; (<?=$pct>0?'+':''?><?=number_format($pct*100,1)?>%)<?php else: ?> &nbsp; (New)<?php endif; ?></div>
    </div>
  <?php endforeach; ?>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>YTD vs Previous Year-to-Date</h2><p><?=yd_h($repFilter!==''?'Sales Rep: '.$repFilter:'All Sales Reps')?>. Customers are counted by customer number/name. Unique products are counted by Product Description, regardless of product code.</p></div>
    <div class="tw"><table>
      <thead><tr><th>Metric</th><th><?=$currentYear?> YTD</th><th><?=$priorYear?> PYTD</th><th>Variance</th><th>Variance %</th></tr></thead>
      <tbody>
      <?php foreach($metrics as $m): $cur=(float)$periods['ytd'][$m['key']];$prev=(float)$periods['pytd'][$m['key']];$var=$cur-$prev;$pct=yd_pct_change($cur,$prev); ?>
        <tr><td class="metric"><?=yd_h($m['label'])?></td><td><?=yd_h(yd_fmt($cur,$m['format']))?></td><td><?=yd_h(yd_fmt($prev,$m['format']))?></td><td class="<?=yd_var_class($var)?>"><?=$var>0?'+':''?><?=yd_h(yd_fmt($var,$m['format']))?></td><td class="<?=yd_var_class($var)?>"><?=$pct===null?'New':(($pct>0?'+':'').number_format($pct*100,1).'%')?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <div class="foot">Source: <?=yd_h(basename($workbook))?>. Includes both invoiced and credit rows so pounds, sales and profit are net activity. Current YTD is <?=yd_h(yd_date($ytdStart))?> through <?=yd_h(yd_date($ytdEnd))?>; PYTD uses the same calendar cutoff in <?=$priorYear?>.</div>
</main>
</body></html>
