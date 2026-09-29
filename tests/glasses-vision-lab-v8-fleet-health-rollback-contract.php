<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-vision-fleet-health.php';

function v86_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v86_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

function v86_snapshot(PDO $pdo,int $org,int $actor,array $package,int $location,array $station,array $device,string $from,string $to,string $state): array
{
    $metrics=[
      'sampleCount'=>1,'observationCount'=>10,'productionErrorCount'=>0,'correctionCount'=>0,'lowConfidenceCount'=>0,
      'criticalDriftCount'=>$state==='critical'?1:0,'warningDriftCount'=>$state==='watch'?1:0,
      'meanConfidence'=>.80,'meanLatencyMs'=>60.0,'errorRate'=>0.0,'correctionRate'=>0.0,'lowConfidenceRate'=>0.0
    ];
    $source=[
      'schema'=>GLASSES_VISION_MODEL_HEALTH_SCHEMA,
      'scope'=>['modelPackagePublicId'=>$package['public_id'],'modelArtifactSha256'=>$package['artifact_sha256'],'locationId'=>$location,'stationPublicId'=>$station['public_id'],'devicePublicId'=>$device['public_id']],
      'window'=>['startedAt'=>$from,'endedAt'=>$to],
      'driftSamples'=>[['sampleKey'=>'fixture-'.$device['public_id'],'evidenceHash'=>hash('sha256',$device['public_id'])]],
      'productionErrors'=>[],
    ];
    $sourceFingerprint=hash('sha256',glasses_vision_training_release_json($source));
    $payload=['schema'=>GLASSES_VISION_MODEL_HEALTH_SCHEMA,'sourceFingerprint'=>$sourceFingerprint,'scope'=>$source['scope'],'window'=>$source['window'],'metrics'=>$metrics,'healthState'=>$state];
    $snapshotHash=hash('sha256',glasses_vision_training_release_json($payload));
    $public=glasses_public_id('vision-health');
    $pdo->prepare("INSERT INTO glasses_vision_model_health_snapshots
      (organization_id,public_id,package_id,location_id,station_id,device_id,window_started_at,window_ended_at,source_fingerprint,
       sample_count,observation_count,production_error_count,correction_count,low_confidence_count,critical_drift_count,warning_drift_count,
       mean_confidence,mean_latency_ms,error_rate,correction_rate,low_confidence_rate,health_state,metrics_json,snapshot_hash,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$org,$public,(int)$package['id'],$location,(int)$station['id'],(int)$device['id'],$from,$to,$sourceFingerprint,
        1,10,0,0,0,$state==='critical'?1:0,$state==='watch'?1:0,.80,60.0,0,0,0,$state,
        glasses_vision_training_release_json(['source'=>$source,'metrics'=>$metrics]),$snapshotHash,$actor]);
    return glasses_vision_model_health_row($pdo,$org,$public);
}

$pdo=app_pdo();
v86_assert(glasses_vision_fleet_health_ready($pdo),'V8 fleet-health migration must be installed.');

$slug='vl86-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Fleet Health '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Fleet','Reviewer','Fleet Reviewer']);$actor=(int)$pdo->lastInsertId();

$locations=[];$stations=[];$devices=[];
for($i=1;$i<=3;$i++){
    $pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,?,'Phoenix','AZ','active')")->execute([$org,'Fleet Kitchen '.$i]);$location=(int)$pdo->lastInsertId();$locations[$i]=$location;
    $stations[$i]=kds_station_save($pdo,$org,$location,['name'=>'Vision Line '.$i,'slug'=>'fleet-'.$i.'-'.$slug,'targetSeconds'=>300],$actor);
    $grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$stations[$i]['public_id'],$actor,10);
    $paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V86-'.$i.'-'.$slug,'displayName'=>'Fleet AIR3 '.$i]);
    $devices[$i]=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
}

$baselinePublic='vision-fleet-baseline-'.$slug;$targetPublic='vision-fleet-target-'.$slug;
$baselineHash=hash('sha256','baseline-'.$slug);$targetHash=hash('sha256','target-'.$slug);
$insertPkg=$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector',?,?,'onnx','inmo_air3',?,?,123,'ready',?)");
$insertPkg->execute([$org,$baselinePublic,'Fleet Detector','baseline','https://example.test/baseline.onnx',$baselineHash,$actor]);$baselineId=(int)$pdo->lastInsertId();
$insertPkg->execute([$org,$targetPublic,'Fleet Detector','target','https://example.test/target.onnx',$targetHash,$actor]);$targetId=(int)$pdo->lastInsertId();
$target=glasses_vision_model_package_row($pdo,$org,$targetPublic,false);

$rolloutPublic='vision-fleet-rollout-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_model_rollouts
 (organization_id,public_id,detector_name,target_package_id,baseline_package_id,canary_percent,status,created_by,activated_by,activated_at)
 VALUES (?,?,'ingredient_detector',?,?,25.00,'active',?,?,UTC_TIMESTAMP(6))")
 ->execute([$org,$rolloutPublic,$targetId,$baselineId,$actor,$actor]);

