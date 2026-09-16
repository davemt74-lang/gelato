<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/customer-crm-agent-extensions.php';

$user=app_require_auth();
$pdo=app_pdo();
if(!crm_ready($pdo))app_json_response(['ok'=>false,'message'=>'Customer CRM migration is not installed. Run upgrade.php.'],503);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();
app_verify_request_csrf($input);
try{
    app_json_response(customer_crm_agent_enhanced_handle($pdo,$user,$input));
}catch(CrmAgentPermissionException|DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}