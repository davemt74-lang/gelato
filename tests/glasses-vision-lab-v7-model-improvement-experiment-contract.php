<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-experiments.php';

function v75_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v75_count(PDO $pdo,string $sql,array $a=[]):int{$q=$pdo->prepare($sql);$q->execute($a);return (int)$q->fetchColumn();}
$pdo=app_pdo();
v75_assert(glasses_vision_experiment_ready($pdo),'V7 model experiment migration must be installed.');

$slug='vl75-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Experiment '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Experiment','Owner','Experiment Owner']);$actor=(int)$pdo->lastInsertId();

$championPublic='vision-model-champion-'.$slug;$championHash=hash('sha256','champion-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','Champion Detector','v1','onnx','air3','https://example.test/champion.onnx',?,100,'ready',?)")->execute([$org,$championPublic,$championHash,$actor]);

$datasetPublic='vision-dataset-'.$slug;$datasetHash=hash('sha256','dataset-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,dataset_hash,row_count,class_count,created_by,frozen_by,frozen_at) VALUES (?,?,?,'v1','frozen',?,3,1,?,?,NOW(6))")->execute([$org,$datasetPublic,'Experiment Corpus',$datasetHash,$actor,$actor]);$datasetId=(int)$pdo->lastInsertId();
$planPublic='vision-split-'.$slug;$planHash=hash('sha256','split-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_split_plans (organization_id,public_id,dataset_id,status,seed,train_ratio,val_ratio,test_ratio,policy_json,manifest_json,plan_hash,created_by,applied_by,applied_at) VALUES (?,?,?,'applied',74,.7,.15,.15,'{}','{}',?,?,?,NOW(6))")->execute([$org,$planPublic,$datasetId,$planHash,$actor,$actor]);$planId=(int)$pdo->lastInsertId();

$releasePublic='vision-release-'.$slug;$releaseHash=hash('sha256','release-'.$slug);
$manifest=['schema'=>GLASSES_VISION_TRAINING_RELEASE_SCHEMA,'releaseHash'=>$releaseHash,'dataset'=>['publicId'=>$datasetPublic,'datasetHash'=>$datasetHash],'splitPlan'=>['publicId'=>$planPublic,'planHash'=>$planHash],'trainingProfile'=>['name'=>'yolo11n_640'],'items'=>[]];
$pdo->prepare("INSERT INTO glasses_vision_training_releases (organization_id,public_id,dataset_id,split_plan_id,status,training_profile,profile_json,manifest_json,release_hash,artifact_relative_path,artifact_sha256,artifact_bytes,created_by) VALUES (?,?,?,?,'qualified','yolo11n_640','{}',?,?,?, ?,1,?)")->execute([$org,$releasePublic,$datasetId,$planId,json_encode($manifest,JSON_UNESCAPED_SLASHES),$releaseHash,$org.'/fixture.zip',hash('sha256','zip-'.$slug),$actor]);$releaseId=(int)$pdo->lastInsertId();

$q1Public='vision-qualification-'.$slug.'-1';$q1Hash=hash('sha256','q1-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_training_qualifications (organization_id,public_id,training_release_id,passed,score,policy_json,result_json,qualification_hash,actor_user_id) VALUES (?,?,?,1,100,'{}','{}',?,?)")->execute([$org,$q1Public,$releaseId,$q1Hash,$actor]);$q1Id=(int)$pdo->lastInsertId();

$config=['epochs'=>100,'batchSize'=>16,'imageSize'=>640,'seed'=>74];
$x=glasses_vision_experiment_create($pdo,$org,['hypothesis'=>'Production corrections improve pepperoni recall without changing runtime family.','championModelPublicId'=>$championPublic,'trainingReleasePublicId'=>$releasePublic,'trainingConfig'=>$config],$actor);
v75_assert($x['status']==='ready'&&$x['candidateDataset']['datasetHash']===$datasetHash,'Experiment must bind frozen dataset and start ready.');
$again=glasses_vision_experiment_create($pdo,$org,['hypothesis'=>'Production corrections improve pepperoni recall without changing runtime family.','championModelPublicId'=>$championPublic,'trainingReleasePublicId'=>$releasePublic,'trainingConfig'=>$config],$actor);
v75_assert($again['publicId']===$x['publicId'],'Identical experiment contract must deduplicate.');

$x=glasses_vision_experiment_start($pdo,$org,$x['publicId'],$actor);
v75_assert($x['status']==='running','Ready experiment must transition to running.');

$q2Public='vision-qualification-'.$slug.'-2';$q2Hash=hash('sha256','q2-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_training_qualifications (organization_id,public_id,training_release_id,passed,score,policy_json,result_json,qualification_hash,actor_user_id) VALUES (?,?,?,1,100,'{}','{}',?,?)")->execute([$org,$q2Public,$releaseId,$q2Hash,$actor]);
$wrongQualificationRun=glasses_vision_lineage_register_training_run($pdo,$org,['releaseHash'=>$releaseHash,'qualificationHash'=>$q2Hash,'runKey'=>'wrong-q-'.$slug,'trainer'=>'gelato-yolo','trainerVersion'=>'v1','status'=>'completed','config'=>$config,'metrics'=>['map50'=>.9],'outputSha256'=>hash('sha256','wrong-q-output-'.$slug)],$actor);
$rejected=false;try{glasses_vision_experiment_attach_run($pdo,$org,$x['publicId'],$wrongQualificationRun['publicId'],$actor);}catch(InvalidArgumentException){$rejected=true;}
v75_assert($rejected,'Experiment must reject a training run using a different qualification attestation.');

$wrongConfigRun=glasses_vision_lineage_register_training_run($pdo,$org,['releaseHash'=>$releaseHash,'qualificationHash'=>$q1Hash,'runKey'=>'wrong-cfg-'.$slug,'trainer'=>'gelato-yolo','trainerVersion'=>'v1','status'=>'completed','config'=>['epochs'=>101,'batchSize'=>16,'imageSize'=>640,'seed'=>74],'metrics'=>['map50'=>.9],'outputSha256'=>hash('sha256','wrong-cfg-output-'.$slug)],$actor);
$rejected=false;try{glasses_vision_experiment_attach_run($pdo,$org,$x['publicId'],$wrongConfigRun['publicId'],$actor);}catch(InvalidArgumentException){$rejected=true;}
v75_assert($rejected,'Experiment must reject a training run whose governed configuration does not match.');

$outputHash=hash('sha256','challenger-output-'.$slug);
$run=glasses_vision_lineage_register_training_run($pdo,$org,['releaseHash'=>$releaseHash,'qualificationHash'=>$q1Hash,'runKey'=>'experiment-'.$slug,'trainer'=>'gelato-yolo','trainerVersion'=>'v1','status'=>'completed','config'=>$config,'metrics'=>['map50'=>.93,'recall'=>.91],'outputSha256'=>$outputHash],$actor);
$x=glasses_vision_experiment_attach_run($pdo,$org,$x['publicId'],$run['publicId'],$actor);
v75_assert($x['status']==='trained'&&$x['trainingRun']['outputSha256']===$outputHash,'Matching completed training run must attach to experiment.');

$badFamilyPublic='vision-model-bad-family-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'plate_detector','Wrong Family','v2','onnx','air3','https://example.test/bad.onnx',?,100,'ready',?)")->execute([$org,$badFamilyPublic,$outputHash,$actor]);
$rejected=false;try{glasses_vision_experiment_bind_challenger($pdo,$org,$x['publicId'],$badFamilyPublic,$actor);}catch(InvalidArgumentException){$rejected=true;}
v75_assert($rejected,'Challenger must match champion detector/runtime/platform family.');

$badHashPublic='vision-model-bad-hash-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','Bad Hash','v2','onnx','air3','https://example.test/badhash.onnx',?,100,'ready',?)")->execute([$org,$badHashPublic,hash('sha256','other-'.$slug),$actor]);
$rejected=false;try{glasses_vision_experiment_bind_challenger($pdo,$org,$x['publicId'],$badHashPublic,$actor);}catch(InvalidArgumentException){$rejected=true;}
v75_assert($rejected,'Challenger artifact hash must exactly equal experiment training output.');

$challengerPublic='vision-model-challenger-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','Challenger Detector','v2','onnx','air3','https://example.test/challenger.onnx',?,100,'ready',?)")->execute([$org,$challengerPublic,$outputHash,$actor]);
$x=glasses_vision_experiment_bind_challenger($pdo,$org,$x['publicId'],$challengerPublic,$actor);
v75_assert($x['status']==='completed'&&$x['challenger']['publicId']===$challengerPublic,'Valid challenger must complete the experiment contract.');
v75_assert(count($x['events'])===4,'Experiment must preserve created, started, run-attached and challenger-bound audit events.');
v75_assert(v75_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_package' AND from_public_id=? AND relation='champion_for' AND to_kind='model_experiment'",[$org,$championPublic])===1,'Champion lineage edge is required.');
v75_assert(v75_count($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_experiment' AND from_public_id=? AND relation='produced_challenger' AND to_public_id=?",[$org,$x['publicId'],$challengerPublic])===1,'Experiment-to-challenger lineage edge is required.');
v75_assert(v75_count($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=?",[$org])===0,'Completing an experiment must never activate or create a rollout.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-experiments.php');
foreach(['model_experiment.create','model_experiment.start','model_experiment.attach_run','model_experiment.bind_challenger'] as $action)v75_assert(str_contains($api,$action),'V7 experiment API missing '.$action);
v75_assert(str_contains($page,'Model Improvement Experiments'),'Vision Lab must expose Section 5 experiments.');
v75_assert(!str_contains($source,'kds_transition')&&!str_contains($source,'rollout.activate')&&!str_contains($source,'rollout.advance'),'Experiment runtime must not mutate kitchen truth or rollout state.');

echo "vision-lab-v7-model-improvement-experiment-ok\n";
