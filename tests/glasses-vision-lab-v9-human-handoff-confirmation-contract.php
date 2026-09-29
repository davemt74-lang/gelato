<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-definition.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-handoff-confirmation.php';

function v97_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v97_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v97_assert(glasses_vision_handoff_confirmation_ready($pdo),'V9 handoff confirmation migration must be installed.');

$slug='vl97-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Handoff '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Handoff Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Handoff','Cook','Handoff Cook']);$actor=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'handoff-'.$slug,'targetSeconds'=>300],$actor);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Handoff Pizza',?,'Finish and validate.',1)")->execute([$org,$section,'handoff-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Cheese',?,'food','verified')")->execute([$org,'cheese-'.$slug]);$cheese=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Cheese',0,1,1)")->execute([$item,$cheese]);$cheeseKey='ingredient:'.$cheese;

$recipePublic='recipe-'.bin2hex(random_bytes(8));
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by)
 VALUES (?,?,?,?,?,?,?,'active','mapped',?,?)")->execute([
 $org,$recipePublic,'Handoff Pizza','Pizza','V9 handoff fixture',
 json_encode([['quantity'=>'1','unit'=>'portion','ingredient'=>'Cheese','notes'=>'']]),
 json_encode(['Add Cheese','Finish pizza']),$actor,$actor
]);
$definition=glasses_definition_compile($pdo,$org,$item,$recipePublic,$actor);
v97_assert($definition['status']==='ready','Fixture recipe must compile.');

kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'V9 handoff fixture',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
 'hardwareIdentifier'=>'AIR3-V97-'.$slug,'displayName'=>'V9 Handoff AIR3','platform'=>'inmo_air3',
 'sdkVersion'=>'1.5.0','appVersion'=>'2.0.0','capabilities'=>['visionModelRuntimes'=>['onnx']]
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];

glasses_vision_profile_save($pdo,$org,['ingredientId'=>$cheese,'detectorName'=>'ingredient_detector','modelLabel'=>'cheese','minimumConfidence'=>.80],$actor);
$modelHash=hash('sha256','handoff-model-'.$slug);$baseHash=hash('sha256','handoff-base-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Handoff Detector','v9.7','onnx','inmo_air3','https://example.test/v97.onnx',?,123,'ready',?)")->execute([$org,'vision-handoff-model-'.$slug,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Handoff Detector','baseline','onnx','inmo_air3','https://example.test/v97-base.onnx',?,123,'ready',?)")->execute([$org,'vision-handoff-base-'.$slug,$baseHash,$actor]);$baseId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_rollouts (organization_id,public_id,detector_name,target_package_id,baseline_package_id,location_id,station_id,canary_percent,status,created_by,activated_by,activated_at)
 VALUES (?,?,'ingredient_detector',?,?,?,?,100.00,'active',?,?,UTC_TIMESTAMP(6))")->execute([$org,'vision-handoff-rollout-'.$slug,$modelId,$baseId,$location,(int)$station['id'],$actor,$actor]);

glasses_build_confirm($pdo,$device,$sessionPublic,$cheeseKey);
$accounted=glasses_build_payload($pdo,$org,$sessionPublic);
v97_assert(!empty($accounted['summary']['accounted']),'Canonical build must be fully accounted before final validation.');

function v97_scene(PDO $pdo,array $device,string $sessionPublic,string $slug,string $frame,float $score,array $defects=[]):array{
 return glasses_vision_scene_capture($pdo,$device,[
  'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','frameKey'=>$frame.'-'.$slug,
  'frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8',
  'capturedAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
  'entities'=>[[
    'entityKey'=>'product-'.$frame,'kind'=>'product','label'=>'Handoff Pizza','trackingId'=>'pizza-'.$frame,
    'source'=>'device_runtime','confidence'=>.99,'bbox'=>[.25,.25,.5,.5],
    'attributes'=>['presentationEstimate'=>['score'=>$score,'confidence'=>.96,'defects'=>$defects]]
  ]]
 ]);
}

