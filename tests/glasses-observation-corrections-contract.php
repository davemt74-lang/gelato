<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-validation.php';

$pdo=app_pdo();

function gcor_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function gcor_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function gcor_component(array $session,string $key): array {
    foreach($session['components'] as $component)if((string)$component['componentKey']===$key)return $component;
    throw new RuntimeException('Component not found: '.$key);
}

gcor_assert(glasses_build_correction_ready($pdo),'Observation correction migration must be installed.');

$slug='gcor-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Correction CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Correction','Lead','Correction Lead']);$user=(int)$pdo->lastInsertId();
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
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 14','guestCount'=>1],$user);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$user);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$user,false);
$q=$pdo->prepare('SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-COR-'.$slug,'displayName'=>'Correction AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);
$sessionPublic=(string)$session['publicId'];

foreach([
    ['key'=>'obs-bread','component'=>$keys['Bread'],'quantity'=>1,'confidence'=>0.96],
    ['key'=>'obs-turkey','component'=>$keys['Turkey'],'quantity'=>1,'confidence'=>0.96],
    ['key'=>'obs-bacon','component'=>$keys['Bacon'],'quantity'=>1,'confidence'=>0.96],
] as $obs){
    $session=glasses_build_observe($pdo,$device,$sessionPublic,[
        'observationKey'=>$obs['key'],'componentKey'=>$obs['component'],'observationAction'=>'added',
        'quantity'=>$obs['quantity'],'confidence'=>$obs['confidence'],'trackingId'=>$obs['key'].'-track',
    ]);
}
gcor_assert((bool)$session['summary']['accounted']===true,'Baseline observations must account for all required ingredients.');
$ready=glasses_validation_evaluate($pdo,$device,$sessionPublic);
gcor_assert((string)$ready['status']==='ready_for_finishing','Baseline evidence must be ready for finishing.');

$reject=glasses_build_correct_observation($pdo,$device,$sessionPublic,[
    'correctionKey'=>'reject-turkey-1',
    'observationKey'=>'obs-turkey',
    'resolution'=>'reject',
    'reason'=>'Cook says this was the turkey pan, not a transfer.',
    'metadata'=>['input'=>'voice'],
]);
$session=$reject['buildSession'];
$turkey=gcor_component($session,$keys['Turkey']);
gcor_assert((string)$turkey['status']==='waiting'&&(float)$turkey['detectedQuantity']===0.0,'Rejecting a false positive must remove its effective contribution.');
gcor_assert((string)gcor_component($session,$keys['Bread'])['status']==='confirmed','Rejecting Turkey must not disturb Bread.');
gcor_assert((string)gcor_component($session,$keys['Bacon'])['status']==='confirmed','Rejecting Turkey must not disturb Bacon.');
$notReady=glasses_validation_evaluate($pdo,$device,$sessionPublic);
gcor_assert((string)$notReady['status']==='pending','Correcting away required evidence must revoke finishing readiness.');

$rejectAgain=glasses_build_correct_observation($pdo,$device,$sessionPublic,[
    'correctionKey'=>'reject-turkey-1',
    'observationKey'=>'obs-turkey',
    'resolution'=>'reject',
]);
gcor_assert((int)gcor_one($pdo,'SELECT COUNT(*) FROM glasses_observation_corrections WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?)',[$org,$org,$sessionPublic])===1,'Correction key must be idempotent.');
gcor_assert((string)$rejectAgain['correction']['correctionKey']==='reject-turkey-1','Idempotent correction must return the original ledger entry.');

$replaceTurkey=glasses_build_correct_observation($pdo,$device,$sessionPublic,[
    'correctionKey'=>'replace-turkey-1',
    'observationKey'=>'obs-turkey',
    'resolution'=>'replace',
    'targetComponentKey'=>$keys['Turkey'],
    'correctedQuantity'=>1,
    'hasCorrectedQuantity'=>true,
    'reason'=>'Cook restored the Turkey event after review.',
]);
$turkey=gcor_component($replaceTurkey['buildSession'],$keys['Turkey']);
gcor_assert((string)$turkey['status']==='confirmed'&&(float)$turkey['detectedQuantity']===1.0,'Latest correction must supersede the earlier rejection.');

$halfBacon=glasses_build_correct_observation($pdo,$device,$sessionPublic,[
    'correctionKey'=>'bacon-half',
    'observationKey'=>'obs-bacon',
    'resolution'=>'replace',
    'targetComponentKey'=>$keys['Bacon'],
    'correctedQuantity'=>0.5,
    'hasCorrectedQuantity'=>true,
]);
$bacon=gcor_component($halfBacon['buildSession'],$keys['Bacon']);
gcor_assert((string)$bacon['status']==='detected'&&abs((float)$bacon['detectedQuantity']-0.5)<0.0001,'Quantity correction must recompute the component deterministically.');

$manual=glasses_build_confirm($pdo,$device,$sessionPublic,$keys['Bacon']);
gcor_assert((string)gcor_component($manual,$keys['Bacon'])['status']==='confirmed','Cook manual confirmation must confirm Bacon after quantity review.');

$rejectBread=glasses_build_correct_observation($pdo,$device,$sessionPublic,[
    'correctionKey'=>'reject-bread-1',
    'observationKey'=>'obs-bread',
    'resolution'=>'reject',
]);
gcor_assert((string)gcor_component($rejectBread['buildSession'],$keys['Bacon'])['status']==='confirmed','Evidence recomputation must preserve explicit manual confirmations.');

$unexpectedKey='vision:unexpected:cheese-'.$slug;
$unexpected=glasses_build_observe($pdo,$device,$sessionPublic,[
    'observationKey'=>'obs-cheese','componentKey'=>$unexpectedKey,'displayName'=>'Cheese',
    'observationAction'=>'added','quantity'=>1,'confidence'=>0.95,
]);
gcor_assert((string)gcor_component($unexpected,$unexpectedKey)['status']==='unexpected','Unexpected evidence must enter the build as an exception.');

$resolved=glasses_build_resolve_unexpected($pdo,$device,$sessionPublic,$unexpectedKey);
gcor_assert((string)gcor_component($resolved,$unexpectedKey)['status']==='ignored','Cook resolution must ignore the unexpected component.');

$restoreBread=glasses_build_correct_observation($pdo,$device,$sessionPublic,[
    'correctionKey'=>'restore-bread',
    'observationKey'=>'obs-bread',
    'resolution'=>'replace',
    'targetComponentKey'=>$keys['Bread'],
    'correctedQuantity'=>1,
    'hasCorrectedQuantity'=>true,
]);
gcor_assert((string)gcor_component($restoreBread['buildSession'],$unexpectedKey)['status']==='ignored','Evidence recomputation must preserve explicit unexpected resolutions.');

$reclassifyCheese=glasses_build_correct_observation($pdo,$device,$sessionPublic,[
    'correctionKey'=>'cheese-was-turkey',
    'observationKey'=>'obs-cheese',
    'resolution'=>'replace',
    'targetComponentKey'=>$keys['Turkey'],
    'correctedQuantity'=>1,
    'hasCorrectedQuantity'=>true,
    'reason'=>'The model mislabeled a Turkey slice as cheese.',
]);
gcor_assert((string)gcor_component($reclassifyCheese['buildSession'],$unexpectedKey)['status']==='ignored','Reclassified unexpected source must no longer block the build.');
gcor_assert((float)gcor_component($reclassifyCheese['buildSession'],$keys['Turkey'])['detectedQuantity']===2.0,'Reclassification must move effective quantity onto the configured recipe component.');

$invalidTarget=false;
try{
    glasses_build_correct_observation($pdo,$device,$sessionPublic,[
        'correctionKey'=>'bad-target','observationKey'=>'obs-bread','resolution'=>'replace',
        'targetComponentKey'=>$unexpectedKey,'correctedQuantity'=>1,'hasCorrectedQuantity'=>true,
    ]);
}catch(InvalidArgumentException){$invalidTarget=true;}
gcor_assert($invalidTarget,'Corrections must not reclassify evidence into an unconfigured unexpected component.');

$evidence=glasses_build_evidence($pdo,$device,$sessionPublic,20);
gcor_assert(count($evidence)===4,'Evidence history must retain every original immutable observation.');
$cheeseEvidence=array_values(array_filter($evidence,static fn(array $e):bool=>$e['observationKey']==='obs-cheese'))[0]??null;
gcor_assert(is_array($cheeseEvidence),'Evidence history must include the original unexpected observation.');
gcor_assert((string)$cheeseEvidence['componentKey']===$unexpectedKey,'Learning ledger must preserve the original model classification.');
gcor_assert((string)($cheeseEvidence['latestCorrection']['targetComponentKey']??'')===$keys['Turkey'],'Learning ledger must expose the latest human reclassification.');

gcor_assert((int)gcor_one($pdo,'SELECT COUNT(*) FROM glasses_build_observations WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?)',[$org,$org,$sessionPublic])===4,'Corrections must never mutate or duplicate original observation rows.');
gcor_assert((int)gcor_one($pdo,"SELECT COUNT(*) FROM glasses_build_events WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?) AND event_type='observation_corrected'",[$org,$org,$sessionPublic])===6,'Every non-idempotent correction must append one build event.');

$grant2=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired2=glasses_pair_device($pdo,(string)$grant2['pairingCode'],['hardwareIdentifier'=>'AIR3-COR-2-'.$slug,'displayName'=>'Second AIR3']);
$device2=glasses_authenticate_token($pdo,(string)$paired2['deviceToken']);
$crossDevice=false;
try{glasses_build_evidence($pdo,$device2,$sessionPublic,20);}catch(InvalidArgumentException){$crossDevice=true;}
gcor_assert($crossDevice,'Correction evidence must preserve build-session device isolation.');

gcor_assert((string)gcor_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$line])==='queued','Human corrections must not mutate the KDS lifecycle.');

echo "glasses-observation-corrections-ok\n";
