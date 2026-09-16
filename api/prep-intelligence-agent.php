<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/prep-agent-core.php';

$user=app_require_auth();
$pdo=app_pdo();
if(!prep_intelligence_ready($pdo))app_json_response(['ok'=>false,'message'=>'Prep + Inventory Intelligence migration is not installed. Run upgrade.php.'],503);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();
app_verify_request_csrf($input);

try{
    app_json_response(prep_agent_handle($pdo,$user,$input));
}catch(PrepAgentPermissionException $error){
    app_json_response(['ok'=>false,'message'=>$error->getMessage()],403);
}catch(Throwable $error){
    app_json_response(['ok'=>false,'message'=>$error->getMessage()],422);
}
