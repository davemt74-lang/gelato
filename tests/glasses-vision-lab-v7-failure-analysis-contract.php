<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-vision-failure-analysis.php';

function v72_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v72_find(array $rows,string $label):?array{foreach($rows as $r)if(($r['label']??null)===$label)return $r;return null;}

$pdo=app_pdo();
v72_assert(glasses_vision_failure_analysis_ready($pdo),'V7 failure-analysis migration must be installed.');

$slug='vl72-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Failure Analysis '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Failure','Analyst','Failure Analyst']);$actor=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'pizza-'.$slug,'targetSeconds'=>300],$actor);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Pepperoni Pizza',?,1)")->execute([$org,$section,'pepperoni-'.$slug]);$menuItem=(int)$pdo->lastInsertId();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V72-'.$slug,'displayName'=>'Failure AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);$deviceId=(int)$device['id'];$devicePublic=(string)$device['public_id'];

$modelPublic='vision-model-'.$slug;$modelHash=hash('sha256','model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','Kitchen Detector','v72','onnx','air3','https://example.test/v72.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();

$datasetPublic='vision-dataset-'.$slug;$datasetHash=hash('sha256','dataset-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,status,dataset_hash,row_count,class_count,created_by,frozen_by,frozen_at) VALUES (?,?,?,'v72','frozen',?,10,2,?,?,NOW(6))")
 ->execute([$org,$datasetPublic,'Failure Corpus',$datasetHash,$actor,$actor]);$datasetId=(int)$pdo->lastInsertId();
$planPublic='vision-split-'.$slug;$planHash=hash('sha256','split-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_dataset_split_plans (organization_id,public_id,dataset_id,status,seed,train_ratio,val_ratio,test_ratio,policy_json,manifest_json,plan_hash,created_by,applied_by,applied_at) VALUES (?,?,?,'applied',74,.70,.15,.15,'{}','{}',?,?,?,NOW(6))")
 ->execute([$org,$planPublic,$datasetId,$planHash,$actor,$actor]);$planId=(int)$pdo->lastInsertId();
$releasePublic='vision-release-'.$slug;$releaseHash=hash('sha256','release-'.$slug);
$manifest=['schema'=>GLASSES_VISION_TRAINING_RELEASE_SCHEMA,'dataset'=>['publicId'=>$datasetPublic,'datasetHash'=>$datasetHash],'splitPlan'=>['publicId'=>$planPublic,'planHash'=>$planHash],'trainingProfile'=>['name'=>'yolo11n_640'],'items'=>[]];
$pdo->prepare("INSERT INTO glasses_vision_training_releases (organization_id,public_id,dataset_id,split_plan_id,status,training_profile,profile_json,manifest_json,release_hash,artifact_relative_path,artifact_sha256,artifact_bytes,created_by) VALUES (?,?,?,?,'built','yolo11n_640','{}',?,?,?, ?,1,?)")
 ->execute([$org,$releasePublic,$datasetId,$planId,json_encode($manifest,JSON_UNESCAPED_SLASHES),$releaseHash,$org.'/fixture.zip',hash('sha256','zip-'.$slug),$actor]);
glasses_vision_lineage_edge($pdo,$org,'training_release',$releasePublic,$releaseHash,'trained_model','model_package',$modelPublic,$modelHash,['fixture'=>true],$actor);

$insert=$pdo->prepare("INSERT INTO glasses_vision_production_errors
 (organization_id,public_id,event_key,event_hash,source_type,error_type,outcome,device_id,location_id,station_id,menu_item_id,model_package_id,predicted_component_key,expected_component_key,confidence,context_json,actor_user_id,occurred_at)
 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$events=[
 ['a','misclassification','reclassified','ingredient:sausage','ingredient:pepperoni',.42,['lighting'=>'low','pose'=>'top_down','buildStep'=>'topping','captureEnvironment'=>'dinner_rush'],'2026-09-28 18:00:00'],
 ['b','false_positive','rejected','ingredient:olive',null,.93,['lighting'=>'low','pose'=>'side','buildStep'=>'topping','captureEnvironment'=>'dinner_rush'],'2026-09-28 18:05:00'],
 ['c','false_negative','missed',null,'ingredient:pepperoni',null,['lighting'=>'bright','pose'=>'top_down','buildStep'=>'sauce','captureEnvironment'=>'prep'],'2026-09-28 18:10:00'],
];
foreach($events as $i=>$e){
 [$suffix,$type,$outcome,$pred,$expected,$confidence,$ctx,$time]=$e;
 $public='vision-prod-error-'.$slug.'-'.$suffix;
 $snapshot=['context'=>$ctx,'schema'=>GLASSES_VISION_PRODUCTION_ERROR_SCHEMA];
 $hash=hash('sha256','event-'.$slug.'-'.$suffix);
 $insert->execute([$org,$public,'event-'.$slug.'-'.$suffix,$hash,'fixture',$type,$outcome,$deviceId,$location,(int)$station['id'],$menuItem,$i<2?$modelId:null,$pred,$expected,$confidence,json_encode($snapshot,JSON_UNESCAPED_SLASHES),$actor,$time]);
}

$analysis=glasses_vision_failure_analysis_run($pdo,$org,[],$actor);
$r=$analysis['result'];
v72_assert($analysis['eventCount']===3&&$r['eventCount']===3,'Analysis must include all matching production errors.');
v72_assert(preg_match('/^[a-f0-9]{64}$/',$analysis['analysisHash'])===1&&preg_match('/^[a-f0-9]{64}$/',$r['sourceFingerprint'])===1,'Analysis and exact source set must be hash-bound.');
v72_assert(count($r['sourceEvents'])===3,'Analysis must retain exact source event identities and hashes.');

$mis=v72_find($r['dimensions']['errorType'],'misclassification');
v72_assert(($mis['count']??0)===1,'Error-type grouping must preserve misclassification counts.');
$low=v72_find($r['dimensions']['lighting'],'low');
v72_assert(($low['count']??0)===2&&abs((float)$low['share']-(2/3))<.00001,'Lighting grouping must identify concentrated failures without inventing a denominator.');
$topping=v72_find($r['dimensions']['buildStep'],'topping');
v72_assert(($topping['count']??0)===2,'Build-step grouping must preserve production context.');
$device=v72_find($r['dimensions']['device'],'Failure AIR3');
v72_assert(($device['count']??0)===3,'Device grouping must preserve hardware attribution.');
$model=v72_find($r['dimensions']['model'],'Kitchen Detector v72 · '.$modelPublic);
v72_assert(($model['count']??0)===2,'Model grouping must preserve package/version attribution.');

v72_assert(count($r['modelAncestry'])===1,'Only attributed models should produce training ancestry.');
$ancestry=$r['modelAncestry'][0]['lineage']??[];
v72_assert(($ancestry['datasetPublicId']??null)===$datasetPublic&&($ancestry['trainingReleasePublicId']??null)===$releasePublic,'Failure analysis must trace model failures back to exact dataset and training release.');
v72_assert(($ancestry['splitPlanHash']??null)===$planHash&&($ancestry['trainingProfile']??null)==='yolo11n_640','Training ancestry must preserve governed split and profile.');
v72_assert(($r['notes']['denominatorAvailable']??true)===false&&($r['notes']['rateClaimed']??true)===false,'Failure analysis must explicitly avoid claiming error rates without a denominator.');

$again=glasses_vision_failure_analysis_run($pdo,$org,[],$actor);
v72_assert($again['publicId']===$analysis['publicId'],'Identical source evidence and filters must deduplicate to the same immutable analysis snapshot.');

$filtered=glasses_vision_failure_analysis_run($pdo,$org,['modelPackagePublicId'=>$modelPublic],$actor);
v72_assert($filtered['eventCount']===2,'Model filter must isolate failures for one exact model package.');
v72_assert($filtered['analysisHash']!==$analysis['analysisHash'],'Different filters/source set must create a different immutable analysis snapshot.');

$badRange=false;try{glasses_vision_failure_analysis_run($pdo,$org,['from'=>'2026-09-29','to'=>'2026-09-28'],$actor);}catch(InvalidArgumentException){$badRange=true;}
v72_assert($badRange,'Invalid analysis date ranges must fail closed.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
v72_assert(str_contains($api,'failure_analysis.run'),'V7 API must expose explicit failure analysis.');
v72_assert(str_contains($page,'Lineage-Aware Failure Analysis'),'Vision Lab must expose the Section 2 analysis workspace.');
v72_assert(!str_contains(file_get_contents(__DIR__.'/../includes/glasses-vision-failure-analysis.php'),'kds_transition'),'Failure analysis must remain read-only relative to kitchen lifecycle truth.');

echo "vision-lab-v7-failure-analysis-ok\n";
