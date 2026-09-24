<?php
/*
  Lowe Chemical Order Forecast (customer order timing and typical amounts)
  Files needed in the same folder: predictive-orders.php, predictive-order-engine.php,
  predictive-orders-admin.php and predictive-order-data.json (created by the update page).
  No database or external PHP library is required.
*/
$dataFile = __DIR__ . '/predictive-order-data.json';
if (!file_exists($dataFile)) { http_response_code(500); die('Forecast data file not found. Open predictive-orders-admin.php and upload the Lowe Master workbook.'); }
$payload = json_decode(file_get_contents($dataFile), true);
if (!$payload || !isset($payload['rows'])) { http_response_code(500); die('Forecast data file could not be read.'); }
$rows = $payload['rows'];
$asOf = $payload['generated_as_of'] ?? date('Y-m-d');
$latest = $payload['latest_invoice_date'] ?? '';
$bt = $payload['backtest'] ?? [];

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function num($v,$d=0){ return number_format((float)$v,$d); }
function fdate($v){ if(!$v) return '-'; $t=strtotime($v); return $t?date('M j, Y',$t):'-'; }
function fshort($v){ if(!$v) return '-'; $t=strtotime($v); return $t?date('M j',$t):'-'; }
function contains_ci($h,$n){ if($n==='') return true; return function_exists('mb_stripos') ? mb_stripos((string)$h,(string)$n)!==false : stripos((string)$h,(string)$n)!==false; }
function status_class($s){
    return ['Due now'=>'s-now','Overdue'=>'s-over','Due in 8-30 days'=>'s-30','Due in 31-60 days'=>'s-60','Later'=>'s-later','Order in house'=>'s-house','Gone quiet'=>'s-quiet','One-time buyer'=>'s-one','Inactive'=>'s-one'][$s] ?? 's-one';
}
function qs(array $over=[]){ $q=array_merge($_GET,$over); foreach($q as $k=>$v){ if($v===''||$v===null) unset($q[$k]); } return '?'.http_build_query($q); }

// ---------- inputs ----------
$tab   = in_array($_GET['tab'] ?? 'orders',['orders','products','customers'],true) ? ($_GET['tab'] ?? 'orders') : 'orders';
$q     = trim($_GET['q'] ?? '');
$view  = $_GET['view'] ?? 'watch';
$rep   = trim($_GET['rep'] ?? '');
$pat   = $_GET['pattern'] ?? 'all';
$minp  = (int)($_GET['minp'] ?? 0);
$sort  = $_GET['sort'] ?? 'date';
$page  = max(1,(int)($_GET['page'] ?? 1));
$per   = 100;

$reps=[]; foreach($rows as $r){ if(!empty($r['rep'])) $reps[$r['rep']]=true; } $reps=array_keys($reps); sort($reps,SORT_NATURAL|SORT_FLAG_CASE);

$watchStatuses = ['Due now','Overdue','Due in 8-30 days'];
$viewMap = [
  'watch'=>['Watch list: due now, overdue, next 30 days',$watchStatuses],
  'now'=>['Due in the next 7 days',['Due now']],
  'd30'=>['Due in 8 to 30 days',['Due in 8-30 days']],
  'd60'=>['Due in 31 to 60 days',['Due in 31-60 days']],
  'over'=>['Overdue: past every previous gap',['Overdue']],
  'house'=>['Order already in house',['Order in house']],
  'quiet'=>['Gone quiet (check in)',['Gone quiet']],
  'one'=>['One-time buyers and inactive',['One-time buyer','Inactive']],
  'later'=>['Later than 60 days',['Later']],
  'all'=>['All customer/product pairs',null],
];
if(!isset($viewMap[$view])) $view='watch';

function passes($r,$q,$rep,$pat,$minp){
    if($q!=='' && !contains_ci($r['customer'],$q) && !contains_ci($r['product'],$q) && !contains_ci($r['product_code'],$q) && !contains_ci($r['customer_code'],$q)) return false;
    if($rep!=='' && $r['rep']!==$rep) return false;
    if($pat!=='all' && $r['pattern']!==$pat) return false;
    if($minp>0 && ($r['p30']===null || $r['p30']*100 < $minp)) return false;
    return true;
}
$base = array_values(array_filter($rows, fn($r)=>passes($r,$q,$rep,$pat,$minp)));

