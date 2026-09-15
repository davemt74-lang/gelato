<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/customer-inbox-core.php';
require_once __DIR__.'/kds-core.php';
require_once __DIR__.'/online-order-lifecycle.php';

function order_recovery_ready(PDO $pdo): bool
{
    foreach(['order_exceptions','pos_refunds','online_orders','pos_checks','kds_order_items'] as $table){
        try{$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;}catch(Throwable){return false;}
    }
    return true;
}

function order_recovery_public_id(string $prefix='recovery'): string
{
    return $prefix.'-'.bin2hex(random_bytes(12));
}

function order_recovery_can_view(array $user): bool
{
    foreach(['order_recovery.view','order_recovery.manage','order_recovery.refund'] as $permission)if(app_has_permission($permission,$user))return true;
    return false;
}

function order_recovery_can_manage(array $user): bool { return app_has_permission('order_recovery.manage',$user); }
function order_recovery_can_refund(array $user): bool { return app_has_permission('order_recovery.refund',$user); }

function order_recovery_types(): array
{
    return ['delay','remake','substitution','missing_item','quality','payment_issue','customer_waiting','no_show','comp','refund','other'];
}

function order_recovery_severity(string $value): string
{
    $value=strtolower(trim($value));
    return in_array($value,['low','medium','high','critical'],true)?$value:'medium';
}

function order_recovery_order(PDO $pdo,int $org,string $orderPublicId,bool $forUpdate=false): array
{
    $sql="SELECT oo.id online_order_id,oo.public_id order_public_id,oo.location_id,oo.customer_id,oo.pos_check_id,oo.payment_mode,oo.status order_status,oo.requested_ready_at,oo.fulfilled_at,
        c.public_id check_public_id,c.check_number,c.status check_status,c.total_amount,c.amount_paid,c.business_date,c.service_mode,
        l.name location_name,cc.display_name customer_name,cc.email customer_email,cc.phone customer_phone
        FROM online_orders oo
        JOIN pos_checks c ON c.id=oo.pos_check_id AND c.organization_id=oo.organization_id
        JOIN locations l ON l.id=oo.location_id AND l.organization_id=oo.organization_id
        JOIN crm_customers cc ON cc.id=oo.customer_id AND cc.organization_id=oo.organization_id
        WHERE oo.organization_id=? AND oo.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,trim($orderPublicId)]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Online order was not found.');
    $r=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_refunds WHERE organization_id=? AND online_order_id=? AND status='recorded'");$r->execute([$org,(int)$row['online_order_id']]);
    $row['refund_total']=round((float)$r->fetchColumn(),2);
    $row['refundable_amount']=round(max(0,(float)$row['amount_paid']-(float)$row['refund_total']),2);
    return $row;
}

