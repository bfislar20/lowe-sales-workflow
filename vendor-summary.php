<?php
/*
  Lowe Chemical Vendor Purchase Summary
  Rolling 1-13 month vendor/product purchase volume report.
  Uses the latest Lowe Master workbook saved by predictive-orders-admin.php.
*/

require_once __DIR__ . '/lowe-dashboard-common.php';

$masterFile = ld_master();\n\nfunction vs_esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function vs_num($v,$d=0){ return number_format((float)$v,$d); }
function vs_money($v){ return '$'.number_format((float)$v,0); }
function vs_contains($h,$n){ if($n==='') return true; return function_exists('mb_stripos') ? mb_stripos((string)$h,(string)$n)!==false : stripos((string)$h,(string)$n)!==false; }
function vs_month_key($date){ return date('Y-m', strtotime($date)); }
function vs_month_label($key){ return date('M Y', strtotime($key.'-01')); }
function vs_add_months($key,$delta){ return date('Y-m', strtotime($key.'-01 '.($delta>=0?'+':'').$delta.' months')); }
function vs_qs(array $over=[]){ $q=array_merge($_GET,$over); foreach($q as $k=>$v){ if($v===''||$v===null) unset($q[$k]); } return '?'.http_build_query($q); }
function vs_field(array $r,array $names,$default=''){ foreach($names as $n){ if(array_key_exists($n,$r) && $r[$n]!=='' && $r[$n]!==null) return $r[$n]; } return $default; }
function vs_total_cost(array $r): float { return of_num(vs_field($r,['Total Item Cost','Total Cost'],0)); }

$cacheFile = __DIR__ . '/vendor-summary-cache.json';
$cacheVersion = 2;
$sourceMtime = @filemtime($masterFile) ?: 0;
$cache = null;
if (is_file($cacheFile)) {
    $tmp = json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($tmp) && ($tmp['version']??0)===$cacheVersion && ($tmp['source_mtime']??0)===$sourceMtime) $cache=$tmp;
}

if (!$cache) {
    @set_time_limit(300);
    $rows = ld_rows('Purchases');
    of_require($rows, ['Supplier Name','Supplier Number','Receipt Date','Product Name','LBs Received'], 'Purchases');

    $latest = null;
    $groups = [];
    foreach ($rows as $r) {
        $date = of_date($r['Receipt Date'] ?? '');
        if (!$date) continue;
        $supplier = trim((string)($r['Supplier Name'] ?? ''));
        $supplierNo = trim((string)($r['Supplier Number'] ?? ''));
        $product = trim((string)($r['Product Name'] ?? ''));
        if ($supplier==='' || $product==='') continue;
        $lbs = of_num($r['LBs Received'] ?? 0);
        $cost = vs_total_cost($r);
        $month = vs_month_key($date);
        // Group intentionally by supplier + product description, not product code.
        $key = strtoupper($supplierNo.'|'.$supplier.'|'.$product);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'supplier'=>$supplier,
                'supplier_number'=>$supplierNo,
                'product'=>$product,
                'months'=>[],
                'spend_months'=>[],
                'last_receipt'=>$date
            ];
        }
        $groups[$key]['months'][$month] = ($groups[$key]['months'][$month] ?? 0) + $lbs;
        $groups[$key]['spend_months'][$month] = ($groups[$key]['spend_months'][$month] ?? 0) + $cost;
        if ($date > $groups[$key]['last_receipt']) $groups[$key]['last_receipt']=$date;
        if ($latest===null || $date>$latest) $latest=$date;
    }
    if (!$latest) throw new RuntimeException('No valid purchase receipt dates were found.');

    $latestMonth = vs_month_key($latest);
    $allMonths=[];
    for($i=0;$i<13;$i++) $allMonths[] = vs_add_months($latestMonth,-$i);

    $data=[];
    foreach($groups as $g){
        $has=false;
        foreach($allMonths as $m){ if(abs((float)($g['months'][$m]??0))>0.00001 || abs((float)($g['spend_months'][$m]??0))>0.00001){$has=true;break;} }
        if($has) $data[]=$g;
    }
    usort($data, fn($a,$b)=>strcasecmp($a['supplier'],$b['supplier']) ?: strcasecmp($a['product'],$b['product']));
    $cache = [
        'version'=>$cacheVersion,
        'source_mtime'=>$sourceMtime,
        'source_file'=>basename($masterFile),
        'latest_receipt_date'=>$latest,
        'latest_month'=>$latestMonth,
        'rows'=>$data
    ];
    @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
}

