<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/service-ops-hardening.php';

$pdo=app_pdo();
function thc(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function thc_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

thc(host_ready($pdo),'Host Stand must be installed.');
$slug='hard-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Hardening '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Dining','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Hard','Manager','Hard Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);
$pdo->prepare("INSERT INTO floor_plans (organization_id,public_id,name,plan_json,width_ft,depth_ft,scale_px_per_ft,version,is_default,created_by,updated_by) VALUES (?,?,?,JSON_OBJECT(),40,30,24,1,1,?,?)")->execute([$org,'floor-'.$slug,'Dining',$user,$user]);$floor='floor-'.$slug;
$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining','sortOrder'=>1],$user);
$tableA=service_ops_managed_table_create($pdo,$org,$location,['name'=>'A1','capacity'=>4,'shape'=>'square','sectionPublicId'=>$section['publicId'],'floorPlanId'=>$floor,'xFt'=>5,'yFt'=>5],$user);
$tableB=service_ops_managed_table_create($pdo,$org,$location,['name'=>'B1','capacity'=>4,'shape'=>'square','sectionPublicId'=>$section['publicId'],'floorPlanId'=>$floor,'xFt'=>10,'yFt'=>5],$user);
thc($tableA['managedAsset']&&$tableA['physicalReady'],'Managed table must have an operable physical asset.');
thc(str_starts_with((string)$tableA['asset']['assetTag'],'TABLE-'.$location.'-'),'Default asset tag must be location-scoped.');

$legacy=table_service_table_save($pdo,$org,$location,['name'=>'Legacy Unsafe','capacity'=>2,'sectionPublicId'=>$section['publicId']],$user);
$blocked=false;try{service_ops_seat($pdo,$org,$location,$legacy['publicId'],2,null,'',$user);}catch(InvalidArgumentException){$blocked=true;}thc($blocked,'Unmanaged legacy table must not be seatable through hardened Table Service.');

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Dinner',?,'active',1)")->execute([$org,'dinner-'.$slug]);$menuSection=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Pizza',?,1)")->execute([$org,$menuSection,'pizza-'.$slug]);$menuItem=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',20.00,'USD',1)")->execute([$menuItem]);$price=(int)$pdo->lastInsertId();

$today=service_ops_business_date($pdo,$org,$location);table_service_section_assign($pdo,$org,$location,$section['publicId'],$user,$today,$user);
$check=service_ops_seat($pdo,$org,$location,$tableA['publicId'],4,null,'Hardening split test',$user);$checkPublic=(string)$check['publicId'];
thc((int)$check['serviceContext']['serverUserId']===$user,'Restaurant-local section assignment must drive default server.');
$check=pos_add_item($pdo,$org,$checkPublic,$price,1,'Seat 1',$user);$item1=(int)end($check['items'])['id'];table_service_item_course($pdo,$org,$checkPublic,$item1,1,'mains',$user);
$check=pos_add_item($pdo,$org,$checkPublic,$price,1,'Seat 2',$user);$item2=(int)end($check['items'])['id'];table_service_item_course($pdo,$org,$checkPublic,$item2,2,'mains',$user);
$split=service_ops_split($pdo,$org,$checkPublic,[$item1],$user,null);
thc((int)$split['created']['guestCount']===1,'A fully moved seat must allocate one cover to the split check.');
thc((int)$split['source']['guestCount']===3,'Source covers must be reduced after seat split.');
thc((int)$split['created']['guestCount']+(int)$split['source']['guestCount']===4,'Split checks must preserve total covers.');

$source=$split['source'];$created=$split['created'];
$source=pos_record_tender($pdo,$org,(string)$source['publicId'],['tenderType'=>'cash','amount'=>$source['balanceDue'],'tipAmount'=>0,'receivedAmount'=>$source['balanceDue']],$user);
$created=pos_record_tender($pdo,$org,(string)$created['publicId'],['tenderType'=>'cash','amount'=>$created['balanceDue'],'tipAmount'=>0,'receivedAmount'=>$created['balanceDue']],$user);
thc((int)thc_one($pdo,"SELECT covers FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider='gelato_pos' AND granularity='daily' AND service_period='all' AND period_start=?",[$org,'id:'.$location,$today])===4,'Paid split checks must not double-count covers in Sales Intelligence.');

