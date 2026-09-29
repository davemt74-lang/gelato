<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-mining.php';

function v73_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v73_rows(array $rows,string $type):array{return array_values(array_filter($rows,static fn($r)=>($r['candidateType']??'')===$type));}
$pdo=app_pdo();
v73_assert(glasses_vision_mining_ready($pdo),'V7 hard-example mining migration must be installed.');

$slug='vl73-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Mining '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Mining','Reviewer','Mining Reviewer']);$actor=(int)$pdo->lastInsertId();

$modelPublic='vision-model-'.$slug;$modelHash=hash('sha256','model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','Mining Detector','v73','onnx','air3','https://example.test/v73.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();

$datasetPublic='vision-dataset-'.$slug;$datasetHash=hash('sha256','dataset-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,dataset_hash,row_count,class_count,created_by,frozen_by,frozen_at) VALUES (?,?,?,'v73','frozen',?,10,2,?,?,NOW(6))")
 ->execute([$org,$datasetPublic,'Mining Corpus',$datasetHash,$actor,$actor]);$datasetId=(int)$pdo->lastInsertId();
$planPublic='vision-split-'.$slug;$planHash=hash('sha256','split-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_split_plans (organization_id,public_id,dataset_id,status,seed,train_ratio,val_ratio,test_ratio,policy_json,manifest_json,plan_hash,created_by,applied_by,applied_at) VALUES (?,?,?,'applied',74,.70,.15,.15,'{}','{}',?,?,?,NOW(6))")
 ->execute([$org,$planPublic,$datasetId,$planHash,$actor,$actor]);$planId=(int)$pdo->lastInsertId();
$releasePublic='vision-release-'.$slug;$releaseHash=hash('sha256','release-'.$slug);
$manifest=['schema'=>GLASSES_VISION_TRAINING_RELEASE_SCHEMA,'dataset'=>['publicId'=>$datasetPublic,'datasetHash'=>$datasetHash],'splitPlan'=>['publicId'=>$planPublic,'planHash'=>$planHash],'trainingProfile'=>['name'=>'yolo11n_640'],'items'=>[]];
$pdo->prepare("INSERT INTO glasses_vision_training_releases (organization_id,public_id,dataset_id,split_plan_id,status,training_profile,profile_json,manifest_json,release_hash,artifact_relative_path,artifact_sha256,artifact_bytes,created_by) VALUES (?,?,?,?,'built','yolo11n_640','{}',?,?,?, ?,1,?)")
 ->execute([$org,$releasePublic,$datasetId,$planId,json_encode($manifest,JSON_UNESCAPED_SLASHES),$releaseHash,$org.'/fixture.zip',hash('sha256','zip-'.$slug),$actor]);
glasses_vision_lineage_edge($pdo,$org,'training_release',$releasePublic,$releaseHash,'trained_model','model_package',$modelPublic,$modelHash,['fixture'=>true],$actor);

$insert=$pdo->prepare("INSERT INTO glasses_vision_production_errors
 (organization_id,public_id,event_key,event_hash,source_type,error_type,outcome,model_package_id,predicted_component_key,expected_component_key,confidence,context_json,actor_user_id,occurred_at)
 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$events=[];
for($i=1;$i<=5;$i++)$events[]=['fp'.$i,'false_positive','rejected','ingredient:olive',null,.96,['lighting'=>'low','pose'=>'side','buildStep'=>'topping','captureEnvironment'=>'rush']];
$events[]=['fn','false_negative','missed',null,'ingredient:pepperoni',.40,['lighting'=>'low','pose'=>'top_down','buildStep'=>'topping','captureEnvironment'=>'rush']];
$events[]=['mis','misclassification','reclassified','ingredient:sausage','ingredient:pepperoni',.94,['lighting'=>'bright','pose'=>'top_down','buildStep'=>'topping','captureEnvironment'=>'prep']];
$events[]=['low','low_confidence','confirmed','ingredient:pepperoni','ingredient:pepperoni',.35,['lighting'=>'dim','pose'=>'oblique','buildStep'=>'topping','captureEnvironment'=>'rush']];
foreach($events as $i=>$e){
 [$suffix,$type,$outcome,$pred,$expected,$confidence,$ctx]=$e;
 $public='vision-prod-error-'.$slug.'-'.$suffix;$hash=hash('sha256','error-'.$slug.'-'.$suffix);
 $snapshot=['schema'=>GLASSES_VISION_PRODUCTION_ERROR_SCHEMA,'context'=>$ctx];
 $insert->execute([$org,$public,'event-'.$slug.'-'.$suffix,$hash,'fixture',$type,$outcome,$modelId,$pred,$expected,$confidence,json_encode($snapshot,JSON_UNESCAPED_SLASHES),$actor,'2026-09-28 18:'.str_pad((string)$i,2,'0',STR_PAD_LEFT).':00']);
}

$run=glasses_vision_mining_run($pdo,$org,['maxOpenPerCluster'=>2,'maxOpenPerModel'=>10,'maxOpenPerRun'=>20],$actor);
$r=$run['result'];$cands=$r['candidates'];
v73_assert(($r['summary']['sourceEvents']??0)===8&&($r['summary']['eligibleCandidates']??0)===8,'Mining must consider each immutable production error exactly once.');
v73_assert(preg_match('/^[a-f0-9]{64}$/',$run['sourceFingerprint'])===1&&preg_match('/^[a-f0-9]{64}$/',$run['runHash'])===1,'Mining source set and result must be SHA-256 bound.');
foreach($cands as $candidate)v73_assert(preg_match('/^[a-f0-9]{64}$/',(string)$candidate['candidateHash'])===1,'Every mined candidate must carry an immutable SHA-256 evidence hash.');


$counter=v73_rows($cands,'counterexample');$missed=v73_rows($cands,'missed_positive');$mis=v73_rows($cands,'misclassification');$hard=v73_rows($cands,'hard_positive');
v73_assert(count($counter)===5&&count($missed)===1&&count($mis)===1&&count($hard)===1,'Error types must map to the correct mining candidate classes.');
$openCounter=count(array_filter($counter,static fn($c)=>$c['status']==='open'));
$suppressedCounter=count(array_filter($counter,static fn($c)=>$c['status']==='suppressed'));
v73_assert($openCounter===2&&$suppressedCounter===3,'Cluster dominance cap must keep only the configured number of repeated examples open.');
v73_assert(($r['summary']['open']??0)===5&&($r['summary']['suppressed']??0)===3,'Mining summary must reflect bounded open and suppressed evidence.');

$fn=$missed[0];$mi=$mis[0];
v73_assert($fn['score']>=$mi['score']-8,'False negatives must remain high-value mining signals.');
v73_assert(($mi['reasons']['confidenceReason']??'')==='confident_wrong_prediction','Confident wrong predictions must receive an explicit mining reason.');
v73_assert(($fn['lineage']['datasetPublicId']??null)===$datasetPublic&&($fn['lineage']['trainingReleasePublicId']??null)===$releasePublic,'Mined candidates must retain exact V6 training ancestry.');
v73_assert(($r['governance']['automaticDatasetInclusion']??true)===false&&($r['governance']['automaticRetraining']??true)===false&&($r['governance']['automaticRollout']??true)===false,'Mining must remain advisory and never auto-train or deploy.');

$again=glasses_vision_mining_run($pdo,$org,['maxOpenPerCluster'=>2,'maxOpenPerModel'=>10,'maxOpenPerRun'=>20],$actor);
v73_assert($again['publicId']===$run['publicId'],'Identical policy over identical source evidence must deduplicate to the same mining run.');

$looser=glasses_vision_mining_run($pdo,$org,['maxOpenPerCluster'=>5,'maxOpenPerModel'=>10,'maxOpenPerRun'=>20],$actor);
v73_assert($looser['publicId']!==$run['publicId'],'A materially different mining policy must produce a new immutable run.');
v73_assert((int)$pdo->query("SELECT COUNT(*) FROM glasses_vision_mined_candidates WHERE organization_id={$org}")->fetchColumn()===16,'Run-scoped candidate persistence must preserve both immutable policy results.');

$rows=glasses_vision_mining_candidates($pdo,$org,'open',200);
$target=$rows[0]??null;v73_assert(is_array($target),'At least one open mining candidate is required.');
$dismissed=glasses_vision_mining_dismiss($pdo,$org,(string)$target['publicId'],'Already represented in the next review batch.',$actor);
v73_assert($dismissed['status']==='dismissed'&&$dismissed['dismissedReason']==='Already represented in the next review batch.','Candidates must support explicit reasoned dismissal.');
$dismissedAgain=glasses_vision_mining_dismiss($pdo,$org,(string)$target['publicId'],'Ignored second reason.',$actor);
v73_assert($dismissedAgain['dismissedReason']==='Already represented in the next review batch.','Dismissal must be idempotent and preserve the original governance reason.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-mining.php');
foreach(['hard_example.mine','hard_example.dismiss'] as $action)v73_assert(str_contains($api,$action),'V7 mining API missing '.$action);
v73_assert(str_contains($page,'Hard Example &amp; Counterexample Mining'),'Vision Lab must expose Section 3 mining.');
v73_assert(!str_contains($source,'kds_transition')&&!str_contains($source,'dataset.add_sample')&&!str_contains($source,'rollout.activate'),'Mining must not mutate kitchen truth, datasets, training, or rollout state.');

echo "vision-lab-v7-hard-example-mining-ok\n";
