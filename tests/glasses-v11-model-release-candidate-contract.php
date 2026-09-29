<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-v11-model-release-candidates.php';
require_once __DIR__.'/../includes/glasses-vision-evidence-review.php';
require_once __DIR__.'/../includes/glasses-vision-promotion.php';

function v118_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v118_count(PDO $pdo,string $sql,array $args=[]):int{$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}

$pdo=app_pdo();
v118_assert(glasses_v11_model_rc_ready($pdo),'V11 release-candidate migration must be installed.');

$slug='v118-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['V11 RC '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture-hash','Release','Owner','Release Owner','active')")->execute([$slug.'@example.test']);$actor=(int)$pdo->lastInsertId();

$comparison=['schema'=>'gelato.vision_model_comparison.v1','eligible'=>true,'override'=>false,'goldenTestHash'=>hash('sha256','golden-'.$slug),'regressions'=>[],'summary'=>['map50Delta'=>.04,'recallDelta'=>.03,'demonstratedGain'=>true]];
$championPublic='vision-model-champion-'.$slug;$championHash=hash('sha256','champion-'.$slug);
$challengerPublic='vision-model-challenger-'.$slug;$challengerHash=hash('sha256','challenger-'.$slug);
$browser=['schema'=>'gelato.browser_onnx_detector.v1','decoder'=>'yolo_v8','input'=>['name'=>'images','width'=>640,'height'=>640,'layout'=>'nchw'],'output'=>['name'=>'output0','layout'=>'channels_first','boxScale'=>'pixels'],'labels'=>['cheese','pepperoni'],'nmsIou'=>.45,'maxDetections'=>25];
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,minimum_sdk_version,minimum_app_version,status,metadata_json,created_by) VALUES (?,?,'ingredient_detector','Champion','v1','onnx','air3','https://example.test/champion.onnx',?,100,'2.0','2.0','ready','{}',?)")
  ->execute([$org,$championPublic,$championHash,$actor]);$championId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,minimum_sdk_version,minimum_app_version,status,metadata_json,created_by) VALUES (?,?,'ingredient_detector','Challenger','v2','onnx','air3','https://example.test/challenger.onnx',?,123456,'2.1','2.1','ready',?,?)")
  ->execute([$org,$challengerPublic,$challengerHash,json_encode(['modelComparison'=>$comparison,'browserInference'=>$browser],JSON_UNESCAPED_SLASHES),$actor]);$challengerId=(int)$pdo->lastInsertId();

