<?php
declare(strict_types=1);

/** B8D18J4D: manager review of one pending available/unavailable exception. */
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/scheduling-agent-core.php';
require_once __DIR__.'/agent-node-registry.php';
require_once __DIR__.'/timeclock-agent-core.php';

function fm8d18j4d_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('schedule.agent',$actor)
        &&app_has_permission('schedule.manage',$actor);
}
function fm8d18j4d_fields(array $args):array
{
    $missing=[];$id=$args['request_id']??null;
    if(!is_string($id)||trim($id)===''||strlen($id)>120||
       !preg_match('/^[A-Za-z0-9_.:-]+$/D',$id))$missing[]='request_id';
    if(!in_array($args['decision']??null,['approved','rejected'],true))$missing[]='decision';
    return $missing;
}
function fm8d18j4d_pending(PDO $pdo,int $org,string $id):array
{
    $q=$pdo->prepare("SELECT e.*,u.display_name FROM staff_availability_exceptions e
        INNER JOIN users u ON u.id=e.user_id AND u.organization_id=e.organization_id
        WHERE e.organization_id=? AND e.public_id=?
          AND e.exception_type IN ('available','unavailable') AND e.status='pending' LIMIT 1");
    $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new InvalidArgumentException('The exact pending availability exception was not found. Select its public request ID.');
    return $row;
}
function fm8d18j4d_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18j4d_can($actor))throw new DomainException('Scheduling Agent management permission is required.');
    if($missing=fm8d18j4d_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
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
        $id=trim($args['request_id']);$row=fm8d18j4d_pending($pdo,$org,$id);
        $summary='Proposed manager decision: '.($args['decision']==='approved'?'APPROVE':'REJECT').
            ' '.$row['exception_type'].' exception '.$id.' for '.$row['display_name'].
            ' on '.$row['exception_date'].'. No existing assigned shifts will change. '.
            'This decision will not be recorded until separate Confirm.';
        $pending=sac_pending_store($org,$uid,'availability_exception_review',[
            'requestId'=>$id,'decision'=>$args['decision'],
            'expectedUpdatedAt'=>$row['updated_at'],
            'source'=>'food_monster_model',
        ],$summary);
        app_audit($pdo,$org,$uid,'schedule.agent_action_proposed','schedule_agent_proposal',
            (string)$pending['id'],null,['type'=>'availability_exception_review','source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)sac_proposal_result($pending,['Staff Scheduling'])['answer'],
            'source'=>'Gelato Scheduling Agent (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$pending['id'],'type'=>$pending['type'],'expiresAt'=>$pending['expires']],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j4d_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j4d_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_review_availability_exception'=>[
        'description'=>'ONLY for an explicit management decision to approve/reject ONE pending dated available/unavailable exception by EXACT public request ID. Time-off reviews use propose_review_time_off_request separately. Never infer a request from employee name alone, bulk-review, or claim approval before a separate Confirm. Fresh management permission and atomic record version are checked on confirmation. Existing shift assignments are not modified.',
        'parameters'=>['type'=>'object','properties'=>[
            'request_id'=>['type'=>'string','description'=>'Exact public ID of one pending available/unavailable exception'],
            'decision'=>['type'=>'string','enum'=>['approved','rejected']],
        ],'required'=>['request_id','decision'],'additionalProperties'=>false],
        'allowedArguments'=>['request_id','decision'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j4d_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j4d_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j4d_propose($args,$user,$context),
    ]];
}
