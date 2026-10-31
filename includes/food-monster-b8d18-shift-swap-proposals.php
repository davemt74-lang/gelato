<?php
declare(strict_types=1);

/** B8D18H: personal shift-swap proposals; the Scheduling Agent owns confirmation. */
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/scheduling-agent-core.php';
require_once __DIR__.'/agent-node-registry.php';
require_once __DIR__.'/timeclock-agent-core.php';

function fm8d18h_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('schedule.agent',$actor)
        &&app_has_permission('schedule.self',$actor);
}
function fm8d18h_fields(array $args):array
{
    $missing=[];
    if(!is_string($args['shift_id']??null)||trim($args['shift_id'])==='')$missing[]='shift_id';
    if(!is_int($args['target_user_id']??null)||$args['target_user_id']<=0)$missing[]='target_user_id';
    foreach(['proposed_shift_id','note'] as $key)
        if(array_key_exists($key,$args)&&!is_string($args[$key]))$missing[]=$key;
    return $missing;
}
function fm8d18h_payload(array $args):array
{
    if($missing=fm8d18h_fields($args))
        throw new InvalidArgumentException('Specify '.implode(', ',$missing).' for your shift swap.');
    $note=trim((string)($args['note']??''));
    if(mb_strlen($note,'UTF-8')>1000)throw new InvalidArgumentException('Swap note cannot exceed 1,000 characters.');
    $shiftId=trim($args['shift_id']);$proposedId=trim((string)($args['proposed_shift_id']??''));
    foreach([$shiftId,$proposedId] as $id)
        if($id!==''&&!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$id))
            throw new InvalidArgumentException('Use an exact shift identifier from Gelato.');
    return ['shiftId'=>$shiftId,'targetUserId'=>$args['target_user_id'],
        'proposedShiftId'=>$proposedId,'note'=>$note];
}
function fm8d18h_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18h_can($actor))throw new DomainException('Personal Scheduling Agent permission is required.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(($context['channel']??'')==='voice'){
        $eventId=trim((string)($context['voiceEventId']??''));
        if(($context['privateVoiceApproved']??false)!==true||$eventId===''||
           !tv_voice_event_valid($pdo,$org,$uid,$eventId))
            throw new DomainException('Verified private voice identity and one-use operator approval are required for spoken swaps.');
    }
    if(!scheduling_core_ready($pdo))throw new RuntimeException('Staff Scheduling is unavailable. Complete upgrade.php first.');
    $payload=fm8d18h_payload($args);
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start the secure Scheduling confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null)
            throw new InvalidArgumentException('An agent action is already pending. Confirm or Cancel it first.');
        $checked=sac_swap_preflight($pdo,$org,$uid,$actor,$payload);
        $payload['userId']=$uid;
        $payload['expectedUpdatedAt']=(string)$checked['shift']['updated_at'];
        $payload['expectedProposedUpdatedAt']=(string)($checked['proposed']['updated_at']??'');
        $pending=sac_pending_store($org,$uid,'shift_swap_request',$payload,$checked['summary']);
        app_audit($pdo,$org,$uid,'schedule.agent_action_proposed','schedule_agent_proposal',
            (string)$pending['id'],null,['type'=>'shift_swap_request','source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)sac_proposal_result($pending,['Staff Scheduling'])['answer'],
            'source'=>'Gelato Scheduling Agent (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$pending['id'],'type'=>$pending['type'],'expiresAt'=>$pending['expires']],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18h_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18h_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_my_shift_swap'=>[
        'description'=>'ONLY for an explicit request by the signed-in employee to swap THEIR OWN future published shift with a named active employee. Require exact shift_id and target_user_id from verified Gelato schedule context; never guess either. Optional proposed_shift_id must belong to that target. No direct shift reassignment: creates a pending manager-review swap request ONLY after separate Confirm. Never claim the target accepted or management approved. For voice require private operator approval and matching identity.',
        'parameters'=>['type'=>'object','properties'=>[
            'shift_id'=>['type'=>'string','description'=>'Exact public ID of my scheduled shift'],
            'target_user_id'=>['type'=>'integer','description'=>'Exact verified staff account ID of intended swap partner'],
            'proposed_shift_id'=>['type'=>'string','description'=>'Optional exact public ID of their shift to exchange'],
            'note'=>['type'=>'string','description'=>'Optional swap request note, maximum 1000 characters'],
        ],'required'=>['shift_id','target_user_id'],'additionalProperties'=>false],
        'allowedArguments'=>['shift_id','target_user_id','proposed_shift_id','note'],
        'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18h_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18h_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18h_propose($args,$user,$context),
    ]];
}
