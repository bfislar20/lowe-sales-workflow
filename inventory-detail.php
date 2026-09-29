<?php
/**
 * Lowe Chemical - Inventory Detail Dashboard
 *
 * Uses the latest Lowe Master workbook maintained by predictive-orders-admin.php.
 * Required tabs/columns:
 * Inventory: Product Name, Product Number, Product UOM, Lot Number, Qty, Total LBs, Total Cost
 * Open Sales Orders: Product Name, Product Number, Qty
 * Open Purchase Orders: Product Name, Product Number, QTY
 */
declare(strict_types=1);

require_once __DIR__ . '/lowe-dashboard-common.php';

function id_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function id_num($v, int $d=0): string { return number_format((float)$v, $d); }
function id_money($v, int $d=4): string { return '$'.number_format((float)$v, $d); }
function id_contains($h,$n): bool {
    if ($n==='') return true;
    return function_exists('mb_stripos') ? mb_stripos((string)$h,(string)$n)!==false : stripos((string)$h,(string)$n)!==false;
}
function id_key($v): string { return strtoupper(trim((string)$v)); }
function id_qs(array $over=[]): string {
    $q=array_merge($_GET,$over);
    foreach($q as $k=>$v){ if($v===''||$v===null) unset($q[$k]); }
    return '?'.http_build_query($q);
}

$workbook = ld_master();

try {
    @set_time_limit(300);
    $inventoryRows = ld_rows('Inventory');
    $salesRows     = ld_rows('Open Sales Orders');
    $poRows        = ld_rows('Open Purchase Orders');

    of_require($inventoryRows,['Product Name','Product Number','Product UOM','Lot Number','Qty','Total LBs','Total Cost'],'Inventory');
    of_require($salesRows,['Product Name','Product Number','Qty'],'Open Sales Orders');
    of_require($poRows,['Product Name','Product Number','QTY'],'Open Purchase Orders');
} catch (Throwable $e) {
    http_response_code(500);
    die('Could not build Inventory Detail Dashboard: '.id_h($e->getMessage()));
}

$products=[];

// Current lot-level inventory.
foreach($inventoryRows as $r){
    $code=trim((string)($r['Product Number']??''));
    if($code==='') continue;
    $k=id_key($code);
    $name=trim((string)($r['Product Name']??''));
    $uom=trim((string)($r['Product UOM']??''));
    if(!isset($products[$k])){
        $products[$k]=[
            'product_number'=>$code,
            'product_name'=>$name,
            'uom'=>$uom,
            'lots'=>[],
            'qty_on_hand'=>0.0,
            'inventory_lbs'=>0.0,
            'inventory_cost'=>0.0,
            'open_so_qty'=>0.0,
            'open_po_qty'=>0.0,
        ];
    }
    if($name!=='') $products[$k]['product_name']=$name;
    if($uom!=='') $products[$k]['uom']=$uom;
    $lot=trim((string)($r['Lot Number']??''));
    if($lot!=='') $products[$k]['lots'][$lot]=true;
    $products[$k]['qty_on_hand'] += of_num($r['Qty']??0);
    $products[$k]['inventory_lbs'] += of_num($r['Total LBs']??0);
    $products[$k]['inventory_cost'] += of_num($r['Total Cost']??0);
}

// Open customer commitments.
foreach($salesRows as $r){
    $code=trim((string)($r['Product Number']??''));
    if($code==='') continue;
    $k=id_key($code);
    if(!isset($products[$k])){
        $products[$k]=[
            'product_number'=>$code,
            'product_name'=>trim((string)($r['Product Name']??'')),
            'uom'=>trim((string)($r['UOM']??'')),
            'lots'=>[],
            'qty_on_hand'=>0.0,
            'inventory_lbs'=>0.0,
            'inventory_cost'=>0.0,
            'open_so_qty'=>0.0,
            'open_po_qty'=>0.0,
        ];
    }
    if($products[$k]['product_name']==='' && trim((string)($r['Product Name']??''))!=='') $products[$k]['product_name']=trim((string)$r['Product Name']);
    $products[$k]['open_so_qty'] += of_num($r['Qty']??0);
}

