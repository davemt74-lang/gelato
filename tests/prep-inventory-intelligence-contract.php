<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/prep-intelligence-core.php';
require __DIR__ . '/../includes/agent-workspace-core.php';
require __DIR__ . '/../includes/prep-agent-core.php';
require __DIR__ . '/../includes/prep-agent-proactive.php';

$pdo=app_pdo();
function pit_assert(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }

pit_assert(operations_core_ready($pdo),'Operations Core must be installed.');
pit_assert(prep_intelligence_ready($pdo),'Prep Intelligence must be installed.');
$emptyRequest=pac_create_request('create a prep list for 2026-11-05');
pit_assert($emptyRequest!==null&&$emptyRequest['date']==='2026-11-05'&&$emptyRequest['items']===[],'explicit dated empty prep-list intent');
$withItems=pac_create_request("create tomorrow's prep list with six pans of dough and four containers of sauce");
pit_assert($withItems!==null&&count($withItems['items'])===2
    &&$withItems['items'][0]['quantity']===6.0
    &&$withItems['items'][1]['quantity']===4.0,'conversational multiple-item prep creation');
pit_assert($withItems['date']===prep_parse_date_phrase('tomorrow'),'spoken tomorrow follows restaurant local date');
pit_assert(pac_create_request('add 20 pounds to inventory')===null,'inventory receipt is not a prep action');
pit_assert(pac_quantity(0)==='0','zero prep quantities must be spoken accurately');
pit_assert(pac_followup_intent('Mark the dough complete')['type']==='complete','spoken completion intent');
pit_assert(pac_followup_intent("Who's doing sauce?")['type']==='assignees','spoken prep assignment intent');
pit_assert(pac_followup_intent('add two more pans of dough')['quantity']===2.0,'spoken incremental prep quantity');
pit_assert(pac_followup_intent('Assign dough to Prep CI Staff')['type']==='assign','spoken task assignment intent');
pit_assert(pac_followup_intent('Mark dough in progress')['status']==='in_progress','spoken task progress intent');
pit_assert(pac_followup_intent('Block prep dough')['status']==='blocked','spoken blocked-task intent');
pit_assert(pac_followup_intent("I'm blocked on dough because mixer is overheating")['reason']==='mixer is overheating',
    'spoken blocker explanation captures reason separately from task identity');
pit_assert(pac_followup_intent('Resolve blocker on dough')['type']==='resolve',
    'spoken manager blocker resolution intent');
pit_assert(pac_followup_intent('Reassign dough to Prep CI Staff')['type']==='assign',
    'spoken reassignment reuses canonical employee matching');
try{pac_task_match([['public_id'=>'one','title'=>'Pizza dough'],['public_id'=>'two','title'=>'Bread dough']],'dough');
    throw new RuntimeException('Ambiguous prep task must not be guessed');}
catch(InvalidArgumentException $expected){}

$orgId=(int)$pdo->query('SELECT id FROM organizations ORDER BY id LIMIT 1')->fetchColumn();
if(!$orgId){
    $pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Prep Intelligence CI','active','America/Phoenix')");
    $orgId=(int)$pdo->lastInsertId();
}
$userId=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if(!$userId){
    $stmt=$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')");
    $stmt->execute(['prep-ci@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Prep','CI','Prep CI']);
    $userId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$orgId,$userId]);
}
$actorName=(string)$pdo->query('SELECT display_name FROM users WHERE id='.(int)$userId)->fetchColumn();

$prepActor=['id'=>$userId,'organization_id'=>$orgId,'permissions'=>['prep.intelligence.manage','prep.intelligence.agent','prep.intelligence.view','tasks.manage']];
$proposal=['organizationId'=>$orgId,'userId'=>$userId,'type'=>'create_plan',
    'payload'=>['date'=>'2026-11-05','service'=>'all','expected'=>pac_snapshot(null)]];
