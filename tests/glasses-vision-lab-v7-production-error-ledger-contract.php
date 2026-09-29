<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-feedback.php';

function v71_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v71_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}
$pdo=app_pdo();
v71_assert(glasses_vision_feedback_ready($pdo),'V7 production error ledger migration must be installed.');

$slug='vl71-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Feedback '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Vision','Reviewer','Vision Reviewer']);$actor=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'pizza-'.$slug,'targetSeconds'=>300],$actor);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Pepperoni Pizza',?,'Build to recipe.',1)")->execute([$org,$section,'pepperoni-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$keys=[];
foreach(['Pepperoni','Sausage'] as $i=>$name){
 $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")->execute([$org,$name,strtolower($name).'-'.$slug]);
 $iid=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)")->execute([$item,$iid,$name,$i+1]);
 $keys[$name]=glasses_build_component_key($name,$iid);
}
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V71-'.$slug,'displayName'=>'V7 AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
$sessionId=(int)v71_one($pdo,"SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);

$modelPublic='vision-model-'.$slug;$modelHash=hash('sha256','model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','Kitchen Detector','v71','onnx','air3','https://example.test/v71.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_assignments (organization_id,device_id,build_session_id,assignment_key,detector_name,package_id,action,selection,rollout_status,issued_at) VALUES (?,?,?,?,?,?,'apply','stable','active',NOW(6))")
 ->execute([$org,(int)$device['id'],$sessionId,hash('sha256','assign-'.$slug),'ingredient_detector',$modelId]);

usleep(2000);
glasses_build_observe($pdo,$device,$sessionPublic,[
 'observationKey'=>'obs-wrong-'.$slug,'componentKey'=>$keys['Sausage'],'observationAction'=>'added','quantity'=>1,'confidence'=>0.92,'trackingId'=>'track-'.$slug
]);
$correction=glasses_build_correct_observation($pdo,$device,$sessionPublic,[
 'correctionKey'=>'fix-'.$slug,'observationKey'=>'obs-wrong-'.$slug,'resolution'=>'replace',
 'targetComponentKey'=>$keys['Pepperoni'],'correctedQuantity'=>1,'hasCorrectedQuantity'=>true,
 'reason'=>'Cook corrected sausage prediction to pepperoni.','metadata'=>['input'=>'voice']
]);

$result=glasses_vision_feedback_sync_corrections($pdo,$org,$actor,100);
v71_assert($result['created']===1,'Correction sync must create exactly one production error event.');
$event=$result['events'][0];
v71_assert($event['errorType']==='misclassification'&&$event['outcome']==='reclassified','Reclassification must become a misclassification outcome.');
v71_assert($event['predictedComponentKey']===$keys['Sausage']&&$event['expectedComponentKey']===$keys['Pepperoni'],'Ledger must preserve predicted and corrected component identities.');
v71_assert($event['modelPackagePublicId']===$modelPublic,'Ledger must bind the model package assigned when the observation occurred.');
v71_assert(abs((float)$event['confidence']-.92)<.00001,'Ledger must preserve original inference confidence.');
v71_assert(preg_match('/^[a-f0-9]{64}$/',$event['eventHash'])===1,'Every production error must have an immutable SHA-256 event hash.');

$again=glasses_vision_feedback_sync_corrections($pdo,$org,$actor,100);
v71_assert($again['created']===0,'Correction sync must be idempotent.');
v71_assert((int)v71_one($pdo,"SELECT COUNT(*) FROM glasses_vision_production_errors WHERE organization_id=?",[$org])===1,'Idempotent sync must not duplicate events.');
v71_assert((int)v71_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_package' AND from_public_id=? AND relation='produced_error' AND to_kind='production_error'",[$org,$modelPublic])===1,'Production error must connect to the V6 model lineage graph.');

$conflict=false;
try{
 glasses_vision_feedback_record($pdo,$org,[
  'eventKey'=>$event['eventKey'],'sourceType'=>'human_correction','errorType'=>'misclassification','outcome'=>'reclassified',
  'buildSessionPublicId'=>$sessionPublic,'observationKey'=>'obs-wrong-'.$slug,
  'predictedComponentKey'=>$keys['Sausage'],'expectedComponentKey'=>$keys['Sausage']
 ],$actor);
}catch(InvalidArgumentException){$conflict=true;}
v71_assert($conflict,'Conflicting reuse of a production error event key must fail closed.');
$badCorrection=false;
try{
 glasses_vision_feedback_record($pdo,$org,[
  'eventKey'=>'bad-correction-'.$slug,'sourceType'=>'manual','errorType'=>'correction','outcome'=>'corrected',
  'buildSessionPublicId'=>$sessionPublic,'observationKey'=>'obs-wrong-'.$slug,'expectedComponentKey'=>$keys['Pepperoni'],
  'correctionId'=>PHP_INT_MAX
 ],$actor);
}catch(InvalidArgumentException){$badCorrection=true;}
v71_assert($badCorrection,'Correction linkage must reject IDs outside the governed organization/build session.');

$badExpected=false;
try{
 glasses_vision_feedback_record($pdo,$org,[
  'eventKey'=>'bad-expected-'.$slug,'sourceType'=>'validation','errorType'=>'false_negative','outcome'=>'missed',
  'buildSessionPublicId'=>$sessionPublic,'expectedComponentKey'=>'ingredient:999999999'
 ],$actor);
}catch(InvalidArgumentException){$badExpected=true;}
v71_assert($badExpected,'Expected production component must belong to the governed build recipe.');


$miss=glasses_vision_feedback_record($pdo,$org,[
 'eventKey'=>'manual-miss-'.$slug,'sourceType'=>'validation','errorType'=>'false_negative','outcome'=>'missed',
 'buildSessionPublicId'=>$sessionPublic,'expectedComponentKey'=>$keys['Pepperoni'],
 'context'=>['reason'=>'Expected ingredient was not detected before manual review.']
],$actor);
v71_assert($miss['modelPackagePublicId']===$modelPublic&&$miss['observationKey']===null,'False-negative ledger events may exist without a detector observation while retaining model assignment lineage.');

$summary=glasses_vision_feedback_summary($pdo,$org);
v71_assert($summary['total']===2&&($summary['byType']['misclassification']??0)===1&&($summary['byType']['false_negative']??0)===1,'Feedback summary must aggregate immutable production outcomes.');

v71_assert((string)v71_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line])==='queued','Production feedback ledger must not mutate KDS lifecycle.');
v71_assert((int)v71_one($pdo,"SELECT COUNT(*) FROM glasses_build_observations WHERE organization_id=? AND build_session_id=?",[$org,$sessionId])===1,'Feedback sync must never mutate or duplicate original observations.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
foreach(['production_error.record','production_error.sync_corrections'] as $action)v71_assert(str_contains($api,$action),'V7 API missing '.$action);
v71_assert(str_contains($page,'Production Error &amp; Outcome Ledger'),'Vision Lab must expose the V7 production feedback ledger.');

echo "vision-lab-v7-production-error-ledger-ok\n";
