<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/customer-inbox-core.php';

function online_order_lifecycle_statuses(): array
{
    return ['submitted','in_kitchen','preparing','ready','kitchen_complete','completed','cancelled'];
}

function online_order_lifecycle_display_status(string $status): string
{
    return match($status){
        'in_kitchen'=>'In kitchen',
        'preparing'=>'Preparing',
        'ready'=>'Ready',
        'kitchen_complete'=>'Kitchen complete',
        'completed'=>'Completed',
        'cancelled'=>'Cancelled',
        default=>'Submitted',
    };
}

function online_order_lifecycle_rank(string $status): int
{
    return match($status){
        'submitted'=>10,
        'in_kitchen'=>20,
        'preparing'=>30,
        'ready'=>40,
        'kitchen_complete'=>45,
        'completed'=>50,
        'cancelled'=>100,
        default=>0,
    };
}

function online_order_lifecycle_derive(array $state): string
{
    $checkStatus=(string)($state['check_status']??'');
    if($checkStatus==='cancelled') return 'cancelled';
    if($checkStatus==='paid') return 'completed';

    $queued=(int)($state['kds_queued_count']??0);
    $held=(int)($state['kds_held_count']??0);
    $progress=(int)($state['kds_progress_count']??0);
    $ready=(int)($state['kds_ready_count']??0);
    $completed=(int)($state['kds_completed_count']??0);
    $cancelled=(int)($state['kds_cancelled_count']??0);
    $total=(int)($state['kds_count']??0);
    $live=max(0,$total-$cancelled);

    if($live>0 && $completed===$live) return 'kitchen_complete';
    if($live>0 && ($ready+$completed)===$live && $queued===0 && $held===0 && $progress===0) return 'ready';
    if($progress>0 || $ready>0 || $completed>0) return 'preparing';
    if(($queued+$held)>0) return 'in_kitchen';
    return 'submitted';
}

