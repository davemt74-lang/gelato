<?php
declare(strict_types=1);

require_once __DIR__.'/agent-confirmation-core.php';
require_once __DIR__.'/operational-access.php';
require_once __DIR__.'/pickup-fulfillment-core.php';
require_once __DIR__.'/order-recovery-core.php';

final class OnlineOrderAgentPermissionException extends RuntimeException {}

function online_order_agent_ready(PDO $pdo): bool
{
    return pickup_fulfillment_ready($pdo) && order_recovery_ready($pdo);
}

function online_order_agent_view_permissions(): array
{
    return ['online_orders.fulfill','pos.use','pos.manage','kds.view','kds.update','kds.configure','crm.view','crm.manage','order_recovery.view','order_recovery.manage','order_recovery.refund'];
}

function online_order_agent_can_view(array $user): bool
{
    foreach(online_order_agent_view_permissions() as $permission){
        if(app_has_permission($permission,$user))return true;
    }
    return false;
}

function online_order_agent_location_allowed(PDO $pdo,array $user,int $locationId,string $mode='view'): bool
{
    if($locationId<1)return false;
    $permissions=match($mode){
        'fulfill'=>['online_orders.fulfill'],
        'recovery'=>['order_recovery.manage'],
        default=>online_order_agent_view_permissions(),
    };
    foreach($permissions as $permission){
        if(app_has_permission($permission,$user) && operational_location_allowed($pdo,$user,$permission,$locationId))return true;
    }
    return false;
}

function online_order_agent_require_view(array $user): void
{
    if(!online_order_agent_can_view($user))throw new OnlineOrderAgentPermissionException('Online order operations permission required.');
}

function online_order_agent_order_updated_at(PDO $pdo,int $organizationId,string $orderPublicId,bool $forUpdate=false): string
{
    $sql='SELECT updated_at FROM online_orders WHERE organization_id=? AND public_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$organizationId,trim($orderPublicId)]);$value=$q->fetchColumn();
    if($value===false)throw new InvalidArgumentException('Online order was not found.');
    return (string)$value;
}

function online_order_agent_order_by_check_number(PDO $pdo,int $organizationId,string $checkNumber): ?array
{
    $q=$pdo->prepare("SELECT oo.public_id FROM online_orders oo JOIN pos_checks c ON c.id=oo.pos_check_id AND c.organization_id=oo.organization_id WHERE oo.organization_id=? AND c.check_number=? ORDER BY oo.id DESC LIMIT 1");
    $q->execute([$organizationId,trim($checkNumber)]);$public=$q->fetchColumn();
    return $public!==false?pickup_fulfillment_order($pdo,$organizationId,(string)$public,false):null;
}

function online_order_agent_explicit_identifier(string $message): ?array
{
    $message=trim($message);
    if(preg_match('/\b(?:online|pickup|web)\s+order\s+(?:id\s+)?([A-Za-z0-9._:-]{5,80})\b/i',$message,$m))return ['type'=>'public','value'=>$m[1]];
    if(preg_match('/\b(?:order|check|ticket)\s*#\s*([A-Za-z0-9._:-]{2,80})\b/i',$message,$m))return ['type'=>'check','value'=>$m[1]];
    if(preg_match('/\bcheck\s+(?:number\s+)?([A-Za-z0-9._:-]{2,80})\b/i',$message,$m))return ['type'=>'check','value'=>$m[1]];
    return null;
}

function online_order_agent_resolve_order(PDO $pdo,int $organizationId,string $message,array $context=[]): ?array
{
    $explicit=online_order_agent_explicit_identifier($message);
    if($explicit){
        if($explicit['type']==='check')return online_order_agent_order_by_check_number($pdo,$organizationId,(string)$explicit['value']);
        return pickup_fulfillment_order($pdo,$organizationId,(string)$explicit['value'],false);
    }
    $selected=trim((string)($context['selectedOrderPublicId']??''));
    if($selected!=='')return pickup_fulfillment_order($pdo,$organizationId,$selected,false);
    return null;
}

function online_order_agent_queue(PDO $pdo,array $user,?int $locationId=null,int $limit=100): array
{
    $organizationId=(int)$user['organization_id'];
    $rows=pickup_fulfillment_queue($pdo,$organizationId,$locationId,'active',$limit);
    return array_values(array_filter($rows,static fn(array $row):bool=>online_order_agent_location_allowed($pdo,$user,(int)$row['location_id'],'view')));
}

