<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-definition.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-step-recognition.php';

function v92_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v92_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v92_assert(glasses_vision_step_ready($pdo),'V9 recipe-step migration must be installed.');

$slug='vl92-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Recipe Step '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Step Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Step','Reviewer','Step Reviewer']);$actor=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'step-'.$slug,'targetSeconds'=>300],$actor);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Step Pizza',?,'Add cheese, add pepperoni, slice, then bake.',1)")->execute([$org,$section,'step-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
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
 ->execute([$org,$recipePublic,'Step Pizza','Pizza','V9 recipe-step fixture',json_encode($recipeIngredients),json_encode($steps),$actor,$actor]);
$definition=glasses_definition_compile($pdo,$org,$item,$recipePublic,$actor);
v92_assert($definition['status']==='ready'&&count($definition['definition']['steps'])===4,'Fixture recipe must compile four canonical steps.');
v92_assert(in_array($cheeseKey,$definition['definition']['steps'][0]['componentKeys'],true),'Cheese step must bind canonical component.');
v92_assert(in_array($pepKey,$definition['definition']['steps'][1]['componentKeys'],true),'Pepperoni step must bind canonical component.');

kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'Extra crisp',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
 'hardwareIdentifier'=>'AIR3-V92-'.$slug,'displayName'=>'V9 Step AIR3','platform'=>'inmo_air3',
 'sdkVersion'=>'1.5.0','appVersion'=>'2.0.0','capabilities'=>['visionModelRuntimes'=>['onnx']]
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
$sessionId=(int)v92_one($pdo,"SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);
v92_assert(($session['buildDefinition']['publicId']??null)===$definition['publicId'],'Build must use the exact compiled recipe definition.');

glasses_vision_profile_save($pdo,$org,['ingredientId'=>$cheese,'detectorName'=>'ingredient_detector','modelLabel'=>'cheese','minimumConfidence'=>.80],$actor);
glasses_vision_profile_save($pdo,$org,['ingredientId'=>$pep,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','minimumConfidence'=>.80],$actor);

$modelPublic='vision-step-model-'.$slug;$modelHash=hash('sha256','step-model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Step Detector','v9.2','onnx','inmo_air3','https://example.test/v92.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$baselinePublic='vision-step-baseline-'.$slug;$baselineHash=hash('sha256','step-baseline-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Step Detector','baseline','onnx','inmo_air3','https://example.test/v92-base.onnx',?,123,'ready',?)")
 ->execute([$org,$baselinePublic,$baselineHash,$actor]);$baselineId=(int)$pdo->lastInsertId();
$rolloutPublic='vision-step-rollout-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_model_rollouts
 (organization_id,public_id,detector_name,target_package_id,baseline_package_id,location_id,station_id,canary_percent,status,created_by,activated_by,activated_at)
 VALUES (?,?,'ingredient_detector',?,?,?,?,100.00,'active',?,?,UTC_TIMESTAMP(6))")
 ->execute([$org,$rolloutPublic,$modelId,$baselineId,$location,(int)$station['id'],$actor,$actor]);

function v92_scene(PDO $pdo,array $device,string $sessionPublic,string $slug,string $frame,array $entities,array $relationships=[]): array
{
    return glasses_vision_scene_capture($pdo,$device,[
      'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','frameKey'=>$frame.'-'.$slug,
      'frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8','capturedAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
      'entities'=>$entities,'relationships'=>$relationships
    ]);
}

$scene1=v92_scene($pdo,$device,$sessionPublic,$slug,'cheese',[
 ['entityKey'=>'cheese','kind'=>'ingredient','label'=>'cheese','trackingId'=>'cheese-1','source'=>'vision_model','confidence'=>.96,'bbox'=>[.25,.25,.15,.15]],
 ['entityKey'=>'pizza','kind'=>'product','label'=>'Step Pizza','trackingId'=>'pizza-1','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.30,.45,.40,.35]],
]);
$rec1=glasses_vision_step_recognize($pdo,$org,$scene1['publicId']);
v92_assert($rec1['state']==='recognized'&&($rec1['recognizedStep']['stepKey']??'')==='step:1','Current visible Cheese must recognize canonical recipe step 1.');
v92_assert(($rec1['nextStep']['stepKey']??'')==='step:2','Step 1 recognition must suggest canonical next step 2.');
v92_assert($rec1['confidence']>=.65&&$rec1['margin']>=.12,'Recognized ingredient step must meet governed confidence and margin policy.');
v92_assert(glasses_vision_step_verify($pdo,$org,$rec1['publicId'])['passed'],'Fresh step recognition must verify.');
$same=glasses_vision_step_recognize($pdo,$org,$scene1['publicId']);
v92_assert($same['publicId']===$rec1['publicId'],'Same immutable scene/policy must deduplicate recognition.');

v92_assert((int)v92_one($pdo,"SELECT COUNT(*) FROM glasses_build_observations WHERE organization_id=? AND build_session_id=?",[$org,$sessionId])===0,'Step recognition must not create ingredient observations.');
v92_assert((string)v92_one($pdo,"SELECT status FROM glasses_build_components WHERE organization_id=? AND build_session_id=? AND component_key=?",[$org,$sessionId,$cheeseKey])==='waiting','Recognition must not confirm the current component.');

glasses_build_confirm($pdo,$device,$sessionPublic,$cheeseKey);
$scene2=v92_scene($pdo,$device,$sessionPublic,$slug,'pepperoni',[
 ['entityKey'=>'pepperoni','kind'=>'ingredient','label'=>'pepperoni','trackingId'=>'pep-1','source'=>'vision_model','confidence'=>.97,'bbox'=>[.25,.25,.15,.15]],
 ['entityKey'=>'pizza','kind'=>'product','label'=>'Step Pizza','trackingId'=>'pizza-2','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.30,.45,.40,.35]],
]);
$rec2=glasses_vision_step_recognize($pdo,$org,$scene2['publicId']);
v92_assert($rec2['state']==='recognized'&&($rec2['recognizedStep']['stepKey']??'')==='step:2','After canonical Cheese confirmation, Pepperoni scene must recognize step 2.');
v92_assert(($rec2['nextStep']['stepKey']??'')==='step:3','Step 2 recognition must suggest Slice pizza next.');

glasses_build_confirm($pdo,$device,$sessionPublic,$pepKey);
$ambiguousScene=v92_scene($pdo,$device,$sessionPublic,$slug,'ambiguous-actions',[
 ['entityKey'=>'cutter','kind'=>'tool','label'=>'pizza cutter','trackingId'=>'cutter-a','source'=>'vision_model','confidence'=>.96,'bbox'=>[.20,.20,.10,.20]],
 ['entityKey'=>'oven','kind'=>'equipment','label'=>'pizza oven','trackingId'=>'oven-a','source'=>'vision_model','confidence'=>.99,'bbox'=>[.60,.10,.30,.40]],
 ['entityKey'=>'pizza','kind'=>'product','label'=>'Step Pizza','trackingId'=>'pizza-a','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.35,.50,.30,.25]],
],[
 ['from'=>'cutter','type'=>'touching','to'=>'pizza','confidence'=>.95],
 ['from'=>'pizza','type'=>'inside','to'=>'oven','confidence'=>.95],
]);
$ambiguous=glasses_vision_step_recognize($pdo,$org,$ambiguousScene['publicId']);
v92_assert($ambiguous['state']==='ambiguous'&&$ambiguous['recognizedStep']===null,'Competing high-confidence Slice/Bake action cues must remain ambiguous instead of guessing.');
v92_assert($ambiguous['confidence']>=.50&&$ambiguous['margin']<.12,'Ambiguous action scene must fail the recognition margin.');

$sliceScene=v92_scene($pdo,$device,$sessionPublic,$slug,'slice-only',[
 ['entityKey'=>'cutter','kind'=>'tool','label'=>'pizza cutter','trackingId'=>'cutter-b','source'=>'vision_model','confidence'=>.97,'bbox'=>[.20,.20,.10,.20]],
 ['entityKey'=>'hand','kind'=>'hand','label'=>'right hand','trackingId'=>'hand-b','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.18,.18,.15,.25]],
 ['entityKey'=>'pizza','kind'=>'product','label'=>'Step Pizza','trackingId'=>'pizza-b','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.35,.50,.30,.25]],
],[
 ['from'=>'cutter','type'=>'held_by','to'=>'hand','confidence'=>.96],
 ['from'=>'cutter','type'=>'touching','to'=>'pizza','confidence'=>.97],
]);
$slice=glasses_vision_step_recognize($pdo,$org,$sliceScene['publicId']);
v92_assert($slice['state']==='recognized'&&($slice['recognizedStep']['stepKey']??'')==='step:3','Cutter/product interaction after prior component completion must recognize Slice pizza.');
v92_assert(($slice['nextStep']['stepKey']??'')==='step:4'&&($slice['nextStep']['text']??'')==='Bake pizza','Slice recognition must suggest Bake pizza next.');
v92_assert(($slice['evidence']['policy']['recognizedMin']??0)===.65&&($slice['evidence']['policy']['recognizedMarginMin']??0)===.12,'Recognition must preserve exact governed policy thresholds.');

$beforeBuild=(string)v92_one($pdo,"SELECT updated_at FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);
$beforeKds=(string)v92_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line]);
glasses_vision_step_recognize($pdo,$org,$sliceScene['publicId']);
v92_assert((string)v92_one($pdo,"SELECT updated_at FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic])===$beforeBuild,'Recognition retry must not mutate build session state.');
v92_assert((string)v92_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line])===$beforeKds,'Recognition must not mutate KDS state.');

$originalCandidates=(string)v92_one($pdo,"SELECT candidates_json FROM glasses_vision_step_recognitions WHERE organization_id=? AND public_id=?",[$org,$slice['publicId']]);
$pdo->prepare("UPDATE glasses_vision_step_recognitions SET candidates_json='[]' WHERE organization_id=? AND public_id=?")->execute([$org,$slice['publicId']]);
v92_assert(!glasses_vision_step_verify($pdo,$org,$slice['publicId'])['passed'],'Candidate-evidence tampering must be detected.');
$pdo->prepare("UPDATE glasses_vision_step_recognitions SET candidates_json=? WHERE organization_id=? AND public_id=?")->execute([$originalCandidates,$org,$slice['publicId']]);
v92_assert(glasses_vision_step_verify($pdo,$org,$slice['publicId'])['passed'],'Restored step evidence must verify.');

$pdo->prepare("UPDATE glasses_build_sessions SET status='cancelled',cancelled_at=UTC_TIMESTAMP(6) WHERE organization_id=? AND public_id=?")->execute([$org,$sessionPublic]);
$inactiveBlocked=false;try{glasses_vision_step_recognize($pdo,$org,$ambiguousScene['publicId']);}catch(InvalidArgumentException){$inactiveBlocked=true;}
v92_assert($inactiveBlocked,'New recipe-step recognition must fail closed after the canonical build is no longer active.');
v92_assert(glasses_vision_step_verify($pdo,$org,$slice['publicId'])['passed'],'Historical recognition verification must remain valid after the build closes.');

v92_assert((int)v92_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='vision_scene' AND relation='recognized_recipe_step_as' AND to_kind='recipe_step_recognition'",[$org])===4,'Each persisted step recognition must retain scene lineage.');

$devApi=file_get_contents(__DIR__.'/../api/glasses-device.php');$labApi=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-step-recognition.php');
foreach(['vision.step.recognize','vision.step.verify'] as $action)v92_assert(str_contains($devApi,$action),'Device API missing '.$action);
foreach(['step.recognize','step.verify'] as $action)v92_assert(str_contains($labApi,$action),'Vision Lab API missing '.$action);
v92_assert(str_contains($page,'Recipe Step Recognition'),'Vision Lab must expose the V9 Section 2 panel.');
foreach(['glasses_build_confirm','glasses_build_observe','kds_transition','glasses_build_complete'] as $forbidden)v92_assert(!str_contains($source,$forbidden),'Recipe-step recognition service must remain advisory-only.');

echo "vision-lab-v9-recipe-step-recognition-ok\n";
