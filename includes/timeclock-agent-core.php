<?php
declare(strict_types=1);

require_once __DIR__.'/timeclock-voice-core.php';
require_once __DIR__.'/agent-confirmation-core.php';

final class TimeclockAgentPermissionException extends RuntimeException {}

function timeclock_agent_require_access(array $user): void
{
    if(!app_has_permission('timeclock.agent',$user)){
        throw new TimeclockAgentPermissionException('Time Clock Agent permission required.');
    }
}

function timeclock_agent_ready(PDO $pdo): bool
{
    return tv_ready($pdo);
}

function timeclock_agent_context(array $input): array
{
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];
    $date=trim((string)($context['selectedDate']??''));
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$date=date('Y-m-d');
    $userId=(int)($context['selectedUserId']??0);
    $locationId=(int)($context['locationId']??0);
    return ['selectedDate'=>$date,'selectedUserId'=>$userId>0?$userId:null,'locationId'=>$locationId>0?$locationId:null];
}

function timeclock_agent_staff(PDO $pdo,int $org,int $userId): ?array
{
    if($userId<1)return null;
    $q=$pdo->prepare("SELECT u.id,u.display_name,om.primary_location_id,om.job_title FROM users u INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active' WHERE u.id=? AND u.status='active' LIMIT 1");
    $q->execute([$org,$userId]);$row=$q->fetch();return $row?:null;
}

function timeclock_agent_active_staff(PDO $pdo,int $org,?int $locationId=null): array
{
    $sql="SELECT u.id user_id,u.display_name,t.public_id clock_public_id,t.clocked_in_at,t.updated_at,s.title shift_title,s.location_id FROM time_clock_entries t INNER JOIN users u ON u.id=t.user_id LEFT JOIN schedule_shifts s ON s.id=t.schedule_shift_id WHERE t.organization_id=? AND t.status='open' AND t.clocked_out_at IS NULL";
    $args=[$org];
    if($locationId!==null){$sql.=' AND s.location_id=?';$args[]=$locationId;}
    $sql.=' ORDER BY t.clocked_in_at';
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll();
}

function timeclock_agent_late_events(PDO $pdo,int $org,string $date,?int $locationId=null): array
{
    $from=$date.' 00:00:00';$to=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');
    $sql="SELECT ae.event_type,ae.severity,ae.summary,ae.created_at,u.id user_id,u.display_name,s.location_id FROM attendance_events ae INNER JOIN users u ON u.id=ae.user_id LEFT JOIN schedule_shifts s ON s.id=ae.schedule_shift_id WHERE ae.organization_id=? AND ae.created_at>=? AND ae.created_at<? AND ae.event_type IN ('attendance.late','attendance.no_show')";
    $args=[$org,$from,$to];
    if($locationId!==null){$sql.=' AND s.location_id=?';$args[]=$locationId;}
    $sql.=' ORDER BY ae.created_at DESC LIMIT 50';
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll();
}

function timeclock_agent_no_shows(PDO $pdo,int $org,?int $locationId=null,int $grace=10): array
{
    $rows=tv_no_shows($pdo,$org,$grace);
    if($locationId===null)return $rows;
    $out=[];
    foreach($rows as $row){
        $q=$pdo->prepare('SELECT location_id FROM schedule_shifts WHERE organization_id=? AND id=? LIMIT 1');
        $q->execute([$org,(int)$row['id']]);
        if((int)$q->fetchColumn()===$locationId)$out[]=$row;
    }
    return $out;
}

function timeclock_agent_daily_summary(PDO $pdo,int $org,string $date,?int $locationId=null): array
{
    if($locationId===null)return tv_daily_summary($pdo,$org,$date);
    $from=$date.' 00:00:00';$to=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');
    $scheduled=$pdo->prepare("SELECT COUNT(*) shifts,COALESCE(SUM(TIMESTAMPDIFF(MINUTE,starts_at,ends_at)-break_minutes),0) minutes FROM schedule_shifts WHERE organization_id=? AND location_id=? AND user_id IS NOT NULL AND archived_at IS NULL AND status<>'cancelled' AND starts_at>=? AND starts_at<?");
    $scheduled->execute([$org,$locationId,$from,$to]);$s=$scheduled->fetch()?:[];
    $actual=$pdo->prepare("SELECT COUNT(DISTINCT t.user_id) employees,COALESCE(SUM(TIMESTAMPDIFF(MINUTE,t.clocked_in_at,COALESCE(t.clocked_out_at,NOW(6)))),0) minutes FROM time_clock_entries t INNER JOIN schedule_shifts s ON s.id=t.schedule_shift_id AND s.organization_id=t.organization_id WHERE t.organization_id=? AND s.location_id=? AND t.clocked_in_at>=? AND t.clocked_in_at<?");
    $actual->execute([$org,$locationId,$from,$to]);$a=$actual->fetch()?:[];
    $open=$pdo->prepare("SELECT COUNT(*) FROM time_clock_entries t INNER JOIN schedule_shifts s ON s.id=t.schedule_shift_id AND s.organization_id=t.organization_id WHERE t.organization_id=? AND s.location_id=? AND t.status='open' AND t.clocked_out_at IS NULL");
    $open->execute([$org,$locationId]);
    return ['date'=>$date,'scheduledMinutes'=>(int)($s['minutes']??0),'actualMinutes'=>(int)($a['minutes']??0),'scheduledShifts'=>(int)($s['shifts']??0),'clockedInEmployees'=>(int)$open->fetchColumn(),'employeesWorked'=>(int)($a['employees']??0),'locationId'=>$locationId];
}

