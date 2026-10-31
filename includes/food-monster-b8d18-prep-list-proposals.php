<?php
declare(strict_types=1);

/** B8D18J2A: model-selected dated prep-list creation, no direct database write. */
require_once __DIR__.'/prep-agent-core.php';
require_once __DIR__.'/agent-node-registry.php';
require_once __DIR__.'/timeclock-agent-core.php';

function fm8d18j2a_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('prep.intelligence.manage',$actor);
}
function fm8d18j2a_fields(array $args):array
{
    $missing=[];
    if(!is_string($args['date']??null)||trim($args['date'])==='')$missing[]='date';
    if(!is_string($args['service']??null)||!in_array($args['service'],['all','morning','lunch','dinner','closing'],true))$missing[]='service';
    return $missing;
}
function fm8d18j2a_payload(array $args):array
{
    if($missing=fm8d18j2a_fields($args))
        throw new InvalidArgumentException('Specify '.implode(', ',$missing).' to create a dated prep list.');
    $date=trim($args['date']);$zone=new DateTimeZone('America/Phoenix');
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);
    if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('Provide an exact YYYY-MM-DD prep date.');
    if($parsed<new DateTimeImmutable('today',$zone))throw new InvalidArgumentException('Cannot create a prep list for a past date.');
    return ['date'=>$date,'service'=>$args['service']];
}
function fm8d18j2a_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18j2a_can($actor))throw new DomainException('Prep Intelligence management permission is required.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(($context['channel']??'')==='voice'){
        $eventId=trim((string)($context['voiceEventId']??''));
        if(($context['privateVoiceApproved']??false)!==true||$eventId===''||
           !tv_voice_event_valid($pdo,$org,$uid,$eventId))
            throw new DomainException('Verified private voice identity and one-use operator approval are required for spoken prep actions.');
    }
    $payload=fm8d18j2a_payload($args);
    if(!prep_intelligence_ready($pdo))throw new RuntimeException('Prep Intelligence is unavailable. Complete upgrade.php first.');
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start the secure Prep Agent confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null||gac_pending_get('prep',$org,$uid)!==null)
            throw new InvalidArgumentException('An agent action is already pending. Confirm or Cancel it first.');
        $existing=prep_plan_for_period($pdo,$org,$payload['date'],$payload['service']);
        if($existing)throw new InvalidArgumentException('A prep plan already exists for that date and service. Review it instead.');
        $payload['expected']=pac_snapshot(null);
        $summary='Proposed action: create an empty '.$payload['date'].' '.ucfirst($payload['service']).
            ' prep list. This does not add tasks or publish the plan.';
        $proposal=gac_pending_store('prep',$org,$uid,'create_plan',$payload,$summary);
        app_audit($pdo,$org,$uid,'prep.agent_action_proposed','prep_agent_proposal',
            (string)$proposal['id'],null,['type'=>'create_plan','source'=>'food_monster_model']);
        $answer=gac_proposal_result($proposal,'prep.action_proposal',['Prep Plan','Restaurant Tasks']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)$answer['answer'],
            'source'=>'Gelato Prep Agent (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>$answer['data']['proposal']??null,
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j2a_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j2a_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_create_prep_list'=>[
        'description'=>'ONLY when an authorized prep manager explicitly requests a new EMPTY dated prep list. Require exact YYYY-MM-DD date and service all/morning/lunch/dinner/closing. Do not infer the date, invent ingredients, add tasks, publish, or imply a list was created. The Prep Agent stages a separate Confirm and rechecks records and permission before any write. If the user specifies items, use the Prep Agent item workflow separately; never silently omit requested items.',
        'parameters'=>['type'=>'object','properties'=>[
            'date'=>['type'=>'string','description'=>'Exact restaurant-local YYYY-MM-DD date'],
            'service'=>['type'=>'string','enum'=>['all','morning','lunch','dinner','closing']],
        ],'required'=>['date','service'],'additionalProperties'=>false],
        'allowedArguments'=>['date','service'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j2a_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j2a_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j2a_propose($args,$user,$context),
    ]];
}
