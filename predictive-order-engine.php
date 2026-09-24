<?php
/**
 * Lowe Chemical Order Forecast Engine (v2)
 *
 * Reads the Lowe Master workbook (Invoices + Open Sales Orders tabs) with no external libraries
 * and produces one prediction record per customer / product relationship.
 *
 * What is different from a simple "last order + median gap" model:
 *  1. Credits that reverse an invoice are netted out, so a cancelled order is not counted as demand.
 *  2. Invoices within 3 days of each other are treated as one order (split shipments).
 *  3. The prediction is CONDITIONAL on how long the customer has already waited. Only past gaps
 *     longer than the current wait are used to say when the order should arrive.
 *  4. Each record gets a calibrated probability of ordering within 30 and 60 days. The probability
 *     comes from the customer's own history blended with a company-wide pattern, then calibrated
 *     against back-tests on this workbook.
 *  5. Typical quantity is the median of the customer's most recent 6 orders, with a low/high range.
 *  6. Customers that have gone quiet (waiting more than 2.5x their normal gap) are separated out
 *     so they do not clutter the "expected" lists.
 */

const OF_MERGE_DAYS = 3;
const OF_RECENT_ORDERS = 6;
const OF_PRIOR_WEIGHT = 3;
/** Add product-code aliases here when the same product exists under two item numbers. */
const OF_PRODUCT_ALIASES = ['015456' => '015452'];
const OF_RATIO_EDGES = [0.4,0.7,1.0,1.3,1.7,2.5,4.0];
const OF_PRIOR_30 = [0.258,0.317,0.3747,0.3841,0.3136,0.2561,0.1923,0.107];
const OF_PRIOR_60 = [0.4177,0.4976,0.5339,0.5184,0.426,0.3906,0.2895,0.1937];
const OF_CAL_30 = [0.02,0.02,0.106,0.138,0.158,0.194,0.232,0.28,0.352,0.352,0.352,0.571,0.571,0.703,0.703,0.791,0.882,0.923,0.93,0.97,0.97];
const OF_CAL_60 = [0.159,0.159,0.159,0.159,0.199,0.21,0.21,0.271,0.294,0.357,0.413,0.447,0.447,0.447,0.665,0.712,0.803,0.917,0.967,0.97,0.97];

