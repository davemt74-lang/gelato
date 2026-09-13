<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/service-seatability.php';

$pdo=app_pdo();
function tsc(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function tsc_table(array $tables,string $publicId): ?array {foreach($tables as $table)if(($table['publicId']??null)===$publicId)return $table;return null;}
function tsc_has(array $availability,string $publicId): bool {foreach($availability as $candidate)if(in_array($publicId,(array)($candidate['tables']??[]),true))return true;return false;}

$slug='seat-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Seatability '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Dining','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash($slug,PASSWORD_DEFAULT),'Seat','Manager','Seat Manager']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$user,$location]);
$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining','sortOrder'=>1],$user);
table_service_section_assign($pdo,$org,$location,$section['publicId'],$user,service_ops_business_date($pdo,$org,$location),$user);
$available=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Available 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$dirty=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Dirty 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$blocked=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Blocked 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$source=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Source 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$pdo->prepare("UPDATE service_tables SET state='dirty' WHERE organization_id=? AND public_id=?")->execute([$org,$dirty['publicId']]);
$pdo->prepare("UPDATE service_tables SET state='blocked' WHERE organization_id=? AND public_id=?")->execute([$org,$blocked['publicId']]);

$today=service_ops_business_date($pdo,$org,$location);
$dashboard=service_seatability_dashboard($pdo,$org,$location,$today,$user);
$a=tsc_table($dashboard['tables'],$available['publicId']);$d=tsc_table($dashboard['tables'],$dirty['publicId']);$b=tsc_table($dashboard['tables'],$blocked['publicId']);
tsc($a!==null&&!empty($a['reservableNow']),'Available table must be reservable now.');
tsc($d!==null&&empty($d['reservableNow']),'Dirty table must not be reservable now.');
tsc($b!==null&&empty($b['reservableNow']),'Blocked table must not be reservable now.');

$now=pos_clock($pdo,$org,$location);$near=$now->modify('+5 minutes')->format('Y-m-d H:i:s');$future=$now->modify('+30 minutes')->format('Y-m-d H:i:s');$far=$now->modify('+150 minutes')->format('Y-m-d H:i:s');
$nearAvailability=service_seatability_availability($pdo,$org,$location,$near,2,90);
tsc(tsc_has($nearAvailability,$available['publicId']),'Available table must remain a near-term reservation candidate.');
tsc(!tsc_has($nearAvailability,$dirty['publicId']),'Dirty table must be excluded during cleanup lead time.');
tsc(!tsc_has($nearAvailability,$blocked['publicId']),'Blocked table must never be a reservation candidate.');
$futureAvailability=service_seatability_availability($pdo,$org,$location,$future,2,90);
tsc(tsc_has($futureAvailability,$dirty['publicId']),'Dirty table may be offered for a sufficiently future reservation after cleanup lead time.');
tsc(!tsc_has($futureAvailability,$blocked['publicId']),'Blocked table must remain unavailable for future reservations.');

