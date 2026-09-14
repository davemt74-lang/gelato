<?php
declare(strict_types=1);

require_once __DIR__.'/customer-account-core.php';
require_once __DIR__.'/customer-inbox-core.php';
require_once __DIR__.'/location-core.php';
require_once __DIR__.'/pos-core.php';
require_once __DIR__.'/kds-core.php';

function online_order_missing_requirements(PDO $pdo): array
{
    $missing=[];
    foreach(['online_orders','pos_settings','pos_checks','pos_check_items','pos_tenders','sales_integrations'] as $table){
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1) $missing[]='table:'.$table;
    }
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='locations' AND column_name IN ('public_slug','pickup_enabled','online_ordering_enabled','pickup_lead_minutes')");
    if((int)$q->fetchColumn()!==4) $missing[]='migration:20261003_location_foundation';
    if(!customer_inbox_ready($pdo)) $missing[]='migration:20261004_online_ordering_customer_inbox';
    return $missing;
}

function online_order_ready(PDO $pdo): bool
{
    return online_order_missing_requirements($pdo)===[];
}

function online_order_locations(PDO $pdo,int $organizationId): array
{
    return array_values(array_filter(location_list($pdo,$organizationId,false),static fn(array $location):bool=>
        !empty($location['online_ordering_enabled']) && !empty($location['pickup_enabled']) && ($location['status']??'')==='active'
    ));
}

function online_order_location(PDO $pdo,int $organizationId,int $locationId): array
{
    $location=location_get($pdo,$organizationId,$locationId);
    if(!$location || ($location['status']??'')!=='active') throw new InvalidArgumentException('That location is not available.');
    if(empty($location['online_ordering_enabled'])) throw new InvalidArgumentException('Online ordering is not enabled for that location.');
    if(empty($location['pickup_enabled'])) throw new InvalidArgumentException('Online pickup is not enabled for that location.');
    return $location;
}

function online_order_menu(PDO $pdo,int $organizationId): array
{
    return pos_menu($pdo,$organizationId);
}

function online_order_idempotency_key(?string $value): string
{
    $value=trim((string)$value);
    if($value==='') return bin2hex(random_bytes(20));
    if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/',$value)) throw new InvalidArgumentException('Order submission token is invalid. Refresh the ordering page and try again.');
    return $value;
}

function online_order_cart(array $raw): array
{
    if(!$raw || count($raw)>60) throw new InvalidArgumentException('Your cart must contain between 1 and 60 line items.');
    $items=[];
    foreach($raw as $row){
        if(!is_array($row)) continue;
        $priceId=max(0,(int)($row['priceId']??0));
        $quantity=round((float)($row['quantity']??0),3);
        $instructions=mb_substr(trim((string)($row['instructions']??'')),0,1000,'UTF-8');
        if($priceId<1 || $quantity<=0 || $quantity>20) throw new InvalidArgumentException('One of the cart items has an invalid quantity or menu option.');
        $key=$priceId.'|'.$instructions;
        if(isset($items[$key])){
            $items[$key]['quantity']=round($items[$key]['quantity']+$quantity,3);
            if($items[$key]['quantity']>20) throw new InvalidArgumentException('A menu item quantity cannot exceed 20.');
        }else{
            $items[$key]=['priceId'=>$priceId,'quantity'=>$quantity,'instructions'=>$instructions];
        }
    }
    if(!$items) throw new InvalidArgumentException('Add at least one item before placing the order.');
    return array_values($items);
}