function online_order_agent_queue_summary(PDO $pdo,array $user,?int $locationId=null): array
{
    $rows=online_order_agent_queue($pdo,$user,$locationId,200);
    $summary=['active'=>count($rows),'ready'=>0,'pastPromise'=>0,'paymentDue'=>0,'readyAging'=>0,'orders'=>[]];
    foreach($rows as $row){
        $state=(string)($row['fulfillmentState']??'');
        if($state==='ready')$summary['ready']++;
        if((int)($row['promiseDeltaSeconds']??1)<0)$summary['pastPromise']++;
        if($state==='ready'&&empty($row['paymentComplete']))$summary['paymentDue']++;
        if($state==='ready'&&(int)($row['readyAgeSeconds']??0)>=600)$summary['readyAging']++;
        if(count($summary['orders'])<12){
            $summary['orders'][]=[
                'orderPublicId'=>(string)$row['order_public_id'],'checkNumber'=>(string)$row['check_number'],'location'=>(string)$row['location_name'],
                'status'=>(string)($row['displayStatus']??$state),'requestedReadyAt'=>$row['requested_ready_at']??null,
                'paymentStatus'=>(string)($row['paymentStatus']??''),'pastPromise'=>(int)($row['promiseDeltaSeconds']??1)<0,
            ];
        }
    }
    return $summary;
}

function online_order_agent_recovery_summary(PDO $pdo,array $user,?int $locationId=null): array
{
    if(!order_recovery_can_view($user))return ['active'=>0,'escalated'=>0,'orders'=>[]];
    $organizationId=(int)$user['organization_id'];$rows=order_recovery_queue($pdo,$organizationId,$locationId,'active',100);
    $rows=array_values(array_filter($rows,static fn(array $row):bool=>online_order_agent_location_allowed($pdo,$user,(int)$row['location_id'],'view')));
    $escalated=0;$out=[];foreach($rows as $row){$escalated+=(int)$row['escalated_count'];if(count($out)<8)$out[]=['orderPublicId'=>$row['order_public_id'],'checkNumber'=>$row['check_number'],'activeExceptions'=>(int)$row['active_exception_count'],'escalated'=>(int)$row['escalated_count'],'latestType'=>$row['latest_type'],'latestSummary'=>$row['latest_summary']];}
    return ['active'=>array_sum(array_map(static fn(array $r):int=>(int)$r['active_exception_count'],$rows)),'escalated'=>$escalated,'orders'=>$out];
}

function online_order_agent_sources(?array $order=null): array
{
    $sources=[['label'=>'Pickup Fulfillment','href'=>'pickup-fulfillment.php'],['label'=>'Online Orders','href'=>'online-orders-admin.php'],['label'=>'Order Recovery','href'=>'order-recovery.php']];
    if($order){$id=rawurlencode((string)$order['order_public_id']);$sources[0]['href']='pickup-fulfillment.php?order='.$id;$sources[2]['href']='order-recovery.php?order='.$id;}
    return $sources;
}

function online_order_agent_read_order(PDO $pdo,array $user,array $order): array
{
    if(!online_order_agent_location_allowed($pdo,$user,(int)$order['location_id'],'view'))throw new OnlineOrderAgentPermissionException('This online order is outside your assigned restaurant locations.');
    $organizationId=(int)$user['organization_id'];$detail=order_recovery_can_view($user)?order_recovery_detail($pdo,$organizationId,(string)$order['order_public_id']):null;
    $state=pickup_fulfillment_state($order);$payment=pickup_fulfillment_payment_complete($order)?'complete':'due';
    $answer='Order '.$order['check_number'].' at '.$order['location_name'].' is '.pickup_fulfillment_display_state($state).'. Payment is '.$payment.'.';
    if(!empty($order['physicalReady'])&&empty($order['fulfilled_at']))$answer.=' The complete kitchen ticket is ready for pickup.';
    if($detail&&((int)($detail['activeExceptionCount']??0)>0))$answer.=' It has '.(int)$detail['activeExceptionCount'].' active recovery issue'.((int)$detail['activeExceptionCount']===1?'':'s').'.';
    return ['ok'=>true,'skill'=>'online_order_detail','answer'=>$answer,'data'=>['order'=>[
        'orderPublicId'=>$order['order_public_id'],'checkPublicId'=>$order['check_public_id'],'checkNumber'=>$order['check_number'],'locationId'=>(int)$order['location_id'],'location'=>$order['location_name'],
        'fulfillmentState'=>$state,'displayStatus'=>pickup_fulfillment_display_state($state),'physicalReady'=>(bool)$order['physicalReady'],'paymentComplete'=>(bool)$order['paymentComplete'],
        'fulfilledAt'=>$order['fulfilled_at']??null,'activeExceptions'=>(int)($detail['activeExceptionCount']??0),'escalatedExceptions'=>(int)($detail['escalatedCount']??0),
    ]],'sources'=>online_order_agent_sources($order)];
}

