<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/operations-core.php';
require_once __DIR__ . '/../includes/operations-wholesale.php';
require_once __DIR__ . '/../includes/operations-wholesale-agent.php';
require_once __DIR__ . '/../includes/agent-confirmation-core.php';
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
function ops_agent_execute_pending(PDO $pdo,int $organizationId,int $userId,array $user,array $proposal):array
{
    if((int)($proposal['organizationId']??0)!==$organizationId||(int)($proposal['userId']??0)!==$userId)throw new RuntimeException('This Operations Agent proposal does not belong to your session.');
    $type=(string)$proposal['type'];$payload=(array)$proposal['payload'];
    if($type==='category_create'){
        if(!app_has_permission('tasks.manage',$user))throw new RuntimeException('Task management permission is required to add a category.');
        $category=operations_create_category($pdo,$organizationId,(string)$payload['name'],$userId);
        return ['ok'=>true,'skill'=>'operations.action_confirmed','answer'=>'Confirmed. Created the task category “'.$category['name'].'”.','data'=>['action'=>'category_create','category'=>$category],'sources'=>[(string)$category['public_id']]];
    }
    if($type==='prep_tasks'||$type==='task_create'){
        if(!app_has_permission('tasks.manage',$user))throw new RuntimeException('Task management permission is required to create tasks.');
        $created=[];$category=$type==='prep_tasks'?'prep':'general';
        foreach((array)($payload['items']??[]) as $item)$created[]=ops_agent_task_create($pdo,$organizationId,$userId,(string)$item,$category,(string)($payload['transcript']??''),(array)($payload['assigneeIds']??[]));
        if(!$created)throw new InvalidArgumentException('The pending task proposal no longer contains valid items.');
        $names=array_column($created,'title');
        return ['ok'=>true,'skill'=>'operations.action_confirmed','answer'=>'Confirmed. Added '.count($created).' '.($type==='prep_tasks'?'prep item(s)':'task(s)').': '.implode(', ',$names).'.','data'=>['action'=>$type,'tasks'=>$created],'sources'=>array_column($created,'public_id')];
    }
    if($type==='inventory_sync'){
        if(!app_has_permission('inventory.manage',$user))throw new RuntimeException('Inventory management permission is required to synchronize inventory sources.');
        $result=operations_sync_inventory_sources($pdo,$organizationId,$userId);
        return ['ok'=>true,'skill'=>'operations.action_confirmed','answer'=>'Confirmed. Inventory synchronized from the menu ingredient catalog and active recipes. '.$result['items'].' ingredient records were touched, covering '.$result['menuReferences'].' menu references and '.$result['recipeReferences'].' recipe references.','data'=>['action'=>'inventory_sync','result'=>$result],'sources'=>[]];
    }
    if($type==='wholesale_task_sync'){
        if(!app_has_permission('tasks.manage',$user))throw new RuntimeException('Task management permission is required to synchronize wholesale fulfillment tasks.');
        $result=operations_sync_wholesale_tasks($pdo,$organizationId,$userId);
        return ['ok'=>true,'skill'=>'operations.action_confirmed','answer'=>'Confirmed. Synchronized wholesale fulfillment tasks from current wholesale orders.','data'=>['action'=>'wholesale_task_sync','result'=>$result],'sources'=>['Wholesale Orders','Restaurant Tasks']];
    }
    throw new InvalidArgumentException('That pending Operations Agent action is no longer supported.');
}

