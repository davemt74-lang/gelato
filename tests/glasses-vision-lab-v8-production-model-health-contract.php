<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-model-health.php';

function v81_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v81_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v81_assert(glasses_vision_model_health_ready($pdo),'V8 production model-health migration must be installed.');

$slug='vl81-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Vision Health '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Vision','Health','Vision Health']);$actor=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'pizza-'.$slug,'targetSeconds'=>300],$actor);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Health Pizza',?,'Build to recipe.',1)")->execute([$org,$section,'health-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V81-'.$slug,'displayName'=>'V8 AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
$sessionId=(int)v81_one($pdo,"SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);

$modelPublic='vision-model-health-'.$slug;$modelHash=hash('sha256','model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Health Detector','v8.1','onnx','air3','https://example.test/v81.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();

$assignmentKey=hash('sha256','assignment-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_assignments
 (organization_id,device_id,build_session_id,assignment_key,detector_name,package_id,action,selection,rollout_status,compatibility_json,issued_at)
 VALUES (?,?,?,?,?,?,'apply','stable','active','{\"compatible\":true}',NOW(6))")
 ->execute([$org,(int)$device['id'],$sessionId,$assignmentKey,'ingredient_detector',$modelId]);$assignmentId=(int)$pdo->lastInsertId();

$insertDrift=$pdo->prepare("INSERT INTO glasses_vision_drift_samples
 (organization_id,assignment_id,package_id,device_id,build_session_id,location_id,station_id,sample_key,
  confidence_mean,latency_mean_ms,observation_count,correction_count,low_confidence_count,drift_state,drift_score,reasons_json,metadata_json,created_at)
 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))");
$insertDrift->execute([$org,$assignmentId,$modelId,(int)$device['id'],$sessionId,$location,(int)$station['id'],'health-a-'.$slug,.90,50,10,1,2,'stable',.10,'[]','{}']);
$insertDrift->execute([$org,$assignmentId,$modelId,(int)$device['id'],$sessionId,$location,(int)$station['id'],'health-b-'.$slug,.80,70,10,2,4,'warning',.35,'[\"confidence\"]','{}']);

$insertError=$pdo->prepare("INSERT INTO glasses_vision_production_errors
 (organization_id,public_id,event_key,event_hash,source_type,error_type,outcome,build_session_id,device_id,location_id,station_id,menu_item_id,model_package_id,
  predicted_component_key,expected_component_key,confidence,context_json,actor_user_id,occurred_at)
 VALUES (?,?,?,?,?,'misclassification','reclassified',?,?,?,?,?,?, 'ingredient:sausage','ingredient:pepperoni',.91,'{}',?,NOW(6))");
for($i=1;$i<=2;$i++){
    $ep='vision-health-error-'.$slug.'-'.$i;$eh=hash('sha256','health-error-'.$slug.'-'.$i);
    $insertError->execute([$org,$ep,'health-event-'.$slug.'-'.$i,$eh,'fixture',$sessionId,(int)$device['id'],$location,(int)$station['id'],$item,$modelId,$actor]);
}

$from=(new DateTimeImmutable('-1 hour'))->format(DATE_ATOM);
$to=(new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
$snapshot=glasses_vision_model_health_snapshot($pdo,$org,[
 'modelPackagePublicId'=>$modelPublic,'locationId'=>$location,'stationPublicId'=>$station['public_id'],'devicePublicId'=>$device['public_id'],
 'windowStartedAt'=>$from,'windowEndedAt'=>$to,
],$actor);

$m=$snapshot['metrics'];
v81_assert($snapshot['healthState']==='watch','Warning drift and elevated production rates must classify the health window as watch.');
v81_assert($m['sampleCount']===2&&$m['observationCount']===20,'Health snapshot must aggregate exact drift sample and observation counts.');
v81_assert($m['productionErrorCount']===2&&$m['correctionCount']===3&&$m['lowConfidenceCount']===6,'Health snapshot must aggregate V7 errors, corrections and low-confidence counts.');
v81_assert(abs((float)$m['meanConfidence']-.85)<.000001&&abs((float)$m['meanLatencyMs']-60.0)<.0001,'Health snapshot must compute observation-weighted confidence and latency.');
v81_assert(abs((float)$m['errorRate']-.10)<.000001&&abs((float)$m['correctionRate']-.15)<.000001&&abs((float)$m['lowConfidenceRate']-.30)<.000001,'Health rates must use the production observation denominator.');
v81_assert(preg_match('/^[a-f0-9]{64}$/',$snapshot['sourceFingerprint'])===1&&preg_match('/^[a-f0-9]{64}$/',$snapshot['snapshotHash'])===1,'Health evidence and snapshot must both be SHA-256 addressed.');
v81_assert(glasses_vision_model_health_verify($pdo,$org,$snapshot['publicId'])['passed']===true,'Fresh model-health snapshot must verify end to end.');

$again=glasses_vision_model_health_snapshot($pdo,$org,[
 'modelPackagePublicId'=>$modelPublic,'locationId'=>$location,'stationPublicId'=>$station['public_id'],'devicePublicId'=>$device['public_id'],
 'windowStartedAt'=>$from,'windowEndedAt'=>$to,
],$actor);
v81_assert($again['publicId']===$snapshot['publicId'],'Identical health window evidence must be idempotent.');
v81_assert((int)v81_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='model_package' AND from_public_id=? AND relation='health_snapshot' AND to_kind='model_health_snapshot'",[$org,$modelPublic])===1,'Health snapshot must attach to exact model-package lineage.');

$pdo->prepare("UPDATE glasses_vision_model_health_snapshots SET metrics_json='{}' WHERE organization_id=? AND public_id=?")->execute([$org,$snapshot['publicId']]);
v81_assert(glasses_vision_model_health_verify($pdo,$org,$snapshot['publicId'])['passed']===false,'Health ledger tampering must be detectable.');

$otherPublic='vision-model-empty-'.$slug;$otherHash=hash('sha256','empty-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Empty Detector','v8.1','onnx','air3','https://example.test/empty.onnx',?,123,'ready',?)")
 ->execute([$org,$otherPublic,$otherHash,$actor]);
$empty=glasses_vision_model_health_snapshot($pdo,$org,['modelPackagePublicId'=>$otherPublic,'windowStartedAt'=>$from,'windowEndedAt'=>$to],$actor);
v81_assert($empty['healthState']==='insufficient'&&$empty['metrics']['sampleCount']===0&&$empty['metrics']['productionErrorCount']===0,'No-evidence windows must be insufficient, never falsely healthy.');

v81_assert((string)v81_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line])==='queued','Health ledger must never mutate KDS lifecycle.');
v81_assert((int)v81_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollout_events WHERE organization_id=?",[$org])===0,'Health ledger must not activate, advance, pause or roll back models.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$source=file_get_contents(__DIR__.'/../includes/glasses-vision-model-health.php');
foreach(['model_health.snapshot','model_health.verify'] as $action)v81_assert(str_contains($api,$action),'V8 model-health API missing '.$action);
v81_assert(str_contains($page,'Production Model Health &amp; Drift Ledger'),'Vision Lab must expose the V8 model-health ledger.');
v81_assert(!str_contains($source,'rollout.activate')&&!str_contains($source,'rollout.advance')&&!str_contains($source,'kds_transition'),'V8 Section 1 must remain observational.');

echo "vision-lab-v8-production-model-health-ledger-ok\n";