$badScene=v97_scene($pdo,$device,$sessionPublic,$slug,'bad',.91,[['type'=>'foreign_object','confidence'=>.98]]);
$badFinal=glasses_vision_final_validate_scene($pdo,$org,$badScene['publicId']);
v97_assert($badFinal['state']==='needs_correction','Blocking final defect must not be confirmable.');
$rejected=false;
try{glasses_vision_handoff_confirm($pdo,$device,$badFinal['publicId'],'confirm-bad-'.$slug,null);}
catch(InvalidArgumentException){$rejected=true;}
v97_assert($rejected,'Human handoff must reject a non-ready final validation.');
v97_assert((string)v97_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND public_id=?",[$org,$kdsPublic])!=='ready','Rejected confirmation must not change KDS state.');

$goodScene=v97_scene($pdo,$device,$sessionPublic,$slug,'good',.95,[]);
$goodFinal=glasses_vision_final_validate_scene($pdo,$org,$goodScene['publicId']);
v97_assert($goodFinal['state']==='ready_candidate'&&!empty($goodFinal['readyCandidate']),'Good final validation must be a ready candidate.');

$key='confirm-good-'.$slug;
$confirmation=glasses_vision_handoff_confirm($pdo,$device,$goodFinal['publicId'],$key,null);
v97_assert($confirmation['status']==='completed','Explicit human confirmation must complete the governed handoff.');
v97_assert($confirmation['confirmationKey']===$key,'Confirmation key must be durable.');
v97_assert($confirmation['actorUserId']===$actor,'Human actor must be attributed to the accountable paired user.');
v97_assert((string)$confirmation['finalValidationHash']===(string)$goodFinal['validationHash'],'Confirmation must bind the exact final-validation hash.');
v97_assert((string)v97_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND public_id=?",[$org,$kdsPublic])==='ready','Canonical handoff must transition KDS to ready.');
v97_assert((string)v97_one($pdo,"SELECT status FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic])==='completed','Canonical handoff must complete the build session.');
v97_assert((int)v97_one($pdo,"SELECT COUNT(*) FROM glasses_kds_handoffs WHERE organization_id=?",[$org])===1,'Exactly one canonical KDS handoff must be created.');
v97_assert((int)v97_one($pdo,"SELECT COUNT(*) FROM glasses_vision_handoff_confirmations WHERE organization_id=?",[$org])===1,'Exactly one human confirmation ledger row must be created.');

$repeat=glasses_vision_handoff_confirm($pdo,$device,$goodFinal['publicId'],$key,null);
v97_assert($repeat['publicId']===$confirmation['publicId'],'Confirmation retry with the same key must be idempotent.');
v97_assert((int)v97_one($pdo,"SELECT COUNT(*) FROM glasses_kds_handoffs WHERE organization_id=?",[$org])===1,'Idempotent retry must not duplicate canonical handoff.');
v97_assert((int)v97_one($pdo,"SELECT COUNT(*) FROM glasses_vision_handoff_confirmations WHERE organization_id=?",[$org])===1,'Idempotent retry must not duplicate confirmation ledger.');

$event=(int)v97_one($pdo,"SELECT COUNT(*) FROM glasses_build_events WHERE organization_id=? AND event_type='vision_human_handoff_confirmed'",[$org]);
v97_assert($event===1,'Governed human confirmation must write one build audit event.');

$dev=file_get_contents(__DIR__.'/../api/glasses-device.php');
$lab=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$source=file_get_contents(__DIR__.'/../includes/glasses-vision-handoff-confirmation.php');
v97_assert(str_contains($dev,"vision.handoff.confirm"),'Device API must expose explicit handoff confirmation.');
v97_assert(str_contains($dev,"empty(\$in['confirmed'])"),'Device API must require an explicit confirmed flag.');
v97_assert(!str_contains($lab,"handoff.confirm"),'Vision Lab API must not expose consequential confirmation.');
v97_assert(str_contains($page,'Governed Human Confirmation & Kitchen Handoff'),'Vision Lab must expose the read-only Section 7 audit panel.');
v97_assert(str_contains($source,'glasses_handoff_to_expo'),'Section 7 must delegate to the existing canonical handoff engine.');
v97_assert(!str_contains($source,"kds_transition("),'Section 7 must not create a second KDS transition engine.');
v97_assert(!str_contains($source,"UPDATE glasses_build_sessions\n            SET status='completed'"),'Section 7 must not duplicate canonical build-completion SQL.');

echo "vision-lab-v9-human-handoff-confirmation-ok\n";
