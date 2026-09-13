<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/service-visit-live.php';

$pdo=app_pdo();
function dvl(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function dvl_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function dvl_pay(PDO $pdo,int $org,string $checkPublic,int $user): array {$check=pos_check_details($pdo,$org,$checkPublic);if($check['balanceDue']<=0)throw new RuntimeException('Test check has no balance to pay.');return pos_record_tender($pdo,$org,$checkPublic,['tenderType'=>'cash','amount'=>$check['balanceDue'],'tipAmount'=>0,'receivedAmount'=>$check['balanceDue']],$user);}
function dvl_table(PDO $pdo,int $org,int $location,array $section,string $name,int $capacity,int $user): array {return service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>$name,'capacity'=>$capacity,'shape'=>'square','sectionPublicId'=>$section['publicId']],$user);}


dvl(service_visit_ready($pdo),'Dining visit migration must be installed.');
dvl((int)dvl_one($pdo,"SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='service_check_contexts' AND index_name='idx_service_context_visit'")>0,'Dining visit index must exist.');
$slug='visit-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Visit CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Dining','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Visit','Manager','Visit Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);
$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining','sortOrder'=>1],$user);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Dinner',?,'active',1)")->execute([$org,'dinner-'.$slug]);$menuSection=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Pizza',?,1)")->execute([$org,$menuSection,'pizza-'.$slug]);$menuItem=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',20.00,'USD',1)")->execute([$menuItem]);$price=(int)$pdo->lastInsertId();
$customerA=crm_customer_save($pdo,$org,['firstName'=>'Alex','lastName'=>'Visit','displayName'=>'Alex Visit','email'=>'alex-'.$slug.'@example.test','phone'=>'6025550101'],$user,'manual');
$customerB=crm_customer_save($pdo,$org,['firstName'=>'Blair','lastName'=>'Visit','displayName'=>'Blair Visit','email'=>'blair-'.$slug.'@example.test','phone'=>'6025550102'],$user,'manual');

// A reservation split into two checks remains one dining visit, one CRM visit, and one occupied table.
$t1=dvl_table($pdo,$org,$location,$section,'T1',4,$user);
$future=(new DateTimeImmutable('tomorrow 19:00',service_ops_timezone($pdo,$org,$location)))->format('Y-m-d H:i:s');
$res=service_ops_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Alex Visit','customerPublicId'=>$customerA['public_id'],'partySize'=>4,'scheduledAt'=>$future,'durationMinutes'=>90,'tablePublicIds'=>[$t1['publicId']]],$user);
$res=service_ops_reservation_status_atomic($pdo,$org,$res['publicId'],'arrived',$user);
$seat=service_visit_host_seat($pdo,$org,$res['publicId'],null,$user);$sourcePublic=(string)$seat['check']['publicId'];$sourceId=(int)$seat['check']['id'];$group=(string)dvl_one($pdo,'SELECT visit_group_id FROM service_check_contexts WHERE organization_id=? AND check_id=?',[$org,$sourceId]);
dvl($group!==''&&str_starts_with($group,'visit-'),'Seated table-service check must receive a dining visit group.');
$check=pos_add_item($pdo,$org,$sourcePublic,$price,1,'Seat 1',$user);$items=$check['items'];$last=end($items);$item1=(int)$last['id'];table_service_item_course($pdo,$org,$sourcePublic,$item1,1,'mains',$user);
$check=pos_add_item($pdo,$org,$sourcePublic,$price,1,'Seat 2',$user);$items=$check['items'];$last=end($items);$item2=(int)$last['id'];table_service_item_course($pdo,$org,$sourcePublic,$item2,2,'mains',$user);
$split=service_visit_split($pdo,$org,$sourcePublic,[$item1],$user,1);$splitPublic=(string)$split['created']['publicId'];$splitId=(int)$split['created']['id'];
dvl((int)$split['source']['guestCount']===3&&(int)$split['created']['guestCount']===1,'Split must preserve the original four covers.');
dvl((string)dvl_one($pdo,'SELECT visit_group_id FROM service_check_contexts WHERE organization_id=? AND check_id=?',[$org,$splitId])===$group,'Split sibling must retain the same dining visit group.');
dvl((int)dvl_one($pdo,'SELECT customer_id FROM pos_checks WHERE organization_id=? AND id=?',[$org,$splitId])===(int)$customerA['id'],'Split sibling must inherit CRM customer attribution.');

