<?php
declare(strict_types=1);
require_once __DIR__ . '/inventory-model-v2.php';
if (!defined('LOWE_INVENTORY_MODEL_VERSION') || LOWE_INVENTORY_MODEL_VERSION < 2) {
    http_response_code(500);
    die('The lot-level inventory model is not installed. Upload inventory-model-v2.php with this dashboard.');
}

function iad_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function iad_num($v, int $d=0): string { return number_format((float)$v, $d); }
function iad_money($v, int $d=2): string { return '$'.number_format((float)$v, $d); }
function iad_date($v): string { if(!$v) return '-'; $t=strtotime((string)$v); return $t?date('M j, Y',$t):'-'; }
function iad_field(array $r,array $names,$default=''){ foreach($names as $n){ if(array_key_exists($n,$r) && $r[$n]!=='' && $r[$n]!==null) return $r[$n]; } return $default; }
function iad_po(array $r): string { return trim((string)iad_field($r,['PO Number','PO#','PO No.','PO'],'')); }
function iad_rel(array $r): string { return trim((string)iad_field($r,['Release Number','Rel. No.','Release No.','Release'],'')); }
function iad_prodno(array $r): string { return trim((string)iad_field($r,['Product Number','Prod No.','Product No.'],'')); }
function iad_qtyord(array $r): float { return of_num(iad_field($r,['Qty Ordered','Qty Ord.'],0)); }
function iad_qtyrec(array $r): float { return of_num(iad_field($r,['Qty Received','Qty Rec.'],0)); }
function iad_uom(array $r): string { return trim((string)iad_field($r,['Purchasing UOM','UOM'],'')); }
function iad_lbs(array $r): float { return of_num(iad_field($r,['LBs Received','LBS Received','Total LBS'],0)); }
function iad_cost(array $r): float { return of_num(iad_field($r,['Total Item Cost','Total Cost'],0)); }
function iad_costlb(array $r): float {
    $direct=of_num(iad_field($r,['Cost/LB','Cost Per LB'],0));
    if($direct!=0) return $direct;
    $lbs=iad_lbs($r); return $lbs!=0 ? iad_cost($r)/$lbs : 0.0;
}
function iad_qs(array $over=[]): string {
    $q=array_merge($_GET,$over);
    foreach($q as $k=>$v){ if($v===''||$v===null||(is_array($v)&&count($v)===0)) unset($q[$k]); }
    return '?'.http_build_query($q);
}

$allRows=$data['rows'];

try {
    $purchaseRows=ld_rows('Purchases');
    $openPoRows=ld_rows('Open Purchase Orders');
} catch(Throwable $e) {
    $purchaseRows=[];
    $openPoRows=[];
}

// Latest purchase receipt date is used only to define the optional PO-history period.
$latestPurchase=null;
foreach($purchaseRows as $r){
    $d=of_date($r['Receipt Date']??'');
    if($d && ($latestPurchase===null || $d>$latestPurchase)) $latestPurchase=$d;
}
if(!$latestPurchase) $latestPurchase=date('Y-m-d');

$period=(string)($_GET['period']??'ytd');
$periodEnd=$latestPurchase;
if($period==='12m'){
    $periodStart=date('Y-m-d',strtotime($periodEnd.' -11 months -'.(date('j',strtotime($periodEnd))-1).' days'));
}elseif($period==='13m'){
    $periodStart=date('Y-m-d',strtotime($periodEnd.' -12 months -'.(date('j',strtotime($periodEnd))-1).' days'));
}elseif($period==='all'){
    $periodStart='1900-01-01';
}else{
    $period='ytd';
    $periodStart=date('Y',strtotime($periodEnd)).'-01-01';
}

$productFilter=$_GET['products']??[];
if(!is_array($productFilter)) $productFilter=[$productFilter];
$productFilter=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$productFilter),fn($v)=>$v!=='')));
$productLookup=array_fill_keys($productFilter,true);

$supplierFilter=$_GET['suppliers']??[];
if(!is_array($supplierFilter)) $supplierFilter=[$supplierFilter];
$supplierFilter=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$supplierFilter),fn($v)=>$v!=='')));
$supplierLookup=array_fill_keys($supplierFilter,true);

$view=(string)($_GET['view']??'action');
$sort=(string)($_GET['sort']??'recommended');
if(!in_array($sort,['recommended','days','available','value','product'],true)) $sort='recommended';

