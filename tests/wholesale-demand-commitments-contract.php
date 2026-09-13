<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/wholesale-commerce.php';
require __DIR__.'/../includes/wholesale-demand.php';
require __DIR__.'/../includes/purchasing-orders.php';
require __DIR__.'/../includes/operations-wholesale.php';

function wdc_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo=app_pdo();
wdc_assert(wholesale_demand_ready($pdo),'Wholesale demand migration missing.');

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Wholesale Demand CI','active','America/Phoenix')");$org=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES ('wdc@example.test','x','Wholesale','Demand','Wholesale Demand CI','active')");$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);

$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,yield_quantity,yield_unit,ingredients_json,instructions_json,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?, 'active',?,?)")->execute([$org,'recipe-wdc-gelato','Wholesale Gelato Base','Gelato',10,'l','[]','[]',$uid,$uid]);
$pdo->prepare("INSERT INTO inventory_items (organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,par_level,reorder_point,status,created_by,updated_by) VALUES (?,?,?,?,?,20,20,5,'active',?,?)")->execute([$org,'inv-wdc-milk','wdc-milk','Milk','lb',$uid,$uid]);$milkId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO inventory_items (organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,par_level,reorder_point,status,created_by,updated_by) VALUES (?,?,?,?,?,10,10,2,'active',?,?)")->execute([$org,'inv-wdc-sugar','wdc-sugar','Sugar','kg',$uid,$uid]);$sugarId=(int)$pdo->lastInsertId();
$source=$pdo->prepare("INSERT INTO inventory_item_sources (organization_id,inventory_item_id,source_type,source_public_id,source_name,quantity_per_source,unit) VALUES (?,?,'recipe',?,?,?,?)");
$source->execute([$org,$milkId,'recipe:recipe-wdc-gelato','Wholesale Gelato Base',4,'kg']);
$source->execute([$org,$sugarId,'recipe:recipe-wdc-gelato','Wholesale Gelato Base',1,'kg']);

$pdo->prepare("INSERT INTO wholesale_leads (organization_id,public_id,business_name,contact_name,email,source,pipeline_stage,probability_percent) VALUES (?,?,?,?,?,'ci','qualified',30)")->execute([$org,'wholesale-wdc-lead','WDC Cafe','Casey Buyer','casey-wdc@example.test']);$leadId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wholesale_accounts (organization_id,public_id,wholesale_lead_id,business_name,account_status,primary_email,created_by,updated_by) VALUES (?,?,?,?, 'active',?,?,?)")->execute([$org,'wacct-wdc',$leadId,'WDC Cafe','casey-wdc@example.test',$uid,$uid]);$accountId=(int)$pdo->lastInsertId();$account=wholesale_commerce_account($pdo,$org,$accountId);
$product=wholesale_commerce_save_product($pdo,$org,['name'=>'Wholesale Gelato','category'=>'Gelato','recipeId'=>'recipe-wdc-gelato'],$uid);
$sku=wholesale_commerce_save_sku($pdo,$org,['productId'=>$product['public_id'],'sku'=>'WDC-5L','name'=>'Wholesale 5L Pan','sellUom'=>'pan','minimumQuantity'=>1,'quantityIncrement'=>1],$uid);
$profile=wholesale_demand_save_sku_production($pdo,$org,['skuId'=>$sku['public_id'],'contentQuantity'=>5,'contentUom'=>'l'],$uid);
wdc_assert((float)$profile['content_quantity']===5.0,'5L SKU content quantity not saved.');
$list=wholesale_commerce_save_price_list($pdo,$org,['name'=>'WDC Wholesale','minimumOrderAmount'=>0,'isDefault'=>true],$uid);
wholesale_commerce_set_price($pdo,$org,['priceListId'=>$list['public_id'],'skuId'=>$sku['public_id'],'unitPrice'=>60,'effectiveFrom'=>date('Y-m-d')],$uid);
wholesale_commerce_assign_price_list($pdo,$org,$accountId,$list['public_id'],$uid);

$beforeMilk=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE id={$milkId}")->fetchColumn();
$order=wholesale_commerce_create_order($pdo,$org,$account,['items'=>[['skuId'=>$sku['public_id'],'quantity'=>4]],'status'=>'confirmed','fulfillmentType'=>'Delivery','requestedFor'=>date('Y-m-d',strtotime('+2 days'))],$uid);
$taskCount=operations_sync_wholesale_tasks($pdo,$org,$uid);
wdc_assert($taskCount===2,'One-item wholesale order should create one production task plus fulfillment.');
$q=$pdo->prepare("SELECT COUNT(*) FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? AND status='active'");$q->execute([$org,$order['publicId']]);
wdc_assert((int)$q->fetchColumn()===2,'Operations wholesale sync must automatically build ingredient commitments.');
$sync=wholesale_demand_sync_order($pdo,$org,$order['publicId'],$uid);
wdc_assert((int)$sync['created']===2,'Confirmed order should create two ingredient commitments.');
wdc_assert(count($sync['issues'])===0,'Mapped confirmed order should not have commitment issues.');
$q=$pdo->prepare("SELECT quantity,unit FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? AND inventory_item_id=? AND status='active'");$q->execute([$org,$order['publicId'],$milkId]);$milk=$q->fetch();
wdc_assert((bool)$milk,'Milk commitment missing.');
wdc_assert((string)$milk['unit']==='lb','Commitment must use inventory base unit.');
wdc_assert(abs((float)$milk['quantity']-17.63698)<0.002,'4 pans at 5L with 10L recipe yield should require 2 batches and convert 8kg milk to lb.');
$afterMilk=(float)$pdo->query("SELECT on_hand_quantity FROM inventory_items WHERE id={$milkId}")->fetchColumn();
wdc_assert(abs($beforeMilk-$afterMilk)<0.0001,'Demand commitment must not decrement physical on-hand inventory.');

