<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-v11-model-release-candidates.php';
require_once __DIR__.'/glasses-vision-models.php';

const GLASSES_V11_SHADOW_VALIDATION_SCHEMA='gelato.vision_v11_shadow_validation.v1';

function glasses_v11_shadow_validation_ready(PDO $pdo): bool {
  foreach(['glasses_vision_shadow_runs','glasses_vision_shadow_events','glasses_vision_shadow_validations'] as $table){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
  }
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='glasses_vision_shadow_runs' AND column_name='release_candidate_id'");$q->execute();
  return (int)$q->fetchColumn()===1&&glasses_v11_model_rc_ready($pdo);
}

function glasses_v11_shadow_policy(array $in=[]): array {
  return [
    'minimumFrames'=>max(30,(int)($in['minimumFrames']??100)),
    'minimumCompletedRuns'=>max(1,(int)($in['minimumCompletedRuns']??3)),
    'minimumDevices'=>max(1,(int)($in['minimumDevices']??2)),
    'minimumLocations'=>max(1,(int)($in['minimumLocations']??1)),
    'minimumStations'=>max(1,(int)($in['minimumStations']??1)),
    'minimumOperators'=>max(1,(int)($in['minimumOperators']??1)),
    'maximumCriticalMismatchRate'=>max(0.0,min(1.0,(float)($in['maximumCriticalMismatchRate']??0.02))),
    'maximumDisagreementRate'=>max(0.0,min(1.0,(float)($in['maximumDisagreementRate']??0.20))),
    'maximumLatencyDeltaMs'=>max(0.0,(float)($in['maximumLatencyDeltaMs']??20.0)),
    'maximumChallengerTimeoutRate'=>max(0.0,min(1.0,(float)($in['maximumChallengerTimeoutRate']??0.01))),
    'maximumChallengerRuntimeErrorRate'=>max(0.0,min(1.0,(float)($in['maximumChallengerRuntimeErrorRate']??0.01))),
    'requireChallengerCorrectionWinRate'=>max(0.0,min(1.0,(float)($in['requireChallengerCorrectionWinRate']??0.50))),
  ];
}

function glasses_v11_shadow_rc_for_rollout(PDO $pdo,int $org,array $rollout): ?array {
  if(!glasses_v11_model_rc_ready($pdo))return null;
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_release_candidates WHERE organization_id=? AND model_package_id=? AND status='approved' ORDER BY id DESC LIMIT 1");
  $q->execute([$org,(int)$rollout['target_package_id']]);$p=$q->fetchColumn();
  return $p!==false?glasses_v11_model_rc_public(glasses_v11_model_rc_row($pdo,$org,(string)$p)):null;
}

function glasses_v11_shadow_assign(PDO $pdo,array $device,string $sessionPublicId,string $detector,array $policy=[]): array {
  if(!glasses_v11_shadow_validation_ready($pdo))throw new RuntimeException('V11 shadow-validation migration is not installed.');
  $org=(int)$device['organization_id'];$session=glasses_build_session_row($pdo,$org,$sessionPublicId,false);glasses_build_assert_device_session($device,$session);
  $detector=glasses_vision_normalize_detector($detector);$rollout=glasses_vision_shadow_rollout_for_device($pdo,$device,$detector);
  if(!$rollout)return ['schema'=>'gelato.vision_v11_shadow_assignment.v1','action'=>'hold','reason'=>'no_eligible_draft_challenger'];
  $rc=glasses_v11_shadow_rc_for_rollout($pdo,$org,$rollout);
  if($rc===null)return glasses_vision_shadow_assignment($pdo,$device,$sessionPublicId,$detector);
  $verify=glasses_v11_model_rc_verify($pdo,$org,(string)$rc['publicId']);if(!$verify['passed'])return ['schema'=>'gelato.vision_v11_shadow_assignment.v1','action'=>'hold','reason'=>'release_candidate_integrity_failed'];
  $base=glasses_vision_shadow_assignment($pdo,$device,$sessionPublicId,$detector);if(($base['action']??'')!=='shadow')return $base;
  $rq=$pdo->prepare("SELECT id FROM glasses_vision_model_release_candidates WHERE organization_id=? AND public_id=? LIMIT 1");$rq->execute([$org,$rc['publicId']]);$rcId=(int)$rq->fetchColumn();
  $q=$pdo->prepare("UPDATE glasses_vision_shadow_runs SET release_candidate_id=?,location_id=?,station_id=?,operator_user_id=?,v11_policy_json=? WHERE organization_id=? AND public_id=? AND release_candidate_id IS NULL");
  $q->execute([$rcId,(int)$device['location_id'],$device['station_id']!==null?(int)$device['station_id']:null,$device['paired_by']!==null?(int)$device['paired_by']:null,
    glasses_vision_training_release_json(glasses_v11_shadow_policy($policy)),$org,$base['runPublicId']]);
  $base['schema']='gelato.vision_v11_shadow_assignment.v1';$base['releaseCandidate']=$rc;$base['nonConsequential']=true;
  return $base;
}

