<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/kds-production.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-definition.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-validation.php';
require_once __DIR__.'/../includes/glasses-handoff.php';

$pdo=app_pdo();

function ghci_assert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function ghci_one(PDO $pdo,string $sql,array $args=[]): mixed {
    $q=$pdo->prepare($sql); $q->execute($args); return $q->fetchColumn();
}
function ghci_complete_component(PDO $pdo,array $device,string $sessionPublic,string $key,float $quantity,string $obs): array {
    return glasses_build_observe($pdo,$device,$sessionPublic,[
        'observationKey'=>$obs,
        'componentKey'=>$key,
        'observationAction'=>'added',
        'quantity'=>$quantity,
        'confidence'=>0.97,
        'trackingId'=>$obs.'-track',
    ]);
}

ghci_assert(glasses_handoff_ready($pdo),'Expo handoff migration must be installed.');

$slug='ghci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Handoff CI '.$slug]);
$org=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")
    ->execute([$org]);
$location=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Expo','Lead','Expo Lead']);
$user=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,[
    'name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300,'sortOrder'=>10
],$user);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Lunch',?,'active',1)")
    ->execute([$org,'lunch-'.$slug]);
$section=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Club Sandwich + Fries',?,'Build and plate.',1)")
    ->execute([$org,$section,'club-'.$slug]);
$item=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',16.00,'USD',1)")
    ->execute([$item]);
$price=(int)$pdo->lastInsertId();

$ids=[];
foreach(['Bread','Turkey','Fries'] as $i=>$name){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")
        ->execute([$org,$name,strtolower($name).'-'.$slug]);
    $id=(int)$pdo->lastInsertId();
    $ids[$name]=$id;
    $pdo->prepare('INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)')
        ->execute([$item,$id,$name,$i+1]);
}

$recipePublic='recipe-'.bin2hex(random_bytes(8));
$recipeIngredients=[
    ['quantity'=>'3','unit'=>'slices','ingredient'=>'Bread','notes'=>''],
    ['quantity'=>'3','unit'=>'slices','ingredient'=>'Turkey','notes'=>''],
    ['quantity'=>'1','unit'=>'portion','ingredient'=>'Fries','notes'=>''],
];
$recipeSteps=['Add Bread','Add Turkey','Plate with Fries'];
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by) VALUES (?,?,?,?,?,?,'active','mapped',?,?)")
    ->execute([$org,$recipePublic,'Club Sandwich + Fries','Sandwich',json_encode($recipeIngredients),json_encode($recipeSteps),$user,$user]);

$definition=glasses_definition_compile($pdo,$org,$item,$recipePublic,$user);
ghci_assert((string)$definition['status']==='ready','Test recipe must compile ready.');

kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$user);

$check=pos_create_check($pdo,$org,$location,[
    'serviceMode'=>'dine_in','tableName'=>'Table 8','guestCount'=>1
],$user);
$checkPublic=(string)$check['publicId'];
$check=pos_add_item($pdo,$org,$checkPublic,$price,1,'',$user);
$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,$checkPublic,$user,false);

$q=$pdo->prepare('SELECT public_id,id,status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');
$q->execute([$org,$line]);
$kds=$q->fetch();
$kdsPublic=(string)$kds['public_id'];
$kdsId=(int)$kds['id'];

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-HANDOFF-'.$slug,'displayName'=>'Handoff AIR3'
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);
$sessionPublic=(string)$session['publicId'];

