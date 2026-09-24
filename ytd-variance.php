<?php
/**
 * Lowe Chemical - Selectable Year vs Previous Year Customer/Product Variance
 * Uses the Lowe Master workbook maintained by predictive-orders-admin.php.
 * Grouping is Customer + Product Description. Product Number is intentionally ignored.
 */

declare(strict_types=1);
require_once __DIR__ . '/predictive-order-engine.php';

function yv_esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function yv_num($v, int $d=0): string { return number_format((float)$v,$d); }
function yv_date($v): string { if(!$v)return '-'; $t=strtotime((string)$v); return $t?date('M j, Y',$t):'-'; }
function yv_contains($h,$n): bool { if($n==='')return true; return function_exists('mb_stripos')?mb_stripos((string)$h,(string)$n)!==false:stripos((string)$h,(string)$n)!==false; }
function yv_xml($v): string { return htmlspecialchars((string)$v, ENT_XML1|ENT_QUOTES,'UTF-8'); }
function yv_col(int $n): string { $o=''; while($n>0){$n--; $o=chr(65+($n%26)).$o; $n=intdiv($n,26);} return $o; }
function yv_qs(array $over=[]): string { $q=array_merge($_GET,$over); foreach($q as $k=>$v){if($v===''||$v===null)unset($q[$k]);} return '?'.http_build_query($q); }

function yv_find_workbook(): ?string {
    foreach([
        __DIR__.'/predictive-order-files/Lowe-Master-Latest.xlsx',
        __DIR__.'/order-forecast-files/Lowe-Master-Latest.xlsx',
        __DIR__.'/Lowe Master.xlsx',
        __DIR__.'/Lowe-Master-Latest.xlsx'
    ] as $p){ if(is_file($p)) return $p; }
    return null;
}

function yv_download_xlsx(array $rows,array $meta): void {
    if(!class_exists('ZipArchive')){ http_response_code(500); die('Excel download requires the PHP Zip extension (ZipArchive) on the server.'); }
    $cur=(int)$meta['selected_year']; $prior=(int)$meta['prior_year'];
    $headers=['Customer','Product',$meta['current_label'],$meta['prior_label'],'Variance','Variance %'];
    $sheetRows=[];
    $sheetRows[]='<row r="1" ht="24" customHeight="1"><c r="A1" t="inlineStr" s="5"><is><t>'.yv_xml($cur.' vs '.$prior.' Volume Variance').'</t></is></c></row>';
    $sheetRows[]='<row r="2"><c r="A2" t="inlineStr" s="6"><is><t>'.yv_xml($meta['comparison_text']).'</t></is></c></row>';
    $rnum=4; $cells=[];
    foreach($headers as $i=>$h){$ref=yv_col($i+1).$rnum;$cells[]='<c r="'.$ref.'" t="inlineStr" s="1"><is><t>'.yv_xml($h).'</t></is></c>';}
    $sheetRows[]='<row r="4">'.implode('',$cells).'</row>';
    foreach($rows as $r){
        $rnum++; $var=(float)$r['variance']; $pct=$r['variance_pct'];
        $vals=[(string)$r['customer'],(string)$r['product'],(float)$r['current'],(float)$r['prior'],$var,$pct===null?'':(float)$pct];
        $cells=[];
        foreach($vals as $i=>$v){
            $ref=yv_col($i+1).$rnum;
            if($i<2){$cells[]='<c r="'.$ref.'" t="inlineStr" s="0"><is><t>'.yv_xml($v).'</t></is></c>';}
            else {
                $style=2; if($i===4||$i===5)$style=$var>0?3:($var<0?4:2);
                if($i===5 && $v==='') $cells[]='<c r="'.$ref.'" s="'.$style.'"/>';
                else $cells[]='<c r="'.$ref.'" s="'.$style.'"><v>'.(float)$v.'</v></c>';
            }
        }
        $sheetRows[]='<row r="'.$rnum.'">'.implode('',$cells).'</row>';
    }
    $last='F';
    $sheetXml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
      .'<cols><col min="1" max="1" width="30" customWidth="1"/><col min="2" max="2" width="42" customWidth="1"/><col min="3" max="5" width="17" customWidth="1"/><col min="6" max="6" width="14" customWidth="1"/></cols>'
      .'<sheetData>'.implode('',$sheetRows).'</sheetData><mergeCells count="2"><mergeCell ref="A1:F1"/><mergeCell ref="A2:F2"/></mergeCells>'
      .'<autoFilter ref="A4:'.$last.$rnum.'"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      .'<fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FF15803D"/><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFA61B1B"/><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="16"/><name val="Calibri"/></font></fonts>'
      .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF061D3F"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE9EEF4"/></patternFill></fill></fills>'
      .'<borders count="2"><border/><border><left style="thin"><color rgb="FFD9E1EA"/></left><right style="thin"><color rgb="FFD9E1EA"/></right><top style="thin"><color rgb="FFD9E1EA"/></top><bottom style="thin"><color rgb="FFD9E1EA"/></bottom></border></borders>'
      .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="7">'
      .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
      .'<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right"/></xf><xf numFmtId="3" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right"/></xf><xf numFmtId="3" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right"/></xf>'
      .'<xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $wb='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Year Variance" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $rels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $root='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $types='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $tmp=tempnam(sys_get_temp_dir(),'yv_'); $zip=new ZipArchive(); if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)die('Could not create Excel file.');
    $zip->addFromString('[Content_Types].xml',$types);$zip->addFromString('_rels/.rels',$root);$zip->addFromString('xl/workbook.xml',$wb);$zip->addFromString('xl/_rels/workbook.xml.rels',$rels);$zip->addFromString('xl/worksheets/sheet1.xml',$sheetXml);$zip->addFromString('xl/styles.xml',$styles);$zip->close();
    $fn='Lowe-'.$cur.'-vs-'.$prior.'-Variance.xlsx'; header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="'.$fn.'"'); header('Content-Length: '.filesize($tmp)); header('Cache-Control: no-store, no-cache, must-revalidate'); readfile($tmp); @unlink($tmp); exit;
}

