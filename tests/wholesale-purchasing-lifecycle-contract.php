<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/wholesale-purchasing-lifecycle.php';
require_once __DIR__ . '/../includes/operations-wholesale.php';

function wpl_assert(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
$pdo=app_pdo();
wpl_assert(wholesale_planning_ready($pdo),'Wholesale purchasing/lifecycle migration missing.');
wpl_assert(wholesale_commerce_ready($pdo),'Wholesale commerce migration missing.');
wpl_assert(wholesale_acquisition_ready($pdo),'Wholesale acquisition migration missing.');

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Wholesale Planner CI','active','America/Phoenix')");
$org=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES ('wholesale-planner@example.test','x','Planner','CI','Planner CI','active')");
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);

$settings=wholesale_planning_save_settings($pdo,$org,[
    'panLiters'=>5,'defaultServingOz'=>4,'defaultSafetyStockPercent'=>10,'defaultWastePercent'=>5,'weeksPerMonth'=>4.33,
    'coreWeeklyPans'=>3,'growthWeeklyPans'=>6,'keyWeeklyPans'=>12
],$uid);
wpl_assert(abs((float)$settings['pan_liters']-5)<.001,'Planning settings did not persist.');

$lead=wholesale_acquisition_save($pdo,$org,[
    'businessName'=>'Planner Cafe','contactName'=>'Taylor Buyer','email'=>'planner-buyer@example.test','businessType'=>'Cafe / Coffee Shop','location'=>'Phoenix',
    'estimatedMonthlyVolume'=>'TBD','orderFrequency'=>'Weekly','flavorsInterest'=>'Pistachio, vanilla','fulfillmentPreference'=>'Delivery','buyerRole'=>'Owner',
    'painPoints'=>'Needs a premium dessert program.','buyingProcess'=>'Owner approves.','nextStep'=>'Build demand model.','nextFollowupAt'=>'2026-09-20T10:00','qualificationStatus'=>'qualified','packageSizes'=>['5L pan']
],$uid,true);
$leadPublic=(string)$lead['public_id'];

$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,yield_quantity,yield_unit,ingredients_json,instructions_json,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?, 'active',?,?)")->execute([$org,'recipe-wpl-pistachio','Pistachio Gelato','Gelato',20,'L','[]','[]',$uid,$uid]);
$product=wholesale_commerce_save_product($pdo,$org,['name'=>'Pistachio Gelato','category'=>'Gelato','recipeId'=>'recipe-wpl-pistachio'],$uid);
$sku=wholesale_commerce_save_sku($pdo,$org,['productId'=>$product['public_id'],'sku'=>'WPL-PIS-5L','name'=>'Pistachio 5L Pan','sellUom'=>'pan','minimumQuantity'=>1,'quantityIncrement'=>1,'recipeYieldPerBatch'=>20,'recipeYieldUnit'=>'L'],$uid);
$list=wholesale_commerce_save_price_list($pdo,$org,['name'=>'Planner Wholesale','minimumOrderAmount'=>0,'isDefault'=>true],$uid);
wholesale_commerce_set_price($pdo,$org,['priceListId'=>$list['public_id'],'skuId'=>$sku['public_id'],'unitPrice'=>55,'effectiveFrom'=>'2026-09-01'],$uid);
$catalog=wholesale_commerce_catalog($pdo,$org,null,'2026-09-13');
wpl_assert(count($catalog)===1&&abs((float)$catalog[0]['unitPrice']-55)<.001,'Planner did not receive canonical Wholesale catalog pricing.');

$calc=wholesale_planning_calculate([
    'customerMode'=>'adding_gelato','servingSizeOz'=>4,'servingsPerDay'=>100,'serviceDaysPerWeek'=>7,'safetyStockPercent'=>10,'wastePercent'=>5,
    'menuPricePerServing'=>6.50,'freezerPanCapacity'=>24,'deliveryFrequency'=>'Weekly','starterItems'=>[['skuId'=>$sku['public_id'],'quantity'=>20]]
],$settings,$catalog);
wpl_assert((int)$calc['recommendedWeeklyPans']===20,'100 daily 4oz servings should plan 20 5L pans/week with 15% combined buffer.');
wpl_assert($calc['accountTier']==='key','Weekly pan volume did not resolve the Key account tier.');
wpl_assert((float)$calc['servingsPerPan']>42&&(float)$calc['servingsPerPan']<43,'5L-to-4oz servings-per-pan conversion is wrong.');
wpl_assert((float)$calc['projectedMonthlyWholesaleSpend']>4700,'Canonical SKU pricing did not feed monthly wholesale spend.');
wpl_assert((float)$calc['projectedMonthlyGrossProfit']>0,'Customer gross-profit model was not calculated.');