$blocked=false;
try{glasses_handoff_to_expo($pdo,$device,$sessionPublic);}catch(InvalidArgumentException){$blocked=true;}
ghci_assert($blocked,'Expo handoff must fail before product validation is ready.');
ghci_assert((string)ghci_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND id=?',[$org,$kdsId])==='queued','Failed handoff must leave KDS queued.');
ghci_assert((int)ghci_one($pdo,'SELECT COUNT(*) FROM glasses_kds_handoffs WHERE organization_id=?',[$org])===0,'Failed handoff must not persist a handoff.');
ghci_assert((string)ghci_one($pdo,'SELECT status FROM glasses_build_sessions WHERE organization_id=? AND public_id=?',[$org,$sessionPublic])==='active','Failed handoff must leave the build session active.');

ghci_complete_component($pdo,$device,$sessionPublic,'ingredient:'.$ids['Bread'],3,'bread-complete');
ghci_complete_component($pdo,$device,$sessionPublic,'ingredient:'.$ids['Turkey'],3,'turkey-complete');
ghci_complete_component($pdo,$device,$sessionPublic,'ingredient:'.$ids['Fries'],1,'fries-complete');

$ready=glasses_validation_evaluate($pdo,$device,$sessionPublic);
ghci_assert((string)$ready['status']==='ready_for_finishing','Complete build must be ready for finishing before handoff.');

$unexpectedKey='vision:cheese';
glasses_build_observe($pdo,$device,$sessionPublic,[
    'observationKey'=>'late-unexpected',
    'componentKey'=>$unexpectedKey,
    'displayName'=>'Swiss Cheese',
    'observationAction'=>'added',
    'quantity'=>1,
    'confidence'=>0.96,
]);
$staleGateBlocked=false;
try{glasses_handoff_to_expo($pdo,$device,$sessionPublic);}catch(InvalidArgumentException){$staleGateBlocked=true;}
ghci_assert($staleGateBlocked,'Handoff must re-evaluate current evidence instead of trusting an older ready validation.');
ghci_assert((string)ghci_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND id=?',[$org,$kdsId])==='queued','Fresh validation failure must roll back all KDS transitions.');

glasses_build_resolve_unexpected($pdo,$device,$sessionPublic,$unexpectedKey);
$handoff=glasses_handoff_to_expo($pdo,$device,$sessionPublic);

ghci_assert((string)$handoff['status']==='completed','Successful Expo handoff must be durable and completed.');
ghci_assert((string)$handoff['fromStatus']==='queued'&&(string)$handoff['toStatus']==='ready','Queued AR work must hand off to KDS Ready.');
ghci_assert((string)$handoff['kdsStatus']==='ready','Handoff response must reflect KDS Ready.');
ghci_assert((string)$handoff['next']['label']==='Sent to Expo / Finishing','Terminal AR response must identify Expo / Finishing.');

$kdsAfter=kds_item($pdo,$org,$kdsPublic,false);
ghci_assert((string)$kdsAfter['status']==='ready','Validated handoff must put the KDS item in Ready.');
ghci_assert(!empty($kdsAfter['started_at'])&&!empty($kdsAfter['ready_at']),'Queued handoff must preserve normal KDS started/ready timestamps.');
ghci_assert((int)$kdsAfter['last_action_by']===$user,'KDS audit actor must be the accountable user who paired the glasses.');

ghci_assert((string)ghci_one($pdo,'SELECT status FROM glasses_build_sessions WHERE organization_id=? AND public_id=?',[$org,$sessionPublic])==='completed','Successful handoff must close the AR build session.');
ghci_assert((string)ghci_one($pdo,'SELECT status FROM glasses_product_validations WHERE organization_id=? AND public_id=?',[$org,$ready['publicId']])==='handed_off','Successful handoff must terminalize validation.');
ghci_assert((string)ghci_one($pdo,'SELECT next_stage FROM glasses_product_validations WHERE organization_id=? AND public_id=?',[$org,$ready['publicId']])==='expo','Terminal validation must identify Expo as the active downstream stage.');

$transitionCount=(int)ghci_one($pdo,"SELECT COUNT(*) FROM kds_order_events WHERE organization_id=? AND kds_order_item_id=? AND event_type='status_changed'",[$org,$kdsId]);
ghci_assert($transitionCount===2,'Queued handoff must use the existing queued → in_progress → ready KDS lifecycle.');
$states=$pdo->prepare("SELECT CONCAT(COALESCE(from_status,''),'->',COALESCE(to_status,'')) transition_name FROM kds_order_events WHERE organization_id=? AND kds_order_item_id=? AND event_type='status_changed' ORDER BY id");
$states->execute([$org,$kdsId]);
ghci_assert($states->fetchAll(PDO::FETCH_COLUMN)===['queued->in_progress','in_progress->ready'],'Handoff must not skip an existing KDS state.');

$expo=kds_production_board($pdo,$org,$location,null,false);
$ticket=array_values(array_filter($expo['tickets'],static fn(array $t):bool=>(string)$t['checkPublicId']===$checkPublic))[0]??null;
ghci_assert(is_array($ticket)&&$ticket['readyToBump']===true,'Ready AR handoff must appear in the existing Expo board and be bumpable.');

$handoffAgain=glasses_handoff_to_expo($pdo,$device,$sessionPublic);
ghci_assert((string)$handoffAgain['publicId']===(string)$handoff['publicId'],'Retry after successful handoff must be idempotent.');
ghci_assert((int)ghci_one($pdo,'SELECT COUNT(*) FROM glasses_kds_handoffs WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?)',[$org,$org,$sessionPublic])===1,'Retry must not create duplicate handoff records.');
ghci_assert((int)ghci_one($pdo,"SELECT COUNT(*) FROM kds_order_events WHERE organization_id=? AND kds_order_item_id=? AND event_type='status_changed'",[$org,$kdsId])===2,'Retry must not duplicate KDS transitions.');

$validationEvents=(int)ghci_one($pdo,"SELECT COUNT(*) FROM glasses_validation_events WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?) AND event_type='handoff_to_expo'",[$org,$org,$sessionPublic]);
ghci_assert($validationEvents===1,'Handoff must append exactly one validation handoff event.');
$buildEvents=(int)ghci_one($pdo,"SELECT COUNT(*) FROM glasses_build_events WHERE organization_id=? AND build_session_id=(SELECT id FROM glasses_build_sessions WHERE organization_id=? AND public_id=?) AND event_type='session_completed'",[$org,$org,$sessionPublic]);
ghci_assert($buildEvents===1,'Handoff must append exactly one build-session completion event.');

$grant2=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired2=glasses_pair_device($pdo,(string)$grant2['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-HANDOFF-2-'.$slug,'displayName'=>'Other AIR3'
]);
$device2=glasses_authenticate_token($pdo,(string)$paired2['deviceToken']);
$crossDevice=false;
try{glasses_handoff_to_expo($pdo,$device2,$sessionPublic);}catch(InvalidArgumentException){$crossDevice=true;}
ghci_assert($crossDevice,'Another glasses device must not claim or replay this build handoff.');

echo "glasses-expo-handoff-ok\n";
