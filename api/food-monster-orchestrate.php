<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-multi-node.php';
require_once __DIR__.'/../includes/food-monster-b8d2-execution-loop.php';
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
  $domainPermissions=[
    'kitchen'=>['kds.view','pos.use','online_orders.fulfill'],
    'inventory'=>['inventory.view','tasks.view'],
    'scheduling'=>['schedule.view','schedule.manage'],
    'purchasing'=>['purchasing.view'],
    'prep'=>['prep.intelligence.view'],
    'catering'=>['catering.view'],
    'crm'=>['crm.view'],
  ];
  // Revalidate live access even on a session-cache hit. A role change must
  // not leave a previously authorized briefing available for three minutes.
  $cached=$_SESSION['fm_g7_briefing'][$key]??null;
  if(is_array($cached)&&($cached['expires']??0)>time()){
    $fresh=fm8d2_refresh_operator($pdo,$user);
    $cacheAllowed=is_array($fresh)&&admin_dashboard_allowed($fresh);
    foreach($domains as $domain){
      $hasDomain=false;
      foreach($domainPermissions[$domain]??[] as $right)
        if(is_array($fresh)&&app_has_permission($right,$fresh)){$hasDomain=true;break;}
      if(!$hasDomain){$cacheAllowed=false;break;}
    }
    if($cacheAllowed)app_json_response(['ok'=>true]+$cached['result']);
  }
  // One already authorized, permission-filtered Brain read covering multiple modules.
  // Never accepts model-generated endpoints, function arguments or write operations.
  $snapshot=agent_brain_orchestration_snapshot($pdo,$user,null);
  // B8D2: trusted, bounded per-domain skill calls. The client supplies only
  // the natural-language request; this endpoint owns the complete tool plan.

  $plan=array_map(static fn(string $domain):array=>[
      'tool'=>'agent_brain.review_domain','arguments'=>['domain'=>$domain]
  ],$domains);
  $execution=fm8d2_run($plan,$user,[
    'agent_brain.review_domain'=>[
      'impact'=>'read','allowedArguments'=>['domain'],
      'authorize'=>static function(array $actor,array $args)use($domainPermissions):bool{
        if(!admin_dashboard_allowed($actor))return false;
        $rights=$domainPermissions[$args['domain']??'']??[];
        foreach($rights as $right)if(app_has_permission($right,$actor))return true;
        return false;
      },
      'validate'=>static fn(array $args,array $actor):array=>
        in_array((string)($args['domain']??''),$domains,true)?[]:['domain'],
      'execute'=>static function(array $args,array $actor)use($snapshot):array{
        $review=fm7_briefing($snapshot,[(string)$args['domain']]);
        $step=(array)($review['data']['steps'][0]??[]);
        return ['ok'=>true,'data'=>[
          'domain'=>$args['domain'],
          'signalCount'=>(int)($step['signalCount']??0),
          'findingCount'=>count((array)($review['data']['findings']??[])),
          'mode'=>'read_only',
        ]];
      },
    ],
  ],[
    'maxSteps'=>6,'maxMs'=>4000,
    'refreshActor'=>static fn():?array=>fm8d2_refresh_operator($pdo,$user),
    'audit'=>static function(array $event)use($pdo,$org,$uid,$threadId):void{
      app_audit($pdo,$org,$uid,'agent.execution_step','agent_conversation',$threadId,null,$event);
    },
  ]);
  if($execution['status']!=='completed'){
    app_json_response(['ok'=>true,'skill'=>'agent.execution.stopped',
      'answer'=>'I stopped the restaurant review safely: '.$execution['reason'].
        ' No business records were changed. We can continue after the issue is resolved.',
      'sources'=>['Gelato Agent Brain'],
      'data'=>['mode'=>'read_only','execution'=>$execution]]);
  }
  $result=fm7_briefing($snapshot,$domains);
  $result['data']['execution']=$execution;
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