dvl_pay($pdo,$org,$sourcePublic,$user);service_visit_reconcile_live($pdo,$org,$location,$user);
dvl((string)dvl_one($pdo,'SELECT status FROM guest_reservations WHERE organization_id=? AND public_id=?',[$org,$res['publicId']])==='seated','Reservation must remain seated while a split sibling is still open.');
dvl((int)dvl_one($pdo,'SELECT seated_check_id FROM guest_reservations WHERE organization_id=? AND public_id=?',[$org,$res['publicId']])===$splitId,'Reservation pointer must move to the surviving open split check.');
dvl((int)dvl_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t1['publicId']])===$splitId,'Physical table must remain occupied by the surviving split check.');
dvl((string)dvl_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t1['publicId']])==='seated','Physical table must stay seated after partial split settlement.');

$partialProfile=service_visit_crm_profile($pdo,$org,(string)$customerA['public_id']);
dvl($partialProfile['metrics']['visits']===1&&abs($partialProfile['metrics']['lifetimeSpend']-20.0)<.01,'One paid split sibling must count as one CRM visit with its paid spend.');
dvl_pay($pdo,$org,$splitPublic,$user);service_visit_reconcile_live($pdo,$org,$location,$user);
dvl((string)dvl_one($pdo,'SELECT status FROM guest_reservations WHERE organization_id=? AND public_id=?',[$org,$res['publicId']])==='completed','Reservation must complete only after every split check is terminal.');
dvl(dvl_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t1['publicId']])===null,'Final settlement must release the physical table.');
dvl((string)dvl_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t1['publicId']])==='dirty','Final settlement must leave the table dirty.');
$profile=service_visit_crm_profile($pdo,$org,(string)$customerA['public_id']);
dvl($profile['metrics']['visits']===1,'Two paid split checks from one seated party must count as one CRM visit.');
dvl(abs($profile['metrics']['lifetimeSpend']-40.0)<.01&&$profile['metrics']['covers']===4,'CRM spend and covers must include both split checks without losing or duplicating covers.');

// Generic POS remains outside the table-service merge surface and still supports CRM attach.
$generic=pos_create_check($pdo,$org,$location,['serviceMode'=>'takeout','guestCount'=>1,'notes'=>'Generic POS control'],$user);$genericPublic=(string)$generic['publicId'];
$visible=service_visit_open_checks($pdo,$org,$location);dvl(!in_array($genericPublic,array_column($visible,'public_id'),true),'Generic POS check must not appear as a table-service merge target.');
service_visit_attach_customer_safe($pdo,$org,$genericPublic,(string)$customerA['public_id']);dvl((int)dvl_one($pdo,'SELECT customer_id FROM pos_checks WHERE organization_id=? AND public_id=?',[$org,$genericPublic])===(int)$customerA['id'],'Generic POS CRM attachment must continue to work outside dining visits.');

// Different CRM customers cannot be merged, and generic POS cannot be merged into a dining visit.
$t2=dvl_table($pdo,$org,$location,$section,'T2',4,$user);$t3=dvl_table($pdo,$org,$location,$section,'T3',4,$user);
$a=service_visit_seat($pdo,$org,$location,$t2['publicId'],2,null,'',$user);$b=service_visit_seat($pdo,$org,$location,$t3['publicId'],2,null,'',$user);
service_visit_attach_customer_safe($pdo,$org,(string)$a['publicId'],(string)$customerA['public_id']);service_visit_attach_customer_safe($pdo,$org,(string)$b['publicId'],(string)$customerB['public_id']);
$blocked=false;try{service_visit_merge_safe($pdo,$org,(string)$a['publicId'],(string)$b['publicId'],$user);}catch(InvalidArgumentException){$blocked=true;}dvl($blocked,'Dining checks linked to different CRM customers must not merge.');
$blocked=false;try{service_visit_merge_safe($pdo,$org,(string)$a['publicId'],$genericPublic,$user);}catch(InvalidArgumentException){$blocked=true;}dvl($blocked,'Generic POS check must not merge through the table-service dining visit path.');
pos_cancel_check($pdo,$org,(string)$a['publicId'],'CI cleanup',$user);pos_cancel_check($pdo,$org,(string)$b['publicId'],'CI cleanup',$user);service_visit_reconcile_live($pdo,$org,$location,$user);

