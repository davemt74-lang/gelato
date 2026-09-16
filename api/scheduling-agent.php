<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/scheduling-core.php';
require_once __DIR__.'/../includes/employee-shift-communications.php';

$user=app_require_auth();
$pdo=app_pdo();
$organizationId=(int)$user['organization_id'];
$userId=(int)$user['id'];
if(!scheduling_core_ready($pdo))app_json_response(['ok'=>false,'message'=>'Staff Scheduling migration is not installed. Run upgrade.php.'],503);
if(!app_has_permission('schedule.agent',$user))app_json_response(['ok'=>false,'message'=>'Scheduling Agent permission required.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();
app_verify_request_csrf($input);
$message=trim((string)($input['message']??''));
if($message===''||mb_strlen($message,'UTF-8')>1800)app_json_response(['ok'=>false,'message'=>'Ask Gelato a scheduling question no longer than 1,800 characters.'],422);
$pageContext=is_array($input['pageContext']??null)?$input['pageContext']:[];
$contextModule=(string)($pageContext['module']??'');
$contextWeek=preg_match('/^20\d{2}-\d{2}-\d{2}$/',(string)($pageContext['week']??''))?(string)$pageContext['week']:'';
$week=scheduling_week_start($contextWeek!==''?$contextWeek:(string)($input['week']??''));

function sa_staff_name(array $row): string{return trim((string)($row['preferred_name']??''))?:trim((string)($row['display_name']??''))?:'Employee';}
function sa_staff_match(array $staff,string $query):?array{
    $query=mb_strtolower(trim(preg_replace('/\s+(?:on|for|from|at)\s+.+$/iu','',$query)??$query),'UTF-8');
    if($query==='')return null;$exact=[];$partial=[];
    foreach($staff as $s){
        foreach(array_filter([(string)($s['preferred_name']??''),(string)($s['display_name']??''),(string)($s['first_name']??'')]) as $name){
            $n=mb_strtolower($name,'UTF-8');
            if($n===$query)$exact[]=$s;elseif(str_contains($n,$query)||str_contains($query,$n))$partial[]=$s;
        }
    }
    return $exact[0]??($partial[0]??null);
}
function sa_day_date(string $text,string $week):?string{
    $days=['monday'=>0,'tuesday'=>1,'wednesday'=>2,'thursday'=>3,'friday'=>4,'saturday'=>5,'sunday'=>6];
    foreach($days as $name=>$offset)if(preg_match('/\b'.$name.'\b/i',$text))return (new DateTimeImmutable($week))->modify('+'.$offset.' days')->format('Y-m-d');
    if(preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/',$text,$m))return $m[1];
    return null;
}
function sa_time(string $raw,bool $end=false):?string{
    $raw=strtolower(trim($raw));
    if(!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/',$raw,$m))return null;
    $h=(int)$m[1];$min=(int)($m[2]??0);$ap=$m[3]??'';
    if($ap==='pm'&&$h<12)$h+=12;if($ap==='am'&&$h===12)$h=0;
    if($ap===''&&$h<=10&&$end)$h+=12;if($ap===''&&$h>=1&&$h<=8&&!$end)$h+=12;
    if($h>23||$min>59)return null;
    return sprintf('%02d:%02d',$h,$min);
}
function sa_time_range(string $text):?array{
    if(!preg_match('/(?:from\s+)?(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*(?:to|\-|–)\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i',$text,$m))return null;
    $start=sa_time(trim($m[1]),false);$end=sa_time(trim($m[2]),true);
    return $start&&$end?[$start,$end]:null;
}
function sa_staff_schedule_answer(PDO $pdo,int $org,string $week,array $staffRow):string{
    $rows=scheduling_shifts($pdo,$org,$week,(int)$staffRow['user_id']);
    if(!$rows)return sa_staff_name($staffRow).' has no shifts scheduled for the week of '.$week.'.';
    $parts=[];foreach($rows as $s)$parts[]=date('D M j',strtotime($s['starts_at'])).' '.date('g:i A',strtotime($s['starts_at'])).'–'.date('g:i A',strtotime($s['ends_at'])).' '.$s['title'];
    return sa_staff_name($staffRow).': '.implode('; ',$parts).'.';
}
function sa_selected_shift(PDO $pdo,int $org,array $context):?array{
    $id=preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)($context['selectedShiftPublicId']??'')))??'';
    return $id!==''?scheduling_shift_by_public_id($pdo,$org,$id):null;
}
function sa_selected_staff(PDO $pdo,int $org,array $context):?array{
    $id=(int)($context['selectedStaffUserId']??0);
    return $id>0?scheduling_staff_user($pdo,$org,$id):null;
}
function sa_pending_key(int $org,int $userId):string{return $org.':'.$userId;}
function sa_pending_get(int $org,int $userId):?array{
    $key=sa_pending_key($org,$userId);$row=$_SESSION['schedule_agent_pending'][$key]??null;
    if(!is_array($row)||($row['expires']??0)<time()){unset($_SESSION['schedule_agent_pending'][$key]);return null;}
    return $row;
}
function sa_pending_store(int $org,int $userId,string $type,array $payload,string $summary):array{
    $row=['id'=>'sap-'.bin2hex(random_bytes(8)),'type'=>$type,'payload'=>$payload,'summary'=>$summary,'expires'=>time()+600];
    $_SESSION['schedule_agent_pending'][sa_pending_key($org,$userId)]=$row;return $row;
}
function sa_pending_clear(int $org,int $userId):void{unset($_SESSION['schedule_agent_pending'][sa_pending_key($org,$userId)]);}
function sa_proposal_response(array $proposal,string $skill,array $sources=[]):never{
    app_json_response(['ok'=>true,'skill'=>$skill,'answer'=>$proposal['summary']."\n\nReply Confirm to execute this change, or Cancel to discard it.",'data'=>['requiresConfirmation'=>true,'proposal'=>['id'=>$proposal['id'],'type'=>$proposal['type'],'summary'=>$proposal['summary'],'expiresAt'=>date(DATE_ATOM,(int)$proposal['expires'])]],'sources'=>$sources]);
}
function sa_execute_pending(PDO $pdo,int $org,int $userId,array $user,array $proposal):array{
    $type=(string)$proposal['type'];$p=(array)$proposal['payload'];
    if(in_array($type,['shift_create','shift_update','shift_cancel','week_publish'],true)&&!app_has_permission('schedule.manage',$user))throw new RuntimeException('Schedule manage permission is required for this action.');
    if($type==='shift_create'){
        $staff=scheduling_staff_user($pdo,$org,(int)($p['userId']??0));if(!$staff)throw new InvalidArgumentException('The proposed employee is no longer active restaurant staff.');
        $shift=scheduling_shift_save($pdo,$org,$p,$userId);app_audit($pdo,$org,$userId,'schedule.agent_shift_created','schedule_shift',(string)$shift['public_id'],null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'schedule.action_confirmed','answer'=>'Confirmed. Scheduled '.sa_staff_name($staff).' on '.date('l M j',strtotime($shift['starts_at'])).' from '.date('g:i A',strtotime($shift['starts_at'])).' to '.date('g:i A',strtotime($shift['ends_at'])).'.','data'=>['action'=>'shift_create','shiftId'=>$shift['public_id']],'sources'=>['Staff Scheduling','Employee Availability']];
    }
    if($type==='shift_update'){
        $existing=scheduling_shift_by_public_id($pdo,$org,(string)($p['shiftId']??''));if(!$existing)throw new InvalidArgumentException('The selected shift no longer exists.');
        $save=(array)($p['changes']??[]);$save['id']=$existing['public_id'];$shift=scheduling_shift_save($pdo,$org,$save,$userId,$existing);app_audit($pdo,$org,$userId,'schedule.agent_shift_updated','schedule_shift',(string)$shift['public_id'],null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'schedule.action_confirmed','answer'=>'Confirmed. Updated '.$shift['title'].' to '.date('l M j',strtotime($shift['starts_at'])).' '.date('g:i A',strtotime($shift['starts_at'])).'–'.date('g:i A',strtotime($shift['ends_at'])).'.','data'=>['action'=>'shift_update','shiftId'=>$shift['public_id']],'sources'=>['Staff Scheduling','Employee Availability']];
    }
    if($type==='shift_cancel'){
        $shift=scheduling_shift_by_public_id($pdo,$org,(string)($p['shiftId']??''));if(!$shift)throw new InvalidArgumentException('The selected shift no longer exists.');
        $pdo->prepare("UPDATE schedule_shifts SET status='cancelled',archived_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND archived_at IS NULL")->execute([$userId,$org,(int)$shift['id']]);
        scheduling_shift_event($pdo,$org,(int)$shift['id'],'shift.cancelled','Shift cancelled through Gelato Agent',$userId,['proposalId'=>$proposal['id']]);app_audit($pdo,$org,$userId,'schedule.agent_shift_cancelled','schedule_shift',(string)$shift['public_id']);
        return ['skill'=>'schedule.action_confirmed','answer'=>'Confirmed. Cancelled '.$shift['title'].' on '.date('l M j',strtotime($shift['starts_at'])).'.','data'=>['action'=>'shift_cancel','shiftId'=>$shift['public_id']],'sources'=>['Staff Scheduling']];
    }
    if($type==='week_publish'){
        $week=scheduling_ensure_week($pdo,$org,(string)($p['week']??''),$userId);$pdo->prepare("UPDATE schedule_weeks SET status='published',published_at=NOW(6),published_by=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$userId,$userId,(int)$week['id'],$org]);app_audit($pdo,$org,$userId,'schedule.agent_week_published','schedule_week',(string)$week['week_start'],null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'schedule.action_confirmed','answer'=>'Confirmed. Published the schedule for the week of '.$week['week_start'].'.','data'=>['action'=>'week_publish','week'=>$week['week_start']],'sources'=>['Staff Scheduling']];
    }
    if($type==='employee_message'){
        if(!employee_shift_comms_ready($pdo))throw new RuntimeException('Employee shift communications are not installed. Run upgrade.php.');
        if(!app_has_permission('employee.handoffs.manage',$user)&&!app_has_permission('staff.manage',$user))throw new RuntimeException('Employee communication management permission is required.');
        $target=scheduling_staff_user($pdo,$org,(int)($p['userId']??0));if(!$target)throw new InvalidArgumentException('The selected employee is no longer active restaurant staff.');
        $row=employee_shift_message_save($pdo,$org,['targetType'=>'user','targetId'=>(int)$target['user_id'],'messageType'=>'announcement','priority'=>'normal','title'=>'Schedule update','body'=>(string)($p['body']??'')],$userId,true);app_audit($pdo,$org,$userId,'schedule.agent_employee_message','employee_shift_message',(string)($row['public_id']??''),null,['targetUserId'=>(int)$target['user_id'],'proposalId'=>$proposal['id']]);
        return ['skill'=>'schedule.action_confirmed','answer'=>'Confirmed. Sent the schedule message to '.sa_staff_name($target).'.','data'=>['action'=>'employee_message','messageId'=>$row['public_id']??null],'sources'=>['Employee Shift Communications']];
    }
    throw new InvalidArgumentException('That pending Scheduling Agent action is no longer supported.');
}
function sa_shift_context_summary(array $shift):string{
    return ($shift['title']?:'Shift').' · '.date('l M j',strtotime($shift['starts_at'])).' '.date('g:i A',strtotime($shift['starts_at'])).'–'.date('g:i A',strtotime($shift['ends_at'])).' · '.($shift['display_name']?:'Open shift').($shift['position_name']?' · '.$shift['position_name']:'').($shift['location_name']?' · '.$shift['location_name']:'');
}

try{
    $staff=scheduling_staff($pdo,$organizationId);$lower=mb_strtolower($message,'UTF-8');
    $selectedShift=$contextModule==='scheduling'?sa_selected_shift($pdo,$organizationId,$pageContext):null;
    $selectedStaff=$contextModule==='scheduling'?sa_selected_staff($pdo,$organizationId,$pageContext):null;
    $pending=sa_pending_get($organizationId,$userId);

    if(preg_match('/^(?:confirm|yes|yes please|do it|go ahead|execute|apply)(?:\s+(?:it|that|change|action))?[.!]?$/i',$message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Scheduling Agent action to confirm.');
        $result=sa_execute_pending($pdo,$organizationId,$userId,$user,$pending);sa_pending_clear($organizationId,$userId);app_audit($pdo,$organizationId,$userId,'schedule.agent_action_confirmed','schedule_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);app_json_response(['ok'=>true]+$result);
    }
    if(preg_match('/^(?:cancel|cancel it|discard|never mind|nevermind|stop)[.!]?$/i',$message)&&$pending){
        sa_pending_clear($organizationId,$userId);app_audit($pdo,$organizationId,$userId,'schedule.agent_action_discarded','schedule_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);app_json_response(['ok'=>true,'skill'=>'schedule.action_cancelled','answer'=>'Cancelled. I did not change the schedule.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Staff Scheduling']]);
    }

    if($selectedShift&&preg_match('/\b(?:who can cover|who could cover|coverage candidates?|available to cover)\b/i',$message)){
        $candidates=[];foreach($staff as $s){$sid=(int)$s['user_id'];if($sid===(int)($selectedShift['user_id']??0))continue;$availability=scheduling_availability_check($pdo,$organizationId,$sid,(string)$selectedShift['starts_at'],(string)$selectedShift['ends_at']);$conflicts=scheduling_shift_conflicts($pdo,$organizationId,$sid,(string)$selectedShift['starts_at'],(string)$selectedShift['ends_at']);if($availability['ok']&&!$conflicts)$candidates[]=sa_staff_name($s);}
        $answer=$candidates?'Recorded availability and overlap checks identify these candidates for the selected shift: '.implode(', ',$candidates).'. Verify role/skill suitability before assigning.':'No active staff pass both the recorded availability and overlap checks for the selected shift.';
        app_json_response(['ok'=>true,'skill'=>'schedule.context_coverage_candidates','answer'=>$answer,'data'=>['shiftId'=>$selectedShift['public_id'],'candidateCount'=>count($candidates)],'sources'=>['Staff Scheduling','Employee Availability']]);
    }
    if($selectedShift&&preg_match('/\b(?:what is|show|describe|details?|about)\s+(?:this|selected)\s+shift\b/i',$message))app_json_response(['ok'=>true,'skill'=>'schedule.context_shift','answer'=>sa_shift_context_summary($selectedShift).'.','data'=>['shiftId'=>$selectedShift['public_id']],'sources'=>['Staff Scheduling']]);
    if($selectedStaff&&preg_match('/\b(?:when do they work|when does (?:he|she) work|this employee|their schedule|their shifts?|what about them|when do they work next)\b/i',$message))app_json_response(['ok'=>true,'skill'=>'schedule.context_employee','answer'=>sa_staff_schedule_answer($pdo,$organizationId,$week,$selectedStaff),'data'=>['userId'=>(int)$selectedStaff['user_id'],'week'=>$week],'sources'=>['Staff Scheduling']]);

    if($selectedShift&&preg_match('/\b(?:cancel|remove|delete)\s+(?:this|selected)\s+shift\b/i',$message)){
        if(!app_has_permission('schedule.manage',$user))app_json_response(['ok'=>false,'message'=>'Schedule manage permission is required for Agent shift cancellation.'],403);
        $summary='Proposed action: cancel '.sa_shift_context_summary($selectedShift).'.';$proposal=sa_pending_store($organizationId,$userId,'shift_cancel',['shiftId'=>$selectedShift['public_id']],$summary);app_audit($pdo,$organizationId,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'shift_cancel']);sa_proposal_response($proposal,'schedule.action_proposal',['Staff Scheduling']);
    }
    if($selectedShift&&preg_match('/\b(?:move|change|update|reschedule)\s+(?:this|selected)\s+shift\b/i',$message)){
        if(!app_has_permission('schedule.manage',$user))app_json_response(['ok'=>false,'message'=>'Schedule manage permission is required for Agent shift changes.'],403);
        $range=sa_time_range($message);if(!$range)throw new InvalidArgumentException('Include the new start and end time, for example “move this shift to Friday 5 pm to 11 pm”.');$date=sa_day_date($message,$week)?:substr((string)$selectedShift['starts_at'],0,10);
        $changes=['title'=>$selectedShift['title'],'startsAt'=>$date.' '.$range[0].':00','endsAt'=>$date.' '.$range[1].':00','userId'=>$selectedShift['user_id']?:'','positionId'=>$selectedShift['position_id']?:'','locationId'=>$selectedShift['location_id']?:'','breakMinutes'=>$selectedShift['break_minutes']??0,'status'=>$selectedShift['status'],'notes'=>$selectedShift['notes']??'','enforceAvailability'=>true];
        $summary='Proposed action: move '.($selectedShift['display_name']?:'the selected employee')."'s ".$selectedShift['title'].' shift to '.date('l M j',strtotime($date)).' '.date('g:i A',strtotime($changes['startsAt'])).'–'.date('g:i A',strtotime($changes['endsAt'])).'.';$proposal=sa_pending_store($organizationId,$userId,'shift_update',['shiftId'=>$selectedShift['public_id'],'changes'=>$changes],$summary);app_audit($pdo,$organizationId,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'shift_update']);sa_proposal_response($proposal,'schedule.action_proposal',['Staff Scheduling','Employee Availability']);
    }
    if($selectedShift&&preg_match('/\b(?:assign|give)\s+(?:this|selected)\s+shift\s+to\s+(.+)$/i',$message,$m)){
        if(!app_has_permission('schedule.manage',$user))app_json_response(['ok'=>false,'message'=>'Schedule manage permission is required for Agent shift assignment.'],403);$person=sa_staff_match($staff,trim($m[1]));if(!$person)throw new InvalidArgumentException('I could not match that employee name.');
        $changes=['title'=>$selectedShift['title'],'startsAt'=>$selectedShift['starts_at'],'endsAt'=>$selectedShift['ends_at'],'userId'=>(int)$person['user_id'],'positionId'=>$selectedShift['position_id']?:'','locationId'=>$selectedShift['location_id']?:'','breakMinutes'=>$selectedShift['break_minutes']??0,'status'=>'scheduled','notes'=>$selectedShift['notes']??'','enforceAvailability'=>true];
        $summary='Proposed action: assign the selected '.$selectedShift['title'].' shift on '.date('l M j',strtotime($selectedShift['starts_at'])).' to '.sa_staff_name($person).'.';$proposal=sa_pending_store($organizationId,$userId,'shift_update',['shiftId'=>$selectedShift['public_id'],'changes'=>$changes],$summary);app_audit($pdo,$organizationId,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'shift_assign']);sa_proposal_response($proposal,'schedule.action_proposal',['Staff Scheduling','Employee Availability']);
    }
    if($selectedStaff&&preg_match('/^\s*(?:message|tell|notify)\s+(?:this employee|them|him|her)(?:\s+(?:that|:|-))?\s+(.+)$/iu',$message,$m)){
        if(!app_has_permission('employee.handoffs.manage',$user)&&!app_has_permission('staff.manage',$user))app_json_response(['ok'=>false,'message'=>'Employee communication management permission is required.'],403);$body=trim($m[1]);if($body==='')throw new InvalidArgumentException('Include the message you want to send.');$summary='Proposed action: send '.sa_staff_name($selectedStaff).' this schedule message: “'.mb_substr($body,0,360,'UTF-8').'”';$proposal=sa_pending_store($organizationId,$userId,'employee_message',['userId'=>(int)$selectedStaff['user_id'],'body'=>$body],$summary);app_audit($pdo,$organizationId,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'employee_message']);sa_proposal_response($proposal,'schedule.action_proposal',['Employee Shift Communications']);
    }
    if(preg_match('/\bpublish\s+(?:this|the|visible)?\s*(?:schedule\s+)?week\b/i',$message)){
        if(!app_has_permission('schedule.manage',$user))app_json_response(['ok'=>false,'message'=>'Schedule manage permission is required to publish schedules.'],403);$summary='Proposed action: publish the schedule for the week of '.$week.'.';$proposal=sa_pending_store($organizationId,$userId,'week_publish',['week'=>$week],$summary);app_audit($pdo,$organizationId,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'week_publish']);sa_proposal_response($proposal,'schedule.action_proposal',['Staff Scheduling']);
    }

    if(preg_match('/\b(schedule|assign)\s+(.+?)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday|20\d{2}-\d{2}-\d{2})\s+(?:from\s+)?([0-9: ]+(?:am|pm)?)\s+(?:to|-)\s+([0-9: ]+(?:am|pm)?)/i',$message,$m)){
        if(!app_has_permission('schedule.manage',$user))app_json_response(['ok'=>false,'message'=>'Schedule manage permission is required for Agent shift creation.'],403);$person=sa_staff_match($staff,trim($m[2]));if(!$person)throw new InvalidArgumentException('I could not match that employee name.');$date=sa_day_date($m[3],$week);$start=sa_time(trim($m[4]),false);$end=sa_time(trim($m[5]),true);if(!$date||!$start||!$end)throw new InvalidArgumentException('I could not parse the requested shift date/time. Try “schedule Anthony Friday 4 pm to 10 pm”.');
        $payload=['title'=>($person['job_title']?:'Shift'),'startsAt'=>$date.' '.$start.':00','endsAt'=>$date.' '.$end.':00','userId'=>(int)$person['user_id'],'status'=>'scheduled','enforceAvailability'=>true];$summary='Proposed action: schedule '.sa_staff_name($person).' on '.date('l M j',strtotime($date)).' from '.date('g:i A',strtotime($payload['startsAt'])).' to '.date('g:i A',strtotime($payload['endsAt'])).'.';$proposal=sa_pending_store($organizationId,$userId,'shift_create',$payload,$summary);app_audit($pdo,$organizationId,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'shift_create']);sa_proposal_response($proposal,'schedule.action_proposal',['Staff Scheduling','Employee Availability']);
    }

    $skill='schedule.summary';$answer='';$sources=['Staff Scheduling'];$data=['week'=>$week];
    if(preg_match('/\b(who\s+(?:is\s+)?works?|who\s+is\s+working|working)\b/i',$message)&&($date=sa_day_date($message,$week))){
        $rows=array_values(array_filter(scheduling_shifts($pdo,$organizationId,$week),fn($s)=>substr((string)$s['starts_at'],0,10)===$date&&$s['status']!=='cancelled'));$skill='schedule.day_roster';if(!$rows)$answer='No shifts are scheduled for '.date('l M j',strtotime($date)).'.';else{$parts=[];foreach($rows as $s)$parts[]=($s['display_name']?:'Open shift').' '.date('g:i A',strtotime($s['starts_at'])).'–'.date('g:i A',strtotime($s['ends_at'])).($s['position_name']?' '.$s['position_name']:'');$answer=date('l M j',strtotime($date)).': '.implode('; ',$parts).'.';}$data['date']=$date;
    } elseif(preg_match('/\b(open|unassigned)\s+shifts?\b/i',$message)){
        $rows=array_values(array_filter(scheduling_shifts($pdo,$organizationId,$week),fn($s)=>!$s['user_id']&&$s['status']==='open'));$skill='schedule.open_shifts';if(!$rows)$answer='There are no open shifts for the week of '.$week.'.';else{$parts=[];foreach($rows as $s)$parts[]=date('D M j',strtotime($s['starts_at'])).' '.date('g:i A',strtotime($s['starts_at'])).'–'.date('g:i A',strtotime($s['ends_at'])).' '.$s['title'];$answer=count($rows).' open shift'.(count($rows)===1?'':'s').': '.implode('; ',$parts).'.';}$data['openShifts']=count($rows);
    } elseif(preg_match('/\b(coverage|short staffed|short-staffed|staffing gap|staffing gaps)\b/i',$message)){
        $coverage=scheduling_coverage($pdo,$organizationId,$week);$gaps=array_values(array_filter($coverage,fn($x)=>(int)$x['gap']>0));$skill='schedule.coverage';$sources[]='Coverage Rules';if(!$gaps)$answer='No minimum-staff coverage gaps are detected for the week of '.$week.'.';else{$parts=[];foreach($gaps as $x)$parts[]=date('D M j',strtotime($x['date'])).' '.$x['label'].' is '.$x['gap'].' short ('.$x['scheduled'].' scheduled / '.$x['minimum'].' minimum)';$answer='Coverage gaps: '.implode('; ',$parts).'.';}$data['coverageGaps']=count($gaps);
    } elseif(preg_match('/\b(workload|task hours|prep hours|labor hours)\b/i',$message)){
        $summary=scheduling_summary($pdo,$organizationId,$week);$skill='schedule.workload';$sources[]='Operations Workload';$answer='Week of '.$week.': '.$summary['scheduledHours'].' scheduled labor hours, '.$summary['taskHours'].' open task/prep hours, '.$summary['shiftCount'].' shifts, '.$summary['openShifts'].' open shifts, and '.$summary['coverageGaps'].' coverage gaps.';$data['summary']=$summary;
    } elseif(preg_match('/\b(available|availability)\b/i',$message)&&($date=sa_day_date($message,$week))){
        $skill='schedule.availability';$sources[]='Employee Availability';$available=[];foreach($staff as $s){$ok=scheduling_availability_check($pdo,$organizationId,(int)$s['user_id'],$date.' 16:00:00',$date.' 22:00:00');$conflicts=scheduling_shift_conflicts($pdo,$organizationId,(int)$s['user_id'],$date.' 16:00:00',$date.' 22:00:00');if($ok['ok']&&!$conflicts)$available[]=sa_staff_name($s);}$answer=$available?'Available with no recorded shift overlap for a 4 PM–10 PM check on '.date('l M j',strtotime($date)).': '.implode(', ',$available).'.':'No staff pass both the recorded availability and overlap check for 4 PM–10 PM on '.date('l M j',strtotime($date)).'.';$data['date']=$date;$data['availableCount']=count($available);
    } else {
        $matched=null;foreach($staff as $s){$names=array_filter([(string)($s['preferred_name']??''),(string)($s['display_name']??''),(string)($s['first_name']??'')]);foreach($names as $name)if($name!==''&&preg_match('/\b'.preg_quote($name,'/').'\b/i',$message)){$matched=$s;break 2;}}
        if($matched&&preg_match('/\b(schedule|shifts?|working)\b/i',$message)){$skill='schedule.employee';$answer=sa_staff_schedule_answer($pdo,$organizationId,$week,$matched);$data['userId']=(int)$matched['user_id'];}else{$summary=scheduling_summary($pdo,$organizationId,$week);$skill='schedule.summary';$answer='Week of '.$week.': '.$summary['scheduledHours'].' labor hours across '.$summary['shiftCount'].' shifts, '.$summary['openShifts'].' open shifts, '.$summary['scheduledStaff'].' scheduled staff, '.$summary['coverageGaps'].' coverage gaps, and '.$summary['taskHours'].' open task hours.';$data['summary']=$summary;}
    }
    app_audit($pdo,$organizationId,$userId,'schedule.agent_skill_used','schedule',$skill,null,['message'=>$message,'contextModule'=>$contextModule]);
    app_json_response(['ok'=>true,'skill'=>$skill,'answer'=>$answer,'week'=>$week,'data'=>$data,'sources'=>array_values(array_unique($sources))]);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],$e instanceof InvalidArgumentException?422:($e instanceof RuntimeException?403:500));}