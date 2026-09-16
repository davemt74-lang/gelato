<?php
declare(strict_types=1);

require_once __DIR__.'/wholesale-fulfillment.php';
require_once __DIR__.'/operations-wholesale-agent.php';
require_once __DIR__.'/agent-confirmation-core.php';

final class WholesaleAgentPermissionException extends RuntimeException {}

function waa_require(array $user,string $permission,string $message): void
{
    if(!app_has_permission($permission,$user))throw new WholesaleAgentPermissionException($message);
}

function waa_clean_public_id(mixed $value): string
{
    return preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)$value))??'';
}

function waa_context_order(PDO $pdo,int $org,array $context): ?array
{
    if((string)($context['module']??'')!=='wholesale')return null;
    $public=waa_clean_public_id($context['selectedWholesaleOrderPublicId']??'');
    if($public==='')return null;
    try{return wholesale_fulfillment_order($pdo,$org,$public);}catch(Throwable){return null;}
}

function waa_order_version(array $order): array
{
    return ['status'=>(string)($order['status']??''),'updatedAt'=>(string)($order['updated_at']??'')];
}

function waa_guard_order(PDO $pdo,int $org,string $publicId,array $expected): array
{
    $order=wholesale_fulfillment_order($pdo,$org,$publicId);
    if((string)$order['status']!==(string)($expected['status']??'')||(string)$order['updated_at']!==(string)($expected['updatedAt']??'')){
        throw new InvalidArgumentException('That Wholesale order changed after I proposed the action. Review the current order and ask again.');
    }
    return $order;
}

function waa_progress_signature(array $progress): string
{
    $rows=[];
    foreach((array)($progress['lines']??[]) as $line)$rows[]=[
        'line'=>(int)$line['lineNumber'],
        'ordered'=>round((float)$line['ordered'],4),
        'allocated'=>round((float)$line['allocated'],4),
        'delivered'=>round((float)$line['delivered'],4),
        'remaining'=>round((float)$line['remainingToAllocate'],4),
    ];
    return hash('sha256',json_encode($rows,JSON_UNESCAPED_SLASHES));
}

function waa_batch_version(array $batch): array
{
    return ['status'=>(string)$batch['status'],'updatedAt'=>(string)($batch['updated_at']??'')];
}

function waa_guard_batch(PDO $pdo,int $org,string $publicId,array $expected): array
{
    $batch=wholesale_fulfillment_batch($pdo,$org,$publicId);
    if((string)$batch['status']!==(string)($expected['status']??'')||(string)$batch['updated_at']!==(string)($expected['updatedAt']??'')){
        throw new InvalidArgumentException('That Wholesale fulfillment batch changed after I proposed the action. Review it and ask again.');
    }
    return $batch;
}

function waa_find_batch(PDO $pdo,int $org,array $order,string $message,array $context): array
{
    $contextPublic=waa_clean_public_id($context['selectedWholesaleBatchPublicId']??'');
    if($contextPublic!==''){
        $batch=wholesale_fulfillment_batch($pdo,$org,$contextPublic);
        if((int)$batch['wholesale_order_id']!==(int)$order['id'])throw new InvalidArgumentException('The selected fulfillment batch does not belong to the selected Wholesale order.');
        return $batch;
    }
    $batches=wholesale_fulfillment_batches($pdo,$org,(int)$order['id']);
    $lower=mb_strtolower($message,'UTF-8');$matches=[];
    foreach($batches as $row){
        $number=mb_strtolower((string)$row['number'],'UTF-8');$public=mb_strtolower((string)$row['id'],'UTF-8');
        if(($number!==''&&str_contains($lower,$number))||($public!==''&&str_contains($lower,$public)))$matches[]=$row;
    }
    if(count($matches)===1)return wholesale_fulfillment_batch($pdo,$org,(string)$matches[0]['id']);
    if(count($matches)>1)throw new InvalidArgumentException('More than one fulfillment batch matched that request. Name the exact batch number.');
    $active=array_values(array_filter($batches,static fn($row)=>!in_array((string)$row['status'],['delivered','cancelled'],true)));
    if(count($active)===1)return wholesale_fulfillment_batch($pdo,$org,(string)$active[0]['id']);
    if(!$active&&count($batches)===1)return wholesale_fulfillment_batch($pdo,$org,(string)$batches[0]['id']);
    if(!$batches)throw new InvalidArgumentException('This Wholesale order does not have a fulfillment batch yet.');
    $names=array_map(static fn($row)=>(string)$row['number'].' ('.(string)$row['status'].')',array_slice($batches,0,8));
    throw new InvalidArgumentException('Name the fulfillment batch you mean: '.implode('; ',$names).'.');
}

