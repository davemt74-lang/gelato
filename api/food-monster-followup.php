<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-operational-followup.php';
$user=app_require_auth();
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();app_verify_request_csrf($input);
if(($input['action']??'')!=='create_report_task')
    app_json_response(['ok'=>false,'message'=>'Unsupported Food Monster action.'],422);
try{
    $personId=(int)($input['personId']??0);
    $reportId=trim((string)($input['reportMessageId']??''));
    $result=fm9f_create_report_task(app_pdo(),$user,$personId,$reportId);
    app_audit(app_pdo(),(int)$user['organization_id'],(int)$user['id'],
        'food_monster.report_task_handoff','agent_message',$reportId,null,
        ['taskId'=>$result['taskId'],'alreadyCreated'=>$result['alreadyCreated']]);
    app_json_response(['ok'=>true,'action'=>'routine_report_task','result'=>$result]);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(Throwable $e){
    error_log('FM-G9F report handoff: '.get_class($e).': '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Operational follow-up is unavailable; no success was reported.'],503);
}
