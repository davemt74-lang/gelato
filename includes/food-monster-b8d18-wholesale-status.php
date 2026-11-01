<?php
declare(strict_types=1);

/** B8D18J8C: exact Wholesale batch transitions through specialist confirmation. */
require_once __DIR__.'/food-monster-b8d18-wholesale-allocation.php';

function fm8d18j8c_fields(array $args):array
{
    $missing=[];
    foreach(['order_public_id','batch_public_id'] as $key)
        if(!fm8d18j8a_id($args[$key]??null))$missing[]=$key;
    foreach(['order_number','batch_number'] as $key){
        $value=$args[$key]??null;
        if(!is_string($value)||trim($value)===''||mb_strlen(trim($value),'UTF-8')>180
           ||preg_match('/[\\x00-\\x1F]/u',$value))$missing[]=$key;
    }
    if(!in_array($args['transition']??null,['ready','dispatch','cancel'],true))$missing[]='transition';
    return $missing;
}
function fm8d18j8c_exact_batch(PDO $pdo,array $actor,array $args):array
{
    $org=(int)$actor['organization_id'];
    $batch=wholesale_fulfillment_batch($pdo,$org,(string)$args['batch_public_id']);
    if((string)$batch['order_public_id']!==(string)$args['order_public_id']
       ||(string)$batch['order_number']!==trim((string)$args['order_number'])
       ||(string)$batch['fulfillment_number']!==trim((string)$args['batch_number']))
        throw new InvalidArgumentException('The saved batch number, order number or order ID does not match this batch.');
    return $batch;
}
function fm8d18j8c_transition(array $args,array $actor):array
{
    if(!fm8d18j8b_can($actor))throw new DomainException('Wholesale Agent management permission is required.');
    if($missing=fm8d18j8c_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/wholesale-agent-core.php';
    $pdo=app_pdo();
    if(!wholesale_fulfillment_ready($pdo))throw new RuntimeException('Wholesale fulfillment is unavailable. Run upgrade.php first.');
    $batch=fm8d18j8c_exact_batch($pdo,$actor,$args);
    $next=(string)$args['transition'];$current=(string)$batch['status'];
    $allowed=['draft'=>['ready','cancel'],'ready'=>['dispatch','cancel'],'dispatched'=>[]];
    if(!in_array($next,$allowed[$current]??[],true))
        throw new InvalidArgumentException('A Wholesale batch cannot transition from '.$current.' using '.$next.'.');
    if($next==='dispatch'&&!in_array((string)$batch['order_status'],['ready','out_for_delivery'],true))
        throw new InvalidArgumentException('Wholesale production must be ready before dispatch.');
    $type=['ready'=>'batch_ready','dispatch'=>'batch_dispatch','cancel'=>'batch_cancel'][$next];
    $verb=['ready'=>'mark ready','dispatch'=>'dispatch','cancel'=>'cancel and release its remaining order-line allocation'][$next];
    $summary='Proposed action: '.$verb.' for Wholesale batch '.$batch['fulfillment_number'].' ('.$batch['public_id']
        .') on order '.$batch['order_number'].' ('.$batch['order_public_id'].') from '.$current.'.';
    if($next==='cancel')$summary.=' Cancellation removes this batch from active allocation and cannot be undone by a status update.';
    return fm8d18j8b_stage($actor,$type,[
        'batchPublicId'=>(string)$batch['public_id'],
        'expectedBatch'=>waa_batch_version($batch),
    ],$summary);
}
function fm8d18j8c_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j8b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return [
        'propose_wholesale_batch_transition'=>[
            'description'=>'ONLY for an explicit authorized manager request to mark ONE exact Wholesale fulfillment batch ready, dispatch it, or cancel it. Require exact saved order and batch public IDs and matching numbers. Valid transitions: draft→ready/cancel; ready→dispatch/cancel. Dispatch requires the order to be production-ready. Cancelling releases allocations and is not reversible by a status update. This ONLY stages the existing Wholesale Agent batch_ready/batch_dispatch/batch_cancel Confirm/Cancel proposal; the specialist rechecks the batch version and rules. Never infer a status change from a progress question.',
            'parameters'=>['type'=>'object','properties'=>[
                'order_public_id'=>['type'=>'string','description'=>'Exact parent Wholesale order public ID'],
                'order_number'=>['type'=>'string','description'=>'Matching saved Wholesale order number'],
                'batch_public_id'=>['type'=>'string','description'=>'Exact saved Wholesale fulfillment batch public ID'],
                'batch_number'=>['type'=>'string','description'=>'Matching saved fulfillment batch number'],
                'transition'=>['type'=>'string','enum'=>['ready','dispatch','cancel']],
            ],'required'=>['order_public_id','order_number','batch_public_id','batch_number','transition'],'additionalProperties'=>false],
            'allowedArguments'=>['order_public_id','order_number','batch_public_id','batch_number','transition'],
            'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j8b_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j8c_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j8c_transition($args,$user),
        ],
    ];
}
