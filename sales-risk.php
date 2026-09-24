<?php
require_once __DIR__.'/lowe-dashboard-common.php';

function sr_h($v){ return ld_h($v); }
function sr_d($v){ $t=strtotime((string)$v); return $t?date('M j, Y',$t):'-'; }
function sr_field(array $r,array $names,$default=''){ foreach($names as $n){ if(array_key_exists($n,$r) && $r[$n]!=='' && $r[$n]!==null) return $r[$n]; } return $default; }
function sr_qs(array $over=[]){ $q=array_merge($_GET,$over); foreach($q as $k=>$v){ if($v===''||$v===null||$v===[]) unset($q[$k]); } return '?'.http_build_query($q); }

try { $inv=ld_rows('Invoices'); }
catch(Throwable $e){ die(ld_h($e->getMessage())); }

$latest=null;
foreach($inv as $r){
    $type=strtolower(trim((string)($r['Doc Type']??'')));
    $d=of_date($r['INV. Date']??'');
    if($type==='invoiced' && $d && ($latest===null || $d>$latest)) $latest=$d;
}
if(!$latest) die('No invoice history found.');

$cut=new DateTimeImmutable($latest);
$cy=(int)$cut->format('Y');
$py=$cy-1;
$ys="$cy-01-01";
$ye=$latest;
$pys="$py-01-01";
$pye=$cut->modify('-1 year')->format('Y-m-d');

$g=[];
$customerNames=[];
$productNames=[];
$reps=[];

foreach($inv as $r){
    $d=of_date($r['INV. Date']??'');
    $t=strtolower(trim((string)($r['Doc Type']??'')));
    if(!$d || !in_array($t,['invoiced','credit'],true)) continue;

    $period=$d>=$ys&&$d<=$ye?'ytd':($d>=$pys&&$d<=$pye?'pytd':null);
    $cust=trim((string)($r['Cust Name']??''));
    $cc=trim((string)($r['Cust#']??''));
    $prod=trim((string)($r['Product Name']??''));
    $rep=trim((string)($r['REP']??''));
    if($cust==='' || $prod==='') continue;

    $customerNames[$cust]=true;
    $productNames[$prod]=true;
    if($rep!=='') $reps[$rep]=true;

    $k=ld_key($cc!==''?$cc:$cust).'|'.ld_key($prod);
    if(!isset($g[$k])){
        $g[$k]=[
            'customer'=>$cust,
            'customer_code'=>$cc,
            'product'=>$prod,
            'rep'=>$rep,
            'ytd_lbs'=>0.0,
            'pytd_lbs'=>0.0,
            'ytd_sales'=>0.0,
            'pytd_sales'=>0.0,
            'last_invoice'=>null
        ];
    }
    if($period){
        $g[$k][$period.'_lbs']+=(float)($r['LBS']??0);
        $g[$k][$period.'_sales']+=(float)($r['Sales $$']??0);
    }
    if($t==='invoiced' && ($g[$k]['last_invoice']===null || $d>$g[$k]['last_invoice'])) $g[$k]['last_invoice']=$d;
    if($rep!=='') $g[$k]['rep']=$rep;
}

$customers=array_keys($customerNames); sort($customers,SORT_NATURAL|SORT_FLAG_CASE);
$products=array_keys($productNames); sort($products,SORT_NATURAL|SORT_FLAG_CASE);
$repList=array_keys($reps); sort($repList,SORT_NATURAL|SORT_FLAG_CASE);

$customerFilter=$_GET['customers']??[];
if(!is_array($customerFilter)) $customerFilter=[$customerFilter];
$customerFilter=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$customerFilter),fn($v)=>$v!=='')));
$customerLookup=array_fill_keys($customerFilter,true);

$productFilter=$_GET['products']??[];
if(!is_array($productFilter)) $productFilter=[$productFilter];
$productFilter=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$productFilter),fn($v)=>$v!=='')));
$productLookup=array_fill_keys($productFilter,true);