// Open inbound purchase orders.
foreach($poRows as $r){
    $code=trim((string)($r['Product Number']??''));
    if($code==='') continue;
    $k=id_key($code);
    if(!isset($products[$k])){
        $products[$k]=[
            'product_number'=>$code,
            'product_name'=>trim((string)($r['Product Name']??'')),
            'uom'=>trim((string)($r['UOM']??'')),
            'lots'=>[],
            'qty_on_hand'=>0.0,
            'inventory_lbs'=>0.0,
            'inventory_cost'=>0.0,
            'open_so_qty'=>0.0,
            'open_po_qty'=>0.0,
        ];
    }
    if($products[$k]['product_name']==='' && trim((string)($r['Product Name']??''))!=='') $products[$k]['product_name']=trim((string)$r['Product Name']);
    if($products[$k]['uom']==='' && trim((string)($r['UOM']??''))!=='') $products[$k]['uom']=trim((string)$r['UOM']);
    $products[$k]['open_po_qty'] += of_num($r['QTY']??0);
}

$rows=[];
foreach($products as $p){
    $p['lot_count']=count($p['lots']);
    unset($p['lots']);
    $p['current_cost_lb']=$p['inventory_lbs']!=0 ? $p['inventory_cost']/$p['inventory_lbs'] : null;
    $p['projected_qty']=$p['qty_on_hand']+$p['open_po_qty']-$p['open_so_qty'];
    $rows[]=$p;
}

$q=trim((string)($_GET['q']??''));
$view=(string)($_GET['view']??'all');
if(!in_array($view,['all','onhand','open_so','open_po','negative'],true)) $view='all';
$sort=(string)($_GET['sort']??'product');
if(!in_array($sort,['product','number','qty','so','po','projected_asc','projected_desc','cost'],true)) $sort='product';
$detailProduct=trim((string)($_GET['detail']??''));

$filtered=[];
foreach($rows as $r){
    if($q!=='' && !id_contains($r['product_name'],$q) && !id_contains($r['product_number'],$q)) continue;
    if($view==='onhand' && abs($r['qty_on_hand'])<0.00001) continue;
    if($view==='open_so' && abs($r['open_so_qty'])<0.00001) continue;
    if($view==='open_po' && abs($r['open_po_qty'])<0.00001) continue;
    if($view==='negative' && $r['projected_qty']>=0) continue;
    $filtered[]=$r;
}

usort($filtered,function($a,$b)use($sort){
    if($sort==='number') return strcasecmp($a['product_number'],$b['product_number']);
    if($sort==='qty'){ $c=$b['qty_on_hand']<=>$a['qty_on_hand']; if($c) return $c; }
    if($sort==='so'){ $c=$b['open_so_qty']<=>$a['open_so_qty']; if($c) return $c; }
    if($sort==='po'){ $c=$b['open_po_qty']<=>$a['open_po_qty']; if($c) return $c; }
    if($sort==='projected_asc'){ $c=$a['projected_qty']<=>$b['projected_qty']; if($c) return $c; }
    if($sort==='projected_desc'){ $c=$b['projected_qty']<=>$a['projected_qty']; if($c) return $c; }
    if($sort==='cost'){ $c=($b['current_cost_lb']??-INF)<=>($a['current_cost_lb']??-INF); if($c) return $c; }
    return strcasecmp($a['product_name'],$b['product_name']) ?: strcasecmp($a['product_number'],$b['product_number']);
});

