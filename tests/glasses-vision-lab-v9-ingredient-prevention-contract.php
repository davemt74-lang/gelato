<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-definition.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-ingredient-prevention.php';

function v93_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v93_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v93_assert(glasses_vision_ingredient_guard_ready($pdo),'V9 ingredient-prevention migration must be installed.');

$slug='vl93-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Ingredient Guard '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Guard Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Step','Reviewer','Step Reviewer']);$actor=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'guard-'.$slug,'targetSeconds'=>300],$actor);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Guard Pizza',?,'Add cheese, add pepperoni, slice, then bake.',1)")->execute([$org,$section,'guard-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();

$ingredients=[];
foreach([['Cheese',1],['Pepperoni',2]] as [$name,$sort]){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")->execute([$org,$name,strtolower($name).'-'.$slug]);
    $id=(int)$pdo->lastInsertId();$ingredients[$name]=$id;
    $pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)")->execute([$item,$id,$name,$sort]);
}
$cheese=$ingredients['Cheese'];$pep=$ingredients['Pepperoni'];$cheeseKey='ingredient:'.$cheese;$pepKey='ingredient:'.$pep;

$recipePublic='recipe-'.bin2hex(random_bytes(8));
$recipeIngredients=[
 ['quantity'=>'1','unit'=>'portion','ingredient'=>'Cheese','notes'=>''],
 ['quantity'=>'1','unit'=>'portion','ingredient'=>'Pepperoni','notes'=>''],
];
$steps=['Add Cheese','Add Pepperoni','Slice pizza','Bake pizza'];
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by)
 VALUES (?,?,?,?,?,?,?,'active','mapped',?,?)")
 ->execute([$org,$recipePublic,'Guard Pizza','Pizza','V9 ingredient-prevention fixture',json_encode($recipeIngredients),json_encode($steps),$actor,$actor]);
$definition=glasses_definition_compile($pdo,$org,$item,$recipePublic,$actor);
v93_assert($definition['status']==='ready'&&count($definition['definition']['steps'])===4,'Fixture recipe must compile four canonical steps.');
v93_assert(in_array($cheeseKey,$definition['definition']['steps'][0]['componentKeys'],true),'Cheese step must bind canonical component.');
v93_assert(in_array($pepKey,$definition['definition']['steps'][1]['componentKeys'],true),'Pepperoni step must bind canonical component.');

kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'Extra crisp',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
 'hardwareIdentifier'=>'AIR3-V93-'.$slug,'displayName'=>'V9 Step AIR3','platform'=>'inmo_air3',
 'sdkVersion'=>'1.5.0','appVersion'=>'2.0.0','capabilities'=>['visionModelRuntimes'=>['onnx']]
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
$sessionId=(int)v93_one($pdo,"SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);
v93_assert(($session['buildDefinition']['publicId']??null)===$definition['publicId'],'Build must use the exact compiled recipe definition.');

glasses_vision_profile_save($pdo,$org,['ingredientId'=>$cheese,'detectorName'=>'ingredient_detector','modelLabel'=>'cheese','minimumConfidence'=>.80],$actor);
glasses_vision_profile_save($pdo,$org,['ingredientId'=>$pep,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','minimumConfidence'=>.80],$actor);

$modelPublic='vision-guard-model-'.$slug;$modelHash=hash('sha256','guard-model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Guard Detector','v9.2','onnx','inmo_air3','https://example.test/v92.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$baselinePublic='vision-guard-baseline-'.$slug;$baselineHash=hash('sha256','guard-baseline-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Guard Detector','baseline','onnx','inmo_air3','https://example.test/v92-base.onnx',?,123,'ready',?)")
 ->execute([$org,$baselinePublic,$baselineHash,$actor]);$baselineId=(int)$pdo->lastInsertId();
$rolloutPublic='vision-guard-rollout-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_model_rollouts
 (organization_id,public_id,detector_name,target_package_id,baseline_package_id,location_id,station_id,canary_percent,status,created_by,activated_by,activated_at)
 VALUES (?,?,'ingredient_detector',?,?,?,?,100.00,'active',?,?,UTC_TIMESTAMP(6))")
 ->execute([$org,$rolloutPublic,$modelId,$baselineId,$location,(int)$station['id'],$actor,$actor]);

function v93_scene(PDO $pdo,array $device,string $sessionPublic,string $slug,string $frame,array $entities,array $relationships=[]): array
{
    return glasses_vision_scene_capture($pdo,$device,[
      'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','frameKey'=>$frame.'-'.$slug,
      'frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8','capturedAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
      'entities'=>$entities,'relationships'=>$relationships
    ]);
}


function v93_types(array $assessment): array
{
    return array_values(array_map(static fn($r)=>(string)$r['type'],(array)$assessment['risks']));
}

$clearScene=v93_scene($pdo,$device,$sessionPublic,$slug,'expected-cheese',[
 ['entityKey'=>'cheese','kind'=>'ingredient','label'=>'cheese','trackingId'=>'cheese-clear','source'=>'vision_model','confidence'=>.96,'bbox'=>[.25,.25,.15,.15]],
 ['entityKey'=>'pizza','kind'=>'product','label'=>'Guard Pizza','trackingId'=>'pizza-clear','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.30,.45,.40,.35]],
]);
$clear=glasses_vision_ingredient_guard_assess($pdo,$org,$clearScene['publicId']);
v93_assert($clear['state']==='clear'&&$clear['expectedVisible']===true,'Expected current ingredient must produce clear guidance.');
v93_assert($clear['stopCount']===0&&$clear['warningCount']===0,'Clear expected ingredient must not produce risk counts.');
v93_assert(glasses_vision_ingredient_guard_verify($pdo,$org,$clear['publicId'])['passed'],'Fresh clear assessment must verify.');
$same=glasses_vision_ingredient_guard_assess($pdo,$org,$clearScene['publicId']);
v93_assert($same['publicId']===$clear['publicId'],'Same immutable scene and policy must deduplicate ingredient assessment.');

$occludedScene=v93_scene($pdo,$device,$sessionPublic,$slug,'occluded-frame',[
 ['entityKey'=>'knife','kind'=>'tool','label'=>'knife','trackingId'=>'knife-occluded','source'=>'vision_model','confidence'=>.91,'bbox'=>[.10,.10,.10,.20]],
]);
$occluded=glasses_vision_ingredient_guard_assess($pdo,$org,$occludedScene['publicId']);
v93_assert($occluded['state']==='insufficient'&&!in_array('missing_expected',v93_types($occluded),true),'Frame without the product/container must be insufficient rather than a false missing-ingredient warning.');

$missingScene=v93_scene($pdo,$device,$sessionPublic,$slug,'missing-cheese',[
 ['entityKey'=>'pizza','kind'=>'product','label'=>'Guard Pizza','trackingId'=>'pizza-missing','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.30,.45,.40,.35]],
]);
$missing=glasses_vision_ingredient_guard_assess($pdo,$org,$missingScene['publicId']);
v93_assert($missing['state']==='warning','Missing expected ingredient must warn.');
v93_assert(in_array('missing_expected',v93_types($missing),true),'Missing expected ingredient risk must be explicit.');

$earlyScene=v93_scene($pdo,$device,$sessionPublic,$slug,'premature-pepperoni',[
 ['entityKey'=>'pepperoni','kind'=>'ingredient','label'=>'pepperoni','trackingId'=>'pep-early','source'=>'vision_model','confidence'=>.97,'bbox'=>[.25,.25,.15,.15]],
 ['entityKey'=>'pizza','kind'=>'product','label'=>'Guard Pizza','trackingId'=>'pizza-early','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.30,.45,.40,.35]],
],[
 ['from'=>'pepperoni','type'=>'touching','to'=>'pizza','confidence'=>.96],
]);
$early=glasses_vision_ingredient_guard_assess($pdo,$org,$earlyScene['publicId']);
v93_assert($early['state']==='stop','A later ingredient touching the product must produce stop guidance.');
v93_assert(in_array('premature_component',v93_types($early),true),'Later canonical ingredient must be identified as premature.');
v93_assert(in_array('missing_expected',v93_types($early),true),'Premature ingredient must not hide the missing expected ingredient.');

$unknownScene=v93_scene($pdo,$device,$sessionPublic,$slug,'unknown-ingredient',[
 ['entityKey'=>'anchovy','kind'=>'ingredient','label'=>'anchovy','trackingId'=>'unknown-a','source'=>'vision_model','confidence'=>.94,'bbox'=>[.25,.25,.15,.15]],
 ['entityKey'=>'pizza','kind'=>'product','label'=>'Guard Pizza','trackingId'=>'pizza-unknown','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.30,.45,.40,.35]],
],[
 ['from'=>'anchovy','type'=>'on','to'=>'pizza','confidence'=>.96],
]);
$unknown=glasses_vision_ingredient_guard_assess($pdo,$org,$unknownScene['publicId']);
v93_assert($unknown['state']==='stop'&&in_array('unknown_ingredient',v93_types($unknown),true),'Unmapped high-confidence ingredient on product must stop without guessing identity.');

$beforeBuild=(string)v93_one($pdo,"SELECT updated_at FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);
$beforeKds=(string)v93_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line]);
glasses_vision_ingredient_guard_assess($pdo,$org,$clearScene['publicId']);
v93_assert((string)v93_one($pdo,"SELECT updated_at FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic])===$beforeBuild,'Ingredient prevention must not mutate build session state.');
v93_assert((string)v93_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line])===$beforeKds,'Ingredient prevention must not mutate KDS state.');
v93_assert((int)v93_one($pdo,"SELECT COUNT(*) FROM glasses_build_observations WHERE organization_id=? AND build_session_id=?",[$org,$sessionId])===0,'Ingredient prevention must not create build observations.');

glasses_build_confirm($pdo,$device,$sessionPublic,$cheeseKey);
$staleBlocked=false;try{glasses_vision_ingredient_guard_assess($pdo,$org,$clearScene['publicId']);}catch(InvalidArgumentException){$staleBlocked=true;}
v93_assert($staleBlocked,'A pre-advance scene must fail closed after canonical build state changes.');
v93_assert(glasses_vision_ingredient_guard_verify($pdo,$org,$clear['publicId'])['passed'],'Historical assessment must remain verifiable after canonical build advances.');

$duplicateScene=v93_scene($pdo,$device,$sessionPublic,$slug,'duplicate-cheese',[
 ['entityKey'=>'cheese','kind'=>'ingredient','label'=>'cheese','trackingId'=>'cheese-duplicate','source'=>'vision_model','confidence'=>.96,'bbox'=>[.25,.25,.15,.15]],
 ['entityKey'=>'pizza','kind'=>'product','label'=>'Guard Pizza','trackingId'=>'pizza-duplicate','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.30,.45,.40,.35]],
],[
 ['from'=>'cheese','type'=>'inside','to'=>'pizza','confidence'=>.98],
]);
$duplicate=glasses_vision_ingredient_guard_assess($pdo,$org,$duplicateScene['publicId']);
v93_assert($duplicate['state']==='stop'&&in_array('duplicate_confirmed_component',v93_types($duplicate),true),'Already-confirmed ingredient being added again must stop.');
v93_assert(in_array('missing_expected',v93_types($duplicate),true),'Duplicate prior ingredient must not hide the current expected Pepperoni.');

$originalResult=(string)v93_one($pdo,"SELECT result_json FROM glasses_vision_ingredient_preventions WHERE organization_id=? AND public_id=?",[$org,$duplicate['publicId']]);
$pdo->prepare("UPDATE glasses_vision_ingredient_preventions SET result_json='{}' WHERE organization_id=? AND public_id=?")->execute([$org,$duplicate['publicId']]);
v93_assert(!glasses_vision_ingredient_guard_verify($pdo,$org,$duplicate['publicId'])['passed'],'Assessment result tampering must be detected.');
$pdo->prepare("UPDATE glasses_vision_ingredient_preventions SET result_json=? WHERE organization_id=? AND public_id=?")->execute([$originalResult,$org,$duplicate['publicId']]);
v93_assert(glasses_vision_ingredient_guard_verify($pdo,$org,$duplicate['publicId'])['passed'],'Restored ingredient assessment must verify.');

v93_assert((int)v93_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='vision_scene' AND relation='assessed_ingredient_risk_as' AND to_kind='ingredient_prevention'",[$org])===6,'Each persisted ingredient assessment must retain scene lineage.');

$devApi=file_get_contents(__DIR__.'/../api/glasses-device.php');
$labApi=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$source=file_get_contents(__DIR__.'/../includes/glasses-vision-ingredient-prevention.php');
foreach(['vision.ingredient_guard.assess','vision.ingredient_guard.verify'] as $action)v93_assert(str_contains($devApi,$action),'Device API missing '.$action);
foreach(['ingredient_guard.assess','ingredient_guard.verify'] as $action)v93_assert(str_contains($labApi,$action),'Vision Lab API missing '.$action);
v93_assert(str_contains($page,'Missing / Wrong Ingredient Prevention'),'Vision Lab must expose the V9 Section 3 panel.');
foreach(['glasses_build_confirm','glasses_build_observe','kds_transition','glasses_build_complete','pos_'] as $forbidden)v93_assert(!str_contains($source,$forbidden),'Ingredient-prevention service must remain advisory-only: '.$forbidden);

echo "vision-lab-v9-ingredient-prevention-ok\n";