$workbook=yv_find_workbook();
if(!$workbook){http_response_code(500);die('Lowe Master workbook not found. Upload the workbook from predictive-orders-admin.php first.');}

try{
    @set_time_limit(300);
    $raw=of_assoc(of_read_sheet($workbook,'Invoices'));
    of_require($raw,['INV. Date','Doc Type','Cust Name','Cust#','Product Name','Product Number','LBS','REP'],'Invoices');
}catch(Throwable $e){http_response_code(500);die('Could not build the variance report: '.yv_esc($e->getMessage()));}

$yearDates=[]; $latestInvoiceDate=null;
foreach($raw as $r){
    if(strtolower(trim((string)($r['Doc Type']??'')))!=='invoiced') continue;
    $d=of_date($r['INV. Date']??''); if(!$d)continue; $y=(int)substr($d,0,4);
    if(!isset($yearDates[$y]))$yearDates[$y]=['min'=>$d,'max'=>$d]; else {if($d<$yearDates[$y]['min'])$yearDates[$y]['min']=$d;if($d>$yearDates[$y]['max'])$yearDates[$y]['max']=$d;}
    if($latestInvoiceDate===null||$d>$latestInvoiceDate)$latestInvoiceDate=$d;
}
if(!$yearDates)die('No invoiced rows with valid dates were found.');
$allYears=array_keys($yearDates); sort($allYears,SORT_NUMERIC); $latestYear=max($allYears);
$availableYears=[]; foreach($allYears as $y){if(isset($yearDates[$y-1]))$availableYears[]=$y;} rsort($availableYears,SORT_NUMERIC);
if(!$availableYears)die('The workbook does not contain two consecutive years of invoice history to compare.');
$selectedYear=(int)($_GET['year']??$availableYears[0]); if(!in_array($selectedYear,$availableYears,true))$selectedYear=$availableYears[0];
$priorYear=$selectedYear-1;
$isPartial=($selectedYear===$latestYear);
if($isPartial){
    $currentEnd=$yearDates[$selectedYear]['max'];
    $md=substr($currentEnd,5); $priorEnd=sprintf('%04d-%s',$priorYear,$md);
    if(strtotime($priorEnd)===false) $priorEnd=sprintf('%04d-02-28',$priorYear);
    $currentStart="$selectedYear-01-01"; $priorStart="$priorYear-01-01";
    $currentLabel="$selectedYear YTD"; $priorLabel="$priorYear PYTD";
    $comparisonText=$currentLabel.' through '.yv_date($currentEnd).' vs '.$priorLabel.' through '.yv_date($priorEnd);
}else{
    $currentStart="$selectedYear-01-01"; $currentEnd="$selectedYear-12-31"; $priorStart="$priorYear-01-01"; $priorEnd="$priorYear-12-31";
    $currentLabel=(string)$selectedYear; $priorLabel=(string)$priorYear;
    $comparisonText='Full year '.$selectedYear.' vs full year '.$priorYear;
}

