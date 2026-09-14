<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/table-turn-readiness.php';

$pdo=app_pdo();
function ttr(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function ttr_find(array $rows,string $tablePublicId): ?array {foreach($rows as $r)if(($r['tablePublicId']??null)===$tablePublicId)return $r;return null;}
function ttr_has_candidate(array $rows,string $tablePublicId): bool {foreach($rows as $r)if(in_array($tablePublicId,(array)($r['tables']??[]),true))return true;return false;}

ttr(table_turn_policy_ready($pdo),'Table turn policy migration must be installed.');
$slug='turn-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Turn CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Dining','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Second Dining','Phoenix','AZ','active')")->execute([$org]);$secondLocation=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash($slug,PASSWORD_DEFAULT),'Host','Lead','Host Lead']);$user=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Host Lead','active')")->execute([$org,$user,$location]);
$section=table_service_section_save($pdo,$org,$location,['name'=>'Dining','sortOrder'=>1],$user);

$defaults=table_turn_policy($pdo,$org,$location);
ttr($defaults['resetTargetMinutes']===15&&$defaults['readyBufferMinutes']===10&&$defaults['urgentThresholdMinutes']===10&&$defaults['configured']===false,'Unconfigured location must retain 15/10/10 backward-compatible defaults.');
ttr(service_seatability_cleanup_minutes($pdo,$org,$location)===15,'Seatability must consume the default reset target.');
ttr(service_reservation_protection_setup_minutes($pdo,$org,$location)===10,'Reservation protection must consume the default ready buffer.');

$overdue=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Overdue 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$urgent=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Urgent 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$scheduled=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Scheduled 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$backlog=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Backlog 1','capacity'=>4,'sectionPublicId'=>$section['publicId']],$user);
$now=pos_clock($pdo,$org,$location);
foreach([[$overdue,'Overdue Guest',$now->modify('+5 minutes')],[$urgent,'Urgent Guest',$now->modify('+18 minutes')],[$scheduled,'Scheduled Guest',$now->modify('+60 minutes')]] as [$table,$guest,$when]){
    service_seatability_reservation_create($pdo,$org,$location,['type'=>'reservation','guestName'=>$guest,'partySize'=>2,'scheduledAt'=>$when->format('Y-m-d H:i:s'),'durationMinutes'=>90,'tablePublicIds'=>[$table['publicId']]],$user);
}
foreach([$overdue,$urgent,$scheduled,$backlog] as $table)$pdo->prepare("UPDATE service_tables SET state='dirty' WHERE organization_id=? AND public_id=?")->execute([$org,$table['publicId']]);
$pdo->prepare("UPDATE service_tables SET dirty_at=DATE_SUB(NOW(6),INTERVAL 20 MINUTE) WHERE organization_id=? AND public_id=?")->execute([$org,$backlog['publicId']]);

$date=service_ops_business_date($pdo,$org,$location);$dash=table_turn_readiness_dashboard($pdo,$org,$location,$date,$user);$queue=$dash['readinessQueue'];
ttr(count($queue)===4,'Every managed Dirty table must enter the reset queue.');
ttr(array_column($queue,'tablePublicId')===[$overdue['publicId'],$urgent['publicId'],$scheduled['publicId'],$backlog['publicId']],'Queue must order overdue, urgent, scheduled, then backlog tables deterministically.');
ttr($queue[0]['urgency']==='overdue'&&$queue[1]['urgency']==='urgent'&&$queue[2]['urgency']==='scheduled'&&$queue[3]['urgency']==='backlog','Queue urgency classes are incorrect.');
ttr($queue[0]['readyBy']!==null&&$queue[0]['minutesToReadyBy']<0,'Overdue reservation must have a passed ready-by deadline.');
ttr($queue[1]['atRisk']===true,'Urgent table should be flagged when projected reset completion exceeds ready-by.');
ttr($queue[3]['nextReservation']===null,'Backlog table must not invent a reservation deadline.');
$encoded=strtolower(json_encode($queue,JSON_THROW_ON_ERROR));
ttr(!str_contains($encoded,'employeescore')&&!str_contains($encoded,'performancescore')&&!str_contains($encoded,'staffrank'),'Readiness priority must never expose employee performance scoring or ranking.');

$started=table_cleaning_start($pdo,$org,$location,$urgent['publicId'],$user);ttr((string)$started['state']==='cleaning','Urgent table must start cleaning through the canonical lifecycle.');
$pdo->prepare("UPDATE service_tables SET cleaning_started_at=DATE_SUB(NOW(6),INTERVAL 12 MINUTE) WHERE organization_id=? AND public_id=?")->execute([$org,$urgent['publicId']]);
$dash=table_turn_readiness_dashboard($pdo,$org,$location,$date,$user);$urgentRow=ttr_find($dash['readinessQueue'],$urgent['publicId']);
ttr($urgentRow!==null&&$urgentRow['resetRemainingMinutes']<=3,'Cleaning queue must reduce estimated reset time by elapsed cleaning time.');

