<?php
require_once __DIR__ . '/opportunity-config/app.php';
require_once __DIR__ . '/includes/workflow-nav.php';
opp_require_device();
if (!opp_device()) { header('Location: opportunities.php'); exit; }

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_opportunity'])) {
    if (!opp_check_csrf($_POST['csrf'] ?? '')) die('Invalid request token.');

    // Product lines become the financial source whenever volume and sell price are entered.
    $products = $_POST['products'] ?? [];
    $normalizedProducts = [];
    $productRevenue = 0.0;
    $productCost = 0.0;
    $hasPricedProduct = false;
    $allProductCostsKnown = true;

    if (is_array($products)) {
        foreach ($products as $p) {
            if (trim($p['product_name'] ?? '') === '') continue;
            $vol = opp_num($p['annual_volume'] ?? 0);
            $sell = opp_num($p['est_sell_price'] ?? 0);
            $pcost = opp_num($p['est_cost'] ?? 0);
            $prev = ($vol > 0 && $sell > 0) ? $vol * $sell : 0.0;
            $lineCost = ($vol > 0 && $pcost > 0) ? $vol * $pcost : 0.0;
            $pgp = ($prev > 0 && $pcost > 0) ? $prev - $lineCost : 0.0;

            if ($prev > 0) {
                $hasPricedProduct = true;
                $productRevenue += $prev;
                if ($pcost > 0) $productCost += $lineCost;
                else $allProductCostsKnown = false;
            }

            $normalizedProducts[] = [
                'product_no' => trim($p['product_no'] ?? ''),
                'product_name' => trim($p['product_name'] ?? ''),
                'cas_no' => trim($p['cas_no'] ?? ''),
                'supplier' => trim($p['supplier'] ?? ''),
                'packaging' => trim($p['packaging'] ?? ''),
                'annual_volume' => $vol,
                'volume_unit' => trim($p['volume_unit'] ?? ''),
                'est_sell_price' => $sell,
                'est_cost' => $pcost,
                'est_annual_revenue' => $prev,
                'est_annual_gp' => $pgp,
            ];
        }
    }

    $manualRevenue = opp_num($_POST['estimated_annual_revenue'] ?? 0);
    $manualCost = opp_num($_POST['estimated_annual_cost'] ?? 0);
    $revenue = $hasPricedProduct ? $productRevenue : $manualRevenue;

    // If every priced product has a cost, calculate annual cost from products.
    // Otherwise keep a manually entered annual cost if one was supplied.
    if ($hasPricedProduct && $allProductCostsKnown) $cost = $productCost;
    elseif ($manualCost > 0) $cost = $manualCost;
    else $cost = 0.0;

    // Do not report all revenue as GP when cost is unknown.
    $gp = $cost > 0 ? $revenue - $cost : 0.0;
    $margin = ($revenue > 0 && $cost > 0) ? ($gp / $revenue) * 100 : 0.0;
    $newStage = trim($_POST['stage'] ?? 'Lead');
    $manualProbability = isset($_POST['manual_probability']);
    $prob = $manualProbability ? max(0, min(100, opp_num($_POST['probability'] ?? 10))) : opp_default_probability($newStage);
    $status = opp_status_for_stage($newStage);
    $weighted = $revenue * ($prob / 100);
    $oppNo = trim($_POST['opportunity_no'] ?? '') ?: opp_new_number();

    $sql = "INSERT INTO opportunities
      (opportunity_no,company_name,customer_no,primary_contact,contact_title,department,phone,email,address,city,state,zipcode,lowe_rep,stage,status,probability,expected_close_date,desired_start_date,next_action,next_followup_date,estimated_annual_revenue,estimated_annual_cost,estimated_annual_gp,estimated_margin_pct,weighted_pipeline_value,industry_application,opportunity_summary,success_definition,internal_notes)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt = opp_db()->prepare($sql);
    $stmt->execute([
        $oppNo,
        trim($_POST['company_name'] ?? ''),
        trim($_POST['customer_no'] ?? ''),
        trim($_POST['primary_contact'] ?? ''),
        trim($_POST['contact_title'] ?? ''),
        trim($_POST['department'] ?? ''),
        trim($_POST['phone'] ?? ''),
        trim($_POST['email'] ?? ''),
        trim($_POST['address'] ?? ''),
        trim($_POST['city'] ?? ''),
        trim($_POST['state'] ?? ''),
        trim($_POST['zipcode'] ?? ''),
        trim($_POST['lowe_rep'] ?? ''),
        $newStage,
        $status,
        $prob,
        $_POST['expected_close_date'] ?: null,
        $_POST['desired_start_date'] ?: null,
        trim($_POST['next_action'] ?? ''),
        $_POST['next_followup_date'] ?: null,
        $revenue,$cost,$gp,$margin,$weighted,
        trim($_POST['industry_application'] ?? ''),
        trim($_POST['opportunity_summary'] ?? ''),
        trim($_POST['success_definition'] ?? ''),
        trim($_POST['internal_notes'] ?? '')
    ]);
    $id = (int)opp_db()->lastInsertId();

    if ($normalizedProducts) {
        $pstmt = opp_db()->prepare("INSERT INTO opportunity_products (opportunity_id,product_no,product_name,cas_no,supplier,packaging,annual_volume,volume_unit,est_sell_price,est_cost,est_annual_revenue,est_annual_gp) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($normalizedProducts as $p) {
            $pstmt->execute([$id,$p['product_no'],$p['product_name'],$p['cas_no'],$p['supplier'],$p['packaging'],$p['annual_volume'],$p['volume_unit'],$p['est_sell_price'],$p['est_cost'],$p['est_annual_revenue'],$p['est_annual_gp']]);
        }
    }

    opp_db()->prepare("INSERT INTO opportunity_stage_history (opportunity_id,from_stage,to_stage,changed_by) VALUES (?,NULL,?,?)")
        ->execute([$id,$newStage,trim($_POST['lowe_rep'] ?? '')]);

    header('Location: opportunity.php?id=' . $id . '&created=1');
    exit;
}
$device = opp_device();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>New Opportunity - Lowe Chemical</title>
<style>
:root{--navy:#071f45;--red:#d20f18;--light:#f5f7fb;--line:#d6dbe6;--text:#07152e}
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#eef2f7;color:var(--text)}.page{max-width:1180px;margin:auto;background:#fff;min-height:100vh;padding:18px}.top{display:flex;justify-content:space-between;align-items:center;border-bottom:5px solid var(--navy);padding-bottom:14px;gap:16px}.brand{display:flex;gap:18px;align-items:center}.logo{width:190px;max-width:40vw}.top h1{margin:0;color:var(--navy);font-size:28px}.small{font-size:13px;color:#58657a}.btn{display:inline-block;padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:700;border:0;cursor:pointer}.navy{background:var(--navy);color:#fff}.red{background:var(--red);color:#fff}.light{background:#e8edf5;color:var(--navy)}.section{margin-top:16px;border:1px solid var(--line);border-radius:10px;overflow:visible;position:relative}.section-title{background:var(--navy);color:#fff;font-weight:700;padding:10px 14px;text-transform:uppercase}.grid{display:grid;gap:12px;padding:14px}.g3{grid-template-columns:repeat(3,1fr)}.g2{grid-template-columns:repeat(2,1fr)}label{display:block;font-weight:700;font-size:13px;margin-bottom:5px}input,select,textarea{width:100%;border:1px solid #cbd2df;border-radius:7px;padding:10px;font-size:15px;background:#fff}textarea{min-height:90px;resize:vertical}.products{padding:14px}.product-row{display:grid;grid-template-columns:1.35fr .75fr .8fr .72fr .8fr .6fr .85fr .85fr;gap:8px;margin-bottom:8px}.actions{display:flex;gap:10px;justify-content:center;padding:18px;flex-wrap:wrap}.lookup-wrap{position:relative;z-index:5000}.lookup-wrap:focus-within{z-index:5001}.lookup-results{position:absolute;z-index:99999;left:0;top:calc(100% + 5px);width:max(100%,520px);max-width:min(720px,82vw);background:#fff;border:2px solid var(--navy);border-radius:10px;box-shadow:0 14px 34px rgba(7,31,69,.24);max-height:360px;overflow-y:auto;display:none}.lookup-item{padding:12px 14px;border-bottom:1px solid #dfe5ee;cursor:pointer;background:#fff;line-height:1.25}.lookup-item:last-child{border-bottom:0}.lookup-item:hover,.lookup-item:focus{background:#eaf2fb}.lookup-item strong{display:block;color:var(--navy);font-size:15px;margin-bottom:3px}.lookup-item small{display:block;color:#4e5f75;font-size:12px;white-space:normal}.product-results{width:560px;max-width:min(760px,86vw)}.lookup-note{font-size:12px;color:#657288;margin-top:5px}@media(max-width:700px){.lookup-results,.product-results{position:fixed!important;left:10px!important;right:10px!important;top:90px!important;bottom:auto!important;width:auto!important;max-width:none!important;max-height:58vh!important;z-index:2147483000!important;border-width:2px;box-shadow:0 18px 50px rgba(0,0,0,.35);-webkit-overflow-scrolling:touch}.lookup-item{padding:14px 15px;font-size:16px;touch-action:manipulation}.lookup-item strong{font-size:16px}.lookup-item small{font-size:13px}.product-row,.product-edit{position:relative;z-index:20}}
<?php if($device==='mobile'): ?>.page{padding:10px}.top,.brand{display:block;text-align:center}.logo{width:210px;max-width:80vw}.top h1{font-size:24px;margin-top:8px}.g3,.g2,.product-row{grid-template-columns:1fr}.section-title{text-align:center}.actions .btn{width:100%;text-align:center}<?php else: ?>@media(max-width:850px){.g3,.g2,.product-row{grid-template-columns:1fr}}<?php endif; ?>
</style></head><body><main class="page">
<div class="top"><div class="brand"><img class="logo" src="images/lowelogo1.png" alt="Lowe Chemical"><div><h1>New Opportunity</h1><div class="small">Lowe Chemical Opportunity Pipeline</div></div></div><div><?=workflow_back_link('btn light')?> <a class="btn light" href="opportunities.php">Pipeline</a> <a class="btn light" href="?change_device=1">Change Device</a></div></div>
<form method="post"><input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>"><input type="hidden" name="save_opportunity" value="1">
<section class="section"><div class="section-title">Customer / Opportunity</div><div class="grid g3">
<div><label>Opportunity #</label><input name="opportunity_no" value="<?=opp_h(opp_new_number())?>"></div>
<div class="lookup-wrap"><label>Company Name *</label><input id="customerLookup" name="company_name" autocomplete="off" placeholder="Start typing customer name, number, city or ZIP" required><input type="hidden" name="customer_no" id="customerNo"><div id="customerResults" class="lookup-results"></div><div class="lookup-note">Select an existing Lowe customer, or type a new prospect name.</div></div>
<div class="lookup-wrap"><label>Lowe Rep</label><input id="repLookup" name="lowe_rep" autocomplete="off" placeholder="Start typing rep name"><div id="repResults" class="lookup-results"></div></div>
<div><label>Primary Contact</label><input id="primaryContact" name="primary_contact"></div><div><label>Contact Title</label><input id="contactTitle" name="contact_title"></div><div><label>Department</label><input id="department" name="department"></div><div><label>Phone</label><input id="customerPhone" name="phone"></div><div><label>Email</label><input id="customerEmail" type="email" name="email"></div>
<div><label>Address</label><input id="customerAddress" name="address"></div><div><label>City</label><input id="customerCity" name="city"></div><div><label>State</label><input id="customerState" name="state"></div><div><label>ZIP</label><input id="customerZip" name="zipcode"></div>
<div><label>Industry / Application</label><input name="industry_application"></div><div><label>Desired Start Date</label><input type="date" name="desired_start_date"></div>
</div></section>
<section class="section"><div class="section-title">Pipeline</div><div class="grid g3">
<div><label>Stage</label><select id="stageSelect" name="stage"><?php foreach(opp_stages() as $s):?><option><?=opp_h($s)?></option><?php endforeach;?></select></div>
<div><label>Status</label><input id="statusDisplay" value="Open" disabled><div class="lookup-note">Set automatically from stage.</div></div>
<div><label>Probability %</label><input id="probabilityInput" name="probability" type="number" min="0" max="100" step="1" value="10"><label style="margin-top:7px;text-transform:none;font-weight:400"><input id="manualProbability" type="checkbox" name="manual_probability" value="1" style="width:auto;margin-right:6px">Manual probability override</label></div>
<div><label>Expected Close Date</label><input type="date" name="expected_close_date"></div><div><label>Next Action</label><input name="next_action"></div><div><label>Next Follow-Up</label><input type="date" name="next_followup_date"></div>
</div></section>
<section class="section"><div class="section-title">Financial Opportunity</div><div class="grid g3"><div><label>Estimated Annual Revenue</label><input name="estimated_annual_revenue" placeholder="$"></div><div><label>Estimated Annual Cost</label><input name="estimated_annual_cost" placeholder="$"></div><div><label>Gross Profit / Margin</label><input disabled value="Calculated when saved"></div></div><div class="lookup-note" style="padding:0 14px 14px">When product annual volume and sell price are entered, product lines automatically determine annual revenue. Product cost determines GP when entered; otherwise a manually entered annual cost is used.</div></section>
<section class="section products-section" style="z-index:100"><div class="section-title">Products</div><div class="products" id="products"><div class="product-row">
<div class="lookup-wrap"><input class="product-lookup" data-index="0" name="products[0][product_name]" placeholder="Product / chemical" autocomplete="off"><div class="lookup-results product-results"></div></div><input name="products[0][product_no]" placeholder="Product #"><input name="products[0][supplier]" placeholder="Supplier"><input name="products[0][packaging]" placeholder="Packaging"><input name="products[0][annual_volume]" placeholder="Annual volume"><input name="products[0][volume_unit]" placeholder="Unit"><input name="products[0][est_sell_price]" placeholder="Est. sell $/unit"><input name="products[0][est_cost]" placeholder="Est. cost $/unit"></div></div><div style="padding:0 14px 14px"><button type="button" class="btn light" onclick="addProduct()">+ Add Product</button></div></section>
<section class="section"><div class="section-title">Opportunity Details</div><div class="grid g2"><div><label>Opportunity Summary</label><textarea name="opportunity_summary"></textarea></div><div><label>What Would Success Look Like?</label><textarea name="success_definition"></textarea></div><div style="grid-column:1/-1"><label>Internal Notes</label><textarea name="internal_notes"></textarea></div></div></section>
<div class="actions"><button class="btn red" type="submit">Save Opportunity</button><a class="btn light" href="opportunities.php">Cancel</a></div>
</form></main>
<script>
const stageDefaults = <?=json_encode(opp_stage_probabilities())?>;
const stageStatus = {'Won':'Won','Lost':'Lost','On Hold':'On Hold'};
const stageSelect=document.getElementById('stageSelect'),probabilityInput=document.getElementById('probabilityInput'),manualProbability=document.getElementById('manualProbability'),statusDisplay=document.getElementById('statusDisplay');
function syncStageDefaults(){if(!stageSelect)return;const st=stageSelect.value;if(statusDisplay)statusDisplay.value=stageStatus[st]||'Open';if(probabilityInput && manualProbability && !manualProbability.checked)probabilityInput.value=stageDefaults[st]??10;}
if(stageSelect)stageSelect.addEventListener('change',syncStageDefaults);
if(probabilityInput)probabilityInput.addEventListener('input',()=>{if(manualProbability)manualProbability.checked=true;});
if(manualProbability)manualProbability.addEventListener('change',()=>{if(!manualProbability.checked)syncStageDefaults();});
syncStageDefaults();
let productIndex=1;
const debounce=(fn,delay=220)=>{let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),delay)}};
async function apiSearch(url,q){const r=await fetch(url+'?q='+encodeURIComponent(q),{cache:'no-store'});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'Lookup failed');return j.results||[];}
function showResults(box,items,render,onPick){box.innerHTML='';if(!items.length){box.innerHTML='<div class="lookup-item">No matches found. You can continue typing a new value.</div>';box.style.display='block';return;}items.forEach(item=>{const d=document.createElement('div');d.className='lookup-item';d.setAttribute('role','button');d.tabIndex=0;d.innerHTML=render(item);const choose=(ev)=>{if(ev){ev.preventDefault();ev.stopPropagation();}onPick(item);box.style.display='none';};d.addEventListener('pointerdown',choose);d.addEventListener('keydown',ev=>{if(ev.key==='Enter'||ev.key===' '){choose(ev);}});box.appendChild(d)});box.style.display='block';}
const customerInput=document.getElementById('customerLookup'),customerBox=document.getElementById('customerResults');
customerInput.addEventListener('input',debounce(async()=>{const q=customerInput.value.trim();document.getElementById('customerNo').value='';if(q.length<2){customerBox.style.display='none';return;}try{const items=await apiSearch('opportunity-api/customer-search.php',q);showResults(customerBox,items,i=>`<strong>${i.customer_name||''}</strong><small>${[i.customer_no,i.city,i.state,i.zipcode,i.phone].filter(Boolean).join(' · ')}</small>`,i=>{customerInput.value=i.customer_name||'';document.getElementById('customerNo').value=i.customer_no||'';document.getElementById('customerPhone').value=i.phone||'';document.getElementById('customerAddress').value=i.address||'';document.getElementById('customerCity').value=i.city||'';document.getElementById('customerState').value=i.state||'';document.getElementById('customerZip').value=i.zipcode||'';if(i.primary_contact)document.getElementById('primaryContact').value=i.primary_contact;if(i.email)document.getElementById('customerEmail').value=i.email;if(i.contact_title)document.getElementById('contactTitle').value=i.contact_title;if(i.department)document.getElementById('department').value=i.department;if(i.lowe_rep)document.getElementById('repLookup').value=i.lowe_rep;});}catch(e){customerBox.innerHTML='<div class="lookup-item">'+e.message+'</div>';customerBox.style.display='block';}}));
const repInput=document.getElementById('repLookup'),repBox=document.getElementById('repResults');
repInput.addEventListener('input',debounce(async()=>{const q=repInput.value.trim();if(q.length<2){repBox.style.display='none';return;}try{const items=await apiSearch('opportunity-api/rep-search.php',q);showResults(repBox,items,i=>`<strong>${i.name||''}</strong><small>${[i.email,i.phone].filter(Boolean).join(' · ')}</small>`,i=>{repInput.value=i.name||'';});}catch(e){repBox.innerHTML='<div class="lookup-item">'+e.message+'</div>';repBox.style.display='block';}}));
function productRowHtml(i){return `<div class="lookup-wrap"><input class="product-lookup" data-index="${i}" name="products[${i}][product_name]" placeholder="Product / chemical" autocomplete="off"><div class="lookup-results product-results"></div></div><input name="products[${i}][product_no]" placeholder="Product #"><input name="products[${i}][supplier]" placeholder="Supplier"><input name="products[${i}][packaging]" placeholder="Packaging"><input name="products[${i}][annual_volume]" placeholder="Annual volume"><input name="products[${i}][volume_unit]" placeholder="Unit"><input name="products[${i}][est_sell_price]" placeholder="Est. sell $/unit"><input name="products[${i}][est_cost]" placeholder="Est. cost $/unit"><input type="hidden" name="products[${i}][cas_no]">`;}
function bindProductLookup(input){if(input.dataset.bound)return;input.dataset.bound='1';const box=input.parentElement.querySelector('.product-results');input.addEventListener('input',debounce(async()=>{const q=input.value.trim();if(q.length<2){box.style.display='none';return;}try{const items=await apiSearch('opportunity-api/product-search.php',q);showResults(box,items,i=>`<strong>${i.product_name||''}</strong><small>${[i.product_no ? 'Product # ' + i.product_no : '',i.packaging,i.supplier].filter(Boolean).join(' · ')}</small>`,i=>{const row=input.closest('.product-row');input.value=i.product_name||'';row.querySelector('[name$="[product_no]"]').value=i.product_no||'';row.querySelector('[name$="[supplier]"]').value=i.supplier||'';row.querySelector('[name$="[packaging]"]').value=i.packaging||'';row.querySelector('[name$="[volume_unit]"]').value=i.volume_unit||'';row.querySelector('[name$="[cas_no]"]').value=i.cas_no||'';});}catch(e){box.innerHTML='<div class="lookup-item">'+e.message+'</div>';box.style.display='block';}}));}
function addProduct(){const p=document.getElementById('products');const r=document.createElement('div');r.className='product-row';r.innerHTML=productRowHtml(productIndex);p.appendChild(r);bindProductLookup(r.querySelector('.product-lookup'));productIndex++;}
document.querySelectorAll('.product-lookup').forEach(bindProductLookup);
document.addEventListener('click',e=>{if(!e.target.closest('.lookup-wrap'))document.querySelectorAll('.lookup-results').forEach(b=>b.style.display='none')});
</script>
</body></html>
