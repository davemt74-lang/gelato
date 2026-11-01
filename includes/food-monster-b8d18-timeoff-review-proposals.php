<?php
declare(strict_types=1);

/** B8D18J3A: manager review of one exact pending time-off request. */
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/scheduling-agent-core.php';
require_once __DIR__.'/agent-node-registry.php';
require_once __DIR__.'/timeclock-agent-core.php';

function fm8d18j3a_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('schedule.agent',$actor)
        &&app_has_permission('schedule.manage',$actor);
}
function fm8d18j3a_fields(array $args):array
{
    $missing=[];
    $id=$args['request_id']??null;
    if(!is_string($id)||trim($id)===''||strlen($id)>120||
       !preg_match('/^[A-Za-z0-9_.:-]+$/D',$id))$missing[]='request_id';
    if(!in_array($args['decision']??null,['approved','rejected'],true))$missing[]='decision';
    return $missing;
}
function fm8d18j3a_pending(PDO $pdo,int $org,string $id):array
{
    $stmt=$pdo->prepare("SELECT e.*,u.display_name FROM staff_availability_exceptions e
        INNER JOIN users u ON u.id=e.user_id AND u.organization_id=e.organization_id
        WHERE e.organization_id=? AND e.public_id=? AND e.exception_type='time_off'
          AND e.status='pending' LIMIT 1");
    $stmt->execute([$org,$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new InvalidArgumentException('The exact pending time-off request was not found. Open pending requests and select its ID.');
    return $row;
}
function fm8d18j3a_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18j3a_can($actor))throw new DomainException('Scheduling manager and Agent permission are required.');
    if($missing=fm8d18j3a_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(($context['channel']??'')==='voice'){
        $eventId=trim((string)($context['voiceEventId']??''));
        if(($context['privateVoiceApproved']??false)!==true||$eventId===''||
           !tv_voice_event_valid($pdo,$org,$uid,$eventId))
            throw new DomainException('Matching private voice identity and one-use approval are required for spoken reviews.');
    }
    if(!scheduling_core_ready($pdo))throw new RuntimeException('Staff Scheduling is unavailable. Complete upgrade.php first.');
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start Scheduling confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null)
            throw new InvalidArgumentException('Another agent action is pending. Confirm or Cancel it first.');
        $id=trim($args['request_id']);$row=fm8d18j3a_pending($pdo,$org,$id);
        $decision=$args['decision'];
        $summary='Proposed manager decision: '.($decision==='approved'?'APPROVE':'REJECT').
            ' time-off request '.$id.' for '.$row['display_name'].' on '.$row['exception_date'].
            '. This changes the request status only after a separate Confirm; it does not edit assigned shifts.';
        $pending=sac_pending_store($org,$uid,'time_off_review',[
            'requestId'=>$id,'decision'=>$decision,'expectedUpdatedAt'=>(string)$row['updated_at'],
            'source'=>'food_monster_model',
        ],$summary);
        app_audit($pdo,$org,$uid,'schedule.agent_action_proposed','schedule_agent_proposal',
            (string)$pending['id'],null,['type'=>'time_off_review','source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)sac_proposal_result($pending,['Staff Scheduling'])['answer'],
            'source'=>'Gelato Scheduling Agent (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$pending['id'],'type'=>$pending['type'],'expiresAt'=>$pending['expires']],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j3a_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j3a_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_review_time_off_request'=>[
        'description'=>'ONLY when a manager explicitly orders APPROVE or REJECT for ONE existing pending time-off request by its exact public request ID. Never infer the request ID, select by employee name alone, bulk approve, or claim a decision happened before Confirm. The Scheduling Agent verifies tenant and pending status, binds the original record version, then requires a separate Confirm with fresh permission and version checks. This does NOT reassign or remove scheduled shifts.',
        'parameters'=>['type'=>'object','properties'=>[
            'request_id'=>['type'=>'string','description'=>'Exact public ID shown in pending time-off requests'],
            'decision'=>['type'=>'string','enum'=>['approved','rejected']],
        ],'required'=>['request_id','decision'],'additionalProperties'=>false],
        'allowedArguments'=>['request_id','decision'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j3a_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j3a_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j3a_propose($args,$user,$context),
    ]];
}
