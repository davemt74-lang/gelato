<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-v11-training-runs.php';

function v116_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v116_count(PDO $pdo,string $sql,array $args=[]):int{$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}

$pdo=app_pdo();
v116_assert(glasses_v11_training_run_ready($pdo),'V11 training-run migration must be installed.');

$slug='v116-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['V11 Training Runs '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture-hash','Training','Owner','Training Owner','active')")->execute([$slug.'@example.test']);$actor=(int)$pdo->lastInsertId();

$championPublic='vision-model-'.$slug;$championHash=hash('sha256','champion-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','Champion','v1','onnx','air3','https://example.test/champion.onnx',?,100,'ready',?)")
  ->execute([$org,$championPublic,$championHash,$actor]);

$datasetPublic='vision-dataset-'.$slug;$datasetHash=hash('sha256','dataset-'.$slug);$assemblyHash=hash('sha256','assembly-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,dataset_hash,assembly_policy_json,assembly_manifest_json,assembly_hash,row_count,class_count,created_by,frozen_by,frozen_at) VALUES (?,?,?,'v11','frozen',?,'{}','{}',?,30,3,?,?,NOW(6))")
  ->execute([$org,$datasetPublic,'V11 Corpus',$datasetHash,$assemblyHash,$actor,$actor]);$datasetId=(int)$pdo->lastInsertId();

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
  'hypothesis'=>'V11 immutable assembly improves ingredient recall without changing runtime family.',
  'championModelPublicId'=>$championPublic,'trainingReleasePublicId'=>$releasePublic,'trainingConfig'=>$config
],$actor);
v116_assert($experiment['status']==='ready','Experiment must be ready before orchestration.');

$run=glasses_v11_training_run_create($pdo,$org,['experimentPublicId'=>$experiment['publicId'],'runKey'=>'train-'.$slug,'trainer'=>'gelato-yolo','trainerVersion'=>'11.0'], $actor);
v116_assert($run['status']==='planned','New V11 training run must start planned.');
v116_assert(strlen((string)$run['runHash'])===64&&strlen((string)$run['configHash'])===64,'Training run and config require immutable hashes.');
v116_assert($run['dataset']['datasetHash']===$datasetHash&&$run['dataset']['assemblyHash']===$assemblyHash,'Run must snapshot dataset and assembly hashes.');
v116_assert($run['release']['releaseHash']===$releaseHash&&$run['qualification']['qualificationHash']===$qualificationHash,'Run must snapshot release and qualification hashes.');

$again=glasses_v11_training_run_create($pdo,$org,['experimentPublicId'=>$experiment['publicId'],'runKey'=>'train-'.$slug,'trainer'=>'gelato-yolo','trainerVersion'=>'11.0'], $actor);
v116_assert($again['publicId']===$run['publicId'],'Identical run contract must deduplicate.');

$mismatch=false;try{glasses_v11_training_run_create($pdo,$org,['experimentPublicId'=>$experiment['publicId'],'runKey'=>'bad-'.$slug,'config'=>['epochs'=>101,'batchSize'=>16,'imageSize'=>640,'seed'=>74]],$actor);}catch(InvalidArgumentException){$mismatch=true;}
v116_assert($mismatch,'Training configuration may not drift from experiment contract.');

$run=glasses_v11_training_run_start($pdo,$org,$run['publicId'],$actor);
v116_assert($run['status']==='running'&&$run['experiment']['status']==='running','Starting a run must start the experiment without changing production state.');

$failed=glasses_v11_training_run_fail($pdo,$org,$run['publicId'],['code'=>'worker_lost','message'=>'Training worker disconnected.','retryable'=>true],$actor);
v116_assert($failed['status']==='failed'&&($failed['failure']['code']??'')==='worker_lost','Running job must preserve structured failure evidence.');

