<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/wholesale-commerce.php';
require __DIR__.'/../includes/wholesale-demand.php';
require __DIR__.'/../includes/wholesale-fulfillment.php';
require __DIR__.'/../includes/operations-wholesale.php';

function waf_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function waf_close(float $a,float $b,float $epsilon=.002):bool{return abs($a-$b)<$epsilon;}
$pdo=app_pdo();
waf_assert(wholesale_fulfillment_ready($pdo),'Wholesale W3 fulfillment migration missing.');
waf_assert(wholesale_demand_ready($pdo),'Wholesale W2 demand contract missing.');

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Wholesale Fulfillment CI','active','America/Phoenix')");$org=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES ('waf@example.test','x','Wholesale','Fulfillment','Wholesale Fulfillment CI','active')");$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);

$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,yield_quantity,yield_unit,ingredients_json,instructions_json,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?, 'active',?,?)")->execute([$org,'recipe-waf-gelato','WAF Gelato Base','Gelato',20,'l','[]','[]',$uid,$uid]);
$pdo->prepare("INSERT INTO inventory_items (organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,par_level,reorder_point,status,created_by,updated_by) VALUES (?,?,?,?,?,20,20,5,'active',?,?)")->execute([$org,'inv-waf-milk','waf-milk','WAF Milk','lb',$uid,$uid]);$milkId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO inventory_items (organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,par_level,reorder_point,status,created_by,updated_by) VALUES (?,?,?,?,?,10,10,2,'active',?,?)")->execute([$org,'inv-waf-sugar','waf-sugar','WAF Sugar','kg',$uid,$uid]);$sugarId=(int)$pdo->lastInsertId();
$source=$pdo->prepare("INSERT INTO inventory_item_sources (organization_id,inventory_item_id,source_type,source_public_id,source_name,quantity_per_source,unit) VALUES (?,?,'recipe',?,?,?,?)");
$source->execute([$org,$milkId,'recipe:recipe-waf-gelato','WAF Gelato Base',4,'kg']);$source->execute([$org,$sugarId,'recipe:recipe-waf-gelato','WAF Gelato Base',1,'kg']);

$pdo->prepare("INSERT INTO wholesale_leads (organization_id,public_id,business_name,contact_name,email,source,pipeline_stage,probability_percent) VALUES (?,?,?,?,?,'ci','won',100)")->execute([$org,'wholesale-waf-lead','WAF Cafe','Avery Buyer','waf-buyer@example.test']);$leadId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wholesale_accounts (organization_id,public_id,wholesale_lead_id,business_name,account_status,primary_email,created_by,updated_by) VALUES (?,?,?,?, 'active',?,?,?)")->execute([$org,'wacct-waf',$leadId,'WAF Cafe','waf-buyer@example.test',$uid,$uid]);$accountId=(int)$pdo->lastInsertId();$account=wholesale_commerce_account($pdo,$org,$accountId);
$pdo->prepare("INSERT INTO wholesale_account_locations (organization_id,wholesale_account_id,public_id,name,address_line_1,city,state,postal_code,country_code,is_primary,status) VALUES (?,?,?,?,?,?,?,?,?,1,'active')")->execute([$org,$accountId,'wloc-waf-main','WAF Cafe Main','100 Test Ave','Phoenix','AZ','85001','US']);$locationId=(int)$pdo->lastInsertId();

$product=wholesale_commerce_save_product($pdo,$org,['name'=>'WAF Gelato','category'=>'Gelato','recipeId'=>'recipe-waf-gelato'],$uid);
$sku=wholesale_commerce_save_sku($pdo,$org,['productId'=>$product['public_id'],'sku'=>'WAF-5L','name'=>'WAF 5L Pan','sellUom'=>'pan','minimumQuantity'=>1,'quantityIncrement'=>1],$uid);
wholesale_demand_save_sku_production($pdo,$org,['skuId'=>$sku['public_id'],'recipeYieldPerBatch'=>20,'recipeYieldUnit'=>'l','contentQuantity'=>5,'contentUom'=>'l'],$uid);
$list=wholesale_commerce_save_price_list($pdo,$org,['name'=>'WAF Wholesale','minimumOrderAmount'=>0,'isDefault'=>true],$uid);
wholesale_commerce_set_price($pdo,$org,['priceListId'=>$list['public_id'],'skuId'=>$sku['public_id'],'unitPrice'=>60,'effectiveFrom'=>date('Y-m-d')],$uid);wholesale_commerce_assign_price_list($pdo,$org,$accountId,$list['public_id'],$uid);

