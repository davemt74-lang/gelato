<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/customer-inbox-core.php';
require_once __DIR__.'/online-order-lifecycle.php';

function pickup_fulfillment_ready(PDO $pdo): bool
{
    try{
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='online_orders' AND column_name IN ('fulfilled_at','fulfilled_by_user_id','fulfillment_note')");
        return (int)$q->fetchColumn()===3;
    }catch(Throwable){
        return false;
    }
}

function pickup_fulfillment_can_view(array $user): bool
{
    foreach(['online_orders.fulfill','pos.use','pos.manage','kds.view','kds.update','kds.configure','crm.view','crm.manage'] as $permission){
        if(app_has_permission($permission,$user)) return true;
    }
    return false;
}

function pickup_fulfillment_can_fulfill(array $user): bool
{
    return app_has_permission('online_orders.fulfill',$user);
}

function pickup_fulfillment_is_physically_ready(array $row): bool
{
    $total=(int)($row['kds_count']??0);
    $cancelled=(int)($row['kds_cancelled_count']??0);
    $live=max(0,$total-$cancelled);
    $ready=(int)($row['kds_ready_count']??0);
    $completed=(int)($row['kds_completed_count']??0);
    $queued=(int)($row['kds_queued_count']??0);
    $held=(int)($row['kds_held_count']??0);
    $progress=(int)($row['kds_progress_count']??0);
    return $live>0 && ($ready+$completed)===$live && $queued===0 && $held===0 && $progress===0;
}

function pickup_fulfillment_payment_complete(array $row): bool
{
    if((string)($row['check_status']??'')==='paid') return true;
    $total=round((float)($row['total_amount']??0),2);
    $paid=round((float)($row['amount_paid']??0),2);
    return $total<=0 || $paid+0.005>=$total;
}

function pickup_fulfillment_state(array $row): string
{
    if((string)($row['check_status']??'')==='cancelled') return 'cancelled';
    if(!empty($row['fulfilled_at'])) return 'fulfilled';
    if(pickup_fulfillment_is_physically_ready($row)) return 'ready';
    $progress=(int)($row['kds_progress_count']??0);
    $ready=(int)($row['kds_ready_count']??0);
    $completed=(int)($row['kds_completed_count']??0);
    if($progress>0 || $ready>0 || $completed>0) return 'preparing';
    if((int)($row['kds_queued_count']??0)>0 || (int)($row['kds_held_count']??0)>0) return 'in_kitchen';
    return 'submitted';
}

function pickup_fulfillment_display_state(string $state): string
{
    return match($state){
        'in_kitchen'=>'In kitchen',
        'preparing'=>'Preparing',
        'ready'=>'Ready for pickup',
        'fulfilled'=>'Fulfilled',
        'cancelled'=>'Cancelled',
        default=>'Submitted',
    };
}

