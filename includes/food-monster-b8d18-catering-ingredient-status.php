<?php
declare(strict_types=1);

/** B8D18J7C: one exact catering ingredient requirement status proposal. */
require_once __DIR__.'/food-monster-b8d18-catering-task-proposals.php';

function fm8d18j7c_fields(array $args):array
{
    $missing=fm8d18j7b_operation_fields($args);
    $id=$args['requirement_id']??null;$name=$args['ingredient_name']??null;
    if(!is_int($id)||$id<1)$missing[]='requirement_id';
    if(!is_string($name)||trim($name)===''||mb_strlen(trim($name),'UTF-8')>220
       ||preg_match('/[\x00-\x1F]/u',$name))$missing[]='ingredient_name';
    if(!in_array($args['status']??null,['planned','ordered','ready','unavailable'],true))$missing[]='status';
    return $missing;
}
function fm8d18j7c_propose(array $args,array $actor):array
{
    if(!fm8d18j7b_can($actor))throw new DomainException('Catering Agent management permission is required.');
    if($missing=fm8d18j7c_fields($args))throw new InvalidArgumentException('Specify '.implode(', ',$missing).'.');
    require_once __DIR__.'/catering-agent-actions.php';
    require_once __DIR__.'/agent-node-registry.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    $operation=fm8d18j7b_operation($pdo,$actor,$args);
    $row=caa_requirement_row($pdo,$org,(int)$operation['id'],(int)$args['requirement_id']);
    if(!$row)throw new InvalidArgumentException('The selected ingredient requirement does not exist in that event.');
    if((string)$row['ingredient_name']!==trim((string)$args['ingredient_name']))
        throw new InvalidArgumentException('The saved ingredient name does not match the selected requirement ID.');
    $status=(string)$args['status'];
    if((string)$row['status']===$status)throw new InvalidArgumentException('The ingredient requirement already has that status.');
    return fm8d18j7b_stage($actor,'requirement_status',[
        'operationPublicId'=>(string)$operation['public_id'],
        'requirementId'=>(int)$row['id'],'status'=>$status,
        'expectedStatus'=>(string)$row['status'],
        'expectedUpdatedAt'=>(string)$row['updated_at'],
    ],'Proposed action: mark ingredient requirement “'.$row['ingredient_name'].'” (#'.$row['id'].') for '.$operation['title'].' as '.$status.' (currently '.$row['status'].').');
}
function fm8d18j7c_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j7b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_catering_ingredient_status'=>[
        'description'=>'ONLY when an authorized catering manager explicitly requests a status change to ONE exact saved ingredient requirement for one selected catering event. Require saved operation public ID and matching title, requirement numeric ID and matching ingredient name, plus a target status (planned, ordered, ready, unavailable). This only stages the existing Catering Agent requirement_status Confirm/Cancel action, never a direct inventory stock adjustment or ingredient rebuild. The specialist rechecks unchanged requirement state and permission at confirmation.',
        'parameters'=>['type'=>'object','properties'=>[
            'operation_public_id'=>['type'=>'string','description'=>'Exact saved catering operation public ID'],
            'operation_title'=>['type'=>'string','description'=>'Matching saved event title'],
            'requirement_id'=>['type'=>'integer','minimum'=>1,'description'=>'Exact saved requirement ID'],
            'ingredient_name'=>['type'=>'string','description'=>'Matching saved ingredient name'],
            'status'=>['type'=>'string','enum'=>['planned','ordered','ready','unavailable']],
        ],'required'=>['operation_public_id','operation_title','requirement_id','ingredient_name','status'],'additionalProperties'=>false],
        'allowedArguments'=>['operation_public_id','operation_title','requirement_id','ingredient_name','status'],
        'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j7b_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j7c_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j7c_propose($args,$user),
    ]];
}
