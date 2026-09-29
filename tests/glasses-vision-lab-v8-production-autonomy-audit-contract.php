<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-autonomy-audit.php';

function v87_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v87_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

function v87_snapshot(PDO $pdo,int $org,int $actor,array $package,int $location,array $station,array $device,string $from,string $to,string $state): array
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
      'driftSamples'=>[['sampleKey'=>'v87-'.$device['public_id'],'evidenceHash'=>hash('sha256',$device['public_id'])]],
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
v87_assert(glasses_vision_autonomy_ready($pdo),'V8 autonomy-audit migration must be installed.');

$slug='vl87-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Autonomy Audit '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Audit','Reviewer','Audit Reviewer']);$actor=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Runtime Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Runtime Line','slug'=>'v87-runtime-'.$slug,'targetSeconds'=>300],$actor);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Audit Pizza',?,1)")->execute([$org,$section,'audit-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Pepperoni',?,'food','verified')")->execute([$org,'pepperoni-'.$slug]);$ingredient=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Pepperoni',0,1,1)")->execute([$item,$ingredient]);
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();
$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V87-RUNTIME-'.$slug,'displayName'=>'Audit Runtime AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
$sessionId=(int)v87_one($pdo,"SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);

glasses_vision_profile_save($pdo,$org,['ingredientId'=>$ingredient,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','minimumConfidence'=>.82],$actor);
$modelPublic='vision-autonomy-target-'.$slug;$modelHash=hash('sha256','autonomy-target-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Autonomy Detector','v8.7','onnx','inmo_air3','https://example.test/v87-target.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$target=glasses_vision_model_package_row($pdo,$org,$modelPublic,false);
$assignmentKey=hash('sha256','autonomy-assignment-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_assignments
 (organization_id,device_id,build_session_id,assignment_key,detector_name,package_id,action,selection,rollout_status,compatibility_json,issued_at)
 VALUES (?,?,?,?,?,?,'apply','stable','active','{\"compatible\":true}',UTC_TIMESTAMP(6))")
 ->execute([$org,(int)$device['id'],$sessionId,$assignmentKey,'ingredient_detector',$modelId]);

$policy=glasses_vision_confidence_policy_create($pdo,$org,[
 'policyKey'=>'autonomy-safe-'.$slug,'detectorName'=>'ingredient_detector','modelPackagePublicId'=>$modelPublic,'defaultThreshold'=>.70,
 'contextRules'=>['insufficient_context'=>['delta'=>.10,'requireHumanReview'=>true]]
],$actor);
glasses_vision_confidence_policy_status($pdo,$org,$policy['publicId'],'active',$actor);

$decision=glasses_vision_confidence_decide($pdo,$org,[
 'devicePublicId'=>$device['public_id'],'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','confidence'=>.90
],null);
v87_assert($decision['decision']==='below_threshold_hold','Fixture must create a governed confidence hold.');

$before=glasses_vision_autonomy_capture($pdo,$org,['confidenceDecisionPublicId'=>$decision['publicId']],$actor);
v87_assert($before['authorityState']==='governed_runtime'&&$before['outcome']==='below_threshold_hold','Runtime audit must explain the pre-recovery hold.');
v87_assert(glasses_vision_autonomy_verify($pdo,$org,$before['publicId'])['passed'],'Fresh runtime autonomy audit must verify.');
$beforeReplay=glasses_vision_autonomy_replay($pdo,$org,$before['publicId']);
v87_assert($beforeReplay['replayOnly']===true&&$beforeReplay['changesProductionState']===false,'Autonomy replay must be explicitly non-executing.');

$recovery=glasses_vision_active_perception_plan($pdo,$org,$decision['publicId'],$device,null);
$recovery=glasses_vision_active_perception_acknowledge($pdo,$org,$recovery['publicId'],$device);
$recovery=glasses_vision_active_perception_complete($pdo,$org,$recovery['publicId'],$device,'success',['frameKey'=>'v87-retry-'.$slug,'confidence'=>.96]);
v87_assert($recovery['status']==='completed','Fixture recovery must complete.');

$after=glasses_vision_autonomy_capture($pdo,$org,['confidenceDecisionPublicId'=>$decision['publicId']],$actor);
v87_assert($after['publicId']!==$before['publicId']&&$after['authorityState']==='bounded_recovery'&&$after['outcome']==='recovery_completed','New recovery evidence must create a new immutable runtime audit.');
v87_assert(glasses_vision_autonomy_verify($pdo,$org,$after['publicId'])['passed'],'Post-recovery autonomy audit must verify.');
v87_assert(glasses_vision_autonomy_verify($pdo,$org,$before['publicId'])['passed'],'Earlier runtime audit must remain valid after later recovery evidence.');
$replayedBefore=glasses_vision_autonomy_replay($pdo,$org,$before['publicId']);
v87_assert($replayedBefore['outcome']==='below_threshold_hold','Replay must preserve what the system knew before recovery.');

$explanation=(string)v87_one($pdo,"SELECT explanation_json FROM glasses_vision_autonomy_audits WHERE organization_id=? AND public_id=?",[$org,$after['publicId']]);
$pdo->prepare("UPDATE glasses_vision_autonomy_audits SET explanation_json='{}' WHERE organization_id=? AND public_id=?")->execute([$org,$after['publicId']]);
v87_assert(!glasses_vision_autonomy_verify($pdo,$org,$after['publicId'])['passed'],'Autonomy explanation tampering must be detected.');
$pdo->prepare("UPDATE glasses_vision_autonomy_audits SET explanation_json=? WHERE organization_id=? AND public_id=?")->execute([$explanation,$org,$after['publicId']]);

$baselinePublic='vision-autonomy-baseline-'.$slug;$baselineHash=hash('sha256','autonomy-baseline-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Autonomy Detector','baseline','onnx','inmo_air3','https://example.test/v87-baseline.onnx',?,123,'ready',?)")
 ->execute([$org,$baselinePublic,$baselineHash,$actor]);$baselineId=(int)$pdo->lastInsertId();

$fleetLocations=[];$fleetStations=[];$fleetDevices=[];
for($i=1;$i<=2;$i++){
    $pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,?,'Phoenix','AZ','active')")->execute([$org,'Fleet Audit Kitchen '.$i]);$fleetLocations[$i]=(int)$pdo->lastInsertId();
    $fleetStations[$i]=kds_station_save($pdo,$org,$fleetLocations[$i],['name'=>'Fleet Audit Line '.$i,'slug'=>'v87-fleet-'.$i.'-'.$slug,'targetSeconds'=>300],$actor);
    $g=glasses_create_pairing_grant($pdo,$org,$fleetLocations[$i],(string)$fleetStations[$i]['public_id'],$actor,10);
    $p=glasses_pair_device($pdo,(string)$g['pairingCode'],['hardwareIdentifier'=>'AIR3-V87-FLEET-'.$i.'-'.$slug,'displayName'=>'Fleet Audit AIR3 '.$i]);
    $fleetDevices[$i]=glasses_authenticate_token($pdo,(string)$p['deviceToken']);
}
$rolloutPublic='vision-autonomy-rollout-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_model_rollouts
 (organization_id,public_id,detector_name,target_package_id,baseline_package_id,canary_percent,status,created_by,activated_by,activated_at)
 VALUES (?,?,'ingredient_detector',?,?,25.00,'active',?,?,UTC_TIMESTAMP(6))")
 ->execute([$org,$rolloutPublic,$modelId,$baselineId,$actor,$actor]);

$from=(new DateTimeImmutable('-2 hours',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
$to=(new DateTimeImmutable('-1 hour',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
v87_snapshot($pdo,$org,$actor,$target,$fleetLocations[1],$fleetStations[1],$fleetDevices[1],$from,$to,'critical');
v87_snapshot($pdo,$org,$actor,$target,$fleetLocations[2],$fleetStations[2],$fleetDevices[2],$from,$to,'critical');
$fleet=glasses_vision_fleet_health_analyze($pdo,$org,['rolloutPublicId'=>$rolloutPublic,'windowStartedAt'=>$from,'windowEndedAt'=>$to],$actor);
v87_assert($fleet['recommendation']==='rollback_review','Fixture fleet analysis must require rollback review.');

$fleetBefore=glasses_vision_autonomy_capture($pdo,$org,['fleetHealthAnalysisPublicId'=>$fleet['publicId']],$actor);
v87_assert($fleetBefore['authorityState']==='human_required'&&$fleetBefore['outcome']==='rollback_review','Fleet audit must explain that rollback requires human authority.');
v87_assert((string)v87_one($pdo,"SELECT status FROM glasses_vision_model_rollouts WHERE organization_id=? AND public_id=?",[$org,$rolloutPublic])==='active','Capturing/replaying a fleet audit must not execute rollback.');
v87_assert(glasses_vision_autonomy_replay($pdo,$org,$fleetBefore['publicId'])['changesProductionState']===false,'Fleet replay must remain audit-only.');

$rolled=glasses_vision_fleet_health_execute_rollback($pdo,$org,$fleet['publicId'],'Cross-location critical degradation requires baseline restore.',$actor);
v87_assert(($rolled['rollout']['status']??null)==='rolled_back','Fixture governed rollback must execute through Section 6.');
$fleetAfter=glasses_vision_autonomy_capture($pdo,$org,['fleetHealthAnalysisPublicId'=>$fleet['publicId']],$actor);
v87_assert($fleetAfter['publicId']!==$fleetBefore['publicId']&&$fleetAfter['authorityState']==='human_authorized'&&$fleetAfter['outcome']==='rollback_executed','Explicit rollback must create a new fleet autonomy audit.');
v87_assert(glasses_vision_autonomy_verify($pdo,$org,$fleetAfter['publicId'])['passed'],'Post-rollback fleet audit must verify.');
v87_assert(glasses_vision_autonomy_verify($pdo,$org,$fleetBefore['publicId'])['passed'],'Pre-rollback fleet audit must remain replayable after explicit rollback.');
v87_assert(glasses_vision_autonomy_replay($pdo,$org,$fleetBefore['publicId'])['outcome']==='rollback_review','Historical fleet replay must preserve the pre-rollback recommendation.');

v87_assert((string)v87_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line])==='queued','Autonomy capture/replay must never mutate KDS lifecycle.');
v87_assert((string)v87_one($pdo,"SELECT status FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic])==='active','Autonomy capture/replay must never mutate build lifecycle.');
v87_assert((int)v87_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND relation='explained_as' AND to_kind='autonomy_audit'",[$org])===4,'Every immutable autonomy snapshot must retain subject lineage.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-autonomy-audit.php');
foreach(['autonomy_audit.capture','autonomy_audit.verify','autonomy_audit.replay'] as $action)v87_assert(str_contains($api,$action),'V8 autonomy-audit API missing '.$action);
v87_assert(str_contains($page,'Production Autonomy Decision Ledger &amp; Explainability'),'Vision Lab must expose the V8 autonomy decision ledger.');
v87_assert(str_contains($source,"'replayOnly'=>true")&&str_contains($source,"'changesProductionState'=>false"),'Replay must explicitly declare itself non-executing.');
foreach(['glasses_vision_model_rollout_rollback','kds_transition','glasses_build_confirm'] as $forbidden)v87_assert(!str_contains($source,$forbidden),'Autonomy audit service must not execute production actions.');

echo "vision-lab-v8-production-autonomy-audit-ok\n";
