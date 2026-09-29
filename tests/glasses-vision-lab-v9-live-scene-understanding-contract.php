<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-calibration.php';
require_once __DIR__.'/../includes/glasses-vision-scene.php';

function v91_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v91_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v91_assert(glasses_vision_scene_ready($pdo),'V9 live-scene migration must be installed.');

$slug='vl91-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Live Scene '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Scene Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Scene','Reviewer','Scene Reviewer']);$actor=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'scene-'.$slug,'targetSeconds'=>300],$actor);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Scene Pizza',?,'Build to recipe.',1)")->execute([$org,$section,'scene-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Pepperoni',?,'food','verified')")->execute([$org,'pepperoni-'.$slug]);$pep=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Cheese',?,'food','verified')")->execute([$org,'cheese-'.$slug]);$cheese=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Cheese',0,1,1),(?,?,'Pepperoni',0,1,2)")
    ->execute([$item,$cheese,$item,$pep]);
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'No olives',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
  'hardwareIdentifier'=>'AIR3-V91-'.$slug,'displayName'=>'V9 Scene AIR3','platform'=>'inmo_air3',
  'sdkVersion'=>'1.5.0','appVersion'=>'2.0.0','capabilities'=>['visionModelRuntimes'=>['onnx']]
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
$sessionId=(int)v91_one($pdo,"SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);
$pepKey='ingredient:'.$pep;$cheeseKey='ingredient:'.$cheese;
glasses_vision_profile_save($pdo,$org,['ingredientId'=>$pep,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','minimumConfidence'=>.80],$actor);
glasses_vision_profile_save($pdo,$org,['ingredientId'=>$cheese,'detectorName'=>'ingredient_detector','modelLabel'=>'cheese','minimumConfidence'=>.80],$actor);

$cal=glasses_station_calibration_save($pdo,$org,$location,(string)$station['public_id'],[
  'platform'=>'inmo_air3','frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8','notes'=>'V9 live-scene fixture',
  'zones'=>[
    ['zoneKey'=>'pepperoni-pan','ingredientId'=>$pep,'displayName'=>'Pepperoni Pan','x'=>.05,'y'=>.10,'width'=>.25,'height'=>.30,'priority'=>20],
    ['zoneKey'=>'cheese-pan','ingredientId'=>$cheese,'displayName'=>'Cheese Pan','x'=>.35,'y'=>.10,'width'=>.25,'height'=>.30,'priority'=>20],
  ],
  'regions'=>[
    ['regionKey'=>'build-surface','regionType'=>'build_surface','displayName'=>'Pizza Build Surface','x'=>.20,'y'=>.45,'width'=>.60,'height'=>.45,'priority'=>20],
    ['regionKey'=>'handoff','regionType'=>'handoff_surface','displayName'=>'Handoff','x'=>.82,'y'=>.45,'width'=>.15,'height'=>.40,'priority'=>10],
  ],
],$actor);
v91_assert($cal['compatibility']['compatible']===true,'Fixture calibration must be compatible.');

$modelPublic='vision-scene-model-'.$slug;$modelHash=hash('sha256','scene-model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Scene Detector','v9.1','onnx','inmo_air3','https://example.test/v91.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$baselinePublic='vision-scene-baseline-'.$slug;$baselineHash=hash('sha256','scene-baseline-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Scene Detector','baseline','onnx','inmo_air3','https://example.test/v91-base.onnx',?,123,'ready',?)")
 ->execute([$org,$baselinePublic,$baselineHash,$actor]);$baselineId=(int)$pdo->lastInsertId();
$rolloutPublic='vision-scene-rollout-'.$slug;
$pdo->prepare("INSERT INTO glasses_vision_model_rollouts
 (organization_id,public_id,detector_name,target_package_id,baseline_package_id,location_id,station_id,canary_percent,status,created_by,activated_by,activated_at)
 VALUES (?,?,'ingredient_detector',?,?,?,?,100.00,'active',?,?,UTC_TIMESTAMP(6))")
 ->execute([$org,$rolloutPublic,$modelId,$baselineId,$location,(int)$station['id'],$actor,$actor]);

$captured=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM);
$input=[
 'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','frameKey'=>'scene-frame-'.$slug,
 'frameWidth'=>640,'frameHeight'=>480,'pixelFormat'=>'grayscale8','capturedAt'=>$captured,
 'entities'=>[
   ['entityKey'=>'ingredient-pep','kind'=>'ingredient','label'=>'pepperoni','trackingId'=>'track-pep','source'=>'vision_model','confidence'=>.96,'bbox'=>[.08,.14,.12,.15],'attributes'=>['detectorClass'=>'pepperoni']],
   ['entityKey'=>'ingredient-cheese','kind'=>'ingredient','label'=>'cheese','trackingId'=>'track-cheese','confidence'=>.94,'bbox'=>[.40,.14,.12,.15]],
   ['entityKey'=>'hand-left','kind'=>'hand','label'=>'left hand','trackingId'=>'hand-1','source'=>'device_runtime','confidence'=>.99,'bbox'=>[.30,.50,.12,.20]],
   ['entityKey'=>'tool-spoodle','kind'=>'tool','label'=>'spoodle','trackingId'=>'tool-1','confidence'=>.91,'bbox'=>[.36,.52,.08,.18]],
   ['entityKey'=>'container-pep','kind'=>'container','label'=>'pepperoni pan','trackingId'=>'container-1','confidence'=>.93,'bbox'=>[.05,.10,.25,.30]],
   ['entityKey'=>'product-pizza','kind'=>'product','label'=>'Scene Pizza','trackingId'=>'product-1','confidence'=>.98,'bbox'=>[.35,.55,.35,.25],'attributes'=>['menuItemId'=>$item]],
 ],
 'relationships'=>[
   ['from'=>'tool-spoodle','type'=>'held_by','to'=>'hand-left','confidence'=>.95],
   ['from'=>'ingredient-pep','type'=>'near','to'=>'container-pep','confidence'=>.90],
   ['from'=>'hand-left','type'=>'near','to'=>'product-pizza','confidence'=>.88],
 ],
];

$scene=glasses_vision_scene_capture($pdo,$device,$input);
v91_assert($scene['sceneState']==='observed'&&$scene['summary']['entityCount']===6,'Scene must persist the complete normalized entity set.');
v91_assert($scene['summary']['relationshipCount']===3,'Scene must persist normalized entity relationships.');
v91_assert($scene['summary']['entityKinds']['ingredient']===2&&$scene['summary']['entityKinds']['hand']===1&&$scene['summary']['entityKinds']['product']===1,'Scene summary must expose entity-kind counts.');
v91_assert($scene['modelPackagePublicId']===$modelPublic&&$scene['modelArtifactSha256']===$modelHash,'Scene must bind to the exact active model assignment.');
v91_assert($scene['calibrationPublicId']===$cal['publicId']&&$scene['calibrationSourceHash']===$cal['sourceHash'],'Scene must bind the exact compatible station calibration.');
v91_assert(($scene['context']['orderContext']['specialInstructions']??'')==='No olives','Scene must preserve canonical POS/order context.');
v91_assert(($scene['context']['recipePlan']['recognizedStep']??'sentinel')===null&&($scene['context']['recipePlan']['recognizedStepReason']??'')==='reserved_for_v9_section_2','Section 1 must expose the recipe plan without claiming step recognition.');
v91_assert(($scene['context']['recipePlan']['currentExpectedComponentKey']??'')===$cheeseKey,'Scene must expose the current canonical build component.');

$byKey=[];foreach($scene['entities'] as $e)$byKey[$e['entityKey']]=$e;
v91_assert(($byKey['ingredient-pep']['componentKey']??'')===$pepKey&&($byKey['ingredient-cheese']['componentKey']??'')===$cheeseKey,'Ingredient component mappings must come from the governed Vision Label Profile.');
v91_assert(($byKey['ingredient-pep']['normalizedLabel']??'')==='pepperoni','Scene must retain the canonical normalized detector label.');
v91_assert(($byKey['ingredient-pep']['spatial']['ingredientZones'][0]['zoneKey']??'')==='pepperoni-pan','Pepperoni entity must resolve into the calibrated pepperoni zone.');
v91_assert(($byKey['product-pizza']['spatial']['regions'][0]['regionKey']??'')==='build-surface','Product entity must resolve into the calibrated build surface.');
v91_assert(($byKey['ingredient-pep']['source']??'')==='vision_model'&&($byKey['hand-left']['source']??'')==='device_runtime','Scene entities must preserve explicit observation provenance.');
v91_assert(preg_match('/^[a-f0-9]{64}$/',(string)($scene['context']['buildContextHash']??''))===1,'Scene must bind a canonical build-context hash.');
v91_assert(glasses_vision_scene_verify($pdo,$org,$scene['publicId'])['passed']===true,'Fresh scene must verify end to end.');
$assignmentId=(int)v91_one($pdo,"SELECT id FROM glasses_vision_model_assignments WHERE organization_id=? AND assignment_key=?",[$org,$scene['assignmentKey']]);
v91_assert($assignmentId>0,'Scene capture must persist/resolve an exact model assignment ledger row.');
$pdo->prepare("UPDATE glasses_vision_model_assignments SET package_id=? WHERE organization_id=? AND id=?")->execute([$baselineId,$org,$assignmentId]);
v91_assert(glasses_vision_scene_verify($pdo,$org,$scene['publicId'])['passed']===false,'Scene verification must detect assignment-ledger tampering.');
$pdo->prepare("UPDATE glasses_vision_model_assignments SET package_id=? WHERE organization_id=? AND id=?")->execute([$modelId,$org,$assignmentId]);
v91_assert(glasses_vision_scene_verify($pdo,$org,$scene['publicId'])['passed']===true,'Restored model assignment must restore scene verification.');

$same=glasses_vision_scene_capture($pdo,$device,$input);
v91_assert($same['publicId']===$scene['publicId'],'Identical frame evidence must be idempotent.');
$conflict=$input;$conflict['entities'][0]['confidence']=.50;$conflicted=false;
try{glasses_vision_scene_capture($pdo,$device,$conflict);}catch(InvalidArgumentException){$conflicted=true;}
v91_assert($conflicted,'Reusing a frame key with different evidence must fail closed.');

$stale=$input;$stale['frameKey']='stale-'.$slug;$stale['capturedAt']=(new DateTimeImmutable('-3 minutes',new DateTimeZone('UTC')))->format(DATE_ATOM);
$staleBlocked=false;try{glasses_vision_scene_capture($pdo,$device,$stale);}catch(InvalidArgumentException){$staleBlocked=true;}
v91_assert($staleBlocked,'Live scene capture must reject stale frames.');

$future=$input;$future['frameKey']='future-'.$slug;$future['capturedAt']=(new DateTimeImmutable('+30 seconds',new DateTimeZone('UTC')))->format(DATE_ATOM);
$futureBlocked=false;try{glasses_vision_scene_capture($pdo,$device,$future);}catch(InvalidArgumentException){$futureBlocked=true;}
v91_assert($futureBlocked,'Live scene capture must reject future-dated frames.');

$duplicateTrack=$input;$duplicateTrack['frameKey']='duplicate-track-'.$slug;$duplicateTrack['entities'][1]['trackingId']='track-pep';
$trackingBlocked=false;try{glasses_vision_scene_capture($pdo,$device,$duplicateTrack);}catch(InvalidArgumentException){$trackingBlocked=true;}
v91_assert($trackingBlocked,'Tracking IDs must be unique within a live scene frame.');

$badComponent=$input;$badComponent['frameKey']='bad-component-'.$slug;$badComponent['entities'][0]['componentKey']='ingredient:999999';
$blocked=false;try{glasses_vision_scene_capture($pdo,$device,$badComponent);}catch(InvalidArgumentException){$blocked=true;}
v91_assert($blocked,'Scene entities must not invent recipe component mappings outside the active build.');

$originalAttributes=(string)v91_one($pdo,"SELECT attributes_json FROM glasses_vision_scene_entities WHERE organization_id=? AND scene_snapshot_id=(SELECT id FROM glasses_vision_scene_snapshots WHERE organization_id=? AND public_id=?) AND entity_key='ingredient-pep'",[$org,$org,$scene['publicId']]);
$pdo->prepare("UPDATE glasses_vision_scene_entities SET attributes_json='{}' WHERE organization_id=? AND scene_snapshot_id=(SELECT id FROM glasses_vision_scene_snapshots WHERE organization_id=? AND public_id=?) AND entity_key='ingredient-pep'")->execute([$org,$org,$scene['publicId']]);
v91_assert(glasses_vision_scene_verify($pdo,$org,$scene['publicId'])['passed']===false,'Entity evidence tampering must be detectable.');
$pdo->prepare("UPDATE glasses_vision_scene_entities SET attributes_json=? WHERE organization_id=? AND scene_snapshot_id=(SELECT id FROM glasses_vision_scene_snapshots WHERE organization_id=? AND public_id=?) AND entity_key='ingredient-pep'")->execute([$originalAttributes,$org,$org,$scene['publicId']]);
v91_assert(glasses_vision_scene_verify($pdo,$org,$scene['publicId'])['passed']===true,'Restored scene evidence must verify.');

v91_assert((int)v91_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_package' AND from_public_id=? AND relation='observed_scene_as' AND to_kind='vision_scene'",[$org,$modelPublic])===1,'Scene snapshot must attach to exact model lineage.');
v91_assert((string)v91_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line])==='queued','Scene capture must never mutate KDS lifecycle.');
v91_assert((string)v91_one($pdo,"SELECT status FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic])==='active','Scene capture must never complete or cancel build state.');
v91_assert((int)v91_one($pdo,"SELECT COUNT(*) FROM glasses_build_observations WHERE organization_id=? AND build_session_id=?",[$org,$sessionId])===0,'Scene understanding must not masquerade as ingredient confirmation evidence.');

$devApi=file_get_contents(__DIR__.'/../api/glasses-device.php');$labApi=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-scene.php');
foreach(['vision.scene.capture','vision.scene.verify'] as $action)v91_assert(str_contains($devApi,$action),'Device API missing '.$action);
v91_assert(str_contains($labApi,"scene.verify"),'Vision Lab API must expose scene verification.');
v91_assert(str_contains($page,'Live Scene Understanding'),'Vision Lab must expose the V9 live-scene panel.');
foreach(['glasses_build_confirm','kds_transition','glasses_vision_model_rollout_rollback'] as $forbidden)v91_assert(!str_contains($source,$forbidden),'Live scene service must remain observational.');

echo "vision-lab-v9-live-scene-understanding-ok\n";
