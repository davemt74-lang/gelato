<?php
declare(strict_types=1);

require_once __DIR__.'/wholesale-demand.php';
require_once __DIR__.'/operations-core.php';
require_once __DIR__.'/wholesale-portal.php';

function wholesale_fulfillment_ready(PDO $pdo): bool
{
    foreach(['wholesale_fulfillments','wholesale_fulfillment_items','wholesale_fulfillment_consumptions','inventory_transactions','inventory_commitments'] as $table){
        if(!restaurant_brain_table_ready($pdo,$table))return false;
    }
    return true;
}

function wholesale_fulfillment_datetime(mixed $value,string $label): ?string
{
    $value=trim((string)$value);
    if($value==='')return null;
    foreach(['Y-m-d\TH:i','Y-m-d H:i:s','Y-m-d H:i'] as $format){
        $date=DateTimeImmutable::createFromFormat('!'.$format,$value);
        if($date)return $date->format('Y-m-d H:i:s');
    }
    throw new InvalidArgumentException($label.' is invalid.');
}

function wholesale_fulfillment_validate_window(?string $start,?string $end,string $label): void
{
    if($start!==null&&$end!==null&&$end<$start)throw new InvalidArgumentException($label.' end cannot be before its start.');
}

function wholesale_fulfillment_order(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT o.*,a.business_name,a.account_status,a.public_id account_public_id FROM wholesale_orders o JOIN wholesale_accounts a ON a.id=o.wholesale_account_id AND a.organization_id=o.organization_id WHERE o.organization_id=? AND o.public_id=? AND a.archived_at IS NULL LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Wholesale order not found.');
    return $row;
}

function wholesale_fulfillment_location(PDO $pdo,int $org,int $accountId,mixed $identifier): ?array
{
    $identifier=trim((string)$identifier);
    if($identifier==='')return null;
    if(ctype_digit($identifier)){
        $q=$pdo->prepare("SELECT * FROM wholesale_account_locations WHERE organization_id=? AND wholesale_account_id=? AND id=? AND status='active' LIMIT 1");
        $q->execute([$org,$accountId,(int)$identifier]);
    }else{
        $q=$pdo->prepare("SELECT * FROM wholesale_account_locations WHERE organization_id=? AND wholesale_account_id=? AND public_id=? AND status='active' LIMIT 1");
        $q->execute([$org,$accountId,$identifier]);
    }
    $row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Fulfillment location does not belong to this Wholesale account or is inactive.');
    return $row;
}

