<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/customer-account-core.php';
require_once __DIR__.'/../includes/online-order-core.php';
require_once __DIR__.'/../includes/online-order-lifecycle.php';
require_once __DIR__.'/../includes/pickup-fulfillment-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/pos-core.php';

$pdo=app_pdo();
function pfci_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function pfci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function pfci_updates(PDO $pdo,int $org,int $customer): int
{
    return (int)pfci_one($pdo,"SELECT COUNT(*) FROM customer_inbox_recipients r JOIN customer_inbox_messages m ON m.id=r.message_id AND m.organization_id=r.organization_id WHERE r.organization_id=? AND r.customer_id=? AND m.message_type='order_update'",[$org,$customer]);
}

pfci_assert(pickup_fulfillment_ready($pdo),'Pickup fulfillment migration must be installed.');
pfci_assert((int)pfci_one($pdo,"SELECT COUNT(*) FROM permissions WHERE permission_key='online_orders.fulfill'")===1,'Pickup fulfillment permission is missing.');

$api=(string)file_get_contents(__DIR__.'/../api/pickup-fulfillment.php');
$page=(string)file_get_contents(__DIR__.'/../pickup-fulfillment.php');
$js=(string)file_get_contents(__DIR__.'/../js/pickup-fulfillment.js');
pfci_assert(str_contains($api,"action!=='order.fulfill'"),'Pickup API must expose only the explicit fulfillment mutation.');
pfci_assert(str_contains($api,"operational_location_allowed(\$pdo,\$user,'online_orders.fulfill'"),'Pickup API must enforce location-scoped fulfillment permission.');
pfci_assert(str_contains($page,'Handed to Customer'),'Pickup workstation must expose the physical handoff concept.');
pfci_assert(str_contains($js,'Complete payment first'),'Pickup board must block pay-at-pickup handoff until payment is complete.');

$slug='pickup-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Pickup CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$location=location_save($pdo,$org,null,[
    'name'=>'Pickup Counter','public_slug'=>'pickup-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix',
    'dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>true,'pickup_lead_minutes'=>20,
]);
$locationId=(int)$location['id'];

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pickup Menu',?,'active',1)")->execute([$org,'pickup-menu-'.$slug]);
$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Pickup Pizza',?,1)")->execute([$org,$section,'pickup-pizza-'.$slug]);
$pizza=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',20.00,'USD',1)")->execute([$pizza]);
$pizzaPrice=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Pickup Salad',?,1)")->execute([$org,$section,'pickup-salad-'.$slug]);
$salad=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',10.00,'USD',1)")->execute([$salad]);
$saladPrice=(int)$pdo->lastInsertId();

$registration=customer_account_register($pdo,$org,[
    'firstName'=>'Pickup','lastName'=>'Customer','email'=>$slug.'@example.test','phone'=>'6025550188','password'=>'Pickup-CI!42','passwordConfirm'=>'Pickup-CI!42',
]);
$userId=(int)$registration['userId'];
$customerId=(int)pfci_one($pdo,'SELECT id FROM crm_customers WHERE organization_id=? AND user_id=?',[$org,$userId]);
pfci_assert($customerId>0,'Pickup customer must remain linked to CRM.');
pos_settings_save($pdo,$org,$locationId,['taxRate'=>0.085,'serviceChargeRate'=>0,'defaultServiceMode'=>'pickup'],$userId);
$station=kds_station_save($pdo,$org,$locationId,['name'=>'Pickup Line','slug'=>'pickup-line','targetSeconds'=>300,'sortOrder'=>1],$userId);
kds_route_save($pdo,$org,$locationId,$pizza,(string)$station['public_id'],$userId);
kds_route_save($pdo,$org,$locationId,$salad,(string)$station['public_id'],$userId);

