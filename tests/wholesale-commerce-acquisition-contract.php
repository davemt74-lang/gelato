<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/wholesale-commerce.php';
require __DIR__ . '/../includes/wholesale-acquisition.php';

function wca_assert(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
$pdo=app_pdo();
wca_assert(wholesale_commerce_ready($pdo),'Wholesale commerce migration missing.');
wca_assert(wholesale_acquisition_ready($pdo),'Wholesale acquisition migration missing.');

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Wholesale Commerce CI','active','America/Phoenix')");
$org=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES ('wholesale-commerce@example.test','x','Wholesale','CI','Wholesale CI','active')");
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,yield_quantity,yield_unit,ingredients_json,instructions_json,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?, 'active',?,?)")->execute([$org,'recipe-wca-pistachio','Pistachio Gelato','Gelato',1,'batch','[]','[]',$uid,$uid]);

$lead=wholesale_acquisition_save($pdo,$org,[
    'businessName'=>'CI Market','contactName'=>'Casey Buyer','email'=>'buyer-wca@example.test','businessType'=>'Retail / Market','location'=>'Phoenix',
    'estimatedMonthlyVolume'=>'20 pans / month','estimatedMonthlyUnits'=>20,'estimatedMonthlyRevenue'=>900,'orderFrequency'=>'Biweekly',
    'flavorsInterest'=>'Pistachio, vanilla','fulfillmentPreference'=>'Delivery','buyerRole'=>'Owner','painPoints'=>'Needs reliable premium gelato supply.',
    'buyingProcess'=>'Owner approves pricing.','nextStep'=>'Schedule tasting.','nextFollowupAt'=>'2026-09-20T10:00','qualificationStatus'=>'qualified',
    'estimatedValue'=>10800,'packageSizes'=>['5L pan']
],$uid,true);
wca_assert($lead['pipeline_stage']==='qualified','Completed qualified worksheet did not feed pipeline stage.');
wca_assert((int)$lead['completion_percent']>50,'Worksheet completion not stored.');

$leadAgain=wholesale_acquisition_save($pdo,$org,[
    'id'=>$lead['public_id'],'businessName'=>'CI Market','contactName'=>'Casey Buyer','email'=>'buyer-wca@example.test','businessType'=>'Retail / Market',
    'location'=>'Phoenix','estimatedMonthlyVolume'=>'24 pans / month','orderFrequency'=>'Biweekly','flavorsInterest'=>'Pistachio, vanilla',
    'fulfillmentPreference'=>'Delivery','buyerRole'=>'Owner','painPoints'=>'Needs reliable premium gelato supply.','buyingProcess'=>'Owner approves pricing.',
    'nextStep'=>'Send sample follow-up.','nextFollowupAt'=>'2026-09-21T11:00','qualificationStatus'=>'sample'
],$uid,true);
wca_assert($leadAgain['pipeline_stage']==='sample','Worksheet update did not move canonical pipeline stage.');

$draft=wholesale_acquisition_save($pdo,$org,[
    'id'=>$lead['public_id'],'businessName'=>'CI Market','contactName'=>'Casey Buyer','email'=>'buyer-wca@example.test','businessType'=>'Retail / Market',
    'location'=>'Phoenix','estimatedMonthlyVolume'=>'24 pans / month','orderFrequency'=>'Biweekly','flavorsInterest'=>'Pistachio, vanilla',
    'fulfillmentPreference'=>'Delivery','buyerRole'=>'Owner','painPoints'=>'Needs reliable premium gelato supply.','buyingProcess'=>'Owner approves pricing.',
    'nextStep'=>'Draft pricing notes.','nextFollowupAt'=>'2026-09-22T11:00','qualificationStatus'=>'quoted'
],$uid,false);
wca_assert($draft['pipeline_stage']==='sample','Draft worksheet save must not reset or advance the existing pipeline stage.');

