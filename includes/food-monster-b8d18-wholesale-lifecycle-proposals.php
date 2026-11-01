<?php
declare(strict_types=1);

/** B8D18J9B: model-selected, confirmation-only wholesale order lifecycle proposals. */
require_once __DIR__.'/food-monster-b8d18-wholesale-allocation.php';
require_once __DIR__.'/wholesale-order-lifecycle-actions.php';

function fm8d18j9b_can(array $actor):bool
{
    return fm8d18j8b_can($actor);
}
function fm8d18j9b_base_fields(array $args):array
{
    $missing=[];
    if(!fm8d18j8a_id($args['order_public_id']??null))$missing[]='order_public_id';
    foreach(['order_number','business_name'] as $field){
        $v=$args[$field]??null;
        if(!is_string($v)||trim($v)===''||mb_strlen($v,'UTF-8')>240||preg_match('/[\\x00-\\x1F]/u',$v))
            $missing[]=$field;
    }
    return $missing;
}
function fm8d18j9b_confirm_fields(array $args):array
{
    $missing=fm8d18j9b_base_fields($args);
    if(!isset($args['saved_total'])||!is_numeric($args['saved_total'])||(float)$args['saved_total']<0)$missing[]='saved_total';
    if(($args['acknowledge_saved_total']??null)!==true)$missing[]='acknowledge_saved_total=true';
    return $missing;
}
function fm8d18j9b_cancel_fields(array $args):array
{
    $missing=fm8d18j9b_base_fields($args);
    if(!is_string($args['reason']??null)||trim((string)$args['reason'])===''||mb_strlen((string)$args['reason'],'UTF-8')>500)$missing[]='reason';
    if(($args['acknowledge_cancel']??null)!==true)$missing[]='acknowledge_cancel=true';
    return $missing;
}
function fm8d18j9b_promise_fields(array $args):array
{
    $missing=fm8d18j9b_base_fields($args);
    foreach(['promised_start','promised_end'] as $field){
        if(!is_string($args[$field]??null)||!preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/D',$args[$field]))
            $missing[]=$field;
    }
    return $missing;
}
function fm8d18j9b_location_fields(array $args):array
{
    $missing=fm8d18j9b_base_fields($args);
    if(!fm8d18j8a_id($args['location_public_id']??null))$missing[]='location_public_id';
    if(!is_string($args['location_name']??null)||trim((string)$args['location_name'])===''||mb_strlen((string)$args['location_name'],'UTF-8')>240)
        $missing[]='location_name';
    return $missing;
}
function fm8d18j9b_propose(array $args,array $actor,string $kind):array
{
    if(!fm8d18j9b_can($actor))throw new DomainException('Wholesale Agent management permission is required.');
    $missing=match($kind){
        'order_confirm'=>fm8d18j9b_confirm_fields($args),
        'order_cancel'=>fm8d18j9b_cancel_fields($args),
        'order_promise'=>fm8d18j9b_promise_fields($args),
        'order_location'=>fm8d18j9b_location_fields($args),
        default=>['action'],
    };
    if($missing)throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_fulfillment_ready($pdo)||!wholesale_commerce_ready($pdo))throw new RuntimeException('Wholesale is unavailable. Run upgrade.php first.');
    $order=wholesale_fulfillment_order($pdo,$org,(string)$args['order_public_id']);
    if((string)$order['order_number']!==trim((string)$args['order_number'])
        ||(string)$order['business_name']!==trim((string)$args['business_name']))
        throw new InvalidArgumentException('Saved order number or business does not match this exact Wholesale order.');
    if((string)$order['account_status']!=='active')
        throw new InvalidArgumentException('This Wholesale account is not active.');
    $payload=['orderPublicId'=>(string)$order['public_id'],'orderNumber'=>(string)$order['order_number'],
        'expectedOrder'=>waa_order_version($order)];
    $description='';
    if($kind==='order_confirm'){
        if((string)$order['status']!=='requested')throw new InvalidArgumentException('Only requested orders can be confirmed.');
        $lines=wholesale_fulfillment_order_lines($pdo,$org,(int)$order['id']);
        if(!$lines)throw new InvalidArgumentException('Cannot confirm an order without canonical saved lines.');
        $total=(float)$order['total'];
        if(abs($total-(float)$args['saved_total'])>.005)throw new InvalidArgumentException('The acknowledged saved total does not match this order.');
        $payload+=['expectedTotal'=>$total,'acknowledgeSavedTotal'=>true];
        $description='confirm requested Wholesale order '.$order['order_number'].' for '.$order['business_name'].' at its saved total '.number_format($total,2,'.','');
    }elseif($kind==='order_cancel'){
        wol_can_cancel($pdo,$org,$order);
        $payload+=['reason'=>trim((string)$args['reason']),'acknowledgeCancel'=>true];
        $description='CANCEL order '.$order['order_number'].' for '.$order['business_name'].' before production, reason: '.$payload['reason'];
    }elseif($kind==='order_promise'){
        if(in_array((string)$order['status'],['delivered','cancelled'],true))
            throw new InvalidArgumentException('Delivered or cancelled orders cannot change planning.');
        wol_no_batches($pdo,$org,(int)$order['id']);
        $start=wholesale_fulfillment_datetime($args['promised_start'],'Promised start');
        $end=wholesale_fulfillment_datetime($args['promised_end'],'Promised end');
        wholesale_fulfillment_validate_window($start,$end,'Promised window');
        $payload+=['start'=>$start,'end'=>$end];
        $description='set promised window for order '.$order['order_number'].' from '.$start.' to '.$end.' (other plan fields stay intact)';
    }else{
        if(in_array((string)$order['status'],['delivered','cancelled'],true))
            throw new InvalidArgumentException('Delivered or cancelled orders cannot change planning.');
        wol_no_batches($pdo,$org,(int)$order['id']);
        $location=wholesale_fulfillment_location($pdo,$org,(int)$order['wholesale_account_id'],(string)$args['location_public_id']);
        if(!$location||(string)$location['public_id']!==(string)$args['location_public_id']
            ||(string)$location['name']!==trim((string)$args['location_name']))
            throw new InvalidArgumentException('The exact active account location name and ID do not match.');
        $payload+=['locationPublicId'=>(string)$location['public_id'],'locationName'=>(string)$location['name']];
        $description='set fulfillment location for order '.$order['order_number'].' to '.$location['name'].' (other plan fields stay intact)';
    }
    return fm8d18j8b_stage($actor,$kind,$payload,'Proposed action: '.$description.'. The order will be checked again at confirmation.');
}
function fm8d18j9b_tool(string $kind,string $description,array $extra,array $required):array
{
    $props=[
        'order_public_id'=>['type'=>'string','description'=>'Exact saved Wholesale order public ID'],
        'order_number'=>['type'=>'string','description'=>'Matching saved order number'],
        'business_name'=>['type'=>'string','description'=>'Matching saved account business name'],
    ]+$extra;
    $fields=array_keys($props);
    return [
        'description'=>$description,'parameters'=>['type'=>'object','properties'=>$props,
            'required'=>array_merge(['order_public_id','order_number','business_name'],$required),
            'additionalProperties'=>false],
        'allowedArguments'=>$fields,'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j9b_can($user),
        'validate'=>static fn(array $args,array $user):array=>match($kind){
            'order_confirm'=>fm8d18j9b_confirm_fields($args),
            'order_cancel'=>fm8d18j9b_cancel_fields($args),
            'order_promise'=>fm8d18j9b_promise_fields($args),
            'order_location'=>fm8d18j9b_location_fields($args),
        },
        'execute'=>static fn(array $args,array $user):array=>fm8d18j9b_propose($args,$user,$kind),
    ];
}
function fm8d18j9b_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j9b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return [
        'propose_wholesale_order_confirmation'=>fm8d18j9b_tool('order_confirm',
            'Only for explicit manager confirmation of one exact requested Wholesale order. Include its matching number/business, exactly saved total and deliberate acknowledgment. Stages a separate Wholesale Agent Confirm/Cancel, revalidating status and total, never changes the order on this call.',
            ['saved_total'=>['type'=>'number'],'acknowledge_saved_total'=>['type'=>'boolean']],
            ['saved_total','acknowledge_saved_total']),
        'propose_wholesale_order_cancellation'=>fm8d18j9b_tool('order_cancel',
            'Only for explicit manager cancellation of one exact requested/confirmed order before production, batches, or invoices. Require specific reason and true acknowledgment. Stages separate Wholesale Agent confirmation, rechecks all blocking records; never cancels immediately.',
            ['reason'=>['type'=>'string'],'acknowledge_cancel'=>['type'=>'boolean']],['reason','acknowledge_cancel']),
        'propose_wholesale_promised_window'=>fm8d18j9b_tool('order_promise',
            'Explicit manager request to update ONLY the promised fulfillment start/end of one exact Wholesale order without batches. Exact YYYY-MM-DDTHH:MM local wall time; separate confirmation preserves other saved planning fields.',
            ['promised_start'=>['type'=>'string'],'promised_end'=>['type'=>'string']],['promised_start','promised_end']),
        'propose_wholesale_order_location'=>fm8d18j9b_tool('order_location',
            'Explicit manager request to change ONLY the fulfillment location of one exact order to an exact active location belonging to that wholesale account; require saved location public ID and matching name. Separate confirmation, other plan fields preserved.',
            ['location_public_id'=>['type'=>'string'],'location_name'=>['type'=>'string']],['location_public_id','location_name']),
    ];
}
