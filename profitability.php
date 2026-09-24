<?php
require_once __DIR__.'/lowe-dashboard-common.php';

function pf_h($v){ return ld_h($v); }
function pf_date($v){ $t=strtotime((string)$v); return $t?date('M j, Y',$t):'-'; }
function pf_contains($h,$n){ if($n==='') return true; return function_exists('mb_stripos') ? mb_stripos((string)$h,(string)$n)!==false : stripos((string)$h,(string)$n)!==false; }
function pf_qs(array $over=[]){ $q=array_merge($_GET,$over); foreach($q as $k=>$v){ if($v===''||$v===null||(is_array($v)&&!$v)) unset($q[$k]); } return '?'.http_build_query($q); }

try { $inv=ld_rows('Invoices'); }
catch(Throwable $e){ die(ld_h($e->getMessage())); }

$latest=null;
foreach($inv as $r){
    $t=strtolower(trim((string)($r['Doc Type']??'')));
    $d=of_date($r['INV. Date']??'');
    if($t==='invoiced' && $d && ($latest===null || $d>$latest)) $latest=$d;
}
if(!$latest) die('No invoice history found.');

$period=$_GET['period']??'ytd';
$end=$latest;
if($period==='12m') $start=date('Y-m-d',strtotime($end.' -11 months -'.(date('j',strtotime($end))-1).' days'));
elseif($period==='13m') $start=date('Y-m-d',strtotime($end.' -12 months -'.(date('j',strtotime($end))-1).' days'));
elseif($period==='all') $start='1900-01-01';
else { $period='ytd'; $start=date('Y',strtotime($end)).'-01-01'; }

$customerFilter=$_GET['customers']??[];
if(!is_array($customerFilter)) $customerFilter=[$customerFilter];
$customerFilter=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$customerFilter),fn($v)=>$v!=='')));
$customerLookup=array_fill_keys($customerFilter,true);

$productFilter=$_GET['products']??[];
if(!is_array($productFilter)) $productFilter=[$productFilter];
$productFilter=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$productFilter),fn($v)=>$v!=='')));
$productLookup=array_fill_keys($productFilter,true);

$rep=trim((string)($_GET['rep']??''));
$sort=$_GET['sort']??'profit';
if(!in_array($sort,['profit','sales','volume','gp','customer','product'],true)) $sort='profit';

$detailCustomer=trim((string)($_GET['detail_customer']??''));
$detailProduct=trim((string)($_GET['detail_product']??''));
$detailMode=($detailCustomer!=='' && $detailProduct!=='');

$groups=[]; $customers=[]; $products=[]; $reps=[]; $detailRows=[];
foreach($inv as $r){
    $d=of_date($r['INV. Date']??'');
    $type=strtolower(trim((string)($r['Doc Type']??'')));
    if(!$d || $d<$start || $d>$end || !in_array($type,['invoiced','credit'],true)) continue;

    $cust=trim((string)($r['Cust Name']??''));
    $cc=trim((string)($r['Cust#']??''));
    $prod=trim((string)($r['Product Name']??''));
    $rp=trim((string)($r['REP']??''));
    if($cust==='' || $prod==='') continue;

    $customers[$cust]=true; $products[$prod]=true; if($rp!=='') $reps[$rp]=true;

    $k=ld_key($cc!==''?$cc:$cust).'|'.ld_key($prod);
    if(!isset($groups[$k])) $groups[$k]=[
        'customer'=>$cust,'customer_code'=>$cc,'product'=>$prod,'rep'=>$rp,
        'lbs'=>0.0,'sales'=>0.0,'profit'=>0.0,'invoice_lines'=>0,'invoices'=>[]
    ];
    $lbs=(float)($r['LBS']??0); $sales=(float)($r['Sales $$']??0); $profit=(float)($r['Profit $$']??0);
    $groups[$k]['lbs'] += $lbs;
    $groups[$k]['sales'] += $sales;
    $groups[$k]['profit'] += $profit;
    $groups[$k]['invoice_lines']++;
    $invNo=trim((string)($r['INV#']??''));
    if($invNo!=='') $groups[$k]['invoices'][$invNo]=true;
    if($rp!=='') $groups[$k]['rep']=$rp;

    if($detailMode && $cust===$detailCustomer && $prod===$detailProduct){
        $detailRows[]=[
            'date'=>$d,
            'invoice'=>$invNo,
            'type'=>$type,
            'customer_po'=>trim((string)($r['Cust PO#']??'')),
            'customer_code'=>$cc,
            'product_number'=>trim((string)($r['Product Number']??'')),
            'product'=>$prod,
            'lbs'=>$lbs,
            'sales'=>$sales,
            'price_per_lb'=>$lbs!=0?$sales/$lbs:0,
            'profit'=>$profit,
            'gp'=>$sales!=0?$profit/$sales:0,
            'rep'=>$rp,
        ];
    }
}

