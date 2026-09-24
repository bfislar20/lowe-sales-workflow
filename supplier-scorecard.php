<?php
declare(strict_types=1);
require_once __DIR__ . '/lowe-dashboard-common.php';

function ss_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ss_num($v,$d=0): string { return number_format((float)$v,$d); }
function ss_money($v,$d=0): string { return '$'.number_format((float)$v,$d); }
function ss_pct($v,$d=1): string { return number_format((float)$v*100,$d).'%'; }
function ss_date($v): string { if(!$v) return '-'; $t=strtotime((string)$v); return $t?date('M j, Y',$t):'-'; }
function ss_norm($v): string { return preg_replace('/\s+/',' ',strtoupper(trim((string)$v))); }
function ss_contains($h,$n): bool { if($n==='') return true; return function_exists('mb_stripos')?mb_stripos((string)$h,(string)$n)!==false:stripos((string)$h,(string)$n)!==false; }
function ss_qs(array $over=[]): string { $q=array_merge($_GET,$over); foreach($q as $k=>$v){ if($v===''||$v===null) unset($q[$k]); } return '?'.http_build_query($q); }
function ss_field(array $r,array $names,$default=''){ foreach($names as $n){ if(array_key_exists($n,$r) && $r[$n]!=='' && $r[$n]!==null) return $r[$n]; } return $default; }
function ss_purchase_total(array $r): float { return of_num(ss_field($r,['Total Item Cost','Total Cost'],0)); }
function ss_lbs_uom(array $r): float { return of_num(ss_field($r,['LBs Per Stocking Unit','LBs/UOM'],0)); }
function ss_cost_lb(array $r): float { $v=of_num(ss_field($r,['Cost/LB'],0)); if($v>0)return $v; $lbs=of_num(ss_field($r,['LBs Received'],0)); $cost=ss_purchase_total($r); return $lbs!=0?$cost/$lbs:0; }


$workbook=ld_master();\n\n$cacheFile=__DIR__.'/supplier-scorecard-cache.json';
$cacheVersion=2;
$mtime=(int)(filemtime($workbook)?:0);
$payload=null;
if(is_file($cacheFile)){
  $c=json_decode((string)file_get_contents($cacheFile),true);
  if(is_array($c)&&($c['version']??0)===$cacheVersion&&(int)($c['source_mtime']??-1)===$mtime) $payload=$c;
}

