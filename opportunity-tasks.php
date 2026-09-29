<?php
require_once __DIR__ . '/opportunity-config/app.php';
require_once __DIR__ . '/includes/workflow-nav.php';
opp_require_device();
if (!opp_device()) { header('Location: opportunities.php'); exit; }
$pdo = opp_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!opp_check_csrf($_POST['csrf'] ?? '')) die('Invalid request token.');
    $taskId = (int)($_POST['task_id'] ?? 0);
    if ($taskId > 0 && isset($_POST['toggle_task'])) {
        $stmt=$pdo->prepare('SELECT completed FROM opportunity_tasks WHERE id=?');
        $stmt->execute([$taskId]);
        $cur=$stmt->fetchColumn();
        if ($cur !== false) {
            $new=((int)$cur===1)?0:1;
            $pdo->prepare('UPDATE opportunity_tasks SET completed=?,completed_at=? WHERE id=?')->execute([$new,$new?date('Y-m-d H:i:s'):null,$taskId]);
        }
    }
    if ($taskId > 0 && isset($_POST['delete_task'])) {
        $pdo->prepare('DELETE FROM opportunity_tasks WHERE id=?')->execute([$taskId]);
    }
    header('Location: opportunity-tasks.php'); exit;
}

$status = $_GET['status'] ?? 'open';
$rep = trim($_GET['rep'] ?? '');
$where=[];$params=[];
if ($status==='open') $where[]='t.completed=0';
elseif ($status==='done') $where[]='t.completed=1';
elseif ($status==='overdue') $where[]='t.completed=0 AND t.due_date<CURDATE()';
elseif ($status==='today') $where[]='t.completed=0 AND t.due_date=CURDATE()';
if ($rep!==''){ $where[]='t.assigned_to=?'; $params[]=$rep; }
$sql="SELECT t.*,o.opportunity_no,o.company_name,o.stage FROM opportunity_tasks t JOIN opportunities o ON o.id=t.opportunity_id".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY t.completed ASC, t.due_date IS NULL, t.due_date ASC, t.id DESC";
$stmt=$pdo->prepare($sql);$stmt->execute($params);$tasks=$stmt->fetchAll();
$reps=$pdo->query("SELECT DISTINCT assigned_to FROM opportunity_tasks WHERE assigned_to IS NOT NULL AND assigned_to<>'' ORDER BY assigned_to")->fetchAll(PDO::FETCH_COLUMN);
$counts=$pdo->query("SELECT SUM(completed=0) open_tasks, SUM(completed=0 AND due_date<CURDATE()) overdue_tasks, SUM(completed=0 AND due_date=CURDATE()) today_tasks, SUM(completed=1) done_tasks FROM opportunity_tasks")->fetch();
$device=opp_device();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lowe Opportunity Tasks</title><style>
:root{--navy:#071f45;--blue:#163f78;--red:#d20f18;--line:#d6dbe6;--bg:#eef2f7}*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:var(--bg);color:#07152e}.page{max-width:1200px;margin:auto;padding:18px}.top{background:var(--blue);color:#fff;border-top:4px solid var(--red);padding:20px 24px;border-radius:14px;display:flex;justify-content:space-between;align-items:center;gap:12px}.top h1{margin:0}.btn{display:inline-block;padding:9px 13px;border:0;border-radius:8px;text-decoration:none;font-weight:700;cursor:pointer}.white{background:#fff;color:var(--navy)}.blue{background:#1f5b98;color:#fff}.red{background:#b73535;color:#fff}.green{background:#27864b;color:#fff}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0}.metric{background:#fff;border:1px solid var(--line);border-radius:11px;padding:14px}.metric small{color:#66758b;text-transform:uppercase}.metric strong{display:block;font-size:24px;color:var(--navy);margin-top:4px}.filters{background:#fff;border:1px solid var(--line);border-radius:11px;padding:12px;display:grid;grid-template-columns:1fr 1fr auto;gap:10px;margin-bottom:12px}.filters select{padding:10px;border:1px solid #cbd2df;border-radius:8px}.card{background:#fff;border:1px solid var(--line);border-radius:11px;overflow:hidden}.task{display:grid;grid-template-columns:1.7fr .8fr .8fr .8fr auto;gap:12px;align-items:center;padding:13px;border-bottom:1px solid #e7ebf1}.task:last-child{border:0}.task.done{opacity:.55}.task.done .name{text-decoration:line-through}.muted{font-size:12px;color:#67758b}.actions{display:flex;gap:6px;flex-wrap:wrap}form.inline{display:inline}.overdue{color:#b00020;font-weight:700}@media(max-width:760px){.page{padding:10px}.top{display:block;text-align:center}.top .links{margin-top:10px}.cards{grid-template-columns:1fr 1fr}.filters{grid-template-columns:1fr}.task{grid-template-columns:1fr}.actions{justify-content:flex-start}}
</style></head><body><main class="page"><div class="top"><div><h1>Opportunity Task Center</h1><div>Follow-ups, deadlines and sales actions.</div></div><div class="links"><?=workflow_back_link('btn white')?> <a class="btn white" href="opportunities.php">Pipeline</a> <a class="btn white" href="opportunity-report.php">Reports</a></div></div>
<div class="cards"><div class="metric"><small>Open Tasks</small><strong><?=number_format((int)$counts['open_tasks'])?></strong></div><div class="metric"><small>Due Today</small><strong><?=number_format((int)$counts['today_tasks'])?></strong></div><div class="metric"><small>Overdue</small><strong><?=number_format((int)$counts['overdue_tasks'])?></strong></div><div class="metric"><small>Completed</small><strong><?=number_format((int)$counts['done_tasks'])?></strong></div></div>
<form class="filters"><select name="status"><option value="open" <?=$status==='open'?'selected':''?>>Open tasks</option><option value="today" <?=$status==='today'?'selected':''?>>Due today</option><option value="overdue" <?=$status==='overdue'?'selected':''?>>Overdue</option><option value="done" <?=$status==='done'?'selected':''?>>Completed</option><option value="all" <?=$status==='all'?'selected':''?>>All tasks</option></select><select name="rep"><option value="">All assigned reps</option><?php foreach($reps as $r): ?><option value="<?=opp_h($r)?>" <?=$rep===$r?'selected':''?>><?=opp_h($r)?></option><?php endforeach; ?></select><button class="btn blue">Filter</button></form>
<div class="card"><?php if(!$tasks): ?><div style="padding:18px">No tasks match this filter.</div><?php endif; ?><?php foreach($tasks as $t): $isOverdue=((int)$t['completed']===0 && $t['due_date'] && $t['due_date']<date('Y-m-d')); ?><div class="task <?=((int)$t['completed']===1)?'done':''?>"><div><div class="name"><strong><?=opp_h($t['task_title'])?></strong></div><div class="muted"><?=opp_h($t['company_name'])?> · <?=opp_h($t['opportunity_no'])?> · <?=opp_h($t['stage'])?></div></div><div><strong>Due</strong><br><span class="<?=$isOverdue?'overdue':''?>"><?=opp_h($t['due_date'] ?: 'No date')?></span></div><div><strong>Assigned</strong><br><?=opp_h($t['assigned_to'] ?: 'Unassigned')?></div><div><a class="btn blue" href="opportunity.php?id=<?=(int)$t['opportunity_id']?>#tasks">Open</a></div><div class="actions"><form class="inline" method="post"><input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>"><input type="hidden" name="task_id" value="<?=(int)$t['id']?>"><input type="hidden" name="toggle_task" value="1"><button class="btn green"><?=((int)$t['completed']===1)?'Reopen':'Complete'?></button></form><form class="inline" method="post" onsubmit="return confirm('Delete this task?');"><input type="hidden" name="csrf" value="<?=opp_h(opp_csrf_token())?>"><input type="hidden" name="task_id" value="<?=(int)$t['id']?>"><input type="hidden" name="delete_task" value="1"><button class="btn red">Delete</button></form></div></div><?php endforeach; ?></div>
</main></body></html>
