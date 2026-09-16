<?php
declare(strict_types=1);

require_once __DIR__.'/catering-brain.php';
require_once __DIR__.'/catering-operations.php';
require_once __DIR__.'/agent-confirmation-core.php';

final class CateringAgentActionPermissionException extends RuntimeException {}

function caa_require(array $user,string $permission,string $message): void
{
    if(!app_has_permission($permission,$user))throw new CateringAgentActionPermissionException($message);
}

function caa_clean_public_id(mixed $value): string
{
    return preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)$value))??'';
}

function caa_context_operation(PDO $pdo,int $org,array $context): ?array
{
    if((string)($context['module']??'')!=='catering')return null;
    $public=caa_clean_public_id($context['selectedOperationPublicId']??'');
    return $public!==''?catering_operation_row($pdo,$org,$public):null;
}

function caa_operation_writable(array $operation): void
{
    if(in_array((string)($operation['status']??''),['completed','cancelled'],true))throw new InvalidArgumentException('Completed or cancelled catering operations are read-only.');
}

function caa_after_change(PDO $pdo,int $org,int $operationId,int $uid,string $auditAction,string $publicId,array $meta=[]): void
{
    catering_operations_readiness($pdo,$org,$operationId,true);
    if(function_exists('catering_operations_sync_knowledge'))catering_operations_sync_knowledge($pdo,$org,$operationId,$uid);
    app_audit($pdo,$org,$uid,$auditAction,'restaurant_operation',$publicId,null,$meta);
}

function caa_task_row(PDO $pdo,int $org,int $operationId,int $taskId): ?array
{
    $q=$pdo->prepare('SELECT * FROM restaurant_operation_tasks WHERE organization_id=? AND operation_id=? AND id=? LIMIT 1');
    $q->execute([$org,$operationId,$taskId]);$row=$q->fetch();return $row?:null;
}

function caa_requirement_row(PDO $pdo,int $org,int $operationId,int $requirementId): ?array
{
    $q=$pdo->prepare('SELECT * FROM restaurant_operation_requirements WHERE organization_id=? AND operation_id=? AND id=? LIMIT 1');
    $q->execute([$org,$operationId,$requirementId]);$row=$q->fetch();return $row?:null;
}

function caa_find_task(PDO $pdo,int $org,int $operationId,string $query): array
{
    $query=mb_strtolower(trim($query),'UTF-8');
    if($query==='')throw new InvalidArgumentException('Name the catering task you want to change.');
    $rows=catering_operation_tasks($pdo,$org,$operationId);
    $exact=array_values(array_filter($rows,static fn($row)=>mb_strtolower(trim((string)$row['title']),'UTF-8')===$query));
    if(count($exact)===1)return $exact[0];
    $matches=array_values(array_filter($rows,static fn($row)=>str_contains(mb_strtolower((string)$row['title'],'UTF-8'),$query)));
    if(count($matches)===1)return $matches[0];
    if(!$matches)throw new InvalidArgumentException('No catering task matched “'.$query.'”.');
    $names=array_map(static fn($row)=>(string)$row['title'],array_slice($matches,0,5));
    throw new InvalidArgumentException('More than one catering task matched. Be more specific: '.implode('; ',$names).'.');
}

function caa_find_requirement(PDO $pdo,int $org,int $operationId,string $query): array
{
    $query=mb_strtolower(trim($query),'UTF-8');
    if($query==='')throw new InvalidArgumentException('Name the ingredient requirement you want to change.');
    $rows=catering_operation_requirements($pdo,$org,$operationId);
    $exact=array_values(array_filter($rows,static fn($row)=>mb_strtolower(trim((string)$row['ingredient_name']),'UTF-8')===$query));
    if(count($exact)===1)return $exact[0];
    $matches=array_values(array_filter($rows,static fn($row)=>str_contains(mb_strtolower((string)$row['ingredient_name'],'UTF-8'),$query)));
    if(count($matches)===1)return $matches[0];
    if(!$matches)throw new InvalidArgumentException('No ingredient requirement matched “'.$query.'”.');
    $names=array_values(array_unique(array_map(static fn($row)=>(string)$row['ingredient_name'],array_slice($matches,0,8))));
    throw new InvalidArgumentException('More than one ingredient requirement matched. Be more specific: '.implode('; ',$names).'.');
}