if(!$payload){
  @set_time_limit(300);
  $rows=ld_rows('Purchases');
  of_require($rows,['PO Number','Release Number','Supplier Name','Supplier Number','Receipt Date','Product Name','Product Number','Purchasing UOM','Qty Ordered','Qty Received','LBs Per Stocking Unit','LBs Received','Total Item Cost'],'Purchases');

  $latest=null;
  foreach($rows as $r){ $d=of_date($r['Receipt Date']??''); if($d&&($latest===null||$d>$latest)) $latest=$d; }
  if(!$latest) throw new RuntimeException('No valid purchase receipt dates were found.');
  $cut=new DateTimeImmutable($latest);
  $cy=(int)$cut->format('Y'); $py=$cy-1;
  $yStart="$cy-01-01"; $yEnd=$cut->format('Y-m-d');
  $pyCut=$cut->modify('-1 year'); $pyStart="$py-01-01"; $pyEnd=$pyCut->format('Y-m-d');
  $trail13Start=$cut->modify('-12 months')->modify('first day of this month')->format('Y-m-d');

  $sup=[]; $prodSup13=[]; $price=[]; $poAgg=[];
  foreach($rows as $r){
    $d=of_date($r['Receipt Date']??''); if(!$d) continue;
    $supplier=trim((string)($r['Supplier Name']??'')); if($supplier==='') continue;
    $supplierNo=trim((string)($r['Supplier Number']??''));
    $product=trim((string)($r['Product Name']??'')); if($product==='') continue;
    $prodKey=ss_norm($product);
    $lbs=(float)of_num($r['LBs Received']??0); $cost=ss_purchase_total($r); $cplb=ss_cost_lb($r);
    $period=null; if($d>=$yStart&&$d<=$yEnd)$period='ytd'; elseif($d>=$pyStart&&$d<=$pyEnd)$period='pytd';
    if(!isset($sup[$supplier])) $sup[$supplier]=['supplier'=>$supplier,'supplier_number'=>$supplierNo,'ytd_spend'=>0.0,'pytd_spend'=>0.0,'ytd_lbs'=>0.0,'pytd_lbs'=>0.0,'products'=>[],'price_up'=>0,'price_down'=>0,'fill_num'=>0.0,'fill_den'=>0.0];
    if($period){ $sup[$supplier][$period.'_spend']+=$cost; $sup[$supplier][$period.'_lbs']+=$lbs; $sup[$supplier]['products'][$prodKey]=$product; }
    if($d>=$trail13Start&&$d<=$yEnd){ $prodSup13[$prodKey]['name']=$product; $prodSup13[$prodKey]['suppliers'][$supplier]=true; $prodSup13[$prodKey]['lbs']=($prodSup13[$prodKey]['lbs']??0)+$lbs; $prodSup13[$prodKey]['spend']=($prodSup13[$prodKey]['spend']??0)+$cost; $prodSup13[$prodKey]['last']=max($prodSup13[$prodKey]['last']??'0000-00-00',$d); }

    $pk=$supplier.'|'.$prodKey;
    if(!isset($price[$pk])) $price[$pk]=['supplier'=>$supplier,'product'=>$product,'ytd_lbs'=>0.0,'ytd_cost'=>0.0,'pytd_lbs'=>0.0,'pytd_cost'=>0.0,'receipts'=>[]];
    if($period){ $price[$pk][$period.'_lbs']+=$lbs; $price[$pk][$period.'_cost']+=$cost; }
    if($cplb>0 && $lbs!=0) $price[$pk]['receipts'][]=['date'=>$d,'cost'=>$cplb,'lbs'=>$lbs];

    if($period==='ytd'){
      $po=trim((string)ss_field($r,['PO Number','PO#'],'')); $rel=trim((string)ss_field($r,['Release Number','Rel. No.'],''));
      $lbsUom=ss_lbs_uom($r); $qOrd=(float)of_num(ss_field($r,['Qty Ordered','Qty Ord.'],0)); $qRec=(float)of_num(ss_field($r,['Qty Received','Qty Rec.'],0));
      $key=$supplier.'|'.$po.'|'.$rel.'|'.$prodKey;
      $ordLbs=$qOrd>0&&$lbsUom>0?$qOrd*$lbsUom:0;
      $recLbs=$lbs!=0?$lbs:($qRec>0&&$lbsUom>0?$qRec*$lbsUom:0);
      if(!isset($poAgg[$key])) $poAgg[$key]=['supplier'=>$supplier,'ordered'=>0.0,'received'=>0.0];
      $poAgg[$key]['ordered']=max($poAgg[$key]['ordered'],$ordLbs);
      $poAgg[$key]['received']+=$recLbs;
    }
  }

  foreach($poAgg as $x){ if($x['ordered']>0){ $recv=max(0,min($x['received'],$x['ordered'])); $sup[$x['supplier']]['fill_num']+=$recv; $sup[$x['supplier']]['fill_den']+=$x['ordered']; } }

  $priceRows=[];
  foreach($price as $x){
    usort($x['receipts'],fn($a,$b)=>strcmp($b['date'],$a['date']));
    $latestRec=$x['receipts'][0]??null; $prevRec=$x['receipts'][1]??null;
    $x['latest_cost']=$latestRec['cost']??0; $x['latest_date']=$latestRec['date']??null; $x['prev_cost']=$prevRec['cost']??0; $x['prev_date']=$prevRec['date']??null;
    $x['latest_var']=$x['prev_cost']>0?$x['latest_cost']-$x['prev_cost']:null;
    $x['latest_var_pct']=$x['prev_cost']>0?($x['latest_cost']/$x['prev_cost']-1):null;
    $x['ytd_avg']=$x['ytd_lbs']!=0?$x['ytd_cost']/$x['ytd_lbs']:0; $x['pytd_avg']=$x['pytd_lbs']!=0?$x['pytd_cost']/$x['pytd_lbs']:0;
    $x['avg_var']=$x['ytd_avg']-$x['pytd_avg']; $x['avg_var_pct']=$x['pytd_avg']>0?($x['ytd_avg']/$x['pytd_avg']-1):null;
    if($x['ytd_avg']>0||$x['pytd_avg']>0||$x['latest_cost']>0) $priceRows[]=$x;
  }

  foreach($priceRows as $x){ if($x['ytd_avg']>0&&$x['pytd_avg']>0){ if($x['ytd_avg']>$x['pytd_avg']*1.001)$sup[$x['supplier']]['price_up']++; elseif($x['ytd_avg']<$x['pytd_avg']*0.999)$sup[$x['supplier']]['price_down']++; } }

  $single=[];
  foreach($prodSup13 as $pk=>$x){ $names=array_keys($x['suppliers']??[]); if(count($names)===1){ $supplier=$names[0]; $single[]=['product'=>$x['name'],'supplier'=>$supplier,'lbs'=>$x['lbs']??0,'spend'=>$x['spend']??0,'last'=>$x['last']??null]; if(isset($sup[$supplier])) $sup[$supplier]['single_source']=($sup[$supplier]['single_source']??0)+1; } }

  $supplierRows=[]; $totalSpend=0;
  foreach($sup as $s=>$x) $totalSpend+=$x['ytd_spend'];
  foreach($sup as $s=>$x){ if(abs($x['ytd_spend'])<0.01&&abs($x['pytd_spend'])<0.01)continue; $x['variance']=$x['ytd_spend']-$x['pytd_spend']; $x['variance_pct']=$x['pytd_spend']!=0?$x['variance']/$x['pytd_spend']:null; $x['spend_share']=$totalSpend>0?$x['ytd_spend']/$totalSpend:0; $x['unique_products']=count($x['products']); $x['single_source']=$x['single_source']??0; $x['fill_rate']=$x['fill_den']>0?$x['fill_num']/$x['fill_den']:null; unset($x['products']); $supplierRows[]=$x; }
  usort($supplierRows,fn($a,$b)=>$b['ytd_spend']<=>$a['ytd_spend']);
  usort($priceRows,fn($a,$b)=>abs((float)($b['avg_var_pct']??0))<=>abs((float)($a['avg_var_pct']??0)));
  usort($single,fn($a,$b)=>$b['spend']<=>$a['spend']);
  $top5=array_sum(array_column(array_slice($supplierRows,0,5),'ytd_spend')); $top5Share=$totalSpend>0?$top5/$totalSpend:0;
  $totalPY=array_sum(array_column($supplierRows,'pytd_spend')); $totalLbs=array_sum(array_column($supplierRows,'ytd_lbs'));
  $fillNum=array_sum(array_column($supplierRows,'fill_num')); $fillDen=array_sum(array_column($supplierRows,'fill_den')); $fillRate=$fillDen>0?$fillNum/$fillDen:null;

  $payload=['version'=>$cacheVersion,'source_mtime'=>$mtime,'source_file'=>basename($workbook),'latest_receipt_date'=>$latest,'current_year'=>$cy,'prior_year'=>$py,'ytd_start'=>$yStart,'ytd_end'=>$yEnd,'pytd_start'=>$pyStart,'pytd_end'=>$pyEnd,'suppliers'=>$supplierRows,'price_rows'=>$priceRows,'single_source'=>$single,'totals'=>['ytd_spend'=>$totalSpend,'pytd_spend'=>$totalPY,'ytd_lbs'=>$totalLbs,'top5_share'=>$top5Share,'single_source_count'=>count($single),'fill_rate'=>$fillRate]];
  @file_put_contents($cacheFile,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
}

$tab=in_array($_GET['tab']??'scorecard',['scorecard','price','single'],true)?($_GET['tab']??'scorecard'):'scorecard';
$q=trim((string)($_GET['q']??'')); $supplier=trim((string)($_GET['supplier']??''));
$detail=(string)($_GET['detail']??'');
$detailSupplier=trim((string)($_GET['detail_supplier']??''));
if($detail!=='purchases') { $detail=''; $detailSupplier=''; }
$suppliers=array_map(fn($r)=>$r['supplier'],$payload['suppliers']); sort($suppliers,SORT_NATURAL|SORT_FLAG_CASE);

$purchaseDetail=[]; $purchaseDetailTotals=['lbs'=>0.0,'spend'=>0.0,'lines'=>0,'pos'=>[]];
if($detail==='purchases' && $detailSupplier!==''){
  @set_time_limit(300);
  $purchaseRows=ld_rows('Purchases');
  foreach($purchaseRows as $r){
    $d=of_date($r['Receipt Date']??'');
    if(!$d || $d<$payload['ytd_start'] || $d>$payload['ytd_end']) continue;
    if(trim((string)($r['Supplier Name']??''))!==$detailSupplier) continue;
    $lbs=(float)of_num($r['LBs Received']??0);
    $cost=ss_purchase_total($r);
    $purchaseDetail[]=[
      'po'=>trim((string)ss_field($r,['PO Number','PO#'],'')),
      'release'=>trim((string)ss_field($r,['Release Number','Rel. No.'],'')),
      'date'=>$d,
      'product'=>trim((string)($r['Product Name']??'')),
      'product_no'=>trim((string)ss_field($r,['Product Number','Prod No.'],'')),
      'qty_ord'=>(float)of_num(ss_field($r,['Qty Ordered','Qty Ord.'],0)),
      'qty_rec'=>(float)of_num(ss_field($r,['Qty Received','Qty Rec.'],0)),
      'uom'=>trim((string)ss_field($r,['Purchasing UOM','UOM'],'')),
      'lbs'=>$lbs,
      'cost_lb'=>ss_cost_lb($r),
      'total_cost'=>$cost
    ];
    $purchaseDetailTotals['lbs']+=$lbs;
    $purchaseDetailTotals['spend']+=$cost;
    $purchaseDetailTotals['lines']++;
    $pokey=trim((string)ss_field($r,['PO Number','PO#'],'')); if($pokey!=='') $purchaseDetailTotals['pos'][$pokey]=true;
  }
  usort($purchaseDetail,fn($a,$b)=>strcmp($b['date'],$a['date']) ?: strcasecmp($a['po'],$b['po']) ?: strcasecmp($a['product'],$b['product']));
}

$score=array_values(array_filter($payload['suppliers'],fn($r)=>($supplier===''||$r['supplier']===$supplier)&&($q===''||ss_contains($r['supplier'],$q))));
$prices=array_values(array_filter($payload['price_rows'],fn($r)=>($supplier===''||$r['supplier']===$supplier)&&($q===''||ss_contains($r['supplier'],$q)||ss_contains($r['product'],$q))));
$single=array_values(array_filter($payload['single_source'],fn($r)=>($supplier===''||$r['supplier']===$supplier)&&($q===''||ss_contains($r['supplier'],$q)||ss_contains($r['product'],$q))));

$export=$tab==='scorecard'?$score:($tab==='price'?$prices:$single);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Purchasing & Supplier Scorecard | Lowe Chemical</title>
<style>
:root{--navy:#061d3f;--red:#c8102e;--green:#15803d;--orange:#c56a08;--blue:#1d5e91;--bg:#eef2f6;--line:#d2dce6;--muted:#68798b}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:#1f2d3d;font-size:14px}.header{background:var(--navy);color:#fff;border-bottom:4px solid var(--red)}.header .in{max-width:1600px;margin:auto;padding:15px 20px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:14px}.logo{width:180px;max-height:60px;object-fit:contain;background:#fff;border-radius:6px;padding:7px 10px}.brand h1{margin:0;font-size:23px}.brand p{margin:4px 0 0;color:#cad8e8;font-size:12px}.navbtn{display:inline-block;background:#fff;color:var(--navy);font-weight:800;text-decoration:none;padding:9px 13px;border-radius:6px}.wrap{max-width:1600px;margin:16px auto;padding:0 14px}.kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:9px;margin-bottom:12px}.kpi{background:#fff;border:1px solid var(--line);border-left:5px solid var(--blue);border-radius:8px;padding:11px 12px}.kpi:nth-child(2){border-left-color:var(--green)}.kpi:nth-child(3){border-left-color:var(--orange)}.kpi:nth-child(4){border-left-color:var(--red)}.kpi:nth-child(5){border-left-color:#7c3aed}.kpi:nth-child(6){border-left-color:#0f766e}.kpi .v{font-size:21px;font-weight:800;color:var(--navy)}.kpi .l{font-size:10px;color:var(--muted);text-transform:uppercase;margin-top:3px}.panel{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:12px}.filters{display:grid;grid-template-columns:1.5fr 1fr auto;gap:8px;align-items:end}.field label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:4px}.field input,.field select{width:100%;padding:9px;border:1px solid #b8c4d0;border-radius:5px}.btn{display:inline-block;background:var(--red);color:#fff;border:0;border-radius:5px;padding:9px 13px;font-weight:700;text-decoration:none;cursor:pointer}.btn.alt{background:#56667a}.btn.green{background:var(--green)}.btn.navy{background:var(--navy)}.btn.sm{padding:6px 9px;font-size:11px}.detail-panel{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px;margin:12px 0}.detail-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:9px}.detail-head h2{margin:0;color:var(--navy);font-size:18px}.detail-head p{margin:3px 0 0;color:var(--muted);font-size:11px}.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}.tabs a{padding:9px 12px;border-radius:6px;background:#dde6ef;color:var(--navy);font-weight:800;text-decoration:none}.tabs a.on{background:var(--navy);color:#fff}.info{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}.info .t{font-weight:800;color:var(--navy)}.info .s{font-size:11px;color:var(--muted);margin-top:2px}.tw{overflow:auto;border:1px solid var(--line);border-radius:7px}table{border-collapse:collapse;width:100%;min-width:950px}th,td{border-right:1px solid #d8e0e8;border-bottom:1px solid #d8e0e8;padding:8px 9px}th{background:#e8eef5;color:var(--navy);font-size:10px;text-transform:uppercase;white-space:nowrap;text-align:left}td{font-size:12px}.r{text-align:right;white-space:nowrap}.member{font-weight:700}.pos{color:var(--green);font-weight:800}.neg{color:#a61b1b;font-weight:800}.risk{font-weight:800;color:#7c3aed}.note{font-size:11px;color:var(--muted);line-height:1.5;margin-top:10px}.small{font-size:10px;color:var(--muted)}
@media(max-width:1000px){.kpis{grid-template-columns:repeat(3,1fr)}}@media(max-width:700px){.header .in{display:block}.brand{display:block}.logo{width:150px;margin-bottom:8px}.navbtn{margin-top:10px}.wrap{padding:0 8px}.kpis{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr}.tabs{display:grid;grid-template-columns:1fr}.tabs a{text-align:center}.tw{border:0;overflow:visible}table{min-width:0;display:block}thead{display:none}tbody{display:block}tr{display:block;background:#fff;border:1px solid var(--line);border-radius:7px;margin-bottom:9px;overflow:hidden}td{display:grid;grid-template-columns:46% 1fr;border-right:0;padding:8px 10px;text-align:right!important;white-space:normal}.member{text-align:right!important}td::before{content:attr(data-label);font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:800;text-align:left}.kpi .v{font-size:18px}}@media(max-width:430px){.kpis{grid-template-columns:1fr}}
</style></head><body>
<header class="header"><div class="in"><div class="brand"><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><div><h1>Purchasing & Supplier Scorecard</h1><p>Spend, price movement, concentration, single-source exposure, and fill rate</p></div></div><a class="navbtn" href="salesworkflow.php">Back to Sales Workflow</a></div></header>
<main class="wrap">
<div class="kpis">
 <div class="kpi"><div class="v"><?=ss_money($payload['totals']['ytd_spend'])?></div><div class="l"><?=$payload['current_year']?> YTD Spend</div></div>
 <div class="kpi"><div class="v"><?=ss_money($payload['totals']['pytd_spend'])?></div><div class="l"><?=$payload['prior_year']?> PYTD Spend</div></div>
 <?php $sv=$payload['totals']['ytd_spend']-$payload['totals']['pytd_spend']; $svp=$payload['totals']['pytd_spend']?($sv/$payload['totals']['pytd_spend']):null; ?>
 <div class="kpi"><div class="v <?=$sv>=0?'pos':'neg'?>"><?=($sv>0?'+':'').ss_money($sv)?><?=$svp!==null?' · '.ss_pct($svp):''?></div><div class="l">Spend Variance</div></div>
 <div class="kpi"><div class="v"><?=ss_pct($payload['totals']['top5_share'])?></div><div class="l">Top 5 Supplier Share</div></div>
 <div class="kpi"><div class="v"><?=ss_num($payload['totals']['single_source_count'])?></div><div class="l">Single-Source Products · 13 Mo</div></div>
 <div class="kpi"><div class="v"><?=$payload['totals']['fill_rate']!==null?ss_pct($payload['totals']['fill_rate']):'N/A'?></div><div class="l">Receipt Fill Rate · YTD</div></div>
</div>
<div class="tabs"><a class="<?=$tab==='scorecard'?'on':''?>" href="<?=ss_h(ss_qs(['tab'=>'scorecard','detail'=>null,'detail_supplier'=>null]))?>">Supplier Scorecard</a><a class="<?=$tab==='price'?'on':''?>" href="<?=ss_h(ss_qs(['tab'=>'price','detail'=>null,'detail_supplier'=>null]))?>">Purchase Price Variance</a><a class="<?=$tab==='single'?'on':''?>" href="<?=ss_h(ss_qs(['tab'=>'single','detail'=>null,'detail_supplier'=>null]))?>">Single-Source Risk</a></div>
<section class="panel"><form method="get" class="filters"><input type="hidden" name="tab" value="<?=ss_h($tab)?>"><div class="field"><label>Search</label><input type="text" name="q" value="<?=ss_h($q)?>" placeholder="Supplier or product"></div><div class="field"><label>Supplier</label><select name="supplier"><option value="">All suppliers</option><?php foreach($suppliers as $s):?><option value="<?=ss_h($s)?>" <?=$supplier===$s?'selected':''?>><?=ss_h($s)?></option><?php endforeach;?></select></div><div><button class="btn" type="submit">Run</button> <a class="btn alt" href="supplier-scorecard.php?tab=<?=ss_h($tab)?>">Reset</a></div></form></section>
<section class="panel">
<?php if($tab==='scorecard'): ?>
<div class="info"><div><div class="t"><?=ss_num(count($score))?> suppliers</div><div class="s">YTD through <?=ss_h(ss_date($payload['ytd_end']))?> versus the same cutoff in <?=$payload['prior_year']?>.</div></div><button class="btn green" id="xlsxBtn">Download .xlsx</button></div>
<div class="tw"><table><thead><tr><th>Supplier</th><th class="r">YTD Spend</th><th class="r">PYTD Spend</th><th class="r">Variance</th><th class="r">Var %</th><th class="r">YTD lb</th><th class="r">Spend Share</th><th class="r">Products</th><th class="r">Single Source</th><th class="r">Products Cost ↑</th><th class="r">Fill Rate</th><th>On-Time</th><th>Purchases</th></tr></thead><tbody><?php foreach($score as $r):?><tr><td class="member" data-label="Supplier"><?=ss_h($r['supplier'])?></td><td class="r" data-label="YTD Spend"><?=ss_money($r['ytd_spend'])?></td><td class="r" data-label="PYTD Spend"><?=ss_money($r['pytd_spend'])?></td><td class="r <?=$r['variance']>=0?'pos':'neg'?>" data-label="Variance"><?=($r['variance']>0?'+':'').ss_money($r['variance'])?></td><td class="r" data-label="Var %"><?=$r['variance_pct']!==null?ss_pct($r['variance_pct']):'-'?></td><td class="r" data-label="YTD lb"><?=ss_num($r['ytd_lbs'])?></td><td class="r" data-label="Spend Share"><?=ss_pct($r['spend_share'])?></td><td class="r" data-label="Products"><?=ss_num($r['unique_products'])?></td><td class="r risk" data-label="Single Source"><?=ss_num($r['single_source'])?></td><td class="r" data-label="Products Cost Up"><?=ss_num($r['price_up'])?></td><td class="r" data-label="Fill Rate"><?=$r['fill_rate']!==null?ss_pct($r['fill_rate']):'-'?></td><td data-label="On-Time">N/A</td><td data-label="Purchases"><a class="btn navy sm" href="<?=ss_h(ss_qs(['tab'=>'scorecard','detail'=>'purchases','detail_supplier'=>$r['supplier']]))?>">View Purchases</a></td></tr><?php endforeach;?></tbody></table></div>

<?php if($detail==='purchases' && $detailSupplier!==''): ?>
<div class="detail-panel" id="purchase-detail">
 <div class="detail-head"><div><h2>YTD Purchases · <?=ss_h($detailSupplier)?></h2><p><?=ss_num($purchaseDetailTotals['lines'])?> receipt lines · <?=ss_num(count($purchaseDetailTotals['pos']))?> PO<?=count($purchaseDetailTotals['pos'])===1?'':'s'?> · <?=ss_num($purchaseDetailTotals['lbs'])?> lb · <?=ss_money($purchaseDetailTotals['spend'])?></p></div><a class="btn alt sm" href="<?=ss_h(ss_qs(['detail'=>null,'detail_supplier'=>null]))?>">Close</a></div>
 <div class="tw"><table><thead><tr><th>Receipt Date</th><th>PO #</th><th>Release</th><th>Product</th><th>Product #</th><th class="r">Qty Ord.</th><th class="r">Qty Rec.</th><th>UOM</th><th class="r">LB Received</th><th class="r">Cost/LB</th><th class="r">Total Cost</th></tr></thead><tbody>
 <?php if(!$purchaseDetail): ?><tr><td colspan="11" style="padding:24px;text-align:center;color:#68798b">No YTD purchase receipt lines were found for this supplier.</td></tr><?php endif; ?>
 <?php foreach($purchaseDetail as $p): ?><tr><td data-label="Receipt Date"><?=ss_h(ss_date($p['date']))?></td><td class="member" data-label="PO #"><?=ss_h($p['po']?:'-')?></td><td data-label="Release"><?=ss_h($p['release']?:'-')?></td><td data-label="Product"><?=ss_h($p['product'])?></td><td data-label="Product #"><?=ss_h($p['product_no']?:'-')?></td><td class="r" data-label="Qty Ord."><?=ss_num($p['qty_ord'],2)?></td><td class="r" data-label="Qty Rec."><?=ss_num($p['qty_rec'],2)?></td><td data-label="UOM"><?=ss_h($p['uom']?:'-')?></td><td class="r" data-label="LB Received"><?=ss_num($p['lbs'])?></td><td class="r" data-label="Cost/LB"><?=ss_money($p['cost_lb'],4)?></td><td class="r" data-label="Total Cost"><?=ss_money($p['total_cost'],2)?></td></tr><?php endforeach; ?>
 <?php if($purchaseDetail): ?><tr><td colspan="8" class="member">YTD Total</td><td class="r member"><?=ss_num($purchaseDetailTotals['lbs'])?></td><td></td><td class="r member"><?=ss_money($purchaseDetailTotals['spend'],2)?></td></tr><?php endif; ?>
 </tbody></table></div>
</div>
<?php endif; ?>
<?php elseif($tab==='price'): ?>
<div class="info"><div><div class="t"><?=ss_num(count($prices))?> supplier/product price relationships</div><div class="s">No standard cost exists in Lowe Master. Variance therefore compares YTD weighted average cost/lb to PYTD and latest receipt cost to the prior receipt cost.</div></div><button class="btn green" id="xlsxBtn">Download .xlsx</button></div>
<div class="tw"><table><thead><tr><th>Supplier</th><th>Product</th><th class="r">YTD Avg $/lb</th><th class="r">PYTD Avg $/lb</th><th class="r">Avg Var</th><th class="r">Avg Var %</th><th class="r">Latest $/lb</th><th>Latest Date</th><th class="r">Previous $/lb</th><th class="r">Latest Var %</th><th class="r">YTD lb</th></tr></thead><tbody><?php foreach($prices as $r):?><tr><td class="member" data-label="Supplier"><?=ss_h($r['supplier'])?></td><td data-label="Product"><?=ss_h($r['product'])?></td><td class="r" data-label="YTD Avg"><?=ss_money($r['ytd_avg'],4)?></td><td class="r" data-label="PYTD Avg"><?=$r['pytd_avg']>0?ss_money($r['pytd_avg'],4):'-'?></td><td class="r <?=($r['avg_var']??0)>0?'neg':(($r['avg_var']??0)<0?'pos':'')?>" data-label="Avg Var"><?=ss_money($r['avg_var'],4)?></td><td class="r" data-label="Avg Var %"><?=$r['avg_var_pct']!==null?ss_pct($r['avg_var_pct']):'-'?></td><td class="r" data-label="Latest Cost"><?=ss_money($r['latest_cost'],4)?></td><td data-label="Latest Date"><?=ss_h(ss_date($r['latest_date']))?></td><td class="r" data-label="Previous Cost"><?=$r['prev_cost']>0?ss_money($r['prev_cost'],4):'-'?></td><td class="r" data-label="Latest Var %"><?=$r['latest_var_pct']!==null?ss_pct($r['latest_var_pct']):'-'?></td><td class="r" data-label="YTD lb"><?=ss_num($r['ytd_lbs'])?></td></tr><?php endforeach;?></tbody></table></div>
<?php else: ?>
<div class="info"><div><div class="t"><?=ss_num(count($single))?> products bought from only one supplier in the trailing 13 months</div><div class="s">Single-source is based on purchase history by Product Description, not product code.</div></div><button class="btn green" id="xlsxBtn">Download .xlsx</button></div>
<div class="tw"><table><thead><tr><th>Product</th><th>Only Supplier</th><th class="r">13-Mo lb</th><th class="r">13-Mo Spend</th><th>Last Receipt</th></tr></thead><tbody><?php foreach($single as $r):?><tr><td class="member" data-label="Product"><?=ss_h($r['product'])?></td><td data-label="Only Supplier"><?=ss_h($r['supplier'])?></td><td class="r" data-label="13-Mo lb"><?=ss_num($r['lbs'])?></td><td class="r" data-label="13-Mo Spend"><?=ss_money($r['spend'])?></td><td data-label="Last Receipt"><?=ss_h(ss_date($r['last']))?></td></tr><?php endforeach;?></tbody></table></div>
<?php endif; ?>
<div class="note"><strong>On-time delivery:</strong> Lowe Master contains receipt dates, but the historical purchase data does not include a promised/required delivery date. Because of that, an on-time delivery percentage cannot be calculated reliably yet. <strong>Fill rate</strong> is calculated from Qty Ordered versus Qty Received, aggregated at PO/release/product level. Source: <?=ss_h($payload['source_file'])?>.</div>
</section>
</main>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<?php if($detail==='purchases' && $detailSupplier!==''): ?>
<script>window.addEventListener('load',()=>{const x=document.getElementById('purchase-detail');if(x)x.scrollIntoView({behavior:'smooth',block:'start'});});</script>
<?php endif; ?>
<script>
const rows=<?=json_encode($export,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
const tab=<?=json_encode($tab)?>;
const btn=document.getElementById('xlsxBtn');
if(btn) btn.addEventListener('click',async()=>{ if(typeof ExcelJS==='undefined'){alert('Excel export library could not load.');return;} const wb=new ExcelJS.Workbook(),ws=wb.addWorksheet('Supplier Scorecard',{views:[{state:'frozen',ySplit:4}]}); let headers=[],data=[]; if(tab==='scorecard'){headers=['Supplier','YTD Spend','PYTD Spend','Variance','Variance %','YTD lb','Spend Share','Products','Single Source','Products Cost Up','Fill Rate','On-Time']; data=rows.map(r=>[r.supplier,r.ytd_spend,r.pytd_spend,r.variance,r.variance_pct,r.ytd_lbs,r.spend_share,r.unique_products,r.single_source,r.price_up,r.fill_rate,'N/A']);} else if(tab==='price'){headers=['Supplier','Product','YTD Avg $/lb','PYTD Avg $/lb','Avg Variance','Avg Variance %','Latest $/lb','Latest Date','Previous $/lb','Latest Variance %','YTD lb']; data=rows.map(r=>[r.supplier,r.product,r.ytd_avg,r.pytd_avg,r.avg_var,r.avg_var_pct,r.latest_cost,r.latest_date,r.prev_cost,r.latest_var_pct,r.ytd_lbs]);} else {headers=['Product','Only Supplier','13-Mo lb','13-Mo Spend','Last Receipt']; data=rows.map(r=>[r.product,r.supplier,r.lbs,r.spend,r.last]);} ws.mergeCells(1,1,1,headers.length); ws.getCell(1,1).value='Lowe Chemical Company - Purchasing & Supplier Scorecard'; ws.getCell(1,1).font={bold:true,size:16,color:{argb:'FFFFFFFF'}}; ws.getCell(1,1).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF061D3F'}}; ws.mergeCells(2,1,2,headers.length); ws.getCell(2,1).value='YTD through <?=ss_h($payload['ytd_end'])?> | PYTD through <?=ss_h($payload['pytd_end'])?>'; headers.forEach((h,i)=>ws.getCell(4,i+1).value=h); ws.getRow(4).font={bold:true,color:{argb:'FFFFFFFF'}}; ws.getRow(4).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF061D3F'}}; data.forEach(r=>ws.addRow(r)); ws.autoFilter={from:{row:4,column:1},to:{row:4,column:headers.length}}; ws.columns.forEach((c,i)=>c.width=i<2?30:16); ws.pageSetup={orientation:'landscape',fitToPage:true,fitToWidth:1,fitToHeight:0}; const buf=await wb.xlsx.writeBuffer(),blob=new Blob([buf],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='Lowe_Supplier_Scorecard_<?=date('Y-m-d')?>.xlsx'; document.body.appendChild(a); a.click(); URL.revokeObjectURL(a.href); a.remove(); });
</script>
</body></html>
