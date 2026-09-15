<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/media-core.php';
require_once __DIR__.'/../includes/menu-operations-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$userId=(int)$user['id'];

if(!media_schema_ready($pdo))app_json_response(['ok'=>false,'message'=>'Restaurant media is not installed. Run Upgrade once.'],503);

$target=trim((string)($_REQUEST['target']??''));
$id=max(0,(int)($_REQUEST['id']??0));
try{$spec=media_target_spec($target);}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
if(!app_has_permission((string)$spec['permission'],$user))app_json_response(['ok'=>false,'message'=>'You do not have permission to manage this image.'],403);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        app_json_response(['ok'=>true,'media'=>media_target_status($pdo,$org,$target,$id)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    if(!app_verify_csrf((string)($_POST['csrf_token']??'')))app_json_response(['ok'=>false,'message'=>'The request expired. Refresh and try again.'],419);
    $action=(string)($_POST['action']??'upload');
    if($action==='remove'){
        $media=media_remove_target($pdo,$org,$target,$id);
        app_audit($pdo,$org,$userId,'media.removed',$target,(string)$id,null,['target'=>$target]);
        if(menu_operations_ready($pdo)&&in_array($target,['menu_item','ingredient','location'],true)){menu_operations_event($pdo,$org,'media.removed',ucfirst(str_replace('_',' ',$target)).' image removed',$target==='menu_item'?$id:null,null,$target==='location'?$id:null,$userId,['target'=>$target,'id'=>$id]);menu_operations_sync_brain($pdo,$org,$userId);}
        app_json_response(['ok'=>true,'media'=>$media]);
    }
    if($action!=='upload')app_json_response(['ok'=>false,'message'=>'Unknown media action.'],400);
    if(!isset($_FILES['image']) || !is_array($_FILES['image']))throw new InvalidArgumentException('Choose an image to upload.');
    $stored=media_store_uploaded_image($pdo,$org,$userId,$_FILES['image']);
    try{$media=media_assign_target($pdo,$org,$target,$id,(int)$stored['id']);}
    catch(Throwable $e){media_retire_if_unreferenced($pdo,$org,(int)$stored['id']);throw $e;}
    app_audit($pdo,$org,$userId,'media.uploaded',$target,(string)$id,null,['target'=>$target,'fileId'=>$stored['id'],'mimeType'=>$stored['mimeType'],'fileSize'=>$stored['fileSize']]);
    if(menu_operations_ready($pdo)&&in_array($target,['menu_item','ingredient','location'],true)){menu_operations_event($pdo,$org,'media.uploaded',ucfirst(str_replace('_',' ',$target)).' image updated',$target==='menu_item'?$id:null,null,$target==='location'?$id:null,$userId,['target'=>$target,'id'=>$id]);menu_operations_sync_brain($pdo,$org,$userId);}
    app_json_response(['ok'=>true,'media'=>$media]);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Restaurant media API failed: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'The image could not be saved.'],500);
}