function glasses_v11_shadow_report(PDO $pdo,array $device,string $sessionPublicId,array $input): array {
  $result=glasses_vision_shadow_report($pdo,$device,$sessionPublicId,$input);
  $org=(int)$device['organization_id'];$runPublic=trim((string)($input['shadowRunPublicId']??''));$frameKey=trim((string)($input['frameKey']??''));
  $q=$pdo->prepare("SELECT e.id FROM glasses_vision_shadow_events e JOIN glasses_vision_shadow_runs r ON r.id=e.shadow_run_id AND r.organization_id=e.organization_id WHERE e.organization_id=? AND r.public_id=? AND e.frame_key=? LIMIT 1");
  $q->execute([$org,$runPublic,$frameKey]);$eventId=(int)$q->fetchColumn();
  if($eventId>0&&empty($result['idempotent'])){
    $cl=array_key_exists('championLatencyMs',$input)?max(0,(float)$input['championLatencyMs']):null;
    $xl=array_key_exists('challengerLatencyMs',$input)?max(0,(float)$input['challengerLatencyMs']):null;
    $pdo->prepare("UPDATE glasses_vision_shadow_events SET champion_latency_ms=?,challenger_latency_ms=?,champion_timeout=?,challenger_timeout=?,champion_runtime_error=?,challenger_runtime_error=? WHERE organization_id=? AND id=?")
      ->execute([$cl,$xl,!empty($input['championTimeout'])?1:0,!empty($input['challengerTimeout'])?1:0,!empty($input['championRuntimeError'])?1:0,!empty($input['challengerRuntimeError'])?1:0,$org,$eventId]);
  }
  return $result;
}

