<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/service-reservation-protection.php';
require_once __DIR__.'/../includes/table-cleaning-lifecycle.php';

$pdo=app_pdo();
function tcl(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function tcl_one(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function tcl_table(array $tables,string $publicId): ?array {foreach($tables as $table)if(($table['publicId']??null)===$publicId)return $table;return null;}

tcl(table_cleaning_ready($pdo),'Table cleaning lifecycle migration must be installed.');
$slug='clean-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Cleaning '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Dining','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash($slug,PASSWORD_DEFAULT),'Reset','Lead','Reset Lead']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);
$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining','sortOrder'=>1],$user);
table_service_section_assign($pdo,$org,$location,$section['publicId'],$user,service_ops_business_date($pdo,$org,$location),$user);

$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,status,sort_order) VALUES (?,'Dinner',?,'active',1)")->execute([$org,'dinner-'.$slug]);$menuSection=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,is_active) VALUES (?,?,'Test Pizza',?,1)")->execute([$org,$menuSection,'pizza-'.$slug]);$menuItem=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'Regular','REG',18.00,'USD',1)")->execute([$menuItem]);$price=(int)$pdo->lastInsertId();

$table=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Reset 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$check=service_reservation_protection_party_seat($pdo,$org,$location,$table['publicId'],2,null,'cleaning lifecycle',$user);
$check=pos_add_item($pdo,$org,$check['publicId'],$price,1,'',$user);
$check=pos_record_tender($pdo,$org,$check['publicId'],['tenderType'=>'cash','amount'=>$check['balanceDue'],'tipAmount'=>0,'receivedAmount'=>$check['balanceDue']],$user);
tcl($check['status']==='paid','Test check must close through Native POS.');
service_visit_reconcile_live($pdo,$org,$location,$user);
$row=host_table_row($pdo,$org,$location,$table['publicId'],false);
tcl((string)$row['state']==='dirty'&&$row['active_check_id']===null,'Closed dining visit must release the table Dirty.');
tcl(!empty($row['dirty_at']),'Dirty transition from normal POS reconciliation must stamp dirty_at.');
tcl($row['cleaning_started_at']===null&&$row['ready_at']===null,'Fresh Dirty transition must clear cleaning and ready timestamps.');

$dashboard=service_seatability_dashboard($pdo,$org,$location,service_ops_business_date($pdo,$org,$location),$user);
$dirtyDash=tcl_table($dashboard['tables'],$table['publicId']);
tcl($dirtyDash!==null&&empty($dirtyDash['reservableNow'])&&!empty($dirtyDash['dirtyAt']),'Host/Table dashboard must expose Dirty timing and block immediate seating.');

$rejected=false;try{service_ops_table_state($pdo,$org,$table['publicId'],'available',$user);}catch(InvalidArgumentException $e){$rejected=str_contains($e->getMessage(),'Table Ready');}
tcl($rejected,'Legacy table.state must not bypass Dirty -> Cleaning -> Table Ready lifecycle.');
$rejected=false;try{table_cleaning_mark_ready($pdo,$org,$location,$table['publicId'],$user);}catch(InvalidArgumentException){$rejected=true;}
tcl($rejected,'Dirty table cannot skip directly to Table Ready.');

$cleaning=table_cleaning_start($pdo,$org,$location,$table['publicId'],$user);
tcl((string)$cleaning['state']==='cleaning','Start Cleaning must move table to cleaning.');
tcl(!empty($cleaning['cleaning_started_at'])&&(int)$cleaning['cleaning_started_by']===$user,'Start Cleaning must persist timestamp and factual operator attribution.');
$rejected=false;try{service_reservation_protection_party_seat($pdo,$org,$location,$table['publicId'],2,null,'must not seat while cleaning',$user);}catch(InvalidArgumentException $e){$rejected=str_contains($e->getMessage(),'being cleaned');}
tcl($rejected,'Cleaning table must reject immediate seating.');

$cleanDash=service_seatability_dashboard($pdo,$org,$location,service_ops_business_date($pdo,$org,$location),$user);$cleanDashTable=tcl_table($cleanDash['tables'],$table['publicId']);
tcl($cleanDashTable!==null&&(string)$cleanDashTable['state']==='cleaning'&&empty($cleanDashTable['reservableNow']),'Cleaning state must be visible and not reservable now.');
tcl((int)$cleanDashTable['cleaningStartedById']===$user&&$cleanDashTable['cleaningStartedByName']==='Reset Lead','Dashboard must expose factual cleaning operator attribution.');

