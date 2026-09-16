<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/catering-brain.php';
require __DIR__ . '/../includes/catering-operations.php';
require_once __DIR__ . '/../includes/catering-agent-actions.php';

$user=app_require_auth();
if(!app_has_permission('catering.view',$user)||!app_has_permission('catering.agent',$user))app_json_response(['ok'=>false,'message'=>'Catering Agent permission required.'],403);
$pdo=app_pdo();$organizationId=(int)$user['organization_id'];
if(!catering_brain_ready($pdo))app_json_response(['ok'=>false,'message'=>'Catering pipeline migration is not installed. Run upgrade.php.'],503);

function catering_agent_operations_intent(string $message):bool
{
    return preg_match('/\b(operations?|operational|readiness|ready|prep|production|ingredient|requirements?|staff|staffing|shift|task|pack|load|setup|shortage|execution|execute|event plan)\b/i',$message)===1;
}

if($_SERVER['REQUEST_METHOD']==='GET'){
 $action=(string)($_GET['action']??'summary');
 if($action==='summary'){
   $payload=['ok'=>true,'skill'=>'catering.summary','summary'=>catering_brain_summary($pdo,$organizationId),'upcoming'=>catering_brain_upcoming($pdo,$organizationId,30),'operations'=>[]];
   if(catering_operations_ready($pdo)){
     $rows=catering_operation_list($pdo,$organizationId,90);foreach($rows as &$row)$row['readiness']=catering_operations_readiness($pdo,$organizationId,(int)$row['id'],true);unset($row);$payload['operations']=$rows;
   }
   app_json_response($payload);
 }
 if($action==='search')app_json_response(['ok'=>true,'skill'=>'catering.search','results'=>catering_brain_search($pdo,$organizationId,(string)($_GET['q']??''),30)]);
 if($action==='operations'&&catering_operations_ready($pdo))app_json_response(['ok'=>true,'skill'=>'catering.operations.summary','operations'=>catering_operation_list($pdo,$organizationId,(int)($_GET['days']??90))]);
 app_json_response(['ok'=>false,'message'=>'Unsupported catering Agent action.'],422);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'ask');
if($action==='ask'){
  try{
    $result=catering_agent_actions_handle($pdo,$user,$input);
    app_audit($pdo,$organizationId,(int)$user['id'],'agent.catering_skill_used','agent_skill',(string)($result['skill']??'catering'),null,['message'=>mb_substr((string)($input['message']??''),0,300,'UTF-8'),'sources'=>$result['sources']??[]]);
    app_json_response($result);
  }catch(CateringAgentActionPermissionException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
  catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
}
if($action==='run_skill'){
  $skill=(string)($input['skill']??'');$args=(array)($input['arguments']??[]);
  if($skill==='catering.search')$result=['skill'=>$skill,'data'=>catering_brain_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??20))];
  elseif($skill==='catering.summary')$result=['skill'=>$skill,'data'=>catering_brain_summary($pdo,$organizationId)];
  elseif($skill==='catering.upcoming')$result=['skill'=>$skill,'data'=>catering_brain_upcoming($pdo,$organizationId,(int)($args['days']??30))];
  elseif($skill==='catering.operations'&&catering_operations_ready($pdo))$result=['skill'=>$skill,'data'=>catering_operation_list($pdo,$organizationId,(int)($args['days']??90))];
  elseif($skill==='catering.operations_context'&&catering_operations_ready($pdo)){$op=catering_operation_row($pdo,$organizationId,(string)($args['operationId']??''));$result=['skill'=>$skill,'data'=>$op,'answer'=>$op?catering_operations_context($pdo,$organizationId,$op):'Catering operation not found.'];}
  else app_json_response(['ok'=>false,'message'=>'Unknown catering Agent skill.'],422);
  app_audit($pdo,$organizationId,(int)$user['id'],'agent.catering_skill_used','agent_skill',$skill,null,['arguments'=>$args]);app_json_response(['ok'=>true]+$result);
}
app_json_response(['ok'=>false,'message'=>'Unsupported catering Agent action.'],422);
