<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-brain-orchestrator.php';
require_once __DIR__.'/../includes/live-shift-brain.php';
require_once __DIR__.'/../includes/customer-crm-brain.php';
require_once __DIR__.'/../includes/marketing-brain.php';
require_once __DIR__.'/../includes/equipment-agent-brain.php';
require_once __DIR__.'/../includes/recipe-agent-brain.php';
require_once __DIR__.'/../includes/online-order-agent-brain.php';
require_once __DIR__.'/../includes/timeclock-agent-brain.php';

$user=app_require_auth();
if(!admin_dashboard_allowed($user))app_json_response(['ok'=>false,'message'=>'Manager Agent Brain access is not available for this account.'],403);

try{
    $pdo=app_pdo();
    $buildSnapshot=static function() use ($pdo,$user): array {
        return timeclock_agent_merge_brain_snapshot(
            $pdo,
            $user,
            online_order_agent_merge_brain_snapshot(
            $pdo,
            $user,
            recipe_agent_merge_brain_snapshot(
                $pdo,
                $user,
                equipment_agent_merge_brain_snapshot(
                    $pdo,
                    $user,
                    marketing_merge_brain_snapshot(
                        $pdo,
                        $user,
                        customer_crm_merge_brain_snapshot(
                            $pdo,
                            $user,
                            live_shift_merge_brain_snapshot($pdo,$user,agent_brain_orchestration_snapshot($pdo,$user,null))
                        )
                    )
                )
            )
        ));
    };
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action!=='snapshot')app_json_response(['ok'=>false,'message'=>'Unsupported Agent Brain read action.'],422);
        $snapshot=$buildSnapshot();
        app_json_response(['ok'=>true,'skill'=>'agent.brain.snapshot','data'=>['generatedAt'=>$snapshot['generatedAt'],'scope'=>$snapshot['scope'],'signals'=>$snapshot['signals'],'nextMoves'=>$snapshot['nextMoves']],'node'=>'brain']);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    $input=app_json_input();app_verify_request_csrf($input);
    if((string)($input['action']??'ask')!=='ask')app_json_response(['ok'=>false,'message'=>'Unsupported Agent Brain orchestration action.'],422);
    $message=trim((string)($input['message']??''));if($message==='')throw new InvalidArgumentException('Enter a restaurant operating question.');
    $snapshot=$buildSnapshot();$result=agent_brain_orchestration_answer($snapshot,$message);
    app_audit($pdo,(int)$user['organization_id'],(int)$user['id'],'agent.brain_orchestration_used','agent_brain','manager',null,['signalCount'=>count($snapshot['signals']),'nextMoveCount'=>count($snapshot['nextMoves']),'liveShiftIntegrated'=>true,'crmIntegrated'=>true,'marketingIntegrated'=>true,'equipmentIntegrated'=>true,'recipesIntegrated'=>true,'onlineOrdersIntegrated'=>true,'timeclockIntegrated'=>true]);
    app_json_response(['ok'=>true,'skill'=>'agent.brain.orchestration','answer'=>$result['answer'],'data'=>$result['data'],'sources'=>$result['sources'],'node'=>'brain']);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('Agent Brain orchestration failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Gelato could not assemble the cross-node restaurant operating picture.'],500);}
