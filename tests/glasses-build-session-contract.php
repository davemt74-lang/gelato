<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';

$pdo=app_pdo();
function gbci_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function gbci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

gbci_assert(glasses_build_ready($pdo),'Build session migration must be installed.');
$slug='gbci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Glasses Build '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Build','Lead','Build Lead']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$user]);
$station=kds_station_save($pdo,$org,$location,['name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300],$user);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Lunch',?,'active',1)")->execute([$org,'lunch-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Club Sandwich',?,'Build to recipe.',1)")->execute([$org,$section,'club-'.$slug]);$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',15.00,'USD',1)")->execute([$item]);$price=(int)$pdo->lastInsertId();

$keys=[];
foreach(['Bread','Turkey','Bacon'] as $i=>$name){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")->execute([$org,$name,strtolower($name).'-'.$slug]);
    $ingredientId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)')->execute([$item,$ingredientId,$name,$i+1]);
    $keys[$name]=glasses_build_component_key($name,$ingredientId);
}

kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$user);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 2','guestCount'=>1],$user);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'NO TOMATO',$user);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$user,false);
$q=$pdo->prepare('SELECT public_id,status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');$q->execute([$org,$line]);$kds=$q->fetch();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-BUILD-'.$slug,'displayName'=>'Build AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);

$work=glasses_current_work($pdo,$device,false);
$staleStart=false;
try{glasses_build_start($pdo,$device,(string)$kds['public_id'],str_repeat('a',64));}catch(InvalidArgumentException){$staleStart=true;}
gbci_assert($staleStart,'A client-supplied stale source revision must not start a build.');
$session=glasses_build_start($pdo,$device,(string)$kds['public_id'],$work['revision']);
gbci_assert((string)$session['status']==='active','Build session must start active.');
gbci_assert(count($session['components'])===4,'Customized build must snapshot three base ingredients and one operator review.');
gbci_assert((int)$session['summary']['required']===1&&(int)$session['summary']['confirmed']===0,'Customization review must be the required operator decision.');
gbci_assert((string)$session['context']['specialInstructions']==='NO TOMATO','Build session must snapshot POS instructions.');
gbci_assert((string)gbci_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$line])==='queued','Starting build session must not mutate KDS lifecycle.');

$again=glasses_build_start($pdo,$device,(string)$kds['public_id'],$work['revision']);
gbci_assert((string)$again['publicId']===(string)$session['publicId'],'Repeated start on the same device must be idempotent.');

$bread=glasses_build_observe($pdo,$device,(string)$session['publicId'],[
    'observationKey'=>'obs-bread-1','componentKey'=>$keys['Bread'],'observationAction'=>'added','quantity'=>1,'confidence'=>0.96,'trackingId'=>'track-bread','bbox'=>['x'=>0.2,'y'=>0.3,'w'=>0.2,'h'=>0.1]
]);
$breadRow=array_values(array_filter($bread['components'],static fn(array $c):bool=>$c['componentKey']===$keys['Bread']))[0];
gbci_assert((string)$breadRow['status']==='confirmed'&&(float)$breadRow['detectedQuantity']===1.0,'High-confidence expected ingredient must auto-confirm.');

$breadDuplicate=glasses_build_observe($pdo,$device,(string)$session['publicId'],[
    'observationKey'=>'obs-bread-1','componentKey'=>$keys['Bread'],'observationAction'=>'added','quantity'=>1,'confidence'=>0.96
]);
$breadRow2=array_values(array_filter($breadDuplicate['components'],static fn(array $c):bool=>$c['componentKey']===$keys['Bread']))[0];
gbci_assert((float)$breadRow2['detectedQuantity']===1.0,'Duplicate observation key must be idempotent.');
$trackReplay=glasses_build_observe($pdo,$device,(string)$session['publicId'],[
    'observationKey'=>'obs-bread-rekeyed','componentKey'=>$keys['Bread'],'observationAction'=>'added','quantity'=>1,'confidence'=>0.96,'trackingId'=>'track-bread',
]);
gbci_assert((float)$trackReplay['components'][0]['detectedQuantity']===1.0,'A repeated track with a new observation key must not count twice.');

