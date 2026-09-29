<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-annotation-workbench.php';

function v113_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v113_user(PDO $pdo,int $org,int $location,string $email,string $name):int{
  $parts=preg_split('/\s+/',trim($name),2);$first=$parts[0]?:'Vision';$last=$parts[1]??'User';
  $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture-hash',?,?,?,'active')")->execute([$email,$first,$last,$name]);$id=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,status) VALUES (?,?,?,'active')")->execute([$org,$id,$location]);return $id;
}
function v113_device(PDO $pdo,int $org,int $location,string $stationPublic,int $actor,string $hardware):array{
  $grant=glasses_create_pairing_grant($pdo,$org,$location,$stationPublic,$actor,10);
  $paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>$hardware,'displayName'=>$hardware,'platform'=>'browser_simulator','sdkVersion'=>'sim','appVersion'=>'v11','capabilities'=>['hardwareRuntimeAdapterId'=>'simulator.v1']]);
  return glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
}

$pdo=app_pdo();v113_assert(glasses_v11_annotation_ready($pdo),'V11 annotation workbench migration must be installed.');
$slug='v113-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['V11 Annotation '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Annotation Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$admin=v113_user($pdo,$org,$location,$slug.'-admin@example.test','Annotation Admin');
$operator=v113_user($pdo,$org,$location,$slug.'-cook@example.test','Annotation Cook');
$r1=v113_user($pdo,$org,$location,$slug.'-r1@example.test','Reviewer One');
$r2=v113_user($pdo,$org,$location,$slug.'-r2@example.test','Reviewer Two');
$adj=v113_user($pdo,$org,$location,$slug.'-adj@example.test','Adjudicator User');

$station=kds_station_save($pdo,$org,$location,['name'=>'Annotation Line','slug'=>'annotation-'.$slug,'targetSeconds'=>300],$admin);
$device=v113_device($pdo,$org,$location,(string)$station['public_id'],$admin,'V11-ANNOTATION-'.$slug);
$program=glasses_training_program_create($pdo,$org,['name'=>'Annotation Program','mode'=>'training','locationId'=>$location,'stationPublicId'=>$station['public_id']],$admin);
$assignment=glasses_training_assignment_create($pdo,$org,['programPublicId'=>$program['public_id'],'userId'=>$operator,'devicePublicId'=>$device['public_id'],'supervisorUserId'=>$r1],$admin);
$session=glasses_training_session_start($pdo,$org,$assignment['public_id'],$device,$admin);

$image='iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAYElEQVR4nO3PQQ0AIBDAMED5SUcEj4ZkVbDtmVk/OzrgVQNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgNaA1oDWgPaBaIsAgBhHc02AAAAAElFTkSuQmCC';
$e=glasses_v11_production_evidence_capture($pdo,$device,[
 'trainingSessionPublicId'=>$session['public_id'],'imageBase64'=>$image,'capturedAt'=>gmdate('c'),
 'recipeStepKey'=>'assemble.base','trainingEligibility'=>'review','privacyScope'=>'training_opt_in',
 'annotations'=>[['label'=>'tomato','bbox'=>['x'=>0.2,'y'=>0.2,'width'=>0.3,'height'=>0.3]]]
],$admin);

$c1=glasses_v11_annotation_submit($pdo,$org,[
 'evidencePublicId'=>$e['publicId'],'correctionKey'=>'label-'.$slug,'correctionType'=>'combined','proposedLabel'=>'pepperoni',
 'proposedRecipeStepKey'=>'assemble.toppings','proposedAnnotations'=>[['label'=>'pepperoni','bbox'=>['x'=>0.25,'y'=>0.25,'width'=>0.28,'height'=>0.28]]],
 'reason'=>'Original label and box were incorrect.'
],$operator);
v113_assert($c1['status']==='submitted'&&$c1['previousLabel']==='tomato'&&$c1['proposedLabel']==='pepperoni','Correction must snapshot previous/proposed annotation state.');

$selfBlocked=false;try{glasses_v11_annotation_review($pdo,$org,$c1['publicId'],'approve','self',$operator);}catch(InvalidArgumentException){$selfBlocked=true;}
v113_assert($selfBlocked,'Submitter must not review own correction.');

$one=glasses_v11_annotation_review($pdo,$org,$c1['publicId'],'approve','Looks correct',$r1);
v113_assert($one['status']==='submitted','One review must not finalize a correction.');
$two=glasses_v11_annotation_review($pdo,$org,$c1['publicId'],'approve','Confirmed',$r2);
v113_assert($two['status']==='approved','Two matching independent reviews must approve correction.');

