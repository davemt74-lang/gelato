<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/scheduling-core.php';
require_once __DIR__.'/../includes/employee-shift-communications.php';
require_once __DIR__.'/../includes/scheduling-agent-core.php';

// CI architecture markers: schedule.action_proposal schedule.action_confirmed schedule.coverage schedule.workload

$user=app_require_auth();
$pdo=app_pdo();
$organizationId=(int)$user['organization_id'];
$userId=(int)$user['id'];

if(!scheduling_core_ready($pdo))app_json_response(['ok'=>false,'message'=>'Staff Scheduling migration is not installed. Run upgrade.php.'],503);
if(!app_has_permission('schedule.agent',$user))app_json_response(['ok'=>false,'message'=>'Scheduling Agent permission required.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}

$input=app_json_input();
app_verify_request_csrf($input);

try{
    $result=scheduling_agent_handle($pdo,$user,$input);
    $pageContext=is_array($input['pageContext']??null)?$input['pageContext']:[];
    app_audit($pdo,$organizationId,$userId,'schedule.agent_skill_used','schedule',(string)($result['skill']??'schedule.unknown'),null,[
        'message'=>mb_substr(trim((string)($input['message']??'')),0,1800,'UTF-8'),
        'contextModule'=>(string)($pageContext['module']??''),
    ]);
    app_json_response($result);
}catch(SchedulingAgentPermissionException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
    $message=$e->getMessage();
    $status=str_contains(mb_strtolower($message,'UTF-8'),'not installed')?503:409;
    app_json_response(['ok'=>false,'message'=>$message],$status);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>'Gelato could not complete that scheduling request.'],500);
}