table_service_reconcile_closed_checks($pdo,$org,$location,$user);
$pdo->prepare("UPDATE service_tables SET state='available' WHERE organization_id=? AND public_id=?")->execute([$org,$tableA['publicId']]);
$current=service_ops_seat($pdo,$org,$location,$tableA['publicId'],2,null,'Current occupancy',$user);
$future=(new DateTimeImmutable('tomorrow 19:00',service_ops_timezone($pdo,$org,$location)))->format('Y-m-d H:i:s');
$available=service_ops_availability($pdo,$org,$location,$future,2,90);
thc(count(array_filter($available,static fn(array $x):bool=>in_array($tableA['publicId'],$x['tables'],true)))===1,'A table occupied now must still be available for a sufficiently future reservation.');
$near=pos_clock($pdo,$org,$location)->modify('+30 minutes')->format('Y-m-d H:i:s');
$availableNear=service_ops_availability($pdo,$org,$location,$near,2,90);
thc(count(array_filter($availableNear,static fn(array $x):bool=>in_array($tableA['publicId'],$x['tables'],true)))===0,'Current occupancy must block near-term availability.');

$tableB=host_update_table_asset($pdo,$org,$location,$tableB['publicId'],['operationalStatus'=>'out_of_service','conditionStatus'=>'poor','widthInches'=>36,'depthInches'=>36,'heightInches'=>30,'assetTag'=>$tableB['asset']['assetTag']],$user);
$blocked=false;try{service_ops_seat($pdo,$org,$location,$tableB['publicId'],2,null,'',$user);}catch(InvalidArgumentException){$blocked=true;}thc($blocked,'Out-of-service physical table must be blocked from direct Table Service seating.');
$blocked=false;try{service_ops_transfer($pdo,$org,(string)$current['publicId'],$tableB['publicId'],$user);}catch(InvalidArgumentException){$blocked=true;}thc($blocked,'Out-of-service physical table must be blocked from check transfer.');

$tableC=service_ops_managed_table_create($pdo,$org,$location,['name'=>'C1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$res=service_ops_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'State Guest','partySize'=>2,'scheduledAt'=>$future,'durationMinutes'=>90,'tablePublicIds'=>[$tableC['publicId']]],$user);
$res=service_ops_reservation_status($pdo,$org,$res['publicId'],'confirmed',$user);thc($res['status']==='confirmed','Booked reservation must confirm.');
$blocked=false;try{service_ops_reservation_status($pdo,$org,$res['publicId'],'booked',$user);}catch(InvalidArgumentException){$blocked=true;}thc($blocked,'Confirmed reservation must not regress to booked.');
$res=service_ops_reservation_update($pdo,$org,$res['publicId'],['partySize'=>3,'notes'=>'Updated safely'],$user);thc($res['partySize']===3&&$res['notes']==='Updated safely','Nonterminal reservation must support safe edits.');

$wait1=service_ops_reservation_create($pdo,$org,$location,['type'=>'waitlist','guestName'=>'Wait One','partySize'=>2],$user);
$wait2=service_ops_reservation_create($pdo,$org,$location,['type'=>'waitlist','guestName'=>'Wait Two','partySize'=>2],$user);
$wait1=service_ops_reservation_assign($pdo,$org,$wait1['publicId'],[$tableC['publicId']],$user);thc(count($wait1['tables'])===1,'Waitlist party must accept a table assignment.');
$blocked=false;try{service_ops_reservation_assign($pdo,$org,$wait2['publicId'],[$tableC['publicId']],$user);}catch(InvalidArgumentException){$blocked=true;}thc($blocked,'One physical table must not be preassigned to two active waitlist parties.');
$blocked=false;try{service_ops_reservation_status($pdo,$org,$wait2['publicId'],'confirmed',$user);}catch(InvalidArgumentException){$blocked=true;}thc($blocked,'Waitlist lifecycle must reject reservation-only confirmed status.');

echo "table-host-hardening-ok\n";