function pickup_fulfillment_queue(PDO $pdo,int $organizationId,?int $locationId=null,string $filter='active',int $limit=200): array
{
    if(!pickup_fulfillment_ready($pdo)) return [];
    $limit=max(1,min(300,$limit));
    $where=['oo.organization_id=?'];
    $args=[$organizationId];
    if($locationId!==null&&$locationId>0){$where[]='oo.location_id=?';$args[]=$locationId;}
    if($filter==='active') $where[]='oo.fulfilled_at IS NULL AND c.status<>\'cancelled\'';
    elseif($filter==='ready') $where[]='oo.fulfilled_at IS NULL AND c.status<>\'cancelled\'';
    elseif($filter==='fulfilled') $where[]='oo.fulfilled_at IS NOT NULL';
    elseif($filter==='cancelled') $where[]='c.status=\'cancelled\'';

    $sql="SELECT oo.id online_order_id,oo.public_id order_public_id,oo.payment_mode,oo.status lifecycle_status,oo.requested_ready_at,oo.customer_note,oo.submitted_at,oo.fulfilled_at,oo.fulfilled_by_user_id,oo.fulfillment_note,
        c.id check_id,c.public_id check_public_id,c.check_number,c.status check_status,c.total_amount,c.amount_paid,
        l.id location_id,l.name location_name,cc.id customer_id,cc.display_name customer_name,cc.email customer_email,cc.phone customer_phone,
        u.display_name fulfilled_by_name,
        COALESCE(ks.kds_count,0) kds_count,COALESCE(ks.kds_queued_count,0) kds_queued_count,COALESCE(ks.kds_held_count,0) kds_held_count,
        COALESCE(ks.kds_progress_count,0) kds_progress_count,COALESCE(ks.kds_ready_count,0) kds_ready_count,COALESCE(ks.kds_completed_count,0) kds_completed_count,
        COALESCE(ks.kds_cancelled_count,0) kds_cancelled_count,ks.ticket_ready_at
        FROM online_orders oo
        JOIN pos_checks c ON c.organization_id=oo.organization_id AND c.id=oo.pos_check_id
        JOIN locations l ON l.organization_id=oo.organization_id AND l.id=oo.location_id
        JOIN crm_customers cc ON cc.organization_id=oo.organization_id AND cc.id=oo.customer_id
        LEFT JOIN users u ON u.id=oo.fulfilled_by_user_id
        LEFT JOIN (
            SELECT organization_id,check_id,COUNT(*) kds_count,
                SUM(status='queued') kds_queued_count,SUM(status='held') kds_held_count,SUM(status='in_progress') kds_progress_count,
                SUM(status='ready') kds_ready_count,SUM(status='completed') kds_completed_count,SUM(status='cancelled') kds_cancelled_count,
                MAX(CASE WHEN status<>'cancelled' THEN ready_at ELSE NULL END) ticket_ready_at
            FROM kds_order_items GROUP BY organization_id,check_id
        ) ks ON ks.organization_id=oo.organization_id AND ks.check_id=oo.pos_check_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY CASE WHEN oo.fulfilled_at IS NULL THEN 0 ELSE 1 END,
                 CASE WHEN ks.kds_count>0 AND (COALESCE(ks.kds_ready_count,0)+COALESCE(ks.kds_completed_count,0))=(ks.kds_count-COALESCE(ks.kds_cancelled_count,0)) AND COALESCE(ks.kds_progress_count,0)=0 AND COALESCE(ks.kds_queued_count,0)=0 AND COALESCE(ks.kds_held_count,0)=0 THEN 0 ELSE 1 END,
                 COALESCE(oo.requested_ready_at,oo.submitted_at),oo.id
        LIMIT {$limit}";
    $q=$pdo->prepare($sql);$q->execute($args);$rows=$q->fetchAll();
    $now=microtime(true);
    foreach($rows as &$row){
        $state=pickup_fulfillment_state($row);
        if($filter==='ready'&&$state!=='ready'){$row['_drop']=true;continue;}
        $row['fulfillmentState']=$state;
        $row['displayStatus']=pickup_fulfillment_display_state($state);
        $row['physicalReady']=pickup_fulfillment_is_physically_ready($row);
        $row['paymentComplete']=pickup_fulfillment_payment_complete($row);
        $row['paymentStatus']=$row['paymentComplete']?'Paid':'Payment due';
        $readyAt=trim((string)($row['ticket_ready_at']??''));
        $row['readyAgeSeconds']=$readyAt!==''?max(0,(int)round($now-(float)(strtotime($readyAt)?:$now))):null;
        $requested=trim((string)($row['requested_ready_at']??''));
        $row['promiseDeltaSeconds']=$requested!==''?(int)round((float)(strtotime($requested)?:$now)-$now):null;
    }
    unset($row);
    return array_values(array_filter($rows,static fn(array $row):bool=>empty($row['_drop'])));
}

