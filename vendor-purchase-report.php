<?php
/*
  Lowe Chemical Vendor Purchase Report
  Calendar-year supplier/product monthly purchase volume with PO drill-down.
  Uses the latest Lowe Master workbook saved by predictive-orders-admin.php.
*/

require_once __DIR__ . '/predictive-order-engine.php';

$masterCandidates = [
    __DIR__ . '/predictive-order-files/Lowe-Master-Latest.xlsx',
    __DIR__ . '/order-forecast-files/Lowe-Master-Latest.xlsx',
    __DIR__ . '/Lowe Master.xlsx',
    __DIR__ . '/Lowe Master(1).xlsx',
    __DIR__ . '/Lowe-Master-Latest.xlsx'
];
$masterFile = null;
foreach ($masterCandidates as $f) {
    if (is_file($f)) { $masterFile = $f; break; }
}
if (!$masterFile) {
    http_response_code(500);
    die('Lowe Master workbook not found. Upload the latest workbook through predictive-orders-admin.php first.');
}

function vp_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function vp_n($v,$d=0){ return number_format((float)$v,$d); }
function vp_m($v,$d=2){ return '$'.number_format((float)$v,$d); }
function vp_contains($h,$n){ if($n==='') return true; return function_exists('mb_stripos') ? mb_stripos((string)$h,(string)$n)!==false : stripos((string)$h,(string)$n)!==false; }
function vp_field(array $r,array $names,$default=''){ foreach($names as $n){ if(array_key_exists($n,$r) && $r[$n]!=='' && $r[$n]!==null) return $r[$n]; } return $default; }
function vp_po(array $r): string { return trim((string)vp_field($r,['PO Number','PO#','PO No.','PO'],'')); }
function vp_rel(array $r): string { return trim((string)vp_field($r,['Release Number','Rel. No.','Release No.','Release'],'')); }
function vp_prodno(array $r): string { return trim((string)vp_field($r,['Product Number','Prod No.','Product No.'],'')); }
function vp_qtyord(array $r): float { return of_num(vp_field($r,['Qty Ordered','Qty Ord.'],0)); }
function vp_qtyrec(array $r): float { return of_num(vp_field($r,['Qty Received','Qty Rec.'],0)); }
function vp_uom(array $r): string { return trim((string)vp_field($r,['Purchasing UOM','UOM'],'')); }
function vp_lbs(array $r): float { return of_num(vp_field($r,['LBs Received','LBS Received','Total LBS'],0)); }
function vp_cost(array $r): float { return of_num(vp_field($r,['Total Item Cost','Total Cost'],0)); }
function vp_costlb(array $r): float {
    $direct=of_num(vp_field($r,['Cost/LB','Cost Per LB'],0));
    if($direct!=0) return $direct;
    $lbs=vp_lbs($r); return $lbs!=0 ? vp_cost($r)/$lbs : 0.0;
}
function vp_qs(array $over=[]): string {
    $q=array_merge($_GET,$over);
    foreach($q as $k=>$v){ if($v===''||$v===null) unset($q[$k]); }
    return '?'.http_build_query($q);
}

try {
    @set_time_limit(300);
    $purchaseRows = of_assoc(of_read_sheet($masterFile,'Purchases'));
    $openPoRows = of_assoc(of_read_sheet($masterFile,'Open Purchase Orders'));
    of_require($purchaseRows,['Supplier Name','Receipt Date','Product Name','LBs Received'],'Purchases');
    of_require($openPoRows,['PO Number','Supplier Name','Product Number','Product Name','QTY','LBS'],'Open Purchase Orders');
} catch(Throwable $e){
    http_response_code(500);
    die('Could not build Vendor Purchase Report: '.vp_h($e->getMessage()));
}

$years=[]; $latestDate=null;
foreach($purchaseRows as $r){
    $d=of_date($r['Receipt Date']??'');
    if(!$d) continue;
    $y=(int)date('Y',strtotime($d)); $years[$y]=true;
    if($latestDate===null || $d>$latestDate) $latestDate=$d;
}
$years=array_keys($years); rsort($years,SORT_NUMERIC);
if(!$years) die('No valid purchase receipt dates were found.');

