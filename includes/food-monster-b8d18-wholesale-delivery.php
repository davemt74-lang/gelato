<?php
declare(strict_types=1);

/** B8D18J8D: inventory-consuming Wholesale delivery, after explicit Confirm only. */
require_once __DIR__.'/food-monster-b8d18-wholesale-status.php';

function fm8d18j8d_can(array $actor):bool
{
    return fm8d18j8b_can($actor)&&app_has_permission('inventory.manage',$actor);
}
function fm8d18j8d_fields(array $args):array
{
    $missing=[];
    foreach(['order_public_id','batch_public_id'] as $key)
        if(!fm8d18j8a_id($args[$key]??null))$missing[]=$key;
    foreach(['order_number','batch_number'] as $key){
        $value=$args[$key]??null;
        if(!is_string($value)||trim($value)===''||mb_strlen(trim($value),'UTF-8')>180
           ||preg_match('/[\\x00-\\x1F]/u',$value))$missing[]=$key;
    }
    if(($args['consume_inventory']??null)!==true)$missing[]='consume_inventory';
    return $missing;
}
function fm8d18j8d_deliver(array $args,array $actor):array
{
    if(!fm8d18j8d_can($actor))throw new DomainException('Wholesale management and inventory management permissions are both required.');
    if($missing=fm8d18j8d_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/wholesale-agent-core.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_fulfillment_ready($pdo))throw new RuntimeException('Wholesale fulfillment is unavailable. Run upgrade.php first.');
    $batch=fm8d18j8c_exact_batch($pdo,$actor,$args);
    if(!in_array((string)$batch['status'],['ready','dispatched'],true))
        throw new InvalidArgumentException('Mark the exact batch ready before delivery.');
    if(!in_array((string)$batch['order_status'],['ready','out_for_delivery'],true))
        throw new InvalidArgumentException('Wholesale production must be ready before delivery.');
    $availability=wholesale_fulfillment_batch_availability($pdo,$org,$batch);
    if($availability['issues'])throw new InvalidArgumentException('Resolve Wholesale demand mapping issues before delivery.');
    if((int)$availability['shortages']>0)
        throw new InvalidArgumentException('Inventory is currently short for this batch. Replenish or correct the count first.');
    if(!$availability['items'])throw new InvalidArgumentException('No canonical inventory requirements were resolved for this batch. Review the order before delivery.');
    $parts=[];
    foreach(array_slice($availability['items'],0,5) as $row)
        $parts[]=$row['name'].' '.rtrim(rtrim(number_format((float)$row['required'],4,'.',''),'0'),'.').' '.$row['unit'];
    $summary='Proposed action: mark Wholesale batch '.$batch['fulfillment_number'].' ('.$batch['public_id']
        .') on order '.$batch['order_number'].' ('.$batch['order_public_id']
        .') as delivered. This will CONSUME canonical inventory and create inventory-use ledger entries: '
        .implode('; ',$parts)
        .(count($availability['items'])>5?'; and '.(count($availability['items'])-5).' more item(s)':'').'.';
    return fm8d18j8b_stage($actor,'batch_deliver',[
        'batchPublicId'=>(string)$batch['public_id'],
        'expectedBatch'=>waa_batch_version($batch),
        'requirementsSignature'=>waa_delivery_signature($availability),
    ],$summary);
}
function fm8d18j8d_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j8d_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return [
        'propose_wholesale_batch_delivery'=>[
            'description'=>'ONLY when the authorized manager EXPLICITLY requests marking ONE exact existing ready/dispatched Wholesale batch delivered, acknowledges that this CONSUMES physical inventory, and has wholesale.manage plus inventory.manage. Require saved order and batch IDs with matching numbers and consume_inventory=true. Check for shortages/demand issues; stage ONLY the existing Wholesale Agent batch_deliver proposal. The specialist rechecks permissions, batch and inventory requirement version at Confirm; underlying delivery rechecks stock under transaction locks. Never infer delivery from dispatch or a status question. No direct model inventory write.',
            'parameters'=>['type'=>'object','properties'=>[
                'order_public_id'=>['type'=>'string','description'=>'Exact saved parent Wholesale order public ID'],
                'order_number'=>['type'=>'string','description'=>'Matching saved Wholesale order number'],
                'batch_public_id'=>['type'=>'string','description'=>'Exact saved Wholesale fulfillment batch public ID'],
                'batch_number'=>['type'=>'string','description'=>'Matching saved Wholesale fulfillment number'],
                'consume_inventory'=>['type'=>'boolean','description'=>'Explicitly true only if user acknowledges delivery consumes canonical inventory'],
            ],'required'=>['order_public_id','order_number','batch_public_id','batch_number','consume_inventory'],'additionalProperties'=>false],
            'allowedArguments'=>['order_public_id','order_number','batch_public_id','batch_number','consume_inventory'],
            'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j8d_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j8d_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j8d_deliver($args,$user),
        ],
    ];
}
