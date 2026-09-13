<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/table-service-core.php';
require_once __DIR__.'/../includes/table-service-reconcile.php';
require_once __DIR__.'/../includes/table-cleaning-lifecycle.php';

$pdo=app_pdo();
function tsci_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function tsci_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}

tsci_assert(pos_ready($pdo),'Native POS must be installed.');
tsci_assert(kds_ready($pdo),'KDS must be installed.');
tsci_assert(table_service_ready($pdo),'Table Service must be installed.');
$slug='table-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Table Service CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Dining Room','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'-manager@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Table','Manager','Table Manager']);$manager=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$manager,$location]);
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'-server@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Alex','Server','Alex Server']);$server=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Server','active')")->execute([$org,$server,$location]);
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Dinner',?,'active',1)")->execute([$org,'dinner-'.$slug]);$menuSection=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Bruschetta',?,1)")->execute([$org,$menuSection,'bruschetta-'.$slug]);$starter=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',12.00,'USD',1)")->execute([$starter]);$starterPrice=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Margherita Pizza',?,1)")->execute([$org,$menuSection,'pizza-'.$slug]);$main=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',20.00,'USD',1)")->execute([$main]);$mainPrice=(int)$pdo->lastInsertId();

$station=kds_station_save($pdo,$org,$location,['name'=>'Hot Line','slug'=>'hot-line','targetSeconds'=>600],$manager);
kds_route_save($pdo,$org,$location,$starter,(string)$station['public_id'],$manager);kds_route_save($pdo,$org,$location,$main,(string)$station['public_id'],$manager);
$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining Room','sortOrder'=>1],$manager);
$businessDate=pos_clock($pdo,$org,$location)->format('Y-m-d');table_service_section_assign($pdo,$org,$location,(string)$section['publicId'],$server,$businessDate,$manager);
$t1=table_service_table_save($pdo,$org,$location,['name'=>'T1','sectionPublicId'=>$section['publicId'],'capacity'=>4,'shape'=>'round','xPercent'=>20,'yPercent'=>30],$manager);
$t2=table_service_table_save($pdo,$org,$location,['name'=>'T2','sectionPublicId'=>$section['publicId'],'capacity'=>4,'shape'=>'square','xPercent'=>45,'yPercent'=>30],$manager);
$t3=table_service_table_save($pdo,$org,$location,['name'=>'T3','sectionPublicId'=>$section['publicId'],'capacity'=>2,'shape'=>'round','xPercent'=>70,'yPercent'=>30],$manager);
tsci_assert(count(table_service_map($pdo,$org,$location)['tables'])===3,'Floor map must expose configured tables.');
$synced=host_sync_all_table_assets($pdo,$org,$location,$manager);tsci_assert($synced===3,'Legacy Table Service fixtures must sync to managed physical assets before modern service operations.');

$check=table_service_seat($pdo,$org,$location,(string)$t1['publicId'],3,null,'Birthday dinner',$manager);$public=(string)$check['publicId'];
tsci_assert((string)$check['serviceContext']['tablePublicId']===(string)$t1['publicId'],'Seating must attach the canonical POS check to the table.');
tsci_assert((int)$check['serviceContext']['serverUserId']===$server,'Section assignment must become the default server.');
tsci_assert((string)tsci_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t1['publicId']])==='seated','Seated table state was not persisted.');

$base=pos_add_item($pdo,$org,$public,$starterPrice,1,'No garlic',$manager);$starterLine=(int)$base['items'][0]['id'];
$base=pos_add_item($pdo,$org,$public,$mainPrice,1,'Extra crisp',$manager);$mainLine=(int)$base['items'][1]['id'];
$check=table_service_item_course($pdo,$org,$public,$starterLine,1,'starters',$manager);$check=table_service_item_course($pdo,$org,$public,$mainLine,2,'mains',$manager);
tsci_assert((int)$check['items'][0]['seatNumber']===1&&(string)$check['items'][0]['courseKey']==='starters','Seat/course metadata must be attached to the POS line.');
tsci_assert((int)$check['items'][1]['seatNumber']===2&&(string)$check['items'][1]['courseKey']==='mains','Second seat/course metadata must be attached to the POS line.');

$fired=table_service_fire_course($pdo,$org,$public,'starters',$manager,false);tsci_assert((int)$fired['kitchen']['sent']===1,'Firing starters must send only the starter course.');
tsci_assert((int)tsci_one($pdo,'SELECT COUNT(*) FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$starterLine])===1,'Starter line must reach KDS.');
tsci_assert((int)tsci_one($pdo,'SELECT COUNT(*) FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$mainLine])===0,'Unfired mains must remain out of KDS.');
$sentLocked=false;try{table_service_item_course($pdo,$org,$public,$starterLine,3,'mains',$manager);}catch(InvalidArgumentException){$sentLocked=true;}tsci_assert($sentLocked,'Sent kitchen lines must not have seat/course metadata rewritten.');

$check=table_service_transfer($pdo,$org,$public,(string)$t2['publicId'],$manager);tsci_assert((string)$check['serviceContext']['tablePublicId']===(string)$t2['publicId'],'Table transfer must move the check context.');
tsci_assert(tsci_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t1['publicId']])===null,'Source table must be released by transfer.');
tsci_assert((int)tsci_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t2['publicId']])===(int)$check['id'],'Destination table must own the transferred check.');