$dessertCalc=wholesale_planning_calculate([
    'customerMode'=>'existing_dessert','servingSizeOz'=>3,'currentDessertUnitsPerDay'=>80,'gelatoCapturePercent'=>50,'serviceDaysPerWeek'=>7,'safetyStockPercent'=>0,'wastePercent'=>0
],$settings,[]);
wpl_assert(abs((float)$dessertCalc['servingsPerDay']-40)<.001,'Existing-dessert capture model did not derive gelato servings.');
$existingCalc=wholesale_planning_calculate([
    'customerMode'=>'existing_gelato','servingSizeOz'=>5,'existingWeeklyPans'=>8,'serviceDaysPerWeek'=>7,'safetyStockPercent'=>0,'wastePercent'=>0
],$settings,[]);
wpl_assert((int)$existingCalc['recommendedWeeklyPans']===8,'Existing-gelato mode did not respect known weekly pan volume.');

$saved=wholesale_planning_save_worksheet($pdo,$org,$leadPublic,[
    'customerMode'=>'adding_gelato','servingSizeOz'=>4,'servingsPerDay'=>100,'serviceDaysPerWeek'=>7,'safetyStockPercent'=>10,'wastePercent'=>5,
    'menuPricePerServing'=>6.50,'freezerPanCapacity'=>24,'deliveryFrequency'=>'Weekly','starterItems'=>[['skuId'=>$sku['public_id'],'quantity'=>20]],'selectedSkuIds'=>[$sku['public_id']],
    'notes'=>'CI planning worksheet'
],$uid);
wpl_assert((int)$saved['recommendedWeeklyPans']===20,'Saved worksheet calculation changed unexpectedly.');
$q=$pdo->prepare('SELECT estimated_monthly_volume,estimated_value FROM wholesale_leads WHERE organization_id=? AND public_id=?');$q->execute([$org,$leadPublic]);$leadAfter=$q->fetch();
wpl_assert(str_contains((string)$leadAfter['estimated_monthly_volume'],'5L pans/month'),'Worksheet did not write planning volume back to canonical lead.');
wpl_assert((float)$leadAfter['estimated_value']>0,'Worksheet did not write projected account value back to canonical lead.');

$life=wholesale_lifecycle_save($pdo,$org,$leadPublic,['lifecycleStage'=>'tasting','ownerUserId'=>$uid,'nextAction'=>'Run tasting.','nextActionAt'=>'2026-09-22T13:00'],$uid);
wpl_assert($life['lifecycle_stage']==='tasting','Lifecycle stage did not persist.');
$q=$pdo->prepare('SELECT pipeline_stage FROM wholesale_leads WHERE organization_id=? AND public_id=?');$q->execute([$org,$leadPublic]);wpl_assert($q->fetchColumn()==='sample','Tasting lifecycle did not mirror to existing sample pipeline stage.');

$tasting=wholesale_tasting_save($pdo,$org,$leadPublic,[
    'status'=>'completed','tastingFormat'=>'onsite','scheduledAt'=>'2026-09-22T13:00','completedAt'=>'2026-09-22T14:00','attendees'=>'Owner, chef',
    'flavors'=>['Pistachio','Vanilla'],'overallScore'=>4.8,'outcome'=>'trial','notes'=>'Strong fit.','nextAction'=>'Create trial order.','nextActionAt'=>'2026-09-23T10:00'
],$uid);
wpl_assert($tasting['status']==='completed'&&count($tasting['flavors'])===2,'Structured tasting record did not persist.');
$q=$pdo->prepare("SELECT COUNT(*) FROM wholesale_lead_activities a JOIN wholesale_leads l ON l.id=a.wholesale_lead_id WHERE a.organization_id=? AND l.public_id=? AND a.activity_type='sample'");$q->execute([$org,$leadPublic]);wpl_assert((int)$q->fetchColumn()===1,'Tasting was not appended to canonical Wholesale CRM activity.');

$blocked=false;try{wholesale_planning_create_starter_order($pdo,$org,$leadPublic,$uid);}catch(InvalidArgumentException){$blocked=true;}
wpl_assert($blocked,'Prospect worksheet must not create a canonical order before account conversion.');