function caa_menu_signature(PDO $pdo,int $org,int $operationId): string
{
    $normalized=[];
    foreach(catering_operation_menu($pdo,$org,$operationId) as $row)$normalized[]=[
        'id'=>(int)$row['id'],'recipeId'=>(int)($row['recipe_id']??0),
        'servings'=>$row['target_servings']!==null?round((float)$row['target_servings'],4):null,
        'batches'=>$row['batches']!==null?round((float)$row['batches'],4):null,
        'updatedAt'=>(string)($row['updated_at']??''),
    ];
    return hash('sha256',json_encode($normalized,JSON_UNESCAPED_SLASHES));
}

function caa_task_status_from_message(string $message): ?array
{
    if(!preg_match('~\b(?:mark|set|move)\s+(?:the\s+)?task\s+(.+?)\s+(?:as\s+|to\s+)?(done|complete|completed|in\s+progress|blocked|open|cancelled|canceled)\b~iu',$message,$m))return null;
    $status=mb_strtolower(trim((string)$m[2]),'UTF-8');
    $status=['complete'=>'done','completed'=>'done','in progress'=>'in_progress','canceled'=>'cancelled'][$status]??$status;
    return ['query'=>trim((string)$m[1]," \t\n\r\0\x0B\"'“”"),'status'=>$status];
}

function caa_requirement_status_from_message(string $message): ?array
{
    if(!preg_match('~\b(?:mark|set)\s+(?:(?:the\s+)?(?:ingredient|requirement)\s+)(.+?)\s+(?:as\s+|to\s+)?(ready|ordered|unavailable|planned)\b~iu',$message,$m))return null;
    return ['query'=>trim((string)$m[1]," \t\n\r\0\x0B\"'“”"),'status'=>mb_strtolower(trim((string)$m[2]),'UTF-8')];
}

function caa_readiness_answer(PDO $pdo,int $org,array $operation): array
{
    $r=catering_operations_readiness($pdo,$org,(int)$operation['id'],true);$issues=(array)($r['issues']??[]);
    $answer=(string)$operation['title'].' is '.(int)$r['percent'].'% ready. '.(int)$r['taskDone'].' of '.(int)$r['taskTotal'].' tasks are done; '.(int)$r['requirementsReady'].' of '.(int)$r['requirementsTotal'].' ingredient requirements are ready; '.(int)$r['staffCount'].' staff are assigned.';
    if($issues)$answer.=' Attention: '.implode(' ',$issues);
    return ['ok'=>true,'skill'=>'catering.context_readiness','answer'=>$answer,'data'=>['operationId'=>$operation['public_id'],'readiness'=>$r],'sources'=>['Catering Operations']];
}

function caa_tasks_answer(PDO $pdo,int $org,array $operation): array
{
    $rows=catering_operation_tasks($pdo,$org,(int)$operation['id']);$open=array_values(array_filter($rows,static fn($row)=>!in_array((string)$row['status'],['done','cancelled'],true)));
    if(!$open)return ['ok'=>true,'skill'=>'catering.context_tasks','answer'=>'All active catering tasks for '.$operation['title'].' are complete.','data'=>['operationId'=>$operation['public_id'],'tasks'=>0],'sources'=>['Catering Operations → Tasks']];
    $parts=[];foreach(array_slice($open,0,10) as $row)$parts[]=$row['title'].' · '.str_replace('_',' ',(string)$row['status']).($row['due_at']?' · due '.$row['due_at']:'').($row['assigned_name']?' · '.$row['assigned_name']:' · unassigned');
    return ['ok'=>true,'skill'=>'catering.context_tasks','answer'=>'Open catering tasks: '.implode('; ',$parts).'.','data'=>['operationId'=>$operation['public_id'],'tasks'=>count($open)],'sources'=>['Catering Operations → Tasks']];
}

function caa_requirements_answer(PDO $pdo,int $org,array $operation): array
{
    $rows=catering_operation_requirements($pdo,$org,(int)$operation['id']);
    if(!$rows)return ['ok'=>true,'skill'=>'catering.context_requirements','answer'=>'No ingredient requirements have been generated for '.$operation['title'].' yet.','data'=>['operationId'=>$operation['public_id'],'requirements'=>0],'sources'=>['Catering Operations → Ingredients']];
    $attention=array_values(array_filter($rows,static fn($row)=>(string)$row['status']!=='ready'||$row['required_quantity']===null));$parts=[];
    foreach(array_slice($attention?:$rows,0,12) as $row)$parts[]=$row['ingredient_name'].' '.($row['required_quantity']!==null?rtrim(rtrim(number_format((float)$row['required_quantity'],4,'.',''),'0'),'.').' '.($row['unit']??''):'quantity TBD').' · '.$row['status'];
    return ['ok'=>true,'skill'=>'catering.context_requirements','answer'=>($attention?'Ingredient attention: ':'Ingredient requirements: ').implode('; ',$parts).'.','data'=>['operationId'=>$operation['public_id'],'requirements'=>count($rows),'attention'=>count($attention)],'sources'=>['Catering Operations → Ingredients']];
}

