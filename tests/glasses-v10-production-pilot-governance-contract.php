<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-production-pilot.php';

function v107_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v107_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v107_assert(glasses_production_pilot_ready($pdo),'V10 production-pilot migration must be installed.');

$slug='v107-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Pilot '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Pilot Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Pilot','Admin','Pilot Admin']);$actor=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Pilot Line','slug'=>'pilot-'.$slug,'targetSeconds'=>300],$actor);

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
 'hardwareIdentifier'=>'AIR3-V107-'.$slug,'displayName'=>'V10 Pilot AIR3','platform'=>'inmo_air3',
 'sdkVersion'=>'1.5.0','appVersion'=>'2.0.0','capabilities'=>['visionModelRuntimes'=>['onnx'],'hardwareRuntimeAdapterId'=>'simulator.v1']
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);$devicePublic=(string)$device['public_id'];

$targetPublic='vision-v107-target-'.$slug;$basePublic='vision-v107-base-'.$slug;
$targetSha=hash('sha256','target-'.$slug);$baseSha=hash('sha256','base-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','V10 Pilot Detector','target','onnx','inmo_air3','https://example.test/v107-target.onnx',?,123,'ready',?)")->execute([$org,$targetPublic,$targetSha,$actor]);$targetId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','V10 Pilot Detector','baseline','onnx','inmo_air3','https://example.test/v107-base.onnx',?,123,'ready',?)")->execute([$org,$basePublic,$baseSha,$actor]);$baseId=(int)$pdo->lastInsertId();
$rolloutPublic='vision-v107-rollout-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_model_rollouts (organization_id,public_id,detector_name,target_package_id,baseline_package_id,location_id,station_id,canary_percent,status,created_by,activated_by,activated_at)
 VALUES (?,?,'ingredient_detector',?,?,?,?,100.00,'active',?,?,UTC_TIMESTAMP(6))")->execute([$org,$rolloutPublic,$targetId,$baseId,$location,(int)$station['id'],$actor,$actor]);

$sim=glasses_production_pilot_create($pdo,$org,[
 'name'=>'Simulator Pilot','cohortKey'=>'sim-'.$slug,'releaseLabel'=>'v10-sim','locationId'=>$location,'stationPublicId'=>$station['public_id'],
 'requiredAdapterId'=>'simulator.v1','requireVendorAdapter'=>false
],$actor);
glasses_production_pilot_enroll($pdo,$org,$sim['publicId'],$devicePublic,$actor);
glasses_production_pilot_set_status($pdo,$org,$sim['publicId'],'active','',$actor);
$runtime=['adapterId'=>'simulator.v1','health'=>'healthy','recoveryState'=>'ready'];
$ready=glasses_production_pilot_evaluate($pdo,$org,$sim['publicId'],$devicePublic,$runtime,$actor);
v107_assert($ready['ready']===true,'Simulator pilot must be able to pass readiness before AIR3 SDK arrival.');
$enabled=glasses_production_pilot_set_device_status($pdo,$org,$sim['publicId'],$devicePublic,'enabled','',$actor,$runtime);
v107_assert($enabled['status']==='enabled','Ready simulator pilot device must enable.');
$status=glasses_production_pilot_device_status($pdo,$device,$runtime);
v107_assert($status['productionAllowed']===true,'Enabled active ready pilot must authorize production mode.');

$kill=glasses_production_pilot_kill_switch($pdo,$org,$sim['publicId'],true,'Emergency stop test',$actor);
v107_assert($kill['killSwitch']===true,'Kill switch must engage.');
v107_assert((string)v107_one($pdo,"SELECT pd.status FROM glasses_production_pilot_devices pd JOIN glasses_production_pilots p ON p.id=pd.pilot_id WHERE p.organization_id=? AND p.public_id=? AND pd.device_id=?",[$org,$sim['publicId'],(int)$device['id']])==='disabled','Kill switch must disable enabled devices.');
v107_assert(glasses_production_pilot_device_status($pdo,$device,$runtime)['productionAllowed']===false,'Kill switch must revoke production authorization.');

glasses_production_pilot_kill_switch($pdo,$org,$sim['publicId'],false,'',$actor);
glasses_production_pilot_set_device_status($pdo,$org,$sim['publicId'],$devicePublic,'enabled','',$actor,$runtime);
glasses_production_pilot_set_status($pdo,$org,$sim['publicId'],'paused','Pilot pause test',$actor);
v107_assert((string)v107_one($pdo,"SELECT pd.status FROM glasses_production_pilot_devices pd JOIN glasses_production_pilots p ON p.id=pd.pilot_id WHERE p.organization_id=? AND p.public_id=? AND pd.device_id=?",[$org,$sim['publicId'],(int)$device['id']])==='disabled','Pausing pilot must disable enabled devices.');

