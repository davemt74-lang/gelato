<?php
declare(strict_types=1);

function scheduling_core_ready(PDO $pdo): bool {
    try {
        return (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schedule_shifts' LIMIT 1")->fetchColumn();
    } catch (Throwable) { return false; }
}

function scheduling_public_id(string $prefix): string {
    return $prefix . '-' . bin2hex(random_bytes(8));
}

function scheduling_week_start(string $value=''): string {
    try { $d=new DateTimeImmutable($value!==''?$value:'today'); } catch (Throwable) { $d=new DateTimeImmutable('today'); }
    $weekday=(int)$d->format('N');
    if($weekday!==1)$d=$d->modify('-'.($weekday-1).' days');
    return $d->format('Y-m-d');
}

function scheduling_week_end(string $weekStart): string {
    return (new DateTimeImmutable(scheduling_week_start($weekStart)))->modify('+7 days')->format('Y-m-d');
}

function scheduling_ensure_week(PDO $pdo,int $organizationId,string $weekStart,int $userId): array {
    $weekStart=scheduling_week_start($weekStart);
    $stmt=$pdo->prepare('SELECT * FROM schedule_weeks WHERE organization_id=? AND week_start=? LIMIT 1');
    $stmt->execute([$organizationId,$weekStart]);$row=$stmt->fetch();if($row)return $row;
    $pdo->prepare("INSERT INTO schedule_weeks (organization_id,week_start,status,created_by,updated_by) VALUES (?,?,'draft',?,?)")->execute([$organizationId,$weekStart,$userId,$userId]);
    $stmt->execute([$organizationId,$weekStart]);return $stmt->fetch()?:[];
}

function scheduling_staff(PDO $pdo,int $organizationId): array {
    $sql="SELECT u.id user_id,u.email,u.first_name,u.last_name,u.display_name,u.phone,om.id membership_id,om.employee_number,om.job_title,om.hire_date,om.primary_location_id,
        sp.preferred_name,sp.employment_type,sp.min_weekly_hours,sp.preferred_weekly_hours,sp.max_weekly_hours,sp.hourly_labor_cost,sp.availability_note,sp.skills_json,sp.scheduling_notes,
        GROUP_CONCAT(DISTINCT p.name ORDER BY up.is_primary DESC,p.name SEPARATOR ', ') positions
      FROM users u
      INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active'
      LEFT JOIN staff_profiles sp ON sp.organization_id=om.organization_id AND sp.user_id=u.id
      LEFT JOIN user_positions up ON up.membership_id=om.id AND up.status='active' AND up.ended_at IS NULL
      LEFT JOIN positions p ON p.id=up.position_id AND p.status='active'
      WHERE u.status='active' AND u.archived_at IS NULL AND COALESCE(om.job_title,'')<>'Wholesale Customer'
        AND NOT EXISTS (SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id WHERE ur.membership_id=om.id AND ur.revoked_at IS NULL AND r.slug='wholesale_customer')
      GROUP BY u.id,u.email,u.first_name,u.last_name,u.display_name,u.phone,om.id,om.employee_number,om.job_title,om.hire_date,om.primary_location_id,
        sp.preferred_name,sp.employment_type,sp.min_weekly_hours,sp.preferred_weekly_hours,sp.max_weekly_hours,sp.hourly_labor_cost,sp.availability_note,sp.skills_json,sp.scheduling_notes
      ORDER BY COALESCE(sp.preferred_name,u.display_name),u.display_name";
    $stmt=$pdo->prepare($sql);$stmt->execute([$organizationId]);return $stmt->fetchAll();
}

function scheduling_staff_user(PDO $pdo,int $organizationId,int $userId): ?array {
    foreach(scheduling_staff($pdo,$organizationId) as $row)if((int)$row['user_id']===$userId)return $row;
    return null;
}

function scheduling_positions(PDO $pdo,int $organizationId): array {
    $stmt=$pdo->prepare("SELECT id,name,slug,description FROM positions WHERE organization_id=? AND status='active' ORDER BY name");$stmt->execute([$organizationId]);return $stmt->fetchAll();
}
function scheduling_locations(PDO $pdo,int $organizationId): array {
    $stmt=$pdo->prepare("SELECT id,name,address_line_1,city,state FROM locations WHERE organization_id=? AND status='active' ORDER BY name");$stmt->execute([$organizationId]);return $stmt->fetchAll();
}

function scheduling_profile_save(PDO $pdo,int $organizationId,int $targetUserId,array $input,int $actorUserId): array {
    if(!scheduling_staff_user($pdo,$organizationId,$targetUserId))throw new RuntimeException('Staff member not found.');
    $employment=(string)($input['employmentType']??'hourly');if(!in_array($employment,['hourly','salary','contract','seasonal'],true))$employment='hourly';
    $number=function(string $key)use($input):?float{$v=$input[$key]??null;return $v===''||$v===null?null:(is_numeric($v)?max(0,(float)$v):null);};
    $preferred=mb_substr(trim((string)($input['preferredName']??'')),0,160,'UTF-8')?:null;
    $availabilityNote=mb_substr(trim((string)($input['availabilityNote']??'')),0,1000,'UTF-8')?:null;
    $schedulingNotes=mb_substr(trim((string)($input['schedulingNotes']??'')),0,5000,'UTF-8')?:null;
    $skills=array_values(array_filter(array_map(fn($x)=>mb_substr(trim((string)$x),0,120,'UTF-8'),(array)($input['skills']??[]))));
    $sql="INSERT INTO staff_profiles (organization_id,user_id,preferred_name,employment_type,min_weekly_hours,preferred_weekly_hours,max_weekly_hours,hourly_labor_cost,availability_note,skills_json,scheduling_notes,created_by,updated_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE preferred_name=VALUES(preferred_name),employment_type=VALUES(employment_type),min_weekly_hours=VALUES(min_weekly_hours),preferred_weekly_hours=VALUES(preferred_weekly_hours),max_weekly_hours=VALUES(max_weekly_hours),hourly_labor_cost=VALUES(hourly_labor_cost),availability_note=VALUES(availability_note),skills_json=VALUES(skills_json),scheduling_notes=VALUES(scheduling_notes),updated_by=VALUES(updated_by),updated_at=NOW(6)";
    $pdo->prepare($sql)->execute([$organizationId,$targetUserId,$preferred,$employment,$number('minWeeklyHours'),$number('preferredWeeklyHours'),$number('maxWeeklyHours'),$number('hourlyLaborCost'),$availabilityNote,$skills?json_encode($skills,JSON_UNESCAPED_UNICODE):null,$schedulingNotes,$actorUserId,$actorUserId]);
    return scheduling_staff_user($pdo,$organizationId,$targetUserId)?:[];
}

function scheduling_availability(PDO $pdo,int $organizationId,int $userId): array {
    $stmt=$pdo->prepare('SELECT id,weekday,start_time,end_time,availability_type FROM staff_availability WHERE organization_id=? AND user_id=? ORDER BY weekday,start_time');$stmt->execute([$organizationId,$userId]);return $stmt->fetchAll();
}
function scheduling_availability_replace(PDO $pdo,int $organizationId,int $userId,array $windows,int $actorUserId): array {
    if(!scheduling_staff_user($pdo,$organizationId,$userId))throw new RuntimeException('Staff member not found.');
    $clean=[];
    foreach($windows as $w){$day=(int)($w['weekday']??-1);$start=trim((string)($w['start']??''));$end=trim((string)($w['end']??''));$type=(string)($w['type']??'available');if($day<0||$day>6||!preg_match('/^\d{2}:\d{2}$/',$start)||!preg_match('/^\d{2}:\d{2}$/',$end)||$end<=$start)continue;if(!in_array($type,['available','unavailable'],true))$type='available';$clean[]=['weekday'=>$day,'start'=>$start.':00','end'=>$end.':00','type'=>$type];}
    $pdo->beginTransaction();try{$pdo->prepare('DELETE FROM staff_availability WHERE organization_id=? AND user_id=?')->execute([$organizationId,$userId]);$ins=$pdo->prepare('INSERT INTO staff_availability (organization_id,user_id,weekday,start_time,end_time,availability_type,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?)');foreach($clean as $w)$ins->execute([$organizationId,$userId,$w['weekday'],$w['start'],$w['end'],$w['type'],$actorUserId,$actorUserId]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}
    return scheduling_availability($pdo,$organizationId,$userId);
}

function scheduling_exception_create(PDO $pdo,int $organizationId,int $userId,array $input,int $actorUserId,bool $manager): array {
    if(!scheduling_staff_user($pdo,$organizationId,$userId))throw new RuntimeException('Staff member not found.');
    $date=trim((string)($input['date']??''));$dt=DateTimeImmutable::createFromFormat('Y-m-d',$date);if(!$dt||$dt->format('Y-m-d')!==$date)throw new InvalidArgumentException('A valid exception date is required.');
    $start=trim((string)($input['start']??''));$end=trim((string)($input['end']??''));if(($start!==''||$end!=='')&&(!preg_match('/^\d{2}:\d{2}$/',$start)||!preg_match('/^\d{2}:\d{2}$/',$end)||$end<=$start))throw new InvalidArgumentException('Exception times are invalid.');
    $type=(string)($input['type']??'time_off');if(!in_array($type,['time_off','unavailable','available'],true))$type='time_off';$status=$manager?'approved':'pending';$public=scheduling_public_id('availability');
    $pdo->prepare('INSERT INTO staff_availability_exceptions (organization_id,public_id,user_id,exception_date,start_time,end_time,exception_type,status,note,requested_by,reviewed_by,reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$organizationId,$public,$userId,$date,$start!==''?$start.':00':null,$end!==''?$end.':00':null,$type,$status,mb_substr(trim((string)($input['note']??'')),0,1000,'UTF-8')?:null,$actorUserId,$manager?$actorUserId:null,$manager?date('Y-m-d H:i:s'):null]);
    $stmt=$pdo->prepare('SELECT * FROM staff_availability_exceptions WHERE organization_id=? AND public_id=?');$stmt->execute([$organizationId,$public]);return $stmt->fetch()?:[];
}

function scheduling_exceptions(PDO $pdo,int $organizationId,?int $userId=null,string $status=''): array {
    $sql="SELECT e.*,u.display_name FROM staff_availability_exceptions e INNER JOIN users u ON u.id=e.user_id WHERE e.organization_id=?";$args=[$organizationId];if($userId!==null){$sql.=' AND e.user_id=?';$args[]=$userId;}if($status!==''){$sql.=' AND e.status=?';$args[]=$status;}$sql.=' ORDER BY e.exception_date DESC,e.created_at DESC LIMIT 300';$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll();
}

function scheduling_shift_by_public_id(PDO $pdo,int $organizationId,string $publicId): ?array {
    $stmt=$pdo->prepare("SELECT s.*,u.display_name,p.name position_name,l.name location_name,w.week_start,w.status week_status FROM schedule_shifts s INNER JOIN schedule_weeks w ON w.id=s.schedule_week_id LEFT JOIN users u ON u.id=s.user_id LEFT JOIN positions p ON p.id=s.position_id LEFT JOIN locations l ON l.id=s.location_id WHERE s.organization_id=? AND s.public_id=? AND s.archived_at IS NULL LIMIT 1");$stmt->execute([$organizationId,$publicId]);$r=$stmt->fetch();return $r?:null;
}

function scheduling_shift_conflicts(PDO $pdo,int $organizationId,int $userId,string $start,string $end,?int $excludeId=null): array {
    $sql="SELECT public_id,title,starts_at,ends_at FROM schedule_shifts WHERE organization_id=? AND user_id=? AND archived_at IS NULL AND status<>'cancelled' AND starts_at<? AND ends_at>?";$args=[$organizationId,$userId,$end,$start];if($excludeId!==null){$sql.=' AND id<>?';$args[]=$excludeId;}$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll();
}

function scheduling_availability_check(PDO $pdo,int $organizationId,int $userId,string $start,string $end): array {
    $s=new DateTimeImmutable($start);$e=new DateTimeImmutable($end);$date=$s->format('Y-m-d');$day=(int)$s->format('w');$startTime=$s->format('H:i:s');$endTime=$e->format('H:i:s');
    $stmt=$pdo->prepare("SELECT * FROM staff_availability_exceptions WHERE organization_id=? AND user_id=? AND exception_date=? AND status='approved' ORDER BY created_at DESC");$stmt->execute([$organizationId,$userId,$date]);$exceptions=$stmt->fetchAll();
    foreach($exceptions as $x){$xs=$x['start_time']?:'00:00:00';$xe=$x['end_time']?:'23:59:59';if($xs<$endTime&&$xe>$startTime&&in_array($x['exception_type'],['time_off','unavailable'],true))return ['ok'=>false,'reason'=>'Approved time off/unavailability overlaps this shift.'];}
    foreach($exceptions as $x){$xs=$x['start_time']?:'00:00:00';$xe=$x['end_time']?:'23:59:59';if($x['exception_type']==='available'&&$xs<=$startTime&&$xe>=$endTime)return ['ok'=>true,'reason'=>'Explicit availability exception covers this shift.'];}
    $stmt=$pdo->prepare('SELECT start_time,end_time,availability_type FROM staff_availability WHERE organization_id=? AND user_id=? AND weekday=? ORDER BY start_time');$stmt->execute([$organizationId,$userId,$day]);$rows=$stmt->fetchAll();if(!$rows)return ['ok'=>true,'reason'=>'No recurring availability is recorded for this day.'];
    foreach($rows as $r)if($r['availability_type']==='unavailable'&&$r['start_time']<$endTime&&$r['end_time']>$startTime)return ['ok'=>false,'reason'=>'Recurring unavailability overlaps this shift.'];
    $available=array_values(array_filter($rows,fn($r)=>$r['availability_type']==='available'));if(!$available)return ['ok'=>true,'reason'=>'No recurring available window is recorded.'];
    foreach($available as $r)if($r['start_time']<=$startTime&&$r['end_time']>=$endTime)return ['ok'=>true,'reason'=>'Shift is inside recurring availability.'];
    return ['ok'=>false,'reason'=>'Shift is outside the employee recurring availability.'];
}

function scheduling_shift_event(PDO $pdo,int $organizationId,?int $shiftId,string $type,string $summary,?int $actorUserId,array $meta=[]): void {
    $pdo->prepare('INSERT INTO schedule_events (organization_id,shift_id,event_type,summary,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?)')->execute([$organizationId,$shiftId,$type,mb_substr($summary,0,500,'UTF-8'),$meta?json_encode($meta,JSON_UNESCAPED_UNICODE):null,$actorUserId]);
}

function scheduling_shift_save(PDO $pdo,int $organizationId,array $input,int $actorUserId,?array $existing=null): array {
    $start=trim((string)($input['startsAt']??($existing['starts_at']??'')));$end=trim((string)($input['endsAt']??($existing['ends_at']??'')));try{$s=new DateTimeImmutable($start);$e=new DateTimeImmutable($end);}catch(Throwable){throw new InvalidArgumentException('Valid shift start and end times are required.');}if($e<=$s)throw new InvalidArgumentException('Shift end must be after shift start.');if($s->format('Y-m-d')!==$e->format('Y-m-d')&&($e->getTimestamp()-$s->getTimestamp())>86400)throw new InvalidArgumentException('Shift length cannot exceed 24 hours.');
    $userId=isset($input['userId'])&&$input['userId']!==''?(int)$input['userId']:(isset($existing['user_id'])?(int)$existing['user_id']:0);if($userId&&!scheduling_staff_user($pdo,$organizationId,$userId))throw new InvalidArgumentException('Assigned employee is not active restaurant staff.');
    if($userId){$conflicts=scheduling_shift_conflicts($pdo,$organizationId,$userId,$s->format('Y-m-d H:i:s'),$e->format('Y-m-d H:i:s'),$existing?(int)$existing['id']:null);if($conflicts)throw new InvalidArgumentException('Employee already has an overlapping shift.');$availability=scheduling_availability_check($pdo,$organizationId,$userId,$s->format('Y-m-d H:i:s'),$e->format('Y-m-d H:i:s'));if(!$availability['ok']&&!empty($input['enforceAvailability']))throw new InvalidArgumentException($availability['reason']);}
    $week=scheduling_ensure_week($pdo,$organizationId,$s->format('Y-m-d'),$actorUserId);$positionId=($input['positionId']??'')!==''?(int)$input['positionId']:null;$locationId=($input['locationId']??'')!==''?(int)$input['locationId']:null;
    if($positionId!==null){$stmt=$pdo->prepare("SELECT COUNT(*) FROM positions WHERE id=? AND organization_id=? AND status='active'");$stmt->execute([$positionId,$organizationId]);if(!(int)$stmt->fetchColumn())throw new InvalidArgumentException('Position is not valid for this restaurant.');}
    if($locationId!==null){$stmt=$pdo->prepare("SELECT COUNT(*) FROM locations WHERE id=? AND organization_id=? AND status='active'");$stmt->execute([$locationId,$organizationId]);if(!(int)$stmt->fetchColumn())throw new InvalidArgumentException('Location is not valid for this restaurant.');}
    $title=mb_substr(trim((string)($input['title']??($existing['title']??'Shift'))),0,180,'UTF-8');if($title==='')$title='Shift';$break=max(0,min(240,(int)($input['breakMinutes']??($existing['break_minutes']??0))));$status=(string)($input['status']??($existing['status']??($userId?'scheduled':'open')));if(!in_array($status,['scheduled','open','cancelled','completed'],true))$status=$userId?'scheduled':'open';if(!$userId&&$status==='scheduled')$status='open';$notes=mb_substr(trim((string)($input['notes']??($existing['notes']??''))),0,1000,'UTF-8')?:null;
    if($existing){$pdo->prepare('UPDATE schedule_shifts SET schedule_week_id=?,location_id=?,position_id=?,user_id=?,title=?,starts_at=?,ends_at=?,break_minutes=?,status=?,notes=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([(int)$week['id'],$locationId,$positionId,$userId?:null,$title,$s->format('Y-m-d H:i:s'),$e->format('Y-m-d H:i:s'),$break,$status,$notes,$actorUserId,(int)$existing['id'],$organizationId]);$public=(string)$existing['public_id'];$event='shift.updated';$shiftId=(int)$existing['id'];}
    else{$public=scheduling_public_id('shift');$pdo->prepare('INSERT INTO schedule_shifts (organization_id,public_id,schedule_week_id,location_id,position_id,user_id,title,starts_at,ends_at,break_minutes,status,notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$organizationId,$public,(int)$week['id'],$locationId,$positionId,$userId?:null,$title,$s->format('Y-m-d H:i:s'),$e->format('Y-m-d H:i:s'),$break,$status,$notes,$actorUserId,$actorUserId]);$shiftId=(int)$pdo->lastInsertId();$event='shift.created';}
    scheduling_shift_event($pdo,$organizationId,$shiftId,$event,$title,$actorUserId,['userId'=>$userId?:null,'startsAt'=>$s->format(DATE_ATOM),'endsAt'=>$e->format(DATE_ATOM)]);return scheduling_shift_by_public_id($pdo,$organizationId,$public)?:[];
}

function scheduling_shifts(PDO $pdo,int $organizationId,string $weekStart,?int $onlyUserId=null): array {
    $start=scheduling_week_start($weekStart);$end=scheduling_week_end($start);$sql="SELECT s.*,u.display_name,p.name position_name,l.name location_name FROM schedule_shifts s LEFT JOIN users u ON u.id=s.user_id LEFT JOIN positions p ON p.id=s.position_id LEFT JOIN locations l ON l.id=s.location_id WHERE s.organization_id=? AND s.archived_at IS NULL AND s.starts_at>=? AND s.starts_at<?";$args=[$organizationId,$start.' 00:00:00',$end.' 00:00:00'];if($onlyUserId!==null){$sql.=' AND s.user_id=?';$args[]=$onlyUserId;}$sql.=' ORDER BY s.starts_at,s.title';$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll();
}

function scheduling_requests(PDO $pdo,int $organizationId,?int $userId=null): array {
    $sql="SELECT r.*,s.public_id shift_public_id,s.title shift_title,s.starts_at,s.ends_at,ru.display_name requester_name,tu.display_name target_name,ps.public_id proposed_shift_public_id,ps.title proposed_shift_title FROM schedule_shift_requests r INNER JOIN schedule_shifts s ON s.id=r.shift_id INNER JOIN users ru ON ru.id=r.requester_user_id LEFT JOIN users tu ON tu.id=r.target_user_id LEFT JOIN schedule_shifts ps ON ps.id=r.proposed_shift_id WHERE r.organization_id=?";$args=[$organizationId];if($userId!==null){$sql.=' AND (r.requester_user_id=? OR r.target_user_id=?)';$args[]=$userId;$args[]=$userId;}$sql.=' ORDER BY r.status="pending" DESC,r.created_at DESC LIMIT 300';$stmt=$pdo->prepare($sql);$stmt->execute($args);return $stmt->fetchAll();
}

function scheduling_request_create(PDO $pdo,int $organizationId,int $requesterId,array $input): array {
    $shift=scheduling_shift_by_public_id($pdo,$organizationId,trim((string)($input['shiftId']??'')));if(!$shift||((int)($shift['user_id']??0)!==$requesterId))throw new InvalidArgumentException('You can only request a change to your assigned shift.');$type=(string)($input['type']??'release');if(!in_array($type,['release','swap'],true))throw new InvalidArgumentException('Unsupported shift request type.');$target=($input['targetUserId']??'')!==''?(int)$input['targetUserId']:null;if($target!==null&&!scheduling_staff_user($pdo,$organizationId,$target))throw new InvalidArgumentException('Swap target is not active restaurant staff.');$proposed=null;if(($input['proposedShiftId']??'')!==''){$proposed=scheduling_shift_by_public_id($pdo,$organizationId,(string)$input['proposedShiftId']);if(!$proposed)throw new InvalidArgumentException('Proposed shift was not found.');if($target!==null&&(int)($proposed['user_id']??0)!==$target)throw new InvalidArgumentException('Proposed shift is not assigned to the selected employee.');}
    $dupe=$pdo->prepare("SELECT COUNT(*) FROM schedule_shift_requests WHERE organization_id=? AND shift_id=? AND requester_user_id=? AND status='pending'");$dupe->execute([$organizationId,(int)$shift['id'],$requesterId]);if((int)$dupe->fetchColumn())throw new InvalidArgumentException('A pending request already exists for this shift.');$public=scheduling_public_id('shift-request');$pdo->prepare('INSERT INTO schedule_shift_requests (organization_id,public_id,shift_id,requester_user_id,request_type,target_user_id,proposed_shift_id,note) VALUES (?,?,?,?,?,?,?,?)')->execute([$organizationId,$public,(int)$shift['id'],$requesterId,$type,$target,$proposed?(int)$proposed['id']:null,mb_substr(trim((string)($input['note']??'')),0,1000,'UTF-8')?:null]);scheduling_shift_event($pdo,$organizationId,(int)$shift['id'],'shift.requested',ucfirst($type).' request submitted',$requesterId,['requestId'=>$public]);$stmt=$pdo->prepare('SELECT * FROM schedule_shift_requests WHERE organization_id=? AND public_id=?');$stmt->execute([$organizationId,$public]);return $stmt->fetch()?:[];
}

function scheduling_request_review(PDO $pdo,int $organizationId,string $publicId,string $decision,int $managerId,string $note=''): array {
    $stmt=$pdo->prepare("SELECT * FROM schedule_shift_requests WHERE organization_id=? AND public_id=? AND status='pending' LIMIT 1");$stmt->execute([$organizationId,$publicId]);$r=$stmt->fetch();if(!$r)throw new InvalidArgumentException('Pending shift request not found.');if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Decision must be approved or rejected.');$shift=scheduling_shift_by_public_id($pdo,$organizationId,(string)$pdo->query('SELECT public_id FROM schedule_shifts WHERE id='.(int)$r['shift_id'])->fetchColumn());if(!$shift)throw new InvalidArgumentException('Shift not found.');
    $pdo->beginTransaction();try{if($decision==='approved'){
        if($r['request_type']==='release'){$pdo->prepare("UPDATE schedule_shifts SET user_id=NULL,status='open',updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$managerId,(int)$r['shift_id'],$organizationId]);}
        elseif($r['request_type']==='swap'){$target=$r['target_user_id']?(int)$r['target_user_id']:0;if(!$target)throw new InvalidArgumentException('Swap request has no target employee.');$s=scheduling_shift_by_public_id($pdo,$organizationId,(string)$shift['public_id']);$check=scheduling_availability_check($pdo,$organizationId,$target,(string)$s['starts_at'],(string)$s['ends_at']);if(!$check['ok'])throw new InvalidArgumentException('Swap target is unavailable: '.$check['reason']);$conf=scheduling_shift_conflicts($pdo,$organizationId,$target,(string)$s['starts_at'],(string)$s['ends_at'],(int)$s['id']);if($r['proposed_shift_id'])$conf=array_values(array_filter($conf,fn($x)=>(string)$x['public_id']!==(string)$pdo->query('SELECT public_id FROM schedule_shifts WHERE id='.(int)$r['proposed_shift_id'])->fetchColumn()));if($conf)throw new InvalidArgumentException('Swap target has an overlapping shift.');if($r['proposed_shift_id']){$ps=$pdo->prepare('SELECT * FROM schedule_shifts WHERE id=? AND organization_id=? AND archived_at IS NULL');$ps->execute([(int)$r['proposed_shift_id'],$organizationId]);$other=$ps->fetch();if(!$other||(int)($other['user_id']??0)!==$target)throw new InvalidArgumentException('Proposed swap shift is no longer assigned to the target employee.');$original=(int)$r['requester_user_id'];$a=scheduling_availability_check($pdo,$organizationId,$original,(string)$other['starts_at'],(string)$other['ends_at']);if(!$a['ok'])throw new InvalidArgumentException('Requester is unavailable for the proposed shift: '.$a['reason']);$c=scheduling_shift_conflicts($pdo,$organizationId,$original,(string)$other['starts_at'],(string)$other['ends_at'],(int)$other['id']);$c=array_values(array_filter($c,fn($x)=>(string)$x['public_id']!==(string)$shift['public_id']));if($c)throw new InvalidArgumentException('Requester has an overlapping shift during the proposed swap.');$pdo->prepare("UPDATE schedule_shifts SET user_id=?,status='scheduled',updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$target,$managerId,(int)$r['shift_id'],$organizationId]);$pdo->prepare("UPDATE schedule_shifts SET user_id=?,status='scheduled',updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$original,$managerId,(int)$other['id'],$organizationId]);}else{$pdo->prepare("UPDATE schedule_shifts SET user_id=?,status='scheduled',updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$target,$managerId,(int)$r['shift_id'],$organizationId]);}}
    }
    $pdo->prepare('UPDATE schedule_shift_requests SET status=?,reviewed_by=?,reviewed_at=NOW(6),manager_note=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$decision,$managerId,mb_substr(trim($note),0,1000,'UTF-8')?:null,(int)$r['id'],$organizationId]);scheduling_shift_event($pdo,$organizationId,(int)$r['shift_id'],'shift.request_reviewed','Shift request '.$decision,$managerId,['requestId'=>$publicId]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}
    $stmt=$pdo->prepare('SELECT * FROM schedule_shift_requests WHERE organization_id=? AND public_id=?');$stmt->execute([$organizationId,$publicId]);return $stmt->fetch()?:[];
}

function scheduling_coverage_rules(PDO $pdo,int $organizationId): array {$stmt=$pdo->prepare("SELECT r.*,p.name position_name,l.name location_name FROM labor_coverage_rules r LEFT JOIN positions p ON p.id=r.position_id LEFT JOIN locations l ON l.id=r.location_id WHERE r.organization_id=? AND r.status='active' ORDER BY r.weekday,r.start_time");$stmt->execute([$organizationId]);return $stmt->fetchAll();}
function scheduling_coverage_rule_save(PDO $pdo,int $organizationId,array $input,int $actorUserId): array {
    $id=(int)($input['id']??0);$weekday=(int)($input['weekday']??-1);$start=trim((string)($input['start']??''));$end=trim((string)($input['end']??''));if($weekday<0||$weekday>6||!preg_match('/^\d{2}:\d{2}$/',$start)||!preg_match('/^\d{2}:\d{2}$/',$end)||$end<=$start)throw new InvalidArgumentException('Coverage day/time is invalid.');$min=max(0,(int)($input['minimumStaff']??1));$target=max($min,(int)($input['targetStaff']??$min));$position=($input['positionId']??'')!==''?(int)$input['positionId']:null;$location=($input['locationId']??'')!==''?(int)$input['locationId']:null;$label=mb_substr(trim((string)($input['label']??'')),0,160,'UTF-8')?:null;
    if($id){$pdo->prepare('UPDATE labor_coverage_rules SET location_id=?,position_id=?,weekday=?,start_time=?,end_time=?,minimum_staff=?,target_staff=?,label=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$location,$position,$weekday,$start.':00',$end.':00',$min,$target,$label,$actorUserId,$id,$organizationId]);}
    else{$pdo->prepare('INSERT INTO labor_coverage_rules (organization_id,location_id,position_id,weekday,start_time,end_time,minimum_staff,target_staff,label,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$organizationId,$location,$position,$weekday,$start.':00',$end.':00',$min,$target,$label,$actorUserId,$actorUserId]);$id=(int)$pdo->lastInsertId();}
    $stmt=$pdo->prepare('SELECT * FROM labor_coverage_rules WHERE id=? AND organization_id=?');$stmt->execute([$id,$organizationId]);return $stmt->fetch()?:[];
}

function scheduling_workload(PDO $pdo,int $organizationId,string $weekStart): array {
    $start=scheduling_week_start($weekStart);$end=scheduling_week_end($start);$labor=[];$stmt=$pdo->prepare("SELECT DATE(starts_at) d,SUM(GREATEST(0,TIMESTAMPDIFF(MINUTE,starts_at,ends_at)-break_minutes)) minutes,COUNT(*) shifts,SUM(user_id IS NULL) open_shifts FROM schedule_shifts WHERE organization_id=? AND archived_at IS NULL AND status<>'cancelled' AND starts_at>=? AND starts_at<? GROUP BY DATE(starts_at)");$stmt->execute([$organizationId,$start.' 00:00:00',$end.' 00:00:00']);foreach($stmt->fetchAll() as $r)$labor[$r['d']]=$r;
    $tasks=[];try{$stmt=$pdo->prepare("SELECT DATE(due_at) d,COUNT(*) tasks,SUM(COALESCE(estimated_minutes,0)) minutes FROM restaurant_tasks WHERE organization_id=? AND archived_at IS NULL AND status NOT IN ('completed','verified') AND due_at>=? AND due_at<? GROUP BY DATE(due_at)");$stmt->execute([$organizationId,$start.' 00:00:00',$end.' 00:00:00']);foreach($stmt->fetchAll() as $r)$tasks[$r['d']]=$r;}catch(Throwable){}
    $days=[];$cursor=new DateTimeImmutable($start);for($i=0;$i<7;$i++,$cursor=$cursor->modify('+1 day')){$d=$cursor->format('Y-m-d');$lm=(int)($labor[$d]['minutes']??0);$tm=(int)($tasks[$d]['minutes']??0);$days[]=['date'=>$d,'scheduledMinutes'=>$lm,'scheduledHours'=>round($lm/60,2),'shiftCount'=>(int)($labor[$d]['shifts']??0),'openShifts'=>(int)($labor[$d]['open_shifts']??0),'taskMinutes'=>$tm,'taskHours'=>round($tm/60,2),'taskCount'=>(int)($tasks[$d]['tasks']??0),'workloadRatio'=>$lm>0?round($tm/$lm,3):($tm>0?999:0)];}
    return $days;
}

function scheduling_coverage(PDO $pdo,int $organizationId,string $weekStart): array {
    $rules=scheduling_coverage_rules($pdo,$organizationId);$start=scheduling_week_start($weekStart);$items=[];
    foreach($rules as $r){$cursor=new DateTimeImmutable($start);for($i=0;$i<7;$i++,$cursor=$cursor->modify('+1 day')){if((int)$cursor->format('w')!==(int)$r['weekday'])continue;$date=$cursor->format('Y-m-d');$from=$date.' '.$r['start_time'];$to=$date.' '.$r['end_time'];$sql="SELECT COUNT(DISTINCT user_id) FROM schedule_shifts WHERE organization_id=? AND user_id IS NOT NULL AND archived_at IS NULL AND status IN ('scheduled','completed') AND starts_at<? AND ends_at>?";$args=[$organizationId,$to,$from];if($r['position_id']){$sql.=' AND position_id=?';$args[]=(int)$r['position_id'];}if($r['location_id']){$sql.=' AND location_id=?';$args[]=(int)$r['location_id'];}$stmt=$pdo->prepare($sql);$stmt->execute($args);$scheduled=(int)$stmt->fetchColumn();$items[]=['ruleId'=>(int)$r['id'],'date'=>$date,'label'=>$r['label']?:($r['position_name']?:'Coverage'),'start'=>$r['start_time'],'end'=>$r['end_time'],'minimum'=>(int)$r['minimum_staff'],'target'=>(int)$r['target_staff'],'scheduled'=>$scheduled,'gap'=>max(0,(int)$r['minimum_staff']-$scheduled),'position'=>$r['position_name'],'location'=>$r['location_name']];}}
    return $items;
}

function scheduling_summary(PDO $pdo,int $organizationId,string $weekStart): array {
    $shifts=scheduling_shifts($pdo,$organizationId,$weekStart);$minutes=0;$open=0;$assignedUsers=[];foreach($shifts as $s){if($s['status']==='cancelled')continue;$minutes+=max(0,(int)((strtotime((string)$s['ends_at'])-strtotime((string)$s['starts_at']))/60)-(int)$s['break_minutes']);if(!$s['user_id'])$open++;else$assignedUsers[(int)$s['user_id']]=true;}$coverage=scheduling_coverage($pdo,$organizationId,$weekStart);$workload=scheduling_workload($pdo,$organizationId,$weekStart);$taskMinutes=array_sum(array_column($workload,'taskMinutes'));return ['weekStart'=>scheduling_week_start($weekStart),'scheduledHours'=>round($minutes/60,2),'shiftCount'=>count($shifts),'openShifts'=>$open,'scheduledStaff'=>count($assignedUsers),'coverageGaps'=>array_sum(array_map(fn($x)=>$x['gap']>0?1:0,$coverage)),'taskHours'=>round($taskMinutes/60,2),'workload'=>$workload,'coverage'=>$coverage];
}
