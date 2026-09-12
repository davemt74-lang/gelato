<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/operations-core.php';
require_once __DIR__ . '/../includes/operations-wholesale.php';
require_once __DIR__ . '/../includes/operations-wholesale-agent.php';
$user=app_require_auth();$pdo=app_pdo();$organizationId=(int)$user['organization_id'];$userId=(int)$user['id'];
$canTasks=app_has_permission('tasks.agent',$user)&&app_has_permission('tasks.view',$user);$canInventory=app_has_permission('inventory.agent',$user)&&app_has_permission('inventory.view',$user);if(!$canTasks&&!$canInventory)app_json_response(['ok'=>false,'message'=>'Operations Agent permission required.'],403);if(!operations_core_ready($pdo))app_json_response(['ok'=>false,'message'=>'Operations Core migration is not installed. Run upgrade.php.'],503);operations_sync_wholesale_tasks($pdo,$organizationId,$userId);

function ops_agent_split_items(string $text):array
{
    $text=preg_replace('/\b(?:and then|then)\b/i',';',$text)??$text;
    $parts=preg_split('/[;\n,]+/',$text)?:[];
    return array_values(array_filter(array_map(static fn(string $v):string=>trim($v," \t\n\r\0\x0B.-"),$parts),static fn(string $v):bool=>$v!==''));
}
function ops_agent_users(PDO $pdo,int $organizationId):array
{
    $stmt=$pdo->prepare("SELECT DISTINCT u.id,u.display_name,u.first_name,u.last_name FROM users u INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? WHERE u.status='active' AND u.archived_at IS NULL AND COALESCE(om.job_title,'')<>'Wholesale Customer' AND NOT EXISTS (SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id WHERE ur.membership_id=om.id AND r.slug='wholesale_customer') ORDER BY CHAR_LENGTH(u.display_name) DESC");$stmt->execute([$organizationId]);return $stmt->fetchAll();
}
function ops_agent_extract_assignees(PDO $pdo,int $organizationId,string &$text,bool &$requested=false):array
{
    $requested=false;if(!preg_match('/\b(?:assign(?:ed)?\s+(?:this\s+|them\s+|it\s+)?to|tag)\s+(.+)$/iu',$text,$m))return [];$requested=true;
    $clause=trim($m[1]);$ids=[];
    foreach(ops_agent_users($pdo,$organizationId) as $row){
        $names=array_unique(array_filter([(string)$row['display_name'],(string)$row['first_name'],trim((string)$row['first_name'].' '.(string)$row['last_name'])]));
        foreach($names as $name){if($name!==''&&preg_match('/\b'.preg_quote($name,'/').'\b/iu',$clause)){$ids[]=(int)$row['id'];break;}}
    }
    $text=trim((string)(preg_replace('/\s*\b(?:assign(?:ed)?\s+(?:this\s+|them\s+|it\s+)?to|tag)\s+.+$/iu','',$text)??$text));
    return array_values(array_unique($ids));
}
function ops_agent_word_number(string $word):?float
{
    $numbers=['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,'twenty'=>20];
    return isset($numbers[mb_strtolower($word,'UTF-8')])?(float)$numbers[mb_strtolower($word,'UTF-8')]:null;
}
function ops_agent_parse_task_item(string $item):array
{
    $original=trim($item);$working=$original;
    if(preg_match('/\b(one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty)\b/iu',$working,$m)){
        $number=ops_agent_word_number($m[1]);if($number!==null)$working=preg_replace('/\b'.preg_quote($m[1],'/').'\b/iu',(string)$number,$working,1)??$working;
    }
    $unitPattern='lb|lbs|pound|pounds|oz|ounce|ounces|qt|quart|quarts|gal|gallon|gallons|cup|cups|pan|pans|tray|trays|batch|batches|case|cases|bag|bags|box|boxes|bottle|bottles|container|containers|dozen|each|ea';
    if(preg_match('/^(.*?)(\d+(?:\.\d+)?)\s+('.$unitPattern.')\b(?:\s+of)?\s*(.*)$/iu',$working,$m)){
        $prefix=trim($m[1]);$suffix=trim($m[4]);$title=trim($prefix.' '.$suffix);if($title==='')$title=$original;
        return ['title'=>$title,'quantity'=>(float)$m[2],'unit'=>mb_strtolower($m[3],'UTF-8')];
    }
    if(preg_match('/^(\d+(?:\.\d+)?)\s+(.+)$/u',$working,$m))return ['title'=>trim($m[2]),'quantity'=>(float)$m[1],'unit'=>'each'];
    return ['title'=>$original,'quantity'=>null,'unit'=>null];
}
function ops_agent_task_create(PDO $pdo,int $organizationId,int $userId,string $item,string $category,string $transcript,array $assigneeIds=[]):array
{
    $parsed=ops_agent_parse_task_item($item);return operations_create_task($pdo,$organizationId,['title'=>$parsed['title'],'category'=>$category,'quantity'=>$parsed['quantity'],'unit'=>$parsed['unit'],'transcript'=>$transcript,'aiConfidence'=>1.0,'assigneeIds'=>$assigneeIds],$userId);
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
        $taskText=trim(($m[1]??'').' '.($m[2]??''));$assignmentRequested=false;$assigneeIds=ops_agent_extract_assignees($pdo,$organizationId,$taskText,$assignmentRequested);if($assignmentRequested&&!$assigneeIds)app_json_response(['ok'=>false,'message'=>'I could not match that employee name to an active restaurant staff account. Say the employee name as it appears in the staff account.'],422);$items=ops_agent_split_items($taskText);if(!$items)app_json_response(['ok'=>false,'message'=>'Tell me which prep items to add.'],422);$created=[];foreach(array_slice($items,0,30) as $item)$created[]=ops_agent_task_create($pdo,$organizationId,$userId,$item,'prep',$message,$assigneeIds);$names=array_column($created,'title');$assigneeNote=$assigneeIds?' and tagged '.count($assigneeIds).' employee(s)':'';app_audit($pdo,$organizationId,$userId,'agent.prep_tasks_created','restaurant_task','prep',null,['count'=>count($created),'assigneeIds'=>$assigneeIds,'message'=>$message]);app_json_response(['ok'=>true,'skill'=>'tasks.prep_add','answer'=>'Added '.count($created).' item(s) to the prep list'.$assigneeNote.': '.implode(', ',$names).'.','data'=>$created,'sources'=>array_column($created,'public_id')]);
    }
    if($canTasks&&preg_match('/\badd\s+(?:a\s+)?task\s*[:,-]?\s*(.+)$/iu',$normalized,$m)){
        if(!app_has_permission('tasks.manage',$user))app_json_response(['ok'=>false,'message'=>'Task management permission is required to add tasks.'],403);$taskText=trim($m[1]);$assignmentRequested=false;$assigneeIds=ops_agent_extract_assignees($pdo,$organizationId,$taskText,$assignmentRequested);if($assignmentRequested&&!$assigneeIds)app_json_response(['ok'=>false,'message'=>'I could not match that employee name to an active restaurant staff account.'],422);$task=ops_agent_task_create($pdo,$organizationId,$userId,$taskText,'general',$message,$assigneeIds);app_audit($pdo,$organizationId,$userId,'agent.task_created','restaurant_task',(string)$task['public_id'],null,['assigneeIds'=>$assigneeIds,'message'=>$message]);app_json_response(['ok'=>true,'skill'=>'tasks.create','answer'=>'Added the task “'.$task['title'].'”'.($assigneeIds?' and tagged the requested employee(s)':'').'.','data'=>$task,'sources'=>[(string)$task['public_id']]]);
    }
    if($canInventory&&preg_match('/\b(?:build|create|sync|refresh|update)\b.*\binventory\b.*\b(?:menu|recipe|recipes|sources)\b/iu',$normalized)){
        if(!app_has_permission('inventory.manage',$user))app_json_response(['ok'=>false,'message'=>'Inventory management permission is required to synchronize inventory sources.'],403);$result=operations_sync_inventory_sources($pdo,$organizationId,$userId);app_audit($pdo,$organizationId,$userId,'agent.inventory_synced','inventory','all',null,$result);app_json_response(['ok'=>true,'skill'=>'inventory.sync','answer'=>'Inventory synchronized from the menu ingredient catalog and active recipes. '.$result['items'].' ingredient records were touched, covering '.$result['menuReferences'].' menu references and '.$result['recipeReferences'].' recipe references.','data'=>$result,'sources'=>[]]);
    }
    if($canTasks&&(preg_match('/\bwholesale\b.*\b(order|orders|production|fulfillment)\b/iu',$normalized)||preg_match('/\b(order|orders)\b.*\bwholesale\b/iu',$normalized))){$result=operations_agent_wholesale_answer($pdo,$organizationId,$message);app_audit($pdo,$organizationId,$userId,'agent.operations_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,500,'UTF-8')]);app_json_response(['ok'=>true]+$result);}
    if($canInventory&&preg_match('/\b(inventory|stock|par|reorder|shortage|running out|ingredient)\b/iu',$normalized)){$result=operations_agent_inventory_answer($pdo,$organizationId,$message);app_audit($pdo,$organizationId,$userId,'agent.operations_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,500,'UTF-8')]);app_json_response(['ok'=>true]+$result);}
    if($canTasks&&preg_match('/\b(task|tasks|prep|opening|closing|cleaning|assignment|assigned|overdue|to do|todo|wholesale|fulfillment|delivery|order|orders)\b/iu',$normalized)){$result=operations_agent_task_answer($pdo,$organizationId,$message,$userId);app_audit($pdo,$organizationId,$userId,'agent.operations_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,500,'UTF-8')]);app_json_response(['ok'=>true]+$result);}
    $summary=operations_core_summary($pdo,$organizationId);$answer='Operations snapshot: '.$summary['openTasks'].' open task(s), '.$summary['inProgress'].' in progress, '.$summary['overdue'].' overdue, '.$summary['inventoryCount'].' inventory item(s), and '.$summary['lowStock'].' at/below reorder point.';app_json_response(['ok'=>true,'skill'=>'operations.summary','answer'=>$answer,'data'=>$summary,'sources'=>[]]);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
