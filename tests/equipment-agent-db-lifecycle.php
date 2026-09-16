<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/agent-node-registry.php';
require __DIR__.'/../includes/agent-workspace-core.php';
require __DIR__.'/../includes/equipment-agent-core.php';
require __DIR__.'/../includes/equipment-agent-brain.php';

function eadb_assert(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}
function eadb_scalar(PDO $pdo,string $sql,array $params=[]): mixed
{
    $q=$pdo->prepare($sql);$q->execute($params);return $q->fetchColumn();
}

$pdo=app_pdo();
$_SESSION=[];

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Equipment Agent CI','active','America/Phoenix')");
$org=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES ('equipment-agent@example.test','x','Equipment','Agent','Equipment Agent CI','active')");
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$user=['id'=>$uid,'organization_id'=>$org,'permissions'=>['*']];

$pdo->prepare("INSERT INTO equipment_assets (organization_id,public_id,name,asset_type,brand,model,asset_tag,replacement_cost,operational_status,condition_status,criticality,location_name,maintenance_required,last_service_on,next_service_on,maintenance_notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$org,'equip-agent-ci','Wood Fired Oven','oven','Stonefire','WF-900','OVEN-CI',18000,'active','good','critical','Pizza Line',1,date('Y-m-d',strtotime('-80 days')),date('Y-m-d',strtotime('-10 days')),'Inspect burner and stone deck.',$uid,$uid]);
$assetId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO equipment_service_contacts (organization_id,public_id,company_name,contact_name,specialty,phone,status,created_by,updated_by) VALUES (?,?,?,?,?,?,'active',?,?)")
    ->execute([$org,'eqcontact-agent-ci','Kitchen Service Co','Test Technician','Ovens','602-555-0100',$uid,$uid]);
$contactId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO equipment_asset_service_contacts (equipment_asset_id,service_contact_id,contact_role,is_primary) VALUES (?,?,'service',1)")->execute([$assetId,$contactId]);
equipment_brain_sync_asset($pdo,$org,$assetId,$uid);
$context=['module'=>'equipment','selectedAssetPublicId'=>'equip-agent-ci'];

// Registry and routing: explicit Equipment intent routes to the node while generic service language stays globally neutral.
$node=gaw_agent_node('equipment');
eadb_assert(($node['route']??'')==='api/equipment-agent.php','Equipment Agent node is not registered.');
$route=gaw_route($user,'What equipment maintenance is overdue?');
eadb_assert(($route['route']??'')==='api/equipment-agent.php','Equipment intent did not route to Equipment Agent.');
$genericRoute=gaw_route($user,'Show me the service history.');
eadb_assert(($genericRoute['domain']??'')!=='equipment_maintenance','Generic service-history language was globally hijacked by the Equipment Agent.');

// Read intelligence is side-effect-free and selected-asset context is resolved server-side.
$summary=equipment_agent_handle($pdo,$user,['message'=>'What equipment needs attention?','pageContext'=>['module'=>'equipment']]);
eadb_assert(($summary['skill']??'')==='equipment.maintenance_attention','Equipment attention read returned the wrong skill.');
eadb_assert((int)($summary['data']['summary']['overdueMaintenance']??0)>=1,'Overdue maintenance was not surfaced.');
$assetRead=equipment_agent_handle($pdo,$user,['message'=>'What is the service history?','pageContext'=>$context]);
eadb_assert(($assetRead['data']['asset']['publicId']??'')==='equip-agent-ci','Selected equipment context was not resolved server-side.');

// Status: Propose -> Confirm -> Execute. No write before Confirm.
$statusProposal=equipment_agent_handle($pdo,$user,['message'=>'mark this equipment out of service','pageContext'=>$context]);
eadb_assert(($statusProposal['data']['requiresConfirmation']??false)===true,'Equipment status write skipped proposal state.');
eadb_assert(eadb_scalar($pdo,"SELECT operational_status FROM equipment_assets WHERE organization_id=? AND public_id=?",[$org,'equip-agent-ci'])==='active','Equipment status changed before confirmation.');
$pendingRoute=gaw_route($user,'Confirm');
eadb_assert(($pendingRoute['route']??'')==='api/equipment-agent.php','Pending Equipment confirmation did not route back to its owning node.');
$statusConfirmed=equipment_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);
eadb_assert(($statusConfirmed['data']['status']??'')==='out_of_service','Equipment status confirmation did not execute.');
eadb_assert(eadb_scalar($pdo,"SELECT operational_status FROM equipment_assets WHERE organization_id=? AND public_id=?",[$org,'equip-agent-ci'])==='out_of_service','Confirmed Equipment status was not persisted.');

// Schedule + Cancel: cancelled proposal must not mutate next_service_on.
$originalDue=(string)eadb_scalar($pdo,"SELECT next_service_on FROM equipment_assets WHERE organization_id=? AND public_id=?",[$org,'equip-agent-ci']);
$newDue=date('Y-m-d',strtotime('+14 days'));
$scheduleProposal=equipment_agent_handle($pdo,$user,['message'=>"schedule maintenance {$newDue}",'pageContext'=>$context]);
eadb_assert(($scheduleProposal['data']['requiresConfirmation']??false)===true,'Equipment schedule write skipped proposal state.');
$cancelled=equipment_agent_handle($pdo,$user,['message'=>'Cancel','pageContext'=>$context]);
eadb_assert(($cancelled['skill']??'')==='equipment.action_cancelled','Equipment schedule proposal did not cancel.');
eadb_assert((string)eadb_scalar($pdo,"SELECT next_service_on FROM equipment_assets WHERE organization_id=? AND public_id=?",[$org,'equip-agent-ci'])===$originalDue,'Cancelled Equipment schedule changed the database.');

