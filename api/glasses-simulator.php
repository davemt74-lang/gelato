<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-simulator.php';

$user=app_require_auth();
$pdo=app_pdo();

if(!app_has_permission('glasses.view',$user))
    app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
if(!glasses_ready($pdo))
    app_json_response(['ok'=>false,'message'=>'Glasses plugin migration is not installed. Run upgrade.php.'],503);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        app_json_response([
            'ok'=>true,
            'devices'=>glasses_simulator_devices($pdo,$user),
            'permissions'=>[
                'view'=>true,
                'liveWrite'=>glasses_simulator_can_write($user),
            ],
        ]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }

    $in=app_json_input();
    app_verify_request_csrf($in);
    $result=glasses_simulator_dispatch($pdo,$user,$in);
    app_json_response(['ok'=>true]+$result);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[gelato-web-glasses-simulator] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Web glasses simulator request could not be completed.'],500);
}