$created=pac_execute($pdo,$orgId,$userId,$prepActor,$proposal,false);
pit_assert(($created['data']['proof']['url']??'')==='prep-intelligence.php','new prep list produces a verifiable record location');
pit_assert((bool)prep_plan_for_period($pdo,$orgId,'2026-11-05','all'),'new prep list is dated and persisted');
try{pac_execute($pdo,$orgId,$userId,$prepActor,$proposal,false);throw new RuntimeException('Repeated plan creation should fail');}
catch(InvalidArgumentException $expected){}
$itemProposal=['organizationId'=>$orgId,'userId'=>$userId,'type'=>'add_items',
    'payload'=>['date'=>'2026-11-06','service'=>'all','expected'=>pac_snapshot(null),
        'items'=>$withItems['items'],'transcript'=>'Create prep list with dough and sauce','source'=>'agent']];
$added=pac_execute($pdo,$orgId,$userId,$prepActor,$itemProposal,false);
pit_assert(count($added['data']['tasks'])===2,'two prep tasks were created atomically');
pit_assert(($added['data']['proof']['planId']??'')!=='','prep task receipt links to dated canonical plan');
$linked=$pdo->prepare('SELECT COUNT(*) FROM prep_plan_tasks WHERE organization_id=? AND plan_id=?');
$linked->execute([$orgId,(int)$added['data']['plan']['id']]);
pit_assert((int)$linked->fetchColumn()===2,'canonical prep plan links both tasks');
try{pac_execute($pdo,$orgId,$userId,$prepActor,$itemProposal,false);throw new RuntimeException('Repeated prep task proposal should fail');}
catch(InvalidArgumentException $expected){}
$linked->execute([$orgId,(int)$added['data']['plan']['id']]);
pit_assert((int)$linked->fetchColumn()===2,'replayed prep proposal does not duplicate tasks');
$existingPlan=prep_plan_for_period($pdo,$orgId,'2026-11-05','all');
$existingProposal=['organizationId'=>$orgId,'userId'=>$userId,'type'=>'add_items',
    'payload'=>['date'=>'2026-11-05','service'=>'all','expected'=>pac_snapshot($existingPlan),
        'items'=>[['title'=>'Dough for Friday','quantity'=>2,'unit'=>'pan']]]];
pac_execute($pdo,$orgId,$userId,$prepActor,$existingProposal,false);
try{pac_execute($pdo,$orgId,$userId,$prepActor,$existingProposal,false);
    throw new RuntimeException('An existing-plan proposal should reject replay');}
catch(InvalidArgumentException $expected){}
$existingCount=$pdo->prepare("SELECT COUNT(*) FROM prep_plan_tasks WHERE organization_id=? AND plan_id=?");
$existingCount->execute([$orgId,(int)$existingPlan['id']]);
pit_assert((int)$existingCount->fetchColumn()===1,'revision-gated replay does not duplicate existing-plan tasks');
try{pac_execute($pdo,$orgId,$userId,['id'=>$userId,'organization_id'=>$orgId,'permissions'=>[]],$proposal,false);
    throw new RuntimeException('Prep manager permission must be enforced');}
catch(PrepAgentPermissionException $expected){}

// B8D17G2B: create -> read -> incremental update -> read -> completion -> read.
app_boot_session();
$read=prep_agent_handle($pdo,$prepActor,['message'=>'Show the prep list on 2026-11-06']);
pit_assert($read['skill']==='prep.list'&&count($read['data']['tasks'])===2,'dated prep list reads both persisted tasks');
pit_assert(str_contains($read['answer'],'6 pans of dough')&&str_contains($read['answer'],'4 containers of sauce'),
    'spoken read-back includes item names and quantities; actual: '.$read['answer']);
pit_assert((pac_context_get($orgId,$userId)['date']??'')==='2026-11-06','prep context is scoped to the current user and plan');
pit_assert(pac_context_get($orgId+1000,$userId)===null,'prep context is not shared across organizations');