if($_SERVER['REQUEST_METHOD']==='GET'){
    $summary=operations_core_summary($pdo,$organizationId);$alerts=[];if((int)$summary['overdue']>0)$alerts[]=$summary['overdue'].' task(s) are overdue.';if((int)$summary['lowStock']>0)$alerts[]=$summary['lowStock'].' inventory item(s) are at or below reorder point.';app_json_response(['ok'=>true,'skill'=>'operations.proactive_summary','summary'=>$summary,'alerts'=>$alerts]);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$message=trim((string)($input['message']??''));if($message===''||mb_strlen($message,'UTF-8')>4000)app_json_response(['ok'=>false,'message'=>'Enter an operations request no longer than 4,000 characters.'],422);$normalized=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/i','',$message)??$message,'UTF-8');

try{
    $pending=gac_pending_get('operations',$organizationId,$userId);
    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Operations Agent action to confirm.');
        try{$result=ops_agent_execute_pending($pdo,$organizationId,$userId,$user,$pending);}catch(Throwable $e){gac_pending_clear('operations',$organizationId,$userId);throw $e;}
        gac_pending_clear('operations',$organizationId,$userId);app_audit($pdo,$organizationId,$userId,'operations.agent_action_confirmed','operations_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);app_json_response($result);
    }
    if(gac_is_cancel($message)&&$pending){
        gac_pending_clear('operations',$organizationId,$userId);app_audit($pdo,$organizationId,$userId,'operations.agent_action_discarded','operations_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);app_json_response(['ok'=>true,'skill'=>'operations.action_cancelled','answer'=>'Cancelled. I did not change restaurant tasks or inventory.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Restaurant Operations']]);
    }

    if($canTasks&&preg_match('/\badd\s+(?:a\s+)?task\s+category(?:\s+(?:called|named))?\s+(.+)$/iu',$normalized,$m)){
        if(!app_has_permission('tasks.manage',$user))app_json_response(['ok'=>false,'message'=>'Task management permission is required to add a category.'],403);$name=trim($m[1]," .\t\n\r");
        $proposal=gac_pending_store('operations',$organizationId,$userId,'category_create',['name'=>ucwords($name)],'Proposed action: create the restaurant task category “'.ucwords($name).'”.');app_audit($pdo,$organizationId,$userId,'operations.agent_action_proposed','operations_agent_proposal',(string)$proposal['id'],null,['type'=>'category_create']);app_json_response(gac_proposal_result($proposal,'operations.action_proposal',['Restaurant Tasks → Categories']));
    }
    if($canTasks&&(preg_match('/\badd(?:\s+these|\s+the following)?(?:\s+items?)?\s+to\s+(?:the\s+)?prep\s+list\s*[:,-]?\s*(.+)$/iu',$normalized,$m)||preg_match('/\b(?:add|put)\s+(.+?)\s+(?:to|on)\s+(?:the\s+)?prep\s+list\b(.*)$/iu',$normalized,$m))){
        if(!app_has_permission('tasks.manage',$user))app_json_response(['ok'=>false,'message'=>'Task management permission is required to add prep tasks.'],403);
        $taskText=trim(($m[1]??'').' '.($m[2]??''));$assignmentRequested=false;$assigneeIds=ops_agent_extract_assignees($pdo,$organizationId,$taskText,$assignmentRequested);if($assignmentRequested&&!$assigneeIds)app_json_response(['ok'=>false,'message'=>'I could not match that employee name to an active restaurant staff account. Say the employee name as it appears in the staff account.'],422);$items=ops_agent_split_items($taskText);if(!$items)app_json_response(['ok'=>false,'message'=>'Tell me which prep items to add.'],422);
        $proposal=gac_pending_store('operations',$organizationId,$userId,'prep_tasks',['items'=>array_slice($items,0,30),'assigneeIds'=>$assigneeIds,'transcript'=>$message],'Proposed action: add '.count(array_slice($items,0,30)).' item(s) to the restaurant prep task list'.($assigneeIds?' and tag '.count($assigneeIds).' employee(s)':'').': '.implode(', ',array_slice($items,0,8)).(count($items)>8?'…':'').'.');app_audit($pdo,$organizationId,$userId,'operations.agent_action_proposed','operations_agent_proposal',(string)$proposal['id'],null,['type'=>'prep_tasks','count'=>count($items)]);app_json_response(gac_proposal_result($proposal,'operations.action_proposal',['Restaurant Tasks → Prep']));
    }
    if($canTasks&&preg_match('/\badd\s+(?:a\s+)?task\s*[:,-]?\s*(.+)$/iu',$normalized,$m)){
        if(!app_has_permission('tasks.manage',$user))app_json_response(['ok'=>false,'message'=>'Task management permission is required to add tasks.'],403);$taskText=trim($m[1]);$assignmentRequested=false;$assigneeIds=ops_agent_extract_assignees($pdo,$organizationId,$taskText,$assignmentRequested);if($assignmentRequested&&!$assigneeIds)app_json_response(['ok'=>false,'message'=>'I could not match that employee name to an active restaurant staff account.'],422);
        $proposal=gac_pending_store('operations',$organizationId,$userId,'task_create',['items'=>[$taskText],'assigneeIds'=>$assigneeIds,'transcript'=>$message],'Proposed action: create the restaurant task “'.$taskText.'”'.($assigneeIds?' and tag the requested employee(s)':'').'.');app_audit($pdo,$organizationId,$userId,'operations.agent_action_proposed','operations_agent_proposal',(string)$proposal['id'],null,['type'=>'task_create']);app_json_response(gac_proposal_result($proposal,'operations.action_proposal',['Restaurant Tasks']));
    }
    if($canInventory&&preg_match('/\b(?:build|create|sync|refresh|update)\b.*\binventory\b.*\b(?:menu|recipe|recipes|sources)\b/iu',$normalized)){
        if(!app_has_permission('inventory.manage',$user))app_json_response(['ok'=>false,'message'=>'Inventory management permission is required to synchronize inventory sources.'],403);
        $proposal=gac_pending_store('operations',$organizationId,$userId,'inventory_sync',[],'Proposed action: synchronize canonical inventory items and menu/recipe source references.');app_audit($pdo,$organizationId,$userId,'operations.agent_action_proposed','operations_agent_proposal',(string)$proposal['id'],null,['type'=>'inventory_sync']);app_json_response(gac_proposal_result($proposal,'operations.action_proposal',['Inventory','Menu Ingredient Catalog','Recipes']));
    }
    if($canTasks&&preg_match('/\b(?:sync|refresh|update)\b.*\bwholesale\b.*\b(?:tasks?|fulfillment|orders?)\b/iu',$normalized)){
        if(!app_has_permission('tasks.manage',$user))app_json_response(['ok'=>false,'message'=>'Task management permission is required to synchronize wholesale fulfillment tasks.'],403);
        $proposal=gac_pending_store('operations',$organizationId,$userId,'wholesale_task_sync',[],'Proposed action: synchronize restaurant wholesale fulfillment tasks from the current wholesale order pipeline.');app_audit($pdo,$organizationId,$userId,'operations.agent_action_proposed','operations_agent_proposal',(string)$proposal['id'],null,['type'=>'wholesale_task_sync']);app_json_response(gac_proposal_result($proposal,'operations.action_proposal',['Wholesale Orders','Restaurant Tasks']));
    }
    if($canTasks&&(preg_match('/\bwholesale\b.*\b(order|orders|production|fulfillment)\b/iu',$normalized)||preg_match('/\b(order|orders)\b.*\bwholesale\b/iu',$normalized))){$result=operations_agent_wholesale_answer($pdo,$organizationId,$message);app_audit($pdo,$organizationId,$userId,'agent.operations_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,500,'UTF-8')]);app_json_response(['ok'=>true]+$result);}
    if($canInventory&&preg_match('/\b(inventory|stock|par|reorder|shortage|running out|ingredient)\b/iu',$normalized)){$result=operations_agent_inventory_answer($pdo,$organizationId,$message);app_audit($pdo,$organizationId,$userId,'agent.operations_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,500,'UTF-8')]);app_json_response(['ok'=>true]+$result);}
    if($canTasks&&preg_match('/\b(task|tasks|prep|opening|closing|cleaning|assignment|assigned|overdue|to do|todo|wholesale|fulfillment|delivery|order|orders)\b/iu',$normalized)){$result=operations_agent_task_answer($pdo,$organizationId,$message,$userId);app_audit($pdo,$organizationId,$userId,'agent.operations_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,500,'UTF-8')]);app_json_response(['ok'=>true]+$result);}
    $summary=operations_core_summary($pdo,$organizationId);$answer='Operations snapshot: '.$summary['openTasks'].' open task(s), '.$summary['inProgress'].' in progress, '.$summary['overdue'].' overdue, '.$summary['inventoryCount'].' inventory item(s), and '.$summary['lowStock'].' at/below reorder point.';app_json_response(['ok'=>true,'skill'=>'operations.summary','answer'=>$answer,'data'=>$summary,'sources'=>[]]);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
