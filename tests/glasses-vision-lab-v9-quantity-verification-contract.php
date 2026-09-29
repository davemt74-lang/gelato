<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-definition.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-quantity-verification.php';

function v94_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v94_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v94_assert(glasses_vision_quantity_ready($pdo),'V9 quantity-verification migration must be installed.');

$slug='vl94-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Quantity '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Quantity Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Quantity','Reviewer','Quantity Reviewer']);$actor=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'qty-'.$slug,'targetSeconds'=>300],$actor);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Quantity Pizza',?,'Use two portions of cheese.',1)")->execute([$org,$section,'quantity-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Cheese',?,'food','verified')")->execute([$org,'cheese-'.$slug]);$cheese=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Cheese',0,1,1)")->execute([$item,$cheese]);$cheeseKey='ingredient:'.$cheese;

$recipePublic='recipe-'.bin2hex(random_bytes(8));
$recipeIngredients=[['quantity'=>'2','unit'=>'portion','ingredient'=>'Cheese','notes'=>'']];
$steps=['Add Cheese','Bake pizza'];
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by)
 VALUES (?,?,?,?,?,?,?,'active','mapped',?,?)")->execute([$org,$recipePublic,'Quantity Pizza','Pizza','V9 quantity fixture',json_encode($recipeIngredients),json_encode($steps),$actor,$actor]);
$definition=glasses_definition_compile($pdo,$org,$item,$recipePublic,$actor);
v94_assert($definition['status']==='ready','Fixture recipe must compile.');
$component=$definition['definition']['components'][0]??[];
v94_assert((float)($component['expectedQuantity']??0)===2.0&&(string)($component['unit']??'')==='portion','Canonical definition must preserve quantity and unit.');

kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'Quantity fixture',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
 'hardwareIdentifier'=>'AIR3-V94-'.$slug,'displayName'=>'V9 Quantity AIR3','platform'=>'inmo_air3',
 'sdkVersion'=>'1.5.0','appVersion'=>'2.0.0','capabilities'=>['visionModelRuntimes'=>['onnx']]
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
$sessionId=(int)v94_one($pdo,"SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);
glasses_vision_profile_save($pdo,$org,['ingredientId'=>$cheese,'detectorName'=>'ingredient_detector','modelLabel'=>'cheese','minimumConfidence'=>.80],$actor);

$modelHash=hash('sha256','qty-model-'.$slug);$baseHash=hash('sha256','qty-base-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Quantity Detector','v9.4','onnx','inmo_air3','https://example.test/v94.onnx',?,123,'ready',?)")->execute([$org,'vision-qty-model-'.$slug,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_packages (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Quantity Detector','baseline','onnx','inmo_air3','https://example.test/v94-base.onnx',?,123,'ready',?)")->execute([$org,'vision-qty-base-'.$slug,$baseHash,$actor]);$baseId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO glasses_vision_model_rollouts (organization_id,public_id,detector_name,target_package_id,baseline_package_id,location_id,station_id,canary_percent,status,created_by,activated_by,activated_at)
 VALUES (?,?,'ingredient_detector',?,?,?,?,100.00,'active',?,?,UTC_TIMESTAMP(6))")->execute([$org,'vision-qty-rollout-'.$slug,$modelId,$baseId,$location,(int)$station['id'],$actor,$actor]);