// Product list from inventory model.
$products=[];
foreach($allRows as $r){
    $p=trim((string)($r['product_name']??''));
    if($p!=='') $products[$p]=true;
}
$products=array_keys($products); sort($products,SORT_NATURAL|SORT_FLAG_CASE);

// Supplier list from purchase history and open POs, plus any primary supplier from the model.
$supplierNames=[];
foreach($allRows as $r){ $s=trim((string)($r['primary_supplier']??'')); if($s!=='') $supplierNames[$s]=true; }
foreach($purchaseRows as $r){ $s=trim((string)($r['Supplier Name']??'')); if($s!=='') $supplierNames[$s]=true; }
foreach($openPoRows as $r){ $s=trim((string)($r['Supplier Name']??'')); if($s!=='') $supplierNames[$s]=true; }
$suppliers=array_keys($supplierNames); sort($suppliers,SORT_NATURAL|SORT_FLAG_CASE);

// Build product -> suppliers mapping using PO history in selected period plus current open POs.
$productSuppliers=[];
foreach($purchaseRows as $r){
    $d=of_date($r['Receipt Date']??'');
    if(!$d || $d<$periodStart || $d>$periodEnd) continue;
    $pc=iad_prodno($r); $pn=trim((string)($r['Product Name']??'')); $s=trim((string)($r['Supplier Name']??''));
    if($s==='') continue;
    if($pc!=='') $productSuppliers['C:'.strtoupper($pc)][$s]=true;
    if($pn!=='') $productSuppliers['N:'.strtoupper($pn)][$s]=true;
}
foreach($openPoRows as $r){
    $pc=iad_prodno($r); $pn=trim((string)($r['Product Name']??'')); $s=trim((string)($r['Supplier Name']??''));
    if($s==='') continue;
    if($pc!=='') $productSuppliers['C:'.strtoupper($pc)][$s]=true;
    if($pn!=='') $productSuppliers['N:'.strtoupper($pn)][$s]=true;
}

$matchesSuppliers=function(array $r) use($supplierFilter,$supplierLookup,$productSuppliers): bool {
    if(!$supplierFilter) return true;
    $sets=[];
    $pc=trim((string)($r['product_code']??''));
    $pn=trim((string)($r['product_name']??''));
    if($pc!=='' && isset($productSuppliers['C:'.strtoupper($pc)])) $sets += $productSuppliers['C:'.strtoupper($pc)];
    if($pn!=='' && isset($productSuppliers['N:'.strtoupper($pn)])) $sets += $productSuppliers['N:'.strtoupper($pn)];
    $primary=trim((string)($r['primary_supplier']??''));
    if($primary!=='') $sets[$primary]=true;
    foreach($supplierFilter as $s){ if(isset($sets[$s])) return true; }
    return false;
};

$match=function($r)use($view,$productFilter,$productLookup,$matchesSuppliers){
    $a=strtoupper((string)($r['action']??''));
    if($productFilter && !isset($productLookup[(string)($r['product_name']??'')])) return false;
    if(!$matchesSuppliers($r)) return false;
    if($view==='action'&&!in_array($a,['ORDER NOW','ORDER SOON','PLAN ORDER'],true))return false;
    if($view==='now'&&$a!=='ORDER NOW')return false;
    if($view==='soon'&&$a!=='ORDER SOON')return false;
    if($view==='plan'&&$a!=='PLAN ORDER')return false;
    if($view==='covered'&&$a!=='COVERED')return false;
    if($view==='negative'&&(float)($r['available_lbs']??0)>=0)return false;
    if($view==='expiry'&&(float)($r['expired_lbs']??0)<=0&&(float)($r['expiring_90_lbs']??0)<=0)return false;
    return true;
};

$rows=array_values(array_filter($allRows,$match));
usort($rows,function($a,$b)use($sort){
    if($sort==='days')return($a['days_supply_after_open_so']??999999)<=>($b['days_supply_after_open_so']??999999);
    if($sort==='product')return strcasecmp($a['product_name'],$b['product_name']);
    if($sort==='available')return$a['available_lbs']<=>$b['available_lbs'];
    if($sort==='value')return$b['inventory_value']<=>$a['inventory_value'];
    return$b['recommended_order_lbs']<=>$a['recommended_order_lbs'];
});