$groups=[]; $rowsUsed=0; $creditRows=0;
foreach($raw as $r){
    $d=of_date($r['INV. Date']??''); if(!$d)continue;
    $type=strtolower(trim((string)($r['Doc Type']??''))); if(!in_array($type,['invoiced','credit'],true))continue;
    $period=null; if($d>=$currentStart&&$d<=$currentEnd)$period='current'; elseif($d>=$priorStart&&$d<=$priorEnd)$period='prior'; else continue;
    $cust=trim((string)($r['Cust Name']??'')); $cc=trim((string)($r['Cust#']??'')); $prod=trim((string)($r['Product Name']??'')); $rep=trim((string)($r['REP']??'')); $lbs=of_num($r['LBS']??0);
    if($cust===''||$prod==='')continue;
    $key=strtoupper(($cc!==''?$cc:$cust).'|'.preg_replace('/\s+/',' ',strtoupper($prod)));
    if(!isset($groups[$key]))$groups[$key]=['customer'=>$cust,'customer_code'=>$cc,'product'=>$prod,'rep'=>$rep,'current'=>0.0,'prior'=>0.0];
    $groups[$key][$period]+=$lbs; if($rep!=='')$groups[$key]['rep']=$rep; $rowsUsed++; if($type==='credit')$creditRows++;
}
$allRows=[]; foreach($groups as $g){ if(abs($g['current'])<0.00001&&abs($g['prior'])<0.00001)continue; $g['variance']=$g['current']-$g['prior']; $g['variance_pct']=abs($g['prior'])>0.00001?$g['variance']/$g['prior']:null; $allRows[]=$g; }

$q=trim((string)($_GET['q']??'')); $customer=trim((string)($_GET['customer']??'')); $rep=trim((string)($_GET['rep']??'')); $view=(string)($_GET['view']??'all'); $sort=(string)($_GET['sort']??'customer'); $page=max(1,(int)($_GET['page']??1)); $perPage=100;
$customers=[];$reps=[];foreach($allRows as $r){$customers[$r['customer']]=true;if($r['rep']!=='')$reps[$r['rep']]=true;} $customers=array_keys($customers);sort($customers,SORT_NATURAL|SORT_FLAG_CASE);$reps=array_keys($reps);sort($reps,SORT_NATURAL|SORT_FLAG_CASE);
$rows=array_values(array_filter($allRows,function($r)use($q,$customer,$rep,$view){
    if($q!==''&&!yv_contains($r['customer'],$q)&&!yv_contains($r['customer_code'],$q)&&!yv_contains($r['product'],$q))return false;
    if($customer!==''&&$r['customer']!==$customer)return false; if($rep!==''&&$r['rep']!==$rep)return false;
    if($view==='up'&&$r['variance']<=0)return false; if($view==='down'&&$r['variance']>=0)return false; if($view==='new'&&!($r['current']>0&&abs($r['prior'])<0.00001))return false; if($view==='lost'&&!(abs($r['current'])<0.00001&&$r['prior']>0))return false; return true;
}));
usort($rows,function($a,$b)use($sort){ if($sort==='variance_desc'){if($c=$b['variance']<=>$a['variance'])return $c;}elseif($sort==='variance_asc'){if($c=$a['variance']<=>$b['variance'])return $c;}elseif($sort==='current'){if($c=$b['current']<=>$a['current'])return $c;}elseif($sort==='prior'){if($c=$b['prior']<=>$a['prior'])return $c;}elseif($sort==='product'){if($c=strcasecmp($a['product'],$b['product']))return $c;} if($c=strcasecmp($a['customer'],$b['customer']))return $c; return strcasecmp($a['product'],$b['product']); });