$q=$pdo->prepare("SELECT canonical_label,annotation_json,review_status,review_outcome FROM glasses_vision_training_samples WHERE organization_id=? AND public_id=?");$q->execute([$org,$e['samplePublicId']]);$sample=$q->fetch();
v113_assert($sample['canonical_label']==='pepperoni'&&$sample['review_status']==='approved'&&$sample['review_outcome']==='corrected','Approved correction must project to canonical sample.');
v113_assert(glasses_v11_production_evidence_get($pdo,$org,$e['publicId'])['recipeStepKey']==='assemble.toppings','Approved correction must project corrected recipe step.');

$c2=glasses_v11_annotation_submit($pdo,$org,[
 'evidencePublicId'=>$e['publicId'],'correctionKey'=>'ingredient-'.$slug,'correctionType'=>'ingredient','proposedLabel'=>'mushroom',
 'proposedAnnotations'=>[['label'=>'mushroom','bbox'=>['x'=>0.25,'y'=>0.25,'width'=>0.28,'height'=>0.28]]],'reason'=>'Second correction requires adjudication.'
],$operator);
glasses_v11_annotation_review($pdo,$org,$c2['publicId'],'approve','approve',$r1);
$conflict=glasses_v11_annotation_review($pdo,$org,$c2['publicId'],'reject','reject',$r2);
v113_assert($conflict['status']==='needs_adjudication','Conflicting independent reviews must require adjudication.');
$resolved=glasses_v11_annotation_adjudicate($pdo,$org,$c2['publicId'],'approve','Adjudicated from source image',$adj);
v113_assert($resolved['status']==='approved'&&$resolved['adjudicatorName']==='Adjudicator User','Independent adjudicator must resolve disagreement.');

$q=$pdo->prepare("SELECT canonical_label,adjudicator_user_id FROM glasses_vision_training_samples WHERE organization_id=? AND public_id=?");$q->execute([$org,$e['samplePublicId']]);$sample=$q->fetch();
v113_assert($sample['canonical_label']==='mushroom'&&(int)$sample['adjudicator_user_id']===$adj,'Adjudicated approval must project to canonical sample and identity.');

$reviewCount=(int)$pdo->query("SELECT COUNT(*) FROM glasses_vision_sample_reviews WHERE correction_id IS NOT NULL")->fetchColumn();
v113_assert($reviewCount===4,'Reviews must be scoped to immutable correction revisions.');
$eventCount=(int)$pdo->query("SELECT COUNT(*) FROM glasses_vision_annotation_correction_events")->fetchColumn();
v113_assert($eventCount>=7,'Correction submit/review/adjudication history must be durable.');

$idempotent=glasses_v11_annotation_submit($pdo,$org,[
 'evidencePublicId'=>$e['publicId'],'correctionKey'=>'ingredient-'.$slug,'correctionType'=>'ingredient','proposedLabel'=>'mushroom',
 'proposedAnnotations'=>[['label'=>'mushroom','bbox'=>['x'=>0.25,'y'=>0.25,'width'=>0.28,'height'=>0.28]]],'reason'=>'Second correction requires adjudication.'
],$operator);
v113_assert($idempotent['publicId']===$c2['publicId'],'Same immutable correction key/payload must be idempotent.');

$catalog=glasses_v11_annotation_catalog($pdo,$org,20);v113_assert(count($catalog['corrections'])===2,'Workbench catalog must expose immutable correction revisions.');

$source=file_get_contents(__DIR__.'/../includes/glasses-annotation-workbench.php');
$migration=file_get_contents(__DIR__.'/../database/20261129_v11_annotation_corrections.sql');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$ui=file_get_contents(__DIR__.'/../assets/js/glasses-annotation-workbench.js');
v113_assert(str_contains($migration,'correction_id BIGINT UNSIGNED NULL'),'Existing sample reviews must be scoped to correction revisions.');
v113_assert(str_contains($api,'annotation.submit')&&str_contains($api,'annotation.review')&&str_contains($api,'annotation.adjudicate'),'Vision Lab API must expose workbench lifecycle.');
v113_assert(str_contains($page,'V11 Human Corrections &amp; Annotation Workbench'),'Vision Lab must expose V11 correction workbench.');
v113_assert(str_contains($ui,'New correction')&&str_contains($ui,'Adjudicate'),'Workbench UI must expose correction and adjudication.');
foreach(['kds_transition(','glasses_handoff_to_expo(','glasses_build_confirm(','glasses_vision_model_rollout_activate('] as $forbidden)
 v113_assert(!str_contains($source,$forbidden),'Annotation workbench must not mutate kitchen/model authority: '.$forbidden);

echo "glasses-v11-annotation-workbench-ok\n";