function wholesale_fulfillment_save_order_plan(PDO $pdo,int $org,string $orderPublic,array $input,int $userId): array
{
    $order=wholesale_fulfillment_order($pdo,$org,$orderPublic);
    if(in_array((string)$order['status'],['delivered','cancelled'],true))throw new InvalidArgumentException('Delivered or cancelled orders cannot change fulfillment planning.');
    $location=wholesale_fulfillment_location($pdo,$org,(int)$order['wholesale_account_id'],$input['locationId']??'');
    $requestedStart=wholesale_fulfillment_datetime($input['requestedWindowStart']??null,'Requested window start');
    $requestedEnd=wholesale_fulfillment_datetime($input['requestedWindowEnd']??null,'Requested window end');
    $promisedStart=wholesale_fulfillment_datetime($input['promisedWindowStart']??null,'Promised window start');
    $promisedEnd=wholesale_fulfillment_datetime($input['promisedWindowEnd']??null,'Promised window end');
    wholesale_fulfillment_validate_window($requestedStart,$requestedEnd,'Requested window');
    wholesale_fulfillment_validate_window($promisedStart,$promisedEnd,'Promised window');
    $type=trim((string)($input['fulfillmentType']??$order['fulfillment_type']??''));
    if($type!=='')$type=mb_substr($type,0,40,'UTF-8');
    $pdo->prepare("UPDATE wholesale_orders SET wholesale_account_location_id=?,fulfillment_type=?,requested_window_start=?,requested_window_end=?,promised_window_start=?,promised_window_end=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$location['id']??null,$type?:null,$requestedStart,$requestedEnd,$promisedStart,$promisedEnd,$userId,(int)$order['id'],$org]);
    wholesale_commerce_order_event($pdo,$org,(int)$order['id'],'fulfillment_plan_updated','Wholesale fulfillment location/window updated.',$userId,['locationId'=>$location['public_id']??null,'fulfillmentType'=>$type?:null,'requestedWindow'=>[$requestedStart,$requestedEnd],'promisedWindow'=>[$promisedStart,$promisedEnd]]);
    if(function_exists('app_audit'))app_audit($pdo,$org,$userId,'wholesale.fulfillment_plan_updated','wholesale_order',$orderPublic,null,['locationId'=>$location['public_id']??null,'fulfillmentType'=>$type?:null,'requestedWindowStart'=>$requestedStart,'requestedWindowEnd'=>$requestedEnd,'promisedWindowStart'=>$promisedStart,'promisedWindowEnd'=>$promisedEnd]);
    return wholesale_fulfillment_order_detail($pdo,$org,$orderPublic);
}

function wholesale_fulfillment_order_lines(PDO $pdo,int $org,int $orderId): array
{
    $q=$pdo->prepare("SELECT oi.id,oi.line_number,oi.sku_snapshot,oi.item_name_snapshot,oi.quantity,oi.sell_uom_snapshot,COALESCE(SUM(CASE WHEN f.status<>'cancelled' THEN fi.quantity ELSE 0 END),0) allocated_quantity,COALESCE(SUM(CASE WHEN f.status='delivered' THEN fi.quantity ELSE 0 END),0) delivered_quantity FROM wholesale_order_items oi LEFT JOIN wholesale_fulfillment_items fi ON fi.organization_id=oi.organization_id AND fi.wholesale_order_item_id=oi.id LEFT JOIN wholesale_fulfillments f ON f.id=fi.wholesale_fulfillment_id AND f.organization_id=fi.organization_id WHERE oi.organization_id=? AND oi.wholesale_order_id=? GROUP BY oi.id ORDER BY oi.line_number");
    $q->execute([$org,$orderId]);$rows=[];
    foreach($q->fetchAll() as $row){
        $ordered=(float)$row['quantity'];$allocated=(float)$row['allocated_quantity'];$delivered=(float)$row['delivered_quantity'];
        $rows[]=['id'=>(int)$row['id'],'lineNumber'=>(int)$row['line_number'],'sku'=>$row['sku_snapshot'],'name'=>$row['item_name_snapshot'],'ordered'=>$ordered,'unit'=>$row['sell_uom_snapshot'],'allocated'=>round($allocated,4),'delivered'=>round($delivered,4),'remainingToAllocate'=>round(max(0,$ordered-$allocated),4),'remainingToDeliver'=>round(max(0,$ordered-$delivered),4)];
    }
    return $rows;
}

function wholesale_fulfillment_progress(PDO $pdo,int $org,int $orderId): array
{
    $lines=wholesale_fulfillment_order_lines($pdo,$org,$orderId);
    $ordered=0.0;$allocated=0.0;$delivered=0.0;$allAllocated=!empty($lines);$allDelivered=!empty($lines);
    foreach($lines as $line){$ordered+=$line['ordered'];$allocated+=$line['allocated'];$delivered+=$line['delivered'];if($line['remainingToAllocate']>.0001)$allAllocated=false;if($line['remainingToDeliver']>.0001)$allDelivered=false;}
    return ['lines'=>$lines,'ordered'=>round($ordered,4),'allocated'=>round($allocated,4),'delivered'=>round($delivered,4),'allAllocated'=>$allAllocated,'allDelivered'=>$allDelivered,'partialDelivered'=>$delivered>.0001&&!$allDelivered,'allocationPercent'=>$ordered>0?round(min(100,$allocated/$ordered*100),1):0.0,'deliveryPercent'=>$ordered>0?round(min(100,$delivered/$ordered*100),1):0.0];
}

function wholesale_fulfillment_batch(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT f.*,o.public_id order_public_id,o.order_number,o.wholesale_account_id,o.status order_status,o.fulfillment_type order_fulfillment_type,a.business_name FROM wholesale_fulfillments f JOIN wholesale_orders o ON o.id=f.wholesale_order_id AND o.organization_id=f.organization_id JOIN wholesale_accounts a ON a.id=o.wholesale_account_id AND a.organization_id=o.organization_id WHERE f.organization_id=? AND f.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Wholesale fulfillment batch not found.');
    return $row;
}

function wholesale_fulfillment_batch_items(PDO $pdo,int $org,int $fulfillmentId): array
{
    $q=$pdo->prepare("SELECT fi.id,fi.wholesale_order_item_id,fi.quantity,oi.line_number,oi.sku_snapshot,oi.item_name_snapshot,oi.quantity order_quantity,oi.sell_uom_snapshot FROM wholesale_fulfillment_items fi JOIN wholesale_order_items oi ON oi.id=fi.wholesale_order_item_id AND oi.organization_id=fi.organization_id WHERE fi.organization_id=? AND fi.wholesale_fulfillment_id=? ORDER BY oi.line_number");
    $q->execute([$org,$fulfillmentId]);return $q->fetchAll();
}

function wholesale_fulfillment_consumptions(PDO $pdo,int $org,int $fulfillmentId): array
{
    $q=$pdo->prepare("SELECT c.quantity,c.unit,i.public_id inventory_id,i.name inventory_name,t.transaction_type,t.quantity_delta,t.resulting_quantity,t.created_at FROM wholesale_fulfillment_consumptions c JOIN inventory_items i ON i.id=c.inventory_item_id AND i.organization_id=c.organization_id JOIN inventory_transactions t ON t.id=c.inventory_transaction_id AND t.organization_id=c.organization_id WHERE c.organization_id=? AND c.wholesale_fulfillment_id=? ORDER BY i.name,c.id");
    $q->execute([$org,$fulfillmentId]);return $q->fetchAll();
}

function wholesale_fulfillment_batch_payload(PDO $pdo,int $org,array $batch): array
{
    $items=wholesale_fulfillment_batch_items($pdo,$org,(int)$batch['id']);
    return ['id'=>$batch['public_id'],'number'=>$batch['fulfillment_number'],'orderId'=>$batch['order_public_id'],'orderNumber'=>$batch['order_number'],'businessName'=>$batch['business_name'],'status'=>$batch['status'],'fulfillmentType'=>$batch['fulfillment_type'],'locationId'=>$batch['wholesale_account_location_id'],'requestedWindowStart'=>$batch['requested_window_start'],'requestedWindowEnd'=>$batch['requested_window_end'],'promisedWindowStart'=>$batch['promised_window_start'],'promisedWindowEnd'=>$batch['promised_window_end'],'notes'=>$batch['notes'],'dispatchedAt'=>$batch['dispatched_at'],'deliveredAt'=>$batch['delivered_at'],'cancelledAt'=>$batch['cancelled_at'],'items'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'orderItemId'=>(int)$r['wholesale_order_item_id'],'lineNumber'=>(int)$r['line_number'],'sku'=>$r['sku_snapshot'],'name'=>$r['item_name_snapshot'],'quantity'=>(float)$r['quantity'],'orderedQuantity'=>(float)$r['order_quantity'],'unit'=>$r['sell_uom_snapshot']],$items),'consumptions'=>wholesale_fulfillment_consumptions($pdo,$org,(int)$batch['id'])];
}

