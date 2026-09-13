<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/service-reservation-protection.php';

$pdo=app_pdo();
function urp(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function urp_table(array $tables,string $publicId): ?array {foreach($tables as $table)if(($table['publicId']??null)===$publicId)return $table;return null;}

$slug='urp-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Reservation protection '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Dining','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash($slug,PASSWORD_DEFAULT),'Host','Manager','Host Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);
$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining','sortOrder'=>1],$user);
table_service_section_assign($pdo,$org,$location,$section['publicId'],$user,service_ops_business_date($pdo,$org,$location),$user);

$soon=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Soon Table','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$later=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Later Table','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$source=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Transfer Source','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$transferTarget=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Transfer Target','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$arrivedTable=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Arrived Table','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);

$now=pos_clock($pdo,$org,$location);
$soonAt=$now->modify('+60 minutes')->format('Y-m-d H:i:s');
$laterAt=$now->modify('+180 minutes')->format('Y-m-d H:i:s');
$pastAt=$now->modify('-10 minutes')->format('Y-m-d H:i:s');

$soonReservation=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Soon Guest','partySize'=>4,'scheduledAt'=>$soonAt,'durationMinutes'=>90,'tablePublicIds'=>[$soon['publicId']]],$user);
$transferReservation=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Transfer Guest','partySize'=>4,'scheduledAt'=>$soonAt,'durationMinutes'=>90,'tablePublicIds'=>[$transferTarget['publicId']]],$user);
$laterReservation=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Later Guest','partySize'=>4,'scheduledAt'=>$laterAt,'durationMinutes'=>90,'tablePublicIds'=>[$later['publicId']]],$user);
$arrivedReservation=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Arrived Guest','partySize'=>2,'scheduledAt'=>$pastAt,'durationMinutes'=>90,'tablePublicIds'=>[$arrivedTable['publicId']]],$user);
service_ops_reservation_status_atomic($pdo,$org,$arrivedReservation['publicId'],'arrived',$user);

$dashboard=service_reservation_protection_dashboard($pdo,$org,$location,service_ops_business_date($pdo,$org,$location),$user);
$soonDash=urp_table($dashboard['tables'],$soon['publicId']);
$laterDash=urp_table($dashboard['tables'],$later['publicId']);
$arrivedDash=urp_table($dashboard['tables'],$arrivedTable['publicId']);
urp($soonDash!==null&&!empty($soonDash['reservationProtected'])&&empty($soonDash['reservableNow']),'Table with an imminent assigned reservation must be protected from walk-in seating.');
urp(($soonDash['nextReservationPublicId']??null)===$soonReservation['publicId'],'Protected table must expose the exact next reservation.');
urp($laterDash!==null&&empty($laterDash['reservationProtected'])&&!empty($laterDash['reservableNow']),'Table with enough turn time before its reservation must remain usable for a walk-in.');
urp($arrivedDash!==null&&!empty($arrivedDash['reservationProtected'])&&empty($arrivedDash['reservableNow']),'An arrived reservation must keep its assigned table protected even when its scheduled time has passed.');

$beforeChecks=(int)$pdo->query('SELECT COUNT(*) FROM pos_checks')->fetchColumn();
$rejected=false;try{service_reservation_protection_party_seat($pdo,$org,$location,$soon['publicId'],2,null,'walk-in should be blocked',$user);}catch(InvalidArgumentException $e){$rejected=str_contains($e->getMessage(),'protected for the');}
urp($rejected,'Direct walk-in seating must reject a table protected for an imminent reservation.');
$afterChecks=(int)$pdo->query('SELECT COUNT(*) FROM pos_checks')->fetchColumn();
urp($afterChecks===$beforeChecks,'Rejected protected-table seating must not create a POS check.');
$soonRow=host_table_row($pdo,$org,$location,$soon['publicId'],false);urp($soonRow['active_check_id']===null,'Rejected protected-table seating must leave the table unoccupied.');