// Merge preserves combined covers, physical tables, visit identity, and a seated reservation pointer.
$m1=dvl_table($pdo,$org,$location,$section,'M1',4,$user);$m2=dvl_table($pdo,$org,$location,$section,'M2',4,$user);
$mergeRes=service_ops_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Merge Guest','partySize'=>2,'scheduledAt'=>$future,'durationMinutes'=>90,'tablePublicIds'=>[$m1['publicId']]],$user);$mergeRes=service_ops_reservation_status_atomic($pdo,$org,$mergeRes['publicId'],'arrived',$user);$ms=service_visit_host_seat($pdo,$org,$mergeRes['publicId'],null,$user);$mt=service_visit_seat($pdo,$org,$location,$m2['publicId'],3,null,'',$user);
pos_add_item($pdo,$org,(string)$ms['check']['publicId'],$price,1,'',$user);pos_add_item($pdo,$org,(string)$mt['publicId'],$price,1,'',$user);
$merged=service_visit_merge_safe($pdo,$org,(string)$ms['check']['publicId'],(string)$mt['publicId'],$user);$mergedId=(int)$merged['id'];
dvl((int)$merged['guestCount']===5,'Merged table-service check must sum source and target covers.');
dvl((string)dvl_one($pdo,'SELECT status FROM pos_checks WHERE organization_id=? AND public_id=?',[$org,$ms['check']['publicId']])==='merged','Source check must become merged terminal history.');
dvl((int)dvl_one($pdo,'SELECT seated_check_id FROM guest_reservations WHERE organization_id=? AND public_id=?',[$org,$mergeRes['publicId']])===$mergedId,'Seated reservation must repoint to the surviving merged check.');
dvl((int)dvl_one($pdo,'SELECT COUNT(*) FROM service_tables WHERE organization_id=? AND public_id IN (?,?) AND active_check_id=? AND state=\'seated\'',[$org,$m1['publicId'],$m2['publicId'],$mergedId])===2,'Both physical tables must remain occupied by the surviving merged check.');
$groups=(int)dvl_one($pdo,"SELECT COUNT(DISTINCT visit_group_id) FROM service_check_contexts WHERE organization_id=? AND check_id IN ((SELECT id FROM pos_checks WHERE organization_id=? AND public_id=?),(SELECT id FROM pos_checks WHERE organization_id=? AND public_id=?))",[$org,$org,$ms['check']['publicId'],$org,$mt['publicId']]);dvl($groups===1,'Merged checks must share one dining visit identity.');
dvl_pay($pdo,$org,(string)$mt['publicId'],$user);service_visit_reconcile_live($pdo,$org,$location,$user);
dvl((string)dvl_one($pdo,'SELECT status FROM guest_reservations WHERE organization_id=? AND public_id=?',[$org,$mergeRes['publicId']])==='completed','Merged reservation must complete from the surviving paid check.');
dvl((int)dvl_one($pdo,'SELECT COUNT(*) FROM service_tables WHERE organization_id=? AND public_id IN (?,?) AND active_check_id IS NULL AND state=\'dirty\'',[$org,$m1['publicId'],$m2['publicId']])===2,'Merged visit settlement must release every physical table dirty.');

// Multi-table reservation transfer moves the whole visit, not only the primary table.
$x1=dvl_table($pdo,$org,$location,$section,'X1',2,$user);$x2=dvl_table($pdo,$org,$location,$section,'X2',2,$user);$small=dvl_table($pdo,$org,$location,$section,'SMALL',2,$user);$big=dvl_table($pdo,$org,$location,$section,'BIG',6,$user);
$combo=service_ops_combination_save($pdo,$org,$location,['name'=>'X1 + X2','tablePublicIds'=>[$x1['publicId'],$x2['publicId']]],$user);
$moveRes=service_ops_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Move Guest','partySize'=>4,'scheduledAt'=>$future,'durationMinutes'=>90,'combinationPublicId'=>$combo['publicId']],$user);$moveRes=service_ops_reservation_status_atomic($pdo,$org,$moveRes['publicId'],'arrived',$user);$moveSeat=service_visit_host_seat($pdo,$org,$moveRes['publicId'],null,$user);$movePublic=(string)$moveSeat['check']['publicId'];$moveId=(int)$moveSeat['check']['id'];
$blocked=false;try{service_visit_transfer_safe($pdo,$org,$movePublic,$small['publicId'],$user);}catch(InvalidArgumentException){$blocked=true;}dvl($blocked,'Destination table must fit the full dining visit before transfer.');
service_visit_transfer_safe($pdo,$org,$movePublic,$big['publicId'],$user);
dvl((int)dvl_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$big['publicId']])===$moveId,'Destination table must own the transferred dining visit.');
dvl((int)dvl_one($pdo,"SELECT COUNT(*) FROM service_tables WHERE organization_id=? AND public_id IN (?,?) AND active_check_id IS NULL AND state='dirty'",[$org,$x1['publicId'],$x2['publicId']])===2,'Every former combined table must be released dirty after visit transfer.');
dvl((int)dvl_one($pdo,'SELECT COUNT(*) FROM guest_reservation_tables rt JOIN guest_reservations r ON r.id=rt.reservation_id WHERE r.organization_id=? AND r.public_id=? AND rt.service_table_id=(SELECT id FROM service_tables WHERE organization_id=? AND public_id=?)',[$org,$moveRes['publicId'],$org,$big['publicId']])===1,'Reservation table assignment must move with the transferred dining visit.');
pos_cancel_check($pdo,$org,$movePublic,'CI cleanup',$user);service_visit_reconcile_live($pdo,$org,$location,$user);dvl((string)dvl_one($pdo,'SELECT status FROM guest_reservations WHERE organization_id=? AND public_id=?',[$org,$moveRes['publicId']])==='cancelled','Cancelled transferred visit must cancel its seated reservation.');