function wholesale_fulfillment_batches(PDO $pdo,int $org,int $orderId): array
{
    $q=$pdo->prepare("SELECT f.*,o.public_id order_public_id,o.order_number,o.wholesale_account_id,o.status order_status,o.fulfillment_type order_fulfillment_type,a.business_name FROM wholesale_fulfillments f JOIN wholesale_orders o ON o.id=f.wholesale_order_id AND o.organization_id=f.organization_id JOIN wholesale_accounts a ON a.id=o.wholesale_account_id AND a.organization_id=o.organization_id WHERE f.organization_id=? AND f.wholesale_order_id=? ORDER BY f.id");
    $q->execute([$org,$orderId]);$rows=[];foreach($q->fetchAll() as $batch)$rows[]=wholesale_fulfillment_batch_payload($pdo,$org,$batch);return $rows;
}

function wholesale_fulfillment_order_availability(PDO $pdo,int $org,array $order): array
{
    $lines=wholesale_demand_line_rows($pdo,$org,(int)$order['id']);$byInventory=[];$issues=[];
    foreach($lines as $line){
        $resolved=wholesale_demand_line_requirements($pdo,$org,$order,$line);
        foreach($resolved['issues'] as $issue)$issues[]=$issue;
        foreach($resolved['requirements'] as $r){
            $key=(int)$r['inventoryItemId'];$consumed=wholesale_demand_consumed_quantity($pdo,$org,(int)$line['order_item_id'],$key);$remaining=max(0,(float)$r['quantity']-$consumed);
            if(!isset($byInventory[$key]))$byInventory[$key]=['inventoryId'=>$r['inventoryPublicId'],'name'=>$r['inventoryName'],'unit'=>$r['unit'],'onHand'=>(float)$r['onHand'],'remainingDemand'=>0.0,'shortage'=>0.0,'lines'=>[]];
            $byInventory[$key]['remainingDemand']+=$remaining;$byInventory[$key]['lines'][]=['lineNumber'=>(int)$line['line_number'],'quantity'=>round($remaining,4)];
        }
    }
    ksort($byInventory,SORT_NUMERIC);
    foreach($byInventory as &$row){$row['remainingDemand']=round($row['remainingDemand'],4);$row['shortage']=round(max(0,$row['remainingDemand']-$row['onHand']),4);}unset($row);
    return ['items'=>array_values($byInventory),'issues'=>$issues,'shortages'=>count(array_filter($byInventory,static fn($r)=>$r['shortage']>.0001))];
}