function online_order_agent_propose_fulfill(PDO $pdo,array $user,array $order,string $note=''): array
{
    if(!app_has_permission('online_orders.fulfill',$user)||!online_order_agent_location_allowed($pdo,$user,(int)$order['location_id'],'fulfill'))throw new OnlineOrderAgentPermissionException('Pickup fulfillment permission is required at this restaurant location.');
    if(!empty($order['fulfilled_at']))throw new InvalidArgumentException('This pickup order has already been handed to the customer.');
    if(empty($order['physicalReady']))throw new InvalidArgumentException('The complete kitchen ticket must be ready before customer handoff.');
    if((string)$order['payment_mode']==='pay_at_pickup'&&empty($order['paymentComplete']))throw new InvalidArgumentException('Payment is still due. Complete the POS tender before customer handoff.');
    $organizationId=(int)$user['organization_id'];$userId=(int)$user['id'];$expected=online_order_agent_order_updated_at($pdo,$organizationId,(string)$order['order_public_id']);
    $proposal=gac_pending_store('online_orders',$organizationId,$userId,'fulfill',['orderPublicId'=>(string)$order['order_public_id'],'expectedUpdatedAt'=>$expected,'note'=>mb_substr(trim($note),0,500,'UTF-8')],'Mark pickup order '.$order['check_number'].' as handed to the customer.');
    return gac_proposal_result($proposal,'online_order_fulfill',online_order_agent_sources($order));
}

function online_order_agent_parse_delay(string $message): ?array
{
    if(!preg_match('/\b(?:delay|move|push|change)\b.*?\b(?:pickup|ready|promise|promised)\b.*?\bto\s+(.+?)(?:\s+because\s+(.+))?$/iu',trim($message),$m))return null;
    $ready=trim($m[1]);$reason=trim((string)($m[2]??''));
    if($reason===''&&preg_match('/\breason\s*[:=-]\s*(.+)$/iu',$message,$r))$reason=trim($r[1]);
    return ['readyAt'=>$ready,'reason'=>$reason];
}

function online_order_agent_propose_delay(PDO $pdo,array $user,array $order,string $readyAt,string $reason): array
{
    if(!order_recovery_can_manage($user)||!online_order_agent_location_allowed($pdo,$user,(int)$order['location_id'],'recovery'))throw new OnlineOrderAgentPermissionException('Order recovery management permission is required at this restaurant location.');
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('Include a reason for changing the pickup promise.');
    try{$target=new DateTimeImmutable(trim($readyAt));}catch(Throwable){throw new InvalidArgumentException('Choose a valid new promised pickup time.');}
    if($target->getTimestamp()<=time())throw new InvalidArgumentException('The new promised pickup time must be in the future.');
    $organizationId=(int)$user['organization_id'];$userId=(int)$user['id'];$expected=online_order_agent_order_updated_at($pdo,$organizationId,(string)$order['order_public_id']);
    $formatted=$target->format(DATE_ATOM);
    $proposal=gac_pending_store('online_orders',$organizationId,$userId,'delay',['orderPublicId'=>(string)$order['order_public_id'],'expectedUpdatedAt'=>$expected,'readyAt'=>$formatted,'reason'=>$reason],'Move pickup order '.$order['check_number'].' promised-ready time to '.$target->format('M j, g:i A').' and notify the customer.');
    return gac_proposal_result($proposal,'online_order_delay',online_order_agent_sources($order));
}