$increase=prep_agent_handle($pdo,$prepActor,['message'=>'Add two more pans of dough']);
pit_assert(($increase['data']['requiresConfirmation']??false)===true,'incremental prep write requires confirmation');
$pendingIncrease=gac_pending_get('prep',$orgId,$userId);
pit_assert(($pendingIncrease['type']??'')==='task_quantity','pending quantity action targets the canonical task');
// Reloading the page does not consume or replay the one-use server-side proposal.
unset($_SESSION['prep_agent_context'][pac_context_key($orgId,$userId)]);
$confirmedIncrease=prep_agent_handle($pdo,$prepActor,['message'=>'Confirm']);
pit_assert(($confirmedIncrease['data']['before']??null)===6.0
    &&($confirmedIncrease['data']['after']??null)===8.0,'confirmed quantity update is additive and exact');
pit_assert(($confirmedIncrease['data']['proof']['taskId']??'')!=='','quantity action includes a verifiable task receipt');
try{pac_execute($pdo,$orgId,$userId,$prepActor,$pendingIncrease,false);
    throw new RuntimeException('Replaying confirmed quantity proposal should fail');}
catch(InvalidArgumentException $expected){}
try{prep_agent_handle($pdo,$prepActor,['message'=>'Confirm']);
    throw new RuntimeException('A second confirmation must not replay the completed write');}
catch(InvalidArgumentException $expected){}
$afterIncrease=prep_agent_handle($pdo,$prepActor,['message'=>'Show the prep list on 2026-11-06']);
pit_assert(str_contains($afterIncrease['answer'],'8 pans of dough'),'read-back reflects the new persisted quantity');

$assignee=prep_agent_handle($pdo,$prepActor,['message'=>"Who's doing sauce?"]);
pit_assert($assignee['skill']==='prep.task_assignees'&&str_contains($assignee['answer'],'unassigned'),
    'conversational assignment query reads the current canonical task');
$employeeActor=['id'=>$userId,'organization_id'=>$orgId,'permissions'=>['prep.intelligence.view','prep.intelligence.agent','tasks.self']];
try{prep_agent_handle($pdo,$employeeActor,['message'=>'Mark the sauce complete']);
    throw new RuntimeException('Unassigned employees may not complete arbitrary prep tasks');}
catch(PrepAgentPermissionException $expected){}
$sauce=operations_task_by_public_id($pdo,$orgId,(string)$added['data']['tasks'][1]['public_id']);
$pdo->prepare("INSERT IGNORE INTO restaurant_task_assignments (organization_id,task_id,user_id,assignment_role,assigned_by) VALUES (?,?,?,'assignee',?)")
    ->execute([$orgId,(int)$sauce['id'],$userId,$userId]);
$assigned=prep_agent_handle($pdo,$employeeActor,['message'=>"Who's doing sauce?"]);
pit_assert(str_contains($assigned['answer'],$actorName),'assignment read-back identifies the actual assigned employee');
$statusProposal=prep_agent_handle($pdo,$employeeActor,['message'=>'Mark the sauce complete']);
pit_assert(($statusProposal['data']['requiresConfirmation']??false)===true,'assigned employee completion still needs confirmation');
$pdo->prepare('UPDATE restaurant_tasks SET requires_photo=1 WHERE id=?')->execute([(int)$sauce['id']]);
try{prep_agent_handle($pdo,$employeeActor,['message'=>'Confirm']);
    throw new RuntimeException('Photo-required prep task cannot complete without proof');}
catch(InvalidArgumentException $expected){}
pit_assert((string)operations_task_by_public_id($pdo,$orgId,(string)$sauce['public_id'])['status']==='queued',
    'denied completion preserves task status');
$pdo->prepare('UPDATE restaurant_tasks SET requires_photo=0 WHERE id=?')->execute([(int)$sauce['id']]);
$statusProposal=prep_agent_handle($pdo,$employeeActor,['message'=>'Mark the sauce complete']);
$statusDone=prep_agent_handle($pdo,$employeeActor,['message'=>'Confirm']);
pit_assert(($statusDone['data']['after']??'')==='completed','assigned employee may complete their prep task');
$afterStatus=prep_agent_handle($pdo,$prepActor,['message'=>'Show the prep list on 2026-11-06']);
pit_assert(str_contains($afterStatus['answer'],'sauce (completed, assigned to '.$actorName.')'),
    'post-write readback includes employee and completed status');

