<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-order-photo-stages.php';

$user=app_require_auth();$pdo=app_pdo();
header('Cache-Control: private, no-store, max-age=0');
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $id=filter_var($_GET['locationId']??null,FILTER_VALIDATE_INT);
        if(!$id)throw new InvalidArgumentException('Select a restaurant location.');
        app_json_response(['ok'=>true,'csrfToken'=>app_csrf_token(),
            'timeline'=>glasses_order_photo_snapshot($pdo,$user,(int)$id)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();app_verify_request_csrf($in);
    $location=$in['locationId']??null;
    if(!is_int($location)||$location<1)throw new InvalidArgumentException('Select an authorized location.');
    $out=glasses_order_photo_action($pdo,$user,$location,$in);
    app_json_response(['ok'=>true,'result'=>$out,'timeline'=>glasses_order_photo_snapshot($pdo,$user,$location)]);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){
    error_log('[glasses-order-photo] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Production photo workflow unavailable. Apply database upgrade and retry.'],503);
}
