<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-definition.php';
require_once __DIR__.'/../includes/glasses-build.php';
require_once __DIR__.'/../includes/glasses-validation.php';

$pdo=app_pdo();

function gvci_assert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function gvci_one(PDO $pdo,string $sql,array $args=[]): mixed {
    $q=$pdo->prepare($sql); $q->execute($args); return $q->fetchColumn();
}
function gvci_component(array $session,string $name): array {
    foreach($session['components'] as $component){
        if((string)$component['displayName']===$name) return $component;
    }
    throw new RuntimeException("Component not found: {$name}");
}

gvci_assert(glasses_validation_ready($pdo),'Product-validation migration must be installed.');

$slug='gvci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Validation CI '.$slug]);
$org=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")
    ->execute([$org]);
$location=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")
    ->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Validation','Lead','Validation Lead']);
$user=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,[
    'name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>360,'sortOrder'=>10
],$user);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Lunch',?,'active',1)")
    ->execute([$org,'lunch-'.$slug]);
$section=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Club Sandwich + Fries',?,'Build, slice, plate with fries.',1)")
    ->execute([$org,$section,'club-'.$slug]);
$item=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',16.00,'USD',1)")
    ->execute([$item]);
$price=(int)$pdo->lastInsertId();

$ingredientIds=[];
foreach(['Toasted Bread','Turkey','Bacon','Lettuce','Tomato','Fries'] as $i=>$name){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")
        ->execute([$org,$name,strtolower(str_replace(' ','-',$name)).'-'.$slug]);
    $ingredientId=(int)$pdo->lastInsertId();
    $ingredientIds[$name]=$ingredientId;
    $pdo->prepare('INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)')
        ->execute([$item,$ingredientId,$name,$i+1]);
}

$recipePublic='recipe-'.bin2hex(random_bytes(8));
$recipeIngredients=[
    ['quantity'=>'3','unit'=>'slices','ingredient'=>'Toasted Bread','notes'=>''],
    ['quantity'=>'3','unit'=>'slices','ingredient'=>'Turkey','notes'=>''],
    ['quantity'=>'3','unit'=>'strips','ingredient'=>'Bacon','notes'=>''],
    ['quantity'=>'1','unit'=>'portion','ingredient'=>'Lettuce','notes'=>''],
    ['quantity'=>'2','unit'=>'slices','ingredient'=>'Tomato','notes'=>''],
    ['quantity'=>'1','unit'=>'portion','ingredient'=>'Fries','notes'=>''],
];
$recipeSteps=[
    'Toast the Toasted Bread','Add Turkey','Add Bacon','Add Lettuce','Add Tomato',
    'Top and slice','Plate with Fries'
];
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,'active','mapped',?,?)")
    ->execute([$org,$recipePublic,'Club Sandwich + Fries','Sandwich','House club',json_encode($recipeIngredients),json_encode($recipeSteps),$user,$user]);

$definition=glasses_definition_compile($pdo,$org,$item,$recipePublic,$user);
gvci_assert((string)$definition['status']==='ready','Club recipe must compile to a ready AR definition.');

kds_route_save($pdo,$org,$location,$item,(string)$station['public_id'],$user);

$check=pos_create_check($pdo,$org,$location,[
    'serviceMode'=>'dine_in','tableName'=>'Table 12','guestCount'=>1
],$user);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$price,1,'',$user);
$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$user,false);
$q=$pdo->prepare('SELECT public_id,status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');
$q->execute([$org,$line]);
$kds=$q->fetch();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-VAL-'.$slug,'displayName'=>'Validation AIR3'
]);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);

$session=glasses_build_start($pdo,$device,(string)$kds['public_id'],null);
$sessionPublic=(string)$session['publicId'];

$initial=glasses_validation_evaluate($pdo,$device,$sessionPublic);
gvci_assert((string)$initial['status']==='pending','Empty build must validate as pending, not ready.');
gvci_assert($initial['nextStage']===null,'Expo must not appear before required components are accounted for.');
gvci_assert((bool)$initial['summary']['allIngredientsAccountedFor']===false,'Initial validation must not claim all ingredients are accounted for.');
gvci_assert((int)$initial['summary']['requiredComponents']===6,'Validation must count only the six recipe-required components.');
gvci_assert((int)$initial['summary']['missingComponents']===6,'Every unobserved required component must be missing initially.');
gvci_assert((string)gvci_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$line])==='queued','Validation must not mutate KDS state.');

