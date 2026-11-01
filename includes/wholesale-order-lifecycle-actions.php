<?php
declare(strict_types=1);

/** B8D18J9: confirmation-time wholesale order lifecycle operations. */
require_once __DIR__.'/wholesale-agent-core.php';

function wol_expected(array $order,array $expected):void
{
    if((string)$order['status']!==(string)($expected['status']??'')
        ||(string)$order['updated_at']!==(string)($expected['updatedAt']??'')){
        throw new InvalidArgumentException('This Wholesale order changed since the proposal. Review it and request the action again.');
    }
}
function wol_count(PDO $pdo,string $sql,int $org,int $id):int
{
    $q=$pdo->prepare($sql);$q->execute([$org,$id]);return (int)$q->fetchColumn();
}
function wol_no_batches(PDO $pdo,int $org,int $id):void
{
    if(wol_count($pdo,'SELECT COUNT(*) FROM wholesale_fulfillments WHERE organization_id=? AND wholesale_order_id=?',$org,$id)>0)
        throw new InvalidArgumentException('Existing fulfillment batches lock this order lifecycle action. Review the batches in Wholesale Fulfillment.');
}
function wol_can_cancel(PDO $pdo,int $org,array $order):void
{
    if(!in_array((string)$order['status'],['requested','confirmed'],true))
        throw new InvalidArgumentException('Only a requested or confirmed order without production can be cancelled here.');
    $id=(int)$order['id'];
    wol_no_batches($pdo,$org,$id);
    if(!wholesale_portal_table_ready($pdo,'wholesale_invoices'))
        throw new RuntimeException('Wholesale invoice verification is unavailable. Run upgrade.php first.');
    if(wol_count($pdo,'SELECT COUNT(*) FROM wholesale_invoices WHERE organization_id=? AND wholesale_order_id=?',$org,$id)>0)
        throw new InvalidArgumentException('This order has an invoice. Resolve it with Wholesale Receivables before attempting cancellation.');
    $q=$pdo->prepare("SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id=? AND archived_at IS NULL AND ((source_type='wholesale_order_item' AND source_public_id LIKE ?) OR (source_type='wholesale_order_fulfillment' AND source_public_id=?)) AND status NOT IN ('queued','pending','cancelled')");
    $q->execute([$org,'wholesale-order-'.$id.'-item-%','wholesale-order-'.$id.'-fulfillment']);
    if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Operations work has started on this order. Resolve production before cancellation.');
}
function wol_execute_pending(PDO $pdo,int $org,int $uid,array $user,array $proposal):array
{
    waa_require($user,'wholesale.manage','Wholesale management permission is required for this action.');
    $type=(string)$proposal['type'];$payload=(array)$proposal['payload'];
    if(!in_array($type,['order_confirm','order_cancel','order_promise','order_location'],true))
        throw new InvalidArgumentException('Unsupported Wholesale order proposal.');
    $pdo->beginTransaction();
    try{
        $order=wholesale_fulfillment_order($pdo,$org,(string)($payload['orderPublicId']??''),true);
        wol_expected($order,(array)($payload['expectedOrder']??[]));
        if((string)$order['order_number']!==(string)($payload['orderNumber']??''))
            throw new InvalidArgumentException('The saved order number no longer matches.');
        if((string)$order['account_status']!=='active')
            throw new InvalidArgumentException('The Wholesale account is not active.');
        $id=(int)$order['id'];
        $number=(string)$order['order_number'];
        if($type==='order_confirm'){
            if((string)$order['status']!=='requested')throw new InvalidArgumentException('Only requested orders can be confirmed.');
            if(!wholesale_commerce_ready($pdo))throw new RuntimeException('Wholesale commerce is unavailable. Run upgrade.php.');
            $lines=wholesale_fulfillment_order_lines($pdo,$org,$id);
            if(!$lines)throw new InvalidArgumentException('Cannot confirm an order without canonical saved lines.');
            if(($payload['acknowledgeSavedTotal']??false)!==true
                ||abs((float)$order['total']-(float)($payload['expectedTotal']??-1))>.005)
                throw new InvalidArgumentException('The saved order total does not match the explicitly acknowledged total.');
            $pdo->prepare("UPDATE wholesale_orders SET status='confirmed',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND status='requested'")
                ->execute([$uid,$org,$id]);
            wholesale_commerce_order_event($pdo,$org,$id,'confirmed','Wholesale requested order confirmed by authorized manager.',$uid,['proposalId'=>$proposal['id']]);
            $answer='Confirmed order '.$number.' at its saved total; Operations now owns production and delivery.';
        }elseif($type==='order_cancel'){
            if(($payload['acknowledgeCancel']??false)!==true)throw new InvalidArgumentException('Explicit order cancellation acknowledgment is required.');
            $reason=trim((string)($payload['reason']??''));
            if($reason===''||mb_strlen($reason,'UTF-8')>500)throw new InvalidArgumentException('A specific cancellation reason up to 500 characters is required.');
            wol_can_cancel($pdo,$org,$order);
            $pdo->prepare("UPDATE wholesale_orders SET status='cancelled',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
                ->execute([$uid,$org,$id]);
            wholesale_commerce_order_event($pdo,$org,$id,'cancelled','Wholesale order cancelled before production.',$uid,['reason'=>$reason,'proposalId'=>$proposal['id']]);
            $answer='Cancelled order '.$number.' before production; no invoice or fulfillment batch was present.';
        }else{
            if(in_array((string)$order['status'],['delivered','cancelled'],true))
                throw new InvalidArgumentException('Delivered or cancelled orders cannot change fulfillment planning.');
            wol_no_batches($pdo,$org,$id);
            $plan=[
                'locationId'=>$order['wholesale_account_location_id']??'',
                'fulfillmentType'=>$order['fulfillment_type']??'',
                'requestedWindowStart'=>$order['requested_window_start']??'',
                'requestedWindowEnd'=>$order['requested_window_end']??'',
                'promisedWindowStart'=>$order['promised_window_start']??'',
                'promisedWindowEnd'=>$order['promised_window_end']??'',
            ];
            if($type==='order_promise'){
                $start=wholesale_fulfillment_datetime($payload['start']??null,'Promised window start');
                $end=wholesale_fulfillment_datetime($payload['end']??null,'Promised window end');
                if($start===null||$end===null)throw new InvalidArgumentException('A complete promised start and end is required.');
                wholesale_fulfillment_validate_window($start,$end,'Promised window');
                $plan['promisedWindowStart']=$start;$plan['promisedWindowEnd']=$end;
                $answer='Updated the promised fulfillment window for order '.$number.'; other planning fields are preserved.';
            }else{
                $location=wholesale_fulfillment_location($pdo,$org,(int)$order['wholesale_account_id'],$payload['locationPublicId']??'');
                if(!$location||(string)$location['public_id']!==(string)($payload['locationPublicId']??'')
                    ||(string)$location['name']!==(string)($payload['locationName']??''))
                    throw new InvalidArgumentException('The exact active Wholesale account location no longer matches.');
                $plan['locationId']=(string)$location['public_id'];
                $answer='Updated the fulfillment location for order '.$number.'; other planning fields are preserved.';
            }
            wholesale_fulfillment_save_order_plan($pdo,$org,(string)$order['public_id'],$plan,$uid);
        }
        app_audit($pdo,$org,$uid,'wholesale.agent_'.$type,'wholesale_order',(string)$order['public_id'],null,
            ['proposalId'=>$proposal['id'],'statusBefore'=>$order['status'],'orderNumber'=>$number]);
        // Keep canonical Operations, demand, and account projections in the same commit.
        if(in_array($type,['order_confirm','order_cancel'],true)){
            operations_sync_wholesale_tasks($pdo,$org,$uid);
            wholesale_portal_sync_account_knowledge($pdo,$org,(int)$order['wholesale_account_id'],$uid);
            if(wholesale_demand_ready($pdo))wholesale_demand_sync_order($pdo,$org,(int)$order['id'],$uid);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['skill'=>'wholesale.action_confirmed','answer'=>'Confirmed. '.$answer,
        'data'=>['action'=>$type,'orderId'=>$order['public_id'],'orderNumber'=>$number],
        'sources'=>['Wholesale Orders','Wholesale Fulfillment']];
}