$year=(int)($_GET['year']??$years[0]);
if(!in_array($year,$years,true)) $year=$years[0];
$supplierFilter = $_GET['suppliers'] ?? [];
if(!is_array($supplierFilter)) $supplierFilter=[$supplierFilter];
$supplierFilter=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$supplierFilter),fn($v)=>$v!=='')));
$supplierLookup=array_fill_keys($supplierFilter,true);
$productFilter = $_GET['products'] ?? [];
if(!is_array($productFilter)) $productFilter=[$productFilter];
$productFilter=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$productFilter),fn($v)=>$v!=='')));
$productLookup=array_fill_keys($productFilter,true);
$supplierCountFilter=max(0,(int)($_GET['supplier_count']??0));
$sort=$_GET['sort']??'supplier';
if(!in_array($sort,['supplier','product','volume'],true)) $sort='supplier';
$page=max(1,(int)($_GET['page']??1)); $per=100;
$detailSupplier=trim((string)($_GET['detail_supplier']??''));
$detailProduct=trim((string)($_GET['detail_product']??''));

$groups=[]; $supplierNames=[]; $productNames=[];
foreach($purchaseRows as $r){
    $d=of_date($r['Receipt Date']??'');
    if(!$d || (int)date('Y',strtotime($d))!==$year) continue;
    $supplier=trim((string)($r['Supplier Name']??''));
    $product=trim((string)($r['Product Name']??''));
    if($supplier===''||$product==='') continue;
    $supplierNames[$supplier]=true;
    $productNames[$product]=true;
    $k=strtoupper($supplier.'|'.$product);
    if(!isset($groups[$k])) $groups[$k]=['supplier'=>$supplier,'product'=>$product,'months'=>array_fill(1,12,0.0),'total'=>0.0,'spend'=>0.0,'lines'=>0];
    $m=(int)date('n',strtotime($d)); $lbs=vp_lbs($r); $cost=vp_cost($r);
    $groups[$k]['months'][$m]+=$lbs; $groups[$k]['total']+=$lbs; $groups[$k]['spend']+=$cost; $groups[$k]['lines']++;
}
$productSuppliers=[];
foreach($groups as $g){ $productSuppliers[strtoupper($g['product'])][$g['supplier']]=true; }
foreach($groups as &$g){ $g['supplier_count']=count($productSuppliers[strtoupper($g['product'])]??[]); }
unset($g);
$maxSupplierCount=0; foreach($productSuppliers as $ps){ $maxSupplierCount=max($maxSupplierCount,count($ps)); }
if($supplierCountFilter>$maxSupplierCount) $supplierCountFilter=0;
$suppliers=array_keys($supplierNames); sort($suppliers,SORT_NATURAL|SORT_FLAG_CASE);
$products=array_keys($productNames); sort($products,SORT_NATURAL|SORT_FLAG_CASE);

$filtered=[];
foreach($groups as $g){
    if($supplierFilter && !isset($supplierLookup[$g['supplier']])) continue;
    if($productFilter && !isset($productLookup[$g['product']])) continue;
    if($supplierCountFilter>0 && (int)$g['supplier_count']!==$supplierCountFilter) continue;
    $filtered[]=$g;
}
usort($filtered,function($a,$b)use($sort){
    if($sort==='volume'){ $c=$b['total']<=>$a['total']; if($c) return $c; }
    if($sort==='product'){ $c=strcasecmp($a['product'],$b['product']); if($c) return $c; return strcasecmp($a['supplier'],$b['supplier']); }
    $c=strcasecmp($a['supplier'],$b['supplier']); return $c ?: strcasecmp($a['product'],$b['product']);
});
$totalRows=count($filtered); $pages=max(1,(int)ceil($totalRows/$per)); $page=min($page,$pages); $slice=array_slice($filtered,($page-1)*$per,$per);

$kpiSup=[]; $kpiLbs=0.0; $kpiSpend=0.0;
foreach($filtered as $g){$kpiSup[$g['supplier']]=true;$kpiLbs+=$g['total'];$kpiSpend+=$g['spend'];}

