<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/agent-workspace-core.php';
require_once __DIR__.'/../includes/food-monster-b8d10-approvals.php';

$user=app_require_auth();$pdo=app_pdo();
$org=(int)$user['organization_id'];$uid=(int)$user['id'];
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(($_GET['action']??'')!=='pending')
            throw new InvalidArgumentException('Unknown approval request.');
        $thread=trim((string)($_GET['threadId']??''));
        if(!gaw_thread($pdo,$org,$uid,$thread))
            throw new InvalidArgumentException('Conversation not found.');
        $actor=fm8d2_refresh_operator($pdo,$user);
        $pending=is_array($actor)?fm8d10_pending($pdo,$actor,$thread):null;
        app_json_response(['ok'=>true,'approval'=>$pending]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();app_verify_request_csrf($in);
    if(($in['action']??'')!=='decide')
        throw new InvalidArgumentException('Unknown approval action.');
    $thread=trim((string)($in['threadId']??''));
    if(!gaw_thread($pdo,$org,$uid,$thread))
        throw new InvalidArgumentException('Conversation not found.');
    $result=fm8d10_decide($pdo,$user,trim((string)($in['proposalId']??'')),
        (string)($in['decision']??''),$thread);
    app_json_response($result);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(Throwable $e){
    error_log('FM-B8D10 approval failure: '.get_class($e).': '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Approval unavailable. No action was confirmed.'],503);
}
