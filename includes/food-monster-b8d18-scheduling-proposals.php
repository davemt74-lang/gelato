<?php
declare(strict_types=1);

/** FM-B8D18G: Model-selected personal time-off requests use the existing
 * Scheduling Agent proposal and confirmation, never a direct model write. */
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/scheduling-agent-core.php';
require_once __DIR__.'/agent-node-registry.php';
require_once __DIR__.'/timeclock-agent-core.php';

function fm8d18g_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('schedule.agent',$actor)
        &&app_has_permission('schedule.self',$actor);
}

function fm8d18g_fields(array $args):array
{
    $missing=[];
    foreach(['start_date','end_date','start_time','end_time','note'] as $name){
        if(array_key_exists($name,$args)&&!is_string($args[$name]))$missing[]=$name;
    }
    if(!in_array('start_date',$missing,true)&&trim((string)($args['start_date']??''))==='')$missing[]='start_date';
    if($missing)return array_values(array_unique($missing));
    if(trim((string)($args['start_time']??''))!==''&&trim((string)($args['end_time']??''))==='')$missing[]='end_time';
    if(trim((string)($args['end_time']??''))!==''&&trim((string)($args['start_time']??''))==='')$missing[]='start_time';
    return $missing;
}

function fm8d18g_payload(array $args):array
{
    if($missing=fm8d18g_fields($args))
        throw new InvalidArgumentException('Specify '.implode(', ',$missing).' to request time off.');
    $note=trim((string)($args['note']??''));
    if(mb_strlen($note,'UTF-8')>1000)throw new InvalidArgumentException('Time-off note cannot exceed 1,000 characters.');
    $payload=[
        'startDate'=>trim((string)$args['start_date']),
        'endDate'=>trim((string)($args['end_date']??''))?:trim((string)$args['start_date']),
        'startTime'=>trim((string)($args['start_time']??'')),
        'endTime'=>trim((string)($args['end_time']??'')),
        'note'=>$note,
    ];
    sac_time_off_dates($payload);
    return $payload;
}

function fm8d18g_preflight(PDO $pdo,array $actor,array $payload):string
{
    if(!fm8d18g_can($actor))throw new DomainException('Personal Scheduling Agent permission is required.');
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $dates=sac_time_off_dates($payload);
    if(!scheduling_staff_user($pdo,$org,$uid))throw new InvalidArgumentException('An active staff scheduling profile is required to request time off.');
    sac_time_off_check_existing($pdo,$org,$uid,$dates);
    $summary='Proposed action: request '.count($dates).' day(s) of time off for your signed-in account, '.
        $dates[0].($dates[count($dates)-1]!==$dates[0]?' through '.$dates[count($dates)-1]:'');
    if($payload['startTime']!=='')$summary.=' from '.$payload['startTime'].' to '.$payload['endTime'];
    if($payload['note']!=='')$summary.='; note: '.$payload['note'];
    return $summary.'. The request will be pending manager review.';
}

function fm8d18g_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18g_can($actor))throw new DomainException('Personal Scheduling Agent permission is required.');
    $voice=($context['channel']??'')==='voice';
    if($voice){
        $eventId=trim((string)($context['voiceEventId']??''));
        if(($context['privateVoiceApproved']??false)!==true||$eventId===''||
           !tv_voice_event_valid(app_pdo(),(int)$actor['organization_id'],(int)$actor['id'],$eventId))
            throw new DomainException('Verified private voice identity and one-use operator approval are required to request time off by speaking.');
    }
    $payload=fm8d18g_payload($args);
    $pdo=app_pdo();
    if(!scheduling_core_ready($pdo))throw new RuntimeException('Staff Scheduling is unavailable. Complete upgrade.php first.');
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start the secure Scheduling confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null)
            throw new InvalidArgumentException('An agent action is already pending. Confirm or Cancel it first.');
        $summary=fm8d18g_preflight($pdo,$actor,$payload);
        $payload['userId']=$uid;
        $pending=sac_pending_store($org,$uid,'time_off_request',$payload,$summary);
        app_audit($pdo,$org,$uid,'schedule.agent_action_proposed','schedule_agent_proposal',
            (string)$pending['id'],null,['type'=>'time_off_request','source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)sac_proposal_result($pending,['Staff Scheduling'])['answer'],
            'source'=>'Gelato Scheduling Agent (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$pending['id'],'type'=>$pending['type'],'expiresAt'=>$pending['expires']],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}

function fm8d18g_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18g_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_my_time_off_request'=>[
        'description'=>'ONLY when the signed-in employee explicitly requests THEIR OWN time off, propose a new pending manager-review request. Give start_date YYYY-MM-DD and optional end_date YYYY-MM-DD (maximum 31 consecutive days). A partial-day request must include BOTH 24-hour HH:MM start_time and end_time and covers ONE day only. Optional note. Never select a target employee, approve the request, invent a date, treat a question as a request, or claim time off was granted. Model execution stages an expiring Scheduling Agent proposal; the user separately confirms before any record is written.',
        'parameters'=>['type'=>'object','properties'=>[
            'start_date'=>['type'=>'string','description'=>'Exact YYYY-MM-DD restaurant-local date'],
            'end_date'=>['type'=>'string','description'=>'Optional last date YYYY-MM-DD, inclusive'],
            'start_time'=>['type'=>'string','description'=>'For one partial day only: 24-hour HH:MM'],
            'end_time'=>['type'=>'string','description'=>'For one partial day only: 24-hour HH:MM'],
            'note'=>['type'=>'string','description'=>'Optional short reason, max 1,000 characters'],
        ],'required'=>['start_date'],'additionalProperties'=>false],
        'allowedArguments'=>['start_date','end_date','start_time','end_time','note'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18g_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18g_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18g_propose($args,$user,$context),
    ]];
}
