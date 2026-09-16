<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-brain-orchestrator.php';

$user=app_require_auth();
if(!admin_dashboard_allowed($user))app_json_response(['ok'=>false,'message'=>'Manager Agent Brain access is not available for this account.'],403);

try{
    $pdo=app_pdo();
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action!=='snapshot')app_json_response(['ok'=>false,'message'=>'Unsupported Agent Brain read action.'],422);
        $snapshot=agent_brain_orchestration_snapshot($pdo,$user,null);
        app_json_response(['ok'=>true,'skill'=>'agent.brain.snapshot','data'=>['generatedAt'=>$snapshot['generatedAt'],'scope'=>$snapshot['scope'],'signals'=>$snapshot['signals'],'nextMoves'=>$snapshot['nextMoves']],'node'=>'brain']);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    $input=app_json_input();app_verify_request_csrf($input);
    if((string)($input['action']??'ask')!=='ask')app_json_response(['ok'=>false,'message'=>'Unsupported Agent Brain orchestration action.'],422);
    $message=trim((string)($input['message']??''));if($message==='')throw new InvalidArgumentException('Enter a restaurant operating question.');
    $snapshot=agent_brain_orchestration_snapshot($pdo,$user,null);$result=agent_brain_orchestration_answer($snapshot,$message);
    app_audit($pdo,(int)$user['organization_id'],(int)$user['id'],'agent.brain_orchestration_used','agent_brain','manager',null,['signalCount'=>count($snapshot['signals']),'nextMoveCount'=>count($snapshot['nextMoves'])]);
    app_json_response(['ok'=>true,'skill'=>'agent.brain.orchestration','answer'=>$result['answer'],'data'=>$result['data'],'sources'=>$result['sources'],'node'=>'brain']);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('Agent Brain orchestration failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Gelato could not assemble the cross-node restaurant operating picture.'],500);}
