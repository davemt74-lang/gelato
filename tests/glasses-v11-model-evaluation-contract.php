<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-v11-model-evaluation.php';
require_once __DIR__.'/../includes/glasses-vision-evidence-review.php';

function v117_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v117_count(PDO $pdo,string $sql,array $args=[]):int{$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}

$pdo=app_pdo();
v117_assert(glasses_v11_model_evaluation_ready($pdo),'V11 benchmark migration must be installed.');

$slug='v117-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['V11 Benchmark '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture-hash','Benchmark','Owner','Benchmark Owner','active')")->execute([$slug.'@example.test']);$actor=(int)$pdo->lastInsertId();

$championPublic='vision-model-champion-'.$slug;$championHash=hash('sha256','champion-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,metadata_json,created_by) VALUES (?,?,'ingredient_detector','Champion','v1','onnx','air3','https://example.test/champion.onnx',?,100,'ready','{}',?)")
  ->execute([$org,$championPublic,$championHash,$actor]);

$datasetPublic='vision-dataset-'.$slug;$datasetHash=hash('sha256','dataset-'.$slug);$assemblyHash=hash('sha256','assembly-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,dataset_hash,assembly_policy_json,assembly_manifest_json,assembly_hash,row_count,class_count,created_by,frozen_by,frozen_at) VALUES (?,?,?,'v11','frozen',?,'{}','{}',?,60,2,?,?,NOW(6))")
  ->execute([$org,$datasetPublic,'Benchmark Corpus',$datasetHash,$assemblyHash,$actor,$actor]);$datasetId=(int)$pdo->lastInsertId();

$planPublic='vision-split-'.$slug;$planHash=hash('sha256','split-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_split_plans (organization_id,public_id,dataset_id,status,seed,train_ratio,val_ratio,test_ratio,policy_json,manifest_json,plan_hash,created_by,applied_by,applied_at) VALUES (?,?,?,'applied',74,.7,.15,.15,'{}','{}',?,?,?,NOW(6))")
  ->execute([$org,$planPublic,$datasetId,$planHash,$actor,$actor]);$planId=(int)$pdo->lastInsertId();

$releasePublic='vision-release-'.$slug;$releaseHash=hash('sha256','release-'.$slug);
$manifest=['schema'=>GLASSES_VISION_TRAINING_RELEASE_SCHEMA,'dataset'=>['publicId'=>$datasetPublic,'datasetHash'=>$datasetHash],'splitPlan'=>['publicId'=>$planPublic,'planHash'=>$planHash],'trainingProfile'=>['name'=>'yolo11n_640'],'items'=>[]];
$pdo->prepare("INSERT INTO glasses_vision_training_releases (organization_id,public_id,dataset_id,split_plan_id,status,training_profile,profile_json,manifest_json,release_hash,artifact_relative_path,artifact_sha256,artifact_bytes,created_by) VALUES (?,?,?,?,'qualified','yolo11n_640','{}',?,?,?, ?,1,?)")
  ->execute([$org,$releasePublic,$datasetId,$planId,json_encode($manifest,JSON_UNESCAPED_SLASHES),$releaseHash,$org.'/fixture.zip',hash('sha256','zip-'.$slug),$actor]);$releaseId=(int)$pdo->lastInsertId();

$qualificationPublic='vision-qualification-'.$slug;$qualificationHash=hash('sha256','qualification-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_training_qualifications (organization_id,public_id,training_release_id,passed,score,policy_json,result_json,qualification_hash,actor_user_id) VALUES (?,?,?,1,100,'{}','{}',?,?)")
  ->execute([$org,$qualificationPublic,$releaseId,$qualificationHash,$actor]);

$config=['epochs'=>100,'batchSize'=>16,'imageSize'=>640,'seed'=>74];
$experiment=glasses_vision_experiment_create($pdo,$org,[
  'hypothesis'=>'V11 challenger improves held-out and operational slices without regression.',
  'championModelPublicId'=>$championPublic,'trainingReleasePublicId'=>$releasePublic,'trainingConfig'=>$config
],$actor);
$run=glasses_v11_training_run_create($pdo,$org,['experimentPublicId'=>$experiment['publicId'],'runKey'=>'benchmark-'.$slug],$actor);
$run=glasses_v11_training_run_start($pdo,$org,$run['publicId'],$actor);
$output=hash('sha256','challenger-'.$slug);
$run=glasses_v11_training_run_complete($pdo,$org,$run['publicId'],['outputSha256'=>$output,'metrics'=>['map50'=>.93,'recall'=>.91,'precision'=>.94]],$actor);

$comparison=['schema'=>'gelato.vision_model_comparison.v1','eligible'=>true,'override'=>false,'goldenTestHash'=>hash('sha256','golden-'.$slug),'regressions'=>[],'summary'=>['map50Delta'=>.03,'recallDelta'=>.02,'demonstratedGain'=>true]];
$challengerPublic='vision-model-challenger-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,metadata_json,created_by) VALUES (?,?,'ingredient_detector','Challenger','v2','onnx','air3','https://example.test/challenger.onnx',?,120,'ready',?,?)")
  ->execute([$org,$challengerPublic,$output,json_encode(['modelComparison'=>$comparison],JSON_UNESCAPED_SLASHES),$actor]);
$experiment=glasses_vision_experiment_bind_challenger($pdo,$org,$experiment['publicId'],$challengerPublic,$actor);
v117_assert($experiment['status']==='completed','Fixture experiment must complete with challenger.');

$reviewInput=[
 'failureEvaluation'=>['evaluationHash'=>hash('sha256','failure-'.$slug),'sourceFingerprint'=>hash('sha256','failure-source-'.$slug),'totalCases'=>40,'championCorrect'=>30,'challengerCorrect'=>36,'criticalRegressions'=>0,'classRegressions'=>[]],
 'runtimeEvaluation'=>['evaluationHash'=>hash('sha256','runtime-'.$slug),'championLatencyMs'=>50,'challengerLatencyMs'=>52,'championTimeouts'=>0,'challengerTimeouts'=>0,'sampleCount'=>40],
];
$blocked=false;try{glasses_vision_evidence_review_run($pdo,$org,$experiment['publicId'],$reviewInput,$actor);}catch(InvalidArgumentException $e){$blocked=str_contains($e->getMessage(),'passing model benchmark');}
v117_assert($blocked,'V11 evidence review must be blocked before a passing Section 7 benchmark.');

$base=[
 'experimentPublicId'=>$experiment['publicId'],
 'testSetHash'=>hash('sha256','held-out-test-'.$slug),
 'hardExampleSetHash'=>hash('sha256','hard-examples-'.$slug),
 'globalMetrics'=>[
   'totalCases'=>80,
   'champion'=>['map50'=>.86,'recall'=>.84,'precision'=>.88],
   'challenger'=>['map50'=>.91,'recall'=>.90,'precision'=>.92,'criticalFalseNegatives'=>0],
 ],
 'slices'=>[
   ['kind'=>'operator','key'=>'operator-a','cases'=>20,'championScore'=>.82,'challengerScore'=>.88],
   ['kind'=>'device','key'=>'air3-a','cases'=>20,'championScore'=>.84,'challengerScore'=>.90],
   ['kind'=>'location','key'=>'phoenix-1','cases'=>20,'championScore'=>.85,'challengerScore'=>.91],
   ['kind'=>'station','key'=>'pizza-line','cases'=>20,'championScore'=>.83,'challengerScore'=>.89],
   ['kind'=>'hard_example','key'=>'occlusion','cases'=>12,'championScore'=>.70,'challengerScore'=>.82],
   ['kind'=>'class','key'=>'pepperoni','cases'=>30,'championScore'=>.86,'challengerScore'=>.92],
 ],
 'confusion'=>[
   'pepperoni'=>['tp'=>28,'fp'=>2,'fn'=>2,'support'=>30],
   'cheese'=>['tp'=>27,'fp'=>2,'fn'=>3,'support'=>30],
 ],
];

$passed=glasses_v11_model_evaluation_run($pdo,$org,$experiment['publicId'],$base,$actor);
v117_assert($passed['status']==='passed'&&$passed['score']===100&&!empty($passed['result']['benchmarkEligible']),'Clean benchmark must pass every validation floor.');
v117_assert($passed['trainingRun']['publicId']===$run['publicId']&&$passed['trainingRun']['runHash']===$run['runHash'],'Benchmark must bind exact V11 training run.');
v117_assert($passed['datasetSnapshot']['datasetHash']===$datasetHash&&$passed['datasetSnapshot']['assemblyHash']===$assemblyHash,'Benchmark must retain immutable dataset/assembly lineage.');
v117_assert(preg_match('/^[a-f0-9]{64}$/',(string)$passed['benchmarkHash'])===1,'Benchmark must be SHA-256 addressed.');

$again=glasses_v11_model_evaluation_run($pdo,$org,$experiment['publicId'],$base,$actor);
v117_assert($again['publicId']===$passed['publicId'],'Identical benchmark evidence must deduplicate.');

$failedInput=$base;
$failedInput['testSetHash']=hash('sha256','held-out-test-regression-'.$slug);
$failedInput['slices'][1]['challengerScore']=.80;
$failed=glasses_v11_model_evaluation_run($pdo,$org,$experiment['publicId'],$failedInput,$actor);
v117_assert($failed['status']==='failed'&&$failed['score']<100,'A negative operational slice must fail the default no-regression benchmark.');
v117_assert(count($failed['regressions']['sliceRegressions']??[])===1,'Failed benchmark must identify exact slice regression.');

$review=glasses_vision_evidence_review_run($pdo,$org,$experiment['publicId'],$reviewInput,$actor);
v117_assert($review['status']==='passed'&&!empty($review['result']['promotionEligible']),'Passing benchmark must unlock normal downstream evidence review.');
v117_assert(($review['evidence']['v11Benchmark']['publicId']??'')===$passed['publicId'],'Evidence review must retain passing V11 benchmark lineage.');

v117_assert(v117_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='training_run' AND from_public_id=? AND relation='benchmarked_as'",[$org,$run['publicId']])===2,'Each distinct benchmark must retain training-run lineage.');
v117_assert(v117_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=?",[$org])===0,'Benchmarking and evidence review must not create a rollout.');

$source=file_get_contents(__DIR__.'/../includes/glasses-v11-model-evaluation.php');
$reviewSource=file_get_contents(__DIR__.'/../includes/glasses-vision-evidence-review.php');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
v117_assert(str_contains($api,'model_benchmark.run'),'Vision Lab API must expose benchmark execution.');
v117_assert(str_contains($page,'V11 Model Evaluation, Validation &amp; Benchmark Suite'),'Vision Lab must expose Section 7 benchmark suite.');
v117_assert(str_contains($reviewSource,'passing model benchmark'),'V11 evidence review must require a passing benchmark.');
foreach(['glasses_vision_model_rollout_activate(','glasses_vision_promotion_','kds_transition(','glasses_handoff_to_expo('] as $forbidden)v117_assert(!str_contains($source,$forbidden),'Benchmark suite must not bypass downstream governance: '.$forbidden);

echo "glasses-v11-model-evaluation-benchmark-ok\n";
