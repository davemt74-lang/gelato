<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/operations-core.php';
$user=app_require_auth();$pdo=app_pdo();$organizationId=(int)$user['organization_id'];$userId=(int)$user['id'];
$canTasks=app_has_permission('tasks.agent',$user)&&app_has_permission('tasks.view',$user);$canInventory=app_has_permission('inventory.agent',$user)&&app_has_permission('inventory.view',$user);if(!$canTasks&&!$canInventory)app_json_response(['ok'=>false,'message'=>'Operations Agent permission required.'],403);if(!operations_core_ready($pdo))app_json_response(['ok'=>false,'message'=>'Operations Core migration is not installed. Run upgrade.php.'],503);

function ops_agent_split_items(string $text):array
{
    $text=preg_replace('/\b(?:and then|then)\b/i',';',$text)??$text;
    $parts=preg_split('/[;\n,]+/',$text)?:[];
    return array_values(array_filter(array_map(static fn(string $v):string=>trim($v," \t\n\r\0\x0B.-"),$parts),static fn(string $v):bool=>$v!==''));
}
function ops_agent_users(PDO $pdo,int $organizationId):array
{
    $stmt=$pdo->prepare("SELECT DISTINCT u.id,u.display_name,u.first_name,u.last_name FROM users u INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? WHERE u.status='active' AND u.archived_at IS NULL ORDER BY CHAR_LENGTH(u.display_name) DESC");$stmt->execute([$organizationId]);return $stmt->fetchAll();
}
function ops_agent_extract_assignees(PDO $pdo,int $organizationId,string &$text):array
{
    if(!preg_match('/\b(?:assign(?:ed)?\s+(?:this\s+|them\s+|it\s+)?to|tag)\s+(.+)$/iu',$text,$m))return [];
    $clause=trim($m[1]);$ids=[];
    foreach(ops_agent_users($pdo,$organizationId) as $row){$names=array_unique(array_filter([(string)$row['display_name'],(string)$row['first_name'],trim((string)$row['first_name'].' '.(string)$row['last_name'])]));foreach($names as $name){if($name!==''&&preg_match('/\b'.preg_quote($name,'/').'\b/iu',$clause)){$ids[]=(int)$row['id'];break;}}}
    if($ids)$text=trim(substr($text,0,(int)$m[0][1]));
    return array_values(array_unique($ids));
}
function ops_agent_task_create(PDO $pdo,int $organizationId,int $userId,string $title,string $category,string $transcript,array $assigneeIds=[]):array
{
    return operations_create_task($pdo,$organizationId,['title'=>$title,'category'=>$category,'transcript'=>$transcript,'aiConfidence'=>1.0,'assigneeIds'=>$assigneeIds],$userId);
}

