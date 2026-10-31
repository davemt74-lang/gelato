<?php
declare(strict_types=1);

/** B8D18I: model-selected schedule publish, always owned by Scheduling Agent confirmation. */
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/scheduling-agent-core.php';
require_once __DIR__.'/agent-node-registry.php';
require_once __DIR__.'/timeclock-agent-core.php';

function fm8d18i_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('schedule.agent',$actor)&&app_has_permission('schedule.manage',$actor);
}
function fm8d18i_fields(array $args):array
{
    return is_string($args['week_start']??null)&&trim($args['week_start'])!==''?[]:['week_start'];
}
function fm8d18i_week(array $args):string
{
    if(fm8d18i_fields($args))throw new InvalidArgumentException('Specify the exact Monday week_start in YYYY-MM-DD.');
    $week=trim($args['week_start']);$zone=new DateTimeZone('America/Phoenix');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$week,$zone);
    if(!$date||$date->format('Y-m-d')!==$week||$date->format('N')!=='1')
        throw new InvalidArgumentException('Provide an exact Monday date in YYYY-MM-DD format.');
    $current=(new DateTimeImmutable('today',$zone))->modify('monday this week');
    if($date<$current)throw new InvalidArgumentException('Cannot publish a past schedule week.');
    return $week;
}
function fm8d18i_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18i_can($actor))throw new DomainException('Scheduling Agent management permission is required.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(($context['channel']??'')==='voice'){
        $eventId=trim((string)($context['voiceEventId']??''));
        if(($context['privateVoiceApproved']??false)!==true||$eventId===''||
           !tv_voice_event_valid($pdo,$org,$uid,$eventId))
            throw new DomainException('Verified private voice identity and one-use operator approval are required for spoken publishing.');
    }
    if(!scheduling_core_ready($pdo))throw new RuntimeException('Staff Scheduling is unavailable. Complete upgrade.php first.');
    $week=fm8d18i_week($args);
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start the secure Scheduling confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null)
            throw new InvalidArgumentException('An agent action is already pending. Confirm or Cancel it first.');
        $checked=sac_publish_preflight($pdo,$org,$actor,$week);
        $pending=sac_pending_store($org,$uid,'week_publish',[
            'week'=>$week,'expected'=>$checked['snapshot'],
            'expectedShiftFingerprint'=>$checked['fingerprint'],
            'source'=>'food_monster_model',
        ],$checked['summary']);
        app_audit($pdo,$org,$uid,'schedule.agent_action_proposed','schedule_agent_proposal',
            (string)$pending['id'],null,['type'=>'week_publish','source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)sac_proposal_result($pending,['Staff Scheduling'])['answer'],
            'source'=>'Gelato Scheduling Agent (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$pending['id'],'type'=>$pending['type'],'expiresAt'=>$pending['expires']],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18i_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18i_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_publish_schedule_week'=>[
        'description'=>'ONLY when the signed-in scheduling manager explicitly orders publishing a specific existing draft week. Require exact week_start YYYY-MM-DD Monday; do not guess dates, publish as part of a question, publish old/empty/already published weeks or claim publication before Confirm. The model stages a 10-minute specialist proposal with a shift-version fingerprint; separate Confirm rechecks management permission and schedule changes. Private approved voice additionally requires a matching operator identity event.',
        'parameters'=>['type'=>'object','properties'=>[
            'week_start'=>['type'=>'string','description'=>'Exact Monday date YYYY-MM-DD for the draft schedule week'],
        ],'required'=>['week_start'],'additionalProperties'=>false],
        'allowedArguments'=>['week_start'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18i_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18i_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18i_propose($args,$user,$context),
    ]];
}
