<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/glasses-hardware-runtime.php';
require_once __DIR__.'/glasses-vision-models.php';

const GLASSES_PRODUCTION_PILOT_SCHEMA='gelato.glasses_production_pilot.v1';

function glasses_production_pilot_ready(PDO $pdo): bool
{
    foreach(['glasses_production_pilots','glasses_production_pilot_devices','glasses_production_pilot_events'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_production_pilot_event(PDO $pdo,int $org,int $pilotId,?int $deviceId,string $event,?string $previous,?string $next,?string $reason,?int $actor,array $metadata=[]): void
{
    $pdo->prepare("INSERT INTO glasses_production_pilot_events
      (organization_id,pilot_id,device_id,event_type,previous_status,next_status,reason,metadata_json,actor_user_id)
      VALUES (?,?,?,?,?,?,?,?,?)")->execute([
        $org,$pilotId,$deviceId,mb_substr(trim($event),0,80,'UTF-8'),$previous,$next,
        ($reason=mb_substr(trim((string)$reason),0,1000,'UTF-8'))!==''?$reason:null,
        glasses_json_object($metadata,12000),$actor
    ]);
}

function glasses_production_pilot_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT p.*,l.name location_name,s.public_id station_public_id,s.name station_name,r.public_id rollout_public_id,r.status rollout_status
      FROM glasses_production_pilots p
      LEFT JOIN locations l ON l.id=p.location_id AND l.organization_id=p.organization_id
      LEFT JOIN kds_stations s ON s.id=p.station_id AND s.organization_id=p.organization_id
      LEFT JOIN glasses_vision_model_rollouts r ON r.id=p.rollout_id AND r.organization_id=p.organization_id
      WHERE p.organization_id=? AND p.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Production pilot was not found.');
    return $r;
}

function glasses_production_pilot_public(array $r): array
{
    return [
      'schema'=>GLASSES_PRODUCTION_PILOT_SCHEMA,'publicId'=>$r['public_id'],'name'=>$r['name'],'cohortKey'=>$r['cohort_key'],
      'releaseLabel'=>$r['release_label'],'locationId'=>$r['location_id']!==null?(int)$r['location_id']:null,'locationName'=>$r['location_name']??null,
      'stationPublicId'=>$r['station_public_id']??null,'stationName'=>$r['station_name']??null,
      'rolloutPublicId'=>$r['rollout_public_id']??null,'rolloutStatus'=>$r['rollout_status']??null,
      'requiredAdapterId'=>$r['required_adapter_id'],'requireVendorAdapter'=>(bool)$r['require_vendor_adapter'],
      'status'=>$r['status'],'killSwitch'=>(bool)$r['kill_switch'],'notes'=>$r['notes'],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at'],
    ];
}

function glasses_production_pilot_catalog(PDO $pdo,int $org): array
{
    if(!glasses_production_pilot_ready($pdo))return ['pilots'=>[],'devices'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_production_pilots WHERE organization_id=? ORDER BY updated_at DESC,id DESC");
    $q->execute([$org]);$pilots=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public)$pilots[]=glasses_production_pilot_public(glasses_production_pilot_row($pdo,$org,(string)$public,false));
    $q=$pdo->prepare("SELECT pd.*,p.public_id pilot_public_id,d.public_id device_public_id,d.display_name,d.platform,d.status device_status,
      d.location_id,d.station_id,d.sdk_version,d.app_version,d.capabilities_json
      FROM glasses_production_pilot_devices pd
      JOIN glasses_production_pilots p ON p.id=pd.pilot_id AND p.organization_id=pd.organization_id
      JOIN glasses_devices d ON d.id=pd.device_id AND d.organization_id=pd.organization_id
      WHERE pd.organization_id=? AND pd.status<>'removed' ORDER BY pd.updated_at DESC,pd.id DESC");
    $q->execute([$org]);$devices=[];
    foreach($q->fetchAll() as $r)$devices[]=[
      'pilotPublicId'=>$r['pilot_public_id'],'devicePublicId'=>$r['device_public_id'],'displayName'=>$r['display_name'],
      'platform'=>$r['platform'],'status'=>$r['status'],'readinessState'=>$r['readiness_state'],
      'readiness'=>json_decode((string)($r['readiness_json']??'null'),true),'evaluatedAt'=>$r['evaluated_at'],
    ];
    return ['pilots'=>$pilots,'devices'=>$devices];
}

function glasses_production_pilot_create(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_production_pilot_ready($pdo))throw new RuntimeException('Production pilot migration is not installed.');
    $name=mb_substr(trim((string)($input['name']??'')),0,160,'UTF-8');if($name==='')throw new InvalidArgumentException('Pilot name is required.');
    $cohort=mb_substr(strtolower(trim((string)($input['cohortKey']??''))),0,120,'UTF-8');
    if(!preg_match('/^[a-z0-9][a-z0-9._-]{1,119}$/',$cohort))throw new InvalidArgumentException('Pilot cohort key is invalid.');
    $release=mb_substr(trim((string)($input['releaseLabel']??'v10-pilot')),0,120,'UTF-8')?:'v10-pilot';
    $required=mb_substr(trim((string)($input['requiredAdapterId']??'air3.vendor.v1')),0,120,'UTF-8')?:'air3.vendor.v1';
    $requireVendor=!array_key_exists('requireVendorAdapter',$input)||!empty($input['requireVendorAdapter']);
    $locationId=null;$stationId=null;
    if((int)($input['locationId']??0)>0){$locationId=(int)$input['locationId'];glasses_location($pdo,$org,$locationId);}
    if(trim((string)($input['stationPublicId']??''))!==''){
        if($locationId===null)throw new InvalidArgumentException('Station-scoped pilot requires a location.');
        $station=glasses_station($pdo,$org,$locationId,(string)$input['stationPublicId']);$stationId=(int)$station['id'];
    }
    $rolloutId=null;
    if(trim((string)($input['rolloutPublicId']??''))!==''){
        $rollout=glasses_vision_model_rollout_row($pdo,$org,(string)$input['rolloutPublicId'],false);$rolloutId=(int)$rollout['id'];
    }
    $public=glasses_public_id('glasses-pilot');
    $notes=mb_substr(trim((string)($input['notes']??'')),0,1000,'UTF-8')?:null;
    try{$pdo->prepare("INSERT INTO glasses_production_pilots
      (organization_id,public_id,name,cohort_key,release_label,location_id,station_id,rollout_id,required_adapter_id,require_vendor_adapter,notes,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$org,$public,$name,$cohort,$release,$locationId,$stationId,$rolloutId,$required,$requireVendor?1:0,$notes,$actor]);}
    catch(PDOException $e){if((string)$e->getCode()==='23000')throw new InvalidArgumentException('Pilot cohort key already exists.');throw $e;}
    $row=glasses_production_pilot_row($pdo,$org,$public,false);
    glasses_production_pilot_event($pdo,$org,(int)$row['id'],null,'created',null,'draft',null,$actor,['releaseLabel'=>$release,'requiredAdapterId'=>$required]);
    return glasses_production_pilot_public($row);
}

function glasses_production_pilot_device_readiness(PDO $pdo,int $org,string $pilotPublic,string $devicePublic,array $runtime=[]): array
{
    $pilot=glasses_production_pilot_row($pdo,$org,$pilotPublic,false);
    $device=glasses_device_row($pdo,$org,$devicePublic,false);
    $reasons=[];$warnings=[];
    if((string)$device['status']!=='active'||$device['revoked_at']!==null)$reasons[]='device_not_active';
    if($pilot['location_id']!==null&&(int)$pilot['location_id']!==(int)$device['location_id'])$reasons[]='location_scope_mismatch';
    if($pilot['station_id']!==null&&(int)$pilot['station_id']!==(int)($device['station_id']??0))$reasons[]='station_scope_mismatch';
    if(!in_array((string)$pilot['status'],['draft','active','paused'],true))$reasons[]='pilot_stopped';
    if(!empty($pilot['kill_switch']))$reasons[]='pilot_kill_switch';

    $adapterId=trim((string)($runtime['adapterId']??''));
    if($adapterId===''){
        $caps=json_decode((string)($device['capabilities_json']??'null'),true);
        $adapterId=is_array($caps)?trim((string)($caps['hardwareRuntimeAdapterId']??'')):'';
    }
    if(!empty($pilot['require_vendor_adapter'])&&$adapterId!==(string)$pilot['required_adapter_id'])$reasons[]='required_hardware_adapter_unavailable';
    elseif($adapterId!==''&&$adapterId!==(string)$pilot['required_adapter_id'])$warnings[]='non_target_adapter';

    if($pilot['rollout_id']!==null){
        if(!in_array((string)$pilot['rollout_status'],['active','paused'],true))$reasons[]='model_rollout_not_available';
    }
    $health=(string)($runtime['health']??'unknown');
    if($health==='degraded')$reasons[]='runtime_health_degraded';
    elseif($health==='unknown')$warnings[]='runtime_health_unreported';
    $recovery=(string)($runtime['recoveryState']??'unknown');
    if(in_array($recovery,['disconnected','recovering','blocked'],true))$reasons[]='runtime_not_ready';
    elseif($recovery==='unknown')$warnings[]='recovery_state_unreported';

    return [
      'schema'=>GLASSES_PRODUCTION_PILOT_SCHEMA,'ready'=>$reasons===[],'state'=>$reasons===[]?'ready':'blocked',
      'pilotPublicId'=>$pilotPublic,'devicePublicId'=>$devicePublic,'requiredAdapterId'=>$pilot['required_adapter_id'],'adapterId'=>$adapterId?:null,
      'reasons'=>$reasons,'warnings'=>$warnings,'checkedAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
    ];
}

function glasses_production_pilot_enroll(PDO $pdo,int $org,string $pilotPublic,string $devicePublic,int $actor): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$pilotPublic,$devicePublic,$actor):array{
        $pilot=glasses_production_pilot_row($pdo,$org,$pilotPublic,true);$device=glasses_device_row($pdo,$org,$devicePublic,true);
        $pdo->prepare("INSERT INTO glasses_production_pilot_devices (organization_id,pilot_id,device_id,status,readiness_state,enrolled_by)
          VALUES (?,?,?,'enrolled','unknown',?)
          ON DUPLICATE KEY UPDATE status=IF(status='removed','enrolled',status),removed_at=NULL,removed_by=NULL,updated_at=NOW(6)")
          ->execute([$org,(int)$pilot['id'],(int)$device['id'],$actor]);
        glasses_production_pilot_event($pdo,$org,(int)$pilot['id'],(int)$device['id'],'device_enrolled',null,'enrolled',null,$actor);
        return glasses_production_pilot_device_readiness($pdo,$org,$pilotPublic,$devicePublic);
    });
}