// KPI cards use the whole file, not the filters
$kpi=[]; foreach(['Due now','Due in 8-30 days','Due in 31-60 days','Overdue','Order in house','Gone quiet'] as $s){ $kpi[$s]=['n'=>0,'lbs'=>0.0]; }
foreach($rows as $r){ if(isset($kpi[$r['status']])){ $kpi[$r['status']]['n']++; $kpi[$r['status']]['lbs'] += ($r['status']==='Order in house' ? $r['open_so_lbs'] : $r['typical_qty_lbs']); } }

// Executive forecast totals
$forecast30=0.0; $forecast60=0.0; $openTotal=0.0; $highConf30=0;
foreach($rows as $r){
    if(in_array($r['status'], ['Due now','Overdue','Due in 8-30 days','Due in 31-60 days','Later'], true)){
        if($r['p30']!==null) $forecast30 += $r['p30'] * $r['typical_qty_lbs'];
        if($r['p60']!==null) $forecast60 += $r['p60'] * $r['typical_qty_lbs'];
        if(($r['p30']??0) >= 0.70) $highConf30++;
    }
    $openTotal += (float)($r['open_so_lbs']??0);
}

// ---------- product demand outlook (probability weighted) ----------
$live = ['Due now','Overdue','Due in 8-30 days','Due in 31-60 days','Later'];
$prod=[]; $cust=[];
foreach($base as $r){
    if(!in_array($r['status'],$live,true)) { if($r['status']==='Order in house'){ $k=$r['product_code']; $prod[$k]['open']=($prod[$k]['open']??0)+$r['open_so_lbs']; $prod[$k]['name']=$r['product']; } continue; }
    $k=$r['product_code'];
    $prod[$k]['name']=$r['product'];
    $prod[$k]['c30']=($prod[$k]['c30']??0)+($r['p30']>=0.5?1:0);
    $prod[$k]['e30']=($prod[$k]['e30']??0)+$r['p30']*$r['typical_qty_lbs'];
    $prod[$k]['e60']=($prod[$k]['e60']??0)+$r['p60']*$r['typical_qty_lbs'];
    $prod[$k]['n']=($prod[$k]['n']??0)+1;
    if(!isset($prod[$k]['top']) || $r['p30']*$r['typical_qty_lbs'] > $prod[$k]['top'][1]) $prod[$k]['top']=[$r['customer'],$r['p30']*$r['typical_qty_lbs']];
    $ck=$r['customer_code'];
    $cust[$ck]['name']=$r['customer']; $cust[$ck]['rep']=$r['rep'];
    $cust[$ck]['e30']=($cust[$ck]['e30']??0)+$r['p30']*$r['typical_qty_lbs'];
    $cust[$ck]['n30']=($cust[$ck]['n30']??0)+($r['p30']>=0.4?1:0);
    if($r['p30']>=0.4) $cust[$ck]['items'][]=[$r['product'],$r['typical_qty_lbs'],$r['p30'],$r['expected_date']];
}
uasort($prod,fn($a,$b)=>($b['e30']??0)<=>($a['e30']??0));
uasort($cust,fn($a,$b)=>($b['e30']??0)<=>($a['e30']??0));

// ---------- orders tab ----------
$list = $base;
if($viewMap[$view][1]!==null){ $allow=$viewMap[$view][1]; $list=array_values(array_filter($list,fn($r)=>in_array($r['status'],$allow,true))); }
usort($list,function($a,$b) use($sort){
    if($sort==='likely'){ $c=($b['p30']??-1)<=>($a['p30']??-1); if($c) return $c; }
    if($sort==='qty'){ $c=$b['typical_qty_lbs']<=>$a['typical_qty_lbs']; if($c) return $c; }
    if($sort==='customer'){ $c=strcasecmp($a['customer'],$b['customer']); if($c) return $c; }
    $ad=$a['expected_date']; $bd=$b['expected_date'];
    if($ad===$bd) return ($b['p30']??-1)<=>($a['p30']??-1);
    if($ad===null) return ($sort==='date'?-1:1); if($bd===null) return ($sort==='date'?1:-1);
    return strcmp($ad,$bd);
});

