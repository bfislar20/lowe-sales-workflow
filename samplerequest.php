<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sample-schema.php';
date_default_timezone_set('America/Chicago');

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function clean($value, int $limit=500): string { $value=trim((string)$value); return function_exists('mb_substr')?mb_substr($value,0,$limit):substr($value,0,$limit); }
function sample_number(): string { return 'LS-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)); }

$pdo=db();
$sourceColumn=$pdo->query("SHOW COLUMNS FROM sample_records LIKE 'request_source'")->fetch();
if(!$sourceColumn)$pdo->exec("ALTER TABLE sample_records ADD request_source VARCHAR(30) NOT NULL DEFAULT 'Internal' AFTER status");
$casColumn=$pdo->query("SHOW COLUMNS FROM sample_records LIKE 'cas_number'")->fetch();
if(!$casColumn)$pdo->exec("ALTER TABLE sample_records ADD cas_number VARCHAR(80) NULL AFTER product_name");
$buyingColumn=$pdo->query("SHOW COLUMNS FROM sample_records LIKE 'currently_buying'")->fetch();
if(!$buyingColumn)$pdo->exec("ALTER TABLE sample_records ADD currently_buying VARCHAR(10) NULL AFTER application");
$supplierColumn=$pdo->query("SHOW COLUMNS FROM sample_records LIKE 'current_supplier'")->fetch();
if(!$supplierColumn)$pdo->exec("ALTER TABLE sample_records ADD current_supplier VARCHAR(180) NULL AFTER currently_buying");
$shippingAccountColumn=$pdo->query("SHOW COLUMNS FROM sample_records LIKE 'shipping_account_number'")->fetch();
if(!$shippingAccountColumn)$pdo->exec("ALTER TABLE sample_records ADD shipping_account_number VARCHAR(40) NULL AFTER carrier");

if(empty($_SESSION['customer_sample_csrf']))$_SESSION['customer_sample_csrf']=bin2hex(random_bytes(32));
if(empty($_SESSION['customer_sample_started']))$_SESSION['customer_sample_started']=time();
$csrf=$_SESSION['customer_sample_csrf'];
$errors=[];
$submitted=$_SESSION['customer_sample_confirmation']??null;
unset($_SESSION['customer_sample_confirmation']);

$form=[
 'customer_no'=>'','customer_company'=>'','contact_name'=>'','contact_email'=>'','contact_phone'=>'','ship_to'=>'',
 'product_number'=>'','product_name'=>'','cas_number'=>'','manufacturer'=>'','sample_quantity'=>'','sample_unit'=>'LB','packaging'=>'',
 'application'=>'','currently_buying'=>'','current_supplier'=>'','reason_for_sample'=>'','needed_by'=>'','sales_rep'=>'','shipping_method'=>'Customer Account','carrier'=>'','shipping_account_number'=>''
];
$units=['OZ','LB','G','KG','ML','GAL','EA','BAG','PAIL','DRUM','TOTE'];

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 foreach($form as $key=>$value)$form[$key]=clean($_POST[$key]??'',in_array($key,['ship_to','application','reason_for_sample'],true)?2000:250);
 if($form['shipping_method']==='')$form['shipping_method']='Customer Account';
 if(!hash_equals($csrf,(string)($_POST['csrf']??'')))$errors[]='This form expired. Please refresh the page and try again.';
 if(!empty($_POST['website']))$errors[]='The request could not be submitted.';
 if(time()-(int)($_SESSION['customer_sample_started']??time())<2)$errors[]='Please review the form and submit it again.';
 if(!empty($_SESSION['customer_sample_last'])&&time()-(int)$_SESSION['customer_sample_last']<20)$errors[]='Please wait a few seconds before sending another request.';
 if($form['customer_company']==='')$errors[]='Company name is required.';
 if($form['contact_name']==='')$errors[]='Contact name is required.';
 if(!filter_var($form['contact_email'],FILTER_VALIDATE_EMAIL))$errors[]='Please enter a valid email address.';
 if($form['contact_phone']==='')$errors[]='Phone number is required.';
 if($form['ship_to']==='')$errors[]='Shipping address is required.';
 if($form['ship_to']!==''&&preg_match('/\b(?:P(?:OST(?:AL)?)?[\s.]*O(?:FFICE)?|P[\s.]*O)[\s.]*BOX\b/i',$form['ship_to']))$errors[]='Samples cannot be shipped to a PO Box. Please enter a physical street address.';
 if($form['product_name']==='')$errors[]='Product name or description is required.';
 if($form['cas_number']==='')$errors[]='CAS number is required.';
 elseif(!preg_match('/^\d{2,7}-\d{2}-\d$/',$form['cas_number']))$errors[]='Please enter the CAS number in the standard format, such as 7732-18-5.';
 if($form['application']==='')$errors[]='Application information is required.';
 if(!in_array($form['currently_buying'],['Yes','No'],true))$errors[]='Please tell us whether you currently buy or source this product.';
 if($form['currently_buying']==='Yes'&&$form['current_supplier']==='')$errors[]='Please enter the company you currently buy or source this product from.';
 if($form['currently_buying']==='No')$form['current_supplier']='';
 if(!in_array($form['carrier'],['UPS','FedEx','DHL'],true))$errors[]='Please select UPS, FedEx, or DHL as the shipping company.';
 if($form['carrier']==='UPS'){
  $form['shipping_account_number']=strtoupper((string)preg_replace('/[^A-Za-z0-9]/','',$form['shipping_account_number']));
  if(!preg_match('/^[A-Z0-9]{6}$/',$form['shipping_account_number']))$errors[]='A UPS account number must contain exactly 6 letters or numbers.';
 }elseif($form['carrier']==='FedEx'){
  $form['shipping_account_number']=(string)preg_replace('/\D/','',$form['shipping_account_number']);
  if(!preg_match('/^\d{9}$/',$form['shipping_account_number']))$errors[]='A FedEx account number must contain exactly 9 digits.';
 }elseif($form['carrier']==='DHL'){
  $form['shipping_account_number']=(string)preg_replace('/\D/','',$form['shipping_account_number']);
  if(!preg_match('/^\d{9}$/',$form['shipping_account_number']))$errors[]='A DHL account number must contain exactly 9 digits.';
 }
 if($form['sample_quantity']===''||!is_numeric($form['sample_quantity'])||(float)$form['sample_quantity']<=0)$errors[]='Please enter a sample quantity greater than zero.';
 if(!in_array($form['sample_unit'],$units,true))$form['sample_unit']='LB';
 if(empty($_POST['consent']))$errors[]='Please confirm that the information is correct.';
 if($form['needed_by']!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$form['needed_by']))$errors[]='Please enter a valid needed-by date.';

 if(!$errors){
  $number=sample_number();
  $sql="INSERT INTO sample_records
   (sample_number,request_date,needed_by,status,request_source,sales_rep,customer_no,customer_company,contact_name,contact_email,contact_phone,ship_to,product_number,product_name,cas_number,manufacturer,sample_quantity,sample_unit,packaging,application,currently_buying,current_supplier,reason_for_sample,shipping_method,carrier,shipping_account_number,follow_up_date)
   VALUES
   (:sample_number,:request_date,:needed_by,'Requested','Customer Website',:sales_rep,:customer_no,:customer_company,:contact_name,:contact_email,:contact_phone,:ship_to,:product_number,:product_name,:cas_number,:manufacturer,:sample_quantity,:sample_unit,:packaging,:application,:currently_buying,:current_supplier,:reason_for_sample,:shipping_method,:carrier,:shipping_account_number,:follow_up_date)";
  $params=[
   ':sample_number'=>$number,':request_date'=>date('Y-m-d'),':needed_by'=>$form['needed_by']?:null,':sales_rep'=>$form['sales_rep'],
   ':customer_no'=>$form['customer_no'],':customer_company'=>$form['customer_company'],':contact_name'=>$form['contact_name'],
   ':contact_email'=>$form['contact_email'],':contact_phone'=>$form['contact_phone'],':ship_to'=>$form['ship_to'],
   ':product_number'=>$form['product_number'],':product_name'=>$form['product_name'],':cas_number'=>$form['cas_number'],':manufacturer'=>$form['manufacturer'],
   ':sample_quantity'=>(float)$form['sample_quantity'],':sample_unit'=>$form['sample_unit'],':packaging'=>$form['packaging'],
   ':application'=>$form['application'],':currently_buying'=>$form['currently_buying'],':current_supplier'=>$form['current_supplier'],':reason_for_sample'=>$form['reason_for_sample'],':shipping_method'=>$form['shipping_method'],':carrier'=>$form['carrier'],':shipping_account_number'=>$form['shipping_account_number'],
   ':follow_up_date'=>date('Y-m-d',strtotime('+7 days'))
  ];
  try{
   $pdo->prepare($sql)->execute($params);
   $_SESSION['customer_sample_last']=time();
   $safeSubject=preg_replace('/[\r\n]+/',' ','New Website Sample Request '.$number.' - '.$form['customer_company']);
   $message='<html><body style="font-family:Arial,sans-serif;color:#1d2935;line-height:1.5">';
   $message.='<div style="border-top:5px solid #D71920;padding-top:16px"><img src="https://lowechemical.com/images/lowe-logo.png" alt="Lowe Chemical Company" style="max-width:420px;width:100%;height:auto"><h2 style="color:#0B2A5B">Website Sample Request</h2></div>';
   $message.='<p><strong>Sample Number:</strong> '.h($number).'<br><strong>Requested:</strong> '.h(date('F j, Y')).'<br><strong>Needed By:</strong> '.h($form['needed_by']?:'Not specified').'</p>';
   $message.='<h3 style="color:#0B2A5B">Customer</h3><p><strong>'.h($form['customer_company']).'</strong><br>'.h($form['contact_name']).'<br>'.h($form['contact_email']).'<br>'.h($form['contact_phone']).'<br>'.nl2br(h($form['ship_to'])).'</p>';
   $message.='<h3 style="color:#0B2A5B">Requested Sample</h3><p><strong>Product:</strong> '.h($form['product_name']).'<br><strong>Product Number:</strong> '.h($form['product_number']?:'Not provided').'<br><strong>CAS Number:</strong> '.h($form['cas_number']).'<br><strong>Manufacturer:</strong> '.h($form['manufacturer']?:'Not specified').'<br><strong>Quantity:</strong> '.h($form['sample_quantity'].' '.$form['sample_unit']).'<br><strong>Packaging:</strong> '.h($form['packaging']?:'Not specified').'</p>';
   $message.='<p><strong>Application:</strong><br>'.nl2br(h($form['application'])).'</p>';
   $message.='<p><strong>Currently buying or sourcing this product:</strong> '.h($form['currently_buying']).($form['currently_buying']==='Yes'?'<br><strong>Current supplier:</strong> '.h($form['current_supplier']):'').'</p>';
   $message.='<p><strong>Shipping Company:</strong> '.h($form['carrier']).'<br><strong>Customer Shipping Account:</strong> '.h($form['shipping_account_number']).'</p>';
   if($form['reason_for_sample']!=='')$message.='<p><strong>Reason for Request:</strong><br>'.nl2br(h($form['reason_for_sample'])).'</p>';
   if($form['sales_rep']!=='')$message.='<p><strong>Lowe Sales Representative:</strong> '.h($form['sales_rep']).'</p>';
   $message.='</body></html>';
   $headers=['MIME-Version: 1.0','Content-Type: text/html; charset=UTF-8','From: Lowe Chemical Company <sales@lowechemical.com>','Reply-To: '.$form['contact_email']];
   $internalEmailed=@mail('sales@lowechemical.com',$safeSubject,$message,implode("\r\n",$headers));
   if(!$internalEmailed)error_log('Customer sample request '.$number.' was saved, but the sales email was not sent.');
   $customerSubject='We received your Lowe Chemical sample request '.$number;
   $customerMessage=str_replace('<h2 style="color:#0B2A5B">Website Sample Request</h2>','<h2 style="color:#0B2A5B">Your Sample Request Has Been Received</h2><p>Thank you. Our team will review your request and contact you if we need more information.</p>',$message);
   $customerHeaders=['MIME-Version: 1.0','Content-Type: text/html; charset=UTF-8','From: Lowe Chemical Company <sales@lowechemical.com>','Reply-To: sales@lowechemical.com'];
   $customerEmailed=@mail($form['contact_email'],$customerSubject,$customerMessage,implode("\r\n",$customerHeaders));
   if(!$customerEmailed)error_log('Customer confirmation for sample request '.$number.' was not sent.');
   $_SESSION['customer_sample_confirmation']=['number'=>$number,'name'=>$form['contact_name'],'email'=>$form['contact_email'],'emailed'=>$customerEmailed];
   $_SESSION['customer_sample_csrf']=bin2hex(random_bytes(32));
   unset($_SESSION['customer_sample_started']);
   header('Location: samplerequest.php?submitted=1');exit;
  }catch(Throwable $e){
   error_log('Customer sample request error: '.$e->getMessage());
   $errors[]='We could not save your request. Please call Lowe Chemical at 800-837-5693.';
  }
 }
}
?>
<!doctype html>
<html lang="en">
<head>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width,initial-scale=1">
 <title>Request a Product Sample | Lowe Chemical Company</title>
 <meta name="description" content="Request a chemical product sample from Lowe Chemical Company.">
 <style>
 :root{--navy:#0B2A5B;--blue:#174F8A;--red:#D71920;--green:#237a45;--bg:#f2f5f7;--line:#cad6df;--muted:#5e707d}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,sans-serif;color:#1d2935}.page{max-width:980px;margin:auto;padding:24px}.hero{background:linear-gradient(135deg,var(--navy),#16477f);border-top:6px solid var(--red);border-radius:14px 14px 0 0;padding:24px;color:#fff}.logo{display:block;width:240px;max-width:75%;max-height:80px;object-fit:contain;background:#fff;border-radius:8px;padding:10px 14px;margin-bottom:20px}.hero h1{font-size:30px;margin:0}.hero p{margin:8px 0 0;color:#dce8f1;line-height:1.5}.form-card{background:#fff;border:1px solid var(--line);border-top:0;border-radius:0 0 14px 14px;padding:26px;box-shadow:0 10px 30px #0b2a5b12}.section{border:0;padding:0;margin:0 0 24px}.section legend{width:100%;border-bottom:2px solid #dce5eb;padding:0 0 8px;margin-bottom:15px;font-size:18px;font-weight:800;color:var(--navy)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.full{grid-column:1/-1}label{display:block;font-size:13px;font-weight:700;color:#394d5c;margin-bottom:5px}.required{color:var(--red)}input,select,textarea{width:100%;border:1px solid #aebfca;border-radius:7px;padding:11px 12px;font:inherit;color:#1d2935;background:#fff}textarea{min-height:90px;resize:vertical}input:focus,select:focus,textarea:focus{outline:3px solid #9dc7e655;border-color:var(--blue)}.help{font-size:12px;color:var(--muted);margin:5px 0 0;line-height:1.4}.choice{display:flex;gap:18px;align-items:center;min-height:43px}.choice label{display:flex;gap:7px;align-items:center;margin:0;font-weight:600}.choice input{width:18px;height:18px}.hidden{display:none}.check{display:flex;align-items:flex-start;gap:9px;font-weight:400;font-size:13px;line-height:1.45}.check input{width:19px;height:19px;margin:1px 0 0;flex:0 0 auto}.submit{width:100%;border:0;border-radius:8px;padding:14px;background:var(--navy);color:#fff;font-size:16px;font-weight:800;cursor:pointer}.submit:hover{background:#174F8A}.errors{background:#fbe9e9;color:#8e2025;border:1px solid #e8b8ba;border-radius:8px;padding:13px 16px;margin-bottom:20px}.errors ul{margin:5px 0 0;padding-left:20px}.success{background:#fff;border-top:6px solid var(--green);border-radius:14px;padding:34px;text-align:center;box-shadow:0 10px 30px #0b2a5b12}.success .logo{margin:0 auto 22px}.success h1{color:var(--navy);margin:0 0 10px}.number{display:inline-block;background:#e8f5ec;color:#23683a;border-radius:24px;padding:9px 14px;font-weight:800;margin:10px 0}.workflow-link{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:8px;padding:9px 12px;font-weight:800;font-size:13px;background:#fff;color:var(--navy);border:1px solid #d6e0e8;margin-bottom:14px}.workflow-link:hover{background:#eef4f8}.footer{text-align:center;color:var(--muted);font-size:12px;line-height:1.6;padding:20px 10px}.trap{position:absolute!important;left:-10000px!important;width:1px!important;height:1px!important;overflow:hidden!important}@media(max-width:680px){.page{padding:10px}.hero,.form-card{padding:20px}.grid{grid-template-columns:1fr}.full{grid-column:auto}.hero h1{font-size:25px}}
 </style>
</head>
<body><main class="page">
<?php if($submitted):?>
 <section class="success"><a class="workflow-link" href="salesworkflow.php">← Sales Workflow</a><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><h1>Your sample request has been received</h1><p>Thank you, <?=h($submitted['name'])?>. Your request number is:</p><div class="number"><?=h($submitted['number'])?></div><p><?php if(!empty($submitted['emailed'])):?>We sent a confirmation to <?=h($submitted['email'])?>. <?php endif;?>A member of the Lowe Chemical team will review your request and contact you if more information is needed.</p><p><a href="samplerequest.php">Request another sample</a></p></section>
<?php else:?>
 <a class="workflow-link" href="salesworkflow.php">← Sales Workflow</a><header class="hero"><img class="logo" src="/images/lowe-logo.png" alt="Lowe Chemical Company"><h1>Request a Product Sample</h1><p>Tell us what you need and where the sample should be sent. Our team will review your request and contact you if we need more information.</p></header>
 <form class="form-card" method="post" novalidate><input type="hidden" name="csrf" value="<?=h($csrf)?>"><div class="trap" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
 <?php if($errors):?><div class="errors"><strong>Please correct the following:</strong><ul><?php foreach($errors as $error):?><li><?=h($error)?></li><?php endforeach;?></ul></div><?php endif;?>
 <fieldset class="section"><legend>Your Information</legend><div class="grid">
  <div><label>Company Name <span class="required">*</span></label><input name="customer_company" required autocomplete="organization" value="<?=h($form['customer_company'])?>"></div>
  <div><label>Lowe Customer Number</label><input name="customer_no" value="<?=h($form['customer_no'])?>"><p class="help">Leave blank if you do not know it.</p></div>
  <div><label>Contact Name <span class="required">*</span></label><input name="contact_name" required autocomplete="name" value="<?=h($form['contact_name'])?>"></div>
  <div><label>Email Address <span class="required">*</span></label><input type="email" name="contact_email" required autocomplete="email" value="<?=h($form['contact_email'])?>"></div>
  <div><label>Phone Number <span class="required">*</span></label><input type="tel" name="contact_phone" required autocomplete="tel" value="<?=h($form['contact_phone'])?>"></div>
  <div><label>Your Lowe Sales Representative</label><input name="sales_rep" value="<?=h($form['sales_rep'])?>"><p class="help">Enter the name if known.</p></div>
  <div class="full"><label>Complete Shipping Address <span class="required">*</span></label><textarea name="ship_to" required autocomplete="shipping street-address" placeholder="Company, physical street address, city, state, and ZIP code"><?=h($form['ship_to'])?></textarea><p class="help"><strong>Samples cannot be shipped to a PO Box.</strong> A physical street address is required.</p></div>
 </div></fieldset>
 <fieldset class="section"><legend>Sample Information</legend><div class="grid">
  <div class="full"><label>Product Name or Description <span class="required">*</span></label><input name="product_name" required value="<?=h($form['product_name'])?>" placeholder="Enter the product you would like to sample"></div>
  <div><label>Product Number</label><input name="product_number" value="<?=h($form['product_number'])?>"></div>
  <div><label>CAS Number <span class="required">*</span></label><input name="cas_number" required inputmode="numeric" value="<?=h($form['cas_number'])?>" placeholder="Example: 7732-18-5"></div>
  <div><label>Preferred Manufacturer</label><input name="manufacturer" value="<?=h($form['manufacturer'])?>"></div>
  <div><label>Requested Quantity <span class="required">*</span></label><input type="number" min="0.001" step="0.001" name="sample_quantity" required value="<?=h($form['sample_quantity'])?>"></div>
  <div><label>Unit <span class="required">*</span></label><select name="sample_unit"><?php foreach($units as $unit):?><option <?=$form['sample_unit']===$unit?'selected':''?>><?=h($unit)?></option><?php endforeach;?></select></div>
  <div><label>Preferred Packaging</label><input name="packaging" value="<?=h($form['packaging'])?>" placeholder="Example: bottle or gallon jug"></div>
  <div><label>Needed By</label><input type="date" name="needed_by" min="<?=h(date('Y-m-d'))?>" value="<?=h($form['needed_by'])?>"></div>
  <div><label>Shipping Company <span class="required">*</span></label><select id="carrier" name="carrier" required><option value="">Select a shipping company</option><option value="UPS" <?=$form['carrier']==='UPS'?'selected':''?>>UPS</option><option value="FedEx" <?=$form['carrier']==='FedEx'?'selected':''?>>FedEx</option><option value="DHL" <?=$form['carrier']==='DHL'?'selected':''?>>DHL</option></select></div>
  <div><label>Shipping Account Number <span class="required">*</span></label><input id="shippingAccount" name="shipping_account_number" required autocomplete="off" value="<?=h($form['shipping_account_number'])?>" placeholder="Select the shipping company first"><p class="help" id="accountHelp">UPS accounts contain 6 letters or numbers. FedEx and DHL accounts contain 9 digits.</p></div>
  <div class="full"><label>How will the product be used? <span class="required">*</span></label><textarea name="application" required placeholder="Briefly describe your application or process."><?=h($form['application'])?></textarea></div>
  <div class="full"><label>Are you currently buying or sourcing this product? <span class="required">*</span></label><div class="choice"><label><input type="radio" name="currently_buying" value="Yes" <?=$form['currently_buying']==='Yes'?'checked':''?> required> Yes</label><label><input type="radio" name="currently_buying" value="No" <?=$form['currently_buying']==='No'?'checked':''?> required> No</label></div></div>
  <div class="full <?=$form['currently_buying']==='Yes'?'':'hidden'?>" id="supplierField"><label>Who are you currently buying or sourcing it from? <span class="required">*</span></label><input id="currentSupplier" name="current_supplier" value="<?=h($form['current_supplier'])?>" placeholder="Enter the supplier or manufacturer name"></div>
  <div class="full"><label>Reason for Request</label><textarea name="reason_for_sample" placeholder="Examples: product trial, new formulation, alternate source, or quality approval"><?=h($form['reason_for_sample'])?></textarea></div>
 </div></fieldset>
 <fieldset class="section"><legend>Submit Your Request</legend><label class="check"><input type="checkbox" name="consent" value="1" required><span>I confirm that the information above is correct. I understand that sample availability, quantity, and shipping method are subject to review by Lowe Chemical Company.</span></label></fieldset>
 <button class="submit" type="submit">Submit Sample Request</button><p class="help" style="text-align:center;margin-top:10px">Required fields are marked with an asterisk.</p>
 </form>
<?php endif;?>
<footer class="footer"><strong>Lowe Chemical Company</strong><br>8300 Baker Ave., Cleveland, OH 44102<br>216-961-4222 · 800-837-5693 · sales@lowechemical.com</footer>
</main><script>
const buying=document.querySelectorAll('input[name="currently_buying"]');
const supplierField=document.getElementById('supplierField');
const supplier=document.getElementById('currentSupplier');
function updateSupplier(){if(!supplierField)return;const yes=document.querySelector('input[name="currently_buying"]:checked')?.value==='Yes';supplierField.classList.toggle('hidden',!yes);supplier.required=yes;if(!yes)supplier.value='';}
buying.forEach(el=>el.addEventListener('change',updateSupplier));updateSupplier();
const carrier=document.getElementById('carrier');
const shippingAccount=document.getElementById('shippingAccount');
const accountHelp=document.getElementById('accountHelp');
function updateAccountFormat(){if(!carrier||!shippingAccount)return;if(carrier.value==='UPS'){shippingAccount.placeholder='6 letters or numbers';shippingAccount.maxLength=6;shippingAccount.pattern='[A-Za-z0-9]{6}';shippingAccount.inputMode='text';accountHelp.textContent='Enter the 6-character UPS account number.';}else if(carrier.value==='FedEx'||carrier.value==='DHL'){shippingAccount.placeholder='9 digits';shippingAccount.maxLength=9;shippingAccount.pattern='[0-9]{9}';shippingAccount.inputMode='numeric';accountHelp.textContent='Enter the 9-digit '+carrier.value+' account number.';}else{shippingAccount.placeholder='Select the shipping company first';shippingAccount.removeAttribute('pattern');shippingAccount.removeAttribute('maxlength');accountHelp.textContent='UPS accounts contain 6 letters or numbers. FedEx and DHL accounts contain 9 digits.';}}
if(carrier){carrier.addEventListener('change',function(){shippingAccount.value='';updateAccountFormat();});updateAccountFormat();}
</script></body></html>