$customers=array_keys($customers); sort($customers,SORT_NATURAL|SORT_FLAG_CASE);
$products=array_keys($products); sort($products,SORT_NATURAL|SORT_FLAG_CASE);
$reps=array_keys($reps); sort($reps,SORT_NATURAL|SORT_FLAG_CASE);

$rows=[];
foreach($groups as $g){
    if($customerFilter && !isset($customerLookup[$g['customer']])) continue;
    if($productFilter && !isset($productLookup[$g['product']])) continue;
    if($rep!=='' && $g['rep']!==$rep) continue;
    $g['gp']=$g['sales']?($g['profit']/$g['sales']):0;
    $g['profit_per_lb']=$g['lbs']?($g['profit']/$g['lbs']):0;
    $g['sales_per_lb']=$g['lbs']?($g['sales']/$g['lbs']):0;
    $g['invoice_count']=count($g['invoices']);
    $rows[]=$g;
}

usort($rows,function($a,$b)use($sort){
    if($sort==='sales') return $b['sales']<=>$a['sales'];
    if($sort==='volume') return $b['lbs']<=>$a['lbs'];
    if($sort==='gp') return $b['gp']<=>$a['gp'];
    if($sort==='product') return strcasecmp($a['product'],$b['product']) ?: strcasecmp($a['customer'],$b['customer']);
    if($sort==='customer') return strcasecmp($a['customer'],$b['customer']) ?: strcasecmp($a['product'],$b['product']);
    return $b['profit']<=>$a['profit'];
});

$sumSales=array_sum(array_column($rows,'sales'));
$sumProfit=array_sum(array_column($rows,'profit'));
$sumLbs=array_sum(array_column($rows,'lbs'));
$gp=$sumSales?$sumProfit/$sumSales:0;
$export=$rows;

if($detailMode){
    usort($detailRows,fn($a,$b)=>strcmp($b['date'],$a['date']) ?: strcasecmp($b['invoice'],$a['invoice']));
    $detailSales=array_sum(array_column($detailRows,'sales'));
    $detailProfit=array_sum(array_column($detailRows,'profit'));
    $detailLbs=array_sum(array_column($detailRows,'lbs'));
    $detailGp=$detailSales?$detailProfit/$detailSales:0;
    $detailInvoices=[]; foreach($detailRows as $x){ if($x['invoice']!=='') $detailInvoices[$x['invoice']]=true; }
}