$total=count($list); $pages=max(1,(int)ceil($total/$per)); $page=min($page,$pages);
$slice=array_slice($list,($page-1)*$per,$per);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Predictive Orders | Lowe Chemical</title>
<style>
:root{--navy:#061d3f;--navy2:#0c2c5a;--red:#b3202a;--bg:#f3f5f8;--card:#fff;--line:#dbe2ea;--text:#1f2d3d;--muted:#66768a;--green:#15803d;--orange:#b45309;--blue:#1d5e91;--purple:#6d4bb5}
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:var(--text);font-size:14px}
a{color:var(--blue)}
.header{background:var(--navy);color:#fff;border-bottom:4px solid var(--red)}
.header .in{max-width:1500px;margin:auto;padding:16px 22px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:14px}.brand-logo{height:54px;width:auto;max-width:180px;display:block;object-fit:contain}.mark{width:44px;height:44px;border-radius:6px;background:var(--red);display:grid;place-items:center;font-weight:700;font-size:20px;letter-spacing:.5px}
.brand h1{margin:0;font-size:22px;letter-spacing:.2px}.brand p{margin:3px 0 0;color:#c9d6e6;font-size:12.5px}
.meta{text-align:right;font-size:12px;line-height:1.6;color:#d7e2ef}.meta a{color:#fff}
.wrap{max-width:1500px;margin:20px auto;padding:0 18px}
.tabs{display:flex;gap:4px;margin-bottom:14px;flex-wrap:wrap}.tabs a{padding:10px 16px;background:#e6ebf1;color:var(--navy);text-decoration:none;font-weight:700;border-radius:8px 8px 0 0;font-size:13px}.tabs a.on{background:var(--navy);color:#fff}
.cards{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px}
.exec{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:-2px 0 16px}.exec .x{background:#fff;border:1px solid var(--line);border-radius:8px;padding:11px 14px}.exec .v{font-size:20px;font-weight:800;color:var(--navy)}.exec .k{font-size:11px;text-transform:uppercase;color:var(--muted);margin-top:3px;letter-spacing:.03em}
.card{background:var(--card);border:1px solid var(--line);border-left:5px solid var(--line);border-radius:8px;padding:12px 14px;text-decoration:none;color:inherit;display:block}
.card .n{font-size:26px;font-weight:700;color:var(--navy)}.card .l{font-size:12px;color:var(--muted);margin-top:2px}.card .s{font-size:12px;margin-top:4px;color:var(--text)}
.card.k-now{border-left-color:var(--red)}.card.k-now .n{color:var(--red)}.card.k-30{border-left-color:#d97706}.card.k-60{border-left-color:var(--blue)}.card.k-over{border-left-color:#7f1d1d}.card.k-house{border-left-color:var(--green)}.card.k-quiet{border-left-color:var(--purple)}
.panel{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:14px;margin-bottom:14px}
.filters{display:grid;grid-template-columns:minmax(240px,2fr) 1.6fr 1fr 1fr 1fr 1fr auto;gap:10px;align-items:end}
.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.field input,.field select{width:100%;padding:9px 10px;border:1px solid #b9c5d1;border-radius:6px;background:#fff;font-size:13.5px}
.btn{padding:9px 15px;border:0;border-radius:6px;background:var(--red);color:#fff;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;font-size:13.5px}.btn.alt{background:#56667a}.btn.green{background:#15803d}
.info{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px;flex-wrap:wrap}.info .t{font-weight:700;color:var(--navy);font-size:15px}.info .s{font-size:12px;color:var(--muted)}
.tw{overflow:auto;border:1px solid var(--line);border-radius:8px}
table{border-collapse:collapse;width:100%;min-width:1180px;background:#fff}
th{position:sticky;top:0;background:#e9eef4;color:var(--navy);text-align:left;padding:9px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.03em;border-bottom:2px solid #c7d2de;white-space:nowrap}
th a{color:var(--navy);text-decoration:none}
td{padding:9px 8px;border-bottom:1px solid #edf0f4;vertical-align:top;font-size:13px}
tr:hover td{background:#f8fafc}.r{text-align:right;white-space:nowrap}.nw{white-space:nowrap}.code{font-family:Consolas,monospace}.sm{font-size:11.5px;color:var(--muted)}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.s-now{background:#fde2e1;color:#8f1d19}.s-over{background:#f6c9c6;color:#6b1410}.s-30{background:#ffedd0;color:#8a4b00}.s-60{background:#e1edfb;color:#164f84}.s-later{background:#edf1f5;color:#586573}.s-house{background:#dff3e6;color:#14663a}.s-quiet{background:#ece5f8;color:#553a9b}.s-one{background:#edf1f5;color:#586573}
.lk{display:flex;align-items:center;gap:6px;white-space:nowrap}.bar{width:64px;height:8px;background:#e5eaf0;border-radius:4px;overflow:hidden}.bar i{display:block;height:100%}.hi i{background:#15803d}.md i{background:#d97706}.lo i{background:#94a3b8}
.qty{font-weight:700;font-size:14px}.up{color:var(--green);font-weight:700}.down{color:#a22b24;font-weight:700}
.pager{display:flex;gap:6px;align-items:center;justify-content:center;margin-top:12px;flex-wrap:wrap}.pager a,.pager span{padding:6px 11px;border:1px solid var(--line);border-radius:6px;text-decoration:none;color:var(--navy);background:#fff;font-size:13px}.pager span.on{background:var(--navy);color:#fff}
details.how{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px 16px;margin-bottom:14px}details.how summary{cursor:pointer;font-weight:700;color:var(--navy)}details.how ul{line-height:1.6;margin:10px 0 6px;padding-left:20px}
.cal{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-top:8px}.cal div{background:#f3f6fa;border-radius:6px;padding:8px;text-align:center;font-size:12px}.cal b{display:block;font-size:13px;color:var(--navy)}
.empty{padding:34px;text-align:center;color:var(--muted)}
.items{font-size:12.5px;line-height:1.5}
@media(max-width:1000px){.cards{grid-template-columns:repeat(2,1fr)}.exec{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:1fr}.meta{text-align:left}.cal{grid-template-columns:repeat(2,1fr)}}
@media print{.filters,.tabs,.btn,.pager,.header a{display:none}.tw{overflow:visible}body{background:#fff}}
</style></head><body>
<div class="header"><div class="in">
 <div class="brand"><img class="brand-logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><div><h1>Predictive Customer Orders</h1><p>Lowe Chemical Company &middot; Who is likely to order, when, and how much</p></div></div>
 <div class="meta"><a href="/salesworkflow.php"><strong>&larr; Back to Sales Workflow</strong></a><br>Forecast date: <strong><?=esc(fdate($asOf))?></strong><br>Invoice history through: <strong><?=esc(fdate($latest))?></strong><br><a href="predictive-orders-admin.php">Update from Excel</a></div>
</div></div>
<div class="wrap">
<div class="cards">
 <a class="card k-now" href="<?=esc(qs(['tab'=>'orders','view'=>'now','page'=>null]))?>"><div class="n"><?=num($kpi['Due now']['n'])?></div><div class="l">Due in the next 7 days</div><div class="s"><?=num($kpi['Due now']['lbs'])?> lb typical</div></a>
 <a class="card k-30" href="<?=esc(qs(['tab'=>'orders','view'=>'d30','page'=>null]))?>"><div class="n"><?=num($kpi['Due in 8-30 days']['n'])?></div><div class="l">Due in 8 to 30 days</div><div class="s"><?=num($kpi['Due in 8-30 days']['lbs'])?> lb typical</div></a>
 <a class="card k-60" href="<?=esc(qs(['tab'=>'orders','view'=>'d60','page'=>null]))?>"><div class="n"><?=num($kpi['Due in 31-60 days']['n'])?></div><div class="l">Due in 31 to 60 days</div><div class="s"><?=num($kpi['Due in 31-60 days']['lbs'])?> lb typical</div></a>
 <a class="card k-over" href="<?=esc(qs(['tab'=>'orders','view'=>'over','page'=>null]))?>"><div class="n"><?=num($kpi['Overdue']['n'])?></div><div class="l">Overdue, follow up</div><div class="s"><?=num($kpi['Overdue']['lbs'])?> lb typical</div></a>
 <a class="card k-house" href="<?=esc(qs(['tab'=>'orders','view'=>'house','page'=>null]))?>"><div class="n"><?=num($kpi['Order in house']['n'])?></div><div class="l">Order already in house</div><div class="s"><?=num($kpi['Order in house']['lbs'])?> lb open</div></a>
 <a class="card k-quiet" href="<?=esc(qs(['tab'=>'orders','view'=>'quiet','page'=>null]))?>"><div class="n"><?=num($kpi['Gone quiet']['n'])?></div><div class="l">Gone quiet, check in</div><div class="s"><?=num($kpi['Gone quiet']['lbs'])?> lb typical</div></a>
</div>
<div class="exec">
 <div class="x"><div class="v"><?=num($forecast30)?> lb</div><div class="k">Probability-weighted demand · next 30 days</div></div>
 <div class="x"><div class="v"><?=num($forecast60)?> lb</div><div class="k">Probability-weighted demand · next 60 days</div></div>
 <div class="x"><div class="v"><?=num($openTotal)?> lb</div><div class="k">Open sales orders currently in house</div></div>
 <div class="x"><div class="v"><?=num($highConf30)?></div><div class="k">Customer/product pairs ≥70% likely in 30 days</div></div>
</div>
<div class="tabs">
 <a class="<?=$tab==='orders'?'on':''?>" href="<?=esc(qs(['tab'=>'orders','page'=>null]))?>">Customer order forecast</a>
 <a class="<?=$tab==='products'?'on':''?>" href="<?=esc(qs(['tab'=>'products','page'=>null]))?>">Product demand outlook</a>
 <a class="<?=$tab==='customers'?'on':''?>" href="<?=esc(qs(['tab'=>'customers','page'=>null]))?>">Customer call list</a>
</div>
<div class="panel"><form method="get" class="filters">
 <input type="hidden" name="tab" value="<?=esc($tab)?>">
 <div class="field"><label>Customer / product / code</label><input type="text" name="q" value="<?=esc($q)?>" placeholder="e.g. Buckman, citric, 005601"></div>
 <?php if($tab==='orders'): ?><div class="field"><label>View</label><select name="view"><?php foreach($viewMap as $k=>$v):?><option value="<?=esc($k)?>" <?=$view===$k?'selected':''?>><?=esc($v[0])?></option><?php endforeach;?></select></div><?php else: ?><div></div><?php endif; ?>
 <div class="field"><label>Sales rep</label><select name="rep"><option value="">All reps</option><?php foreach($reps as $r):?><option value="<?=esc($r)?>" <?=$rep===$r?'selected':''?>><?=esc($r)?></option><?php endforeach;?></select></div>
 <div class="field"><label>Buying pattern</label><select name="pattern"><option value="all">All</option><?php foreach(['Regular','Somewhat regular','Irregular'] as $c):?><option <?=$pat===$c?'selected':''?>><?=esc($c)?></option><?php endforeach;?></select></div>
 <div class="field"><label>Min. likelihood (30 days)</label><select name="minp"><?php foreach([0=>'Any',25=>'25% or more',50=>'50% or more',75=>'75% or more'] as $k=>$v):?><option value="<?=$k?>" <?=$minp===$k?'selected':''?>><?=esc($v)?></option><?php endforeach;?></select></div>
 <?php if($tab==='orders'): ?><div class="field"><label>Sort by</label><select name="sort"><?php foreach(['date'=>'Expected date','likely'=>'Likelihood','qty'=>'Typical amount','customer'=>'Customer'] as $k=>$v):?><option value="<?=$k?>" <?=$sort===$k?'selected':''?>><?=esc($v)?></option><?php endforeach;?></select></div><?php else: ?><div></div><?php endif; ?>
 <div><button class="btn" type="submit">Run</button> <a class="btn alt" href="<?=esc(basename($_SERVER['PHP_SELF']).'?tab='.$tab)?>">Reset</a></div>
</form></div>

<?php if($tab==='orders'): ?>
<div class="panel">
 <div class="info"><div><div class="t"><?=num($total)?> customer/product pairs &middot; <?=esc($viewMap[$view][0])?></div><div class="s">Amounts are the median of each customer's last 6 orders. Likelihood is the chance of an order within the next 30 days.</div></div>
 <div><button class="btn green" type="button" id="excelExport">Download Excel</button></div></div>
 <div class="tw"><table>
 <thead><tr><th>Status</th><th>Expected order</th><th>Customer</th><th>Product</th><th>Product code</th><th class="r">Typical amount (lb)</th><th>Likelihood, 30 days</th><th class="r">Usual gap</th><th>Last order</th><th>Pattern</th><th>Rep</th></tr></thead><tbody>
 <?php if(!$slice): ?><tr><td colspan="11" class="empty">No customer/product pairs match these filters.</td></tr><?php endif; ?>
 <?php foreach($slice as $r): $p=$r['p30']; $cls=$p===null?'':($p>=0.6?'hi':($p>=0.35?'md':'lo')); ?>
 <tr>
  <td><span class="badge <?=status_class($r['status'])?>"><?=esc($r['status'])?></span><?php if($r['status']==='Order in house'): ?><br><span class="sm"><?=num($r['open_so_lbs'])?> lb open<?=$r['open_so_ship']?', ships '.esc(fshort($r['open_so_ship'])):''?></span><?php endif;?></td>
  <td class="nw"><?php if($r['expected_date']): ?><strong><?=esc(fdate($r['expected_date']))?></strong><br><span class="sm"><?=esc(fshort($r['window_start']))?> to <?=esc(fshort($r['window_end']))?><?=$r['days_until']!==null?' &middot; '.($r['days_until']>=0?'in '.(int)$r['days_until'].' days':(abs((int)$r['days_until']).' days ago')):''?></span><?php elseif($r['status']==='Overdue'): ?><strong>Any day</strong><br><span class="sm">Past every earlier gap</span><?php else: ?><span class="sm">Not enough history</span><?php endif; ?></td>
  <td><strong><?=esc($r['customer'])?></strong><br><span class="sm"><?=esc($r['customer_code'])?></span></td>
  <td><?=esc($r['product'])?></td>
  <td class="code nw"><?=esc($r['product_code'])?></td>
  <td class="r"><span class="qty"><?=num($r['typical_qty_lbs'])?></span><?php if($r['order_count']>=3 && $r['qty_low_lbs']!=$r['qty_high_lbs']): ?><br><span class="sm">usually <?=num($r['qty_low_lbs'])?> to <?=num($r['qty_high_lbs'])?></span><?php endif;?><?php if($r['trend']!=='Stable'): ?><br><span class="<?=$r['trend']==='Increasing'?'up':'down'?> sm"><?=$r['trend']==='Increasing'?'Trending up':'Trending down'?></span><?php endif;?></td>
  <td><?php if($p!==null): ?><div class="lk"><div class="bar <?=$cls?>"><i style="width:<?=round($p*100)?>%"></i></div><strong><?=round($p*100)?>%</strong></div><span class="sm">60 days: <?=round($r['p60']*100)?>%</span><?php else: ?><span class="sm">n/a</span><?php endif;?></td>
  <td class="r"><?=$r['typical_gap_days']!==null?num($r['typical_gap_days']).' days':'-'?></td>
  <td class="nw"><?=esc(fdate($r['last_order']))?><br><span class="sm"><?=num($r['last_qty_lbs'])?> lb &middot; <?=num($r['order_count'])?> order<?=$r['order_count']==1?'':'s'?></span></td>
  <td><?=esc($r['pattern'])?><br><span class="sm"><?=esc($r['confidence'])?> confidence</span></td>
  <td><?=esc($r['rep'])?></td>
 </tr>
 <?php endforeach; ?></tbody></table></div>
 <?php if($pages>1): ?><div class="pager"><?php if($page>1):?><a href="<?=esc(qs(['page'=>$page-1]))?>">Previous</a><?php endif;?><span class="on">Page <?=$page?> of <?=$pages?></span><?php if($page<$pages):?><a href="<?=esc(qs(['page'=>$page+1]))?>">Next</a><?php endif;?></div><?php endif;?>
</div>

<?php elseif($tab==='products'): ?>
<div class="panel">
 <div class="info"><div><div class="t"><?=num(count($prod))?> products with expected demand</div><div class="s">Expected pounds = each customer's typical amount x likelihood of ordering in that window. Use this to plan purchasing. Customers who already have an open order are counted under Open sales orders.</div></div></div>
 <div class="tw"><table style="min-width:900px"><thead><tr><th>Product</th><th>Product code</th><th class="r">Customers likely in 30 days</th><th class="r">Expected lb, next 30 days</th><th class="r">Expected lb, next 60 days</th><th class="r">Open sales orders (lb)</th><th>Largest expected buyer</th></tr></thead><tbody>
 <?php $i=0; foreach($prod as $code=>$p): if(($p['e30']??0)<=0 && ($p['open']??0)<=0) continue; if(++$i>150) break; ?>
 <tr><td><strong><?=esc($p['name'])?></strong></td><td class="code"><?=esc($code)?></td><td class="r"><?=num($p['c30']??0)?></td><td class="r"><strong><?=num($p['e30']??0)?></strong></td><td class="r"><?=num($p['e60']??0)?></td><td class="r"><?=($p['open']??0)>0?num($p['open']):'-'?></td><td><?=isset($p['top'])?esc($p['top'][0]):'-'?></td></tr>
 <?php endforeach; if(!$i): ?><tr><td colspan="7" class="empty">No products match these filters.</td></tr><?php endif; ?></tbody></table></div>
</div>

<?php else: ?>
<div class="panel">
 <div class="info"><div><div class="t">Customers to call, ranked by expected pounds in the next 30 days</div><div class="s">Lists the products each customer is at least 40% likely to order in the next 30 days.</div></div></div>
 <div class="tw"><table style="min-width:900px"><thead><tr><th>Customer</th><th>Rep</th><th class="r">Products likely</th><th class="r">Expected lb, 30 days</th><th>What to expect</th></tr></thead><tbody>
 <?php $i=0; foreach($cust as $code=>$c): if(empty($c['items'])) continue; if(++$i>120) break; usort($c['items'],fn($a,$b)=>$b[2]<=>$a[2]); ?>
 <tr><td><strong><?=esc($c['name'])?></strong><br><span class="sm"><?=esc($code)?></span></td><td><?=esc($c['rep'])?></td><td class="r"><?=num($c['n30'])?></td><td class="r"><strong><?=num($c['e30'])?></strong></td>
 <td class="items"><?php foreach(array_slice($c['items'],0,4) as $it): ?><?=esc($it[0])?>: <strong><?=num($it[1])?> lb</strong> around <?=esc(fshort($it[3]))?> (<?=round($it[2]*100)?>%)<br><?php endforeach; if(count($c['items'])>4): ?><span class="sm">and <?=count($c['items'])-4?> more</span><?php endif;?></td></tr>
 <?php endforeach; if(!$i): ?><tr><td colspan="5" class="empty">No customers match these filters.</td></tr><?php endif; ?></tbody></table></div>
</div>
<?php endif; ?>

<details class="how"><summary>How the forecast works and how accurate it has been</summary>
 <ul><?php foreach(($payload['methodology']??[]) as $m): ?><li><?=esc($m)?></li><?php endforeach; ?></ul>
 <?php if($bt): ?><p><strong>Back-test:</strong> <?=esc($bt['summary']??'')?> The model ranked which pairs would order within 30 days better than a simple &quot;last order plus usual gap&quot; rule (score <?=esc($bt['auc']??'')?> vs. <?=esc($bt['auc_simple_rule']??'')?>) and its typical date miss was about <?=esc($bt['median_date_error_days']??'')?> days versus <?=esc($bt['median_date_error_simple_days']??'')?> days.</p>
 <p class="sm">When the forecast says a given likelihood, this is how often an order actually arrived within 30 days:</p>
 <div class="cal"><?php foreach(($bt['calibration']??[]) as $c): ?><div><?=esc($c['band'])?> predicted<b><?=esc($c['actual'])?>% actually ordered</b></div><?php endforeach; ?></div>
 <p class="sm">Buying dates are naturally uneven, so treat the expected date as a planning guide and the likelihood as the more reliable signal. Company-wide, customers with 3 or more past orders were about 20 to 55% likely to land inside a tight window, which is why windows are shown as ranges.</p><?php endif; ?>
</details>
</div>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>
(function(){
 const btn=document.getElementById('excelExport');
 if(!btn) return;
 const rows=<?=json_encode($list, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
 const asOf=<?=json_encode($asOf)?>;
 const viewLabel=<?=json_encode($viewMap[$view][0])?>;
 const fileDate=<?=json_encode(date('Y-m-d'))?>;
 btn.addEventListener('click', async function(){
   if(typeof ExcelJS==='undefined'){ alert('Excel export library could not load. Check the internet connection and try again.'); return; }
   btn.disabled=true; const old=btn.textContent; btn.textContent='Building Excel...';
   try{
     const wb=new ExcelJS.Workbook(); wb.creator='Lowe Chemical Company'; wb.created=new Date();
     const ws=wb.addWorksheet('Predictive Orders',{views:[{state:'frozen',ySplit:4}]});
     ws.mergeCells('A1:U1'); ws.getCell('A1').value='Lowe Chemical Company - Predictive Customer Orders';
     ws.getCell('A1').font={bold:true,size:18,color:{argb:'FFFFFFFF'}}; ws.getCell('A1').fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF061D3F'}}; ws.getCell('A1').alignment={vertical:'middle'}; ws.getRow(1).height=28;
     ws.mergeCells('A2:U2'); ws.getCell('A2').value='Forecast date: '+asOf+' | View: '+viewLabel; ws.getCell('A2').font={italic:true,color:{argb:'FF44546A'}};
     ws.mergeCells('A3:U3'); ws.getCell('A3').value='Probability-based customer order timing and typical quantities. Use expected dates as planning guides; likelihood is the stronger signal.'; ws.getCell('A3').font={size:10,color:{argb:'FF66768A'}};
     const headers=['Status','Expected Date','Window Start','Window End','Customer','Customer Code','Product','Product Code','Typical Amount (lb)','Low (lb)','High (lb)','Likelihood 30 Days','Likelihood 60 Days','Typical Gap (days)','Last Order','Last Amount (lb)','Orders on File','Pattern','Trend','Open SO (lb)','Rep'];
     ws.addRow(headers); const hr=ws.getRow(4); hr.font={bold:true,color:{argb:'FFFFFFFF'}}; hr.fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF0C2C5A'}}; hr.alignment={vertical:'middle'}; hr.height=22;
     const fills={'Due now':'FFFDE2E1','Overdue':'FFF6C9C6','Due in 8-30 days':'FFFFEDD0','Due in 31-60 days':'FFE1EDFB','Order in house':'FFDFF3E6','Gone quiet':'FFECE5F8','Later':'FFEDF1F5','One-time buyer':'FFEDF1F5','Inactive':'FFEDF1F5'};
     rows.forEach(r=>{
       const vals=[r.status,r.expected_date||'',r.window_start||'',r.window_end||'',r.customer,r.customer_code,r.product,r.product_code,r.typical_qty_lbs,r.qty_low_lbs,r.qty_high_lbs,r.p30==null?'':r.p30,r.p60==null?'':r.p60,r.typical_gap_days??'',r.last_order||'',r.last_qty_lbs,r.order_count,r.pattern,r.trend,r.open_so_lbs,r.rep];
       const row=ws.addRow(vals); const fill=fills[r.status]||'FFFFFFFF';
       row.eachCell({includeEmpty:true},c=>{c.fill={type:'pattern',pattern:'solid',fgColor:{argb:fill}}; c.alignment={vertical:'top'};});
     });
     ws.autoFilter={from:{row:4,column:1},to:{row:4,column:21}};
     [2,3,4,15].forEach(c=>ws.getColumn(c).numFmt='mmm d, yyyy');
     [9,10,11,16,20].forEach(c=>ws.getColumn(c).numFmt='#,##0');
     [12,13].forEach(c=>ws.getColumn(c).numFmt='0%');
     ws.getColumn(14).numFmt='0'; ws.getColumn(17).numFmt='0';
     const widths=[18,15,15,15,28,14,40,14,18,13,13,18,18,18,15,17,14,18,15,16,18]; widths.forEach((w,i)=>ws.getColumn(i+1).width=w);
     ws.pageSetup={orientation:'landscape',fitToPage:true,fitToWidth:1,fitToHeight:0,paperSize:9,margins:{left:.25,right:.25,top:.5,bottom:.5,header:.2,footer:.2}};
     const buf=await wb.xlsx.writeBuffer(); const blob=new Blob([buf],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}); const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='Lowe_Predictive_Orders_'+fileDate+'.xlsx'; document.body.appendChild(a); a.click(); setTimeout(()=>{URL.revokeObjectURL(a.href);a.remove();},1000);
   }catch(e){ console.error(e); alert('Excel export failed: '+e.message); }
   finally{ btn.disabled=false; btn.textContent=old; }
 });
})();
</script></body></html>
