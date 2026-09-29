<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-production-evidence.php';

function v112_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v112_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}
function v112_user(PDO $pdo,int $org,int $location,string $email,string $name):int{
  $parts=preg_split('/\s+/',trim($name),2);$first=$parts[0]?:'Vision';$last=$parts[1]??'User';
  $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture-hash',?,?,?,'active')")->execute([$email,$first,$last,$name]);$id=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,status) VALUES (?,?,?,'active')")->execute([$org,$id,$location]);return $id;
}
function v112_device(PDO $pdo,int $org,int $location,string $stationPublic,int $actor,string $hardware):array{
  $grant=glasses_create_pairing_grant($pdo,$org,$location,$stationPublic,$actor,10);
  $paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>$hardware,'displayName'=>$hardware,'platform'=>'browser_simulator','sdkVersion'=>'sim','appVersion'=>'v11','capabilities'=>['hardwareRuntimeAdapterId'=>'simulator.v1']]);
  return glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
}

$pdo=app_pdo();
v112_assert(glasses_v11_evidence_ready($pdo),'V11 production evidence migration must be installed.');

$slug='v112-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['V11 Evidence '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Evidence Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$actor=v112_user($pdo,$org,$location,$slug.'-admin@example.test','Evidence Admin');
$operator=v112_user($pdo,$org,$location,$slug.'-cook@example.test','Evidence Cook');
$supervisor=v112_user($pdo,$org,$location,$slug.'-sup@example.test','Evidence Supervisor');
$station=kds_station_save($pdo,$org,$location,['name'=>'Evidence Line','slug'=>'evidence-'.$slug,'targetSeconds'=>300],$actor);
$device=v112_device($pdo,$org,$location,(string)$station['public_id'],$actor,'V11-EVIDENCE-A-'.$slug);
$other=v112_device($pdo,$org,$location,(string)$station['public_id'],$actor,'V11-EVIDENCE-B-'.$slug);

$program=glasses_training_program_create($pdo,$org,['name'=>'Evidence Capture Program','mode'=>'training','locationId'=>$location,'stationPublicId'=>$station['public_id']],$actor);
$assignment=glasses_training_assignment_create($pdo,$org,['programPublicId'=>$program['public_id'],'userId'=>$operator,'devicePublicId'=>$device['public_id'],'supervisorUserId'=>$supervisor],$actor);
$session=glasses_training_session_start($pdo,$org,$assignment['public_id'],$device,$actor,['source'=>'v11-section2-contract']);

$modelPublic='vision-v112-'.$slug;$sha=hash('sha256','model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','V11 Evidence Detector','1','onnx','browser_simulator','https://example.test/v112.onnx',?,123,'ready',?)")->execute([$org,$modelPublic,$sha,$actor]);

$image='iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAYElEQVR4nO3PQQ0AIBDAMED5SUcEj4ZkVbDtmVk/OzrgVQNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgPaBaIsAgBhHc02AAAAAElFTkSuQmCC';
$evidence=glasses_v11_production_evidence_capture($pdo,$device,[
  'trainingSessionPublicId'=>$session['public_id'],'modelPackagePublicId'=>$modelPublic,
  'imageBase64'=>$image,'capturedAt'=>gmdate('c'),'retentionDays'=>90,
  'recipeStepKey'=>'sauce.spread','validationOutcome'=>'ready_candidate','trainingEligibility'=>'review',
  'privacyScope'=>'training_opt_in','annotations'=>[['label'=>'sauce','bbox'=>['x'=>0.2,'y'=>0.2,'width'=>0.3,'height'=>0.3]]]
],$actor);