function v94_scene(PDO $pdo,array $device,string $sessionPublic,string $slug,string $frame,array $entities,array $relationships=[]):array{
 return glasses_vision_scene_capture($pdo,$device,[
  'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','frameKey'=>$frame.'-'.$slug,
  'frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8',
  'capturedAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
  'entities'=>$entities,'relationships'=>$relationships
 ]);
}
function v94_product(string $id):array{return ['entityKey'=>'pizza','kind'=>'product','label'=>'Quantity Pizza','trackingId'=>'pizza-'.$id,'source'=>'device_runtime','confidence'=>.99,'bbox'=>[.3,.45,.4,.35]];}
function v94_cheese(string $id,float $value,string $unit='portion',float $confidence=.95,string $method='vision_estimate'):array{
 return ['entityKey'=>'cheese-'.$id,'kind'=>'ingredient','label'=>'cheese','trackingId'=>'cheese-'.$id,'source'=>'vision_model','confidence'=>.97,'bbox'=>[.2,.2,.2,.2],
  'attributes'=>['quantityEstimate'=>['value'=>$value,'unit'=>$unit,'confidence'=>$confidence,'method'=>$method]]];
}

$withinScene=v94_scene($pdo,$device,$sessionPublic,$slug,'within',[v94_cheese('within',2.1),v94_product('within')]);
v94_assert(($withinScene['context']['recipePlan']['components'][0]['unit']??null)==='portion','V9 scene must preserve canonical quantity unit.');
$within=glasses_vision_quantity_verify_scene($pdo,$org,$withinScene['publicId']);
v94_assert($within['state']==='within_tolerance','2.1 vs 2.0 portions must be within governed tolerance.');
v94_assert(abs((float)$within['observedQuantity']-2.1)<.0001&&$within['unit']==='portion','Verification must preserve observed quantity and canonical unit.');
v94_assert(glasses_vision_quantity_verify($pdo,$org,$within['publicId'])['passed'],'Fresh quantity verification must verify.');
$same=glasses_vision_quantity_verify_scene($pdo,$org,$withinScene['publicId']);
v94_assert($same['publicId']===$within['publicId'],'Same immutable scene/policy must deduplicate verification.');

$under=glasses_vision_quantity_verify_scene($pdo,$org,v94_scene($pdo,$device,$sessionPublic,$slug,'under',[v94_cheese('under',1.2),v94_product('under')])['publicId']);
v94_assert($under['state']==='under_portioned'&&(float)$under['result']['deviation']<0,'Material under-portion must be identified.');

$over=glasses_vision_quantity_verify_scene($pdo,$org,v94_scene($pdo,$device,$sessionPublic,$slug,'over',[v94_cheese('over',2.8),v94_product('over')])['publicId']);
v94_assert($over['state']==='over_portioned'&&(float)$over['result']['deviation']>0,'Material over-portion must be identified.');

$occluded=glasses_vision_quantity_verify_scene($pdo,$org,v94_scene($pdo,$device,$sessionPublic,$slug,'occluded',[v94_cheese('occluded',1.1)])['publicId']);
v94_assert($occluded['state']==='insufficient'&&($occluded['result']['reason']??'')==='product_not_visible','Occluded product must not produce a false portion judgment.');

$unitMismatch=glasses_vision_quantity_verify_scene($pdo,$org,v94_scene($pdo,$device,$sessionPublic,$slug,'unit-mismatch',[v94_cheese('unit',50,'g'),v94_product('unit')])['publicId']);
v94_assert($unitMismatch['state']==='insufficient'&&($unitMismatch['result']['reason']??'')==='unit_mismatch','Incompatible units must not be converted or guessed.');

$low=glasses_vision_quantity_verify_scene($pdo,$org,v94_scene($pdo,$device,$sessionPublic,$slug,'low-confidence',[v94_cheese('low',1.0,'portion',.50),v94_product('low')])['publicId']);
v94_assert($low['state']==='insufficient'&&($low['result']['reason']??'')==='no_quantity_evidence','Low-confidence quantity evidence must be ignored.');

$ambiguousScene=v94_scene($pdo,$device,$sessionPublic,$slug,'ambiguous',[
 v94_cheese('amb-a',1.0),v94_cheese('amb-b',2.2),v94_product('amb')
]);
$ambiguous=glasses_vision_quantity_verify_scene($pdo,$org,$ambiguousScene['publicId']);
v94_assert($ambiguous['state']==='ambiguous'&&($ambiguous['result']['reason']??'')==='conflicting_quantity_evidence','Conflicting high-confidence estimates must remain ambiguous.');