function waa_order_summary(PDO $pdo,int $org,array $order): array
{
    $detail=wholesale_fulfillment_order_detail($pdo,$org,(string)$order['public_id']);$o=$detail['order'];$p=$detail['progress'];$a=$detail['availability'];
    $answer=$o['number'].' · '.$o['businessName'].' · '.str_replace('_',' ',$o['status']).'. '
        .'Allocated '.$p['allocationPercent'].'%; delivered '.$p['deliveryPercent'].'%; '.count($detail['batches']).' fulfillment batch'.(count($detail['batches'])===1?'':'es').'; '.$a['shortages'].' ATP shortage'.((int)$a['shortages']===1?'':'s').'.';
    if($o['promisedWindowStart'])$answer.=' Promised window starts '.$o['promisedWindowStart'].'.';
    elseif($o['promisedFor'])$answer.=' Promised for '.$o['promisedFor'].'.';
    return ['ok'=>true,'skill'=>'wholesale.context_order','answer'=>$answer,'data'=>['orderId'=>$o['id'],'progress'=>$p,'shortages'=>$a['shortages']],'sources'=>['Wholesale Fulfillment']];
}

function waa_shortages_answer(PDO $pdo,int $org,array $order): array
{
    $detail=wholesale_fulfillment_order_detail($pdo,$org,(string)$order['public_id']);$availability=$detail['availability'];
    $short=array_values(array_filter((array)$availability['items'],static fn($row)=>(float)$row['shortage']>.0001));
    if(!$short)return ['ok'=>true,'skill'=>'wholesale.context_shortages','answer'=>'No ATP inventory shortages are currently projected for '.$detail['order']['number'].'.','data'=>['orderId'=>$order['public_id'],'shortages'=>0],'sources'=>['Wholesale Demand Commitments','Inventory']];
    $parts=[];foreach(array_slice($short,0,12) as $row)$parts[]=$row['name'].' short '.rtrim(rtrim(number_format((float)$row['shortage'],4,'.',''),'0'),'.').' '.$row['unit'].' (on hand '.rtrim(rtrim(number_format((float)$row['onHand'],4,'.',''),'0'),'.').')';
    return ['ok'=>true,'skill'=>'wholesale.context_shortages','answer'=>'Wholesale shortages for '.$detail['order']['number'].': '.implode('; ',$parts).'.','data'=>['orderId'=>$order['public_id'],'shortages'=>count($short)],'sources'=>['Wholesale Demand Commitments','Inventory']];
}

function waa_batches_answer(PDO $pdo,int $org,array $order): array
{
    $detail=wholesale_fulfillment_order_detail($pdo,$org,(string)$order['public_id']);$batches=(array)$detail['batches'];
    if(!$batches)return ['ok'=>true,'skill'=>'wholesale.context_batches','answer'=>$detail['order']['number'].' has no fulfillment batches yet.','data'=>['orderId'=>$order['public_id'],'batches'=>0],'sources'=>['Wholesale Fulfillment']];
    $parts=[];foreach(array_slice($batches,0,10) as $row)$parts[]=$row['number'].' · '.str_replace('_',' ',$row['status']).' · '.$row['fulfillmentType'].' · '.count((array)$row['items']).' line'.(count((array)$row['items'])===1?'':'s');
    return ['ok'=>true,'skill'=>'wholesale.context_batches','answer'=>'Fulfillment batches: '.implode('; ',$parts).'.','data'=>['orderId'=>$order['public_id'],'batches'=>count($batches)],'sources'=>['Wholesale Fulfillment']];
}

