<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/admin-control-core.php';
require_once __DIR__.'/../includes/workspace-workforce-core.php';

$user=app_require_auth();
if(!admin_control_allowed($user))app_json_response(['ok'=>false,'message'=>'Workforce command-center access is not available for this account.'],403);
if($_SERVER['REQUEST_METHOD']!=='GET'){
    header('Allow: GET');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}

try{
    app_json_response(['ok'=>true,'workforce'=>workspace_workforce_snapshot(app_pdo(),$user)]);
}catch(Throwable $e){
    error_log('Workspace workforce snapshot failed: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Workforce command-center data could not be loaded.'],500);
}