function glasses_v11_shadow_validation_row(PDO $pdo,int $org,string $publicId): array {
  $q=$pdo->prepare("SELECT v.*,r.public_id rollout_public_id,rc.public_id rc_public_id,rc.rc_hash
    FROM glasses_vision_shadow_validations v
    JOIN glasses_vision_model_rollouts r ON r.id=v.rollout_id AND r.organization_id=v.organization_id
    JOIN glasses_vision_model_release_candidates rc ON rc.id=v.release_candidate_id AND rc.organization_id=v.organization_id
    WHERE v.organization_id=? AND v.public_id=? LIMIT 1");
  $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('V11 shadow validation was not found.');
  return ['schema'=>GLASSES_V11_SHADOW_VALIDATION_SCHEMA,'publicId'=>$r['public_id'],'status'=>$r['status'],'score'=>(int)$r['score'],'validationHash'=>$r['validation_hash'],
    'rolloutPublicId'=>$r['rollout_public_id'],'releaseCandidate'=>['publicId'=>$r['rc_public_id'],'rcHash'=>$r['rc_hash']],
    'policy'=>json_decode((string)$r['policy_json'],true)?:[],'metrics'=>json_decode((string)$r['metrics_json'],true)?:[],
    'coverage'=>json_decode((string)$r['coverage_json'],true)?:[],'result'=>json_decode((string)$r['result_json'],true)?:[],'createdAt'=>$r['created_at']];
}

function glasses_v11_shadow_validate(PDO $pdo,int $org,string $rolloutPublic,array $input,int $actor): array {
  if(!glasses_v11_shadow_validation_ready($pdo))throw new RuntimeException('V11 shadow-validation migration is not installed.');
  $rollout=glasses_vision_model_rollout_row($pdo,$org,$rolloutPublic,false);$rc=glasses_v11_shadow_rc_for_rollout($pdo,$org,$rollout);
  if($rc===null)throw new InvalidArgumentException('V11 shadow validation requires an approved release candidate.');
  $policy=glasses_v11_shadow_policy(is_array($input['policy']??null)?$input['policy']:[]);
  $q=$pdo->prepare("SELECT r.id,r.public_id,r.frame_count,r.disagreement_count,r.correction_champion_wins,r.correction_challenger_wins,r.correction_ties,r.critical_mismatch_count,r.device_id,r.location_id,r.station_id,r.operator_user_id,
    COALESCE(AVG(e.champion_latency_ms),0) champion_latency,COALESCE(AVG(e.challenger_latency_ms),0) challenger_latency,
    COALESCE(SUM(e.champion_timeout),0) champion_timeouts,COALESCE(SUM(e.challenger_timeout),0) challenger_timeouts,
    COALESCE(SUM(e.champion_runtime_error),0) champion_runtime_errors,COALESCE(SUM(e.challenger_runtime_error),0) challenger_runtime_errors
    FROM glasses_vision_shadow_runs r LEFT JOIN glasses_vision_shadow_events e ON e.shadow_run_id=r.id AND e.organization_id=r.organization_id
    WHERE r.organization_id=? AND r.rollout_id=? AND r.release_candidate_id=(SELECT id FROM glasses_vision_model_release_candidates WHERE organization_id=? AND public_id=? LIMIT 1) AND r.status='completed'
    GROUP BY r.id ORDER BY r.id");
  $q->execute([$org,(int)$rollout['id'],$org,$rc['publicId']]);$runs=$q->fetchAll();
  $frames=$disagreements=$critical=$champWins=$challWins=$ties=$challTimeouts=$challErrors=0;$champLatencyWeighted=$challLatencyWeighted=0.0;
  $devices=$locations=$stations=$operators=[];$runEvidence=[];
  foreach($runs as $r){$n=(int)$r['frame_count'];$frames+=$n;$disagreements+=(int)$r['disagreement_count'];$critical+=(int)$r['critical_mismatch_count'];$champWins+=(int)$r['correction_champion_wins'];$challWins+=(int)$r['correction_challenger_wins'];$ties+=(int)$r['correction_ties'];
    $challTimeouts+=(int)$r['challenger_timeouts'];$challErrors+=(int)$r['challenger_runtime_errors'];$champLatencyWeighted+=(float)$r['champion_latency']*$n;$challLatencyWeighted+=(float)$r['challenger_latency']*$n;
    $devices[(int)$r['device_id']]=true;if($r['location_id']!==null)$locations[(int)$r['location_id']]=true;if($r['station_id']!==null)$stations[(int)$r['station_id']]=true;if($r['operator_user_id']!==null)$operators[(int)$r['operator_user_id']]=true;
    $runEvidence[]=['publicId'=>$r['public_id'],'frames'=>$n,'critical'=>(int)$r['critical_mismatch_count'],'disagreements'=>(int)$r['disagreement_count']];
  }
  $decisions=$champWins+$challWins+$ties;$metrics=['completedRuns'=>count($runs),'frames'=>$frames,'disagreementRate'=>$frames?round($disagreements/$frames,6):1.0,'criticalMismatchRate'=>$frames?round($critical/$frames,6):1.0,
    'championMeanLatencyMs'=>$frames?round($champLatencyWeighted/$frames,3):null,'challengerMeanLatencyMs'=>$frames?round($challLatencyWeighted/$frames,3):null,
    'latencyDeltaMs'=>$frames?round(($challLatencyWeighted-$champLatencyWeighted)/$frames,3):null,'challengerTimeoutRate'=>$frames?round($challTimeouts/$frames,6):1.0,
    'challengerRuntimeErrorRate'=>$frames?round($challErrors/$frames,6):1.0,'challengerCorrectionWinRate'=>$decisions?round($challWins/$decisions,6):0.0];
  $coverage=['devices'=>count($devices),'locations'=>count($locations),'stations'=>count($stations),'operators'=>count($operators),'runs'=>$runEvidence];
  $checks=[
    ['key'=>'frames','passed'=>$frames>=$policy['minimumFrames'],'actual'=>$frames,'required'=>$policy['minimumFrames']],
    ['key'=>'runs','passed'=>count($runs)>=$policy['minimumCompletedRuns'],'actual'=>count($runs),'required'=>$policy['minimumCompletedRuns']],
    ['key'=>'devices','passed'=>count($devices)>=$policy['minimumDevices'],'actual'=>count($devices),'required'=>$policy['minimumDevices']],
    ['key'=>'locations','passed'=>count($locations)>=$policy['minimumLocations'],'actual'=>count($locations),'required'=>$policy['minimumLocations']],
    ['key'=>'stations','passed'=>count($stations)>=$policy['minimumStations'],'actual'=>count($stations),'required'=>$policy['minimumStations']],
    ['key'=>'operators','passed'=>count($operators)>=$policy['minimumOperators'],'actual'=>count($operators),'required'=>$policy['minimumOperators']],
    ['key'=>'critical_mismatch','passed'=>$metrics['criticalMismatchRate']<=$policy['maximumCriticalMismatchRate'],'actual'=>$metrics['criticalMismatchRate'],'required'=>$policy['maximumCriticalMismatchRate']],
    ['key'=>'disagreement','passed'=>$metrics['disagreementRate']<=$policy['maximumDisagreementRate'],'actual'=>$metrics['disagreementRate'],'required'=>$policy['maximumDisagreementRate']],
    ['key'=>'latency','passed'=>$metrics['latencyDeltaMs']!==null&&$metrics['latencyDeltaMs']<=$policy['maximumLatencyDeltaMs'],'actual'=>$metrics['latencyDeltaMs'],'required'=>$policy['maximumLatencyDeltaMs']],
    ['key'=>'timeouts','passed'=>$metrics['challengerTimeoutRate']<=$policy['maximumChallengerTimeoutRate'],'actual'=>$metrics['challengerTimeoutRate'],'required'=>$policy['maximumChallengerTimeoutRate']],
    ['key'=>'runtime_errors','passed'=>$metrics['challengerRuntimeErrorRate']<=$policy['maximumChallengerRuntimeErrorRate'],'actual'=>$metrics['challengerRuntimeErrorRate'],'required'=>$policy['maximumChallengerRuntimeErrorRate']],
    ['key'=>'correction_wins','passed'=>$metrics['challengerCorrectionWinRate']>=$policy['requireChallengerCorrectionWinRate'],'actual'=>$metrics['challengerCorrectionWinRate'],'required'=>$policy['requireChallengerCorrectionWinRate']],
  ];
  $passedCount=count(array_filter($checks,fn($c)=>$c['passed']));$passed=$passedCount===count($checks);$score=(int)round(100*$passedCount/count($checks));
  $result=['passed'=>$passed,'canaryEligible'=>$passed,'score'=>$score,'checks'=>$checks,'nonConsequential'=>true];
  $identity=['schema'=>GLASSES_V11_SHADOW_VALIDATION_SCHEMA,'rolloutPublicId'=>$rolloutPublic,'releaseCandidatePublicId'=>$rc['publicId'],'rcHash'=>$rc['rcHash'],'policy'=>$policy,'metrics'=>$metrics,'coverage'=>$coverage,'result'=>$result];
  $hash=hash('sha256',glasses_vision_training_release_json($identity));$existing=$pdo->prepare("SELECT public_id FROM glasses_vision_shadow_validations WHERE organization_id=? AND validation_hash=? LIMIT 1");$existing->execute([$org,$hash]);$ep=$existing->fetchColumn();
  if($ep!==false)return glasses_v11_shadow_validation_row($pdo,$org,(string)$ep);
  $rq=$pdo->prepare("SELECT id FROM glasses_vision_model_release_candidates WHERE organization_id=? AND public_id=? LIMIT 1");$rq->execute([$org,$rc['publicId']]);$rcId=(int)$rq->fetchColumn();
  $public=glasses_public_id('vision-shadow-validation');
  $pdo->prepare("INSERT INTO glasses_vision_shadow_validations (organization_id,public_id,rollout_id,release_candidate_id,status,score,policy_json,metrics_json,coverage_json,result_json,validation_hash,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$org,$public,(int)$rollout['id'],$rcId,$passed?'passed':'failed',$score,glasses_vision_training_release_json($policy),glasses_vision_training_release_json($metrics),glasses_vision_training_release_json($coverage),glasses_vision_training_release_json($result),$hash,$actor]);
  glasses_vision_lineage_edge($pdo,$org,'model_release_candidate',(string)$rc['publicId'],(string)$rc['rcHash'],'shadow_validated_as','shadow_validation',$public,$hash,['rolloutPublicId'=>$rolloutPublic,'passed'=>$passed],$actor);
  return glasses_v11_shadow_validation_row($pdo,$org,$public);
}

function glasses_v11_shadow_latest_pass(PDO $pdo,int $org,int $rolloutId): ?array {
  if(!glasses_v11_shadow_validation_ready($pdo))return null;
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_shadow_validations WHERE organization_id=? AND rollout_id=? AND status='passed' ORDER BY id DESC LIMIT 1");$q->execute([$org,$rolloutId]);$p=$q->fetchColumn();
  return $p!==false?glasses_v11_shadow_validation_row($pdo,$org,(string)$p):null;
}

function glasses_v11_shadow_catalog(PDO $pdo,int $org): array {
  if(!glasses_v11_shadow_validation_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_V11_SHADOW_VALIDATION_SCHEMA,'validations'=>[]];
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_shadow_validations WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
  return ['ready'=>true,'schema'=>GLASSES_V11_SHADOW_VALIDATION_SCHEMA,'validations'=>array_map(fn($p)=>glasses_v11_shadow_validation_row($pdo,$org,(string)$p),$q->fetchAll(PDO::FETCH_COLUMN))];
}
