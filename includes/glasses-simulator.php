<?php
declare(strict_types=1);

require_once __DIR__.'/operational-access.php';
require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/glasses-work.php';
require_once __DIR__.'/glasses-build.php';
require_once __DIR__.'/glasses-validation.php';
require_once __DIR__.'/glasses-handoff.php';

function glasses_simulator_can_write(array $user): bool
{
    return app_has_permission('glasses.manage',$user) && app_has_permission('kds.update',$user);
}

function glasses_simulator_devices(PDO $pdo,array $user): array
{
    if(!app_has_permission('glasses.view',$user))return [];
    $org=(int)$user['organization_id'];
    $out=[];
    foreach(glasses_devices($pdo,$org) as $device){
        if((string)($device['status']??'')!=='active')continue;
        $locationId=(int)($device['locationId']??0);
        if($locationId<1||!operational_location_allowed($pdo,$user,'glasses.view',$locationId))continue;
        $device['simulatorWritable']=glasses_simulator_can_write($user)
            && operational_location_allowed($pdo,$user,'glasses.manage',$locationId)
            && operational_location_allowed($pdo,$user,'kds.update',$locationId);
        $out[]=$device;
    }
    return $out;
}

function glasses_simulator_device(PDO $pdo,array $user,string $publicId,bool $write=false): array
{
    $publicId=trim($publicId);
    if($publicId==='')throw new InvalidArgumentException('Choose a glasses device.');
    $org=(int)$user['organization_id'];
    $row=glasses_device_row($pdo,$org,$publicId,false);
    if((string)$row['status']!=='active'||!empty($row['revoked_at']))
        throw new InvalidArgumentException('Glasses device is not active.');
    $locationId=(int)$row['location_id'];
    if(!app_has_permission('glasses.view',$user)||!operational_location_allowed($pdo,$user,'glasses.view',$locationId))
        throw new DomainException('AR glasses access is not assigned at this restaurant location.');
    if($write){
        if(!glasses_simulator_can_write($user)
            ||!operational_location_allowed($pdo,$user,'glasses.manage',$locationId)
            ||!operational_location_allowed($pdo,$user,'kds.update',$locationId)){
            throw new DomainException('AR glasses management and Kitchen Display update permissions are required for live simulator actions.');
        }
    }
    return $row;
}

function glasses_simulator_build_payload(PDO $pdo,array $device,string $sessionPublicId): array
{
    $org=(int)$device['organization_id'];
    $session=glasses_build_session_row($pdo,$org,$sessionPublicId,false);
    glasses_build_assert_device_session($device,$session);
    return glasses_build_payload($pdo,$org,$sessionPublicId);
}

function glasses_simulator_dispatch(PDO $pdo,array $user,array $in): array
{
    $action=trim((string)($in['action']??''));
    $write=in_array($action,[
        'build.start','build.observe','build.confirm','build.resolve_unexpected',
        'validation.evaluate','handoff.expo'
    ],true);
    $device=glasses_simulator_device($pdo,$user,(string)($in['devicePublicId']??''),$write);

    if($action==='work'){
        return ['work'=>glasses_current_work($pdo,$device)];
    }

    if($action==='build.start'){
        $kds=trim((string)($in['kdsItemPublicId']??''));
        if($kds==='')throw new InvalidArgumentException('Choose a KDS item.');
        $revision=trim((string)($in['sourceRevision']??''));
        if($revision!==''&&!preg_match('/^[a-f0-9]{64}$/i',$revision))
            throw new InvalidArgumentException('Source revision is invalid.');
        return ['buildSession'=>glasses_build_start($pdo,$device,$kds,$revision!==''?$revision:null)];
    }

    $session=trim((string)($in['buildSessionPublicId']??''));
    if($session==='')throw new InvalidArgumentException('Build session is required.');

    if($action==='build.get'){
        return ['buildSession'=>glasses_simulator_build_payload($pdo,$device,$session)];
    }
    if($action==='build.observe'){
        return ['buildSession'=>glasses_build_observe($pdo,$device,$session,$in)];
    }
    if($action==='build.confirm'){
        $key=trim((string)($in['componentKey']??''));
        if($key==='')throw new InvalidArgumentException('Build component is required.');
        return ['buildSession'=>glasses_build_confirm($pdo,$device,$session,$key)];
    }
    if($action==='build.resolve_unexpected'){
        $key=trim((string)($in['componentKey']??''));
        if($key==='')throw new InvalidArgumentException('Unexpected component is required.');
        return ['buildSession'=>glasses_build_resolve_unexpected($pdo,$device,$session,$key)];
    }
    if($action==='validation.get'){
        return ['validation'=>glasses_validation_get($pdo,$device,$session)];
    }
    if($action==='validation.evaluate'){
        return ['validation'=>glasses_validation_evaluate($pdo,$device,$session)];
    }
    if($action==='handoff.expo'){
        return ['handoff'=>glasses_handoff_to_expo($pdo,$device,$session)];
    }

    throw new InvalidArgumentException('Unsupported simulator action.');
}
