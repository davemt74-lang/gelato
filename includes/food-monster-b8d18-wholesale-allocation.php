<?php
declare(strict_types=1);

/** B8D18J8B: model-selected Wholesale allocation, specialist-confirmed only. */
require_once __DIR__.'/food-monster-b8d18-wholesale-reads.php';

function fm8d18j8b_can(array $actor):bool
{
    if(!fm8d18j8a_can($actor))return false;
    return app_has_permission('wholesale.manage',$actor);
}
function fm8d18j8b_order_fields(array $args):array
{
    $missing=[];
    if(!fm8d18j8a_id($args['order_public_id']??null))$missing[]='order_public_id';
    foreach(['order_number','business_name'] as $key){
        $value=$args[$key]??null;
        if(!is_string($value)||trim($value)===''||mb_strlen(trim($value),'UTF-8')>240
           ||preg_match('/[\\x00-\\x1F]/u',$value))$missing[]=$key;
    }
    if(($args['all_remaining']??null)!==true)$missing[]='all_remaining';
    return $missing;
}
function fm8d18j8b_order(PDO $pdo,array $actor,array $args):array
{
    $order=wholesale_fulfillment_order($pdo,(int)$actor['organization_id'],(string)$args['order_public_id']);
    if((string)$order['order_number']!==trim((string)$args['order_number'])
       ||(string)$order['business_name']!==trim((string)$args['business_name']))
        throw new InvalidArgumentException('The saved Wholesale order number or business does not match that exact order ID.');
    if(in_array((string)$order['status'],['requested','delivered','cancelled'],true))
        throw new InvalidArgumentException('Only confirmed, in-production, ready, or out-for-delivery orders can be allocated.');
    return $order;
}
function fm8d18j8b_stage(array $actor,string $type,array $payload,string $summary):array
{
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start secure Wholesale confirmation session.');
    try{
        require_once __DIR__.'/agent-node-registry.php';
        require_once __DIR__.'/agent-confirmation-core.php';
        if(gaw_pending_action_node($org,$uid)!==null||gac_pending_get('wholesale',$org,$uid)!==null)
            throw new InvalidArgumentException('A restaurant action is already pending. Confirm or Cancel it first.');
        $pending=gac_pending_store('wholesale',$org,$uid,$type,$payload,$summary);
        app_audit(app_pdo(),$org,$uid,'wholesale.agent_action_proposed','wholesale_agent_proposal',
            (string)$pending['id'],null,['type'=>$type,'source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>$summary."\n\nReply Confirm to apply through the Wholesale Agent, or Cancel. No order allocation has changed yet.",
            'source'=>'Gelato Wholesale Fulfillment (server-verified pending proposal)',
            'requiresConfirmation'=>true,'proposal'=>['id'=>$pending['id'],'type'=>$type],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j8b_allocate(array $args,array $actor):array
{
    if(!fm8d18j8b_can($actor))throw new DomainException('Wholesale Agent management permission is required.');
    if($missing=fm8d18j8b_order_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/wholesale-agent-core.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    if(!wholesale_fulfillment_ready($pdo))throw new RuntimeException('Wholesale fulfillment is unavailable. Run upgrade.php first.');
    $order=fm8d18j8b_order($pdo,$actor,$args);
    $progress=wholesale_fulfillment_progress($pdo,$org,(int)$order['id']);
    $remaining=array_values(array_filter((array)$progress['lines'],
        static fn($line)=>(float)$line['remainingToAllocate']>.0001));
    if(!$remaining)throw new InvalidArgumentException('This Wholesale order is already fully allocated.');
    $quantity=array_sum(array_map(static fn($line)=>(float)$line['remainingToAllocate'],$remaining));
    $unitText=count($remaining).' saved order line'.(count($remaining)===1?'':'s');
    $summary='Proposed action: create ONE draft Wholesale fulfillment batch for ALL currently unallocated quantities ('
        .rtrim(rtrim(number_format($quantity,4,'.',''),'0'),'.').' sell units across '.$unitText
        .') on order '.$order['order_number'].' ('.$order['public_id'].') for '.$order['business_name'].'.';
    return fm8d18j8b_stage($actor,'create_remaining_batch',[
        'orderPublicId'=>(string)$order['public_id'],
        'expectedOrder'=>waa_order_version($order),
        'progressSignature'=>waa_progress_signature($progress),
    ],$summary);
}
function fm8d18j8b_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j8b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return [
        'propose_wholesale_allocate_remaining'=>[
            'description'=>'ONLY when the authorized Wholesale manager EXPLICITLY requests one draft batch for ALL remaining, currently unallocated quantities of an EXACT saved order. Require exact order public ID, matching saved order number/business name and all_remaining=true. Only stages the existing Wholesale Agent create_remaining_batch proposal. Confirmation rechecks order version and allocation signature; NO allocation is written by this tool. Never infer full allocation from a stock/fulfillment question.',
            'parameters'=>['type'=>'object','properties'=>[
                'order_public_id'=>['type'=>'string','description'=>'Exact saved Wholesale order public ID'],
                'order_number'=>['type'=>'string','description'=>'Matching saved Wholesale order number'],
                'business_name'=>['type'=>'string','description'=>'Matching saved Wholesale account business name'],
                'all_remaining'=>['type'=>'boolean','description'=>'Must be explicitly true: allocate all remaining quantities in ONE draft batch'],
            ],'required'=>['order_public_id','order_number','business_name','all_remaining'],'additionalProperties'=>false],
            'allowedArguments'=>['order_public_id','order_number','business_name','all_remaining'],
            'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j8b_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j8b_order_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j8b_allocate($args,$user),
        ],
    ];
}