function online_order_existing(PDO $pdo,int $organizationId,int $customerId,string $idempotencyKey): ?array
{
    $q=$pdo->prepare("SELECT oo.public_id,oo.status,oo.payment_mode,oo.requested_ready_at,oo.submitted_at,c.public_id check_public_id,c.check_number,c.subtotal,c.tax_amount,c.service_charge_amount,c.total_amount,c.status check_status,l.name location_name
        FROM online_orders oo JOIN pos_checks c ON c.id=oo.pos_check_id AND c.organization_id=oo.organization_id JOIN locations l ON l.id=oo.location_id AND l.organization_id=oo.organization_id
        WHERE oo.organization_id=? AND oo.customer_id=? AND oo.idempotency_key=? LIMIT 1");
    $q->execute([$organizationId,$customerId,$idempotencyKey]);
    $row=$q->fetch();
    return $row?:null;
}

function online_order_requested_ready_at(PDO $pdo,int $organizationId,array $location): string
{
    $timezone=trim((string)($location['timezone']??''));
    if($timezone===''){
        $q=$pdo->prepare('SELECT timezone FROM organizations WHERE id=? LIMIT 1');
        $q->execute([$organizationId]);
        $timezone=trim((string)$q->fetchColumn());
    }
    try{$tz=new DateTimeZone($timezone?:'America/Phoenix');}catch(Throwable){$tz=new DateTimeZone('America/Phoenix');}
    $minutes=max(0,min(1440,(int)($location['pickup_lead_minutes']??20)));
    return (new DateTimeImmutable('now',$tz))->modify('+'.$minutes.' minutes')->format('Y-m-d H:i:s.u');
}

function online_order_submit_pickup(PDO $pdo,int $organizationId,array $account,array $input): array
{
    if(!online_order_ready($pdo)) throw new RuntimeException('Online ordering is not installed. Run Upgrade first.');
    if(!app_has_permission('online_ordering.use',$account)) throw new RuntimeException('Online ordering permission is required.');
    $userId=(int)($account['id']??0);
    $customerId=(int)($account['customer_id']??0);
    if($userId<1||$customerId<1) throw new RuntimeException('Your customer account is not linked to the restaurant CRM.');
    $locationId=max(0,(int)($input['locationId']??0));
    $location=online_order_location($pdo,$organizationId,$locationId);
    $idempotency=online_order_idempotency_key($input['idempotencyKey']??null);
    if($existing=online_order_existing($pdo,$organizationId,$customerId,$idempotency)) return $existing+['duplicate'=>true];
    $cart=online_order_cart(is_array($input['items']??null)?$input['items']:[]);
    $note=mb_substr(trim((string)($input['note']??'')),0,1000,'UTF-8');
    $readyAt=online_order_requested_ready_at($pdo,$organizationId,$location);

    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $check=pos_create_check($pdo,$organizationId,$locationId,[
            'serviceMode'=>'pickup','tableName'=>'Online Pickup','guestCount'=>1,
            'notes'=>$note!==''?'Online pickup: '.$note:'Online pickup order',
        ],$userId);
        $pdo->prepare('UPDATE pos_checks SET customer_id=? WHERE organization_id=? AND id=?')->execute([$customerId,$organizationId,(int)$check['id']]);
        foreach($cart as $item){
            $check=pos_add_item($pdo,$organizationId,(string)$check['publicId'],(int)$item['priceId'],(float)$item['quantity'],(string)$item['instructions'],$userId);
        }
        $orderPublic=customer_inbox_public_id('online-order');
        $pdo->prepare("INSERT INTO online_orders (organization_id,location_id,customer_id,user_id,pos_check_id,public_id,idempotency_key,service_mode,payment_mode,status,requested_ready_at,customer_note) VALUES (?,?,?,?,?,?,?,'pickup','pay_at_pickup','submitted',?,?)")
            ->execute([$organizationId,$locationId,$customerId,$userId,(int)$check['id'],$orderPublic,$idempotency,$readyAt,$note?:null]);

        $kitchen=['ready'=>false,'sent'=>0,'unsent'=>count($cart),'unrouted'=>0,'items'=>[]];
        if(kds_ready($pdo)) $kitchen=kds_send_check($pdo,$organizationId,(string)$check['publicId'],$userId,false);

        $readyDisplay=(new DateTimeImmutable($readyAt))->format('g:i A');
        customer_inbox_send_direct($pdo,$organizationId,$customerId,[
            'messageType'=>'order_update',
            'locationId'=>$locationId,
            'title'=>'Order received — '.$check['checkNumber'],
            'previewText'=>'Your pickup order is in the restaurant workflow.',
            'bodyText'=>'We received your pickup order for '.$location['name'].'. Estimated ready time: '.$readyDisplay.'. Payment is due when you pick up the order.',
            'ctaLabel'=>'View order history',
            'ctaUrl'=>'customer-account.php#orders',
        ],$userId);
        try{app_audit($pdo,$organizationId,$userId,'online_order.submitted','online_order',$orderPublic,null,['checkPublicId'=>$check['publicId'],'customerId'=>$customerId,'locationId'=>$locationId,'total'=>$check['totalAmount'],'paymentMode'=>'pay_at_pickup']);}catch(Throwable){}
        if($owns)$pdo->commit();
        return [
            'public_id'=>$orderPublic,'status'=>'submitted','payment_mode'=>'pay_at_pickup','requested_ready_at'=>$readyAt,'submitted_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'check_public_id'=>$check['publicId'],'check_number'=>$check['checkNumber'],'subtotal'=>$check['subtotal'],'tax_amount'=>$check['taxAmount'],
            'service_charge_amount'=>$check['serviceChargeAmount'],'total_amount'=>$check['totalAmount'],'check_status'=>$check['status'],'location_name'=>$location['name'],
            'kitchen'=>$kitchen,'duplicate'=>false,
        ];
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function online_order_customer_orders(PDO $pdo,int $organizationId,int $customerId,int $limit=25): array
{
    $limit=max(1,min(100,$limit));
    $hasKds=kds_ready($pdo);
    $kdsJoin=$hasKds?' LEFT JOIN kds_order_items k ON k.organization_id=oo.organization_id AND k.check_id=oo.pos_check_id ':'';
    $kdsSelect=$hasKds?",SUM(CASE WHEN k.status='ready' THEN 1 ELSE 0 END) kds_ready_count,SUM(CASE WHEN k.status='in_progress' THEN 1 ELSE 0 END) kds_progress_count,SUM(CASE WHEN k.status IN ('queued','held') THEN 1 ELSE 0 END) kds_waiting_count,SUM(CASE WHEN k.status='completed' THEN 1 ELSE 0 END) kds_completed_count,COUNT(k.id) kds_count":",0 kds_ready_count,0 kds_progress_count,0 kds_waiting_count,0 kds_completed_count,0 kds_count";
    $q=$pdo->prepare("SELECT oo.public_id order_public_id,oo.status order_status,oo.payment_mode,oo.requested_ready_at,oo.submitted_at,c.public_id check_public_id,c.check_number,c.status check_status,c.total_amount,c.amount_paid,l.name location_name{$kdsSelect}
        FROM online_orders oo JOIN pos_checks c ON c.id=oo.pos_check_id AND c.organization_id=oo.organization_id JOIN locations l ON l.id=oo.location_id AND l.organization_id=oo.organization_id {$kdsJoin}
        WHERE oo.organization_id=? AND oo.customer_id=? GROUP BY oo.id ORDER BY oo.submitted_at DESC,oo.id DESC LIMIT {$limit}");
    $q->execute([$organizationId,$customerId]);
    $rows=$q->fetchAll();
    foreach($rows as &$row){
        if((string)$row['check_status']==='cancelled') $state='Cancelled';
        elseif((string)$row['check_status']==='paid') $state='Completed';
        elseif((int)$row['kds_ready_count']>0) $state='Ready';
        elseif((int)$row['kds_progress_count']>0) $state='Preparing';
        elseif((int)$row['kds_waiting_count']>0) $state='In kitchen';
        else $state='Submitted';
        $row['displayStatus']=$state;
    }
    unset($row);
    return $rows;
}