$turkey=glasses_build_observe($pdo,$device,(string)$session['publicId'],[
    'observationKey'=>'obs-turkey-1','componentKey'=>$keys['Turkey'],'observationAction'=>'added','quantity'=>1,'confidence'=>0.62
]);
$turkeyRow=array_values(array_filter($turkey['components'],static fn(array $c):bool=>$c['componentKey']===$keys['Turkey']))[0];
gbci_assert((string)$turkeyRow['status']==='verify','Low-confidence advisory ingredient must remain labeled for verification.');
$turkey=glasses_build_confirm($pdo,$device,(string)$session['publicId'],$keys['Turkey']);
$turkeyRow=array_values(array_filter($turkey['components'],static fn(array $c):bool=>$c['componentKey']===$keys['Turkey']))[0];
gbci_assert((string)$turkeyRow['status']==='confirmed'&&(float)$turkeyRow['confidence']===1.0,'Manual component confirmation must resolve verification.');

$bacon=glasses_build_observe($pdo,$device,(string)$session['publicId'],[
    'observationKey'=>'obs-bacon-1','componentKey'=>$keys['Bacon'],'observationAction'=>'seen','quantity'=>1,'confidence'=>0.91
]);
gbci_assert($bacon['summary']['accounted']===false,'Base ingredient observations must not silently clear customization review.');
$forgedReview=false;
try{
    glasses_build_observe($pdo,$device,$session['publicId'],[
        'observationKey'=>'fake-review','componentKey'=>strtoupper(GLASSES_BUILD_CUSTOMIZATION_REVIEW),'quantity'=>1,'confidence'=>1,
    ]);
}catch(InvalidArgumentException){$forgedReview=true;}
gbci_assert($forgedReview,'A differently cased component key must not let camera evidence satisfy manual review.');
$reviewed=glasses_build_confirm($pdo,$device,$session['publicId'],GLASSES_BUILD_CUSTOMIZATION_REVIEW,$user);
gbci_assert($reviewed['summary']['accounted']===true,'Explicit operator confirmation must complete the customized order.');

$unexpectedKey='vision:cheese-'.$slug;
$unexpected=glasses_build_observe($pdo,$device,(string)$session['publicId'],[
    'observationKey'=>'obs-unexpected-1','componentKey'=>$unexpectedKey,'displayName'=>'Cheese','observationAction'=>'added','quantity'=>1,'confidence'=>0.93
]);
gbci_assert((int)$unexpected['summary']['unexpected']===1&&(bool)$unexpected['summary']['accounted']===false,'Unexpected ingredient must block accounted state.');
$resolved=glasses_build_resolve_unexpected($pdo,$device,(string)$session['publicId'],$unexpectedKey);
gbci_assert((int)$resolved['summary']['unexpected']===0&&(bool)$resolved['summary']['accounted']===true,'Resolved unexpected ingredient must clear the exception.');

$grant2=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired2=glasses_pair_device($pdo,(string)$grant2['pairingCode'],['hardwareIdentifier'=>'AIR3-BUILD-2-'.$slug,'displayName'=>'Second AIR3']);
$device2=glasses_authenticate_token($pdo,(string)$paired2['deviceToken']);
$conflict=false;try{glasses_build_start($pdo,$device2,(string)$kds['public_id']);}catch(InvalidArgumentException){$conflict=true;}
gbci_assert($conflict,'A second device must not steal an active build session.');
$crossDevice=false;try{glasses_build_confirm($pdo,$device2,(string)$session['publicId'],$keys['Bread']);}catch(InvalidArgumentException){$crossDevice=true;}
gbci_assert($crossDevice,'Build session actions must be device-isolated.');

