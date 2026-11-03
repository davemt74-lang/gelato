<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-training-media.php';

$user=app_require_auth();
$pdo=app_pdo();$org=(int)$user['organization_id'];
if(!app_has_permission('glasses.view',$user))app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
$pooledView=glasses_vision_lab_org_access($pdo,$user,'glasses.view');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if($_SERVER['REQUEST_METHOD']==='GET' && !empty($_GET['publicId'])){
    if(!$pooledView||!glasses_vision_lab_org_access($pdo,$user,'glasses.manage')){http_response_code(403);exit('Organization-wide AR glasses view and management permission is required for Vision Lab media.');}
    try{
        $row=glasses_vision_training_media_row($pdo,$org,(string)$_GET['publicId'],false);
        glasses_vision_media_require_retained($row);
        $path=glasses_vision_training_media_storage_path($org,(string)$row['storage_relative_path']);
        if(!is_file($path)){http_response_code(404);exit('Training media bytes are missing.');}
        header('Content-Type: '.(string)$row['mime_type']);
        header('Content-Length: '.(string)filesize($path));
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Disposition: inline; filename="'.preg_replace('/[^a-zA-Z0-9._-]+/','-',(string)$row['public_id']).'"');
        readfile($path);exit;
    }catch(GlassesVisionMediaUnavailable $e){http_response_code(410);exit('Training media is no longer available.');}
    catch(Throwable $e){http_response_code(404);exit('Training media was not found.');}
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(!$pooledView)throw new DomainException('Organization-wide AR glasses view permission is required for Vision Lab media.');
        app_json_response(['ok'=>true,'catalog'=>glasses_vision_training_media_catalog($pdo,$org)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    if(!app_has_permission('glasses.manage',$user))app_json_response(['ok'=>false,'message'=>'AR glasses management permission required.'],403);
    $in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');
    $pooledManage=$pooledView&&glasses_vision_lab_org_access($pdo,$user,'glasses.manage');
    if($action==='upload')app_json_response(['ok'=>true,'media'=>glasses_vision_training_media_store($pdo,$org,$in,(int)$user['id'],$pooledManage?null:$user)],201);
    if(!$pooledManage)throw new DomainException('Organization-wide AR glasses view and management permission is required for Vision Lab media.');
    if($action==='delete')app_json_response(['ok'=>true,'media'=>glasses_vision_training_media_delete($pdo,$org,(string)($in['publicId']??''),(string)($in['reason']??''),(int)$user['id'])]);
    if($action==='expire')app_json_response(['ok'=>true,'result'=>glasses_vision_training_media_expire($pdo,$org,(int)$user['id'],(int)($in['limit']??500))]);
    if($action==='dataset_quality')app_json_response(['ok'=>true,'quality'=>glasses_vision_training_media_dataset_quality($pdo,$org,!empty($in['datasetPublicId'])?(string)$in['datasetPublicId']:null)]);
    if($action==='export_manifest')app_json_response(['ok'=>true,'manifest'=>glasses_vision_training_media_export_manifest($pdo,$org,!empty($in['datasetPublicId'])?(string)$in['datasetPublicId']:null)]);
    app_json_response(['ok'=>false,'message'=>'Unsupported training-media action.'],422);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('[gelato-vision-training-media] '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Training-media request could not be completed.'],500);}
