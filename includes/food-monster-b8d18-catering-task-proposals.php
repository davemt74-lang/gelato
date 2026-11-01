<?php
declare(strict_types=1);

/** B8D18J7B: catering task proposals through the existing Confirm/Cancel Agent. */

function fm8d18j7b_can(array $actor):bool
{
    if((int)($actor['id']??0)<1||(int)($actor['organization_id']??0)<1)return false;
    foreach(['catering.view','catering.agent','catering.manage'] as $permission)
        if(!app_has_permission($permission,$actor))return false;
    return true;
}
function fm8d18j7b_operation_fields(array $args):array
{
    $missing=[];$id=$args['operation_public_id']??null;$name=$args['operation_title']??null;
    if(!is_string($id)||strlen($id)<2||strlen($id)>120
       ||!preg_match('/^[A-Za-z0-9_.:-]+$/D',$id))$missing[]='operation_public_id';
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>240
       ||preg_match('/[\x00-\x1F]/u',$name))$missing[]='operation_title';
    return $missing;
}
function fm8d18j7b_add_fields(array $args):array
{
    $missing=fm8d18j7b_operation_fields($args);$title=$args['task_title']??null;
    if(!is_string($title)||trim($title)===''||mb_strlen(trim($title),'UTF-8')>240
       ||preg_match('/[\x00-\x1F]/u',$title))$missing[]='task_title';
    return $missing;
}
function fm8d18j7b_status_fields(array $args):array
{
    $missing=fm8d18j7b_operation_fields($args);$id=$args['task_id']??null;$title=$args['task_title']??null;
    if(!is_int($id)||$id<1)$missing[]='task_id';
    if(!is_string($title)||trim($title)===''||mb_strlen(trim($title),'UTF-8')>240
       ||preg_match('/[\x00-\x1F]/u',$title))$missing[]='task_title';
    if(!in_array($args['status']??null,['open','in_progress','blocked','done','cancelled'],true))$missing[]='status';
    return $missing;
}
function fm8d18j7b_operation(PDO $pdo,array $actor,array $args):array
{
    $org=(int)$actor['organization_id'];
    if(!catering_operations_ready($pdo))throw new RuntimeException('Catering operations are unavailable. Complete upgrade.php first.');
    $operation=catering_operation_row($pdo,$org,(string)$args['operation_public_id']);
    if(!$operation)throw new InvalidArgumentException('The exact saved catering operation was not found.');
    if((string)$operation['title']!==trim((string)$args['operation_title']))
        throw new InvalidArgumentException('The event title does not match the saved operation ID.');
    caa_operation_writable($operation);
    return $operation;
}
function fm8d18j7b_stage(array $actor,string $type,array $payload,string $summary):array
{
    $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
    $opened=session_status()!==PHP_SESSION_ACTIVE;
    if($opened&&!session_start())throw new RuntimeException('Could not start the secure catering confirmation session.');
    try{
        if(gaw_pending_action_node($org,$uid)!==null||gac_pending_get('catering',$org,$uid)!==null)
            throw new InvalidArgumentException('A restaurant action is already pending. Confirm or Cancel it first.');
        $pending=gac_pending_store('catering',$org,$uid,$type,$payload,$summary);
        app_audit(app_pdo(),$org,$uid,'catering.agent_action_proposed','catering_agent_proposal',
            (string)$pending['id'],null,['type'=>$type,'source'=>'food_monster_model']);
        return ['ok'=>true,'data'=>[
            'answer'=>$summary."\n\nReply Confirm to apply through the Catering Agent, or Cancel. No catering record has changed yet.",
            'source'=>'Gelato Catering Operations (server-verified pending proposal)',
            'requiresConfirmation'=>true,'proposal'=>['id'=>$pending['id'],'type'=>$type],
        ]];
    }finally{if($opened&&session_status()===PHP_SESSION_ACTIVE)session_write_close();}
}
function fm8d18j7b_add(array $args,array $actor):array
{
    if(!fm8d18j7b_can($actor))throw new DomainException('Catering Agent management permission is required.');
    if($missing=fm8d18j7b_add_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/catering-agent-actions.php';
    require_once __DIR__.'/agent-node-registry.php';
    $pdo=app_pdo();$operation=fm8d18j7b_operation($pdo,$actor,$args);$title=trim((string)$args['task_title']);
    return fm8d18j7b_stage($actor,'task_add',[
        'operationPublicId'=>(string)$operation['public_id'],'title'=>$title,
    ],'Proposed action: add open catering task “'.$title.'” to '.$operation['title'].' ('.$operation['public_id'].').');
}
function fm8d18j7b_status(array $args,array $actor):array
{
    if(!fm8d18j7b_can($actor))throw new DomainException('Catering Agent management permission is required.');
    if($missing=fm8d18j7b_status_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/catering-agent-actions.php';
    require_once __DIR__.'/agent-node-registry.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];$operation=fm8d18j7b_operation($pdo,$actor,$args);
    $task=caa_task_row($pdo,$org,(int)$operation['id'],(int)$args['task_id']);
    if(!$task)throw new InvalidArgumentException('The selected catering task was not found in that event.');
    if((string)$task['title']!==trim((string)$args['task_title']))
        throw new InvalidArgumentException('The selected task title does not match its saved ID.');
    $status=(string)$args['status'];
    if((string)$task['status']===$status)throw new InvalidArgumentException('The task already has that status.');
    return fm8d18j7b_stage($actor,'task_status',[
        'operationPublicId'=>(string)$operation['public_id'],
        'taskId'=>(int)$task['id'],'status'=>$status,
        'expectedStatus'=>(string)$task['status'],'expectedUpdatedAt'=>(string)$task['updated_at'],
    ],'Proposed action: change task “'.$task['title'].'” (#'.$task['id'].') for '.$operation['title'].' from '.$task['status'].' to '.$status.'.');
}
function fm8d18j7b_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j7b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return [
        'propose_catering_task_add'=>[
            'description'=>'ONLY when the authorized catering manager explicitly asks to ADD one specified task to ONE selected active, non-completed/non-cancelled catering operation. Require exact saved event public ID and matching event title, plus explicit new task text. This only stages the existing Catering Agent task_add Confirm/Cancel proposal, not a direct write. Do not infer tasks from readiness reports.',
            'parameters'=>['type'=>'object','properties'=>[
                'operation_public_id'=>['type'=>'string','description'=>'Exact saved catering operation public ID'],
                'operation_title'=>['type'=>'string','description'=>'Matching saved event title'],
                'task_title'=>['type'=>'string','description'=>'Exact requested new task title'],
            ],'required'=>['operation_public_id','operation_title','task_title'],'additionalProperties'=>false],
            'allowedArguments'=>['operation_public_id','operation_title','task_title'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j7b_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j7b_add_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j7b_add($args,$user),
        ],
        'propose_catering_task_status'=>[
            'description'=>'ONLY when the authorized catering manager explicitly asks to set ONE existing task status for ONE exact selected catering operation. Require exact saved event ID and title, task numeric ID and matching saved task title, and one allowed status: open, in_progress, blocked, done, cancelled. This stages only the existing Catering Agent task_status Confirm/Cancel; specialist rechecks task status and updated_at at confirmation.',
            'parameters'=>['type'=>'object','properties'=>[
                'operation_public_id'=>['type'=>'string','description'=>'Exact saved catering operation public ID'],
                'operation_title'=>['type'=>'string','description'=>'Matching saved event title'],
                'task_id'=>['type'=>'integer','minimum'=>1,'description'=>'Exact saved catering task numeric ID from event task read'],
                'task_title'=>['type'=>'string','description'=>'Matching saved catering task title'],
                'status'=>['type'=>'string','enum'=>['open','in_progress','blocked','done','cancelled']],
            ],'required'=>['operation_public_id','operation_title','task_id','task_title','status'],'additionalProperties'=>false],
            'allowedArguments'=>['operation_public_id','operation_title','task_id','task_title','status'],'impact'=>'read',
            'authorize'=>static fn(array $user,array $args):bool=>fm8d18j7b_can($user),
            'validate'=>static fn(array $args,array $user):array=>fm8d18j7b_status_fields($args),
            'execute'=>static fn(array $args,array $user):array=>fm8d18j7b_status($args,$user),
        ],
    ];
}
