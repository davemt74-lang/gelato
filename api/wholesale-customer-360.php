<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/wholesale-portal-operations.php';

$user=app_require_permission('wholesale.view');
$pdo=app_pdo();$org=(int)$user['organization_id'];
if($_SERVER['REQUEST_METHOD']!=='GET'){header('Allow: GET');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$action=(string)($_GET['action']??'list');
if($action==='list')app_json_response(['ok'=>true,'accounts'=>wholesale_operations_accounts($pdo,$org),'w5Ready'=>wholesale_portal_operations_ready($pdo)]);
if($action==='detail'){
    $id=trim((string)($_GET['id']??''));if($id==='')app_json_response(['ok'=>false,'message'=>'Wholesale account ID is required.'],422);
    app_json_response(['ok'=>true,'detail'=>wholesale_operations_account_360($pdo,$org,$id)]);
}
app_json_response(['ok'=>false,'message'=>'Unsupported Wholesale 360 action.'],422);