$beforeDetected=(float)v94_one($pdo,"SELECT detected_quantity FROM glasses_build_components WHERE organization_id=? AND build_session_id=? AND component_key=?",[$org,$sessionId,$cheeseKey]);
$beforeStatus=(string)v94_one($pdo,"SELECT status FROM glasses_build_components WHERE organization_id=? AND build_session_id=? AND component_key=?",[$org,$sessionId,$cheeseKey]);
$beforeKds=(string)v94_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line]);
glasses_vision_quantity_verify_scene($pdo,$org,$over['scenePublicId']);
v94_assert((float)v94_one($pdo,"SELECT detected_quantity FROM glasses_build_components WHERE organization_id=? AND build_session_id=? AND component_key=?",[$org,$sessionId,$cheeseKey])===$beforeDetected,'Quantity verification must not change canonical detected quantity.');
v94_assert((string)v94_one($pdo,"SELECT status FROM glasses_build_components WHERE organization_id=? AND build_session_id=? AND component_key=?",[$org,$sessionId,$cheeseKey])===$beforeStatus,'Quantity verification must not confirm/advance component state.');
v94_assert((string)v94_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line])===$beforeKds,'Quantity verification must not mutate KDS state.');
v94_assert((int)v94_one($pdo,"SELECT COUNT(*) FROM glasses_build_observations WHERE organization_id=? AND build_session_id=?",[$org,$sessionId])===0,'Quantity verification must not create build observations.');

glasses_build_confirm($pdo,$device,$sessionPublic,$cheeseKey);
$stale=false;try{glasses_vision_quantity_verify_scene($pdo,$org,$withinScene['publicId']);}catch(InvalidArgumentException){$stale=true;}
v94_assert($stale,'Scene captured before canonical build advance must be rejected.');
v94_assert(glasses_vision_quantity_verify($pdo,$org,$within['publicId'])['passed'],'Historical quantity verification must remain verifiable.');

$original=(string)v94_one($pdo,"SELECT result_json FROM glasses_vision_quantity_verifications WHERE organization_id=? AND public_id=?",[$org,$over['publicId']]);
$pdo->prepare("UPDATE glasses_vision_quantity_verifications SET result_json='{}' WHERE organization_id=? AND public_id=?")->execute([$org,$over['publicId']]);
v94_assert(!glasses_vision_quantity_verify($pdo,$org,$over['publicId'])['passed'],'Result tampering must be detected.');
$pdo->prepare("UPDATE glasses_vision_quantity_verifications SET result_json=? WHERE organization_id=? AND public_id=?")->execute([$original,$org,$over['publicId']]);
v94_assert(glasses_vision_quantity_verify($pdo,$org,$over['publicId'])['passed'],'Restored result must verify.');

v94_assert((int)v94_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND relation='verified_quantity_as' AND to_kind='quantity_verification'",[$org])>=7,'Quantity verification must retain scene lineage.');

$dev=file_get_contents(__DIR__.'/../api/glasses-device.php');$lab=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-quantity-verification.php');
foreach(['vision.quantity.verify_scene','vision.quantity.verify'] as $a)v94_assert(str_contains($dev,$a),'Device API missing '.$a);
foreach(['quantity.verify_scene','quantity.verify'] as $a)v94_assert(str_contains($lab,$a),'Vision Lab API missing '.$a);
v94_assert(str_contains($page,'Portion / Quantity Verification'),'Vision Lab must expose the Section 4 panel.');
foreach(['glasses_build_confirm','glasses_build_observe','kds_transition','glasses_build_complete','pos_'] as $forbidden)v94_assert(!str_contains($source,$forbidden),'Quantity service must remain advisory-only: '.$forbidden);

echo "vision-lab-v9-quantity-verification-ok\n";
