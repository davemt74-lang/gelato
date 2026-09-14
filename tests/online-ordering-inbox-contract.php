<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/customer-account-core.php';
require __DIR__.'/../includes/customer-inbox-core.php';
require __DIR__.'/../includes/online-order-core.php';

$pdo=app_pdo();
function ooici_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function ooici_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

ooici_assert(online_order_ready($pdo),'Online ordering and customer Inbox migrations must be installed.');
foreach(['customer_inbox_messages','customer_inbox_recipients','customer_inbox_preferences','online_orders'] as $table){
    ooici_assert((int)ooici_one($pdo,"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?",[$table])===1,$table.' table is missing.');
}
ooici_assert((int)ooici_one($pdo,"SELECT COUNT(*) FROM permissions WHERE permission_key='customer_promotions.manage'")===1,'Customer promotion permission is missing.');
$forbidden=(int)ooici_one($pdo,"SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='online_orders' AND column_name IN ('card_number','pan','cvv','track_data','magstripe')");
ooici_assert($forbidden===0,'Online ordering must not create raw payment-card storage.');

$slug='online-inbox-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Online Inbox CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$location=location_save($pdo,$org,null,[
    'name'=>'Central Pickup','public_slug'=>'central-pickup','city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix',
    'dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>true,'pickup_lead_minutes'=>25,
]);
$locationId=(int)$location['id'];

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);
$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'CI Margherita',?,1)")->execute([$org,$section,'ci-margherita-'.$slug]);
$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'12 inch','12',20.00,'USD',1)")->execute([$item]);
$price=(int)$pdo->lastInsertId();

$email=$slug.'@example.test';
$password='Online-Order-CI!42';
$registration=customer_account_register($pdo,$org,[
    'firstName'=>'Online','lastName'=>'Customer','email'=>$email,'phone'=>'6025550144','password'=>$password,'passwordConfirm'=>$password,
]);
$userId=(int)$registration['userId'];
$customerId=(int)ooici_one($pdo,'SELECT id FROM crm_customers WHERE organization_id=? AND user_id=?',[$org,$userId]);
ooici_assert($customerId>0,'Customer account must remain linked to CRM identity.');
pos_settings_save($pdo,$org,$locationId,['taxRate'=>0.085,'serviceChargeRate'=>0,'defaultServiceMode'=>'pickup'],$userId);
customer_account_start_session($pdo,$org,$userId);
$account=customer_account_current($pdo,$org);
ooici_assert(is_array($account)&&app_has_permission('online_ordering.use',$account),'Customer account must retain online ordering permission.');