function timeclock_agent_selected_employee(PDO $pdo,array $user,array $context): ?array
{
    if(empty($context['selectedUserId']))return null;
    if(!app_has_permission('timeclock.view',$user)&&!app_has_permission('attendance.view',$user))return null;
    return timeclock_agent_staff($pdo,(int)$user['organization_id'],(int)$context['selectedUserId']);
}

function timeclock_agent_propose(PDO $pdo,array $user,string $type,array $payload,string $summary): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    if(!app_has_permission('timeclock.self',$user))throw new TimeclockAgentPermissionException('Personal time-clock permission required for clock and break actions.');
    $clock=tv_open_clock($pdo,$org,$uid);
    $openBreakId=null;
    if($clock){
        $b=$pdo->prepare('SELECT id FROM time_clock_breaks WHERE time_clock_entry_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');
        $b->execute([(int)$clock['id']]);$value=$b->fetchColumn();$openBreakId=$value!==false?(int)$value:null;
    }
    $payload += [
        'expectedClockPublicId'=>$clock?(string)$clock['public_id']:null,
        'expectedClockUpdatedAt'=>$clock?(string)$clock['updated_at']:null,
        'expectedOpenBreakId'=>$openBreakId,
    ];
    $proposal=gac_pending_store('timeclock',$org,$uid,$type,$payload,$summary,300);
    app_audit($pdo,$org,$uid,'timeclock.agent_action_proposed','timeclock_agent_proposal',(string)$proposal['id'],null,['type'=>$type,'clockPublicId'=>$payload['expectedClockPublicId']]);
    return gac_proposal_result($proposal,'timeclock.action_proposal',['Time Clock','Attendance','Time Clock + Attendance Agent']);
}

function timeclock_agent_assert_state(PDO $pdo,int $org,int $uid,array $payload): ?array
{
    $clock=tv_open_clock($pdo,$org,$uid);
    $expected=(string)($payload['expectedClockPublicId']??'');
    if($expected===''){
        if($clock)throw new InvalidArgumentException('Your time-clock state changed after this proposal was created. Refresh and ask again.');
        return null;
    }
    if(!$clock||(string)$clock['public_id']!==$expected||(string)$clock['updated_at']!==(string)($payload['expectedClockUpdatedAt']??'')){
        throw new InvalidArgumentException('Your time-clock state changed after this proposal was created. Refresh and ask again.');
    }
    $b=$pdo->prepare('SELECT id FROM time_clock_breaks WHERE time_clock_entry_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');
    $b->execute([(int)$clock['id']]);$current=$b->fetchColumn();$currentBreakId=$current!==false?(int)$current:null;
    $expectedBreakId=array_key_exists('expectedOpenBreakId',$payload)&&$payload['expectedOpenBreakId']!==null?(int)$payload['expectedOpenBreakId']:null;
    if($currentBreakId!==$expectedBreakId){
        throw new InvalidArgumentException('Your break state changed after this proposal was created. Refresh and ask again.');
    }
    return $clock;
}

