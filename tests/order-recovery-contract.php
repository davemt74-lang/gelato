<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/customer-account-core.php';
require_once __DIR__.'/../includes/customer-inbox-core.php';
require_once __DIR__.'/../includes/online-order-core.php';
require_once __DIR__.'/../includes/online-order-lifecycle.php';
require_once __DIR__.'/../includes/order-recovery-core.php';
require_once __DIR__.'/../includes/order-recovery-reporting.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/pos-core.php';

function orc_assert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function orc_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function orc_updates(PDO $pdo,int $org,int $customerId): int {return (int)orc_one($pdo,"SELECT COUNT(*) FROM customer_inbox_recipients r JOIN customer_inbox_messages m ON m.id=r.message_id AND m.organization_id=r.organization_id WHERE r.organization_id=? AND r.customer_id=? AND m.message_type='order_update'",[$org,$customerId]);}

$pdo=app_pdo();
orc_assert(order_recovery_ready($pdo),'Order recovery schema must be installed.');
orc_assert(order_recovery_reporting_ready($pdo),'Order recovery reporting dependencies must be installed.');
orc_assert(online_order_ready($pdo),'Online ordering must be installed.');
orc_assert(kds_ready($pdo),'KDS must be installed.');
orc_assert(pos_ready($pdo),'POS must be installed.');

$slug='recovery-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Recovery CI '.$slug]);$org=(int)$pdo->lastInsertId();
$location=location_save($pdo,$org,null,['name'=>'Recovery Counter','public_slug'=>'recovery-'.$slug,'city'=>'Phoenix','state'=>'AZ','country_code'=>'US','timezone'=>'America/Phoenix','dine_in_enabled'=>true,'pickup_enabled'=>true,'delivery_enabled'=>false,'online_ordering_enabled'=>true,'pickup_lead_minutes'=>20]);$locationId=(int)$location['id'];
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Kitchen',?,'active',1)")->execute([$org,'kitchen-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Recovery Pizza',?,1)")->execute([$org,$section,'pizza-'.$slug]);$pizza=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',20.00,'USD',1)")->execute([$pizza]);$pizzaPrice=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Recovery Salad',?,1)")->execute([$org,$section,'salad-'.$slug]);$salad=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',10.00,'USD',1)")->execute([$salad]);$saladPrice=(int)$pdo->lastInsertId();

$email=$slug.'@example.test';$password='Recovery-CI!42';
$registration=customer_account_register($pdo,$org,['firstName'=>'Recovery','lastName'=>'Customer','email'=>$email,'phone'=>'6025550188','password'=>$password,'passwordConfirm'=>$password]);$userId=(int)$registration['userId'];
$customerId=(int)orc_one($pdo,'SELECT id FROM crm_customers WHERE organization_id=? AND user_id=?',[$org,$userId]);
pos_settings_save($pdo,$org,$locationId,['taxRate'=>0.085,'serviceChargeRate'=>0,'defaultServiceMode'=>'pickup'],$userId);
$station=kds_station_save($pdo,$org,$locationId,['name'=>'Recovery Line','slug'=>'recovery-line','targetSeconds'=>300,'sortOrder'=>1],$userId);
kds_route_save($pdo,$org,$locationId,$pizza,(string)$station['public_id'],$userId);kds_route_save($pdo,$org,$locationId,$salad,(string)$station['public_id'],$userId);
customer_account_start_session($pdo,$org,$userId);$account=customer_account_current($pdo,$org);orc_assert(is_array($account),'Customer session must start.');