function caa_staff_answer(PDO $pdo,int $org,array $operation): array
{
    $rows=catering_operation_staff($pdo,$org,(int)$operation['id']);
    if(!$rows)return ['ok'=>true,'skill'=>'catering.context_staff','answer'=>'No staff are assigned to '.$operation['title'].' yet.','data'=>['operationId'=>$operation['public_id'],'staff'=>0],'sources'=>['Catering Operations → Staffing']];
    $parts=[];foreach(array_slice($rows,0,12) as $row)$parts[]=$row['user_name'].' · '.$row['role_name'].' · '.$row['status'].($row['shift_start_at']?' · '.$row['shift_start_at']:'');
    return ['ok'=>true,'skill'=>'catering.context_staff','answer'=>'Catering staffing: '.implode('; ',$parts).'.','data'=>['operationId'=>$operation['public_id'],'staff'=>count($rows)],'sources'=>['Catering Operations → Staffing']];
}

function caa_execute_pending(PDO $pdo,int $org,int $uid,array $user,array $proposal): array
{
    if((int)($proposal['organizationId']??0)!==$org||(int)($proposal['userId']??0)!==$uid)throw new CateringAgentActionPermissionException('This Catering Agent proposal does not belong to your session.');
    caa_require($user,'catering.manage','Catering management permission is required for this action.');
    $type=(string)$proposal['type'];$payload=(array)$proposal['payload'];$public=caa_clean_public_id($payload['operationPublicId']??'');
    $operation=$public!==''?catering_operation_row($pdo,$org,$public):null;
    if(!$operation)throw new InvalidArgumentException('The selected catering operation no longer exists.');
    caa_operation_writable($operation);$operationId=(int)$operation['id'];

    if($type==='task_add'){
        $title=mb_substr(trim((string)$payload['title']),0,240,'UTF-8');if($title==='')throw new InvalidArgumentException('The proposed catering task title is empty.');
        $pdo->prepare("INSERT INTO restaurant_operation_tasks (organization_id,operation_id,category,title,status,priority,created_by,updated_by) VALUES (?,?, 'operations',?,'open','normal',?,?)")->execute([$org,$operationId,$title,$uid,$uid]);
        caa_after_change($pdo,$org,$operationId,$uid,'catering.agent_task_added',$public,['proposalId'=>$proposal['id'],'title'=>$title]);
        return ['skill'=>'catering.action_confirmed','answer'=>'Confirmed. Added the catering task “'.$title.'” to '.$operation['title'].'.','data'=>['action'=>'task_add','operationId'=>$public],'sources'=>['Catering Operations → Tasks']];
    }

    if($type==='task_status'){
        $task=caa_task_row($pdo,$org,$operationId,(int)$payload['taskId']);if(!$task)throw new InvalidArgumentException('The proposed catering task no longer exists.');
        if((string)$task['status']!==(string)($payload['expectedStatus']??'')||(string)$task['updated_at']!==(string)($payload['expectedUpdatedAt']??''))throw new InvalidArgumentException('That catering task changed after I proposed the action. Review it and ask again.');
        $next=(string)$payload['status'];if(!in_array($next,['open','in_progress','blocked','done','cancelled'],true))throw new InvalidArgumentException('The proposed catering task status is no longer supported.');
        $q=$pdo->prepare('UPDATE restaurant_operation_tasks SET status=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND operation_id=? AND status=? AND updated_at=?');
        $q->execute([$next,$uid,(int)$task['id'],$org,$operationId,$payload['expectedStatus'],$payload['expectedUpdatedAt']]);
        if($q->rowCount()!==1)throw new InvalidArgumentException('That catering task changed while I was confirming it. Review it and ask again.');
        caa_after_change($pdo,$org,$operationId,$uid,'catering.agent_task_status',$public,['proposalId'=>$proposal['id'],'taskId'=>$task['id'],'status'=>$next]);
        return ['skill'=>'catering.action_confirmed','answer'=>'Confirmed. Marked “'.$task['title'].'” '.str_replace('_',' ',$next).'.','data'=>['action'=>'task_status','operationId'=>$public,'taskId'=>(int)$task['id'],'status'=>$next],'sources'=>['Catering Operations → Tasks']];
    }

    if($type==='requirement_status'){
        $row=caa_requirement_row($pdo,$org,$operationId,(int)$payload['requirementId']);if(!$row)throw new InvalidArgumentException('The proposed ingredient requirement no longer exists.');
        if((string)$row['status']!==(string)($payload['expectedStatus']??'')||(string)$row['updated_at']!==(string)($payload['expectedUpdatedAt']??''))throw new InvalidArgumentException('That ingredient requirement changed after I proposed the action. Review it and ask again.');
        $next=(string)$payload['status'];if(!in_array($next,['planned','ordered','ready','unavailable'],true))throw new InvalidArgumentException('The proposed ingredient status is no longer supported.');
        $q=$pdo->prepare('UPDATE restaurant_operation_requirements SET status=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND operation_id=? AND status=? AND updated_at=?');
        $q->execute([$next,$uid,(int)$row['id'],$org,$operationId,$payload['expectedStatus'],$payload['expectedUpdatedAt']]);
        if($q->rowCount()!==1)throw new InvalidArgumentException('That ingredient requirement changed while I was confirming it. Review it and ask again.');
        caa_after_change($pdo,$org,$operationId,$uid,'catering.agent_requirement_status',$public,['proposalId'=>$proposal['id'],'requirementId'=>$row['id'],'status'=>$next]);
        return ['skill'=>'catering.action_confirmed','answer'=>'Confirmed. Marked '.$row['ingredient_name'].' '.$next.' for '.$operation['title'].'.','data'=>['action'=>'requirement_status','operationId'=>$public,'requirementId'=>(int)$row['id'],'status'=>$next],'sources'=>['Catering Operations → Ingredients']];
    }

    if($type==='requirements_rebuild'){
        if(caa_menu_signature($pdo,$org,$operationId)!==(string)($payload['menuSignature']??''))throw new InvalidArgumentException('The catering menu changed after I proposed rebuilding ingredients. Review the menu and ask again.');
        $count=catering_operations_rebuild_requirements($pdo,$org,$operationId,$uid);
        caa_after_change($pdo,$org,$operationId,$uid,'catering.agent_requirements_rebuilt',$public,['proposalId'=>$proposal['id'],'requirements'=>$count]);
        return ['skill'=>'catering.action_confirmed','answer'=>'Confirmed. Regenerated '.$count.' ingredient requirement row'.($count===1?'':'s').' for '.$operation['title'].'.','data'=>['action'=>'requirements_rebuild','operationId'=>$public,'requirements'=>$count],'sources'=>['Catering Operations → Menu & Recipes','Catering Operations → Ingredients']];
    }
    throw new InvalidArgumentException('That pending Catering Agent action is no longer supported.');
}

