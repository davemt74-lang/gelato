<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/wholesale-portal-operations.php';

function w5_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function w5_close(float $a,float $b,float $epsilon=.011):bool{return abs($a-$b)<$epsilon;}
$pdo=app_pdo();w5_assert(wholesale_portal_operations_ready($pdo),'W5 dependencies are not ready.');

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Wholesale W5 CI','active','America/Phoenix')");$org=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES ('w5@example.test','x','Wholesale','W5','Wholesale W5 CI','active')");$uid=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$pdo->prepare("INSERT INTO wholesale_accounts (organization_id,public_id,business_name,account_status,primary_email,payment_terms,preferred_fulfillment,internal_notes,created_by,updated_by) VALUES (?,'wacct-w5-a','W5 Market','active','buyer-a@example.test','Net 30','Delivery','INTERNAL-ONLY-W5',?,?)")->execute([$org,$uid,$uid]);$accountId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wholesale_accounts (organization_id,public_id,business_name,account_status,primary_email,payment_terms,preferred_fulfillment,created_by,updated_by) VALUES (?,'wacct-w5-b','Other W5 Market','active','buyer-b@example.test','Net 15','Pickup',?,?)")->execute([$org,$uid,$uid]);$otherAccountId=(int)$pdo->lastInsertId();
$account=wholesale_commerce_account($pdo,$org,$accountId);
$product=wholesale_commerce_save_product($pdo,$org,['name'=>'W5 Pistachio','category'=>'Gelato'],$uid);
$sku=wholesale_commerce_save_sku($pdo,$org,['productId'=>$product['public_id'],'sku'=>'W5-PIS-5L','name'=>'Pistachio 5L Pan','sellUom'=>'pan','minimumQuantity'=>1,'quantityIncrement'=>1],$uid);
$list=wholesale_commerce_save_price_list($pdo,$org,['name'=>'W5 Wholesale','minimumOrderAmount'=>0,'isDefault'=>true],$uid);
wholesale_commerce_set_price($pdo,$org,['priceListId'=>$list['public_id'],'skuId'=>$sku['public_id'],'unitPrice'=>50,'effectiveFrom'=>'2026-01-01'],$uid);
wholesale_commerce_assign_price_list($pdo,$org,$accountId,$list['public_id'],$uid);
wholesale_commerce_assign_price_list($pdo,$org,$otherAccountId,$list['public_id'],$uid);

