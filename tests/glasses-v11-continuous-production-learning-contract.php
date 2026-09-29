<?php
declare(strict_types=1);

require __DIR__.'/glasses-v11-governed-canary-release-contract.php';
require_once __DIR__.'/../includes/glasses-v11-production-learning.php';

function v1111_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v1111_count(PDO $pdo,string $sql,array $args=[]):int{$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}

v1111_assert(glasses_v11_production_learning_ready($pdo),'V11 production-learning migration must be installed.');
$acceptedAt=(string)$acceptance['acceptedAt'];
$start=(new DateTimeImmutable($acceptedAt))->modify('+1 second')->format('Y-m-d H:i:s.u');
$end=(new DateTimeImmutable($acceptedAt))->modify('+1 hour')->format('Y-m-d H:i:s.u');

$cycle=glasses_v11_production_learning_start($pdo,$org,[
  'productionAcceptancePublicId'=>$acceptance['publicId'],
  'sourceWindowStart'=>$start,'sourceWindowEnd'=>$end,
  'policy'=>['minimumProductionAgeHours'=>0,'minimumMinedCandidates'=>5],
],$actor);
v1111_assert($cycle['status']==='collecting'&&strlen((string)$cycle['cycleHash'])===64,'Accepted production model must open an immutable learning cycle.');
v1111_assert($cycle['productionModel']['publicId']===$challengerPublic,'Learning cycle must bind the accepted production model.');
v1111_assert(empty($cycle['policy']['automaticTraining'])&&empty($cycle['policy']['automaticRollout']),'Closed-loop learning must never enable automatic training or rollout.');

$again=glasses_v11_production_learning_start($pdo,$org,[
  'productionAcceptancePublicId'=>$acceptance['publicId'],'sourceWindowStart'=>$start,'sourceWindowEnd'=>$end,
  'policy'=>['minimumProductionAgeHours'=>0,'minimumMinedCandidates'=>5],
],$actor);
v1111_assert($again['publicId']===$cycle['publicId'],'Identical production-learning cycles must deduplicate.');

$modelId=(int)(function()use($pdo,$org,$challengerPublic){$q=$pdo->prepare("SELECT id FROM glasses_vision_model_packages WHERE organization_id=? AND public_id=?");$q->execute([$org,$challengerPublic]);return $q->fetchColumn();})();
$miningPublic='vision-mining-v1111-'.bin2hex(random_bytes(3));$miningHash=hash('sha256',$miningPublic);
$pdo->prepare("INSERT INTO glasses_vision_mining_runs (organization_id,public_id,source_fingerprint,policy_json,result_json,run_hash,created_by) VALUES (?,?,?,'{}','{}',?,?)")
 ->execute([$org,$miningPublic,hash('sha256','source-'.$miningPublic),$miningHash,$actor]);$miningId=(int)$pdo->lastInsertId();