customer_account_start_session($pdo,$org,$userId);
$account=customer_account_current($pdo,$org);
pfci_assert(is_array($account)&&app_has_permission('online_ordering.use',$account),'Customer must retain online ordering permission.');
$order=online_order_submit_pickup($pdo,$org,$account,[
    'locationId'=>$locationId,
    'idempotencyKey'=>'pickup_'.bin2hex(random_bytes(18)),
    'items'=>[['priceId'=>$pizzaPrice,'quantity'=>1],['priceId'=>$saladPrice,'quantity'=>1]],
    'note'=>'Pickup at front counter',
]);
$orderPublic=(string)$order['public_id'];
$checkPublic=(string)$order['check_public_id'];
$checkId=(int)pfci_one($pdo,'SELECT pos_check_id FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$orderPublic]);
pfci_assert($checkId>0,'Pickup order must wrap one canonical POS check.');

$queue=pickup_fulfillment_queue($pdo,$org,$locationId,'active',20);
pfci_assert(count($queue)===1,'Active pickup must appear in the queue.');
pfci_assert(($queue[0]['fulfillmentState']??'')==='in_kitchen','Fresh pickup must begin in the kitchen queue.');
pfci_assert(empty($queue[0]['physicalReady']),'Fresh pickup must not be physically ready.');

$blocked=false;
try{pickup_fulfillment_mark_handed($pdo,$org,$orderPublic,$userId);}catch(InvalidArgumentException $e){$blocked=str_contains($e->getMessage(),'complete kitchen ticket');}
pfci_assert($blocked,'Pickup handoff must be blocked before every kitchen line is ready.');

$q=$pdo->prepare('SELECT public_id FROM kds_order_items WHERE organization_id=? AND check_id=? ORDER BY id');$q->execute([$org,$checkId]);$kds=$q->fetchAll(PDO::FETCH_COLUMN);
pfci_assert(count($kds)===2,'Two pickup lines must remain two KDS items.');
foreach($kds as $public){
    $item=kds_transition($pdo,$org,(string)$public,'in_progress',$userId,'Pickup CI start');
    online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$item['check_id'],$userId);
    $item=kds_transition($pdo,$org,(string)$public,'ready',$userId,'Pickup CI ready');
    online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$item['check_id'],$userId);
}
$queue=pickup_fulfillment_queue($pdo,$org,$locationId,'ready',20);
pfci_assert(count($queue)===1&&($queue[0]['fulfillmentState']??'')==='ready','Fully ready ticket must enter the Ready pickup queue.');
pfci_assert(!empty($queue[0]['physicalReady']),'Ready pickup must be physically ready.');
pfci_assert(empty($queue[0]['paymentComplete']),'Pay-at-pickup order must still show payment due before tender.');
pfci_assert($queue[0]['readyAgeSeconds']!==null,'Ready pickup must expose Ready aging.');

$blocked=false;
try{pickup_fulfillment_mark_handed($pdo,$org,$orderPublic,$userId);}catch(InvalidArgumentException $e){$blocked=str_contains($e->getMessage(),'Payment is still due');}
pfci_assert($blocked,'Pay-at-pickup handoff must be blocked until POS payment is complete.');

$check=pos_check_details($pdo,$org,$checkPublic);
pos_record_tender($pdo,$org,$checkPublic,['tenderType'=>'cash','amount'=>(float)$check['balanceDue'],'tipAmount'=>0],$userId);
online_order_lifecycle_sync($pdo,$org,$checkPublic,$userId);
$queue=pickup_fulfillment_queue($pdo,$org,$locationId,'ready',20);
pfci_assert(count($queue)===1&&!empty($queue[0]['paymentComplete']),'Paid but unfulfilled order must remain in Ready queue until physical handoff.');
$updatesBefore=pfci_updates($pdo,$org,$customerId);
$result=pickup_fulfillment_mark_handed($pdo,$org,$orderPublic,$userId,'Verified customer name');
pfci_assert(empty($result['duplicate']),'First physical handoff must be recorded.');
pfci_assert((string)pfci_one($pdo,'SELECT fulfillment_note FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$orderPublic])==='Verified customer name','Handoff note must persist.');
pfci_assert((int)pfci_one($pdo,'SELECT fulfilled_by_user_id FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$orderPublic])===$userId,'Handoff actor must persist.');
pfci_assert((string)pfci_one($pdo,'SELECT fulfilled_at FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$orderPublic])!=='','Handoff timestamp must persist.');
pfci_assert(pfci_updates($pdo,$org,$customerId)===$updatesBefore+1,'Physical handoff must send one transactional customer update.');
pfci_assert((int)pfci_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='online_order.fulfilled' AND entity_id=?",[$org,$orderPublic])===1,'Physical handoff must be audited exactly once.');

$again=pickup_fulfillment_mark_handed($pdo,$org,$orderPublic,$userId,'Duplicate attempt');
pfci_assert(!empty($again['duplicate']),'Repeated handoff request must be idempotent.');
pfci_assert(pfci_updates($pdo,$org,$customerId)===$updatesBefore+1,'Repeated handoff must not duplicate customer notification.');
pfci_assert((int)pfci_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND action='online_order.fulfilled' AND entity_id=?",[$org,$orderPublic])===1,'Repeated handoff must not duplicate audit event.');

$fulfilled=pickup_fulfillment_queue($pdo,$org,$locationId,'fulfilled',20);
pfci_assert(count($fulfilled)===1&&($fulfilled[0]['fulfillmentState']??'')==='fulfilled','Fulfilled queue must retain completed physical handoff history.');
pfci_assert(pickup_fulfillment_queue($pdo,$org,$locationId,'active',20)===[],'Fulfilled order must leave the active pickup queue.');

echo "pickup-fulfillment=ok\n";
