<?php
declare(strict_types=1);
const LOWE_INVENTORY_MODEL_VERSION = 2;
require_once __DIR__ . '/lowe-dashboard-common.php';

/*
 * Lowe Chemical - Shared inventory/purchasing model
 * Revised for lot-level Inventory columns:
 * Product Name, Product Number, CAS Number, Product UOM, Lot Number, Qty,
 * Cost Per LB, Unit Cost, Total Cost, LB per Unit, Total LBs, Receipt Date,
 * Expire Date, MIN, MAX, Lead Time (Days).
 */

function im_code($v): string { return strtoupper(trim((string)$v)); }
function im_date($v): ?string { $d = of_date($v ?? ''); return $d ?: null; }
function im_clamp(float $v, float $lo, float $hi): float { return max($lo, min($hi, $v)); }
function im_first(array $r, array $keys, $default='') {
    foreach ($keys as $k) if (array_key_exists($k,$r) && $r[$k] !== '' && $r[$k] !== null) return $r[$k];
    return $default;
}
function im_pick_name(array &$products, string $code, $name, int $priority): void {
    $name = trim((string)$name);
    if ($code === '' || $name === '' || str_starts_with($name,'=')) return;
    if (!isset($products[$code])) $products[$code] = ['product_code'=>$code,'_name_priority'=>-1];
    if ($priority >= (int)($products[$code]['_name_priority'] ?? -1)) {
        $products[$code]['product_name'] = $name;
        $products[$code]['_name_priority'] = $priority;
    }
}

