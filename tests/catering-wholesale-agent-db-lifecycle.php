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


// B8D18J9: Model-selected lifecycle proposals must be inert until a separate
// Wholesale Agent Confirm, then use tenant-scoped locked canonical state.
require __DIR__.'/../includes/food-monster-b8d18-wholesale-lifecycle-reads.php';
require __DIR__.'/../includes/food-monster-b8d18-wholesale-lifecycle-proposals.php';
require_once __DIR__.'/../includes/wholesale-receivables.php';
$j9Confirm=static function(string $orderPublic)use($pdo,$user):array{
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();
    try{return wholesale_agent_handle($pdo,$user,
        ['message'=>'Confirm','pageContext'=>['module'=>'wholesale','selectedWholesaleOrderPublicId'=>$orderPublic]]);}
    finally{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();}
};
$newOrder=static function(string $status='requested')use($pdo,$org,$account,$sku,$uid):array{
    $made=wholesale_commerce_create_order($pdo,$org,$account,
        ['items'=>[['skuId'=>$sku['public_id'],'quantity'=>3]],'status'=>$status,'fulfillmentType'=>'pickup'],$uid);
    return wholesale_fulfillment_order($pdo,$org,$made['publicId']);
};
$readArgs=['order_public_id'=>$order['public_id'],'section'=>'commercial'];
$read=fm8d18j9a_order_read($readArgs,$user);
cwdb_assert(($read['data']['records']['orderNumber']??null)===$order['order_number'],'Lifecycle order commercial read did not return saved order.');
$quoteMade=wholesale_commerce_create_quote($pdo,$org,$account,
    ['items'=>[['skuId'=>$sku['public_id'],'quantity'=>2]],'status'=>'draft'],$uid);
$quoteRead=fm8d18j9a_quote_read(['quote_public_id'=>$quoteMade['publicId'],'section'=>'summary'],$user);
cwdb_assert(($quoteRead['data']['records']['quoteNumber']??null)===$quoteMade['quoteNumber'],'Lifecycle quote summary read not canonical.');
$quoteSearch=fm8d18j9a_quote_search(['query'=>$quoteMade['quoteNumber']],$user);
cwdb_assert(($quoteSearch['data']['matches'][0]['quotePublicId']??null)===$quoteMade['publicId'],'Lifecycle quote search not tenant scoped.');
$requested=$newOrder();$exact=['order_public_id'=>$requested['public_id'],'order_number'=>$requested['order_number'],'business_name'=>$requested['business_name']];
$planArgs=$exact+['promised_start'=>'2026-12-04T09:00','promised_end'=>'2026-12-04T11:00'];
$planProposal=fm8d18j9b_propose($planArgs,$user,'order_promise');
cwdb_assert(($planProposal['data']['requiresConfirmation']??false)===true,'Promised window proposal was not staged.');
cwdb_assert((wholesale_fulfillment_order($pdo,$org,$requested['public_id']))['promised_window_start']===null,'Promised window was changed before confirmation.');
$planConfirmed=$j9Confirm($requested['public_id']);
cwdb_assert(($planConfirmed['data']['action']??null)==='order_promise','Promised window confirmation not routed to specialist.');
$planned=wholesale_fulfillment_order($pdo,$org,$requested['public_id']);
cwdb_assert(str_starts_with((string)$planned['promised_window_start'],'2026-12-04 09:00'),'Promised start not saved.');
cwdb_assert($planned['fulfillment_type']==='pickup'&&$planned['requested_window_start']===null,'Promised update erased another plan field.');
$locationArgs=$exact+['location_public_id'=>'wloc-agent-ci','location_name'=>'Lifecycle Cafe Main'];
fm8d18j9b_propose($locationArgs,$user,'order_location');
$locConfirmed=$j9Confirm($requested['public_id']);
cwdb_assert(($locConfirmed['data']['action']??null)==='order_location','Location confirmation not routed to specialist.');
$afterLoc=wholesale_fulfillment_order($pdo,$org,$requested['public_id']);
cwdb_assert(str_starts_with((string)$afterLoc['promised_window_start'],'2026-12-04 09:00'),'Location update erased promised window.');
cwdb_assert((int)$afterLoc['wholesale_account_location_id']>0,'Location confirmation did not save account location.');
$confirmArgs=$exact+['saved_total'=>(float)$requested['total'],'acknowledge_saved_total'=>true];
fm8d18j9b_propose($confirmArgs,$user,'order_confirm');
cwdb_assert((wholesale_fulfillment_order($pdo,$org,$requested['public_id']))['status']==='requested','Order confirmation wrote before Confirm.');
$confirmedOrder=$j9Confirm($requested['public_id']);
cwdb_assert(($confirmedOrder['data']['action']??null)==='order_confirm','Order confirmation not routed to specialist.');
cwdb_assert((wholesale_fulfillment_order($pdo,$org,$requested['public_id']))['status']==='confirmed','Order not persisted as confirmed.');
$stale=$newOrder();
$staleArgs=['order_public_id'=>$stale['public_id'],'order_number'=>$stale['order_number'],'business_name'=>$stale['business_name'],
    'promised_start'=>'2026-12-05T09:00','promised_end'=>'2026-12-05T10:00'];
