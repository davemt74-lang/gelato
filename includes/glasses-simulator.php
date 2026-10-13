<?php
declare(strict_types=1);

require_once __DIR__.'/operational-access.php';
require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/glasses-work.php';
require_once __DIR__.'/glasses-build.php';
require_once __DIR__.'/glasses-validation.php';
require_once __DIR__.'/glasses-handoff.php';
require_once __DIR__.'/glasses-vision-models.php';
require_once __DIR__.'/glasses-vision-profiles.php';
require_once __DIR__.'/glasses-hardware-runtime.php';
require_once __DIR__.'/glasses-frame-pipeline.php';
require_once __DIR__.'/glasses-device-health.php';
require_once __DIR__.'/glasses-runtime-recovery.php';
require_once __DIR__.'/glasses-runtime-soak.php';
require_once __DIR__.'/glasses-cook-ux.php';

/** A read-only virtual display for a location or KDS station; no hardware pairing. */
function glasses_simulator_virtual_devices(PDO $pdo,array $user): array
{
    if(!app_has_permission('glasses.view',$user))return [];
    $org=(int)$user['organization_id'];
    $q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY id");
    $q->execute([$org]);
    $out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $location){
        $id=(int)$location['id'];
        if(!operational_location_allowed($pdo,$user,'glasses.view',$id))continue;
        $base=[
            'displayName'=>'Browser preview · All stations',
            'platform'=>'browser_preview','status'=>'active','locationId'=>$id,
            'locationName'=>(string)$location['name'],
            'stationPublicId'=>null,'stationName'=>'All kitchen stations',
            'simulatorWritable'=>false,'virtualPreview'=>true
        ];
        $out[]=['publicId'=>'preview:'.$id.':all']+$base;
        $stations=$pdo->prepare("SELECT public_id,name FROM kds_stations WHERE organization_id=? AND location_id=? AND status='active' ORDER BY name,id");
        $stations->execute([$org,$id]);
        foreach($stations->fetchAll(PDO::FETCH_ASSOC) as $station){
            $out[]=[
                'publicId'=>'preview:'.$id.':'.(string)$station['public_id'],
                'displayName'=>'Browser preview · '.(string)$station['name'],
                'stationPublicId'=>(string)$station['public_id'],
                'stationName'=>(string)$station['name']
            ]+$base;
        }
    }
    return $out;
}
function glasses_simulator_preview_device(PDO $pdo,array $user,string $publicId,bool $write): array
{
    if($write)throw new DomainException('Browser preview is read-only. Select a paired device with glasses.manage and kds.update for build actions.');
    // Resolve exclusively against currently authorized active stations. Never
    // trust location/station names encoded in a browser supplied device ID.
    foreach(glasses_simulator_virtual_devices($pdo,$user) as $candidate){
        if(hash_equals((string)$candidate['publicId'],$publicId)){
            $stationId=null;
            if(!empty($candidate['stationPublicId'])){
                $station=glasses_station($pdo,(int)$user['organization_id'],(int)$candidate['locationId'],(string)$candidate['stationPublicId']);
                $stationId=(int)$station['id'];
            }
            return [
                'id'=>0,'organization_id'=>(int)$user['organization_id'],
                'location_id'=>(int)$candidate['locationId'],'station_id'=>$stationId,
                'station_public_id'=>$candidate['stationPublicId'],
                'station_name'=>$candidate['stationName'],'location_name'=>$candidate['locationName'],
                'public_id'=>$publicId,'display_name'=>$candidate['displayName'],
                'status'=>'active','revoked_at'=>null,'virtual_preview'=>true,
            ];
        }
    }
    throw new DomainException('Browser preview is no longer available at this location or station.');
}
/** Live KDS projection; no hardware token, mutation, or build-session assignment. */
function glasses_simulator_preview_work(PDO $pdo,array $device): array
{
    $org=(int)$device['organization_id'];$locationId=(int)$device['location_id'];
    $stationId=$device['station_public_id']?:null;
    $board=kds_production_board($pdo,$org,$locationId,$stationId,false);
    $items=[];
    foreach((array)$board['items'] as $row)$items[]=glasses_work_item($pdo,$org,$row);
    $focus=glasses_work_focus_index($items);
    $fingerprint=array_map(static fn(array $i)=>[
        $i['kdsItemPublicId'],$i['status'],$i['ticket'],$i['posLine']
    ],$items);
    return [
        'device'=>['publicId'=>$device['public_id'],'virtualPreview'=>true],
        'virtualPreview'=>true,'readOnly'=>true,'assignmentRequired'=>false,
        'location'=>$board['location'],
        'station'=>['publicId'=>$stationId,'name'=>$device['station_name']],
        'items'=>$items,'focusItem'=>$focus!==null?$items[$focus]:null,
        'metrics'=>$board['metrics'],
        'revision'=>hash('sha256',json_encode($fingerprint,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
        'pollAfterMs'=>1500,
    ];
}

function glasses_simulator_can_write(array $user): bool
{
    return app_has_permission('glasses.manage',$user) && app_has_permission('kds.update',$user);
}

function glasses_simulator_devices(PDO $pdo,array $user): array
{
    if(!app_has_permission('glasses.view',$user))return [];
    $org=(int)$user['organization_id'];
    $out=glasses_simulator_virtual_devices($pdo,$user);
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
    if(str_starts_with($publicId,'preview:'))return glasses_simulator_preview_device($pdo,$user,$publicId,$write);
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
        'validation.evaluate','handoff.expo','vision.shadow_report','vision.shadow_complete'
    ],true);
    $device=glasses_simulator_device($pdo,$user,(string)($in['devicePublicId']??''),$write);

    if($action==='hardware.cook_ux.evaluate'){
        return ['cookUx'=>glasses_cook_ux_evaluate((array)($in['report']??[]))];
    }

    if($action==='hardware.soak.evaluate'){
        return ['soak'=>glasses_runtime_soak_evaluate((array)($in['report']??[]),(array)($in['policy']??[]))];
    }

    if($action==='hardware.recovery.contract'){
        return ['recovery'=>glasses_runtime_recovery_contract((array)($in['policy']??[]))];
    }
    if($action==='hardware.recovery.decide'){
        return ['recovery'=>glasses_runtime_recovery_decide((array)($in['snapshot']??[]),(array)($in['policy']??[]))];
    }

    if($action==='hardware.health.normalize'){
        return ['health'=>glasses_device_health_normalize((array)($in['snapshot']??[]))];
    }
    if($action==='hardware.diagnostics.bundle'){
        return ['diagnosticBundle'=>glasses_device_health_bundle((array)($in['snapshot']??[]),(array)($in['context']??[]))];
    }

    if($action==='hardware.frame_pipeline'){
        return ['framePipeline'=>glasses_frame_pipeline_runtime_contract((array)($in['policy']??[]))];
    }

    if($action==='hardware.runtime'){
        return ['hardwareRuntime'=>glasses_hardware_runtime_contract('simulator.v1')];
    }
    if($action==='hardware.frame.prepare'){
        return ['frame'=>glasses_hardware_runtime_adapter('simulator.v1')->normalizeFrame((array)($in['frame']??[]))];
    }
    if($action==='hardware.display.prepare'){
        return ['display'=>glasses_hardware_runtime_adapter('simulator.v1')->normalizeDisplay((array)($in['display']??[]))];
    }
    if($action==='hardware.input.prepare'){
        return ['input'=>glasses_hardware_runtime_adapter('simulator.v1')->normalizeInput((array)($in['input']??[]))];
    }
    if($action==='hardware.model.prepare'){
        return ['model'=>glasses_hardware_runtime_adapter('simulator.v1')->normalizeModelLoad((array)($in['model']??[]))];
    }

    if($action==='work'){
        return ['work'=>!empty($device['virtual_preview'])?glasses_simulator_preview_work($pdo,$device):glasses_current_work($pdo,$device)];
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
    if($action==='vision.model_assignment'){
        $detector=trim((string)($in['detectorName']??''));
        if($detector==='')throw new InvalidArgumentException('Detector name is required.');
        return ['assignment'=>glasses_vision_model_assignment($pdo,$device,$session,$detector)];
    }
    if($action==='vision.profile'){
        $detector=trim((string)($in['detectorName']??''));
        if($detector==='')throw new InvalidArgumentException('Detector name is required.');
        return ['profile'=>glasses_vision_profile_for_build($pdo,$device,$session,$detector)];
    }
    if($action==='vision.shadow_assignment'){
        $detector=trim((string)($in['detectorName']??''));
        if($detector==='')throw new InvalidArgumentException('Detector name is required.');
        return ['shadow'=>glasses_vision_shadow_assignment($pdo,$device,$session,$detector)];
    }
    if($action==='vision.shadow_report'){
        return ['shadow'=>glasses_vision_shadow_report($pdo,$device,$session,$in)];
    }
    if($action==='vision.shadow_complete'){
        $run=trim((string)($in['shadowRunPublicId']??''));
        if($run==='')throw new InvalidArgumentException('Shadow run is required.');
        return ['shadow'=>glasses_vision_shadow_complete($pdo,$device,$session,$run)];
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
