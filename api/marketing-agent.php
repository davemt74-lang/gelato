<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/marketing-agent-core.php';

$user=app_require_auth();
$pdo=app_pdo();
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();
app_verify_request_csrf($input);
try{
    app_json_response(marketing_agent_handle($pdo,$user,$input));
}catch(MarketingAgentPermissionException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException|DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Marketing Agent failed: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'The Marketing Agent could not complete that request.'],500);
}
