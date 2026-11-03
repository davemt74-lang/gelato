<?php
declare(strict_types=1);
require_once __DIR__.'/scheduling-core.php';

function tv_ready(PDO $pdo): bool {
    try{return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('time_clock_entries','voice_identity_profiles','proactive_agent_events')")->fetchColumn()>=3;}catch(Throwable){return false;}
}
function tv_public_id(string $prefix): string{return $prefix.'-'.bin2hex(random_bytes(8));}
/** Mutations use a current locking read after the employee lock, never a prior snapshot. */
function tv_open_clock(PDO $pdo,int $org,int $userId,bool $forUpdate=false): ?array {
    $sql="SELECT t.*,s.public_id shift_public_id,s.title shift_title,s.starts_at shift_starts_at,s.ends_at shift_ends_at FROM time_clock_entries t LEFT JOIN schedule_shifts s ON s.id=t.schedule_shift_id AND s.organization_id=t.organization_id WHERE t.organization_id=? AND t.user_id=? AND t.status='open' AND t.clocked_out_at IS NULL ORDER BY t.clocked_in_at DESC,t.id DESC";
    $sql.=$forUpdate?' LIMIT 2 FOR UPDATE':' LIMIT 1';
    $q=$pdo->prepare($sql);$q->execute([$org,$userId]);$rows=$q->fetchAll();
    if($forUpdate&&count($rows)>1)throw new InvalidArgumentException('Multiple open time entries need manager correction before another time-clock action.');
    return $rows[0]??null;
}

/** Shared lock order for direct, confirmed-agent and employee-PIN paths. */
function tv_lock_employee(PDO $pdo,int $org,int $userId,bool $requireActive=true): void {
    if(!$pdo->inTransaction())throw new LogicException('Time-clock employee locks require a transaction.');
    $q=$pdo->prepare('SELECT id,status,archived_at FROM users WHERE id=? FOR UPDATE');
    $q->execute([$userId]);$user=$q->fetch();
    $q=$pdo->prepare('SELECT id,status FROM organization_memberships WHERE organization_id=? AND user_id=? FOR UPDATE');
    $q->execute([$org,$userId]);$membership=$q->fetch();
    if(!$user||!$membership||($requireActive&&($user['status']!=='active'||$user['archived_at']!==null||$membership['status']!=='active')))
        throw new InvalidArgumentException('Active restaurant staff profile required.');
}

/** Preserve an outer confirmed action transaction, while rolling back a failed operation. */
function tv_employee_transaction(PDO $pdo,int $org,int $userId,callable $operation,bool $requireActive=true): array {
    $own=!$pdo->inTransaction();$savepoint='tv_'.bin2hex(random_bytes(8));
    if($own)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT '.$savepoint);
    try{
        tv_lock_employee($pdo,$org,$userId,$requireActive);
        $result=$operation();
        if($own)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        return $result;
    }catch(Throwable $error){
        if($pdo->inTransaction()){
            if($own)$pdo->rollBack();
            else{$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}
        }
        throw $error;
    }
}

function tv_open_break(PDO $pdo,int $org,int $clockId): ?array {
    $q=$pdo->prepare('SELECT * FROM time_clock_breaks WHERE organization_id=? AND time_clock_entry_id=? AND ended_at IS NULL ORDER BY started_at DESC,id DESC LIMIT 2 FOR UPDATE');
    $q->execute([$org,$clockId]);$rows=$q->fetchAll();
    if(count($rows)>1)throw new InvalidArgumentException('Multiple open breaks need manager correction before another time-clock action.');
    return $rows[0]??null;
}
function tv_nearest_shift(PDO $pdo,int $org,int $userId,?DateTimeImmutable $now=null): ?array {
    $now=$now?:new DateTimeImmutable('now');$from=$now->modify('-4 hours')->format('Y-m-d H:i:s');$to=$now->modify('+8 hours')->format('Y-m-d H:i:s');
    $q=$pdo->prepare("SELECT * FROM schedule_shifts WHERE organization_id=? AND user_id=? AND archived_at IS NULL AND status<>'cancelled' AND ends_at>=? AND starts_at<=? ORDER BY ABS(TIMESTAMPDIFF(SECOND,starts_at,?)) LIMIT 1");$q->execute([$org,$userId,$from,$to,$now->format('Y-m-d H:i:s')]);$r=$q->fetch();return $r?:null;
}
function tv_voice_event_valid(PDO $pdo,int $org,int $sessionUserId,string $eventPublicId): bool {
    if($eventPublicId==='')return false;$q=$pdo->prepare("SELECT COUNT(*) FROM voice_identity_events WHERE organization_id=? AND public_id=? AND session_user_id=? AND matched_user_id=? AND event_type='match' AND created_at>=DATE_SUB(NOW(6),INTERVAL 15 MINUTE)");$q->execute([$org,$eventPublicId,$sessionUserId,$sessionUserId]);return (int)$q->fetchColumn()===1;
}
function tv_require_voice_event(PDO $pdo,int $org,int $userId,string $source,string $eventId): void {
    if($source!=='voice')return;if(!tv_voice_event_valid($pdo,$org,$userId,$eventId))throw new RuntimeException('A recent matching voice check is required for voice time-clock actions. Voice recognition personalizes Gelato; it does not replace account authentication.');
}
function tv_attendance_event(PDO $pdo,int $org,int $userId,string $type,string $summary,string $severity='info',?int $shiftId=null,?int $clockId=null,?int $actor=null,array $meta=[]): void {
    $pdo->prepare('INSERT INTO attendance_events (organization_id,user_id,schedule_shift_id,time_clock_entry_id,event_type,severity,summary,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$org,$userId,$shiftId,$clockId,$type,$severity,mb_substr($summary,0,500,'UTF-8'),$meta?json_encode($meta,JSON_UNESCAPED_UNICODE):null,$actor]);
}
function tv_clock_in(PDO $pdo,int $org,int $userId,int $actor,string $source='manual',string $note='',string $voiceEventId=''): array {
    return tv_employee_transaction($pdo,$org,$userId,function()use($pdo,$org,$userId,$actor,$source,$note,$voiceEventId):array{
        tv_require_voice_event($pdo,$org,$userId,$source,$voiceEventId);
        if(tv_open_clock($pdo,$org,$userId,true))throw new InvalidArgumentException('You are already clocked in.');
        if(!scheduling_staff_user($pdo,$org,$userId))throw new InvalidArgumentException('Active restaurant staff profile required.');
        $shift=tv_nearest_shift($pdo,$org,$userId);$now=new DateTimeImmutable('now');$public=tv_public_id('clock');
        $pdo->prepare("INSERT INTO time_clock_entries (organization_id,public_id,user_id,schedule_shift_id,clocked_in_at,clock_in_source,clock_in_note,status,created_by,updated_by) VALUES (?,?,?,?,NOW(6),?,?, 'open',?,?)")
            ->execute([$org,$public,$userId,$shift?(int)$shift['id']:null,$source,mb_substr(trim($note),0,1000,'UTF-8')?:null,$actor,$actor]);
        $id=(int)$pdo->lastInsertId();$lateMin=0;
        if($shift)$lateMin=max(0,(int)floor(($now->getTimestamp()-(new DateTimeImmutable((string)$shift['starts_at']))->getTimestamp())/60));
        tv_attendance_event($pdo,$org,$userId,'clock.in','Clocked in'.($shift?' for '.$shift['title']:''),$lateMin>5?'warning':'info',$shift?(int)$shift['id']:null,$id,$actor,['source'=>$source,'lateMinutes'=>$lateMin]);
        if($lateMin>5)tv_attendance_event($pdo,$org,$userId,'attendance.late','Clock-in was '.$lateMin.' minutes after scheduled start.','warning',(int)$shift['id'],$id,$actor,['lateMinutes'=>$lateMin]);
        app_audit($pdo,$org,$actor,'timeclock.clock_in','user',(string)$userId,null,['source'=>$source,'clockPublicId'=>$public]);
        return tv_open_clock($pdo,$org,$userId,true)?:[];
    });
}
function tv_clock_out(PDO $pdo,int $org,int $userId,int $actor,string $source='manual',string $note='',string $voiceEventId=''): array {
    return tv_employee_transaction($pdo,$org,$userId,function()use($pdo,$org,$userId,$actor,$source,$note,$voiceEventId):array{
        tv_require_voice_event($pdo,$org,$userId,$source,$voiceEventId);
        $entry=tv_open_clock($pdo,$org,$userId,true);if(!$entry)throw new InvalidArgumentException('You are not clocked in.');
        tv_open_break($pdo,$org,(int)$entry['id']);
        $pdo->prepare('UPDATE time_clock_breaks SET ended_at=NOW(6) WHERE organization_id=? AND time_clock_entry_id=? AND ended_at IS NULL')->execute([$org,(int)$entry['id']]);
        $q=$pdo->prepare("UPDATE time_clock_entries SET clocked_out_at=NOW(6),clock_out_source=?,clock_out_note=?,status='closed',updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND status='open' AND clocked_out_at IS NULL");
        $q->execute([$source,mb_substr(trim($note),0,1000,'UTF-8')?:null,$actor,(int)$entry['id'],$org]);
        if($q->rowCount()!==1)throw new InvalidArgumentException('The time entry changed. Refresh and try again.');
        tv_attendance_event($pdo,$org,$userId,'clock.out','Clocked out','info',$entry['schedule_shift_id']?(int)$entry['schedule_shift_id']:null,(int)$entry['id'],$actor,['source'=>$source]);
        app_audit($pdo,$org,$actor,'timeclock.clock_out','user',(string)$userId,null,['source'=>$source,'clockPublicId'=>$entry['public_id']]);
        $q=$pdo->prepare('SELECT * FROM time_clock_entries WHERE id=? AND organization_id=?');$q->execute([(int)$entry['id'],$org]);return $q->fetch()?:[];
    });
}
function tv_break_action(PDO $pdo,int $org,int $userId,int $actor,string $action,string $source='manual',string $voiceEventId=''): array {
    return tv_employee_transaction($pdo,$org,$userId,function()use($pdo,$org,$userId,$actor,$action,$source,$voiceEventId):array{
        tv_require_voice_event($pdo,$org,$userId,$source,$voiceEventId);
        $entry=tv_open_clock($pdo,$org,$userId,true);if(!$entry)throw new InvalidArgumentException('Clock in before using breaks.');
        $open=tv_open_break($pdo,$org,(int)$entry['id']);
        if($action==='start'){
            if($open)throw new InvalidArgumentException('A break is already running.');
            $pdo->prepare("INSERT INTO time_clock_breaks (organization_id,time_clock_entry_id,started_at,source,created_by) VALUES (?,?,NOW(6),?,?)")->execute([$org,(int)$entry['id'],$source,$actor]);
            tv_attendance_event($pdo,$org,$userId,'break.start','Break started','info',$entry['schedule_shift_id']?(int)$entry['schedule_shift_id']:null,(int)$entry['id'],$actor,['source'=>$source]);
        }elseif($action==='end'){
            if(!$open)throw new InvalidArgumentException('No active break to end.');
            $q=$pdo->prepare('UPDATE time_clock_breaks SET ended_at=NOW(6) WHERE id=? AND organization_id=? AND ended_at IS NULL');$q->execute([(int)$open['id'],$org]);
            if($q->rowCount()!==1)throw new InvalidArgumentException('The break changed. Refresh and try again.');
            tv_attendance_event($pdo,$org,$userId,'break.end','Break ended','info',$entry['schedule_shift_id']?(int)$entry['schedule_shift_id']:null,(int)$entry['id'],$actor,['source'=>$source]);
        }else throw new InvalidArgumentException('Unsupported break action.');
        app_audit($pdo,$org,$actor,'timeclock.break_'.$action,'user',(string)$userId,null,['source'=>$source,'clockPublicId'=>$entry['public_id']]);
        $q=$pdo->prepare('SELECT * FROM time_clock_breaks WHERE organization_id=? AND time_clock_entry_id=? ORDER BY started_at,id');$q->execute([$org,(int)$entry['id']]);return $q->fetchAll();
    });
}

/** Managers may reconcile legacy duplicate entries one at a time without rewriting history. */
function tv_correct_entry(PDO $pdo,int $org,string $publicId,int $actor,string $clockIn,string $clockOut,string $reason): array {
    if(trim($reason)==='')throw new InvalidArgumentException('A correction reason is required.');
    if(trim($clockIn)===''||trim($clockOut)==='')throw new InvalidArgumentException('Correction times are invalid.');
    try{$a=new DateTimeImmutable($clockIn);$b=new DateTimeImmutable($clockOut);}catch(Throwable){throw new InvalidArgumentException('Correction times are invalid.');}
    if($b<=$a)throw new InvalidArgumentException('Clock-out must be after clock-in.');
    $q=$pdo->prepare('SELECT user_id FROM time_clock_entries WHERE organization_id=? AND public_id=?');$q->execute([$org,$publicId]);$userId=(int)$q->fetchColumn();
    if($userId<1)throw new InvalidArgumentException('Time entry not found.');
    return tv_employee_transaction($pdo,$org,$userId,function()use($pdo,$org,$userId,$publicId,$actor,$a,$b,$reason):array{
        $q=$pdo->prepare('SELECT * FROM time_clock_entries WHERE organization_id=? AND public_id=? AND user_id=? FOR UPDATE');$q->execute([$org,$publicId,$userId]);$row=$q->fetch();
        if(!$row)throw new InvalidArgumentException('Time entry not found.');
        $q=$pdo->prepare('SELECT * FROM time_clock_breaks WHERE organization_id=? AND time_clock_entry_id=? ORDER BY started_at,id FOR UPDATE');$q->execute([$org,(int)$row['id']]);
        foreach($q->fetchAll() as $break){
            $start=new DateTimeImmutable((string)$break['started_at']);$end=$break['ended_at']===null?$b:new DateTimeImmutable((string)$break['ended_at']);
            if($start<$a||$start>$b||$end<$start||$end>$b)throw new InvalidArgumentException('Correction must contain the saved break intervals.');
        }
        $before=['clocked_in_at'=>$row['clocked_in_at'],'clocked_out_at'=>$row['clocked_out_at']];
        $after=['clocked_in_at'=>$a->format('Y-m-d H:i:s.u'),'clocked_out_at'=>$b->format('Y-m-d H:i:s.u')];
        $pdo->prepare('UPDATE time_clock_breaks SET ended_at=? WHERE organization_id=? AND time_clock_entry_id=? AND ended_at IS NULL')->execute([$after['clocked_out_at'],$org,(int)$row['id']]);
        $pdo->prepare("UPDATE time_clock_entries SET clocked_in_at=?,clocked_out_at=?,status='corrected',corrected_by=?,correction_reason=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$after['clocked_in_at'],$after['clocked_out_at'],$actor,mb_substr(trim($reason),0,1000,'UTF-8'),$actor,$org,(int)$row['id']]);
        tv_attendance_event($pdo,$org,$userId,'clock.corrected','Manager corrected a time entry.','warning',$row['schedule_shift_id']?(int)$row['schedule_shift_id']:null,(int)$row['id'],$actor,['before'=>$before,'after'=>$after,'reason'=>$reason]);
        app_audit($pdo,$org,$actor,'timeclock.corrected','time_clock_entry',$publicId,$before,$after+['reason'=>$reason]);
        $q=$pdo->prepare('SELECT * FROM time_clock_entries WHERE organization_id=? AND id=?');$q->execute([$org,(int)$row['id']]);return $q->fetch()?:[];
    },false);
}
function tv_daily_summary(PDO $pdo,int $org,string $date): array {
    $day=DateTimeImmutable::createFromFormat('Y-m-d',$date);if(!$day||$day->format('Y-m-d')!==$date)$date=date('Y-m-d');$from=$date.' 00:00:00';$to=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');
    $scheduled=$pdo->prepare("SELECT COUNT(*) shifts,COALESCE(SUM(TIMESTAMPDIFF(MINUTE,starts_at,ends_at)-break_minutes),0) minutes FROM schedule_shifts WHERE organization_id=? AND user_id IS NOT NULL AND archived_at IS NULL AND status<>'cancelled' AND starts_at>=? AND starts_at<?");$scheduled->execute([$org,$from,$to]);$s=$scheduled->fetch()?:[];
    $actual=$pdo->prepare("SELECT COUNT(DISTINCT user_id) employees,COALESCE(SUM(TIMESTAMPDIFF(MINUTE,clocked_in_at,COALESCE(clocked_out_at,NOW(6)))),0) minutes FROM time_clock_entries WHERE organization_id=? AND clocked_in_at>=? AND clocked_in_at<?");$actual->execute([$org,$from,$to]);$a=$actual->fetch()?:[];
    $open=$pdo->prepare("SELECT COUNT(*) FROM time_clock_entries WHERE organization_id=? AND status='open' AND clocked_out_at IS NULL");$open->execute([$org]);
    return ['date'=>$date,'scheduledMinutes'=>(int)($s['minutes']??0),'actualMinutes'=>(int)($a['minutes']??0),'scheduledShifts'=>(int)($s['shifts']??0),'clockedInEmployees'=>(int)$open->fetchColumn(),'employeesWorked'=>(int)($a['employees']??0)];
}
function tv_clock_rows(PDO $pdo,int $org,string $date,?int $userId=null): array {
    $from=$date.' 00:00:00';$to=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');$sql="SELECT t.*,u.display_name,s.title shift_title,s.starts_at shift_starts_at,s.ends_at shift_ends_at FROM time_clock_entries t INNER JOIN users u ON u.id=t.user_id LEFT JOIN schedule_shifts s ON s.id=t.schedule_shift_id WHERE t.organization_id=? AND t.clocked_in_at>=? AND t.clocked_in_at<?";$args=[$org,$from,$to];if($userId!==null){$sql.=' AND t.user_id=?';$args[]=$userId;}$sql.=' ORDER BY t.clocked_in_at DESC';$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll();
}
function tv_no_shows(PDO $pdo,int $org,int $grace=10): array {
    $q=$pdo->prepare("SELECT s.public_id,s.id,s.user_id,s.title,s.starts_at,s.ends_at,u.display_name FROM schedule_shifts s INNER JOIN users u ON u.id=s.user_id WHERE s.organization_id=? AND s.archived_at IS NULL AND s.status<>'cancelled' AND s.starts_at BETWEEN DATE_SUB(NOW(6),INTERVAL 4 HOUR) AND DATE_SUB(NOW(6),INTERVAL ? MINUTE) AND s.ends_at>NOW(6) AND NOT EXISTS (SELECT 1 FROM time_clock_entries t WHERE t.organization_id=s.organization_id AND t.user_id=s.user_id AND t.clocked_in_at BETWEEN DATE_SUB(s.starts_at,INTERVAL 4 HOUR) AND s.ends_at)");$q->execute([$org,$grace]);return $q->fetchAll();
}
function tv_voice_vector(array $raw): array {$v=[];foreach($raw as $x){if(is_numeric($x))$v[]=max(-1,min(1,(float)$x));if(count($v)>=64)break;}if(count($v)<12)throw new InvalidArgumentException('Voice sample did not contain enough acoustic features.');$norm=sqrt(array_sum(array_map(fn($x)=>$x*$x,$v)));if($norm<0.00001)throw new InvalidArgumentException('Voice sample was too quiet.');return array_map(fn($x)=>round($x/$norm,7),$v);}
function tv_cosine(array $a,array $b): float {$n=min(count($a),count($b));if($n<12)return 0.0;$sum=0;$aa=0;$bb=0;for($i=0;$i<$n;$i++){$x=(float)$a[$i];$y=(float)$b[$i];$sum+=$x*$y;$aa+=$x*$x;$bb+=$y*$y;}return ($aa>0&&$bb>0)?$sum/(sqrt($aa)*sqrt($bb)):0.0;}
function tv_voice_profile(PDO $pdo,int $org,int $userId,bool $includePrint=false): ?array {$q=$pdo->prepare('SELECT * FROM voice_identity_profiles WHERE organization_id=? AND user_id=? LIMIT 1');$q->execute([$org,$userId]);$r=$q->fetch();if(!$r)return null;if(!$includePrint)unset($r['voiceprint_json'],$r['provider_reference'],$r['metadata_json']);return $r;}
function tv_voice_match(PDO $pdo,int $org,int $sessionUserId,array $vector,bool $matchAny=false): array {
    $vector=tv_voice_vector($vector);$sql="SELECT vp.*,u.display_name FROM voice_identity_profiles vp INNER JOIN users u ON u.id=vp.user_id WHERE vp.organization_id=? AND vp.status='active' AND vp.voiceprint_json IS NOT NULL";$args=[$org];if(!$matchAny){$sql.=' AND vp.user_id=?';$args[]=$sessionUserId;}$q=$pdo->prepare($sql);$q->execute($args);$best=null;$score=-1.0;foreach($q->fetchAll() as $r){$stored=json_decode((string)$r['voiceprint_json'],true);if(!is_array($stored))continue;$s=tv_cosine($vector,$stored);if($s>$score){$score=$s;$best=$r;}}$threshold=$best?(float)$best['match_threshold']:0.86;$matched=$best&&$score>=$threshold;$event=tv_public_id('voice');$pdo->prepare("INSERT INTO voice_identity_events (organization_id,public_id,session_user_id,matched_user_id,event_type,confidence,threshold_used,context,metadata_json) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$org,$event,$sessionUserId,$matched?(int)$best['user_id']:null,$matched?'match':'no_match',max(0,$score),$threshold,'agent_listening',json_encode(['matchAny'=>$matchAny])]);if($matched)$pdo->prepare('UPDATE voice_identity_profiles SET last_verified_at=NOW(6) WHERE id=?')->execute([(int)$best['id']]);return ['matched'=>$matched,'eventId'=>$event,'confidence'=>round(max(0,$score),4),'threshold'=>$threshold,'userId'=>$matched?(int)$best['user_id']:null,'displayName'=>$matched?(string)$best['display_name']:null,'sessionUserMatch'=>$matched&&((int)$best['user_id']===$sessionUserId)];}
function tv_self_snapshot(PDO $pdo,int $org,int $userId): array {
    $clock=tv_open_clock($pdo,$org,$userId);$week=scheduling_week_start();$shifts=scheduling_shifts($pdo,$org,$week,$userId);$tasks=[];try{$q=$pdo->prepare("SELECT t.public_id,t.title,t.status,t.due_at,t.priority FROM restaurant_tasks t INNER JOIN restaurant_task_assignments a ON a.task_id=t.id AND a.user_id=? WHERE t.organization_id=? AND t.archived_at IS NULL AND t.status NOT IN ('completed','verified','cancelled') ORDER BY COALESCE(t.due_at,'2999-12-31'),t.priority DESC LIMIT 20");$q->execute([$userId,$org]);$tasks=$q->fetchAll();}catch(Throwable){}return ['clock'=>$clock,'shifts'=>$shifts,'tasks'=>$tasks];
}