$errorIds=[];
for($i=0;$i<5;$i++){
  $ep='vision-error-v1111-'.$i.'-'.bin2hex(random_bytes(2));$eh=hash('sha256',$ep);
  $pdo->prepare("INSERT INTO glasses_vision_production_errors
   (organization_id,public_id,event_key,event_hash,source_type,error_type,outcome,model_package_id,rollout_id,predicted_component_key,expected_component_key,confidence,context_json,actor_user_id,occurred_at)
   VALUES (?,?,?,?, 'production_learning','misclassification','corrected',?,?, 'vision:wrong','vision:right',0.61,'{}',?,?)")
   ->execute([$org,$ep,'event-'.$ep,$eh,$modelId,$acceptRolloutId,$actor,$start]);
  $errorId=(int)$pdo->lastInsertId();$errorIds[]=$errorId;
  $candidatePublic='vision-candidate-v1111-'.$i.'-'.bin2hex(random_bytes(2));$candidateHash=hash('sha256',$candidatePublic);
  $pdo->prepare("INSERT INTO glasses_vision_mined_candidates
   (organization_id,public_id,mining_run_id,production_error_id,candidate_key,candidate_hash,candidate_type,score,status,cluster_key,model_package_id,predicted_component_key,expected_component_key,reasons_json,lineage_json)
   VALUES (?,?,?,?,?,?,'misclassification',90,'open',?,?, 'vision:wrong','vision:right','[]','{}')")
   ->execute([$org,$candidatePublic,$miningId,$errorId,hash('sha256','key-'.$candidatePublic),$candidateHash,hash('sha256','cluster-'.$candidatePublic),$modelId]);
}
$cycle=glasses_v11_production_learning_attach_mining($pdo,$org,$cycle['publicId'],$miningPublic,$actor);
v1111_assert($cycle['status']==='mined'&&$cycle['miningRun']['publicId']===$miningPublic,'Accepted-model mining evidence must attach to the cycle.');

$batchPublic='vision-retraining-batch-v1111-'.bin2hex(random_bytes(3));$batchHash=hash('sha256',$batchPublic);
$pdo->prepare("INSERT INTO glasses_vision_retraining_batches (organization_id,public_id,mining_run_id,status,policy_json,manifest_json,batch_hash,created_by) VALUES (?,?,?,'draft','{}','{}',?,?)")
 ->execute([$org,$batchPublic,$miningId,$batchHash,$actor]);$batchId=(int)$pdo->lastInsertId();
$cq=$pdo->prepare("SELECT id,production_error_id FROM glasses_vision_mined_candidates WHERE organization_id=? AND mining_run_id=? ORDER BY id LIMIT 1");$cq->execute([$org,$miningId]);$cand=$cq->fetch();
$pdo->prepare("INSERT INTO glasses_vision_retraining_batch_items
 (organization_id,public_id,batch_id,mined_candidate_id,production_error_id,training_sample_id,training_media_id,eligibility_status,eligibility_reason,decision,decision_reason,evidence_hash,reviewed_by,reviewed_at)
 VALUES (?,?,?,?,?,NULL,NULL,'eligible',NULL,'include','Reviewed for closed-loop retraining',?,?,NOW(6))")
 ->execute([$org,'vision-retraining-item-v1111-'.bin2hex(random_bytes(3)),$batchId,(int)$cand['id'],(int)$cand['production_error_id'],hash('sha256','evidence-'.$batchPublic),$actor]);

$cycle=glasses_v11_production_learning_attach_batch($pdo,$org,$cycle['publicId'],$batchPublic,$actor);
v1111_assert($cycle['status']==='reviewed'&&$cycle['retrainingBatch']['publicId']===$batchPublic,'Explicitly reviewed retraining batch must attach.');

$datasetPublic='vision-dataset-v1111-'.bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,row_count,class_count,created_by) VALUES (?,?,?,'v11-learning','draft',1,1,?)")
 ->execute([$org,$datasetPublic,'V11 Closed Loop '.$datasetPublic,$actor]);$datasetId=(int)$pdo->lastInsertId();
$pdo->prepare("UPDATE glasses_vision_retraining_batches SET status='built',dataset_id=?,built_by=?,built_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$datasetId,$actor,$org,$batchId]);

$cycle=glasses_v11_production_learning_attach_dataset($pdo,$org,$cycle['publicId'],$datasetPublic,$actor);
v1111_assert($cycle['status']==='dataset_ready','Unfrozen retraining dataset must not become experiment-ready.');
v1111_assert($cycle['readyAt']===null,'Unfrozen dataset must not receive readiness timestamp.');

$datasetHash=hash('sha256','dataset-'.$datasetPublic);$assemblyHash=hash('sha256','assembly-'.$datasetPublic);
$pdo->prepare("UPDATE glasses_vision_dataset_versions SET status='frozen',dataset_hash=?,assembly_policy_json='{}',assembly_manifest_json='{}',assembly_hash=?,frozen_by=?,frozen_at=NOW(6) WHERE organization_id=? AND id=?")
 ->execute([$datasetHash,$assemblyHash,$actor,$org,$datasetId]);
$cycle=glasses_v11_production_learning_refresh($pdo,$org,$cycle['publicId'],$actor);
v1111_assert($cycle['status']==='ready_for_experiment'&&!empty($cycle['readyAt']),'Only frozen V11-assembled dataset may become experiment-ready.');
v1111_assert($cycle['dataset']['datasetHash']===$datasetHash&&$cycle['dataset']['assemblyHash']===$assemblyHash,'Learning cycle must preserve final dataset and assembly hashes.');

v1111_assert(v1111_count($pdo,"SELECT COUNT(*) FROM glasses_vision_training_runs WHERE organization_id=? AND dataset_hash_snapshot=?",[$org,$datasetHash])===0,'Section 11 must not launch training automatically.');
v1111_assert(v1111_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=? AND created_at>=(SELECT created_at FROM glasses_vision_learning_cycles WHERE organization_id=? AND public_id=?)",[$org,$org,$cycle['publicId']])===0,'Section 11 must not create rollout activity.');
v1111_assert(v1111_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='production_acceptance' AND from_public_id=? AND relation='opened_learning_cycle'",[$org,$acceptance['publicId']])===1,'Production acceptance to learning-cycle lineage is required.');
v1111_assert(v1111_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='retraining_batch' AND from_public_id=? AND relation='closed_loop_dataset'",[$org,$batchPublic])===1,'Retraining batch to closed-loop dataset lineage is required.');

$source=file_get_contents(__DIR__.'/../includes/glasses-v11-production-learning.php');
$migration=file_get_contents(__DIR__.'/../database/20261207_v11_continuous_production_learning.sql');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
v1111_assert(str_contains($migration,'CREATE TABLE glasses_vision_learning_cycles'),'Section 11 must persist governed learning cycles.');
foreach(['learning_cycle.start','learning_cycle.attach_mining','learning_cycle.attach_batch','learning_cycle.attach_dataset','learning_cycle.refresh'] as $action)v1111_assert(str_contains($api,$action),'Vision Lab API missing '.$action);
v1111_assert(str_contains($page,'V11 Continuous Production Learning &amp; Closed-Loop Retraining'),'Vision Lab must expose Section 11.');
foreach(['glasses_v11_training_run_create(','glasses_vision_experiment_create(','glasses_vision_model_rollout_activate(','glasses_vision_model_rollout_advance('] as $forbidden)v1111_assert(!str_contains($source,$forbidden),'Production learning must not autonomously cross a governed boundary: '.$forbidden);

echo "glasses-v11-continuous-production-learning-ok\n";