$base=array_values(array_filter($allRows,function($r)use($productFilter,$productLookup,$matchesSuppliers){
    if($productFilter && !isset($productLookup[(string)($r['product_name']??'')])) return false;
    return $matchesSuppliers($r);
}));
$sumRec=array_sum(array_column($base,'recommended_order_lbs'));
$now=count(array_filter($base,fn($r)=>($r['action']??'')==='ORDER NOW'));
$soon=count(array_filter($base,fn($r)=>($r['action']??'')==='ORDER SOON'));
$neg=count(array_filter($base,fn($r)=>(float)($r['available_lbs']??0)<0));

if(($_GET['download']??'')==='csv'){
    ld_export_csv('inventory-action-'.date('Y-m-d').'.csv',
        ['Action','Product #','Product','Recommended LB','Days Supply','On Hand LB','Allocated/Open SO LB','Available LB','Open PO LB','Projected Available LB','Forecast 30D LB','Reorder Point LB','Target Supply LB','MIN Units','MAX Units','Lead Time Days','Inventory Value','Expired LB','Expiring 90D LB','Primary Supplier','Last Cost/LB','Top Customer'],
        array_map(fn($r)=>[$r['action'],$r['product_code'],$r['product_name'],round($r['recommended_order_lbs'],2),$r['days_supply_after_open_so']===null?'':round($r['days_supply_after_open_so'],1),round($r['on_hand_lbs'],2),round($r['allocated_lbs'],2),round($r['available_lbs'],2),round($r['open_purchase_orders_lbs'],2),round($r['projected_available_lbs'],2),round($r['forecast_next_30_days_lbs'],2),round($r['reorder_point_lbs'],2),round($r['target_supply_lbs'],2),$r['min_units'],$r['max_units'],$r['lead_time_days'],round($r['inventory_value'],2),round($r['expired_lbs'],2),round($r['expiring_90_lbs'],2),$r['primary_supplier'],$r['last_cost_per_lb'],$r['top_customer']],$rows)
    );
}

// Purchase detail, opened in a separate browser tab from the main report.
$detail=(($_GET['detail']??'')==='po');
$detailProductCode=trim((string)($_GET['detail_product_code']??''));
$detailProductName=trim((string)($_GET['detail_product_name']??''));
$detailRows=[]; $detailOpen=[]; $detailSuppliers=[]; $detailLbs=0.0; $detailSpend=0.0; $detailPOs=[];
if($detail && ($detailProductCode!=='' || $detailProductName!=='')){
    foreach($purchaseRows as $r){
        $d=of_date($r['Receipt Date']??''); if(!$d || $d<$periodStart || $d>$periodEnd) continue;
        $pc=iad_prodno($r); $pn=trim((string)($r['Product Name']??''));
        $same=($detailProductCode!=='' && strcasecmp($pc,$detailProductCode)===0) || ($detailProductName!=='' && strcasecmp($pn,$detailProductName)===0);
        if(!$same) continue;
        $s=trim((string)($r['Supplier Name']??''));
        if($supplierFilter && !isset($supplierLookup[$s])) continue;
        $row=[
            'supplier'=>$s,'date'=>$d,'po'=>iad_po($r),'release'=>iad_rel($r),'product'=>$pn,'product_no'=>$pc,
            'qty_ord'=>iad_qtyord($r),'qty_rec'=>iad_qtyrec($r),'uom'=>iad_uom($r),'lbs'=>iad_lbs($r),
            'cost_lb'=>iad_costlb($r),'total_cost'=>iad_cost($r)
        ];
        $detailRows[]=$row; $detailLbs+=$row['lbs']; $detailSpend+=$row['total_cost'];
        if($s!=='') $detailSuppliers[$s]=true; if($row['po']!=='') $detailPOs[$s.'|'.$row['po']]=true;
    }
    usort($detailRows,fn($a,$b)=>strcmp($b['date'],$a['date']) ?: strcasecmp($a['supplier'],$b['supplier']) ?: strcasecmp($a['po'],$b['po']));

    foreach($openPoRows as $r){
        $pc=iad_prodno($r); $pn=trim((string)($r['Product Name']??''));
        $same=($detailProductCode!=='' && strcasecmp($pc,$detailProductCode)===0) || ($detailProductName!=='' && strcasecmp($pn,$detailProductName)===0);
        if(!$same) continue;
        $s=trim((string)($r['Supplier Name']??''));
        if($supplierFilter && !isset($supplierLookup[$s])) continue;
        $qty=of_num($r['QTY']??0); $lbs=of_num($r['LBS']??0); $value=of_num(iad_field($r,['Total Cost','Total Item Cost'],0));
        $costLb=of_num(iad_field($r,['Cost/LB','Cost Per LB'],0)); if($costLb==0 && $lbs!=0) $costLb=$value/$lbs;
        $detailOpen[]=[
            'supplier'=>$s,'po_date'=>of_date($r['PO Date']??''),'po'=>iad_po($r),'release'=>iad_rel($r),
            'owner'=>trim((string)($r['PO Owner']??'')),'product'=>$pn,'product_no'=>$pc,'qty'=>$qty,
            'uom'=>trim((string)iad_field($r,['UOM','Purchasing UOM'],'')),'lbs'=>$lbs,'cost_lb'=>$costLb,'total_cost'=>$value
        ];
        if($s!=='') $detailSuppliers[$s]=true;
    }
    usort($detailOpen,fn($a,$b)=>strcmp((string)$b['po_date'],(string)$a['po_date']) ?: strcasecmp($a['supplier'],$b['supplier']) ?: strcasecmp($a['po'],$b['po']));
}

