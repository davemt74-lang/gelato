<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/glasses-core.php';
require_once __DIR__.'/../includes/glasses-definition.php';
require_once __DIR__.'/../includes/glasses-build.php';

$pdo=app_pdo();
function gdef_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function gdef_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

gdef_assert(glasses_definition_ready($pdo),'Build-definition migration must be installed.');
$slug='gdef-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Definition CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Recipe','Lead','Recipe Lead']);$user=(int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Lunch',?,'active',1)")->execute([$org,'lunch-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,preparation_notes,is_active) VALUES (?,?,'Club Sandwich + Fries',?,'Build, slice, plate with fries.',1)")->execute([$org,$section,'club-'.$slug]);$club=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',16.00,'USD',1)")->execute([$club]);$clubPrice=(int)$pdo->lastInsertId();

$ids=[];
foreach(['Toasted Bread','Mayo','Turkey','Bacon','Lettuce','Tomato','Fries'] as $i=>$name){
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'food','verified')")->execute([$org,$name,strtolower(str_replace(' ','-',$name)).'-'.$slug]);
    $id=(int)$pdo->lastInsertId();$ids[$name]=$id;
    $pdo->prepare('INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)')->execute([$club,$id,$name,$i+1]);
}

$recipePublic='recipe-'.bin2hex(random_bytes(8));
$ingredients=[
 ['quantity'=>'3','unit'=>'slices','ingredient'=>'Toasted Bread','notes'=>'toasted'],
 ['quantity'=>'1','unit'=>'layer','ingredient'=>'Mayo','notes'=>''],
 ['quantity'=>'3','unit'=>'slices','ingredient'=>'Turkey','notes'=>''],
 ['quantity'=>'3','unit'=>'strips','ingredient'=>'Bacon','notes'=>''],
 ['quantity'=>'1','unit'=>'portion','ingredient'=>'Lettuce','notes'=>''],
 ['quantity'=>'2','unit'=>'slices','ingredient'=>'Tomato','notes'=>''],
 ['quantity'=>'1','unit'=>'portion','ingredient'=>'Fries','notes'=>'seasoned'],
];
$steps=['Toast the Toasted Bread','Add Mayo','Add Turkey','Add Bacon','Add Lettuce','Add Tomato','Top and slice','Plate with Fries'];
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,'active','mapped',?,?)")
    ->execute([$org,$recipePublic,'Club Sandwich + Fries','Sandwich','House club',json_encode($ingredients),json_encode($steps),$user,$user]);

$compiled=glasses_definition_compile($pdo,$org,$club,null,$user);
gdef_assert((string)$compiled['status']==='ready','Exact complete recipe must compile ready.');
gdef_assert((int)$compiled['version']===1,'First definition must be version 1.');
gdef_assert((string)$compiled['recipePublicId']===$recipePublic,'Exact-name compiler must select the unique recipe.');
gdef_assert(count($compiled['definition']['components'])===7,'Definition must include every canonical menu ingredient.');
gdef_assert(count($compiled['definition']['steps'])===8,'Definition must preserve recipe instruction order.');
gdef_assert(count($compiled['definition']['unresolved'])===0,'Complete recipe/menu mapping must have no unresolved entries.');

$turkey=array_values(array_filter($compiled['definition']['components'],static fn(array $c):bool=>$c['displayName']==='Turkey'))[0]??null;
$tomato=array_values(array_filter($compiled['definition']['components'],static fn(array $c):bool=>$c['displayName']==='Tomato'))[0]??null;
gdef_assert(is_array($turkey)&&(float)$turkey['expectedQuantity']===3.0&&(string)$turkey['unit']==='slices','Recipe quantity/unit must compile into Turkey component.');
gdef_assert(is_array($tomato)&&(float)$tomato['expectedQuantity']===2.0,'Recipe quantity must compile into Tomato component.');
$turkeyStep=array_values(array_filter($compiled['definition']['steps'],static fn(array $s):bool=>$s['text']==='Add Turkey'))[0]??null;
gdef_assert(is_array($turkeyStep)&&in_array('ingredient:'.$ids['Turkey'],$turkeyStep['componentKeys'],true),'Build step must reference a named component when instruction text identifies it.');

$current=glasses_definition_for_menu_item($pdo,$org,$club);
gdef_assert(is_array($current)&&$current['stale']===false&&(string)$current['publicId']===(string)$compiled['publicId'],'Fresh ready definition must be current.');