$meta=['selected_year'=>$selectedYear,'prior_year'=>$priorYear,'current_label'=>$currentLabel,'prior_label'=>$priorLabel,'comparison_text'=>$comparisonText];
if(($_GET['download']??'')==='xlsx')yv_download_xlsx($rows,$meta);
$totalRows=count($rows);$totalPages=max(1,(int)ceil($totalRows/$perPage));$page=min($page,$totalPages);$slice=array_slice($rows,($page-1)*$perPage,$perPage);
$sumCurrent=0.0;$sumPrior=0.0;$sumVariance=0.0;$shownCustomers=[];foreach($rows as $r){$sumCurrent+=$r['current'];$sumPrior+=$r['prior'];$sumVariance+=$r['variance'];$shownCustomers[$r['customer_code']!==''?$r['customer_code']:$r['customer']]=true;}
$sumPct=abs($sumPrior)>0.00001?$sumVariance/$sumPrior:null;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Year vs Previous Year Variance | Lowe Chemical</title>
<style>
:root{--navy:#061d3f;--red:#c8102e;--green:#15803d;--blue:#1d5e91;--bg:#f3f5f8;--line:#dbe2ea;--text:#1f2d3d;--muted:#66768a;--head:#e9eef4;--orange:#d97706}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif;font-size:14px}a{color:var(--blue)}.header{background:var(--navy);border-bottom:4px solid var(--red);color:#fff}.header .inner{max-width:1550px;margin:auto;padding:15px 22px;display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap}.brand{display:flex;align-items:center;gap:16px}.logo{width:180px;max-height:58px;object-fit:contain;background:#fff;border-radius:6px;padding:7px 10px}.brand h1{margin:0;font-size:23px}.brand p{margin:4px 0 0;color:#cbd8e8;font-size:12.5px}.meta{text-align:right;font-size:12px;line-height:1.55;color:#d9e3ef}.meta a{color:#fff}.workflow-link{display:inline-block;margin-top:6px;padding:7px 10px;border-radius:6px;background:#fff;color:#061d3f!important;text-decoration:none;font-weight:800;font-size:12px}.wrap{max-width:1550px;margin:18px auto;padding:0 16px}.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px}.card{background:#fff;border:1px solid var(--line);border-left:5px solid var(--blue);border-radius:8px;padding:12px 14px}.card:nth-child(2){border-left-color:var(--green)}.card:nth-child(3){border-left-color:var(--orange)}.card:nth-child(4){border-left-color:var(--red)}.card:nth-child(5){border-left-color:#6d4bb5}.card .n{font-size:22px;font-weight:800;color:var(--navy)}.card .n.pos{color:var(--green)}.card .n.neg{color:#a61b1b}.card .l{font-size:10.5px;text-transform:uppercase;color:var(--muted);margin-top:3px}.panel{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px;margin-bottom:14px}.filters{display:grid;grid-template-columns:115px minmax(230px,1.6fr) 1.2fr 1fr 1fr 1.2fr auto;gap:9px;align-items:end}.field label{display:block;margin-bottom:4px;font-size:10.5px;text-transform:uppercase;font-weight:700;color:var(--muted)}.field input,.field select{width:100%;padding:9px 10px;border:1px solid #b9c5d1;border-radius:6px;background:#fff;font-size:13px}.btn{display:inline-block;border:0;border-radius:6px;background:var(--red);color:#fff;text-decoration:none;font-weight:700;padding:9px 14px;cursor:pointer}.btn.alt{background:#56667a}.btn.excel{background:#15803d}.info{margin-bottom:9px;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.info .title{font-size:15px;font-weight:800;color:var(--navy)}.info .sub{font-size:11.5px;color:var(--muted);margin-top:3px}.tablewrap{overflow:auto;border:1px solid var(--line);border-radius:7px;max-height:70vh}table{border-collapse:separate;border-spacing:0;width:max-content;min-width:900px;background:#fff}th{position:sticky;top:0;z-index:3;background:var(--head);color:var(--navy);padding:8px 9px;border-right:1px solid #c5d1dd;border-bottom:2px solid #c5d1dd;font-size:10px;text-transform:uppercase;white-space:nowrap;text-align:right}th.left{text-align:left}td{padding:8px 9px;border-right:1px solid #dfe5eb;border-bottom:1px solid #dfe5eb;font-size:12px;text-align:right;white-space:nowrap}td.left{text-align:left}tbody tr:hover td{background:#f8fafc}.cust{font-weight:700;color:var(--navy)}.prod{white-space:normal;line-height:1.25}.pos{color:var(--green);font-weight:800}.neg{color:#a61b1b;font-weight:800}.zero{color:#7a8896}.w-cust{width:250px;min-width:250px}.w-prod{width:340px;min-width:340px}.w-num{width:135px;min-width:135px}.sticky1{position:sticky;left:0;z-index:2;background:#fff}.sticky2{position:sticky;left:250px;z-index:2;background:#fff}th.sticky1,th.sticky2{z-index:5;background:var(--head)}.pager{display:flex;gap:6px;justify-content:center;align-items:center;margin:13px 0 2px}.pager a,.pager span{padding:6px 10px;border:1px solid var(--line);border-radius:5px;background:#fff;text-decoration:none;color:var(--navy)}.pager span.on{background:var(--navy);color:#fff}.foot{font-size:11px;color:var(--muted);line-height:1.45;margin:10px 2px 22px}.mode-note{background:#eef4fa;border-left:4px solid var(--blue);padding:9px 12px;border-radius:5px;margin-bottom:12px;color:#3f5265;font-size:12px}
@media(max-width:1000px){.cards{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:1fr 1fr}.meta{text-align:left}}@media(max-width:700px){.header .inner{display:block;padding:14px 12px}.brand{align-items:flex-start}.logo{width:145px}.meta{margin-top:12px;text-align:left}.wrap{padding:0 8px}.cards{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr}.field input,.field select{font-size:16px;padding:11px 10px}.filters>div:last-child{display:grid;grid-template-columns:1fr 1fr;gap:8px}.btn{width:100%;text-align:center}.tablewrap{overflow:visible;max-height:none;border:0}table{display:block;min-width:0;width:100%;background:transparent}thead{display:none}tbody{display:block}tbody tr{display:block;background:#fff;border:1px solid var(--line);border-radius:8px;margin-bottom:10px;overflow:hidden}td,td.left{display:grid;grid-template-columns:125px 1fr;gap:10px;width:100%!important;min-width:0!important;padding:8px 10px;border-right:0;border-bottom:1px solid #e6ebf0;text-align:right;white-space:normal;background:#fff}td::before{content:attr(data-label);font-size:10px;font-weight:800;text-transform:uppercase;color:var(--muted);text-align:left}.sticky1,.sticky2{position:static!important;left:auto!important}.prod{white-space:normal}}
</style></head><body>
<header class="header"><div class="inner"><div class="brand"><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><div><h1>Year vs Previous Year Variance</h1><p>Customer and product volume comparison</p></div></div><div class="meta"><?=yv_esc($comparisonText)?><br><a href="customer-13.php">Customer Volume History</a> &nbsp;|&nbsp; <a href="predictive-orders-admin.php">Update from Excel</a><br><a class="workflow-link" href="salesworkflow.php">Back to Sales Workflow</a></div></div></header>
<main class="wrap">
<div class="mode-note"><?php if($isPartial): ?><strong><?=$selectedYear?> is a partial year.</strong> The report automatically compares <?=$selectedYear?> through <?=yv_esc(yv_date($currentEnd))?> with <?=$priorYear?> through the same calendar cutoff.<?php else: ?><strong>Full-year comparison.</strong> <?=$selectedYear?> is compared January 1 through December 31 against the full <?=$priorYear?> calendar year.<?php endif; ?></div>
<div class="cards"><div class="card"><div class="n"><?=yv_num(count($shownCustomers))?></div><div class="l">Customers shown</div></div><div class="card"><div class="n"><?=yv_num($sumCurrent)?> lb</div><div class="l"><?=yv_esc($currentLabel)?> volume</div></div><div class="card"><div class="n"><?=yv_num($sumPrior)?> lb</div><div class="l"><?=yv_esc($priorLabel)?> volume</div></div><div class="card"><div class="n <?=$sumVariance>0?'pos':($sumVariance<0?'neg':'')?>"><?=($sumVariance>0?'+':'')?><?=yv_num($sumVariance)?> lb</div><div class="l">Variance</div></div><div class="card"><div class="n <?=$sumVariance>0?'pos':($sumVariance<0?'neg':'')?>"><?=$sumPct===null?'N/A':(($sumPct>0?'+':'').number_format($sumPct*100,1).'%')?></div><div class="l">Variance %</div></div></div>
<section class="panel"><form method="get" class="filters"><div class="field"><label>Compare Year</label><select name="year"><?php foreach($availableYears as $y):?><option value="<?=$y?>" <?=$selectedYear===$y?'selected':''?>><?=$y?> vs <?=$y-1?></option><?php endforeach;?></select></div><div class="field"><label>Customer / product</label><input type="text" name="q" value="<?=yv_esc($q)?>" placeholder="Search customer or product"></div><div class="field"><label>Customer</label><select name="customer"><option value="">All customers</option><?php foreach($customers as $c):?><option value="<?=yv_esc($c)?>" <?=$customer===$c?'selected':''?>><?=yv_esc($c)?></option><?php endforeach;?></select></div><div class="field"><label>Sales rep</label><select name="rep"><option value="">All reps</option><?php foreach($reps as $r):?><option value="<?=yv_esc($r)?>" <?=$rep===$r?'selected':''?>><?=yv_esc($r)?></option><?php endforeach;?></select></div><div class="field"><label>Variance view</label><select name="view"><option value="all" <?=$view==='all'?'selected':''?>>All</option><option value="up" <?=$view==='up'?'selected':''?>>Positive variance</option><option value="down" <?=$view==='down'?'selected':''?>>Negative variance</option><option value="new" <?=$view==='new'?'selected':''?>>New in selected year</option><option value="lost" <?=$view==='lost'?'selected':''?>>No selected-year volume</option></select></div><div class="field"><label>Sort by</label><select name="sort"><option value="customer" <?=$sort==='customer'?'selected':''?>>Customer</option><option value="variance_desc" <?=$sort==='variance_desc'?'selected':''?>>Variance high to low</option><option value="variance_asc" <?=$sort==='variance_asc'?'selected':''?>>Variance low to high</option><option value="current" <?=$sort==='current'?'selected':''?>><?=$selectedYear?> volume</option><option value="prior" <?=$sort==='prior'?'selected':''?>><?=$priorYear?> volume</option><option value="product" <?=$sort==='product'?'selected':''?>>Product description</option></select></div><div><button class="btn" type="submit">Run</button> <a class="btn alt" href="ytd-variance.php?year=<?=$selectedYear?>">Reset</a></div></form></section>
<section class="panel"><div class="info"><div><div class="title"><?=yv_num($totalRows)?> customer/product rows</div><div class="sub"><?=yv_esc($comparisonText)?>. Volumes are net pounds from invoices and credits, grouped by Customer + Product Description regardless of product code.</div></div><a class="btn excel" href="<?=yv_esc(yv_qs(['download'=>'xlsx','page'=>null]))?>">Download .xlsx</a></div><div class="tablewrap"><table><thead><tr><th class="left sticky1 w-cust">Customer</th><th class="left sticky2 w-prod">Product</th><th class="w-num"><?=yv_esc($currentLabel)?></th><th class="w-num"><?=yv_esc($priorLabel)?></th><th class="w-num">Variance</th><th class="w-num">Variance %</th></tr></thead><tbody><?php if(!$slice):?><tr><td colspan="6" style="text-align:center;padding:32px;color:#66768a">No rows match these filters.</td></tr><?php endif;?><?php foreach($slice as $r):$v=(float)$r['variance'];?><tr><td class="left sticky1 w-cust" data-label="Customer"><span class="cust"><?=yv_esc($r['customer'])?></span><?php if($r['customer_code']!==''):?><br><span style="font-size:10px;color:#7a8896"><?=yv_esc($r['customer_code'])?></span><?php endif;?></td><td class="left sticky2 w-prod prod" data-label="Product"><?=yv_esc($r['product'])?></td><td class="w-num" data-label="<?=yv_esc($currentLabel)?>"><?=abs((float)$r['current'])<0.00001?'-':yv_num($r['current'])?></td><td class="w-num" data-label="<?=yv_esc($priorLabel)?>"><?=abs((float)$r['prior'])<0.00001?'-':yv_num($r['prior'])?></td><td class="w-num <?=$v>0?'pos':($v<0?'neg':'zero')?>" data-label="Variance"><?=$v>0?'+':''?><?=abs($v)<0.00001?'-':yv_num($v)?></td><td class="w-num <?=$v>0?'pos':($v<0?'neg':'zero')?>" data-label="Variance %"><?=$r['variance_pct']===null?'N/A':(($r['variance_pct']>0?'+':'').number_format($r['variance_pct']*100,1).'%')?></td></tr><?php endforeach;?></tbody></table></div><?php if($totalPages>1):?><div class="pager"><?php if($page>1):?><a href="<?=yv_esc(yv_qs(['page'=>$page-1]))?>">Previous</a><?php endif;?><span class="on">Page <?=$page?> of <?=$totalPages?></span><?php if($page<$totalPages):?><a href="<?=yv_esc(yv_qs(['page'=>$page+1]))?>">Next</a><?php endif;?></div><?php endif;?></section>
<div class="foot">Source: <?=yv_esc(basename($workbook))?>. Latest invoice date in workbook: <?=yv_esc(yv_date($latestInvoiceDate))?>. Included <?=yv_num($rowsUsed)?> invoice/credit rows across the selected comparison periods, including <?=yv_num($creditRows)?> credit rows. Credits are netted against pounds.</div>
</main></body></html>
