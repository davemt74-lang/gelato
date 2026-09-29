<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-vision-confidence-policy.php';

function v84_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function v84_one(PDO $pdo,string $sql,array $a=[]):mixed{$q=$pdo->prepare($sql);$q->execute($a);return $q->fetchColumn();}

$pdo=app_pdo();
v84_assert(glasses_vision_confidence_policy_ready($pdo),'V8 confidence-policy migration must be installed.');

$slug='vl84-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Confidence Policy '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','fixture-hash','Policy','Reviewer','Policy Reviewer']);$actor=(int)$pdo->lastInsertId();
$station=kds_station_save($pdo,$org,$location,['name'=>'Pizza Line','slug'=>'v84-'.$slug,'targetSeconds'=>300],$actor);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Pizza',?,'active',1)")->execute([$org,'pizza-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Policy Pizza',?,1)")->execute([$org,$section,'policy-pizza-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,'Pepperoni',?,'food','verified')")->execute([$org,'pepperoni-'.$slug]);$ingredient=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,'Pepperoni',0,1,1)")->execute([$item,$ingredient]);
kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$actor);

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$actor);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$actor);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$actor,false);
$q=$pdo->prepare("SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?");$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();
$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$actor,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-V84-'.$slug,'displayName'=>'V8 Policy AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);$sessionPublic=(string)$session['publicId'];

$label=glasses_vision_profile_save($pdo,$org,[
 'ingredientId'=>$ingredient,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','minimumConfidence'=>.82
],$actor);
v84_assert(abs((float)$label['minimumConfidence']-.82)<.0001,'Fixture label floor must be 0.82.');

$weak=false;try{glasses_vision_confidence_policy_create($pdo,$org,['policyKey'=>'weak','detectorName'=>'ingredient_detector','defaultThreshold'=>.49],$actor);}catch(InvalidArgumentException){$weak=true;}
v84_assert($weak,'Policy default must never fall below the global 0.50 hard floor.');
$negative=false;try{glasses_vision_confidence_policy_create($pdo,$org,['policyKey'=>'negative','detectorName'=>'ingredient_detector','defaultThreshold'=>.70,'contextRules'=>['context_shift'=>['delta'=>-.01]]],$actor);}catch(InvalidArgumentException){$negative=true;}
v84_assert($negative,'Context adaptation must never use a negative threshold delta.');

$policy=glasses_vision_confidence_policy_create($pdo,$org,[
 'policyKey'=>'production-safe','detectorName'=>'ingredient_detector','defaultThreshold'=>.70,
 'classRules'=>['pepperoni'=>['minimumConfidence'=>.75,'requireHumanReview'=>false]],
 'contextRules'=>['insufficient_context'=>['delta'=>.10,'requireHumanReview'=>true]]
],$actor);
v84_assert($policy['status']==='draft'&&glasses_vision_confidence_policy_verify($pdo,$org,$policy['publicId'])['passed'],'New policy must be draft and hash-verifiable.');
$policy=glasses_vision_confidence_policy_status($pdo,$org,$policy['publicId'],'active',$actor);
$duplicate=glasses_vision_confidence_policy_create($pdo,$org,[
 'policyKey'=>'production-safe-duplicate','detectorName'=>'ingredient_detector','defaultThreshold'=>.75
],$actor);
$duplicateBlocked=false;try{glasses_vision_confidence_policy_status($pdo,$org,$duplicate['publicId'],'active',$actor);}catch(InvalidArgumentException){$duplicateBlocked=true;}
v84_assert($duplicateBlocked,'A detector/model scope must not have multiple active confidence policies.');

