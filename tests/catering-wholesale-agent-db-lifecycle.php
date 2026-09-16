<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/catering-agent-actions.php';
require __DIR__.'/../includes/wholesale-commerce.php';
require __DIR__.'/../includes/wholesale-agent-core.php';

function cwdb_assert(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}

function cwdb_count(PDO $pdo,string $sql,array $params=[]): int
{
    $q=$pdo->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();
}

$pdo=app_pdo();
$_SESSION=[];

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Agent Lifecycle CI','active','America/Phoenix')");
$org=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES ('agent-lifecycle@example.test','x','Agent','Lifecycle','Agent Lifecycle CI','active')");
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$user=['id'=>$uid,'organization_id'=>$org,'permissions'=>['*']];

// Catering: the action must be proposed first, produce no write before confirmation,
// execute against MariaDB only after confirmation, support cancel, and reject stale writes.
$pdo->prepare("INSERT INTO catering_leads (organization_id,public_id,contact_name,email,event_type,event_date,start_time,end_time,guest_count,venue_name,pipeline_stage,probability_percent) VALUES (?,?,?,?,?,DATE_ADD(CURDATE(),INTERVAL 10 DAY),'18:00:00','21:00:00',50,?,'confirmed',100)")
    ->execute([$org,'cater-agent-ci','Lifecycle Customer','customer@example.test','Corporate Event','Lifecycle Venue']);
$operation=$pdo->query("SELECT * FROM restaurant_operations WHERE organization_id={$org} AND source_public_id='cater-agent-ci' LIMIT 1")->fetch();
cwdb_assert((bool)$operation,'Catering operation fixture was not created.');
$context=['module'=>'catering','selectedOperationPublicId'=>$operation['public_id']];

$proposal=catering_agent_actions_handle($pdo,$user,['message'=>'add catering task: Agent lifecycle task','pageContext'=>$context]);
cwdb_assert(($proposal['skill']??'')==='catering.action_proposal','Catering write did not enter proposal state.');
cwdb_assert(($proposal['data']['requiresConfirmation']??false)===true,'Catering proposal did not require confirmation.');
cwdb_assert(cwdb_count($pdo,"SELECT COUNT(*) FROM restaurant_operation_tasks WHERE organization_id=? AND operation_id=? AND title=?",[$org,$operation['id'],'Agent lifecycle task'])===0,'Catering task was written before confirmation.');

$confirmed=catering_agent_actions_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);
cwdb_assert(($confirmed['skill']??'')==='catering.action_confirmed','Catering confirmation did not execute.');
cwdb_assert(cwdb_count($pdo,"SELECT COUNT(*) FROM restaurant_operation_tasks WHERE organization_id=? AND operation_id=? AND title=?",[$org,$operation['id'],'Agent lifecycle task'])===1,'Confirmed Catering task was not persisted.');

$statusProposal=catering_agent_actions_handle($pdo,$user,['message'=>'mark task Agent lifecycle task done','pageContext'=>$context]);
cwdb_assert(($statusProposal['data']['requiresConfirmation']??false)===true,'Catering status write skipped proposal state.');
$statusConfirmed=catering_agent_actions_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);
cwdb_assert(($statusConfirmed['data']['status']??'')==='done','Catering task status confirmation did not execute.');
$cwStatus=$pdo->prepare("SELECT status FROM restaurant_operation_tasks WHERE organization_id=? AND operation_id=? AND title=?");
$cwStatus->execute([$org,$operation['id'],'Agent lifecycle task']);
cwdb_assert($cwStatus->fetchColumn()==='done','Confirmed Catering task status was not persisted.');

$cancelProposal=catering_agent_actions_handle($pdo,$user,['message'=>'add catering task: This must be cancelled','pageContext'=>$context]);
cwdb_assert(($cancelProposal['data']['requiresConfirmation']??false)===true,'Catering cancel fixture did not enter proposal state.');
$cancelled=catering_agent_actions_handle($pdo,$user,['message'=>'Cancel','pageContext'=>$context]);
cwdb_assert(($cancelled['skill']??'')==='catering.action_cancelled','Catering proposal was not cancelled.');
cwdb_assert(cwdb_count($pdo,"SELECT COUNT(*) FROM restaurant_operation_tasks WHERE organization_id=? AND operation_id=? AND title=?",[$org,$operation['id'],'This must be cancelled'])===0,'Cancelled Catering proposal changed the database.');

$staleProposal=catering_agent_actions_handle($pdo,$user,['message'=>'mark task Agent lifecycle task blocked','pageContext'=>$context]);
cwdb_assert(($staleProposal['data']['requiresConfirmation']??false)===true,'Catering stale-write fixture did not enter proposal state.');
$pdo->prepare("UPDATE restaurant_operation_tasks SET status='in_progress',updated_at=DATE_ADD(NOW(6),INTERVAL 1 SECOND) WHERE organization_id=? AND operation_id=? AND title=?")
    ->execute([$org,$operation['id'],'Agent lifecycle task']);
$staleRejected=false;
try{catering_agent_actions_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);}catch(InvalidArgumentException $e){$staleRejected=str_contains($e->getMessage(),'changed');}
cwdb_assert($staleRejected,'Catering stale proposal was not rejected by the DB-backed concurrency guard.');
$cwStatus->execute([$org,$operation['id'],'Agent lifecycle task']);
cwdb_assert($cwStatus->fetchColumn()==='in_progress','Catering stale confirmation overwrote newer state.');