// ---------- XLSX reader (dependency-free) ----------
function of_col_index(string $letters): int { $n=0; for($i=0,$l=strlen($letters);$i<$l;$i++){ $n=$n*26+(ord($letters[$i])-64); } return $n-1; }
function of_shared_strings(ZipArchive $zip): array {
    $xml=$zip->getFromName('xl/sharedStrings.xml'); if($xml===false) return [];
    $sx=simplexml_load_string($xml); if(!$sx) return [];
    $out=[];
    foreach($sx->si as $si){ $parts=[]; if(isset($si->t)) $parts[]=(string)$si->t; if(isset($si->r)){ foreach($si->r as $r) $parts[]=(string)$r->t; } $out[]=implode('',$parts); }
    return $out;
}
function of_sheet_target(ZipArchive $zip, string $sheetName): string {
    $wbXml=$zip->getFromName('xl/workbook.xml'); $relXml=$zip->getFromName('xl/_rels/workbook.xml.rels');
    if($wbXml===false||$relXml===false) throw new RuntimeException('Workbook metadata could not be read.');
    $wb=simplexml_load_string($wbXml); $rels=simplexml_load_string($relXml);
    if(!$wb||!$rels) throw new RuntimeException('Workbook metadata is invalid.');
    $wb->registerXPathNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rid=null;
    foreach($wb->xpath('//m:sheets/m:sheet') as $sheet){
        if((string)$sheet['name']===$sheetName){ $a=$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships'); $rid=(string)$a['id']; break; }
    }
    if(!$rid) throw new RuntimeException("Required worksheet '{$sheetName}' was not found.");
    $target=null;
    foreach($rels->Relationship as $rel){ if((string)$rel['Id']===$rid){ $target=(string)$rel['Target']; break; } }
    if(!$target) throw new RuntimeException("Worksheet relationship for '{$sheetName}' was not found.");
    return str_starts_with($target,'/') ? ltrim($target,'/') : 'xl/'.ltrim($target,'/');
}
function of_read_sheet(string $path, string $sheetName): array {
    if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive is required. Ask your host to enable the PHP zip extension.');
    $zip=new ZipArchive();
    if($zip->open($path)!==true) throw new RuntimeException('The uploaded file is not a readable .xlsx workbook.');
    try{
        $shared=of_shared_strings($zip);
        $xml=$zip->getFromName(of_sheet_target($zip,$sheetName));
        if($xml===false) throw new RuntimeException("Worksheet '{$sheetName}' could not be read.");
        $sx=simplexml_load_string($xml); if(!$sx) throw new RuntimeException("Worksheet '{$sheetName}' XML is invalid.");
        $sx->registerXPathNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows=[];
        foreach($sx->xpath('//m:sheetData/m:row') as $row){
            $cells=[];
            foreach($row->c as $c){
                if(!preg_match('/^([A-Z]+)(\d+)$/',(string)$c['r'],$m)) continue;
                $idx=of_col_index($m[1]); $type=(string)$c['t']; $v='';
                if($type==='s'){ $n=isset($c->v)?(int)$c->v:-1; $v=$shared[$n]??''; }
                elseif($type==='inlineStr'){ if(isset($c->is->t)) $v=(string)$c->is->t; elseif(isset($c->is->r)){ $p=[]; foreach($c->is->r as $r) $p[]=(string)$r->t; $v=implode('',$p);} }
                elseif($type==='b'){ $v=((string)$c->v==='1')?'1':'0'; }
                else{ $v=isset($c->v)?(string)$c->v:''; }
                $cells[$idx]=$v;
            }
            if($cells) $rows[]=$cells;
        }
        return $rows;
    } finally { $zip->close(); }
}
function of_assoc(array $rows): array {
    if(!$rows) return [];
    $hr=array_shift($rows); $max=$hr?max(array_keys($hr)):-1; $h=[];
    for($i=0;$i<=$max;$i++) $h[$i]=trim((string)($hr[$i]??''));
    $out=[];
    foreach($rows as $row){ $a=[]; $has=false; foreach($h as $i=>$name){ if($name==='') continue; $v=$row[$i]??''; if($v!=='') $has=true; $a[$name]=$v; } if($has) $out[]=$a; }
    return $out;
}
function of_require(array $rows, array $req, string $sheet): void {
    if(!$rows) throw new RuntimeException("Worksheet '{$sheet}' contains no data rows.");
    $missing=array_values(array_diff($req,array_keys($rows[0])));
    if($missing) throw new RuntimeException("Worksheet '{$sheet}' is missing required columns: ".implode(', ',$missing));
}
function of_date($v): ?string {
    if($v===null||$v==='') return null;
    if(is_numeric($v)){ $u=(int)round(((float)$v-25569)*86400); if($u>0) return gmdate('Y-m-d',$u); }
    $t=strtotime((string)$v); return $t?date('Y-m-d',$t):null;
}
function of_num($v): float { if($v===null||$v==='') return 0.0; if(is_numeric($v)) return (float)$v; return (float)str_replace([',','$',' '],'',(string)$v); }

// ---------- statistics ----------
function of_median(array $v): ?float { $v=array_values($v); $n=count($v); if(!$n) return null; sort($v,SORT_NUMERIC); $m=intdiv($n,2); return $n%2?(float)$v[$m]:((float)$v[$m-1]+(float)$v[$m])/2; }
function of_mean(array $v): ?float { return $v?array_sum($v)/count($v):null; }
function of_stddev(array $v): float { $n=count($v); if($n<2) return 0.0; $m=array_sum($v)/$n; $s=0.0; foreach($v as $x) $s+=($x-$m)**2; return sqrt($s/($n-1)); }
function of_quantile(array $v, float $q): float { sort($v,SORT_NUMERIC); $n=count($v); if($n===1) return (float)$v[0]; $pos=($n-1)*$q; $lo=(int)floor($pos); $hi=(int)ceil($pos); return $v[$lo]+($v[$hi]-$v[$lo])*($pos-$lo); }
function of_days(string $a, string $b): int { return (int)round((strtotime($b)-strtotime($a))/86400); }
function of_add(string $d, int $n): string { return date('Y-m-d',strtotime($d.' '.($n>=0?'+':'').$n.' days')); }
function of_bucket(float $ratio): int { foreach(OF_RATIO_EDGES as $i=>$e){ if($ratio<=$e) return $i; } return count(OF_RATIO_EDGES); }
function of_interp(array $tbl, float $p): float { $p=max(0,min(1,$p)); $pos=$p*(count($tbl)-1); $lo=(int)floor($pos); $hi=min(count($tbl)-1,$lo+1); return $tbl[$lo]+($tbl[$hi]-$tbl[$lo])*($pos-$lo); }
/** Probability of an order within $horizon days, given the customer's past gaps and the wait so far. */
function of_probability(array $iv, int $age, float $ratio, int $horizon): float {
    $prior = $horizon===30 ? OF_PRIOR_30[of_bucket($ratio)] : OF_PRIOR_60[of_bucket($ratio)];
    $S=array_values(array_filter($iv, fn($x)=>$x>$age));
    if($S){ $hit=count(array_filter($S, fn($x)=>$x<=$age+$horizon)); $own=$hit/count($S); $k=count($S); $raw=($k*$own+OF_PRIOR_WEIGHT*$prior)/($k+OF_PRIOR_WEIGHT); }
    else $raw=$prior;
    return of_interp($horizon===30 ? OF_CAL_30 : OF_CAL_60, $raw);
}

// ---------- main build ----------
function of_build_payload(string $xlsxPath, ?string $asOf = null): array {
    $asOf = $asOf ?: date('Y-m-d');
    $invRows=of_assoc(of_read_sheet($xlsxPath,'Invoices'));
    $soRows=of_assoc(of_read_sheet($xlsxPath,'Open Sales Orders'));
    of_require($invRows,['INV. Date','Doc Type','Cust Name','Cust#','Product Name','Product Number','LBS','REP'],'Invoices');
    of_require($soRows,['Customer Name','Customer Number','Order Date','Product Name','Product Number','Total LBS','Rep Name'],'Open Sales Orders');

    $alias=fn($c)=>OF_PRODUCT_ALIASES[$c]??$c;
    $groups=[]; $credits=[]; $latest=null; $reversed=0;
    foreach($invRows as $r){
        $type=strtolower(trim((string)$r['Doc Type']));
        $date=of_date($r['INV. Date']); if(!$date) continue;
        $cust=trim((string)$r['Cust Name']); $cc=trim((string)$r['Cust#']); $prod=trim((string)$r['Product Name']); $pc=$alias(trim((string)$r['Product Number']));
        $lbs=of_num($r['LBS']); if($cust===''||$pc==='') continue;
        $key=strtoupper($cc.'|'.$pc);
        if($type==='credit' && $lbs<0){ $credits[]=['key'=>$key,'date'=>$date,'lbs'=>$lbs]; continue; }
        if($type!=='invoiced'||$lbs<=0) continue;
        if(!isset($groups[$key])) $groups[$key]=['customer'=>$cust,'customer_code'=>$cc,'product'=>$prod,'product_code'=>$pc,'rep'=>'','inv'=>[]];
        $groups[$key]['inv'][]=['date'=>$date,'lbs'=>$lbs];
        $groups[$key]['product']=$prod;
        if(trim((string)$r['REP'])!=='') $groups[$key]['rep']=trim((string)$r['REP']);
        if($latest===null||$date>$latest) $latest=$date;
    }
    // 1) net out credits that reverse an invoice (same customer/product, same pounds, invoice on or before the credit)
    usort($credits, fn($a,$b)=>strcmp($a['date'],$b['date']));
    foreach($credits as $c){
        if(!isset($groups[$c['key']])) continue;
        $best=null;
        foreach($groups[$c['key']]['inv'] as $i=>$iv){
            if(!empty($iv['used'])||$iv['date']>$c['date']) continue;
            if(abs($iv['lbs']+$c['lbs'])<1 && ($best===null||$iv['date']>=$groups[$c['key']]['inv'][$best]['date'])) $best=$i;
        }
        if($best!==null){ $groups[$c['key']]['inv'][$best]['used']=true; $reversed++; }
    }
    // open sales orders
    $open=[];
    foreach($soRows as $r){
        $cc=trim((string)$r['Customer Number']); $pc=$alias(trim((string)$r['Product Number'])); if($cc===''&&$pc==='') continue;
        $key=strtoupper($cc.'|'.$pc); $lbs=max(0,of_num($r['Total LBS'])); $d=of_date($r['Order Date']);
        $ship=of_date($r['Ship Date']??'');
        if(!isset($open[$key])) $open[$key]=['lbs'=>0.0,'date'=>null,'ship'=>null];
        $open[$key]['lbs']+=$lbs;
        if($d&&($open[$key]['date']===null||$d<$open[$key]['date'])) $open[$key]['date']=$d;
        if($ship&&($open[$key]['ship']===null||$ship<$open[$key]['ship'])) $open[$key]['ship']=$ship;
    }

    $rows=[];
    foreach($groups as $key=>$g){
        // 2) combine invoices into order events
        $byDate=[];
        foreach($g['inv'] as $iv){ if(!empty($iv['used'])) continue; $byDate[$iv['date']]=($byDate[$iv['date']]??0)+$iv['lbs']; }
        if(!$byDate) continue;
        ksort($byDate);
        $dates=[]; $qtys=[];
        foreach($byDate as $d=>$q){
            $n=count($dates);
            if($n && of_days($dates[$n-1],$d)<=OF_MERGE_DAYS){ $qtys[$n-1]+=$q; } else { $dates[]=$d; $qtys[]=$q; }
        }
        $n=count($dates); $last=$dates[$n-1]; $lastQty=$qtys[$n-1];
        $age=max(0,of_days($last,$asOf));
        $recentQ=array_slice($qtys,-OF_RECENT_ORDERS);
        $typQty=of_median($recentQ); $qLow=of_quantile($recentQ,0.25); $qHigh=of_quantile($recentQ,0.75);
        // trailing 12 months
        $from=of_add($asOf,-365); $o12=0; $l12=0.0;
        foreach($dates as $i=>$d){ if($d>$from){ $o12++; $l12+=$qtys[$i]; } }
        $trend='Stable';
        if($n>=4){ $h=intdiv($n,2); $old=of_mean(array_slice($qtys,0,$n-$h)); $new=of_mean(array_slice($qtys,-$h)); if($old>0){ $chg=($new-$old)/$old; if($chg>=0.15)$trend='Increasing'; elseif($chg<=-0.15)$trend='Decreasing'; } }

        $iv=[]; for($i=1;$i<$n;$i++) $iv[]=of_days($dates[$i-1],$dates[$i]);
        $openLbs=$open[$key]['lbs']??0.0; $openDate=$open[$key]['date']??null; $openShip=$open[$key]['ship']??null;
        $rec=['customer'=>$g['customer'],'customer_code'=>$g['customer_code'],'product'=>$g['product'],'product_code'=>$g['product_code'],'rep'=>$g['rep'],
              'order_count'=>$n,'last_order'=>$last,'last_qty_lbs'=>round($lastQty),'days_since_last'=>$age,
              'typical_qty_lbs'=>round($typQty),'qty_low_lbs'=>round($qLow),'qty_high_lbs'=>round($qHigh),'trend'=>$trend,
              'orders_12m'=>$o12,'lbs_12m'=>round($l12),'open_so_lbs'=>round($openLbs),'open_so_date'=>$openDate,'open_so_ship'=>$openShip,
              'typical_gap_days'=>null,'expected_date'=>null,'window_start'=>null,'window_end'=>null,'days_until'=>null,
              'p30'=>null,'p60'=>null,'pattern'=>'One-time buyer','confidence'=>'Low','status'=>'One-time buyer'];
        if($n<2){
            if($openLbs>0) $rec['status']='Order in house';
            elseif($age>365) $rec['status']='Inactive';
            $rows[]=$rec; continue;
        }
        $med=of_median($iv); $medR=max(1,(int)round($med));
        $ratio=$age/max(1,$med);
        $mean=of_mean($iv); $cv=$mean>0?of_stddev($iv)/$mean:1.0;
        $S=array_values(array_filter($iv, fn($x)=>$x>$age));
        $exp=null;
        if($S) $exp=of_add($last,(int)round(of_median($S)));
        $rel = $cv<0.25?0.30:($cv<0.5?0.40:($cv<0.8?0.65:1.20));
        $half=max(5,(int)round($rel*$med));
        $p30=of_probability($iv,$age,$ratio,30); $p60=of_probability($iv,$age,$ratio,60);
        $pattern = ($cv<0.4&&$n>=5)?'Regular':(($cv<0.8&&$n>=3)?'Somewhat regular':'Irregular');
        $conf = ($n>=5&&$cv<0.4)?'High':(($n>=3&&$cv<0.8)?'Medium':'Low');
        $status='Later'; $du=null;
        if($exp!==null){ $du=of_days($asOf,$exp); $rec['expected_date']=$exp; $rec['window_start']=of_add($exp,-$half); $rec['window_end']=of_add($exp,$half); $rec['days_until']=$du; }
        if($openLbs>0) $status='Order in house';
        elseif($age>365 || ($ratio>2.5 && $age>90)) $status='Gone quiet';
        elseif($exp===null) $status='Overdue';
        elseif($du<=7) $status='Due now';
        elseif($du<=30) $status='Due in 8-30 days';
        elseif($du<=60) $status='Due in 31-60 days';
        $rec['typical_gap_days']=$medR; $rec['p30']=round($p30,3); $rec['p60']=round($p60,3);
        $rec['pattern']=$pattern; $rec['confidence']=$conf; $rec['status']=$status; $rec['gap_variation']=round($cv,2);
        $rows[]=$rec;
    }
    usort($rows,function($a,$b){
        $pa=$a['p30']??-1; $pb=$b['p30']??-1;
        if($a['expected_date']===$b['expected_date']) return $pb<=>$pa;
        if($a['expected_date']===null) return 1; if($b['expected_date']===null) return -1;
        return strcmp($a['expected_date'],$b['expected_date']);
    });
    return [
        'generated_as_of'=>$asOf,'latest_invoice_date'=>$latest,'source_file'=>basename($xlsxPath),
        'reversed_invoices_removed'=>$reversed,'model_version'=>'2.0',
        'methodology'=>[
            'Every customer and product pair is analyzed on its own. Invoices on the same day, or within 3 days of each other, count as one order. Credits that reverse an invoice are removed.',
            'Typical amount is the median pounds of the customer\'s last 6 orders. The range shown is the middle half (25th to 75th percentile) of those orders.',
            'Expected date is the last order date plus the customer\'s typical gap, using only past gaps that are longer than the time already waited.',
            'Likelihood is the chance of an order inside the next 30 (or 60) days. It blends the customer\'s own history with the company-wide pattern and is calibrated to back-test results.',
            'Customers waiting more than 2.5 times their normal gap (and more than 90 days) are listed as Gone quiet rather than Expected. Open sales orders are matched by customer number and product number.'
        ],
        'backtest'=>[
            'summary'=>'Tested on this workbook: the model was calibrated on orders before March 2026, then scored on 8,000+ customer/product checkpoints from March to mid-August 2026.',
            'auc'=>0.75,'auc_simple_rule'=>0.70,
            'median_date_error_days'=>19,'median_date_error_simple_days'=>29,
            'calibration'=>[['band'=>'10-20%','predicted'=>14,'actual'=>12],['band'=>'30-40%','predicted'=>32,'actual'=>22],['band'=>'50-60%','predicted'=>53,'actual'=>53],['band'=>'60-70%','predicted'=>67,'actual'=>72],['band'=>'70-80%','predicted'=>76,'actual'=>81],['band'=>'80%+','predicted'=>92,'actual'=>93]]
        ],
        'rows'=>$rows
    ];
}
function of_write_atomic(array $payload, string $target): void {
    $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false) throw new RuntimeException('Forecast data could not be encoded.');
    $tmp=$target.'.tmp';
    if(file_put_contents($tmp,$json,LOCK_EX)===false) throw new RuntimeException('Forecast data could not be written. Check folder permissions.');
    if(!rename($tmp,$target)){ @unlink($tmp); throw new RuntimeException('Forecast data could not replace the live file.'); }
}
