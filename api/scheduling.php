<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/scheduling-core.php';

$user=app_require_auth();$pdo=app_pdo();$organizationId=(int)$user['organization_id'];$userId=(int)$user['id'];
if(!scheduling_core_ready($pdo))app_json_response(['ok'=>false,'message'=>'Staff Scheduling migration is not installed. Run upgrade.php.'],503);
function sched_can(array $u,string $p):bool{return app_has_permission($p,$u);}function sched_require(array $u,string $p):void{if(!sched_can($u,$p))app_json_response(['ok'=>false,'message'=>'Permission required: '.$p],403);}function sched_manager(array $u):bool{return sched_can($u,'schedule.manage')||sched_can($u,'staff.manage');}
function sched_json_error(Throwable $e):never{$status=$e instanceof InvalidArgumentException?422:($e instanceof RuntimeException?404:500);app_json_response(['ok'=>false,'message'=>$e->getMessage()],$status);}
function sched_employee_week_visible(array $week):bool{return in_array((string)($week['status']??''),['published','locked'],true);}
function sched_read_week(PDO $pdo,int $organizationId,string $week):array{$week=scheduling_week_start($week);$stmt=$pdo->prepare('SELECT * FROM schedule_weeks WHERE organization_id=? AND week_start=? LIMIT 1');$stmt->execute([$organizationId,$week]);$row=$stmt->fetch();return $row?:['id'=>null,'organization_id'=>$organizationId,'week_start'=>$week,'status'=>'draft','published_at'=>null,'published_by'=>null];}
function sched_staff_payload(array $rows,string $level):array{
    if($level==='private')return $rows;
    if($level==='roster')return array_map(static fn(array $row):array=>array_intersect_key($row,array_flip(['user_id','display_name','preferred_name','job_title','positions'])),$rows);
    return array_map(static function(array $row):array{unset($row['phone'],$row['employee_number'],$row['hire_date'],$row['hourly_labor_cost'],$row['scheduling_notes']);return $row;},$rows);
}
function sched_validate_availability_windows(array $windows):void{
    foreach($windows as $index=>$window){
        if(!is_array($window))throw new InvalidArgumentException('Availability window '.($index+1).' is invalid.');
        $weekday=filter_var($window['weekday']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>6]]);
        $start=trim((string)($window['start']??''));$end=trim((string)($window['end']??''));$type=(string)($window['type']??'available');
        if($weekday===false||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)||$end<=$start||!in_array($type,['available','unavailable'],true))throw new InvalidArgumentException('Availability window '.($index+1).' has an invalid day, time range, or type.');
    }
}
function sched_validate_org_reference(PDO $pdo,int $organizationId,string $table,mixed $rawId,string $label):void{
    if($rawId===''||$rawId===null)return;$id=filter_var($rawId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)throw new InvalidArgumentException($label.' is invalid.');
    $allowed=['positions','locations'];if(!in_array($table,$allowed,true))throw new LogicException('Unsupported scheduling reference validation.');
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id=? AND organization_id=? AND status='active'");$stmt->execute([(int)$id,$organizationId]);if((int)$stmt->fetchColumn()!==1)throw new InvalidArgumentException($label.' is not valid for this restaurant.');
}

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'bootstrap');$week=scheduling_week_start((string)($_GET['week']??''));$canView=sched_can($user,'schedule.view');$canSelf=sched_can($user,'schedule.self');$canStaff=sched_can($user,'staff.view')||sched_can($user,'staff.manage');$canStaffManage=sched_can($user,'staff.manage');$canScheduleManage=sched_can($user,'schedule.manage');if(!$canView&&!$canSelf&&!$canStaff)app_json_response(['ok'=>false,'message'=>'Scheduling permission required.'],403);
    if($action==='bootstrap'){
        $weekRow=sched_read_week($pdo,$organizationId,$week);$onlyUser=(!$canView&&$canSelf)?$userId:null;$employeeCanSeeWeek=$canView||($canSelf&&sched_employee_week_visible($weekRow));$staffRows=($canStaff||$canScheduleManage||$canSelf)?scheduling_staff($pdo,$organizationId):[];$staffLevel=$canStaffManage?'private':(($canStaff||$canScheduleManage)?'view':'roster');
        app_json_response(['ok'=>true,'week'=>$weekRow,'summary'=>$canView?scheduling_summary($pdo,$organizationId,$week):null,'shifts'=>$employeeCanSeeWeek?scheduling_shifts($pdo,$organizationId,$week,$onlyUser):[],'staff'=>sched_staff_payload($staffRows,$staffLevel),'positions'=>scheduling_positions($pdo,$organizationId),'locations'=>scheduling_locations($pdo,$organizationId),'requests'=>$canScheduleManage?scheduling_requests($pdo,$organizationId):($canSelf?scheduling_requests($pdo,$organizationId,$userId):[]),'exceptions'=>$canScheduleManage?scheduling_exceptions($pdo,$organizationId):($canSelf?scheduling_exceptions($pdo,$organizationId,$userId):[]),'availability'=>$canSelf?scheduling_availability($pdo,$organizationId,$userId):[],'permissions'=>['view'=>$canView,'manage'=>$canScheduleManage,'self'=>$canSelf,'staffView'=>$canStaff,'staffManage'=>$canStaffManage,'agent'=>sched_can($user,'schedule.agent')]]);
    }
    if($action==='shifts'){
        if(!$canView&&!$canSelf)app_json_response(['ok'=>false,'message'=>'Schedule view permission required.'],403);$weekRow=sched_read_week($pdo,$organizationId,$week);if(!$canView&&!sched_employee_week_visible($weekRow))app_json_response(['ok'=>true,'week'=>$weekRow,'shifts'=>[]]);app_json_response(['ok'=>true,'week'=>$weekRow,'shifts'=>scheduling_shifts($pdo,$organizationId,$week,$canView?null:$userId)]);
    }
    if($action==='staff'){if(!$canStaff&&!$canScheduleManage)app_json_response(['ok'=>false,'message'=>'Staff view permission required.'],403);app_json_response(['ok'=>true,'staff'=>sched_staff_payload(scheduling_staff($pdo,$organizationId),$canStaffManage?'private':'view')]);}
    if($action==='availability'){$target=(int)($_GET['userId']??$userId);if($target!==$userId&&!sched_manager($user))app_json_response(['ok'=>false,'message'=>'You can only view your own availability.'],403);app_json_response(['ok'=>true,'availability'=>scheduling_availability($pdo,$organizationId,$target),'exceptions'=>scheduling_exceptions($pdo,$organizationId,$target)]);}
    if($action==='requests'){app_json_response(['ok'=>true,'requests'=>$canScheduleManage?scheduling_requests($pdo,$organizationId):scheduling_requests($pdo,$organizationId,$userId)]);}
    if($action==='coverage'){sched_require($user,'schedule.view');app_json_response(['ok'=>true,'summary'=>scheduling_summary($pdo,$organizationId,$week),'rules'=>scheduling_coverage_rules($pdo,$organizationId)]);}
    app_json_response(['ok'=>false,'message'=>'Unsupported scheduling action.'],422);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');
