<?php
session_start();
if (empty($_SESSION['rfq_csrf'])) { $_SESSION['rfq_csrf'] = bin2hex(random_bytes(24)); }
$csrfToken = $_SESSION['rfq_csrf'];

function readCsvAssoc($path) {
    if (!is_file($path) || !is_readable($path)) return [];
    $fh = fopen($path, 'r');
    if (!$fh) return [];
    $headers = fgetcsv($fh);
    if (!$headers) { fclose($fh); return []; }
    $headers = array_map(function($h){ return preg_replace('/^\xEF\xBB\xBF/', '', trim((string)$h)); }, $headers);
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        $row = array_pad($row, count($headers), '');
        $rows[] = array_combine($headers, array_slice($row, 0, count($headers)));
    }
    fclose($fh);
    return $rows;
}

$base = __DIR__ . '/data';
$personnel = readCsvAssoc($base . '/lowe-personnel.csv');
$products = readCsvAssoc($base . '/product-names.csv');
$supplierProducts = readCsvAssoc($base . '/supplier-products.csv');

$productMap = [];
foreach ($products as $p) {
    // Supports the new Product Names CSV as well as the older product file.
    $name = trim((string)($p['Product Name'] ?? $p['Product Description'] ?? ''));
    if ($name === '') continue;

    $cas = trim((string)(
        $p['CAS No.']
        ?? $p['CAS No']
        ?? $p['CAS Number']
        ?? $p['CAS']
        ?? ''
    ));

    $chemicalName = trim((string)($p['Chemical Name'] ?? ''));

    // Normalize whitespace/case so datalist selections match reliably.
    $key = strtoupper(preg_replace('/\s+/', ' ', $name));

    $productMap[$key] = [
        'cas' => $cas,
        'chemicalName' => $chemicalName
    ];
}

$supplierSet = [];
$productSuppliers = [];
foreach ($supplierProducts as $sp) {
    $supplier = trim($sp['Supplier Name'] ?? '');
    $product = trim($sp['Product Name'] ?? '');
    if ($supplier !== '') $supplierSet[$supplier] = true;
    if ($supplier !== '' && $product !== '') {
        $key = strtoupper($product);
        if (!isset($productSuppliers[$key])) $productSuppliers[$key] = [];
        if (!in_array($supplier, $productSuppliers[$key], true)) $productSuppliers[$key][] = $supplier;
    }
}
$suppliers = array_keys($supplierSet);
sort($suppliers, SORT_NATURAL | SORT_FLAG_CASE);

