<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-definition.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-rework.php';

function v98_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v98_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}
$pdo=app_pdo();v98_assert(glasses_vision_rework_ready($pdo),'V9 rework migration must be installed.');

$slug='vl98-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Rework '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Rework Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Rework','Cook','Rework Cook']);$actor=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'rework-'.$slug,'targetSeconds'=>300],$actor);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Rework Pizza',?,'Validate presentation.',1)")->execute([$org,$section,'rework-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Cheese',?,'food','verified')")->execute([$org,'cheese-'.$slug]);$cheese=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Cheese',0,1,1)")->execute([$item,$cheese]);$componentKey='ingredient:'.$cheese;
$recipePublic='recipe-'.bin2hex(random_bytes(8));
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by)
 VALUES (?,?,?,?,?,?,?,'active','mapped',?,?)")->execute([$org,$recipePublic,'Rework Pizza','Pizza','fixture',json_encode([['quantity'=>'1','unit'=>'portion','ingredient'=>'Cheese','notes'=>'']]),json_encode(['Add Cheese','Finish']),$actor,$actor]);
v98_assert(glasses_definition_compile($pdo,$org,$item,$recipePublic,$actor)['status']==='ready','Recipe compile failed.');
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'V9 rework fixture',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();
$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V98-'.$slug,'displayName'=>'V9 Rework AIR3','platform'=>'inmo_air3','sdkVersion'=>'1.5.0','appVersion'=>'2.0.0','capabilities'=>['visionModelRuntimes'=>['onnx']]]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
glasses_vision_profile_save($pdo,$org,['ingredientId'=>$cheese,'detectorName'=>'ingredient_detector','modelLabel'=>'cheese','minimumConfidence'=>.80],$actor);
$modelHash=hash('sha256','rw-model-'.$slug);$baseHash=hash('sha256','rw-base-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','RW Detector','v9.8','onnx','inmo_air3','https://example.test/rw.onnx',?,123,'ready',?)")->execute([$org,'rw-model-'.$slug,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','RW Detector','base','onnx','inmo_air3','https://example.test/rw-base.onnx',?,123,'ready',?)")->execute([$org,'rw-base-'.$slug,$baseHash,$actor]);$baseId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_rollouts (organization_id,public_id,detector_name,target_package_id,baseline_package_id,location_id,station_id,canary_percent,status,created_by,activated_by,activated_at) VALUES (?,?,'ingredient_detector',?,?,?,?,100.00,'active',?,?,UTC_TIMESTAMP(6))")->execute([$org,'rw-rollout-'.$slug,$modelId,$baseId,$location,(int)$station['id'],$actor,$actor]);
glasses_build_confirm($pdo,$device,$sessionPublic,$componentKey);
v98_assert(!empty(glasses_build_payload($pdo,$org,$sessionPublic)['summary']['accounted']),'Build must be accounted.');