ld_head('Customer / Product Profitability','Volume, sales, gross profit, GP %, and profit per pound by customer and product');
?>
<style>
.pf-filters{display:grid;grid-template-columns:minmax(250px,1.35fr) minmax(250px,1.35fr) 160px 155px 170px auto;gap:9px;align-items:end}
.pf-multi{position:relative}.pf-multi summary{list-style:none;width:100%;padding:9px 30px 9px 10px;border:1px solid #b7c3cf;border-radius:6px;background:#fff;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:relative}.pf-multi summary::-webkit-details-marker{display:none}.pf-multi summary:after{content:'▾';position:absolute;right:10px;color:#68798a}.pf-multi[open] summary{border-color:#1d5e91}.pf-menu{position:absolute;z-index:50;top:calc(100% + 5px);left:0;width:min(460px,92vw);background:#fff;border:1px solid #b7c3cf;border-radius:7px;box-shadow:0 10px 24px rgba(6,29,63,.18);padding:9px}.pf-tools{display:flex;gap:6px;margin-bottom:7px}.pf-tools input{flex:1;padding:8px 9px;border:1px solid #b7c3cf;border-radius:5px}.pf-mini{border:1px solid #d1dae4;background:#f7f9fb;color:#061d3f;border-radius:5px;padding:7px 9px;font-weight:700;cursor:pointer}.pf-list{max-height:300px;overflow:auto;border-top:1px solid #e5eaf0;padding-top:5px}.pf-opt{display:flex;align-items:flex-start;gap:8px;padding:6px 4px;font-size:12px;line-height:1.3}.pf-opt:hover{background:#f7f9fb}.pf-opt input{width:auto;margin-top:2px}.pf-note{margin-top:6px;color:#68798a;font-size:10.5px}.pf-actions{display:flex;gap:6px;flex-wrap:wrap}.detail-btn{background:#061d3f!important;color:#fff!important;padding:6px 9px!important;font-size:11px!important;white-space:nowrap}.detail-summary{display:grid;grid-template-columns:repeat(5,1fr);gap:9px;margin:12px 0}.detail-summary .card{min-height:0}.detail-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}.detail-head h2{margin:0;color:#061d3f;font-size:19px}.detail-head p{margin:4px 0 0;color:#68798a;font-size:11px}
@media(max-width:1050px){.pf-filters{grid-template-columns:1fr 1fr 1fr}.detail-summary{grid-template-columns:1fr 1fr}}
@media(max-width:700px){.pf-filters{grid-template-columns:1fr}.pf-actions{display:grid;grid-template-columns:1fr 1fr}.detail-summary{grid-template-columns:1fr 1fr}}
</style>

<?php if(!$detailMode): ?>
<div class="cards">
  <div class="card"><div class="n"><?=ld_money($sumSales)?></div><div class="l">Sales</div></div>
  <div class="card"><div class="n"><?=ld_money($sumProfit)?></div><div class="l">Gross Profit</div></div>
  <div class="card"><div class="n"><?=number_format($gp*100,1)?>%</div><div class="l">Gross Margin</div></div>
  <div class="card"><div class="n"><?=ld_n($sumLbs)?> lb</div><div class="l">Volume</div></div>
</div>

<section class="panel">
<form class="pf-filters" method="get">
  <div class="field"><label>Customers</label>
    <details class="pf-multi" id="customerMulti"><summary id="customerSummary"><?=$customerFilter ? pf_h(count($customerFilter)).' customer'.(count($customerFilter)===1?'':'s').' selected' : 'All customers'?></summary>
      <div class="pf-menu"><div class="pf-tools"><input type="text" id="customerSearch" placeholder="Type any part of customer name"><button class="pf-mini" type="button" id="selectCustomers">Select visible</button><button class="pf-mini" type="button" id="clearCustomers">Clear</button></div>
      <div class="pf-list" id="customerList"><?php foreach($customers as $c):?><label class="pf-opt" data-search="<?=pf_h(strtolower($c))?>"><input type="checkbox" name="customers[]" value="<?=pf_h($c)?>" <?=isset($customerLookup[$c])?'checked':''?>><span><?=pf_h($c)?></span></label><?php endforeach;?></div>
      <div class="pf-note">Search any part of the customer name. Leave all unchecked for all customers.</div></div>
    </details>
  </div>
  <div class="field"><label>Products</label>
    <details class="pf-multi" id="productMulti"><summary id="productSummary"><?=$productFilter ? pf_h(count($productFilter)).' product'.(count($productFilter)===1?'':'s').' selected' : 'All products'?></summary>
      <div class="pf-menu"><div class="pf-tools"><input type="text" id="productSearch" placeholder="Type any part of product name"><button class="pf-mini" type="button" id="selectProducts">Select visible</button><button class="pf-mini" type="button" id="clearProducts">Clear</button></div>
      <div class="pf-list" id="productList"><?php foreach($products as $p):?><label class="pf-opt" data-search="<?=pf_h(strtolower($p))?>"><input type="checkbox" name="products[]" value="<?=pf_h($p)?>" <?=isset($productLookup[$p])?'checked':''?>><span><?=pf_h($p)?></span></label><?php endforeach;?></div>
      <div class="pf-note">Search any part of the product name. Leave all unchecked for all products.</div></div>
    </details>
  </div>
  <div class="field"><label>Rep</label><select name="rep"><option value="">All reps</option><?php foreach($reps as $r):?><option value="<?=pf_h($r)?>" <?=$rep===$r?'selected':''?>><?=pf_h($r)?></option><?php endforeach;?></select></div>
  <div class="field"><label>Period</label><select name="period"><option value="ytd" <?=$period==='ytd'?'selected':''?>>YTD</option><option value="12m" <?=$period==='12m'?'selected':''?>>Last 12 months</option><option value="13m" <?=$period==='13m'?'selected':''?>>Last 13 months</option><option value="all" <?=$period==='all'?'selected':''?>>All data</option></select></div>
  <div class="field"><label>Sort</label><select name="sort"><option value="profit" <?=$sort==='profit'?'selected':''?>>Profit dollars</option><option value="sales" <?=$sort==='sales'?'selected':''?>>Sales dollars</option><option value="volume" <?=$sort==='volume'?'selected':''?>>Volume</option><option value="gp" <?=$sort==='gp'?'selected':''?>>GP %</option><option value="customer" <?=$sort==='customer'?'selected':''?>>Customer</option><option value="product" <?=$sort==='product'?'selected':''?>>Product</option></select></div>
  <div class="pf-actions"><button class="btn" type="submit">Run</button><a class="btn alt" href="profitability.php">Reset</a><button type="button" class="btn green" id="xlsxBtn">Download .xlsx</button></div>
</form>
</section>

<div class="tablewrap"><table><thead><tr><th class="left">Customer</th><th class="left prod">Product</th><th class="left">Rep</th><th>Invoices</th><th>Volume LB</th><th>Sales</th><th>Gross Profit</th><th>GP %</th><th>Sales/LB</th><th>Profit/LB</th><th>Detail</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="11" style="padding:28px;text-align:center;color:#68798a">No customer/product rows match the selected filters.</td></tr><?php endif;?>
<?php foreach($rows as $r):?><tr>
<td class="left"><?=pf_h($r['customer'])?></td><td class="left prod"><?=pf_h($r['product'])?></td><td class="left"><?=pf_h($r['rep'])?></td><td><?=ld_n($r['invoice_count'])?></td><td><?=ld_n($r['lbs'])?></td><td><?=ld_money($r['sales'])?></td><td class="<?=$r['profit']<0?'neg':'pos'?>"><?=ld_money($r['profit'])?></td><td><?=number_format($r['gp']*100,1)?>%</td><td><?=ld_money($r['sales_per_lb'],3)?></td><td><?=ld_money($r['profit_per_lb'],3)?></td>
<td><a class="btn detail-btn" target="_blank" rel="noopener" href="<?=pf_h(pf_qs(['detail_customer'=>$r['customer'],'detail_product'=>$r['product']]))?>">Detail</a></td>
</tr><?php endforeach;?></tbody></table></div>
<div class="foot">Period: <?=pf_date($start)?> through <?=pf_date($end)?>. Invoices and credits are netted together. Product codes are intentionally ignored so identical product descriptions are consolidated.</div>

<script>
function setupPfMulti(listId,searchId,summaryId,selectId,clearId,singular){
 const list=document.getElementById(listId),search=document.getElementById(searchId),summary=document.getElementById(summaryId),selectBtn=document.getElementById(selectId),clearBtn=document.getElementById(clearId);
 if(!list||!search||!summary)return;
 const opts=[...list.querySelectorAll('.pf-opt')]; const checks=()=>[...list.querySelectorAll('input[type="checkbox"]')];
 const norm=v=>(v||'').toString().toLowerCase().replace(/[^a-z0-9]+/g,' ').trim();
 const update=()=>{const n=checks().filter(c=>c.checked).length;summary.textContent=n?n+' '+singular+(n===1?'':'s')+' selected':'All '+singular+'s';};
 const apply=()=>{const terms=norm(search.value).split(/\s+/).filter(Boolean);opts.forEach(o=>{const hay=norm(o.dataset.search||o.textContent);o.style.display=(!terms.length||terms.every(t=>hay.includes(t)))?'flex':'none';});};
 search.addEventListener('input',apply); search.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();apply();}}); list.addEventListener('change',update);
 selectBtn.addEventListener('click',()=>{apply();opts.filter(o=>o.style.display!=='none').forEach(o=>{const c=o.querySelector('input');if(c)c.checked=true;});update();});
 clearBtn.addEventListener('click',()=>{checks().forEach(c=>c.checked=false);update();}); update();
}
setupPfMulti('customerList','customerSearch','customerSummary','selectCustomers','clearCustomers','customer');
setupPfMulti('productList','productSearch','productSummary','selectProducts','clearProducts','product');
</script>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>
const rows=<?=json_encode($export,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
document.getElementById('xlsxBtn').onclick=async()=>{if(!window.ExcelJS)return alert('Excel library could not load.');const wb=new ExcelJS.Workbook(),ws=wb.addWorksheet('Profitability');const h=['Customer','Product','Rep','Invoices','Volume LB','Sales','Gross Profit','GP %','Sales/LB','Profit/LB'];ws.addRow(h);rows.forEach(r=>ws.addRow([r.customer,r.product,r.rep,r.invoice_count,r.lbs,r.sales,r.profit,r.gp,r.sales_per_lb,r.profit_per_lb]));ws.getRow(1).font={bold:true,color:{argb:'FFFFFFFF'}};ws.getRow(1).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF061D3F'}};ws.views=[{state:'frozen',ySplit:1,xSplit:2}];ws.autoFilter={from:'A1',to:'J1'};[28,38,20,11,14,14,14,11,12,12].forEach((w,i)=>ws.getColumn(i+1).width=w);for(let r=2;r<=ws.rowCount;r++){ws.getCell(r,4).numFmt='0';ws.getCell(r,5).numFmt='#,##0';[6,7,9,10].forEach(c=>ws.getCell(r,c).numFmt='$#,##0.000;[Red]($#,##0.000);-');ws.getCell(r,8).numFmt='0.0%';}const b=await wb.xlsx.writeBuffer(),a=document.createElement('a');a.href=URL.createObjectURL(new Blob([b]));a.download='Lowe_Profitability_<?=date('Y-m-d')?>.xlsx';a.click();URL.revokeObjectURL(a.href);};
</script>

<?php else: ?>
<section class="panel">
<div class="detail-head"><div><h2>Invoice Detail · <?=pf_h($detailCustomer)?></h2><p><?=pf_h($detailProduct)?> · <?=pf_date($start)?> through <?=pf_date($end)?></p></div><a class="btn alt" href="profitability.php?period=<?=pf_h($period)?>">Close Detail</a></div>
<div class="detail-summary">
  <div class="card"><div class="n"><?=ld_n(count($detailInvoices))?></div><div class="l">Invoices</div></div>
  <div class="card"><div class="n"><?=ld_n($detailLbs)?> lb</div><div class="l">Volume</div></div>
  <div class="card"><div class="n"><?=ld_money($detailSales)?></div><div class="l">Sales</div></div>
  <div class="card"><div class="n"><?=ld_money($detailProfit)?></div><div class="l">Gross Profit</div></div>
  <div class="card"><div class="n"><?=number_format($detailGp*100,1)?>%</div><div class="l">Gross Margin</div></div>
</div>
<div class="tablewrap"><table><thead><tr><th>Invoice Date</th><th>Invoice #</th><th>Type</th><th>Customer PO</th><th>Customer #</th><th class="left prod">Product</th><th>Product #</th><th>Volume LB</th><th>Sales</th><th>Price/LB</th><th>Gross Profit</th><th>GP %</th><th class="left">Rep</th></tr></thead><tbody>
<?php if(!$detailRows):?><tr><td colspan="13" style="padding:28px;text-align:center;color:#68798a">No invoice detail was found for this customer/product in the selected period.</td></tr><?php endif;?>
<?php foreach($detailRows as $d):?><tr><td><?=pf_h(pf_date($d['date']))?></td><td><strong><?=pf_h($d['invoice']?:'-')?></strong></td><td><?=pf_h(ucfirst($d['type']))?></td><td><?=pf_h($d['customer_po']?:'-')?></td><td><?=pf_h($d['customer_code']?:'-')?></td><td class="left prod"><?=pf_h($d['product'])?></td><td><?=pf_h($d['product_number']?:'-')?></td><td><?=ld_n($d['lbs'])?></td><td><?=ld_money($d['sales'])?></td><td><?=ld_money($d['price_per_lb'],3)?></td><td class="<?=$d['profit']<0?'neg':'pos'?>"><?=ld_money($d['profit'])?></td><td><?=number_format($d['gp']*100,1)?>%</td><td class="left"><?=pf_h($d['rep'])?></td></tr><?php endforeach;?>
<?php if($detailRows):?><tr><td colspan="7"><strong>Selected Period Total</strong></td><td><strong><?=ld_n($detailLbs)?></strong></td><td><strong><?=ld_money($detailSales)?></strong></td><td></td><td><strong><?=ld_money($detailProfit)?></strong></td><td><strong><?=number_format($detailGp*100,1)?>%</strong></td><td></td></tr><?php endif;?>
</tbody></table></div>
</section>
<?php endif; ?>

<?php ld_foot(); ?>
