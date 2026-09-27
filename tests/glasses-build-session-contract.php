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

$session=glasses_build_start($pdo,$device,(string)$kds['public_id'],str_repeat('a',64));
gbci_assert((string)$session['status']==='active','Build session must start active.');
gbci_assert(count($session['components'])===3,'Build session must snapshot canonical menu ingredients.');
gbci_assert((int)$session['summary']['required']===3&&(int)$session['summary']['confirmed']===0,'New session summary must reflect required components.');
gbci_assert((string)$session['context']['specialInstructions']==='NO TOMATO','Build session must snapshot POS instructions.');
gbci_assert((string)gbci_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$line])==='queued','Starting build session must not mutate KDS lifecycle.');

$again=glasses_build_start($pdo,$device,(string)$kds['public_id'],str_repeat('b',64));
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

$turkey=glasses_build_observe($pdo,$device,(string)$session['publicId'],[
    'observationKey'=>'obs-turkey-1','componentKey'=>$keys['Turkey'],'observationAction'=>'added','quantity'=>1,'confidence'=>0.62
]);
$turkeyRow=array_values(array_filter($turkey['components'],static fn(array $c):bool=>$c['componentKey']===$keys['Turkey']))[0];
gbci_assert((string)$turkeyRow['status']==='verify'&&(int)$turkey['summary']['verify']===1,'Low-confidence ingredient must require verification.');
$turkey=glasses_build_confirm($pdo,$device,(string)$session['publicId'],$keys['Turkey']);
$turkeyRow=array_values(array_filter($turkey['components'],static fn(array $c):bool=>$c['componentKey']===$keys['Turkey']))[0];
gbci_assert((string)$turkeyRow['status']==='confirmed'&&(float)$turkeyRow['confidence']===1.0,'Manual component confirmation must resolve verification.');

$bacon=glasses_build_observe($pdo,$device,(string)$session['publicId'],[
    'observationKey'=>'obs-bacon-1','componentKey'=>$keys['Bacon'],'observationAction'=>'seen','quantity'=>1,'confidence'=>0.91
]);
gbci_assert((bool)$bacon['summary']['accounted']===true,'All required confirmed components must mark ingredients accounted.');

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

echo "glasses-build-session-ok\n";