$future=pos_clock($pdo,$org,$location)->modify('+30 minutes')->format('Y-m-d H:i:s');
$res=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Future Guest','partySize'=>2,'scheduledAt'=>$future,'durationMinutes'=>90,'tablePublicIds'=>[$table['publicId']]],$user);
tcl(count($res['tables'])===1&&$res['tables'][0]['publicId']===$table['publicId'],'Cleaning table may remain assigned to a sufficiently future reservation.');

$ready=table_cleaning_mark_ready($pdo,$org,$location,$table['publicId'],$user);
tcl((string)$ready['state']==='available','Table Ready must return table to Available.');
tcl(!empty($ready['ready_at'])&&(int)$ready['ready_by']===$user,'Table Ready must persist timestamp and factual operator attribution.');
$readyDash=service_seatability_dashboard($pdo,$org,$location,service_ops_business_date($pdo,$org,$location),$user);$readyDashTable=tcl_table($readyDash['tables'],$table['publicId']);
tcl($readyDashTable!==null&&!empty($readyDashTable['reservableNow'])&&!empty($readyDashTable['readyAt']),'Ready table must become immediately reservable and expose reset timing.');
tcl($readyDashTable['lastResetSeconds']!==null,'Completed reset must expose a factual dirty-to-ready duration.');

$events=(int)tcl_one($pdo,"SELECT COUNT(*) FROM service_events WHERE organization_id=? AND table_id=(SELECT id FROM service_tables WHERE organization_id=? AND public_id=?) AND event_type IN ('table_cleaning_started','table_ready')",[$org,$org,$table['publicId']]);
tcl($events===2,'Cleaning lifecycle must append both start and ready service events.');

service_ops_reservation_status_atomic($pdo,$org,$res['publicId'],'cancelled',$user);
$check2=service_reservation_protection_party_seat($pdo,$org,$location,$table['publicId'],2,null,'second cycle',$user);
$check2=pos_add_item($pdo,$org,$check2['publicId'],$price,1,'',$user);
$check2=pos_record_tender($pdo,$org,$check2['publicId'],['tenderType'=>'cash','amount'=>$check2['balanceDue'],'tipAmount'=>0,'receivedAmount'=>$check2['balanceDue']],$user);
service_visit_reconcile_live($pdo,$org,$location,$user);
$secondDirty=host_table_row($pdo,$org,$location,$table['publicId'],false);
tcl((string)$secondDirty['state']==='dirty'&&!empty($secondDirty['dirty_at']),'Second close must start a fresh Dirty lifecycle.');
tcl($secondDirty['cleaning_started_at']===null&&$secondDirty['cleaning_started_by']===null&&$secondDirty['ready_at']===null&&$secondDirty['ready_by']===null,'New Dirty cycle must clear prior cleaning/ready completion metadata.');

// A shared combined check must stamp each physical table independently when it closes.
$t2=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Reset 2','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$t3=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Reset 3','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$combo=service_ops_combination_save($pdo,$org,$location,['name'=>'Reset 2 + 3','tablePublicIds'=>[$t2['publicId'],$t3['publicId']]],$user);
$walk=service_reservation_protection_reservation_create($pdo,$org,$location,['type'=>'waitlist','guestName'=>'Combo Walk In','partySize'=>6,'combinationPublicId'=>$combo['publicId']],$user);
$walk=service_ops_reservation_status_atomic($pdo,$org,$walk['publicId'],'arrived',$user);
$seated=service_reservation_protection_host_seat($pdo,$org,$walk['publicId'],null,$user);$comboCheck=$seated['check'];
$comboCheck=pos_add_item($pdo,$org,$comboCheck['publicId'],$price,1,'',$user);
$comboCheck=pos_record_tender($pdo,$org,$comboCheck['publicId'],['tenderType'=>'cash','amount'=>$comboCheck['balanceDue'],'tipAmount'=>0,'receivedAmount'=>$comboCheck['balanceDue']],$user);
service_visit_reconcile_live($pdo,$org,$location,$user);
$q=$pdo->prepare("SELECT COUNT(*) FROM service_tables WHERE organization_id=? AND public_id IN (?,?) AND state='dirty' AND dirty_at IS NOT NULL AND active_check_id IS NULL");$q->execute([$org,$t2['publicId'],$t3['publicId']]);
tcl((int)$q->fetchColumn()===2,'One combined paid check must release both physical tables Dirty with independent dirty_at timestamps.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Cleaning Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();
$isolated=false;try{table_cleaning_start($pdo,$otherOrg,$location,$table['publicId'],$user);}catch(InvalidArgumentException){$isolated=true;}
tcl($isolated,'Cleaning operations must remain organization-isolated.');

echo "table-cleaning-lifecycle-ok\n";
