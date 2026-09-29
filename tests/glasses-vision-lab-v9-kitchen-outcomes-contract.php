<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-definition.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-kitchen-outcomes.php';

function v99_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v99_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}
$pdo=app_pdo();v99_assert(glasses_vision_kitchen_outcome_ready($pdo),'V9 kitchen-outcome migration must be installed.');

$slug='vl99-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Kitchen Outcomes '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Learning Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Learning','Cook','Learning Cook']);$actor=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'learn-'.$slug,'targetSeconds'=>300],$actor);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Learning Pizza',?,'Final validate.',1)")->execute([$org,$section,'learning-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Cheese',?,'food','verified')")->execute([$org,'cheese-'.$slug]);$cheese=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Cheese',0,1,1)")->execute([$item,$cheese]);$componentKey='ingredient:'.$cheese;

$recipePublic='recipe-'.bin2hex(random_bytes(8));
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by)
 VALUES (?,?,?,?,?,?,?,'active','mapped',?,?)")->execute([$org,$recipePublic,'Learning Pizza','Pizza','fixture',json_encode([['quantity'=>'1','unit'=>'portion','ingredient'=>'Cheese','notes'=>'']]),json_encode(['Add Cheese','Finish']),$actor,$actor]);
v99_assert(glasses_definition_compile($pdo,$org,$item,$recipePublic,$actor)['status']==='ready','Recipe compile failed.');
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'V9 outcome fixture',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V99-'.$slug,'displayName'=>'V9 Learning AIR3','platform'=>'inmo_air3','sdkVersion'=>'1.5.0','appVersion'=>'2.0.0','capabilities'=>['visionModelRuntimes'=>['onnx']]]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
glasses_vision_profile_save($pdo,$org,['ingredientId'=>$cheese,'detectorName'=>'ingredient_detector','modelLabel'=>'cheese','minimumConfidence'=>.80],$actor);

$modelHash=hash('sha256','learn-model-'.$slug);$baseHash=hash('sha256','learn-base-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','Learning Detector','v9.9','onnx','inmo_air3','https://example.test/learn.onnx',?,123,'ready',?)")->execute([$org,'learn-model-'.$slug,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by) VALUES (?,?,'ingredient_detector','Learning Detector','base','onnx','inmo_air3','https://example.test/learn-base.onnx',?,123,'ready',?)")->execute([$org,'learn-base-'.$slug,$baseHash,$actor]);$baseId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_rollouts (organization_id,public_id,detector_name,target_package_id,baseline_package_id,location_id,station_id,canary_percent,status,created_by,activated_by,activated_at) VALUES (?,?,'ingredient_detector',?,?,?,?,100.00,'active',?,?,UTC_TIMESTAMP(6))")->execute([$org,'learn-rollout-'.$slug,$modelId,$baseId,$location,(int)$station['id'],$actor,$actor]);

glasses_build_confirm($pdo,$device,$sessionPublic,$componentKey);
v99_assert(!empty(glasses_build_payload($pdo,$org,$sessionPublic)['summary']['accounted']),'Build must be accounted.');