function wholesale_fulfillment_order_detail(PDO $pdo,int $org,string $orderPublic): array
{
    $order=wholesale_fulfillment_order($pdo,$org,$orderPublic);
    $locationsQ=$pdo->prepare("SELECT public_id,name,address_line_1 AS address_line1,address_line_2 AS address_line2,city,state AS state_region,postal_code,country_code,contact_name,phone AS contact_phone,delivery_notes,is_primary FROM wholesale_account_locations WHERE organization_id=? AND wholesale_account_id=? AND status='active' ORDER BY is_primary DESC,name");
    $locationsQ->execute([$org,(int)$order['wholesale_account_id']]);
    return ['order'=>['id'=>$order['public_id'],'number'=>$order['order_number'],'businessName'=>$order['business_name'],'status'=>$order['status'],'fulfillmentType'=>$order['fulfillment_type'],'locationId'=>$order['wholesale_account_location_id'],'requestedFor'=>$order['requested_for'],'promisedFor'=>$order['promised_for'],'requestedWindowStart'=>$order['requested_window_start']??null,'requestedWindowEnd'=>$order['requested_window_end']??null,'promisedWindowStart'=>$order['promised_window_start']??null,'promisedWindowEnd'=>$order['promised_window_end']??null,'deliveredAt'=>$order['delivered_at']],'progress'=>wholesale_fulfillment_progress($pdo,$org,(int)$order['id']),'batches'=>wholesale_fulfillment_batches($pdo,$org,(int)$order['id']),'locations'=>$locationsQ->fetchAll(),'availability'=>wholesale_fulfillment_order_availability($pdo,$org,$order)];
}

function wholesale_fulfillment_orders(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT o.public_id,o.order_number,o.status,o.fulfillment_type,o.requested_for,o.promised_for,o.promised_window_start,o.promised_window_end,a.business_name,(SELECT COUNT(*) FROM wholesale_fulfillments f WHERE f.organization_id=o.organization_id AND f.wholesale_order_id=o.id AND f.status<>'cancelled') fulfillment_count FROM wholesale_orders o JOIN wholesale_accounts a ON a.id=o.wholesale_account_id AND a.organization_id=o.organization_id WHERE o.organization_id=? AND o.status<>'cancelled' ORDER BY FIELD(o.status,'out_for_delivery','ready','in_production','confirmed','requested','delivered'),COALESCE(o.promised_window_start,o.promised_for,o.created_at),o.id DESC LIMIT 250");
    $q->execute([$org]);return $q->fetchAll();
}