$from=(new DateTimeImmutable('-2 hours',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
$to=(new DateTimeImmutable('-1 hour',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
$s1=v86_snapshot($pdo,$org,$actor,$target,$locations[1],$stations[1],$devices[1],$from,$to,'critical');
$s2=v86_snapshot($pdo,$org,$actor,$target,$locations[2],$stations[2],$devices[2],$from,$to,'critical');
v86_assert(glasses_vision_model_health_verify($pdo,$org,$s1['publicId'])['passed']&&glasses_vision_model_health_verify($pdo,$org,$s2['publicId'])['passed'],'Fixture health snapshots must verify.');

$analysis=glasses_vision_fleet_health_analyze($pdo,$org,['rolloutPublicId'=>$rolloutPublic,'windowStartedAt'=>$from,'windowEndedAt'=>$to],$actor);
v86_assert($analysis['fleetState']==='critical'&&$analysis['recommendation']==='rollback_review','Critical cross-location/device health must recommend governed rollback review.');
v86_assert(($analysis['evidence']['counts']['devices']??0)===2&&($analysis['evidence']['counts']['locations']??0)===2,'Fleet analysis must prove cross-device and cross-location impact.');
v86_assert(glasses_vision_fleet_health_verify($pdo,$org,$analysis['publicId'])['passed'],'Fresh fleet analysis must verify.');
v86_assert((string)v86_one($pdo,"SELECT status FROM glasses_vision_model_rollouts WHERE organization_id=? AND public_id=?",[$org,$rolloutPublic])==='active','Fleet intelligence must never roll back automatically.');

$empty=false;try{glasses_vision_fleet_health_execute_rollback($pdo,$org,$analysis['publicId'],'',$actor);}catch(InvalidArgumentException){$empty=true;}
v86_assert($empty,'Governed rollback must require a documented human reason.');

$s3=v86_snapshot($pdo,$org,$actor,$target,$locations[3],$stations[3],$devices[3],$from,$to,'critical');
v86_assert(glasses_vision_fleet_health_verify($pdo,$org,$s3['publicId'])['passed'],'Late health snapshot must verify.');
v86_assert(glasses_vision_fleet_health_verify($pdo,$org,$analysis['publicId'])['passed'],'Late fleet evidence must not invalidate an earlier immutable analysis.');
$newAnalysis=glasses_vision_fleet_health_analyze($pdo,$org,['rolloutPublicId'=>$rolloutPublic,'windowStartedAt'=>$from,'windowEndedAt'=>$to],$actor);
v86_assert($newAnalysis['publicId']!==$analysis['publicId']&&($newAnalysis['evidence']['counts']['devices']??0)===3,'Late evidence must create a new immutable fleet analysis instead of rewriting history.');

$result=glasses_vision_fleet_health_execute_rollback($pdo,$org,$newAnalysis['publicId'],'Multiple locations show critical target-model degradation.',$actor);
$action=$result['action'];
v86_assert(($result['rollout']['status']??null)==='rolled_back','Explicit governed rollback must use the existing rollout rollback engine.');
v86_assert($action['actionType']==='rollback'&&$action['status']==='completed','Rollback audit action must be durable.');
v86_assert(glasses_vision_fleet_health_action_verify($pdo,$org,$action['publicId'])['passed'],'Rollback action must verify end to end.');

$again=glasses_vision_fleet_health_execute_rollback($pdo,$org,$newAnalysis['publicId'],'Multiple locations show critical target-model degradation.',$actor);
v86_assert($again['action']['publicId']===$action['publicId'],'Governed rollback execution must be idempotent after rollout rollback.');

$reason=(string)v86_one($pdo,"SELECT reason FROM glasses_vision_fleet_health_actions WHERE organization_id=? AND public_id=?",[$org,$action['publicId']]);
$pdo->prepare("UPDATE glasses_vision_fleet_health_actions SET reason='tampered' WHERE organization_id=? AND public_id=?")->execute([$org,$action['publicId']]);
v86_assert(!glasses_vision_fleet_health_action_verify($pdo,$org,$action['publicId'])['passed'],'Rollback reason tampering must be detected.');
$pdo->prepare("UPDATE glasses_vision_fleet_health_actions SET reason=? WHERE organization_id=? AND public_id=?")->execute([$reason,$org,$action['publicId']]);
$pdo->prepare("UPDATE glasses_vision_fleet_health_actions SET actor_user_id=NULL WHERE organization_id=? AND public_id=?")->execute([$org,$action['publicId']]);
$actorTamper=false;
try{$actorTamper=!glasses_vision_fleet_health_action_verify($pdo,$org,$action['publicId'])['passed'];}catch(Throwable){$actorTamper=true;}
v86_assert($actorTamper,'Rollback actor attribution tampering must be detected.');
$pdo->prepare("UPDATE glasses_vision_fleet_health_actions SET actor_user_id=? WHERE organization_id=? AND public_id=?")->execute([$actor,$org,$action['publicId']]);
v86_assert(glasses_vision_fleet_health_action_verify($pdo,$org,$action['publicId'])['passed'],'Restored rollback audit must verify.');

v86_assert((int)v86_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_package' AND relation='fleet_health_analyzed_as' AND to_kind='fleet_health_analysis'",[$org])===2,'Every immutable fleet analysis must attach to model lineage.');
v86_assert((int)v86_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='fleet_health_analysis' AND relation='governed_rollback_as' AND to_kind='fleet_health_action'",[$org])===1,'Explicit rollback must retain fleet-analysis lineage.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-fleet-health.php');
foreach(['fleet_health.analyze','fleet_health.verify','fleet_health.rollback','fleet_health.action.verify'] as $actionName)v86_assert(str_contains($api,$actionName),'V8 fleet-health API missing '.$actionName);
v86_assert(str_contains($page,'Fleet Model Health &amp; Rollback Intelligence'),'Vision Lab must expose Section 6 fleet-health intelligence.');
v86_assert(str_contains($source,"'automaticRollback'=>false")&&str_contains($source,'glasses_vision_model_rollout_rollback'),'Fleet intelligence must recommend by default and use only the governed rollout engine for explicit rollback.');
v86_assert(!str_contains($source,'kds_transition'),'Fleet health intelligence must never mutate KDS truth.');

echo "vision-lab-v8-fleet-health-rollback-ok\n";