$pdo->prepare("INSERT INTO wholesale_accounts (organization_id,public_id,wholesale_lead_id,business_name,account_status,primary_email,created_by,updated_by) SELECT organization_id,'wacct-wpl',id,business_name,'active',email,?,? FROM wholesale_leads WHERE organization_id=? AND public_id=?")->execute([$uid,$uid,$org,$leadPublic]);
$accountId=(int)$pdo->lastInsertId();
wholesale_commerce_assign_price_list($pdo,$org,$accountId,$list['public_id'],$uid);
$order=wholesale_planning_create_starter_order($pdo,$org,$leadPublic,$uid);
wpl_assert($order['orderNumber']!==''&&count($order['lines'])===1&&!$order['alreadyExists'],'Starter order did not enter canonical Wholesale order model.');
$q=$pdo->prepare('SELECT status,id FROM wholesale_orders WHERE organization_id=? AND public_id=?');$q->execute([$org,$order['publicId']]);$orderRow=$q->fetch();wpl_assert($orderRow['status']==='requested','Worksheet starter order must begin as requested, not committed production.');
$reused=wholesale_planning_create_starter_order($pdo,$org,$leadPublic,$uid);
wpl_assert($reused['publicId']===$order['publicId']&&!empty($reused['alreadyExists']),'Repeated starter-order creation must reuse the canonical order.');
$q=$pdo->prepare('SELECT COUNT(*) FROM wholesale_orders WHERE organization_id=? AND wholesale_account_id=?');$q->execute([$org,$accountId]);wpl_assert((int)$q->fetchColumn()===1,'Repeated starter-order creation created a duplicate order.');
$q=$pdo->prepare('SELECT starter_order_public_id FROM wholesale_purchase_worksheets WHERE organization_id=? AND wholesale_lead_id=(SELECT id FROM wholesale_leads WHERE organization_id=? AND public_id=?)');$q->execute([$org,$org,$leadPublic]);wpl_assert($q->fetchColumn()===$order['publicId'],'Worksheet did not retain its canonical starter-order linkage.');
if(operations_wholesale_ready($pdo)) operations_sync_wholesale_tasks($pdo,$org,$uid);
if(restaurant_brain_table_ready($pdo,'inventory_commitments')){
    $q=$pdo->prepare("SELECT COUNT(*) FROM inventory_commitments WHERE organization_id=? AND source_type='wholesale_order' AND source_parent_public_id=? AND status='active'");$q->execute([$org,$order['publicId']]);
    wpl_assert((int)$q->fetchColumn()===0,'Requested starter order must not reserve production inventory before confirmation.');
}
$q=$pdo->prepare('SELECT lifecycle_stage FROM wholesale_lifecycle l JOIN wholesale_leads w ON w.id=l.wholesale_lead_id WHERE l.organization_id=? AND w.public_id=?');$q->execute([$org,$leadPublic]);wpl_assert($q->fetchColumn()==='trial','Starter order did not advance lifecycle to trial.');
$q=$pdo->prepare('SELECT pipeline_stage FROM wholesale_leads WHERE organization_id=? AND public_id=?');$q->execute([$org,$leadPublic]);wpl_assert($q->fetchColumn()==='negotiation','Trial lifecycle did not preserve compatibility with the existing negotiation pipeline stage.');

$recurring=wholesale_lifecycle_save($pdo,$org,$leadPublic,['lifecycleStage'=>'recurring','ownerUserId'=>$uid,'nextAction'=>'Review standing order cadence.','nextActionAt'=>'2026-10-01T09:00'],$uid);
wpl_assert($recurring['lifecycle_stage']==='recurring','Recurring lifecycle stage missing.');
$q=$pdo->prepare('SELECT pipeline_stage,won_at,lost_at FROM wholesale_leads WHERE organization_id=? AND public_id=?');$q->execute([$org,$leadPublic]);$terminal=$q->fetch();
wpl_assert($terminal['pipeline_stage']==='won'&&$terminal['won_at']!==null&&$terminal['lost_at']===null,'Recurring account must mirror canonical won state and terminal timestamps.');
$lost=wholesale_lifecycle_save($pdo,$org,$leadPublic,['lifecycleStage'=>'lost','ownerUserId'=>$uid,'nextAction'=>'','nextActionAt'=>''],$uid);
wpl_assert($lost['lifecycle_stage']==='lost','Lost lifecycle stage missing.');
$q=$pdo->prepare('SELECT pipeline_stage,won_at,lost_at FROM wholesale_leads WHERE organization_id=? AND public_id=?');$q->execute([$org,$leadPublic]);$terminal=$q->fetch();
wpl_assert($terminal['pipeline_stage']==='lost'&&$terminal['won_at']===null&&$terminal['lost_at']!==null,'Lost lifecycle must clear won_at and match canonical pipeline semantics.');
wholesale_lifecycle_save($pdo,$org,$leadPublic,['lifecycleStage'=>'recurring','ownerUserId'=>$uid,'nextAction'=>'Reactivated recurring account.','nextActionAt'=>'2026-10-02T09:00'],$uid);
$q=$pdo->prepare('SELECT pipeline_stage,won_at,lost_at FROM wholesale_leads WHERE organization_id=? AND public_id=?');$q->execute([$org,$leadPublic]);$terminal=$q->fetch();
wpl_assert($terminal['pipeline_stage']==='won'&&$terminal['won_at']!==null&&$terminal['lost_at']===null,'Reactivated recurring lifecycle must clear lost_at.');

$detail=wholesale_planning_detail($pdo,$org,$leadPublic);
wpl_assert($detail['canCreateOrder']===true,'Active converted account should expose canonical starter-order capability.');
wpl_assert(count($detail['tastings'])===1,'Planner detail did not return tasting history.');
wpl_assert((int)$detail['worksheet']['recommended_weekly_pans']===20,'Planner detail did not return saved demand model.');
wpl_assert($detail['worksheet']['starter_order_public_id']===$order['publicId'],'Planner detail omitted canonical starter-order linkage.');

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Other Planner CI','active','America/Phoenix')");$other=(int)$pdo->lastInsertId();
$q=$pdo->prepare('SELECT COUNT(*) FROM wholesale_purchase_worksheets WHERE organization_id=?');$q->execute([$other]);wpl_assert((int)$q->fetchColumn()===0,'Wholesale planning data leaked across organizations.');

echo "wholesale-purchasing-lifecycle contract passed\n";
