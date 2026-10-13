<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-camera-locations.php';

$user=app_require_auth();
$pdo=app_pdo();
header('Cache-Control: private, no-store, max-age=0');
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=filter_var($_GET['locationId']??null,FILTER_VALIDATE_INT);
        if(!$locationId)throw new InvalidArgumentException('Choose an authorized restaurant location.');
        app_json_response(['ok'=>true,'csrfToken'=>app_csrf_token(),
            'configuration'=>glasses_camera_location_list($pdo,$user,(int)$locationId)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();
    app_verify_request_csrf($in);
    if((string)($in['action']??'')!=='save')
        throw new InvalidArgumentException('Unsupported camera configuration action.');
    $locationId=$in['locationId']??null;
    $slot=$in['slot']??null;
    if(!is_int($locationId)||!is_int($slot))
        throw new InvalidArgumentException('Location and camera slot must be integer identifiers.');
    $updated=glasses_camera_location_save($pdo,$user,$locationId,$slot,$in);
    app_json_response(['ok'=>true,'result'=>$updated,
        'configuration'=>glasses_camera_location_list($pdo,$user,$locationId)]);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('[camera-setup] '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Camera configuration unavailable. Verify the database upgrade.'],503);}
