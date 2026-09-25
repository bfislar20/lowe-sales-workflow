<?php
require_once __DIR__ . '/opportunity-config/app.php';
require_once __DIR__ . '/includes/workflow-nav.php';
opp_require_device();
if (!opp_device()) { header('Location: opportunities.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: opportunities.php'); exit; }

$pdo = opp_db();

function load_opportunity(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM opportunities WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) die('Opportunity not found.');
    return $row;
}

$opp = load_opportunity($pdo, $id);
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!opp_check_csrf($_POST['csrf'] ?? '')) die('Invalid request token.');

    if (isset($_POST['delete_opportunity'])) {
        $oppNo = $opp['opportunity_no'];
        $company = $opp['company_name'];
        try {
            $pdo->beginTransaction();
            // Related products, activities, tasks, and stage history are removed
            // automatically by the database ON DELETE CASCADE foreign keys.
            $stmt = $pdo->prepare('DELETE FROM opportunities WHERE id=?');
            $stmt->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        header('Location: opportunities.php?deleted=1&opp=' . urlencode($oppNo) . '&company=' . urlencode($company));
        exit;
    }

    if (isset($_POST['add_activity'])) {
        $subject = trim($_POST['subject'] ?? '');
        $details = trim($_POST['details'] ?? '');
        if ($subject === '' && $details === '') {
            header('Location: opportunity.php?id=' . $id . '&activity_empty=1#activity');
            exit;
        }
        $pdo->prepare('INSERT INTO opportunity_activities (opportunity_id,activity_type,subject,details,created_by) VALUES (?,?,?,?,?)')
            ->execute([
                $id,
                $_POST['activity_type'] ?? 'Note',
                $subject,
                $details,
                $opp['lowe_rep']
            ]);
        header('Location: opportunity.php?id=' . $id . '&activity=1#activity');
        exit;
    }

    if (isset($_POST['add_task'])) {
        $title = trim($_POST['task_title'] ?? '');
        if ($title !== '') {
            $pdo->prepare('INSERT INTO opportunity_tasks (opportunity_id,task_title,due_date,assigned_to) VALUES (?,?,?,?)')
                ->execute([
                    $id,
                    $title,
                    ($_POST['due_date'] ?? '') ?: null,
                    trim($_POST['assigned_to'] ?? $opp['lowe_rep'])
                ]);
        }
        header('Location: opportunity.php?id=' . $id . '&task=1');
        exit;
    }

    if (isset($_POST['toggle_task'])) {
        $taskId = (int)($_POST['task_id'] ?? 0);
        if ($taskId > 0) {
            $stmt = $pdo->prepare('SELECT completed FROM opportunity_tasks WHERE id=? AND opportunity_id=?');
            $stmt->execute([$taskId,$id]);
            $completed = $stmt->fetchColumn();
            if ($completed !== false) {
                $new = ((int)$completed === 1) ? 0 : 1;
                $pdo->prepare('UPDATE opportunity_tasks SET completed=?, completed_at=? WHERE id=? AND opportunity_id=?')
                    ->execute([$new, $new ? date('Y-m-d H:i:s') : null, $taskId, $id]);
            }
        }
        header('Location: opportunity.php?id=' . $id . '#tasks');
        exit;
    }

    if (isset($_POST['delete_task'])) {
        $taskId = (int)($_POST['task_id'] ?? 0);
        if ($taskId > 0) {
            $pdo->prepare('DELETE FROM opportunity_tasks WHERE id=? AND opportunity_id=?')->execute([$taskId,$id]);
        }
        header('Location: opportunity.php?id=' . $id . '#tasks');
        exit;
    }

    if (isset($_POST['update_quote_status'])) {
        $quoteLinkId = (int)($_POST['quote_link_id'] ?? 0);
        $allowedQuoteStatuses = ['Draft','Sent','Revised','Accepted','Declined','Expired'];
        $quoteStatus = trim($_POST['quote_status'] ?? 'Draft');
        if ($quoteLinkId > 0 && in_array($quoteStatus, $allowedQuoteStatuses, true)) {
            $qstmt = $pdo->prepare('SELECT quote_number,quote_status,quote_amount FROM opportunity_quotes WHERE id=? AND opportunity_id=? LIMIT 1');
            $qstmt->execute([$quoteLinkId,$id]);
            $quoteRow = $qstmt->fetch();
            if ($quoteRow) {
                $pdo->prepare('UPDATE opportunity_quotes SET quote_status=? WHERE id=? AND opportunity_id=?')
                    ->execute([$quoteStatus,$quoteLinkId,$id]);

                // Accepted quote means the opportunity is now waiting for the customer's PO.
                if ($quoteStatus === 'Accepted' && !in_array($opp['status'], ['Won','Lost'], true)) {
                    $oldStage = $opp['stage'];
                    $newStage = 'Awaiting PO';
                    $prob = 90.0;
                    $weighted = opp_num($opp['estimated_annual_revenue']) * 0.90;
                    $pdo->prepare('UPDATE opportunities SET stage=?,status=?,probability=?,weighted_pipeline_value=? WHERE id=?')
                        ->execute([$newStage,'Open',$prob,$weighted,$id]);
                    if ($oldStage !== $newStage) {
                        $pdo->prepare('INSERT INTO opportunity_stage_history (opportunity_id,from_stage,to_stage,changed_by) VALUES (?,?,?,?)')
                            ->execute([$id,$oldStage,$newStage,$opp['lowe_rep']]);
                    }
                    $subject = 'Quote Accepted - ' . $quoteRow['quote_number'];
                    $details = ($quoteRow['quote_amount'] !== null && $quoteRow['quote_amount'] !== '')
                        ? 'Accepted quote amount: ' . opp_money($quoteRow['quote_amount']) . '. Opportunity moved to Awaiting PO.'
                        : 'Opportunity moved to Awaiting PO.';
                    $pdo->prepare('INSERT INTO opportunity_activities (opportunity_id,activity_type,subject,details,created_by) VALUES (?,?,?,?,?)')
                        ->execute([$id,'Quote',$subject,$details,$opp['lowe_rep']]);
                }
            }
        }
        header('Location: opportunity.php?id=' . $id . '&quote_status_saved=1#quotes');
        exit;
    }

    if (isset($_POST['mark_won'])) {
        $wonDate = ($_POST['won_date'] ?? '') ?: date('Y-m-d');
        $poNumber = trim($_POST['po_number'] ?? '');
        $actualRevenue = opp_num($_POST['actual_annual_revenue'] ?? $opp['estimated_annual_revenue']);
        $actualCost = opp_num($_POST['actual_annual_cost'] ?? $opp['estimated_annual_cost']);
        $actualGp = $actualRevenue - $actualCost;
        $actualMargin = $actualRevenue > 0 ? ($actualGp / $actualRevenue) * 100 : 0.0;
        $closureNotes = trim($_POST['closure_notes'] ?? '');
        if ($actualRevenue <= 0) {
            header('Location: opportunity.php?id=' . $id . '&close_error=revenue#close');
            exit;
        }
        $oldStage = $opp['stage'];
        $pdo->prepare("UPDATE opportunities SET stage='Won',status='Won',probability=100,weighted_pipeline_value=0,won_date=?,lost_date=NULL,po_number=?,actual_annual_revenue=?,actual_annual_cost=?,actual_annual_gp=?,actual_margin_pct=?,competitor_name=NULL,closure_notes=?,closed_at=NOW() WHERE id=?")
            ->execute([$wonDate,$poNumber,$actualRevenue,$actualCost,$actualGp,$actualMargin,$closureNotes,$id]);
        if ($oldStage !== 'Won') {
            $pdo->prepare('INSERT INTO opportunity_stage_history (opportunity_id,from_stage,to_stage,changed_by) VALUES (?,?,?,?)')
                ->execute([$id,$oldStage,'Won',$opp['lowe_rep']]);
        }
        $details = 'Won ' . opp_money($actualRevenue) . ' annual revenue; GP ' . opp_money($actualGp);
        if ($poNumber !== '') $details .= '; PO ' . $poNumber;
        if ($closureNotes !== '') $details .= '. ' . $closureNotes;
        $pdo->prepare('INSERT INTO opportunity_activities (opportunity_id,activity_type,subject,details,created_by) VALUES (?,?,?,?,?)')
            ->execute([$id,'Won','Opportunity Won',$details,$opp['lowe_rep']]);
        header('Location: opportunity.php?id=' . $id . '&closed=won#close');
        exit;
    }

    if (isset($_POST['mark_lost'])) {
        $lostDate = ($_POST['lost_date'] ?? '') ?: date('Y-m-d');
        $lostReason = trim($_POST['lost_reason_close'] ?? '');
        $competitor = trim($_POST['competitor_name'] ?? '');
        $closureNotes = trim($_POST['closure_notes'] ?? '');
        $allowedReasons = ['Price','Competitor','Customer Cancelled','Timing','Supply Issue','Credit','Specification','No Response','Other'];
        if (!in_array($lostReason, $allowedReasons, true)) {
            header('Location: opportunity.php?id=' . $id . '&close_error=reason#close');
            exit;
        }
        $oldStage = $opp['stage'];
        $pdo->prepare("UPDATE opportunities SET stage='Lost',status='Lost',probability=0,weighted_pipeline_value=0,lost_reason=?,lost_date=?,won_date=NULL,competitor_name=?,closure_notes=?,closed_at=NOW() WHERE id=?")
            ->execute([$lostReason,$lostDate,$competitor,$closureNotes,$id]);
        if ($oldStage !== 'Lost') {
            $pdo->prepare('INSERT INTO opportunity_stage_history (opportunity_id,from_stage,to_stage,changed_by) VALUES (?,?,?,?)')
                ->execute([$id,$oldStage,'Lost',$opp['lowe_rep']]);
        }
        $details = 'Lost reason: ' . $lostReason;
        if ($competitor !== '') $details .= '; competitor: ' . $competitor;
        if ($closureNotes !== '') $details .= '. ' . $closureNotes;
        $pdo->prepare('INSERT INTO opportunity_activities (opportunity_id,activity_type,subject,details,created_by) VALUES (?,?,?,?,?)')
            ->execute([$id,'Lost','Opportunity Lost',$details,$opp['lowe_rep']]);
        header('Location: opportunity.php?id=' . $id . '&closed=lost#close');
        exit;
    }

    if (isset($_POST['delete_quote_draft'])) {
        $quoteLinkId = (int)($_POST['quote_link_id'] ?? 0);
        if ($quoteLinkId > 0) {
            // Safety rule: only Draft quote links can be deleted here.
            $stmt = $pdo->prepare("DELETE FROM opportunity_quotes WHERE id=? AND opportunity_id=? AND quote_status='Draft'");
            $stmt->execute([$quoteLinkId,$id]);
        }
        header('Location: opportunity.php?id=' . $id . '&quote_draft_deleted=1#quotes');
        exit;
    }

    if (isset($_POST['quick_stage_update'])) {
        $oldStage = $opp['stage'];
        $newStage = trim($_POST['quick_stage'] ?? $oldStage);
        if (!in_array($newStage, opp_stages(), true)) $newStage = $oldStage;
        $prob = max(0, min(100, opp_num($_POST['quick_probability'] ?? opp_default_probability($newStage))));
        $status = opp_status_for_stage($newStage);
        $weighted = opp_num($opp['estimated_annual_revenue']) * ($prob / 100);
        $pdo->prepare('UPDATE opportunities SET stage=?,status=?,probability=?,weighted_pipeline_value=? WHERE id=?')
            ->execute([$newStage,$status,$prob,$weighted,$id]);
        if ($newStage !== $oldStage) {
            $pdo->prepare('INSERT INTO opportunity_stage_history (opportunity_id,from_stage,to_stage,changed_by) VALUES (?,?,?,?)')
                ->execute([$id,$oldStage,$newStage,$opp['lowe_rep']]);
        }
        header('Location: opportunity.php?id=' . $id . '&stage_saved=1');
        exit;
    }

    if (isset($_POST['save_changes'])) {
        $oldStage = $opp['stage'];
        $newStage = trim($_POST['stage'] ?? 'Lead');
        $newRep = trim($_POST['lowe_rep'] ?? '');
        $manualProbability = isset($_POST['manual_probability']);
        $prob = $manualProbability ? max(0, min(100, opp_num($_POST['probability'] ?? 0))) : opp_default_probability($newStage);

        // Normalize product lines first so product pricing can drive opportunity financials.
        $postedProducts = $_POST['products'] ?? [];
        $normalizedProducts = [];
        $productRevenue = 0.0;
        $productCost = 0.0;
        $hasPricedProduct = false;
        $allProductCostsKnown = true;

        if (is_array($postedProducts)) {
            foreach ($postedProducts as $p) {
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
        if ($hasPricedProduct && $allProductCostsKnown) $cost = $productCost;
        elseif ($manualCost > 0) $cost = $manualCost;
        else $cost = 0.0;

        // Unknown cost must not be treated as 100% gross profit.
        $gp = $cost > 0 ? $revenue - $cost : 0.0;
        $margin = ($revenue > 0 && $cost > 0) ? ($gp / $revenue) * 100 : 0.0;
        $weighted = $revenue * ($prob / 100);

        $sql = "UPDATE opportunities SET
            company_name=?, customer_no=?, primary_contact=?, contact_title=?, department=?,
            phone=?, email=?, address=?, city=?, state=?, zipcode=?, lowe_rep=?, stage=?, status=?,
            probability=?, expected_close_date=?, desired_start_date=?, next_action=?, next_followup_date=?,
            estimated_annual_revenue=?, estimated_annual_cost=?, estimated_annual_gp=?, estimated_margin_pct=?,
            weighted_pipeline_value=?, industry_application=?, opportunity_summary=?, success_definition=?,
            internal_notes=?, lost_reason=?
            WHERE id=?";

        $pdo->prepare($sql)->execute([
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
            $newRep,
            $newStage,
            opp_status_for_stage($newStage),
            $prob,
            ($_POST['expected_close_date'] ?? '') ?: null,
            ($_POST['desired_start_date'] ?? '') ?: null,
            trim($_POST['next_action'] ?? ''),
            ($_POST['next_followup_date'] ?? '') ?: null,
            $revenue,
            $cost,
            $gp,
            $margin,
            $weighted,
            trim($_POST['industry_application'] ?? ''),
            trim($_POST['opportunity_summary'] ?? ''),
            trim($_POST['success_definition'] ?? ''),
            trim($_POST['internal_notes'] ?? ''),
            trim($_POST['lost_reason'] ?? ''),
            $id
        ]);

        if ($newStage !== $oldStage) {
            $pdo->prepare('INSERT INTO opportunity_stage_history (opportunity_id,from_stage,to_stage,changed_by) VALUES (?,?,?,?)')
                ->execute([$id, $oldStage, $newStage, $newRep]);
        }

        $pdo->prepare('DELETE FROM opportunity_products WHERE opportunity_id=?')->execute([$id]);
        if ($normalizedProducts) {
            $pstmt = $pdo->prepare('INSERT INTO opportunity_products
                (opportunity_id,product_no,product_name,cas_no,supplier,packaging,annual_volume,volume_unit,est_sell_price,est_cost,est_annual_revenue,est_annual_gp)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($normalizedProducts as $p) {
                $pstmt->execute([
                    $id,$p['product_no'],$p['product_name'],$p['cas_no'],$p['supplier'],$p['packaging'],
                    $p['annual_volume'],$p['volume_unit'],$p['est_sell_price'],$p['est_cost'],
                    $p['est_annual_revenue'],$p['est_annual_gp']
                ]);
            }
        }

        header('Location: opportunity.php?id=' . $id . '&saved=1');
        exit;
    }

}

$opp = load_opportunity($pdo, $id);
$productsStmt = $pdo->prepare('SELECT * FROM opportunity_products WHERE opportunity_id=? ORDER BY id');
$productsStmt->execute([$id]);
$products = $productsStmt->fetchAll();
$actsStmt = $pdo->prepare("SELECT * FROM opportunity_activities WHERE opportunity_id=? AND (TRIM(COALESCE(subject,'')) <> '' OR TRIM(COALESCE(details,'')) <> '') ORDER BY activity_date DESC,id DESC");
$actsStmt->execute([$id]);
$acts = $actsStmt->fetchAll();
$stageStmt = $pdo->prepare('SELECT * FROM opportunity_stage_history WHERE opportunity_id=? ORDER BY changed_at DESC,id DESC');
$stageStmt->execute([$id]);
$stageHistory = $stageStmt->fetchAll();
$tasksStmt = $pdo->prepare('SELECT * FROM opportunity_tasks WHERE opportunity_id=? ORDER BY completed ASC, due_date IS NULL, due_date ASC, id DESC');
$tasksStmt->execute([$id]);
$tasks = $tasksStmt->fetchAll();
$quotes = [];
try {
    $quotesStmt = $pdo->prepare('SELECT * FROM opportunity_quotes WHERE opportunity_id=? ORDER BY created_at DESC,id DESC');
    $quotesStmt->execute([$id]);
    $quotes = $quotesStmt->fetchAll();
} catch (Throwable $e) {
    // Phase 4 table may not be installed yet. Keep the opportunity page usable.
    $quotes = [];
}

$editMode = isset($_GET['edit']);
$device = opp_device();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=opp_h($opp['opportunity_no'])?> - Lowe Opportunity</title>
<style>
:root{--navy:#071f45;--blue:#163f78;--red:#d20f18;--line:#d6dbe6;--light:#f5f7fb;--text:#07152e}
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#eef2f7;color:var(--text)}
.page{max-width:1180px;margin:auto;padding:18px}.top{background:var(--blue);color:#fff;border-top:4px solid var(--red);border-radius:14px;padding:20px;display:flex;justify-content:space-between;gap:16px;align-items:center}.top h1{margin:0 0 3px}.top-actions{display:flex;gap:7px;flex-wrap:wrap}.btn{display:inline-block;padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:700;border:0;cursor:pointer;font-size:14px}.white{background:#fff;color:var(--navy)}.blue{background:#1f5b98;color:#fff}.red{background:var(--red);color:#fff}.gray{background:#e9eef5;color:var(--navy)}
.notice{margin-top:12px;background:#e8f6ec;border:1px solid #b9dfc3;color:#185d2b;padding:10px 13px;border-radius:9px}.card{background:#fff;border:1px solid var(--line);border-radius:12px;margin-top:14px;overflow:hidden}.title{background:var(--navy);color:#fff;padding:10px 14px;font-weight:700}.body{padding:14px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.g2{grid-template-columns:repeat(2,1fr)}.field b,label{display:block;font-size:12px;color:#657288;margin-bottom:4px;text-transform:uppercase;font-weight:700}.wide{grid-column:1/-1}.field-value{min-height:20px}
input,select,textarea{width:100%;padding:10px;border:1px solid #cbd2df;border-radius:8px;background:#fff;font-size:14px}textarea{min-height:90px;resize:vertical}.product-edit{display:grid;grid-template-columns:1.1fr .7fr .65fr .9fr .75fr .75fr .55fr .75fr .75fr 44px;gap:7px;align-items:end;margin-bottom:8px}.product-head{font-size:11px;font-weight:700;color:#657288;text-transform:uppercase}.remove{background:#fee9e9;color:#a40000;border:1px solid #f4b8b8;border-radius:7px;height:39px;cursor:pointer;font-weight:700}table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #e7ebf1;text-align:left;vertical-align:top}th{background:#f2f5f9}.task-row{display:grid;grid-template-columns:1fr 140px 150px auto;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #eee}.task-row.done{opacity:.58}.task-row.done .task-name{text-decoration:line-through}.task-actions{display:flex;gap:6px;flex-wrap:wrap}.smallbtn{padding:7px 9px;border:0;border-radius:7px;font-weight:700;cursor:pointer}.green{background:#27864b;color:#fff}.danger{background:#b73535;color:#fff}.wonbox{background:#eef9f1;border:1px solid #b9dfc3;border-radius:10px;padding:14px}.lostbox{background:#fff2f2;border:1px solid #edc1c1;border-radius:10px;padding:14px}.close-actions{display:grid;grid-template-columns:1fr 1fr;gap:14px}.close-actions h3{margin:0 0 10px;color:var(--navy)}@media(max-width:760px){.close-actions{grid-template-columns:1fr}}@media(max-width:700px){.task-row{grid-template-columns:1fr}.task-actions{justify-content:flex-start}}.activity{padding:10px 0;border-bottom:1px solid #eee}.activity:last-child{border:0}.stage-row{padding:8px 0;border-bottom:1px solid #eee}.stage-row:last-child{border:0}.muted{color:#657288;font-size:12px}.actions-bottom{display:flex;gap:10px;justify-content:center;padding:16px;flex-wrap:wrap}.lookup-wrap{position:relative;z-index:5000}.lookup-wrap:focus-within{z-index:5001}.lookup-results{position:absolute;z-index:99999;left:0;top:calc(100% + 5px);width:max(100%,520px);max-width:min(720px,82vw);background:#fff;border:2px solid var(--navy);border-radius:10px;box-shadow:0 14px 34px rgba(7,31,69,.24);max-height:360px;overflow-y:auto;display:none}.lookup-item{padding:12px 14px;border-bottom:1px solid #dfe5ee;cursor:pointer;background:#fff;line-height:1.25}.lookup-item:last-child{border-bottom:0}.lookup-item:hover,.lookup-item:focus{background:#eaf2fb}.lookup-item strong{display:block;color:var(--navy);font-size:15px;margin-bottom:3px}.lookup-item small{display:block;color:#4e5f75;font-size:12px;white-space:normal}.product-results{width:560px;max-width:min(760px,86vw)}.lookup-note{font-size:12px;color:#657288;margin-top:5px}@media(max-width:700px){.lookup-results,.product-results{position:fixed!important;left:10px!important;right:10px!important;top:90px!important;bottom:auto!important;width:auto!important;max-width:none!important;max-height:58vh!important;z-index:2147483000!important;border-width:2px;box-shadow:0 18px 50px rgba(0,0,0,.35);-webkit-overflow-scrolling:touch}.lookup-item{padding:14px 15px;font-size:16px;touch-action:manipulation}.lookup-item strong{font-size:16px}.lookup-item small{font-size:13px}.product-row,.product-edit{position:relative;z-index:20}}
<?php if($device==='mobile'): ?>
.page{padding:10px}.top{display:block;text-align:center}.top-actions{justify-content:center;margin-top:12px}.grid,.g2,.product-edit{grid-template-columns:1fr}.product-head{display:none}.remove{width:100%}
<?php else: ?>
@media(max-width:900px){.grid,.g2,.product-edit{grid-template-columns:1fr}.product-head{display:none}}
<?php endif; ?>
</style>
</head>
<body>
<main class="page">
<div class="top">
  <div><h1><?=opp_h($opp['company_name'])?></h1><div><?=opp_h($opp['opportunity_no'])?> • <?=opp_h($opp['stage'])?></div></div>
  <div class="top-actions">
    <a class="btn white" href="<?=htmlspecialchars(workflow_url(),ENT_QUOTES,'UTF-8')?>">← Sales Workflow</a> <a class="btn white" href="opportunities.php">Pipeline</a> <a class="btn white" href="opportunity-dashboard.php">Management Dashboard</a> <a class="btn white" href="opportunity-tasks.php">Task Center</a> <a class="btn white" href="opportunity-report.php">Reports</a> <a class="btn white" href="opportunity-funnel.php">Funnel</a> <a class="btn white" href="opportunity-closed.php">Closed</a>
    <?php if($editMode): ?>
      <a class="btn white" href="opportunity.php?id=<?=$id?>">Cancel Edit</a>
    <?php else: ?>
      <a class="btn green" href="opportunity-create-quote.php?id=<?=$id?>" onclick="return confirm('Create a new Price Quote from this opportunity? Customer, rep and product information will be prefilled.');">Create Quote</a>
      <a class="btn white" href="opportunity.php?id=<?=$id?>&edit=1">Edit Opportunity</a>
      <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Permanently delete <?=opp_h($opp['opportunity_no'])?> for <?=opp_h($opp['company_name'])?>? This will also delete its products, activities, tasks, and stage history. This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>">
        <input type="hidden" name="delete_opportunity" value="1">
        <button class="btn red" type="submit">Delete Opportunity</button>
      </form>
    <?php endif; ?>
    <a class="btn white" href="?id=<?=$id?>&change_device=1">Change Device</a>
  </div>
</div>

<?php if(isset($_GET['saved'])):?><div class="notice">Opportunity changes saved.</div><?php endif;?>
<?php if(isset($_GET['activity'])):?><div class="notice">Activity added.</div><?php endif;?>
<?php if(isset($_GET['stage_saved'])):?><div class="notice">Stage and probability updated.</div><?php endif;?>
<?php if(isset($_GET['quote_status_saved'])):?><div class="notice">Quote status updated.</div><?php endif;?>
<?php if(isset($_GET['quote_draft_deleted'])):?><div class="notice">Draft quote deleted.</div><?php endif;?>
<?php if(($_GET['closed'] ?? '')==='won'):?><div class="notice">Opportunity marked Won.</div><?php endif;?>
<?php if(($_GET['closed'] ?? '')==='lost'):?><div class="notice" style="background:#fff0f0;border-color:#edc1c1;color:#8a2020">Opportunity marked Lost.</div><?php endif;?>
<?php if(isset($_GET['close_error'])):?><div class="notice" style="background:#fff4e5;border-color:#f1c98a;color:#7a4a00">Complete the required close information before saving.</div><?php endif;?>
<?php if(isset($_GET['activity_empty'])):?><div class="notice" style="background:#fff4e5;border-color:#f1c98a;color:#7a4a00">Enter a subject or activity details before adding an activity.</div><?php endif;?>

<?php if($editMode): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>">
<input type="hidden" name="save_changes" value="1">

<div class="card"><div class="title">Edit Customer / Opportunity</div><div class="body grid">
  <div class="lookup-wrap"><label>Company Name</label><input id="customerLookup" name="company_name" autocomplete="off" required value="<?=opp_h($opp['company_name'])?>"><div id="customerResults" class="lookup-results"></div></div>
  <div><label>Customer #</label><input id="customerNo" name="customer_no" value="<?=opp_h($opp['customer_no'])?>"></div>
  <div class="lookup-wrap"><label>Lowe Rep</label><input id="repLookup" name="lowe_rep" autocomplete="off" value="<?=opp_h($opp['lowe_rep'])?>"><div id="repResults" class="lookup-results"></div></div>
  <div><label>Primary Contact</label><input id="primaryContact" name="primary_contact" value="<?=opp_h($opp['primary_contact'])?>"></div>
  <div><label>Contact Title</label><input id="contactTitle" name="contact_title" value="<?=opp_h($opp['contact_title'])?>"></div>
  <div><label>Department</label><input id="department" name="department" value="<?=opp_h($opp['department'])?>"></div>
  <div><label>Phone</label><input id="customerPhone" name="phone" value="<?=opp_h($opp['phone'])?>"></div>
  <div><label>Email</label><input id="customerEmail" type="email" name="email" value="<?=opp_h($opp['email'])?>"></div>
  <div><label>Address</label><input id="customerAddress" name="address" value="<?=opp_h($opp['address'])?>"></div>
  <div><label>City</label><input id="customerCity" name="city" value="<?=opp_h($opp['city'])?>"></div>
  <div><label>State</label><input id="customerState" name="state" value="<?=opp_h($opp['state'])?>"></div>
  <div><label>ZIP</label><input id="customerZip" name="zipcode" value="<?=opp_h($opp['zipcode'])?>"></div>
  <div class="wide"><label>Industry / Application</label><input name="industry_application" value="<?=opp_h($opp['industry_application'])?>"></div>
</div></div>

<div class="card"><div class="title">Edit Pipeline</div><div class="body grid">
  <div><label>Stage</label><select id="stageSelect" name="stage"><?php foreach(opp_stages() as $s):?><option value="<?=opp_h($s)?>" <?=$opp['stage']===$s?'selected':''?>><?=opp_h($s)?></option><?php endforeach;?></select></div>
  <div><label>Status</label><input id="statusDisplay" value="<?=opp_h($opp['status'])?>" disabled><div class="muted">Set automatically from stage.</div></div>
  <div><label>Probability %</label><input id="probabilityInput" type="number" min="0" max="100" step="1" name="probability" value="<?=opp_h($opp['probability'])?>"><label style="margin-top:7px;text-transform:none;font-weight:400"><input id="manualProbability" type="checkbox" name="manual_probability" value="1" style="width:auto;margin-right:6px" <?=abs((float)$opp['probability']-opp_default_probability($opp['stage']))>0.01?'checked':''?>>Manual probability override</label></div>
  <div><label>Expected Close</label><input type="date" name="expected_close_date" value="<?=opp_h($opp['expected_close_date'])?>"></div>
  <div><label>Desired Start Date</label><input type="date" name="desired_start_date" value="<?=opp_h($opp['desired_start_date'])?>"></div>
  <div><label>Next Follow-Up</label><input type="date" name="next_followup_date" value="<?=opp_h($opp['next_followup_date'])?>"></div>
  <div class="wide"><label>Next Action</label><input name="next_action" value="<?=opp_h($opp['next_action'])?>"></div>
  <div><label>Estimated Annual Revenue</label><input name="estimated_annual_revenue" value="<?=opp_h($opp['estimated_annual_revenue'])?>"></div>
  <div><label>Estimated Annual Cost</label><input name="estimated_annual_cost" value="<?=opp_h($opp['estimated_annual_cost'])?>"></div>
  <div><label>Lost Reason</label><input name="lost_reason" value="<?=opp_h($opp['lost_reason'])?>"></div><div class="wide muted">If product volume and sell price are entered, product lines determine annual revenue. Product costs determine GP when entered; otherwise the annual cost field is used.</div>
</div></div>

<div class="card"><div class="title">Edit Products</div><div class="body">
  <div class="product-edit product-head"><div>Product</div><div>Product #</div><div>CAS</div><div>Supplier</div><div>Packaging</div><div>Annual Volume</div><div>Unit</div><div>Est. Sell</div><div>Est. Cost</div><div></div></div>
  <div id="productsEdit">
    <?php if(!$products) $products=[['product_name'=>'','product_no'=>'','cas_no'=>'','supplier'=>'','packaging'=>'','annual_volume'=>'','volume_unit'=>'','est_sell_price'=>'','est_cost'=>'']]; ?>
    <?php foreach($products as $i=>$p): ?>
    <div class="product-edit">
      <div class="lookup-wrap"><input class="product-lookup" name="products[<?=$i?>][product_name]" placeholder="Product" autocomplete="off" value="<?=opp_h($p['product_name'])?>"><div class="lookup-results product-results"></div></div>
      <input name="products[<?=$i?>][product_no]" placeholder="Product #" value="<?=opp_h($p['product_no'])?>">
      <input name="products[<?=$i?>][cas_no]" placeholder="CAS" value="<?=opp_h($p['cas_no'])?>">
      <input name="products[<?=$i?>][supplier]" placeholder="Supplier" value="<?=opp_h($p['supplier'])?>">
      <input name="products[<?=$i?>][packaging]" placeholder="Packaging" value="<?=opp_h($p['packaging'])?>">
      <input name="products[<?=$i?>][annual_volume]" placeholder="Volume" value="<?=opp_h($p['annual_volume'])?>">
      <input name="products[<?=$i?>][volume_unit]" placeholder="LB" value="<?=opp_h($p['volume_unit'])?>">
      <input name="products[<?=$i?>][est_sell_price]" placeholder="$ / unit" value="<?=opp_h($p['est_sell_price'])?>">
      <input name="products[<?=$i?>][est_cost]" placeholder="Cost / unit" value="<?=opp_h($p['est_cost'])?>">
      <button type="button" class="remove" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endforeach; ?>
  </div>
  <button type="button" class="btn gray" onclick="addProductRow()">+ Add Product</button>
</div></div>

<div class="card"><div class="title">Edit Opportunity Details</div><div class="body g2 grid">
  <div><label>Opportunity Summary</label><textarea name="opportunity_summary"><?=opp_h($opp['opportunity_summary'])?></textarea></div>
  <div><label>What Would Success Look Like?</label><textarea name="success_definition"><?=opp_h($opp['success_definition'])?></textarea></div>
  <div class="wide"><label>Internal Notes</label><textarea name="internal_notes"><?=opp_h($opp['internal_notes'])?></textarea></div>
</div></div>

<div class="actions-bottom"><button class="btn red" type="submit">Save Changes</button><a class="btn gray" href="opportunity.php?id=<?=$id?>">Cancel</a></div>
</form>

<?php else: ?>
<?php if(!in_array($opp['status'], ['Won','Lost'], true)): ?>
<div class="card"><div class="title">Quick Stage Update</div><div class="body">
<form method="post"><input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>"><input type="hidden" name="quick_stage_update" value="1">
<div class="grid"><div><label>Stage</label><select id="quickStage" name="quick_stage"><?php foreach(opp_stages() as $s):?><option value="<?=opp_h($s)?>" <?=$opp['stage']===$s?'selected':''?>><?=opp_h($s)?></option><?php endforeach;?></select></div><div><label>Probability %</label><input id="quickProbability" name="quick_probability" type="number" min="0" max="100" step="1" value="<?=opp_h($opp['probability'])?>"></div><div style="display:flex;align-items:end"><button class="btn blue" type="submit" style="width:100%">Update Stage</button></div></div>
<div class="muted" style="margin-top:8px">Changing the stage suggests the standard probability. You can type a different probability before saving.</div>
</form></div></div>
<?php endif; ?>
<div class="card"><div class="title">Opportunity Summary</div><div class="body grid">
  <div class="field"><b>Contact</b><div class="field-value"><?=opp_h($opp['primary_contact'])?></div></div>
  <div class="field"><b>Lowe Rep</b><div class="field-value"><?=opp_h($opp['lowe_rep'])?></div></div>
  <div class="field"><b>Stage / Status</b><div class="field-value"><?=opp_h($opp['stage'])?> / <?=opp_h($opp['status'])?></div></div>
  <div class="field"><b>Annual Revenue</b><div class="field-value"><?=opp_money($opp['estimated_annual_revenue'])?></div></div>
  <div class="field"><b>Expected GP</b><div class="field-value"><?=opp_money($opp['estimated_annual_gp'])?></div></div>
  <div class="field"><b>Probability</b><div class="field-value"><?=number_format((float)$opp['probability'],0)?>%</div></div>
  <div class="field"><b>Expected Close</b><div class="field-value"><?=opp_h($opp['expected_close_date'])?></div></div>
  <div class="field"><b>Next Action</b><div class="field-value"><?=opp_h($opp['next_action'])?></div></div>
  <div class="field"><b>Next Follow-Up</b><div class="field-value"><?=opp_h($opp['next_followup_date'])?></div></div>
  <div class="field wide"><b>Summary</b><div class="field-value"><?=nl2br(opp_h($opp['opportunity_summary']))?></div></div>
</div></div>

<div class="card" id="close"><div class="title">Opportunity Outcome</div><div class="body">
<?php if($opp['status']==='Won'): ?>
  <div class="wonbox"><h3 style="margin-top:0">Won</h3><div class="grid"><div class="field"><b>Won Date</b><div><?=opp_h($opp['won_date'] ?? '')?></div></div><div class="field"><b>PO / Order Reference</b><div><?=opp_h($opp['po_number'] ?? '')?></div></div><div class="field"><b>Actual Annual Revenue</b><div><?=opp_money($opp['actual_annual_revenue'] ?? 0)?></div></div><div class="field"><b>Actual Annual Cost</b><div><?=opp_money($opp['actual_annual_cost'] ?? 0)?></div></div><div class="field"><b>Actual GP</b><div><?=opp_money($opp['actual_annual_gp'] ?? 0)?></div></div><div class="field"><b>Actual Margin</b><div><?=number_format((float)($opp['actual_margin_pct'] ?? 0),1)?>%</div></div><div class="field wide"><b>Closure Notes</b><div><?=nl2br(opp_h($opp['closure_notes'] ?? ''))?></div></div></div></div>
<?php elseif($opp['status']==='Lost'): ?>
  <div class="lostbox"><h3 style="margin-top:0">Lost</h3><div class="grid"><div class="field"><b>Lost Date</b><div><?=opp_h($opp['lost_date'] ?? '')?></div></div><div class="field"><b>Reason</b><div><?=opp_h($opp['lost_reason'] ?? '')?></div></div><div class="field"><b>Competitor</b><div><?=opp_h($opp['competitor_name'] ?? '')?></div></div><div class="field wide"><b>Closure Notes</b><div><?=nl2br(opp_h($opp['closure_notes'] ?? ''))?></div></div></div></div>
<?php else: ?>
  <div class="close-actions">
    <div class="wonbox"><h3>Mark Won</h3><form method="post"><input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>"><input type="hidden" name="mark_won" value="1"><div class="grid g2"><div><label>Won Date</label><input type="date" name="won_date" value="<?=date('Y-m-d')?>" required></div><div><label>PO / Order Reference</label><input name="po_number" placeholder="Optional PO or order #"></div><div><label>Actual Annual Revenue</label><input type="number" step="0.01" min="0" name="actual_annual_revenue" value="<?=opp_h($opp['estimated_annual_revenue'])?>" required></div><div><label>Actual Annual Cost</label><input type="number" step="0.01" min="0" name="actual_annual_cost" value="<?=opp_h($opp['estimated_annual_cost'])?>"></div><div class="wide"><label>Closure Notes</label><textarea name="closure_notes" placeholder="Optional notes about the win"></textarea></div></div><div style="margin-top:10px"><button class="btn green" type="submit" onclick="return confirm('Mark this opportunity as Won?');">Mark Won</button></div></form></div>
    <div class="lostbox"><h3>Mark Lost</h3><form method="post"><input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>"><input type="hidden" name="mark_lost" value="1"><div class="grid g2"><div><label>Lost Date</label><input type="date" name="lost_date" value="<?=date('Y-m-d')?>" required></div><div><label>Loss Reason</label><select name="lost_reason_close" required><option value="">Select reason...</option><?php foreach(['Price','Competitor','Customer Cancelled','Timing','Supply Issue','Credit','Specification','No Response','Other'] as $lr):?><option><?=opp_h($lr)?></option><?php endforeach;?></select></div><div class="wide"><label>Competitor</label><input name="competitor_name" placeholder="Optional competitor name"></div><div class="wide"><label>Closure Notes</label><textarea name="closure_notes" placeholder="What caused the loss?"></textarea></div></div><div style="margin-top:10px"><button class="btn danger" type="submit" onclick="return confirm('Mark this opportunity as Lost?');">Mark Lost</button></div></form></div>
  </div>
<?php endif; ?>
</div></div>

<div class="card"><div class="title">Products</div><div class="body"><table><thead><tr><th>Product</th><th>Supplier</th><th>Packaging</th><th>Annual Volume</th><th>Est. Sell</th><th>Est. Cost</th></tr></thead><tbody>
<?php if(!$products):?><tr><td colspan="6">No products entered.</td></tr><?php endif;?>
<?php foreach($products as $p):?><tr><td><?=opp_h($p['product_name'])?></td><td><?=opp_h($p['supplier'])?></td><td><?=opp_h($p['packaging'])?></td><td><?=number_format((float)$p['annual_volume'],2)?> <?=opp_h($p['volume_unit'])?></td><td><?=opp_money($p['est_sell_price'])?></td><td><?=opp_money($p['est_cost'])?></td></tr><?php endforeach;?></tbody></table></div></div>

<div class="card" id="quotes"><div class="title">Quotes</div><div class="body">
  <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
    <div class="muted">Quotes created from this opportunity.</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn green" href="opportunity-create-quote.php?id=<?=$id?>" onclick="return confirm('Create another Price Quote from this opportunity?');">+ Create Quote</a><a class="btn gray" href="quotes.php">Quote History</a></div>
  </div>
  <?php if(!$quotes): ?>
    <div class="muted">No linked quotes yet.</div>
  <?php else: ?>
    <table><thead><tr><th>Quote #</th><th>Date</th><th>Valid Through</th><th>Status</th><th>Amount</th><th>Action</th></tr></thead><tbody>
    <?php foreach($quotes as $q): ?>
      <tr>
        <td><strong><?=opp_h($q['quote_number'])?></strong></td>
        <td><?=opp_h($q['quote_date'])?></td>
        <td><?=opp_h($q['valid_through'])?></td>
        <td>
          <form method="post" style="display:flex;gap:6px;align-items:center;min-width:205px">
            <input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>">
            <input type="hidden" name="update_quote_status" value="1">
            <input type="hidden" name="quote_link_id" value="<?=(int)$q['id']?>">
            <select name="quote_status" style="padding:7px"><?php foreach(['Draft','Sent','Revised','Accepted','Declined','Expired'] as $qs):?><option value="<?=opp_h($qs)?>" <?=$q['quote_status']===$qs?'selected':''?>><?=opp_h($qs)?></option><?php endforeach;?></select>
            <button class="smallbtn blue" type="submit">Save</button>
          </form>
        </td>
        <td><?=($q['quote_amount']===null||$q['quote_amount']==='')?'—':opp_money($q['quote_amount'])?></td>
        <td>
          <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <a class="btn gray" href="pricequote.php?load=<?=urlencode($q['quote_number'])?>">Open Quote</a>
            <?php if (($q['quote_status'] ?? '') === 'Draft'): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete draft quote <?=opp_h($q['quote_number'])?>? This removes the draft from this opportunity. This cannot be undone.');">
                <input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>">
                <input type="hidden" name="delete_quote_draft" value="1">
                <input type="hidden" name="quote_link_id" value="<?=(int)$q['id']?>">
                <button class="smallbtn danger" type="submit">Delete Draft</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</div></div>

<div class="card" id="tasks"><div class="title">Tasks & Follow-Ups</div><div class="body">
<?php if(!$tasks): ?><div class="muted">No tasks yet.</div><?php endif; ?>
<?php foreach($tasks as $t): ?>
<div class="task-row <?=((int)$t['completed']===1)?'done':''?>">
  <div><div class="task-name"><strong><?=opp_h($t['task_title'])?></strong></div><div class="muted">Created <?=opp_h($t['created_at'])?></div></div>
  <div><strong>Due:</strong><br><?=opp_h($t['due_date'] ?: 'No date')?></div>
  <div><strong>Assigned:</strong><br><?=opp_h($t['assigned_to'] ?: $opp['lowe_rep'])?></div>
  <div class="task-actions">
    <form method="post"><input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>"><input type="hidden" name="toggle_task" value="1"><input type="hidden" name="task_id" value="<?=(int)$t['id']?>"><button class="smallbtn green" type="submit"><?=((int)$t['completed']===1)?'Reopen':'Complete'?></button></form>
    <form method="post" onsubmit="return confirm('Delete this task?');"><input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>"><input type="hidden" name="delete_task" value="1"><input type="hidden" name="task_id" value="<?=(int)$t['id']?>"><button class="smallbtn danger" type="submit">Delete</button></form>
  </div>
</div>
<?php endforeach; ?>
<div style="margin-top:14px;border-top:1px solid #e7ebf1;padding-top:14px">
<form method="post"><input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>"><input type="hidden" name="add_task" value="1">
<div class="grid"><div class="wide"><label>New Task</label><input name="task_title" placeholder="Example: Follow up on sample evaluation" required></div><div><label>Due Date</label><input type="date" name="due_date"></div><div><label>Assigned To</label><input name="assigned_to" value="<?=opp_h($opp['lowe_rep'])?>"></div></div>
<div style="margin-top:10px"><button class="btn blue" type="submit">+ Add Task</button></div>
</form></div>
</div></div>

<div class="card"><div class="title">Stage History</div><div class="body">
<?php if(!$stageHistory):?><div>No stage changes recorded yet.</div><?php endif;?>
<?php foreach($stageHistory as $s):?><div class="stage-row"><strong><?=opp_h($s['from_stage'] ?: 'New')?> → <?=opp_h($s['to_stage'])?></strong><div class="muted"><?=opp_h($s['changed_at'])?><?= $s['changed_by']?' • '.opp_h($s['changed_by']):'' ?></div></div><?php endforeach;?>
</div></div>

<div class="card"><div class="title">Activity History</div><div class="body">
<?php if(!$acts):?><div>No activity recorded yet.</div><?php endif;?>
<?php foreach($acts as $a):?><div class="activity"><strong><?=opp_h($a['activity_type'])?><?= $a['subject']?' - '.opp_h($a['subject']):'' ?></strong><div><?=nl2br(opp_h($a['details']))?></div><small><?=opp_h($a['activity_date'])?><?= $a['created_by']?' • '.opp_h($a['created_by']):'' ?></small></div><?php endforeach;?>
</div></div>

<div class="card" id="activity"><div class="title">Add Activity</div><div class="body"><form method="post"><input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>"><input type="hidden" name="add_activity" value="1"><div class="grid"><div><select name="activity_type"><option>Note</option><option>Call</option><option>Email</option><option>Meeting</option><option>Sample</option><option>Quote</option><option>Follow-Up</option></select></div><div><input name="subject" placeholder="Subject"></div><div></div><div class="wide"><textarea name="details" placeholder="Activity details"></textarea></div></div><div class="muted" style="margin-top:8px">Enter either a subject or activity details.</div><div style="margin-top:10px"><button class="btn red">Add Activity</button></div></form></div></div>
<?php endif; ?>
</main>
<script>
const stageDefaults = <?=json_encode(opp_stage_probabilities())?>;
const stageStatus = {'Won':'Won','Lost':'Lost','On Hold':'On Hold'};
const stageSelect=document.getElementById('stageSelect'),probabilityInput=document.getElementById('probabilityInput'),manualProbability=document.getElementById('manualProbability'),statusDisplay=document.getElementById('statusDisplay');
function syncStageDefaults(){if(!stageSelect)return;const st=stageSelect.value;if(statusDisplay)statusDisplay.value=stageStatus[st]||'Open';if(probabilityInput && manualProbability && !manualProbability.checked)probabilityInput.value=stageDefaults[st]??10;}
if(stageSelect)stageSelect.addEventListener('change',syncStageDefaults);
if(probabilityInput)probabilityInput.addEventListener('input',()=>{if(manualProbability)manualProbability.checked=true;});
if(manualProbability)manualProbability.addEventListener('change',()=>{if(!manualProbability.checked)syncStageDefaults();});
const quickStage=document.getElementById('quickStage'),quickProbability=document.getElementById('quickProbability');
if(quickStage&&quickProbability)quickStage.addEventListener('change',()=>{quickProbability.value=stageDefaults[quickStage.value]??10;});
let productIndex = <?=count($products)?>;
const debounce=(fn,delay=220)=>{let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),delay)}};
async function apiSearch(url,q){const r=await fetch(url+'?q='+encodeURIComponent(q),{cache:'no-store'});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'Lookup failed');return j.results||[];}
function showResults(box,items,render,onPick){box.innerHTML='';if(!items.length){box.innerHTML='<div class="lookup-item">No matches found. You can keep the current/manual value.</div>';box.style.display='block';return;}items.forEach(item=>{const d=document.createElement('div');d.className='lookup-item';d.setAttribute('role','button');d.tabIndex=0;d.innerHTML=render(item);const choose=(ev)=>{if(ev){ev.preventDefault();ev.stopPropagation();}onPick(item);box.style.display='none';};d.addEventListener('pointerdown',choose);d.addEventListener('keydown',ev=>{if(ev.key==='Enter'||ev.key===' '){choose(ev);}});box.appendChild(d)});box.style.display='block';}
const customerInput=document.getElementById('customerLookup'),customerBox=document.getElementById('customerResults');
if(customerInput)customerInput.addEventListener('input',debounce(async()=>{const q=customerInput.value.trim();if(q.length<2){customerBox.style.display='none';return;}try{const items=await apiSearch('opportunity-api/customer-search.php',q);showResults(customerBox,items,i=>`<strong>${i.customer_name||''}</strong><small>${[i.customer_no,i.city,i.state,i.zipcode,i.phone].filter(Boolean).join(' · ')}</small>`,i=>{customerInput.value=i.customer_name||'';document.getElementById('customerNo').value=i.customer_no||'';document.getElementById('customerPhone').value=i.phone||'';document.getElementById('customerAddress').value=i.address||'';document.getElementById('customerCity').value=i.city||'';document.getElementById('customerState').value=i.state||'';document.getElementById('customerZip').value=i.zipcode||'';if(i.primary_contact)document.getElementById('primaryContact').value=i.primary_contact;if(i.email)document.getElementById('customerEmail').value=i.email;if(i.contact_title)document.getElementById('contactTitle').value=i.contact_title;if(i.department)document.getElementById('department').value=i.department;if(i.lowe_rep)document.getElementById('repLookup').value=i.lowe_rep;});}catch(e){customerBox.innerHTML='<div class="lookup-item">'+e.message+'</div>';customerBox.style.display='block';}}));
const repInput=document.getElementById('repLookup'),repBox=document.getElementById('repResults');
if(repInput)repInput.addEventListener('input',debounce(async()=>{const q=repInput.value.trim();if(q.length<2){repBox.style.display='none';return;}try{const items=await apiSearch('opportunity-api/rep-search.php',q);showResults(repBox,items,i=>`<strong>${i.name||''}</strong><small>${[i.email,i.phone].filter(Boolean).join(' · ')}</small>`,i=>{repInput.value=i.name||'';});}catch(e){repBox.innerHTML='<div class="lookup-item">'+e.message+'</div>';repBox.style.display='block';}}));
function bindProductLookup(input){if(!input||input.dataset.bound)return;input.dataset.bound='1';const box=input.parentElement.querySelector('.product-results');input.addEventListener('input',debounce(async()=>{const q=input.value.trim();if(q.length<2){box.style.display='none';return;}try{const items=await apiSearch('opportunity-api/product-search.php',q);showResults(box,items,i=>`<strong>${i.product_name||''}</strong><small>${[i.product_no ? 'Product # ' + i.product_no : '',i.packaging,i.supplier].filter(Boolean).join(' · ')}</small>`,i=>{const row=input.closest('.product-edit');input.value=i.product_name||'';row.querySelector('[name$="[product_no]"]').value=i.product_no||'';row.querySelector('[name$="[cas_no]"]').value=i.cas_no||'';row.querySelector('[name$="[supplier]"]').value=i.supplier||'';row.querySelector('[name$="[packaging]"]').value=i.packaging||'';row.querySelector('[name$="[volume_unit]"]').value=i.volume_unit||'';});}catch(e){box.innerHTML='<div class="lookup-item">'+e.message+'</div>';box.style.display='block';}}));}
function addProductRow(){const wrap=document.getElementById('productsEdit');if(!wrap)return;const r=document.createElement('div');r.className='product-edit';r.innerHTML=`<div class="lookup-wrap"><input class="product-lookup" name="products[${productIndex}][product_name]" placeholder="Product" autocomplete="off"><div class="lookup-results product-results"></div></div><input name="products[${productIndex}][product_no]" placeholder="Product #"><input name="products[${productIndex}][cas_no]" placeholder="CAS"><input name="products[${productIndex}][supplier]" placeholder="Supplier"><input name="products[${productIndex}][packaging]" placeholder="Packaging"><input name="products[${productIndex}][annual_volume]" placeholder="Volume"><input name="products[${productIndex}][volume_unit]" placeholder="LB"><input name="products[${productIndex}][est_sell_price]" placeholder="$ / unit"><input name="products[${productIndex}][est_cost]" placeholder="Cost / unit"><button type="button" class="remove" onclick="this.parentElement.remove()">×</button>`;wrap.appendChild(r);bindProductLookup(r.querySelector('.product-lookup'));productIndex++;}
document.querySelectorAll('.product-lookup').forEach(bindProductLookup);
document.addEventListener('click',e=>{if(!e.target.closest('.lookup-wrap'))document.querySelectorAll('.lookup-results').forEach(b=>b.style.display='none')});
</script>
</body>
</html>
