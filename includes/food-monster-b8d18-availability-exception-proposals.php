<?php
declare(strict_types=1);

/** B8D18J4C: self-only dated availability exception proposal for manager review. */
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/scheduling-agent-core.php';
require_once __DIR__.'/agent-node-registry.php';
require_once __DIR__.'/timeclock-agent-core.php';

function fm8d18j4c_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('schedule.agent',$actor)
        &&app_has_permission('schedule.self',$actor);
}
function fm8d18j4c_fields(array $args):array
{
    $missing=[];
    $date=$args['date']??null;
    if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))$missing[]='date';
    else{
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('America/Phoenix'));
        if(!$dt||$dt->format('Y-m-d')!==$date)$missing[]='date';
    }
    if(!in_array($args['type']??null,['available','unavailable'],true))$missing[]='type';
    if(!is_bool($args['all_day']??null))$missing[]='all_day';
    if(($args['all_day']??null)===true){
        if(isset($args['start'])||isset($args['end']))$missing[]='start/end';
    }elseif(($args['all_day']??null)===false){
        foreach(['start','end'] as $part)
            if(!is_string($args[$part]??null)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$args[$part]))
                $missing[]=$part;
        if(isset($args['start'],$args['end'])&&is_string($args['start'])&&is_string($args['end'])&&
            !in_array('start',$missing,true)&&!in_array('end',$missing,true)&&$args['end']<=$args['start'])
            $missing[]='end';
    }
    $note=$args['note']??'';
    if(!is_string($note)||mb_strlen($note,'UTF-8')>1000)$missing[]='note';
    return array_values(array_unique($missing));
}
function fm8d18j4c_payload(array $args):array
{
    if($missing=fm8d18j4c_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    $zone=new DateTimeZone('America/Phoenix');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$args['date'],$zone);
    $today=new DateTimeImmutable('today',$zone);
    if($date<$today||$date>$today->modify('+365 days'))
        throw new InvalidArgumentException('Request one exact date between today and one year ahead.');
    return ['date'=>$args['date'],'type'=>$args['type'],'allDay'=>$args['all_day'],
        'start'=>$args['all_day']?'':$args['start'],'end'=>$args['all_day']?'':$args['end'],
        'note'=>trim($args['note']??'')];
}
function fm8d18j4c_no_duplicate(PDO $pdo,int $org,int $uid,array $payload):void
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM staff_availability_exceptions
        WHERE organization_id=? AND user_id=? AND exception_date=? AND exception_type=?
          AND status IN ('pending','approved')");
    $q->execute([$org,$uid,$payload['date'],$payload['type']]);
    if((int)$q->fetchColumn()>0)
        throw new InvalidArgumentException('An existing pending or approved availability exception for that date and type needs review first.');
}
function fm8d18j4c_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18j4c_can($actor))throw new DomainException('Personal Scheduling Agent permission is required.');
    $payload=fm8d18j4c_payload($args);
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(($context['channel']??'')==='voice'){
        $eventId=trim((string)($context['voiceEventId']??''));
        if(($context['privateVoiceApproved']??false)!==true||$eventId===''||
           !tv_voice_event_valid($pdo,$org,$uid,$eventId))
            throw new DomainException('Matching private voice identity and one-use approval are required for spoken availability requests.');
    }
    if(!scheduling_core_ready($pdo))throw new RuntimeException('Staff Scheduling is unavailable. Complete upgrade.php first.');
    if(!scheduling_staff_user($pdo,$org,$uid))throw new InvalidArgumentException('An active staff scheduling profile is required.');
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start Scheduling confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null)
            throw new InvalidArgumentException('Another agent action is pending. Confirm or Cancel it first.');
        fm8d18j4c_no_duplicate($pdo,$org,$uid,$payload);
        $summary='Proposed personal '.$payload['type'].' exception for '.$payload['date'].
            ($payload['allDay']?' (full day)':' from '.$payload['start'].' to '.$payload['end']).
            '. After Confirm this submits a pending manager-review request, not an automatic approval or shift change.';
        $pending=sac_pending_store($org,$uid,'availability_exception_request',$payload,$summary);
        app_audit($pdo,$org,$uid,'schedule.agent_action_proposed','schedule_agent_proposal',
            (string)$pending['id'],null,['type'=>'availability_exception_request','source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)sac_proposal_result($pending,['Staff Scheduling'])['answer'],
            'source'=>'Gelato Scheduling Agent (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$pending['id'],'type'=>$pending['type'],'expiresAt'=>$pending['expires']],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j4c_apply(PDO $pdo,int $org,int $uid,array $actor,array $proposal,array $payload):array
{
    if(!fm8d18j4c_can($actor))throw new SchedulingAgentPermissionException('Personal scheduling permissions are required on confirmation.');
    $validated=fm8d18j4c_payload([
        'date'=>$payload['date']??null,'type'=>$payload['type']??null,
        'all_day'=>$payload['allDay']??null,
        'start'=>($payload['allDay']??false)?null:($payload['start']??null),
        'end'=>($payload['allDay']??false)?null:($payload['end']??null),
        'note'=>$payload['note']??'',
    ]);
    $pdo->beginTransaction();
    try{
        $membership=$pdo->prepare("SELECT id FROM organization_memberships WHERE organization_id=? AND user_id=? AND status='active' FOR UPDATE");
        $membership->execute([$org,$uid]);
        if(!$membership->fetchColumn()||!scheduling_staff_user($pdo,$org,$uid))
            throw new InvalidArgumentException('Your active staff profile is no longer available.');
        fm8d18j4c_no_duplicate($pdo,$org,$uid,$validated);
        $row=scheduling_exception_create($pdo,$org,$uid,[
            'date'=>$validated['date'],'type'=>$validated['type'],
            'start'=>$validated['start'],'end'=>$validated['end'],'note'=>$validated['note'],
        ],$uid,false);
        app_audit($pdo,$org,$uid,'schedule.availability_exception_created',
            'availability_exception',(string)$row['public_id'],null,
            ['proposalId'=>$proposal['id'],'source'=>'food_monster_model']);
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    return ['skill'=>'schedule.action_confirmed',
        'answer'=>'Confirmed. Submitted your '.$validated['type'].' request for '.$validated['date'].
            '. It is pending manager review. Your assigned shifts were not changed.',
        'data'=>['action'=>'availability_exception_request','requestId'=>$row['public_id'],'status'=>'pending'],
        'sources'=>['Staff Scheduling']];
}
function fm8d18j4c_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j4c_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_my_availability_exception'=>[
        'description'=>'ONLY for the signed-in employee explicitly asking to submit ONE dated available or unavailable exception for manager review. Require exact YYYY-MM-DD, explicit type and whether full day or an exact 24-hour HH:MM start/end range. A separate Confirm creates a PENDING request, not approval and not a shift assignment change. Use propose_my_time_off_request for time off instead. Never guess dates, times, or identities.',
        'parameters'=>['type'=>'object','properties'=>[
            'date'=>['type'=>'string','description'=>'Exact restaurant-local YYYY-MM-DD date'],
            'type'=>['type'=>'string','enum'=>['available','unavailable']],
            'all_day'=>['type'=>'boolean','description'=>'True for full day; false requires start/end'],
            'start'=>['type'=>'string','description'=>'Required HH:MM 24h if all_day=false; omit for full day'],
            'end'=>['type'=>'string','description'=>'Required HH:MM after start if all_day=false; omit for full day'],
            'note'=>['type'=>'string','description'=>'Optional note, max 1000 characters'],
        ],'required'=>['date','type','all_day'],'additionalProperties'=>false],
        'allowedArguments'=>['date','type','all_day','start','end','note'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j4c_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j4c_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j4c_propose($args,$user,$context),
    ]];
}
