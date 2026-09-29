<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-mining.php';
require_once __DIR__.'/glasses-production-evidence.php';
require_once __DIR__.'/glasses-annotation-workbench.php';

const GLASSES_V11_HARD_EXAMPLE_SCHEMA='gelato.vision_v11_hard_example_mining.v1';

function glasses_v11_mining_ready(PDO $pdo): bool {
  foreach(['media_id','correction_id','training_session_id','source_kind','training_value_json'] as $column){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='glasses_vision_mined_candidates' AND column_name=?");
    $q->execute([$column]);if((int)$q->fetchColumn()!==1)return false;
  }
  return glasses_vision_mining_ready($pdo)&&glasses_v11_annotation_ready($pdo);
}

function glasses_v11_mining_policy(array $requested=[]): array {
  return [
    'minimumScore'=>max(1,min(100,(int)($requested['minimumScore']??60))),
    'maxOpenPerRun'=>max(1,min(1000,(int)($requested['maxOpenPerRun']??100))),
    'maxOpenPerOperator'=>max(1,min(100,(int)($requested['maxOpenPerOperator']??20))),
    'maxOpenPerRecipeStep'=>max(1,min(100,(int)($requested['maxOpenPerRecipeStep']??20))),
    'correctionRecurrenceBonus'=>max(0,min(20,(int)($requested['correctionRecurrenceBonus']??3))),
    'disagreementBonus'=>max(0,min(20,(int)($requested['disagreementBonus']??10))),
    'validationFailureBonus'=>max(0,min(20,(int)($requested['validationFailureBonus']??12))),
  ];
}

function glasses_v11_mining_base_score(string $kind,string $correctionType='',?string $validation=null): int {
  if($kind==='correction'){
    return match($correctionType){
      'false_negative'=>96,'false_positive'=>92,'ingredient'=>90,'step'=>88,'combined'=>86,'bbox'=>82,'label'=>80,default=>75,
    };
  }
  return match((string)$validation){
    'needs_correction'=>88,'not_ready_for_final_validation'=>86,'rework_failure'=>90,'final_failure'=>92,'insufficient'=>76,default=>65,
  };
}

function glasses_v11_mining_candidate_key(string $sourceKind,string $sourcePublicId): string {
  return hash('sha256',GLASSES_V11_HARD_EXAMPLE_SCHEMA.'|'.$sourceKind.'|'.$sourcePublicId);
}