function timeclock_agent_execute_pending(PDO $pdo,array $user,array $proposal): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    if((int)($proposal['organizationId']??0)!==$org||(int)($proposal['userId']??0)!==$uid)throw new TimeclockAgentPermissionException('This Time Clock proposal belongs to another session.');
    if(!app_has_permission('timeclock.self',$user))throw new TimeclockAgentPermissionException('Personal time-clock permission required.');
    $type=(string)($proposal['type']??'');$payload=is_array($proposal['payload']??null)?$proposal['payload']:[];
    $source=(string)($payload['source']??'agent');if(!in_array($source,['agent','voice'],true))$source='agent';
    $voiceEventId=(string)($payload['voiceEventId']??'');
    $pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$uid]);if(!$lock->fetchColumn())throw new InvalidArgumentException('Employee account is no longer available.');
        $clock=timeclock_agent_assert_state($pdo,$org,$uid,$payload);
        if($type==='clock_in'){
            if($clock)throw new InvalidArgumentException('You are already clocked in.');
            $row=tv_clock_in($pdo,$org,$uid,$uid,$source,'Agent confirmed clock-in',$voiceEventId);
            $answer='Confirmed. You are clocked in'.(!empty($row['shift_title'])?' for '.$row['shift_title']:'').'.';$entity=(string)($row['public_id']??'');
        }elseif($type==='clock_out'){
            if(!$clock)throw new InvalidArgumentException('You are not clocked in.');
            $row=tv_clock_out($pdo,$org,$uid,$uid,$source,'Agent confirmed clock-out',$voiceEventId);
            $answer='Confirmed. You are clocked out.';$entity=(string)($row['public_id']??'');
        }elseif($type==='break_start'){
            if(!$clock)throw new InvalidArgumentException('Clock in before using breaks.');
            tv_break_action($pdo,$org,$uid,$uid,'start',$source,$voiceEventId);$answer='Confirmed. Your break has started.';$entity=(string)$clock['public_id'];
        }elseif($type==='break_end'){
            if(!$clock)throw new InvalidArgumentException('Clock in before using breaks.');
            tv_break_action($pdo,$org,$uid,$uid,'end',$source,$voiceEventId);$answer='Confirmed. Your break has ended.';$entity=(string)$clock['public_id'];
        }else throw new InvalidArgumentException('The pending Time Clock action is no longer supported.');
        app_audit($pdo,$org,$uid,'timeclock.agent_action_confirmed','time_clock_entry',$entity,null,['proposalId'=>$proposal['id'],'actionType'=>$type,'source'=>$source]);
        $pdo->commit();
        return ['skill'=>'timeclock.action_confirmed','answer'=>$answer,'data'=>['actionType'=>$type,'clockPublicId'=>$entity],'sources'=>['Time Clock','Time Clock + Attendance Agent']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function timeclock_agent_handle(PDO $pdo,array $user,array $input): array
{
    timeclock_agent_require_access($user);if(!timeclock_agent_ready($pdo))throw new RuntimeException('Time Clock + Attendance is not installed. Run upgrade.php.');
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];$message=trim((string)($input['message']??''));
    if($message===''||mb_strlen($message,'UTF-8')>2400)throw new InvalidArgumentException('Ask Gelato a time-clock or attendance question no longer than 2,400 characters.');
    $context=timeclock_agent_context($input);$pending=gac_pending_get('timeclock',$org,$uid);$lower=mb_strtolower($message,'UTF-8');
    $voice=!empty($input['voice']);$voiceEventId=trim((string)($input['voiceEventId']??''));$source=$voice?'voice':'agent';

    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Time Clock action to confirm.');
        try{$result=timeclock_agent_execute_pending($pdo,$user,$pending);}catch(Throwable $e){gac_pending_clear('timeclock',$org,$uid);throw $e;}
        gac_pending_clear('timeclock',$org,$uid);return ['ok'=>true]+$result;
    }
    if(gac_is_cancel($message)&&$pending){
        gac_pending_clear('timeclock',$org,$uid);app_audit($pdo,$org,$uid,'timeclock.agent_action_discarded','timeclock_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']??null]);
        return ['ok'=>true,'skill'=>'timeclock.action_cancelled','answer'=>'Cancelled. I did not change your time-clock state.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Time Clock + Attendance Agent']];
    }

    if(preg_match('/\bclock\s+(?:me\s+)?in\b/u',$lower)){
        if(!app_has_permission('timeclock.self',$user))throw new TimeclockAgentPermissionException('Personal time-clock permission required.');
        if(tv_open_clock($pdo,$org,$uid))throw new InvalidArgumentException('You are already clocked in.');
        return timeclock_agent_propose($pdo,$user,'clock_in',['source'=>$source,'voiceEventId'=>$voiceEventId],'Proposed action: clock you in now.');
    }
    if(preg_match('/\bclock\s+(?:me\s+)?out\b/u',$lower)){
        if(!app_has_permission('timeclock.self',$user))throw new TimeclockAgentPermissionException('Personal time-clock permission required.');
        if(!tv_open_clock($pdo,$org,$uid))throw new InvalidArgumentException('You are not clocked in.');
        return timeclock_agent_propose($pdo,$user,'clock_out',['source'=>$source,'voiceEventId'=>$voiceEventId],'Proposed action: clock you out now.');
    }
    if(preg_match('/\b(?:start|take|begin)\s+(?:my\s+)?break\b/u',$lower)){
        if(!app_has_permission('timeclock.self',$user))throw new TimeclockAgentPermissionException('Personal time-clock permission required.');
        $clock=tv_open_clock($pdo,$org,$uid);if(!$clock)throw new InvalidArgumentException('Clock in before using breaks.');
        $q=$pdo->prepare('SELECT COUNT(*) FROM time_clock_breaks WHERE time_clock_entry_id=? AND ended_at IS NULL');$q->execute([(int)$clock['id']]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('A break is already running.');
        return timeclock_agent_propose($pdo,$user,'break_start',['source'=>$source,'voiceEventId'=>$voiceEventId],'Proposed action: start your break now.');
    }
    if(preg_match('/\b(?:end|finish|stop)\s+(?:my\s+)?break\b/u',$lower)){
        if(!app_has_permission('timeclock.self',$user))throw new TimeclockAgentPermissionException('Personal time-clock permission required.');
        $clock=tv_open_clock($pdo,$org,$uid);if(!$clock)throw new InvalidArgumentException('Clock in before using breaks.');
        $q=$pdo->prepare('SELECT COUNT(*) FROM time_clock_breaks WHERE time_clock_entry_id=? AND ended_at IS NULL');$q->execute([(int)$clock['id']]);if((int)$q->fetchColumn()===0)throw new InvalidArgumentException('No active break to end.');
        return timeclock_agent_propose($pdo,$user,'break_end',['source'=>$source,'voiceEventId'=>$voiceEventId],'Proposed action: end your break now.');
    }

    if(preg_match('/\b(?:my clock status|am i clocked in|clock status|my time clock)\b/u',$lower)){
        if(!app_has_permission('timeclock.self',$user))throw new TimeclockAgentPermissionException('Personal time-clock permission required.');
        $clock=tv_open_clock($pdo,$org,$uid);$answer=$clock?'You are clocked in since '.date('g:i A',strtotime((string)$clock['clocked_in_at'])).(!empty($clock['shift_title'])?' for '.$clock['shift_title']:'').'.':'You are not clocked in.';
        return ['ok'=>true,'skill'=>'timeclock.self_status','answer'=>$answer,'data'=>['clock'=>$clock?['publicId'=>$clock['public_id'],'clockedInAt'=>$clock['clocked_in_at'],'shiftTitle'=>$clock['shift_title']??null]:null],'sources'=>['Time Clock']];
    }
    if(preg_match('/\b(?:when do i work|my schedule|next shift|when am i scheduled)\b/u',$lower)){
        $snapshot=tv_self_snapshot($pdo,$org,$uid);$up=array_values(array_filter($snapshot['shifts'],static fn($s)=>strtotime((string)$s['ends_at'])>=time()));usort($up,static fn($a,$b)=>strcmp((string)$a['starts_at'],(string)$b['starts_at']));
        $answer=$up?'Your next shift is '.date('l M j, g:i A',strtotime((string)$up[0]['starts_at'])).'–'.date('g:i A',strtotime((string)$up[0]['ends_at'])).' for '.$up[0]['title'].'.':'You have no remaining published shifts this week.';
        return ['ok'=>true,'skill'=>'schedule.self_context','answer'=>$answer,'data'=>['nextShift'=>$up[0]??null],'sources'=>['Staff Scheduling']];
    }
    if(preg_match('/\b(?:my tasks|what.*prep|what do i need to do|assigned to me)\b/u',$lower)){
        $snapshot=tv_self_snapshot($pdo,$org,$uid);$tasks=$snapshot['tasks'];$answer=$tasks?'You have '.count($tasks).' open assigned task'.(count($tasks)===1?'':'s').': '.implode('; ',array_map(static fn($t)=>$t['title'].($t['due_at']?' due '.date('D g:i A',strtotime((string)$t['due_at'])):''),array_slice($tasks,0,6))).'.':'You have no open assigned tasks.';
        return ['ok'=>true,'skill'=>'tasks.self_context','answer'=>$answer,'data'=>['tasks'=>array_slice($tasks,0,20)],'sources'=>['Restaurant Tasks']];
    }
    if(preg_match('/\b(?:who.*clocked in|clocked in now|on the clock)\b/u',$lower)){
        if(!app_has_permission('timeclock.view',$user))throw new TimeclockAgentPermissionException('Time-clock view permission required.');
        $rows=timeclock_agent_active_staff($pdo,$org,$context['locationId']);$answer=$rows?count($rows).' employee'.(count($rows)===1?' is':'s are').' clocked in: '.implode('; ',array_map(static fn($r)=>$r['display_name'].' since '.date('g:i A',strtotime((string)$r['clocked_in_at'])).($r['shift_title']?' — '.$r['shift_title']:''),$rows)).'.':'No employees are currently clocked in for this scope.';
        return ['ok'=>true,'skill'=>'timeclock.active_staff','answer'=>$answer,'data'=>['rows'=>$rows,'scope'=>$context],'sources'=>['Time Clock']];
    }
    if(preg_match('/\b(?:late|no.?show|missing.*shift|attendance exceptions?|attendance issues?)\b/u',$lower)){
        if(!app_has_permission('attendance.view',$user))throw new TimeclockAgentPermissionException('Attendance permission required.');
        $noShows=timeclock_agent_no_shows($pdo,$org,$context['locationId']);$events=timeclock_agent_late_events($pdo,$org,$context['selectedDate'],$context['locationId']);
        $parts=[];if($noShows)$parts[]='Potential no-shows: '.implode('; ',array_map(static fn($r)=>$r['display_name'].' — '.$r['title'].' started '.date('g:i A',strtotime((string)$r['starts_at'])),$noShows)).'.';if($events)$parts[]=count($events).' recorded late/no-show attendance event'.(count($events)===1?'':'s').' on '.$context['selectedDate'].'.';
        return ['ok'=>true,'skill'=>'attendance.exceptions','answer'=>$parts?implode(' ',$parts):'No current attendance exceptions are detected for this scope.','data'=>['noShows'=>$noShows,'events'=>$events,'scope'=>$context],'sources'=>['Attendance']];
    }
    if(preg_match('/\b(?:actual labor|scheduled.*actual|labor today|labor hours|labor variance)\b/u',$lower)){
        if(!app_has_permission('attendance.view',$user))throw new TimeclockAgentPermissionException('Attendance permission required.');
        $s=timeclock_agent_daily_summary($pdo,$org,$context['selectedDate'],$context['locationId']);$delta=$s['actualMinutes']-$s['scheduledMinutes'];
        $answer=$context['selectedDate'].' has '.intdiv($s['scheduledMinutes'],60).'h '.($s['scheduledMinutes']%60).'m scheduled labor and '.intdiv($s['actualMinutes'],60).'h '.($s['actualMinutes']%60).'m actual clocked labor. Variance is '.($delta>=0?'+':'-').intdiv(abs($delta),60).'h '.(abs($delta)%60).'m. '.$s['clockedInEmployees'].' employee'.($s['clockedInEmployees']===1?' is':'s are').' currently clocked in.';
        return ['ok'=>true,'skill'=>'attendance.labor','answer'=>$answer,'data'=>['summary'=>$s,'varianceMinutes'=>$delta,'scope'=>$context],'sources'=>['Time Clock','Staff Scheduling']];
    }
    if(preg_match('/\b(?:this employee|selected employee|their clock|their attendance|employee attendance)\b/u',$lower)){
        $employee=timeclock_agent_selected_employee($pdo,$user,$context);if(!$employee)throw new InvalidArgumentException('Select an employee row first, or ask about attendance for the team.');
        $clock=tv_open_clock($pdo,$org,(int)$employee['id']);$rows=tv_clock_rows($pdo,$org,$context['selectedDate'],(int)$employee['id']);
        $answer=$employee['display_name'].($clock?' is clocked in since '.date('g:i A',strtotime((string)$clock['clocked_in_at'])).'.':' is not currently clocked in.').' There '.(count($rows)===1?'is 1 time entry':'are '.count($rows).' time entries').' on '.$context['selectedDate'].'.';
        return ['ok'=>true,'skill'=>'attendance.employee_context','answer'=>$answer,'data'=>['employee'=>$employee,'clock'=>$clock,'entries'=>$rows,'scope'=>$context],'sources'=>['Time Clock','Attendance']];
    }

    $clock=app_has_permission('timeclock.self',$user)?tv_open_clock($pdo,$org,$uid):null;
    $answer=$clock?'You are clocked in since '.date('g:i A',strtotime((string)$clock['clocked_in_at'])).'. Ask me about your break, attendance, team clock status, or labor variance.':'Ask me about your clock status, next shift, attendance exceptions, who is clocked in, or labor variance.';
    return ['ok'=>true,'skill'=>'timeclock.context','answer'=>$answer,'data'=>['scope'=>$context],'sources'=>['Time Clock + Attendance Agent']];
}