$token='ci_'.bin2hex(random_bytes(18));
$order=online_order_submit_pickup($pdo,$org,$account,[
    'locationId'=>$locationId,'idempotencyKey'=>$token,'items'=>[['priceId'=>$price,'quantity'=>2]],'note'=>'Cut into squares',
]);
ooici_assert(empty($order['duplicate']),'First order submission must not be treated as a duplicate.');
ooici_assert(abs((float)$order['subtotal']-40.00)<.01,'Online order must re-price from canonical POS menu prices.');
ooici_assert(abs((float)$order['tax_amount']-3.40)<.01,'Online order tax must be calculated by native POS settings.');
ooici_assert(abs((float)$order['total_amount']-43.40)<.01,'Online order total must be calculated by native POS.');
ooici_assert((string)$order['payment_mode']==='pay_at_pickup','Initial online ordering must use pay-at-pickup without card storage.');
$checkId=(int)ooici_one($pdo,'SELECT pos_check_id FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$order['public_id']]);
ooici_assert($checkId>0,'Online order metadata must wrap a canonical POS check.');
ooici_assert((int)ooici_one($pdo,'SELECT customer_id FROM pos_checks WHERE organization_id=? AND id=?',[$org,$checkId])===$customerId,'Online POS check must be linked to the CRM customer.');
ooici_assert((string)ooici_one($pdo,'SELECT service_mode FROM pos_checks WHERE organization_id=? AND id=?',[$org,$checkId])==='pickup','Online order must use canonical pickup service mode.');
ooici_assert((int)ooici_one($pdo,'SELECT COUNT(*) FROM kds_order_items WHERE organization_id=? AND check_id=?',[$org,$checkId])===1,'Submitted online order must send its line to the existing KDS workflow.');
ooici_assert((int)ooici_one($pdo,"SELECT COUNT(*) FROM customer_inbox_recipients r JOIN customer_inbox_messages m ON m.id=r.message_id WHERE r.organization_id=? AND r.customer_id=? AND m.message_type='order_update'",[$org,$customerId])===1,'Online order must create a transactional Inbox confirmation.');

$duplicate=online_order_submit_pickup($pdo,$org,$account,[
    'locationId'=>$locationId,'idempotencyKey'=>$token,'items'=>[['priceId'=>$price,'quantity'=>2]],'note'=>'Cut into squares',
]);
ooici_assert(!empty($duplicate['duplicate']),'Repeated checkout token must return the existing order.');
ooici_assert((int)ooici_one($pdo,'SELECT COUNT(*) FROM online_orders WHERE organization_id=? AND customer_id=?',[$org,$customerId])===1,'Idempotent retry must not duplicate online order metadata.');
ooici_assert((int)ooici_one($pdo,"SELECT COUNT(*) FROM pos_checks WHERE organization_id=? AND customer_id=? AND service_mode='pickup'",[$org,$customerId])===1,'Idempotent retry must not duplicate the restaurant ticket.');

$promotion=customer_inbox_send_promotion($pdo,$org,[
    'title'=>'CI promotion','previewText'=>'A customer Inbox test','bodyText'=>'This offer exists only inside the signed-in customer Inbox.',
    'promoCode'=>'CI10','ctaLabel'=>'Order online','ctaUrl'=>'online-order.php','locationId'=>$locationId,
],$userId);
ooici_assert((int)$promotion['recipientCount']===1,'Promotion should materialize to the eligible customer account.');
ooici_assert(customer_inbox_unread_count($pdo,$org,$customerId)===2,'Order update and promotion should both begin unread.');
$messages=customer_inbox_messages($pdo,$org,$customerId,20);
ooici_assert(count($messages)===2,'Customer Inbox must return both transactional and promotional messages.');
customer_inbox_mark_read($pdo,$org,$customerId,(string)$promotion['publicId']);
ooici_assert(customer_inbox_unread_count($pdo,$org,$customerId)===1,'Mark-read must affect only the selected Inbox recipient.');

customer_inbox_preferences_save($pdo,$org,$customerId,false,$userId);
ooici_assert(customer_inbox_preferences($pdo,$org,$customerId)['promotionsEnabled']===false,'Customer must be able to disable future Inbox promotions.');
$blocked=false;
try{customer_inbox_send_promotion($pdo,$org,['title'=>'Blocked promotion','bodyText'=>'Should not deliver.'],$userId);}catch(InvalidArgumentException $e){$blocked=str_contains($e->getMessage(),'No eligible customer accounts');}
ooici_assert($blocked,'Promotion broadcast must respect the customer Inbox promotion preference.');
ooici_assert((int)ooici_one($pdo,"SELECT COUNT(*) FROM customer_inbox_recipients r JOIN customer_inbox_messages m ON m.id=r.message_id WHERE r.organization_id=? AND r.customer_id=? AND m.message_type='order_update'",[$org,$customerId])===1,'Turning off promotions must not remove transactional order updates.');

ooici_assert(customer_inbox_safe_url('online-order.php?location=central-pickup')==='online-order.php?location=central-pickup','Same-site promotion CTA must be accepted.');
ooici_assert(customer_inbox_safe_url('https://evil.example/')===null,'Absolute promotion CTA must be rejected.');

echo "online-ordering-inbox contract passed\n";