function glasses_v11_mining_source_rows(PDO $pdo,int $org): array {
  $rows=[];
  $mq=$pdo->prepare("SELECT m.id media_id,m.public_id media_public_id,m.sample_id,m.training_session_id,m.operator_user_id,m.recipe_step_key,m.validation_outcome,m.training_eligibility,m.quality_state,m.metadata_json,
      ts.public_id training_session_public_id,tp.public_id training_program_public_id,d.public_id device_public_id,ks.public_id station_public_id,s.canonical_label
    FROM glasses_vision_training_media m
    LEFT JOIN glasses_training_sessions ts ON ts.id=m.training_session_id
    LEFT JOIN glasses_training_assignments ta ON ta.id=m.training_assignment_id
    LEFT JOIN glasses_training_programs tp ON tp.id=m.training_program_id
    LEFT JOIN glasses_devices d ON d.id=m.device_id
    LEFT JOIN kds_stations ks ON ks.id=ts.station_id
    LEFT JOIN glasses_vision_training_samples s ON s.id=m.sample_id
    WHERE m.organization_id=? AND m.status='active' AND m.training_session_id IS NOT NULL AND m.training_eligibility<>'excluded'
      AND (m.validation_outcome IN ('needs_correction','not_ready_for_final_validation','rework_failure','final_failure','insufficient') OR m.quality_state='poor')
    ORDER BY m.id");
  $mq->execute([$org]);
  foreach($mq->fetchAll() as $r){$r['source_kind']='production_evidence';$rows[]=$r;}

  $cq=$pdo->prepare("SELECT c.id correction_id,c.public_id correction_public_id,c.media_id,c.sample_id,c.correction_type,c.status correction_status,c.submitted_by,
      c.proposed_label,c.reason,m.training_session_id,m.operator_user_id,m.recipe_step_key,m.validation_outcome,m.training_eligibility,
      ts.public_id training_session_public_id,tp.public_id training_program_public_id,d.public_id device_public_id,ks.public_id station_public_id
    FROM glasses_vision_annotation_corrections c
    JOIN glasses_vision_training_media m ON m.id=c.media_id
    LEFT JOIN glasses_training_sessions ts ON ts.id=m.training_session_id
    LEFT JOIN glasses_training_programs tp ON tp.id=m.training_program_id
    LEFT JOIN glasses_devices d ON d.id=m.device_id
    LEFT JOIN kds_stations ks ON ks.id=ts.station_id
    WHERE c.organization_id=? AND m.status='active' AND m.training_eligibility<>'excluded'
    ORDER BY c.id");
  $cq->execute([$org]);
  foreach($cq->fetchAll() as $r){$r['source_kind']='correction';$rows[]=$r;}
  return $rows;
}

function glasses_v11_mining_build(PDO $pdo,int $org,array $policy): array {
  $sources=glasses_v11_mining_source_rows($pdo,$org);
  $recurrence=[];
  foreach($sources as $s){
    $operator=(int)($s['operator_user_id']??0);$step=(string)($s['recipe_step_key']??'unknown');
    $key=$operator.'|'.$step;$recurrence[$key]=($recurrence[$key]??0)+1;
  }
  $prepared=[];$fingerprintParts=[];
  foreach($sources as $s){
    $kind=(string)$s['source_kind'];$sourcePublic=$kind==='correction'?(string)$s['correction_public_id']:(string)$s['media_public_id'];
    $base=glasses_v11_mining_base_score($kind,(string)($s['correction_type']??''),$s['validation_outcome']??null);
    $operator=(int)($s['operator_user_id']??0);$step=(string)($s['recipe_step_key']??'unknown');
    $repeat=max(0,($recurrence[$operator.'|'.$step]??1)-1);
    $recurrenceBonus=min(15,$repeat*(int)$policy['correctionRecurrenceBonus']);
    $disagreement=((string)($s['correction_status']??''))==='needs_adjudication'?(int)$policy['disagreementBonus']:0;
    $validation=in_array((string)($s['validation_outcome']??''),['needs_correction','not_ready_for_final_validation','rework_failure','final_failure'],true)?(int)$policy['validationFailureBonus']:0;
    $poor=(string)($s['quality_state']??'')==='poor'?6:0;
    $score=min(100,$base+$recurrenceBonus+$disagreement+$validation+$poor);
    $fingerprintParts[]=['kind'=>$kind,'publicId'=>$sourcePublic,'score'=>$score,'status'=>$s['correction_status']??null,'validation'=>$s['validation_outcome']??null];
    if($score<(int)$policy['minimumScore'])continue;
    $value=[
      'schema'=>GLASSES_V11_HARD_EXAMPLE_SCHEMA,'sourceKind'=>$kind,'sourcePublicId'=>$sourcePublic,'baseScore'=>$base,
      'recurrenceCount'=>$recurrence[$operator.'|'.$step]??1,'recurrenceBonus'=>$recurrenceBonus,
      'disagreementBonus'=>$disagreement,'validationFailureBonus'=>$validation,'poorMediaBonus'=>$poor,
      'operatorUserId'=>$operator?:null,'recipeStepKey'=>$step!=='unknown'?$step:null,'validationOutcome'=>$s['validation_outcome']??null,
      'trainingEligibility'=>$s['training_eligibility']??'review'
    ];
    $prepared[]=['source'=>$s,'sourcePublicId'=>$sourcePublic,'candidateKey'=>glasses_v11_mining_candidate_key($kind,$sourcePublic),'score'=>$score,'value'=>$value];
  }
  usort($fingerprintParts,static fn($a,$b)=>strcmp($a['kind'].'|'.$a['publicId'],$b['kind'].'|'.$b['publicId']));
  $fingerprint=hash('sha256',glasses_vision_training_release_json($fingerprintParts));
  usort($prepared,static fn($a,$b)=>$b['score']<=>$a['score']?:strcmp($a['candidateKey'],$b['candidateKey']));

  $operatorOpen=[];$stepOpen=[];$open=0;$suppressed=0;
  foreach($prepared as &$x){
    $s=$x['source'];$operator=(string)((int)($s['operator_user_id']??0));$step=(string)($s['recipe_step_key']??'unknown');
    $status='open';$reason=null;
    if($open>=(int)$policy['maxOpenPerRun']){$status='suppressed';$reason='run_limit';}
    elseif(($operatorOpen[$operator]??0)>=(int)$policy['maxOpenPerOperator']){$status='suppressed';$reason='operator_dominance_cap';}
    elseif(($stepOpen[$step]??0)>=(int)$policy['maxOpenPerRecipeStep']){$status='suppressed';$reason='recipe_step_dominance_cap';}
    if($status==='open'){$open++;$operatorOpen[$operator]=($operatorOpen[$operator]??0)+1;$stepOpen[$step]=($stepOpen[$step]??0)+1;}else{$suppressed++;}
    $x['status']=$status;if($reason)$x['value']['suppressionReason']=$reason;
  } unset($x);

  return ['schema'=>GLASSES_V11_HARD_EXAMPLE_SCHEMA,'policy'=>$policy,'sourceFingerprint'=>$fingerprint,
    'summary'=>['sourceEvents'=>count($sources),'eligibleCandidates'=>count($prepared),'open'=>$open,'suppressed'=>$suppressed],
    'candidates'=>$prepared,
    'governance'=>['automaticDatasetInclusion'=>false,'automaticRetraining'=>false,'automaticRollout'=>false,'humanReviewRequired'=>true]
  ];
}

function glasses_v11_mining_run(PDO $pdo,int $org,array $requested,int $actor): array {
  if(!glasses_v11_mining_ready($pdo))throw new RuntimeException('V11 hard-example mining migration is not installed.');
  $policy=glasses_v11_mining_policy($requested);$result=glasses_v11_mining_build($pdo,$org,$policy);
  $runHash=hash('sha256',glasses_vision_training_release_json($result));
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_mining_runs WHERE organization_id=? AND run_hash=? LIMIT 1");$q->execute([$org,$runHash]);$existing=$q->fetchColumn();
  if($existing!==false)return glasses_vision_mining_run_row($pdo,$org,(string)$existing);

  return glasses_transaction($pdo,function()use($pdo,$org,$actor,$policy,$result,$runHash):array{
    $public=glasses_public_id('vision-v11-mining-run');
    $pdo->prepare("INSERT INTO glasses_vision_mining_runs (organization_id,public_id,source_fingerprint,policy_json,result_json,run_hash,created_by) VALUES (?,?,?,?,?,?,?)")
      ->execute([$org,$public,$result['sourceFingerprint'],json_encode($policy,JSON_UNESCAPED_SLASHES),json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$runHash,$actor]);
    $runId=(int)$pdo->lastInsertId();
    $insert=$pdo->prepare("INSERT INTO glasses_vision_mined_candidates
      (organization_id,public_id,mining_run_id,production_error_id,source_kind,media_id,correction_id,training_session_id,candidate_key,candidate_hash,candidate_type,score,status,cluster_key,model_package_id,predicted_component_key,expected_component_key,reasons_json,lineage_json,training_value_json)
      VALUES (?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach($result['candidates'] as $x){
      $s=$x['source'];$candidateType=$s['source_kind']==='correction'?'correction_hard_example':'production_hard_example';
      $clusterParts=['sourceKind'=>$s['source_kind'],'operatorUserId'=>$s['operator_user_id']??null,'recipeStepKey'=>$s['recipe_step_key']??null,'validationOutcome'=>$s['validation_outcome']??null,'correctionType'=>$s['correction_type']??null];
      $cluster=hash('sha256',glasses_vision_training_release_json($clusterParts));
      $hashBody=['candidateKey'=>$x['candidateKey'],'score'=>$x['score'],'status'=>$x['status'],'trainingValue'=>$x['value'],'cluster'=>$cluster];
      $candidateHash=hash('sha256',glasses_vision_training_release_json($hashBody));
      $insert->execute([$org,glasses_public_id('vision-mined'),$runId,$s['source_kind'],(int)$s['media_id'],$s['correction_id']??null,$s['training_session_id']??null,$x['candidateKey'],$candidateHash,$candidateType,$x['score'],$x['status'],$cluster,null,null,null,json_encode($x['value'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),null,json_encode($x['value'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
    }
    return glasses_vision_mining_run_row($pdo,$org,$public);
  });
}

function glasses_v11_mining_candidates(PDO $pdo,int $org,string $status='all',int $limit=200): array {
  if(!glasses_v11_mining_ready($pdo))return [];
  $limit=max(1,min(1000,$limit));$args=[$org];$sql="SELECT c.*,m.public_id media_public_id,ac.public_id correction_public_id,ts.public_id training_session_public_id,u.display_name operator_name
    FROM glasses_vision_mined_candidates c
    LEFT JOIN glasses_vision_training_media m ON m.id=c.media_id
    LEFT JOIN glasses_vision_annotation_corrections ac ON ac.id=c.correction_id
    LEFT JOIN glasses_training_sessions ts ON ts.id=c.training_session_id
    LEFT JOIN users u ON u.id=ts.user_id
    WHERE c.organization_id=? AND c.source_kind<>'production_error'";
  if($status!=='all'){$allowed=['open','suppressed','dismissed'];if(!in_array($status,$allowed,true))throw new InvalidArgumentException('Mined-candidate status is invalid.');$sql.=" AND c.status=?";$args[]=$status;}
  $sql.=" ORDER BY c.score DESC,c.id DESC LIMIT ".$limit;$q=$pdo->prepare($sql);$q->execute($args);
  return array_map(static fn($r)=>[
    'publicId'=>$r['public_id'],'candidateKey'=>$r['candidate_key'],'candidateHash'=>$r['candidate_hash'],'candidateType'=>$r['candidate_type'],
    'sourceKind'=>$r['source_kind'],'mediaPublicId'=>$r['media_public_id'],'correctionPublicId'=>$r['correction_public_id'],'trainingSessionPublicId'=>$r['training_session_public_id'],
    'operatorName'=>$r['operator_name'],'score'=>(int)$r['score'],'status'=>$r['status'],'clusterKey'=>$r['cluster_key'],
    'trainingValue'=>json_decode((string)($r['training_value_json']??'{}'),true)?:[],'dismissedReason'=>$r['dismissed_reason'],'createdAt'=>$r['created_at']
  ],$q->fetchAll());
}

function glasses_v11_mining_dismiss(PDO $pdo,int $org,string $public,string $reason,int $actor): array {
  $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('Dismissal reason is required.');
  return glasses_transaction($pdo,function()use($pdo,$org,$public,$reason,$actor):array{
    $q=$pdo->prepare("SELECT status,source_kind FROM glasses_vision_mined_candidates WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
    $q->execute([$org,$public]);$r=$q->fetch();if(!$r||$r['source_kind']==='production_error')throw new InvalidArgumentException('V11 mined candidate was not found.');
    if($r['status']!=='dismissed')$pdo->prepare("UPDATE glasses_vision_mined_candidates SET status='dismissed',dismissed_by=?,dismissed_reason=?,dismissed_at=NOW(6) WHERE organization_id=? AND public_id=?")->execute([$actor,$reason,$org,$public]);
    foreach(glasses_v11_mining_candidates($pdo,$org,'dismissed',1000) as $row)if($row['publicId']===$public)return $row;
    throw new RuntimeException('Dismissed V11 candidate could not be reloaded.');
  });
}

function glasses_v11_mining_catalog(PDO $pdo,int $org): array {
  return ['schema'=>GLASSES_V11_HARD_EXAMPLE_SCHEMA,'ready'=>glasses_v11_mining_ready($pdo),'candidates'=>glasses_v11_mining_candidates($pdo,$org,'all',200)];
}