function wholesale_fulfillment_create_batch(PDO $pdo,int $org,string $orderPublic,array $input,int $userId): array
{
    if(!wholesale_fulfillment_ready($pdo))throw new RuntimeException('Wholesale fulfillment migration is not installed.');
    $items=(array)($input['items']??[]);if(!$items)throw new InvalidArgumentException('Add at least one order line to the fulfillment batch.');
    $pdo->beginTransaction();
    try{
        $order=wholesale_fulfillment_order($pdo,$org,$orderPublic,true);
        if(in_array((string)$order['status'],['requested','delivered','cancelled'],true))throw new InvalidArgumentException('Only confirmed, in-production, ready, or out-for-delivery orders can be allocated to fulfillment batches.');
        $location=wholesale_fulfillment_location($pdo,$org,(int)$order['wholesale_account_id'],$input['locationId']??($order['wholesale_account_location_id']??''));
        $type=trim((string)($input['fulfillmentType']??$order['fulfillment_type']??'pickup'))?:'pickup';$type=mb_substr($type,0,40,'UTF-8');
        $requestedStart=wholesale_fulfillment_datetime($input['requestedWindowStart']??($order['requested_window_start']??null),'Requested window start');
        $requestedEnd=wholesale_fulfillment_datetime($input['requestedWindowEnd']??($order['requested_window_end']??null),'Requested window end');
        $promisedStart=wholesale_fulfillment_datetime($input['promisedWindowStart']??($order['promised_window_start']??null),'Promised window start');
        $promisedEnd=wholesale_fulfillment_datetime($input['promisedWindowEnd']??($order['promised_window_end']??null),'Promised window end');
        wholesale_fulfillment_validate_window($requestedStart,$requestedEnd,'Requested window');wholesale_fulfillment_validate_window($promisedStart,$promisedEnd,'Promised window');
        $lineRows=wholesale_fulfillment_order_lines($pdo,$org,(int)$order['id']);$lineMap=[];foreach($lineRows as $r)$lineMap[(int)$r['lineNumber']]=$r;
        $normalized=[];
        foreach($items as $raw){
            if(!is_array($raw))continue;$line=(int)($raw['lineNumber']??0);$qty=(float)($raw['quantity']??0);if($line<=0||$qty<=0)continue;
            if(!isset($lineMap[$line]))throw new InvalidArgumentException('Order line '.$line.' was not found.');
            if($qty-$lineMap[$line]['remainingToAllocate']>.0001)throw new InvalidArgumentException('Order line '.$line.' exceeds its remaining unallocated quantity of '.$lineMap[$line]['remainingToAllocate'].'.');
            $normalized[$line]=($normalized[$line]??0)+$qty;
        }
        if(!$normalized)throw new InvalidArgumentException('Add a positive quantity to at least one order line.');
        foreach($normalized as $line=>$qty)if($qty-$lineMap[$line]['remainingToAllocate']>.0001)throw new InvalidArgumentException('Order line '.$line.' exceeds its remaining unallocated quantity.');
        $countQ=$pdo->prepare('SELECT COUNT(*) FROM wholesale_fulfillments WHERE organization_id=? AND wholesale_order_id=? FOR UPDATE');$countQ->execute([$org,(int)$order['id']]);$sequence=(int)$countQ->fetchColumn()+1;
        $public='wful-'.bin2hex(random_bytes(10));$number=(string)$order['order_number'].'-F'.str_pad((string)$sequence,2,'0',STR_PAD_LEFT);$notes=mb_substr(trim((string)($input['notes']??'')),0,10000,'UTF-8')?:null;
        $pdo->prepare("INSERT INTO wholesale_fulfillments (organization_id,wholesale_order_id,wholesale_account_location_id,public_id,fulfillment_number,fulfillment_type,status,requested_window_start,requested_window_end,promised_window_start,promised_window_end,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,'draft',?,?,?,?,?,?,?)")->execute([$org,(int)$order['id'],$location['id']??null,$public,$number,$type,$requestedStart,$requestedEnd,$promisedStart,$promisedEnd,$notes,$userId,$userId]);
        $fulfillmentId=(int)$pdo->lastInsertId();$insert=$pdo->prepare('INSERT INTO wholesale_fulfillment_items (organization_id,wholesale_fulfillment_id,wholesale_order_item_id,quantity) VALUES (?,?,?,?)');
        foreach($normalized as $line=>$qty)$insert->execute([$org,$fulfillmentId,(int)$lineMap[$line]['id'],$qty]);
        wholesale_commerce_order_event($pdo,$org,(int)$order['id'],'fulfillment_created','Fulfillment batch '.$number.' created.',$userId,['fulfillmentId'=>$public,'items'=>$normalized]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(function_exists('app_audit'))app_audit($pdo,$org,$userId,'wholesale.fulfillment_created','wholesale_fulfillment',$public,null,['orderId'=>$orderPublic,'number'=>$number]);
    return wholesale_fulfillment_batch_payload($pdo,$org,wholesale_fulfillment_batch($pdo,$org,$public));
}

function wholesale_fulfillment_set_status(PDO $pdo,int $org,string $publicId,string $next,int $userId): array
{
    if(!in_array($next,['ready','dispatched','cancelled'],true))throw new InvalidArgumentException('Unsupported fulfillment transition.');
    $pdo->beginTransaction();
    try{
        $batch=wholesale_fulfillment_batch($pdo,$org,$publicId,true);$current=(string)$batch['status'];
        if($current===$next){$pdo->commit();return wholesale_fulfillment_batch_payload($pdo,$org,$batch);}
        if(in_array($current,['delivered','cancelled'],true))throw new InvalidArgumentException('Delivered or cancelled fulfillment batches are read-only.');
        $allowed=['draft'=>['ready','cancelled'],'ready'=>['dispatched','cancelled'],'dispatched'=>[]];
        if(!in_array($next,$allowed[$current]??[],true))throw new InvalidArgumentException('Fulfillment cannot move from '.$current.' to '.$next.'.');
        if($next==='dispatched'&&!in_array((string)$batch['order_status'],['ready','out_for_delivery'],true))throw new InvalidArgumentException('Wholesale production must be ready before dispatch.');
        if($next==='dispatched'){
            $pdo->prepare("UPDATE wholesale_fulfillments SET status='dispatched',dispatched_at=COALESCE(dispatched_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$userId,(int)$batch['id'],$org]);
            if(stripos((string)$batch['fulfillment_type'],'delivery')!==false||stripos((string)$batch['fulfillment_type'],'ship')!==false)$pdo->prepare("UPDATE wholesale_orders SET status='out_for_delivery',updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND status='ready'")->execute([$userId,(int)$batch['wholesale_order_id'],$org]);
        }elseif($next==='ready'){
            $pdo->prepare("UPDATE wholesale_fulfillments SET status='ready',updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$userId,(int)$batch['id'],$org]);
        }else{
            $pdo->prepare("UPDATE wholesale_fulfillments SET status='cancelled',cancelled_at=COALESCE(cancelled_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$userId,(int)$batch['id'],$org]);
        }
        wholesale_commerce_order_event($pdo,$org,(int)$batch['wholesale_order_id'],'fulfillment_'.$next,'Fulfillment batch '.$batch['fulfillment_number'].' moved to '.$next.'.',$userId,['fulfillmentId'=>$publicId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(function_exists('app_audit'))app_audit($pdo,$org,$userId,'wholesale.fulfillment_'.$next,'wholesale_fulfillment',$publicId,['status'=>$current],['status'=>$next]);
    return wholesale_fulfillment_batch_payload($pdo,$org,wholesale_fulfillment_batch($pdo,$org,$publicId));
}

function wholesale_fulfillment_batch_availability(PDO $pdo,int $org,array $batch): array
{
    $order=wholesale_demand_order($pdo,$org,(int)$batch['wholesale_order_id']);if(!$order)throw new RuntimeException('Wholesale order not found for fulfillment.');
    $demandLines=wholesale_demand_line_rows($pdo,$org,(int)$order['id']);$lineByItem=[];foreach($demandLines as $line)$lineByItem[(int)$line['order_item_id']]=$line;
    $aggregated=[];$issues=[];
    foreach(wholesale_fulfillment_batch_items($pdo,$org,(int)$batch['id']) as $item){
        $line=$lineByItem[(int)$item['wholesale_order_item_id']]??null;if(!$line)throw new RuntimeException('Fulfillment line is no longer attached to the order.');
        $resolved=wholesale_demand_line_requirements($pdo,$org,$order,$line);
        foreach($resolved['issues'] as $issue)$issues[]=$issue;
        $ratio=(float)$line['order_quantity']>0?(float)$item['quantity']/(float)$line['order_quantity']:0;
        foreach($resolved['requirements'] as $r){$id=(int)$r['inventoryItemId'];$qty=round((float)$r['quantity']*$ratio,4);if($qty<=0)continue;if(!isset($aggregated[$id]))$aggregated[$id]=['inventoryItemId'=>$id,'inventoryId'=>$r['inventoryPublicId'],'name'=>$r['inventoryName'],'unit'=>$r['unit'],'onHand'=>(float)$r['onHand'],'required'=>0.0,'shortage'=>0.0,'lines'=>[]];$aggregated[$id]['required']+=$qty;$aggregated[$id]['lines'][]=['fulfillmentItemId'=>(int)$item['id'],'orderItemId'=>(int)$item['wholesale_order_item_id'],'lineNumber'=>(int)$line['line_number'],'quantity'=>$qty,'commitmentPublicId'=>$r['commitmentPublicId']];}
    }
    ksort($aggregated,SORT_NUMERIC);
    foreach($aggregated as &$r){$r['required']=round($r['required'],4);$r['shortage']=round(max(0,$r['required']-$r['onHand']),4);}unset($r);
    return ['items'=>array_values($aggregated),'issues'=>$issues,'shortages'=>count(array_filter($aggregated,static fn($r)=>$r['shortage']>.0001))];
}

function wholesale_fulfillment_deliver(PDO $pdo,int $org,string $publicId,int $userId): array
{
    if(!wholesale_fulfillment_ready($pdo))throw new RuntimeException('Wholesale fulfillment migration is not installed.');
    $pdo->beginTransaction();
    try{
        $batch=wholesale_fulfillment_batch($pdo,$org,$publicId,true);
        if((string)$batch['status']==='delivered'){$pdo->commit();return wholesale_fulfillment_batch_payload($pdo,$org,$batch);}
        if((string)$batch['status']==='cancelled')throw new InvalidArgumentException('Cancelled fulfillment batches cannot be delivered.');
        if(!in_array((string)$batch['status'],['ready','dispatched'],true))throw new InvalidArgumentException('Mark the fulfillment batch ready before delivery.');
        if(!in_array((string)$batch['order_status'],['ready','out_for_delivery'],true))throw new InvalidArgumentException('Wholesale production must be ready before delivery.');
        $availability=wholesale_fulfillment_batch_availability($pdo,$org,$batch);
        if($availability['issues'])throw new RuntimeException('Inventory consumption cannot be resolved for this batch: '.($availability['issues'][0]['message']??'Resolve the Wholesale demand mapping first.'));
        if((int)$availability['shortages']>0){$short=array_values(array_filter($availability['items'],static fn($r)=>$r['shortage']>.0001));$first=$short[0];throw new RuntimeException('Insufficient '.$first['name'].' for delivery: short '.rtrim(rtrim(number_format((float)$first['shortage'],4,'.',''),'0'),'.').' '.$first['unit'].'.');}
        $transaction=$pdo->prepare("INSERT INTO inventory_transactions (organization_id,inventory_item_id,transaction_type,quantity_delta,resulting_quantity,unit,note,source_type,source_public_id,created_by) VALUES (?,?,'use',?,?,?,?, 'wholesale_fulfillment',?,?)");
        $consume=$pdo->prepare("INSERT INTO wholesale_fulfillment_consumptions (organization_id,wholesale_fulfillment_id,wholesale_fulfillment_item_id,wholesale_order_item_id,inventory_item_id,inventory_transaction_id,source_inventory_commitment_public_id,quantity,unit) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach($availability['items'] as $inventory){
            foreach($inventory['lines'] as $line){
                $existing=$pdo->prepare('SELECT id FROM wholesale_fulfillment_consumptions WHERE organization_id=? AND wholesale_fulfillment_item_id=? AND inventory_item_id=? LIMIT 1');$existing->execute([$org,$line['fulfillmentItemId'],$inventory['inventoryItemId']]);if($existing->fetchColumn())continue;
                $lock=$pdo->prepare('SELECT public_id,name,base_unit,on_hand_quantity FROM inventory_items WHERE organization_id=? AND id=? AND archived_at IS NULL FOR UPDATE');$lock->execute([$org,$inventory['inventoryItemId']]);$stock=$lock->fetch();if(!$stock)throw new RuntimeException('Inventory item disappeared during fulfillment.');
                $needed=(float)$line['quantity'];$on=(float)$stock['on_hand_quantity'];if($on+0.0001<$needed)throw new RuntimeException('Inventory changed during delivery and '.$stock['name'].' is now short. Retry after replenishment or a corrected count.');$result=round($on-$needed,4);
                $pdo->prepare('UPDATE inventory_items SET on_hand_quantity=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$result,$userId,$org,$inventory['inventoryItemId']]);
                $source=mb_substr($publicId.':line:'.$line['lineNumber'],0,160,'UTF-8');$note='Wholesale fulfillment '.$batch['fulfillment_number'].' · order '.$batch['order_number'].' · line '.$line['lineNumber'];
                $transaction->execute([$org,$inventory['inventoryItemId'],-$needed,$result,$stock['base_unit'],$note,$source,$userId]);$txId=(int)$pdo->lastInsertId();
                $consume->execute([$org,(int)$batch['id'],$line['fulfillmentItemId'],$line['orderItemId'],$inventory['inventoryItemId'],$txId,$line['commitmentPublicId'],$needed,$stock['base_unit']?:$inventory['unit']]);
            }
        }
        $pdo->prepare("UPDATE wholesale_fulfillments SET status='delivered',delivered_at=COALESCE(delivered_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,(int)$batch['id']]);
        $progress=wholesale_fulfillment_progress($pdo,$org,(int)$batch['wholesale_order_id']);
        if($progress['allDelivered'])$pdo->prepare("UPDATE wholesale_orders SET status='delivered',delivered_at=COALESCE(delivered_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,(int)$batch['wholesale_order_id']]);
        elseif((string)$batch['order_status']==='out_for_delivery')$pdo->prepare("UPDATE wholesale_orders SET status='ready',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,(int)$batch['wholesale_order_id']]);
        wholesale_commerce_order_event($pdo,$org,(int)$batch['wholesale_order_id'],'fulfillment_delivered','Fulfillment batch '.$batch['fulfillment_number'].' delivered.',$userId,['fulfillmentId'=>$publicId,'partial'=>!$progress['allDelivered'],'deliveryPercent'=>$progress['deliveryPercent']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(wholesale_demand_ready($pdo))wholesale_demand_sync_order($pdo,$org,(int)$batch['wholesale_order_id'],$userId);
    wholesale_portal_sync_account_knowledge($pdo,$org,(int)$batch['wholesale_account_id'],$userId);
    if(function_exists('app_audit'))app_audit($pdo,$org,$userId,'wholesale.fulfillment_delivered','wholesale_fulfillment',$publicId,null,['orderId'=>$batch['order_public_id']]);
    return wholesale_fulfillment_batch_payload($pdo,$org,wholesale_fulfillment_batch($pdo,$org,$publicId));
}
