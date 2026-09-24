<?php
session_start();
require __DIR__ . '/config/db.php';

function fail(string $message, int $code=400): never {
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Arial;background:#f4f7fa;padding:40px;color:#17212b}.box{max-width:760px;margin:auto;background:#fff;border:1px solid #d9e0e6;border-radius:14px;padding:24px}a{color:#174f86}</style><div class="box"><h1>RFQ could not be saved</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><p><a href="vendor-pricing-request.php">Return to the pricing request</a></p></div>';
    exit;
}
function post(string $key,string $default=''): string { return trim((string)($_POST[$key]??$default)); }
function nullableDecimal(string $v): ?string { return ($v!==''&&is_numeric($v))?$v:null; }
function parseEmails(string $v): array {
    if(trim($v)==='') return [];
    $out=[];
    foreach(preg_split('/[;,]+/',$v) as $e){$e=trim($e);if($e==='')continue;if(!filter_var($e,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid CC email: '.$e);$out[strtolower($e)]=$e;}
    return array_values($out);
}
function storedRequirements(string $application,string $notes): ?string {
    $parts=[];
    if(trim($application)!=='')$parts[]='Application: '.trim($application);
    if(trim($notes)!=='')$parts[]='Special Requirements / Notes: '.trim($notes);
    return $parts?implode("\n\n",$parts):null;
}

if($_SERVER['REQUEST_METHOD']!=='POST') fail('This page only accepts form submissions.',405);
if(empty($_SESSION['rfq_csrf'])||!hash_equals($_SESSION['rfq_csrf'],post('csrf_token'))) fail('The form session expired. Please reopen the request form.',403);

$action=post('submit_action','send_now');
if(!in_array($action,['save_draft','send_now'],true))$action='send_now';

$required=['requester_email'=>'Requested By','request_date'=>'Request Date','pricing_needed_by'=>'Pricing Needed By','product_name'=>'Product Name','packaging'=>'Product Packaging','quantity'=>'Quantity','quantity_uom'=>'Quantity UOM','price_basis'=>'Price Basis','ship_address'=>'Delivery Address','ship_city'=>'Delivery City','ship_state'=>'Delivery State','ship_zip'=>'ZIP Code'];
foreach($required as $key=>$label) if(post($key)==='') fail($label.' is required.');
if(!is_numeric(post('quantity'))||(float)post('quantity')<=0) fail('Quantity must be greater than zero.');
if(!filter_var(post('requester_email'),FILTER_VALIDATE_EMAIL)) fail('Requester email is invalid.');
if(!filter_var(post('reply_to','sourcing@lowechemical.com'),FILTER_VALIDATE_EMAIL)) fail('Reply-To email is invalid.');

$names=$_POST['supplier_name']??[];$contacts=$_POST['supplier_contact']??[];$emails=$_POST['supplier_email']??[];
if(!is_array($names)) fail('Supplier information is invalid.');
$suppliers=[];
foreach($names as $i=>$raw){
    $name=trim((string)$raw);if($name==='')continue;
    $email=trim((string)($emails[$i]??''));
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))fail('A supplier email address is invalid.');
    $suppliers[]=['name'=>$name,'contact'=>trim((string)($contacts[$i]??'')),'email'=>$email];
}
if(!$suppliers)fail('At least one supplier is required.');
if($action==='send_now')foreach($suppliers as $s)if($s['email']==='')fail('Every supplier needs an email address before the RFQ can be sent. Use Save Draft if contact information is not available yet.');

try{$additionalCc=parseEmails(post('additional_cc'));}catch(Throwable $e){fail($e->getMessage());}

$pdo=db();
try{
    $pdo->beginTransaction();

    $requesterStmt=$pdo->prepare('SELECT id,full_name,email,phone FROM lowe_personnel WHERE email=? AND is_active=1 LIMIT 1');
    $requesterStmt->execute([post('requester_email')]);
    $requester=$requesterStmt->fetch();
    if(!$requester){
        $csv=__DIR__.'/data/lowe-personnel.csv';$matched=null;
        if(is_file($csv)&&($fh=fopen($csv,'r'))){
            $headers=fgetcsv($fh);if($headers){$headers=array_map(fn($h)=>preg_replace('/^\xEF\xBB\xBF/','',trim((string)$h)),$headers);while(($row=fgetcsv($fh))!==false){$row=array_pad($row,count($headers),'');$a=array_combine($headers,array_slice($row,0,count($headers)));if(strcasecmp(trim((string)($a['Email']??'')),post('requester_email'))===0){$matched=$a;break;}}}fclose($fh);
        }
        if(!$matched)throw new RuntimeException('The selected Lowe employee could not be verified.');
        $pdo->prepare('INSERT INTO lowe_personnel(full_name,email,phone,is_active) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),phone=VALUES(phone),is_active=1')->execute([trim((string)($matched['Sales Rep']??post('requester_name'))),trim((string)($matched['Email']??post('requester_email'))),trim((string)($matched['Phone Number']??''))?:null]);
        $requesterStmt->execute([post('requester_email')]);$requester=$requesterStmt->fetch();
    }
    if(!$requester)throw new RuntimeException('The Lowe requester could not be loaded.');

    $productStmt=$pdo->prepare('SELECT id,product_number FROM products WHERE product_name=? LIMIT 1');
    $productStmt->execute([post('product_name')]);$product=$productStmt->fetch()?:null;

    $year=(int)date('Y');$seqStmt=$pdo->prepare('SELECT next_number FROM rfq_sequences WHERE sequence_year=? FOR UPDATE');$seqStmt->execute([$year]);$seq=$seqStmt->fetchColumn();
    if($seq===false){$number=1;$pdo->prepare('INSERT INTO rfq_sequences(sequence_year,next_number) VALUES(?,2)')->execute([$year]);}else{$number=(int)$seq;$pdo->prepare('UPDATE rfq_sequences SET next_number=? WHERE sequence_year=?')->execute([$number+1,$year]);}
    $rfqNumber=sprintf('RFQ-%04d-%04d',$year,$number);

    $allHaveEmail=!array_filter($suppliers,fn($s)=>$s['email']==='');$status=$allHaveEmail?'Ready for Email':'Draft';
    $sql='INSERT INTO rfqs (rfq_number,requester_id,requester_name,requester_email,requester_phone,request_date,pricing_needed_by,product_id,product_number,product_name,cas_number,product_grade,packaging,container_weight,container_weight_uom,quantity,quantity_uom,price_basis,estimated_annual_usage,requirement_type,ship_address,ship_city,ship_state,ship_zip,special_requirements,cc_requester,cc_sourcing,additional_cc,email_format,email_subject,reply_to,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
    $pdo->prepare($sql)->execute([$rfqNumber,(int)$requester['id'],$requester['full_name'],$requester['email'],$requester['phone'],post('request_date'),post('pricing_needed_by'),$product?(int)$product['id']:null,$product['product_number']??null,post('product_name'),post('cas_number')?:null,post('product_grade')?:null,post('packaging'),null,'LB',post('quantity'),post('quantity_uom'),post('price_basis'),post('estimated_annual_usage')?:null,post('requirement_type')?:null,post('ship_address'),post('ship_city'),strtoupper(post('ship_state')),post('ship_zip'),storedRequirements(post('application'),post('special_requirements')),isset($_POST['cc_requester'])?1:0,isset($_POST['cc_sourcing'])?1:0,$additionalCc?implode(', ',$additionalCc):null,post('email_format','email_pdf'),post('email_subject','Lowe Chemical Request for Pricing'),post('reply_to','sourcing@lowechemical.com'),$status]);
    $rfqId=(int)$pdo->lastInsertId();

    $lookup=$pdo->prepare('SELECT id FROM suppliers WHERE supplier_name=? LIMIT 1');
    $insert=$pdo->prepare('INSERT INTO rfq_suppliers(rfq_id,supplier_id,supplier_name,contact_name,contact_email,sort_order,email_status) VALUES(?,?,?,?,?,?,?)');
    foreach($suppliers as $i=>$s){$lookup->execute([$s['name']]);$sid=$lookup->fetchColumn();$insert->execute([$rfqId,$sid?:null,$s['name'],$s['contact']?:null,$s['email']?:null,$i+1,$s['email']!==''?'Ready':'Not Ready']);}
    $pdo->prepare('INSERT INTO rfq_status_history(rfq_id,old_status,new_status,changed_by,note) VALUES(?,NULL,?,?,?)')->execute([$rfqId,$status,$requester['email'],$action==='send_now'?'RFQ created and queued for immediate supplier email.':'RFQ saved as a draft.']);
    $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fail($e->getMessage(),500);}

$_SESSION['rfq_csrf']=bin2hex(random_bytes(24));

if($action==='send_now'){
    // Hand the newly created RFQ directly to the proven send-rfq.php workflow.
    // This keeps the user to a single click while preserving the same direct-to-supplier
    // email, PDF option, secure supplier token, Submit Pricing button, and delivery logging.
    $_SESSION['rfq_send_csrf']=bin2hex(random_bytes(24));
    $sendToken=$_SESSION['rfq_send_csrf'];
    ?><!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Sending RFQ</title>
    <style>
    *{box-sizing:border-box}body{margin:0;background:#f4f7fa;color:#17212b;font:15px/1.5 Arial,sans-serif;padding:18px}.box{max-width:720px;margin:52px auto;background:#fff;border:1px solid #d9e0e6;border-radius:14px;padding:28px;text-align:center;box-shadow:0 8px 24px rgba(18,31,43,.08)}h1{color:#0c2340;font-size:24px;margin:0 0 8px}.spinner{width:34px;height:34px;border:4px solid #d9e0e6;border-top-color:#17613c;border-radius:50%;margin:18px auto;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.status{color:#52616e;margin:0 0 18px}.fallback{margin-top:18px;padding-top:18px;border-top:1px solid #e1e7ec}.fallback p{font-size:13px;color:#66717d;margin:0 0 10px}button{width:auto;min-height:48px;border:0;background:#17613c;color:#fff;border-radius:9px;padding:12px 20px;font-weight:700;font-size:16px;cursor:pointer;touch-action:manipulation}button:disabled{opacity:.6;cursor:wait}@media(max-width:600px){body{padding:12px}.box{margin:18px auto;padding:22px 16px}h1{font-size:21px}button{width:100%}}
    </style></head>
    <body><div class="box"><h1>Creating and emailing <?= htmlspecialchars($rfqNumber,ENT_QUOTES,'UTF-8') ?></h1><div class="spinner" id="spinner"></div><p class="status" id="sendStatus">Your RFQ has been saved. The supplier email is being sent now.</p>
    <form id="sendForm" method="post" action="send-rfq.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($sendToken,ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="rfq_id" value="<?= (int)$rfqId ?>">
      <div class="fallback"><p>If your mobile browser does not continue automatically, tap the button below.</p><button type="submit" id="sendNowBtn">Send RFQ Now</button></div>
    </form></div>
    <script>
    (function(){
      const form=document.getElementById('sendForm');
      const btn=document.getElementById('sendNowBtn');
      const status=document.getElementById('sendStatus');
      let submitted=false;
      function sendNow(){
        if(submitted) return;
        submitted=true;
        btn.disabled=true;
        btn.textContent='Sending RFQ...';
        status.textContent='Connecting to Lowe Chemical email service...';
        if(typeof form.requestSubmit==='function') form.requestSubmit();
        else form.submit();
      }
      // A short delay is more reliable on mobile Safari/Chrome than firing during initial parsing.
      window.addEventListener('load',function(){setTimeout(sendNow,350);},{once:true});
      // Keep the button as a real user-initiated fallback for mobile browsers.
      form.addEventListener('submit',function(){submitted=true;btn.disabled=true;btn.textContent='Sending RFQ...';});
    })();
    </script></body></html><?php
    exit;
}

header('Location: rfq-view.php?id='.$rfqId.'&saved=1');exit;