function v99_scene(PDO $pdo,array $device,string $sessionPublic,string $slug,string $frame,?float $score,array $defects=[],bool $visible=true):array{
 $entities=[];
 if($visible)$entities[]=['entityKey'=>'product-'.$frame,'kind'=>'product','label'=>'Learning Pizza','trackingId'=>'pizza-'.$frame,'source'=>'device_runtime','confidence'=>.99,'bbox'=>[.25,.25,.5,.5],'attributes'=>['presentationEstimate'=>['score'=>$score,'confidence'=>.96,'defects'=>$defects]]];
 return glasses_vision_scene_capture($pdo,$device,['buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','frameKey'=>$frame.'-'.$slug,'frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8','capturedAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),'entities'=>$entities]);
}

$failed=glasses_vision_final_validate_scene($pdo,$org,v99_scene($pdo,$device,$sessionPublic,$slug,'failed',.94,[['type'=>'foreign_object','confidence'=>.98]])['publicId']);
v99_assert($failed['state']==='needs_correction','Initial validation must fail.');
$case=glasses_vision_rework_open($pdo,$device,$failed['publicId'],'case-'.$slug,null);
$insufficient=glasses_vision_rework_revalidate($pdo,$device,$case['publicId'],v99_scene($pdo,$device,$sessionPublic,$slug,'occluded',null,[],false)['publicId'],'attempt-1-'.$slug);
v99_assert($insufficient['attempt']['state']==='insufficient','First rework attempt must be insufficient.');
$corrected=glasses_vision_rework_revalidate($pdo,$device,$case['publicId'],v99_scene($pdo,$device,$sessionPublic,$slug,'corrected',.96,[])['publicId'],'attempt-2-'.$slug);
v99_assert($corrected['attempt']['state']==='ready_candidate','Correction must produce ready candidate.');
$confirmation=glasses_vision_handoff_confirm($pdo,$device,$corrected['finalValidation']['publicId'],'confirm-'.$slug,null);
v99_assert($confirmation['status']==='completed','Corrected product must complete governed handoff.');

$before=[
 'productionErrors'=>(int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_production_errors WHERE organization_id=?",[$org]),
 'packages'=>(int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_packages WHERE organization_id=?",[$org]),
 'rollouts'=>(int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=?",[$org]),
 'batches'=>(int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_retraining_batches WHERE organization_id=?",[$org]),
 'promotions'=>(int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_promotions WHERE organization_id=?",[$org]),
];

$sync=glasses_vision_kitchen_outcome_sync($pdo,$org,$actor,500);
v99_assert($sync['created']===5,'Expected failed final, passing final, two rework outcomes, and confirmed handoff.');
$summary=glasses_vision_kitchen_outcome_summary($pdo,$org);
v99_assert($summary['total']===5&&$summary['pendingReview']===5,'All kitchen outcomes must start pending review.');
foreach(['confirmed_pass','final_failure','rework_failure','rework_success','confirmed_handoff'] as $category)
 v99_assert(($summary['byCategory'][$category]??0)===1,'Missing governed category '.$category);

$events=glasses_vision_kitchen_outcome_list($pdo,$org,20);
v99_assert(count($events)===5,'Kitchen outcome list must include all five immutable events.');
foreach($events as $event){
 v99_assert($event['reviewStatus']==='pending','Outcome must remain pending until explicit review.');
 v99_assert(preg_match('/^[a-f0-9]{64}$/',$event['eventHash'])===1,'Outcome must be hash-addressed.');
}
v99_assert(glasses_vision_kitchen_outcome_sync($pdo,$org,$actor,500)['created']===0,'Outcome sync must be idempotent.');
v99_assert((int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND relation='observed_as_kitchen_outcome'",[$org])===5,'Every outcome must preserve source lineage.');

$after=[
 'productionErrors'=>(int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_production_errors WHERE organization_id=?",[$org]),
 'packages'=>(int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_packages WHERE organization_id=?",[$org]),
 'rollouts'=>(int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=?",[$org]),
 'batches'=>(int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_retraining_batches WHERE organization_id=?",[$org]),
 'promotions'=>(int)v99_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_promotions WHERE organization_id=?",[$org]),
];
v99_assert($before===$after,'Kitchen outcome ingestion must not mutate error, package, rollout, retraining, or promotion state.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-kitchen-outcomes.php');
v99_assert(str_contains($api,"outcomes.sync"),'Vision Lab API must expose managed outcome sync.');
v99_assert(str_contains($page,'Production Learning from Kitchen Outcomes'),'Vision Lab must expose Section 9.');
foreach(['glasses_vision_retraining_','glasses_vision_promotion_','glasses_vision_model_rollout','glasses_vision_feedback_record(','kds_transition(','glasses_build_confirm('] as $forbidden)
 v99_assert(!str_contains($source,$forbidden),'Kitchen outcome ingestion must not bypass governance: '.$forbidden);

echo "vision-lab-v9-kitchen-outcomes-ok\n";
