<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/online-order-agent-core.php';

$user=app_require_auth();
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();
app_verify_request_csrf($input);

try{
    app_json_response(online_order_agent_handle(app_pdo(),$user,$input));
}catch(OnlineOrderAgentPermissionException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException|DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[Online Order Agent] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'The Online Ordering + Pickup Fulfillment Agent could not complete that request.'],500);
}