// B8D17G2C: manager-only assignment, assigned-employee progress, and proactive signals.
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute(['prep-ci-staff@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Prep','Staff','Prep CI Staff']);
$staffId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")
    ->execute([$orgId,$staffId]);
try{pac_staff_match($pdo,$orgId,'Prep');throw new RuntimeException('Ambiguous first names must not be guessed');}
catch(InvalidArgumentException $expected){}
try{pac_staff_match($pdo,$orgId,'Someone Outside');throw new RuntimeException('Unknown staff identity must be rejected');}
catch(InvalidArgumentException $expected){}
$doughId=(string)$added['data']['tasks'][0]['public_id'];
$assignProposal=prep_agent_handle($pdo,$prepActor,['message'=>'Assign dough to Prep CI Staff']);
pit_assert(($assignProposal['data']['requiresConfirmation']??false)===true,'assignment requires confirmation');
$staffActor=['id'=>$staffId,'organization_id'=>$orgId,'permissions'=>['prep.intelligence.view','prep.intelligence.agent','tasks.self']];
prep_agent_handle($pdo,$staffActor,['message'=>'Show the prep list on 2026-11-06']);
try{prep_agent_handle($pdo,$staffActor,['message'=>'Assign dough to Prep CI Staff']);
    throw new RuntimeException('Employee may not reassign prep tasks');}
catch(PrepAgentPermissionException $expected){}
$assignedDough=prep_agent_handle($pdo,$prepActor,['message'=>'Confirm']);
pit_assert(($assignedDough['data']['action']??'')==='task_assign','manager confirmation records assignment action');
$doughDetail=operations_task_detail($pdo,$orgId,$doughId);
pit_assert(count($doughDetail['assignments'])===1&&(int)$doughDetail['assignments'][0]['user_id']===$staffId,
    'canonical task assignment targets the named employee exactly');
try{prep_agent_handle($pdo,$prepActor,['message'=>'Confirm']);throw new RuntimeException('Replayed assignment must fail');}
catch(InvalidArgumentException $expected){}
$progressProposal=prep_agent_handle($pdo,$staffActor,['message'=>'Mark dough in progress']);
pit_assert(($progressProposal['data']['requiresConfirmation']??false)===true,'assigned employee progress needs confirmation');
$progress=prep_agent_handle($pdo,$staffActor,['message'=>'Confirm']);
pit_assert(($progress['data']['after']??'')==='in_progress','assigned employee can start prep progress');
$blockedProposal=prep_agent_handle($pdo,$staffActor,['message'=>'Block prep dough']);
pit_assert(($blockedProposal['data']['requiresConfirmation']??false)===true,'blocking a task needs confirmation');
$blocked=prep_agent_handle($pdo,$staffActor,['message'=>'Confirm']);
pit_assert(($blocked['data']['after']??'')==='blocked','assigned employee can report a blocker');
 
// B8D17G2D: actor-scoped prep inbox, tenant isolation and restart-safe readbacks.
$mine=prep_agent_handle($pdo,$staffActor,['message'=>'What is my prep on 2026-11-06?']);
pit_assert(($mine['skill']??'')==='prep.my_tasks','spoken my-prep intent reaches dedicated inbox');
pit_assert(count($mine['data']['tasks']??[])===1
    &&(string)$mine['data']['tasks'][0]['public_id']===$doughId,
    'employee inbox contains only their actual dated task assignment');
pit_assert(str_contains($mine['answer'],'blocked')&&!str_contains($mine['answer'],'sauce'),
    'inbox speaks live progress without disclosing another employee\'s prep');
