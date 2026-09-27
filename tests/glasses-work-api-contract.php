<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-work.php';

$pdo=app_pdo();
function glasseswork_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function glasseswork_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

glasseswork_assert(glasses_ready($pdo),'Glasses foundation must be installed.');
glasseswork_assert(kds_ready($pdo),'KDS must be installed.');
glasseswork_assert(pos_ready($pdo),'POS must be installed.');

$slug='glasses-work-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Glasses Work '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Kitchen','Lead','Kitchen Lead']);$user=(int)$pdo->lastInsertId();

$sandwichStation=kds_station_save($pdo,$org,$location,['name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>360,'sortOrder'=>10],$user);
$fryStation=kds_station_save($pdo,$org,$location,['name'=>'Fry','slug'=>'fry','targetSeconds'=>240,'sortOrder'=>20],$user);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Lunch',?,'active',1)")->execute([$org,'lunch-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,description,preparation_notes,is_active) VALUES (?,?,'Club Sandwich + Fries',?,'Triple-decker club with fries.','Build, slice and plate with fries.',1)")->execute([$org,$section,'club-'.$slug]);$club=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',16.00,'USD',1)")->execute([$club]);$clubPrice=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Fried Pickles',?,1)")->execute([$org,$section,'pickles-'.$slug]);$pickles=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',8.00,'USD',1)")->execute([$pickles]);$picklesPrice=(int)$pdo->lastInsertId();

$ingredientIds=[];
foreach(['Toasted Bread','Mayo','Turkey','Bacon','Lettuce','Tomato','Fries'] as $index=>$name){
    $ingSlug=strtolower(str_replace(' ','-',$name)).'-'.$slug;
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")->execute([$org,$name,$ingSlug]);
    $ingredientIds[]=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)')->execute([$club,end($ingredientIds),$name,$index+1]);
}

$recipePublic='recipe-'.bin2hex(random_bytes(8));
$recipeIngredients=[
    ['quantity'=>'3','unit'=>'slices','ingredient'=>'Toasted Bread','notes'=>''],
    ['quantity'=>'3','unit'=>'slices','ingredient'=>'Turkey','notes'=>''],
    ['quantity'=>'3','unit'=>'strips','ingredient'=>'Bacon','notes'=>''],
    ['quantity'=>'2','unit'=>'slices','ingredient'=>'Tomato','notes'=>''],
];
$recipeSteps=['Toast bread','Add mayo','Add turkey','Add bacon','Add lettuce','Add tomato','Top and slice','Plate with fries'];
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,'active','mapped',?,?)")
    ->execute([$org,$recipePublic,'Club Sandwich + Fries','Sandwich','House club build',json_encode($recipeIngredients),json_encode($recipeSteps),$user,$user]);

kds_route_save($pdo,$org,$location,$club,(string)$sandwichStation['public_id'],$user);
kds_route_save($pdo,$org,$location,$pickles,(string)$fryStation['public_id'],$user);

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 7','guestCount'=>2],$user);$checkPublic=(string)$check['publicId'];
$check=pos_add_item($pdo,$org,$checkPublic,$clubPrice,1,'NO TOMATO · EXTRA BACON',$user);$clubLine=(int)$check['items'][0]['id'];
$pdo->prepare("INSERT INTO pos_check_item_modifiers (organization_id,pos_check_item_id,modifier_option_id,modifier_group_name_snapshot,modifier_name_snapshot,quantity,unit_amount,total_amount) VALUES (?,?,NULL,'Add-ons','Extra Bacon',1,2.00,2.00)")
    ->execute([$org,$clubLine]);
$check=pos_add_item($pdo,$org,$checkPublic,$picklesPrice,1,'',$user);$pickleLine=(int)$check['items'][1]['id'];
kds_send_check($pdo,$org,$checkPublic,$user,false);

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$sandwichStation['public_id'],$user,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-WORK-'.$slug,'displayName'=>'Sandwich AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);

