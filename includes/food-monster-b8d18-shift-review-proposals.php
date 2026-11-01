<?php
declare(strict_types=1);

/** B8D18J3B: manager proposal for one pending shift request review. */
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/scheduling-agent-core.php';
require_once __DIR__.'/agent-node-registry.php';
require_once __DIR__.'/timeclock-agent-core.php';

function fm8d18j3b_can(array $actor):bool
{
    return (int)($actor['id']??0)>0&&(int)($actor['organization_id']??0)>0
        &&app_has_permission('schedule.agent',$actor)&&app_has_permission('schedule.manage',$actor);
}
function fm8d18j3b_fields(array $args):array
{
    $missing=[];$id=$args['request_id']??null;
    if(!is_string($id)||trim($id)===''||strlen($id)>120||
       !preg_match('/^[A-Za-z0-9_.:-]+$/D',$id))$missing[]='request_id';
    if(!in_array($args['decision']??null,['approved','rejected'],true))$missing[]='decision';
    $note=$args['note']??'';
    if(!is_string($note)||mb_strlen($note,'UTF-8')>1000)$missing[]='note';
    return $missing;
}
/** Tenant-scoped fingerprint of both the review and its underlying shift assignments. */
function fm8d18j3b_snapshot(PDO $pdo,int $org,string $id):array
{
    $q=$pdo->prepare("SELECT r.public_id,r.status,r.request_type,r.requester_user_id,r.target_user_id,
        r.proposed_shift_id,r.updated_at,r.shift_id,
        s.public_id shift_public_id,s.updated_at shift_updated_at,s.user_id shift_user_id,
        s.status shift_status,s.archived_at shift_archived_at,
        ps.public_id proposed_shift_public_id,ps.updated_at proposed_updated_at,
        ps.user_id proposed_user_id,ps.status proposed_status,ps.archived_at proposed_archived_at,
        ru.display_name requester_name,tu.display_name target_name
        FROM schedule_shift_requests r
        INNER JOIN schedule_shifts s ON s.id=r.shift_id AND s.organization_id=r.organization_id
        INNER JOIN users ru ON ru.id=r.requester_user_id AND ru.organization_id=r.organization_id
        LEFT JOIN users tu ON tu.id=r.target_user_id AND tu.organization_id=r.organization_id
        LEFT JOIN schedule_shifts ps ON ps.id=r.proposed_shift_id AND ps.organization_id=r.organization_id
        WHERE r.organization_id=? AND r.public_id=? AND r.status='pending' LIMIT 1");
    $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new InvalidArgumentException('The exact pending shift request was not found. Select its public ID from the review list.');
    if(!in_array($row['request_type'],['release','swap'],true))
        throw new InvalidArgumentException('Unsupported pending shift request type.');
    if($row['shift_archived_at']!==null||!in_array($row['shift_status'],['scheduled','open'],true))
        throw new InvalidArgumentException('The requested shift has changed or is no longer active.');
    if((int)$row['shift_user_id']!==(int)$row['requester_user_id'])
        throw new InvalidArgumentException('The requested shift is no longer assigned to its original employee.');
    if($row['request_type']==='swap'){
        if((int)$row['target_user_id']<=0||$row['target_name']===null)
            throw new InvalidArgumentException('The swap target is no longer available.');
        if($row['proposed_shift_id']!==null&&(
            $row['proposed_shift_public_id']===null||
            $row['proposed_archived_at']!==null||
            (int)$row['proposed_user_id']!==(int)$row['target_user_id']))
            throw new InvalidArgumentException('The proposed swap shift has changed.');
    }
    return $row;
}
function fm8d18j3b_fingerprint(array $row):string
{
    unset($row['requester_name'],$row['target_name']);
    return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
}
function fm8d18j3b_propose(array $args,array $actor,array $context):array
{
    if(!fm8d18j3b_can($actor))throw new DomainException('Scheduling Agent management permission is required.');
    if($missing=fm8d18j3b_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    if(($context['channel']??'')==='voice'){
        $eventId=trim((string)($context['voiceEventId']??''));
        if(($context['privateVoiceApproved']??false)!==true||$eventId===''||
           !tv_voice_event_valid($pdo,$org,$uid,$eventId))
            throw new DomainException('Matching private voice identity and one-use approval are required for spoken shift reviews.');
    }
    if(!scheduling_core_ready($pdo))throw new RuntimeException('Staff Scheduling is unavailable. Complete upgrade.php first.');
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start Scheduling confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null)
            throw new InvalidArgumentException('Another agent action is pending. Confirm or Cancel it first.');
        $id=trim($args['request_id']);$row=fm8d18j3b_snapshot($pdo,$org,$id);
        $decision=$args['decision'];
        $summary='Proposed manager decision: '.($decision==='approved'?'APPROVE':'REJECT').
            ' '.$row['request_type'].' request '.$id.' from '.$row['requester_name'].
            ($row['target_name']?' involving '.$row['target_name']:'').
            '. Approval may change shift assignments. No shift changes until separate Confirm.';
        $pending=sac_pending_store($org,$uid,'shift_request_review',[
            'requestId'=>$id,'decision'=>$decision,'note'=>trim($args['note']??''),
            'expectedFingerprint'=>fm8d18j3b_fingerprint($row),
            'source'=>'food_monster_model',
        ],$summary);
        app_audit($pdo,$org,$uid,'schedule.agent_action_proposed','schedule_agent_proposal',
            (string)$pending['id'],null,['type'=>'shift_request_review','source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>(string)sac_proposal_result($pending,['Staff Scheduling'])['answer'],
            'source'=>'Gelato Scheduling Agent (server-verified proposal)',
            'requiresConfirmation'=>true,
            'proposal'=>['id'=>$pending['id'],'type'=>$pending['type'],'expiresAt'=>$pending['expires']],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j3b_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j3b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_review_shift_request'=>[
        'description'=>'ONLY when a scheduling manager explicitly approves or rejects ONE existing pending shift release or swap request by EXACT public request ID. Never guess IDs, bulk-review, assume target consent, or claim a shift was reassigned before Confirm. Approval can reassign shifts. The Scheduling Agent requires a separate confirmation, fresh management permission, unchanged request and shift versions, and availability/conflict checks.',
        'parameters'=>['type'=>'object','properties'=>[
            'request_id'=>['type'=>'string','description'=>'Exact saved public ID of one pending shift request'],
            'decision'=>['type'=>'string','enum'=>['approved','rejected']],
            'note'=>['type'=>'string','description'=>'Optional manager note, at most 1000 characters'],
        ],'required'=>['request_id','decision'],'additionalProperties'=>false],
        'allowedArguments'=>['request_id','decision','note'],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j3b_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j3b_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j3b_propose($args,$user,$context),
    ]];
}