$review=glasses_vision_confidence_decide($pdo,$org,[
 'devicePublicId'=>$device['public_id'],'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','confidence'=>.95
],$actor);
v84_assert($review['decision']==='human_review','High confidence under insufficient context must require governed human review.');
v84_assert(abs($review['labelFloor']-.82)<.0001&&abs($review['effectiveThreshold']-.92)<.0001,'Effective threshold must preserve the stricter label floor and add only a nonnegative context adjustment.');
v84_assert(glasses_vision_confidence_decision_verify($pdo,$org,$review['publicId'])['passed'],'Fresh confidence decision must verify.');

$below=glasses_vision_confidence_decide($pdo,$org,[
 'devicePublicId'=>$device['public_id'],'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','confidence'=>.90
],$actor);
v84_assert($below['decision']==='below_threshold_hold'&&abs($below['effectiveThreshold']-.92)<.0001,'Confidence below effective floor must be held, never accepted.');

$unmapped=glasses_vision_confidence_decide($pdo,$org,[
 'devicePublicId'=>$device['public_id'],'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','modelLabel'=>'unknown topping','confidence'=>.99
],$actor);
v84_assert($unmapped['decision']==='unmapped_hold','Unmapped detector labels must fail closed even at very high confidence.');

$again=glasses_vision_confidence_decide($pdo,$org,[
 'devicePublicId'=>$device['public_id'],'buildSessionPublicId'=>$sessionPublic,'detectorName'=>'ingredient_detector','modelLabel'=>'pepperoni','confidence'=>.95
],$actor);
v84_assert($again['publicId']===$review['publicId'],'Identical decision evidence must be idempotent.');
v84_assert((int)v84_one($pdo,"SELECT COUNT(*) FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='confidence_policy' AND from_public_id=? AND relation='decided_as' AND to_kind='confidence_decision'",[$org,$policy['publicId']])===3,'Each distinct governed decision must retain policy lineage.');

v84_assert((string)v84_one($pdo,"SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?",[$org,$line])==='queued','Confidence decision runtime must not mutate KDS lifecycle.');
v84_assert((int)v84_one($pdo,"SELECT COUNT(*) FROM glasses_vision_model_rollout_events WHERE organization_id=?",[$org])===0,'Confidence policy must not mutate model rollout state.');

$pdo->prepare("UPDATE glasses_vision_confidence_decisions SET evidence_json='{}' WHERE organization_id=? AND public_id=?")->execute([$org,$review['publicId']]);
v84_assert(!glasses_vision_confidence_decision_verify($pdo,$org,$review['publicId'])['passed'],'Confidence decision tampering must be detectable.');

$policy=glasses_vision_confidence_policy_status($pdo,$org,$policy['publicId'],'retired',$actor);
$reactivate=false;try{glasses_vision_confidence_policy_status($pdo,$org,$policy['publicId'],'active',$actor);}catch(InvalidArgumentException){$reactivate=true;}
v84_assert($reactivate,'Retired confidence policies must not silently reactivate.');

$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');$deviceApi=file_get_contents(__DIR__.'/../api/glasses-device.php');$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');$source=file_get_contents(__DIR__.'/../includes/glasses-vision-confidence-policy.php');
foreach(['confidence_policy.create','confidence_policy.activate','confidence_policy.retire','confidence_policy.verify','confidence_policy.decide','confidence_decision.verify'] as $action)v84_assert(str_contains($api,$action),'V8 confidence-policy API missing '.$action);
v84_assert(str_contains($page,'Adaptive Confidence &amp; Decision Policy'),'Vision Lab must expose Section 4 adaptive confidence controls.');
v84_assert(str_contains($deviceApi,"vision.confidence_decision"),'Authenticated glasses runtime must expose governed confidence decisions.');
v84_assert(str_contains($source,'GLASSES_VISION_CONFIDENCE_HARD_FLOOR=0.50'),'Global 0.50 safety floor must be explicit.');
v84_assert(!str_contains($source,'rollout.activate')&&!str_contains($source,'rollout.advance')&&!str_contains($source,'kds_transition'),'Confidence policy must remain a decision layer only.');

echo "vision-lab-v8-adaptive-confidence-policy-ok\n";
