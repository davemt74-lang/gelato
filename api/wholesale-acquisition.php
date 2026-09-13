<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/wholesale-acquisition.php';

$user=app_require_permission('wholesale.manage');
$pdo=app_pdo();$org=(int)$user['organization_id'];
if(!wholesale_acquisition_ready($pdo))app_json_response(['ok'=>false,'message'=>'Wholesale acquisition migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $id=trim((string)($_GET['id']??''));
    $users=$pdo->prepare("SELECT DISTINCT u.id,u.display_name FROM users u JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? WHERE u.status='active' AND u.archived_at IS NULL ORDER BY u.display_name");$users->execute([$org]);
    $lead=$id!==''?wholesale_acquisition_get($pdo,$org,$id):null;
    if($id!==''&&!$lead)app_json_response(['ok'=>false,'message'=>'Wholesale lead not found.'],404);
    app_json_response(['ok'=>true,'lead'=>$lead,'users'=>$users->fetchAll()]);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'save');
if(!in_array($action,['save','complete'],true))app_json_response(['ok'=>false,'message'=>'Unsupported acquisition action.'],422);
try{
    $lead=wholesale_acquisition_save($pdo,$org,$input,(int)$user['id'],$action==='complete');
    app_json_response(['ok'=>true,'message'=>$action==='complete'?'Acquisition worksheet completed and pipeline updated.':'Acquisition worksheet saved to the wholesale pipeline.','lead'=>$lead]);
}catch(InvalidArgumentException|RuntimeException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