ld_head('Inventory Action Dashboard','Lot-level stock, customer commitments, inbound supply, and purchasing actions');
?>
<style>
.iad-filters{display:grid;grid-template-columns:minmax(220px,1.1fr) minmax(240px,1.25fr) 150px 145px 160px auto;gap:9px;align-items:end}
.iad-multi{position:relative}.iad-multi summary{list-style:none;width:100%;padding:9px 30px 9px 10px;border:1px solid #b7c3cf;border-radius:6px;background:#fff;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:relative}.iad-multi summary::-webkit-details-marker{display:none}.iad-multi summary:after{content:'▾';position:absolute;right:10px;color:#68798a}.iad-multi[open] summary{border-color:#1d5e91}.iad-menu{position:absolute;z-index:50;top:calc(100% + 5px);left:0;width:min(460px,92vw);background:#fff;border:1px solid #b7c3cf;border-radius:7px;box-shadow:0 10px 24px rgba(6,29,63,.18);padding:9px}.iad-tools{display:flex;gap:6px;margin-bottom:7px}.iad-tools input{flex:1;padding:8px 9px;border:1px solid #b7c3cf;border-radius:5px}.iad-mini{border:1px solid #d1dae4;background:#f7f9fb;color:#061d3f;border-radius:5px;padding:7px 9px;font-weight:700;cursor:pointer}.iad-list{max-height:280px;overflow:auto;border-top:1px solid #e5eaf0;padding-top:5px}.iad-opt{display:flex;align-items:flex-start;gap:8px;padding:6px 4px;font-size:12px;line-height:1.3}.iad-opt:hover{background:#f7f9fb}.iad-opt input{width:auto;margin-top:2px}.iad-note{margin-top:6px;color:#68798a;font-size:10.5px}.detail-btn{display:inline-block;background:#061d3f;color:#fff!important;text-decoration:none;font-weight:800;padding:6px 9px;border-radius:5px;font-size:11px}.detail-panel{background:#fff;border:1px solid #d1dae4;border-radius:8px;padding:12px;margin-bottom:14px}.detail-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px}.detail-head h2{margin:0;color:#061d3f;font-size:18px}.detail-head p{margin:4px 0 0;color:#68798a;font-size:11px}.detail-table{overflow:auto;border:1px solid #d1dae4;border-radius:7px;margin-bottom:14px}.detail-table table{width:max-content;min-width:100%;border-collapse:collapse}.detail-table th,.detail-table td{border:1px solid #d8e0e8;padding:7px 8px;font-size:12px;white-space:nowrap}.detail-table th{background:#e8eef5;color:#061d3f;font-size:10px;text-transform:uppercase}.iad-actions{display:flex;gap:6px;flex-wrap:wrap}
@media(max-width:1050px){.iad-filters{grid-template-columns:1fr 1fr 1fr}.iad-actions{grid-column:span 3}}@media(max-width:700px){.iad-filters{grid-template-columns:1fr}.iad-actions{grid-column:auto;display:grid;grid-template-columns:1fr 1fr}.iad-actions .btn{text-align:center}.iad-menu{position:fixed;left:4vw;right:4vw;top:18vh;width:92vw;max-height:70vh;overflow:auto}}
</style>

<?php if($detail): ?>
<div class="detail-panel">
  <div class="detail-head">
    <div>
      <h2>Purchase Order Detail · <?=iad_h($detailProductName?:$detailProductCode)?></h2>
      <p><?=iad_h(iad_date($periodStart))?> through <?=iad_h(iad_date($periodEnd))?> · <?=iad_num(count($detailSuppliers))?> supplier<?=count($detailSuppliers)===1?'':'s'?> · <?=iad_num(count($detailPOs))?> received PO<?=count($detailPOs)===1?'':'s'?> · <?=iad_num($detailLbs)?> lb received · <?=iad_money($detailSpend,0)?></p>
    </div>
    <a class="btn alt" href="inventory-dashboard.php">Close Detail</a>
  </div>
  <div class="info"><div><div class="title">Received Purchase History</div><div class="sub">PO receipt lines for the selected product and purchase-detail period.</div></div></div>
  <div class="detail-table"><table><thead><tr><th>Supplier</th><th>Receipt Date</th><th>PO Number</th><th>Release</th><th>Product Number</th><th>Qty Ordered</th><th>Qty Received</th><th>UOM</th><th>LBs Received</th><th>Cost/LB</th><th>Total Cost</th></tr></thead><tbody>
  <?php if(!$detailRows):?><tr><td colspan="11" style="text-align:center;padding:24px;color:#68798a">No received purchase-order detail was found in the selected timeframe.</td></tr><?php endif;?>
  <?php foreach($detailRows as $d):?><tr><td><?=iad_h($d['supplier']?:'-')?></td><td><?=iad_h(iad_date($d['date']))?></td><td><strong><?=iad_h($d['po']?:'-')?></strong></td><td><?=iad_h($d['release']?:'-')?></td><td><?=iad_h($d['product_no']?:'-')?></td><td class="r"><?=iad_num($d['qty_ord'],2)?></td><td class="r"><?=iad_num($d['qty_rec'],2)?></td><td><?=iad_h($d['uom']?:'-')?></td><td class="r"><?=iad_num($d['lbs'])?></td><td class="r"><?=iad_money($d['cost_lb'],4)?></td><td class="r"><?=iad_money($d['total_cost'],2)?></td></tr><?php endforeach;?>
  <?php if($detailRows):?><tr><td colspan="8"><strong>Selected Period Total</strong></td><td class="r"><strong><?=iad_num($detailLbs)?></strong></td><td></td><td class="r"><strong><?=iad_money($detailSpend,2)?></strong></td></tr><?php endif;?>
  </tbody></table></div>

  <div class="info"><div><div class="title">Current Open Purchase Orders</div><div class="sub">Current open POs for the same product. These are shown regardless of receipt-history period because they are still open.</div></div></div>
  <div class="detail-table"><table><thead><tr><th>Supplier</th><th>PO Date</th><th>PO Number</th><th>Release</th><th>PO Owner</th><th>Product Number</th><th>Open Qty</th><th>UOM</th><th>Open LBs</th><th>Cost/LB</th><th>Open Value</th></tr></thead><tbody>
  <?php if(!$detailOpen):?><tr><td colspan="11" style="text-align:center;padding:24px;color:#68798a">No current open purchase orders were found for this product.</td></tr><?php endif;?>
  <?php foreach($detailOpen as $d):?><tr><td><?=iad_h($d['supplier']?:'-')?></td><td><?=iad_h(iad_date($d['po_date']))?></td><td><strong><?=iad_h($d['po']?:'-')?></strong></td><td><?=iad_h($d['release']?:'-')?></td><td><?=iad_h($d['owner']?:'-')?></td><td><?=iad_h($d['product_no']?:'-')?></td><td class="r"><?=iad_num($d['qty'],2)?></td><td><?=iad_h($d['uom']?:'-')?></td><td class="r"><?=iad_num($d['lbs'])?></td><td class="r"><?=iad_money($d['cost_lb'],4)?></td><td class="r"><?=iad_money($d['total_cost'],2)?></td></tr><?php endforeach;?>
  </tbody></table></div>
</div>
<?php else: ?>
<div class="cards"><div class="card"><div class="n"><?=$now?></div><div class="l">Order Now</div></div><div class="card"><div class="n"><?=$soon?></div><div class="l">Order Soon</div></div><div class="card"><div class="n"><?=ld_n($sumRec)?> lb</div><div class="l">Recommended Buy</div></div><div class="card"><div class="n"><?=$neg?></div><div class="l">Negative Available</div></div></div>

<section class="panel"><form class="iad-filters" method="get">
  <div class="field"><label>Products</label><details class="iad-multi" id="productMulti"><summary id="productSummary"><?=$productFilter?iad_num(count($productFilter)).' product'.(count($productFilter)===1?'':'s').' selected':'All products'?></summary><div class="iad-menu"><div class="iad-tools"><input type="text" id="productSearch" placeholder="Type any part of product name"><button class="iad-mini" type="button" id="selectProducts">Select visible</button><button class="iad-mini" type="button" id="clearProducts">Clear</button></div><div class="iad-list" id="productList"><?php foreach($products as $p):?><label class="iad-opt" data-search="<?=iad_h(strtolower($p))?>"><input type="checkbox" name="products[]" value="<?=iad_h($p)?>" <?=isset($productLookup[$p])?'checked':''?>><span><?=iad_h($p)?></span></label><?php endforeach;?></div><div class="iad-note">Leave all unchecked to include every product.</div></div></details></div>
  <div class="field"><label>Suppliers</label><details class="iad-multi" id="supplierMulti"><summary id="supplierSummary"><?=$supplierFilter?iad_num(count($supplierFilter)).' supplier'.(count($supplierFilter)===1?'':'s').' selected':'All suppliers'?></summary><div class="iad-menu"><div class="iad-tools"><input type="text" id="supplierSearch" placeholder="Type any part of supplier name"><button class="iad-mini" type="button" id="selectSuppliers">Select visible</button><button class="iad-mini" type="button" id="clearSuppliers">Clear</button></div><div class="iad-list" id="supplierList"><?php foreach($suppliers as $s):?><label class="iad-opt" data-search="<?=iad_h(strtolower($s))?>"><input type="checkbox" name="suppliers[]" value="<?=iad_h($s)?>" <?=isset($supplierLookup[$s])?'checked':''?>><span><?=iad_h($s)?></span></label><?php endforeach;?></div><div class="iad-note">Supplier filtering uses purchase history in the selected period plus current open POs.</div></div></details></div>
  <div class="field"><label>View</label><select name="view"><?php foreach(['action'=>'Action items','all'=>'All products','now'=>'Order now','soon'=>'Order soon','plan'=>'Plan order','covered'=>'Covered','negative'=>'Negative available','expiry'=>'Expiry attention'] as $k=>$v):?><option value="<?=$k?>" <?=$view===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
  <div class="field"><label>PO Detail Period</label><select name="period"><option value="ytd" <?=$period==='ytd'?'selected':''?>>YTD</option><option value="12m" <?=$period==='12m'?'selected':''?>>Last 12 months</option><option value="13m" <?=$period==='13m'?'selected':''?>>Last 13 months</option><option value="all" <?=$period==='all'?'selected':''?>>All history</option></select></div>
  <div class="field"><label>Sort</label><select name="sort"><option value="recommended" <?=$sort==='recommended'?'selected':''?>>Recommended buy</option><option value="days" <?=$sort==='days'?'selected':''?>>Days supply</option><option value="available" <?=$sort==='available'?'selected':''?>>Available</option><option value="value" <?=$sort==='value'?'selected':''?>>Inventory value</option><option value="product" <?=$sort==='product'?'selected':''?>>Product</option></select></div>
  <div class="iad-actions"><button class="btn" type="submit">Run</button><a class="btn alt" href="inventory-dashboard.php">Reset</a><a class="btn green" href="?<?=ld_h(http_build_query(array_merge($_GET,['download'=>'csv'])))?>">Download CSV</a></div>
</form></section>

<div class="tablewrap"><table><thead><tr><th class="left">Action</th><th class="left">Product #</th><th class="left prod">Product</th><th>Recommended LB</th><th>Days Supply</th><th>On Hand</th><th>Allocated / Open SO</th><th>Available</th><th>Open PO</th><th>Projected Available</th><th>Forecast 30D</th><th>Reorder Point</th><th>Target Supply</th><th>MIN</th><th>MAX</th><th>Lead Days</th><th>Inventory Value</th><th>Expiry Attention LB</th><th class="left">Primary Supplier</th><th class="left">Top Customer</th><th>Detail</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="21" style="text-align:center;padding:28px">No products match the selected filters.</td></tr><?php endif;?>
<?php foreach($rows as $r):$a=$r['action'];$cl=$a==='ORDER NOW'?'now':($a==='ORDER SOON'?'soon':($a==='PLAN ORDER'?'plan':'ok'));$expiry=$r['expired_lbs']+$r['expiring_90_lbs'];?><tr><td class="left action <?=$cl?>"><?=ld_h($a)?></td><td class="left"><?=ld_h($r['product_code'])?></td><td class="left prod"><?=ld_h($r['product_name'])?></td><td><?=ld_n($r['recommended_order_lbs'])?></td><td><?=$r['days_supply_after_open_so']===null?'-':ld_n($r['days_supply_after_open_so'],1)?></td><td><?=ld_n($r['on_hand_lbs'])?></td><td><?=ld_n($r['allocated_lbs'])?></td><td class="<?=$r['available_lbs']<0?'neg':''?>"><?=ld_n($r['available_lbs'])?></td><td><?=ld_n($r['open_purchase_orders_lbs'])?></td><td class="<?=$r['projected_available_lbs']<0?'neg':''?>"><?=ld_n($r['projected_available_lbs'])?></td><td><?=ld_n($r['forecast_next_30_days_lbs'])?></td><td><?=ld_n($r['reorder_point_lbs'])?></td><td><?=ld_n($r['target_supply_lbs'])?></td><td><?=$r['min_units']>0?ld_n($r['min_units']):'-'?></td><td><?=$r['max_units']>0?ld_n($r['max_units']):'-'?></td><td><?=$r['lead_time_days']>0?ld_n($r['lead_time_days']):'-'?></td><td><?=ld_money($r['inventory_value'])?></td><td class="<?=$expiry>0?'neg':''?>"><?=$expiry>0?ld_n($expiry):'-'?></td><td class="left"><?=ld_h($r['primary_supplier'])?></td><td class="left"><?=ld_h($r['top_customer'])?></td><td><a class="detail-btn" target="_blank" rel="noopener" href="<?=iad_h(iad_qs(['detail'=>'po','detail_product_code'=>$r['product_code'],'detail_product_name'=>$r['product_name'],'download'=>null]))?>">Detail</a></td></tr><?php endforeach;?></tbody></table></div>
<div class="foot"><?=count($rows)?> products shown. On Hand is the sum of Inventory Total LBs by Product Number. Allocated equals open sales-order pounds. Available = On Hand - Open SO. Projected Available adds open purchase orders. MIN/MAX are shown in inventory units as stored in the workbook; when populated they are converted to pounds using LB per Unit for purchasing policy. Lead time is used when populated; otherwise the model falls back to the 60-day demand plus 15-day safety-stock framework. PO Detail Period: <?=iad_h(iad_date($periodStart))?> through <?=iad_h(iad_date($periodEnd))?>.</div>
<script>
function iadSetupMulti(listId,searchId,summaryId,selectId,clearId,singular){
 const list=document.getElementById(listId),search=document.getElementById(searchId),summary=document.getElementById(summaryId),selectBtn=document.getElementById(selectId),clearBtn=document.getElementById(clearId);
 if(!list||!search||!summary)return;
 const opts=[...list.querySelectorAll('.iad-opt')];
 const checks=()=>[...list.querySelectorAll('input[type="checkbox"]')];
 const normalize=v=>(v||'').toString().toLowerCase().replace(/[^a-z0-9]+/g,' ').trim();
 const update=()=>{const n=checks().filter(c=>c.checked).length;summary.textContent=n?n+' '+singular+(n===1?'':'s')+' selected':'All '+singular+'s';};
 const apply=()=>{const terms=normalize(search.value).split(/\s+/).filter(Boolean);opts.forEach(o=>{const hay=normalize(o.dataset.search||o.textContent);o.style.display=!terms.length||terms.every(t=>hay.includes(t))?'flex':'none';});};
 search.addEventListener('input',apply); search.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();apply();}});
 list.addEventListener('change',update);
 selectBtn.addEventListener('click',()=>{apply();opts.filter(o=>o.style.display!=='none').forEach(o=>{const c=o.querySelector('input');if(c)c.checked=true;});update();});
 clearBtn.addEventListener('click',()=>{checks().forEach(c=>c.checked=false);update();});
 update();
}
iadSetupMulti('productList','productSearch','productSummary','selectProducts','clearProducts','product');
iadSetupMulti('supplierList','supplierSearch','supplierSummary','selectSuppliers','clearSuppliers','supplier');
</script>
<?php endif; ?>
<?php ld_foot(); ?>