$rows = $cache['rows'];
$latest = $cache['latest_receipt_date'];
$latestMonth = $cache['latest_month'];

$monthsToShow = max(1,min(13,(int)($_GET['months'] ?? 13)));
$q = trim((string)($_GET['q'] ?? ''));
$supplierFilter = trim((string)($_GET['supplier'] ?? ''));
$productFilter = $_GET['products'] ?? [];
if (!is_array($productFilter)) $productFilter = [$productFilter];
$productFilter = array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v), $productFilter), fn($v)=>$v!=='')));
$productLookup = array_fill_keys($productFilter, true);
$minVol = max(0,(float)($_GET['minvol'] ?? 0));
$sort = $_GET['sort'] ?? 'supplier';
if(!in_array($sort,['supplier','volume','spend','product'],true)) $sort='supplier';
$page=max(1,(int)($_GET['page']??1));
$per=100;

$months=[];
for($i=0;$i<$monthsToShow;$i++) $months[] = vs_add_months($latestMonth,-$i);

$suppliers=[]; $products=[];
foreach($rows as $r){ $suppliers[$r['supplier']]=true; $products[$r['product']]=true; }
$suppliers=array_keys($suppliers); sort($suppliers,SORT_NATURAL|SORT_FLAG_CASE);
$products=array_keys($products); sort($products,SORT_NATURAL|SORT_FLAG_CASE);

$filtered=[];
foreach($rows as $r){
    if($q!=='' && !vs_contains($r['supplier'],$q) && !vs_contains($r['product'],$q) && !vs_contains($r['supplier_number'],$q)) continue;
    if($supplierFilter!=='' && $r['supplier']!==$supplierFilter) continue;
    if($productFilter && !isset($productLookup[$r['product']])) continue;
    $total=0.0; $spend=0.0;
    foreach($months as $m){ $total += (float)($r['months'][$m]??0); $spend += (float)($r['spend_months'][$m]??0); }
    if($total < $minVol) continue;
    $r['_total']=$total; $r['_spend']=$spend;
    $filtered[]=$r;
}

usort($filtered,function($a,$b)use($sort){
    if($sort==='volume'){ $c=$b['_total']<=>$a['_total']; if($c) return $c; }
    elseif($sort==='spend'){ $c=$b['_spend']<=>$a['_spend']; if($c) return $c; }
    elseif($sort==='product'){ $c=strcasecmp($a['product'],$b['product']); if($c) return $c; }
    $c=strcasecmp($a['supplier'],$b['supplier']);
    return $c ?: strcasecmp($a['product'],$b['product']);
});

$totalRows=count($filtered); $pages=max(1,(int)ceil($totalRows/$per)); $page=min($page,$pages);
$slice=array_slice($filtered,($page-1)*$per,$per);

$kpiLbs=0.0; $kpiSpend=0.0; $kpiSup=[];
foreach($filtered as $r){ $kpiLbs += $r['_total']; $kpiSpend += $r['_spend']; $kpiSup[$r['supplier']]=true; }
$selectedLabel = $monthsToShow===1 ? '1 month' : $monthsToShow.' months';

