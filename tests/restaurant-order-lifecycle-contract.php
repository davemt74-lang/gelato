<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/customer-account-core.php';
require_once __DIR__.'/../includes/customer-inbox-core.php';
require_once __DIR__.'/../includes/online-order-core.php';
require_once __DIR__.'/../includes/online-order-lifecycle.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/kds-production.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/customer-crm-core.php';

$pdo=app_pdo();
function rol_assert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function rol_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function rol_order_updates(PDO $pdo,int $org,int $customerId): int
{
    return (int)rol_one($pdo,"SELECT COUNT(*) FROM customer_inbox_recipients r JOIN customer_inbox_messages m ON m.id=r.message_id AND m.organization_id=r.organization_id WHERE r.organization_id=? AND r.customer_id=? AND m.message_type='order_update'",[$org,$customerId]);
}
function rol_order_status(PDO $pdo,int $org,string $orderPublic): string
{
    return (string)rol_one($pdo,'SELECT status FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$orderPublic]);
}

rol_assert(online_order_ready($pdo),'Online ordering must be installed.');
rol_assert(kds_ready($pdo),'KDS must be installed.');
rol_assert(pos_ready($pdo),'Native POS must be installed.');

$posApi=(string)file_get_contents(__DIR__.'/../api/pos.php');
$kdsApi=(string)file_get_contents(__DIR__.'/../api/kds.php');
rol_assert(str_contains($posApi,"require_once __DIR__.'/../includes/online-order-lifecycle.php'"),'POS API must load the lifecycle synchronizer.');
rol_assert(substr_count($posApi,'online_order_lifecycle_sync_safe(')>=4,'POS API must sync void, kitchen send, tender, and cancellation mutations.');
rol_assert(str_contains($kdsApi,"require_once __DIR__.'/../includes/online-order-lifecycle.php'"),'KDS API must load the lifecycle synchronizer.');
rol_assert(substr_count($kdsApi,'online_order_lifecycle_sync')>=3,'KDS API must sync item transitions, recalls, and ticket actions.');

$slug='order-lifecycle-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Order Lifecycle CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$location=location_save($pdo,$org,null,[
    'name'=>'Lifecycle Pickup','public_slug'=>'lifecycle-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix',
    'dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>true,'pickup_lead_minutes'=>20,
]);
$locationId=(int)$location['id'];

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Kitchen',?,'active',1)")->execute([$org,'kitchen-'.$slug]);
$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Lifecycle Pizza',?,1)")->execute([$org,$section,'pizza-'.$slug]);
$pizza=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',20.00,'USD',1)")->execute([$pizza]);
$pizzaPrice=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Lifecycle Salad',?,1)")->execute([$org,$section,'salad-'.$slug]);
$salad=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',10.00,'USD',1)")->execute([$salad]);
$saladPrice=(int)$pdo->lastInsertId();

$email=$slug.'@example.test';
$password='Lifecycle-CI!42';
$registration=customer_account_register($pdo,$org,[
    'firstName'=>'Lifecycle','lastName'=>'Customer','email'=>$email,'phone'=>'6025550177','password'=>$password,'passwordConfirm'=>$password,
]);
$userId=(int)$registration['userId'];
$customerId=(int)rol_one($pdo,'SELECT id FROM crm_customers WHERE organization_id=? AND user_id=?',[$org,$userId]);
rol_assert($customerId>0,'Customer must remain linked to CRM.');
pos_settings_save($pdo,$org,$locationId,['taxRate'=>0.085,'serviceChargeRate'=>0,'defaultServiceMode'=>'pickup'],$userId);

$station=kds_station_save($pdo,$org,$locationId,['name'=>'Lifecycle Line','slug'=>'lifecycle-line','targetSeconds'=>300,'sortOrder'=>1],$userId);
kds_route_save($pdo,$org,$locationId,$pizza,(string)$station['public_id'],$userId);
kds_route_save($pdo,$org,$locationId,$salad,(string)$station['public_id'],$userId);

customer_account_start_session($pdo,$org,$userId);
$account=customer_account_current($pdo,$org);
rol_assert(is_array($account)&&app_has_permission('online_ordering.use',$account),'Customer must have online ordering permission.');