$managerMine=prep_agent_handle($pdo,$prepActor,['message'=>'Show my prep tasks on 2026-11-06']);
pit_assert(count($managerMine['data']['tasks']??[])===1
    &&(string)$managerMine['data']['tasks'][0]['public_id']===(string)$sauce['public_id'],
    'manager\'s personal prep inbox does not turn into an unrestricted task list');
$tenantMine=pac_my_prep($pdo,$orgId+100000,$staffId,'My prep on 2026-11-06');
pit_assert(($tenantMine['data']['tasks']??[])===[],
    'prep inbox does not read another organization\'s task assignments');
unset($_SESSION['prep_agent_context']);
$reloadMine=prep_agent_handle($pdo,$staffActor,['message'=>'My prep on 2026-11-06']);
pit_assert(($reloadMine['sources']??[])===[$doughId],
    'prep inbox reconstructs the same canonical assignment after session reload');

$unassignedTask=prep_attach_manual_task($pdo,$orgId,prep_plan($pdo,$orgId,(string)$added['data']['plan']['public_id']),
    ['title'=>'Garlic knots','quantity'=>2,'unit'=>'tray'],$userId,'contract');
$managerSignals=pac_proactive_signals($pdo,$orgId,$userId,$prepActor,'2026-11-06');
$managerKinds=array_column($managerSignals,'type');
pit_assert(in_array('prep.task_blocked',$managerKinds,true)&&in_array('prep.task_unassigned',$managerKinds,true),
    'manager proactively sees blocked and unassigned prep tasks');
$staffSignals=pac_proactive_signals($pdo,$orgId,$staffId,$staffActor,'2026-11-06');
pit_assert(count($staffSignals)===1&&$staffSignals[0]['type']==='prep.task_blocked',
    'employee sees their own blocked prep task without managerial unassigned alerts');
pit_assert(pac_proactive_signals($pdo,$orgId,$userId,
    ['id'=>$userId,'permissions'=>['prep.intelligence.view','tasks.self']],'2026-11-06')===[],
    'other employees do not receive somebody else’s blocked task');
pit_assert(array_column(pac_proactive_signals($pdo,$orgId,$staffId,$staffActor,'2026-11-06'),'key')
    ===array_column($staffSignals,'key'),'proactive dedupe keys stay stable across polling');

// B8D17G2E: employee blocker reason, manager visibility, deduped escalation, resolution and handoff.
$reasonProposal=prep_agent_handle($pdo,$staffActor,
    ['message'=>"I'm blocked on dough because the mixer is overheating"]);
pit_assert(($reasonProposal['data']['requiresConfirmation']??false)===true,
    'reporting a new reason for an already blocked task requires confirmation');
$reasonRecorded=prep_agent_handle($pdo,$staffActor,['message'=>'Confirm']);
pit_assert(($reasonRecorded['data']['action']??'')==='task_blocker',
    'confirmed blocker reason is a distinct canonical task action');