$order=online_order_submit_pickup($pdo,$org,$account,['locationId'=>$locationId,'idempotencyKey'=>'recovery_'.bin2hex(random_bytes(18)),'items'=>[['priceId'=>$pizzaPrice,'quantity'=>1],['priceId'=>$saladPrice,'quantity'=>1]],'note'=>'Recovery contract']);
$orderPublic=(string)$order['public_id'];$checkPublic=(string)$order['check_public_id'];$checkId=(int)orc_one($pdo,'SELECT pos_check_id FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$orderPublic]);
orc_assert($checkId>0,'Online order must retain canonical POS check.');$initialUpdates=orc_updates($pdo,$org,$customerId);

$newPromise=(new DateTimeImmutable('+35 minutes'))->format('Y-m-d H:i:s');
$delay=order_recovery_delay($pdo,$org,$orderPublic,$newPromise,'Oven recovery delay',$userId,true);
orc_assert(($delay['exception']['exception_type']??'')==='delay','Delay must create a delay exception.');
orc_assert((int)orc_one($pdo,"SELECT COUNT(*) FROM order_exceptions WHERE organization_id=? AND online_order_id=? AND exception_type='delay'",[$org,(int)$delay['order']['online_order_id']])===1,'Delay exception must persist.');
orc_assert(orc_updates($pdo,$org,$customerId)===$initialUpdates+1,'Delay must send one customer update.');

$q=$pdo->prepare('SELECT public_id FROM kds_order_items WHERE organization_id=? AND check_id=? ORDER BY id');$q->execute([$org,$checkId]);$kds=$q->fetchAll(PDO::FETCH_COLUMN);orc_assert(count($kds)===2,'Two order lines must produce two kitchen items.');
$first=(string)$kds[0];$second=(string)$kds[1];
kds_transition($pdo,$org,$first,'in_progress',$userId,'start');kds_transition($pdo,$org,$first,'ready',$userId,'ready');
$remake=order_recovery_remake($pdo,$org,$orderPublic,$first,'First pizza failed quality check',$userId);
orc_assert((string)orc_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND public_id=?',[$org,$first])==='queued','Remake must re-fire the original kitchen line to queued.');
orc_assert((int)orc_one($pdo,"SELECT COUNT(*) FROM kds_order_events e JOIN kds_order_items k ON k.id=e.kds_order_item_id AND k.organization_id=e.organization_id WHERE e.organization_id=? AND k.public_id=? AND e.event_type='recovery_remake'",[$org,$first])===1,'Remake must leave a kitchen event audit trail.');
orc_assert(($remake['exception']['exception_type']??'')==='remake','Remake must create a recovery exception.');

$issue=order_recovery_create_exception($pdo,$org,$orderPublic,'missing_item','Missing dressing','Dressing was omitted from bag','high',$userId,['customerMessage'=>'We caught a missing dressing and are correcting it before handoff.','requiresManager'=>true]);
$issuePublic=(string)$issue['public_id'];$escalated=order_recovery_escalate($pdo,$org,$issuePublic,$userId,'Customer is waiting at counter');
orc_assert((string)$escalated['status']==='escalated','Exception must escalate.');
$queue=order_recovery_queue($pdo,$org,$locationId,'escalated',50);orc_assert(count(array_filter($queue,static fn(array $r):bool=>(string)$r['order_public_id']===$orderPublic))===1,'Escalated queue must contain the order.');
$resolved=order_recovery_resolve($pdo,$org,$issuePublic,$userId,'Dressing added and bag rechecked');orc_assert((string)$resolved['status']==='resolved','Exception must resolve with a note.');

$sub=order_recovery_create_exception($pdo,$org,$orderPublic,'substitution','Substitute side','Customer approved side substitution','medium',$userId,['customerMessage'=>'Your requested side substitution is confirmed.']);
orc_assert((string)$sub['exception_type']==='substitution','Substitution must be recorded without rewriting the original POS sale line.');

foreach([$first,$second] as $itemPublic){$status=(string)orc_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND public_id=?',[$org,$itemPublic]);if($status==='queued')kds_transition($pdo,$org,$itemPublic,'in_progress',$userId,'finish');$status=(string)orc_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND public_id=?',[$org,$itemPublic]);if($status==='in_progress')kds_transition($pdo,$org,$itemPublic,'ready',$userId,'finish');}
online_order_lifecycle_sync_by_check_id($pdo,$org,$checkId,$userId);
$check=pos_check_details($pdo,$org,$checkPublic);$check=pos_record_tender($pdo,$org,$checkPublic,['tenderType'=>'cash','amount'=>(float)$check['balanceDue'],'tipAmount'=>0],$userId);orc_assert((string)$check['status']==='paid','POS check must be paid before refund recording.');
online_order_lifecycle_sync($pdo,$org,$checkPublic,$userId);

$refund=order_recovery_refund($pdo,$org,$orderPublic,5.00,'cash','Service recovery refund','',$userId);
order_recovery_sync_native_sales_for_check($pdo,$org,$checkId);
orc_assert(abs((float)$refund['amount']-5.00)<0.001,'Refund amount must persist.');
orc_assert(abs((float)orc_one($pdo,"SELECT COALESCE(SUM(amount),0) FROM pos_refunds WHERE organization_id=? AND online_order_id=? AND status='recorded'",[$org,(int)$refund['order']['online_order_id']])-5.0)<0.001,'Refund ledger must total recorded refunds.');
$detail=order_recovery_detail($pdo,$org,$orderPublic);orc_assert(abs((float)$detail['refundable_amount']-((float)$detail['amount_paid']-5.0))<0.01,'Refundable amount must decrease after refund.');
$businessDate=(string)orc_one($pdo,'SELECT business_date FROM pos_checks WHERE organization_id=? AND id=?',[$org,$checkId]);
$periodRefunds=(float)orc_one($pdo,"SELECT refunds_amount FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider='gelato_pos' AND granularity='daily' AND service_period='all' AND period_start=? LIMIT 1",[$org,'id:'.$locationId,$businessDate]);
orc_assert(abs($periodRefunds-5.0)<0.001,'Native Sales Intelligence must expose the recorded refund.');
$baseNet=(float)orc_one($pdo,"SELECT COALESCE(SUM(GREATEST(0,subtotal-discount_amount)),0) FROM pos_checks WHERE organization_id=? AND location_id=? AND business_date=? AND status='paid'",[$org,$locationId,$businessDate]);
$reportedNet=(float)orc_one($pdo,"SELECT net_sales FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider='gelato_pos' AND granularity='daily' AND service_period='all' AND period_start=? LIMIT 1",[$org,'id:'.$locationId,$businessDate]);
orc_assert(abs($reportedNet-max(0,$baseNet-5.0))<0.01,'Native Sales Intelligence net sales must subtract recorded refunds.');
pos_rebuild_sales_day($pdo,$org,$locationId,$businessDate);
$periodRefundsAfterRebuild=(float)orc_one($pdo,"SELECT refunds_amount FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider='gelato_pos' AND granularity='daily' AND service_period='all' AND period_start=? LIMIT 1",[$org,'id:'.$locationId,$businessDate]);
$reportedNetAfterRebuild=(float)orc_one($pdo,"SELECT net_sales FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider='gelato_pos' AND granularity='daily' AND service_period='all' AND period_start=? LIMIT 1",[$org,'id:'.$locationId,$businessDate]);
orc_assert(abs($periodRefundsAfterRebuild-5.0)<0.001&&abs($reportedNetAfterRebuild-max(0,$baseNet-5.0))<0.01,'Later Native POS rebuilds must preserve refund-adjusted reporting.');
$blocked=false;try{order_recovery_refund($pdo,$org,$orderPublic,(float)$detail['refundable_amount']+1,'cash','Too much','',$userId);}catch(InvalidArgumentException){$blocked=true;}orc_assert($blocked,'Refunds above the remaining paid amount must be blocked.');

$noShow=order_recovery_create_exception($pdo,$org,$orderPublic,'no_show','Customer did not arrive','Order remained unclaimed past pickup window','high',$userId,['requiresManager'=>true]);orc_assert((string)$noShow['exception_type']==='no_show','No-show must become a first-class exception.');
$active=order_recovery_queue($pdo,$org,$locationId,'active',50);orc_assert(count(array_filter($active,static fn(array $r):bool=>(string)$r['order_public_id']===$orderPublic))===1,'Active recovery queue must retain unresolved recovery work.');

echo "order-recovery=ok\n";