$detailRows=[]; $detailPOs=[]; $detailLbs=0.0; $detailSpend=0.0;
$detailOpenRows=[]; $detailOpenPOs=[]; $detailOpenQty=0.0; $detailOpenLbs=0.0; $detailOpenValue=0.0;
$detailSuppliers=[]; $detailAllSuppliers=false;
if($detailSupplier!=='' && $detailProduct!==''){
    $detailProductKey=strtoupper($detailProduct);
    $supplierSet=array_keys($productSuppliers[$detailProductKey]??[]);
    $detailAllSuppliers=count($supplierSet)>1;
    foreach($purchaseRows as $r){
        $d=of_date($r['Receipt Date']??''); if(!$d || (int)date('Y',strtotime($d))!==$year) continue;
        $supplier=trim((string)($r['Supplier Name']??''));
        if(!$detailAllSuppliers && $supplier!==$detailSupplier) continue;
        if(trim((string)($r['Product Name']??''))!==$detailProduct) continue;
        $row=[
            'supplier'=>$supplier,'date'=>$d,'po'=>vp_po($r),'release'=>vp_rel($r),'product_no'=>vp_prodno($r),
            'qty_ord'=>vp_qtyord($r),'qty_rec'=>vp_qtyrec($r),'uom'=>vp_uom($r),
            'lbs'=>vp_lbs($r),'cost_lb'=>vp_costlb($r),'total_cost'=>vp_cost($r)
        ];
        $detailRows[]=$row; $detailLbs+=$row['lbs']; $detailSpend+=$row['total_cost'];
        $detailSuppliers[$supplier]=true;
        if($row['po']!=='') $detailPOs[$supplier.'|'.$row['po']]=true;
    }
    usort($detailRows,fn($a,$b)=>strcasecmp($a['supplier'],$b['supplier']) ?: strcmp($b['date'],$a['date']) ?: strcasecmp($a['po'],$b['po']));

    foreach($openPoRows as $r){
        $supplier=trim((string)($r['Supplier Name']??''));
        if(!$detailAllSuppliers && $supplier!==$detailSupplier) continue;
        if(trim((string)($r['Product Name']??''))!==$detailProduct) continue;
        $qty=of_num($r['QTY']??0); $lbs=of_num($r['LBS']??0); $value=of_num($r['Total Cost']??0);
        $row=[
            'supplier'=>$supplier,'po'=>trim((string)($r['PO Number']??'')),'release'=>trim((string)($r['Release Number']??'')),
            'po_date'=>of_date($r['PO Date']??''),'owner'=>trim((string)($r['PO Owner']??'')),
            'product_no'=>trim((string)($r['Product Number']??'')),'uom'=>trim((string)($r['UOM']??'')),
            'qty'=>$qty,'lbs'=>$lbs,'cost_lb'=>of_num($r['Cost/LB']??0),'unit_cost'=>of_num($r['Unit Cost']??0),'total_cost'=>$value
        ];
        $detailOpenRows[]=$row; $detailOpenQty+=$qty; $detailOpenLbs+=$lbs; $detailOpenValue+=$value;
        $detailSuppliers[$supplier]=true;
        if($row['po']!=='') $detailOpenPOs[$supplier.'|'.$row['po']]=true;
    }
    usort($detailOpenRows,fn($a,$b)=>strcasecmp($a['supplier'],$b['supplier']) ?: strcmp((string)$b['po_date'],(string)$a['po_date']) ?: strcasecmp($a['po'],$b['po']));
}

