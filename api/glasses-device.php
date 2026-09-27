<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';

$pdo=app_pdo();
if(!glasses_ready($pdo))app_json_response(['ok'=>false,'message'=>'Glasses plugin migration is not installed.'],503);

function glasses_device_bearer(): string
{
    $header='';
    if(isset($_SERVER['HTTP_AUTHORIZATION']))$header=(string)$_SERVER['HTTP_AUTHORIZATION'];
    elseif(function_exists('getallheaders')){
        foreach((array)getallheaders() as $name=>$value){
            if(strtolower((string)$name)==='authorization'){$header=(string)$value;break;}
        }
    }
    return preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/',$header,$m)?strtolower($m[1]):'';
}

try{
    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    $in=app_json_input();
    $action=(string)($in['action']??'');

    if($action==='pair'){
        $result=glasses_pair_device($pdo,(string)($in['pairingCode']??''),[
            'hardwareIdentifier'=>$in['hardwareIdentifier']??null,
            'displayName'=>$in['displayName']??'INMO AIR3',
            'platform'=>$in['platform']??'inmo_air3',
            'sdkVersion'=>$in['sdkVersion']??null,
            'appVersion'=>$in['appVersion']??null,
            'systemVersion'=>$in['systemVersion']??null,
            'capabilities'=>$in['capabilities']??null,
        ]);
        app_json_response(['ok'=>true]+$result,201);
    }

    $token=glasses_device_bearer();
    if($token==='')app_json_response(['ok'=>false,'message'=>'Device authentication required.'],401);
    $device=glasses_authenticate_token($pdo,$token);

    if($action==='heartbeat'){
        $public=glasses_heartbeat($pdo,$device,$in);
        app_json_response(['ok'=>true,'device'=>$public,'serverTime'=>(new DateTimeImmutable())->format(DATE_ATOM)]);
    }

    if($action==='current_work'){
        $work=glasses_current_work($pdo,$device);
        app_json_response(['ok'=>true,'work'=>$work,'serverTime'=>(new DateTimeImmutable())->format(DATE_ATOM)]);
    }

    if($action==='self'){
        app_json_response(['ok'=>true,'device'=>glasses_device_public($device)]);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported glasses device action.'],422);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[gelato-glasses-device] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Glasses device request could not be completed.'],500);
}
