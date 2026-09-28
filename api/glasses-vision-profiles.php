<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-profiles.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];

if(!app_has_permission('glasses.view',$user))
    app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        app_json_response(['ok'=>true,'catalog'=>glasses_vision_profile_catalog($pdo,$user)]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }

    if(!app_has_permission('glasses.manage',$user))
        app_json_response(['ok'=>false,'message'=>'AR glasses management permission required.'],403);

    $in=app_json_input();
    app_verify_request_csrf($in);
    $action=(string)($in['action']??'');

    if($action==='profile.save'){
        $profile=glasses_vision_profile_save($pdo,$org,$in,(int)$user['id']);
        app_json_response(['ok'=>true,'profile'=>$profile]);
    }

    if($action==='profile.status'){
        $profile=glasses_vision_profile_set_status(
            $pdo,$org,
            (string)($in['publicId']??''),
            (string)($in['status']??''),
            (int)$user['id']
        );
        app_json_response(['ok'=>true,'profile'=>$profile]);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported vision-profile action.'],422);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[gelato-glasses-vision-profiles] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Vision profile request could not be completed.'],500);
}
