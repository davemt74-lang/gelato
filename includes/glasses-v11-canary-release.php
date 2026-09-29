<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-v11-shadow-validation.php';
require_once __DIR__.'/glasses-vision-models.php';

const GLASSES_V11_CANARY_STAGE_SCHEMA='gelato.vision_v11_canary_stage.v1';
const GLASSES_V11_PRODUCTION_ACCEPTANCE_SCHEMA='gelato.vision_v11_production_acceptance.v1';

function glasses_v11_canary_release_ready(PDO $pdo): bool {
  foreach(['glasses_vision_canary_stage_validations','glasses_vision_production_acceptances'] as $table){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
  }
  return glasses_v11_shadow_validation_ready($pdo)&&glasses_vision_canary_ready($pdo);
}

function glasses_v11_canary_stage_policy(float $stage,array $in=[]): array {
  $stage=round($stage,2);
  $defaults=[
    '5.00'=>['samples'=>20,'devices'=>2,'locations'=>1,'stations'=>1,'operators'=>1],
    '10.00'=>['samples'=>30,'devices'=>3,'locations'=>1,'stations'=>1,'operators'=>1],
    '25.00'=>['samples'=>50,'devices'=>4,'locations'=>1,'stations'=>1,'operators'=>2],
    '50.00'=>['samples'=>80,'devices'=>5,'locations'=>1,'stations'=>1,'operators'=>2],
    '100.00'=>['samples'=>120,'devices'=>5,'locations'=>1,'stations'=>1,'operators'=>3],
  ];
  $key=number_format($stage,2,'.','');if(!isset($defaults[$key]))throw new InvalidArgumentException('V11 canary stage must be 5, 10, 25, 50, or 100 percent.');
  $d=$defaults[$key];
  return [
    'minimumSamplesPerCohort'=>max($d['samples'],(int)($in['minimumSamplesPerCohort']??$d['samples'])),
    'minimumDevicesPerCohort'=>max($d['devices'],(int)($in['minimumDevicesPerCohort']??$d['devices'])),
    'minimumLocations'=>max($d['locations'],(int)($in['minimumLocations']??$d['locations'])),
    'minimumStations'=>max($d['stations'],(int)($in['minimumStations']??$d['stations'])),
    'minimumOperators'=>max($d['operators'],(int)($in['minimumOperators']??$d['operators'])),
    'maximumCorrectionRateDelta'=>max(0.0,(float)($in['maximumCorrectionRateDelta']??0.03)),
    'maximumValidationFailureRateDelta'=>max(0.0,(float)($in['maximumValidationFailureRateDelta']??0.05)),
    'maximumLowConfidenceRateDelta'=>max(0.0,(float)($in['maximumLowConfidenceRateDelta']??0.10)),
    'maximumUnexpectedRateDelta'=>max(0.0,(float)($in['maximumUnexpectedRateDelta']??0.05)),
    'maximumTimeoutRateDelta'=>max(0.0,(float)($in['maximumTimeoutRateDelta']??0.03)),
    'maximumRuntimeErrorRateDelta'=>max(0.0,(float)($in['maximumRuntimeErrorRateDelta']??0.02)),
    'maximumInferenceLatencyRatio'=>max(1.0,(float)($in['maximumInferenceLatencyRatio']??1.35)),
    'maximumBuildDurationRatio'=>max(1.0,(float)($in['maximumBuildDurationRatio']??1.20)),
    'rollbackHoldHours'=>max(24,min(168,(int)($in['rollbackHoldHours']??72))),
  ];
}

function glasses_v11_canary_rollout_context(PDO $pdo,int $org,string $rolloutPublic): array {
  $rollout=glasses_vision_model_rollout_row($pdo,$org,$rolloutPublic,false);
  $rc=glasses_v11_shadow_rc_for_rollout($pdo,$org,$rollout);if($rc===null)throw new InvalidArgumentException('V11 canary governance requires an approved release candidate.');
  $shadow=glasses_v11_shadow_latest_pass($pdo,$org,(int)$rollout['id']);if($shadow===null)throw new InvalidArgumentException('V11 canary governance requires a passing Section 9 shadow validation.');
  if(($shadow['releaseCandidate']['publicId']??'')!==$rc['publicId'])throw new InvalidArgumentException('Shadow validation release candidate does not match rollout target.');
  return ['rollout'=>$rollout,'releaseCandidate'=>$rc,'shadowValidation'=>$shadow];
}