$rejected=false;try{service_seatability_party_seat($pdo,$org,$location,$dirty['publicId'],2,null,'dirty direct seat',$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Dirty table must reject immediate direct seating.');
$rejected=false;try{service_seatability_party_seat($pdo,$org,$location,$blocked['publicId'],2,null,'blocked direct seat',$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Blocked table must reject immediate direct seating.');

$check=service_seatability_party_seat($pdo,$org,$location,$source['publicId'],2,null,'transfer source',$user);
$rejected=false;try{service_seatability_transfer($pdo,$org,(string)$check['publicId'],$dirty['publicId'],$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Open visit must not transfer to a Dirty table.');
$rejected=false;try{service_seatability_transfer($pdo,$org,(string)$check['publicId'],$blocked['publicId'],$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Open visit must not transfer to a Blocked table.');

$wait=service_seatability_reservation_create($pdo,$org,$location,['type'=>'waitlist','guestName'=>'Wait Dirty','partySize'=>2],$user);
$rejected=false;try{service_seatability_reservation_assign($pdo,$org,$wait['publicId'],[$dirty['publicId']],$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Active waitlist party must not be assigned to a Dirty table.');

$before=(int)$pdo->query("SELECT COUNT(*) FROM guest_reservations")->fetchColumn();
$rejected=false;try{service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Blocked Create','partySize'=>2,'scheduledAt'=>$future,'durationMinutes'=>90,'tablePublicIds'=>[$blocked['publicId']]],$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Reservation creation must reject a Blocked table assignment.');
$after=(int)$pdo->query("SELECT COUNT(*) FROM guest_reservations")->fetchColumn();tsc($after===$before,'Rejected reservation creation with table assignment must roll back atomically.');

$blockedCombo=service_ops_combination_save($pdo,$org,$location,['name'=>'Available plus Blocked','tablePublicIds'=>[$available['publicId'],$blocked['publicId']]],$user);
$beforeCombo=(int)$pdo->query("SELECT COUNT(*) FROM guest_reservations")->fetchColumn();
$rejected=false;try{service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Blocked Combination','partySize'=>6,'scheduledAt'=>$future,'durationMinutes'=>90,'combinationPublicId'=>$blockedCombo['publicId']],$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Reservation creation must route a table combination through seatability policy and reject its Blocked member.');
$afterCombo=(int)$pdo->query("SELECT COUNT(*) FROM guest_reservations")->fetchColumn();tsc($afterCombo===$beforeCombo,'Rejected combination reservation must roll back atomically.');
$pdo->prepare("UPDATE service_tables SET state='available' WHERE organization_id=? AND public_id=?")->execute([$org,$blocked['publicId']]);
$validComboReservation=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Valid Combination','partySize'=>6,'scheduledAt'=>$future,'durationMinutes'=>90,'combinationPublicId'=>$blockedCombo['publicId']],$user);
tsc(count($validComboReservation['tables'])===2,'Valid table combination must still attach both member tables through the hardened assignment path.');
$validComboIds=array_column($validComboReservation['tables'],'publicId');sort($validComboIds);$expectedComboIds=[$available['publicId'],$blocked['publicId']];sort($expectedComboIds);tsc($validComboIds===$expectedComboIds,'Valid combination reservation must preserve the saved member table set.');

$occupiedFuture=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Occupied Future','partySize'=>2,'scheduledAt'=>$far,'durationMinutes'=>90,'tablePublicIds'=>[$source['publicId']]],$user);
$occupiedOriginal=$occupiedFuture['scheduledAt'];$rejected=false;try{service_seatability_reservation_update($pdo,$org,$occupiedFuture['publicId'],['scheduledAt'=>$future],$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Reservation edit must reject moving an assigned table inside the projected active-check occupancy window.');
$occupiedReload=host_reservation_row($pdo,$org,$occupiedFuture['publicId'],false);tsc((string)$occupiedReload['scheduled_at']===(string)$occupiedOriginal,'Rejected occupied-table schedule edit must roll back its scheduled time.');

$nearRes=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Dirty Near','partySize'=>2,'scheduledAt'=>$near,'durationMinutes'=>90],$user);
$rejected=false;try{service_seatability_reservation_assign($pdo,$org,$nearRes['publicId'],[$dirty['publicId']],$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Dirty table must reject reservation assignment inside cleanup window.');

$futureRes=service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>'Dirty Future','partySize'=>2,'scheduledAt'=>$future,'durationMinutes'=>90,'tablePublicIds'=>[$dirty['publicId']]],$user);
tsc(count($futureRes['tables'])===1&&$futureRes['tables'][0]['publicId']===$dirty['publicId'],'Sufficiently future reservation may assign a Dirty table pending cleanup.');
$dirtyOriginal=$futureRes['scheduledAt'];$rejected=false;try{service_seatability_reservation_update($pdo,$org,$futureRes['publicId'],['scheduledAt'=>$near],$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Reservation edit must reject moving an assigned Dirty table inside cleanup lead time.');
$dirtyReload=host_reservation_row($pdo,$org,$futureRes['publicId'],false);tsc((string)$dirtyReload['scheduled_at']===(string)$dirtyOriginal,'Rejected Dirty-table schedule edit must roll back its scheduled time.');
$contactEdit=service_seatability_reservation_update($pdo,$org,$futureRes['publicId'],['guestName'=>'Dirty Future Updated'], $user);tsc($contactEdit['guestName']==='Dirty Future Updated','Non-schedule reservation edits must remain allowed while a future table is Dirty.');
$later=$now->modify('+45 minutes')->format('Y-m-d H:i:s');$movedLater=service_seatability_reservation_update($pdo,$org,$futureRes['publicId'],['scheduledAt'=>$later],$user);tsc((string)$movedLater['scheduledAt']===$later,'Dirty table reservation may be moved to another time outside the cleanup window.');
$rejected=false;try{service_seatability_host_seat($pdo,$org,$futureRes['publicId'],null,$user);}catch(InvalidArgumentException){$rejected=true;}tsc($rejected,'Assigned Dirty table must still be Available before actual seating.');
$pdo->prepare("UPDATE service_tables SET state='available' WHERE organization_id=? AND public_id=?")->execute([$org,$dirty['publicId']]);
$seated=service_seatability_host_seat($pdo,$org,$futureRes['publicId'],null,$user);
tsc(($seated['reservation']['status']??null)==='seated','Reservation must seat after the physical table is marked Available.');

echo "table-seatability-ok\n";