try{
    if($action==='profile_save'){
        sched_require($user,'staff.manage');$target=(int)($input['userId']??0);$row=scheduling_profile_save($pdo,$organizationId,$target,$input,$userId);app_audit($pdo,$organizationId,$userId,'staff.profile_saved','user',(string)$target,null,['employmentType'=>$row['employment_type']??null]);app_json_response(['ok'=>true,'staff'=>$row,'message'=>'Staff scheduling profile saved.']);
    }
    if($action==='availability_save'){
        $target=(int)($input['userId']??$userId);$allowed=$target===$userId&&sched_can($user,'schedule.self');if(!$allowed&&!sched_can($user,'schedule.manage'))app_json_response(['ok'=>false,'message'=>'You can only change your own availability.'],403);$windows=(array)($input['windows']??[]);sched_validate_availability_windows($windows);$rows=scheduling_availability_replace($pdo,$organizationId,$target,$windows,$userId);app_audit($pdo,$organizationId,$userId,'schedule.availability_saved','user',(string)$target,null,['windows'=>count($rows)]);app_json_response(['ok'=>true,'availability'=>$rows,'message'=>'Availability saved.']);
    }
    if($action==='exception_request'){
        $target=(int)($input['userId']??$userId);$manager=sched_can($user,'schedule.manage');if($target!==$userId&&!$manager)app_json_response(['ok'=>false,'message'=>'You can only submit your own availability request.'],403);if($target===$userId&&!sched_can($user,'schedule.self')&&!$manager)app_json_response(['ok'=>false,'message'=>'Employee scheduling permission required.'],403);$row=scheduling_exception_create($pdo,$organizationId,$target,$input,$userId,$manager);app_audit($pdo,$organizationId,$userId,'schedule.availability_exception_created','availability_exception',(string)$row['public_id']);app_json_response(['ok'=>true,'exception'=>$row,'message'=>$manager?'Availability exception added.':'Time-off/availability request submitted.'],201);
    }
    if($action==='exception_review'){
        sched_require($user,'schedule.manage');$id=trim((string)($input['id']??''));$decision=(string)($input['decision']??'');if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Decision must be approved or rejected.');$stmt=$pdo->prepare("SELECT * FROM staff_availability_exceptions WHERE organization_id=? AND public_id=? AND status='pending' LIMIT 1");$stmt->execute([$organizationId,$id]);$row=$stmt->fetch();if(!$row)throw new InvalidArgumentException('Pending availability request not found.');$pdo->prepare('UPDATE staff_availability_exceptions SET status=?,reviewed_by=?,reviewed_at=NOW(6),updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$decision,$userId,(int)$row['id'],$organizationId]);app_audit($pdo,$organizationId,$userId,'schedule.availability_exception_reviewed','availability_exception',$id,null,['decision'=>$decision]);app_json_response(['ok'=>true,'message'=>'Availability request '.$decision.'.']);
    }
    if($action==='shift_save'){
        sched_require($user,'schedule.manage');$id=trim((string)($input['id']??''));$existing=$id!==''?scheduling_shift_by_public_id($pdo,$organizationId,$id):null;if($id!==''&&!$existing)throw new InvalidArgumentException('Shift not found.');$shift=scheduling_shift_save($pdo,$organizationId,$input,$userId,$existing);app_audit($pdo,$organizationId,$userId,$existing?'schedule.shift_updated':'schedule.shift_created','schedule_shift',(string)$shift['public_id'],null,['userId'=>$shift['user_id']??null,'startsAt'=>$shift['starts_at']??null]);app_json_response(['ok'=>true,'shift'=>$shift,'message'=>$existing?'Shift updated.':'Shift created.'],201);
    }
    if($action==='shift_archive'){
        sched_require($user,'schedule.manage');$id=trim((string)($input['id']??''));$shift=scheduling_shift_by_public_id($pdo,$organizationId,$id);if(!$shift)throw new InvalidArgumentException('Shift not found.');$pdo->prepare("UPDATE schedule_shifts SET status='cancelled',archived_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$organizationId,(int)$shift['id']]);scheduling_shift_event($pdo,$organizationId,(int)$shift['id'],'shift.cancelled','Shift cancelled',$userId);app_audit($pdo,$organizationId,$userId,'schedule.shift_cancelled','schedule_shift',$id);app_json_response(['ok'=>true,'message'=>'Shift cancelled.']);
    }
    if($action==='week_publish'){
        sched_require($user,'schedule.manage');$week=scheduling_ensure_week($pdo,$organizationId,(string)($input['week']??''),$userId);$status=(string)($input['status']??'published');if(!in_array($status,['draft','published','locked'],true))throw new InvalidArgumentException('Unsupported schedule status.');$pdo->prepare('UPDATE schedule_weeks SET status=?,published_at=IF(?="published",NOW(6),published_at),published_by=IF(?="published",?,published_by),updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?')->execute([$status,$status,$status,$userId,$userId,(int)$week['id'],$organizationId]);app_audit($pdo,$organizationId,$userId,'schedule.week_status_changed','schedule_week',(string)$week['week_start'],null,['status'=>$status]);app_json_response(['ok'=>true,'message'=>'Schedule week marked '.$status.'.']);
    }
    if($action==='request_create'){
        $isManager=sched_can($user,'schedule.manage');if(!sched_can($user,'schedule.self')&&!$isManager)app_json_response(['ok'=>false,'message'=>'Employee scheduling permission required.'],403);if(!$isManager){$shift=scheduling_shift_by_public_id($pdo,$organizationId,trim((string)($input['shiftId']??'')));if(!$shift||(int)($shift['user_id']??0)!==$userId)app_json_response(['ok'=>false,'message'=>'You can only request a change to your assigned shift.'],403);if(!in_array((string)($shift['week_status']??''),['published','locked'],true))app_json_response(['ok'=>false,'message'=>'Shift requests are available after the schedule is published.'],403);}$row=scheduling_request_create($pdo,$organizationId,$userId,$input);app_audit($pdo,$organizationId,$userId,'schedule.shift_request_created','shift_request',(string)$row['public_id']);app_json_response(['ok'=>true,'request'=>$row,'message'=>'Shift request submitted.'],201);
    }
    if($action==='request_review'){
        sched_require($user,'schedule.manage');$row=scheduling_request_review($pdo,$organizationId,trim((string)($input['id']??'')),(string)($input['decision']??''),$userId,(string)($input['note']??''));app_audit($pdo,$organizationId,$userId,'schedule.shift_request_reviewed','shift_request',(string)$row['public_id'],null,['status'=>$row['status']]);app_json_response(['ok'=>true,'request'=>$row,'message'=>'Shift request '.$row['status'].'.']);
    }
    if($action==='coverage_rule_save'){
        sched_require($user,'schedule.manage');sched_validate_org_reference($pdo,$organizationId,'positions',$input['positionId']??null,'Coverage position');sched_validate_org_reference($pdo,$organizationId,'locations',$input['locationId']??null,'Coverage location');$row=scheduling_coverage_rule_save($pdo,$organizationId,$input,$userId);app_audit($pdo,$organizationId,$userId,'schedule.coverage_rule_saved','coverage_rule',(string)$row['id']);app_json_response(['ok'=>true,'rule'=>$row,'message'=>'Coverage rule saved.']);
    }
    if($action==='coverage_rule_archive'){
        sched_require($user,'schedule.manage');$id=(int)($input['id']??0);$pdo->prepare("UPDATE labor_coverage_rules SET status='inactive',updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$userId,$id,$organizationId]);app_audit($pdo,$organizationId,$userId,'schedule.coverage_rule_archived','coverage_rule',(string)$id);app_json_response(['ok'=>true,'message'=>'Coverage rule archived.']);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported scheduling action.'],422);
}catch(Throwable $e){sched_json_error($e);}
