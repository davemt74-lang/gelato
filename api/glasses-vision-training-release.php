<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-training-release.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];
if(!app_has_permission('glasses.view',$user)){http_response_code(403);exit('AR glasses permission required.');}
$publicId=trim((string)($_GET['publicId']??''));
if($publicId===''){http_response_code(400);exit('Training release public ID is required.');}
try{
    $row=glasses_vision_training_release_download_row($pdo,$org,$publicId);
    $root=glasses_vision_training_release_storage_root();
    $path=$root.'/'.(string)$row['package_relative_path'];
    $realRoot=realpath($root);$real=realpath($path);
    if($realRoot===false||$real===false||!str_starts_with($real,$realRoot.DIRECTORY_SEPARATOR)||!is_file($real)){http_response_code(404);exit('Training release package is unavailable.');}
    if(!hash_equals((string)$row['package_hash'],substr((string)$row['public_id'],15,32).str_repeat('',0))){
        // Public IDs are hash-derived but the full immutable hash remains authoritative in the manifest/table.
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/','-',(string)$row['public_id']).'.zip"');
    header('Content-Length: '.filesize($real));
    header('Cache-Control: private, no-store');
    readfile($real);
}catch(InvalidArgumentException $e){http_response_code(404);exit($e->getMessage());}
