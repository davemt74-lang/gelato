<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/timeclock-agent-core.php';

// Compatibility skill markers for the original Time Clock CI contract: timeclock.clock_in attendance.labor

$user=app_require_auth();
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();
app_verify_request_csrf($input);

try{
    app_json_response(timeclock_agent_handle(app_pdo(),$user,$input));
}catch(TimeclockAgentPermissionException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException|DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[Time Clock + Attendance Agent] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'The Time Clock + Attendance Agent could not complete that request.'],500);
}