function online_order_agent_execute_pending(PDO $pdo,array $user,array $proposal): array
{
    $organizationId=(int)$user['organization_id'];$userId=(int)$user['id'];$payload=(array)($proposal['payload']??[]);$public=trim((string)($payload['orderPublicId']??''));
    if($public==='')throw new InvalidArgumentException('The pending online-order action is missing its order.');
    $type=(string)($proposal['type']??'');
    $result=operational_db_wrap($pdo,function()use($pdo,$user,$organizationId,$userId,$payload,$public,$type):array{
        $order=pickup_fulfillment_order($pdo,$organizationId,$public,true);if(!$order)throw new InvalidArgumentException('Online order was not found.');
        $mode=$type==='fulfill'?'fulfill':'recovery';
        if(!online_order_agent_location_allowed($pdo,$user,(int)$order['location_id'],$mode))throw new OnlineOrderAgentPermissionException('This action is outside your assigned restaurant locations.');
        $fresh=online_order_agent_order_updated_at($pdo,$organizationId,$public,false);$expected=(string)($payload['expectedUpdatedAt']??'');
        if($expected===''||$fresh!==$expected)throw new DomainException('This online order changed after the proposal. Refresh the order and propose the action again.');
        if($type==='fulfill'){
            if(!app_has_permission('online_orders.fulfill',$user))throw new OnlineOrderAgentPermissionException('Pickup fulfillment permission is required.');
            return ['type'=>'fulfill','result'=>pickup_fulfillment_mark_handed($pdo,$organizationId,$public,$userId,(string)($payload['note']??''))];
        }
        if($type==='delay'){
            if(!order_recovery_can_manage($user))throw new OnlineOrderAgentPermissionException('Order recovery management permission is required.');
            return ['type'=>'delay','result'=>order_recovery_delay($pdo,$organizationId,$public,(string)$payload['readyAt'],(string)$payload['reason'],$userId,true)];
        }
        throw new InvalidArgumentException('Unsupported pending online-order action.');
    });
    gac_pending_clear('online_orders',$organizationId,$userId);
    $order=pickup_fulfillment_order($pdo,$organizationId,$public,false);
    return ['ok'=>true,'skill'=>'online_order_'.$result['type'],'answer'=>$result['type']==='fulfill'?'Pickup handoff recorded.':'Pickup promise updated and the recovery update was recorded.','data'=>['executed'=>true,'result'=>$result['result']],'sources'=>online_order_agent_sources($order?:null)];
}

function online_order_agent_handle(PDO $pdo,array $user,array $input): array
{
    online_order_agent_require_view($user);
    if(!online_order_agent_ready($pdo))throw new RuntimeException('Online ordering, pickup fulfillment, or order recovery migrations are not installed. Run upgrade.php.');
    $organizationId=(int)$user['organization_id'];$userId=(int)$user['id'];$message=trim((string)($input['message']??''));if($message==='')throw new InvalidArgumentException('Enter an online-order request.');
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];
    $pending=gac_pending_get('online_orders',$organizationId,$userId);
    if(gac_is_cancel($message)){
        if(!$pending)return ['ok'=>true,'skill'=>'online_order_confirmation','answer'=>'There is no pending online-order action to cancel.','data'=>['cancelled'=>false],'sources'=>online_order_agent_sources()];
        gac_pending_clear('online_orders',$organizationId,$userId);
        return ['ok'=>true,'skill'=>'online_order_confirmation','answer'=>'Pending online-order action cancelled. No order data was changed.','data'=>['cancelled'=>true],'sources'=>online_order_agent_sources()];
    }
    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending online-order action to confirm.');
        return online_order_agent_execute_pending($pdo,$user,$pending);
    }

    $order=online_order_agent_resolve_order($pdo,$organizationId,$message,$context);
    $lower=mb_strtolower($message,'UTF-8');
    if(preg_match('/\b(?:handed|hand off|handoff|picked up|pickup complete|fulfill|fulfilled)\b/u',$lower)){
        if(!$order)throw new InvalidArgumentException('Choose an online order before recording customer handoff.');
        return online_order_agent_propose_fulfill($pdo,$user,$order,(string)($input['note']??''));
    }
    $delay=online_order_agent_parse_delay($message);
    if($delay){
        if(!$order)throw new InvalidArgumentException('Choose an online order before changing the pickup promise.');
        return online_order_agent_propose_delay($pdo,$user,$order,$delay['readyAt'],$delay['reason']);
    }
    if($order)return online_order_agent_read_order($pdo,$user,$order);

    $locationId=(int)($context['locationId']??0);if($locationId<1)$locationId=null;
    if($locationId!==null&&!online_order_agent_location_allowed($pdo,$user,$locationId,'view'))throw new OnlineOrderAgentPermissionException('This restaurant location is outside your assigned online-order access.');
    $queue=online_order_agent_queue_summary($pdo,$user,$locationId);$recovery=online_order_agent_recovery_summary($pdo,$user,$locationId);
    $answer='There are '.$queue['active'].' active pickup order'.($queue['active']===1?'':'s').', '.$queue['ready'].' ready now, '.$queue['pastPromise'].' past promise, and '.$queue['paymentDue'].' ready with payment still due.';
    if($recovery['active']>0)$answer.=' Recovery has '.$recovery['active'].' active issue'.($recovery['active']===1?'':'s').', including '.$recovery['escalated'].' escalated.';
    return ['ok'=>true,'skill'=>'online_order_fulfillment_summary','answer'=>$answer,'data'=>['queue'=>$queue,'recovery'=>$recovery,'guardrails'=>['refunds'=>'handoff','payments'=>'POS','remakes'=>'Order Recovery/KDS']],'sources'=>online_order_agent_sources()];
}