function online_order_lifecycle_snapshot(PDO $pdo,int $organizationId,string $checkPublicId,bool $forUpdate=false): ?array
{
    $checkPublicId=trim($checkPublicId);
    if($checkPublicId==='') return null;
    $q=$pdo->prepare("SELECT oo.id online_order_id,oo.public_id order_public_id,oo.status order_status,oo.customer_id,oo.location_id,oo.user_id customer_user_id,
        c.id check_id,c.public_id check_public_id,c.check_number,c.status check_status,c.amount_paid,c.total_amount,l.name location_name
        FROM online_orders oo
        JOIN pos_checks c ON c.id=oo.pos_check_id AND c.organization_id=oo.organization_id
        JOIN locations l ON l.id=oo.location_id AND l.organization_id=oo.organization_id
        WHERE oo.organization_id=? AND c.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $q->execute([$organizationId,$checkPublicId]);
    $row=$q->fetch();
    if(!$row) return null;

    $counts=['kds_count'=>0,'kds_queued_count'=>0,'kds_held_count'=>0,'kds_progress_count'=>0,'kds_ready_count'=>0,'kds_completed_count'=>0,'kds_cancelled_count'=>0];
    try{
        $q=$pdo->prepare("SELECT COUNT(*) kds_count,
            SUM(status='queued') kds_queued_count,
            SUM(status='held') kds_held_count,
            SUM(status='in_progress') kds_progress_count,
            SUM(status='ready') kds_ready_count,
            SUM(status='completed') kds_completed_count,
            SUM(status='cancelled') kds_cancelled_count
            FROM kds_order_items WHERE organization_id=? AND check_id=?");
        $q->execute([$organizationId,(int)$row['check_id']]);
        $aggregate=$q->fetch()?:[];
        foreach($counts as $key=>$zero)$counts[$key]=(int)($aggregate[$key]??0);
    }catch(Throwable){}

    $row=array_merge($row,$counts);
    $row['derived_status']=online_order_lifecycle_derive($row);
    $row['display_status']=online_order_lifecycle_display_status((string)$row['derived_status']);
    return $row;
}

function online_order_lifecycle_check_public_id(PDO $pdo,int $organizationId,int $checkId): ?string
{
    if($checkId<1) return null;
    $q=$pdo->prepare('SELECT public_id FROM pos_checks WHERE organization_id=? AND id=? LIMIT 1');
    $q->execute([$organizationId,$checkId]);
    $value=$q->fetchColumn();
    return $value!==false?(string)$value:null;
}

function online_order_lifecycle_notification_milestone(string $status): string
{
    return $status==='kitchen_complete'?'ready':$status;
}

function online_order_lifecycle_notification_cta(array $state,string $status): string
{
    $milestone=online_order_lifecycle_notification_milestone($status);
    return 'customer-account.php?order='.rawurlencode((string)$state['order_public_id']).'&status='.rawurlencode($milestone).'#orders';
}

function online_order_lifecycle_notification_exists(PDO $pdo,int $organizationId,int $customerId,array $state,string $status): bool
{
    $q=$pdo->prepare("SELECT COUNT(*)
        FROM customer_inbox_recipients r
        JOIN customer_inbox_messages m ON m.id=r.message_id AND m.organization_id=r.organization_id
        WHERE r.organization_id=? AND r.customer_id=? AND m.message_type='order_update' AND m.cta_url=?");
    $q->execute([$organizationId,$customerId,online_order_lifecycle_notification_cta($state,$status)]);
    return (int)$q->fetchColumn()>0;
}

function online_order_lifecycle_notification(string $status,array $state): ?array
{
    $check=(string)($state['check_number']??'your order');
    $location=(string)($state['location_name']??'the restaurant');
    return match($status){
        'preparing'=>[
            'title'=>'Order '.$check.' is being prepared',
            'previewText'=>'The kitchen has started your pickup order.',
            'bodyText'=>'The kitchen has started preparing your pickup order at '.$location.'.',
        ],
        'ready','kitchen_complete'=>[
            'title'=>'Order '.$check.' is ready',
            'previewText'=>'Your pickup order is ready.',
            'bodyText'=>'Your pickup order is ready at '.$location.'. Payment is due at pickup unless already completed.',
        ],
        'completed'=>[
            'title'=>'Order '.$check.' completed',
            'previewText'=>'Thanks for your order.',
            'bodyText'=>'Your pickup order at '.$location.' is complete. Thanks for visiting us.',
        ],
        'cancelled'=>[
            'title'=>'Order '.$check.' cancelled',
            'previewText'=>'This pickup order has been cancelled.',
            'bodyText'=>'Your pickup order at '.$location.' has been cancelled. Please contact the restaurant if you have questions.',
        ],
        default=>null,
    };
}

function online_order_lifecycle_should_notify(string $previous,string $next): bool
{
    if($next==='cancelled') return true;
    if(!in_array($next,['preparing','ready','kitchen_complete','completed'],true)) return false;
    return online_order_lifecycle_rank($next)>=online_order_lifecycle_rank($previous);
}

function online_order_lifecycle_notify_once(PDO $pdo,int $organizationId,array $state,string $status,?int $actorUserId): bool
{
    $message=online_order_lifecycle_notification($status,$state);
    if($message===null) return false;
    $customerId=(int)$state['customer_id'];
    if(online_order_lifecycle_notification_exists($pdo,$organizationId,$customerId,$state,$status)) return false;
    customer_inbox_send_direct($pdo,$organizationId,$customerId,[
        ...$message,
        'messageType'=>'order_update',
        'locationId'=>(int)$state['location_id'],
        'ctaLabel'=>'View order history',
        'ctaUrl'=>online_order_lifecycle_notification_cta($state,$status),
    ],$actorUserId);
    return true;
}

function online_order_lifecycle_sync(PDO $pdo,int $organizationId,string $checkPublicId,?int $actorUserId=null): ?array
{
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $state=online_order_lifecycle_snapshot($pdo,$organizationId,$checkPublicId,true);
        if(!$state){
            if($owns)$pdo->commit();
            return null;
        }
        $previous=(string)($state['order_status']??'submitted');
        $next=(string)$state['derived_status'];
        $changed=false;
        if($previous!==$next){
            $q=$pdo->prepare('UPDATE online_orders SET status=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND status=?');
            $q->execute([$next,$organizationId,(int)$state['online_order_id'],$previous]);
            if($q->rowCount()!==1){
                $fresh=online_order_lifecycle_snapshot($pdo,$organizationId,$checkPublicId,false);
                if($owns)$pdo->commit();
                return $fresh?($fresh+['changed'=>false,'notified'=>false]):null;
            }
            $changed=true;
        }

        $notified=false;
        if(online_order_lifecycle_should_notify($previous,$next)){
            $notified=online_order_lifecycle_notify_once($pdo,$organizationId,$state,$next,$actorUserId);
        }

        if($changed){
            try{
                app_audit($pdo,$organizationId,$actorUserId,'online_order.status_changed','online_order',(string)$state['order_public_id'],null,[
                    'checkPublicId'=>$checkPublicId,'fromStatus'=>$previous,'toStatus'=>$next,'customerId'=>(int)$state['customer_id'],
                ]);
            }catch(Throwable){}
        }

        if($owns)$pdo->commit();
        $state['order_status']=$next;
        $state['display_status']=online_order_lifecycle_display_status($next);
        return $state+['changed'=>$changed,'notified'=>$notified,'previous_status'=>$previous];
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function online_order_lifecycle_sync_by_check_id(PDO $pdo,int $organizationId,int $checkId,?int $actorUserId=null): ?array
{
    $public=online_order_lifecycle_check_public_id($pdo,$organizationId,$checkId);
    return $public!==null?online_order_lifecycle_sync($pdo,$organizationId,$public,$actorUserId):null;
}

function online_order_lifecycle_sync_safe(PDO $pdo,int $organizationId,string $checkPublicId,?int $actorUserId=null): ?array
{
    try{return online_order_lifecycle_sync($pdo,$organizationId,$checkPublicId,$actorUserId);}
    catch(Throwable $e){
        try{app_audit($pdo,$organizationId,$actorUserId,'online_order.status_sync_failed','pos_check',$checkPublicId,null,['error'=>mb_substr($e->getMessage(),0,500,'UTF-8')]);}catch(Throwable){}
        return null;
    }
}

function online_order_lifecycle_sync_by_check_id_safe(PDO $pdo,int $organizationId,int $checkId,?int $actorUserId=null): ?array
{
    try{return online_order_lifecycle_sync_by_check_id($pdo,$organizationId,$checkId,$actorUserId);}
    catch(Throwable $e){
        try{app_audit($pdo,$organizationId,$actorUserId,'online_order.status_sync_failed','pos_check',(string)$checkId,null,['error'=>mb_substr($e->getMessage(),0,500,'UTF-8')]);}catch(Throwable){}
        return null;
    }
}