$datasetPublic='vision-dataset-'.$slug;$datasetHash=hash('sha256','dataset-'.$slug);$assemblyHash=hash('sha256','assembly-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,dataset_hash,assembly_policy_json,assembly_manifest_json,assembly_hash,row_count,class_count,created_by,frozen_by,frozen_at) VALUES (?,?,?,'v11','frozen',?,'{}','{}',?,80,2,?,?,NOW(6))")
  ->execute([$org,$datasetPublic,'RC Corpus',$datasetHash,$assemblyHash,$actor,$actor]);$datasetId=(int)$pdo->lastInsertId();
$planHash=hash('sha256','split-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_split_plans (organization_id,public_id,dataset_id,status,seed,train_ratio,val_ratio,test_ratio,policy_json,manifest_json,plan_hash,created_by,applied_by,applied_at) VALUES (?,?,?,'applied',74,.7,.15,.15,'{}','{}',?,?,?,NOW(6))")
  ->execute([$org,'vision-split-'.$slug,$datasetId,$planHash,$actor,$actor]);$planId=(int)$pdo->lastInsertId();

$releasePublic='vision-release-'.$slug;$releaseHash=hash('sha256','release-'.$slug);
$manifest=['schema'=>GLASSES_VISION_TRAINING_RELEASE_SCHEMA,'dataset'=>['publicId'=>$datasetPublic,'datasetHash'=>$datasetHash],'splitPlan'=>['planHash'=>$planHash],'trainingProfile'=>['name'=>'yolo11n_640'],'items'=>[]];
$pdo->prepare("INSERT INTO glasses_vision_training_releases (organization_id,public_id,dataset_id,split_plan_id,status,training_profile,profile_json,manifest_json,release_hash,artifact_relative_path,artifact_sha256,artifact_bytes,created_by) VALUES (?,?,?,?,'qualified','yolo11n_640','{}',?,?,?, ?,1,?)")
  ->execute([$org,$releasePublic,$datasetId,$planId,json_encode($manifest,JSON_UNESCAPED_SLASHES),$releaseHash,$org.'/rc.zip',hash('sha256','zip-'.$slug),$actor]);$releaseId=(int)$pdo->lastInsertId();

$qPublic='vision-qualification-'.$slug;$qHash=hash('sha256','qualification-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_training_qualifications (organization_id,public_id,training_release_id,passed,score,policy_json,result_json,qualification_hash,actor_user_id) VALUES (?,?,?,1,100,'{}','{}',?,?)")
  ->execute([$org,$qPublic,$releaseId,$qHash,$actor]);$qualificationId=(int)$pdo->lastInsertId();

$config=['epochs'=>100,'batchSize'=>16,'imageSize'=>640,'seed'=>74];$configHash=hash('sha256',glasses_vision_training_release_json($config));$runHash=hash('sha256','run-'.$slug);
$runPublic='vision-train-run-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_training_runs
 (organization_id,public_id,training_release_id,qualification_id,attempt_no,dataset_hash_snapshot,assembly_hash_snapshot,release_hash_snapshot,qualification_hash_snapshot,config_hash,run_hash,run_key,trainer,trainer_version,status,config_json,metrics_json,artifacts_json,metrics_hash,output_sha256,created_by,requested_by,started_by,completed_by,started_at,completed_at)
 VALUES (?,?,?,?,1,?,?,?,?,?,?,?,'gelato-yolo','11.0','completed',?,?,?,?,?,?,?,?,NOW(6),NOW(6))")
 ->execute([$org,$runPublic,$releaseId,$qualificationId,$datasetHash,$assemblyHash,$releaseHash,$qHash,$configHash,$runHash,'rc-run-'.$slug,json_encode($config,JSON_UNESCAPED_SLASHES),json_encode(['map50'=>.93]),json_encode([['name'=>'best.onnx','kind'=>'model','sha256'=>$challengerHash,'bytes'=>123456]]),hash('sha256',glasses_vision_training_release_json(['map50'=>.93])),$challengerHash,$actor,$actor,$actor,$actor]);$runId=(int)$pdo->lastInsertId();

$experimentPublic='vision-experiment-'.$slug;$experimentHash=hash('sha256','experiment-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_experiments (organization_id,public_id,hypothesis,champion_package_id,candidate_dataset_id,training_release_id,qualification_id,status,training_config_json,experiment_hash,training_run_id,challenger_package_id,created_by,started_by,completed_by,started_at,completed_at) VALUES (?,?,?,?,?,?,?,'completed',?,?,?,?,?,?,?,NOW(6),NOW(6))")
 ->execute([$org,$experimentPublic,'Package a benchmark-passing V11 challenger.',$championId,$datasetId,$releaseId,$qualificationId,json_encode($config,JSON_UNESCAPED_SLASHES),$experimentHash,$runId,$challengerId,$actor,$actor,$actor]);
$experimentId=(int)$pdo->lastInsertId();
$pdo->prepare("UPDATE glasses_vision_training_runs SET experiment_id=? WHERE organization_id=? AND id=?")->execute([$experimentId,$org,$runId]);

$benchmarkPublic='vision-benchmark-'.$slug;$benchmarkHash=hash('sha256','benchmark-'.$slug);
$datasetSnapshot=['datasetPublicId'=>$datasetPublic,'datasetHash'=>$datasetHash,'assemblyHash'=>$assemblyHash,'releaseHash'=>$releaseHash,'qualificationHash'=>$qHash,'testSetHash'=>hash('sha256','test-'.$slug),'hardExampleSetHash'=>hash('sha256','hard-'.$slug)];
$global=['totalCases'=>80,'champion'=>['map50'=>.86,'recall'=>.84,'precision'=>.88],'challenger'=>['map50'=>.92,'recall'=>.91,'precision'=>.93],'criticalFalseNegatives'=>0];
$slices=[['kind'=>'operator','key'=>'operator-a','cases'=>20,'championScore'=>.8,'challengerScore'=>.9],['kind'=>'device','key'=>'air3-a','cases'=>20,'championScore'=>.8,'challengerScore'=>.9],['kind'=>'location','key'=>'phoenix','cases'=>20,'championScore'=>.8,'challengerScore'=>.9],['kind'=>'station','key'=>'line','cases'=>20,'championScore'=>.8,'challengerScore'=>.9]];
$confusion=['cheese'=>['tp'=>28,'fp'=>1,'fn'=>2,'support'=>30],'pepperoni'=>['tp'=>29,'fp'=>1,'fn'=>1,'support'=>30]];
$result=['passed'=>true,'benchmarkEligible'=>true,'score'=>100,'checks'=>[],'deltas'=>['map50'=>.06,'recall'=>.07,'precision'=>.05]];
$pdo->prepare("INSERT INTO glasses_vision_model_benchmarks (organization_id,public_id,experiment_id,training_run_id,champion_package_id,challenger_package_id,status,score,policy_json,dataset_snapshot_json,global_metrics_json,slice_metrics_json,confusion_json,regressions_json,result_json,benchmark_hash,evaluated_by) VALUES (?,?,?,?,?,?,'passed',100,'{}',?,?,?,?,?,?,?,?)")
 ->execute([$org,$benchmarkPublic,$experimentId,$runId,$championId,$challengerId,glasses_vision_training_release_json($datasetSnapshot),glasses_vision_training_release_json($global),glasses_vision_training_release_json($slices),glasses_vision_training_release_json($confusion),glasses_vision_training_release_json(['classRegressions'=>[],'sliceRegressions'=>[],'missingSliceKinds'=>[]]),glasses_vision_training_release_json($result),$benchmarkHash,$actor]);

$rc=glasses_v11_model_rc_create($pdo,$org,['benchmarkPublicId'=>$benchmarkPublic,'releaseNotes'=>'Improves ingredient recall and preserves all governed operational slices.'],$actor);
v118_assert($rc['status']==='pending_approval'&&strlen((string)$rc['rcHash'])===64,'Passing benchmark must package as immutable pending RC.');
v118_assert($rc['modelPackage']['publicId']===$challengerPublic&&$rc['modelPackage']['artifactSha256']===$challengerHash,'RC must wrap canonical challenger package rather than create a new registry item.');
v118_assert(($rc['manifest']['dataset']['assemblyHash']??'')===$assemblyHash&&($rc['manifest']['trainingRun']['runHash']??'')===$runHash,'RC must retain dataset assembly and training-run lineage.');
v118_assert(($rc['runtimeCompatibility']['minimumSdkVersion']??'')==='2.1'&&($rc['runtimeCompatibility']['minimumAppVersion']??'')==='2.1','RC must retain runtime compatibility requirements.');
v118_assert(($rc['manifest']['labels']??[])===['cheese','pepperoni'],'RC must package deterministic labels.');

$again=glasses_v11_model_rc_create($pdo,$org,['benchmarkPublicId'=>$benchmarkPublic,'releaseNotes'=>'Improves ingredient recall and preserves all governed operational slices.'],$actor);
v118_assert($again['publicId']===$rc['publicId'],'Identical RC manifest must deduplicate.');
$verify=glasses_v11_model_rc_verify($pdo,$org,$rc['publicId']);v118_assert($verify['passed'],'Fresh RC integrity must verify.');

$review=glasses_vision_evidence_review_run($pdo,$org,$experimentPublic,[
 'failureEvaluation'=>['evaluationHash'=>hash('sha256','failure-'.$slug),'sourceFingerprint'=>hash('sha256','source-'.$slug),'totalCases'=>40,'championCorrect'=>30,'challengerCorrect'=>36,'criticalRegressions'=>0,'classRegressions'=>[]],
 'runtimeEvaluation'=>['evaluationHash'=>hash('sha256','runtime-'.$slug),'championLatencyMs'=>50,'challengerLatencyMs'=>52,'championTimeouts'=>0,'challengerTimeouts'=>0,'sampleCount'=>40],
],$actor);
v118_assert($review['status']==='passed','Fixture evidence review must pass after benchmark.');

$blocked=false;try{glasses_vision_promotion_source($pdo,$org,$review['publicId']);}catch(InvalidArgumentException $e){$blocked=str_contains($e->getMessage(),'approved model release candidate');}
v118_assert($blocked,'V11 promotion must fail closed before RC approval.');

$approved=glasses_v11_model_rc_approve($pdo,$org,$rc['publicId'],$actor);
v118_assert($approved['status']==='approved'&&!empty($approved['approvedAt']),'RC approval must be explicit and audited.');
$latest=glasses_v11_model_rc_latest_approved($pdo,$org,$experimentId);v118_assert(($latest['publicId']??'')===$rc['publicId'],'Approved RC must become the experiment promotion-ready candidate.');

$passedRcGate=false;try{glasses_vision_promotion_source($pdo,$org,$review['publicId']);}catch(InvalidArgumentException $e){$passedRcGate=str_contains($e->getMessage(),'built V7 retraining batch');}
v118_assert($passedRcGate,'After RC approval, promotion must proceed beyond the RC gate to existing downstream governance.');

$second=glasses_v11_model_rc_create($pdo,$org,['benchmarkPublicId'=>$benchmarkPublic,'releaseNotes'=>'Alternate candidate notes for rejection path.'],$actor);
$second=glasses_v11_model_rc_reject($pdo,$org,$second['publicId'],'Release notes require revision.',$actor);
v118_assert($second['status']==='rejected'&&$second['rejectionReason']==='Release notes require revision.','RC rejection must preserve explicit rationale.');

v118_assert(v118_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_packages WHERE organization_id=?",[$org])===2,'Section 8 must not create a second model package registry.');
v118_assert(v118_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=?",[$org])===0,'RC packaging and approval must not create or activate a rollout.');
v118_assert(v118_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_release_candidate' AND from_public_id=? AND relation='approved_for_promotion'",[$org,$rc['publicId']])===1,'RC approval lineage is required.');

$source=file_get_contents(__DIR__.'/../includes/glasses-v11-model-release-candidates.php');
$promotion=file_get_contents(__DIR__.'/../includes/glasses-vision-promotion.php');
$migration=file_get_contents(__DIR__.'/../database/20261204_v11_model_release_candidate_governance.sql');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
v118_assert(str_contains($migration,'CREATE TABLE glasses_vision_model_release_candidates')&&!str_contains($migration,'CREATE TABLE glasses_vision_model_packages'),'Section 8 governance must wrap the canonical package registry.');
foreach(['model_rc.create','model_rc.approve','model_rc.reject','model_rc.verify'] as $action)v118_assert(str_contains($api,$action),'Vision Lab API missing '.$action);
v118_assert(str_contains($page,'V11 Model Packaging, Registry &amp; Release Candidate Governance'),'Vision Lab must expose Section 8 RC governance.');
v118_assert(str_contains($promotion,'V11 promotion requires an approved model release candidate'),'Existing promotion flow must require approved V11 RCs.');
foreach(['glasses_vision_model_rollout_activate(','rollout.advance','kds_transition(','glasses_handoff_to_expo('] as $forbidden)v118_assert(!str_contains($source,$forbidden),'RC governance must not bypass downstream deployment governance: '.$forbidden);

echo "glasses-v11-model-release-candidate-governance-ok\n";
