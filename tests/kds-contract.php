<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/kds-core.php';

$pdo=app_pdo();
function kdsci_assert(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
function kdsci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

kdsci_assert(pos_ready($pdo),'Native POS must be installed.');
kdsci_assert(kds_ready($pdo),'Kitchen Display must be installed.');
$slug='kds-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['KDS CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Kitchen','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Kitchen','Manager','Kitchen Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Kitchen Manager','active')")->execute([$org,$user,$location]);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Kitchen',?,'active',1)")->execute([$org,'kitchen-'.$slug]);$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Wood Fired Pizza',?,1)")->execute([$org,$section,'pizza-'.$slug]);$pizza=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$pizza]);$pizzaPrice=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'House Salad',?,1)")->execute([$org,$section,'salad-'.$slug]);$salad=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',9.00,'USD',1)")->execute([$salad]);$saladPrice=(int)$pdo->lastInsertId();

$oven=kds_station_save($pdo,$org,$location,['name'=>'Pizza Oven','slug'=>'pizza-oven','targetSeconds'=>30,'sortOrder'=>1],$user);
kdsci_assert((string)$oven['name']==='Pizza Oven','Kitchen station was not created.');
$route=kds_route_save($pdo,$org,$location,$pizza,(string)$oven['public_id'],$user);
kdsci_assert((string)$route['station']['publicId']===(string)$oven['public_id'],'Menu route was not saved.');
$catalog=kds_menu_catalog($pdo,$org,$location);$pizzaCatalog=array_values(array_filter($catalog,static fn(array $r):bool=>(int)$r['id']===$pizza))[0]??null;
kdsci_assert(is_array($pizzaCatalog)&&($pizzaCatalog['station']['publicId']??null)===$oven['public_id'],'Menu catalog must expose the configured station.');

$check=pos_create_check($pdo,$org,$location,['serviceMode'=>'dine_in','tableName'=>'Table 4','guestCount'=>2],$user);$public=(string)$check['publicId'];
$check=pos_add_item($pdo,$org,$public,$pizzaPrice,1,'Well done',$user);$pizzaLine=(int)$check['items'][0]['id'];
$check=pos_add_item($pdo,$org,$public,$saladPrice,1,'Dressing on side',$user);$saladLine=(int)$check['items'][1]['id'];
$summary=kds_send_check($pdo,$org,$public,$user,false);
kdsci_assert((int)$summary['sent']===2&&(int)$summary['unsent']===0,'Send to Kitchen must create one KDS item per unsent active POS line.');
kdsci_assert((int)$summary['unrouted']===1,'An item without a station route must remain visible as Unrouted.');
kdsci_assert((int)kdsci_one($pdo,'SELECT COUNT(*) FROM kds_order_items WHERE organization_id=? AND check_id=(SELECT id FROM pos_checks WHERE organization_id=? AND public_id=?)',[$org,$org,$public])===2,'Exactly two KDS items should exist after the first send.');

$again=kds_send_check($pdo,$org,$public,$user,false);
kdsci_assert((int)$again['sent']===2&&(int)$again['unsent']===0,'Repeated send must be idempotent.');
kdsci_assert((int)kdsci_one($pdo,'SELECT COUNT(*) FROM kds_order_items WHERE organization_id=?',[$org])===2,'Repeated send must not duplicate kitchen rows.');

$locked=false;try{kds_assert_pos_line_mutable($pdo,$org,$pizzaLine);}catch(InvalidArgumentException){$locked=true;}kdsci_assert($locked,'A sent POS line must be immutable; correction requires void plus a new line.');

$q=$pdo->prepare('SELECT public_id,station_id,status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');$q->execute([$org,$pizzaLine]);$pizzaKds=$q->fetch();
$q=$pdo->prepare('SELECT public_id,station_id,status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');$q->execute([$org,$saladLine]);$saladKds=$q->fetch();
kdsci_assert((int)$pizzaKds['station_id']===(int)$oven['id']&&(string)$pizzaKds['status']==='queued','Routed pizza must enter the station queue.');
kdsci_assert($saladKds['station_id']===null,'Unmapped salad must remain Unrouted.');
$unroutedBlocked=false;try{kds_transition($pdo,$org,(string)$saladKds['public_id'],'in_progress',$user);}catch(InvalidArgumentException){$unroutedBlocked=true;}kdsci_assert($unroutedBlocked,'Unrouted item must not begin preparation.');