$detailInventory=[]; $detailSO=[]; $detailPO=[];
$detailSummary=null;
if($detailProduct!==''){
    $dk=id_key($detailProduct);
    if(isset($products[$dk])){
        $detailSummary=$products[$dk];
        foreach($inventoryRows as $r){
            if(id_key($r['Product Number']??'')!==$dk) continue;
            $detailInventory[]=[
                'lot'=>trim((string)($r['Lot Number']??'')),
                'qty'=>of_num($r['Qty']??0),
                'uom'=>trim((string)($r['Product UOM']??'')),
                'lbs'=>of_num($r['Total LBs']??0),
                'cost_lb'=>of_num($r['Cost Per LB']??0),
                'total_cost'=>of_num($r['Total Cost']??0),
                'receipt'=>of_date($r['Receipt Date']??''),
                'expire'=>of_date($r['Expire Date']??''),
            ];
        }
        usort($detailInventory,fn($a,$b)=>strcmp((string)($a['receipt']??''),(string)($b['receipt']??'')));
        foreach($salesRows as $r){
            if(id_key($r['Product Number']??'')!==$dk) continue;
            $detailSO[]=[
                'order'=>trim((string)($r['Order #']??'')),
                'release'=>trim((string)($r['Rel. No.']??'')),
                'customer'=>trim((string)($r['Customer Name']??'')),
                'order_date'=>of_date($r['Order Date']??''),
                'ship_date'=>of_date($r['Ship Date']??''),
                'qty'=>of_num($r['Qty']??0),
                'uom'=>trim((string)($r['UOM']??'')),
                'lbs'=>of_num($r['Total LBS']??0),
                'sales'=>of_num($r['Total $$']??0),
                'rep'=>trim((string)($r['Rep Name']??'')),
            ];
        }
        usort($detailSO,fn($a,$b)=>strcmp((string)($a['ship_date']??''),(string)($b['ship_date']??'')));
        foreach($poRows as $r){
            if(id_key($r['Product Number']??'')!==$dk) continue;
            $detailPO[]=[
                'po'=>trim((string)($r['PO Number']??'')),
                'release'=>trim((string)($r['Release Number']??'')),
                'po_date'=>of_date($r['PO Date']??''),
                'supplier'=>trim((string)($r['Supplier Name']??'')),
                'qty'=>of_num($r['QTY']??0),
                'uom'=>trim((string)($r['UOM']??'')),
                'lbs'=>of_num($r['LBS']??0),
                'cost_lb'=>of_num($r['Cost/LB']??0),
                'total_cost'=>of_num($r['Total Cost']??0),
            ];
        }
        usort($detailPO,fn($a,$b)=>strcmp((string)($a['po_date']??''),(string)($b['po_date']??'')));
    }
}

$totalProducts=count($filtered);
$totalQty=array_sum(array_column($filtered,'qty_on_hand'));
$totalSO=array_sum(array_column($filtered,'open_so_qty'));
$totalPO=array_sum(array_column($filtered,'open_po_qty'));
$totalProjected=array_sum(array_column($filtered,'projected_qty'));
$negativeCount=count(array_filter($filtered,fn($r)=>$r['projected_qty']<0));