function pickup_fulfillment_order(PDO $pdo,int $organizationId,string $orderPublicId,bool $forUpdate=false): ?array
{
    $orderPublicId=trim($orderPublicId);
    if($orderPublicId==='') return null;
    $q=$pdo->prepare("SELECT oo.id online_order_id,oo.public_id order_public_id,oo.location_id,oo.customer_id,oo.payment_mode,oo.fulfilled_at,oo.fulfilled_by_user_id,oo.fulfillment_note,
        c.id check_id,c.public_id check_public_id,c.check_number,c.status check_status,c.total_amount,c.amount_paid,l.name location_name
        FROM online_orders oo
        JOIN pos_checks c ON c.organization_id=oo.organization_id AND c.id=oo.pos_check_id
        JOIN locations l ON l.organization_id=oo.organization_id AND l.id=oo.location_id
        WHERE oo.organization_id=? AND oo.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $q->execute([$organizationId,$orderPublicId]);
    $row=$q->fetch();
    if(!$row)return null;
    $k=$pdo->prepare('SELECT id,status,ready_at FROM kds_order_items WHERE organization_id=? AND check_id=?'.($forUpdate?' FOR UPDATE':''));
    $k->execute([$organizationId,(int)$row['check_id']]);
    $items=$k->fetchAll();
    $counts=['kds_count'=>count($items),'kds_queued_count'=>0,'kds_held_count'=>0,'kds_progress_count'=>0,'kds_ready_count'=>0,'kds_completed_count'=>0,'kds_cancelled_count'=>0];
    $readyTimes=[];
    foreach($items as $item){
        $status=(string)$item['status'];
        $key=match($status){'queued'=>'kds_queued_count','held'=>'kds_held_count','in_progress'=>'kds_progress_count','ready'=>'kds_ready_count','completed'=>'kds_completed_count','cancelled'=>'kds_cancelled_count',default=>null};
        if($key!==null)$counts[$key]++;
        if($status!=='cancelled'&&!empty($item['ready_at']))$readyTimes[]=(string)$item['ready_at'];
    }
    $row=array_merge($row,$counts);
    $row['ticket_ready_at']=$readyTimes?max($readyTimes):null;
    $row['physicalReady']=pickup_fulfillment_is_physically_ready($row);
    $row['paymentComplete']=pickup_fulfillment_payment_complete($row);
    return $row;
}

function pickup_fulfillment_mark_handed(PDO $pdo,int $organizationId,string $orderPublicId,int $actorUserId,string $note=''): array
{
    if(!pickup_fulfillment_ready($pdo)) throw new RuntimeException('Pickup fulfillment is not installed. Run Upgrade first.');
    $note=mb_substr(trim($note),0,500,'UTF-8');
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $order=pickup_fulfillment_order($pdo,$organizationId,$orderPublicId,true);
        if(!$order) throw new InvalidArgumentException('Pickup order was not found.');
        if((string)$order['check_status']==='cancelled') throw new InvalidArgumentException('A cancelled order cannot be fulfilled.');
        if(!empty($order['fulfilled_at'])){
            if($owns)$pdo->commit();
            return $order+['duplicate'=>true,'fulfillmentState'=>'fulfilled','notified'=>false];
        }
        if(empty($order['physicalReady'])) throw new InvalidArgumentException('The complete kitchen ticket must be ready before customer handoff.');
        if((string)$order['payment_mode']==='pay_at_pickup'&&empty($order['paymentComplete'])) throw new InvalidArgumentException('Payment is still due. Complete the POS tender before customer handoff.');

        $q=$pdo->prepare('UPDATE online_orders SET fulfilled_at=NOW(6),fulfilled_by_user_id=?,fulfillment_note=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND fulfilled_at IS NULL');
        $q->execute([$actorUserId,$note!==''?$note:null,$organizationId,(int)$order['online_order_id']]);
        if($q->rowCount()!==1) throw new RuntimeException('Pickup fulfillment changed while this request was being processed. Refresh and try again.');

        $notified=false;
        try{
            customer_inbox_send_direct($pdo,$organizationId,(int)$order['customer_id'],[
                'messageType'=>'order_update','locationId'=>(int)$order['location_id'],
                'title'=>'Order '.$order['check_number'].' picked up','previewText'=>'Your pickup order has been handed off.',
                'bodyText'=>'Your order from '.$order['location_name'].' was handed to you. Thanks for visiting us.',
                'ctaLabel'=>'View order history','ctaUrl'=>'customer-account.php#orders',
            ],$actorUserId);
            $notified=true;
        }catch(Throwable $notificationError){
            try{app_audit($pdo,$organizationId,$actorUserId,'online_order.fulfillment_notification_failed','online_order',(string)$order['order_public_id'],null,[
                'checkPublicId'=>(string)$order['check_public_id'],'error'=>mb_substr($notificationError->getMessage(),0,500,'UTF-8'),
            ]);}catch(Throwable){}
        }
        try{app_audit($pdo,$organizationId,$actorUserId,'online_order.fulfilled','online_order',(string)$order['order_public_id'],null,[
            'checkPublicId'=>(string)$order['check_public_id'],'locationId'=>(int)$order['location_id'],'customerId'=>(int)$order['customer_id'],'note'=>$note!==''?$note:null,'notified'=>$notified,
        ]);}catch(Throwable){}

        $fresh=pickup_fulfillment_order($pdo,$organizationId,$orderPublicId,false)??$order;
        if($owns)$pdo->commit();
        return $fresh+['duplicate'=>false,'fulfillmentState'=>'fulfilled','notified'=>$notified];
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
