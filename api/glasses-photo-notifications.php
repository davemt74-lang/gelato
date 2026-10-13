<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-customer-photo-notifications.php';
$user=app_require_auth();$pdo=app_pdo();
header('Cache-Control: private, no-store, max-age=0');
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $location=filter_var($_GET['locationId']??null,FILTER_VALIDATE_INT);
        if(!$location)throw new InvalidArgumentException('Choose a restaurant location.');
        app_json_response(['ok'=>true,'csrfToken'=>app_csrf_token(),
            'delivery'=>glasses_customer_photo_staff_snapshot($pdo,$user,(int)$location)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $data=app_json_input();
    app_verify_request_csrf($data);
    $location=$data['locationId']??null;
    if(!is_int($location)||$location<1)throw new InvalidArgumentException('Choose a valid restaurant location.');
    $result=glasses_customer_photo_staff_action($pdo,$user,$location,$data);
    app_json_response(['ok'=>true,'result'=>$result,
        'delivery'=>glasses_customer_photo_staff_snapshot($pdo,$user,$location)]);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('[glasses-photo-delivery] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Photo delivery is unavailable. Check the database upgrade or contact an administrator.'],503);}