$source=wholesale_commerce_create_order($pdo,$org,$account,['items'=>[['skuId'=>$sku['public_id'],'quantity'=>2]],'status'=>'confirmed','deliveryFee'=>10,'taxRatePercent'=>10,'fulfillmentType'=>'Delivery'],$uid);
$pdo->prepare("UPDATE wholesale_orders SET status='delivered',delivered_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$org,(int)$source['id']]);
$orderItemQ=$pdo->prepare('SELECT id FROM wholesale_order_items WHERE organization_id=? AND wholesale_order_id=? LIMIT 1');$orderItemQ->execute([$org,(int)$source['id']]);$orderItemId=(int)$orderItemQ->fetchColumn();
$pdo->prepare("INSERT INTO wholesale_fulfillments (organization_id,wholesale_order_id,public_id,fulfillment_number,fulfillment_type,status,delivered_at,created_by,updated_by) VALUES (?,?, 'wful-w5-a','WF-W5-A','Delivery','delivered',NOW(6),?,?)")->execute([$org,(int)$source['id'],$uid,$uid]);$fulfillmentId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO wholesale_fulfillment_items (organization_id,wholesale_fulfillment_id,wholesale_order_item_id,quantity) VALUES (?,?,?,2)')->execute([$org,$fulfillmentId,$orderItemId]);

$invoice=wholesale_receivables_create_invoice($pdo,$org,$source['publicId'],['issueDate'=>date('Y-m-d')],$uid);$invoice=wholesale_receivables_issue($pdo,$org,$invoice['invoice']['id'],[],$uid);wholesale_receivables_record($pdo,$org,$invoice['invoice']['id'],'payment',['amount'=>20,'effectiveDate'=>date('Y-m-d'),'paymentMethod'=>'ACH','reference'=>'W5-ACH-1'],$uid);

// Customer-safe fulfillment exposes progress but never inventory availability/consumption details.
$safeFulfillment=wholesale_portal_safe_fulfillment($pdo,$org,$accountId,$source['publicId']);
w5_assert($safeFulfillment!==null,'Customer-safe fulfillment was not returned.');
w5_assert(w5_close((float)$safeFulfillment['progress']['deliveryPercent'],100),'Customer delivery progress is incorrect.');
w5_assert(count($safeFulfillment['batches'])===1&&$safeFulfillment['batches'][0]['status']==='delivered','Customer fulfillment batch was not exposed.');
w5_assert(!array_key_exists('availability',$safeFulfillment)&&!array_key_exists('consumptions',$safeFulfillment['batches'][0]),'Customer fulfillment leaked internal inventory or consumption data.');
w5_assert(wholesale_portal_safe_fulfillment($pdo,$org,$otherAccountId,$source['publicId'])===null,'Customer fulfillment leaked across Wholesale accounts.');

// Customer-safe A/R exposes invoice/payment facts but never internal invoice/account notes.
$safeInvoice=wholesale_portal_safe_invoice($pdo,$org,$accountId,$invoice['invoice']['id']);
w5_assert($safeInvoice!==null&&count($safeInvoice['entries'])===2,'Customer-safe invoice/payment history is incomplete.');
w5_assert(!array_key_exists('internalNote',$safeInvoice),'Customer-safe invoice leaked internal notes.');
w5_assert(wholesale_portal_safe_invoice($pdo,$org,$otherAccountId,$invoice['invoice']['id'])===null,'Customer invoice leaked across Wholesale accounts.');

// Catalog resolves only active, priced SKUs for the account.
$catalog=wholesale_portal_catalog($pdo,$org,$accountId);w5_assert(count($catalog)===1&&w5_close((float)$catalog[0]['unitPrice'],50),'Account catalog did not resolve current canonical price.');

// Structured catalog shopping creates a customer request, not a fake order with guessed tax/delivery.
$orderCountBefore=(int)$pdo->query('SELECT COUNT(*) FROM wholesale_orders')->fetchColumn();
$request=wholesale_portal_catalog_request($pdo,$org,$accountId,['items'=>[['skuId'=>$sku['public_id'],'quantity'=>3]],'requestedFor'=>'2026-10-10'],$uid);
w5_assert(w5_close((float)$request['estimatedSubtotal'],150),'Catalog request subtotal is not server-authoritative.');
w5_assert((int)$pdo->query('SELECT COUNT(*) FROM wholesale_orders')->fetchColumn()===$orderCountBefore,'Catalog request fabricated a canonical Wholesale order before tax/delivery confirmation.');
$q=$pdo->prepare('SELECT request_type,metadata_json FROM wholesale_customer_requests WHERE organization_id=? AND public_id=?');$q->execute([$org,$request['requestId']]);$requestRow=$q->fetch();$metadata=json_decode((string)$requestRow['metadata_json'],true);
w5_assert($requestRow['request_type']==='reorder'&&($metadata['source']??'')==='portal_catalog'&&count($metadata['items']??[])===1,'Catalog request did not preserve structured canonical item metadata.');

// Repeat order is allowed only from this account's delivered order, reprices against today's catalog, and keeps prior tax/delivery setup.
wholesale_commerce_set_price($pdo,$org,['priceListId'=>$list['public_id'],'skuId'=>$sku['public_id'],'unitPrice'=>60,'effectiveFrom'=>date('Y-m-d')],$uid);
$repeat=wholesale_portal_repeat_order($pdo,$org,$accountId,$source['publicId'],['requestedFor'=>'2026-10-15'],$uid);
w5_assert($repeat['orderNumber']!==$source['orderNumber']&&w5_close((float)$repeat['totals']['subtotal'],120),'Repeat order did not use current SKU pricing.');
$q=$pdo->prepare('SELECT status,delivery_fee,tax_rate_percent,requested_for,fulfillment_type FROM wholesale_orders WHERE organization_id=? AND public_id=?');$q->execute([$org,$repeat['publicId']]);$repeatRow=$q->fetch();
w5_assert($repeatRow['status']==='requested'&&w5_close((float)$repeatRow['delivery_fee'],10)&&w5_close((float)$repeatRow['tax_rate_percent'],10),'Repeat order did not preserve requested-state and prior commercial fulfillment setup.');
w5_assert($repeatRow['requested_for']==='2026-10-15'&&$repeatRow['fulfillment_type']==='Delivery','Repeat order did not preserve requested fulfillment context.');
$blocked=false;try{wholesale_portal_repeat_order($pdo,$org,$otherAccountId,$source['publicId'],[],$uid);}catch(InvalidArgumentException){$blocked=true;}w5_assert($blocked,'Repeat order crossed Wholesale account isolation.');

// Dashboard composes W1-W4 safely and includes relationship history.
$dashboard=wholesale_portal_dashboard($pdo,$org,$accountId);w5_assert(count($dashboard['catalog'])===1&&count($dashboard['orders'])>=2&&count($dashboard['invoices'])===1,'W5 dashboard did not compose canonical Wholesale layers.');
w5_assert((float)$dashboard['summary']['receivables']>0&&count($dashboard['history'])>=3,'W5 dashboard receivables/history summary is incomplete.');
w5_assert(strpos(json_encode($dashboard,JSON_THROW_ON_ERROR),'INTERNAL-ONLY-W5')===false,'Customer dashboard leaked internal account notes.');

// Internal 360 may see internal context but remains scoped to the selected account.
$operator=wholesale_operations_account_360($pdo,$org,'wacct-w5-a');w5_assert(($operator['account']['internal_notes']??'')==='INTERNAL-ONLY-W5','Internal 360 lost internal account context.');
w5_assert(($operator['account']['public_id']??'')==='wacct-w5-a','Internal 360 resolved the wrong account.');
$accounts=wholesale_operations_accounts($pdo,$org);w5_assert(count($accounts)===2,'Internal 360 account list is incomplete.');

// W5 is composition-only: it must not create another inventory, order, receivable, or CRM table.
foreach(['wholesale_inventory','wholesale_portal_orders','wholesale_portal_invoices','wholesale_portal_customers'] as $forbidden){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$forbidden]);w5_assert((int)$q->fetchColumn()===0,'W5 introduced forbidden parallel table '.$forbidden.'.');}

echo "wholesale-portal-operations contract passed\n";
