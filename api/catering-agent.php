<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/catering-brain.php';

$user=app_require_auth();
if(!app_has_permission('catering.view',$user)||!app_has_permission('catering.agent',$user))app_json_response(['ok'=>false,'message'=>'Catering Agent permission required.'],403);
$pdo=app_pdo();$organizationId=(int)$user['organization_id'];
if(!catering_brain_ready($pdo))app_json_response(['ok'=>false,'message'=>'Catering pipeline migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
 $action=(string)($_GET['action']??'summary');
 if($action==='summary')app_json_response(['ok'=>true,'skill'=>'catering.summary','summary'=>catering_brain_summary($pdo,$organizationId),'upcoming'=>catering_brain_upcoming($pdo,$organizationId,30)]);
 if($action==='search')app_json_response(['ok'=>true,'skill'=>'catering.search','results'=>catering_brain_search($pdo,$organizationId,(string)($_GET['q']??''),30)]);
 app_json_response(['ok'=>false,'message'=>'Unsupported catering Agent action.'],422);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'ask');
if($action==='ask'){$message=trim((string)($input['message']??''));if($message===''||mb_strlen($message,'UTF-8')>1600)app_json_response(['ok'=>false,'message'=>'Enter a catering question no longer than 1,600 characters.'],422);$result=catering_brain_answer($pdo,$organizationId,$message);app_audit($pdo,$organizationId,(int)$user['id'],'agent.catering_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,300,'UTF-8'),'sources'=>$result['sources']]);app_json_response(['ok'=>true]+$result);}
if($action==='run_skill'){$skill=(string)($input['skill']??'');$args=(array)($input['arguments']??[]);if($skill==='catering.search')$result=['skill'=>$skill,'data'=>catering_brain_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??20))];elseif($skill==='catering.summary')$result=['skill'=>$skill,'data'=>catering_brain_summary($pdo,$organizationId)];elseif($skill==='catering.upcoming')$result=['skill'=>$skill,'data'=>catering_brain_upcoming($pdo,$organizationId,(int)($args['days']??30))];else app_json_response(['ok'=>false,'message'=>'Unknown catering Agent skill.'],422);app_audit($pdo,$organizationId,(int)$user['id'],'agent.catering_skill_used','agent_skill',$skill,null,['arguments'=>$args]);app_json_response(['ok'=>true]+$result);}
app_json_response(['ok'=>false,'message'=>'Unsupported catering Agent action.'],422);