gbci_assert((string)gbci_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$line])==='queued','Build observations and confirmations must not mutate KDS lifecycle.');
gbci_assert((int)gbci_one($pdo,'SELECT COUNT(*) FROM glasses_build_observations WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?)',[$org,$org,$session['publicId']])===4,'Only unique observations must persist.');
gbci_assert((int)gbci_one($pdo,'SELECT COUNT(*) FROM glasses_build_events WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?)',[$org,$org,$session['publicId']])>=7,'Build lifecycle must retain append-only event history.');

// A normal two-unit order needs two units of each component. Review-only
// camera evidence has a durable trail without advancing detected quantities.
$two=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$user);
$two=pos_add_item($pdo,$org,$two['publicId'],$price,2,'',$user);
$twoLine=(int)$two['items'][0]['id'];
kds_send_check($pdo,$org,$two['publicId'],$user,false);
$twoKds=(string)gbci_one($pdo,'SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$twoLine]);
$twoSession=glasses_build_start($pdo,$device,$twoKds);
gbci_assert((float)$twoSession['components'][0]['expectedQuantity']===2.0,'Two ordered units must double base ingredient quantities.');
$partial=glasses_build_observe($pdo,$device,$twoSession['publicId'],[
    'observationKey'=>'obs-partial','componentKey'=>$keys['Bread'],'quantity'=>1,'confidence'=>0.97,'trackingId'=>'bread-first',
]);
gbci_assert($partial['components'][0]['status']==='detected','One unit of a two-unit component must remain incomplete.');
$cameraReview=glasses_build_observe($pdo,$device,$twoSession['publicId'],[
    'observationKey'=>'obs-review-only','componentKey'=>$keys['Bread'],'quantity'=>1,'confidence'=>0.74,
    'metadata'=>['evidenceKind'=>'manual_camera_review'],
]);
gbci_assert((float)$cameraReview['components'][0]['detectedQuantity']===1.0&&$cameraReview['components'][0]['status']==='detected',
    'Review-only camera evidence must not inherit high confidence or add effective quantity.');
$corrected=glasses_build_correct_observation($pdo,$device,$twoSession['publicId'],[
    'correctionKey'=>'retain-review-only','observationKey'=>'obs-review-only','resolution'=>'replace','correctedQuantity'=>2,
]);
gbci_assert((float)$corrected['buildSession']['components'][0]['detectedQuantity']===1.0,
    'Recomputing after camera evidence correction must keep review-only evidence out of effective quantity.');

$governed=['componentKey'=>$keys['Bread'],'quantity'=>1,'confidence'=>0.96,'trackingId'=>'governed-track','metadata'=>['evidenceKind'=>'governed_browser']];
$added=glasses_build_observe($pdo,$device,$twoSession['publicId'],['observationKey'=>'governed-added']+$governed);
gbci_assert($added['components'][0]['detectedQuantity']===2.0,'A newly observed track credits its placement.');
$removed=glasses_build_observe($pdo,$device,$twoSession['publicId'],[
    'observationKey'=>'governed-removed','observationAction'=>'removed','quantity'=>99,
]+$governed);
gbci_assert($removed['components'][0]['detectedQuantity']===1.0,'Removal debits only what the track contributed.');
$replayed=glasses_build_observe($pdo,$device,$twoSession['publicId'],['observationKey'=>'governed-replayed']+$governed);
gbci_assert($replayed['components'][0]['detectedQuantity']===1.0,'A removed track must never be revived under a fresh observation key.');
$replacementTrack=glasses_build_observe($pdo,$device,$twoSession['publicId'],[
    'observationKey'=>'governed-replacement','trackingId'=>'governed-track-new',
]+$governed);
gbci_assert($replacementTrack['components'][0]['detectedQuantity']===2.0,'New placement requires a new track identity.');

$beforeEdit=glasses_current_work($pdo,$device,false);
pos_update_item($pdo,$org,$two['publicId'],$twoLine,3,'');
pos_update_item($pdo,$org,$two['publicId'],$twoLine,2,'');
$oldRevision=false;try{glasses_build_start($pdo,$device,$twoKds,$beforeEdit['revision']);}catch(InvalidArgumentException){$oldRevision=true;}
gbci_assert($oldRevision,'Restoring the POS values must still invalidate the stale work revision.');
$oldEvidence=false;try{glasses_build_confirm($pdo,$device,$twoSession['publicId'],$keys['Bread']);}catch(InvalidArgumentException){$oldEvidence=true;}
gbci_assert($oldEvidence,'Restoring the POS values must still invalidate the old build evidence.');
$twoSession=glasses_build_start($pdo,$device,$twoKds);

$stationB=kds_station_save($pdo,$org,$location,['name'=>'Second Line','slug'=>'second-line','targetSeconds'=>300],$user);
kds_reassign($pdo,$org,$twoKds,(string)$stationB['public_id'],$user);
$wrongStation=false;try{glasses_build_confirm($pdo,$device,$twoSession['publicId'],$keys['Bread']);}catch(InvalidArgumentException){$wrongStation=true;}
gbci_assert($wrongStation,'A build on the old station must reject manual confirmation after reassignment.');
kds_reassign($pdo,$org,$twoKds,(string)$station['public_id'],$user);
$staleAfterReturn=false;try{glasses_build_confirm($pdo,$device,$twoSession['publicId'],$keys['Bread']);}catch(InvalidArgumentException){$staleAfterReturn=true;}
gbci_assert($staleAfterReturn,'Rerouting back must not reactivate the old build evidence.');
$replacement=glasses_build_start($pdo,$device,$twoKds);
gbci_assert($replacement['publicId']!==$twoSession['publicId']&&$replacement['components'][0]['detectedQuantity']===0.0,
    'Restart must retire obsolete routing evidence and create a clean current build.');
pos_void_item($pdo,$org,$two['publicId'],$twoLine,'Order changed',$user);
$voidBlocked=false;try{glasses_build_confirm($pdo,$device,$replacement['publicId'],$keys['Bread']);}catch(InvalidArgumentException){$voidBlocked=true;}
gbci_assert($voidBlocked,'A POS void alone must invalidate build writes before the KDS cancel is visible.');

$custom=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$user);
$custom=pos_add_item($pdo,$org,$custom['publicId'],$price,1,'',$user);
$customLine=(int)$custom['items'][0]['id'];
$pdo->prepare("INSERT INTO pos_check_item_modifiers (organization_id,pos_check_item_id,modifier_option_id,modifier_group_name_snapshot,modifier_name_snapshot,quantity,unit_amount,total_amount)
    VALUES (?,?,NULL,'Add-ons','Extra Bacon',1,2,2)")->execute([$org,$customLine]);
kds_send_check($pdo,$org,$custom['publicId'],$user,false);
$customKds=(string)gbci_one($pdo,'SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$customLine]);
$modifierBuild=glasses_build_start($pdo,$device,$customKds);
gbci_assert($modifierBuild['context']['modifiers'][0]['name']==='Extra Bacon'
    &&in_array(GLASSES_BUILD_CUSTOMIZATION_REVIEW,array_column($modifierBuild['components'],'componentKey'),true),
    'A POS modifier alone must trigger explicit review with the ordered modifier visible.');

$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Large','LG',18.00,'USD',2)")->execute([$item]);
$largePrice=(int)$pdo->lastInsertId();
$sized=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1],$user);
$sized=pos_add_item($pdo,$org,$sized['publicId'],$largePrice,1,'',$user);
$sizedLine=(int)$sized['items'][0]['id'];
kds_send_check($pdo,$org,$sized['publicId'],$user,false);
$sizedKds=(string)gbci_one($pdo,'SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$sizedLine]);
$sizeBuild=glasses_build_start($pdo,$device,$sizedKds);
gbci_assert($sizeBuild['context']['optionName']==='Large'
    &&in_array(GLASSES_BUILD_CUSTOMIZATION_REVIEW,array_column($sizeBuild['components'],'componentKey'),true),
    'A POS size variant alone must trigger explicit review with the ordered size visible.');

echo "glasses-build-session-ok\n";
