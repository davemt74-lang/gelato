<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-lineage.php';

function v67_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
$pdo=app_pdo();
v67_assert(glasses_vision_lineage_ready($pdo),'V6 lineage migration must be installed.');

$slug='vl67-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Lineage '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'V6','Lineage','V6 Lineage Curator']);$actor=(int)$pdo->lastInsertId();

$datasetPublic='vision-dataset-'.$slug;$datasetHash=hash('sha256','dataset-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,dataset_hash,row_count,class_count,created_by,frozen_by,frozen_at) VALUES (?,?,?,'v7','frozen',?,1,1,?,?,NOW(6))")
 ->execute([$org,$datasetPublic,'Lineage Corpus',$datasetHash,$actor,$actor]);$datasetId=(int)$pdo->lastInsertId();

$samplePublic='vision-sample-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_training_samples (organization_id,public_id,source_type,source_reference,review_status,review_outcome,canonical_label,annotation_json,reviewer_user_id,created_by,reviewed_at) VALUES (?,?,'governed_training_media',?,'approved','approve','pepperoni','[]',?,?,NOW(6))")
 ->execute([$org,$samplePublic,'fixture',$actor,$actor]);$sampleId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_dataset_items (organization_id,dataset_id,sample_id,split_name,class_label,source_hash) VALUES (?,?,?,'train','pepperoni',?)")
 ->execute([$org,$datasetId,$sampleId,hash('sha256','source-'.$slug)]);
$pdo->prepare("INSERT INTO glasses_vision_dataset_curation_events (organization_id,dataset_id,sample_id,decision,reason,source,actor_user_id) VALUES (?,?,?,'include','hard corrected pepperoni example','manual',?)")
 ->execute([$org,$datasetId,$sampleId,$actor]);

$planPublic='vision-split-'.$slug;$planHash=hash('sha256','plan-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_split_plans (organization_id,public_id,dataset_id,status,seed,train_ratio,val_ratio,test_ratio,policy_json,manifest_json,plan_hash,created_by,applied_by,applied_at) VALUES (?,?,?,'applied',74,.70,.15,.15,'{}','{}',?,?,?,NOW(6))")
 ->execute([$org,$planPublic,$datasetId,$planHash,$actor,$actor]);$planId=(int)$pdo->lastInsertId();

$releasePublic='vision-release-'.$slug;$releaseHash=hash('sha256','release-'.$slug);
$manifest=['schema'=>GLASSES_VISION_TRAINING_RELEASE_SCHEMA,'releaseHash'=>$releaseHash,'dataset'=>['publicId'=>$datasetPublic,'datasetHash'=>$datasetHash],'splitPlan'=>['publicId'=>$planPublic,'planHash'=>$planHash],'trainingProfile'=>['name'=>'yolo11n_640'],'items'=>[[
 'samplePublicId'=>$samplePublic,'mediaPublicId'=>'vision-media-'.$slug,'split'=>'train','imageSha256'=>hash('sha256','image'),'labelSha256'=>hash('sha256','label'),'canonicalLabel'=>'pepperoni','curationDecision'=>'include','curationReason'=>'hard corrected pepperoni example','captureGroup'=>'burst-1','buildSessionPublicId'=>'build-1'
]]];
$pdo->prepare("INSERT INTO glasses_vision_training_releases (organization_id,public_id,dataset_id,split_plan_id,status,training_profile,profile_json,manifest_json,release_hash,artifact_relative_path,artifact_sha256,artifact_bytes,created_by) VALUES (?,?,?,?,'qualified','yolo11n_640','{}',?,?,?, ?,1,?)")
 ->execute([$org,$releasePublic,$datasetId,$planId,json_encode($manifest,JSON_UNESCAPED_SLASHES),$releaseHash,$org.'/fixture.zip',hash('sha256','zip'),$actor]);$releaseId=(int)$pdo->lastInsertId();

$qualificationHash=hash('sha256','qualification-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_training_qualifications (organization_id,public_id,training_release_id,passed,score,policy_json,result_json,qualification_hash,actor_user_id) VALUES (?,?,?,1,100,'{}','{}',?,?)")
 ->execute([$org,'vision-qualification-'.$slug,$releaseId,$qualificationHash,$actor]);

$outputHash=hash('sha256','onnx-'.$slug);
$run=glasses_vision_lineage_register_training_run($pdo,$org,[
 'releaseHash'=>$releaseHash,'qualificationHash'=>$qualificationHash,'runKey'=>'run-'.$slug,'trainer'=>'gelato-yolo','trainerVersion'=>'v1',
 'status'=>'completed','config'=>['epochs'=>100],'metrics'=>['map50'=>.91],'outputSha256'=>$outputHash
],$actor);
v67_assert($run['releaseHash']===$releaseHash&&$run['outputSha256']===$outputHash,'Training run must bind qualified release to exact output hash.');
$repeat=glasses_vision_lineage_register_training_run($pdo,$org,[
 'releaseHash'=>$releaseHash,'qualificationHash'=>$qualificationHash,'runKey'=>'run-'.$slug,'trainer'=>'gelato-yolo','trainerVersion'=>'v1',
 'status'=>'completed','config'=>['epochs'=>100],'metrics'=>['map50'=>.91],'outputSha256'=>$outputHash
],$actor);
v67_assert($repeat['publicId']===$run['publicId'],'Identical training-run registration must be idempotent.');
$conflict=false;try{glasses_vision_lineage_register_training_run($pdo,$org,[
 'releaseHash'=>$releaseHash,'qualificationHash'=>$qualificationHash,'runKey'=>'run-'.$slug,'trainer'=>'gelato-yolo','trainerVersion'=>'v1',
 'status'=>'completed','config'=>['epochs'=>101],'metrics'=>['map50'=>.91],'outputSha256'=>$outputHash
],$actor);}catch(InvalidArgumentException){$conflict=true;}
v67_assert($conflict,'Training run key reuse with changed immutable evidence must fail closed.');
$unfinished=false;try{glasses_vision_lineage_register_training_run($pdo,$org,[
 'releaseHash'=>$releaseHash,'qualificationHash'=>$qualificationHash,'runKey'=>'unfinished-'.$slug,'trainer'=>'gelato-yolo','status'=>'running'
],$actor);}catch(InvalidArgumentException){$unfinished=true;}
v67_assert($unfinished,'Lineage registration must accept completed training runs only.');


$modelPublic='vision-model-'.$slug;
$comparison=['schema'=>'gelato.vision_model_comparison.v1','eligible'=>true,'override'=>false,'overrideReason'=>null,'goldenTestHash'=>hash('sha256','golden'),'regressions'=>[],'summary'=>['map50Delta'=>.03,'recallDelta'=>.02,'demonstratedGain'=>true]];
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,metadata_json,created_by) VALUES (?,?, 'ingredient_detector','Pepperoni Detector','v8','onnx','air3','https://example.test/model.onnx',?,123,'ready',?,?)")
 ->execute([$org,$modelPublic,$outputHash,json_encode(['modelComparison'=>$comparison],JSON_UNESCAPED_SLASHES),$actor]);

$edge=glasses_vision_lineage_bind_model($pdo,$org,$run['publicId'],$modelPublic,$actor);
v67_assert($edge['relation']==='produced'&&$edge['to']['publicId']===$modelPublic,'Training run must bind to model package by artifact SHA-256.');
$edgeAgain=glasses_vision_lineage_bind_model($pdo,$org,$run['publicId'],$modelPublic,$actor);
v67_assert($edgeAgain['publicId']===$edge['publicId'],'Identical model lineage binding must be idempotent.');
$edgeConflict=false;try{
    glasses_vision_lineage_edge($pdo,$org,'training_run',$run['publicId'],$outputHash,'produced','model_package',$modelPublic,$outputHash,['modelName'=>'tampered'], $actor);
}catch(InvalidArgumentException){$edgeConflict=true;}
v67_assert($edgeConflict,'Existing lineage edge cannot be rewritten with different immutable evidence.');


$trace=glasses_vision_lineage_model_trace($pdo,$org,$modelPublic);
v67_assert(($trace['release']['releaseHash']??null)===$releaseHash,'Model trace must resolve exact training release.');
v67_assert(count($trace['release']['images']??[])===1&&$trace['release']['images'][0]['samplePublicId']===$samplePublic,'Model trace must answer which exact images trained the model.');
v67_assert(($trace['release']['images'][0]['curationReason']??'')==='hard corrected pepperoni example','Model trace must preserve why an image was included.');
v67_assert(($trace['comparison']['eligible']??false)===true,'Model trace must surface golden model comparison.');
v67_assert(count(glasses_vision_lineage_edges($pdo,$org,'model_package',$modelPublic))>=1,'Model package must have durable lineage edges.');

$sampleTrace=glasses_vision_lineage_sample_trace($pdo,$org,$samplePublic);
v67_assert(count($sampleTrace['models'])===1&&$sampleTrace['models'][0]['modelVersion']==='v8','Sample trace must answer which model versions used the sample.');
v67_assert(($sampleTrace['curation'][0]['reason']??'')==='hard corrected pepperoni example','Sample trace must answer why the sample was included.');

$source=file_get_contents(__DIR__.'/../includes/glasses-vision-lineage.php');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
foreach(['lineage.model.trace','lineage.sample.trace','lineage.dataset.diff','lineage.training_run.register','lineage.model.bind'] as $action)v67_assert(str_contains($api,$action),'Lineage API missing '.$action);
v67_assert(str_contains($source,'glasses_vision_shadow_runs')&&str_contains($source,'glasses_vision_canary_health_snapshots')&&str_contains($source,'glasses_vision_model_assignments'),'Model trace must connect shadow, canary, and production lifecycle truth.');
v67_assert(str_contains($source,"'label','split','curationDecision','curationReason','sourceHash','captureGroups','buildPublicId'"),'Dataset diff must compare material sample lineage fields.');
v67_assert(str_contains($page,'Dataset Lineage &amp; Model Traceability'),'Vision Lab must expose the lineage explorer.');

echo "vision-lab-v6-lineage-traceability-ok\n";
