<?php
require_once __DIR__ . '/opportunity-config/app.php';
opp_require_device();
if (!opp_device()) { header('Location: opportunities.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: opportunities.php'); exit; }

$pdo = opp_db();
$stmt = $pdo->prepare('SELECT * FROM opportunities WHERE id=?');
$stmt->execute([$id]);
$opp = $stmt->fetch();
if (!$opp) { header('Location: opportunities.php'); exit; }

$pstmt = $pdo->prepare('SELECT * FROM opportunity_products WHERE opportunity_id=? ORDER BY id');
$pstmt->execute([$id]);
$products = $pstmt->fetchAll();

function oq_quote_number(): string {
    return 'LC-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}
function oq_rep_details(string $name): array {
    $out = ['email'=>'','phone'=>''];
    $path = __DIR__ . '/database/reps.csv';
    if (!is_file($path) || ($fh = fopen($path, 'r')) === false) return $out;
    $headers = fgetcsv($fh) ?: [];
    $norm = static function($s){ return strtolower(preg_replace('/[^a-z0-9]+/i','',trim((string)$s))); };
    $map = [];
    foreach ($headers as $i=>$h) $map[$norm($h)] = $i;
    $pick = static function($row,$map,$keys){ foreach($keys as $k){ if(isset($map[$k]) && isset($row[$map[$k]])) return trim((string)$row[$map[$k]]); } return ''; };
    while (($row = fgetcsv($fh)) !== false) {
        $rname = $pick($row,$map,['name','repname','salesrep','salesrepresentative','employeename']);
        if ($rname !== '' && strcasecmp($rname,$name) === 0) {
            $out['email'] = $pick($row,$map,['email','repemail','salesrepemail']);
            $out['phone'] = $pick($row,$map,['phone','telephone','cell','mobile','repphone','salesrepphone']);
            break;
        }
    }
    fclose($fh);
    return $out;
}

function oq_package_weight(string $productName, string $packaging, string $unit): float {
    $text = strtoupper(trim($productName . ' ' . $packaging));
    $u = preg_quote(strtoupper($unit), '/');
    if (preg_match('/(?:^|\\s)([0-9]+(?:\\.[0-9]+)?)\\s*' . $u . '(?:\\s|$)/', $text, $m)) {
        return (float)$m[1];
    }
    // Common fallback for product names that contain LB/KG/GAL even if volume_unit differs or is blank.
    if (preg_match('/(?:^|\\s)([0-9]+(?:\\.[0-9]+)?)\\s*(LB|KG|GAL|L)(?:\\s|$)/', $text, $m)) {
        return (float)$m[1];
    }
    return 1.0;
}

function oq_address(array $opp): string {
    $parts = [];
    if (trim((string)$opp['address']) !== '') $parts[] = trim((string)$opp['address']);
    $cityline = trim(implode(', ', array_filter([trim((string)$opp['city']), trim((string)$opp['state'])])) . ' ' . trim((string)$opp['zipcode']));
    if ($cityline !== '') $parts[] = $cityline;
    return implode("\n", $parts);
}

$quoteNo = oq_quote_number();
$quoteDate = date('Y-m-d');
$validThrough = date('Y-m-d', strtotime('+30 days'));
$rep = oq_rep_details((string)$opp['lowe_rep']);
$address = oq_address($opp);

$items = [];
foreach ($products as $p) {
    $volume = (float)($p['annual_volume'] ?? 0);
    $unit = strtoupper(trim((string)($p['volume_unit'] ?? 'LB')));
    if ($unit === '') $unit = 'LB';
    $productName = (string)($p['product_name'] ?? '');
    $packaging = (string)($p['packaging'] ?? '');
    $packageWeight = oq_package_weight($productName, $packaging, $unit);
    $qty = 1; // Quote starts at one package. Sales rep can change shipment quantity.
    $totalUnits = $qty * $packageWeight;

    $items[] = [
        'product_number' => (string)($p['product_no'] ?? ''),
        'product' => $productName,
        'description' => 'Opportunity annual volume: ' . number_format($volume, 2) . ' ' . $unit . '. Quote defaults to 1 package; adjust Qty for the actual shipment.',
        'cas' => (string)($p['cas_no'] ?? ''),
        'grade' => '',
        'packaging' => $packaging,
        'quantity' => $qty,
        'units_ordered' => $packageWeight,
        'unit' => $unit,
        'unit_price' => (float)($p['est_sell_price'] ?? 0),
        'manufacturer' => (string)($p['supplier'] ?? ''),
        'un_number' => '',
        'hazard_class' => '',
        'packing_group' => '',
        // Current quote builder aliases for package weight / calculated total units.
        'weight_per_unit' => $packageWeight,
        'units_per_package' => $packageWeight,
        'number_of_units' => $totalUnits,
        'total_units' => $totalUnits,
    ];
}
if (!$items) {
    $items[] = [
        'product_number'=>'','product'=>'','description'=>'','cas'=>'','grade'=>'','packaging'=>'',
        'quantity'=>1,'units_ordered'=>1,'unit'=>'LB','unit_price'=>0,'manufacturer'=>'','un_number'=>'','hazard_class'=>'','packing_group'=>'',
        'weight_per_unit'=>1,'units_per_package'=>1,'number_of_units'=>1,'total_units'=>1
    ];
}

$quoteSession = [
    'quote_number' => $quoteNo,
    'quote_date' => $quoteDate,
    'valid_through' => $validThrough,
    'sales_rep' => (string)$opp['lowe_rep'],
    'sales_email' => $rep['email'],
    'sales_phone' => $rep['phone'],
    'customer_no' => (string)$opp['customer_no'],
    'customer_company' => (string)$opp['company_name'],
    'customer_name' => (string)$opp['primary_contact'],
    'customer_email' => (string)$opp['email'],
    'customer_phone' => (string)$opp['phone'],
    'bill_to' => $address,
    'ship_to' => $address,
    'payment_terms' => 'Net 30, subject to credit approval',
    'freight_terms' => 'Delivered',
    'lead_time' => 'Subject to availability at time of order',
    'shipping_method' => '',
    'special_instructions' => '',
    'notes' => 'Created from Opportunity ' . (string)$opp['opportunity_no'] . '. Review shipment quantity before sending.',
    'freight' => 0,
    'hazmat' => 0,
    'other' => 0,
    'tax_rate' => 0,
    'items' => $items,
    'opportunity_id' => $id,
    'opportunity_no' => (string)$opp['opportunity_no'],
];

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['lowe_quote'] = $quoteSession;
$_SESSION['lowe_quote_opportunity_id'] = $id;
$_SESSION['lowe_quote_opportunity_no'] = (string)$opp['opportunity_no'];

$pdo->beginTransaction();
try {
    $q = $pdo->prepare('INSERT INTO opportunity_quotes (opportunity_id,quote_number,quote_status,quote_date,valid_through,quote_amount,notes,created_by) VALUES (?,?,?,?,?,?,?,?)');
    $q->execute([$id,$quoteNo,'Draft',$quoteDate,$validThrough,null,'Created from opportunity',$opp['lowe_rep']]);

    // Creating a quote is a real pipeline event. Move early-stage opportunities to Quoting.
    $earlyStages = ['Lead','Qualified','Sample / Trial','Customer Testing'];
    if (in_array((string)$opp['stage'], $earlyStages, true)) {
        $oldStage = (string)$opp['stage'];
        $newStage = 'Quoting';
        $prob = function_exists('opp_default_probability') ? opp_default_probability($newStage) : 65;
        $weighted = (float)$opp['estimated_annual_revenue'] * ($prob / 100);
        $pdo->prepare('UPDATE opportunities SET stage=?,status=?,probability=?,weighted_pipeline_value=? WHERE id=?')
            ->execute([$newStage,'Open',$prob,$weighted,$id]);
        $pdo->prepare('INSERT INTO opportunity_stage_history (opportunity_id,from_stage,to_stage,changed_by) VALUES (?,?,?,?)')
            ->execute([$id,$oldStage,$newStage,$opp['lowe_rep']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

header('Location: pricequote.php?from_opportunity=' . $id . '&quote=' . urlencode($quoteNo));
exit;