$beforeClub=(string)glasseswork_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$clubLine]);
$beforePickles=(string)glasseswork_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$pickleLine]);
$work=glasses_current_work($pdo,$device);

glasseswork_assert($work['assignmentRequired']===false,'Assigned glasses must receive station work.');
glasseswork_assert((string)$work['station']['publicId']===(string)$sandwichStation['public_id'],'Work projection must be scoped to the assigned station.');
glasseswork_assert(count($work['items'])===1,'Station-scoped glasses must not receive another station\'s item.');
glasseswork_assert((int)$work['items'][0]['posLine']['id']===$clubLine,'Projected item must match the KDS line.');
glasseswork_assert((string)$work['items'][0]['posLine']['specialInstructions']==='NO TOMATO · EXTRA BACON','POS special instructions must reach the glasses.');
glasseswork_assert(count($work['items'][0]['posLine']['modifiers'])===1&&(string)$work['items'][0]['posLine']['modifiers'][0]['name']==='Extra Bacon','Structured POS modifiers must reach the glasses.');
glasseswork_assert(count($work['items'][0]['menu']['ingredients'])===7,'Canonical menu ingredients must reach the glasses.');
glasseswork_assert((string)$work['items'][0]['menu']['preparationNotes']==='Build, slice and plate with fries.','Menu preparation notes must reach the glasses.');
glasseswork_assert((string)$work['items'][0]['recipeSource']['status']==='exact_name','A unique exact recipe name must be projected conservatively.');
glasseswork_assert((string)$work['items'][0]['recipeSource']['recipe']['publicId']===$recipePublic,'Exact recipe projection must use the existing recipe record.');
glasseswork_assert(count($work['items'][0]['recipeSource']['recipe']['instructions'])===8,'Recipe instructions must reach the glasses.');
glasseswork_assert((string)$work['focusItem']['status']==='queued','Queued station work must become the focus when nothing is in progress.');
glasseswork_assert(strlen((string)$work['revision'])===64,'Work payload must include a stable SHA-256 revision.');
glasseswork_assert($beforeClub===(string)glasseswork_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$clubLine]),'Reading glasses work must not mutate KDS status.');
glasseswork_assert($beforePickles===(string)glasseswork_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$pickleLine]),'Reading glasses work must not mutate other stations.');

$q=$pdo->prepare('SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');$q->execute([$org,$clubLine]);$clubKds=(string)$q->fetchColumn();
kds_transition($pdo,$org,$clubKds,'in_progress',$user);
$work2=glasses_current_work($pdo,glasses_authenticate_token($pdo,(string)$paired['deviceToken']));
glasseswork_assert((string)$work2['focusItem']['status']==='in_progress','In-progress work must take focus over queued/ready work.');

$unassignedGrant=glasses_create_pairing_grant($pdo,$org,$location,null,$user,10);
$unassignedPair=glasses_pair_device($pdo,(string)$unassignedGrant['pairingCode'],['hardwareIdentifier'=>'AIR3-UNASSIGNED-'.$slug,'displayName'=>'Unassigned AIR3']);
$unassigned=glasses_current_work($pdo,glasses_authenticate_token($pdo,(string)$unassignedPair['deviceToken']));
glasseswork_assert($unassigned['assignmentRequired']===true&&$unassigned['items']===[],'Unassigned glasses must not receive arbitrary location work.');

$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,status,mapping_status,created_by,updated_by) VALUES (?,?,?,'active','mapped',?,?)")
    ->execute([$org,'recipe-'.bin2hex(random_bytes(8)),'Club Sandwich + Fries',$user,$user]);
$ambiguous=glasses_current_work($pdo,glasses_authenticate_token($pdo,(string)$paired['deviceToken']));
glasseswork_assert((string)$ambiguous['items'][0]['recipeSource']['status']==='ambiguous'&&$ambiguous['items'][0]['recipeSource']['recipe']===null,'Ambiguous recipe names must never be silently selected.');

echo "glasses-work-api-ok\n";
