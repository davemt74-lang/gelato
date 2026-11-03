<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/scheduling-core.php';
$pdo=app_pdo();$checks=0;$suffix=bin2hex(random_bytes(6));
function hsi_check(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);++$GLOBALS['checks'];}
function hsi_reject(callable $call,string $message): void{try{$call();}catch(InvalidArgumentException){hsi_check(true,$message);return;}throw new RuntimeException($message);}
function hsi_windows(PDO $pdo,int $org,int $uid,array $rows): void{
    $pdo->prepare('DELETE FROM staff_availability WHERE organization_id=? AND user_id=?')->execute([$org,$uid]);
    $q=$pdo->prepare('INSERT INTO staff_availability(organization_id,user_id,weekday,start_time,end_time,availability_type) VALUES (?,?,?,?,?,?)');
    foreach($rows as $row)$q->execute(array_merge([$org,$uid],$row));
}
function hsi_exception(PDO $pdo,int $org,int $uid,string $date,string $type,?string $start=null,?string $end=null,string $status='approved'): void{
    $pdo->prepare('INSERT INTO staff_availability_exceptions(organization_id,public_id,user_id,exception_date,start_time,end_time,exception_type,status) VALUES (?,?,?,?,?,?,?,?)')->execute([$org,'hsi-'.bin2hex(random_bytes(8)),$uid,$date,$start,$end,$type,$status]);
}
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/New_York')")->execute(['Scheduling intervals '.$suffix]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Scheduling intervals foreign '.$suffix]);$foreign=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture','Interval','Worker','Interval Worker','active')")->execute(['hsi-'.$suffix.'@example.test']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$check=static fn(string $a,string $b):array=>scheduling_availability_check($pdo,$org,$uid,$a,$b);
$clearExceptions=static fn()=>$pdo->prepare('DELETE FROM staff_availability_exceptions WHERE organization_id=? AND user_id=?')->execute([$org,$uid]);
hsi_windows($pdo,$org,$uid,[[1,'09:00:00','17:00:00','available']]);
hsi_check(!$check('2026-11-02 22:00:00','2026-11-03 02:00:00')['ok'],'A daytime window must not cover an overnight shift by comparing only HH:MM values.');
hsi_check($check('2026-11-02 10:00:00','2026-11-02 16:00:00')['ok'],'An ordinary daytime shift remains valid.');
hsi_windows($pdo,$org,$uid,[[1,'22:00:00','24:00:00','available'],[2,'00:00:00','02:00:00','available']]);
hsi_check($check('2026-11-02 22:00:00','2026-11-03 02:00:00')['ok'],'Two local availability dates may cover the complete overnight shift.');
hsi_exception($pdo,$org,$uid,'2026-11-03','time_off');
hsi_check(!$check('2026-11-02 22:00:00','2026-11-03 02:00:00')['ok'],'Approved next-day leave must block the overnight segment.');
hsi_check($check('2026-11-02 22:00:00','2026-11-03 00:00:00.000000')['ok'],'An exact midnight end must not overlap leave on the following date.');
hsi_check(!$check('2026-11-02 22:00:00.000000','2026-11-03 00:00:00.500000')['ok'],'Even a fractional-second next-day segment must respect approved leave.');
$before=(int)$pdo->query('SELECT COUNT(*) FROM schedule_shifts WHERE organization_id='.$org)->fetchColumn();
hsi_reject(fn()=>scheduling_shift_save($pdo,$org,['title'=>'Rejected overnight','startsAt'=>'2026-11-02 22:00:00','endsAt'=>'2026-11-03 02:00:00','userId'=>$uid,'enforceAvailability'=>true],$uid),'The actual native shift writer must enforce next-day leave.');
hsi_check((int)$pdo->query('SELECT COUNT(*) FROM schedule_shifts WHERE organization_id='.$org)->fetchColumn()===$before,'A rejected shift must not create a schedule row.');
$clearExceptions();hsi_exception($pdo,$org,$uid,'2026-11-03','time_off','02:00:00','05:00:00');
hsi_check($check('2026-11-02 22:00:00','2026-11-03 02:00:00')['ok'],'Time-off beginning exactly at shift end does not overlap.');
hsi_exception($pdo,$org,$uid,'2026-11-03','unavailable','01:00:00','02:00:00');
hsi_check(!$check('2026-11-02 22:00:00','2026-11-03 02:00:00')['ok'],'Partial next-day unavailability must be checked.');
$clearExceptions();hsi_exception($pdo,$org,$uid,'2026-11-03','time_off',null,null,'pending');
hsi_exception($pdo,$foreign,$uid,'2026-11-03','time_off');
hsi_check($check('2026-11-02 22:00:00','2026-11-03 02:00:00')['ok'],'Pending and foreign-tenant leave must not change this employee interval.');
$clearExceptions();hsi_windows($pdo,$org,$uid,[[1,'09:00:00','12:00:00','available'],[1,'12:00:00','17:00:00','available']]);
hsi_check($check('2026-11-02 10:00:00','2026-11-02 16:00:00')['ok'],'Adjacent windows jointly cover a shift.');
hsi_windows($pdo,$org,$uid,[[1,'09:00:00','12:00:00','available'],[1,'13:00:00','17:00:00','available']]);
hsi_check(!$check('2026-11-02 10:00:00','2026-11-02 16:00:00')['ok'],'A gap between windows must not disappear when they are combined.');
hsi_exception($pdo,$org,$uid,'2026-11-02','available','12:00:00','13:00:00');
hsi_check($check('2026-11-02 10:00:00','2026-11-02 16:00:00')['ok'],'Approved explicit availability can fill a recurring gap.');
hsi_exception($pdo,$org,$uid,'2026-11-02','time_off','12:30:00','12:45:00');
hsi_check(!$check('2026-11-02 10:00:00','2026-11-02 16:00:00')['ok'],'Approved leave retains priority over positive availability.');
$clearExceptions();hsi_windows($pdo,$org,$uid,[[1,'09:00:00','17:00:00','available'],[1,'11:00:00','12:00:00','unavailable']]);
hsi_check(!$check('2026-11-02 10:00:00','2026-11-02 16:00:00')['ok'],'Recurring unavailability must interrupt an available interval.');
hsi_exception($pdo,$org,$uid,'2026-11-02','available','11:00:00','12:00:00');
hsi_check($check('2026-11-02 10:00:00','2026-11-02 16:00:00')['ok'],'An approved dated availability exception can override its covered recurring restriction.');
$clearExceptions();hsi_windows($pdo,$org,$uid,[]);
hsi_check($check('2026-11-02 22:00:00','2026-11-03 02:00:00')['ok'],'Absence of recorded recurring windows remains unrestricted.');
hsi_reject(fn()=>$check('2026-11-02 10:00:00','2026-11-03 11:00:00'),'More than 24 actual hours must be rejected.');
hsi_reject(fn()=>$check('2026-02-30 10:00:00','2026-03-03 11:00:00'),'Invalid calendar dates must not roll forward silently.');
hsi_reject(fn()=>$check('2026-11-02 10:00:00','2026-11-02 10:00:00'),'Empty intervals must be rejected.');
$zone=new DateTimeZone('America/New_York');
hsi_reject(fn()=>scheduling_shift_datetime('2026-03-08 02:30:00',$zone),'A nonexistent spring-forward boundary must be reviewed.');
hsi_reject(fn()=>scheduling_shift_datetime('2026-11-01 01:30:00',$zone),'A repeated fall-back boundary cannot be persisted ambiguously.');
hsi_windows($pdo,$org,$uid,[[0,'00:00:00','04:00:00','available']]);
hsi_check($check('2026-03-08 00:00:00','2026-03-08 04:00:00')['ok'],'An unambiguous shift can span the spring clock change.');
[$a,$b]=scheduling_shift_interval($pdo,$org,'2026-03-08 00:00:00','2026-03-08 04:00:00');
hsi_check($b->getTimestamp()-$a->getTimestamp()===3*3600,'Spring-forward duration must use actual restaurant-local elapsed time.');
hsi_check($check('2026-11-01 00:00:00','2026-11-01 03:00:00')['ok'],'An unambiguous shift can span both repeated hours.');
[$a,$b]=scheduling_shift_interval($pdo,$org,'2026-11-01 00:00:00','2026-11-01 03:00:00');
hsi_check($b->getTimestamp()-$a->getTimestamp()===4*3600,'Fall-back duration must include the repeated hour.');
hsi_check($check('2026-03-08T05:00:00Z','2026-03-08T08:00:00Z')['ok'],'Explicit offset inputs must be converted into the restaurant timezone before date checks.');
$shift=scheduling_shift_save($pdo,$org,['title'=>'DST integration','startsAt'=>'2026-03-08T05:00:00Z','endsAt'=>'2026-03-08T08:00:00Z','userId'=>$uid,'enforceAvailability'=>true],$uid);
hsi_check(substr($shift['starts_at'],0,19)==='2026-03-08 00:00:00'&&substr($shift['ends_at'],0,19)==='2026-03-08 04:00:00','Native shift storage must contain the validated restaurant-local values.');
// A wall-clock gap occurs twice during fall-back; taking the outermost
// candidate endpoints would incorrectly fill both copies of that gap.
hsi_windows($pdo,$org,$uid,[[0,'00:00:00','01:15:00','available'],[0,'01:45:00','03:00:00','available']]);
hsi_check(!$check('2026-11-01 00:00:00','2026-11-01 03:00:00')['ok'],'Repeated-hour availability must preserve a genuine gap between positive windows.');
$pieces=scheduling_window_intervals('2026-11-01','01:15:00','01:45:00',$zone);
hsi_check(count($pieces)===2&&$pieces[0][1]-$pieces[0][0]===1800&&$pieces[1][1]-$pieces[1][0]===1800&&$pieces[0][1]<$pieces[1][0],'A repeated half-hour window must have two separate real-time pieces.');
hsi_windows($pdo,$org,$uid,[[0,'00:00:00','03:00:00','available']]);
hsi_check($check('2026-11-01 00:00:00','2026-11-01 03:00:00')['ok'],'A full available window covers both repeated hours without creating a false gap.');
hsi_windows($pdo,$org,$uid,[[0,'00:00:00','03:00:00','available'],[0,'01:15:00','01:45:00','unavailable']]);
hsi_check(!$check('2026-11-01 00:00:00','2026-11-01 03:00:00')['ok'],'A repeated unavailable window blocks its real pieces.');
hsi_exception($pdo,$org,$uid,'2026-11-01','available','01:15:00','01:45:00');
hsi_check($check('2026-11-01 00:00:00','2026-11-01 03:00:00')['ok'],'A matching dated override covers both repeated pieces of recurring unavailability.');
hsi_exception($pdo,$org,$uid,'2026-11-01','time_off','01:20:00','01:25:00');
hsi_check(!$check('2026-11-01 00:00:00','2026-11-01 03:00:00')['ok'],'Repeated-hour approved leave retains priority over matching positive exceptions.');
$clearExceptions();hsi_windows($pdo,$org,$uid,[[0,'00:00:00','03:00:00','unavailable']]);
hsi_exception($pdo,$org,$uid,'2026-11-01','available','00:00:00','01:15:00');hsi_exception($pdo,$org,$uid,'2026-11-01','available','01:45:00','03:00:00');
hsi_check(!$check('2026-11-01 00:00:00','2026-11-01 03:00:00')['ok'],'Dated positive exceptions must not join across a repeated-hour gap.');
$clearExceptions();hsi_windows($pdo,$org,$uid,[[0,'02:00:00','03:00:00','available']]);
hsi_check(!$check('2026-03-08 00:00:00','2026-03-08 04:00:00')['ok'],'Availability confined to a nonexistent spring hour must not become unrestricted availability.');
hsi_windows($pdo,$org,$uid,[[0,'01:00:00','02:30:00','available'],[0,'02:30:00','04:00:00','available']]);
hsi_check($check('2026-03-08 01:00:00','2026-03-08 04:00:00')['ok'],'Missing spring wall-clock pieces disappear without adding an artificial real-time gap.');
hsi_windows($pdo,$org,$uid,[[0,'00:00:00','04:00:00','available'],[0,'02:00:00','03:00:00','unavailable']]);
hsi_check($check('2026-03-08 00:00:00','2026-03-08 04:00:00')['ok'],'Unavailability confined to a nonexistent hour blocks no real instant.');
$pdo->prepare('UPDATE organizations SET timezone=? WHERE id=?')->execute(['America/Sao_Paulo',$org]);
hsi_windows($pdo,$org,$uid,[]);hsi_exception($pdo,$org,$uid,'2018-11-05','time_off');
hsi_check(!$check('2018-11-04 23:30:00','2018-11-05 00:30:00')['ok'],'A missing local midnight must not skip early next-day approved leave.');
$clearExceptions();$pdo->prepare('UPDATE organizations SET timezone=? WHERE id=?')->execute(['America/New_York',$org]);
hsi_windows($pdo,$org,$uid,[[0,'00:00:00','04:00:00','available']]);
hsi_reject(fn()=>scheduling_availability_replace($pdo,$org,$uid,[['weekday'=>0,'start'=>'25:00','end'=>'26:00']],$uid),'Invalid availability input must not erase the saved schedule.');
hsi_check(count(scheduling_availability($pdo,$org,$uid))===1,'Rejected replacement must preserve original availability.');
echo 'Scheduling full intervals: '.$checks." checks passed.\n";