$order=wholesale_commerce_create_order($pdo,$org,$account,['items'=>[['skuId'=>$sku['public_id'],'quantity'=>4]],'status'=>'confirmed','fulfillmentType'=>'local_delivery','requestedFor'=>date('Y-m-d',strtotime('+2 days'))],$uid);
operations_sync_wholesale_tasks($pdo,$org,$uid);
$detail=wholesale_fulfillment_save_order_plan($pdo,$org,$order['publicId'],['locationId'=>'wloc-waf-main','fulfillmentType'=>'local_delivery','requestedWindowStart'=>'2026-09-20T10:00','requestedWindowEnd'=>'2026-09-20T12:00','promisedWindowStart'=>'2026-09-20T10:30','promisedWindowEnd'=>'2026-09-20T11:30'],$uid);
waf_assert((int)$pdo->query("SELECT wholesale_account_location_id FROM wholesale_orders WHERE id=".(int)$order['id'])->fetchColumn()===$locationId,'Order fulfillment location was not persisted.');
waf_assert(substr((string)$detail['order']['promisedWindowEnd'],0,19)==='2026-09-20 11:30:00','Promised fulfillment window did not persist.');
$badWindow=false;try{wholesale_fulfillment_save_order_plan($pdo,$org,$order['publicId'],['promisedWindowStart'=>'2026-09-20T12:00','promisedWindowEnd'=>'2026-09-20T11:00'],$uid);}catch(InvalidArgumentException){$badWindow=true;}waf_assert($badWindow,'Invalid reversed fulfillment window was accepted.');

// A location from another account in the same organization must never be assignable to this order.
$pdo->prepare("INSERT INTO wholesale_leads (organization_id,public_id,business_name,contact_name,email,source,pipeline_stage,probability_percent) VALUES (?,?,?,?,?,'ci','won',100)")->execute([$org,'wholesale-waf-other-lead','Other Cafe','Other Buyer','other-waf@example.test']);$otherLead=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wholesale_accounts (organization_id,public_id,wholesale_lead_id,business_name,account_status,primary_email,created_by,updated_by) VALUES (?,?,?,?, 'active',?,?,?)")->execute([$org,'wacct-waf-other',$otherLead,'Other Cafe','other-waf@example.test',$uid,$uid]);$otherAccount=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wholesale_account_locations (organization_id,wholesale_account_id,public_id,name,address_line_1,city,state,country_code,status) VALUES (?,?,?,?,?,?,?,'US','active')")->execute([$org,$otherAccount,'wloc-waf-other','Other Cafe Main','200 Other Ave','Phoenix','AZ']);
$crossLocation=false;try{wholesale_fulfillment_save_order_plan($pdo,$org,$order['publicId'],['locationId'=>'wloc-waf-other'],$uid);}catch(InvalidArgumentException){$crossLocation=true;}waf_assert($crossLocation,'A fulfillment location from another Wholesale account was accepted.');

$batch1=wholesale_fulfillment_create_batch($pdo,$org,$order['publicId'],['items'=>[['lineNumber'=>1,'quantity'=>2]],'fulfillmentType'=>'local_delivery','locationId'=>'wloc-waf-main','notes'=>'First half'], $uid);
waf_assert($batch1['status']==='draft'&&count($batch1['items'])===1,'Partial fulfillment batch did not persist.');
operations_sync_wholesale_tasks($pdo,$org,$uid);
$legacyQ=$pdo->prepare("SELECT status FROM restaurant_tasks WHERE organization_id=? AND source_type='wholesale_order_fulfillment' AND source_public_id=? LIMIT 1");$legacyQ->execute([$org,'wholesale-order-'.(int)$order['id'].'-fulfillment']);waf_assert($legacyQ->fetchColumn()==='cancelled','W3 fulfillment batch did not supersede the legacy whole-order fulfillment task.');
$batchTaskQ=$pdo->prepare("SELECT * FROM restaurant_tasks WHERE organization_id=? AND source_type='wholesale_fulfillment' AND source_public_id=? LIMIT 1");$batchTaskQ->execute([$org,'wholesale-fulfillment-'.(int)$pdo->query("SELECT id FROM wholesale_fulfillments WHERE public_id=".$pdo->quote($batch1['id']))->fetchColumn()]);$batchTask=$batchTaskQ->fetch();waf_assert((bool)$batchTask&&$batchTask['status']==='queued','W3 fulfillment batch task was not created in Operations.');
$progress=wholesale_fulfillment_progress($pdo,$org,(int)$order['id']);waf_assert(waf_close((float)$progress['allocationPercent'],50,.01),'Two of four pans should allocate 50% of the order.');
$over=false;try{wholesale_fulfillment_create_batch($pdo,$org,$order['publicId'],['items'=>[['lineNumber'=>1,'quantity'=>3]]],$uid);}catch(InvalidArgumentException){$over=true;}waf_assert($over,'Over-allocation beyond remaining order quantity was accepted.');