$q=$pdo->prepare("SELECT COUNT(*) FROM wholesale_lead_activities WHERE organization_id=? AND wholesale_lead_id=(SELECT id FROM wholesale_leads WHERE organization_id=? AND public_id=?) AND activity_type='worksheet'");
$q->execute([$org,$org,$lead['public_id']]);
wca_assert((int)$q->fetchColumn()===3,'Worksheet saves must append pipeline activity.');

$pdo->prepare("INSERT INTO wholesale_accounts (organization_id,public_id,wholesale_lead_id,business_name,account_status,primary_email,created_by,updated_by) SELECT organization_id,'wacct-wca',id,business_name,'active',email,?,? FROM wholesale_leads WHERE organization_id=? AND public_id=?")->execute([$uid,$uid,$org,$lead['public_id']]);
$accountId=(int)$pdo->lastInsertId();
$account=wholesale_commerce_account($pdo,$org,$accountId);

$product=wholesale_commerce_save_product($pdo,$org,['name'=>'Pistachio Gelato','category'=>'Gelato','recipeId'=>'recipe-wca-pistachio'],$uid);
$productAgain=wholesale_commerce_save_product($pdo,$org,['id'=>$product['public_id'],'name'=>'Pistachio Gelato','category'=>'Gelato','recipeId'=>'recipe-wca-pistachio'],$uid);
wca_assert($productAgain['public_id']===$product['public_id'],'No-op product edit should remain valid.');

$sku=wholesale_commerce_save_sku($pdo,$org,['productId'=>$product['public_id'],'sku'=>'GEL-PIS-5L','name'=>'Pistachio 5L Pan','sellUom'=>'pan','minimumQuantity'=>2,'quantityIncrement'=>1,'recipeYieldPerBatch'=>4,'recipeYieldUnit'=>'pan'],$uid);
$skuAgain=wholesale_commerce_save_sku($pdo,$org,['id'=>$sku['public_id'],'productId'=>$product['public_id'],'sku'=>'GEL-PIS-5L','name'=>'Pistachio 5L Pan','sellUom'=>'pan','minimumQuantity'=>2,'quantityIncrement'=>1,'recipeYieldPerBatch'=>4,'recipeYieldUnit'=>'pan'],$uid);
wca_assert($skuAgain['public_id']===$sku['public_id'],'No-op SKU edit should remain valid.');

$list=wholesale_commerce_save_price_list($pdo,$org,['name'=>'CI Wholesale','minimumOrderAmount'=>80,'isDefault'=>true],$uid);
$listAgain=wholesale_commerce_save_price_list($pdo,$org,['id'=>$list['public_id'],'name'=>'CI Wholesale','minimumOrderAmount'=>80,'isDefault'=>true],$uid);
wca_assert($listAgain['public_id']===$list['public_id'],'No-op price-list edit should remain valid.');

$badDate=false;
try{wholesale_commerce_set_price($pdo,$org,['priceListId'=>$list['public_id'],'skuId'=>$sku['public_id'],'unitPrice'=>48,'effectiveFrom'=>'09/01/2026'],$uid);}catch(InvalidArgumentException){$badDate=true;}
wca_assert($badDate,'Invalid price effective dates must be rejected.');
wholesale_commerce_set_price($pdo,$org,['priceListId'=>$list['public_id'],'skuId'=>$sku['public_id'],'unitPrice'=>48,'effectiveFrom'=>'2026-09-01'],$uid);
wholesale_commerce_assign_price_list($pdo,$org,$accountId,$list['public_id'],$uid);
wholesale_commerce_assign_price_list($pdo,$org,$accountId,$list['public_id'],$uid);
$q=$pdo->prepare("SELECT COUNT(*) FROM wholesale_account_price_lists WHERE organization_id=? AND wholesale_account_id=? AND effective_until IS NULL");$q->execute([$org,$accountId]);
wca_assert((int)$q->fetchColumn()===1,'Repeated price-list assignment must be idempotent.');

