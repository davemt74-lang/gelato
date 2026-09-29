<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-calibration.php';
require_once __DIR__.'/../includes/glasses-vision-calibration-profiles.php';

function v83_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v83_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v83_assert(glasses_vision_calibration_profile_ready($pdo),'V8 calibration-profile migration must be installed.');

$slug='vl83-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Calibration Profiles '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Calibration','Reviewer','Calibration Reviewer']);$actor=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'v83-'.$slug,'targetSeconds'=>300],$actor);
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Pepperoni',?,'food','verified')")->execute([$org,'pepperoni-'.$slug]);$ingredient=(int)$pdo->lastInsertId();

$bright=glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
 'platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8',
 'zones'=>[['zoneKey'=>'pep','ingredientId'=>$ingredient,'x'=>.1,'y'=>.1,'width'=>.2,'height'=>.2]]
],$actor);
$modelPublic='vision-cal-model-'.$slug;$modelHash=hash('sha256','cal-model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Calibration Detector','v8.3','onnx','inmo_air3','https://example.test/v83.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);

$profile=glasses_vision_calibration_profile_create($pdo,$org,[
 'profileKey'=>'bright-line','calibrationPublicId'=>$bright['publicId'],'modelPackagePublicId'=>$modelPublic,'priority'=>50,
 'context'=>['brightness'=>['min'=>.75,'max'=>1.0],'contrast'=>['min'=>.25,'max'=>.60]]
],$actor);
v83_assert($profile['status']==='draft','New calibration profile must require explicit activation.');
v83_assert(glasses_vision_calibration_profile_verify($pdo,$org,$profile['publicId'])['passed']===true,'Fresh calibration profile must verify.');
$profile=glasses_vision_calibration_profile_set_status($pdo,$org,$profile['publicId'],'active',$actor);

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V83-'.$slug,'displayName'=>'V8 Calibration AIR3','platform'=>'inmo_air3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);

$baseInput=[
 'devicePublicId'=>$device['public_id'],'modelPackagePublicId'=>$modelPublic,
 'runtime'=>['platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8']
];
$match=glasses_vision_calibration_profile_select($pdo,$org,$baseInput+['context'=>['brightnessMean'=>.90,'contrastMean'=>.40]],$actor);
v83_assert($match['decision']==='profile'&&$match['selectedProfilePublicId']===$profile['publicId'],'Compatible bright context must select the governed profile.');
v83_assert(glasses_vision_calibration_selection_verify($pdo,$org,$match['publicId'])['passed']===true,'Fresh calibration selection must verify.');

$same=glasses_vision_calibration_profile_select($pdo,$org,$baseInput+['context'=>['brightnessMean'=>.90,'contrastMean'=>.40]],$actor);
v83_assert($same['publicId']===$match['publicId'],'Same runtime/context selection must be idempotent.');

$fallback=glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
 'platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8',
 'zones'=>[['zoneKey'=>'pep','ingredientId'=>$ingredient,'x'=>.12,'y'=>.12,'width'=>.2,'height'=>.2]]
],$actor);
v83_assert((string)v83_one($pdo,"SELECT status FROM glasses_station_calibrations WHERE organization_id=? AND public_id=?",[$org,$bright['publicId']])==='superseded','Second station calibration must supersede first.');
$profileAfterSupersede=glasses_vision_calibration_profile_row($pdo,$org,$profile['publicId']);
v83_assert($profileAfterSupersede['calibrationStatus']==='superseded','Profile must surface superseded source calibration state.');

$dark=glasses_vision_calibration_profile_select($pdo,$org,$baseInput+['context'=>['brightnessMean'=>.40,'contrastMean'=>.40]],$actor);
v83_assert($dark['decision']==='station_fallback'&&$dark['selectedProfilePublicId']===null&&$dark['fallbackCalibrationPublicId']===$fallback['publicId'],'Non-matching context must fall back to current station calibration.');