$eventQuery=$pdo->prepare("SELECT metadata_json FROM restaurant_task_events
    WHERE organization_id=? AND task_id=? AND event_type='prep_blocker_reported' ORDER BY id DESC LIMIT 1");
$eventQuery->execute([$orgId,(int)operations_task_by_public_id($pdo,$orgId,$doughId)['id']]);
$reasonEvent=json_decode((string)$eventQuery->fetchColumn(),true);
pit_assert(($reasonEvent['reason']??'')==='the mixer is overheating',
    'append-only task event retains the employee reported blocker reason');
$otherBlockers=prep_agent_handle($pdo,$employeeActor,['message'=>'Blocked prep on 2026-11-06']);
pit_assert(($otherBlockers['data']['tasks']??[])===[],
    'employee cannot read another employee’s blocker reason');
$duplicateProposal=prep_agent_handle($pdo,$staffActor,
    ['message'=>"I'm blocked on dough because the mixer is overheating"]);
pit_assert(($duplicateProposal['data']['requiresConfirmation']??false)===true,
    'repeated blocker report is still presented for confirmation');
try{prep_agent_handle($pdo,$staffActor,['message'=>'Confirm']);
    throw new RuntimeException('Identical blocker reason must not create duplicate events');}
catch(InvalidArgumentException $expected){}
$eventQuery->execute([$orgId,(int)operations_task_by_public_id($pdo,$orgId,$doughId)['id']]);
pit_assert(json_decode((string)$eventQuery->fetchColumn(),true)['reason']==='the mixer is overheating',
    'duplicate confirmation preserves the original reason and audit lineage');
$managerBlockers=prep_agent_handle($pdo,$prepActor,['message'=>"What's blocked on prep on 2026-11-06?"]);
pit_assert(($managerBlockers['skill']??'')==='prep.blockers'
    &&count($managerBlockers['data']['tasks']??[])===1
    &&str_contains($managerBlockers['answer'],'the mixer is overheating')
    &&str_contains($managerBlockers['answer'],'Prep CI Staff'),
    'manager sees the live blocker reason and assigned employee');
$myBlockers=prep_agent_handle($pdo,$staffActor,['message'=>'Blocked prep on 2026-11-06']);
pit_assert(count($myBlockers['data']['tasks']??[])===1,
    'assigned employee sees their own blocked task');
try{prep_agent_handle($pdo,$staffActor,['message'=>'Resolve blocker on dough']);
    throw new RuntimeException('Employee cannot authorize manager-only blocker resolution');}
catch(PrepAgentPermissionException $expected){}
$refreshedSignals=pac_proactive_signals($pdo,$orgId,$userId,$prepActor,'2026-11-06');
$refreshedBlocked=array_values(array_filter($refreshedSignals,
    static fn(array $signal):bool=>$signal['type']==='prep.task_blocked'));
pit_assert(count($refreshedBlocked)===1
    &&str_contains($refreshedBlocked[0]['message'],'the mixer is overheating')
    &&$refreshedBlocked[0]['key']!==$managerSignals[0]['key'],
    'new blocker reason creates a fresh actionable manager alert');
pit_assert(array_column(pac_proactive_signals($pdo,$orgId,$userId,$prepActor,'2026-11-06'),'key')
    ===array_column($refreshedSignals,'key'),'same blocker reason never creates duplicate alert keys');
$resolveProposal=prep_agent_handle($pdo,$prepActor,['message'=>'Resolve blocker on dough']);
pit_assert(($resolveProposal['data']['requiresConfirmation']??false)===true,
    'manager resolution requests explicit confirmation; got '.json_encode($resolveProposal,JSON_UNESCAPED_SLASHES));
$resolved=prep_agent_handle($pdo,$prepActor,['message'=>'Confirm']);
pit_assert(($resolved['data']['after']??'')==='in_progress',
    'manager-confirmed blocker resolution returns task to in progress');
pit_assert(!in_array('prep.task_blocked',
    array_column(pac_proactive_signals($pdo,$orgId,$userId,$prepActor,'2026-11-06'),'type'),true),
    'resolved blocker disappears from proactive exception feed');
$reblockProposal=prep_agent_handle($pdo,$prepActor,['message'=>'Block prep dough']);
pit_assert(($reblockProposal['data']['requiresConfirmation']??false)===true,
    'new status epoch still permits a bare block without inventing a reason');
$reblocked=prep_agent_handle($pdo,$prepActor,['message'=>'Confirm']);
pit_assert(($reblocked['data']['after']??'')==='blocked','reblock changes task status');
$reblockSignals=pac_proactive_signals($pdo,$orgId,$userId,$prepActor,'2026-11-06');
$bareBlock=array_values(array_filter($reblockSignals,
    static fn(array $signal):bool=>$signal['type']==='prep.task_blocked'));
pit_assert(count($bareBlock)===1
    &&!str_contains($bareBlock[0]['message'],'the mixer is overheating'),
    'new status epoch never reuses a stale blocker reason');
$reResolve=prep_agent_handle($pdo,$prepActor,['message'=>'Resolve blocker on dough']);
pit_assert(($reResolve['data']['requiresConfirmation']??false)===true,'manager can resolve a later blocker');
prep_agent_handle($pdo,$prepActor,['message'=>'Confirm']);
$reassignProposal=prep_agent_handle($pdo,$prepActor,
    ['message'=>'Reassign dough to '.$actorName]);
pit_assert(($reassignProposal['data']['requiresConfirmation']??false)===true,
    'manager handoff to a named employee requires confirmation');
$reassigned=prep_agent_handle($pdo,$prepActor,['message'=>'Confirm']);
pit_assert(($reassigned['data']['action']??'')==='task_assign',
    'confirmed blocker handoff changes the canonical task assignment');
$handoff=operations_task_detail($pdo,$orgId,$doughId);
pit_assert(count($handoff['assignments'])===1
    &&(int)$handoff['assignments'][0]['user_id']===$userId,
    'old assignee is replaced by new handoff owner, not duplicated');
try{prep_agent_handle($pdo,$staffActor,['message'=>'Mark dough in progress']);
    throw new RuntimeException('Former assignee must lose task progress authority after handoff');}
catch(PrepAgentPermissionException $expected){}


operations_ensure_default_categories($pdo,$orgId,$userId);
$prepCategory=operations_category_by_slug($pdo,$orgId,'prep');
pit_assert((bool)$prepCategory,'Prep category is required.');

$target='2026-09-19';
foreach(['2026-08-29'=>10.0,'2026-09-05'=>12.0,'2026-09-12'=>14.0] as $date=>$qty){
    $public='ci-hist-'.str_replace('-','',$date);
    $existing=operations_task_by_public_id($pdo,$orgId,$public);
    if(!$existing){
        $stmt=$pdo->prepare("INSERT INTO restaurant_tasks (organization_id,public_id,category_id,title,quantity,unit,station,priority,status,due_at,created_by,updated_by) VALUES (?,?,?,?,?,'batch','Dough','normal','completed',?,?,?)");
        $stmt->execute([$orgId,$public,(int)$prepCategory['id'],'Pizza dough',$qty,$date.' 10:00:00',$userId,$userId]);
        $taskId=(int)$pdo->lastInsertId();
        operations_task_event($pdo,$orgId,$taskId,'completed','Pizza dough completed.',$userId,['contract'=>true]);
    }
}

$inventory=operations_inventory_upsert($pdo,$orgId,'mozzarella',$userId);
$pdo->prepare("UPDATE inventory_items SET base_unit='lb',on_hand_quantity=2,par_level=10,reorder_point=4,updated_by=? WHERE id=? AND organization_id=?")
    ->execute([$userId,(int)$inventory['id'],$orgId]);

$plan=prep_get_or_create_plan($pdo,$orgId,$target,'all',$userId);
$plan=prep_save_plan($pdo,$orgId,$plan,['demandMultiplier'=>1.10,'projectedCovers'=>150,'notes'=>'Saturday CI forecast'],$userId);
pit_assert(abs((float)$plan['demand_multiplier']-1.10)<0.001,'Demand multiplier was not saved.');

$detail=prep_generate_recommendations($pdo,$orgId,$plan,$userId);
$rec=null;
foreach($detail['recommendations'] as $row){if(prep_normalized_key((string)$row['title'])==='pizza dough'){$rec=$row;break;}}
pit_assert(is_array($rec),'Historical Pizza dough recommendation was not generated.');
pit_assert((int)$rec['sample_count']===3,'Same-weekday sample count should be 3.');
pit_assert(abs((float)$rec['recommended_quantity']-13.2)<0.001,'Expected 13.2 batch recommendation from history and multiplier.');
pit_assert((float)$rec['confidence']>=0.70,'Same-weekday recommendation confidence is unexpectedly low.');

$forecast=null;
foreach($detail['forecasts'] as $row){if((string)$row['inventory_name']==='mozzarella'){$forecast=$row;break;}}
pit_assert(is_array($forecast),'Low-stock inventory item was not included in forecast.');
pit_assert((float)$forecast['restock_quantity']>=8.0,'Restock-to-par forecast should flag at least 8 lb mozzarella.');

$updated=prep_update_recommendation($pdo,$orgId,(string)$rec['public_id'],['quantity'=>15,'unit'=>'batch','station'=>'Dough','status'=>'accepted'],$userId);
pit_assert((float)$updated['recommended_quantity']===15.0,'Human recommendation override was not retained.');
pit_assert((string)$updated['status']==='accepted','Recommendation acceptance was not retained.');

$published=prep_publish_plan($pdo,$orgId,$plan,$userId);
$generated=array_values(array_filter($published['tasks'],static fn(array $row):bool=>(string)($row['source_type']??'')==='prep_plan'));
pit_assert(count($generated)>=1,'Publishing did not create canonical prep tasks.');
$stmt=$pdo->prepare("SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id=? AND source_type='prep_plan'");
$stmt->execute([$orgId]);$before=(int)$stmt->fetchColumn();
$publishedAgain=prep_publish_plan($pdo,$orgId,prep_plan($pdo,$orgId,(string)$plan['public_id'])??$plan,$userId);
$stmt->execute([$orgId]);$after=(int)$stmt->fetchColumn();
pit_assert($before===$after,'Republishing duplicated canonical prep tasks.');
pit_assert(count($publishedAgain['tasks'])>=1,'Published prep plan lost task links.');

$history=prep_item_history_summary($pdo,$orgId,'Pizza dough',6,12);
pit_assert((int)$history['sampleCount']>=3,'Prep history summary did not find Saturday samples.');
pit_assert(abs((float)$history['average']-12.0)<0.001,'Prep history average should be 12 batches.');

$dayHistory=prep_task_history_for_date($pdo,$orgId,'2026-09-12','Pizza dough');
pit_assert(count($dayHistory)===1,'Historical date lookup should return the completed Pizza dough task.');

$routeUser=['role_slug'=>'manager','permissions'=>['prep.intelligence.view','prep.intelligence.agent','tasks.view','tasks.agent','inventory.view','inventory.agent']];
$route=gaw_route($routeUser,'Hey Gelato, what should we prep today?');
pit_assert(($route['route']??null)==='api/prep-intelligence-agent.php','Prep planning should route to the dedicated Prep Intelligence Agent.');
$route=gaw_route($routeUser,'Hey Gelato, show prep history from last Saturday');
pit_assert(($route['route']??null)==='api/prep-intelligence-agent.php','Prep history should route to the dedicated Prep Intelligence Agent.');
$route=gaw_route($routeUser,'What is my prep today?');
pit_assert(($route['route']??null)==='api/prep-intelligence-agent.php',
    'my-prep inbox routes to the scoped Prep Agent instead of generic tasks');
$route=gaw_route($routeUser,"What's blocked on prep today?");
pit_assert(($route['route']??null)==='api/prep-intelligence-agent.php',
    'blocked prep inquiry routes to scoped Prep Intelligence');
$route=gaw_route($routeUser,'Hey Gelato, count inventory');
pit_assert(($route['route']??null)==='api/operations-agent.php','Generic inventory should remain on the Operations Agent.');

$events=$pdo->prepare('SELECT COUNT(*) FROM prep_plan_events WHERE organization_id=? AND plan_id=?');
$events->execute([$orgId,(int)$plan['id']]);
pit_assert((int)$events->fetchColumn()>=4,'Prep plan append-only event history is incomplete.');

$closed=prep_close_plan($pdo,$orgId,prep_plan($pdo,$orgId,(string)$plan['public_id'])??$plan,$userId);
pit_assert((string)$closed['status']==='closed','Prep plan did not close.');
$blocked=false;
try{prep_save_plan($pdo,$orgId,$closed,['notes'=>'must not edit'],$userId);}catch(RuntimeException){$blocked=true;}
pit_assert($blocked,'Closed prep plan must reject edits.');

echo "prep-inventory-intelligence contract passed\n";