$air=glasses_production_pilot_create($pdo,$org,[
 'name'=>'AIR3 Pilot','cohortKey'=>'air3-'.$slug,'releaseLabel'=>'v10-air3','locationId'=>$location,'stationPublicId'=>$station['public_id'],
 'requiredAdapterId'=>'air3.vendor.v1','requireVendorAdapter'=>true
],$actor);
glasses_production_pilot_enroll($pdo,$org,$air['publicId'],$devicePublic,$actor);
glasses_production_pilot_set_status($pdo,$org,$air['publicId'],'active','',$actor);
$airReady=glasses_production_pilot_evaluate($pdo,$org,$air['publicId'],$devicePublic,$runtime,$actor);
v107_assert($airReady['ready']===false&&in_array('required_hardware_adapter_unavailable',$airReady['reasons'],true),'Real AIR3 pilot must remain blocked without air3.vendor.v1.');
$blocked=false;try{glasses_production_pilot_set_device_status($pdo,$org,$air['publicId'],$devicePublic,'enabled','',$actor,$runtime);}catch(InvalidArgumentException){$blocked=true;}
v107_assert($blocked,'AIR3 device enable must fail until vendor adapter readiness passes.');

$rollbackPilot=glasses_production_pilot_create($pdo,$org,[
 'name'=>'Rollback Pilot','cohortKey'=>'rollback-'.$slug,'releaseLabel'=>'v10-rollback','locationId'=>$location,'stationPublicId'=>$station['public_id'],
 'rolloutPublicId'=>$rolloutPublic,'requiredAdapterId'=>'simulator.v1','requireVendorAdapter'=>false
],$actor);
glasses_production_pilot_enroll($pdo,$org,$rollbackPilot['publicId'],$devicePublic,$actor);
glasses_production_pilot_set_status($pdo,$org,$rollbackPilot['publicId'],'active','',$actor);
glasses_production_pilot_set_device_status($pdo,$org,$rollbackPilot['publicId'],$devicePublic,'enabled','',$actor,$runtime);
$rollback=glasses_production_pilot_rollback_rollout($pdo,$org,$rollbackPilot['publicId'],'Acceptance rollback',$actor);
v107_assert($rollback['pilot']['killSwitch']===true,'Pilot rollback must engage kill switch first.');
v107_assert($rollback['rollout']['status']==='rolled_back','Pilot rollback must delegate to canonical model rollout rollback.');
v107_assert((string)v107_one($pdo,"SELECT status FROM glasses_vision_model_rollouts WHERE organization_id=? AND public_id=?",[$org,$rolloutPublic])==='rolled_back','Canonical rollout must be rolled back.');
v107_assert((int)v107_one($pdo,"SELECT COUNT(*) FROM glasses_production_pilot_events WHERE organization_id=? AND pilot_id=(SELECT id FROM glasses_production_pilots WHERE organization_id=? AND public_id=?)",[$org,$org,$rollbackPilot['publicId']])>=4,'Pilot lifecycle must write durable audit events.');

$devApi=file_get_contents(__DIR__.'/../api/glasses-device.php');
$labApi=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$ui=file_get_contents(__DIR__.'/../assets/js/glasses-production-pilot.js');
$source=file_get_contents(__DIR__.'/../includes/glasses-production-pilot.php');

v107_assert(str_contains($devApi,"pilot.production_status"),'Device API must expose production readiness status.');
v107_assert(str_contains($devApi,"productionMode")&&str_contains($devApi,'glasses_production_pilot_assert_device_ready'),'Production model assignment must enforce pilot readiness when productionMode is requested.');
foreach(['pilot.create','pilot.device_enable','pilot.kill_switch','pilot.rollback_rollout'] as $action)v107_assert(str_contains($labApi,$action),'Vision Lab API missing '.$action);
v107_assert(str_contains($page,'V10 Production Pilot Governance'),'Vision Lab must expose production pilot governance.');
v107_assert(str_contains($ui,'KILL SWITCH')&&str_contains($ui,'Rollback rollout'),'Pilot UI must expose emergency controls.');
v107_assert(str_contains($source,'glasses_vision_model_rollout_rollback'),'Pilot rollback must reuse canonical model rollout rollback.');
v107_assert(!str_contains($source,'kds_transition(')&&!str_contains($source,'glasses_handoff_to_expo(')&&!str_contains($source,'glasses_build_confirm('),'Pilot governance must not mutate kitchen authority.');

echo "glasses-v10-production-pilot-governance-ok\n";
