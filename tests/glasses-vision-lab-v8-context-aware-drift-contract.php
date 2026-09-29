<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-context-drift.php';

function v82_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v82_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v82_assert(glasses_vision_context_drift_ready($pdo),'V8 context-aware drift migration must be installed.');

$slug='vl82-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Context Drift '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Context','Reviewer','Context Reviewer']);$actor=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'pizza-'.$slug,'targetSeconds'=>300],$actor);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Context Pizza',?,'Build to recipe.',1)")->execute([$org,$section,'context-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Pepperoni',?,'food','verified')")->execute([$org,'pepperoni-'.$slug]);$ingredient=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Pepperoni',0,1,1)")->execute([$item,$ingredient]);
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V82-'.$slug,'displayName'=>'V8 Context AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
$sessionId=(int)v82_one($pdo,"SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);

$modelPublic='vision-model-context-'.$slug;$modelHash=hash('sha256','model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Context Detector','v8.2','onnx','air3','https://example.test/v82.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$assignmentKey=hash('sha256','assignment-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_assignments
 (organization_id,device_id,build_session_id,assignment_key,detector_name,package_id,action,selection,rollout_status,compatibility_json,issued_at)
 VALUES (?,?,?,?,?,?,'apply','stable','active','{\"compatible\":true}',UTC_TIMESTAMP(6))")
 ->execute([$org,(int)$device['id'],$sessionId,$assignmentKey,'ingredient_detector',$modelId]);$assignmentId=(int)$pdo->lastInsertId();

$baselineTime=(new DateTimeImmutable('-4 hours',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
$calA=hash('sha256','cal-a-'.$slug);$calB=hash('sha256','cal-b-'.$slug);
$menuA=hash('sha256','menu-a-'.$slug);$menuB=hash('sha256','menu-b-'.$slug);$ingredientSig=hash('sha256','ingredient-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_drift_baselines
 (organization_id,package_id,location_id,station_id,detector_name,calibration_source_hash,menu_signature,ingredient_signature,frame_width,frame_height,pixel_format,
  sample_count,brightness_mean,contrast_mean,camera_pitch_mean,camera_yaw_mean,camera_roll_mean,confidence_mean,latency_mean_ms,correction_rate,low_confidence_rate,status,established_at)
 VALUES (?,?,?,?,?,?,?,?,640,480,'grayscale8',20,.50,.40,1.0,2.0,.5,.90,50,.02,.05,'active',?)")
 ->execute([$org,$modelId,$location,(int)$station['id'],'ingredient_detector',$calA,$menuA,$ingredientSig,$baselineTime]);

$insert=$pdo->prepare("INSERT INTO glasses_vision_drift_samples
 (organization_id,assignment_id,package_id,device_id,build_session_id,location_id,station_id,sample_key,calibration_source_hash,menu_signature,ingredient_signature,
  frame_width,frame_height,pixel_format,brightness_mean,contrast_mean,camera_pitch,camera_yaw,camera_roll,confidence_mean,latency_mean_ms,
  observation_count,correction_count,low_confidence_count,drift_state,drift_score,reasons_json,metadata_json,created_at)
 VALUES (?,?,?,?,?,?,?,?,?,?,?,640,480,'grayscale8',?,?,?,?,?,?,?,?,?,?,?,?,?,'{}',?)");

$ctxAt=(new DateTimeImmutable('-90 minutes',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
for($i=1;$i<=3;$i++)$insert->execute([
 $org,$assignmentId,$modelId,(int)$device['id'],$sessionId,$location,(int)$station['id'],'context-shift-'.$slug.'-'.$i,$calB,$menuB,$ingredientSig,
 .80,.40,1.0,2.0,.5,.89,52,10,0,1,'warning',.35,'["lighting","calibration"]',$ctxAt
]);
$ctxFrom=(new DateTimeImmutable('-2 hours',new DateTimeZone('UTC')))->format(DATE_ATOM);
$ctxTo=(new DateTimeImmutable('-1 hour',new DateTimeZone('UTC')))->format(DATE_ATOM);
$ctxHealth=glasses_vision_model_health_snapshot($pdo,$org,[
 'modelPackagePublicId'=>$modelPublic,'locationId'=>$location,'stationPublicId'=>$station['public_id'],'devicePublicId'=>$device['public_id'],
 'windowStartedAt'=>$ctxFrom,'windowEndedAt'=>$ctxTo,
],$actor);
$ctx=glasses_vision_context_drift_analyze($pdo,$org,$ctxHealth['publicId'],$actor);
v82_assert($ctx['classification']==='context_shift','Material calibration/menu/lighting changes with stable performance must classify as context shift.');
v82_assert($ctx['contextScore']>=.30&&$ctx['performanceScore']<.30,'Context-shift analysis must separate context score from model-performance score.');
v82_assert(($ctx['result']['governance']['causalClaim']??true)===false,'Context-aware drift must not claim causal certainty.');
v82_assert(glasses_vision_context_drift_verify($pdo,$org,$ctx['publicId'])['passed']===true,'Fresh context-drift analysis must verify.');

$perfAt=(new DateTimeImmutable('-30 minutes',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
for($i=1;$i<=3;$i++)$insert->execute([
 $org,$assignmentId,$modelId,(int)$device['id'],$sessionId,$location,(int)$station['id'],'model-degrade-'.$slug.'-'.$i,$calA,$menuA,$ingredientSig,
 .50,.40,1.0,2.0,.5,.65,90,10,3,5,'degraded',.70,'["confidence","latency"]',$perfAt
]);
$errorInsert=$pdo->prepare("INSERT INTO glasses_vision_production_errors
 (organization_id,public_id,event_key,event_hash,source_type,error_type,outcome,build_session_id,device_id,location_id,station_id,menu_item_id,model_package_id,
  predicted_component_key,expected_component_key,confidence,context_json,actor_user_id,occurred_at)
 VALUES (?,?,?,?,?,'misclassification','reclassified',?,?,?,?,?,?, 'ingredient:sausage','ingredient:pepperoni',.65,'{}',?,?)");
for($i=1;$i<=3;$i++){
 $p='vision-context-error-'.$slug.'-'.$i;$errorInsert->execute([$org,$p,'context-event-'.$slug.'-'.$i,hash('sha256',$p),'fixture',$sessionId,(int)$device['id'],$location,(int)$station['id'],$item,$modelId,$actor,$perfAt]);
}
$perfFrom=(new DateTimeImmutable('-1 hour',new DateTimeZone('UTC')))->format(DATE_ATOM);
$perfTo=(new DateTimeImmutable('+1 minute',new DateTimeZone('UTC')))->format(DATE_ATOM);
$perfHealth=glasses_vision_model_health_snapshot($pdo,$org,[
 'modelPackagePublicId'=>$modelPublic,'locationId'=>$location,'stationPublicId'=>$station['public_id'],'devicePublicId'=>$device['public_id'],
 'windowStartedAt'=>$perfFrom,'windowEndedAt'=>$perfTo,
],$actor);
$perf=glasses_vision_context_drift_analyze($pdo,$org,$perfHealth['publicId'],$actor);
v82_assert($perf['classification']==='model_degradation','Stable measured context with strong confidence/latency/error regression must classify as model degradation.');
v82_assert($perf['contextScore']<.20&&$perf['performanceScore']>=.30,'Model-degradation analysis must preserve low context shift and high performance regression.');
v82_assert($perf['confidenceScore']>0,'Analysis must expose evidence completeness, not an outcome probability.');

$same=glasses_vision_context_drift_analyze($pdo,$org,$perfHealth['publicId'],$actor);
v82_assert($same['publicId']===$perf['publicId'],'Identical snapshot/baseline/context evidence must deduplicate.');
v82_assert((int)v82_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_health_snapshot' AND relation='context_analyzed_as' AND to_kind='context_drift_analysis'",[$org])===2,'Each distinct health window must retain context-analysis lineage.');

$pdo->prepare("UPDATE glasses_vision_context_drift_analyses SET result_json='{}' WHERE organization_id=? AND public_id=?")->execute([$org,$perf['publicId']]);
v82_assert(glasses_vision_context_drift_verify($pdo,$org,$perf['publicId'])['passed']===false,'Context-drift analysis tampering must be detectable.');

v82_assert((string)v82_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line])==='queued','Context drift analysis must not mutate KDS lifecycle.');
v82_assert((int)v82_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollout_events WHERE organization_id=?",[$org])===0,'Context drift analysis must not mutate rollout state.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-context-drift.php');
foreach(['context_drift.analyze','context_drift.verify'] as $action)v82_assert(str_contains($api,$action),'V8 context-drift API missing '.$action);
v82_assert(str_contains($page,'Context-Aware Drift Detection'),'Vision Lab must expose Section 2 context-aware drift controls.');
v82_assert(!str_contains($source,'rollout.activate')&&!str_contains($source,'rollout.advance')&&!str_contains($source,'kds_transition'),'Section 2 must remain observational.');

echo "vision-lab-v8-context-aware-drift-ok\n";
