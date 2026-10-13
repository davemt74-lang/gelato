<?php
declare(strict_types=1);
require_once __DIR__.'/glasses-order-photo-stages.php';
require_once __DIR__.'/customer-inbox-core.php';
require_once __DIR__.'/glasses-vision-training-media.php';

function glasses_customer_photo_ready(PDO $pdo): bool
{
    $names=['glasses_photo_delivery_settings','glasses_customer_photo_preferences',
        'glasses_order_photo_shares','glasses_order_photo_notifications'];
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?)");
    $q->execute($names);
    return (int)$q->fetchColumn()===4;
}
function glasses_customer_photo_customer_opted(PDO $pdo,int $org,int $customerId):bool
{
    if($customerId<1||!glasses_customer_photo_ready($pdo))return false;
    $q=$pdo->prepare("SELECT enabled FROM glasses_customer_photo_preferences WHERE organization_id=? AND customer_id=?");
    $q->execute([$org,$customerId]);
    return (bool)$q->fetchColumn();
}
function glasses_customer_photo_preference_save(PDO $pdo,int $org,int $customerId,bool $enabled):void
{
    if(!glasses_customer_photo_ready($pdo))throw new RuntimeException('Photo notifications require the latest database upgrade.');
    $q=$pdo->prepare("SELECT id FROM crm_customers WHERE organization_id=? AND id=? AND status='active'");
    $q->execute([$org,$customerId]);
    if(!(int)$q->fetchColumn())throw new DomainException('Customer profile not found.');
    $pdo->prepare("INSERT INTO glasses_customer_photo_preferences
        (organization_id,customer_id,enabled) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_at=NOW(6)")
        ->execute([$org,$customerId,$enabled?1:0]);
    try{app_audit($pdo,$org,null,'customer.production_photo_preference','crm_customer',(string)$customerId,null,
        ['enabled'=>$enabled]);}catch(Throwable){}
}
function glasses_customer_photo_location_enabled(PDO $pdo,int $org,int $location):bool
{
    $q=$pdo->prepare("SELECT enabled FROM glasses_photo_delivery_settings
        WHERE organization_id=? AND location_id=?");
    $q->execute([$org,$location]);
    return (bool)$q->fetchColumn();
}
function glasses_customer_photo_staff_auth(PDO $pdo,array $user,int $locationId,bool $write):void
{
    glasses_order_photo_auth($pdo,$user,$locationId,$write);
    if(!glasses_customer_photo_ready($pdo))throw new RuntimeException('Section 260 photo notifications upgrade is pending.');
}
function glasses_customer_photo_share_candidates(PDO $pdo,int $org,int $locationId):array
{
    $q=$pdo->prepare("SELECT s.id stage_id,s.revision,s.reviewed_at,s.photo_library_id,
        k.public_id kds_public_id,c.check_number,oo.id online_order_id,oo.public_id order_public_id,
        oo.customer_id,oo.status order_status,m.public_id media_public_id,p.menu_item_id,
        share.id share_id,share.revoked_at,share.photo_library_id approved_photo_id,notif.id notification_id, pref.enabled customer_opted
        FROM glasses_order_photo_stages s
        JOIN kds_order_items k ON k.id=s.kds_item_id AND k.organization_id=s.organization_id AND k.location_id=s.location_id
        JOIN pos_checks c ON c.id=k.check_id AND c.organization_id=k.organization_id
        JOIN online_orders oo ON oo.pos_check_id=k.check_id AND oo.organization_id=s.organization_id
            AND oo.location_id=s.location_id
        JOIN glasses_camera_photo_library p ON p.id=s.photo_library_id AND p.organization_id=s.organization_id
            AND p.location_id=s.location_id AND p.status='active' AND p.camera_slot=3
        JOIN glasses_vision_training_media m ON m.id=p.media_id AND m.organization_id=p.organization_id
            AND m.status='active' AND (m.retention_until IS NULL OR m.retention_until>NOW(6))
        LEFT JOIN glasses_order_photo_shares share ON share.organization_id=s.organization_id AND share.stage_id=s.id
        LEFT JOIN glasses_order_photo_notifications notif ON notif.organization_id=s.organization_id
            AND notif.online_order_id=oo.id
        LEFT JOIN glasses_customer_photo_preferences pref ON pref.organization_id=s.organization_id
            AND pref.customer_id=oo.customer_id
        WHERE s.organization_id=? AND s.location_id=? AND s.stage='post_oven' AND s.status='confirmed'
            AND s.camera_slot=3 AND k.status<>'cancelled' AND oo.status<>'cancelled'
        ORDER BY s.reviewed_at DESC LIMIT 100");
    $q->execute([$org,$locationId]);
    $rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$rows[]=[
        'stageId'=>(int)$r['stage_id'],'revision'=>(int)$r['revision'],
        'photoLibraryId'=>(int)$r['photo_library_id'],
        'kdsItemPublicId'=>(string)$r['kds_public_id'],'checkNumber'=>(string)$r['check_number'],
        'onlineOrderPublicId'=>(string)$r['order_public_id'],
        'orderStatus'=>(string)$r['order_status'],
        'mediaPublicId'=>(string)$r['media_public_id'],
        'customerOptedIn'=>(bool)$r['customer_opted'],
        'approved'=>$r['share_id']!==null&&$r['revoked_at']===null&&
            (int)$r['approved_photo_id']===(int)$r['photo_library_id'],
        'notified'=>$r['notification_id']!==null,
        'reviewedAt'=>(string)$r['reviewed_at']
    ];
    return $rows;
}
function glasses_customer_photo_staff_snapshot(PDO $pdo,array $user,int $locationId):array
{
    glasses_customer_photo_staff_auth($pdo,$user,$locationId,false);
    $org=(int)$user['organization_id'];
    return ['enabled'=>glasses_customer_photo_location_enabled($pdo,$org,$locationId),
        'canManage'=>app_has_permission('glasses.manage',$user),
        'photos'=>glasses_customer_photo_share_candidates($pdo,$org,$locationId)];
}
/** Decision-only policy; no photo is delivered unless every independent gate passes. */
function glasses_customer_photo_delivery_gate(array $flags):string
{
    if(!in_array((string)($flags['orderStatus']??''),['ready','kitchen_complete','completed'],true))
        return 'order_not_ready';
    if(empty($flags['locationEnabled']))return 'location_off';
    if(empty($flags['customerOptedIn']))return 'customer_not_opted_in';
    if(!empty($flags['alreadyNotified']))return 'already_notified';
    if((int)($flags['approvedPhotos']??0)<1)return 'no_approved_photos';
    return 'eligible';
}
function glasses_customer_photo_notify_once(PDO $pdo,int $org,int $onlineOrderId,?int $actorId=null):array
{
    if(!glasses_customer_photo_ready($pdo))return ['sent'=>false,'reason'=>'upgrade_pending'];
    return customer_inbox_transaction($pdo,static function()use($pdo,$org,$onlineOrderId,$actorId):array{
        // The row lock serializes approval, milestone-sync and explicit retries.
        $q=$pdo->prepare("SELECT id,public_id,customer_id,location_id,status FROM online_orders
            WHERE organization_id=? AND id=? FOR UPDATE");
        $q->execute([$org,$onlineOrderId]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if(!$order)return ['sent'=>false,'reason'=>'order_missing'];
        $status=(string)$order['status'];
        if(glasses_customer_photo_delivery_gate(['orderStatus'=>$status,'locationEnabled'=>true,
             'customerOptedIn'=>true,'approvedPhotos'=>1])!=='eligible')
            return ['sent'=>false,'reason'=>'order_not_ready'];
        $location=(int)$order['location_id'];$customer=(int)$order['customer_id'];
        if(!glasses_customer_photo_location_enabled($pdo,$org,$location))
            return ['sent'=>false,'reason'=>'location_off'];
        if(!glasses_customer_photo_customer_opted($pdo,$org,$customer))
            return ['sent'=>false,'reason'=>'customer_not_opted_in'];
        $existing=$pdo->prepare("SELECT id FROM glasses_order_photo_notifications
            WHERE organization_id=? AND online_order_id=?");
        $existing->execute([$org,$onlineOrderId]);
        if($existing->fetchColumn())return ['sent'=>false,'reason'=>'already_notified'];
        $items=$pdo->prepare("SELECT COUNT(*) FROM glasses_order_photo_shares share
          JOIN glasses_order_photo_stages s ON s.id=share.stage_id
            AND s.organization_id=share.organization_id AND s.location_id=share.location_id
            AND s.photo_library_id=share.photo_library_id
          JOIN kds_order_items k ON k.id=s.kds_item_id AND k.organization_id=s.organization_id
          JOIN glasses_camera_photo_library p ON p.id=s.photo_library_id AND p.organization_id=s.organization_id
            AND p.location_id=s.location_id AND p.status='active'
          JOIN glasses_vision_training_media m ON m.id=p.media_id AND m.organization_id=p.organization_id
            AND m.status='active' AND (m.retention_until IS NULL OR m.retention_until>NOW(6))
          JOIN online_orders oo ON oo.pos_check_id=k.check_id AND oo.organization_id=s.organization_id
            AND oo.location_id=s.location_id AND oo.id=share.online_order_id
          WHERE share.organization_id=? AND share.online_order_id=? AND share.revoked_at IS NULL
            AND s.status='confirmed' AND s.stage='post_oven' AND s.camera_slot=3
            AND k.status<>'cancelled' AND oo.status<>'cancelled'");
        $items->execute([$org,$onlineOrderId]);
        $count=(int)$items->fetchColumn();
        $gate=glasses_customer_photo_delivery_gate(['orderStatus'=>$status,
            'locationEnabled'=>true,'customerOptedIn'=>true,'approvedPhotos'=>$count]);
        if($gate!=='eligible')return ['sent'=>false,'reason'=>$gate];
        $message=customer_inbox_send_direct($pdo,$org,$customer,[
            'messageType'=>'order_update',
            'locationId'=>$location,
            'title'=>'Your pizza photo is ready',
            'previewText'=>'See the finished pizza photo approved by our kitchen.',
            'bodyText'=>'Your order has an approved finished-pizza photo. Sign in to view it privately. Photos are available only while your sharing preference is enabled.',
            'ctaLabel'=>'View approved pizza photo',
            'ctaUrl'=>'customer-order-photos.php?order='.rawurlencode((string)$order['public_id'])
        ],$actorId);
        $pdo->prepare("INSERT INTO glasses_order_photo_notifications
            (organization_id,location_id,online_order_id,customer_id,inbox_message_id)
            VALUES (?,?,?,?,?)")->execute([$org,$location,$onlineOrderId,$customer,(int)$message['id']]);
        try{app_audit($pdo,$org,$actorId,'glasses.customer_photo_notified','online_order',(string)$order['public_id'],
            null,['locationId'=>$location,'customerId'=>$customer,'transport'=>'customer_inbox']);}catch(Throwable){}
        return ['sent'=>true,'reason'=>'customer_inbox'];
    });
}
function glasses_customer_photo_notify_safe(PDO $pdo,int $org,int $onlineOrderId,?int $actorId=null):array
{
    try{return glasses_customer_photo_notify_once($pdo,$org,$onlineOrderId,$actorId);}
    catch(Throwable $e){error_log('[customer-photo-notify] '.$e->getMessage());
        return ['sent'=>false,'reason'=>'retry_required'];}
}
function glasses_customer_photo_staff_action(PDO $pdo,array $user,int $locationId,array $input):array
{
    glasses_customer_photo_staff_auth($pdo,$user,$locationId,true);
    $action=(string)($input['action']??'');
    $org=(int)$user['organization_id'];$actor=(int)$user['id'];
    if($action==='settings'){
        if(!is_bool($input['enabled']??null))throw new InvalidArgumentException('Photo sharing enabled must be true or false.');
        $enabled=$input['enabled'];
        $pdo->prepare("INSERT INTO glasses_photo_delivery_settings
            (organization_id,location_id,enabled,updated_by) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_by=VALUES(updated_by),updated_at=NOW(6)")
          ->execute([$org,$locationId,$enabled?1:0,$actor]);
        app_audit($pdo,$org,$actor,'glasses.customer_photo_settings','location',(string)$locationId,null,['enabled'=>$enabled]);
        return ['enabled'=>$enabled];
    }
    if(!in_array($action,['approve','revoke','retry'],true))
        throw new InvalidArgumentException('Unsupported customer photo action.');
    $orderPublic=trim((string)($input['onlineOrderPublicId']??''));
    if($orderPublic===''||strlen($orderPublic)>120)throw new InvalidArgumentException('Select an online order.');
    $lookup=$pdo->prepare("SELECT id FROM online_orders WHERE organization_id=? AND location_id=? AND public_id=?");
    $lookup->execute([$org,$locationId,$orderPublic]);
    $orderId=(int)$lookup->fetchColumn();
    if($orderId<1)throw new DomainException('Online order is not at this location.');
    if($action==='retry')return glasses_customer_photo_notify_once($pdo,$org,$orderId,$actor);
    $stageId=$input['stageId']??null;$revision=$input['expectedRevision']??null;
    if(!is_int($stageId)||$stageId<1||!is_int($revision)||$revision<1)
        throw new InvalidArgumentException('Select a confirmed photo and current revision.');
    $result=customer_inbox_transaction($pdo,static function()use($pdo,$org,$locationId,$orderId,$stageId,$revision,$action,$actor):array{
        $q=$pdo->prepare("SELECT s.id,s.revision,s.status,s.stage,s.camera_slot,s.photo_library_id,
            p.status photo_status,m.status media_status,m.retention_until,k.status item_status
            FROM glasses_order_photo_stages s
            JOIN kds_order_items k ON k.id=s.kds_item_id AND k.organization_id=s.organization_id
                AND k.location_id=s.location_id
            JOIN online_orders oo ON oo.pos_check_id=k.check_id AND oo.organization_id=s.organization_id
                AND oo.location_id=s.location_id
            JOIN glasses_camera_photo_library p ON p.id=s.photo_library_id AND p.organization_id=s.organization_id
                AND p.location_id=s.location_id AND p.camera_slot=3
            JOIN glasses_vision_training_media m ON m.id=p.media_id AND m.organization_id=p.organization_id
            WHERE s.organization_id=? AND s.location_id=? AND s.id=? AND oo.id=? AND oo.status<>'cancelled' FOR UPDATE");
        $q->execute([$org,$locationId,$stageId,$orderId]);$stage=$q->fetch(PDO::FETCH_ASSOC);
        if(!$stage||$stage['status']!=='confirmed'||$stage['stage']!=='post_oven'||(int)$stage['camera_slot']!==3
            ||(int)$stage['revision']!==$revision||$stage['item_status']==='cancelled'
            ||$stage['photo_status']!=='active'||$stage['media_status']!=='active'
            ||($stage['retention_until']!==null&&strtotime((string)$stage['retention_until'])<=time()))
            throw new DomainException('Photo must be confirmed, current, active, and from the correct oven camera.');
        $current=$pdo->prepare("SELECT id,revoked_at,photo_library_id FROM glasses_order_photo_shares
            WHERE organization_id=? AND stage_id=? FOR UPDATE");
        $current->execute([$org,$stageId]);$row=$current->fetch(PDO::FETCH_ASSOC);
        if($action==='approve'){
            if($row&&$row['revoked_at']===null&&(int)$row['photo_library_id']===(int)$stage['photo_library_id'])return ['approved'=>true,'existing'=>true];
            if($row){
                $pdo->prepare("UPDATE glasses_order_photo_shares
                    SET revoked_at=NULL,revoked_by=NULL,photo_library_id=?,approved_by=?,approved_at=NOW(6)
                    WHERE organization_id=? AND id=?")->execute([(int)$stage['photo_library_id'],$actor,$org,(int)$row['id']]);
            }else{
                $pdo->prepare("INSERT INTO glasses_order_photo_shares
                  (organization_id,location_id,online_order_id,stage_id,photo_library_id,approved_by) VALUES (?,?,?,?,?,?)")
                  ->execute([$org,$locationId,$orderId,$stageId,(int)$stage['photo_library_id'],$actor]);
            }
        }else{
            if(!$row||$row['revoked_at']!==null)return ['revoked'=>true,'existing'=>true];
            $pdo->prepare("UPDATE glasses_order_photo_shares
                SET revoked_at=NOW(6),revoked_by=? WHERE organization_id=? AND id=?")
                ->execute([$actor,$org,(int)$row['id']]);
        }
        app_audit($pdo,$org,$actor,'glasses.customer_photo_'.$action,'camera_photo_stage',(string)$stageId,null,
            ['locationId'=>$locationId,'orderId'=>$orderId,'stageRevision'=>$revision]);
        return [$action==='approve'?'approved':'revoked'=>true];
    });
    if($action==='approve')$result['delivery']=glasses_customer_photo_notify_safe($pdo,$org,$orderId,$actor);
    return $result;
}
function glasses_customer_photo_visible(PDO $pdo,int $org,int $customerId,string $orderPublic):array
{
    if(!glasses_customer_photo_ready($pdo))return [];
    if(!glasses_customer_photo_customer_opted($pdo,$org,$customerId))return [];
    $query=$pdo->prepare("SELECT oo.id,oo.location_id FROM online_orders oo
        WHERE oo.organization_id=? AND oo.customer_id=? AND oo.public_id=?
            AND oo.status IN ('ready','kitchen_complete','completed')");
    $query->execute([$org,$customerId,$orderPublic]);$order=$query->fetch(PDO::FETCH_ASSOC);
    if(!$order||!glasses_customer_photo_location_enabled($pdo,$org,(int)$order['location_id']))return [];
    $q=$pdo->prepare("SELECT share.id share_id,p.id photo_id,m.public_id media_public_id,
        i.item_name_snapshot item_name,s.reviewed_at
        FROM glasses_order_photo_shares share
        JOIN glasses_order_photo_stages s ON s.id=share.stage_id
            AND s.organization_id=share.organization_id AND s.photo_library_id=share.photo_library_id
            AND s.status='confirmed'
            AND s.stage='post_oven' AND s.camera_slot=3
        JOIN kds_order_items k ON k.id=s.kds_item_id AND k.organization_id=s.organization_id
            AND k.status<>'cancelled'
        JOIN online_orders linked_order ON linked_order.organization_id=share.organization_id
            AND linked_order.id=share.online_order_id AND linked_order.pos_check_id=k.check_id
            AND linked_order.location_id=share.location_id
        JOIN pos_check_items i ON i.id=k.pos_check_item_id AND i.organization_id=k.organization_id
        JOIN glasses_camera_photo_library p ON p.id=s.photo_library_id AND p.organization_id=s.organization_id
            AND p.location_id=share.location_id AND p.id=share.photo_library_id AND p.status='active'
        JOIN glasses_vision_training_media m ON m.id=p.media_id AND m.organization_id=p.organization_id
            AND m.status='active' AND (m.retention_until IS NULL OR m.retention_until>NOW(6))
        WHERE share.organization_id=? AND share.location_id=? AND share.online_order_id=?
            AND share.revoked_at IS NULL ORDER BY s.reviewed_at ASC LIMIT 30");
    $q->execute([$org,(int)$order['location_id'],(int)$order['id']]);
    return array_map(static fn($r)=>['shareId'=>(int)$r['share_id'],
        'photoId'=>(int)$r['photo_id'],'mediaPublicId'=>(string)$r['media_public_id'],
        'itemName'=>(string)$r['item_name'],'reviewedAt'=>(string)$r['reviewed_at']],
        $q->fetchAll(PDO::FETCH_ASSOC));
}
