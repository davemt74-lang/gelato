<?php
declare(strict_types=1);

// Reuse the complete Section 6 governed experiment/review fixture, then add the
// continuous-learning ancestry required by Section 7.
require __DIR__.'/glasses-vision-lab-v7-champion-challenger-review-contract.php';
require_once __DIR__.'/../includes/glasses-vision-promotion.php';

function v77_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v77_count(PDO $pdo,string $sql,array $a=[]):int{$q=$pdo->prepare($sql);$q->execute($a);return (int)$q->fetchColumn();}

v77_assert(glasses_vision_promotion_ready($pdo),'V7 governed-promotion migration must be installed.');

$productionErrorPublic='vision-prod-error-'.$slug;
$productionErrorHash=hash('sha256','production-error-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_production_errors
 (organization_id,public_id,event_key,event_hash,source_type,error_type,outcome,predicted_component_key,expected_component_key,confidence,context_json,actor_user_id,occurred_at)
 VALUES (?,?,?,?,?,'misclassification','reclassified','ingredient:sausage','ingredient:pepperoni',.94,'{}',?,NOW(6))")
 ->execute([$org,$productionErrorPublic,'promotion-error-'.$slug,$productionErrorHash,'fixture',$actor]);
$productionErrorId=(int)$pdo->lastInsertId();

$miningResult=[
 'schema'=>GLASSES_VISION_MINING_SCHEMA,
 'policy'=>glasses_vision_mining_policy([]),
 'sourceFingerprint'=>hash('sha256','promotion-source-'.$slug),
 'sourceEvents'=>[['publicId'=>$productionErrorPublic,'eventHash'=>$productionErrorHash]],
 'summary'=>['sourceEvents'=>1,'eligibleCandidates'=>1,'open'=>1,'suppressed'=>0],
 'candidates'=>[],
 'governance'=>['automaticDatasetInclusion'=>false,'automaticRetraining'=>false,'automaticRollout'=>false],
];
$miningRunPublic='vision-mining-run-promotion-'.$slug;
$miningRunHash=hash('sha256',glasses_vision_training_release_json($miningResult));
$pdo->prepare("INSERT INTO glasses_vision_mining_runs
 (organization_id,public_id,source_fingerprint,policy_json,result_json,run_hash,created_by)
 VALUES (?,?,?,?,?,?,?)")
 ->execute([$org,$miningRunPublic,$miningResult['sourceFingerprint'],json_encode($miningResult['policy'],JSON_UNESCAPED_SLASHES),json_encode($miningResult,JSON_UNESCAPED_SLASHES),$miningRunHash,$actor]);
$miningRunId=(int)$pdo->lastInsertId();

$candidatePublic='vision-mined-promotion-'.$slug;
$candidateHash=hash('sha256','promotion-candidate-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_mined_candidates
 (organization_id,public_id,mining_run_id,production_error_id,candidate_key,candidate_hash,candidate_type,score,status,cluster_key,predicted_component_key,expected_component_key,reasons_json)
 VALUES (?,?,?,?,?,?, 'misclassification',99,'open',?,'ingredient:sausage','ingredient:pepperoni','{}')")
 ->execute([$org,$candidatePublic,$miningRunId,$productionErrorId,hash('sha256','candidate-key-'.$slug),$candidateHash,hash('sha256','cluster-'.$slug)]);
$candidateId=(int)$pdo->lastInsertId();

$batchManifest=[
 'schema'=>GLASSES_VISION_RETRAINING_SCHEMA,
 'miningRunPublicId'=>$miningRunPublic,
 'miningRunHash'=>$miningRunHash,
 'policy'=>glasses_vision_retraining_policy([]),
 'items'=>[['candidatePublicId'=>$candidatePublic,'productionErrorPublicId'=>$productionErrorPublic,'samplePublicId'=>null,'mediaPublicId'=>null,'eligibility'=>'eligible','eligibilityReason'=>null,'evidenceHash'=>hash('sha256','batch-evidence-'.$slug)]],
 'governance'=>['requiresExplicitReview'=>true,'automaticDatasetInclusion'=>false,'automaticTraining'=>false,'automaticRollout'=>false],
];
$batchHash=hash('sha256',glasses_vision_training_release_json($batchManifest));
$batchPublic='vision-retraining-batch-promotion-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_retraining_batches
 (organization_id,public_id,mining_run_id,status,policy_json,manifest_json,batch_hash,dataset_id,created_by,built_by,built_at)
 VALUES (?,?,?,'built',?,?,?,?,?,?,NOW(6))")
 ->execute([$org,$batchPublic,$miningRunId,json_encode($batchManifest['policy'],JSON_UNESCAPED_SLASHES),json_encode($batchManifest,JSON_UNESCAPED_SLASHES),$batchHash,$datasetId,$actor,$actor]);
$batchId=(int)$pdo->lastInsertId();

$batchEvidenceHash=(string)$batchManifest['items'][0]['evidenceHash'];
$pdo->prepare("INSERT INTO glasses_vision_retraining_batch_items
 (organization_id,public_id,batch_id,mined_candidate_id,production_error_id,training_sample_id,training_media_id,eligibility_status,eligibility_reason,decision,decision_reason,evidence_hash,reviewed_by,reviewed_at)
 VALUES (?,?,?,?,?,NULL,NULL,'eligible',NULL,'include','Reviewed production correction.',?,?,NOW(6))")
 ->execute([$org,'vision-retraining-item-promotion-'.$slug,$batchId,$candidateId,$productionErrorId,$batchEvidenceHash,$actor]);

$failedBlocked=false;
try{
 glasses_vision_promotion_authorize($pdo,$org,(string)$failed['publicId'],['rationale'=>'Should not pass.'],$actor);
}catch(InvalidArgumentException){$failedBlocked=true;}
v77_assert($failedBlocked,'Failed Section 6 evidence reviews must never authorize promotion.');

$promotion=glasses_vision_promotion_authorize($pdo,$org,(string)$passed['publicId'],[
 'rationale'=>'Production failures drove a reviewed retraining dataset and the challenger passed all evidence gates.'
],$actor);
v77_assert($promotion['status']==='authorized','Passing review must create an authorization, not activate a rollout.');
v77_assert(preg_match('/^[a-f0-9]{64}$/',$promotion['promotionHash'])===1,'Promotion authorization must be hash-addressed.');
v77_assert(($promotion['audit']['productionErrors'][0]['productionErrorHash']??null)===$productionErrorHash,'Audit must preserve exact production-error hash.');
v77_assert(($promotion['audit']['productionErrors'][0]['candidateHash']??null)===$candidateHash,'Audit must preserve exact mined-candidate hash.');
v77_assert(($promotion['audit']['productionErrors'][0]['batchEvidenceHash']??null)===$batchEvidenceHash,'Audit must preserve exact retraining evidence hash.');
v77_assert(($promotion['audit']['evidenceReview']['reviewHash']??null)===$passed['reviewHash'],'Promotion audit must bind the exact passing evidence review.');
v77_assert(!empty($promotion['integrity']['passed']),'Fresh promotion authorization must verify end to end: '.json_encode($promotion['integrity']['checks'],JSON_UNESCAPED_SLASHES));
v77_assert(v77_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=?",[$org])===0,'Authorization alone must not create or activate a rollout.');

$same=glasses_vision_promotion_authorize($pdo,$org,(string)$passed['publicId'],[
 'rationale'=>'Production failures drove a reviewed retraining dataset and the challenger passed all evidence gates.'
],$actor);
v77_assert($same['publicId']===$promotion['publicId'],'Identical authorization must be idempotent.');

$pdo->prepare("UPDATE glasses_vision_mined_candidates SET candidate_hash=? WHERE organization_id=? AND id=?")
 ->execute([hash('sha256','tampered-candidate-'.$slug),$org,$candidateId]);
$tamperBlocked=false;
try{glasses_vision_promotion_create_rollout($pdo,$org,$promotion['publicId'],$actor);}catch(InvalidArgumentException){$tamperBlocked=true;}
v77_assert($tamperBlocked,'Candidate/evidence tampering after authorization must fail closed before rollout creation.');
$pdo->prepare("UPDATE glasses_vision_mined_candidates SET candidate_hash=? WHERE organization_id=? AND id=?")->execute([$candidateHash,$org,$candidateId]);

$created=glasses_vision_promotion_create_rollout($pdo,$org,$promotion['publicId'],$actor);
v77_assert($created['status']==='draft_rollout_created','Section 7 may create only a draft governed rollout.');
v77_assert(($created['rollout']['status']??null)==='draft'&&abs((float)$created['rollout']['canaryPercent']-5.0)<.001,'Governed promotion must begin as a draft 5% canary.');
v77_assert(v77_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=? AND status IN ('active','paused')",[$org])===0,'Section 7 must never activate or advance the rollout.');
v77_assert(v77_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_promotion_events WHERE organization_id=? AND promotion_id=(SELECT id FROM glasses_vision_model_promotions WHERE organization_id=? AND public_id=?)",[$org,$org,$promotion['publicId']])===2,'Authorization and draft-rollout creation must both be durably audited.');
v77_assert(v77_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='evidence_review' AND from_public_id=? AND relation='authorized_promotion' AND to_kind='model_promotion'",[$org,$passed['publicId']])===1,'Passing evidence review must retain promotion lineage.');
v77_assert(v77_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_promotion' AND from_public_id=? AND relation='created_draft_rollout' AND to_kind='model_rollout'",[$org,$promotion['publicId']])===1,'Promotion must retain draft-rollout lineage.');

$again=glasses_vision_promotion_create_rollout($pdo,$org,$promotion['publicId'],$actor);
v77_assert(($again['rollout']['publicId']??null)===($created['rollout']['publicId']??null),'Draft rollout creation must be idempotent.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$source=file_get_contents(__DIR__.'/../includes/glasses-vision-promotion.php');
foreach(['promotion.authorize','promotion.create_rollout'] as $action)v77_assert(str_contains($api,$action),'V7 promotion API missing '.$action);
v77_assert(str_contains($page,'Governed Promotion &amp; Continuous-Learning Audit'),'Vision Lab must expose Section 7 promotion audit.');
v77_assert(!str_contains($source,'rollout.activate')&&!str_contains($source,'rollout.advance')&&!str_contains($source,'kds_transition'),'Section 7 must not activate/advance rollout or mutate kitchen truth.');

echo "vision-lab-v7-governed-promotion-audit-ok\n";