function glasses_v11_canary_stage_metrics(PDO $pdo,int $org,int $rolloutId): array {
  $q=$pdo->prepare("SELECT s.cohort,COUNT(*) samples,COUNT(DISTINCT s.device_id) devices,
    COUNT(DISTINCT d.location_id) locations,COUNT(DISTINCT d.station_id) stations,COUNT(DISTINCT d.paired_by) operators,
    COALESCE(SUM(s.observation_count),0) observations,COALESCE(SUM(s.correction_count),0) corrections,
    COALESCE(SUM(s.low_confidence_count),0) low_confidence,COALESCE(SUM(s.unexpected_count),0) unexpected,
    COALESCE(SUM(s.validation_failed),0) validation_failed,COALESCE(SUM(CASE WHEN s.build_duration_ms IS NOT NULL THEN 1 ELSE 0 END),0) build_samples,
    COALESCE(SUM(s.build_duration_ms),0) build_duration_total,COALESCE(SUM(s.inference_count),0) inferences,
    COALESCE(SUM(s.inference_latency_ms),0) inference_latency_total,COALESCE(SUM(s.timeout_count),0) timeouts,
    COALESCE(SUM(s.runtime_error_count),0) runtime_errors
    FROM glasses_vision_canary_samples s
    JOIN glasses_devices d ON d.id=s.device_id AND d.organization_id=s.organization_id
    WHERE s.organization_id=? AND s.rollout_id=? GROUP BY s.cohort");
  $q->execute([$org,$rolloutId]);$rows=[];
  foreach($q->fetchAll() as $r){
    $base=glasses_vision_canary_metric_row($r);
    $base['locations']=(int)$r['locations'];$base['stations']=(int)$r['stations'];$base['operators']=(int)$r['operators'];
    $rows[(string)$r['cohort']]=$base;
  }
  $empty=glasses_vision_canary_metric_row([]);$empty['locations']=$empty['stations']=$empty['operators']=0;
  return ['baseline'=>$rows['baseline']??$empty,'target'=>$rows['target']??$empty];
}

function glasses_v11_canary_ratio(?float $target,?float $baseline): ?float {
  if($target===null||$baseline===null||$baseline<=0)return null;
  return round($target/$baseline,6);
}

function glasses_v11_canary_stage_row(PDO $pdo,int $org,string $publicId): array {
  $q=$pdo->prepare("SELECT v.*,r.public_id rollout_public_id,rc.public_id rc_public_id,rc.rc_hash,sv.public_id shadow_public_id,sv.validation_hash shadow_hash
    FROM glasses_vision_canary_stage_validations v
    JOIN glasses_vision_model_rollouts r ON r.id=v.rollout_id AND r.organization_id=v.organization_id
    JOIN glasses_vision_model_release_candidates rc ON rc.id=v.release_candidate_id AND rc.organization_id=v.organization_id
    JOIN glasses_vision_shadow_validations sv ON sv.id=v.shadow_validation_id AND sv.organization_id=v.organization_id
    WHERE v.organization_id=? AND v.public_id=? LIMIT 1");
  $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('V11 canary stage validation was not found.');
  return ['schema'=>GLASSES_V11_CANARY_STAGE_SCHEMA,'publicId'=>$r['public_id'],'rolloutPublicId'=>$r['rollout_public_id'],'stagePercent'=>(float)$r['stage_percent'],
    'status'=>$r['status'],'score'=>(int)$r['score'],'stageHash'=>$r['stage_hash'],'releaseCandidate'=>['publicId'=>$r['rc_public_id'],'rcHash'=>$r['rc_hash']],
    'shadowValidation'=>['publicId'=>$r['shadow_public_id'],'validationHash'=>$r['shadow_hash']],'policy'=>json_decode((string)$r['policy_json'],true)?:[],
    'metrics'=>json_decode((string)$r['metrics_json'],true)?:[],'coverage'=>json_decode((string)$r['coverage_json'],true)?:[],
    'result'=>json_decode((string)$r['result_json'],true)?:[],'createdAt'=>$r['created_at']];
}

function glasses_v11_canary_stage_evaluate(PDO $pdo,int $org,string $rolloutPublic,array $input,int $actor): array {
  if(!glasses_v11_canary_release_ready($pdo))throw new RuntimeException('V11 canary-release migration is not installed.');
  $ctx=glasses_v11_canary_rollout_context($pdo,$org,$rolloutPublic);$rollout=$ctx['rollout'];$rc=$ctx['releaseCandidate'];$shadow=$ctx['shadowValidation'];
  if((string)$rollout['status']!=='active')throw new InvalidArgumentException('V11 canary stage evaluation requires an active rollout.');
  $stage=(float)$rollout['canary_percent'];$policy=glasses_v11_canary_stage_policy($stage,is_array($input['policy']??null)?$input['policy']:[]);
  $m=glasses_v11_canary_stage_metrics($pdo,$org,(int)$rollout['id']);$b=$m['baseline'];$t=$m['target'];
  $coverage=[
    'baseline'=>['samples'=>$b['samples'],'devices'=>$b['devices'],'locations'=>$b['locations'],'stations'=>$b['stations'],'operators'=>$b['operators']],
    'target'=>['samples'=>$t['samples'],'devices'=>$t['devices'],'locations'=>$t['locations'],'stations'=>$t['stations'],'operators'=>$t['operators']],
  ];
  $checks=[
    ['key'=>'baseline_samples','passed'=>$b['samples']>=$policy['minimumSamplesPerCohort'],'actual'=>$b['samples'],'required'=>$policy['minimumSamplesPerCohort']],
    ['key'=>'target_samples','passed'=>$t['samples']>=$policy['minimumSamplesPerCohort'],'actual'=>$t['samples'],'required'=>$policy['minimumSamplesPerCohort']],
    ['key'=>'baseline_devices','passed'=>$b['devices']>=$policy['minimumDevicesPerCohort'],'actual'=>$b['devices'],'required'=>$policy['minimumDevicesPerCohort']],
    ['key'=>'target_devices','passed'=>$t['devices']>=$policy['minimumDevicesPerCohort'],'actual'=>$t['devices'],'required'=>$policy['minimumDevicesPerCohort']],
    ['key'=>'target_locations','passed'=>$t['locations']>=$policy['minimumLocations'],'actual'=>$t['locations'],'required'=>$policy['minimumLocations']],
    ['key'=>'target_stations','passed'=>$t['stations']>=$policy['minimumStations'],'actual'=>$t['stations'],'required'=>$policy['minimumStations']],
    ['key'=>'target_operators','passed'=>$t['operators']>=$policy['minimumOperators'],'actual'=>$t['operators'],'required'=>$policy['minimumOperators']],
  ];
  foreach(['correctionRate'=>'maximumCorrectionRateDelta','validationFailureRate'=>'maximumValidationFailureRateDelta','lowConfidenceRate'=>'maximumLowConfidenceRateDelta','unexpectedRate'=>'maximumUnexpectedRateDelta','timeoutRate'=>'maximumTimeoutRateDelta','runtimeErrorRate'=>'maximumRuntimeErrorRateDelta'] as $metric=>$rule){
    $delta=round((float)$t[$metric]-(float)$b[$metric],6);$checks[]=['key'=>$metric.'_delta','passed'=>$delta<=$policy[$rule],'actual'=>$delta,'required'=>$policy[$rule]];
  }
  $latencyRatio=glasses_v11_canary_ratio($t['avgInferenceLatencyMs'],$b['avgInferenceLatencyMs']);
  $buildRatio=glasses_v11_canary_ratio($t['avgBuildDurationMs'],$b['avgBuildDurationMs']);
  $checks[]=['key'=>'inference_latency_ratio','passed'=>$latencyRatio!==null&&$latencyRatio<=$policy['maximumInferenceLatencyRatio'],'actual'=>$latencyRatio,'required'=>$policy['maximumInferenceLatencyRatio']];
  $checks[]=['key'=>'build_duration_ratio','passed'=>$buildRatio!==null&&$buildRatio<=$policy['maximumBuildDurationRatio'],'actual'=>$buildRatio,'required'=>$policy['maximumBuildDurationRatio']];
  $health=glasses_vision_canary_evaluate_and_enforce($pdo,$org,$rolloutPublic);
  $checks[]=['key'=>'canonical_canary_health','passed'=>($health['state']??'')==='healthy'&&!empty($health['promotionEligible']),'actual'=>$health['state']??null,'required'=>'healthy'];
  $drift=glasses_vision_drift_ready($pdo)?glasses_vision_drift_rollout_summary($pdo,$org,$rolloutPublic):['promotionBlocked'=>false];
  $checks[]=['key'=>'production_drift','passed'=>empty($drift['promotionBlocked']),'actual'=>!empty($drift['promotionBlocked']),'required'=>false];

  $passedCount=count(array_filter($checks,static fn($c)=>$c['passed']));$passed=$passedCount===count($checks);$score=(int)round(100*$passedCount/max(1,count($checks)));
  $metrics=['baseline'=>$b,'target'=>$t,'inferenceLatencyRatio'=>$latencyRatio,'buildDurationRatio'=>$buildRatio,'canonicalHealth'=>$health,'drift'=>$drift];
  $result=['passed'=>$passed,'stageAdvanceEligible'=>$passed&&$stage<100.0,'productionAcceptanceEligible'=>$passed&&abs($stage-100.0)<0.0001,'score'=>$score,'checks'=>$checks];
  $identity=['schema'=>GLASSES_V11_CANARY_STAGE_SCHEMA,'rolloutPublicId'=>$rolloutPublic,'stagePercent'=>$stage,'releaseCandidate'=>['publicId'=>$rc['publicId'],'rcHash'=>$rc['rcHash']],
    'shadowValidation'=>['publicId'=>$shadow['publicId'],'validationHash'=>$shadow['validationHash']],'policy'=>$policy,'metrics'=>$metrics,'coverage'=>$coverage,'result'=>$result];
  $hash=hash('sha256',glasses_vision_training_release_json($identity));
  $existing=$pdo->prepare("SELECT public_id FROM glasses_vision_canary_stage_validations WHERE organization_id=? AND stage_hash=? LIMIT 1");$existing->execute([$org,$hash]);$ep=$existing->fetchColumn();
  if($ep!==false)return glasses_v11_canary_stage_row($pdo,$org,(string)$ep);
  $rq=$pdo->prepare("SELECT id FROM glasses_vision_model_release_candidates WHERE organization_id=? AND public_id=? LIMIT 1");$rq->execute([$org,$rc['publicId']]);$rcId=(int)$rq->fetchColumn();
  $sq=$pdo->prepare("SELECT id FROM glasses_vision_shadow_validations WHERE organization_id=? AND public_id=? LIMIT 1");$sq->execute([$org,$shadow['publicId']]);$shadowId=(int)$sq->fetchColumn();
  $public=glasses_public_id('vision-canary-stage');
  $pdo->prepare("INSERT INTO glasses_vision_canary_stage_validations (organization_id,public_id,rollout_id,release_candidate_id,shadow_validation_id,stage_percent,status,score,policy_json,metrics_json,coverage_json,result_json,stage_hash,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$org,$public,(int)$rollout['id'],$rcId,$shadowId,$stage,$passed?'passed':'failed',$score,glasses_vision_training_release_json($policy),glasses_vision_training_release_json($metrics),glasses_vision_training_release_json($coverage),glasses_vision_training_release_json($result),$hash,$actor]);
  glasses_vision_lineage_edge($pdo,$org,'shadow_validation',(string)$shadow['publicId'],(string)$shadow['validationHash'],'canary_validated_at_'.$stage,'canary_stage_validation',$public,$hash,['rolloutPublicId'=>$rolloutPublic,'stagePercent'=>$stage,'passed'=>$passed],$actor);
  return glasses_v11_canary_stage_row($pdo,$org,$public);
}

function glasses_v11_canary_latest_pass(PDO $pdo,int $org,int $rolloutId,float $stage): ?array {
  if(!glasses_v11_canary_release_ready($pdo))return null;
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_canary_stage_validations WHERE organization_id=? AND rollout_id=? AND stage_percent=? AND status='passed' ORDER BY id DESC LIMIT 1");
  $q->execute([$org,$rolloutId,$stage]);$p=$q->fetchColumn();return $p!==false?glasses_v11_canary_stage_row($pdo,$org,(string)$p):null;
}

function glasses_v11_production_acceptance_row(PDO $pdo,int $org,string $publicId): array {
  $q=$pdo->prepare("SELECT a.*,r.public_id rollout_public_id,rc.public_id rc_public_id,rc.rc_hash,v.public_id stage_public_id,v.stage_hash
    FROM glasses_vision_production_acceptances a
    JOIN glasses_vision_model_rollouts r ON r.id=a.rollout_id AND r.organization_id=a.organization_id
    JOIN glasses_vision_model_release_candidates rc ON rc.id=a.release_candidate_id AND rc.organization_id=a.organization_id
    JOIN glasses_vision_canary_stage_validations v ON v.id=a.stage_validation_id AND v.organization_id=a.organization_id
    WHERE a.organization_id=? AND a.public_id=? LIMIT 1");
  $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('V11 production acceptance was not found.');
  return ['schema'=>GLASSES_V11_PRODUCTION_ACCEPTANCE_SCHEMA,'publicId'=>$r['public_id'],'status'=>$r['status'],'acceptanceHash'=>$r['acceptance_hash'],'rolloutPublicId'=>$r['rollout_public_id'],
    'releaseCandidate'=>['publicId'=>$r['rc_public_id'],'rcHash'=>$r['rc_hash']],'stageValidation'=>['publicId'=>$r['stage_public_id'],'stageHash'=>$r['stage_hash']],
    'manifest'=>json_decode((string)$r['manifest_json'],true)?:[],'acceptedAt'=>$r['accepted_at']];
}

function glasses_v11_production_accept(PDO $pdo,int $org,string $rolloutPublic,int $actor): array {
  if(!glasses_v11_canary_release_ready($pdo))throw new RuntimeException('V11 canary-release migration is not installed.');
  $ctx=glasses_v11_canary_rollout_context($pdo,$org,$rolloutPublic);$rollout=$ctx['rollout'];$rc=$ctx['releaseCandidate'];$shadow=$ctx['shadowValidation'];
  if((string)$rollout['status']!=='active'||abs((float)$rollout['canary_percent']-100.0)>0.0001)throw new InvalidArgumentException('Production acceptance requires an active 100% rollout.');
  $stage=glasses_v11_canary_latest_pass($pdo,$org,(int)$rollout['id'],100.0);if($stage===null||empty($stage['result']['productionAcceptanceEligible']))throw new InvalidArgumentException('Production acceptance requires a passing 100% canary validation.');
  $manifest=['schema'=>GLASSES_V11_PRODUCTION_ACCEPTANCE_SCHEMA,'rolloutPublicId'=>$rolloutPublic,'releaseCandidate'=>['publicId'=>$rc['publicId'],'rcHash'=>$rc['rcHash']],
    'shadowValidation'=>['publicId'=>$shadow['publicId'],'validationHash'=>$shadow['validationHash']],'finalCanaryValidation'=>['publicId'=>$stage['publicId'],'stageHash'=>$stage['stageHash']],
    'targetPackage'=>$rc['modelPackage'],'acceptedCanaryPercent'=>100.0,'governance'=>['automaticRollbackRemainsEnabled'=>true,'continuousDriftMonitoringRequired'=>true]];
  $hash=hash('sha256',glasses_vision_training_release_json($manifest));
  $existing=$pdo->prepare("SELECT public_id,acceptance_hash FROM glasses_vision_production_acceptances WHERE organization_id=? AND rollout_id=? LIMIT 1");$existing->execute([$org,(int)$rollout['id']]);$e=$existing->fetch();
  if($e){if(!hash_equals((string)$e['acceptance_hash'],$hash))throw new InvalidArgumentException('Rollout already has a different immutable production acceptance.');return glasses_v11_production_acceptance_row($pdo,$org,(string)$e['public_id']);}
  $rq=$pdo->prepare("SELECT id FROM glasses_vision_model_release_candidates WHERE organization_id=? AND public_id=? LIMIT 1");$rq->execute([$org,$rc['publicId']]);$rcId=(int)$rq->fetchColumn();
  $vq=$pdo->prepare("SELECT id FROM glasses_vision_canary_stage_validations WHERE organization_id=? AND public_id=? LIMIT 1");$vq->execute([$org,$stage['publicId']]);$stageId=(int)$vq->fetchColumn();
  $public=glasses_public_id('vision-production-acceptance');
  $pdo->prepare("INSERT INTO glasses_vision_production_acceptances (organization_id,public_id,rollout_id,release_candidate_id,stage_validation_id,status,manifest_json,acceptance_hash,accepted_by) VALUES (?,?,?,?,?,'accepted',?,?,?)")
    ->execute([$org,$public,(int)$rollout['id'],$rcId,$stageId,glasses_vision_training_release_json($manifest),$hash,$actor]);
  glasses_vision_lineage_edge($pdo,$org,'canary_stage_validation',(string)$stage['publicId'],(string)$stage['stageHash'],'accepted_for_production','production_acceptance',$public,$hash,['rolloutPublicId'=>$rolloutPublic],$actor);
  return glasses_v11_production_acceptance_row($pdo,$org,$public);
}

function glasses_v11_canary_catalog(PDO $pdo,int $org): array {
  if(!glasses_v11_canary_release_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_V11_CANARY_STAGE_SCHEMA,'stageValidations'=>[],'productionAcceptances'=>[]];
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_canary_stage_validations WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
  $stages=array_map(fn($p)=>glasses_v11_canary_stage_row($pdo,$org,(string)$p),$q->fetchAll(PDO::FETCH_COLUMN));
  $a=$pdo->prepare("SELECT public_id FROM glasses_vision_production_acceptances WHERE organization_id=? ORDER BY id DESC LIMIT 50");$a->execute([$org]);
  $accept=array_map(fn($p)=>glasses_v11_production_acceptance_row($pdo,$org,(string)$p),$a->fetchAll(PDO::FETCH_COLUMN));
  return ['ready'=>true,'schema'=>GLASSES_V11_CANARY_STAGE_SCHEMA,'stageValidations'=>$stages,'productionAcceptances'=>$accept];
}