$view=$_GET['view']??'attention';
$repF=trim((string)($_GET['rep']??''));
$sort=$_GET['sort']??'salesvar';

$rows=[];
foreach($g as $r){
    if($r['ytd_lbs']==0 && $r['pytd_lbs']==0) continue;
    $r['var_lbs']=$r['ytd_lbs']-$r['pytd_lbs'];
    $r['var_sales']=$r['ytd_sales']-$r['pytd_sales'];
    $pct=$r['pytd_lbs']!=0?($r['var_lbs']/$r['pytd_lbs']):null;
    if($r['pytd_lbs']<=0 && $r['ytd_lbs']>0) $status='New';
    elseif($r['ytd_lbs']<=0 && $r['pytd_lbs']>0) $status='Lost / No YTD';
    elseif($pct!==null && $pct<=-.20) $status='Declining';
    elseif($pct!==null && $pct>=.20) $status='Growing';
    else $status='Stable';
    $r['status']=$status;
    $r['pct']=$pct;

    if($customerFilter && !isset($customerLookup[$r['customer']])) continue;
    if($productFilter && !isset($productLookup[$r['product']])) continue;
    if($repF!=='' && $r['rep']!==$repF) continue;
    if($view==='attention' && !in_array($status,['Lost / No YTD','Declining'],true)) continue;
    if($view==='lost' && $status!=='Lost / No YTD') continue;
    if($view==='declining' && $status!=='Declining') continue;
    if($view==='growing' && $status!=='Growing') continue;
    if($view==='new' && $status!=='New') continue;
    $rows[]=$r;
}

usort($rows,function($a,$b)use($sort){
    if($sort==='lbsvar') return $a['var_lbs']<=>$b['var_lbs'];
    if($sort==='customer') return strcasecmp($a['customer'],$b['customer']) ?: strcasecmp($a['product'],$b['product']);
    if($sort==='product') return strcasecmp($a['product'],$b['product']) ?: strcasecmp($a['customer'],$b['customer']);
    if($sort==='last') return strcmp((string)$a['last_invoice'],(string)$b['last_invoice']);
    return $a['var_sales']<=>$b['var_sales'];
});

$lost=count(array_filter($rows,fn($r)=>$r['status']==='Lost / No YTD'));
$decl=count(array_filter($rows,fn($r)=>$r['status']==='Declining'));
$negSales=array_sum(array_map(fn($r)=>min(0,$r['var_sales']),$rows));
$export=$rows;

// Detail mode: exact customer/product, both comparison periods.
$detail=(($_GET['detail']??'')==='1');
$detailCustomer=trim((string)($_GET['detail_customer']??''));
$detailCustomerCode=trim((string)($_GET['detail_customer_code']??''));
$detailProduct=trim((string)($_GET['detail_product']??''));
$detailRows=[];
$detailTotals=['ytd'=>['lbs'=>0.0,'sales'=>0.0,'profit'=>0.0,'invoices'=>[]],'pytd'=>['lbs'=>0.0,'sales'=>0.0,'profit'=>0.0,'invoices'=>[]]];