// Grouped CRM attach/detach propagates across open split siblings; any sibling payment locks merge/identity rewrites.
$g1=dvl_table($pdo,$org,$location,$section,'G1',4,$user);$gcheck=service_visit_seat($pdo,$org,$location,$g1['publicId'],2,null,'',$user);$gpublic=(string)$gcheck['publicId'];
$gcheck=pos_add_item($pdo,$org,$gpublic,$price,1,'',$user);$items=$gcheck['items'];$last=end($items);$ga=(int)$last['id'];table_service_item_course($pdo,$org,$gpublic,$ga,1,'mains',$user);
$gcheck=pos_add_item($pdo,$org,$gpublic,$price,1,'',$user);$items=$gcheck['items'];$last=end($items);$gb=(int)$last['id'];table_service_item_course($pdo,$org,$gpublic,$gb,2,'mains',$user);
$gsplit=service_visit_split($pdo,$org,$gpublic,[$ga],$user,1);$g2public=(string)$gsplit['created']['publicId'];service_visit_attach_customer_safe($pdo,$org,$gpublic,(string)$customerB['public_id']);
dvl((int)dvl_one($pdo,"SELECT COUNT(*) FROM pos_checks c JOIN service_check_contexts cx ON cx.check_id=c.id AND cx.organization_id=c.organization_id WHERE c.organization_id=? AND cx.visit_group_id=(SELECT visit_group_id FROM service_check_contexts WHERE organization_id=? AND check_id=(SELECT id FROM pos_checks WHERE organization_id=? AND public_id=?)) AND c.status='open' AND c.customer_id=?",[$org,$org,$org,$gpublic,$customerB['id']])===2,'CRM attach must propagate to every open split sibling.');
service_visit_attach_customer_safe($pdo,$org,$gpublic,null);dvl((int)dvl_one($pdo,"SELECT COUNT(*) FROM pos_checks c JOIN service_check_contexts cx ON cx.check_id=c.id AND cx.organization_id=c.organization_id WHERE c.organization_id=? AND cx.visit_group_id=(SELECT visit_group_id FROM service_check_contexts WHERE organization_id=? AND check_id=(SELECT id FROM pos_checks WHERE organization_id=? AND public_id=?)) AND c.status='open' AND c.customer_id IS NOT NULL",[$org,$org,$org,$gpublic])===0,'CRM detach must propagate across all unpaid open siblings.');
service_visit_attach_customer_safe($pdo,$org,$gpublic,(string)$customerB['public_id']);dvl_pay($pdo,$org,$g2public,$user);$other=dvl_table($pdo,$org,$location,$section,'OTHER',4,$user);$otherCheck=service_visit_seat($pdo,$org,$location,$other['publicId'],1,null,'',$user);
$blocked=false;try{service_visit_merge_safe($pdo,$org,$gpublic,(string)$otherCheck['publicId'],$user);}catch(InvalidArgumentException){$blocked=true;}dvl($blocked,'Any captured tender in a sibling check must lock dining-visit merge.');
$blocked=false;try{service_visit_attach_customer_safe($pdo,$org,$gpublic,null);}catch(InvalidArgumentException){$blocked=true;}dvl($blocked,'Paid sibling must lock incompatible dining-visit customer identity changes.');

echo "dining-visit-lifecycle-ok\n";
