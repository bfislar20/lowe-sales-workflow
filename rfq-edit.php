<?php
session_start();
require __DIR__ . '/config/db.php';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$pdo = db();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$rfqNumber = trim((string)($_GET['rfq'] ?? ''));

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM rfqs WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
} elseif ($rfqNumber !== '') {
    $stmt = $pdo->prepare('SELECT * FROM rfqs WHERE rfq_number=? LIMIT 1');
    $stmt->execute([$rfqNumber]);
} else {
    header('Location: rfq-list.php');
    exit;
}

$rfq = $stmt->fetch();

if (!$rfq) {
    http_response_code(404);
    exit('RFQ not found.');
}

$id = (int)$rfq['id'];

$s = $pdo->prepare('SELECT * FROM rfq_suppliers WHERE rfq_id=? ORDER BY sort_order,id');
$s->execute([$id]);
$suppliers = $s->fetchAll();

/*
 * Supplier directory for autocomplete.
 * The normal New RFQ page gets supplier names from data/supplier-products.csv,
 * so the edit page uses that same source first. We then merge in the supplier
 * master and prior RFQ recipients so names are not lost and recent contact/email
 * information can still auto-fill when available.
 */
$supplierDirectory = [];

function addSupplierDirectoryName(array &$directory, string $name, int $id=0): void {
    $name = trim($name);
    if ($name === '') return;
    $key = strtolower($name);
    if (!isset($directory[$key])) {
        $directory[$key] = [
            'id' => $id,
            'name' => $name,
            'contact' => '',
            'email' => ''
        ];
    } elseif ($id > 0 && empty($directory[$key]['id'])) {
        $directory[$key]['id'] = $id;
    }
}

// 1) Match the supplier source used by vendor-pricing-request.php.
$csvPath = __DIR__ . '/data/supplier-products.csv';
if (is_file($csvPath) && is_readable($csvPath) && ($fh = fopen($csvPath, 'r'))) {
    $headers = fgetcsv($fh);
    if ($headers) {
        $headers = array_map(function($h){
            return preg_replace('/^\xEF\xBB\xBF/', '', trim((string)$h));
        }, $headers);
        $supplierNameIndex = array_search('Supplier Name', $headers, true);
        if ($supplierNameIndex !== false) {
            while (($row = fgetcsv($fh)) !== false) {
                $name = trim((string)($row[$supplierNameIndex] ?? ''));
                addSupplierDirectoryName($supplierDirectory, $name);
            }
        }
    }
    fclose($fh);
}

try {
    // 2) Merge the database supplier master as another valid source.
    $masterRows = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE supplier_name IS NOT NULL AND supplier_name<>'' ORDER BY supplier_name")->fetchAll();
    foreach ($masterRows as $row) {
        addSupplierDirectoryName($supplierDirectory, (string)$row['supplier_name'], (int)$row['id']);
    }

    // 3) Merge prior RFQ recipients and use the newest available contact/email.
    $contactRows = $pdo->query("SELECT supplier_name, contact_name, contact_email FROM rfq_suppliers WHERE supplier_name IS NOT NULL AND supplier_name<>'' ORDER BY id DESC")->fetchAll();
    foreach ($contactRows as $row) {
        $name = trim((string)$row['supplier_name']);
        if ($name === '') continue;
        addSupplierDirectoryName($supplierDirectory, $name);
        $key = strtolower($name);
        if ($supplierDirectory[$key]['contact'] === '' && trim((string)$row['contact_name']) !== '') {
            $supplierDirectory[$key]['contact'] = trim((string)$row['contact_name']);
        }
        if ($supplierDirectory[$key]['email'] === '' && trim((string)$row['contact_email']) !== '') {
            $supplierDirectory[$key]['email'] = trim((string)$row['contact_email']);
        }
    }
} catch (Throwable $e) {
    error_log('RFQ supplier directory database merge could not be loaded: '.$e->getMessage());
}

uasort($supplierDirectory, function($a, $b){
    return strnatcasecmp((string)$a['name'], (string)$b['name']);
});
$supplierDirectory = array_values($supplierDirectory);

$_SESSION['rfq_edit_csrf'] = bin2hex(random_bytes(24));