if($detail && $detailCustomer!=='' && $detailProduct!==''){
    foreach($inv as $r){
        $d=of_date($r['INV. Date']??'');
        $type=strtolower(trim((string)($r['Doc Type']??'')));
        if(!$d || !in_array($type,['invoiced','credit'],true)) continue;
        $period=$d>=$ys&&$d<=$ye?'ytd':($d>=$pys&&$d<=$pye?'pytd':null);
        if(!$period) continue;

        $cust=trim((string)($r['Cust Name']??''));
        $cc=trim((string)($r['Cust#']??''));
        $prod=trim((string)($r['Product Name']??''));
        $custMatch=$detailCustomerCode!=='' ? ($cc===$detailCustomerCode) : ($cust===$detailCustomer);
        if(!$custMatch || $prod!==$detailProduct) continue;

        $lbs=(float)($r['LBS']??0);
        $sales=(float)($r['Sales $$']??0);
        $profit=(float)($r['Profit $$']??0);
        $invoice=trim((string)sr_field($r,['INV#','Invoice #','Invoice Number'],''));
        $row=[
            'period'=>$period,
            'date'=>$d,
            'invoice'=>$invoice,
            'type'=>$type,
            'customer_po'=>trim((string)sr_field($r,['Cust PO#','Customer PO','Customer PO#'],'')),
            'customer_code'=>$cc,
            'product_number'=>trim((string)($r['Product Number']??'')),
            'lbs'=>$lbs,
            'sales'=>$sales,
            'price_lb'=>$lbs!=0?$sales/$lbs:0,
            'profit'=>$profit,
            'gp'=>$sales!=0?$profit/$sales:0,
            'rep'=>trim((string)($r['REP']??''))
        ];
        $detailRows[]=$row;
        $detailTotals[$period]['lbs']+=$lbs;
        $detailTotals[$period]['sales']+=$sales;
        $detailTotals[$period]['profit']+=$profit;
        if($invoice!=='') $detailTotals[$period]['invoices'][$invoice]=true;
    }
    usort($detailRows,fn($a,$b)=>strcmp($b['date'],$a['date']) ?: strcasecmp($b['invoice'],$a['invoice']));
}