function im_build(): array {
    @set_time_limit(300);
    $inventory = ld_rows('Inventory');
    $invoices  = ld_rows('Invoices');
    $purchases = ld_rows('Purchases');
    $openSO    = ld_rows('Open Sales Orders');
    $openPO    = ld_rows('Open Purchase Orders');
    try { $parts = ld_rows('Part Master'); } catch (Throwable $e) { $parts = []; }

    of_require($inventory,['Product Number','Total LBs','Total Cost','Receipt Date'],'Inventory');
    of_require($invoices,['INV. Date','Doc Type','Cust Name','Product Number','LBS'],'Invoices');
    of_require($purchases,['Supplier Name','Receipt Date','Product Number','LBs Received'],'Purchases');
    of_require($openSO,['Product Number','Total LBS'],'Open Sales Orders');
    of_require($openPO,['Product Number','LBS'],'Open Purchase Orders');

    $asOf = null;
    foreach ($invoices as $r) {
        if (strtolower(trim((string)($r['Doc Type']??''))) !== 'invoiced') continue;
        $d=im_date($r['INV. Date']??''); if($d && ($asOf===null || $d>$asOf)) $asOf=$d;
    }
    if(!$asOf) $asOf=date('Y-m-d');
    $asTs=strtotime($asOf);
    $today=date('Y-m-d');
    $todayTs=strtotime($today);

    $products=[];
    foreach($parts as $r){
        $code=im_code($r['Product Code']??''); if($code==='') continue;
        im_pick_name($products,$code,$r['Product Description']??($r['Product Name']??''),100);
    }

    // Current lot-level inventory. Total LBs and Total Cost are authoritative.
    foreach($inventory as $r){
        $code=im_code($r['Product Number']??''); if($code==='') continue;
        if(!isset($products[$code])) $products[$code]=['product_code'=>$code,'_name_priority'=>-1];
        im_pick_name($products,$code,$r['Product Name']??'',80);
        $p=&$products[$code];
        foreach(['on_hand_lbs','inventory_value','qty_units','lot_count','expired_lbs','expiring_90_lbs'] as $k) if(!isset($p[$k])) $p[$k]=0.0;
        $lbs=of_num($r['Total LBs']??0); $value=of_num($r['Total Cost']??0); $qty=of_num($r['Qty']??0);
        $p['on_hand_lbs'] += $lbs;
        $p['inventory_value'] += $value;
        $p['qty_units'] += $qty;
        if(abs($lbs)>0.00001 || abs($qty)>0.00001) $p['lot_count']++;
        $p['cas_number']=trim((string)($r['CAS Number']??($p['cas_number']??'')));
        $p['product_uom']=trim((string)($r['Product UOM']??($p['product_uom']??'')));
        $lbu=of_num($r['LB per Unit']??0); if($lbu>0) $p['lb_per_unit']=$lbu;
        $cpl=of_num($r['Cost Per LB']??0); if($cpl>0) $p['inventory_cost_per_lb']=$cpl;
        $minU=of_num($r['MIN']??0); $maxU=of_num($r['MAX']??0); $lead=max(0,of_num($r['Lead Time (Days)']??0));
        if($minU>0) $p['min_units']=max((float)($p['min_units']??0),$minU);
        if($maxU>0) $p['max_units']=max((float)($p['max_units']??0),$maxU);
        if($lead>0) $p['lead_time_days']=max((float)($p['lead_time_days']??0),$lead);
        $rd=im_date($r['Receipt Date']??'');
        if($rd){
            if(empty($p['oldest_receipt_date']) || $rd<$p['oldest_receipt_date']) $p['oldest_receipt_date']=$rd;
            if(empty($p['latest_receipt_date']) || $rd>$p['latest_receipt_date']) $p['latest_receipt_date']=$rd;
        }
        $ed=im_date($r['Expire Date']??'');
        if($ed && $lbs>0){
            $days=(int)floor((strtotime($ed)-$todayTs)/86400);
            if($days<0) $p['expired_lbs'] += $lbs;
            elseif($days<=90) $p['expiring_90_lbs'] += $lbs;
            if(empty($p['earliest_expire_date']) || $ed<$p['earliest_expire_date']) $p['earliest_expire_date']=$ed;
        }
    }

    // Demand history and top customers.
    $custBySku=[];
    foreach($invoices as $r){
        $code=im_code($r['Product Number']??''); if($code==='') continue;
        if(!isset($products[$code])) $products[$code]=['product_code'=>$code,'_name_priority'=>-1];
        im_pick_name($products,$code,$r['Product Name']??'',70);
        $d=im_date($r['INV. Date']??''); if(!$d) continue;
        $type=strtolower(trim((string)($r['Doc Type']??''))); if(!in_array($type,['invoiced','credit'],true)) continue;
        $days=(int)floor(($asTs-strtotime($d))/86400); if($days<0) continue;
        $lbs=of_num($r['LBS']??0); $p=&$products[$code];
        foreach(['sales_90_lbs','sales_180_lbs','sales_365_lbs','sales_prev90_lbs'] as $k) if(!isset($p[$k])) $p[$k]=0.0;
        if($days<=89) $p['sales_90_lbs'] += $lbs;
        if($days<=179) $p['sales_180_lbs'] += $lbs;
        if($days<=364) $p['sales_365_lbs'] += $lbs;
        if($days>=90 && $days<=179) $p['sales_prev90_lbs'] += $lbs;
        if($type==='invoiced' && $lbs>0 && $days<=364){$c=trim((string)($r['Cust Name']??''));if($c!=='')$custBySku[$code][$c]=($custBySku[$code][$c]??0)+$lbs;}
    }

    // Customer commitments are the replacement for the old Inventory Allocated field.
    foreach($openSO as $r){
        $code=im_code($r['Product Number']??''); if($code==='') continue;
        if(!isset($products[$code])) $products[$code]=['product_code'=>$code,'_name_priority'=>-1];
        im_pick_name($products,$code,$r['Product Name']??'',75);
        $products[$code]['open_sales_orders_lbs']=($products[$code]['open_sales_orders_lbs']??0)+max(0.0,of_num($r['Total LBS']??0));
    }
    foreach($openPO as $r){
        $code=im_code($r['Product Number']??''); if($code==='') continue;
        if(!isset($products[$code])) $products[$code]=['product_code'=>$code,'_name_priority'=>-1];
        im_pick_name($products,$code,$r['Product Name']??'',75);
        $products[$code]['open_purchase_orders_lbs']=($products[$code]['open_purchase_orders_lbs']??0)+max(0.0,of_num($r['LBS']??0));
    }

    // Supplier history and latest purchase cost, using current purchase headers.
    $supplierLbs=[];$lastPurchase=[];
    foreach($purchases as $r){
        $code=im_code($r['Product Number']??''); if($code==='') continue;
        if(!isset($products[$code])) $products[$code]=['product_code'=>$code,'_name_priority'=>-1];
        im_pick_name($products,$code,$r['Product Name']??'',60);
        $sup=trim((string)($r['Supplier Name']??'')); $lbs=max(0.0,of_num($r['LBs Received']??0));
        if($sup!=='') $supplierLbs[$code][$sup]=($supplierLbs[$code][$sup]??0)+$lbs;
        $d=im_date($r['Receipt Date']??'');
        $cost=$lbs>0 ? of_num($r['Total Item Cost']??0)/$lbs : 0.0;
        if($cost<=0){$unit=of_num($r['Unit Cost']??0);$lbpu=of_num($r['LBs Per Stocking Unit']??0);if($unit>0&&$lbpu>0)$cost=$unit/$lbpu;}
        if($d && (!isset($lastPurchase[$code]) || $d>$lastPurchase[$code]['date'])) $lastPurchase[$code]=['date'=>$d,'cost'=>$cost,'supplier'=>$sup];
    }

    $rows=[];
    foreach($products as $code=>$p){
        $p['product_code']=$code; $p['product_name']=trim((string)($p['product_name']??$code));
        foreach(['on_hand_lbs','inventory_value','open_sales_orders_lbs','open_purchase_orders_lbs','sales_90_lbs','sales_180_lbs','sales_365_lbs','sales_prev90_lbs','min_units','max_units','lead_time_days','expired_lbs','expiring_90_lbs','lot_count'] as $k) $p[$k]=(float)($p[$k]??0);
        $p['allocated_lbs']=$p['open_sales_orders_lbs'];
        $p['available_lbs']=$p['on_hand_lbs']-$p['allocated_lbs'];
        $p['projected_available_lbs']=$p['available_lbs']+$p['open_purchase_orders_lbs'];
        $lbpu=(float)($p['lb_per_unit']??0);
        $p['min_lbs']=$p['min_units']>0&&$lbpu>0?$p['min_units']*$lbpu:0.0;
        $p['max_lbs']=$p['max_units']>0&&$lbpu>0?$p['max_units']*$lbpu:0.0;
        if($p['on_hand_lbs']>0 && $p['inventory_value']>0) $p['inventory_cost_per_lb']=$p['inventory_value']/$p['on_hand_lbs'];

        $r90=max(0.0,$p['sales_90_lbs'])/90.0;$r180=max(0.0,$p['sales_180_lbs'])/180.0;$r365=max(0.0,$p['sales_365_lbs'])/365.0;
        $base=0.50*$r90+0.30*$r180+0.20*$r365;$prev=max(0.0,$p['sales_prev90_lbs'])/90.0;
        $trend=$prev>0.00001?($r90/$prev)-1.0:($r90>0?0.50:0.0);$impact=im_clamp($trend*0.35,-0.35,0.50);
        $daily=max(0.0,$base*(1+$impact));$p['forecast_daily_lbs']=$daily;$p['forecast_next_30_days_lbs']=$daily*30;$p['safety_stock_lbs']=$daily*15;

        // Default target: 60 demand days plus 15 safety days. If a real lead time is populated,
        // reorder point covers lead time plus safety and target covers lead time plus 30 days plus safety.
        $lead=(float)$p['lead_time_days'];
        $reorder=$daily*(($lead>0?$lead:0)+15);
        $target=$daily*(($lead>0?$lead+30:60)+15);
        // When MIN/MAX stocking policy is populated, convert units to pounds and use it as policy.
        if($p['min_lbs']>0) $reorder=max($reorder,$p['min_lbs']);
        if($p['max_lbs']>0) $target=$p['max_lbs'];
        $target=max($target,$reorder);
        $p['reorder_point_lbs']=$reorder;$p['target_supply_lbs']=$target;
        $p['recommended_order_lbs']=max(0.0,$target-$p['projected_available_lbs']);
        $p['days_supply_after_open_so']=$daily>0.00001?$p['projected_available_lbs']/$daily:null;
        $p['projected_shortage_lbs']=max(0.0,$p['forecast_next_30_days_lbs']-$p['projected_available_lbs']);

        if($p['recommended_order_lbs']<=0.5) $p['action']='COVERED';
        elseif($p['projected_available_lbs']<0 || $p['projected_available_lbs']<$reorder || ($p['days_supply_after_open_so']!==null && $p['days_supply_after_open_so']<15)) $p['action']='ORDER NOW';
        elseif($p['days_supply_after_open_so']!==null && $p['days_supply_after_open_so']<30) $p['action']='ORDER SOON';
        else $p['action']='PLAN ORDER';

        $p['primary_supplier']='';if(!empty($supplierLbs[$code])){arsort($supplierLbs[$code],SORT_NUMERIC);$p['primary_supplier']=(string)array_key_first($supplierLbs[$code]);}
        elseif(!empty($lastPurchase[$code]['supplier']))$p['primary_supplier']=$lastPurchase[$code]['supplier'];
        $p['last_cost_per_lb']=(float)($lastPurchase[$code]['cost']??0);$p['last_purchase_date']=$lastPurchase[$code]['date']??null;
        $p['top_customer']='';if(!empty($custBySku[$code])){arsort($custBySku[$code],SORT_NUMERIC);$p['top_customer']=(string)array_key_first($custBySku[$code]);}
        if(!empty($p['oldest_receipt_date'])) $p['oldest_inventory_days']=max(0,(int)floor(($todayTs-strtotime($p['oldest_receipt_date']))/86400)); else $p['oldest_inventory_days']=null;
        unset($p['_name_priority']);
        $activity=abs($p['on_hand_lbs'])+abs($p['open_sales_orders_lbs'])+abs($p['open_purchase_orders_lbs'])+abs($p['sales_365_lbs']);
        if($activity>0.00001)$rows[]=$p;
    }
    return ['cache_version'=>5,'source_file'=>basename(ld_master()),'source_mtime'=>@filemtime(ld_master())?:0,'as_of'=>$asOf,'inventory_snapshot_date'=>$today,'rows'=>$rows];
}

$cacheFile=__DIR__.'/inventory-dashboard-data-v2.json';$mtime=@filemtime(ld_master())?:0;$data=null;
if(is_file($cacheFile)){$tmp=json_decode((string)@file_get_contents($cacheFile),true);if(is_array($tmp)&&(int)($tmp['cache_version']??0)===5&&(int)($tmp['source_mtime']??-1)===$mtime&&!empty($tmp['rows']))$data=$tmp;}
if(!$data){try{$data=im_build();@file_put_contents($cacheFile,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);}catch(Throwable $e){http_response_code(500);die(ld_h('Could not build inventory model: '.$e->getMessage()));}}
?>
