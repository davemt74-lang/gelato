<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/catering-operations.php';

$user=app_require_permission($_SERVER['REQUEST_METHOD']==='GET'?'catering.view':'catering.manage');
$pdo=app_pdo();$organizationId=(int)$user['organization_id'];$userId=(int)$user['id'];
if(!catering_operations_ready($pdo))app_json_response(['ok'=>false,'message'=>'Catering operations migration is not installed. Run upgrade.php.'],503);

function catering_ops_users(PDO $pdo,int $organizationId):array
{
    $stmt=$pdo->prepare("SELECT DISTINCT u.id,u.display_name FROM users u INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? WHERE u.status='active' AND u.archived_at IS NULL ORDER BY u.display_name");$stmt->execute([$organizationId]);return $stmt->fetchAll();
}
function catering_ops_recipes(PDO $pdo,int $organizationId):array
{
    if(!restaurant_brain_table_ready($pdo,'recipes'))return [];$stmt=$pdo->prepare("SELECT public_id,name,category,yield_quantity,yield_unit FROM recipes WHERE organization_id=? AND archived_at IS NULL AND status='active' ORDER BY name LIMIT 500");$stmt->execute([$organizationId]);return $stmt->fetchAll();
}
function catering_ops_operation(PDO $pdo,int $organizationId,string $id):array
{
    $row=catering_operation_row($pdo,$organizationId,$id);if(!$row)app_json_response(['ok'=>false,'message'=>'Catering operation not found.'],404);return $row;
}
function catering_ops_valid_user(PDO $pdo,int $organizationId,int $userId):bool
{
    if($userId<=0)return false;$stmt=$pdo->prepare("SELECT COUNT(*) FROM users u INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL");$stmt->execute([$organizationId,$userId]);return (int)$stmt->fetchColumn()>0;
}
function catering_ops_datetime(mixed $value):?string
{
    $value=trim((string)$value);if($value==='')return null;$dt=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$value);if(!$dt)$dt=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$value);if(!$dt)app_json_response(['ok'=>false,'message'=>'Invalid date/time value.'],422);return $dt->format('Y-m-d H:i:s');
}
function catering_ops_after_change(PDO $pdo,int $organizationId,int $operationId,int $userId,string $auditAction,string $publicId,array $meta=[]):void
{
    catering_operations_readiness($pdo,$organizationId,$operationId,true);catering_operations_sync_knowledge($pdo,$organizationId,$operationId,$userId);app_audit($pdo,$organizationId,$userId,$auditAction,'restaurant_operation',$publicId,null,$meta);
}

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'list');
    if($action==='list'){
        $rows=catering_operation_list($pdo,$organizationId,(int)($_GET['days']??120));foreach($rows as &$row)$row['readiness']=catering_operations_readiness($pdo,$organizationId,(int)$row['id'],true);unset($row);
        app_json_response(['ok'=>true,'operations'=>$rows]);
    }
    if($action==='summary'){
        $rows=catering_operation_list($pdo,$organizationId,180);$active=0;$ready=0;$overdue=0;$unassigned=0;foreach($rows as &$row){$row['readiness']=catering_operations_readiness($pdo,$organizationId,(int)$row['id'],true);if(!in_array($row['status'],['completed','cancelled'],true)){$active++;if((int)$row['readiness']['percent']>=90)$ready++;$overdue+=(int)$row['readiness']['overdueTasks'];$unassigned+=(int)$row['readiness']['unassignedCritical'];}}unset($row);
        app_json_response(['ok'=>true,'active'=>$active,'ready'=>$ready,'overdueTasks'=>$overdue,'unassignedCritical'=>$unassigned,'operations'=>$rows,'users'=>catering_ops_users($pdo,$organizationId),'recipes'=>catering_ops_recipes($pdo,$organizationId)]);
    }
    if($action==='detail'){
        $id=trim((string)($_GET['id']??''));$op=catering_ops_operation($pdo,$organizationId,$id);$operationId=(int)$op['id'];$readiness=catering_operations_readiness($pdo,$organizationId,$operationId,true);
        app_json_response(['ok'=>true,'operation'=>$op,'readiness'=>$readiness,'menu'=>catering_operation_menu($pdo,$organizationId,$operationId),'requirements'=>catering_operation_requirements($pdo,$organizationId,$operationId),'tasks'=>catering_operation_tasks($pdo,$organizationId,$operationId),'staff'=>catering_operation_staff($pdo,$organizationId,$operationId),'users'=>catering_ops_users($pdo,$organizationId),'recipes'=>catering_ops_recipes($pdo,$organizationId)]);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported catering operations action.'],422);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');$publicId=trim((string)($input['id']??''));$op=catering_ops_operation($pdo,$organizationId,$publicId);$operationId=(int)$op['id'];

