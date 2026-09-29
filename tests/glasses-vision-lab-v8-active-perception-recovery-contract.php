<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-active-perception.php';

function v85_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v85_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v85_assert(glasses_vision_active_perception_ready($pdo),'V8 active-perception migration must be installed.');

$slug='vl85-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Active Perception '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Perception','Reviewer','Perception Reviewer']);$actor=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'v85-'.$slug,'targetSeconds'=>300],$actor);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Perception Pizza',?,1)")->execute([$org,$section,'perception-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Pepperoni',?,'food','verified')")->execute([$org,'pepperoni-'.$slug]);$ingredient=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Pepperoni',0,1,1)")->execute([$item,$ingredient]);
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V85-'.$slug,'displayName'=>'V8 Recovery AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];
$sessionId=(int)v85_one($pdo,"SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic]);

$label=glasses_vision_profile_save($pdo,$org,[
 'ingredientId'=>$ingredient,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','minimumConfidence'=>.82
],$actor);

$modelPublic='vision-recovery-model-'.$slug;$modelHash=hash('sha256','recovery-model-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_packages
 (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,status,created_by)
 VALUES (?,?,'ingredient_detector','Recovery Detector','v8.5','onnx','air3','https://example.test/v85.onnx',?,123,'ready',?)")
 ->execute([$org,$modelPublic,$modelHash,$actor]);$modelId=(int)$pdo->lastInsertId();
$assignment1=hash('sha256','recovery-assignment-1-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_assignments
 (organization_id,device_id,build_session_id,assignment_key,detector_name,package_id,action,selection,rollout_status,compatibility_json,issued_at)
 VALUES (?,?,?,?,?,?,'apply','stable','active','{\"compatible\":true}',UTC_TIMESTAMP(6))")
 ->execute([$org,(int)$device['id'],$sessionId,$assignment1,'ingredient_detector',$modelId]);

$policy=glasses_vision_confidence_policy_create($pdo,$org,[
 'policyKey'=>'recovery-safe-'.$slug,'detectorName'=>'ingredient_detector','modelPackagePublicId'=>$modelPublic,'defaultThreshold'=>.70,
 'contextRules'=>['insufficient_context'=>['delta'=>.10,'requireHumanReview'=>true]]
],$actor);
$policy=glasses_vision_confidence_policy_status($pdo,$org,$policy['publicId'],'active',$actor);

$below=glasses_vision_confidence_decide($pdo,$org,[
 'devicePublicId'=>$device['public_id'],'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','confidence'=>.90
],null);
v85_assert($below['decision']==='below_threshold_hold','Fixture must produce a below-threshold governed hold.');

$plan=glasses_vision_active_perception_plan($pdo,$org,$below['publicId'],$device,null);
v85_assert($plan['primaryAction']==='capture_additional_frame'&&!$plan['requiresHuman'],'Insufficient-context low confidence must request one additional governed frame.');
v85_assert(($plan['instruction']['steps'][0]??null)==='pause_validation'&&in_array('capture_additional_frame',$plan['instruction']['steps'],true),'Recovery plan must hold validation before collecting more evidence.');
v85_assert(($plan['instruction']['maxAttempts']??0)===1,'Each confidence decision must permit only one active-perception attempt.');
v85_assert($plan['modelPackagePublicId']===$modelPublic&&($plan['evidence']['model']['artifactSha256']??null)===$modelHash,'Recovery action must preserve exact assigned model identity.');
v85_assert(glasses_vision_active_perception_verify($pdo,$org,$plan['publicId'])['passed'],'Fresh active-perception action must verify.');

$same=glasses_vision_active_perception_plan($pdo,$org,$below['publicId'],$device,null);
v85_assert($same['publicId']===$plan['publicId'],'Same confidence decision must deduplicate to the same recovery action.');

$wrong=['id'=>PHP_INT_MAX,'public_id'=>'wrong-device'];
$wrongBlocked=false;try{glasses_vision_active_perception_plan($pdo,$org,$below['publicId'],$wrong,null);}catch(InvalidArgumentException){$wrongBlocked=true;}
v85_assert($wrongBlocked,'A device must never plan recovery for another device decision.');

$plan=glasses_vision_active_perception_acknowledge($pdo,$org,$plan['publicId'],$device);
v85_assert($plan['status']==='acknowledged','Non-human recovery must become acknowledged.');
$plan=glasses_vision_active_perception_complete($pdo,$org,$plan['publicId'],$device,'success',['frameKey'=>'retry-'.$slug,'confidence'=>.96]);
v85_assert($plan['status']==='completed'&&$plan['completionHash']!==null,'Device recovery completion must be durable and hash-bound.');
v85_assert(glasses_vision_active_perception_verify($pdo,$org,$plan['publicId'])['passed'],'Completed device recovery must verify end to end.');
$eventId=(int)v85_one($pdo,"SELECT e.id FROM glasses_vision_active_perception_events e JOIN glasses_vision_active_perception_actions a ON a.id=e.action_id WHERE e.organization_id=? AND a.public_id=? ORDER BY e.id DESC LIMIT 1",[$org,$plan['publicId']]);
$eventJson=(string)v85_one($pdo,"SELECT evidence_json FROM glasses_vision_active_perception_events WHERE organization_id=? AND id=?",[$org,$eventId]);
$pdo->prepare("UPDATE glasses_vision_active_perception_events SET evidence_json='{}' WHERE organization_id=? AND id=?")->execute([$org,$eventId]);
v85_assert(!glasses_vision_active_perception_verify($pdo,$org,$plan['publicId'])['passed'],'Recovery lifecycle-event tampering must be detected.');
$pdo->prepare("UPDATE glasses_vision_active_perception_events SET evidence_json=? WHERE organization_id=? AND id=?")->execute([$eventJson,$org,$eventId]);
v85_assert(glasses_vision_active_perception_verify($pdo,$org,$plan['publicId'])['passed'],'Restored recovery event evidence must verify again.');

$originalExpiry=$plan['expiresAt'];
$pdo->prepare("UPDATE glasses_vision_active_perception_actions SET expires_at=DATE_ADD(expires_at,INTERVAL 1 SECOND) WHERE organization_id=? AND public_id=?")->execute([$org,$plan['publicId']]);
v85_assert(!glasses_vision_active_perception_verify($pdo,$org,$plan['publicId'])['passed'],'Changing immutable recovery expiry must be detected.');
$pdo->prepare("UPDATE glasses_vision_active_perception_actions SET expires_at=? WHERE organization_id=? AND public_id=?")->execute([$originalExpiry,$org,$plan['publicId']]);
v85_assert(glasses_vision_active_perception_verify($pdo,$org,$plan['publicId'])['passed'],'Restoring immutable recovery evidence must restore verification.');

usleep(2000);
$assignment2=hash('sha256','recovery-assignment-2-'.$slug);
$pdo->prepare("INSERT INTO glasses_vision_model_assignments
 (organization_id,device_id,build_session_id,assignment_key,detector_name,package_id,action,selection,rollout_status,compatibility_json,issued_at)
 VALUES (?,?,?,?,?,?,'apply','stable','active','{\"compatible\":true}',UTC_TIMESTAMP(6))")
 ->execute([$org,(int)$device['id'],$sessionId,$assignment2,'ingredient_detector',$modelId]);
$stale=false;try{glasses_vision_active_perception_plan($pdo,$org,$below['publicId'],$device,null);}catch(InvalidArgumentException){$stale=true;}
v85_assert($stale,'Recovery planning must reject a confidence decision after the active model assignment changes.');

$review=glasses_vision_confidence_decide($pdo,$org,[
 'devicePublicId'=>$device['public_id'],'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','confidence'=>.95
],null);
v85_assert($review['decision']==='human_review','High-confidence insufficient context must require human review.');
$human=glasses_vision_active_perception_plan($pdo,$org,$review['publicId'],$device,null);
v85_assert($human['primaryAction']==='request_human_confirmation'&&$human['requiresHuman'],'Human-review confidence decisions must produce human-confirmation recovery.');
$human=glasses_vision_active_perception_acknowledge($pdo,$org,$human['publicId'],$device);
v85_assert($human['status']==='awaiting_human','Device acknowledgement must not self-resolve human confirmation.');
$deviceResolve=false;try{glasses_vision_active_perception_complete($pdo,$org,$human['publicId'],$device,'success',[]);}catch(InvalidArgumentException){$deviceResolve=true;}
v85_assert($deviceResolve,'Glasses must never resolve a human-confirmation action themselves.');
$human=glasses_vision_active_perception_resolve_human($pdo,$org,$human['publicId'],'confirmed','Cook visually confirmed the ingredient.',$actor);
v85_assert($human['status']==='completed'&&glasses_vision_active_perception_verify($pdo,$org,$human['publicId'])['passed'],'Human resolution must be actor-bound and verifiable.');

$pdo->prepare("UPDATE glasses_vision_active_perception_actions SET resolved_by=NULL WHERE organization_id=? AND public_id=?")->execute([$org,$human['publicId']]);
v85_assert(!glasses_vision_active_perception_verify($pdo,$org,$human['publicId'])['passed'],'Human resolution attribution tampering must be detected.');
$pdo->prepare("UPDATE glasses_vision_active_perception_actions SET resolved_by=? WHERE organization_id=? AND public_id=?")->execute([$actor,$org,$human['publicId']]);

v85_assert((int)v85_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='confidence_decision' AND relation='recovery_planned_as' AND to_kind='active_perception_action'",[$org])===2,'Every recovery plan must retain confidence-decision lineage.');
v85_assert((string)v85_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line])==='queued','Active perception must never mutate KDS lifecycle.');
v85_assert((string)v85_one($pdo,"SELECT status FROM glasses_build_sessions WHERE organization_id=? AND public_id=?",[$org,$sessionPublic])==='active','Active perception must never complete or cancel the build session.');
v85_assert((int)v85_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollout_events WHERE organization_id=?",[$org])===0,'Active perception must not mutate model rollout state.');

$devApi=file_get_contents(__DIR__.'/../api/glasses-device.php');$labApi=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-active-perception.php');
foreach(['vision.active_perception.plan','vision.active_perception.acknowledge','vision.active_perception.complete'] as $action)v85_assert(str_contains($devApi,$action),'Device API missing '.$action);
foreach(['active_perception.resolve_human','active_perception.verify'] as $action)v85_assert(str_contains($labApi,$action),'Vision Lab API missing '.$action);
v85_assert(str_contains($page,'Active Perception &amp; Recovery Actions'),'Vision Lab must expose Section 5 active perception recovery.');
foreach(['rollout.activate','rollout.advance','kds_transition'] as $forbidden)v85_assert(!str_contains($source,$forbidden),'Active perception service must remain a recovery-instruction layer only.');

echo "vision-lab-v8-active-perception-recovery-ok\n";