if(($_GET['export']??'')==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Lowe_Inventory_Detail_'.date('Y-m-d').'.csv"');
    $o=fopen('php://output','w'); fwrite($o,"\xEF\xBB\xBF");
    fputcsv($o,['Product Name','Product Number','UOM','Current Cost/LB','Lot Count','Qty On Hand','Open Sales Orders Qty','Open Purchase Orders Qty','Projected Qty']);
    foreach($filtered as $r){
        fputcsv($o,[$r['product_name'],$r['product_number'],$r['uom'],$r['current_cost_lb'],$r['lot_count'],$r['qty_on_hand'],$r['open_so_qty'],$r['open_po_qty'],$r['projected_qty']]);
    }
    fclose($o); exit;
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Inventory Detail | Lowe Chemical</title>
<style>
:root{--navy:#061d3f;--red:#c8102e;--green:#15803d;--blue:#1d5e91;--orange:#b45309;--bg:#eef2f6;--line:#d3dde7;--muted:#68798b;--text:#1f2d3d}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:var(--text);font-size:14px}.header{background:var(--navy);color:#fff;border-bottom:4px solid var(--red)}.header .in{max-width:1650px;margin:auto;padding:15px 20px;display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:14px}.logo{width:180px;max-height:60px;object-fit:contain;background:#fff;border-radius:6px;padding:7px 10px}.brand h1{margin:0;font-size:23px}.brand p{margin:4px 0 0;color:#cbd8e8;font-size:12px}.navbtn{display:inline-block;background:#fff;color:var(--navy);text-decoration:none;font-weight:800;padding:9px 13px;border-radius:6px}.wrap{max-width:1650px;margin:16px auto;padding:0 14px 30px}.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:9px;margin-bottom:12px}.card{background:#fff;border:1px solid var(--line);border-left:5px solid var(--blue);border-radius:8px;padding:11px 12px}.card:nth-child(2){border-left-color:var(--green)}.card:nth-child(3){border-left-color:var(--orange)}.card:nth-child(4){border-left-color:#7c3aed}.card:nth-child(5){border-left-color:var(--red)}.card .n{font-size:21px;font-weight:800;color:var(--navy)}.card .l{font-size:10px;text-transform:uppercase;color:var(--muted);margin-top:3px}.panel{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:12px}.filters{display:grid;grid-template-columns:minmax(240px,1.8fr) minmax(170px,1fr) minmax(170px,1fr) auto;gap:8px;align-items:end}.field label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:4px}.field input,.field select{width:100%;padding:9px;border:1px solid #b8c4d0;border-radius:5px;background:#fff}.actions{display:flex;gap:6px;flex-wrap:wrap}.btn{display:inline-block;border:0;border-radius:5px;background:var(--red);color:#fff;text-decoration:none;padding:9px 13px;font-weight:700;cursor:pointer;white-space:nowrap}.btn.alt{background:#56667a}.btn.green{background:var(--green)}.info{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}.info .t{font-weight:800;color:var(--navy)}.info .s{font-size:11px;color:var(--muted);margin-top:2px}.tw{overflow:auto;border:1px solid var(--line);border-radius:7px;max-height:72vh}table{border-collapse:collapse;width:100%;min-width:1100px;background:#fff}th,td{border-right:1px solid #d8e0e8;border-bottom:1px solid #d8e0e8;padding:8px 9px}th{position:sticky;top:0;z-index:2;background:#e8eef5;color:var(--navy);font-size:10px;text-transform:uppercase;white-space:nowrap;text-align:right}th.left,td.left{text-align:left}td{font-size:12px;text-align:right}.product{min-width:270px;white-space:normal}.code{font-family:Consolas,monospace;white-space:nowrap}.neg{color:#a61b1b;font-weight:800;background:#fff3f3}.pos{color:var(--green);font-weight:800}.muted{color:var(--muted)}.foot{font-size:11px;color:var(--muted);line-height:1.5;margin-top:8px}.detail-btn{display:inline-block;padding:6px 9px;border-radius:5px;background:var(--navy);color:#fff;text-decoration:none;font-weight:700;font-size:11px;white-space:nowrap}.detailbox{background:#fff;border:1px solid var(--line);border-radius:8px;margin:12px 0;padding:12px}.detailhead{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px}.detailhead h2{margin:0;color:var(--navy);font-size:19px}.detailhead p{margin:4px 0 0;color:var(--muted);font-size:11px}.detailgrid{display:grid;grid-template-columns:1fr;gap:12px}.detailsection h3{margin:0 0 7px;color:var(--navy);font-size:15px}.detailsection .tw{max-height:360px}.detailsection table{min-width:900px}.empty{padding:22px!important;text-align:center!important;color:var(--muted)}
@media(max-width:1000px){.cards{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:1fr 1fr}.actions{grid-column:1/-1}.header .in{align-items:flex-start}}
@media(max-width:650px){.header .in{display:block}.brand{display:block}.logo{width:150px;margin-bottom:8px}.navbtn{margin-top:10px}.wrap{padding:0 8px}.cards,.filters{grid-template-columns:1fr}.actions{display:grid;grid-template-columns:1fr 1fr}.actions .btn{text-align:center}.tw{max-height:none}table{min-width:980px}}
</style>
</head>
<body>
<header class="header"><div class="in"><div class="brand"><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><div><h1>Inventory Detail Dashboard</h1><p>Current inventory quantities, open customer demand, and inbound purchase orders by product number</p></div></div><a class="navbtn" href="salesworkflow.php">Back to Sales Workflow</a></div></header>
<main class="wrap">
<div class="cards">
 <div class="card"><div class="n"><?=id_num($totalProducts)?></div><div class="l">Products Shown</div></div>
 <div class="card"><div class="n"><?=id_num($totalQty,2)?></div><div class="l">Qty On Hand</div></div>
 <div class="card"><div class="n"><?=id_num($totalSO,2)?></div><div class="l">Open Sales Order Qty</div></div>
 <div class="card"><div class="n"><?=id_num($totalPO,2)?></div><div class="l">Open Purchase Order Qty</div></div>
 <div class="card"><div class="n <?= $totalProjected<0?'neg':'pos' ?>"><?=id_num($totalProjected,2)?></div><div class="l">Projected Qty · <?=$negativeCount?> Negative</div></div>
</div>
<section class="panel"><form method="get" class="filters">
 <div class="field"><label>Product / Product Number</label><input type="text" name="q" value="<?=id_h($q)?>" placeholder="Search product description or number"></div>
 <div class="field"><label>View</label><select name="view"><option value="all" <?=$view==='all'?'selected':''?>>All products</option><option value="onhand" <?=$view==='onhand'?'selected':''?>>Qty on hand</option><option value="open_so" <?=$view==='open_so'?'selected':''?>>Open sales orders</option><option value="open_po" <?=$view==='open_po'?'selected':''?>>Open purchase orders</option><option value="negative" <?=$view==='negative'?'selected':''?>>Negative projected qty</option></select></div>
 <div class="field"><label>Sort By</label><select name="sort"><option value="product" <?=$sort==='product'?'selected':''?>>Product name</option><option value="number" <?=$sort==='number'?'selected':''?>>Product number</option><option value="qty" <?=$sort==='qty'?'selected':''?>>Qty on hand</option><option value="so" <?=$sort==='so'?'selected':''?>>Open SO qty</option><option value="po" <?=$sort==='po'?'selected':''?>>Open PO qty</option><option value="projected_asc" <?=$sort==='projected_asc'?'selected':''?>>On Hand + Open PO - Open SO · Low to High</option><option value="projected_desc" <?=$sort==='projected_desc'?'selected':''?>>On Hand + Open PO - Open SO · High to Low</option><option value="cost" <?=$sort==='cost'?'selected':''?>>Current cost/lb</option></select></div>
 <div class="actions"><button class="btn" type="submit">Run</button><a class="btn alt" href="inventory-detail.php">Reset</a><a class="btn green" href="<?=id_h(id_qs(['export'=>'csv']))?>">Download CSV</a></div>
</form></section>
<section class="panel">
 <div class="info"><div><div class="t"><?=id_num($totalProducts)?> product<?= $totalProducts===1?'':'s' ?></div><div class="s">Projected Qty = Qty On Hand + Open Purchase Order Qty - Open Sales Order Qty.</div></div><div class="s">Source: <?=id_h(basename($workbook))?></div></div>
 <div class="tw"><table><thead><tr>
  <th class="left">Product Name</th><th class="left">Product Number</th><th class="left">UOM</th><th>Current Cost/LB</th><th>No. of Lot Numbers</th><th>Qty On Hand</th><th>Open Sales Orders Qty</th><th>Open Purchase Orders Qty</th><th><a style="color:inherit;text-decoration:none" title="Click to toggle sort" href="<?=id_h(id_qs(['sort'=>$sort==='projected_asc'?'projected_desc':'projected_asc']))?>">On Hand + Open PO - Open SO <?=$sort==='projected_asc'?'▲':($sort==='projected_desc'?'▼':'↕')?></a></th>
 </tr></thead><tbody>
 <?php if(!$filtered): ?><tr><td colspan="10" style="padding:28px;text-align:center;color:#68798b">No products match these filters.</td></tr><?php endif; ?>
 <?php foreach($filtered as $r): ?>
 <tr>
  <td class="left product"><?=id_h($r['product_name']?:'-')?></td>
  <td class="left code"><?=id_h($r['product_number'])?></td>
  <td class="left"><?=id_h($r['uom']?:'-')?></td>
  <td><?=$r['current_cost_lb']===null?'-':id_money($r['current_cost_lb'],4)?></td>
  <td><?=id_num($r['lot_count'])?></td>
  <td><?=id_num($r['qty_on_hand'],2)?></td>
  <td><?=id_num($r['open_so_qty'],2)?></td>
  <td><?=id_num($r['open_po_qty'],2)?></td>
  <td class="<?=$r['projected_qty']<0?'neg':'pos'?>"><?=id_num($r['projected_qty'],2)?></td>
  <td><a class="detail-btn" href="<?=id_h(id_qs(['detail'=>$r['product_number'],'export'=>null]))?>#product-detail">Detail</a></td>
 </tr>
 <?php endforeach; ?>
 </tbody></table></div>
 <?php if($detailSummary): ?>
 <div class="detailbox" id="product-detail">
  <div class="detailhead"><div><h2><?=id_h($detailSummary['product_name']?:$detailProduct)?> · <?=id_h($detailSummary['product_number'])?></h2><p>Inventory, open sales orders, and open purchase orders for this product number.</p></div><a class="btn alt" href="<?=id_h(id_qs(['detail'=>null]))?>">Close Detail</a></div>
  <div class="detailgrid">
   <div class="detailsection"><h3>Current Inventory Lots · <?=id_num(count($detailInventory))?> lot line<?=count($detailInventory)===1?'':'s'?></h3><div class="tw"><table><thead><tr><th class="left">Lot Number</th><th>Qty</th><th class="left">UOM</th><th>Total LBs</th><th>Cost/LB</th><th>Total Cost</th><th class="left">Receipt Date</th><th class="left">Expire Date</th></tr></thead><tbody>
   <?php if(!$detailInventory): ?><tr><td colspan="8" class="empty">No current inventory lots for this product.</td></tr><?php endif; ?>
   <?php $diQty=$diLbs=$diCost=0; foreach($detailInventory as $x): $diQty+=$x['qty'];$diLbs+=$x['lbs'];$diCost+=$x['total_cost']; ?><tr><td class="left code"><?=id_h($x['lot']?:'-')?></td><td><?=id_num($x['qty'],2)?></td><td class="left"><?=id_h($x['uom']?:'-')?></td><td><?=id_num($x['lbs'],2)?></td><td><?=$x['cost_lb']?id_money($x['cost_lb'],4):'-'?></td><td><?=id_money($x['total_cost'],2)?></td><td class="left"><?=id_h($x['receipt']?date('M j, Y',strtotime($x['receipt'])):'-')?></td><td class="left"><?=id_h($x['expire']?date('M j, Y',strtotime($x['expire'])):'-')?></td></tr><?php endforeach; ?>
   <?php if($detailInventory): ?><tr><td class="left"><strong>Total</strong></td><td><strong><?=id_num($diQty,2)?></strong></td><td></td><td><strong><?=id_num($diLbs,2)?></strong></td><td></td><td><strong><?=id_money($diCost,2)?></strong></td><td></td><td></td></tr><?php endif; ?>
   </tbody></table></div></div>

   <div class="detailsection"><h3>Open Sales Orders · <?=id_num(count($detailSO))?> line<?=count($detailSO)===1?'':'s'?></h3><div class="tw"><table><thead><tr><th class="left">Order #</th><th class="left">Release</th><th class="left">Customer</th><th class="left">Order Date</th><th class="left">Ship Date</th><th>Qty</th><th class="left">UOM</th><th>Total LBs</th><th>Sales $</th><th class="left">Rep</th></tr></thead><tbody>
   <?php if(!$detailSO): ?><tr><td colspan="10" class="empty">No open sales orders for this product.</td></tr><?php endif; ?>
   <?php $soQty=$soLbs=$soSales=0; foreach($detailSO as $x): $soQty+=$x['qty'];$soLbs+=$x['lbs'];$soSales+=$x['sales']; ?><tr><td class="left code"><?=id_h($x['order']?:'-')?></td><td class="left"><?=id_h($x['release']?:'-')?></td><td class="left"><?=id_h($x['customer']?:'-')?></td><td class="left"><?=id_h($x['order_date']?date('M j, Y',strtotime($x['order_date'])):'-')?></td><td class="left"><?=id_h($x['ship_date']?date('M j, Y',strtotime($x['ship_date'])):'-')?></td><td><?=id_num($x['qty'],2)?></td><td class="left"><?=id_h($x['uom']?:'-')?></td><td><?=id_num($x['lbs'],2)?></td><td><?=id_money($x['sales'],2)?></td><td class="left"><?=id_h($x['rep']?:'-')?></td></tr><?php endforeach; ?>
   <?php if($detailSO): ?><tr><td colspan="5" class="left"><strong>Total</strong></td><td><strong><?=id_num($soQty,2)?></strong></td><td></td><td><strong><?=id_num($soLbs,2)?></strong></td><td><strong><?=id_money($soSales,2)?></strong></td><td></td></tr><?php endif; ?>
   </tbody></table></div></div>

   <div class="detailsection"><h3>Open Purchase Orders · <?=id_num(count($detailPO))?> line<?=count($detailPO)===1?'':'s'?></h3><div class="tw"><table><thead><tr><th class="left">PO Number</th><th class="left">Release</th><th class="left">PO Date</th><th class="left">Supplier</th><th>Qty</th><th class="left">UOM</th><th>LBS</th><th>Cost/LB</th><th>Total Cost</th></tr></thead><tbody>
   <?php if(!$detailPO): ?><tr><td colspan="9" class="empty">No open purchase orders for this product.</td></tr><?php endif; ?>
   <?php $poQty=$poLbs=$poCost=0; foreach($detailPO as $x): $poQty+=$x['qty'];$poLbs+=$x['lbs'];$poCost+=$x['total_cost']; ?><tr><td class="left code"><?=id_h($x['po']?:'-')?></td><td class="left"><?=id_h($x['release']?:'-')?></td><td class="left"><?=id_h($x['po_date']?date('M j, Y',strtotime($x['po_date'])):'-')?></td><td class="left"><?=id_h($x['supplier']?:'-')?></td><td><?=id_num($x['qty'],2)?></td><td class="left"><?=id_h($x['uom']?:'-')?></td><td><?=id_num($x['lbs'],2)?></td><td><?=$x['cost_lb']?id_money($x['cost_lb'],4):'-'?></td><td><?=id_money($x['total_cost'],2)?></td></tr><?php endforeach; ?>
   <?php if($detailPO): ?><tr><td colspan="4" class="left"><strong>Total</strong></td><td><strong><?=id_num($poQty,2)?></strong></td><td></td><td><strong><?=id_num($poLbs,2)?></strong></td><td></td><td><strong><?=id_money($poCost,2)?></strong></td></tr><?php endif; ?>
   </tbody></table></div></div>
  </div>
 </div>
 <?php endif; ?>
 <div class="foot">Current Cost/LB is the weighted average value of current inventory: Total Cost divided by Total LBs for each product number. Lot count is the number of distinct nonblank lot numbers currently present on the Inventory tab. Quantities use each product's native UOM from the Lowe Master workbook.</div>
</section>
</main>
</body>
</html>