function waa_open_orders_answer(PDO $pdo,int $org): array
{
    $rows=wholesale_fulfillment_orders($pdo,$org);
    $active=array_values(array_filter($rows,static fn($row)=>!in_array((string)$row['status'],['delivered','cancelled'],true)));
    if(!$active)return ['ok'=>true,'skill'=>'wholesale.orders','answer'=>'There are no active Wholesale orders awaiting fulfillment.','data'=>['orders'=>0],'sources'=>['Wholesale Orders','Wholesale Fulfillment']];
    $parts=[];foreach(array_slice($active,0,10) as $row)$parts[]=$row['order_number'].' · '.$row['business_name'].' · '.str_replace('_',' ',$row['status']).' · '.(int)$row['fulfillment_count'].' batch'.((int)$row['fulfillment_count']===1?'':'es');
    return ['ok'=>true,'skill'=>'wholesale.orders','answer'=>'Active Wholesale fulfillment: '.implode('; ',$parts).'.','data'=>['orders'=>count($active)],'sources'=>['Wholesale Orders','Wholesale Fulfillment']];
}

function waa_execute_pending(PDO $pdo,int $org,int $uid,array $user,array $proposal): array
{
    if((int)($proposal['organizationId']??0)!==$org||(int)($proposal['userId']??0)!==$uid)throw new WholesaleAgentPermissionException('This Wholesale Agent proposal does not belong to your session.');
    $type=(string)$proposal['type'];$payload=(array)$proposal['payload'];
    waa_require($user,'wholesale.manage','Wholesale management permission is required for this action.');

    if($type==='create_remaining_batch'){
        $order=waa_guard_order($pdo,$org,(string)$payload['orderPublicId'],(array)$payload['expectedOrder']);
        $progress=wholesale_fulfillment_progress($pdo,$org,(int)$order['id']);
        if(waa_progress_signature($progress)!==(string)$payload['progressSignature'])throw new InvalidArgumentException('Wholesale allocation changed after I proposed the batch. Review the order and ask again.');
        $items=[];foreach((array)$progress['lines'] as $line)if((float)$line['remainingToAllocate']>.0001)$items[]=['lineNumber'=>(int)$line['lineNumber'],'quantity'=>(float)$line['remainingToAllocate']];
        if(!$items)throw new InvalidArgumentException('This Wholesale order is already fully allocated.');
        $batch=wholesale_fulfillment_create_batch($pdo,$org,(string)$order['public_id'],['items'=>$items,'locationId'=>$order['wholesale_account_location_id']??'','fulfillmentType'=>$order['fulfillment_type']??'pickup','requestedWindowStart'=>$order['requested_window_start']??null,'requestedWindowEnd'=>$order['requested_window_end']??null,'promisedWindowStart'=>$order['promised_window_start']??null,'promisedWindowEnd'=>$order['promised_window_end']??null,'notes'=>'Created through Gelato Agent after explicit confirmation.'],$uid);
        operations_sync_wholesale_tasks($pdo,$org,$uid);
        app_audit($pdo,$org,$uid,'wholesale.agent_batch_created','wholesale_fulfillment',(string)$batch['id'],null,['proposalId'=>$proposal['id'],'orderId'=>$order['public_id']]);
        return ['skill'=>'wholesale.action_confirmed','answer'=>'Confirmed. Created fulfillment batch '.$batch['number'].' with all currently unallocated quantities for '.$order['order_number'].'.','data'=>['action'=>'create_remaining_batch','orderId'=>$order['public_id'],'batchId'=>$batch['id']],'sources'=>['Wholesale Fulfillment']];
    }

    if(in_array($type,['batch_ready','batch_dispatch','batch_cancel','batch_deliver'],true)){
        $batch=waa_guard_batch($pdo,$org,(string)$payload['batchPublicId'],(array)$payload['expectedBatch']);
        if($type==='batch_deliver'){
            waa_require($user,'inventory.manage','Delivering a Wholesale fulfillment consumes physical inventory and requires inventory.manage permission.');
            $saved=wholesale_fulfillment_deliver($pdo,$org,(string)$batch['public_id'],$uid);
            operations_sync_wholesale_tasks($pdo,$org,$uid);
            app_audit($pdo,$org,$uid,'wholesale.agent_batch_delivered','wholesale_fulfillment',(string)$batch['public_id'],null,['proposalId'=>$proposal['id'],'orderId'=>$batch['order_public_id']]);
            return ['skill'=>'wholesale.action_confirmed','answer'=>'Confirmed. Marked '.$batch['fulfillment_number'].' delivered and consumed its canonical inventory requirements.','data'=>['action'=>'batch_deliver','batchId'=>$batch['public_id'],'orderId'=>$batch['order_public_id']],'sources'=>['Wholesale Fulfillment','Inventory']];
        }
        $next=['batch_ready'=>'ready','batch_dispatch'=>'dispatched','batch_cancel'=>'cancelled'][$type];
        $saved=wholesale_fulfillment_set_status($pdo,$org,(string)$batch['public_id'],$next,$uid);
        operations_sync_wholesale_tasks($pdo,$org,$uid);
        app_audit($pdo,$org,$uid,'wholesale.agent_batch_'.$next,'wholesale_fulfillment',(string)$batch['public_id'],null,['proposalId'=>$proposal['id'],'orderId'=>$batch['order_public_id']]);
        return ['skill'=>'wholesale.action_confirmed','answer'=>'Confirmed. Moved '.$batch['fulfillment_number'].' to '.str_replace('_',' ',$next).'.','data'=>['action'=>$type,'batchId'=>$batch['public_id'],'orderId'=>$batch['order_public_id'],'status'=>$saved['status']??$next],'sources'=>['Wholesale Fulfillment']];
    }

    throw new InvalidArgumentException('That pending Wholesale Agent action is no longer supported.');
}

