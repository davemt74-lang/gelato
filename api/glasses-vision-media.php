<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-training-media.php';

$user=app_require_auth();
if(!app_has_permission('glasses.view',$user))app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
$pdo=app_pdo();$org=(int)$user['organization_id'];

if($_SERVER['REQUEST_METHOD']==='GET' && !empty($_GET['publicId'])){
    try{
        $row=glasses_vision_training_media_row($pdo,$org,(string)$_GET['publicId'],false);
        if((string)$row['status']!=='active'){http_response_code(410);exit('Training media is no longer available.');}
        $path=glasses_vision_training_media_storage_root().'/'.(string)$row['storage_relative_path'];
        if(!is_file($path)){http_response_code(404);exit('Training media bytes are missing.');}
        header('Content-Type: '.(string)$row['mime_type']);
        header('Content-Length: '.(string)filesize($path));
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Disposition: inline; filename="'.preg_replace('/[^a-zA-Z0-9._-]+/','-',(string)$row['public_id']).'"');
        readfile($path);exit;
    }catch(Throwable $e){http_response_code(404);exit('Training media was not found.');}
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET')app_json_response(['ok'=>true,'catalog'=>glasses_vision_training_media_catalog($pdo,$org)]);
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    if(!app_has_permission('glasses.manage',$user))app_json_response(['ok'=>false,'message'=>'AR glasses management permission required.'],403);
    $in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');
    if($action==='upload')app_json_response(['ok'=>true,'media'=>glasses_vision_training_media_store($pdo,$org,$in,(int)$user['id'])],201);
    if($action==='delete')app_json_response(['ok'=>true,'media'=>glasses_vision_training_media_delete($pdo,$org,(string)($in['publicId']??''),(string)($in['reason']??''),(int)$user['id'])]);
    if($action==='expire')app_json_response(['ok'=>true,'result'=>glasses_vision_training_media_expire($pdo,$org,(int)$user['id'],(int)($in['limit']??500))]);
    if($action==='dataset_quality')app_json_response(['ok'=>true,'quality'=>glasses_vision_training_media_dataset_quality($pdo,$org,!empty($in['datasetPublicId'])?(string)$in['datasetPublicId']:null)]);
    if($action==='export_manifest')app_json_response(['ok'=>true,'manifest'=>glasses_vision_training_media_export_manifest($pdo,$org,!empty($in['datasetPublicId'])?(string)$in['datasetPublicId']:null)]);
    app_json_response(['ok'=>false,'message'=>'Unsupported training-media action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('[gelato-vision-training-media] '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Training-media request could not be completed.'],500);}
