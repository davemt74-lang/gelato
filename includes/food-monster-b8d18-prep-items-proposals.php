<?php
declare(strict_types=1);

/** B8D18J2B: structured, confirmation-only prep item additions. */
require_once __DIR__.'/food-monster-b8d18-prep-list-proposals.php';

function fm8d18j2b_fields(array $args):array
{
    $missing=fm8d18j2a_fields($args);
    $items=$args['items']??null;
    if(!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>30)
        $missing[]='items';
    else foreach($items as $item){
        if(!is_array($item)||!is_string($item['title']??null)||
           trim($item['title'])===''||mb_strlen(trim($item['title']),'UTF-8')>160||
           !isset($item['quantity'])||!is_numeric($item['quantity'])||
           !is_int($item['quantity'])&&!is_float($item['quantity'])||
           (float)$item['quantity']<=0||(float)$item['quantity']>100000||
           !is_string($item['unit']??null)||trim($item['unit'])===''||
           mb_strlen(trim($item['unit']),'UTF-8')>40){
            $missing[]='items';break;
        }
    }
    return array_values(array_unique($missing));
}
function fm8d18j2b_payload(array $args):array
{
    if($missing=fm8d18j2b_fields($args))
        throw new InvalidArgumentException('Provide exact '.implode(', ',$missing).' for prep items.');
    $base=fm8d18j2a_payload($args);
    $items=[];foreach($args['items'] as $item){
        $unit=prep_normalized_unit(trim($item['unit']));
        $items[]=['title'=>trim($item['title']),
            'quantity'=>(float)$item['quantity'],'unit'=>$unit];
    }
    $base['items']=$items;
    return $base;
}
function fm8d18j2b_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18j2a_can($actor))throw new DomainException('Prep Intelligence management permission is required.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(($context['channel']??'')==='voice'){
        $eventId=trim((string)($context['voiceEventId']??''));
        if(($context['privateVoiceApproved']??false)!==true||$eventId===''||
           !tv_voice_event_valid($pdo,$org,$uid,$eventId))
            throw new DomainException('Verified private voice identity and one-use operator approval are required for spoken prep actions.');
    }
    $payload=fm8d18j2b_payload($args);
    if(!prep_intelligence_ready($pdo))throw new RuntimeException('Prep Intelligence is unavailable. Complete upgrade.php first.');
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start Prep Agent confirmation.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null||gac_pending_get('prep',$org,$uid)!==null)
            throw new InvalidArgumentException('Another action is pending. Confirm or Cancel first.');
        $plan=prep_plan_for_period($pdo,$org,$payload['date'],$payload['service']);
        if(!$plan)throw new InvalidArgumentException('Create the dated prep list first, then add items.');
        if((string)$plan['status']==='closed')throw new InvalidArgumentException('A closed prep list cannot accept new items.');
        $payload['expected']=pac_snapshot($plan);
        $payload['source']=($context['channel']??'')==='voice'?'voice':'agent';
        $labels=array_map(static fn(array $item):string=>pac_quantity($item['quantity']).' '.$item['unit'].' '.$item['title'],$payload['items']);
        $summary='Proposed action: add '.count($labels).' item(s) to the '.$payload['date'].' '.
            ucfirst($payload['service']).' prep list: '.implode('; ',$labels).'. No changes made yet.';
        $pending=gac_pending_store('prep',$org,$uid,'add_items',$payload,$summary);
        app_audit($pdo,$org,$uid,'prep.agent_action_proposed','prep_agent_proposal',
            (string)$pending['id'],null,['type'=>'add_items','count'=>count($labels),'source'=>'food_monster_model']);
        $response=gac_proposal_result($pending,'prep.action_proposal',['Prep Plan','Restaurant Tasks']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)$response['answer'],
            'source'=>'Gelato Prep Agent (server-verified proposal)',
            'requiresConfirmation'=>true,'proposal'=>$response['data']['proposal']??null,
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j2b_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j2a_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_add_prep_items'=>[
        'description'=>'ONLY when a Prep Intelligence manager explicitly asks to add specified items to an EXISTING dated prep list. Require exact date YYYY-MM-DD, service all/morning/lunch/dinner/closing, and 1–30 item objects with exact title, positive numeric quantity, and explicit unit. Ask for missing details, never guess quantities or units, and never silently drop an item. Stages an expiring Prep Agent proposal; separate Confirm rechecks the same plan version, management permission, and writes atomically. No direct model write. Private voice requires a matching identity and approval.',
        'parameters'=>['type'=>'object','properties'=>[
            'date'=>['type'=>'string','description'=>'Exact restaurant-local YYYY-MM-DD date'],
            'service'=>['type'=>'string','enum'=>['all','morning','lunch','dinner','closing']],
            'items'=>['type'=>'array','minItems'=>1,'maxItems'=>30,'items'=>[
                'type'=>'object','properties'=>[
                    'title'=>['type'=>'string','description'=>'Exact item or task name'],
                    'quantity'=>['type'=>'number','exclusiveMinimum'=>0],
                    'unit'=>['type'=>'string','description'=>'Explicit quantity unit, such as pans, batches, containers, lb or ea'],
                ],'required'=>['title','quantity','unit'],'additionalProperties'=>false]],
        ],'required'=>['date','service','items'],'additionalProperties'=>false],
        'allowedArguments'=>['date','service','items'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j2a_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j2b_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j2b_propose($args,$user,$context),
    ]];
}
