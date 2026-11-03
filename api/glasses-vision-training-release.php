<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-training-release.php';

$user=app_require_auth();$pdo=app_pdo();
if(!glasses_vision_lab_org_access($pdo,$user,'glasses.view')){http_response_code(403);exit('Organization-wide AR glasses view permission is required for Vision Lab.');}
// This ZIP contains the same private image bytes as the management-only media endpoint.
if(!glasses_vision_lab_org_access($pdo,$user,'glasses.manage')){http_response_code(403);exit('Organization-wide AR glasses management permission is required for Vision Lab.');}
$public=trim((string)($_GET['publicId']??''));
if($public===''){http_response_code(400);exit('Training release is required.');}
header('Cache-Control: private, no-store, max-age=0');
try{
    $artifact=glasses_vision_training_release_artifact($pdo,(int)$user['organization_id'],$public);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.str_replace('"','',(string)$artifact['filename']).'"');
    header('Content-Length: '.(string)$artifact['bytes']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Gelato-SHA256: '.(string)$artifact['sha256']);
    readfile((string)$artifact['path']);
}catch(GlassesVisionMediaUnavailable $e){http_response_code(410);exit('Training release media is no longer available.');}
catch(InvalidArgumentException $e){http_response_code(404);exit('Training release was not found.');}
catch(Throwable $e){error_log('[gelato-vision-training-release-download] '.$e->getMessage());http_response_code(500);exit('Training release could not be downloaded.');}