if($_SERVER['REQUEST_METHOD']==='GET'){
    $summary=operations_core_summary($pdo,$organizationId);$alerts=[];if((int)$summary['overdue']>0)$alerts[]=$summary['overdue'].' task(s) are overdue.';if((int)$summary['lowStock']>0)$alerts[]=$summary['lowStock'].' inventory item(s) are at or below reorder point.';app_json_response(['ok'=>true,'skill'=>'operations.proactive_summary','summary'=>$summary,'alerts'=>$alerts]);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$message=trim((string)($input['message']??''));if($message===''||mb_strlen($message,'UTF-8')>4000)app_json_response(['ok'=>false,'message'=>'Enter an operations request no longer than 4,000 characters.'],422);$normalized=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/i','',$message)??$message,'UTF-8');

try{
    if($canTasks&&preg_match('/\badd\s+(?:a\s+)?task\s+category(?:\s+(?:called|named))?\s+(.+)$/iu',$normalized,$m)){
        if(!app_has_permission('tasks.manage',$user))app_json_response(['ok'=>false,'message'=>'Task management permission is required to add a category.'],403);$name=trim($m[1]," .\t\n\r");$category=operations_create_category($pdo,$organizationId,ucwords($name),$userId);app_audit($pdo,$organizationId,$userId,'agent.task_category_created','task_category',(string)$category['public_id'],null,['message'=>$message]);app_json_response(['ok'=>true,'skill'=>'tasks.category_create','answer'=>'Created the task category “'.$category['name'].'”.','data'=>$category,'sources'=>[(string)$category['public_id']]]);
    }
    if($canTasks&&(preg_match('/\badd(?:\s+these|\s+the following)?(?:\s+items?)?\s+to\s+(?:the\s+)?prep\s+list\s*[:,-]?\s*(.+)$/iu',$normalized,$m)||preg_match('/\b(?:add|put)\s+(.+?)\s+(?:to|on)\s+(?:the\s+)?prep\s+list\b(.*)$/iu',$normalized,$m))){
        if(!app_has_permission('tasks.manage',$user))app_json_response(['ok'=>false,'message'=>'Task management permission is required to add prep tasks.'],403);
        $taskText=trim(($m[1]??'').' '.($m[2]??''));$assigneeIds=ops_agent_extract_assignees($pdo,$organizationId,$taskText);$items=ops_agent_split_items($taskText);if(!$items)app_json_response(['ok'=>false,'message'=>'Tell me which prep items to add.'],422);$created=[];foreach(array_slice($items,0,30) as $item)$created[]=ops_agent_task_create($pdo,$organizationId,$userId,$item,'prep',$message,$assigneeIds);$names=array_column($created,'title');$assigneeNote=$assigneeIds?' and tagged '.count($assigneeIds).' employee(s)':'';app_audit($pdo,$organizationId,$userId,'agent.prep_tasks_created','restaurant_task','prep',null,['count'=>count($created),'assigneeIds'=>$assigneeIds,'message'=>$message]);app_json_response(['ok'=>true,'skill'=>'tasks.prep_add','answer'=>'Added '.count($created).' item(s) to the prep list'.$assigneeNote.': '.implode(', ',$names).'.','data'=>$created,'sources'=>array_column($created,'public_id')]);
    }
    if($canTasks&&preg_match('/\badd\s+(?:a\s+)?task\s*[:,-]?\s*(.+)$/iu',$normalized,$m)){
        if(!app_has_permission('tasks.manage',$user))app_json_response(['ok'=>false,'message'=>'Task management permission is required to add tasks.'],403);$taskText=trim($m[1]);$assigneeIds=ops_agent_extract_assignees($pdo,$organizationId,$taskText);$task=ops_agent_task_create($pdo,$organizationId,$userId,$taskText,'general',$message,$assigneeIds);app_audit($pdo,$organizationId,$userId,'agent.task_created','restaurant_task',(string)$task['public_id'],null,['assigneeIds'=>$assigneeIds,'message'=>$message]);app_json_response(['ok'=>true,'skill'=>'tasks.create','answer'=>'Added the task “'.$task['title'].'”'.($assigneeIds?' and tagged the requested employee(s)':'').'.','data'=>$task,'sources'=>[(string)$task['public_id']]]);
    }
    if($canInventory&&preg_match('/\b(?:build|create|sync|refresh|update)\b.*\binventory\b.*\b(?:menu|recipe|recipes|sources)\b/iu',$normalized)){
        if(!app_has_permission('inventory.manage',$user))app_json_response(['ok'=>false,'message'=>'Inventory management permission is required to synchronize inventory sources.'],403);$result=operations_sync_inventory_sources($pdo,$organizationId,$userId);app_audit($pdo,$organizationId,$userId,'agent.inventory_synced','inventory','all',null,$result);app_json_response(['ok'=>true,'skill'=>'inventory.sync','answer'=>'Inventory synchronized from the menu ingredient catalog and active recipes. '.$result['items'].' ingredient records were touched, covering '.$result['menuReferences'].' menu references and '.$result['recipeReferences'].' recipe references.','data'=>$result,'sources'=>[]]);
    }
    if($canInventory&&preg_match('/\b(inventory|stock|par|reorder|shortage|running out|ingredient)\b/iu',$normalized)){$result=operations_agent_inventory_answer($pdo,$organizationId,$message);app_audit($pdo,$organizationId,$userId,'agent.operations_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,500,'UTF-8')]);app_json_response(['ok'=>true]+$result);}
    if($canTasks&&preg_match('/\b(task|tasks|prep|opening|closing|cleaning|assignment|assigned|overdue|to do|todo)\b/iu',$normalized)){$result=operations_agent_task_answer($pdo,$organizationId,$message,$userId);app_audit($pdo,$organizationId,$userId,'agent.operations_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,500,'UTF-8')]);app_json_response(['ok'=>true]+$result);}
    $summary=operations_core_summary($pdo,$organizationId);$answer='Operations snapshot: '.$summary['openTasks'].' open task(s), '.$summary['inProgress'].' in progress, '.$summary['overdue'].' overdue, '.$summary['inventoryCount'].' inventory item(s), and '.$summary['lowStock'].' at/below reorder point.';app_json_response(['ok'=>true,'skill'=>'operations.summary','answer'=>$answer,'data'=>$summary,'sources'=>[]]);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