v112_assert($evidence['trainingSessionPublicId']===$session['public_id'],'Evidence must bind active V11 training session.');
v112_assert($evidence['trainingAssignmentPublicId']===$assignment['public_id'],'Evidence must bind V11 assignment.');
v112_assert($evidence['programPublicId']===$program['public_id'],'Evidence must bind training program.');
v112_assert((int)$evidence['operatorUserId']===$operator,'Evidence must bind canonical employee/operator.');
v112_assert($evidence['devicePublicId']===$device['public_id'],'Evidence must bind authenticated glasses.');
v112_assert($evidence['stationPublicId']===$station['public_id'],'Evidence must retain station identity.');
v112_assert($evidence['modelPackagePublicId']===$modelPublic&&$evidence['modelArtifactSha256']===$sha,'Evidence must bind governed model artifact.');
v112_assert($evidence['recipeStepKey']==='sauce.spread'&&$evidence['validationOutcome']==='ready_candidate','Evidence must retain recipe/validation context.');
v112_assert($evidence['trainingEligibility']==='review'&&$evidence['privacyScope']==='training_opt_in','Evidence must default into governed review.');
v112_assert((int)v112_one($pdo,"SELECT COUNT(*) FROM glasses_vision_training_media WHERE organization_id=? AND public_id=?",[$org,$evidence['publicId']])===1,'V11 evidence must use the existing governed training-media store.');
v112_assert((int)v112_one($pdo,"SELECT operator_user_id FROM glasses_vision_training_media WHERE organization_id=? AND public_id=?",[$org,$evidence['publicId']])===$operator,'Canonical media row must carry V11 operator identity.');
v112_assert((int)v112_one($pdo,"SELECT operator_user_id FROM glasses_vision_training_samples WHERE organization_id=? AND public_id=?",[$org,$evidence['samplePublicId']])===$operator,'Canonical sample must inherit V11 operator identity.');
v112_assert((int)v112_one($pdo,"SELECT COUNT(*) FROM glasses_vision_training_media_events WHERE organization_id=? AND media_id=(SELECT id FROM glasses_vision_training_media WHERE organization_id=? AND public_id=?) AND event_type='v11_production_context_bound'",[$org,$org,$evidence['publicId']])===1,'V11 context binding must be audited.');

$eligible=glasses_v11_production_evidence_set_eligibility($pdo,$org,$evidence['publicId'],'eligible','',$actor);
v112_assert($eligible['trainingEligibility']==='eligible','Reviewer must be able to mark evidence eligible.');
$reasonRequired=false;try{glasses_v11_production_evidence_set_eligibility($pdo,$org,$evidence['publicId'],'excluded','',$actor);}catch(InvalidArgumentException){$reasonRequired=true;}
v112_assert($reasonRequired,'Exclusion must require a reason.');
$excluded=glasses_v11_production_evidence_set_eligibility($pdo,$org,$evidence['publicId'],'excluded','Wrong framing',$actor);
v112_assert($excluded['trainingEligibility']==='excluded'&&$excluded['trainingExclusionReason']==='Wrong framing','Excluded evidence must preserve reason.');

$wrongDevice=false;try{glasses_v11_production_evidence_capture($pdo,$other,['trainingSessionPublicId'=>$session['public_id'],'imageBase64'=>$image,'capturedAt'=>gmdate('c')],$actor);}catch(InvalidArgumentException){$wrongDevice=true;}
v112_assert($wrongDevice,'A training session cannot be reused by another glasses device.');

glasses_training_session_end($pdo,$org,$session['public_id'],'completed','done',$actor);
$endedBlocked=false;try{glasses_v11_production_evidence_capture($pdo,$device,['trainingSessionPublicId'=>$session['public_id'],'imageBase64'=>$image,'capturedAt'=>gmdate('c')],$actor);}catch(InvalidArgumentException){$endedBlocked=true;}
v112_assert($endedBlocked,'Evidence capture must require an active training session.');

$catalog=glasses_v11_production_evidence_catalog($pdo,$org,10);
v112_assert(count($catalog['evidence'])===1,'Evidence catalog must expose canonical V11-tagged media.');

$source=file_get_contents(__DIR__.'/../includes/glasses-production-evidence.php');
$migration=file_get_contents(__DIR__.'/../database/20261128_v11_production_evidence_capture.sql');
$deviceApi=file_get_contents(__DIR__.'/../api/glasses-device.php');
$labApi=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$ui=file_get_contents(__DIR__.'/../assets/js/glasses-production-evidence.js');

v112_assert(str_contains($migration,'ALTER TABLE glasses_vision_training_media'),'Section 2 must extend the canonical media store rather than create a second evidence table.');
v112_assert(!str_contains($migration,'CREATE TABLE'),'Section 2 must not create a duplicate production evidence repository.');
v112_assert(str_contains($deviceApi,'training.evidence_capture'),'Device API must expose governed evidence capture.');
v112_assert(str_contains($labApi,'training.evidence_eligibility'),'Vision Lab must expose audited eligibility review.');
v112_assert(str_contains($page,'V11 Production Evidence Capture'),'Vision Lab must expose V11 evidence lineage.');
v112_assert(str_contains($ui,'Eligible')&&str_contains($ui,'Exclude'),'Evidence UI must expose governed eligibility states.');
foreach(['kds_transition(','glasses_handoff_to_expo(','glasses_build_confirm(','glasses_production_pilot_set_device_status('] as $forbidden)
 v112_assert(!str_contains($source,$forbidden),'Evidence capture must not mutate kitchen/production authority: '.$forbidden);

echo "glasses-v11-production-evidence-ok\n";