$split=table_service_split($pdo,$org,$public,[$mainLine],$manager);$splitPublic=(string)$split['created']['publicId'];
tsci_assert(count($split['source']['items'])===1&&count($split['created']['items'])===1,'Item-level split must move exactly the selected POS line.');
tsci_assert((int)tsci_one($pdo,'SELECT COUNT(*) FROM service_check_contexts WHERE organization_id=? AND table_id=(SELECT id FROM service_tables WHERE organization_id=? AND public_id=?) AND status=\'active\'',[$org,$org,$t2['publicId']])===2,'Split checks must remain associated with the same table.');
$splitFire=table_service_fire_course($pdo,$org,$splitPublic,'mains',$manager,false);tsci_assert((int)$splitFire['kitchen']['sent']===1,'Split check course must fire independently.');
$splitCheckId=(int)$split['created']['id'];tsci_assert((int)tsci_one($pdo,'SELECT check_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$mainLine])===$splitCheckId,'KDS row must point at the split check after course fire.');

$merged=table_service_merge($pdo,$org,$splitPublic,$public,$manager);tsci_assert(count($merged['items'])===2,'Merge must reunite all POS lines on the target check.');
tsci_assert((string)tsci_one($pdo,'SELECT status FROM pos_checks WHERE organization_id=? AND public_id=?',[$org,$splitPublic])==='merged','Source check must close as merged.');
tsci_assert((int)tsci_one($pdo,'SELECT check_id FROM kds_order_items WHERE organization_id=? AND pos_check_item_id=?',[$org,$mainLine])===(int)$merged['id'],'KDS history must move with a merged POS line instead of being resent.');
tsci_assert((int)tsci_one($pdo,'SELECT COUNT(*) FROM service_check_contexts WHERE organization_id=? AND table_id=(SELECT id FROM service_tables WHERE organization_id=? AND public_id=?) AND status=\'active\'',[$org,$org,$t2['publicId']])===1,'Merged source context must no longer count as active.');

$guardCheck=table_service_seat($pdo,$org,$location,(string)$t3['publicId'],1,$server,'',$manager);$guardCheck=pos_add_item($pdo,$org,(string)$guardCheck['publicId'],$starterPrice,1,'',$manager);$pdo->prepare("INSERT INTO pos_tenders (organization_id,check_id,public_id,tender_type,amount,tip_amount,change_amount,status,processed_by) VALUES (?,?,?,'cash',1.00,0.00,0.00,'captured',?)")->execute([$org,(int)$guardCheck['id'],table_service_public_id('tender'),$manager]);
$paymentBlocked=false;try{table_service_transfer($pdo,$org,(string)$guardCheck['publicId'],(string)$t1['publicId'],$manager);}catch(InvalidArgumentException){$paymentBlocked=true;}tsci_assert($paymentBlocked,'Table transfer must be blocked after captured payment.');

$paid=table_service_detail($pdo,$org,$public);$paid=pos_record_tender($pdo,$org,$public,['tenderType'=>'external_card','amount'=>$paid['balanceDue'],'tipAmount'=>0,'externalReference'=>'table_service_ci'],$manager);tsci_assert((string)$paid['status']==='paid','Canonical POS must close the table check after full tender.');
$reconciled=table_service_reconcile_closed_checks($pdo,$org,$location,$manager);tsci_assert($reconciled>=1,'Floor reconciliation must release a closed POS check.');
tsci_assert((string)tsci_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t2['publicId']])==='dirty','Paid table must become Dirty after reconciliation.');
tsci_assert(tsci_one($pdo,'SELECT active_check_id FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t2['publicId']])===null,'Dirty table must no longer hold an active check pointer.');
table_cleaning_start($pdo,$org,$location,(string)$t2['publicId'],$manager);
table_cleaning_mark_ready($pdo,$org,$location,(string)$t2['publicId'],$manager);
tsci_assert((string)tsci_one($pdo,'SELECT state FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t2['publicId']])==='available','Bussed table must complete Dirty -> Cleaning -> Ready before returning Available.');
tsci_assert(tsci_one($pdo,'SELECT ready_at FROM service_tables WHERE organization_id=? AND public_id=?',[$org,$t2['publicId']])!==null,'Ready table must retain completion timestamp.');

tsci_assert((int)tsci_one($pdo,"SELECT COUNT(*) FROM service_events WHERE organization_id=? AND event_type='party_seated'",[$org])>=2,'Party seating must be audited.');
tsci_assert((int)tsci_one($pdo,"SELECT COUNT(*) FROM service_events WHERE organization_id=? AND event_type='course_fired'",[$org])>=2,'Course fire must be audited.');
tsci_assert((int)tsci_one($pdo,"SELECT COUNT(*) FROM service_events WHERE organization_id=? AND event_type='checks_merged'",[$org])===1,'Check merge must be audited once.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Table Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$isolated=false;try{table_service_detail($pdo,$otherOrg,$public);}catch(InvalidArgumentException){$isolated=true;}tsci_assert($isolated,'Table-service checks must be organization-isolated.');

echo "table-service contract passed\n";
