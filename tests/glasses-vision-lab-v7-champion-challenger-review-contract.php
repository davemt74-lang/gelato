<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-evidence-review.php';

function v76_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v76_count(PDO $pdo,string $sql,array $a=[]):int{$q=$pdo->prepare($sql);$q->execute($a);return (int)$q->fetchColumn();}
$pdo=app_pdo();
v76_assert(glasses_vision_evidence_review_ready($pdo),'V7 evidence review migration must be installed.');

$slug='vl76-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Evidence Review '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Evidence','Reviewer','Evidence Reviewer']);$actor=(int)$pdo->lastInsertId();

$goldenHash=hash('sha256','golden-'.$slug);
$comparison=['schema'=>'gelato.vision_model_comparison.v1','eligible'=>true,'override'=>false,'goldenTestHash'=>$goldenHash,'regressions'=>[],'summary'=>['map50Delta'=>.04,'recallDelta'=>.03,'demonstratedGain'=>true]];
$championPublic='vision-model-champion-'.$slug;$championHash=hash('sha256','champion-'.$slug);
$challengerPublic='vision-model-challenger-'.$slug;$challengerHash=hash('sha256','challenger-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,metadata_json,created_by) VALUES (?,?,'ingredient_detector','Champion','v1','onnx','air3','https://example.test/champion.onnx',?,100,'ready','{}',?)")->execute([$org,$championPublic,$championHash,$actor]);$championId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,metadata_json,created_by) VALUES (?,?,'ingredient_detector','Challenger','v2','onnx','air3','https://example.test/challenger.onnx',?,100,'ready',?,?)")->execute([$org,$challengerPublic,$challengerHash,json_encode(['modelComparison'=>$comparison],JSON_UNESCAPED_SLASHES),$actor]);$challengerId=(int)$pdo->lastInsertId();

$datasetPublic='vision-dataset-'.$slug;$datasetHash=hash('sha256','dataset-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,dataset_hash,row_count,class_count,created_by,frozen_by,frozen_at) VALUES (?,?,?,'v1','frozen',?,20,1,?,?,NOW(6))")->execute([$org,$datasetPublic,'Review Corpus',$datasetHash,$actor,$actor]);$datasetId=(int)$pdo->lastInsertId();
$planHash=hash('sha256','split-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_split_plans (organization_id,public_id,dataset_id,status,seed,train_ratio,val_ratio,test_ratio,policy_json,manifest_json,plan_hash,created_by,applied_by,applied_at) VALUES (?,?,?,'applied',74,.7,.15,.15,'{}','{}',?,?,?,NOW(6))")->execute([$org,'vision-split-'.$slug,$datasetId,$planHash,$actor,$actor]);$planId=(int)$pdo->lastInsertId();
$releasePublic='vision-release-'.$slug;$releaseHash=hash('sha256','release-'.$slug);
$manifest=['schema'=>GLASSES_VISION_TRAINING_RELEASE_SCHEMA,'releaseHash'=>$releaseHash,'dataset'=>['publicId'=>$datasetPublic,'datasetHash'=>$datasetHash],'splitPlan'=>['planHash'=>$planHash],'trainingProfile'=>['name'=>'yolo11n_640'],'items'=>[]];
$pdo->prepare("INSERT INTO glasses_vision_training_releases (organization_id,public_id,dataset_id,split_plan_id,status,training_profile,profile_json,manifest_json,release_hash,artifact_relative_path,artifact_sha256,artifact_bytes,created_by) VALUES (?,?,?,?,'qualified','yolo11n_640','{}',?,?,?, ?,1,?)")->execute([$org,$releasePublic,$datasetId,$planId,json_encode($manifest,JSON_UNESCAPED_SLASHES),$releaseHash,$org.'/review.zip',hash('sha256','zip-'.$slug),$actor]);$releaseId=(int)$pdo->lastInsertId();
$qPublic='vision-qualification-'.$slug;$qHash=hash('sha256','qualification-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_training_qualifications (organization_id,public_id,training_release_id,passed,score,policy_json,result_json,qualification_hash,actor_user_id) VALUES (?,?,?,1,100,'{}','{}',?,?)")->execute([$org,$qPublic,$releaseId,$qHash,$actor]);$qualificationId=(int)$pdo->lastInsertId();
$runPublic='vision-train-run-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_training_runs (organization_id,public_id,training_release_id,qualification_id,run_key,trainer,trainer_version,status,config_json,metrics_json,output_sha256,created_by) VALUES (?,?,?,?,?,'gelato-yolo','v1','completed','{}','{}',?,?)")->execute([$org,$runPublic,$releaseId,$qualificationId,'review-run-'.$slug,$challengerHash,$actor]);$runId=(int)$pdo->lastInsertId();

$experimentPublic='vision-experiment-'.$slug;$experimentHash=hash('sha256','experiment-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_experiments (organization_id,public_id,hypothesis,champion_package_id,candidate_dataset_id,training_release_id,qualification_id,status,training_config_json,experiment_hash,training_run_id,challenger_package_id,created_by,started_by,completed_by,started_at,completed_at) VALUES (?,?,?,?,?,?,?,'completed','{}',?,?,?,?,?,?,NOW(6),NOW(6))")->execute([$org,$experimentPublic,'Reduce known production errors without regressions.',$championId,$datasetId,$releaseId,$qualificationId,$experimentHash,$runId,$challengerId,$actor,$actor,$actor]);

$failed=glasses_vision_evidence_review_run($pdo,$org,$experimentPublic,[
 'failureEvaluation'=>['evaluationHash'=>hash('sha256','fail-eval-'.$slug),'sourceFingerprint'=>hash('sha256','fail-source-'.$slug),'totalCases'=>50,'championCorrect'=>35,'challengerCorrect'=>46,'criticalRegressions'=>0,'classRegressions'=>['fries']],
 'runtimeEvaluation'=>['evaluationHash'=>hash('sha256','runtime-fail-'.$slug),'championLatencyMs'=>50,'challengerLatencyMs'=>55,'championTimeouts'=>0,'challengerTimeouts'=>0,'sampleCount'=>50],
],$actor);
v76_assert($failed['status']==='failed'&&empty($failed['result']['promotionEligible']),'Any class-level regression must fail promotion evidence review.');
v76_assert($failed['score']<100,'Failed review must expose a non-perfect deterministic score.');

$passedInput=[
 'failureEvaluation'=>['evaluationHash'=>hash('sha256','pass-eval-'.$slug),'sourceFingerprint'=>hash('sha256','pass-source-'.$slug),'totalCases'=>50,'championCorrect'=>35,'challengerCorrect'=>46,'criticalRegressions'=>0,'classRegressions'=>[]],
 'runtimeEvaluation'=>['evaluationHash'=>hash('sha256','runtime-pass-'.$slug),'championLatencyMs'=>50,'challengerLatencyMs'=>55,'championTimeouts'=>0,'challengerTimeouts'=>0,'sampleCount'=>50],
];
$passed=glasses_vision_evidence_review_run($pdo,$org,$experimentPublic,$passedInput,$actor);
v76_assert($passed['status']==='passed'&&!empty($passed['result']['promotionEligible'])&&$passed['score']===100,'Clean golden/failure/runtime evidence must pass all policy floors.');
v76_assert(preg_match('/^[a-f0-9]{64}$/',$passed['reviewHash'])===1,'Evidence review must be hash-addressed.');
$again=glasses_vision_evidence_review_run($pdo,$org,$experimentPublic,$passedInput,$actor);
v76_assert($again['publicId']===$passed['publicId'],'Identical evidence review must deduplicate.');

v76_assert(v76_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_experiment' AND from_public_id=? AND relation='evidence_reviewed_as' AND to_kind='evidence_review'",[$org,$experimentPublic])===2,'Each distinct immutable evidence review must retain experiment lineage.');
v76_assert(v76_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=?",[$org])===0,'Evidence review must never create or activate a rollout.');

$overrideComparison=$comparison;$overrideComparison['override']=true;$overrideComparison['overrideReason']='Manual exception.';
$pdo->prepare("UPDATE glasses_vision_model_packages SET metadata_json=? WHERE organization_id=? AND id=?")->execute([json_encode(['modelComparison'=>$overrideComparison],JSON_UNESCAPED_SLASHES),$org,$challengerId]);
$overrideInput=$passedInput;$overrideInput['failureEvaluation']['evaluationHash']=hash('sha256','override-'.$slug);
$override=glasses_vision_evidence_review_run($pdo,$org,$experimentPublic,$overrideInput,$actor);
v76_assert($override['status']==='failed','Golden comparison overrides must not qualify for automated continuous-learning promotion.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-evidence-review.php');
v76_assert(str_contains($api,'evidence_review.run'),'V7 evidence-review API action is required.');
v76_assert(str_contains($page,'Champion / Challenger Evidence Review'),'Vision Lab must expose Section 6 review controls.');
v76_assert(!str_contains($source,'rollout.activate')&&!str_contains($source,'rollout.advance')&&!str_contains($source,'kds_transition'),'Evidence review must not mutate rollout or kitchen truth.');

echo "vision-lab-v7-champion-challenger-review-ok\n";
