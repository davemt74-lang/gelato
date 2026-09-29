<?php
declare(strict_types=1);

require __DIR__.'/glasses-v11-shadow-production-validation-contract.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-v11-canary-release.php';

function v1110_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v1110_count(PDO $pdo,string $sql,array $args=[]):int{$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}

v1110_assert(glasses_v11_canary_release_ready($pdo),'V11 governed-canary migration must be installed.');
v1110_assert(glasses_v11_canary_stage_policy(5)['minimumSamplesPerCohort']===20,'5% stage must retain a meaningful evidence floor.');
v1110_assert(glasses_v11_canary_stage_policy(100)['minimumSamplesPerCohort']>=120,'100% production acceptance must require materially deeper evidence than 5%.');

$locationName='V11 Canary Kitchen '.bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status,is_primary,sort_order) VALUES (?,?, 'Phoenix','AZ','active',0,20)")->execute([$org,$locationName]);$location=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'V11 Canary Line','slug'=>'v11-canary-'.bin2hex(random_bytes(3)),'targetSeconds'=>300,'sortOrder'=>20],$actor);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'V11 Canary',?,'active',20)")->execute([$org,'v11-canary-'.bin2hex(random_bytes(3))]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'V11 Canary Pizza',?,1)")->execute([$org,$section,'v11-canary-pizza-'.bin2hex(random_bytes(3))]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',12.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'V11 Canary Cheese',?,'food','verified')")->execute([$org,'v11-canary-cheese-'.bin2hex(random_bytes(3))]);$ingredient=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'V11 Canary Cheese',0,1,1)")->execute([$item,$ingredient]);
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'V11','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$extraUsers=[];
for($i=1;$i<=2;$i++){
  $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture-hash','Canary',? ,?,'active')")
    ->execute(['v1110-'.$i.'-'.bin2hex(random_bytes(3)).'@example.test','Operator '.$i,'Canary Operator '.$i]);
  $extraUsers[]=(int)$pdo->lastInsertId();
}
$operators=array_merge([$actor],$extraUsers);
$devices=[];
for($i=0;$i<6;$i++){
  $owner=$operators[$i%count($operators)];
  $grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$owner,10);
  $paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
    'hardwareIdentifier'=>'V11-CANARY-'.bin2hex(random_bytes(5)),'displayName'=>'V11 Canary '.$i,'platform'=>'inmo_air3',
    'sdkVersion'=>'2.1','appVersion'=>'2.1','capabilities'=>['visionModelRuntimes'=>['onnx']]
  ]);
  $devices[]=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
}
$session=glasses_build_start($pdo,$devices[0],$kdsPublic,null);$sessionRow=glasses_build_session_row($pdo,$org,(string)$session['publicId'],false);