fm8d18j9b_propose($staleArgs,$user,'order_promise');
$pdo->prepare('UPDATE wholesale_orders SET updated_at=DATE_ADD(NOW(6),INTERVAL 1 SECOND) WHERE organization_id=? AND id=?')->execute([$org,$stale['id']]);
$staleRejected=false;
try{$j9Confirm($stale['public_id']);}catch(InvalidArgumentException $e){$staleRejected=str_contains($e->getMessage(),'changed');}
cwdb_assert($staleRejected,'Stale lifecycle proposal was not rejected.');
cwdb_assert((wholesale_fulfillment_order($pdo,$org,$stale['public_id']))['promised_window_start']===null,'Stale lifecycle action changed the order.');
$toCancel=$newOrder();
$cancelArgs=['order_public_id'=>$toCancel['public_id'],'order_number'=>$toCancel['order_number'],'business_name'=>$toCancel['business_name'],
    'reason'=>'Buyer requested early cancellation','acknowledge_cancel'=>true];
fm8d18j9b_propose($cancelArgs,$user,'order_cancel');
cwdb_assert((wholesale_fulfillment_order($pdo,$org,$toCancel['public_id']))['status']==='requested','Cancel proposal changed order prematurely.');
$cancelConfirmed=$j9Confirm($toCancel['public_id']);
cwdb_assert(($cancelConfirmed['data']['action']??null)==='order_cancel','Order cancellation confirmation not routed to specialist.');
cwdb_assert((wholesale_fulfillment_order($pdo,$org,$toCancel['public_id']))['status']==='cancelled','Order cancellation not persisted.');
$invoiced=$newOrder();
$invoice=wholesale_receivables_create_invoice($pdo,$org,$invoiced['public_id'],['issueDate'=>date('Y-m-d')],$uid);
cwdb_assert(!empty($invoice),'Draft invoice fixture not created.');
$invoiceBlocked=false;
try{fm8d18j9b_propose(['order_public_id'=>$invoiced['public_id'],'order_number'=>$invoiced['order_number'],'business_name'=>$invoiced['business_name'],
    'reason'=>'Cancel despite invoice','acknowledge_cancel'=>true],$user,'order_cancel');}catch(InvalidArgumentException $e){$invoiceBlocked=str_contains($e->getMessage(),'invoice');}
cwdb_assert($invoiceBlocked,'Cancellation did not block existing draft invoice.');
$existingBatchBlocked=false;
try{fm8d18j9b_propose(['order_public_id'=>$order['public_id'],'order_number'=>$order['order_number'],'business_name'=>$order['business_name'],
    'reason'=>'Cancel despite batch','acknowledge_cancel'=>true],$user,'order_cancel');}catch(InvalidArgumentException){$existingBatchBlocked=true;}
cwdb_assert($existingBatchBlocked,'Cancellation did not block existing fulfillment batch.');


