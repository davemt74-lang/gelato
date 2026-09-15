<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/admin-dashboard-core.php';

$user=app_require_auth();
if(!admin_dashboard_allowed($user))app_json_response(['ok'=>false,'message'=>'Admin command dashboard access is not available for this account.'],403);
if($_SERVER['REQUEST_METHOD']!=='GET'){
    header('Allow: GET');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}

try{
    $locationId=max(0,(int)($_GET['locationId']??0));
    $snapshot=admin_dashboard_snapshot(app_pdo(),$user,$locationId?:null);
    app_json_response(['ok'=>true,'dashboard'=>$snapshot]);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Admin dashboard failed: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'The restaurant command dashboard could not be loaded.'],500);
}