$beforeWaitlists=(int)$pdo->query("SELECT COUNT(*) FROM guest_reservations WHERE reservation_type='waitlist'")->fetchColumn();
$rejected=false;try{service_reservation_protection_reservation_create($pdo,$org,$location,['type'=>'waitlist','guestName'=>'Protected Walk-in Create','partySize'=>2,'tablePublicIds'=>[$soon['publicId']]],$user);}catch(InvalidArgumentException $e){$rejected=str_contains($e->getMessage(),'protected for the');}
urp($rejected,'Host Stand walk-in creation must reject assigning an imminent-reservation table.');
$afterWaitlists=(int)$pdo->query("SELECT COUNT(*) FROM guest_reservations WHERE reservation_type='waitlist'")->fetchColumn();
urp($afterWaitlists===$beforeWaitlists,'Rejected protected walk-in creation must roll back the waitlist row atomically.');

$walkin=service_reservation_protection_reservation_create($pdo,$org,$location,['type'=>'waitlist','guestName'=>'Protected Walk-in Assign','partySize'=>2],$user);
$rejected=false;try{service_reservation_protection_reservation_assign($pdo,$org,$walkin['publicId'],[$soon['publicId']],$user);}catch(InvalidArgumentException $e){$rejected=str_contains($e->getMessage(),'protected for the');}
urp($rejected,'Host Stand walk-in assignment must reject an imminent-reservation table.');
$walkinReload=host_reservation_row($pdo,$org,$walkin['publicId'],false);urp(host_reservation_tables($pdo,$org,(int)$walkinReload['id'])===[],'Rejected protected walk-in assignment must leave the waitlist unassigned.');

$legacyWalkin=service_seatability_reservation_create($pdo,$org,$location,['type'=>'waitlist','guestName'=>'Legacy Assigned Walk-in','partySize'=>2],$user);
service_seatability_reservation_assign($pdo,$org,$legacyWalkin['publicId'],[$soon['publicId']],$user);
$rejected=false;try{service_reservation_protection_host_seat($pdo,$org,$legacyWalkin['publicId'],null,$user);}catch(InvalidArgumentException $e){$rejected=str_contains($e->getMessage(),'protected for the');}
urp($rejected,'Host Stand seating must recheck protection for an already-assigned walk-in.');
$legacyReload=host_reservation_row($pdo,$org,$legacyWalkin['publicId'],false);urp((string)$legacyReload['status']==='waiting','Rejected protected walk-in seating must leave its waitlist status unchanged.');
$soonRow=host_table_row($pdo,$org,$location,$soon['publicId'],false);urp($soonRow['active_check_id']===null,'Rejected protected walk-in seating must leave the reservation table unoccupied.');

$scheduledSeat=service_reservation_protection_host_seat($pdo,$org,$arrivedReservation['publicId'],null,$user);
urp(($scheduledSeat['reservation']['status']??null)==='seated','A scheduled reservation must be able to seat into its own protected table.');

$laterCheck=service_reservation_protection_party_seat($pdo,$org,$location,$later['publicId'],2,null,'walk-in fits before later reservation',$user);
urp(($laterCheck['status']??null)==='open','Walk-in must be allowed when projected turn and cleanup finish before the later reservation hold.');

$sourceCheck=service_reservation_protection_party_seat($pdo,$org,$location,$source['publicId'],2,null,'transfer source',$user);
$rejected=false;try{service_reservation_protection_transfer($pdo,$org,$sourceCheck['publicId'],$transferTarget['publicId'],$user);}catch(InvalidArgumentException $e){$rejected=str_contains($e->getMessage(),'protected for the');}
urp($rejected,'Open dining visit must not transfer onto a table protected for an imminent reservation.');
$sourceRow=host_table_row($pdo,$org,$location,$source['publicId'],false);$targetRow=host_table_row($pdo,$org,$location,$transferTarget['publicId'],false);
urp((int)$sourceRow['active_check_id']===(int)$sourceCheck['id'],'Rejected protected-table transfer must leave the visit on its source table.');
urp($targetRow['active_check_id']===null,'Rejected protected-table transfer must leave the protected destination table unoccupied.');

urp(service_reservation_protection_turn_minutes(2)===90,'Two-person turn estimate must be 90 minutes.');
urp(service_reservation_protection_turn_minutes(4)===105,'Four-person turn estimate must be 105 minutes.');
urp(service_reservation_protection_turn_minutes(6)===120,'Large-party turn estimate must be 120 minutes.');
urp(service_reservation_protection_setup_minutes()===10,'Reservation setup buffer must be 10 minutes.');
urp(service_reservation_protection_grace_minutes()===20,'Reservation grace window must be 20 minutes.');

echo "upcoming-reservation-protection-ok\n";