$policy=table_turn_policy_save($pdo,$org,$location,['resetTargetMinutes'=>20,'readyBufferMinutes'=>5,'urgentThresholdMinutes'=>15],$user);
ttr($policy['resetTargetMinutes']===20&&$policy['readyBufferMinutes']===5&&$policy['urgentThresholdMinutes']===15&&$policy['configured']===true,'Saved location policy was not persisted.');
ttr(service_seatability_cleanup_minutes($pdo,$org,$location)===20,'Seatability must use the configured reset target.');
ttr(service_reservation_protection_setup_minutes($pdo,$org,$location)===5,'Reservation protection must use the configured ready buffer.');
ttr(table_turn_policy($pdo,$org,$secondLocation)['configured']===false,'Policy must remain location-scoped.');

$policyTable=service_ops_managed_table_create_safe($pdo,$org,$location,['name'=>'Policy Window','capacity'=>2,'sectionPublicId'=>$section['publicId']],$user);
$pdo->prepare("UPDATE service_tables SET state='dirty' WHERE organization_id=? AND public_id=?")->execute([$org,$policyTable['publicId']]);
$near=service_seatability_availability($pdo,$org,$location,$now->modify('+10 minutes')->format('Y-m-d H:i:s'),2,90);
$far=service_seatability_availability($pdo,$org,$location,$now->modify('+25 minutes')->format('Y-m-d H:i:s'),2,90);
ttr(!ttr_has_candidate($near,$policyTable['publicId']),'Configured 20-minute reset policy must exclude a Dirty table from near-term availability.');
ttr(ttr_has_candidate($far,$policyTable['publicId']),'Configured reset policy must allow the Dirty table beyond the reset lead window.');

// Aggregate metrics are table/location facts derived from append-only table_ready events, never employee scoring.
// Pin the fixtures by epoch so the database stores them in its own session timezone while the
// requested metrics day remains the restaurant's America/Phoenix business date.
$metricTz=service_ops_timezone($pdo,$org,$location);
table_service_event($pdo,$org,$location,(int)$overdue['id'],null,null,'table_ready','Metric fixture A.',['dirtyToReadySeconds'=>300,'cleaningSeconds'=>120],$user);$metricEventA=(int)$pdo->lastInsertId();
table_service_event($pdo,$org,$location,(int)$scheduled['id'],null,null,'table_ready','Metric fixture B.',['dirtyToReadySeconds'=>1800,'cleaningSeconds'=>600],$user);$metricEventB=(int)$pdo->lastInsertId();
table_service_event($pdo,$org,$location,(int)$scheduled['id'],null,null,'table_ready','Metric fixture next day.',['dirtyToReadySeconds'=>9999,'cleaningSeconds'=>9999],$user);$metricEventNext=(int)$pdo->lastInsertId();
$metricEpochA=(new DateTimeImmutable($date.' 23:30:00',$metricTz))->getTimestamp();
$metricEpochB=(new DateTimeImmutable($date.' 23:40:00',$metricTz))->getTimestamp();
$metricEpochNext=(new DateTimeImmutable($date.' 00:30:00',$metricTz))->modify('+1 day')->getTimestamp();
$stamp=$pdo->prepare('UPDATE service_events SET created_at=FROM_UNIXTIME(?) WHERE organization_id=? AND id=?');
$stamp->execute([$metricEpochA,$org,$metricEventA]);$stamp->execute([$metricEpochB,$org,$metricEventB]);$stamp->execute([$metricEpochNext,$org,$metricEventNext]);
$metrics=table_turn_reset_metrics($pdo,$org,$location,$date);
ttr($metrics['resetCount']===2&&$metrics['averageResetSeconds']===1050&&$metrics['medianResetSeconds']===1050,'Reset metrics must aggregate factual reset durations for the restaurant-local business date.');
ttr($metrics['withinTargetCount']===1&&abs((float)$metrics['withinTargetPercent']-50.0)<0.1,'Reset target compliance must be location aggregate only.');
ttr($metrics['averageCleaningSeconds']===360,'Cleaning duration average is incorrect.');

$table=host_table_row($pdo,$org,$location,$backlog['publicId'],false);ttr((string)$table['state']==='dirty','Backlog table fixture must still be Dirty.');
table_cleaning_start($pdo,$org,$location,$backlog['publicId'],$user);table_cleaning_mark_ready($pdo,$org,$location,$backlog['publicId'],$user);
$dash=table_turn_readiness_dashboard($pdo,$org,$location,$date,$user);ttr(ttr_find($dash['readinessQueue'],$backlog['publicId'])===null,'Table Ready must immediately remove a table from the reset queue.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Turn Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();
$isolated=false;try{table_turn_policy($pdo,$otherOrg,$location);}catch(InvalidArgumentException){$isolated=true;}ttr($isolated,'Table turn policy reads must remain organization-isolated.');

echo "table-turn-readiness-ok\n";