// Service history: Confirm creates the service event and updates maintenance dates.
$serviceDue=date('Y-m-d',strtotime('+90 days'));
$beforeEvents=(int)eadb_scalar($pdo,"SELECT COUNT(*) FROM equipment_service_events WHERE organization_id=? AND equipment_asset_id=?",[$org,$assetId]);
$serviceProposal=equipment_agent_handle($pdo,$user,['message'=>"record repair: replaced thermostat next due {$serviceDue}",'pageContext'=>$context]);
eadb_assert(($serviceProposal['data']['requiresConfirmation']??false)===true,'Equipment service write skipped proposal state.');
eadb_assert((int)eadb_scalar($pdo,"SELECT COUNT(*) FROM equipment_service_events WHERE organization_id=? AND equipment_asset_id=?",[$org,$assetId])===$beforeEvents,'Equipment service event was written before confirmation.');
$serviceConfirmed=equipment_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);
eadb_assert(($serviceConfirmed['skill']??'')==='equipment.action_confirmed','Equipment service confirmation did not execute.');
eadb_assert((int)eadb_scalar($pdo,"SELECT COUNT(*) FROM equipment_service_events WHERE organization_id=? AND equipment_asset_id=?",[$org,$assetId])===$beforeEvents+1,'Confirmed Equipment service event was not persisted.');
eadb_assert((string)eadb_scalar($pdo,"SELECT next_service_on FROM equipment_assets WHERE organization_id=? AND public_id=?",[$org,'equip-agent-ci'])===$serviceDue,'Confirmed Equipment service next-due date was not persisted.');
$desc=(string)eadb_scalar($pdo,"SELECT description FROM equipment_service_events WHERE organization_id=? AND equipment_asset_id=? ORDER BY id DESC LIMIT 1",[$org,$assetId]);
eadb_assert($desc==='replaced thermostat','Equipment service description parser persisted unexpected text.');

// Concurrency: confirmation must reject a stale equipment record and preserve newer state.
$stale=equipment_agent_handle($pdo,$user,['message'=>'mark this equipment active','pageContext'=>$context]);
eadb_assert(($stale['data']['requiresConfirmation']??false)===true,'Equipment stale-write fixture did not enter proposal state.');
$pdo->prepare("UPDATE equipment_assets SET operational_status='maintenance',updated_at=DATE_ADD(NOW(6),INTERVAL 1 SECOND) WHERE organization_id=? AND public_id=?")->execute([$org,'equip-agent-ci']);
$staleRejected=false;
try{equipment_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);}catch(InvalidArgumentException $e){$staleRejected=str_contains($e->getMessage(),'changed');}
eadb_assert($staleRejected,'Equipment stale proposal was not rejected.');
eadb_assert(eadb_scalar($pdo,"SELECT operational_status FROM equipment_assets WHERE organization_id=? AND public_id=?",[$org,'equip-agent-ci'])==='maintenance','Stale Equipment confirmation overwrote newer state.');

// Permission boundaries: view/skill alone cannot propose edit or service writes.
$readOnly=$user;$readOnly['permissions']=['equipment.view','agent.equipment_skills'];
$editBlocked=false;
try{equipment_agent_handle($pdo,$readOnly,['message'=>'mark this equipment out of service','pageContext'=>$context]);}catch(EquipmentAgentPermissionException){$editBlocked=true;}
eadb_assert($editBlocked,'Equipment status proposal bypassed equipment.edit permission.');
$serviceBlocked=false;
try{equipment_agent_handle($pdo,$readOnly,['message'=>'record repair: permission test','pageContext'=>$context]);}catch(EquipmentAgentPermissionException){$serviceBlocked=true;}
eadb_assert($serviceBlocked,'Equipment service proposal bypassed equipment.service permission.');

// Replacement intelligence may hand off to Purchasing but must not create a PO itself.
$poBefore=(int)eadb_scalar($pdo,"SELECT COUNT(*) FROM purchase_orders WHERE organization_id=?",[$org]);
$replacement=equipment_agent_handle($pdo,$user,['message'=>'Should we replace this equipment?','pageContext'=>$context]);
eadb_assert(($replacement['data']['handoff']['node']??'')==='purchasing','Equipment replacement intelligence did not identify the Purchasing handoff.');
eadb_assert((int)eadb_scalar($pdo,"SELECT COUNT(*) FROM purchase_orders WHERE organization_id=?",[$org])===$poBefore,'Equipment replacement read created a purchase order.');

// Main Brain signals include a real critical outage/overdue condition without mutating equipment.
$pdo->prepare("UPDATE equipment_assets SET operational_status='out_of_service',maintenance_required=1,next_service_on=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=?")
    ->execute([date('Y-m-d',strtotime('-1 day')),$org,'equip-agent-ci']);
$snapshot=['generatedAt'=>date(DATE_ATOM),'scope'=>[],'signals'=>[],'nextMoves'=>[]];
$merged=equipment_agent_merge_brain_snapshot($pdo,$user,$snapshot);
eadb_assert(count($merged['signals'])>=1,'Equipment risk was not added to Main Brain signals.');
eadb_assert(in_array('equipment',array_column($merged['signals'],'node'),true),'Main Brain signals do not identify the Equipment node.');

echo "Equipment + Maintenance DB-backed Agent lifecycle passed.\n";