// Wholesale: build a real order using the canonical commerce API, then prove that
// create-batch and batch-cancel writes both follow Propose -> Confirm -> Execute.
$pdo->prepare("INSERT INTO wholesale_leads (organization_id,public_id,business_name,contact_name,email,source,pipeline_stage,probability_percent) VALUES (?,?,?,?,?,'ci','won',100)")
    ->execute([$org,'wholesale-agent-ci-lead','Lifecycle Cafe','Wholesale Buyer','buyer@example.test']);
$leadId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wholesale_accounts (organization_id,public_id,wholesale_lead_id,business_name,account_status,primary_email,created_by,updated_by) VALUES (?,?,?,?, 'active',?,?,?)")
    ->execute([$org,'wacct-agent-ci',$leadId,'Lifecycle Cafe','buyer@example.test',$uid,$uid]);
$accountId=(int)$pdo->lastInsertId();
$account=wholesale_commerce_account($pdo,$org,$accountId);
$pdo->prepare("INSERT INTO wholesale_account_locations (organization_id,wholesale_account_id,public_id,name,address_line_1,city,state,postal_code,country_code,is_primary,status) VALUES (?,?,?,?,?,?,?,?,?,1,'active')")
    ->execute([$org,$accountId,'wloc-agent-ci','Lifecycle Cafe Main','100 Test Ave','Phoenix','AZ','85001','US']);

$product=wholesale_commerce_save_product($pdo,$org,['name'=>'Lifecycle Gelato','category'=>'Gelato'],$uid);
$sku=wholesale_commerce_save_sku($pdo,$org,['productId'=>$product['public_id'],'sku'=>'AGENT-CI-5L','name'=>'Lifecycle 5L Pan','sellUom'=>'pan','minimumQuantity'=>1,'quantityIncrement'=>1],$uid);
$list=wholesale_commerce_save_price_list($pdo,$org,['name'=>'Lifecycle Wholesale','minimumOrderAmount'=>0,'isDefault'=>true],$uid);
wholesale_commerce_set_price($pdo,$org,['priceListId'=>$list['public_id'],'skuId'=>$sku['public_id'],'unitPrice'=>60,'effectiveFrom'=>date('Y-m-d')],$uid);
wholesale_commerce_assign_price_list($pdo,$org,$accountId,$list['public_id'],$uid);
$orderCreated=wholesale_commerce_create_order($pdo,$org,$account,['items'=>[['skuId'=>$sku['public_id'],'quantity'=>4]],'status'=>'confirmed','fulfillmentType'=>'pickup','requestedFor'=>date('Y-m-d',strtotime('+2 days'))],$uid);
$order=wholesale_fulfillment_order($pdo,$org,$orderCreated['publicId']);
$wContext=['module'=>'wholesale','selectedWholesaleOrderPublicId'=>$order['public_id']];

$wProposal=wholesale_agent_handle($pdo,$user,['message'=>'create fulfillment batch for all remaining quantities','pageContext'=>$wContext]);
cwdb_assert(($wProposal['skill']??'')==='wholesale.action_proposal','Wholesale create-batch write did not enter proposal state.');
cwdb_assert(($wProposal['data']['requiresConfirmation']??false)===true,'Wholesale create-batch proposal did not require confirmation.');
cwdb_assert(cwdb_count($pdo,"SELECT COUNT(*) FROM wholesale_fulfillments WHERE organization_id=? AND wholesale_order_id=?",[$org,$order['id']])===0,'Wholesale batch was written before confirmation.');

$wConfirmed=wholesale_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$wContext]);
cwdb_assert(($wConfirmed['skill']??'')==='wholesale.action_confirmed','Wholesale create-batch confirmation did not execute.');
$batchPublic=(string)($wConfirmed['data']['batchId']??'');
cwdb_assert($batchPublic!=='','Wholesale confirmation did not return the created batch.');
cwdb_assert(cwdb_count($pdo,"SELECT COUNT(*) FROM wholesale_fulfillments WHERE organization_id=? AND wholesale_order_id=? AND public_id=?",[$org,$order['id'],$batchPublic])===1,'Confirmed Wholesale batch was not persisted.');

$batchContext=$wContext+['selectedWholesaleBatchPublicId'=>$batchPublic];
$cancelBatchProposal=wholesale_agent_handle($pdo,$user,['message'=>'cancel this batch','pageContext'=>$batchContext]);
cwdb_assert(($cancelBatchProposal['data']['requiresConfirmation']??false)===true,'Wholesale batch cancellation skipped proposal state.');
$q=$pdo->prepare("SELECT status FROM wholesale_fulfillments WHERE organization_id=? AND public_id=?");$q->execute([$org,$batchPublic]);
cwdb_assert($q->fetchColumn()==='draft','Wholesale batch changed before cancellation confirmation.');
$cancelBatchConfirmed=wholesale_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$batchContext]);
cwdb_assert(($cancelBatchConfirmed['data']['status']??'')==='cancelled','Wholesale batch cancellation did not execute.');
$q->execute([$org,$batchPublic]);
cwdb_assert($q->fetchColumn()==='cancelled','Confirmed Wholesale batch cancellation was not persisted.');

// Permission regression: a user that can view/use the node but cannot manage Wholesale
// must not even receive a write proposal.
$readOnly=$user;$readOnly['permissions']=['wholesale.view','wholesale.agent'];
$permissionBlocked=false;
try{wholesale_agent_handle($pdo,$readOnly,['message'=>'create fulfillment batch for all remaining quantities','pageContext'=>$wContext]);}catch(WholesaleAgentPermissionException){$permissionBlocked=true;}
cwdb_assert($permissionBlocked,'Wholesale write proposal bypassed wholesale.manage permission.');

echo "Catering + Wholesale DB-backed Agent lifecycle passed.\n";