wholesale_demand_sync_order($pdo,$org,$order['publicId'],$uid);
$q=$pdo->prepare("SELECT COUNT(*) FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? AND status='active'");$q->execute([$org,$order['publicId']]);wdc_assert((int)$q->fetchColumn()===2,'Repeated synchronization must be idempotent.');
$forecast=wholesale_demand_forecast($pdo,$org,7);$milkForecast=null;foreach($forecast['items'] as $row)if($row['inventoryId']==='inv-wdc-milk')$milkForecast=$row;
wdc_assert(is_array($milkForecast),'Wholesale demand forecast did not include milk.');
wdc_assert(abs((float)$milkForecast['availableToPromise']-(20-17.637))<0.01,'Available-to-promise calculation is incorrect.');
$suggestions=purchasing_suggestions($pdo,$org);$milkSuggestion=null;foreach($suggestions as $row)if($row['inventoryId']==='inv-wdc-milk')$milkSuggestion=$row;
wdc_assert(is_array($milkSuggestion),'Purchasing did not consume wholesale commitments.');
wdc_assert((float)$milkSuggestion['committedQuantity']>17.63,'Purchasing commitment quantity missing.');
wdc_assert((float)$milkSuggestion['needQuantity']>17.63,'Purchasing should recommend replenishment for committed demand.');

// Explicit sell-unit-per-batch yield must override the 5L content fallback.
wholesale_demand_save_sku_production($pdo,$org,['skuId'=>$sku['public_id'],'recipeYieldPerBatch'=>4,'recipeYieldUnit'=>'pan','contentQuantity'=>5,'contentUom'=>'l'],$uid);
operations_sync_wholesale_tasks($pdo,$org,$uid);
$q=$pdo->prepare("SELECT quantity FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? AND inventory_item_id=? AND status='active'");$q->execute([$org,$order['publicId'],$milkId]);
wdc_assert(abs((float)$q->fetchColumn()-8.81849)<0.002,'Explicit four-pans-per-batch yield should require one batch and 4kg milk.');

$requested=wholesale_commerce_create_order($pdo,$org,$account,['items'=>[['skuId'=>$sku['public_id'],'quantity'=>2]],'status'=>'requested','requestedFor'=>date('Y-m-d',strtotime('+3 days'))],$uid);
operations_sync_wholesale_tasks($pdo,$org,$uid);
$q=$pdo->prepare("SELECT COUNT(*) FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? AND status='active'");$q->execute([$org,$requested['publicId']]);
wdc_assert((int)$q->fetchColumn()===0,'Requested/unconfirmed order must not reserve inventory.');

// Starting production from Operations converts requested demand into committed demand.
$q=$pdo->prepare("SELECT id FROM wholesale_orders WHERE organization_id=? AND public_id=?");$q->execute([$org,$requested['publicId']]);$requestedDbId=(int)$q->fetchColumn();
$q=$pdo->prepare("SELECT * FROM restaurant_tasks WHERE organization_id=? AND source_type='wholesale_order_item' AND source_public_id=? LIMIT 1");$q->execute([$org,'wholesale-order-'.$requestedDbId.'-item-0']);$requestedTask=$q->fetch();
wdc_assert((bool)$requestedTask,'Requested order production task missing.');
$requestedTask=operations_task_set_status($pdo,$org,(string)$requestedTask['public_id'],'in_progress',$uid);
operations_wholesale_task_status_changed($pdo,$org,$requestedTask,'in_progress',$uid);
$q=$pdo->prepare("SELECT status FROM wholesale_orders WHERE organization_id=? AND public_id=?");$q->execute([$org,$requested['publicId']]);wdc_assert($q->fetchColumn()==='in_production','Operations transition did not move requested order into production.');
$q=$pdo->prepare("SELECT COUNT(*) FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? AND status='active'");$q->execute([$org,$requested['publicId']]);wdc_assert((int)$q->fetchColumn()===2,'Starting production must activate requested-order ingredient commitments.');

$pdo->prepare("UPDATE wholesale_orders SET status='cancelled' WHERE organization_id=? AND public_id=?")->execute([$org,$order['publicId']]);
$cancel=wholesale_demand_sync_order($pdo,$org,$order['publicId'],$uid);wdc_assert((int)$cancel['created']===0,'Cancelled order must not create commitments.');
$q=$pdo->prepare("SELECT COUNT(*) FROM inventory_commitments WHERE organization_id=? AND source_parent_public_id=? AND status='active'");$q->execute([$org,$order['publicId']]);wdc_assert((int)$q->fetchColumn()===0,'Cancelled order commitments must be released.');

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Wholesale Demand Other','active','America/Phoenix')");$other=(int)$pdo->lastInsertId();
$q=$pdo->prepare("SELECT COUNT(*) FROM inventory_commitments WHERE organization_id=?");$q->execute([$other]);wdc_assert((int)$q->fetchColumn()===0,'Inventory commitments leaked across organizations.');

echo "wholesale-demand-commitments contract passed\n";
