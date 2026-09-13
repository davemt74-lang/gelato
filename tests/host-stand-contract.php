<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/host-stand-core.php';
require_once __DIR__.'/../includes/table-service-reconcile.php';

$pdo=app_pdo();
function hci(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function hci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

hci(host_ready($pdo),'Host Stand migration must be installed.');
$slug='host-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Host CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Dining Room','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Host','Manager','Host Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Host Manager','active')")->execute([$org,$user,$location]);
$pdo->prepare("INSERT INTO floor_plans (organization_id,public_id,name,plan_json,width_ft,depth_ft,scale_px_per_ft,version,is_default,created_by,updated_by) VALUES (?,?,?,JSON_OBJECT(),40,30,24,1,1,?,?)")->execute([$org,'floor-'.$slug,'Main Dining Floor',$user,$user]);$floor='floor-'.$slug;
$section=table_service_section_save($pdo,$org,$location,['name'=>'Main Dining','sortOrder'=>1],$user);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Dinner',?,'active',1)")->execute([$org,'dinner-'.$slug]);$menuSection=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Margherita Pizza',?,1)")->execute([$org,$menuSection,'pizza-'.$slug]);$menuItem=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',20.00,'USD',1)")->execute([$menuItem]);$price=(int)$pdo->lastInsertId();

$t12=host_create_managed_table($pdo,$org,$location,['name'=>'T12','capacity'=>4,'shape'=>'square','sectionPublicId'=>$section['publicId'],'assetTag'=>'TABLE-012','purchasePrice'=>140,'replacementCost'=>180,'widthInches'=>36,'depthInches'=>36,'heightInches'=>30,'floorPlanId'=>$floor,'xFt'=>5,'yFt'=>6,'rotationDeg'=>0],$user);
$t13=host_create_managed_table($pdo,$org,$location,['name'=>'T13','capacity'=>4,'shape'=>'square','sectionPublicId'=>$section['publicId'],'assetTag'=>'TABLE-013','replacementCost'=>180,'widthInches'=>36,'depthInches'=>36,'heightInches'=>30,'floorPlanId'=>$floor,'xFt'=>10,'yFt'=>6,'rotationDeg'=>0],$user);
hci($t12['managedAsset']&&$t12['asset']['assetType']==='dining_table','Managed table must create a dining_table equipment asset.');
hci((string)hci_one($pdo,'SELECT asset_tag FROM equipment_assets WHERE id=(SELECT equipment_asset_id FROM service_tables WHERE organization_id=? AND public_id=?)',[$org,$t12['publicId']])==='TABLE-012','Table asset tag was not stored.');
hci(abs((float)$t12['projection']['xPercent']-12.5)<.2,'Service-table X projection must derive from canonical 5ft / 40ft floor placement.');
hci(abs((float)$t12['projection']['yPercent']-20.0)<.2,'Service-table Y projection must derive from canonical 6ft / 30ft floor placement.');

$t12=host_place_table($pdo,$org,$location,$t12['publicId'],['floorPlanId'=>$floor,'xFt'=>8,'yFt'=>9,'rotationDeg'=>90],$user);
hci(abs((float)$t12['asset']['xFt']-8)<.001&&abs((float)$t12['projection']['xPercent']-20)<.2,'Moving the canonical asset must update the Table Service projection.');

$legacy=table_service_table_save($pdo,$org,$location,['name'=>'Legacy 7','capacity'=>2,'sectionPublicId'=>$section['publicId'],'shape'=>'round','xPercent'=>70,'yPercent'=>20],$user);
hci(!$legacy['activeCheckId'],'Legacy service table should start without a check.');$synced=host_sync_all_table_assets($pdo,$org,$location,$user);hci($synced===1,'Exactly one legacy table should be converted to a managed asset.');
hci((int)hci_one($pdo,"SELECT COUNT(*) FROM equipment_assets WHERE organization_id=? AND asset_type='dining_table'",[$org])===3,'Legacy sync must create a dining_table asset.');

$combo=host_combination_save($pdo,$org,$location,['name'=>'T12 + T13','tablePublicIds'=>[$t12['publicId'],$t13['publicId']]],$user);
hci($combo['capacity']===8&&count($combo['tables'])===2,'Table combination must aggregate both physical tables.');

$customer=crm_customer_save($pdo,$org,['firstName'=>'Alex','lastName'=>'Guest','displayName'=>'Alex Guest','email'=>'alex-'.$slug.'@example.test','phone'=>'6025550198'],$user,'manual');
$start=(new DateTimeImmutable('tomorrow 19:00'))->format('Y-m-d H:i:s');
$res=host_reservation_save($pdo,$org,$location,['type'=>'reservation','guestName'=>'Alex Guest','customerPublicId'=>$customer['public_id'],'partySize'=>6,'scheduledAt'=>$start,'durationMinutes'=>90,'combinationPublicId'=>$combo['publicId'],'notes'=>'Anniversary'], $user);
hci($res['status']==='booked'&&count($res['tables'])===2,'Reservation must book the saved two-table combination.');
$conflict=false;try{host_reservation_save($pdo,$org,$location,['type'=>'reservation','guestName'=>'Overlap','partySize'=>2,'scheduledAt'=>(new DateTimeImmutable($start))->modify('+30 minutes')->format('Y-m-d H:i:s'),'durationMinutes'=>60,'tablePublicIds'=>[$t12['publicId']]],$user);}catch(InvalidArgumentException){$conflict=true;}hci($conflict,'Overlapping reservation on a reserved physical table must be blocked.');