// Complete the canonical production task so the order can enter ready state.
$q=$pdo->prepare("SELECT * FROM restaurant_tasks WHERE organization_id=? AND source_type='wholesale_order_item' AND source_public_id=? LIMIT 1");$q->execute([$org,'wholesale-order-'.(int)$order['id'].'-item-0']);$prod=$q->fetch();waf_assert((bool)$prod,'Wholesale production task missing.');
$prod=operations_task_set_status($pdo,$org,(string)$prod['public_id'],'completed',$uid);operations_wholesale_task_status_changed($pdo,$org,$prod,'completed',$uid);
$q=$pdo->prepare('SELECT status FROM wholesale_orders WHERE organization_id=? AND id=?');$q->execute([$org,(int)$order['id']]);waf_assert($q->fetchColumn()==='ready','Completing Wholesale production did not make the order ready.');

$batch1=wholesale_fulfillment_set_status($pdo,$org,$batch1['id'],'ready',$uid);operations_sync_wholesale_tasks($pdo,$org,$uid);
$batchTaskQ->execute([$org,'wholesale-fulfillment-'.(int)$pdo->query("SELECT id FROM wholesale_fulfillments WHERE public_id=".$pdo->quote($batch1['id']))->fetchColumn()]);$batchTask=$batchTaskQ->fetch();
$completionBlocked=false;try{operations_wholesale_validate_task_transition($pdo,$org,$batchTask,'completed');}catch(InvalidArgumentException){$completionBlocked=true;}waf_assert($completionBlocked,'Operations task completion bypassed inventory-controlled Wholesale delivery.');
operations_wholesale_validate_task_transition($pdo,$org,$batchTask,'in_progress');$batchTask=operations_task_set_status($pdo,$org,(string)$batchTask['public_id'],'in_progress',$uid);operations_wholesale_task_status_changed($pdo,$org,$batchTask,'in_progress',$uid);
$q=$pdo->prepare('SELECT status FROM wholesale_fulfillments WHERE organization_id=? AND public_id=?');$q->execute([$org,$batch1['id']]);waf_assert($q->fetchColumn()==='dispatched','Operations in-progress transition did not dispatch the W3 batch.');
$q=$pdo->prepare('SELECT status FROM wholesale_orders WHERE organization_id=? AND id=?');$q->execute([$org,(int)$order['id']]);waf_assert($q->fetchColumn()==='out_for_delivery','Delivery batch dispatch did not move the order to out_for_delivery.');

$availability=wholesale_fulfillment_batch_availability($pdo,$org,wholesale_fulfillment_batch($pdo,$org,$batch1['id']));waf_assert((int)$availability['shortages']===0,'First fulfillment batch unexpectedly has inventory shortages.');
$milkBefore=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE id={$milkId}")->fetchColumn();$sugarBefore=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE id={$sugarId}")->fetchColumn();
$delivered1=wholesale_fulfillment_deliver($pdo,$org,$batch1['id'],$uid);waf_assert($delivered1['status']==='delivered','First partial batch did not deliver.');
$milkAfter1=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE id={$milkId}")->fetchColumn();$sugarAfter1=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE id={$sugarId}")->fetchColumn();
waf_assert(waf_close($milkBefore-$milkAfter1,4.409245),'Two of four 5L pans should consume half of the 4kg milk recipe requirement converted to lb.');
waf_assert(waf_close($sugarBefore-$sugarAfter1,.5,.0002),'Two of four 5L pans should consume 0.5kg sugar.');
$q=$pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE organization_id=? AND source_type='wholesale_fulfillment' AND source_public_id LIKE ?");$q->execute([$org,$batch1['id'].':line:%']);waf_assert((int)$q->fetchColumn()===2,'Delivered partial batch should create two canonical inventory transactions.');
$q=$pdo->prepare("SELECT COUNT(*) FROM wholesale_fulfillment_consumptions WHERE organization_id=? AND wholesale_fulfillment_id=(SELECT id FROM wholesale_fulfillments WHERE organization_id=? AND public_id=?)");$q->execute([$org,$org,$batch1['id']]);waf_assert((int)$q->fetchColumn()===2,'Fulfillment consumption linkage is incomplete.');
$q=$pdo->prepare("SELECT status FROM wholesale_orders WHERE organization_id=? AND id=?");$q->execute([$org,(int)$order['id']]);waf_assert($q->fetchColumn()==='ready','Partial delivery must return an out-for-delivery order to ready until the remainder ships.');
$q=$pdo->prepare("SELECT quantity FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? AND inventory_item_id=? AND status='active'");$q->execute([$org,$order['publicId'],$milkId]);waf_assert(waf_close((float)$q->fetchColumn(),4.409245),'Remaining milk commitment should shrink proportionally after partial delivery.');