$initialAgain=glasses_validation_evaluate($pdo,$device,$sessionPublic);
gvci_assert((int)$initialAgain['revision']===(int)$initial['revision'],'Identical validation snapshot must be idempotent.');
gvci_assert((int)gvci_one($pdo,'SELECT COUNT(*) FROM glasses_validation_events WHERE organization_id=? AND validation_id=(SELECT id FROM glasses_product_validations WHERE organization_id=? AND public_id=?)',[$org,$org,$initial['publicId']])===1,'Idempotent validation must not add duplicate events.');

$breadKey='ingredient:'.$ingredientIds['Toasted Bread'];
$partial=glasses_build_observe($pdo,$device,$sessionPublic,[
    'observationKey'=>'bread-1',
    'componentKey'=>$breadKey,
    'observationAction'=>'added',
    'quantity'=>2,
    'confidence'=>0.97,
    'trackingId'=>'bread-stack-a'
]);
$bread=gvci_component($partial,'Toasted Bread');
gvci_assert((string)$bread['status']==='detected'&&(float)$bread['detectedQuantity']===2.0,'Two of three bread slices must remain detected, not confirmed.');

$partialValidation=glasses_validation_evaluate($pdo,$device,$sessionPublic);
gvci_assert((string)$partialValidation['status']==='pending','Partial required quantity must remain pending.');
$breadBlockers=array_values(array_filter($partialValidation['blockers'],static fn(array $b):bool=>($b['componentKey']??'')===$breadKey));
gvci_assert(count($breadBlockers)===1&&abs((float)($breadBlockers[0]['missingQuantity']??0)-1.0)<0.0001,'Validation must expose the exact missing bread quantity.');

$turkeyKey='ingredient:'.$ingredientIds['Turkey'];
$turkey=glasses_build_observe($pdo,$device,$sessionPublic,[
    'observationKey'=>'turkey-low-confidence',
    'componentKey'=>$turkeyKey,
    'observationAction'=>'seen',
    'quantity'=>3,
    'confidence'=>0.68,
    'trackingId'=>'turkey-stack'
]);
gvci_assert((string)gvci_component($turkey,'Turkey')['status']==='verify','Low-confidence complete Turkey count must require Verify.');

$verifyValidation=glasses_validation_evaluate($pdo,$device,$sessionPublic);
gvci_assert((string)$verifyValidation['status']==='blocked','Verify state must block finishing.');
gvci_assert((int)$verifyValidation['summary']['verifyComponents']===1,'Validation summary must report the verify component.');
gvci_assert($verifyValidation['nextStage']===null,'Expo must remain unavailable while Verify is unresolved.');

$turkey=glasses_build_confirm($pdo,$device,$sessionPublic,$turkeyKey);
gvci_assert((string)gvci_component($turkey,'Turkey')['status']==='confirmed','Manual confirmation must resolve Turkey verification.');

$completeObservationMap=[
    ['key'=>$breadKey,'name'=>'Toasted Bread','quantity'=>1,'obs'=>'bread-2'],
    ['key'=>'ingredient:'.$ingredientIds['Bacon'],'name'=>'Bacon','quantity'=>3,'obs'=>'bacon-1'],
    ['key'=>'ingredient:'.$ingredientIds['Lettuce'],'name'=>'Lettuce','quantity'=>1,'obs'=>'lettuce-1'],
    ['key'=>'ingredient:'.$ingredientIds['Tomato'],'name'=>'Tomato','quantity'=>2,'obs'=>'tomato-1'],
    ['key'=>'ingredient:'.$ingredientIds['Fries'],'name'=>'Fries','quantity'=>1,'obs'=>'fries-1'],
];
foreach($completeObservationMap as $entry){
    $session=glasses_build_observe($pdo,$device,$sessionPublic,[
        'observationKey'=>$entry['obs'],
        'componentKey'=>$entry['key'],
        'observationAction'=>'added',
        'quantity'=>$entry['quantity'],
        'confidence'=>0.96,
        'trackingId'=>$entry['obs'].'-track'
    ]);
    gvci_assert((string)gvci_component($session,$entry['name'])['status']==='confirmed',$entry['name'].' must confirm at expected quantity.');
}