function catering_agent_actions_handle(PDO $pdo,array $user,array $input): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    caa_require($user,'catering.view','Catering view permission is required.');caa_require($user,'catering.agent','Catering Agent permission is required.');
    $message=trim((string)($input['message']??''));if($message===''||mb_strlen($message,'UTF-8')>1800)throw new InvalidArgumentException('Ask Gelato a catering question no longer than 1,800 characters.');
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];$operation=caa_context_operation($pdo,$org,$context);$pending=gac_pending_get('catering',$org,$uid);

    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Catering Agent action to confirm.');
        try{$result=caa_execute_pending($pdo,$org,$uid,$user,$pending);}catch(Throwable $e){gac_pending_clear('catering',$org,$uid);throw $e;}
        gac_pending_clear('catering',$org,$uid);app_audit($pdo,$org,$uid,'catering.agent_action_confirmed','catering_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);return ['ok'=>true]+$result;
    }
    if(gac_is_cancel($message)&&$pending){
        gac_pending_clear('catering',$org,$uid);app_audit($pdo,$org,$uid,'catering.agent_action_discarded','catering_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);
        return ['ok'=>true,'skill'=>'catering.action_cancelled','answer'=>'Cancelled. I did not change the catering operation.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Catering Operations']];
    }

    if($operation){
        if(preg_match('/\b(?:rebuild|regenerate|recalculate)\b.*\b(?:ingredient|requirements?)\b|\b(?:ingredient|requirements?)\b.*\b(?:rebuild|regenerate|recalculate)\b/iu',$message)){
            caa_require($user,'catering.manage','Catering management permission is required to rebuild ingredient requirements.');caa_operation_writable($operation);
            $proposal=gac_pending_store('catering',$org,$uid,'requirements_rebuild',['operationPublicId'=>$operation['public_id'],'menuSignature'=>caa_menu_signature($pdo,$org,(int)$operation['id'])],'Proposed action: regenerate ingredient requirements for '.$operation['title'].' from its current recipe-linked operational menu.');
            app_audit($pdo,$org,$uid,'catering.agent_action_proposed','catering_agent_proposal',(string)$proposal['id'],null,['type'=>'requirements_rebuild','operationId'=>$operation['public_id']]);return gac_proposal_result($proposal,'catering.action_proposal',['Catering Operations → Menu & Recipes','Catering Operations → Ingredients']);
        }
        if(preg_match('/\b(?:add|create|make)\s+(?:a\s+)?(?:catering\s+)?task\s*[:\-]?\s*(.+)$/iu',$message,$m)){
            caa_require($user,'catering.manage','Catering management permission is required to add catering tasks.');caa_operation_writable($operation);
            $title=mb_substr(trim((string)$m[1]," \t\n\r\0\x0B?.!"),0,240,'UTF-8');if($title==='')throw new InvalidArgumentException('Tell me the task title to add.');
            $proposal=gac_pending_store('catering',$org,$uid,'task_add',['operationPublicId'=>$operation['public_id'],'title'=>$title],'Proposed action: add the open catering task “'.$title.'” to '.$operation['title'].'.');
            app_audit($pdo,$org,$uid,'catering.agent_action_proposed','catering_agent_proposal',(string)$proposal['id'],null,['type'=>'task_add','operationId'=>$operation['public_id']]);return gac_proposal_result($proposal,'catering.action_proposal',['Catering Operations → Tasks']);
        }
        $taskChange=caa_task_status_from_message($message);
        if($taskChange){
            caa_require($user,'catering.manage','Catering management permission is required to update catering tasks.');caa_operation_writable($operation);$task=caa_find_task($pdo,$org,(int)$operation['id'],$taskChange['query']);
            $proposal=gac_pending_store('catering',$org,$uid,'task_status',['operationPublicId'=>$operation['public_id'],'taskId'=>(int)$task['id'],'status'=>$taskChange['status'],'expectedStatus'=>$task['status'],'expectedUpdatedAt'=>$task['updated_at']],'Proposed action: mark the catering task “'.$task['title'].'” '.str_replace('_',' ',$taskChange['status']).' for '.$operation['title'].'.');
            app_audit($pdo,$org,$uid,'catering.agent_action_proposed','catering_agent_proposal',(string)$proposal['id'],null,['type'=>'task_status','operationId'=>$operation['public_id'],'taskId'=>$task['id']]);return gac_proposal_result($proposal,'catering.action_proposal',['Catering Operations → Tasks']);
        }
        $requirementChange=caa_requirement_status_from_message($message);
        if($requirementChange){
            caa_require($user,'catering.manage','Catering management permission is required to update ingredient requirements.');caa_operation_writable($operation);$row=caa_find_requirement($pdo,$org,(int)$operation['id'],$requirementChange['query']);
            $proposal=gac_pending_store('catering',$org,$uid,'requirement_status',['operationPublicId'=>$operation['public_id'],'requirementId'=>(int)$row['id'],'status'=>$requirementChange['status'],'expectedStatus'=>$row['status'],'expectedUpdatedAt'=>$row['updated_at']],'Proposed action: mark '.$row['ingredient_name'].' '.$requirementChange['status'].' for '.$operation['title'].'.');
            app_audit($pdo,$org,$uid,'catering.agent_action_proposed','catering_agent_proposal',(string)$proposal['id'],null,['type'=>'requirement_status','operationId'=>$operation['public_id'],'requirementId'=>$row['id']]);return gac_proposal_result($proposal,'catering.action_proposal',['Catering Operations → Ingredients']);
        }
        if(preg_match('/\b(?:ingredient|ingredients|requirement|requirements|shortage|shortages|unavailable)\b/iu',$message))return caa_requirements_answer($pdo,$org,$operation);
        if(preg_match('/\b(?:task|tasks|overdue|blocked|to do|todo|execution)\b/iu',$message))return caa_tasks_answer($pdo,$org,$operation);
        if(preg_match('/\b(?:staff|staffing|assigned|crew|captain|server|driver)\b/iu',$message))return caa_staff_answer($pdo,$org,$operation);
        if(preg_match('/\b(?:this event|selected event|this operation|selected operation|readiness|ready|status|what needs attention|what(?:\x27s| is) missing|summary|how are we looking)\b/iu',$message))return caa_readiness_answer($pdo,$org,$operation);
    }

    $result=(catering_operations_ready($pdo)&&function_exists('catering_agent_operations_intent')&&catering_agent_operations_intent($message))?catering_operations_agent_answer($pdo,$org,$message):catering_brain_answer($pdo,$org,$message);
    return ['ok'=>true]+$result;
}