$saladAssigned=kds_reassign($pdo,$org,(string)$saladKds['public_id'],(string)$oven['public_id'],$user,'Expo routed item');
kdsci_assert((int)$saladAssigned['station_id']===(int)$oven['id'],'Expo reassignment failed.');
$saladProgress=kds_transition($pdo,$org,(string)$saladKds['public_id'],'in_progress',$user);
kdsci_assert((string)$saladProgress['status']==='in_progress','Queued item did not start preparation.');
$saladReady=kds_transition($pdo,$org,(string)$saladKds['public_id'],'ready',$user);
kdsci_assert((string)$saladReady['status']==='ready'&&!empty($saladReady['ready_at']),'Kitchen item did not reach Ready.');
$saladDone=kds_transition($pdo,$org,(string)$saladKds['public_id'],'completed',$user);
kdsci_assert((string)$saladDone['status']==='completed'&&!empty($saladDone['completed_at']),'Expo completion failed.');
$terminalBlocked=false;try{kds_transition($pdo,$org,(string)$saladKds['public_id'],'in_progress',$user);}catch(InvalidArgumentException){$terminalBlocked=true;}kdsci_assert($terminalBlocked,'Completed kitchen items must be terminal.');

$check=pos_add_item($pdo,$org,$public,$pizzaPrice,1,'Second course',$user);$newLine=(int)end($check['items'])['id'];
$held=kds_send_check($pdo,$org,$public,$user,true);kdsci_assert((int)$held['sent']===3,'Newly added POS line must be sendable after the first kitchen send.');
$q=$pdo->prepare('SELECT public_id,status,fired_at FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?');$q->execute([$org,$newLine]);$heldItem=$q->fetch();
kdsci_assert((string)$heldItem['status']==='held'&&$heldItem['fired_at']===null,'Held send must not start kitchen timing.');
$fired=kds_transition($pdo,$org,(string)$heldItem['public_id'],'queued',$user);kdsci_assert((string)$fired['status']==='queued'&&!empty($fired['fired_at']),'Fire must enter the queue and start timing.');

$pdo->prepare('UPDATE kds_order_items SET fired_at=DATE_SUB(NOW(6),INTERVAL 90 SECOND) WHERE organization_id=? AND public_id=?')->execute([$org,(string)$pizzaKds['public_id']]);
$board=kds_board($pdo,$org,$location,null,false);$late=array_values(array_filter($board['items'],static fn(array $r):bool=>(string)$r['public_id']===(string)$pizzaKds['public_id']))[0]??null;
kdsci_assert(is_array($late)&&$late['late']===true,'KDS board must mark an item late after its station target is exceeded.');

$check=pos_void_item($pdo,$org,$public,$pizzaLine,'Guest changed item',$user);kds_cancel_pos_line($pdo,$org,$pizzaLine,'Guest changed item',$user);
$voided=kds_item($pdo,$org,(string)$pizzaKds['public_id']);kdsci_assert((string)$voided['status']==='cancelled'&&!empty($voided['cancelled_at']),'POS void must propagate to a live kitchen item.');

$cancel=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','tableName'=>'Pickup CI','guestCount'=>1],$user);$cancelPublic=(string)$cancel['publicId'];$cancel=pos_add_item($pdo,$org,$cancelPublic,$pizzaPrice,1,'',$user);$cancelLine=(int)$cancel['items'][0]['id'];kds_send_check($pdo,$org,$cancelPublic,$user,false);$cancelBase=pos_check_base($pdo,$org,$cancelPublic,false);pos_cancel_check($pdo,$org,$cancelPublic,'Customer cancelled',$user);kds_cancel_check($pdo,$org,(int)$cancelBase['id'],'Customer cancelled',$user);
kdsci_assert((string)kdsci_one($pdo,'SELECT status FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$cancelLine])==='cancelled','Check cancellation must propagate to nonterminal kitchen items.');

kdsci_assert((int)kdsci_one($pdo,"SELECT COUNT(*) FROM kds_order_events WHERE organization_id=? AND event_type='sent'",[$org])>=4,'Every kitchen send must produce an audit event.');
kdsci_assert((int)kdsci_one($pdo,"SELECT COUNT(*) FROM kds_order_events WHERE organization_id=? AND event_type='status_changed'",[$org])>=4,'Kitchen lifecycle changes must be append-only events.');
kdsci_assert((int)kdsci_one($pdo,"SELECT COUNT(*) FROM kds_order_events WHERE organization_id=? AND event_type='reassigned'",[$org])>=1,'Expo reassignment must be audited.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['KDS Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$isolated=false;try{kds_item($pdo,$otherOrg,(string)$saladKds['public_id']);}catch(InvalidArgumentException){$isolated=true;}kdsci_assert($isolated,'Kitchen items must be organization-isolated.');

echo "kds-ok\n";