$ready=glasses_validation_evaluate($pdo,$device,$sessionPublic);
gvci_assert((string)$ready['status']==='ready_for_finishing','All required confirmed ingredients must validate ready for finishing.');
gvci_assert((bool)$ready['summary']['allIngredientsAccountedFor']===true,'Ready validation must say all ingredients are accounted for.');
gvci_assert((string)$ready['nextStage']==='expo_finishing','Ready validation must expose Expo / Finishing as the next stage.');
gvci_assert((bool)$ready['summary']['next']['available']===true,'Right-rail NEXT state must become available.');
gvci_assert((string)$ready['summary']['next']['label']==='Expo / Finishing','NEXT label must match the AR UX.');
gvci_assert((string)$ready['summary']['next']['message']==='All ingredients accounted for.','NEXT message must explain why finishing is available.');
gvci_assert((int)$ready['summary']['passedComponents']===6,'Every required component must pass.');

$unexpectedKey='vision:swiss-cheese';
$unexpected=glasses_build_observe($pdo,$device,$sessionPublic,[
    'observationKey'=>'unexpected-cheese-1',
    'componentKey'=>$unexpectedKey,
    'displayName'=>'Swiss Cheese',
    'observationAction'=>'added',
    'quantity'=>1,
    'confidence'=>0.94,
    'trackingId'=>'cheese-1'
]);
gvci_assert((int)$unexpected['summary']['unexpected']===1,'Unexpected ingredient must be recorded on the build.');

$blockedUnexpected=glasses_validation_evaluate($pdo,$device,$sessionPublic);
gvci_assert((string)$blockedUnexpected['status']==='blocked','Unexpected ingredient must block finishing.');
gvci_assert((int)$blockedUnexpected['summary']['unexpectedComponents']===1,'Validation summary must expose unexpected component count.');
gvci_assert($blockedUnexpected['nextStage']===null,'NEXT must disappear while an unexpected component is unresolved.');

$resolved=glasses_build_resolve_unexpected($pdo,$device,$sessionPublic,$unexpectedKey);
gvci_assert((int)$resolved['summary']['unexpected']===0,'Unexpected component resolution must clear build exception.');

$readyAgain=glasses_validation_evaluate($pdo,$device,$sessionPublic);
gvci_assert((string)$readyAgain['status']==='ready_for_finishing','Resolved unexpected ingredient must restore finishing readiness.');
gvci_assert((string)$readyAgain['nextStage']==='expo_finishing','Expo / Finishing must return after the blocker is resolved.');
gvci_assert((int)$readyAgain['revision']>(int)$ready['revision'],'Changed evidence must advance validation revision.');

gvci_assert((string)gvci_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$line])==='queued','Product validation must never implicitly advance KDS state.');
gvci_assert((int)gvci_one($pdo,'SELECT COUNT(*) FROM glasses_product_validations WHERE organization_id=?',[$org])===1,'One build session must own one durable validation record.');
gvci_assert((int)gvci_one($pdo,'SELECT COUNT(*) FROM glasses_validation_events WHERE organization_id=?',[$org])>=6,'Validation state changes must retain append-only event history.');

$grant2=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired2=glasses_pair_device($pdo,(string)$grant2['pairingCode'],[
    'hardwareIdentifier'=>'AIR3-VAL-2-'.$slug,'displayName'=>'Other AIR3'
]);
$device2=glasses_authenticate_token($pdo,(string)$paired2['deviceToken']);
$isolated=false;
try{glasses_validation_get($pdo,$device2,$sessionPublic);}catch(InvalidArgumentException){$isolated=true;}
gvci_assert($isolated,'Validation must preserve build-session device isolation.');

echo "glasses-product-validation-ok\n";