$retry=glasses_v11_training_run_rerun($pdo,$org,$failed['publicId'],[],$actor);
v116_assert($retry['status']==='planned'&&$retry['attemptNo']===2&&$retry['parentRunPublicId']===$failed['publicId'],'Retry must preserve parentage and increment attempt.');
v116_assert($retry['configHash']===$failed['configHash']&&$retry['dataset']['assemblyHash']===$failed['dataset']['assemblyHash'],'Retry must preserve exact immutable training inputs.');
$retry2=glasses_v11_training_run_rerun($pdo,$org,$failed['publicId'],[],$actor);
v116_assert($retry2['publicId']===$retry['publicId'],'Identical retry contract must deduplicate.');

$retry=glasses_v11_training_run_start($pdo,$org,$retry['publicId'],$actor);
$output=hash('sha256','trained-model-'.$slug);
$retry=glasses_v11_training_run_complete($pdo,$org,$retry['publicId'],[
  'outputSha256'=>$output,'metrics'=>['map50'=>0.93,'recall'=>0.91],
  'artifacts'=>[
    ['name'=>'best.onnx','kind'=>'model','sha256'=>$output,'bytes'=>123456],
    ['name'=>'metrics.json','kind'=>'metrics','sha256'=>hash('sha256','metrics-'.$slug),'bytes'=>256]
  ]
],$actor);
v116_assert($retry['status']==='completed'&&$retry['outputSha256']===$output,'Running retry must complete with immutable artifact output.');
v116_assert(strlen((string)$retry['metricsHash'])===64&&count($retry['artifacts'])===2,'Completion must retain hashed metrics and artifact manifest.');

$experimentAfter=glasses_vision_experiment_public($pdo,$org,glasses_vision_experiment_row($pdo,$org,$experiment['publicId']));
v116_assert($experimentAfter['status']==='trained'&&$experimentAfter['trainingRun']['publicId']===$retry['publicId'],'Completed V11 run must attach through canonical V7 experiment lineage.');

$immutable=false;try{glasses_v11_training_run_complete($pdo,$org,$retry['publicId'],['outputSha256'=>$output,'metrics'=>['map50'=>0.95],'artifacts'=>[['name'=>'best.onnx','kind'=>'model','sha256'=>$output,'bytes'=>123456]]],$actor);}catch(InvalidArgumentException){$immutable=true;}
v116_assert($immutable,'Completed run results must be immutable.');

$comparison=glasses_v11_training_run_compare($pdo,$org,$failed['publicId'],$retry['publicId']);
v116_assert($comparison['sameConfigHash']===true&&$comparison['sameDatasetHash']===true&&$comparison['sameAssemblyHash']===true,'Retry comparison must prove input parity.');
v116_assert(v116_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='training_run' AND from_public_id=? AND relation='retried_as' AND to_public_id=?",[$org,$failed['publicId'],$retry['publicId']])===1,'Retry lineage edge is required.');
v116_assert(v116_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=?",[$org])===0,'Training orchestration must never create or activate production rollouts.');

$source=file_get_contents(__DIR__.'/../includes/glasses-v11-training-runs.php');
$migration=file_get_contents(__DIR__.'/../database/20261202_v11_training_run_orchestration.sql');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$ui=file_get_contents(__DIR__.'/../assets/js/glasses-v11-training-runs.js');
v116_assert(str_contains($migration,'ALTER TABLE glasses_vision_training_runs')&&!str_contains($migration,'CREATE TABLE'),'V11 Section 6 must extend canonical V6 training runs, not create a second registry.');
foreach(['training_run.create','training_run.start','training_run.complete','training_run.fail','training_run.cancel','training_run.rerun','training_run.compare'] as $action)v116_assert(str_contains($api,$action),'Vision Lab API missing '.$action);
v116_assert(str_contains($page,'V11 Training Run Orchestration &amp; Experiment Lineage'),'Vision Lab must expose V11 training-run orchestration.');
v116_assert(str_contains($ui,'Retry'),'Training-run UI must expose controlled retry.');
foreach(['glasses_vision_model_rollout_activate(','glasses_vision_promotion_','kds_transition(','glasses_handoff_to_expo('] as $forbidden)v116_assert(!str_contains($source,$forbidden),'Training orchestration must not bypass downstream governance: '.$forbidden);

echo "glasses-v11-training-run-orchestration-ok\n";