if($action==='operation_update'){
    $notes=mb_substr(trim((string)($input['notes']??$op['notes']??'')),0,10000,'UTF-8');
    $status=(string)$op['status'];
    if(($op['source_type']??'')!=='catering'){
        $requested=(string)($input['status']??$status);if(!in_array($requested,['planning','active','ready','completed','cancelled'],true))app_json_response(['ok'=>false,'message'=>'Invalid operation status.'],422);$status=$requested;
    }
    $pdo->prepare('UPDATE restaurant_operations SET status=?,notes=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$status,$notes?:null,$userId,$operationId,$organizationId]);catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.updated',$publicId,['status'=>$status,'lifecycleSource'=>(($op['source_type']??'')==='catering'?'catering_pipeline':'operations')]);app_json_response(['ok'=>true,'message'=>'Operations notes updated. Catering lifecycle status follows the catering pipeline.']);
}

if($action==='menu_save'){
    $menuId=(int)($input['menuId']??0);$recipePublicId=trim((string)($input['recipeId']??''));$recipeId=null;$recipe=null;
    if($recipePublicId!==''){$stmt=$pdo->prepare("SELECT * FROM recipes WHERE organization_id=? AND public_id=? AND archived_at IS NULL AND status='active' LIMIT 1");$stmt->execute([$organizationId,$recipePublicId]);$recipe=$stmt->fetch();if(!$recipe)app_json_response(['ok'=>false,'message'=>'Recipe not found.'],422);$recipeId=(int)$recipe['id'];}
    $name=mb_substr(trim((string)($input['itemName']??($recipe['name']??''))),0,220,'UTF-8');if($name==='')app_json_response(['ok'=>false,'message'=>'Menu item name is required.'],422);
    $servings=$input['targetServings']??null;if($servings!==null&&$servings!==''&&!is_numeric($servings))app_json_response(['ok'=>false,'message'=>'Target servings must be numeric.'],422);$servings=$servings===''?null:$servings;
    $batches=$input['batches']??null;if($batches!==null&&$batches!==''&&!is_numeric($batches))app_json_response(['ok'=>false,'message'=>'Batches must be numeric.'],422);if(($batches===null||$batches==='')&&$recipe&&$servings!==null&&(float)$recipe['yield_quantity']>0)$batches=round((float)$servings/(float)$recipe['yield_quantity'],3);$batches=$batches===''?null:$batches;
    $notes=mb_substr(trim((string)($input['notes']??'')),0,5000,'UTF-8');
    if($menuId>0){$check=$pdo->prepare('SELECT COUNT(*) FROM restaurant_operation_menu_items WHERE id=? AND organization_id=? AND operation_id=?');$check->execute([$menuId,$organizationId,$operationId]);if(!(int)$check->fetchColumn())app_json_response(['ok'=>false,'message'=>'Menu item not found.'],404);$pdo->prepare('UPDATE restaurant_operation_menu_items SET recipe_id=?,item_name=?,target_servings=?,batches=?,notes=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND operation_id=?')->execute([$recipeId,$name,$servings,$batches,$notes?:null,$userId,$menuId,$organizationId,$operationId]);}
    else{$pdo->prepare('INSERT INTO restaurant_operation_menu_items (organization_id,operation_id,recipe_id,item_name,target_servings,batches,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$organizationId,$operationId,$recipeId,$name,$servings,$batches,$notes?:null,$userId,$userId]);}
    $count=catering_operations_rebuild_requirements($pdo,$organizationId,$operationId,$userId);catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.menu_saved',$publicId,['itemName'=>$name,'requirements'=>$count]);app_json_response(['ok'=>true,'message'=>'Operational menu saved and ingredient requirements regenerated.']);
}

if($action==='menu_delete'){
    $menuId=(int)($input['menuId']??0);$stmt=$pdo->prepare('DELETE FROM restaurant_operation_menu_items WHERE id=? AND organization_id=? AND operation_id=?');$stmt->execute([$menuId,$organizationId,$operationId]);$count=catering_operations_rebuild_requirements($pdo,$organizationId,$operationId,$userId);catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.menu_deleted',$publicId,['menuId'=>$menuId,'requirements'=>$count]);app_json_response(['ok'=>true,'message'=>'Menu item removed and requirements regenerated.']);
}

if($action==='match_recipes'){
    $leadText=mb_strtolower(trim((string)($op['menu_interests']??'')),'UTF-8');if($leadText==='')app_json_response(['ok'=>false,'message'=>'This catering inquiry has no menu interests to match.'],422);
    $recipes=$pdo->prepare("SELECT * FROM recipes WHERE organization_id=? AND archived_at IS NULL AND status='active' ORDER BY CHAR_LENGTH(name) DESC");$recipes->execute([$organizationId]);$insert=$pdo->prepare("INSERT INTO restaurant_operation_menu_items (organization_id,operation_id,recipe_id,item_name,target_servings,batches,notes,created_by,updated_by) SELECT ?,?,?,?,?,?,?,?,? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM restaurant_operation_menu_items WHERE organization_id=? AND operation_id=? AND recipe_id=?)");$matched=0;$target=(int)($op['guest_count']??0)?:null;
    foreach($recipes->fetchAll() as $recipe){$name=mb_strtolower(trim((string)$recipe['name']),'UTF-8');if($name===''||!str_contains($leadText,$name))continue;$batches=$target&&(float)$recipe['yield_quantity']>0?round($target/(float)$recipe['yield_quantity'],3):null;$insert->execute([$organizationId,$operationId,(int)$recipe['id'],$recipe['name'],$target,$batches,'Matched from catering inquiry menu interests.',$userId,$userId,$organizationId,$operationId,(int)$recipe['id']]);$matched+=$insert->rowCount();}
    $count=catering_operations_rebuild_requirements($pdo,$organizationId,$operationId,$userId);catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.recipes_matched',$publicId,['matched'=>$matched,'requirements'=>$count]);app_json_response(['ok'=>true,'message'=>$matched?"Matched {$matched} recipe(s) and regenerated requirements.":'No exact recipe-name matches were found in the catering menu text.','matched'=>$matched]);
}

if($action==='requirements_rebuild'){$count=catering_operations_rebuild_requirements($pdo,$organizationId,$operationId,$userId);catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.requirements_rebuilt',$publicId,['requirements'=>$count]);app_json_response(['ok'=>true,'message'=>"Generated {$count} ingredient requirement row(s)."]);}

if($action==='requirement_status'){
    $requirementId=(int)($input['requirementId']??0);$status=(string)($input['status']??'planned');if(!in_array($status,['planned','ordered','ready','unavailable'],true))app_json_response(['ok'=>false,'message'=>'Invalid requirement status.'],422);$stmt=$pdo->prepare('UPDATE restaurant_operation_requirements SET status=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND operation_id=?');$stmt->execute([$status,$userId,$requirementId,$organizationId,$operationId]);catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.requirement_status',$publicId,['requirementId'=>$requirementId,'status'=>$status]);app_json_response(['ok'=>true,'message'=>'Ingredient requirement updated.']);
}

if($action==='task_save'){
    $taskId=(int)($input['taskId']??0);$title=mb_substr(trim((string)($input['title']??'')),0,240,'UTF-8');if($title==='')app_json_response(['ok'=>false,'message'=>'Task title is required.'],422);$category=mb_substr(trim((string)($input['category']??'operations')),0,60,'UTF-8')?:'operations';$description=mb_substr(trim((string)($input['description']??'')),0,10000,'UTF-8');$due=catering_ops_datetime($input['dueAt']??'');$priority=(string)($input['priority']??'normal');if(!in_array($priority,['low','normal','high','critical'],true))$priority='normal';$assignedTo=(int)($input['assignedTo']??0);$assignedTo=$assignedTo>0?$assignedTo:null;if($assignedTo&&!catering_ops_valid_user($pdo,$organizationId,$assignedTo))app_json_response(['ok'=>false,'message'=>'Task assignee is not an active organization user.'],422);
    if($taskId>0){$stmt=$pdo->prepare('UPDATE restaurant_operation_tasks SET category=?,title=?,description=?,due_at=?,priority=?,assigned_to=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND operation_id=?');$stmt->execute([$category,$title,$description?:null,$due,$priority,$assignedTo,$userId,$taskId,$organizationId,$operationId]);}
    else{$stmt=$pdo->prepare("INSERT INTO restaurant_operation_tasks (organization_id,operation_id,category,title,description,due_at,status,priority,assigned_to,created_by,updated_by) VALUES (?,?,?,?,?,?,'open',?,?,?,?)");$stmt->execute([$organizationId,$operationId,$category,$title,$description?:null,$due,$priority,$assignedTo,$userId,$userId]);}
    catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.task_saved',$publicId,['title'=>$title]);app_json_response(['ok'=>true,'message'=>'Operations task saved.']);
}

if($action==='task_status'){
    $taskId=(int)($input['taskId']??0);$status=(string)($input['status']??'open');if(!in_array($status,['open','in_progress','blocked','done','cancelled'],true))app_json_response(['ok'=>false,'message'=>'Invalid task status.'],422);$stmt=$pdo->prepare('UPDATE restaurant_operation_tasks SET status=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND operation_id=?');$stmt->execute([$status,$userId,$taskId,$organizationId,$operationId]);catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.task_status',$publicId,['taskId'=>$taskId,'status'=>$status]);app_json_response(['ok'=>true,'message'=>'Task status updated.']);
}

if($action==='task_delete'){$taskId=(int)($input['taskId']??0);$pdo->prepare('DELETE FROM restaurant_operation_tasks WHERE id=? AND organization_id=? AND operation_id=?')->execute([$taskId,$organizationId,$operationId]);catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.task_deleted',$publicId,['taskId'=>$taskId]);app_json_response(['ok'=>true,'message'=>'Task removed.']);}

if($action==='staff_save'){
    $staffId=(int)($input['staffId']??0);$assigned=(int)($input['userId']??0);if(!catering_ops_valid_user($pdo,$organizationId,$assigned))app_json_response(['ok'=>false,'message'=>'Choose an active organization user.'],422);$role=mb_substr(trim((string)($input['roleName']??'')),0,120,'UTF-8');if($role==='')app_json_response(['ok'=>false,'message'=>'Staff role is required.'],422);$start=catering_ops_datetime($input['shiftStartAt']??'');$end=catering_ops_datetime($input['shiftEndAt']??'');if($start&&$end&&$end<$start)app_json_response(['ok'=>false,'message'=>'Shift end must be after shift start.'],422);$status=(string)($input['status']??'planned');if(!in_array($status,['planned','confirmed','checked_in','completed','cancelled'],true))$status='planned';$notes=mb_substr(trim((string)($input['notes']??'')),0,500,'UTF-8');
    if($staffId>0){$stmt=$pdo->prepare('UPDATE restaurant_operation_staff SET user_id=?,role_name=?,shift_start_at=?,shift_end_at=?,status=?,notes=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND operation_id=?');$stmt->execute([$assigned,$role,$start,$end,$status,$notes?:null,$userId,$staffId,$organizationId,$operationId]);}
    else{$stmt=$pdo->prepare('INSERT INTO restaurant_operation_staff (organization_id,operation_id,user_id,role_name,shift_start_at,shift_end_at,status,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$organizationId,$operationId,$assigned,$role,$start,$end,$status,$notes?:null,$userId,$userId]);}
    catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.staff_saved',$publicId,['assignedUserId'=>$assigned,'role'=>$role]);app_json_response(['ok'=>true,'message'=>'Event staffing assignment saved.']);
}

if($action==='staff_delete'){$staffId=(int)($input['staffId']??0);$pdo->prepare('DELETE FROM restaurant_operation_staff WHERE id=? AND organization_id=? AND operation_id=?')->execute([$staffId,$organizationId,$operationId]);catering_ops_after_change($pdo,$organizationId,$operationId,$userId,'catering.operations.staff_deleted',$publicId,['staffId'=>$staffId]);app_json_response(['ok'=>true,'message'=>'Staff assignment removed.']);}

app_json_response(['ok'=>false,'message'=>'Unsupported catering operations action.'],422);