function wholesale_agent_handle(PDO $pdo,array $user,array $input): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    waa_require($user,'wholesale.view','Wholesale view permission is required.');
    waa_require($user,'wholesale.agent','Wholesale Agent permission is required.');
    $message=trim((string)($input['message']??''));
    if($message===''||mb_strlen($message,'UTF-8')>1800)throw new InvalidArgumentException('Ask Gelato a Wholesale question no longer than 1,800 characters.');
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];
    $order=waa_context_order($pdo,$org,$context);
    $pending=gac_pending_get('wholesale',$org,$uid);

    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Wholesale Agent action to confirm.');
        try{$result=waa_execute_pending($pdo,$org,$uid,$user,$pending);}catch(Throwable $e){gac_pending_clear('wholesale',$org,$uid);throw $e;}
        gac_pending_clear('wholesale',$org,$uid);
        app_audit($pdo,$org,$uid,'wholesale.agent_action_confirmed','wholesale_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);
        return ['ok'=>true]+$result;
    }
    if(gac_is_cancel($message)&&$pending){
        gac_pending_clear('wholesale',$org,$uid);
        app_audit($pdo,$org,$uid,'wholesale.agent_action_discarded','wholesale_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);
        return ['ok'=>true,'skill'=>'wholesale.action_cancelled','answer'=>'Cancelled. I did not change Wholesale fulfillment or inventory.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Wholesale Fulfillment']];
    }

    if($order){
        if(preg_match('/\b(?:create|make|build)\b.*\b(?:batch|fulfillment)\b.*\b(?:remaining|unallocated|rest|everything)\b|\b(?:allocate|batch)\b.*\b(?:all remaining|everything remaining)\b/iu',$message)){
            waa_require($user,'wholesale.manage','Wholesale management permission is required to create fulfillment batches.');
            if(in_array((string)$order['status'],['requested','delivered','cancelled'],true))throw new InvalidArgumentException('Only confirmed, in-production, ready, or out-for-delivery orders can be allocated.');
            $progress=wholesale_fulfillment_progress($pdo,$org,(int)$order['id']);$remaining=array_values(array_filter((array)$progress['lines'],static fn($line)=>(float)$line['remainingToAllocate']>.0001));
            if(!$remaining)throw new InvalidArgumentException('This Wholesale order is already fully allocated.');
            $qty=array_sum(array_map(static fn($line)=>(float)$line['remainingToAllocate'],$remaining));
            $proposal=gac_pending_store('wholesale',$org,$uid,'create_remaining_batch',['orderPublicId'=>$order['public_id'],'expectedOrder'=>waa_order_version($order),'progressSignature'=>waa_progress_signature($progress)],'Proposed action: create one new fulfillment batch for all currently unallocated quantities on '.$order['order_number'].' ('.rtrim(rtrim(number_format($qty,2,'.',''),'0'),'.').' total sell units across '.count($remaining).' line'.(count($remaining)===1?'':'s').').');
            app_audit($pdo,$org,$uid,'wholesale.agent_action_proposed','wholesale_agent_proposal',(string)$proposal['id'],null,['type'=>'create_remaining_batch','orderId'=>$order['public_id']]);
            return gac_proposal_result($proposal,'wholesale.action_proposal',['Wholesale Fulfillment']);
        }

        $batchAction=null;
        if(preg_match('/\b(?:mark|set|move)\b.*\b(?:batch|fulfillment)\b.*\bready\b|\bmark\s+(?:this\s+)?batch\s+ready\b/iu',$message))$batchAction='batch_ready';
        elseif(preg_match('/\b(?:dispatch|send out|send)\b.*\b(?:batch|fulfillment)\b|\bdispatch\s+(?:this\s+)?batch\b/iu',$message))$batchAction='batch_dispatch';
        elseif(preg_match('/\b(?:cancel|void)\b.*\b(?:batch|fulfillment)\b|\bcancel\s+(?:this\s+)?batch\b/iu',$message))$batchAction='batch_cancel';
        elseif(preg_match('/\b(?:deliver|delivered|complete delivery|mark delivered)\b.*\b(?:batch|fulfillment)\b|\bmark\s+(?:this\s+)?batch\s+delivered\b/iu',$message))$batchAction='batch_deliver';
        if($batchAction){
            waa_require($user,'wholesale.manage','Wholesale management permission is required to change fulfillment batches.');
            if($batchAction==='batch_deliver')waa_require($user,'inventory.manage','Delivering a Wholesale fulfillment consumes physical inventory and requires inventory.manage permission.');
            $batch=waa_find_batch($pdo,$org,$order,$message,$context);
            $verb=['batch_ready'=>'mark '.$batch['fulfillment_number'].' ready','batch_dispatch'=>'dispatch '.$batch['fulfillment_number'],'batch_cancel'=>'cancel '.$batch['fulfillment_number'],'batch_deliver'=>'mark '.$batch['fulfillment_number'].' delivered and consume the required canonical inventory'][$batchAction];
            $proposal=gac_pending_store('wholesale',$org,$uid,$batchAction,['batchPublicId'=>$batch['public_id'],'expectedBatch'=>waa_batch_version($batch)],'Proposed action: '.$verb.' for '.$batch['order_number'].' / '.$batch['business_name'].'.');
            app_audit($pdo,$org,$uid,'wholesale.agent_action_proposed','wholesale_agent_proposal',(string)$proposal['id'],null,['type'=>$batchAction,'orderId'=>$order['public_id'],'batchId'=>$batch['public_id']]);
            return gac_proposal_result($proposal,'wholesale.action_proposal',$batchAction==='batch_deliver'?['Wholesale Fulfillment','Inventory']:['Wholesale Fulfillment']);
        }

        if(preg_match('/\b(?:short|shortage|shortages|inventory|available to promise|atp|enough stock)\b/iu',$message))return waa_shortages_answer($pdo,$org,$order);
        if(preg_match('/\b(?:batch|batches|fulfillment|allocation|allocated)\b/iu',$message))return waa_batches_answer($pdo,$org,$order);
        if(preg_match('/\b(?:this order|selected order|wholesale order|status|summary|where are we|what(?:\x27s| is) going on)\b/iu',$message))return waa_order_summary($pdo,$org,$order);
    }

    if(preg_match('/\b(?:wholesale|fulfillment|allocation|delivery|orders?|batches?)\b/iu',$message))return waa_open_orders_answer($pdo,$org);
    return ['ok'=>true]+operations_agent_wholesale_answer($pdo,$org,$message);
}