function v98_scene(PDO $pdo,array $device,string $sessionPublic,string $slug,string $frame,?float $score,array $defects=[],bool $visible=true):array{
 $entities=[];
 if($visible)$entities[]=['entityKey'=>'product-'.$frame,'kind'=>'product','label'=>'Rework Pizza','trackingId'=>'pizza-'.$frame,'source'=>'device_runtime','confidence'=>.99,'bbox'=>[.25,.25,.5,.5],'attributes'=>['presentationEstimate'=>['score'=>$score,'confidence'=>.96,'defects'=>$defects]]];
 return glasses_vision_scene_capture($pdo,$device,['buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','frameKey'=>$frame.'-'.$slug,'frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8','capturedAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),'entities'=>$entities]);
}

$failedScene=v98_scene($pdo,$device,$sessionPublic,$slug,'failed',.94,[['type'=>'foreign_object','confidence'=>.98]]);
$failed=glasses_vision_final_validate_scene($pdo,$org,$failedScene['publicId']);
v98_assert($failed['state']==='needs_correction','Initial final validation must fail.');
$case=glasses_vision_rework_open($pdo,$device,$failed['publicId'],'case-'.$slug,null);
v98_assert($case['status']==='open'&&$case['sourceFinalValidationPublicId']===$failed['publicId'],'Failed final must open durable rework case.');
$caseRepeat=glasses_vision_rework_open($pdo,$device,$failed['publicId'],'case-'.$slug,null);
v98_assert($caseRepeat['publicId']===$case['publicId'],'Rework open must be idempotent.');

$insufficientScene=v98_scene($pdo,$device,$sessionPublic,$slug,'occluded',null,[],false);
$attempt1=glasses_vision_rework_revalidate($pdo,$device,$case['publicId'],$insufficientScene['publicId'],'attempt-1-'.$slug);
v98_assert($attempt1['attempt']['state']==='insufficient','Occluded revalidation must remain insufficient.');
v98_assert($attempt1['reworkCase']['status']==='open','Insufficient revalidation must keep case open.');

$againScene=v98_scene($pdo,$device,$sessionPublic,$slug,'still-bad',.70,[]);
$attempt2=glasses_vision_rework_revalidate($pdo,$device,$case['publicId'],$againScene['publicId'],'attempt-2-'.$slug);
v98_assert($attempt2['attempt']['state']==='needs_correction','Still-bad product must remain needs correction.');
v98_assert($attempt2['reworkCase']['status']==='open','Failed revalidation must keep case open.');

$goodScene=v98_scene($pdo,$device,$sessionPublic,$slug,'corrected',.96,[]);
$attempt3=glasses_vision_rework_revalidate($pdo,$device,$case['publicId'],$goodScene['publicId'],'attempt-3-'.$slug);
v98_assert($attempt3['attempt']['state']==='ready_candidate','Corrected product must produce a new ready candidate.');
v98_assert($attempt3['reworkCase']['status']==='resolved','Passing revalidation must resolve case.');
$newFinal=$attempt3['finalValidation'];
v98_assert($newFinal['publicId']!==$failed['publicId'],'Rework must create a new immutable final validation.');
v98_assert(glasses_vision_final_verify($pdo,$org,$failed['publicId'])['passed'],'Original failed validation must remain intact/verifiable.');
v98_assert(glasses_vision_final_verify($pdo,$org,$newFinal['publicId'])['passed'],'Superseding final validation must verify.');

$retry=glasses_vision_rework_revalidate($pdo,$device,$case['publicId'],$goodScene['publicId'],'attempt-3-'.$slug);
v98_assert($retry['attempt']['publicId']===$attempt3['attempt']['publicId'],'Attempt retry must be idempotent.');
v98_assert((int)v98_one($pdo,"SELECT COUNT(*) FROM glasses_vision_rework_attempts WHERE organization_id=?",[$org])===3,'Exactly three immutable attempts should exist.');

$oldRejected=false;try{glasses_vision_handoff_confirm($pdo,$device,$failed['publicId'],'old-confirm-'.$slug,null);}catch(InvalidArgumentException){$oldRejected=true;}
v98_assert($oldRejected,'Original failed validation must never become handoff-eligible.');
$confirmation=glasses_vision_handoff_confirm($pdo,$device,$newFinal['publicId'],'new-confirm-'.$slug,null);
v98_assert($confirmation['status']==='completed','Superseding ready candidate must enter Section 7 confirmation.');
v98_assert((string)v98_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND public_id=?",[$org,$kdsPublic])==='ready','Corrected human-confirmed product must use canonical KDS ready transition.');

v98_assert((int)v98_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND relation='opened_rework_case'",[$org])===1,'Failure must have rework lineage.');
v98_assert((int)v98_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND relation='revalidated_as'",[$org])===3,'Each revalidation attempt must preserve supersession lineage.');
v98_assert((int)v98_one($pdo,"SELECT COUNT(*) FROM glasses_build_events WHERE organization_id=? AND event_type='vision_rework_resolved'",[$org])===1,'Resolved rework must write one audit event.');

$dev=file_get_contents(__DIR__.'/../api/glasses-device.php');$lab=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-rework.php');
foreach(['vision.rework.open','vision.rework.revalidate'] as $a)v98_assert(str_contains($dev,$a),'Device API missing '.$a);
v98_assert(!str_contains($lab,'rework.open')&&!str_contains($lab,'rework.revalidate'),'Vision Lab API must remain audit-only for rework actions.');
v98_assert(str_contains($page,'Exception Recovery, Rework & Revalidation'),'Vision Lab must expose Section 8 audit panel.');
foreach(['kds_transition(','glasses_build_confirm(','UPDATE glasses_build_components'] as $forbidden)v98_assert(!str_contains($source,$forbidden),'Rework runtime must not mutate canonical kitchen truth: '.$forbidden);

echo "vision-lab-v9-rework-revalidation-ok\n";
