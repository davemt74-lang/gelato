<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';

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

    if($action==='build.start'){
        $kdsItemPublicId=trim((string)($in['kdsItemPublicId']??''));
        if($kdsItemPublicId==='')throw new InvalidArgumentException('Kitchen item is required.');
        $sourceRevision=trim((string)($in['sourceRevision']??''))?:null;
        if($sourceRevision!==null&&!preg_match('/^[a-f0-9]{64}$/i',$sourceRevision))throw new InvalidArgumentException('Source revision is invalid.');
        $session=glasses_build_start($pdo,$device,$kdsItemPublicId,$sourceRevision);
        app_json_response(['ok'=>true,'buildSession'=>$session],201);
    }

    if($action==='build.get'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        $session=glasses_build_session_row($pdo,(int)$device['organization_id'],$sessionPublicId,false);
        glasses_build_assert_device_session($device,$session);
        app_json_response(['ok'=>true,'buildSession'=>glasses_build_payload($pdo,(int)$device['organization_id'],$sessionPublicId)]);
    }

    if($action==='build.observe'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        $session=glasses_build_observe($pdo,$device,$sessionPublicId,$in);
        app_json_response(['ok'=>true,'buildSession'=>$session]);
    }

    if($action==='build.confirm'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        $componentKey=trim((string)($in['componentKey']??''));
        if($componentKey==='')throw new InvalidArgumentException('Build component is required.');
        $session=glasses_build_confirm($pdo,$device,$sessionPublicId,$componentKey);
        app_json_response(['ok'=>true,'buildSession'=>$session]);
    }

    if($action==='build.resolve_unexpected'){
        $sessionPublicId=trim((string)($in['buildSessionPublicId']??''));
        $componentKey=trim((string)($in['componentKey']??''));
        if($componentKey==='')throw new InvalidArgumentException('Unexpected component is required.');
        $session=glasses_build_resolve_unexpected($pdo,$device,$sessionPublicId,$componentKey);
        app_json_response(['ok'=>true,'buildSession'=>$session]);
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