ld_head('Sales Risk / Lost Business','Customer-product declines, lost volume, growth, and new business versus prior year-to-date');
?>
<style>
.sr-multi{position:relative}.sr-multi summary{list-style:none;width:100%;padding:9px 30px 9px 10px;border:1px solid #b7c3cf;border-radius:6px;background:#fff;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:relative}.sr-multi summary::-webkit-details-marker{display:none}.sr-multi summary:after{content:'▾';position:absolute;right:10px;color:#68798a}.sr-multi[open] summary{border-color:#1d5e91}.sr-menu{position:absolute;z-index:50;top:calc(100% + 5px);left:0;width:min(440px,92vw);background:#fff;border:1px solid #b7c3cf;border-radius:7px;box-shadow:0 10px 24px rgba(6,29,63,.18);padding:9px}.sr-tools{display:flex;gap:6px;margin-bottom:7px}.sr-tools input{flex:1;padding:8px 9px;border:1px solid #b7c3cf;border-radius:5px}.sr-mini{border:1px solid #d1dae4;background:#f7f9fb;color:#061d3f;border-radius:5px;padding:7px 9px;font-weight:700;cursor:pointer}.sr-list{max-height:280px;overflow:auto;border-top:1px solid #e5eaf0;padding-top:5px}.sr-opt{display:flex;align-items:flex-start;gap:8px;padding:6px 4px;font-size:12px;line-height:1.3}.sr-opt:hover{background:#f7f9fb}.sr-opt input{width:auto;margin-top:2px}.sr-note{margin-top:6px;color:#68798a;font-size:10.5px}.sr-detail{background:#061d3f!important;color:#fff!important;text-decoration:none;border-radius:6px;padding:6px 9px;font-weight:800;font-size:11px;display:inline-block}.sr-detail-panel{background:#fff;border:1px solid #d1dae4;border-radius:8px;padding:12px;margin:0 0 14px}.sr-detail-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:10px}.sr-detail-head h2{margin:0;color:#061d3f}.sr-detail-head p{margin:4px 0 0;color:#68798a;font-size:11px}.sr-period{font-weight:800}.sr-ytd{color:#0b5f9e}.sr-pytd{color:#7b4b00}
@media(max-width:900px){.filters{grid-template-columns:1fr 1fr!important}}@media(max-width:700px){.filters{grid-template-columns:1fr!important}.sr-menu{position:fixed;left:5vw;right:5vw;width:90vw;top:18vh;max-height:65vh}.sr-list{max-height:42vh}}
</style>

<?php if($detail && $detailCustomer!=='' && $detailProduct!==''): ?>
<div class="sr-detail-panel">
  <div class="sr-detail-head">
    <div><h2>Invoice Detail · <?=sr_h($detailCustomer)?></h2><p><?=sr_h($detailProduct)?> · <?=$cy?> YTD <?=sr_d($ys)?> through <?=sr_d($ye)?> · <?=$py?> PYTD <?=sr_d($pys)?> through <?=sr_d($pye)?></p></div>
    <a class="btn alt" href="sales-risk.php">Close Detail</a>
  </div>
  <div class="cards">
    <div class="card"><div class="n"><?=count($detailTotals['ytd']['invoices'])?></div><div class="l"><?=$cy?> YTD invoices</div></div>
    <div class="card"><div class="n"><?=ld_n($detailTotals['ytd']['lbs'])?> lb</div><div class="l"><?=$cy?> YTD volume</div></div>
    <div class="card"><div class="n"><?=ld_money($detailTotals['ytd']['sales'])?></div><div class="l"><?=$cy?> YTD sales</div></div>
    <div class="card"><div class="n"><?=ld_money($detailTotals['pytd']['sales'])?></div><div class="l"><?=$py?> PYTD sales</div></div>
  </div>
  <div class="tablewrap"><table><thead><tr><th class="left">Period</th><th>Invoice Date</th><th>Invoice #</th><th class="left">Type</th><th class="left">Customer PO</th><th class="left">Product #</th><th>Volume LB</th><th>Sales</th><th>Price/LB</th><th>Gross Profit</th><th>GP %</th><th class="left">Rep</th></tr></thead><tbody>
  <?php if(!$detailRows): ?><tr><td colspan="12" style="padding:28px;text-align:center">No invoice detail was found for the two comparison periods.</td></tr><?php endif; ?>
  <?php foreach($detailRows as $d): ?><tr>
    <td class="left sr-period <?=$d['period']==='ytd'?'sr-ytd':'sr-pytd'?>"><?=$d['period']==='ytd'?$cy.' YTD':$py.' PYTD'?></td>
    <td><?=sr_h(sr_d($d['date']))?></td><td><strong><?=sr_h($d['invoice']?:'-')?></strong></td><td class="left"><?=sr_h(ucfirst($d['type']))?></td><td class="left"><?=sr_h($d['customer_po']?:'-')?></td><td class="left"><?=sr_h($d['product_number']?:'-')?></td><td><?=ld_n($d['lbs'])?></td><td><?=ld_money($d['sales'])?></td><td><?=ld_money($d['price_lb'],4)?></td><td class="<?=$d['profit']<0?'neg':'pos'?>"><?=ld_money($d['profit'])?></td><td><?=number_format($d['gp']*100,1)?>%</td><td class="left"><?=sr_h($d['rep'])?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php else: ?>

<div class="cards"><div class="card"><div class="n"><?=$lost?></div><div class="l">Lost / No YTD</div></div><div class="card"><div class="n"><?=$decl?></div><div class="l">Declining >20%</div></div><div class="card"><div class="n"><?=ld_money(abs($negSales))?></div><div class="l">Negative Sales Variance Shown</div></div><div class="card"><div class="n"><?=count($rows)?></div><div class="l">Rows Shown</div></div></div>

<section class="panel"><form class="filters" method="get">
  <div class="field"><label>Customers</label><details class="sr-multi"><summary id="customerSummary"><?=$customerFilter?count($customerFilter).' customer'.(count($customerFilter)===1?'':'s').' selected':'All customers'?></summary><div class="sr-menu"><div class="sr-tools"><input type="text" id="customerSearch" placeholder="Type any part of customer name"><button class="sr-mini" type="button" id="selectCustomers">Select visible</button><button class="sr-mini" type="button" id="clearCustomers">Clear</button></div><div class="sr-list" id="customerList"><?php foreach($customers as $c): ?><label class="sr-opt" data-search="<?=sr_h(strtolower($c))?>"><input type="checkbox" name="customers[]" value="<?=sr_h($c)?>" <?=isset($customerLookup[$c])?'checked':''?>><span><?=sr_h($c)?></span></label><?php endforeach; ?></div><div class="sr-note">Leave all unchecked to include every customer.</div></div></details></div>
  <div class="field"><label>Products</label><details class="sr-multi"><summary id="productSummary"><?=$productFilter?count($productFilter).' product'.(count($productFilter)===1?'':'s').' selected':'All products'?></summary><div class="sr-menu"><div class="sr-tools"><input type="text" id="productSearch" placeholder="Type any part of product name"><button class="sr-mini" type="button" id="selectProducts">Select visible</button><button class="sr-mini" type="button" id="clearProducts">Clear</button></div><div class="sr-list" id="productList"><?php foreach($products as $p): ?><label class="sr-opt" data-search="<?=sr_h(strtolower($p))?>"><input type="checkbox" name="products[]" value="<?=sr_h($p)?>" <?=isset($productLookup[$p])?'checked':''?>><span><?=sr_h($p)?></span></label><?php endforeach; ?></div><div class="sr-note">Leave all unchecked to include every product.</div></div></details></div>
  <div class="field"><label>Rep</label><select name="rep"><option value="">All reps</option><?php foreach($repList as $r):?><option value="<?=sr_h($r)?>" <?=$repF===$r?'selected':''?>><?=sr_h($r)?></option><?php endforeach;?></select></div>
  <div class="field"><label>View</label><select name="view"><option value="attention" <?=$view==='attention'?'selected':''?>>Needs attention</option><option value="all" <?=$view==='all'?'selected':''?>>All</option><option value="lost" <?=$view==='lost'?'selected':''?>>Lost / no YTD</option><option value="declining" <?=$view==='declining'?'selected':''?>>Declining</option><option value="growing" <?=$view==='growing'?'selected':''?>>Growing</option><option value="new" <?=$view==='new'?'selected':''?>>New</option></select></div>
  <div class="field"><label>Sort</label><select name="sort"><option value="salesvar" <?=$sort==='salesvar'?'selected':''?>>Worst sales variance</option><option value="lbsvar" <?=$sort==='lbsvar'?'selected':''?>>Worst volume variance</option><option value="last" <?=$sort==='last'?'selected':''?>>Oldest last invoice</option><option value="customer" <?=$sort==='customer'?'selected':''?>>Customer</option><option value="product" <?=$sort==='product'?'selected':''?>>Product</option></select></div>
  <button class="btn" type="submit">Run</button><a class="btn alt" href="sales-risk.php">Reset</a><button type="button" class="btn green" id="xlsxBtn">Download .xlsx</button>
</form></section>

<div class="tablewrap"><table><thead><tr><th class="left">Status</th><th class="left">Customer</th><th class="left prod">Product</th><th class="left">Rep</th><th><?=$cy?> YTD LB</th><th><?=$py?> PYTD LB</th><th>Variance LB</th><th>Variance %</th><th><?=$cy?> YTD Sales</th><th><?=$py?> PYTD Sales</th><th>Sales Variance</th><th class="left">Last Invoice</th><th>Detail</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td class="left action <?=in_array($r['status'],['Lost / No YTD','Declining'])?'now':($r['status']==='Growing'?'ok':'')?>"><?=sr_h($r['status'])?></td><td class="left"><?=sr_h($r['customer'])?></td><td class="left prod"><?=sr_h($r['product'])?></td><td class="left"><?=sr_h($r['rep'])?></td><td><?=ld_n($r['ytd_lbs'])?></td><td><?=ld_n($r['pytd_lbs'])?></td><td class="<?=$r['var_lbs']<0?'neg':'pos'?>"><?=ld_n($r['var_lbs'])?></td><td><?=$r['pct']===null?'-':number_format($r['pct']*100,1).'%'?></td><td><?=ld_money($r['ytd_sales'])?></td><td><?=ld_money($r['pytd_sales'])?></td><td class="<?=$r['var_sales']<0?'neg':'pos'?>"><?=ld_money($r['var_sales'])?></td><td class="left"><?=sr_d($r['last_invoice'])?></td><td><a class="sr-detail" target="_blank" rel="noopener" href="<?=sr_h(sr_qs(['detail'=>'1','detail_customer'=>$r['customer'],'detail_customer_code'=>$r['customer_code'],'detail_product'=>$r['product']]))?>">Detail</a></td></tr><?php endforeach;?>
</tbody></table></div>
<div class="foot">YTD through <?=sr_d($ye)?> compared with PYTD through <?=sr_d($pye)?>. Declining means volume is down at least 20%. Lost / No YTD means the customer bought the product in PYTD but has no net YTD volume.</div>

<script>
function srSetupMulti(listId,searchId,summaryId,selectId,clearId,singular){
 const list=document.getElementById(listId),search=document.getElementById(searchId),summary=document.getElementById(summaryId),selectBtn=document.getElementById(selectId),clearBtn=document.getElementById(clearId);if(!list||!search||!summary)return;
 const opts=[...list.querySelectorAll('.sr-opt')],checks=()=>[...list.querySelectorAll('input[type="checkbox"]')];
 const norm=v=>(v||'').toString().toLowerCase().replace(/[^a-z0-9]+/g,' ').trim();
 const update=()=>{const n=checks().filter(c=>c.checked).length;summary.textContent=n?n+' '+singular+(n===1?'':'s')+' selected':'All '+singular+'s';};
 const apply=()=>{const terms=norm(search.value).split(/\s+/).filter(Boolean);opts.forEach(o=>{const hay=norm(o.dataset.search||o.textContent);o.style.display=!terms.length||terms.every(t=>hay.includes(t))?'flex':'none';});};
 search.addEventListener('input',apply);search.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();apply();}});list.addEventListener('change',update);
 selectBtn.addEventListener('click',()=>{apply();opts.filter(o=>o.style.display!=='none').forEach(o=>{const c=o.querySelector('input');if(c)c.checked=true;});update();});
 clearBtn.addEventListener('click',()=>{checks().forEach(c=>c.checked=false);update();});update();
}
srSetupMulti('customerList','customerSearch','customerSummary','selectCustomers','clearCustomers','customer');
srSetupMulti('productList','productSearch','productSummary','selectProducts','clearProducts','product');
</script>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>
const rows=<?=json_encode($export,JSON_UNESCAPED_UNICODE)?>;
document.getElementById('xlsxBtn').onclick=async()=>{if(!window.ExcelJS)return alert('Excel library could not load.');const wb=new ExcelJS.Workbook(),ws=wb.addWorksheet('Sales Risk');const h=['Status','Customer','Product','Rep','<?=$cy?> YTD LB','<?=$py?> PYTD LB','Variance LB','Variance %','<?=$cy?> YTD Sales','<?=$py?> PYTD Sales','Sales Variance','Last Invoice'];ws.addRow(h);rows.forEach(r=>ws.addRow([r.status,r.customer,r.product,r.rep,r.ytd_lbs,r.pytd_lbs,r.var_lbs,r.pct,r.ytd_sales,r.pytd_sales,r.var_sales,r.last_invoice]));ws.getRow(1).font={bold:true,color:{argb:'FFFFFFFF'}};ws.getRow(1).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF061D3F'}};ws.views=[{state:'frozen',ySplit:1,xSplit:2}];ws.autoFilter={from:'A1',to:'L1'};[18,28,38,20,13,13,13,12,14,14,14,14].forEach((w,i)=>ws.getColumn(i+1).width=w);for(let r=2;r<=ws.rowCount;r++){[5,6,7].forEach(c=>ws.getCell(r,c).numFmt='#,##0;[Red](#,##0);-');ws.getCell(r,8).numFmt='0.0%';[9,10,11].forEach(c=>ws.getCell(r,c).numFmt='$#,##0;[Red]($#,##0);-');}const b=await wb.xlsx.writeBuffer(),a=document.createElement('a');a.href=URL.createObjectURL(new Blob([b]));a.download='Lowe_Sales_Risk_<?=date('Y-m-d')?>.xlsx';a.click();URL.revokeObjectURL(a.href);};
</script>
<?php endif; ?>
<?php ld_foot(); ?>
