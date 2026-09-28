<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/glasses-calibration.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];

$canView=app_has_permission('glasses.view',$user);
$canManage=app_has_permission('glasses.manage',$user);
if(!$canView)app_json_response(['ok'=>false,'message'=>'AR glasses permission required.'],403);
if(!glasses_station_calibration_ready($pdo))
    app_json_response(['ok'=>false,'message'=>'Station-calibration migration is not installed. Run upgrade.php.'],503);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=(int)($_GET['locationId']??0);
        $stationPublicId=trim((string)($_GET['stationPublicId']??''));
        if($locationId<1||$stationPublicId==='')throw new InvalidArgumentException('Choose a restaurant location and kitchen station.');
        if(!operational_location_allowed($pdo,$user,'glasses.view',$locationId))
            throw new DomainException('AR glasses access is not assigned at this restaurant location.');

        app_json_response([
            'ok'=>true,
            'calibrations'=>glasses_station_calibration_versions($pdo,$org,$locationId,$stationPublicId),
            'permissions'=>['view'=>true,'manage'=>$canManage],
        ]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    if(!$canManage)app_json_response(['ok'=>false,'message'=>'AR glasses management permission required.'],403);

    $in=app_json_input();
    app_verify_request_csrf($in);
    $action=(string)($in['action']??'');

    if($action==='calibration.save'){
        $locationId=(int)($in['locationId']??0);
        $stationPublicId=trim((string)($in['stationPublicId']??''));
        if($locationId<1||$stationPublicId==='')throw new InvalidArgumentException('Choose a restaurant location and kitchen station.');
        if(!operational_location_allowed($pdo,$user,'glasses.manage',$locationId))
            throw new DomainException('AR glasses management is not assigned at this restaurant location.');

        $calibration=glasses_station_calibration_save($pdo,$org,$locationId,$stationPublicId,$in,$uid);
        app_audit($pdo,$org,$uid,'glasses.station_calibration_saved','kds_station',$stationPublicId,null,[
            'calibrationPublicId'=>$calibration['publicId'],
            'version'=>$calibration['version'],
            'sourceHash'=>$calibration['sourceHash'],
            'zoneCount'=>count($calibration['zones']),
        ]);
        app_json_response(['ok'=>true,'calibration'=>$calibration],201);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported station-calibration action.'],422);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[gelato-glasses-calibration] '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'Station calibration could not complete the request.')],500);
}