// Retry must be idempotent: no duplicate stock consumption.
wholesale_fulfillment_deliver($pdo,$org,$batch1['id'],$uid);$milkRetry=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE id={$milkId}")->fetchColumn();waf_assert(waf_close($milkRetry,$milkAfter1,.0001),'Retrying a delivered fulfillment consumed inventory twice.');

// A cancelled batch releases allocation and never consumes physical inventory.
$cancelBatch=wholesale_fulfillment_create_batch($pdo,$org,$order['publicId'],['items'=>[['lineNumber'=>1,'quantity'=>2]],'fulfillmentType'=>'pickup'],$uid);wholesale_fulfillment_set_status($pdo,$org,$cancelBatch['id'],'cancelled',$uid);
$progress=wholesale_fulfillment_progress($pdo,$org,(int)$order['id']);waf_assert(waf_close((float)$progress['allocated'],2,.0001),'Cancelled batch must release its order-line allocation.');

$batch2=wholesale_fulfillment_create_batch($pdo,$org,$order['publicId'],['items'=>[['lineNumber'=>1,'quantity'=>2]],'fulfillmentType'=>'pickup'],$uid);wholesale_fulfillment_set_status($pdo,$org,$batch2['id'],'ready',$uid);wholesale_fulfillment_deliver($pdo,$org,$batch2['id'],$uid);
$q=$pdo->prepare('SELECT status,delivered_at FROM wholesale_orders WHERE organization_id=? AND id=?');$q->execute([$org,(int)$order['id']]);$finalOrder=$q->fetch();waf_assert($finalOrder['status']==='delivered'&&!empty($finalOrder['delivered_at']),'Final partial batch did not complete the canonical order.');
$q=$pdo->prepare("SELECT COUNT(*) FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? AND status='active'");$q->execute([$org,$order['publicId']]);waf_assert((int)$q->fetchColumn()===0,'Fully delivered order must have no active ingredient commitments.');

// Shortage delivery must fail atomically without consuming any item or changing the batch status.
$order2=wholesale_commerce_create_order($pdo,$org,$account,['items'=>[['skuId'=>$sku['public_id'],'quantity'=>4]],'status'=>'confirmed','fulfillmentType'=>'pickup'],$uid);operations_sync_wholesale_tasks($pdo,$org,$uid);
$q=$pdo->prepare("SELECT * FROM restaurant_tasks WHERE organization_id=? AND source_type='wholesale_order_item' AND source_public_id=? LIMIT 1");$q->execute([$org,'wholesale-order-'.(int)$order2['id'].'-item-0']);$prod2=$q->fetch();$prod2=operations_task_set_status($pdo,$org,(string)$prod2['public_id'],'completed',$uid);operations_wholesale_task_status_changed($pdo,$org,$prod2,'completed',$uid);
$shortBatch=wholesale_fulfillment_create_batch($pdo,$org,$order2['publicId'],['items'=>[['lineNumber'=>1,'quantity'=>4]],'fulfillmentType'=>'pickup'],$uid);wholesale_fulfillment_set_status($pdo,$org,$shortBatch['id'],'ready',$uid);
$pdo->prepare('UPDATE inventory_items SET on_hand_quantity=? WHERE organization_id=? AND id=?')->execute([1,$org,$milkId]);$sugarBeforeShort=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE id={$sugarId}")->fetchColumn();
$blocked=false;try{wholesale_fulfillment_deliver($pdo,$org,$shortBatch['id'],$uid);}catch(RuntimeException){$blocked=true;}waf_assert($blocked,'Inventory-short fulfillment was not blocked.');
waf_assert(waf_close((float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE id={$milkId}")->fetchColumn(),1,.0001),'Shortage rollback changed milk inventory.');waf_assert(waf_close((float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE id={$sugarId}")->fetchColumn(),$sugarBeforeShort,.0001),'Shortage rollback partially consumed another ingredient.');
$q=$pdo->prepare('SELECT status FROM wholesale_fulfillments WHERE organization_id=? AND public_id=?');$q->execute([$org,$shortBatch['id']]);waf_assert($q->fetchColumn()==='ready','Blocked shortage delivery changed fulfillment status.');

// Organization isolation.
$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Wholesale Fulfillment Other','active','America/Phoenix')");$other=(int)$pdo->lastInsertId();
$leak=$pdo->prepare('SELECT COUNT(*) FROM wholesale_fulfillments WHERE organization_id=?');$leak->execute([$other]);waf_assert((int)$leak->fetchColumn()===0,'Wholesale fulfillment data leaked across organizations.');

echo "wholesale-allocation-fulfillment contract passed\n";