function order_recovery_exceptions(PDO $pdo,int $org,int $onlineOrderId): array
{
    $q=$pdo->prepare("SELECT e.public_id,e.exception_type,e.status,e.severity,e.summary,e.details,e.customer_message,e.recovery_amount,e.requires_manager,e.created_at,e.escalated_at,e.resolved_at,e.resolution_note,
        cu.display_name created_by_name,eu.display_name escalated_by_name,ru.display_name resolved_by_name,
        ki.public_id kds_item_public_id,pi.item_name_snapshot pos_item_name
        FROM order_exceptions e
        JOIN users cu ON cu.id=e.created_by
        LEFT JOIN users eu ON eu.id=e.escalated_by
        LEFT JOIN users ru ON ru.id=e.resolved_by
        LEFT JOIN kds_order_items ki ON ki.id=e.kds_order_item_id AND ki.organization_id=e.organization_id
        LEFT JOIN pos_check_items pi ON pi.id=e.pos_check_item_id AND pi.organization_id=e.organization_id
        WHERE e.organization_id=? AND e.online_order_id=? ORDER BY CASE e.status WHEN 'escalated' THEN 0 WHEN 'open' THEN 1 ELSE 2 END,e.created_at DESC,e.id DESC");
    $q->execute([$org,$onlineOrderId]);return $q->fetchAll();
}

function order_recovery_kitchen_items(PDO $pdo,int $org,int $checkId): array
{
    $q=$pdo->prepare("SELECT k.public_id,k.status,k.station_id,s.name station_name,k.sent_at,k.started_at,k.ready_at,k.completed_at,
        i.id pos_check_item_id,i.item_name_snapshot,i.option_name_snapshot,i.quantity,i.special_instructions,i.status pos_item_status
        FROM kds_order_items k
        JOIN pos_check_items i ON i.id=k.pos_check_item_id AND i.organization_id=k.organization_id
        LEFT JOIN kds_stations s ON s.id=k.station_id AND s.organization_id=k.organization_id
        WHERE k.organization_id=? AND k.check_id=? ORDER BY k.id");
    $q->execute([$org,$checkId]);return $q->fetchAll();
}

function order_recovery_detail(PDO $pdo,int $org,string $orderPublicId): array
{
    $order=order_recovery_order($pdo,$org,$orderPublicId,false);
    $order['exceptions']=order_recovery_exceptions($pdo,$org,(int)$order['online_order_id']);
    $order['kitchenItems']=order_recovery_kitchen_items($pdo,$org,(int)$order['pos_check_id']);
    $order['activeExceptionCount']=count(array_filter($order['exceptions'],static fn(array $e):bool=>in_array((string)$e['status'],['open','escalated'],true)));
    $order['escalatedCount']=count(array_filter($order['exceptions'],static fn(array $e):bool=>(string)$e['status']==='escalated'));
    return $order;
}

function order_recovery_queue(PDO $pdo,int $org,?int $locationId=null,string $filter='active',int $limit=200): array
{
    if(!order_recovery_ready($pdo))return [];
    $limit=max(1,min(300,$limit));$args=[$org];$where=['oo.organization_id=?'];
    if($locationId!==null&&$locationId>0){$where[]='oo.location_id=?';$args[]=$locationId;}
    if($filter==='active')$where[]="COALESCE(ex.active_count,0)>0";
    elseif($filter==='escalated')$where[]="COALESCE(ex.escalated_count,0)>0";
    elseif($filter==='ready')$where[]="oo.fulfilled_at IS NULL AND oo.status IN ('ready','kitchen_complete')";
    elseif($filter==='completed')$where[]="c.status='paid'";
    $sql="SELECT oo.public_id order_public_id,oo.status order_status,oo.requested_ready_at,oo.fulfilled_at,oo.submitted_at,
        c.public_id check_public_id,c.check_number,c.status check_status,c.total_amount,c.amount_paid,l.id location_id,l.name location_name,
        cc.display_name customer_name,cc.email customer_email,cc.phone customer_phone,
        COALESCE(ex.active_count,0) active_exception_count,COALESCE(ex.escalated_count,0) escalated_count,ex.latest_type,ex.latest_summary,ex.latest_created_at,
        COALESCE(rf.refund_total,0) refund_total
        FROM online_orders oo
        JOIN pos_checks c ON c.id=oo.pos_check_id AND c.organization_id=oo.organization_id
        JOIN locations l ON l.id=oo.location_id AND l.organization_id=oo.organization_id
        JOIN crm_customers cc ON cc.id=oo.customer_id AND cc.organization_id=oo.organization_id
        LEFT JOIN (
          SELECT online_order_id,
            SUM(status IN ('open','escalated')) active_count,
            SUM(status='escalated') escalated_count,
            SUBSTRING_INDEX(GROUP_CONCAT(exception_type ORDER BY created_at DESC,id DESC),',',1) latest_type,
            SUBSTRING_INDEX(GROUP_CONCAT(REPLACE(summary,',',';') ORDER BY created_at DESC,id DESC),',',1) latest_summary,
            MAX(created_at) latest_created_at
          FROM order_exceptions WHERE organization_id=? GROUP BY online_order_id
        ) ex ON ex.online_order_id=oo.id
        LEFT JOIN (
          SELECT online_order_id,SUM(amount) refund_total FROM pos_refunds WHERE organization_id=? AND status='recorded' GROUP BY online_order_id
        ) rf ON rf.online_order_id=oo.id
        WHERE ".implode(' AND ',$where)."
        ORDER BY COALESCE(ex.escalated_count,0) DESC,COALESCE(ex.active_count,0) DESC,COALESCE(ex.latest_created_at,oo.submitted_at) DESC LIMIT {$limit}";
    array_unshift($args,$org,$org);
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll();
}

function order_recovery_notify(PDO $pdo,int $org,array $order,string $title,string $body,int $actorUserId): bool
{
    try{
        customer_inbox_send_direct($pdo,$org,(int)$order['customer_id'],[
            'messageType'=>'order_update','locationId'=>(int)$order['location_id'],'title'=>$title,
            'previewText'=>mb_substr($body,0,300,'UTF-8'),'bodyText'=>$body,'ctaLabel'=>'View order history','ctaUrl'=>'customer-account.php#orders',
        ],$actorUserId);
        return true;
    }catch(Throwable $e){
        try{app_audit($pdo,$org,$actorUserId,'online_order.recovery_notification_failed','online_order',(string)$order['order_public_id'],null,['error'=>mb_substr($e->getMessage(),0,500,'UTF-8')]);}catch(Throwable){}
        return false;
    }
}

function order_recovery_create_exception(PDO $pdo,int $org,string $orderPublicId,string $type,string $summary,string $details,string $severity,int $actorUserId,array $options=[]): array
{
    if(!order_recovery_ready($pdo))throw new RuntimeException('Order recovery migration is not installed. Run upgrade.php.');
    $type=strtolower(trim($type));if(!in_array($type,order_recovery_types(),true))throw new InvalidArgumentException('Choose a supported order exception type.');
    $summary=mb_substr(trim($summary),0,500,'UTF-8');if($summary==='')throw new InvalidArgumentException('Describe the order issue.');
    $details=mb_substr(trim($details),0,5000,'UTF-8');$severity=order_recovery_severity($severity);
    $customerMessage=mb_substr(trim((string)($options['customerMessage']??'')),0,1000,'UTF-8');
    $amount=round(max(0,(float)($options['recoveryAmount']??0)),2);$requiresManager=!empty($options['requiresManager'])||in_array($severity,['high','critical'],true);
    $order=order_recovery_order($pdo,$org,$orderPublicId,false);
    if((string)$order['check_status']==='cancelled'&&$type!=='refund')throw new InvalidArgumentException('Cancelled orders cannot receive new operational exceptions.');
    $posItemId=isset($options['posCheckItemId'])?(int)$options['posCheckItemId']:null;$kdsItemId=isset($options['kdsOrderItemId'])?(int)$options['kdsOrderItemId']:null;
    $public=order_recovery_public_id('exception');
    $pdo->prepare("INSERT INTO order_exceptions (organization_id,location_id,online_order_id,pos_check_id,pos_check_item_id,kds_order_item_id,public_id,exception_type,status,severity,summary,details,customer_message,recovery_amount,requires_manager,created_by) VALUES (?,?,?,?,?,?,?,?,'open',?,?,?,?,?,?,?)")
        ->execute([$org,(int)$order['location_id'],(int)$order['online_order_id'],(int)$order['pos_check_id'],$posItemId?:null,$kdsItemId?:null,$public,$type,$severity,$summary,$details?:null,$customerMessage?:null,$amount,$requiresManager?1:0,$actorUserId]);
    $notified=false;if($customerMessage!=='')$notified=order_recovery_notify($pdo,$org,$order,'Update for order '.$order['check_number'],$customerMessage,$actorUserId);
    try{app_audit($pdo,$org,$actorUserId,'online_order.exception_created','order_exception',$public,null,['orderPublicId'=>$orderPublicId,'type'=>$type,'severity'=>$severity,'requiresManager'=>$requiresManager,'notified'=>$notified]);}catch(Throwable){}
    $q=$pdo->prepare('SELECT * FROM order_exceptions WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$public]);return $q->fetch()?:[];
}

function order_recovery_delay(PDO $pdo,int $org,string $orderPublicId,string $readyAt,string $reason,int $actorUserId,bool $notifyCustomer=true): array
{
    $order=order_recovery_order($pdo,$org,$orderPublicId,true);
    if(!empty($order['fulfilled_at']))throw new InvalidArgumentException('A fulfilled order cannot be delayed.');
    if(in_array((string)$order['check_status'],['cancelled'],true))throw new InvalidArgumentException('A cancelled order cannot be delayed.');
    try{$target=new DateTimeImmutable($readyAt);}catch(Throwable){throw new InvalidArgumentException('Choose a valid new promised pickup time.');}
    if($target->getTimestamp()<=time())throw new InvalidArgumentException('The new promised pickup time must be in the future.');
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('A delay reason is required.');
    $formatted=$target->format('Y-m-d H:i:s.u');
    $pdo->prepare('UPDATE online_orders SET requested_ready_at=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$formatted,$org,(int)$order['online_order_id']]);
    $message=$notifyCustomer?'We need a little more time with your order. Your new estimated pickup time is '.$target->format('g:i A').'. '.$reason:'';
    $exception=order_recovery_create_exception($pdo,$org,$orderPublicId,'delay','Pickup promise moved to '.$target->format('g:i A'),$reason,'high',$actorUserId,['customerMessage'=>$message,'requiresManager'=>false]);
    return ['order'=>order_recovery_detail($pdo,$org,$orderPublicId),'exception'=>$exception];
}

function order_recovery_remake(PDO $pdo,int $org,string $orderPublicId,string $kdsPublicId,string $reason,int $actorUserId): array
{
    $order=order_recovery_order($pdo,$org,$orderPublicId,true);
    if(!empty($order['fulfilled_at']))throw new InvalidArgumentException('A fulfilled pickup cannot be remade from the active-order recovery queue.');
    if((string)$order['check_status']==='cancelled')throw new InvalidArgumentException('A cancelled order cannot be remade.');
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('A remake reason is required.');
    $q=$pdo->prepare("SELECT k.*,i.item_name_snapshot FROM kds_order_items k JOIN pos_check_items i ON i.id=k.pos_check_item_id AND i.organization_id=k.organization_id WHERE k.organization_id=? AND k.check_id=? AND k.public_id=? LIMIT 1 FOR UPDATE");
    $q->execute([$org,(int)$order['pos_check_id'],trim($kdsPublicId)]);$item=$q->fetch();if(!$item)throw new InvalidArgumentException('Kitchen item was not found on this order.');
    if((string)$item['status']==='cancelled')throw new InvalidArgumentException('A cancelled kitchen item cannot be remade.');
    if($item['station_id']===null)throw new InvalidArgumentException('Route this kitchen item to a station before remaking it.');
    $from=(string)$item['status'];
    $pdo->prepare("UPDATE kds_order_items SET status='queued',fired_at=NOW(6),started_at=NULL,ready_at=NULL,completed_at=NULL,cancelled_at=NULL,last_action_by=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?")
        ->execute([$actorUserId,$org,(int)$item['id']]);
    kds_event($pdo,$org,(int)$item['id'],'recovery_remake',$from,'queued',$reason,$actorUserId);
    online_order_lifecycle_sync_by_check_id_safe($pdo,$org,(int)$order['pos_check_id'],$actorUserId);
    $message='We are remaking '.$item['item_name_snapshot'].' for your order to make it right. Your pickup may take a little longer.';
    $exception=order_recovery_create_exception($pdo,$org,$orderPublicId,'remake','Remake '.$item['item_name_snapshot'],$reason,'high',$actorUserId,[
        'customerMessage'=>$message,'posCheckItemId'=>(int)$item['pos_check_item_id'],'kdsOrderItemId'=>(int)$item['id'],'requiresManager'=>true,
    ]);
    return ['order'=>order_recovery_detail($pdo,$org,$orderPublicId),'exception'=>$exception];
}

function order_recovery_exception_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $q=$pdo->prepare('SELECT * FROM order_exceptions WHERE organization_id=? AND public_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$q->execute([$org,trim($publicId)]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Order exception was not found.');return $row;
}

function order_recovery_escalate(PDO $pdo,int $org,string $publicId,int $actorUserId,string $note=''): array
{
    $row=order_recovery_exception_row($pdo,$org,$publicId,true);if((string)$row['status']==='resolved')throw new InvalidArgumentException('Resolved exceptions cannot be escalated.');
    $note=mb_substr(trim($note),0,1000,'UTF-8');
    $pdo->prepare("UPDATE order_exceptions SET status='escalated',severity=IF(severity IN ('low','medium'),'high',severity),requires_manager=1,escalated_by=?,escalated_at=COALESCE(escalated_at,NOW(6)),details=IF(?='',details,CONCAT(COALESCE(details,''),IF(COALESCE(details,'')='','',CHAR(10)),?)),updated_at=NOW(6) WHERE organization_id=? AND id=?")
        ->execute([$actorUserId,$note,$note,$org,(int)$row['id']]);
    try{app_audit($pdo,$org,$actorUserId,'online_order.exception_escalated','order_exception',$publicId,null,['onlineOrderId'=>(int)$row['online_order_id'],'note'=>$note?:null]);}catch(Throwable){}
    return order_recovery_exception_row($pdo,$org,$publicId,false);
}

function order_recovery_resolve(PDO $pdo,int $org,string $publicId,int $actorUserId,string $resolution): array
{
    $row=order_recovery_exception_row($pdo,$org,$publicId,true);if((string)$row['status']==='resolved')return $row;
    $resolution=mb_substr(trim($resolution),0,1000,'UTF-8');if($resolution==='')throw new InvalidArgumentException('Add a resolution note.');
    $pdo->prepare("UPDATE order_exceptions SET status='resolved',resolved_by=?,resolved_at=NOW(6),resolution_note=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
        ->execute([$actorUserId,$resolution,$org,(int)$row['id']]);
    try{app_audit($pdo,$org,$actorUserId,'online_order.exception_resolved','order_exception',$publicId,null,['onlineOrderId'=>(int)$row['online_order_id'],'resolution'=>$resolution]);}catch(Throwable){}
    return order_recovery_exception_row($pdo,$org,$publicId,false);
}

function order_recovery_refund(PDO $pdo,int $org,string $orderPublicId,float $amount,string $method,string $reason,string $externalReference,int $actorUserId): array
{
    $method=strtolower(trim($method));if(!in_array($method,['cash','external_card','manual'],true))throw new InvalidArgumentException('Refund method must be cash, external terminal card, or manual.');
    $reason=mb_substr(trim($reason),0,500,'UTF-8');if($reason==='')throw new InvalidArgumentException('A refund reason is required.');
    $externalReference=mb_substr(trim($externalReference),0,255,'UTF-8');$amount=round($amount,2);if($amount<=0)throw new InvalidArgumentException('Refund amount must be greater than zero.');
    $order=order_recovery_order($pdo,$org,$orderPublicId,true);
    if((string)$order['check_status']!=='paid')throw new InvalidArgumentException('Only a paid order can receive a recorded refund.');
    if($amount>(float)$order['refundable_amount']+0.001)throw new InvalidArgumentException('Refund exceeds the remaining paid amount available to refund.');
    if($method==='external_card'&&$externalReference==='')throw new InvalidArgumentException('Enter the external terminal refund reference.');
    $exception=order_recovery_create_exception($pdo,$org,$orderPublicId,'refund','Refund '.$amount.' recorded',$reason,'high',$actorUserId,['recoveryAmount'=>$amount,'requiresManager'=>true]);
    $pdo->prepare("UPDATE order_exceptions SET status='resolved',resolved_by=?,resolved_at=NOW(6),resolution_note='Refund recorded',updated_at=NOW(6) WHERE organization_id=? AND public_id=?")
        ->execute([$actorUserId,$org,(string)$exception['public_id']]);
    $q=$pdo->prepare('SELECT id FROM order_exceptions WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,(string)$exception['public_id']]);$exceptionId=(int)$q->fetchColumn();
    $public=order_recovery_public_id('refund');
    $pdo->prepare("INSERT INTO pos_refunds (organization_id,location_id,online_order_id,pos_check_id,exception_id,public_id,amount,refund_method,reason,external_reference,status,processed_by) VALUES (?,?,?,?,?,?,?,?,?,?,'recorded',?)")
        ->execute([$org,(int)$order['location_id'],(int)$order['online_order_id'],(int)$order['pos_check_id'],$exceptionId,$public,$amount,$method,$reason,$externalReference?:null,$actorUserId]);
    $body='A refund of $'.number_format($amount,2).' was recorded for order '.$order['check_number'].'.';
    order_recovery_notify($pdo,$org,$order,'Refund recorded for order '.$order['check_number'],$body,$actorUserId);
    try{app_audit($pdo,$org,$actorUserId,'pos.refund_recorded','pos_refund',$public,null,['orderPublicId'=>$orderPublicId,'amount'=>$amount,'method'=>$method,'externalReference'=>$externalReference?:null]);}catch(Throwable){}
    return ['refundPublicId'=>$public,'amount'=>$amount,'method'=>$method,'order'=>order_recovery_detail($pdo,$org,$orderPublicId)];
}