// Export data to browser-side ExcelJS. Full filtered set, not just visible page.
$exportRows=[];
foreach($filtered as $r){
    $x=['Supplier'=>$r['supplier'],'Product'=>$r['product']];
    foreach($months as $m) $x[vs_month_label($m)] = round((float)($r['months'][$m]??0),2);
    $x['Total Pounds']=round((float)$r['_total'],2);
    $x['Total Spend']=round((float)$r['_spend'],2);
    $exportRows[]=$x;
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vendor Purchase Summary | Lowe Chemical</title>
<style>
:root{--navy:#061d3f;--red:#c8102e;--bg:#eef1f5;--card:#fff;--line:#cfd8e3;--text:#1f2d3d;--muted:#66768a;--green:#15803d;--blue:#1d5e91;--orange:#b45309}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;font-size:14px}
a{color:var(--blue)}.header{background:var(--navy);color:#fff;border-bottom:4px solid var(--red)}.header .in{max-width:1600px;margin:auto;padding:15px 20px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:14px}.logo{width:180px;max-height:60px;object-fit:contain;background:#fff;border-radius:5px;padding:6px 10px}.brand h1{margin:0;font-size:22px}.brand p{margin:4px 0 0;color:#c9d6e6;font-size:12px}.meta{text-align:right;color:#d7e2ef;font-size:12px;line-height:1.5}.wrap{max-width:1600px;margin:18px auto;padding:0 16px}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}.kpi{background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px 14px;border-left:5px solid var(--blue)}.kpi:nth-child(2){border-left-color:var(--green)}.kpi:nth-child(3){border-left-color:var(--orange)}.kpi:nth-child(4){border-left-color:var(--red)}.kpi .v{font-size:23px;font-weight:800;color:var(--navy)}.kpi .l{font-size:11px;text-transform:uppercase;color:var(--muted);margin-top:3px}
.panel{background:#fff;border:1px solid var(--line);border-radius:8px;padding:13px;margin-bottom:14px}.filters{display:grid;grid-template-columns:minmax(210px,1.5fr) minmax(170px,1.1fr) minmax(210px,1.5fr) 105px 125px 140px auto;gap:9px;align-items:end}.field label{display:block;font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--muted);margin-bottom:4px}.field input,.field select{width:100%;padding:9px 10px;border:1px solid #b8c4d1;border-radius:6px;background:#fff}.btn{display:inline-block;border:0;border-radius:6px;background:var(--red);color:#fff;text-decoration:none;padding:9px 14px;font-weight:700;cursor:pointer;white-space:nowrap}.btn.alt{background:#56667a}.btn.green{background:var(--green)}.actions{display:flex;gap:6px;flex-wrap:wrap}.info{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px;flex-wrap:wrap}.info .title{font-weight:700;color:var(--navy);font-size:15px}.info .sub{font-size:12px;color:var(--muted);margin-top:2px}.multi{position:relative}.multi summary{list-style:none;width:100%;padding:9px 30px 9px 10px;border:1px solid #b8c4d1;border-radius:6px;background:#fff;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:relative}.multi summary::-webkit-details-marker{display:none}.multi summary:after{content:'▾';position:absolute;right:10px;color:var(--muted)}.multi[open] summary{border-color:var(--blue)}.multi-menu{position:absolute;z-index:30;top:calc(100% + 5px);left:0;width:min(430px,90vw);background:#fff;border:1px solid #b8c4d1;border-radius:7px;box-shadow:0 10px 24px rgba(6,29,63,.18);padding:9px}.multi-tools{display:flex;gap:6px;margin-bottom:7px}.multi-tools input{flex:1;padding:8px 9px}.mini-btn{border:1px solid var(--line);background:#f7f9fb;color:var(--navy);border-radius:5px;padding:7px 9px;font-weight:700;cursor:pointer}.product-list{max-height:260px;overflow:auto;border-top:1px solid #e5eaf0;padding-top:5px}.product-opt{display:flex;align-items:flex-start;gap:8px;padding:6px 4px;font-size:12px;line-height:1.3}.product-opt:hover{background:#f7f9fb}.product-opt input{width:auto;margin-top:2px}.selected-note{margin-top:6px;color:var(--muted);font-size:10.5px}
.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:7px;background:#fff}.report{border-collapse:collapse;width:max-content;min-width:0;background:#fff}.report th,.report td{border:1px solid #d4dce5;padding:7px 8px}.report th{position:sticky;top:0;z-index:3;background:#e8edf3;color:var(--navy);text-transform:uppercase;font-size:10.5px;letter-spacing:.02em;white-space:nowrap}.report td{font-size:12.5px}.report .supplier{width:190px;max-width:190px;white-space:normal;font-weight:700}.report .product{width:250px;max-width:250px;white-space:normal}.report .month{width:86px;min-width:86px;text-align:right;white-space:nowrap}.report .total{width:100px;min-width:100px;text-align:right;white-space:nowrap;font-weight:800;background:#f7f9fb}.report .spend{width:105px;min-width:105px;text-align:right;white-space:nowrap;font-weight:700}.report .current{background:#fff7dc}.report th.current{background:#f2d990}.report tbody tr:hover td{background:#f8fafc}.report tbody tr:hover td.current{background:#fff3c7}
.sticky1{position:sticky;left:0;z-index:2;background:#fff}.sticky2{position:sticky;left:190px;z-index:2;background:#fff}.report th.sticky1,.report th.sticky2{z-index:4;background:#e8edf3}.sm{font-size:11px;color:var(--muted)}.pager{display:flex;gap:6px;justify-content:center;align-items:center;margin-top:12px}.pager a,.pager span{padding:6px 10px;border:1px solid var(--line);border-radius:5px;text-decoration:none;background:#fff;color:var(--navy)}.pager .on{background:var(--navy);color:#fff}.mobile-cards{display:none}
.workflow-link{display:inline-block;margin-top:6px;padding:7px 10px;border-radius:6px;background:#fff;color:#061d3f!important;text-decoration:none;font-weight:800;font-size:12px;border:1px solid #d8e1eb}.workflow-link:hover,.workflow-link:focus{background:#eef4fa}
@media(max-width:1000px){.filters{grid-template-columns:1fr 1fr 1fr}.kpis{grid-template-columns:1fr 1fr}.meta{text-align:left}}
@media(max-width:700px){body{font-size:13px}.header .in{display:block}.brand{align-items:flex-start}.logo{width:150px}.brand h1{font-size:20px}.meta{margin-top:10px}.wrap{padding:0 9px;margin-top:10px}.kpis{grid-template-columns:1fr 1fr;gap:7px}.kpi{padding:10px}.kpi .v{font-size:19px}.filters{grid-template-columns:1fr}.actions{display:grid;grid-template-columns:1fr 1fr}.actions .btn{text-align:center}.table-wrap{display:none}.mobile-cards{display:block}.mcard{background:#fff;border:1px solid var(--line);border-radius:8px;margin-bottom:10px;overflow:hidden}.mhead{padding:10px 12px;background:#f0f4f8;border-bottom:1px solid var(--line)}.mhead strong{display:block;color:var(--navy);font-size:14px}.mprod{margin-top:3px;font-size:12px}.mmonths{display:grid;grid-template-columns:1fr 1fr}.mrow{display:flex;justify-content:space-between;gap:12px;padding:7px 10px;border-bottom:1px solid #e3e8ee}.mrow:nth-child(odd){border-right:1px solid #e3e8ee}.mrow.current{background:#fff7dc}.mlabel{color:var(--muted);font-size:11px}.mval{font-weight:700;text-align:right}.mtotal{display:grid;grid-template-columns:1fr 1fr;background:#f7f9fb}.mtotal .mrow{border-bottom:0}.info{align-items:flex-start}.btn{padding:10px 12px}}
</style></head><body>
<div class="header"><div class="in"><div class="brand"><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><div><h1>Vendor Purchase Summary</h1><p>Rolling vendor purchase volume by supplier and product description</p></div></div><div class="meta">Latest receipt: <strong><?=vs_esc(date('M j, Y',strtotime($latest)))?></strong><br>Source: <?=vs_esc($cache['source_file'])?><br><a class="workflow-link" href="salesworkflow.php">Back to Sales Workflow</a></div></div></div>
<div class="wrap">
<div class="kpis"><div class="kpi"><div class="v"><?=vs_num(count($kpiSup))?></div><div class="l">Suppliers in selected period</div></div><div class="kpi"><div class="v"><?=vs_num($totalRows)?></div><div class="l">Supplier / product pairs</div></div><div class="kpi"><div class="v"><?=vs_num($kpiLbs)?> lb</div><div class="l">Purchase volume · <?=$selectedLabel?></div></div><div class="kpi"><div class="v"><?=vs_money($kpiSpend)?></div><div class="l">Purchase spend · <?=$selectedLabel?></div></div></div>
<div class="panel"><form method="get" class="filters">
<div class="field"><label>Supplier / product</label><input type="text" name="q" value="<?=vs_esc($q)?>" placeholder="Search supplier or product"></div>
<div class="field"><label>Supplier</label><select name="supplier"><option value="">All suppliers</option><?php foreach($suppliers as $s):?><option value="<?=vs_esc($s)?>" <?=$supplierFilter===$s?'selected':''?>><?=vs_esc($s)?></option><?php endforeach;?></select></div>
<div class="field"><label>Products</label><details class="multi" id="productMulti"><summary id="productSummary"><?=$productFilter ? vs_num(count($productFilter)).' product'.(count($productFilter)===1?'':'s').' selected' : 'All products'?></summary><div class="multi-menu"><div class="multi-tools"><input type="text" id="productSearch" placeholder="Find a product"><button class="mini-btn" type="button" id="selectVisible">Select</button><button class="mini-btn" type="button" id="clearProducts">Clear</button></div><div class="product-list" id="productList"><?php foreach($products as $p):?><label class="product-opt" data-product="<?=vs_esc(strtolower($p))?>"><input type="checkbox" name="products[]" value="<?=vs_esc($p)?>" <?=isset($productLookup[$p])?'checked':''?>><span><?=vs_esc($p)?></span></label><?php endforeach;?></div><div class="selected-note">Choose any combination of products. Leave all unchecked to include every product.</div></div></details></div>
<div class="field"><label>Months to show</label><select name="months"><?php for($i=1;$i<=13;$i++):?><option value="<?=$i?>" <?=$monthsToShow===$i?'selected':''?>><?=$i?></option><?php endfor;?></select></div>
<div class="field"><label>Min total lb</label><input type="number" min="0" step="1" name="minvol" value="<?=vs_esc($minVol?:'')?>" placeholder="Any"></div>
<div class="field"><label>Sort by</label><select name="sort"><option value="supplier" <?=$sort==='supplier'?'selected':''?>>Supplier</option><option value="volume" <?=$sort==='volume'?'selected':''?>>Total pounds</option><option value="spend" <?=$sort==='spend'?'selected':''?>>Total spend</option><option value="product" <?=$sort==='product'?'selected':''?>>Product</option></select></div>
<div class="actions"><button class="btn" type="submit">Run</button><a class="btn alt" href="vendor-summary.php">Reset</a></div>
</form></div>
<div class="panel"><div class="info"><div><div class="title"><?=vs_num($totalRows)?> supplier/product rows · showing <?=$selectedLabel?></div><div class="sub">Monthly columns are pounds received. Product codes are intentionally ignored so the same product description is consolidated.</div></div><button class="btn green" type="button" id="xlsxBtn">Download .xlsx</button></div>
<div class="table-wrap"><table class="report"><thead><tr><th class="sticky1 supplier">Supplier</th><th class="sticky2 product">Product Description</th><?php foreach($months as $idx=>$m):?><th class="month <?=$idx===0?'current':''?>"><?=vs_esc(vs_month_label($m))?></th><?php endforeach;?><th class="total">Total Pounds</th><th class="spend">Total Spend</th></tr></thead><tbody><?php if(!$slice):?><tr><td colspan="<?=4+count($months)?>" style="padding:28px;text-align:center;color:#66768a">No purchases match these filters.</td></tr><?php endif;?><?php foreach($slice as $r):?><tr><td class="sticky1 supplier"><?=vs_esc($r['supplier'])?></td><td class="sticky2 product"><?=vs_esc($r['product'])?></td><?php foreach($months as $idx=>$m):$v=(float)($r['months'][$m]??0);?><td class="month <?=$idx===0?'current':''?>"><?=$v!=0?vs_num($v):'-'?></td><?php endforeach;?><td class="total"><?=vs_num($r['_total'])?></td><td class="spend"><?=vs_money($r['_spend'])?></td></tr><?php endforeach;?></tbody></table></div>
<div class="mobile-cards"><?php foreach($slice as $r):?><div class="mcard"><div class="mhead"><strong><?=vs_esc($r['supplier'])?></strong><div class="mprod"><?=vs_esc($r['product'])?></div></div><div class="mmonths"><?php foreach($months as $idx=>$m):?><div class="mrow <?=$idx===0?'current':''?>"><span class="mlabel"><?=vs_esc(vs_month_label($m))?></span><span class="mval"><?=vs_num((float)($r['months'][$m]??0))?> lb</span></div><?php endforeach;?></div><div class="mtotal"><div class="mrow"><span class="mlabel">Total Pounds</span><span class="mval"><?=vs_num($r['_total'])?> lb</span></div><div class="mrow"><span class="mlabel">Total Spend</span><span class="mval"><?=vs_money($r['_spend'])?></span></div></div></div><?php endforeach;?></div>
<?php if($pages>1):?><div class="pager"><?php if($page>1):?><a href="<?=vs_esc(vs_qs(['page'=>$page-1]))?>">Previous</a><?php endif;?><span class="on">Page <?=$page?> of <?=$pages?></span><?php if($page<$pages):?><a href="<?=vs_esc(vs_qs(['page'=>$page+1]))?>">Next</a><?php endif;?></div><?php endif;?></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>
const exportRows = <?=json_encode($exportRows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
const monthLabels = <?=json_encode(array_map('vs_month_label',$months),JSON_UNESCAPED_UNICODE)?>;
document.getElementById('xlsxBtn').addEventListener('click', async function(){
  if(typeof ExcelJS==='undefined'){ alert('Excel export library could not load. Please check your internet connection and try again.'); return; }
  const wb=new ExcelJS.Workbook(); wb.creator='Lowe Chemical Company'; wb.created=new Date();
  const ws=wb.addWorksheet('Vendor Purchase Summary',{views:[{state:'frozen',ySplit:4,xSplit:2}]});
  const cols=['Supplier','Product',...monthLabels,'Total Pounds','Total Spend'];
  ws.mergeCells(1,1,1,cols.length); ws.getCell(1,1).value='Lowe Chemical Company - Vendor Purchase Summary';
  ws.mergeCells(2,1,2,cols.length); ws.getCell(2,1).value='Rolling <?=vs_esc($selectedLabel)?> through <?=vs_esc(date('M j, Y',strtotime($latest)))?>';
  ws.getRow(1).height=25; ws.getCell(1,1).font={bold:true,size:16,color:{argb:'FFFFFFFF'}}; ws.getCell(1,1).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF061D3F'}};
  ws.getCell(2,1).font={italic:true,color:{argb:'FF66768A'}};
  const hr=4; cols.forEach((c,i)=>ws.getCell(hr,i+1).value=c);
  ws.getRow(hr).font={bold:true,color:{argb:'FFFFFFFF'}}; ws.getRow(hr).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF061D3F'}};
  exportRows.forEach(r=>{ const vals=[r.Supplier,r.Product,...monthLabels.map(m=>r[m]||0),r['Total Pounds']||0,r['Total Spend']||0]; ws.addRow(vals); });
  ws.autoFilter={from:{row:hr,column:1},to:{row:hr,column:cols.length}};
  ws.getColumn(1).width=28; ws.getColumn(2).width=36; for(let c=3;c<3+monthLabels.length;c++) ws.getColumn(c).width=13; ws.getColumn(cols.length-1).width=15; ws.getColumn(cols.length).width=15;
  for(let r=5;r<=ws.rowCount;r++){ for(let c=3;c<cols.length;c++) ws.getCell(r,c).numFmt='#,##0;[Red](#,##0);-'; ws.getCell(r,cols.length).numFmt='$#,##0;[Red]($#,##0);-'; }
  if(monthLabels.length){ for(let r=4;r<=ws.rowCount;r++) ws.getCell(r,3).fill={type:'pattern',pattern:'solid',fgColor:{argb:r===4?'FFF2D990':'FFFFF7DC'}}; }
  for(let r=4;r<=ws.rowCount;r++){ for(let c=1;c<=cols.length;c++){ ws.getCell(r,c).border={top:{style:'thin',color:{argb:'FFD4DCE5'}},left:{style:'thin',color:{argb:'FFD4DCE5'}},bottom:{style:'thin',color:{argb:'FFD4DCE5'}},right:{style:'thin',color:{argb:'FFD4DCE5'}}}; } }
  ws.pageSetup={orientation:'landscape',fitToPage:true,fitToWidth:1,fitToHeight:0,margins:{left:0.25,right:0.25,top:0.5,bottom:0.5,header:0.2,footer:0.2}};
  const buf=await wb.xlsx.writeBuffer(); const blob=new Blob([buf],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}); const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='Lowe_Vendor_Purchase_Summary_<?=date('Y-m-d')?>.xlsx'; document.body.appendChild(a); a.click(); URL.revokeObjectURL(a.href); a.remove();
});
</script>

<script>
(function(){
  const search=document.getElementById('productSearch');
  const list=document.getElementById('productList');
  const summary=document.getElementById('productSummary');
  const clearBtn=document.getElementById('clearProducts');
  const selectBtn=document.getElementById('selectVisible');
  if(!search||!list) return;
  const opts=[...list.querySelectorAll('.product-opt')];
  const checks=()=>[...list.querySelectorAll('input[type="checkbox"]')];
  function updateSummary(){
    const n=checks().filter(c=>c.checked).length;
    summary.textContent=n ? n+' product'+(n===1?'':'s')+' selected' : 'All products';
  }
  search.addEventListener('input',()=>{
    const q=search.value.trim().toLowerCase();
    opts.forEach(o=>o.style.display=(!q||o.dataset.product.includes(q))?'flex':'none');
  });
  list.addEventListener('change',updateSummary);
  clearBtn.addEventListener('click',()=>{checks().forEach(c=>c.checked=false);updateSummary();});
  selectBtn.addEventListener('click',()=>{opts.filter(o=>o.style.display!=='none').forEach(o=>{const c=o.querySelector('input');if(c)c.checked=true;});updateSummary();});
  updateSummary();
})();
</script>
</body></html>