function glasses_production_pilot_evaluate(PDO $pdo,int $org,string $pilotPublic,string $devicePublic,array $runtime,int $actor): array
{
    $readiness=glasses_production_pilot_device_readiness($pdo,$org,$pilotPublic,$devicePublic,$runtime);
    $pilot=glasses_production_pilot_row($pdo,$org,$pilotPublic,false);$device=glasses_device_row($pdo,$org,$devicePublic,false);
    $q=$pdo->prepare("SELECT id,status FROM glasses_production_pilot_devices WHERE organization_id=? AND pilot_id=? AND device_id=? AND status<>'removed' LIMIT 1");
    $q->execute([$org,(int)$pilot['id'],(int)$device['id']]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Device is not enrolled in this pilot.');
    $pdo->prepare("UPDATE glasses_production_pilot_devices SET readiness_state=?,readiness_json=?,evaluated_at=NOW(6),status=IF(?='blocked' AND status='enabled','blocked',status),updated_at=NOW(6) WHERE id=?")
      ->execute([$readiness['state'],json_encode($readiness,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$readiness['state'],(int)$row['id']]);
    glasses_production_pilot_event($pdo,$org,(int)$pilot['id'],(int)$device['id'],'readiness_evaluated',(string)$row['status'],null,null,$actor,['readiness'=>$readiness]);
    return $readiness;
}

function glasses_production_pilot_set_device_status(PDO $pdo,int $org,string $pilotPublic,string $devicePublic,string $next,string $reason,int $actor,array $runtime=[]): array
{
    if(!in_array($next,['enabled','disabled','removed'],true))throw new InvalidArgumentException('Pilot device status is invalid.');
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($next!=='enabled'&&$reason==='')throw new InvalidArgumentException('Disabling/removing a pilot device requires a reason.');
    return glasses_transaction($pdo,function()use($pdo,$org,$pilotPublic,$devicePublic,$next,$reason,$actor,$runtime):array{
        $pilot=glasses_production_pilot_row($pdo,$org,$pilotPublic,true);$device=glasses_device_row($pdo,$org,$devicePublic,true);
        $q=$pdo->prepare("SELECT * FROM glasses_production_pilot_devices WHERE organization_id=? AND pilot_id=? AND device_id=? AND status<>'removed' LIMIT 1 FOR UPDATE");
        $q->execute([$org,(int)$pilot['id'],(int)$device['id']]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Device is not enrolled in this pilot.');
        $previous=(string)$row['status'];
        if($next==='enabled'){
            if((string)$pilot['status']!=='active'||!empty($pilot['kill_switch']))throw new InvalidArgumentException('Pilot must be active with kill switch off before enabling devices.');
            $ready=glasses_production_pilot_device_readiness($pdo,$org,$pilotPublic,$devicePublic,$runtime);
            if(!$ready['ready'])throw new InvalidArgumentException('Device is not production-ready: '.implode(', ',$ready['reasons']));
            $pdo->prepare("UPDATE glasses_production_pilot_devices SET status='enabled',readiness_state='ready',readiness_json=?,evaluated_at=NOW(6),enabled_by=?,enabled_at=NOW(6),disabled_at=NULL,disabled_by=NULL,updated_at=NOW(6) WHERE id=?")
              ->execute([json_encode($ready,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$actor,(int)$row['id']]);
        }elseif($next==='disabled'){
            $pdo->prepare("UPDATE glasses_production_pilot_devices SET status='disabled',disabled_by=?,disabled_at=NOW(6),updated_at=NOW(6) WHERE id=?")
              ->execute([$actor,(int)$row['id']]);
        }else{
            $pdo->prepare("UPDATE glasses_production_pilot_devices SET status='removed',removed_by=?,removed_at=NOW(6),updated_at=NOW(6) WHERE id=?")
              ->execute([$actor,(int)$row['id']]);
        }
        glasses_production_pilot_event($pdo,$org,(int)$pilot['id'],(int)$device['id'],'device_'.$next,$previous,$next,$reason,$actor);
        return ['pilot'=>glasses_production_pilot_public(glasses_production_pilot_row($pdo,$org,$pilotPublic,false)),'readiness'=>glasses_production_pilot_device_readiness($pdo,$org,$pilotPublic,$devicePublic,$runtime),'status'=>$next];
    });
}

function glasses_production_pilot_set_status(PDO $pdo,int $org,string $pilotPublic,string $next,string $reason,int $actor): array
{
    if(!in_array($next,['active','paused','stopped'],true))throw new InvalidArgumentException('Pilot status is invalid.');
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if(in_array($next,['paused','stopped'],true)&&$reason==='')throw new InvalidArgumentException('Pausing/stopping a pilot requires a reason.');
    return glasses_transaction($pdo,function()use($pdo,$org,$pilotPublic,$next,$reason,$actor):array{
        $pilot=glasses_production_pilot_row($pdo,$org,$pilotPublic,true);$previous=(string)$pilot['status'];
        if($next==='active'&&!in_array($previous,['draft','paused'],true))throw new InvalidArgumentException('Only draft or paused pilots can be activated.');
        if($next==='paused'&&$previous!=='active')throw new InvalidArgumentException('Only active pilots can be paused.');
        if($next==='stopped'&&!in_array($previous,['active','paused','draft'],true))throw new InvalidArgumentException('Pilot cannot be stopped from its current state.');
        $sets=["status=?","updated_at=NOW(6)"];$args=[$next];
        if($next==='active'){$sets[]="activated_by=?";$sets[]="activated_at=COALESCE(activated_at,NOW(6))";$sets[]="paused_at=NULL";$args[]=$actor;}
        if($next==='paused'){$sets[]="paused_by=?";$sets[]="paused_at=NOW(6)";$args[]=$actor;}
        if($next==='stopped'){$sets[]="stopped_by=?";$sets[]="stopped_at=NOW(6)";$args[]=$actor;}
        $args[]=$org;$args[]=(int)$pilot['id'];
        $pdo->prepare("UPDATE glasses_production_pilots SET ".implode(',',$sets)." WHERE organization_id=? AND id=?")->execute($args);
        if($next!=='active')$pdo->prepare("UPDATE glasses_production_pilot_devices SET status=IF(status='enabled','disabled',status),disabled_by=IF(status='enabled',?,disabled_by),disabled_at=IF(status='enabled',NOW(6),disabled_at),updated_at=NOW(6) WHERE organization_id=? AND pilot_id=?")
          ->execute([$actor,$org,(int)$pilot['id']]);
        glasses_production_pilot_event($pdo,$org,(int)$pilot['id'],null,'pilot_'.$next,$previous,$next,$reason,$actor);
        return glasses_production_pilot_public(glasses_production_pilot_row($pdo,$org,$pilotPublic,false));
    });
}

function glasses_production_pilot_kill_switch(PDO $pdo,int $org,string $pilotPublic,bool $enabled,string $reason,int $actor): array
{
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($enabled&&$reason==='')throw new InvalidArgumentException('Kill switch activation requires a reason.');
    return glasses_transaction($pdo,function()use($pdo,$org,$pilotPublic,$enabled,$reason,$actor):array{
        $pilot=glasses_production_pilot_row($pdo,$org,$pilotPublic,true);
        $pdo->prepare("UPDATE glasses_production_pilots SET kill_switch=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$enabled?1:0,$org,(int)$pilot['id']]);
        if($enabled)$pdo->prepare("UPDATE glasses_production_pilot_devices SET status=IF(status='enabled','disabled',status),disabled_by=IF(status='enabled',?,disabled_by),disabled_at=IF(status='enabled',NOW(6),disabled_at),updated_at=NOW(6) WHERE organization_id=? AND pilot_id=?")->execute([$actor,$org,(int)$pilot['id']]);
        glasses_production_pilot_event($pdo,$org,(int)$pilot['id'],null,$enabled?'kill_switch_on':'kill_switch_off',null,null,$reason,$actor);
        return glasses_production_pilot_public(glasses_production_pilot_row($pdo,$org,$pilotPublic,false));
    });
}

function glasses_production_pilot_rollback_rollout(PDO $pdo,int $org,string $pilotPublic,string $reason,int $actor): array
{
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('Pilot rollout rollback requires a documented reason.');
    return glasses_transaction($pdo,function()use($pdo,$org,$pilotPublic,$reason,$actor):array{
        $pilot=glasses_production_pilot_row($pdo,$org,$pilotPublic,true);
        if($pilot['rollout_public_id']===null)throw new InvalidArgumentException('Pilot is not bound to a model rollout.');
        $pdo->prepare("UPDATE glasses_production_pilots SET kill_switch=1,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$org,(int)$pilot['id']]);
        $pdo->prepare("UPDATE glasses_production_pilot_devices SET status=IF(status='enabled','disabled',status),disabled_by=IF(status='enabled',?,disabled_by),disabled_at=IF(status='enabled',NOW(6),disabled_at),updated_at=NOW(6) WHERE organization_id=? AND pilot_id=?")
          ->execute([$actor,$org,(int)$pilot['id']]);
        glasses_production_pilot_event($pdo,$org,(int)$pilot['id'],null,'kill_switch_on',null,null,'Rollback: '.$reason,$actor);
        $rollout=glasses_vision_model_rollout_rollback($pdo,$org,(string)$pilot['rollout_public_id'],$actor,'Production pilot '.$pilotPublic.': '.$reason);
        glasses_production_pilot_event($pdo,$org,(int)$pilot['id'],null,'rollout_rolled_back',(string)$pilot['rollout_status'],'rolled_back',$reason,$actor,['rolloutPublicId'=>$pilot['rollout_public_id']]);
        return ['pilot'=>glasses_production_pilot_public(glasses_production_pilot_row($pdo,$org,$pilotPublic,false)),'rollout'=>$rollout];
    });
}

function glasses_production_pilot_device_status(PDO $pdo,array $device,array $runtime=[]): array
{
    $org=(int)$device['organization_id'];
    if(!glasses_production_pilot_ready($pdo))return ['schema'=>GLASSES_PRODUCTION_PILOT_SCHEMA,'productionAllowed'=>false,'reason'=>'pilot_governance_unavailable','pilot'=>null];
    $q=$pdo->prepare("SELECT p.public_id FROM glasses_production_pilot_devices pd JOIN glasses_production_pilots p ON p.id=pd.pilot_id AND p.organization_id=pd.organization_id
      WHERE pd.organization_id=? AND pd.device_id=? AND pd.status='enabled' AND p.status='active' AND p.kill_switch=0 ORDER BY p.updated_at DESC LIMIT 1");
    $q->execute([$org,(int)$device['id']]);$pilotPublic=$q->fetchColumn();
    if(!$pilotPublic)return ['schema'=>GLASSES_PRODUCTION_PILOT_SCHEMA,'productionAllowed'=>false,'reason'=>'device_not_enabled_for_active_pilot','pilot'=>null];
    $readiness=glasses_production_pilot_device_readiness($pdo,$org,(string)$pilotPublic,(string)$device['public_id'],$runtime);
    return ['schema'=>GLASSES_PRODUCTION_PILOT_SCHEMA,'productionAllowed'=>$readiness['ready'],'reason'=>$readiness['ready']?null:'readiness_blocked','pilot'=>glasses_production_pilot_public(glasses_production_pilot_row($pdo,$org,(string)$pilotPublic,false)),'readiness'=>$readiness];
}

function glasses_production_pilot_assert_device_ready(PDO $pdo,array $device,array $runtime=[]): array
{
    $status=glasses_production_pilot_device_status($pdo,$device,$runtime);
    if(empty($status['productionAllowed']))throw new InvalidArgumentException('Production mode is not authorized for this device: '.($status['reason']??'pilot_not_ready'));
    return $status;
}