$order=online_order_submit_pickup($pdo,$org,$account,[
    'locationId'=>$locationId,
    'idempotencyKey'=>'lifecycle_'.bin2hex(random_bytes(18)),
    'items'=>[
        ['priceId'=>$pizzaPrice,'quantity'=>1,'instructions'=>'Well done','customizations'=>['note'=>'Extra crisp']],
        ['priceId'=>$saladPrice,'quantity'=>1,'instructions'=>'Dressing on side'],
    ],
    'note'=>'Call name at pickup',
]);
$orderPublic=(string)$order['public_id'];
$checkPublic=(string)$order['check_public_id'];
$checkId=(int)rol_one($pdo,'SELECT pos_check_id FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$orderPublic]);
rol_assert($checkId>0,'Online order must wrap one canonical POS check.');
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='in_kitchen','Submission sent to KDS must persist In kitchen status.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===1,'Submission must create exactly one received-order Inbox update.');

$q=$pdo->prepare('SELECT id,special_instructions FROM pos_check_items WHERE organization_id=? AND check_id=? AND status=\'active\' ORDER BY id');
$q->execute([$org,$checkId]);
$posLines=$q->fetchAll();
rol_assert(count($posLines)===2,'Two checkout lines must remain two POS lines.');
$firstInstructions=(string)$posLines[0]['special_instructions'];
rol_assert(str_contains($firstInstructions,'ITEM NOTE: Extra crisp'),'Structured item note must survive into POS instructions.');
rol_assert(str_contains($firstInstructions,'SPECIAL REQUEST: Well done'),'Line request must survive into POS instructions.');
rol_assert(str_contains($firstInstructions,'ORDER NOTE: Call name at pickup'),'Order note must survive into fulfillment instructions.');

$q=$pdo->prepare('SELECT k.public_id,k.check_id,i.special_instructions FROM kds_order_items k JOIN pos_check_items i ON i.id=k.pos_check_item_id AND i.organization_id=k.organization_id WHERE k.organization_id=? AND k.check_id=? ORDER BY k.id');
$q->execute([$org,$checkId]);
$kdsLines=$q->fetchAll();
rol_assert(count($kdsLines)===2,'Two POS lines must create two KDS items.');
rol_assert((string)$kdsLines[0]['special_instructions']===$firstInstructions,'KDS must render the exact canonical POS line instructions.');

$first=(string)$kdsLines[0]['public_id'];
$second=(string)$kdsLines[1]['public_id'];
$firstItem=kds_transition($pdo,$org,$first,'in_progress',$userId,'Lifecycle CI start first');
online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$firstItem['check_id'],$userId);
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='preparing','Starting any kitchen item must advance order to Preparing.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===2,'Preparing milestone must notify once.');

$firstItem=kds_transition($pdo,$org,$first,'ready',$userId,'Lifecycle CI first ready');
online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$firstItem['check_id'],$userId);
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='preparing','One ready line while another is queued must not mark the whole order Ready.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===2,'Partial readiness must not send a Ready notification.');

$secondItem=kds_transition($pdo,$org,$second,'in_progress',$userId,'Lifecycle CI start second');
online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$secondItem['check_id'],$userId);
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='preparing','Second line preparation must remain Preparing.');
$secondItem=kds_transition($pdo,$org,$second,'ready',$userId,'Lifecycle CI second ready');
online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$secondItem['check_id'],$userId);
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='ready','Order must become Ready only after every live kitchen line is ready.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===3,'Ready milestone must notify exactly once.');

$history=online_order_customer_orders($pdo,$org,$customerId,10);
rol_assert(($history[0]['displayStatus']??'')==='Ready','Customer order history must use the shared Ready lifecycle rule.');

$bump=kds_production_ticket_action($pdo,$org,$locationId,$checkPublic,'bump',null,$userId);
rol_assert((int)$bump['affected']===2,'Expo bump must complete both ready kitchen lines.');
online_order_lifecycle_sync($pdo,$org,$checkPublic,$userId);
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='kitchen_complete','Expo bump must persist Kitchen complete.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===3,'Kitchen complete after Ready must not duplicate the Ready notification.');