if (!$suppliers) {
    $suppliers = [
        [
            'supplier_name' => '',
            'contact_name' => '',
            'contact_email' => ''
        ]
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Edit <?= h($rfq['rfq_number']) ?> | Lowe Chemical</title>
<style>
body{margin:0;background:#f4f7fa;color:#17212b;font:14px/1.45 Arial,sans-serif}
.top{background:#0c2340;color:#fff;padding:18px}
.topin,.wrap{max-width:1050px;margin:auto}
.topin{display:flex;align-items:center;gap:14px}
.top img{max-height:44px;max-width:220px;background:#fff;padding:3px;border-radius:4px}
.wrap{padding:22px}
.card{background:#fff;border:1px solid #d9e0e6;border-radius:14px;padding:20px;margin:14px 0}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}
.triple{display:grid;grid-template-columns:2fr 1fr 1fr;gap:12px}
.field label{display:block;font-size:11px;text-transform:uppercase;color:#687684;font-weight:700;margin-bottom:5px}
.field input,.field select,.field textarea{width:100%;box-sizing:border-box;border:1px solid #cfd8df;border-radius:8px;padding:10px;background:#fff;font:inherit}
.field textarea{min-height:90px}
.supplier-row{display:grid;grid-template-columns:1.4fr 1fr 1.5fr auto;gap:10px;margin-bottom:10px;align-items:end}
.btn{border:0;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.primary{background:#0c2340;color:#fff}
.secondary{background:#e9eef3;color:#243646}
.remove{background:#f7e7e7;color:#8a2424}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.note{background:#eef5fb;border-left:4px solid #174f86;padding:11px 13px;margin-bottom:12px}
.clone-card{border:2px solid #1d6a45;background:#f4fbf7}
.clone-card h2{color:#17583a!important}
.clone-grid{display:grid;grid-template-columns:1.4fr 1fr 1.5fr;gap:12px}
.clone-action{background:#17613c;color:#fff}
.small{font-size:12px;color:#687684;margin-top:8px}
.autocomplete-wrap{position:relative}
.autocomplete-results{display:none;position:absolute;left:0;right:0;top:100%;z-index:50;background:#fff;border:1px solid #bfcbd5;border-top:0;border-radius:0 0 8px 8px;max-height:240px;overflow-y:auto;box-shadow:0 8px 18px rgba(0,0,0,.12)}
.autocomplete-option{padding:9px 11px;cursor:pointer;border-bottom:1px solid #edf1f4}
.autocomplete-option:last-child{border-bottom:0}
.autocomplete-option:hover,.autocomplete-option.active{background:#eef5fb}
.manual-toggle{display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px;font-weight:700;color:#243646}
.manual-note{display:none;margin-top:8px;padding:9px 11px;border-radius:8px;background:#fff6df;border:1px solid #ead08d;color:#6b5715}
@media(max-width:720px){.wrap{padding:14px}.grid,.triple,.supplier-row,.clone-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="top">
    <div class="topin">
        <img src="images/lowe-logo.png" alt="Lowe Chemical Company">
        <strong>Internal Sourcing</strong>
    </div>
</div>

<main class="wrap">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
        <div>
            <h1 style="color:#0c2340;margin-bottom:4px">Edit <?= h($rfq['rfq_number']) ?></h1>
            <div>Current status: <strong><?= h($rfq['status']) ?></strong></div>
        </div>
        <div class="actions"><a class="btn secondary" href="salesworkflow.php">Sales Workflow</a><a class="btn secondary" href="rfq-view.php?id=<?= $id ?>">Cancel</a></div>
    </div>

    <form method="post" action="update-rfq.php">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_edit_csrf']) ?>">
        <input type="hidden" name="rfq_id" value="<?= $id ?>">

        <div class="card">
            <h2 style="color:#0c2340;margin-top:0">Request Details</h2>
            <div class="grid">
                <div class="field"><label>Pricing Needed By</label><input type="date" name="pricing_needed_by" value="<?= h((string)$rfq['pricing_needed_by']) ?>"></div>
                <div class="field"><label>Requested By</label><input name="requester_name" value="<?= h($rfq['requester_name']) ?>" required></div>
                <div class="field"><label>Requester Email</label><input type="email" name="requester_email" value="<?= h($rfq['requester_email']) ?>" required></div>
                <div class="field"><label>Requester Phone</label><input name="requester_phone" value="<?= h((string)$rfq['requester_phone']) ?>"></div>
                <div class="field"><label>Product Name</label><input name="product_name" value="<?= h($rfq['product_name']) ?>" required></div>
                <div class="field"><label>CAS Number</label><input name="cas_number" value="<?= h((string)$rfq['cas_number']) ?>"></div>
                <div class="field"><label>Product Grade</label><input name="product_grade" value="<?= h((string)$rfq['product_grade']) ?>"></div>
                <div class="field"><label>Packaging</label><input name="packaging" value="<?= h((string)$rfq['packaging']) ?>"></div>
                <div class="field"><label>Container Weight</label><input name="container_weight" value="<?= h((string)$rfq['container_weight']) ?>"></div>
                <div class="field"><label>Container Weight UOM</label><input name="container_weight_uom" value="<?= h((string)$rfq['container_weight_uom']) ?>"></div>
                <div class="field"><label>Quantity</label><input type="number" step="0.001" min="0" name="quantity" value="<?= h((string)$rfq['quantity']) ?>" required></div>
                <div class="field"><label>Quantity UOM</label><input name="quantity_uom" value="<?= h((string)$rfq['quantity_uom']) ?>" required></div>
                <div class="field"><label>Price Basis</label><input name="price_basis" value="<?= h((string)$rfq['price_basis']) ?>"></div>
                <div class="field"><label>Estimated Annual Usage</label><input name="estimated_annual_usage" value="<?= h((string)$rfq['estimated_annual_usage']) ?>"></div>
                <div class="field"><label>Requirement Type</label><input name="requirement_type" value="<?= h((string)$rfq['requirement_type']) ?>"></div>
                <div class="field">
                    <label>Email Format</label>
                    <select name="email_format">
                        <option value="email_only" <?= in_array($rfq['email_format'],['email','email_only'],true)?'selected':'' ?>>Email only</option>
                        <option value="email_pdf" <?= $rfq['email_format']==='email_pdf'?'selected':'' ?>>Email + Lowe PDF</option>
                    </select>
                </div>
            </div>

            <h3>Delivery</h3>
            <div class="triple">
                <div class="field"><label>City</label><input name="ship_city" value="<?= h($rfq['ship_city']) ?>" required></div>
                <div class="field"><label>State</label><input name="ship_state" value="<?= h($rfq['ship_state']) ?>" required></div>
                <div class="field"><label>ZIP</label><input name="ship_zip" value="<?= h($rfq['ship_zip']) ?>" required></div>
            </div>

            <div class="field" style="margin-top:14px"><label>Special Requirements / Notes</label><textarea name="special_requirements"><?= h((string)$rfq['special_requirements']) ?></textarea></div>
        </div>

        <div class="card">
            <h2 style="color:#0c2340;margin-top:0">Supplier Recipients</h2>
            <div class="note">You can change supplier names or email addresses here. Saving does not send the RFQ. Use the Send/Resend button after reviewing the saved changes.</div>
            <div id="supplierRows">
                <?php foreach($suppliers as $sp): ?>
                    <div class="supplier-row">
                        <div class="field"><label>Supplier</label><input class="supplier-name" name="supplier_name[]" list="supplierMasterList" autocomplete="off" value="<?= h((string)$sp['supplier_name']) ?>" required></div>
                        <div class="field"><label>Contact</label><input class="supplier-contact" name="supplier_contact[]" value="<?= h((string)$sp['contact_name']) ?>"></div>
                        <div class="field"><label>Email</label><input class="supplier-email" type="email" name="supplier_email[]" value="<?= h((string)$sp['contact_email']) ?>"></div>
                        <button type="button" class="btn remove" onclick="this.closest('.supplier-row').remove()">Remove</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn secondary" onclick="addSupplier()">+ Add Supplier</button>
            <datalist id="supplierMasterList">
                <?php foreach ($supplierDirectory as $sd): ?>
                    <option value="<?= h($sd['name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="card clone-card">
            <h2 style="margin-top:0">Create a New RFQ for Another Supplier</h2>
            <div class="note" style="border-left-color:#17613c;background:#eaf7ef">
                This creates a completely new RFQ using the request details currently shown above. The original <strong><?= h($rfq['rfq_number']) ?></strong> and its supplier/history are left unchanged.
            </div>
            <div class="clone-grid">
                <div class="field">
                    <label>New Supplier</label>
                    <div class="autocomplete-wrap">
                        <input id="newSupplierName" name="new_supplier_name" autocomplete="off" placeholder="Start typing supplier name" aria-autocomplete="list" aria-expanded="false">
                        <div id="newSupplierResults" class="autocomplete-results" role="listbox"></div>
                    </div>
                    <label class="manual-toggle"><input type="checkbox" id="manualSupplierMode" name="manual_supplier_mode" value="1"> Manual supplier not in system</label>
                    <div id="manualSupplierNote" class="manual-note">Manual mode is on. Type the supplier name exactly as you want it saved, then enter the contact and email.</div>
                </div>
                <div class="field"><label>Contact</label><input id="newSupplierContact" name="new_supplier_contact" placeholder="Auto-fills when available"></div>
                <div class="field"><label>Email</label><input id="newSupplierEmail" type="email" name="new_supplier_email" placeholder="Auto-fills when available"></div>
            </div>
            <div class="grid" style="margin-top:14px">
                <div class="field">
                    <label>Email Format for New RFQ</label>
                    <select name="new_email_format" id="newEmailFormat">
                        <option value="email_pdf" <?= in_array((string)($rfq['email_format'] ?? ''), ['email_pdf'], true) ? 'selected' : '' ?>>Email + Lowe PDF</option>
                        <option value="email_only" <?= in_array((string)($rfq['email_format'] ?? ''), ['email','email_only'], true) ? 'selected' : '' ?>>Email only</option>
                    </select>
                </div>
                <div class="note" style="margin:0;align-self:end">
                    Creating the RFQ does <strong>not</strong> send it. After creation, review the new RFQ and click <strong>Send RFQ Now</strong>.
                </div>
            </div>
            <div class="small">Supplier directory loaded: <strong><?= count($supplierDirectory) ?></strong> names. Normal mode searches Lowe's supplier list as you type. Click a matching supplier to select it and auto-fill the most recently used contact and email. Turn on Manual supplier only when the supplier is not already in the system.</div>
            <div class="actions" style="margin-top:14px">
                <button class="btn clone-action" type="submit" formaction="clone-rfq.php" formmethod="post" onclick="return confirmNewSupplierRFQ()">Create New Supplier RFQ</button>
            </div>
        </div>

        <div class="card">
            <h2 style="color:#0c2340;margin-top:0">Email Options</h2>
            <div class="grid">
                <label><input type="checkbox" name="cc_requester" value="1" <?= !empty($rfq['cc_requester'])?'checked':'' ?>> CC requester</label>
                <label><input type="checkbox" name="cc_sourcing" value="1" <?= !empty($rfq['cc_sourcing'])?'checked':'' ?>> Include Sourcing@lowechemical.com</label>
                <div class="field"><label>Additional Lowe CC</label><input name="additional_cc" value="<?= h((string)$rfq['additional_cc']) ?>"></div>
                <div class="field"><label>Email Subject</label><input name="email_subject" value="<?= h((string)$rfq['email_subject']) ?>"></div>
            </div>
        </div>

        <div class="actions">
            <button class="btn primary" type="submit">Save Changes</button>
            <a class="btn secondary" href="rfq-view.php?id=<?= $id ?>">Cancel</a>
        </div>
    </form>
</main>

<script>
const supplierDirectory = <?= json_encode($supplierDirectory, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const supplierByName = new Map(supplierDirectory.map(s => [String(s.name || '').trim().toLowerCase(), s]));

function fillSupplierRow(input){
    const row=input.closest('.supplier-row');
    if(!row) return;
    const match=supplierByName.get(input.value.trim().toLowerCase());
    if(!match) return;
    const contact=row.querySelector('.supplier-contact');
    const email=row.querySelector('.supplier-email');
    if(contact && !contact.value.trim()) contact.value=match.contact || '';
    if(email && !email.value.trim()) email.value=match.email || '';
}

function wireSupplierRow(row){
    const input=row.querySelector('.supplier-name');
    if(!input) return;
    input.addEventListener('change',()=>fillSupplierRow(input));
    input.addEventListener('blur',()=>fillSupplierRow(input));
}

document.querySelectorAll('.supplier-row').forEach(wireSupplierRow);

function addSupplier(){
    const wrap=document.getElementById('supplierRows');
    const row=document.createElement('div');
    row.className='supplier-row';
    row.innerHTML='<div class="field"><label>Supplier</label><input class="supplier-name" name="supplier_name[]" list="supplierMasterList" autocomplete="off" required placeholder="Start typing supplier name"></div><div class="field"><label>Contact</label><input class="supplier-contact" name="supplier_contact[]"></div><div class="field"><label>Email</label><input class="supplier-email" type="email" name="supplier_email[]"></div><button type="button" class="btn remove" onclick="this.closest(\'.supplier-row\').remove()">Remove</button>';
    wrap.appendChild(row);
    wireSupplierRow(row);
    row.querySelector('.supplier-name').focus();
}

const newSupplierName = document.getElementById('newSupplierName');
const newSupplierContact = document.getElementById('newSupplierContact');
const newSupplierEmail = document.getElementById('newSupplierEmail');
const newSupplierResults = document.getElementById('newSupplierResults');
const manualSupplierMode = document.getElementById('manualSupplierMode');
const manualSupplierNote = document.getElementById('manualSupplierNote');

function supplierSearchMatches(term){
    const q=String(term || '').trim().toLowerCase();
    if(!q) return [];
    return supplierDirectory.filter(s=>String(s.name || '').toLowerCase().includes(q)).slice(0,12);
}

function hideSupplierResults(){
    if(!newSupplierResults) return;
    newSupplierResults.style.display='none';
    newSupplierResults.innerHTML='';
    newSupplierName?.setAttribute('aria-expanded','false');
}

function chooseNewSupplier(supplier){
    if(!supplier || !newSupplierName) return;
    newSupplierName.value=supplier.name || '';
    if(newSupplierContact) newSupplierContact.value=supplier.contact || '';
    if(newSupplierEmail) newSupplierEmail.value=supplier.email || '';
    hideSupplierResults();
}

function renderSupplierResults(){
    if(!newSupplierName || !newSupplierResults) return;
    if(manualSupplierMode?.checked){ hideSupplierResults(); return; }
    const matches=supplierSearchMatches(newSupplierName.value);
    newSupplierResults.innerHTML='';
    if(!matches.length){
        if(newSupplierName.value.trim()){
            const none=document.createElement('div');
            none.className='autocomplete-option';
            none.textContent='No supplier found. Use Manual supplier not in system below.';
            none.style.cursor='default';
            newSupplierResults.appendChild(none);
            newSupplierResults.style.display='block';
            newSupplierName.setAttribute('aria-expanded','true');
        } else hideSupplierResults();
        return;
    }
    matches.forEach(supplier=>{
        const option=document.createElement('div');
        option.className='autocomplete-option';
        option.setAttribute('role','option');
        option.textContent=supplier.name;
        option.addEventListener('mousedown',e=>{
            e.preventDefault();
            chooseNewSupplier(supplier);
        });
        newSupplierResults.appendChild(option);
    });
    newSupplierResults.style.display='block';
    newSupplierName.setAttribute('aria-expanded','true');
}

newSupplierName?.addEventListener('input',()=>{
    if(!manualSupplierMode?.checked){
        if(newSupplierContact) newSupplierContact.value='';
        if(newSupplierEmail) newSupplierEmail.value='';
        renderSupplierResults();
    }
});
newSupplierName?.addEventListener('focus',renderSupplierResults);
newSupplierName?.addEventListener('blur',()=>setTimeout(hideSupplierResults,150));

manualSupplierMode?.addEventListener('change',()=>{
    const manual=manualSupplierMode.checked;
    hideSupplierResults();
    if(manualSupplierNote) manualSupplierNote.style.display=manual?'block':'none';
    if(newSupplierName){
        newSupplierName.value='';
        newSupplierName.placeholder=manual?'Type new supplier name manually':'Start typing supplier name';
    }
    if(newSupplierContact) newSupplierContact.value='';
    if(newSupplierEmail) newSupplierEmail.value='';
    newSupplierName?.focus();
});

function fillNewSupplier(){
    if(manualSupplierMode?.checked || !newSupplierName) return;
    const match=supplierByName.get(newSupplierName.value.trim().toLowerCase());
    if(!match) return;
    if(newSupplierContact && !newSupplierContact.value.trim()) newSupplierContact.value=match.contact || '';
    if(newSupplierEmail && !newSupplierEmail.value.trim()) newSupplierEmail.value=match.email || '';
}

function confirmNewSupplierRFQ(){
    fillNewSupplier();
    const name=document.getElementById('newSupplierName')?.value.trim() || '';
    const email=document.getElementById('newSupplierEmail')?.value.trim() || '';
    if(!name){ alert('Choose or enter the new supplier first.'); document.getElementById('newSupplierName')?.focus(); return false; }
    const manual=manualSupplierMode?.checked || false;
    if(!manual && !supplierByName.has(name.toLowerCase())){
        alert('Please choose a supplier from the search results, or check Manual supplier not in system.');
        document.getElementById('newSupplierName')?.focus();
        return false;
    }
    if(!email){ alert('Enter the new supplier email before creating the new RFQ.'); document.getElementById('newSupplierEmail')?.focus(); return false; }
    return confirm('Create a new RFQ for '+name+' using the request information shown on this page? The original RFQ will not be changed.');
}
</script>
</body>
</html>