$ingredients[2]['quantity']='4';
$pdo->prepare("UPDATE recipes SET ingredients_json=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=?")->execute([json_encode($ingredients),$org,$recipePublic]);
$stale=glasses_definition_for_menu_item($pdo,$org,$club);
gdef_assert($stale['stale']===true,'Source changes must make a compiled definition stale.');

$compiled2=glasses_definition_compile($pdo,$org,$club,$recipePublic,$user);
gdef_assert((string)$compiled2['status']==='ready'&&(int)$compiled2['version']===2,'Recompile must create a new ready version.');
gdef_assert((string)gdef_one($pdo,'SELECT status FROM glasses_build_definitions WHERE organization_id=? AND public_id=?',[$org,$compiled['publicId']])==='superseded','New ready version must supersede prior ready definition.');
$turkey2=array_values(array_filter($compiled2['definition']['components'],static fn(array $c):bool=>$c['displayName']==='Turkey'))[0]??null;
gdef_assert((float)$turkey2['expectedQuantity']===4.0,'Recompile must reflect current recipe quantity.');

$badRecipePublic='recipe-'.bin2hex(random_bytes(8));
$badIngredients=$ingredients;$badIngredients[]=['quantity'=>'1','unit'=>'slice','ingredient'=>'Swiss Cheese','notes'=>''];
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by) VALUES (?,?,?,?,?,'active','mapped',?,?)")
    ->execute([$org,$badRecipePublic,'Club Sandwich + Fries',json_encode($badIngredients),json_encode($steps),$user,$user]);
$ambiguous=false;try{glasses_definition_compile($pdo,$org,$club,null,$user);}catch(InvalidArgumentException){$ambiguous=true;}
gdef_assert($ambiguous,'Multiple exact-name recipes must require explicit selection.');
$needsReview=glasses_definition_compile($pdo,$org,$club,$badRecipePublic,$user);
gdef_assert((string)$needsReview['status']==='needs_review'&&count($needsReview['definition']['unresolved'])>=1,'Unmapped recipe ingredient must force needs_review.');
$currentAfterReview=glasses_definition_for_menu_item($pdo,$org,$club);
gdef_assert((string)$currentAfterReview['publicId']===(string)$compiled2['publicId'],'Needs-review compile must not displace the current ready definition.');

$station=kds_station_save($pdo,$org,$location,['name'=>'Sandwich','slug'=>'sandwich','targetSeconds'=>300],$user);
kds_route_save($pdo,$org,$location,$club,(string)$station['public_id'],$user);
$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 4','guestCount'=>1],$user);
$check=pos_add_item($pdo,$org,(string)$check['publicId'],$clubPrice,1,'',$user);$line=(int)$check['items'][0]['id'];
kds_send_check($pdo,$org,(string)$check['publicId'],$user,false);
$q=$pdo->prepare('SELECT public_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');$q->execute([$org,$line]);$kdsPublic=(string)$q->fetchColumn();

$grant=glasses_create_pairing_grant($pdo,$org,$location,(string)$station['public_id'],$user,10);
$paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'AIR3-DEF-'.$slug,'displayName'=>'Definition AIR3']);
$device=glasses_authenticate_token($pdo,(string)$paired['deviceToken']);
$session=glasses_build_start($pdo,$device,$kdsPublic,null);
gdef_assert((string)$session['buildDefinition']['publicId']===(string)$compiled2['publicId'],'Build session must attach the latest fresh ready definition.');
gdef_assert((string)$session['sourceRevision']===(string)$compiled2['sourceHash'],'Build session revision must default to definition source hash.');
$sessionTurkey=array_values(array_filter($session['components'],static fn(array $c):bool=>$c['displayName']==='Turkey'))[0]??null;
gdef_assert((float)$sessionTurkey['expectedQuantity']===4.0&&(string)$sessionTurkey['unit']==='slices','Build session must consume compiled recipe quantities.');
gdef_assert(count((array)$session['context']['buildDefinition']['steps'])===8,'Build session context must expose ordered build steps.');

gdef_assert(abs((float)glasses_definition_quantity('1/2')-0.5)<0.0001,'Fraction quantity parser must support simple fractions.');
gdef_assert(abs((float)glasses_definition_quantity('1 1/2')-1.5)<0.0001,'Fraction quantity parser must support mixed fractions.');

echo "glasses-build-definition-ok\n";
