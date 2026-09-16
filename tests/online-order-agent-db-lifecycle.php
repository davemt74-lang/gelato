<?php
declare(strict_types=1);

require __DIR__.'/pickup-fulfillment-contract.php';
require_once __DIR__.'/../includes/online-order-agent-core.php';
require_once __DIR__.'/../includes/agent-node-registry.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';

function ooaci_assert(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
function ooaci_one(PDO $pdo,string $sql,array $args=[]): mixed { $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn(); }

$staff=[
    'id'=>$userId,
    'organization_id'=>$org,
    'membership_id'=>0,
    'role_slug'=>'owner',
    'permissions'=>['*'],
];

$node=gaw_agent_node('online_orders');
ooaci_assert(is_array($node),'Online order Agent node must be registered.');
ooaci_assert(($node['route']??'')==='api/online-order-agent.php','Online order Agent node route is incorrect.');
ooaci_assert(($node['mode']??'')==='read_confirmed_write','Online order Agent must use read_confirmed_write mode.');
$route=gaw_route($staff,'Show me the online order queue.');
ooaci_assert(($route['route']??'')==='api/online-order-agent.php','Explicit online-order intent must route to the Online Order Agent.');
$generic=gaw_route($staff,'Show me this order.');
ooaci_assert(($generic['route']??'')!=='api/online-order-agent.php','Generic order language must not globally hijack routing.');

$core=(string)file_get_contents(__DIR__.'/../includes/online-order-agent-core.php');
ooaci_assert(!preg_match('/pos_refunds\s*\(|INSERT\s+INTO\s+pos_refunds|UPDATE\s+pos_refunds|pos_record_tender|order_recovery_refund|order_recovery_remake/i',$core),'Online Order Agent must not mutate refunds, tenders, or remakes directly.');
ooaci_assert(str_contains($core,"gac_pending_store('online_orders'"),'Online Order Agent writes must use shared confirmation storage.');
ooaci_assert(str_contains($core,'expectedUpdatedAt'),'Online Order Agent proposals must carry a stale-write token.');

// Build a second canonical pickup order for Agent fulfillment lifecycle testing.
$order2=online_order_submit_pickup($pdo,$org,$account,[
    'locationId'=>$locationId,
    'idempotencyKey'=>'oo_agent_fulfill_'.bin2hex(random_bytes(16)),
    'items'=>[['priceId'=>$pizzaPrice,'quantity'=>1]],
    'note'=>'Agent fulfillment test',
]);
$order2Public=(string)$order2['public_id'];
$check2Public=(string)$order2['check_public_id'];
$check2Id=(int)ooaci_one($pdo,'SELECT pos_check_id FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$order2Public]);
$q=$pdo->prepare('SELECT public_id FROM kds_order_items WHERE organization_id=? AND check_id=? ORDER BY id');$q->execute([$org,$check2Id]);$kds2=$q->fetchAll(PDO::FETCH_COLUMN);
ooaci_assert(count($kds2)===1,'Agent fulfillment fixture must have one KDS item.');
$item=kds_transition($pdo,$org,(string)$kds2[0],'in_progress',$userId,'Agent CI start');
online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$item['check_id'],$userId);
$item=kds_transition($pdo,$org,(string)$kds2[0],'ready',$userId,'Agent CI ready');
online_order_lifecycle_sync_by_check_id($pdo,$org,(int)$item['check_id'],$userId);
$check2=pos_check_details($pdo,$org,$check2Public);
pos_record_tender($pdo,$org,$check2Public,['tenderType'=>'cash','amount'=>(float)$check2['balanceDue'],'tipAmount'=>0],$userId);
online_order_lifecycle_sync($pdo,$org,$check2Public,$userId);

$selected=['module'=>'pickup_fulfillment','selectedOrderPublicId'=>$order2Public,'locationId'=>$locationId];
$read=online_order_agent_handle($pdo,$staff,['message'=>'What is going on with this order?','pageContext'=>$selected]);
ooaci_assert(($read['skill']??'')==='online_order_detail','Selected pickup order must re-resolve canonically on the server.');
ooaci_assert(!empty($read['data']['order']['physicalReady'])&&!empty($read['data']['order']['paymentComplete']),'Ready paid pickup must be reported accurately.');

// An invalid explicit identifier is authoritative and must never fall back to selected page context.
$explicitMiss=online_order_agent_handle($pdo,$staff,['message'=>'Show online order id missing-order-99999','pageContext'=>$selected]);
ooaci_assert(($explicitMiss['skill']??'')==='online_order_fulfillment_summary','Invalid explicit order ID must not fall back to the selected page-context order.');

$proposal=online_order_agent_handle($pdo,$staff,['message'=>'Mark this pickup as handed to the customer.','pageContext'=>$selected]);
ooaci_assert(!empty($proposal['data']['requiresConfirmation']),'Pickup handoff must be proposed, not executed immediately.');
ooaci_assert((string)ooaci_one($pdo,'SELECT COALESCE(fulfilled_at,\'\') FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$order2Public])==='','Proposal must not mutate fulfillment state.');
ooaci_assert(gaw_pending_action_node($org,$userId)==='online_orders','Shared pending-action registry must identify the Online Order Agent.');
$cancel=online_order_agent_handle($pdo,$staff,['message'=>'Cancel']);
ooaci_assert(!empty($cancel['data']['cancelled']),'Cancel must discard the pending pickup action.');
ooaci_assert((string)ooaci_one($pdo,'SELECT COALESCE(fulfilled_at,\'\') FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$order2Public])==='','Cancelled proposal must not mutate fulfillment state.');

// Stale-write rejection.
online_order_agent_handle($pdo,$staff,['message'=>'Mark this pickup as handed to the customer.','pageContext'=>$selected]);
$pdo->prepare("UPDATE online_orders SET updated_at=DATE_ADD(NOW(6), INTERVAL 2 SECOND) WHERE organization_id=? AND public_id=?")->execute([$org,$order2Public]);
$stale=false;
try{ online_order_agent_handle($pdo,$staff,['message'=>'Confirm']); }catch(DomainException $e){ $stale=str_contains($e->getMessage(),'changed after the proposal'); }
ooaci_assert($stale,'Stale pickup proposal must be rejected.');
online_order_agent_handle($pdo,$staff,['message'=>'Cancel']);

$proposal=online_order_agent_handle($pdo,$staff,['message'=>'Mark this pickup as handed to the customer.','pageContext'=>$selected]);
$done=online_order_agent_handle($pdo,$staff,['message'=>'Confirm']);
ooaci_assert(!empty($done['data']['executed']),'Confirmed pickup handoff must execute.');
ooaci_assert((string)ooaci_one($pdo,'SELECT COALESCE(fulfilled_at,\'\') FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$order2Public])!=='','Confirmed pickup handoff must persist canonical fulfilled_at.');

// Build a third order for confirmed promise-delay/recovery behavior.
$order3=online_order_submit_pickup($pdo,$org,$account,[
    'locationId'=>$locationId,
    'idempotencyKey'=>'oo_agent_delay_'.bin2hex(random_bytes(16)),
    'items'=>[['priceId'=>$saladPrice,'quantity'=>1]],
    'note'=>'Agent delay test',
]);
$order3Public=(string)$order3['public_id'];
$originalReady=(string)ooaci_one($pdo,'SELECT requested_ready_at FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$order3Public]);
$delayContext=['module'=>'order_recovery','selectedOrderPublicId'=>$order3Public,'locationId'=>$locationId];
$delayProposal=online_order_agent_handle($pdo,$staff,['message'=>'Delay pickup promise to 2099-01-01 7:00 PM because kitchen backup','pageContext'=>$delayContext]);
ooaci_assert(!empty($delayProposal['data']['requiresConfirmation']),'Promise delay must require confirmation.');
ooaci_assert((string)ooaci_one($pdo,'SELECT requested_ready_at FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$order3Public])===$originalReady,'Delay proposal must not mutate promised time.');
$refundsBefore=(int)ooaci_one($pdo,'SELECT COUNT(*) FROM pos_refunds WHERE organization_id=?',[$org]);
$delayDone=online_order_agent_handle($pdo,$staff,['message'=>'Confirm']);
ooaci_assert(!empty($delayDone['data']['executed']),'Confirmed pickup delay must execute.');
$newReady=(string)ooaci_one($pdo,'SELECT requested_ready_at FROM online_orders WHERE organization_id=? AND public_id=?',[$org,$order3Public]);
ooaci_assert($newReady!==''&&$newReady!==$originalReady,'Confirmed delay must update canonical requested_ready_at.');
ooaci_assert((int)ooaci_one($pdo,"SELECT COUNT(*) FROM order_exceptions e JOIN online_orders oo ON oo.id=e.online_order_id AND oo.organization_id=e.organization_id WHERE e.organization_id=? AND oo.public_id=? AND e.exception_type='delay'",[$org,$order3Public])===1,'Confirmed delay must create one canonical recovery exception.');
ooaci_assert((int)ooaci_one($pdo,'SELECT COUNT(*) FROM pos_refunds WHERE organization_id=?',[$org])===$refundsBefore,'Online Order Agent must not create refund records.');

$summary=online_order_agent_handle($pdo,$staff,['message'=>'Show the pickup queue','pageContext'=>['module'=>'pickup_fulfillment','locationId'=>$locationId]]);
ooaci_assert(($summary['skill']??'')==='online_order_fulfillment_summary','Pickup queue summary must remain readable after writes.');

echo "online-order-agent=ok\n";
