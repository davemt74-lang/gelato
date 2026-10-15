<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-multi-node.php';
require_once __DIR__.'/../includes/agent-brain-orchestrator.php';
$user=app_require_auth();
if(!admin_dashboard_allowed($user))
  app_json_response(['ok'=>false,'message'=>'Manager Agent Brain access is not available for this account.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){
  header('Allow: POST');
  app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();app_verify_request_csrf($input);
if(($input['action']??'')!=='ask')
  app_json_response(['ok'=>false,'message'=>'Unsupported orchestration action.'],422);
try{
  $message=trim((string)($input['message']??''));
  $threadId=trim((string)($input['threadId']??''));
  $domains=fm7_requested_domains($message);
  if(!$domains||$threadId===''||strlen($threadId)>100)
    throw new InvalidArgumentException('Enter a multi-area read-only request from Agent Chat.');
  $pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
  if(!gaw_ready($pdo)||!gaw_thread($pdo,$org,$uid,$threadId))
    app_json_response(['ok'=>false,'message'=>'Conversation not found.'],404);
  $messages=gaw_messages($pdo,$org,$uid,$threadId,1);
  $last=$messages[0]??null;
  if(!$last||($last['role']??'')!=='user'||trim((string)($last['content']??''))!==$message)
    app_json_response(['ok'=>false,'message'=>'Send this request through the authenticated Agent chat.'],409);
  $key=hash('sha256',$org.'|'.$uid.'|'.$threadId.'|'.(string)$last['public_id']);
  $cached=$_SESSION['fm_g7_briefing'][$key]??null;
  if(is_array($cached)&&($cached['expires']??0)>time())
    app_json_response(['ok'=>true]+$cached['result']);
  // One already authorized, permission-filtered Brain read covering multiple modules.
  // Never accepts model-generated endpoints, function arguments or write operations.
  $snapshot=agent_brain_orchestration_snapshot($pdo,$user,null);
  $result=fm7_briefing($snapshot,$domains);
  $_SESSION['fm_g7_briefing']=array_slice((array)($_SESSION['fm_g7_briefing']??[]),-9,null,true);
  $_SESSION['fm_g7_briefing'][$key]=['expires'=>time()+180,'result'=>$result];
  app_audit($pdo,$org,$uid,'agent.multi_node_briefing','agent_conversation',$threadId,null,
    ['domains'=>$domains,'mode'=>'read_only','signalCount'=>count($snapshot['signals']??[])]);
  app_json_response(['ok'=>true]+$result);
}catch(InvalidArgumentException $e){
  app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
  error_log('Food Monster multi-node read error: '.get_class($e).': '.$e->getMessage());
  app_json_response(['ok'=>false,'message'=>'The Agent Brain briefing is temporarily unavailable.'],503);
}