// B8D18J10: Wholesale Receivables read/propose/Confirm lifecycle using canonical
// MariaDB, including stale-transaction guards and untouched-draft restrictions.
require __DIR__.'/../includes/food-monster-b8d18-wholesale-receivables-reads.php';
require __DIR__.'/../includes/food-monster-b8d18-wholesale-receivables-proposals.php';
$j10Order=$newOrder('confirmed');
$j10Base=['order_public_id'=>$j10Order['public_id'],'order_number'=>$j10Order['order_number'],
    'business_name'=>$j10Order['business_name'],'saved_total'=>(float)$j10Order['total'],
    'issue_date'=>date('Y-m-d'),'create_draft'=>true];
$j10Stage=fm8d18j10b_propose($j10Base,$user,'invoice_create_draft');
cwdb_assert(($j10Stage['data']['requiresConfirmation']??false)===true,'Draft invoice did not stage pending Confirm.');
cwdb_assert(cwdb_count($pdo,'SELECT COUNT(*) FROM wholesale_invoices WHERE organization_id=? AND wholesale_order_id=?',
    [$org,$j10Order['id']])===0,'Model draft proposal wrote an invoice prematurely.');
$j10Confirmed=$j9Confirm($j10Order['public_id']);
cwdb_assert(($j10Confirmed['data']['action']??null)==='invoice_create_draft','Specialist did not handle draft invoice confirmation.');
$j10Id=(string)($j10Confirmed['data']['invoiceId']??'');
cwdb_assert($j10Id!=='','Confirmed draft returned no exact invoice ID.');
$j10Invoice=wholesale_receivables_invoice($pdo,$org,$j10Id);
cwdb_assert($j10Invoice['status']==='draft','Specialist draft created an issued invoice.');
cwdb_assert(cwdb_count($pdo,'SELECT COUNT(*) FROM wholesale_receivable_entries WHERE organization_id=? AND wholesale_invoice_id=?',
    [$org,$j10Invoice['id']])===0,'Draft creation wrote a financial ledger entry.');
$j10Search=fm8d18j10a_search(['query'=>$j10Invoice['invoice_number']],$user);
cwdb_assert(($j10Search['data']['matches'][0]['invoicePublicId']??null)===$j10Id,'Search did not find exact saved invoice.');
$j10Read=fm8d18j10a_read(['invoice_public_id'=>$j10Id,'section'=>'summary'],$user);
cwdb_assert(($j10Read['data']['records']['status']??null)==='draft','Read did not return canonical draft status.');
$j10Ledger=fm8d18j10a_read(['invoice_public_id'=>$j10Id,'section'=>'ledger'],$user);
cwdb_assert(($j10Ledger['data']['records']??null)===[],'Draft invoice should have no payment ledger.');
$j10Aging=fm8d18j10a_aging([],$user);
cwdb_assert(is_array($j10Aging['data']['aging']??null),'A/R aging read failed.');
$j10WrongOrg=$user;$j10WrongOrg['organization_id']=$org+9100;
$j10ForeignDenied=false;
try{fm8d18j10a_read(['invoice_public_id'=>$j10Id,'section'=>'summary'],$j10WrongOrg);}
catch(InvalidArgumentException $e){$j10ForeignDenied=str_contains($e->getMessage(),'not found');}
cwdb_assert($j10ForeignDenied,'Cross-tenant invoice read leaked records.');
$j10VoidArgs=['invoice_public_id'=>$j10Id,'invoice_number'=>$j10Invoice['invoice_number'],
    'order_number'=>$j10Invoice['order_number'],'business_name'=>$j10Invoice['business_name'],
    'reason'=>'Duplicate test draft','void_draft'=>true];
$j10VoidStage=fm8d18j10b_propose($j10VoidArgs,$user,'invoice_void_draft');
cwdb_assert(($j10VoidStage['data']['requiresConfirmation']??false)===true,'Draft-void did not stage.');
cwdb_assert((wholesale_receivables_invoice($pdo,$org,$j10Id))['status']==='draft','Draft void changed state before Confirm.');
$j10VoidConfirmed=$j9Confirm($j10Order['public_id']);
cwdb_assert(($j10VoidConfirmed['data']['action']??null)==='invoice_void_draft','Specialist did not handle void draft.');
cwdb_assert((wholesale_receivables_invoice($pdo,$org,$j10Id))['status']==='void','Confirmed untouched draft not voided.');
$j10CreateBlocked=false;
try{fm8d18j10b_propose($j10Base,$user,'invoice_create_draft');}catch(InvalidArgumentException $e){$j10CreateBlocked=str_contains($e->getMessage(),'already exists');}
cwdb_assert($j10CreateBlocked,'New draft proposal allowed duplicate/voided invoice.');
$j10StaleOrder=$newOrder('confirmed');
$j10StaleBase=['order_public_id'=>$j10StaleOrder['public_id'],'order_number'=>$j10StaleOrder['order_number'],
    'business_name'=>$j10StaleOrder['business_name'],'saved_total'=>(float)$j10StaleOrder['total'],
    'issue_date'=>date('Y-m-d'),'create_draft'=>true];
