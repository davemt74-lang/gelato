<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/wholesale-agent-core.php';

$user=app_require_auth();
$pdo=app_pdo();
if(!wholesale_fulfillment_ready($pdo))app_json_response(['ok'=>false,'message'=>'Wholesale fulfillment migration is not installed. Run upgrade.php.'],503);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();
app_verify_request_csrf($input);
try{
    app_json_response(wholesale_agent_handle($pdo,$user,$input));
}catch(WholesaleAgentPermissionException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}
