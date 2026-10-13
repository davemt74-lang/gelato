<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-camera-photo-library.php';

$user=app_require_auth();
$pdo=app_pdo();
header('Cache-Control: private, no-store, max-age=0');
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=filter_var($_GET['locationId']??null,FILTER_VALIDATE_INT);
        if(!$locationId)throw new InvalidArgumentException('Choose an authorized restaurant location.');
        app_json_response(['ok'=>true,'csrfToken'=>app_csrf_token(),
            'library'=>glasses_camera_photo_library($pdo,$user,(int)$locationId)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();
    app_verify_request_csrf($in);
    $locationId=$in['locationId']??null;
    if(!is_int($locationId)||$locationId<1)throw new InvalidArgumentException('Choose a location.');
    $result=glasses_camera_photo_command($pdo,$user,$locationId,$in);
    app_json_response(['ok'=>true,'result'=>$result,
        'library'=>glasses_camera_photo_library($pdo,$user,$locationId)]);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('[gelato-camera-photo-library] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Camera photo library is unavailable. Verify the database upgrade and permissions.'],503);}
