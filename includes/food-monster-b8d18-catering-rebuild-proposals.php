<?php
declare(strict_types=1);

/** B8D18J7D: explicit, version-guarded catering ingredient regeneration proposal. */
require_once __DIR__.'/food-monster-b8d18-catering-ingredient-status.php';

function fm8d18j7d_fields(array $args):array
{
    $missing=fm8d18j7b_operation_fields($args);
    if(($args['reset_existing_statuses']??null)!==true)$missing[]='reset_existing_statuses';
    return $missing;
}
function fm8d18j7d_propose(array $args,array $actor):array
{
    if(!fm8d18j7b_can($actor))throw new DomainException('Catering Agent management permission is required.');
    if($missing=fm8d18j7d_fields($args))
        throw new InvalidArgumentException('Specify exact event identity and explicitly acknowledge ingredient status resets: '.implode(', ',$missing).'.');
    require_once __DIR__.'/catering-agent-actions.php';
    require_once __DIR__.'/agent-node-registry.php';
    $pdo=app_pdo();$org=(int)$actor['organization_id'];
    $operation=fm8d18j7b_operation($pdo,$actor,$args);$id=(int)$operation['id'];
    $menu=catering_operation_menu($pdo,$org,$id);
    $linked=array_values(array_filter($menu,static fn(array $r):bool=>(int)($r['recipe_id']??0)>0));
    if(!$linked)throw new InvalidArgumentException('The selected event has no recipe-linked menu items to regenerate ingredients from.');
    $requirements=catering_operation_requirements($pdo,$org,$id);
    $counts=['planned'=>0,'ordered'=>0,'ready'=>0,'unavailable'=>0];
    foreach($requirements as $r){
        $status=(string)$r['status'];
        if(array_key_exists($status,$counts))$counts[$status]++;
    }
    $existing=count($requirements);
    $warning=$existing>0?('This will DELETE and REPLACE all '.$existing.' saved ingredient requirement rows, including manual notes, and RESET their statuses to planned. Existing states: planned '.$counts['planned'].', ordered '.$counts['ordered'].', ready '.$counts['ready'].', unavailable '.$counts['unavailable'].'.'):
        'No ingredient requirement rows currently exist. Recipe-linked requirements will be generated as planned.';
    $summary='Proposed ingredient regeneration for '.$operation['title'].' ('.$operation['public_id'].') using '.count($linked).' recipe-linked menu item(s). '.$warning.' This is a destructive reset and needs a separate Confirm.';
    return fm8d18j7b_stage($actor,'requirements_rebuild',[
        'operationPublicId'=>(string)$operation['public_id'],
        'menuSignature'=>caa_menu_signature($pdo,$org,$id),
        'menuInputsSignature'=>caa_rebuild_inputs_signature($pdo,$org,$id),
        'requirementsSignature'=>caa_requirements_signature($pdo,$org,$id),
        'resetAcknowledged'=>true,
    ],$summary);
}
function fm8d18j7d_catalogue(array $actor,array $context=[]):array
{
    if(!fm8d18j7b_can($actor))return [];
    if(($context['channel']??'')==='voice'&&($context['privateVoiceApproved']??false)!==true)return [];
    return ['propose_catering_ingredient_rebuild'=>[
        'description'=>'ONLY when an authorized catering manager explicitly requests to REGENERATE the ingredient requirements from recipe-linked menu items for ONE exact saved active catering event, and explicitly acknowledges that existing ingredient requirement rows, manual notes and saved statuses will be deleted/replaced and statuses reset to planned. Require exact event public ID, matching saved title and reset_existing_statuses=true. If the user has not acknowledged that reset, ask before selecting the tool. This only stages the existing Catering Agent Confirm/Cancel proposal; it locks and rechecks menu, recipe and current ingredient signatures at confirmation. No direct write or implicit stock adjustment.',
        'parameters'=>['type'=>'object','properties'=>[
            'operation_public_id'=>['type'=>'string','description'=>'Exact saved catering operation public ID'],
            'operation_title'=>['type'=>'string','description'=>'Matching saved event title'],
            'reset_existing_statuses'=>['type'=>'boolean','description'=>'True ONLY after explicit user acknowledgement that existing saved requirement rows/notes/statuses will be reset'],
        ],'required'=>['operation_public_id','operation_title','reset_existing_statuses'],'additionalProperties'=>false],
        'allowedArguments'=>['operation_public_id','operation_title','reset_existing_statuses'],
        'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>fm8d18j7b_can($user),
        'validate'=>static fn(array $args,array $user):array=>fm8d18j7d_fields($args),
        'execute'=>static fn(array $args,array $user):array=>fm8d18j7d_propose($args,$user),
    ]];
}
