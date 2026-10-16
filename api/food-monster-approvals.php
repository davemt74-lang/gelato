<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-high-impact-approval.php';
$user=app_require_auth();
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$in=app_json_input();app_verify_request_csrf($in);
try {
    $action=(string)($in['action']??'');
    $pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
    if($action==='propose'){
        $thread=trim((string)($in['threadId']??''));
        if(!gaw_thread($pdo,$org,$uid,$thread))throw new InvalidArgumentException('Conversation not found.');
        $result=fm9f_approval_propose($user,(string)($in['kind']??''),(string)($in['summary']??''),$thread);
        app_audit($pdo,$org,$uid,'food_monster.high_impact_proposed','agent_conversation',$thread,null,
            ['proposalId'=>$result['proposalId'],'kind'=>$result['kind'],'executed'=>false]);
        app_json_response(['ok'=>true,'approval'=>$result]);
    }
    if($action==='decide'){
        $result=fm9f_approval_decide($user,trim((string)($in['proposalId']??'')),(string)($in['decision']??''));
        app_audit($pdo,$org,$uid,'food_monster.high_impact_decided','agent_conversation',$result['threadId'],null,
            ['proposalId'=>$result['proposalId'],'kind'=>$result['kind'],'decision'=>$result['decision'],'executed'=>false]);
        app_json_response(['ok'=>true,'approval'=>$result]);
    }
    throw new InvalidArgumentException('Unsupported approval action.');
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(Throwable $e){error_log('FM-G9F approval error: '.get_class($e));app_json_response(['ok'=>false,'message'=>'Approval workflow unavailable. No action was executed.'],503);}
