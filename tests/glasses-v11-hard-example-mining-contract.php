<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-v11-mining.php';

function v114_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v114_user(PDO $pdo,int $org,int $location,string $email,string $name):int{
 $p=preg_split('/\s+/',trim($name),2);$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture-hash',?,?,?,'active')")->execute([$email,$p[0]?:'Vision',$p[1]??'User',$name]);$id=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,status) VALUES (?,?,?,'active')")->execute([$org,$id,$location]);return $id;
}
function v114_device(PDO $pdo,int $org,int $location,string $stationPublic,int $actor,string $hardware):array{
 $g=glasses_create_pairing_grant($pdo,$org,$location,$stationPublic,$actor,10);
 $p=glasses_pair_device($pdo,(string)$g['pairingCode'],['hardwareIdentifier'=>$hardware,'displayName'=>$hardware,'platform'=>'browser_simulator']);
 return glasses_authenticate_token($pdo,(string)$p['deviceToken']);
}
$pdo=app_pdo();v114_assert(glasses_v11_mining_ready($pdo),'V11 mining migration must be installed.');
$slug='v114-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['V11 Mining '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Mining Kitchen','Phoenix','AZ','active')")->execute([$org]);$loc=(int)$pdo->lastInsertId();
$admin=v114_user($pdo,$org,$loc,$slug.'-a@example.test','Mining Admin');$cook=v114_user($pdo,$org,$loc,$slug.'-c@example.test','Mining Cook');$r1=v114_user($pdo,$org,$loc,$slug.'-r1@example.test','Review One');$r2=v114_user($pdo,$org,$loc,$slug.'-r2@example.test','Review Two');
$station=kds_station_save($pdo,$org,$loc,['name'=>'Mining Line','slug'=>'mining-'.$slug,'targetSeconds'=>300],$admin);
$device=v114_device($pdo,$org,$loc,(string)$station['public_id'],$admin,'V11-MINING-'.$slug);
$program=glasses_training_program_create($pdo,$org,['name'=>'Mining Program','mode'=>'training','locationId'=>$loc,'stationPublicId'=>$station['public_id']],$admin);
$assignment=glasses_training_assignment_create($pdo,$org,['programPublicId'=>$program['public_id'],'userId'=>$cook,'devicePublicId'=>$device['public_id']],$admin);
$session=glasses_training_session_start($pdo,$org,$assignment['public_id'],$device,$admin);
$image='iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAYElEQVR4nO3PQQ0AIBDAMED5SUcEj4ZkVbDtmVk/OzrgVQNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgPaBaIsAgBhHc02AAAAAElFTkSuQmCC';
$e=glasses_v11_production_evidence_capture($pdo,$device,['trainingSessionPublicId'=>$session['public_id'],'imageBase64'=>$image,'capturedAt'=>gmdate('c'),'recipeStepKey'=>'assemble.toppings','validationOutcome'=>'final_failure','trainingEligibility'=>'review','privacyScope'=>'training_opt_in','annotations'=>[['label'=>'pepperoni','bbox'=>['x'=>.2,'y'=>.2,'width'=>.3,'height'=>.3]]]],$admin);
$c=glasses_v11_annotation_submit($pdo,$org,['evidencePublicId'=>$e['publicId'],'correctionKey'=>'miss-'.$slug,'correctionType'=>'false_negative','proposedLabel'=>'mushroom','proposedAnnotations'=>[['label'=>'mushroom','bbox'=>['x'=>.3,'y'=>.3,'width'=>.2,'height'=>.2]]],'reason'=>'Missed ingredient'], $cook);
glasses_v11_annotation_review($pdo,$org,$c['publicId'],'approve','yes',$r1);
glasses_v11_annotation_review($pdo,$org,$c['publicId'],'reject','no',$r2);

$run=glasses_v11_mining_run($pdo,$org,[],$admin);
v114_assert(($run['result']['governance']['automaticDatasetInclusion']??true)===false,'Mining cannot auto-include dataset samples.');
$rows=glasses_v11_mining_candidates($pdo,$org,'all',20);
v114_assert(count($rows)>=2,'V11 mining must rank both failed evidence and correction sources.');
$kinds=array_unique(array_column($rows,'sourceKind'));v114_assert(in_array('production_evidence',$kinds,true)&&in_array('correction',$kinds,true),'Unified queue must contain evidence and correction candidates.');
$top=$rows[0];v114_assert($top['score']>=90,'High-value failure/correction must rank highly.');
v114_assert(($top['trainingValue']['operatorUserId']??null)===$cook,'Training value must preserve operator identity.');
$disagreement=array_values(array_filter($rows,fn($x)=>$x['sourceKind']==='correction'))[0];
v114_assert(($disagreement['trainingValue']['disagreementBonus']??0)>0,'Reviewer disagreement must increase training value.');
$d=glasses_v11_mining_dismiss($pdo,$org,$disagreement['publicId'],'Not representative',$admin);v114_assert($d['status']==='dismissed','Candidate dismissal must be durable.');

glasses_v11_production_evidence_set_eligibility($pdo,$org,$e['publicId'],'excluded','Privacy exclusion',$admin);
$run2=glasses_v11_mining_run($pdo,$org,[],$admin);
v114_assert(($run2['result']['summary']['sourceEvents']??-1)===0,'Excluded evidence and its corrections must not enter new mining runs.');

$source=file_get_contents(__DIR__.'/../includes/glasses-v11-mining.php');
$migration=file_get_contents(__DIR__.'/../database/20261130_v11_hard_example_failure_mining.sql');
v114_assert(str_contains($migration,'ALTER TABLE glasses_vision_mined_candidates')&&!str_contains($migration,'CREATE TABLE'),'V11 must extend the canonical V7 mining queue.');
foreach(['glasses_vision_lab_add_dataset_sample(','glasses_vision_retraining_','glasses_vision_model_rollout_activate(','kds_transition('] as $forbidden)v114_assert(!str_contains($source,$forbidden),'Mining must not bypass downstream governance: '.$forbidden);
echo "glasses-v11-hard-example-mining-ok\n";