fm8d18j10b_propose($j10StaleBase,$user,'invoice_create_draft');
$pdo->prepare('UPDATE wholesale_orders SET updated_at=DATE_ADD(NOW(6),INTERVAL 1 SECOND) WHERE organization_id=? AND id=?')
    ->execute([$org,$j10StaleOrder['id']]);
$j10OrderStaleDenied=false;
try{$j9Confirm($j10StaleOrder['public_id']);}catch(InvalidArgumentException $e){$j10OrderStaleDenied=str_contains($e->getMessage(),'changed');}
cwdb_assert($j10OrderStaleDenied,'Stale order did not block draft at confirmation.');
cwdb_assert(cwdb_count($pdo,'SELECT COUNT(*) FROM wholesale_invoices WHERE organization_id=? AND wholesale_order_id=?',
    [$org,$j10StaleOrder['id']])===0,'Stale draft proposal created an invoice.');
$j10StaleInvoiceOrder=$newOrder('confirmed');
$j10StaleInvoice=wholesale_receivables_create_invoice($pdo,$org,$j10StaleInvoiceOrder['public_id'],
    ['issueDate'=>date('Y-m-d')],$uid);
$j10StaleInvoiceId=(string)$j10StaleInvoice['invoice']['id'];
$j10SavedInvoice=wholesale_receivables_invoice($pdo,$org,$j10StaleInvoiceId);
fm8d18j10b_propose([
    'invoice_public_id'=>$j10StaleInvoiceId,
    'invoice_number'=>$j10SavedInvoice['invoice_number'],'order_number'=>$j10SavedInvoice['order_number'],
    'business_name'=>$j10SavedInvoice['business_name'],'reason'=>'Stale void should fail','void_draft'=>true],
    $user,'invoice_void_draft');
$pdo->prepare('UPDATE wholesale_invoices SET updated_at=DATE_ADD(NOW(6),INTERVAL 1 SECOND) WHERE organization_id=? AND id=?')
    ->execute([$org,$j10SavedInvoice['id']]);
$j10InvoiceStaleDenied=false;
try{$j9Confirm($j10StaleInvoiceOrder['public_id']);}catch(InvalidArgumentException $e){$j10InvoiceStaleDenied=str_contains($e->getMessage(),'changed');}
cwdb_assert($j10InvoiceStaleDenied,'Stale invoice did not block draft-void at confirmation.');
cwdb_assert((wholesale_receivables_invoice($pdo,$org,$j10StaleInvoiceId))['status']==='draft','Stale draft-void changed invoice.');
$j10ViewOnly=$user;$j10ViewOnly['permissions']=['wholesale.view','wholesale.agent','wholesale.receivables.view'];
cwdb_assert(fm8d18j10b_catalogue($j10ViewOnly)===[],'View-only actor exposed financial proposals.');

// Permission regression: a user that can view/use the node but cannot manage Wholesale
// must not even receive a write proposal.
$readOnly=$user;$readOnly['permissions']=['wholesale.view','wholesale.agent'];
$permissionBlocked=false;
try{wholesale_agent_handle($pdo,$readOnly,['message'=>'create fulfillment batch for all remaining quantities','pageContext'=>$wContext]);}catch(WholesaleAgentPermissionException){$permissionBlocked=true;}
cwdb_assert($permissionBlocked,'Wholesale write proposal bypassed wholesale.manage permission.');

echo "Catering + Wholesale DB-backed Agent lifecycle passed.\n";