$personnelJson = json_encode(array_values($personnel), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
$productMapJson = json_encode($productMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
$productSuppliersJson = json_encode($productSuppliers, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Request Supplier Pricing | Lowe Chemical Company</title>
<style>
:root{
  --navy:#0c2340;--blue:#174f86;--blue2:#eaf2f9;--ink:#17212b;--muted:#66717d;--line:#d9e0e6;
  --bg:#f4f7fa;--white:#fff;--green:#1f6f43;--amber:#9a6415;--red:#a62929;--radius:14px;
}
*{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 Arial,Helvetica,sans-serif}
.topbar{background:var(--navy);color:#fff;border-bottom:4px solid #4f8fbd}.topbar-inner{max-width:1280px;margin:auto;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;gap:20px}
.brand{display:flex;align-items:center;gap:14px}.brand img{height:48px;max-width:230px;object-fit:contain;background:#fff;border-radius:6px;padding:4px}.brand-fallback{font-weight:800;letter-spacing:.03em;font-size:20px}.brand small{display:block;color:#c8d7e5;font-weight:400;font-size:12px;margin-top:2px}
.page{max-width:1280px;margin:24px auto;padding:0 20px 40px}.titlebar{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:16px}.titlebar h1{margin:0;font-size:28px;color:var(--navy)}.titlebar p{margin:5px 0 0;color:var(--muted)}
.rfq-chip{background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px 14px;color:var(--muted);white-space:nowrap}.rfq-chip b{display:block;color:var(--navy);font-size:16px}
.notice{background:#eef5fb;border:1px solid #cbdceb;border-left:5px solid var(--blue);border-radius:10px;padding:12px 14px;margin-bottom:18px;color:#31465a}
.card{background:#fff;border:1px solid var(--line);border-radius:var(--radius);box-shadow:0 3px 12px rgba(21,37,53,.05);margin-bottom:18px;overflow:hidden}.card-head{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:14px;background:#fbfcfd}.card-head h2{font-size:17px;margin:0;color:var(--navy)}.card-head span{font-size:12px;color:var(--muted)}.card-body{padding:18px}
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}.span-12{grid-column:span 12}.span-8{grid-column:span 8}.span-6{grid-column:span 6}.span-4{grid-column:span 4}.span-3{grid-column:span 3}.span-2{grid-column:span 2}
label{display:block;font-weight:700;font-size:13px;margin-bottom:6px;color:#263443}.req:after{content:' *';color:var(--red)} input,select,textarea{width:100%;border:1px solid #bfc9d3;border-radius:9px;padding:10px 11px;background:#fff;color:var(--ink);font:inherit;min-height:42px}input:focus,select:focus,textarea:focus{outline:none;border-color:#4c86b4;box-shadow:0 0 0 3px rgba(76,134,180,.14)} input[readonly]{background:#f6f8fa;color:#5d6770}.help{font-size:12px;color:var(--muted);margin-top:5px}.inline-check{display:flex;gap:9px;align-items:flex-start}.inline-check input{width:auto;min-height:0;margin-top:3px}.inline-check label{margin:0;font-weight:600}
.supplier-row{display:grid;grid-template-columns:2fr 1.3fr 2fr auto;gap:10px;align-items:end;border:1px solid var(--line);padding:12px;border-radius:10px;margin-bottom:10px;background:#fbfcfd}.supplier-row button{height:42px}
.btn{border:0;border-radius:9px;padding:10px 15px;font-weight:700;cursor:pointer}.btn-primary{background:var(--navy);color:#fff}.btn-secondary{background:#e9eef3;color:#243646}.btn-outline{background:#fff;color:var(--blue);border:1px solid #8db0cd}.btn-danger{background:#fff;color:var(--red);border:1px solid #dfb4b4}.btn[disabled]{opacity:.5;cursor:not-allowed}
.actions{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:18px;background:#fff;border:1px solid var(--line);border-radius:var(--radius);position:sticky;bottom:10px;box-shadow:0 8px 24px rgba(18,31,43,.11)}.action-note{font-size:12px;color:var(--muted);max-width:610px}.action-buttons{display:flex;gap:9px;flex-wrap:wrap}
.pill{display:inline-block;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:700}.pill-green{background:#e7f4ec;color:#1d633d}.pill-amber{background:#fff4df;color:#80530e}
.history-box{margin-top:10px;background:#f6f9fc;border:1px dashed #b8c8d7;border-radius:9px;padding:10px 12px;display:none}.history-box strong{color:var(--navy)}
.person-search-wrap{position:relative}.person-results{position:absolute;left:0;right:0;top:100%;z-index:50;background:#fff;border:1px solid #bfc9d3;border-top:0;border-radius:0 0 9px 9px;box-shadow:0 8px 18px rgba(12,35,64,.12);max-height:240px;overflow:auto;display:none}.person-option{padding:12px 14px;cursor:pointer;border-bottom:1px solid #edf1f4;touch-action:manipulation}.person-option:last-child{border-bottom:0}.person-option:hover,.person-option.active{background:#eef5fb}.person-option strong{display:block;color:var(--navy)}.person-option span{display:block;font-size:12px;color:var(--muted);margin-top:2px}.person-empty{padding:10px 12px;color:var(--muted);font-size:12px}

/* Required fields: light yellow fill + stronger border so employees can see what must be completed. */
input:required:not([type="hidden"]),select:required,textarea:required{background:#fff9e8;border-color:#d8a62b}
input:required:not([type="hidden"]):focus,select:required:focus,textarea:required:focus{background:#fffdf6;border-color:#b98000;box-shadow:0 0 0 3px rgba(216,166,43,.18)}
.required-key{display:inline-flex;align-items:center;gap:7px;font-size:12px;color:#6b5a22;margin-top:6px}.required-key:before{content:"";width:14px;height:14px;border:1px solid #d8a62b;background:#fff9e8;border-radius:3px}

/* Additional CC personnel picker */
.cc-search-wrap{position:relative}.cc-results{position:absolute;left:0;right:0;top:100%;z-index:55;background:#fff;border:1px solid #bfc9d3;border-top:0;border-radius:0 0 9px 9px;box-shadow:0 8px 18px rgba(12,35,64,.12);max-height:240px;overflow:auto;display:none}.cc-option{padding:12px 14px;cursor:pointer;border-bottom:1px solid #edf1f4;touch-action:manipulation}.cc-option:last-child{border-bottom:0}.cc-option:hover,.cc-option.active{background:#eef5fb}.cc-option strong{display:block;color:var(--navy)}.cc-option span{display:block;font-size:12px;color:var(--muted);margin-top:2px}.cc-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}.cc-chip{display:inline-flex;align-items:center;gap:7px;background:#eef5fb;border:1px solid #c8d9e7;border-radius:999px;padding:6px 9px;color:#173a5e;font-size:12px}.cc-chip button{border:0;background:transparent;color:#8a2424;font-weight:700;cursor:pointer;padding:0;font-size:14px;line-height:1;touch-action:manipulation}
.preview{display:none;margin-top:14px;border:1px solid var(--line);border-radius:12px;overflow:hidden}.preview-head{background:var(--navy);color:#fff;padding:14px 16px;font-weight:700}.preview-brand{display:flex;align-items:center;gap:14px}.preview-brand img{height:58px;max-width:250px;object-fit:contain;background:#fff;border-radius:6px;padding:5px}.preview-brand strong{display:block;font-size:18px}.preview-brand span{display:block;font-size:13px;color:#d7e4ef;margin-top:2px}.preview-body{padding:16px;background:#fff}.preview-body table{width:100%;border-collapse:collapse}.preview-body th,.preview-body td{text-align:left;border-bottom:1px solid #e7ebef;padding:8px;vertical-align:top}.preview-body th{width:31%;color:#51606f;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
@media(max-width:900px){.span-8,.span-6,.span-4,.span-3,.span-2{grid-column:span 12}.titlebar{display:block}.rfq-chip{margin-top:12px;display:inline-block}.supplier-row{grid-template-columns:1fr}.supplier-row button{width:100%}.actions{position:static}.action-buttons{width:100%}.action-buttons .btn{flex:1}.topbar-inner{align-items:flex-start}.brand img{height:42px}}
@media(max-width:560px){.page{padding:0 12px 24px;margin-top:16px}.topbar-inner{padding:13px 14px}.brand-fallback{font-size:17px}.titlebar h1{font-size:23px}.card-body{padding:14px}.card-head{padding:14px}.action-buttons{display:grid;grid-template-columns:1fr;width:100%}}
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <img src="images/lowe-logo.png" alt="Lowe Chemical Company" onerror="this.style.display='none';document.getElementById('brandFallback').style.display='block'">
      <div id="brandFallback" class="brand-fallback" style="display:none">LOWE CHEMICAL COMPANY<small>Our Chemistry Enhances Your Chemistry.</small></div>
    </div>
    <div style="font-size:13px;color:#d8e5f0;text-align:right">Internal Sourcing<br><b>Supplier Pricing Request</b></div>
  </div>
</header>

<main class="page">
  <div class="titlebar">
    <div><h1>Request Supplier Pricing</h1><p>Create one RFQ and send it privately to one or more suppliers.</p></div>
    <div class="rfq-chip">RFQ Number<b>Assigned when submitted</b></div>
  </div>

  <div class="notice"><b>Supplier privacy:</b> each selected supplier will receive a separate email with a unique secure pricing-response link. The Lowe requester will be copied. Choose the Reply-To address in the Email &amp; PDF Options section.<div class="required-key">Highlighted fields are required.</div></div>

  <form id="pricingForm" method="post" action="save-rfq.php" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <section class="card">
      <div class="card-head"><h2>1. Requester Information</h2><span>Lowe Chemical personnel</span></div>
      <div class="card-body grid">
        <div class="span-4"><label class="req" for="requester">Requested By</label><div class="person-search-wrap"><input id="requester" name="requester_name" type="text" autocomplete="off" required placeholder="Start typing a Lowe employee name"><div id="personResults" class="person-results" role="listbox" aria-label="Lowe personnel search results"></div></div><input id="requesterEmailHidden" name="requester_email" type="hidden"><div class="help">Start typing any part of the employee's name, then choose the matching person. Email and phone will populate automatically.</div></div>
        <div class="span-4"><label for="requesterEmail">Email</label><input id="requesterEmail" name="requester_email_display" type="email" readonly></div>
        <div class="span-4"><label for="requesterPhone">Phone</label><input id="requesterPhone" name="requester_phone_display" type="text" readonly></div>
        <div class="span-3"><label for="requestDate">Request Date</label><input id="requestDate" name="request_date" type="date" value="<?= date('Y-m-d') ?>" readonly></div>
        <div class="span-3"><label class="req" for="neededBy">Pricing Needed By</label><input id="neededBy" name="pricing_needed_by" type="date" required></div>
        <div class="span-3"><label>Requester Copy</label><div class="inline-check"><input id="ccRequester" name="cc_requester" value="1" type="checkbox" checked><label for="ccRequester">CC requesting employee</label></div></div>
        <div class="span-3"><label>Sourcing Copy</label><div class="inline-check"><input id="ccSourcing" name="cc_sourcing" value="1" type="checkbox" checked><label for="ccSourcing">Include Sourcing@lowechemical.com</label></div></div>
        <div class="span-12">
          <label for="additionalCcSearch">Additional Lowe CC Email(s)</label>
          <div class="cc-search-wrap">
            <input id="additionalCcSearch" type="text" autocomplete="off" placeholder="Start typing a Lowe employee name or email">
            <div id="additionalCcResults" class="cc-results" role="listbox" aria-label="Additional Lowe CC search results"></div>
          </div>
          <input id="additionalCc" name="additional_cc" type="hidden" value="">
          <div id="additionalCcChips" class="cc-chips"></div>
          <div class="help">Optional. Select one or more Lowe employees. Their email addresses will be added automatically.</div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>2. Product & Requirement</h2><span>What Lowe needs quoted</span></div>
      <div class="card-body grid">
        <div class="span-8"><label class="req" for="productName">Product Name</label><input id="productName" name="product_name" list="productList" autocomplete="off" required placeholder="Begin typing product name"><datalist id="productList"><?php foreach ($products as $p): $n=trim((string)($p['Product Name'] ?? $p['Product Description'] ?? '')); if($n!==''): ?><option value="<?= htmlspecialchars($n) ?>"></option><?php endif; endforeach; ?></datalist><div class="help">Select a historical product or type a new one.</div></div>
        <div class="span-4"><label for="casNumber">CAS Number</label><input id="casNumber" name="cas_number" type="text" placeholder="e.g., 64-19-7"><div class="help">Auto-populates from the product file when a CAS number is available. You can edit it if needed.</div></div>
        <div class="span-6"><label for="grade">Product Grade</label><input id="grade" name="product_grade" type="text" placeholder="If applicable"></div>
        <div class="span-6"><label class="req" for="packaging">Product Packaging</label><input id="packaging" name="packaging" type="text" required placeholder="Drum, bag, tote, bulk, etc."></div>
        <div class="span-4"><label class="req" for="quantity">Quantity</label><div style="display:grid;grid-template-columns:2fr 1fr;gap:8px"><input id="quantity" name="quantity" type="number" min="0" step="0.01" required><select id="quantityUom" name="quantity_uom"><option>LB</option><option>KG</option><option>Drums</option><option>Totes</option><option>Bags</option><option>Gallons</option><option>Truckload</option><option>Other</option></select></div></div>
        <div class="span-4"><label class="req" for="priceBasis">Price Basis Requested</label><select id="priceBasis" name="price_basis" required><option value="">Select basis</option><option>$/LB</option><option>$/KG</option><option>$/Drum</option><option>$/Tote</option><option>$/Bag</option><option>$/Gallon</option><option>Other</option></select></div>
        <div class="span-4"><label for="annualUsage">Estimated Annual Usage</label><input id="annualUsage" name="estimated_annual_usage" type="text" placeholder="Optional"></div>
        <div class="span-4"><label for="requirementType">Requirement Type</label><select id="requirementType" name="requirement_type"><option>New Opportunity</option><option>Existing Business</option><option>One-Time Purchase</option><option>Recurring Requirement</option><option>Spot Requirement</option></select></div>
        <div class="span-12"><label for="application">Application / How Product Will Be Used</label><textarea id="application" name="application" rows="2" placeholder="Describe how the customer will use the product, process, end use, formulation, treatment, cleaning application, etc."></textarea><div class="help">This information will be included with the RFQ so the supplier understands the intended use.</div></div>
        <div class="span-12"><label class="req" for="shipAddress">Delivery Address</label><input id="shipAddress" name="ship_address" type="text" value="8300 Baker Ave." required autocomplete="street-address" placeholder="Street address"></div>
        <div class="span-4"><label class="req" for="shipCity">Delivery City</label><input id="shipCity" name="ship_city" type="text" value="Cleveland" required autocomplete="address-level2"></div>
        <div class="span-4"><label class="req" for="shipState">State</label><input id="shipState" name="ship_state" type="text" maxlength="2" value="OH" required autocomplete="address-level1" placeholder="OH"></div>
        <div class="span-4"><label class="req" for="shipZip">ZIP Code</label><input id="shipZip" name="ship_zip" type="text" inputmode="numeric" maxlength="10" value="44102" required autocomplete="postal-code" pattern="\d{5}(-\d{4})?" title="Enter a 5-digit ZIP code or ZIP+4"></div>
        <div class="span-12"><label for="specialRequirements">Special Requirements / Notes</label><textarea id="specialRequirements" name="special_requirements" rows="3" placeholder="Manufacturer restriction, origin restriction, purity requirement, shelf life, special handling, etc."></textarea></div>
        <div class="span-12"><div id="historyBox" class="history-box"></div></div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>3. Information Requested From Supplier</h2><span>These items will appear in the email/PDF</span></div>
      <div class="card-body grid">
        <div class="span-3"><label>Price</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Price Unit</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Freight Method</label><span class="pill pill-green">FOB / Delivered / PPA</span></div>
        <div class="span-3"><label>FOB Location</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Minimum Order</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Lead Time</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Availability</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Payment Terms</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Country of Origin</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Quote Expiration</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Freight / Surcharges</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Manufacturer</label><span class="pill pill-green">Requested</span></div>
        <div class="span-3"><label>Container Weight / Net Weight</label><span class="pill pill-green">Requested</span></div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>4. Suppliers to Contact</h2><span>Each supplier receives a separate secure link</span></div>
      <div class="card-body">
        <div id="supplierRows"></div>
        <button type="button" class="btn btn-outline" id="addSupplierBtn">+ Add Supplier</button>
        <datalist id="supplierList"><?php foreach ($suppliers as $s): ?><option value="<?= htmlspecialchars($s) ?>"></option><?php endforeach; ?></datalist>
        <div class="help" style="margin-top:10px">Supplier contact fields are intentionally manual for this version. We can connect them to the supplier contact database later.</div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>5. Email & PDF Options</h2><span>Choose how this RFQ will be sent</span></div>
      <div class="card-body grid">
        <div class="span-4"><label>Email Format</label><select id="emailFormat" name="email_format"><option value="email_pdf">Email + Lowe-branded PDF</option><option value="email_only">Email only</option></select></div>
        <div class="span-4"><label for="emailSubject">Email Subject</label><input id="emailSubject" name="email_subject" type="text" value="Lowe Chemical Request for Pricing" maxlength="160"></div>
        <div class="span-4"><label for="replyTo">Reply-To</label><select id="replyTo" name="reply_to">
          <option value="sourcing@lowechemical.com" selected>sourcing@lowechemical.com</option>
          <option value="sales@lowechemical.com">sales@lowechemical.com</option>
          <option value="orders@lowechemical.com">orders@lowechemical.com</option>
          <option value="kenlowejr@lowechemical.com">kenlowejr@lowechemical.com</option>
          <option value="davidlowe@lowechemical.com">davidlowe@lowechemical.com</option>
          <option value="dmazzola@lowechemical.com">dmazzola@lowechemical.com</option>
          <option value="bfislar@lowechemical.com">bfislar@lowechemical.com</option>
          <option value="jmazzola@lowechemical.com">jmazzola@lowechemical.com</option>
          <option value="loricapretta@lowechemical.com">loricapretta@lowechemical.com</option>
        </select></div>
        <div class="span-12"><div class="notice" style="margin:0"><b>Supplier privacy:</b> emails are sent individually. Each supplier gets a different pricing-submission link and cannot see who else was contacted.</div></div>
        <div class="span-12"><div class="inline-check"><input id="supplierInstruction" name="supplier_instruction" value="1" type="checkbox" checked><label for="supplierInstruction">Include instruction: “Please return pricing and availability to Sourcing@lowechemical.com.”</label></div></div>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h2>Preview</h2><span>Review before sending the RFQ</span></div>
      <div class="card-body"><button type="button" class="btn btn-secondary" id="previewBtn">Preview Request</button><div id="preview" class="preview"><div class="preview-head"><div class="preview-brand"><img src="images/lowe-logo.png" alt="Lowe Chemical Company"><div><strong>Lowe Chemical Company</strong><span>Request for Pricing</span></div></div></div><div class="preview-body" id="previewBody"></div></div></div>
    </section>

    <div class="actions">
      <div class="action-note"><b>Ready to send:</b> Create &amp; Email assigns the RFQ number, saves the request, and immediately emails each supplier separately using the Email &amp; PDF option selected above. Each supplier receives a unique secure Submit Pricing link.</div>
      <div class="action-buttons"><button type="reset" class="btn btn-secondary">Clear Form</button><button type="button" class="btn btn-secondary" id="continueBtn">Preview &amp; Validate</button><button type="submit" class="btn btn-outline" name="submit_action" value="save_draft" id="draftBtn">Save Draft</button><button type="submit" class="btn btn-primary" name="submit_action" value="send_now" id="sendBtn">Create RFQ &amp; Email Supplier(s)</button></div>
    </div>
  </form>
</main>

<script>
const personnel = <?= $personnelJson ?: '[]' ?>;
const productMap = <?= $productMapJson ?: '{}' ?>;
const productSuppliers = <?= $productSuppliersJson ?: '{}' ?>;
const form = document.getElementById('pricingForm');
const requester = document.getElementById('requester');
const requesterEmail = document.getElementById('requesterEmail');
const requesterPhone = document.getElementById('requesterPhone');
const requesterEmailHidden = document.getElementById('requesterEmailHidden');
const personResults = document.getElementById('personResults');
let selectedRequester = null;
let activePersonIndex = -1;

function personnelName(row){
  return String(row['Sales Rep'] || row['Name'] || row['Full Name'] || '').trim();
}
function personnelEmail(row){
  return String(row['Email'] || row['Email Address'] || '').trim();
}
function personnelPhone(row){
  return String(row['Phone Number'] || row['Phone'] || '').trim();
}
function clearRequesterDetails(){
  selectedRequester = null;
  requesterEmail.value = '';
  requesterPhone.value = '';
  requesterEmailHidden.value = '';
}
function chooseRequester(row){
  selectedRequester = row;
  requester.value = personnelName(row);
  requesterEmail.value = personnelEmail(row);
  requesterPhone.value = personnelPhone(row);
  requesterEmailHidden.value = personnelEmail(row);
  personResults.style.display = 'none';
  personResults.innerHTML = '';
  activePersonIndex = -1;
}
function getPersonnelMatches(query){
  const q = String(query || '').trim().toLowerCase();
  if(!q) return [];
  return personnel
    .filter(row => personnelName(row).toLowerCase().includes(q))
    .sort((a,b) => {
      const an = personnelName(a).toLowerCase();
      const bn = personnelName(b).toLowerCase();
      const aStarts = an.startsWith(q) ? 0 : 1;
      const bStarts = bn.startsWith(q) ? 0 : 1;
      return aStarts - bStarts || an.localeCompare(bn);
    })
    .slice(0, 12);
}
function renderPersonnelResults(){
  const matches = getPersonnelMatches(requester.value);
  personResults.innerHTML = '';
  activePersonIndex = -1;

  if(!requester.value.trim()){
    personResults.style.display = 'none';
    return;
  }

  if(!matches.length){
    personResults.innerHTML = '<div class="person-empty">No Lowe employee found.</div>';
    personResults.style.display = 'block';
    return;
  }

  matches.forEach((row, index) => {
    const item = document.createElement('div');
    item.className = 'person-option';
    item.setAttribute('role','option');
    item.dataset.index = String(index);

    const name = document.createElement('strong');
    name.textContent = personnelName(row);
    item.appendChild(name);

    const meta = document.createElement('span');
    const details = [personnelEmail(row), personnelPhone(row)].filter(Boolean);
    meta.textContent = details.join(' • ');
    item.appendChild(meta);

    const choose = (e) => {
      e.preventDefault();
      chooseRequester(row);
    };
    item.addEventListener('pointerdown', choose);
    item.addEventListener('click', choose);

    personResults.appendChild(item);
  });

  personResults.style.display = 'block';
}
function populateRequester(){
  const typed = String(requester.value || '').trim().toLowerCase();
  const exact = personnel.find(row => personnelName(row).toLowerCase() === typed) || null;
  if(exact){
    chooseRequester(exact);
    return exact;
  }
  return selectedRequester && personnelName(selectedRequester).toLowerCase() === typed ? selectedRequester : null;
}

requester.addEventListener('input', () => {
  const current = selectedRequester ? personnelName(selectedRequester) : '';
  if(requester.value !== current) clearRequesterDetails();
  renderPersonnelResults();
});

requester.addEventListener('focus', renderPersonnelResults);

requester.addEventListener('keydown', (e) => {
  const options = [...personResults.querySelectorAll('.person-option')];
  if(!options.length) return;

  if(e.key === 'ArrowDown'){
    e.preventDefault();
    activePersonIndex = Math.min(activePersonIndex + 1, options.length - 1);
  } else if(e.key === 'ArrowUp'){
    e.preventDefault();
    activePersonIndex = Math.max(activePersonIndex - 1, 0);
  } else if(e.key === 'Enter' && activePersonIndex >= 0){
    e.preventDefault();
    const matches = getPersonnelMatches(requester.value);
    if(matches[activePersonIndex]) chooseRequester(matches[activePersonIndex]);
    return;
  } else if(e.key === 'Escape'){
    personResults.style.display = 'none';
    return;
  } else {
    return;
  }

  options.forEach((el,i)=>el.classList.toggle('active', i === activePersonIndex));
  if(options[activePersonIndex]) options[activePersonIndex].scrollIntoView({block:'nearest'});
});

requester.addEventListener('blur', () => {
  setTimeout(() => {
    const exact = personnel.find(row => personnelName(row).toLowerCase() === requester.value.trim().toLowerCase()) || null;
    if(exact) chooseRequester(exact);
    else personResults.style.display = 'none';
  }, 150);
});


const additionalCcSearch = document.getElementById('additionalCcSearch');
const additionalCcResults = document.getElementById('additionalCcResults');
const additionalCcHidden = document.getElementById('additionalCc');
const additionalCcChips = document.getElementById('additionalCcChips');
let additionalCcSelected = [];
let activeCcIndex = -1;

function syncAdditionalCc(){
  additionalCcHidden.value = additionalCcSelected.map(x => x.email).join(', ');
  additionalCcChips.innerHTML = '';

  additionalCcSelected.forEach((entry, index) => {
    const chip = document.createElement('span');
    chip.className = 'cc-chip';

    const label = document.createElement('span');
    label.textContent = entry.name + ' • ' + entry.email;
    chip.appendChild(label);

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.setAttribute('aria-label', 'Remove ' + entry.name);
    remove.textContent = '×';
    remove.addEventListener('click', () => {
      additionalCcSelected.splice(index, 1);
      syncAdditionalCc();
    });
    chip.appendChild(remove);

    additionalCcChips.appendChild(chip);
  });
}

function getCcMatches(query){
  const q = String(query || '').trim().toLowerCase();
  if(!q) return [];

  const already = new Set(additionalCcSelected.map(x => x.email.toLowerCase()));

  return personnel
    .filter(row => {
      const name = personnelName(row).toLowerCase();
      const email = personnelEmail(row).toLowerCase();
      return email && !already.has(email) && (name.includes(q) || email.includes(q));
    })
    .sort((a,b) => {
      const an = personnelName(a).toLowerCase();
      const bn = personnelName(b).toLowerCase();
      const ae = personnelEmail(a).toLowerCase();
      const be = personnelEmail(b).toLowerCase();

      const aStarts = an.startsWith(q) || ae.startsWith(q) ? 0 : 1;
      const bStarts = bn.startsWith(q) || be.startsWith(q) ? 0 : 1;

      return aStarts - bStarts || an.localeCompare(bn);
    })
    .slice(0, 12);
}

function chooseAdditionalCc(row){
  const email = personnelEmail(row);
  if(!email) return;

  if(!additionalCcSelected.some(x => x.email.toLowerCase() === email.toLowerCase())){
    additionalCcSelected.push({
      name: personnelName(row),
      email
    });
  }

  syncAdditionalCc();
  additionalCcSearch.value = '';
  additionalCcResults.style.display = 'none';
  additionalCcResults.innerHTML = '';
  activeCcIndex = -1;
}

function renderAdditionalCcResults(){
  const matches = getCcMatches(additionalCcSearch.value);
  additionalCcResults.innerHTML = '';
  activeCcIndex = -1;

  if(!additionalCcSearch.value.trim()){
    additionalCcResults.style.display = 'none';
    return;
  }

  if(!matches.length){
    additionalCcResults.innerHTML = '<div class="person-empty">No matching Lowe employee found.</div>';
    additionalCcResults.style.display = 'block';
    return;
  }

  matches.forEach((row, index) => {
    const item = document.createElement('div');
    item.className = 'cc-option';
    item.setAttribute('role', 'option');
    item.dataset.index = String(index);

    const name = document.createElement('strong');
    name.textContent = personnelName(row);
    item.appendChild(name);

    const meta = document.createElement('span');
    meta.textContent = [personnelEmail(row), personnelPhone(row)].filter(Boolean).join(' • ');
    item.appendChild(meta);

    const choose = (e) => {
      e.preventDefault();
      chooseAdditionalCc(row);
    };

    item.addEventListener('pointerdown', choose);
    item.addEventListener('click', choose);

    additionalCcResults.appendChild(item);
  });

  additionalCcResults.style.display = 'block';
}

additionalCcSearch.addEventListener('input', renderAdditionalCcResults);
additionalCcSearch.addEventListener('focus', renderAdditionalCcResults);

additionalCcSearch.addEventListener('keydown', (e) => {
  const options = [...additionalCcResults.querySelectorAll('.cc-option')];
  if(!options.length) return;

  if(e.key === 'ArrowDown'){
    e.preventDefault();
    activeCcIndex = Math.min(activeCcIndex + 1, options.length - 1);
  } else if(e.key === 'ArrowUp'){
    e.preventDefault();
    activeCcIndex = Math.max(activeCcIndex - 1, 0);
  } else if(e.key === 'Enter' && activeCcIndex >= 0){
    e.preventDefault();
    const matches = getCcMatches(additionalCcSearch.value);
    if(matches[activeCcIndex]) chooseAdditionalCc(matches[activeCcIndex]);
    return;
  } else if(e.key === 'Escape'){
    additionalCcResults.style.display = 'none';
    return;
  } else {
    return;
  }

  options.forEach((el,i) => el.classList.toggle('active', i === activeCcIndex));
  if(options[activeCcIndex]) options[activeCcIndex].scrollIntoView({block:'nearest'});
});

additionalCcSearch.addEventListener('blur', () => {
  setTimeout(() => {
    additionalCcResults.style.display = 'none';
  }, 150);
});

function normalize(s){
  return String(s || '')
    .replace(/\s+/g,' ')
    .trim()
    .toUpperCase();
}

let lastAutoCas = '';

function updateProductInfo(){
  const productInput = document.getElementById('productName');
  const casInput = document.getElementById('casNumber');
  const name = productInput.value;
  const key = normalize(name);
  const hit = productMap[key] || null;

  if(hit){
    const newCas = String(hit.cas || '').trim();

    // Populate immediately when an exact product is selected/typed.
    // Keep CAS editable so a Lowe employee can correct it when needed.
    if(newCas !== ''){
      casInput.value = newCas;
      lastAutoCas = newCas;
    } else if(casInput.value === lastAutoCas){
      casInput.value = '';
      lastAutoCas = '';
    }
  } else if(casInput.value === lastAutoCas){
    // Clear only a value that this script populated. Never erase a manual CAS.
    casInput.value = '';
    lastAutoCas = '';
  }

  const exact = productSuppliers[key] || [];
  const history = document.getElementById('historyBox');

  if(exact.length){
    history.style.display='block';
    history.innerHTML='<strong>Past supplier history found:</strong> ' +
      exact.map(x=>escapeHtml(x)).join(', ') +
      '. <span style="color:#66717d">Use this as a suggestion, not a restriction.</span>';
  } else {
    history.style.display='none';
    history.innerHTML='';
  }
}

const productNameInput = document.getElementById('productName');
productNameInput.addEventListener('input', updateProductInfo);
productNameInput.addEventListener('change', updateProductInfo);
productNameInput.addEventListener('blur', updateProductInfo);

const supplierRows = document.getElementById('supplierRows');
function addSupplier(prefill=''){
  const row=document.createElement('div'); row.className='supplier-row';
  row.innerHTML=`<div><label class="req">Supplier Name</label><input class="supplier-name" name="supplier_name[]" list="supplierList" required value="${escapeAttr(prefill)}" placeholder="Select or enter supplier"></div>
  <div><label>Contact Name</label><input class="supplier-contact" name="supplier_contact[]" type="text" placeholder="Optional for now"></div>
  <div><label>Supplier Email</label><input class="supplier-email" name="supplier_email[]" type="email" placeholder="Can be added later"></div>
  <div><button type="button" class="btn btn-danger remove-supplier">Remove</button></div>`;
  row.querySelector('.remove-supplier').addEventListener('click',()=>{ if(supplierRows.children.length>1) row.remove(); });
  supplierRows.appendChild(row);
}
addSupplier();
document.getElementById('addSupplierBtn').addEventListener('click',()=>addSupplier());

function getValue(id){return document.getElementById(id).value.trim();}
function buildPreview(){
  const supplierNames=[...document.querySelectorAll('.supplier-name')].map(x=>x.value.trim()).filter(Boolean);
  const rows=[
    ['Requested By', getValue('requester')],
    ['Pricing Needed By', getValue('neededBy')],
    ['Product', getValue('productName')],
    ['CAS Number', getValue('casNumber') || 'Not specified'],
    ['Grade', getValue('grade') || 'Not specified'],
    ['Packaging', getValue('packaging')],
    ['Quantity', getValue('quantity')+' '+getValue('quantityUom')],
    ['Price Basis', getValue('priceBasis')],
    ['Deliver To', [getValue('shipAddress'), [getValue('shipCity'),getValue('shipState'),getValue('shipZip')].filter(Boolean).join(', ')].filter(Boolean).join(', ')],
    ['Requirement', getValue('requirementType')],
    ['Application / Use', getValue('application') || 'Not specified'],
    ['Suppliers', supplierNames.join('; ')],
    ['Special Requirements', getValue('specialRequirements') || 'None']
  ];
  let html='<table>' + rows.map(r=>`<tr><th>${escapeHtml(r[0])}</th><td>${escapeHtml(r[1])}</td></tr>`).join('') + '</table>';
  html += '<p style="margin:16px 0 0"><b>Supplier response requested:</b> Price, price unit, freight method, FOB location, minimum order, lead time, availability, payment terms, country of origin, quote expiration, freight/surcharges, manufacturer, and container/net weight per package.</p>';
  document.getElementById('previewBody').innerHTML=html;
  document.getElementById('preview').style.display='block';
}
document.getElementById('previewBtn').addEventListener('click', buildPreview);

document.getElementById('continueBtn').addEventListener('click',()=>{
  if(!form.reportValidity()) return;
  if(!populateRequester() || !requesterEmailHidden.value){ alert('Please select a Lowe employee from the name suggestions.'); requester.focus(); return; }
  buildPreview();
  document.getElementById('preview').scrollIntoView({behavior:'smooth',block:'center'});
});

form.addEventListener('reset', () => {
  setTimeout(() => {
    additionalCcSelected = [];
    syncAdditionalCc();
    clearRequesterDetails();
    personResults.style.display = 'none';
    additionalCcResults.style.display = 'none';
  }, 0);
});

form.addEventListener('submit', (e)=>{
  if(!form.reportValidity()){ e.preventDefault(); return; }
  if(!populateRequester() || !requesterEmailHidden.value){
    e.preventDefault();
    alert('Please select a Lowe employee from the name suggestions so the requester email can be populated.');
    requester.focus();
    return;
  }
  const rows=[...document.querySelectorAll('.supplier-row')];
  const namedRows=rows.filter(row => row.querySelector('.supplier-name').value.trim());
  if(!namedRows.length){ e.preventDefault(); alert('Add at least one supplier.'); return; }

  const action=(e.submitter && e.submitter.value) ? e.submitter.value : 'send_now';
  if(action === 'send_now') {
    const missingEmail=namedRows.find(row => !row.querySelector('.supplier-email').value.trim());
    if(missingEmail){
      e.preventDefault();
      alert('Enter an email address for every supplier before sending. Use Save Draft if you are still waiting for contact information.');
      missingEmail.querySelector('.supplier-email').focus();
      return;
    }
    
  }
});

function escapeHtml(s){return String(s||'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));}
function escapeAttr(s){return escapeHtml(s);}
</script>
</body>
</html>