$wait=host_reservation_save($pdo,$org,$location,['type'=>'waitlist','guestName'=>'Walk In','partySize'=>2,'quotedWaitMinutes'=>25],$user);hci($wait['status']==='waiting'&&!empty($wait['joinedWaitlistAt']),'Walk-in must enter the waitlist with timestamp.');
$res=host_reservation_status($pdo,$org,$res['publicId'],'arrived',$user);hci($res['status']==='arrived'&&!empty($res['arrivedAt']),'Reservation arrival must be recorded.');

$seat=host_seat($pdo,$org,$res['publicId'],null,$user);$checkPublic=(string)$seat['check']['publicId'];$checkId=(int)$seat['check']['id'];
hci($seat['reservation']['status']==='seated','Seating must move reservation to seated.');
hci((int)hci_one($pdo,'SELECT COUNT(*) FROM service_tables WHERE organization_id=? AND active_check_id=?',[$org,$checkId])===2,'Both combined physical tables must share one canonical POS check.');
hci((int)hci_one($pdo,'SELECT customer_id FROM pos_checks WHERE organization_id=? AND id=?',[$org,$checkId])===(int)$customer['id'],'CRM customer must carry into the seated POS check.');
$check=pos_add_item($pdo,$org,$checkPublic,$price,1,'',$user);hci(abs((float)$check['subtotal']-20)<.01,'Seated reservation must be a normal Native POS check.');
$check=pos_record_tender($pdo,$org,$checkPublic,['tenderType'=>'cash','amount'=>$check['balanceDue'],'tipAmount'=>0,'receivedAmount'=>$check['balanceDue']],$user);hci($check['status']==='paid','Reservation POS check must close normally.');
$released=table_service_reconcile_closed_checks($pdo,$org,$location,$user);hci($released===2,'Closing one combined POS check must release both physical tables.');
$reconciled=host_reconcile($pdo,$org,$location,$user);hci($reconciled===1,'Host reservation must reconcile from the paid POS check.');
hci((string)hci_one($pdo,'SELECT status FROM guest_reservations WHERE organization_id=? AND public_id=?',[$org,$res['publicId']])==='completed','Paid POS check must complete the reservation.');
hci((int)hci_one($pdo,"SELECT COUNT(*) FROM service_tables WHERE organization_id=? AND public_id IN (?,?) AND state='dirty' AND active_check_id IS NULL",[$org,$t12['publicId'],$t13['publicId']])===2,'Both combined tables must become dirty after payment.');

$pdo->prepare("UPDATE service_tables SET state='available' WHERE organization_id=? AND public_id IN (?,?)")->execute([$org,$t12['publicId'],$t13['publicId']]);
$t13=host_update_table_asset($pdo,$org,$location,$t13['publicId'],['operationalStatus'=>'out_of_service','conditionStatus'=>'poor','widthInches'=>36,'depthInches'=>36,'heightInches'=>30,'replacementCost'=>180,'assetTag'=>'TABLE-013'],$user);
hci(!$t13['physicalReady']&&$t13['state']==='out_of_service','Out-of-service/poor physical table must leave reservable capacity.');
$blocked=false;try{host_reservation_save($pdo,$org,$location,['type'=>'reservation','guestName'=>'Cannot Seat','partySize'=>2,'scheduledAt'=>(new DateTimeImmutable('tomorrow 21:00'))->format('Y-m-d H:i:s'),'tablePublicIds'=>[$t13['publicId']]],$user);}catch(InvalidArgumentException){$blocked=true;}hci($blocked,'Out-of-service table asset must be blocked from reservation assignment.');

$available=host_availability($pdo,$org,$location,(new DateTimeImmutable('tomorrow 21:00'))->format('Y-m-d H:i:s'),4,90);hci(count(array_filter($available,static fn(array $x):bool=>in_array($t13['publicId'],$x['tables'],true)))===0,'Availability must exclude out-of-service physical tables and combinations containing them.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Host Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$isolated=false;try{host_reservation_row($pdo,$otherOrg,$res['publicId']);}catch(InvalidArgumentException){$isolated=true;}hci($isolated,'Reservations must be organization-isolated.');
hci((int)hci_one($pdo,"SELECT COUNT(*) FROM guest_reservation_events WHERE organization_id=? AND reservation_id=(SELECT id FROM guest_reservations WHERE organization_id=? AND public_id=?)",[$org,$org,$res['publicId']])>=4,'Reservation lifecycle must retain append-only events.');

echo "host-stand-ok\n";