$wrongFrame=glasses_vision_calibration_profile_select($pdo,$org,[
 'devicePublicId'=>$device['public_id'],'modelPackagePublicId'=>$modelPublic,
 'runtime'=>['platform'=>'inmo_air3','frameWidth'=>800,'frameHeight'=>480,'pixelFormat'=>'grayscale8'],
 'context'=>['brightnessMean'=>.90,'contrastMean'=>.40]
],$actor);
v83_assert($wrongFrame['decision']==='none','Incompatible profile and incompatible fallback must return none rather than guess.');

$missingRuntime=false;
try{glasses_vision_calibration_profile_select($pdo,$org,['devicePublicId'=>$device['public_id'],'modelPackagePublicId'=>$modelPublic,'context'=>['brightnessMean'=>.90]],$actor);}
catch(InvalidArgumentException){$missingRuntime=true;}
v83_assert($missingRuntime,'Selection without a current camera signature or verified analysis must fail closed.');
v83_assert((int)v83_one($pdo,"SELECT COUNT(*) FROM glasses_vision_calibration_profile_events WHERE organization_id=? AND profile_id=(SELECT id FROM glasses_vision_calibration_profiles WHERE organization_id=? AND public_id=?)",[$org,$org,$profile['publicId']])===2,'Profile creation and activation must both be append-only lifecycle events.');
v83_assert((int)v83_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='station_calibration' AND from_public_id=? AND relation='profiled_as' AND to_kind='calibration_profile'",[$org,$bright['publicId']])===1,'Calibration profile must retain source-calibration lineage.');
v83_assert((int)v83_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='calibration_profile' AND from_public_id=? AND relation='selected_as' AND to_kind='calibration_selection'",[$org,$profile['publicId']])===1,'Profile selection must retain immutable lineage.');

$profile=glasses_vision_calibration_profile_set_status($pdo,$org,$profile['publicId'],'retired',$actor);
$retired=glasses_vision_calibration_profile_select($pdo,$org,$baseInput+['context'=>['brightnessMean'=>.90,'contrastMean'=>.40]],$actor);
v83_assert($retired['decision']==='station_fallback'&&$retired['selectedProfilePublicId']===null,'Retired profiles must never be selected.');
$reactivate=false;try{glasses_vision_calibration_profile_set_status($pdo,$org,$profile['publicId'],'active',$actor);}catch(InvalidArgumentException){$reactivate=true;}
v83_assert($reactivate,'Retired profiles must not be silently reactivated.');
v83_assert((int)v83_one($pdo,"SELECT COUNT(*) FROM glasses_vision_calibration_profile_events WHERE organization_id=? AND profile_id=(SELECT id FROM glasses_vision_calibration_profiles WHERE organization_id=? AND public_id=?)",[$org,$org,$profile['publicId']])===3,'Retirement must append a durable lifecycle event.');

$pdo->prepare("UPDATE glasses_vision_calibration_selections SET reasons_json='{}' WHERE organization_id=? AND public_id=?")->execute([$org,$match['publicId']]);
v83_assert(glasses_vision_calibration_selection_verify($pdo,$org,$match['publicId'])['passed']===false,'Calibration selection tampering must be detectable.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$source=file_get_contents(__DIR__.'/../includes/glasses-vision-calibration-profiles.php');
foreach(['calibration_profile.create','calibration_profile.activate','calibration_profile.retire','calibration_profile.select','calibration_profile.verify','calibration_selection.verify'] as $action)
 v83_assert(str_contains($api,$action),'V8 calibration-profile API missing '.$action);
v83_assert(str_contains($page,'Calibration Profile Runtime'),'Vision Lab must expose Section 3 calibration profiles.');
v83_assert(!str_contains($source,'rollout.activate')&&!str_contains($source,'rollout.advance')&&!str_contains($source,'kds_transition'),'Calibration profile runtime must not mutate rollout or kitchen truth.');
v83_assert(str_contains($source,"'automaticThresholdChange'=>false")&&str_contains($source,"'automaticModelChange'=>false"),'Calibration profiles must preserve hard governance boundaries.');

echo "vision-lab-v8-calibration-profile-runtime-ok\n";