$catalog=wholesale_commerce_catalog($pdo,$org,$accountId,'2026-09-13');
wca_assert(count($catalog)===1&&abs((float)$catalog[0]['unitPrice']-48)<.001,'Account catalog did not resolve canonical price.');
$failed=false;try{wholesale_commerce_resolve_lines($pdo,$org,$accountId,[['skuId'=>$sku['public_id'],'quantity'=>1]],'2026-09-13');}catch(InvalidArgumentException){$failed=true;}
wca_assert($failed,'MOQ must be enforced server-side.');

$badTax=false;
try{wholesale_commerce_totals(100,0,31);}catch(InvalidArgumentException){$badTax=true;}
wca_assert($badTax,'Invalid tax rates must be rejected rather than silently clamped.');

$quote=wholesale_commerce_create_quote($pdo,$org,$account,['items'=>[['skuId'=>$sku['public_id'],'quantity'=>2]],'subtotal'=>1,'total'=>1,'deliveryFee'=>5,'taxRatePercent'=>8.5,'status'=>'sent'],$uid);
wca_assert(abs((float)$quote['totals']['subtotal']-96)<.001,'Quote trusted caller subtotal instead of canonical pricing.');
wca_assert(abs((float)$quote['totals']['taxTotal']-8.16)<.001,'Quote tax was not server-calculated.');
$q=$pdo->prepare('SELECT COUNT(*) FROM wholesale_quote_items WHERE organization_id=? AND wholesale_quote_id=?');$q->execute([$org,$quote['id']]);wca_assert((int)$q->fetchColumn()===1,'Normalized quote line missing.');
$q=$pdo->prepare('SELECT COUNT(*) FROM wholesale_quote_events WHERE organization_id=? AND wholesale_quote_id=?');$q->execute([$org,$quote['id']]);wca_assert((int)$q->fetchColumn()===1,'Quote event missing.');

$order=wholesale_commerce_create_order($pdo,$org,$account,['items'=>[['skuId'=>$sku['public_id'],'quantity'=>3]],'subtotal'=>0,'deliveryFee'=>0,'taxRatePercent'=>0,'status'=>'confirmed','fulfillmentType'=>'Delivery','requestedFor'=>'2026-09-20'],$uid);
wca_assert(abs((float)$order['totals']['subtotal']-144)<.001,'Order trusted caller subtotal instead of canonical pricing.');
$q=$pdo->prepare('SELECT COUNT(*) FROM wholesale_order_items WHERE organization_id=? AND wholesale_order_id=?');$q->execute([$org,$order['id']]);wca_assert((int)$q->fetchColumn()===1,'Normalized order line missing.');
$q=$pdo->prepare('SELECT COUNT(*) FROM wholesale_order_events WHERE organization_id=? AND wholesale_order_id=?');$q->execute([$org,$order['id']]);wca_assert((int)$q->fetchColumn()===1,'Order event missing.');

$pdo->prepare("UPDATE wholesale_accounts SET account_status='on_hold' WHERE id=? AND organization_id=?")->execute([$accountId,$org]);
$holdBlocked=false;
try{wholesale_commerce_create_order($pdo,$org,wholesale_commerce_account($pdo,$org,$accountId),['items'=>[['skuId'=>$sku['public_id'],'quantity'=>2]],'deliveryFee'=>0,'taxRatePercent'=>0],$uid);}catch(InvalidArgumentException){$holdBlocked=true;}
wca_assert($holdBlocked,'On-hold wholesale accounts must not create new orders.');

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Other Wholesale CI','active','America/Phoenix')");
$other=(int)$pdo->lastInsertId();
$q=$pdo->prepare("SELECT COUNT(*) FROM wholesale_products WHERE organization_id=? AND public_id=?");$q->execute([$other,$product['public_id']]);
wca_assert((int)$q->fetchColumn()===0,'Wholesale commerce leaked across organizations.');

echo "wholesale-commerce-acquisition contract passed\n";