$rolloutId=(int)(function()use($pdo,$org,$rollout){$q=$pdo->prepare("SELECT id FROM glasses_vision_model_rollouts WHERE organization_id=? AND public_id=? LIMIT 1");$q->execute([$org,$rollout['publicId']]);return $q->fetchColumn();})();
$rcId=(int)(function()use($pdo,$org,$approved){$q=$pdo->prepare("SELECT id FROM glasses_vision_model_release_candidates WHERE organization_id=? AND public_id=? LIMIT 1");$q->execute([$org,$approved['publicId']]);return $q->fetchColumn();})();
$pdo->prepare("UPDATE glasses_vision_model_rollouts SET status='active',canary_percent=5,activated_by=?,activated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$actor,$org,$rolloutId]);

$shadowPublic='vision-shadow-validation-v1110-'.bin2hex(random_bytes(3));$shadowHash=hash('sha256','shadow-v1110-'.$shadowPublic);
$pdo->prepare("INSERT INTO glasses_vision_shadow_validations (organization_id,public_id,rollout_id,release_candidate_id,status,score,policy_json,metrics_json,coverage_json,result_json,validation_hash,created_by) VALUES (?,?,?,?, 'passed',100,'{}','{}','{}',?, ?,?)")
  ->execute([$org,$shadowPublic,$rolloutId,$rcId,glasses_vision_training_release_json(['passed'=>true,'canaryEligible'=>true]),$shadowHash,$actor]);

$pkgBaseline=(int)(function()use($pdo,$org,$championPublic){$q=$pdo->prepare("SELECT id FROM glasses_vision_model_packages WHERE organization_id=? AND public_id=?");$q->execute([$org,$championPublic]);return $q->fetchColumn();})();
$pkgTarget=(int)(function()use($pdo,$org,$challengerPublic){$q=$pdo->prepare("SELECT id FROM glasses_vision_model_packages WHERE organization_id=? AND public_id=?");$q->execute([$org,$challengerPublic]);return $q->fetchColumn();})();

$assignmentIds=['baseline'=>[],'target'=>[]];
foreach(['baseline','baseline','target','target'] as $idx=>$cohort){
  $device=$devices[$idx];$packageId=$cohort==='target'?$pkgTarget:$pkgBaseline;
  $key=hash('sha256','v1110-assign-'.$cohort.'-'.$idx);
  $pdo->prepare("INSERT INTO glasses_vision_model_assignments
    (organization_id,device_id,build_session_id,assignment_key,detector_name,rollout_id,package_id,action,selection,rollout_status,canary_percent,canary_bucket,compatibility_json)
    VALUES (?,?,?,?,?,?,?,?,?,'active',5,?,?)")
    ->execute([$org,(int)$device['id'],(int)$sessionRow['id'],$key,'ingredient_detector',$rolloutId,$packageId,'apply',$cohort,$cohort==='target'?1.0:90.0,'{"compatible":true,"reasons":[]}']);
  $assignmentIds[$cohort][]=(int)$pdo->lastInsertId();
}
foreach(['baseline','target'] as $cohort){
  for($i=1;$i<=20;$i++){
    $a=$assignmentIds[$cohort][$i%2];$device=$cohort==='baseline'?$devices[$i%2]:$devices[2+($i%2)];
    $pdo->prepare("INSERT INTO glasses_vision_canary_samples
      (organization_id,rollout_id,assignment_id,device_id,build_session_id,sample_key,cohort,observation_count,correction_count,low_confidence_count,unexpected_count,validation_failed,build_duration_ms,inference_count,inference_latency_ms,timeout_count,runtime_error_count)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$org,$rolloutId,$a,(int)$device['id'],(int)$sessionRow['id'],'v1110-'.$cohort.'-'.$i,$cohort,10,0,1,0,0,$cohort==='target'?102000:100000,100,$cohort==='target'?5200:5000,0,0]);
  }
}

$blocked=false;
try{glasses_vision_model_rollout_advance($pdo,$org,(string)$rollout['publicId'],10,$actor);}catch(InvalidArgumentException $e){$blocked=str_contains($e->getMessage(),'passing validation for the current stage');}
v1110_assert($blocked,'V11 rollout must not leave 5% without a passing 5% stage attestation.');

$stage5=glasses_v11_canary_stage_evaluate($pdo,$org,(string)$rollout['publicId'],[],$actor);
v1110_assert($stage5['status']==='passed'&&$stage5['stagePercent']===5.0&&!empty($stage5['result']['stageAdvanceEligible']),'Healthy 5% evidence must produce a passing immutable stage attestation.');
v1110_assert(strlen((string)$stage5['stageHash'])===64&&$stage5['releaseCandidate']['publicId']===$approved['publicId'],'Stage attestation must be hash-addressed and RC-bound.');
$stage5Again=glasses_v11_canary_stage_evaluate($pdo,$org,(string)$rollout['publicId'],[],$actor);
v1110_assert($stage5Again['publicId']===$stage5['publicId'],'Identical stage evidence must deduplicate.');

$advanced=glasses_vision_model_rollout_advance($pdo,$org,(string)$rollout['publicId'],10,$actor);
v1110_assert((float)$advanced['canaryPercent']===10.0,'Passing 5% attestation must unlock only the next governed stage.');

$rollback=glasses_vision_canary_auto_rollback($pdo,$org,(string)$rollout['publicId'],['rollbackReasons'=>['runtimeErrorRate_severe_regression']]);
v1110_assert($rollback['status']==='rolled_back','Severe V11 production evidence must preserve immediate automatic rollback.');
$hq=$pdo->prepare("SELECT hold_until,TIMESTAMPDIFF(HOUR,NOW(6),hold_until) hours_remaining FROM glasses_vision_canary_package_holds WHERE organization_id=? AND package_id=? LIMIT 1");$hq->execute([$org,$pkgTarget]);$hold=$hq->fetch();
v1110_assert($hold&&(int)$hold['hours_remaining']>=70,'V11 automatic rollback must impose approximately a 72-hour package hold.');

$acceptRollout=glasses_vision_model_rollout_create($pdo,$org,[
  'targetPackagePublicId'=>$challengerPublic,'baselinePackagePublicId'=>$championPublic,'canaryPercent'=>100,'notes'=>'V11 final acceptance fixture.'
],$actor);
$acceptRolloutId=(int)(function()use($pdo,$org,$acceptRollout){$q=$pdo->prepare("SELECT id FROM glasses_vision_model_rollouts WHERE organization_id=? AND public_id=?");$q->execute([$org,$acceptRollout['publicId']]);return $q->fetchColumn();})();
$pdo->prepare("UPDATE glasses_vision_model_rollouts SET status='active',canary_percent=100,activated_by=?,activated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$actor,$org,$acceptRolloutId]);
$shadow2='vision-shadow-validation-v1110-final-'.bin2hex(random_bytes(3));$shadow2Hash=hash('sha256',$shadow2);
$pdo->prepare("INSERT INTO glasses_vision_shadow_validations (organization_id,public_id,rollout_id,release_candidate_id,status,score,policy_json,metrics_json,coverage_json,result_json,validation_hash,created_by) VALUES (?,?,?,?, 'passed',100,'{}','{}','{}',?, ?,?)")
  ->execute([$org,$shadow2,$acceptRolloutId,$rcId,glasses_vision_training_release_json(['passed'=>true,'canaryEligible'=>true]),$shadow2Hash,$actor]);

$stage100Public='vision-canary-stage-v1110-'.bin2hex(random_bytes(3));$stage100Hash=hash('sha256',$stage100Public);
$policy100=glasses_v11_canary_stage_policy(100);
$pdo->prepare("INSERT INTO glasses_vision_canary_stage_validations (organization_id,public_id,rollout_id,release_candidate_id,shadow_validation_id,stage_percent,status,score,policy_json,metrics_json,coverage_json,result_json,stage_hash,created_by)
 SELECT ?,?,?,?,id,100,'passed',100,?,'{}','{}',?,?,? FROM glasses_vision_shadow_validations WHERE organization_id=? AND public_id=? LIMIT 1")
 ->execute([$org,$stage100Public,$acceptRolloutId,$rcId,glasses_vision_training_release_json($policy100),glasses_vision_training_release_json(['passed'=>true,'stageAdvanceEligible'=>false,'productionAcceptanceEligible'=>true,'score'=>100,'checks'=>[]]),$stage100Hash,$actor,$org,$shadow2]);

$acceptance=glasses_v11_production_accept($pdo,$org,(string)$acceptRollout['publicId'],$actor);
v1110_assert($acceptance['status']==='accepted'&&strlen((string)$acceptance['acceptanceHash'])===64,'100% passing stage must create immutable production acceptance.');
v1110_assert(($acceptance['manifest']['governance']['automaticRollbackRemainsEnabled']??false)===true,'Production acceptance must not disable automatic rollback.');
$acceptanceAgain=glasses_v11_production_accept($pdo,$org,(string)$acceptRollout['publicId'],$actor);
v1110_assert($acceptanceAgain['publicId']===$acceptance['publicId'],'Production acceptance must be idempotent.');
v1110_assert(v1110_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='canary_stage_validation' AND from_public_id=? AND relation='accepted_for_production'",[$org,$stage100Public])===1,'Final acceptance lineage is required.');

$source=file_get_contents(__DIR__.'/../includes/glasses-v11-canary-release.php');
$models=file_get_contents(__DIR__.'/../includes/glasses-vision-models.php');
$migration=file_get_contents(__DIR__.'/../database/20261206_v11_governed_canary_release.sql');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
v1110_assert(str_contains($migration,'CREATE TABLE glasses_vision_canary_stage_validations')&&str_contains($migration,'CREATE TABLE glasses_vision_production_acceptances'),'Section 10 must persist stage attestations and final acceptance.');
v1110_assert(str_contains($api,'canary_stage.evaluate')&&str_contains($api,'production_acceptance.create'),'Vision Lab API must expose Section 10 governance.');
v1110_assert(str_contains($page,'V11 Governed Canary Release &amp; Automatic Safety Rollback'),'Vision Lab must expose Section 10.');
v1110_assert(str_contains($models,'V11 canary advancement requires a passing validation for the current stage.'),'Canonical rollout advancement must enforce V11 stage attestation.');
v1110_assert(str_contains($models,'$holdHours=72'),'V11 automatic rollback must extend the package hold.');
foreach(['glasses_vision_model_rollout_activate(','glasses_vision_model_rollout_advance('] as $forbidden)v1110_assert(!str_contains($source,$forbidden),'Section 10 evaluator must not itself activate or advance rollouts: '.$forbidden);

echo "glasses-v11-governed-canary-release-ok\n";
