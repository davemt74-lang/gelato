<?php
declare(strict_types=1);

/** B8D18J4B: one-day recurring availability replacement by confirmed self action. */
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/scheduling-agent-core.php';
require_once __DIR__.'/agent-node-registry.php';
require_once __DIR__.'/timeclock-agent-core.php';

function fm8d18j4b_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('schedule.agent',$actor)
        &&app_has_permission('schedule.self',$actor);
}
function fm8d18j4b_fields(array $args):array
{
    $missing=[];$day=$args['weekday']??null;
    if(!is_int($day)||$day<0||$day>6)$missing[]='weekday';
    foreach(['start','end'] as $part){
        $time=$args[$part]??null;
        if(!is_string($time)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))
            $missing[]=$part;
    }
    if(!in_array($args['type']??null,['available','unavailable'],true))$missing[]='type';
    if(!in_array('start',$missing,true)&&!in_array('end',$missing,true)&&$args['end']<=$args['start'])
        $missing[]='end';
    return array_values(array_unique($missing));
}
function fm8d18j4b_day_rows(PDO $pdo,int $org,int $uid,int $day,bool $lock=false):array
{
    $q=$pdo->prepare('SELECT id,weekday,start_time,end_time,availability_type FROM staff_availability
        WHERE organization_id=? AND user_id=? AND weekday=? ORDER BY id'.($lock?' FOR UPDATE':''));
    $q->execute([$org,$uid,$day]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function fm8d18j4b_fingerprint(array $rows):string
{
    return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
}
function fm8d18j4b_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18j4b_can($actor))throw new DomainException('Personal Scheduling Agent permission is required.');
    if($missing=fm8d18j4b_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(($context['channel']??'')==='voice'){
        $eventId=trim((string)($context['voiceEventId']??''));
        if(($context['privateVoiceApproved']??false)!==true||$eventId===''||
           !tv_voice_event_valid($pdo,$org,$uid,$eventId))
            throw new DomainException('Matching private voice identity and one-use approval are required for spoken availability changes.');
    }
    if(!scheduling_core_ready($pdo))throw new RuntimeException('Staff Scheduling is unavailable. Complete upgrade.php first.');
    if(!scheduling_staff_user($pdo,$org,$uid))throw new InvalidArgumentException('An active staff scheduling profile is required.');
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start Scheduling confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null)
            throw new InvalidArgumentException('Another agent action is pending. Confirm or Cancel it first.');
        $day=$args['weekday'];$existing=fm8d18j4b_day_rows($pdo,$org,$uid,$day);
        $days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $summary='Proposed action: REPLACE ALL '.count($existing).' saved '.$days[$day].
            ' recurring availability window(s) with one '.$args['type'].' window '.
            $args['start'].'–'.$args['end'].'. Other weekdays remain unchanged. '.
            'This changes recurring availability, not existing assigned shifts.';
        $pending=sac_pending_store($org,$uid,'self_availability_day',[
            'weekday'=>$day,'start'=>$args['start'],'end'=>$args['end'],'type'=>$args['type'],
            'expectedFingerprint'=>fm8d18j4b_fingerprint($existing),
            'source'=>'food_monster_model',
        ],$summary);
        app_audit($pdo,$org,$uid,'schedule.agent_action_proposed','schedule_agent_proposal',
            (string)$pending['id'],null,['type'=>'self_availability_day','source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)sac_proposal_result($pending,['Staff Scheduling'])['answer'],
            'source'=>'Gelato Scheduling Agent (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$pending['id'],'type'=>$pending['type'],'expiresAt'=>$pending['expires']],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j4b_apply(PDO $pdo,int $org,int $uid,array $actor,array $proposal,array $payload):array
{
    if(!fm8d18j4b_can($actor))throw new SchedulingAgentPermissionException('Personal Scheduling Agent permission is required on confirmation.');
    if($missing=fm8d18j4b_fields($payload))throw new InvalidArgumentException('Invalid availability parameters: '.implode(', ',$missing).'.');
    $day=$payload['weekday'];
    $pdo->beginTransaction();
    try{
        $membership=$pdo->prepare("SELECT id FROM organization_memberships WHERE organization_id=? AND user_id=? AND status='active' FOR UPDATE");
        $membership->execute([$org,$uid]);
        if(!$membership->fetchColumn()||!scheduling_staff_user($pdo,$org,$uid))
            throw new InvalidArgumentException('Your active staff profile is no longer available.');
        $current=fm8d18j4b_day_rows($pdo,$org,$uid,$day,true);
        if(fm8d18j4b_fingerprint($current)!==(string)($payload['expectedFingerprint']??''))
            throw new InvalidArgumentException('Your saved availability changed after the proposal. Review the new windows and request again.');
        $delete=$pdo->prepare('DELETE FROM staff_availability WHERE organization_id=? AND user_id=? AND weekday=?');
        $delete->execute([$org,$uid,$day]);
        $add=$pdo->prepare('INSERT INTO staff_availability
            (organization_id,user_id,weekday,start_time,end_time,availability_type,created_by,updated_by)
            VALUES (?,?,?,?,?,?,?,?)');
        $add->execute([$org,$uid,$day,$payload['start'].':00',$payload['end'].':00',
            $payload['type'],$uid,$uid]);
        app_audit($pdo,$org,$uid,'schedule.availability_saved','user',(string)$uid,null,
            ['weekday'=>$day,'replacedWindows'=>count($current),'proposalId'=>$proposal['id'],'source'=>'food_monster_model']);
        $pdo->commit();
    }catch(Throwable $e){$pdo->rollBack();throw $e;}
    $days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    return ['skill'=>'schedule.action_confirmed',
        'answer'=>'Confirmed. Your recurring '.$days[$day].' availability is now '.$payload['type'].
            ' from '.$payload['start'].' to '.$payload['end'].'. Other days and existing shifts were unchanged.',
        'data'=>['action'=>'self_availability_day','weekday'=>$day,'type'=>$payload['type']],
        'sources'=>['Staff Scheduling']];
}
function fm8d18j4b_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j4b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_set_my_weekday_availability'=>[
        'description'=>'ONLY when the signed-in employee explicitly requests REPLACING ALL existing recurring availability windows for ONE named weekday with ONE exact start/end range and type available/unavailable. weekday uses Sunday=0, Monday=1, ..., Saturday=6; start/end are 24-hour HH:MM. Never infer a weekday, times, or type. If the user wants an additional second window instead of replacing the day, ask for clarification. Existing shifts are unaffected. The Scheduling Agent shows how many same-day windows will be replaced and requires a separate Confirm with fresh permission and stale-record checks.',
        'parameters'=>['type'=>'object','properties'=>[
            'weekday'=>['type'=>'integer','minimum'=>0,'maximum'=>6,'description'=>'Sunday=0, Monday=1, Tuesday=2, Wednesday=3, Thursday=4, Friday=5, Saturday=6'],
            'start'=>['type'=>'string','description'=>'Exact HH:MM, 24-hour restaurant-local time'],
            'end'=>['type'=>'string','description'=>'Exact HH:MM strictly after start'],
            'type'=>['type'=>'string','enum'=>['available','unavailable']],
        ],'required'=>['weekday','start','end','type'],'additionalProperties'=>false],
        'allowedArguments'=>['weekday','start','end','type'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j4b_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j4b_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j4b_propose($args,$user,$context),
    ]];
}