$recalled=kds_production_recall_item($pdo,$org,$first,$userId);
online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$recalled['check_id'],$userId);
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='ready','Recalling one completed line must return the order to Ready.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===3,'Recall must not duplicate the original Ready notification.');
$reworked=kds_transition($pdo,$org,$first,'in_progress',$userId,'Lifecycle CI rework');
online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$reworked['check_id'],$userId);
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='preparing','Reworking a recalled line must move the live status back to Preparing.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===3,'Rework must not duplicate the Preparing notification.');
$reworked=kds_transition($pdo,$org,$first,'ready',$userId,'Lifecycle CI rework ready');
online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$reworked['check_id'],$userId);
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='ready','Reworked line can return the order to Ready.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===3,'Ready after recall/rework must remain notification-idempotent.');
$rebump=kds_production_ticket_action($pdo,$org,$locationId,$checkPublic,'bump',null,$userId);
rol_assert((int)$rebump['affected']===1,'Second Expo bump must complete only the recalled line.');
online_order_lifecycle_sync($pdo,$org,$checkPublic,$userId);
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='kitchen_complete','Reworked ticket must return to Kitchen complete.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===3,'Second Kitchen complete must not duplicate Ready messaging.');

$check=pos_check_details($pdo,$org,$checkPublic);
$check=pos_record_tender($pdo,$org,$checkPublic,['tenderType'=>'cash','amount'=>(float)$check['balanceDue'],'tipAmount'=>0],$userId);
rol_assert((string)$check['status']==='paid','Final tender must close the canonical POS check.');
online_order_lifecycle_sync($pdo,$org,$checkPublic,$userId);
rol_assert(rol_order_status($pdo,$org,$orderPublic)==='completed','Paid POS check must complete the online order.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===4,'Completed milestone must notify exactly once.');
online_order_lifecycle_sync($pdo,$org,$checkPublic,$userId);
rol_assert(rol_order_updates($pdo,$org,$customerId)===4,'Repeated lifecycle sync must be notification-idempotent.');

$metrics=crm_metrics($pdo,$org,$customerId);
rol_assert((int)$metrics['visits']===1,'Paid online order must appear in CRM purchase history.');
rol_assert((float)$metrics['lifetimeSpend']>0,'Paid online order must contribute to CRM lifetime spend.');
rol_assert((int)rol_one($pdo,"SELECT COUNT(*) FROM sales_periods WHERE organization_id=? AND source_provider='gelato_pos' AND tickets>0",[$org])>=1,'Paid online order must post to Sales Intelligence.');
$history=online_order_customer_orders($pdo,$org,$customerId,10);
rol_assert(($history[0]['displayStatus']??'')==='Completed','Customer history must show Completed after payment.');

$cancel=online_order_submit_pickup($pdo,$org,$account,[
    'locationId'=>$locationId,
    'idempotencyKey'=>'cancel_'.bin2hex(random_bytes(18)),
    'items'=>[['priceId'=>$pizzaPrice,'quantity'=>1]],
    'note'=>'Cancel branch',
]);
$cancelPublic=(string)$cancel['public_id'];
$cancelCheck=(string)$cancel['check_public_id'];
$updatesBeforeCancel=rol_order_updates($pdo,$org,$customerId);
$base=pos_check_base($pdo,$org,$cancelCheck,false);
pos_cancel_check($pdo,$org,$cancelCheck,'Lifecycle CI cancellation',$userId);
kds_cancel_check($pdo,$org,(int)$base['id'],'Lifecycle CI cancellation',$userId);
online_order_lifecycle_sync($pdo,$org,$cancelCheck,$userId);
rol_assert(rol_order_status($pdo,$org,$cancelPublic)==='cancelled','Cancelled POS check must cancel the online order.');
rol_assert(rol_order_updates($pdo,$org,$customerId)===$updatesBeforeCancel+1,'Cancellation must send one transactional update.');
online_order_lifecycle_sync($pdo,$org,$cancelCheck,$userId);
rol_assert(rol_order_updates($pdo,$org,$customerId)===$updatesBeforeCancel+1,'Repeated cancellation sync must not duplicate the update.');

echo "restaurant-order-lifecycle=ok\n";