$monthNames=[1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
$exportRows=[];
foreach($filtered as $g){
    $x=['Supplier'=>$g['supplier'],'Product'=>$g['product']];
    foreach($monthNames as $m=>$label) $x[$label]=round($g['months'][$m],2);
    $x['Total Volume']=round($g['total'],2); $x['No. of Suppliers']=(int)$g['supplier_count']; $exportRows[]=$x;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Vendor Purchase Report | Lowe Chemical</title>
<style>
:root{--navy:#061d3f;--red:#c8102e;--bg:#eef2f6;--card:#fff;--line:#d1dae4;--text:#203142;--muted:#68798a;--green:#15803d;--blue:#1d5e91}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:var(--text);font-size:14px}.header{background:var(--navy);color:#fff;border-bottom:4px solid var(--red)}.head{max-width:1750px;margin:auto;padding:15px 20px;display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:14px}.logo{width:180px;background:#fff;border-radius:6px;padding:7px 10px}.brand h1{margin:0;font-size:23px}.brand p{margin:4px 0 0;color:#cbd8e8;font-size:12px}.meta{text-align:right;color:#d7e3ef;font-size:12px;line-height:1.6}.workflow{display:inline-block;background:#fff;color:var(--navy);text-decoration:none;font-weight:800;padding:8px 11px;border-radius:6px;margin-top:5px}.wrap{max-width:1750px;margin:16px auto;padding:0 14px}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}.kpi{background:#fff;border:1px solid var(--line);border-left:5px solid var(--blue);border-radius:8px;padding:11px 13px}.kpi:nth-child(2){border-left-color:var(--green)}.kpi:nth-child(3){border-left-color:#b45309}.kpi:nth-child(4){border-left-color:var(--red)}.kpi .v{font-size:22px;font-weight:800;color:var(--navy)}.kpi .l{font-size:10px;text-transform:uppercase;color:var(--muted);margin-top:3px}.panel{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:12px}.filters{display:grid;grid-template-columns:120px minmax(230px,1.25fr) 190px minmax(260px,1.45fr) 150px auto;gap:9px;align-items:end}.field label{display:block;font-size:10px;text-transform:uppercase;font-weight:800;color:var(--muted);margin-bottom:4px}.field input,.field select{width:100%;padding:9px;border:1px solid #b7c3cf;border-radius:6px;background:#fff}.btn{display:inline-block;border:0;border-radius:6px;padding:9px 13px;background:var(--red);color:#fff;text-decoration:none;font-weight:800;cursor:pointer;white-space:nowrap}.btn.alt{background:#56667a}.btn.green{background:var(--green)}.btn.navy{background:var(--navy)}.btn.sm{padding:6px 9px;font-size:11px}.multi{position:relative}.multi summary{list-style:none;width:100%;padding:9px 30px 9px 10px;border:1px solid #b7c3cf;border-radius:6px;background:#fff;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:relative}.multi summary::-webkit-details-marker{display:none}.multi summary:after{content:'▾';position:absolute;right:10px;color:var(--muted)}.multi[open] summary{border-color:var(--blue)}.multi-menu{position:absolute;z-index:40;top:calc(100% + 5px);left:0;width:min(440px,92vw);background:#fff;border:1px solid #b7c3cf;border-radius:7px;box-shadow:0 10px 24px rgba(6,29,63,.18);padding:9px}.multi-tools{display:flex;gap:6px;margin-bottom:7px}.multi-tools input{flex:1;padding:8px 9px;border:1px solid #b7c3cf;border-radius:5px}.mini-btn{border:1px solid var(--line);background:#f7f9fb;color:var(--navy);border-radius:5px;padding:7px 9px;font-weight:700;cursor:pointer}.multi-list{max-height:280px;overflow:auto;border-top:1px solid #e5eaf0;padding-top:5px}.multi-opt{display:flex;align-items:flex-start;gap:8px;padding:6px 4px;font-size:12px;line-height:1.3}.multi-opt:hover{background:#f7f9fb}.multi-opt input{width:auto;margin-top:2px}.selected-note{margin-top:6px;color:var(--muted);font-size:10.5px}.actions{display:flex;gap:6px;flex-wrap:wrap}.info{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:9px}.info .t{font-weight:800;color:var(--navy)}.info .s{font-size:11px;color:var(--muted);margin-top:2px}.tw{overflow:auto;border:1px solid var(--line);border-radius:7px}.report{border-collapse:collapse;width:max-content;min-width:100%;background:#fff}.report th,.report td{border:1px solid #d8e0e8;padding:7px 8px}.report th{position:sticky;top:0;background:#e8eef5;color:var(--navy);font-size:10px;text-transform:uppercase;white-space:nowrap;z-index:3}.report td{font-size:12px}.left{text-align:left}.r{text-align:right;white-space:nowrap}.supplier{min-width:190px;max-width:220px;white-space:normal;font-weight:700}.product{min-width:260px;max-width:320px;white-space:normal}.month{min-width:78px}.total{font-weight:800;background:#f7f9fb;min-width:100px}.supplier-count{width:62px;min-width:62px;max-width:62px;text-align:center!important;white-space:normal!important;line-height:1.15}.detail-panel{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px;margin-top:12px}.detail-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:9px}.detail-head h2{margin:0;color:var(--navy);font-size:18px}.detail-head p{margin:4px 0 0;color:var(--muted);font-size:11px}.pager{display:flex;gap:6px;justify-content:center;align-items:center;margin-top:12px}.pager a,.pager span{padding:6px 10px;border:1px solid var(--line);border-radius:5px;text-decoration:none;background:#fff;color:var(--navy)}.pager .on{background:var(--navy);color:#fff}.neg{color:#a61b1b}.mobile{display:none}
@media(max-width:900px){.kpis{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr 1fr}.meta{text-align:left}}@media(max-width:700px){.head{display:block}.brand{display:block}.logo{width:150px;margin-bottom:8px}.wrap{padding:0 8px}.kpis{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr}.tw{overflow:auto}.report{min-width:1320px}.meta{margin-top:10px}.actions{display:grid;grid-template-columns:1fr 1fr}.actions .btn{text-align:center}}@media(max-width:440px){.kpis{grid-template-columns:1fr}}
</style></head><body>
<header class="header"><div class="head"><div class="brand"><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><div><h1>Vendor Purchase Report</h1><p>Calendar-year purchase volume by supplier and product</p></div></div><div class="meta">Selected Year: <strong><?=vp_h($year)?></strong><br>Source: <strong><?=vp_h(basename($masterFile))?></strong><br><a class="workflow" href="salesworkflow.php">Back to Sales Workflow</a></div></div></header>
<main class="wrap">
<section class="kpis"><div class="kpi"><div class="v"><?=vp_n(count($kpiSup))?></div><div class="l">Suppliers · <?=$year?></div></div><div class="kpi"><div class="v"><?=vp_n($totalRows)?></div><div class="l">Supplier / Product Rows</div></div><div class="kpi"><div class="v"><?=vp_n($kpiLbs)?> lb</div><div class="l">Total Purchase Volume</div></div><div class="kpi"><div class="v"><?=vp_m($kpiSpend,0)?></div><div class="l">Total Purchase Spend</div></div></section>
<section class="panel"><form class="filters" method="get"><div class="field"><label>Calendar Year</label><select name="year"><?php foreach($years as $y):?><option value="<?=$y?>" <?=$year===$y?'selected':''?>><?=$y?></option><?php endforeach;?></select></div>
<div class="field"><label>Suppliers</label><details class="multi" id="supplierMulti"><summary id="supplierSummary"><?=$supplierFilter ? vp_n(count($supplierFilter)).' supplier'.(count($supplierFilter)===1?'':'s').' selected' : 'All suppliers'?></summary><div class="multi-menu"><div class="multi-tools"><input type="text" id="supplierSearch" placeholder="Type any part of supplier name"><button class="mini-btn" type="button" id="selectSuppliers">Select visible</button><button class="mini-btn" type="button" id="clearSuppliers">Clear</button></div><div class="multi-list" id="supplierList"><?php foreach($suppliers as $s):?><label class="multi-opt" data-search="<?=vp_h(strtolower($s))?>"><input type="checkbox" name="suppliers[]" value="<?=vp_h($s)?>" <?=isset($supplierLookup[$s])?'checked':''?>><span><?=vp_h($s)?></span></label><?php endforeach;?></div><div class="selected-note">Leave all unchecked to include every supplier.</div></div></details></div>
<div class="field"><label>No. of Suppliers on Product</label><select name="supplier_count"><option value="0">All supplier counts</option><?php for($sc=1;$sc<=$maxSupplierCount;$sc++):?><option value="<?=$sc?>" <?=$supplierCountFilter===$sc?'selected':''?>><?=$sc?> supplier<?=$sc===1?'':'s'?></option><?php endfor;?></select></div>
<div class="field"><label>Products</label><details class="multi" id="productMulti"><summary id="productSummary"><?=$productFilter ? vp_n(count($productFilter)).' product'.(count($productFilter)===1?'':'s').' selected' : 'All products'?></summary><div class="multi-menu"><div class="multi-tools"><input type="text" id="productSearch" placeholder="Type any part of product name"><button class="mini-btn" type="button" id="selectProducts">Select visible</button><button class="mini-btn" type="button" id="clearProducts">Clear</button></div><div class="multi-list" id="productList"><?php foreach($products as $p):?><label class="multi-opt" data-search="<?=vp_h(strtolower($p))?>"><input type="checkbox" name="products[]" value="<?=vp_h($p)?>" <?=isset($productLookup[$p])?'checked':''?>><span><?=vp_h($p)?></span></label><?php endforeach;?></div><div class="selected-note">Leave all unchecked to include every product.</div></div></details></div>
<div class="field"><label>Sort By</label><select name="sort"><option value="supplier" <?=$sort==='supplier'?'selected':''?>>Supplier</option><option value="product" <?=$sort==='product'?'selected':''?>>Product</option><option value="volume" <?=$sort==='volume'?'selected':''?>>Total Volume</option></select></div><div class="actions"><button class="btn" type="submit">Run</button><a class="btn alt" href="vendor-purchase-report.php?year=<?=$year?>">Reset</a></div></form></section>
<section class="panel"><div class="info"><div><div class="t"><?=vp_n($totalRows)?> supplier/product rows for <?=$year?></div><div class="s">Monthly values are pounds received. Total Volume is January through December for the selected calendar year.</div></div><button type="button" class="btn green" id="xlsxBtn">Download .xlsx</button></div>
<div class="tw"><table class="report"><thead><tr><th class="left">Supplier Name</th><th class="left">Product Name</th><?php foreach($monthNames as $label):?><th class="r month"><?=$label?></th><?php endforeach;?><th class="r total">Total Volume</th><th class="supplier-count">No. of<br>Suppliers</th><th>Detail</th></tr></thead><tbody><?php if(!$slice):?><tr><td colspan="17" style="padding:28px;text-align:center;color:#68798a">No purchase activity matches these filters.</td></tr><?php endif;?><?php foreach($slice as $g):?><tr><td class="left supplier"><?=vp_h($g['supplier'])?></td><td class="left product"><?=vp_h($g['product'])?></td><?php foreach($monthNames as $m=>$label):?><td class="r month"><?=$g['months'][$m]!=0?vp_n($g['months'][$m]):'-'?></td><?php endforeach;?><td class="r total"><?=vp_n($g['total'])?></td><td class="supplier-count"><strong><?=vp_n($g['supplier_count'])?></strong></td><td><a class="btn navy sm" href="<?=vp_h(vp_qs(['detail_supplier'=>$g['supplier'],'detail_product'=>$g['product'],'page'=>$page]))?>#po-detail">Detail</a></td></tr><?php endforeach;?></tbody></table></div>
<?php if($pages>1):?><div class="pager"><?php if($page>1):?><a href="<?=vp_h(vp_qs(['page'=>$page-1,'detail_supplier'=>null,'detail_product'=>null]))?>">Previous</a><?php endif;?><span class="on">Page <?=$page?> of <?=$pages?></span><?php if($page<$pages):?><a href="<?=vp_h(vp_qs(['page'=>$page+1,'detail_supplier'=>null,'detail_product'=>null]))?>">Next</a><?php endif;?></div><?php endif;?></section>

<?php if($detailSupplier!=='' && $detailProduct!==''):?>
<section class="detail-panel" id="po-detail">
<div class="detail-head"><div><h2>PO Detail · <?=vp_h($detailProduct)?></h2><p><?=$detailAllSuppliers?'All suppliers for this product':'Supplier: '.vp_h($detailSupplier)?> · <?=$year?> · <?=vp_n(count($detailSuppliers))?> supplier<?=count($detailSuppliers)===1?'':'s'?> · <?=vp_n(count($detailPOs))?> received PO<?=count($detailPOs)===1?'':'s'?> · <?=vp_n($detailLbs)?> lb received · <?=vp_m($detailSpend,0)?></p></div><a class="btn alt sm" href="<?=vp_h(vp_qs(['detail_supplier'=>null,'detail_product'=>null]))?>">Close Detail</a></div>
<?php
$detailSupplierNames=array_keys($detailSuppliers);
sort($detailSupplierNames,SORT_NATURAL|SORT_FLAG_CASE);
foreach($detailSupplierNames as $ds):
    $supplierReceived=array_values(array_filter($detailRows,fn($x)=>$x['supplier']===$ds));
    $supplierOpen=array_values(array_filter($detailOpenRows,fn($x)=>$x['supplier']===$ds));
    $srLbs=array_sum(array_column($supplierReceived,'lbs'));
    $srSpend=array_sum(array_column($supplierReceived,'total_cost'));
    $srPOs=[]; foreach($supplierReceived as $x){if($x['po']!=='')$srPOs[$x['po']]=true;}
    $soQty=array_sum(array_column($supplierOpen,'qty'));
    $soLbs=array_sum(array_column($supplierOpen,'lbs'));
    $soValue=array_sum(array_column($supplierOpen,'total_cost'));
    $soPOs=[]; foreach($supplierOpen as $x){if($x['po']!=='')$soPOs[$x['po']]=true;}
?>
<div class="supplier-detail-block" style="margin-top:16px;border:1px solid #cfd8e3;border-radius:8px;overflow:hidden;background:#fff">
 <div style="background:#eef3f8;padding:11px 13px;border-bottom:1px solid #cfd8e3"><strong style="color:#061d3f;font-size:15px"><?=vp_h($ds)?></strong><div style="font-size:11px;color:#68798a;margin-top:3px"><?=vp_n(count($srPOs))?> received PO<?=count($srPOs)===1?'':'s'?> · <?=vp_n($srLbs)?> lb · <?=vp_m($srSpend,0)?> received spend<?php if($supplierOpen):?> · <?=vp_n(count($soPOs))?> open PO<?=count($soPOs)===1?'':'s'?> · <?=vp_n($soLbs)?> lb open<?php endif;?></div></div>
 <div class="info" style="padding:10px 10px 0"><div><div class="t">Received Purchase History</div><div class="s">PO receipts for <?=vp_h($ds)?> and this product in <?=$year?>.</div></div></div>
 <div class="tw" style="margin:0 10px 10px"><table class="report"><thead><tr><th>Receipt Date</th><th>PO Number</th><th>Release</th><th>Product Number</th><th class="r">Qty Ordered</th><th class="r">Qty Received</th><th>UOM</th><th class="r">LBs Received</th><th class="r">Cost/LB</th><th class="r">Total Cost</th></tr></thead><tbody>
 <?php if(!$supplierReceived):?><tr><td colspan="10" style="padding:22px;text-align:center;color:#68798a">No received PO detail was found for this supplier in <?=$year?>.</td></tr><?php endif;?>
 <?php foreach($supplierReceived as $d):?><tr><td><?=vp_h(date('M j, Y',strtotime($d['date'])))?></td><td><strong><?=vp_h($d['po']?:'-')?></strong></td><td><?=vp_h($d['release']?:'-')?></td><td><?=vp_h($d['product_no']?:'-')?></td><td class="r"><?=vp_n($d['qty_ord'],2)?></td><td class="r"><?=vp_n($d['qty_rec'],2)?></td><td><?=vp_h($d['uom']?:'-')?></td><td class="r"><?=vp_n($d['lbs'])?></td><td class="r"><?=vp_m($d['cost_lb'],4)?></td><td class="r"><?=vp_m($d['total_cost'],2)?></td></tr><?php endforeach;?>
 <?php if($supplierReceived):?><tr><td colspan="7"><strong><?=vp_h($ds)?> Total</strong></td><td class="r"><strong><?=vp_n($srLbs)?></strong></td><td></td><td class="r"><strong><?=vp_m($srSpend,2)?></strong></td></tr><?php endif;?></tbody></table></div>

 <div class="info" style="padding:4px 10px 0"><div><div class="t">Open Purchase Orders</div><div class="s">Current open POs for <?=vp_h($ds)?> and this product.</div></div></div>
 <div class="tw" style="margin:0 10px 12px"><table class="report"><thead><tr><th>PO Date</th><th>PO Number</th><th>Release</th><th>PO Owner</th><th>Product Number</th><th class="r">Open Qty</th><th>UOM</th><th class="r">Open LBs</th><th class="r">Cost/LB</th><th class="r">Open Value</th></tr></thead><tbody>
 <?php if(!$supplierOpen):?><tr><td colspan="10" style="padding:22px;text-align:center;color:#68798a">No open purchase orders were found for <?=vp_h($ds)?> on this product.</td></tr><?php endif;?>
 <?php foreach($supplierOpen as $d):?><tr><td><?=$d['po_date']?vp_h(date('M j, Y',strtotime($d['po_date']))):'-'?></td><td><strong><?=vp_h($d['po']?:'-')?></strong></td><td><?=vp_h($d['release']?:'-')?></td><td><?=vp_h($d['owner']?:'-')?></td><td><?=vp_h($d['product_no']?:'-')?></td><td class="r"><?=vp_n($d['qty'],2)?></td><td><?=vp_h($d['uom']?:'-')?></td><td class="r"><?=vp_n($d['lbs'])?></td><td class="r"><?=vp_m($d['cost_lb'],4)?></td><td class="r"><?=vp_m($d['total_cost'],2)?></td></tr><?php endforeach;?>
 <?php if($supplierOpen):?><tr><td colspan="5"><strong><?=vp_h($ds)?> Open PO Total</strong></td><td class="r"><strong><?=vp_n($soQty,2)?></strong></td><td></td><td class="r"><strong><?=vp_n($soLbs)?></strong></td><td></td><td class="r"><strong><?=vp_m($soValue,2)?></strong></td></tr><?php endif;?></tbody></table></div>
</div>
<?php endforeach;?>
</section>
<?php endif;?>
</main>
<script>
function setupMulti(listId,searchId,summaryId,selectId,clearId,singular){
 const list=document.getElementById(listId), search=document.getElementById(searchId), summary=document.getElementById(summaryId), selectBtn=document.getElementById(selectId), clearBtn=document.getElementById(clearId);
 if(!list||!search||!summary)return;
 const opts=[...list.querySelectorAll('.multi-opt')];
 const checks=()=>[...list.querySelectorAll('input[type="checkbox"]')];
 const normalize=v=>(v||'').toString().toLowerCase().replace(/[^a-z0-9]+/g,' ').trim();
 const update=()=>{const n=checks().filter(c=>c.checked).length; summary.textContent=n ? n+' '+singular+(n===1?'':'s')+' selected' : 'All '+singular+'s';};
 const applySearch=()=>{
   const raw=search.value.trim();
   const q=normalize(raw);
   const terms=q?q.split(/\s+/).filter(Boolean):[];
   opts.forEach(o=>{
     const hay=normalize(o.dataset.search||o.textContent);
     const match=!terms.length || terms.every(t=>hay.includes(t));
     o.style.display=match?'flex':'none';
   });
 };
 search.addEventListener('input',applySearch);
 search.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();applySearch();}});
 list.addEventListener('change',update);
 selectBtn.addEventListener('click',()=>{applySearch();opts.filter(o=>o.style.display!=='none').forEach(o=>{const c=o.querySelector('input');if(c)c.checked=true;});update();});
 clearBtn.addEventListener('click',()=>{checks().forEach(c=>c.checked=false);update();});
 update();
}
setupMulti('supplierList','supplierSearch','supplierSummary','selectSuppliers','clearSuppliers','supplier');
setupMulti('productList','productSearch','productSummary','selectProducts','clearProducts','product');
</script>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script><script>
const exportRows=<?=json_encode($exportRows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
document.getElementById('xlsxBtn').addEventListener('click',async function(){
 if(typeof ExcelJS==='undefined'){alert('Excel export library could not load.');return;}
 const wb=new ExcelJS.Workbook(); wb.creator='Lowe Chemical Company'; const ws=wb.addWorksheet('Vendor Purchase Report',{views:[{state:'frozen',ySplit:4,xSplit:2}]});
 const cols=['Supplier Name','Product Name','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec','Total Volume','No. of Suppliers'];
 ws.mergeCells(1,1,1,cols.length); ws.getCell(1,1).value='Lowe Chemical Company - Vendor Purchase Report'; ws.getCell(1,1).font={bold:true,size:16,color:{argb:'FFFFFFFF'}}; ws.getCell(1,1).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF061D3F'}};
 ws.mergeCells(2,1,2,cols.length); ws.getCell(2,1).value='Calendar Year: <?=$year?>'; ws.getCell(2,1).font={italic:true,color:{argb:'FF66768A'}};
 cols.forEach((c,i)=>ws.getCell(4,i+1).value=c); ws.getRow(4).font={bold:true,color:{argb:'FFFFFFFF'}}; ws.getRow(4).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF0C2C5A'}};
 exportRows.forEach(r=>ws.addRow([r.Supplier,r.Product,r.Jan||0,r.Feb||0,r.Mar||0,r.Apr||0,r.May||0,r.Jun||0,r.Jul||0,r.Aug||0,r.Sep||0,r.Oct||0,r.Nov||0,r.Dec||0,r['Total Volume']||0,r['No. of Suppliers']||0]));
 ws.autoFilter={from:{row:4,column:1},to:{row:4,column:cols.length}}; ws.getColumn(1).width=28; ws.getColumn(2).width=40; for(let c=3;c<=15;c++){ws.getColumn(c).width=12;for(let r=5;r<=ws.rowCount;r++)ws.getCell(r,c).numFmt='#,##0;[Red](#,##0);-';}
 ws.pageSetup={orientation:'landscape',fitToPage:true,fitToWidth:1,fitToHeight:0,margins:{left:.25,right:.25,top:.5,bottom:.5,header:.2,footer:.2}};
 const buf=await wb.xlsx.writeBuffer(),blob=new Blob([buf],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='Lowe_Vendor_Purchase_Report_<?=$year?>.xlsx';document.body.appendChild(a);a.click();setTimeout(()=>{URL.revokeObjectURL(a.href);a.remove();},1000);
});
</script></body></html>
