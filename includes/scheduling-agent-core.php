<?php
declare(strict_types=1);

final class SchedulingAgentPermissionException extends RuntimeException {}

function sac_has(array $user,string $permission): bool{return app_has_permission($permission,$user);}
function sac_require(array $user,string $permission,string $message='Permission required.'): void{if(!sac_has($user,$permission))throw new SchedulingAgentPermissionException($message);}
function sac_view_all(array $user): bool{return sac_has($user,'schedule.view')||sac_has($user,'schedule.manage');}
function sac_view_self(array $user): bool{return sac_has($user,'schedule.self');}
function sac_staff_name(array $row): string{return trim((string)($row['preferred_name']??''))?:trim((string)($row['display_name']??''))?:'Employee';}

function sac_staff_match(array $staff,string $query): ?array
{
    $query=mb_strtolower(trim(preg_replace('/\s+(?:on|for|from|at)\s+.+$/iu','',$query)??$query),'UTF-8');
    if($query==='')return null;$exact=[];$partial=[];
    foreach($staff as $row){
        foreach(array_filter([(string)($row['preferred_name']??''),(string)($row['display_name']??''),(string)($row['first_name']??'')]) as $name){
            $candidate=mb_strtolower($name,'UTF-8');
            if($candidate===$query)$exact[]=$row;
            elseif(str_contains($candidate,$query)||str_contains($query,$candidate))$partial[]=$row;
        }
    }
    return $exact[0]??($partial[0]??null);
}

function sac_day_date(string $text,string $week): ?string
{
    $days=['monday'=>0,'tuesday'=>1,'wednesday'=>2,'thursday'=>3,'friday'=>4,'saturday'=>5,'sunday'=>6];
    foreach($days as $name=>$offset)if(preg_match('/\b'.$name.'\b/i',$text))return (new DateTimeImmutable($week))->modify('+'.$offset.' days')->format('Y-m-d');
    if(preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/',$text,$match))return $match[1];
    return null;
}

function sac_time(string $raw,bool $end=false): ?string
{
    $raw=strtolower(trim($raw));
    if(!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/',$raw,$match))return null;
    $hour=(int)$match[1];$minute=(int)($match[2]??0);$period=$match[3]??'';
    if($period==='pm'&&$hour<12)$hour+=12;if($period==='am'&&$hour===12)$hour=0;
    if($period===''&&$hour<=10&&$end)$hour+=12;if($period===''&&$hour>=1&&$hour<=8&&!$end)$hour+=12;
    if($hour>23||$minute>59)return null;
    return sprintf('%02d:%02d',$hour,$minute);
}

function sac_time_range(string $text): ?array
{
    if(!preg_match('/(?:from\s+)?(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*(?:to|\-|–)\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i',$text,$match))return null;
    $start=sac_time(trim($match[1]),false);$end=sac_time(trim($match[2]),true);
    return $start&&$end?[$start,$end]:null;
}

function sac_staff_schedule_answer(PDO $pdo,int $org,string $week,array $staff): string
{
    $rows=scheduling_shifts($pdo,$org,$week,(int)$staff['user_id']);
    if(!$rows)return sac_staff_name($staff).' has no shifts scheduled for the week of '.$week.'.';
    $parts=[];foreach($rows as $shift)$parts[]=date('D M j',strtotime($shift['starts_at'])).' '.date('g:i A',strtotime($shift['starts_at'])).'–'.date('g:i A',strtotime($shift['ends_at'])).' '.$shift['title'];
    return sac_staff_name($staff).': '.implode('; ',$parts).'.';
}

function sac_shift_summary(array $shift): string
{
    return ($shift['title']?:'Shift').' · '.date('l M j',strtotime($shift['starts_at'])).' '.date('g:i A',strtotime($shift['starts_at'])).'–'.date('g:i A',strtotime($shift['ends_at'])).' · '.($shift['display_name']?:'Open shift').($shift['position_name']?' · '.$shift['position_name']:'').($shift['location_name']?' · '.$shift['location_name']:'');
}

function sac_selected_shift(PDO $pdo,int $org,int $userId,array $user,array $context): ?array
{
    $public=preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)($context['selectedShiftPublicId']??'')))??'';
    if($public==='')return null;
    $shift=scheduling_shift_by_public_id($pdo,$org,$public);if(!$shift)return null;
    if(sac_view_all($user))return $shift;
    if(sac_view_self($user)&&(int)($shift['user_id']??0)===$userId)return $shift;
    throw new SchedulingAgentPermissionException('Schedule view permission is required for that selected shift.');
}

function sac_selected_staff(PDO $pdo,int $org,int $userId,array $user,array $context): ?array
{
    $target=(int)($context['selectedStaffUserId']??0);if($target<=0)return null;
    $staff=scheduling_staff_user($pdo,$org,$target);if(!$staff)return null;
    if($target===$userId||sac_view_all($user)||sac_has($user,'staff.view')||sac_has($user,'staff.manage'))return $staff;
    throw new SchedulingAgentPermissionException('Staff view permission is required for that selected employee.');
}

function sac_pending_key(int $org,int $userId): string{return $org.':'.$userId;}
function sac_pending_get(int $org,int $userId): ?array
{
    $key=sac_pending_key($org,$userId);$row=$_SESSION['schedule_agent_pending'][$key]??null;
    if(!is_array($row)||($row['expires']??0)<time()){unset($_SESSION['schedule_agent_pending'][$key]);return null;}
    return $row;
}
function sac_pending_store(int $org,int $userId,string $type,array $payload,string $summary): array
{
    $row=['id'=>'sap-'.bin2hex(random_bytes(8)),'organizationId'=>$org,'userId'=>$userId,'type'=>$type,'payload'=>$payload,'summary'=>$summary,'expires'=>time()+600];
    $_SESSION['schedule_agent_pending'][sac_pending_key($org,$userId)]=$row;return $row;
}
function sac_pending_clear(int $org,int $userId): void{unset($_SESSION['schedule_agent_pending'][sac_pending_key($org,$userId)]);}
function sac_proposal_result(array $proposal,array $sources): array
{
    return ['ok'=>true,'skill'=>'schedule.action_proposal','answer'=>$proposal['summary']."\n\nReply Confirm to execute this change, or Cancel to discard it.",'data'=>['requiresConfirmation'=>true,'proposal'=>['id'=>$proposal['id'],'type'=>$proposal['type'],'summary'=>$proposal['summary'],'expiresAt'=>date(DATE_ATOM,(int)$proposal['expires'])]],'sources'=>$sources];
}

function sac_week_snapshot(PDO $pdo,int $org,string $week): array
{
    $query=$pdo->prepare('SELECT week_start,status,updated_at FROM schedule_weeks WHERE organization_id=? AND week_start=? LIMIT 1');$query->execute([$org,scheduling_week_start($week)]);$row=$query->fetch();
    return $row?['exists'=>true,'status'=>(string)$row['status'],'updatedAt'=>(string)$row['updated_at']]:['exists'=>false,'status'=>'draft','updatedAt'=>''];
}

function sac_guard_shift_version(int $org,int $userId,array $proposal,array $shift): void
{
    $payload=(array)$proposal['payload'];$expected=(string)($payload['expectedUpdatedAt']??'');
    if($expected!==''&&$expected!==(string)($shift['updated_at']??'')){sac_pending_clear($org,$userId);throw new InvalidArgumentException('That shift changed after I proposed the action. Review the current shift and ask again.');}
}

function sac_validate_candidate(PDO $pdo,int $org,int $staffId,string $start,string $end,?int $excludeShiftId=null): void
{
    $availability=scheduling_availability_check($pdo,$org,$staffId,$start,$end);if(!$availability['ok'])throw new InvalidArgumentException($availability['reason']?:'The employee is unavailable for that shift.');
    if(scheduling_shift_conflicts($pdo,$org,$staffId,$start,$end,$excludeShiftId))throw new InvalidArgumentException('Employee already has an overlapping shift.');
}

function sac_execute_pending(PDO $pdo,int $org,int $userId,array $user,array $proposal): array
{
    if((int)($proposal['organizationId']??0)!==$org||(int)($proposal['userId']??0)!==$userId)throw new SchedulingAgentPermissionException('This Scheduling Agent proposal does not belong to your session.');
    $type=(string)$proposal['type'];$payload=(array)$proposal['payload'];
    if(in_array($type,['shift_create','shift_update','shift_cancel','week_publish'],true))sac_require($user,'schedule.manage','Schedule manage permission is required for this action.');

    if($type==='shift_create'){
        $staff=scheduling_staff_user($pdo,$org,(int)($payload['userId']??0));if(!$staff)throw new InvalidArgumentException('The proposed employee is no longer active restaurant staff.');
        $shift=scheduling_shift_save($pdo,$org,$payload,$userId);app_audit($pdo,$org,$userId,'schedule.agent_shift_created','schedule_shift',(string)$shift['public_id'],null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'schedule.action_confirmed','answer'=>'Confirmed. Scheduled '.sac_staff_name($staff).' on '.date('l M j',strtotime($shift['starts_at'])).' from '.date('g:i A',strtotime($shift['starts_at'])).' to '.date('g:i A',strtotime($shift['ends_at'])).'.','data'=>['action'=>'shift_create','shiftId'=>$shift['public_id']],'sources'=>['Staff Scheduling','Employee Availability']];
    }
    if($type==='shift_update'){
        $existing=scheduling_shift_by_public_id($pdo,$org,(string)($payload['shiftId']??''));if(!$existing)throw new InvalidArgumentException('The selected shift no longer exists.');sac_guard_shift_version($org,$userId,$proposal,$existing);
        $changes=(array)($payload['changes']??[]);$shift=scheduling_shift_save($pdo,$org,$changes,$userId,$existing);app_audit($pdo,$org,$userId,'schedule.agent_shift_updated','schedule_shift',(string)$shift['public_id'],null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'schedule.action_confirmed','answer'=>'Confirmed. Updated '.$shift['title'].' to '.date('l M j',strtotime($shift['starts_at'])).' '.date('g:i A',strtotime($shift['starts_at'])).'–'.date('g:i A',strtotime($shift['ends_at'])).'.','data'=>['action'=>'shift_update','shiftId'=>$shift['public_id']],'sources'=>['Staff Scheduling','Employee Availability']];
    }
    if($type==='shift_cancel'){
        $shift=scheduling_shift_by_public_id($pdo,$org,(string)($payload['shiftId']??''));if(!$shift)throw new InvalidArgumentException('The selected shift no longer exists.');sac_guard_shift_version($org,$userId,$proposal,$shift);
        $statement=$pdo->prepare("UPDATE schedule_shifts SET status='cancelled',archived_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND archived_at IS NULL");$statement->execute([$userId,$org,(int)$shift['id']]);if($statement->rowCount()!==1)throw new InvalidArgumentException('The selected shift changed before cancellation.');
        scheduling_shift_event($pdo,$org,(int)$shift['id'],'shift.cancelled','Shift cancelled through Gelato Agent',$userId,['proposalId'=>$proposal['id']]);app_audit($pdo,$org,$userId,'schedule.agent_shift_cancelled','schedule_shift',(string)$shift['public_id']);
        return ['skill'=>'schedule.action_confirmed','answer'=>'Confirmed. Cancelled '.$shift['title'].' on '.date('l M j',strtotime($shift['starts_at'])).'.','data'=>['action'=>'shift_cancel','shiftId'=>$shift['public_id']],'sources'=>['Staff Scheduling']];
    }
    if($type==='week_publish'){
        $week=scheduling_week_start((string)($payload['week']??''));$current=sac_week_snapshot($pdo,$org,$week);$expected=(array)($payload['expected']??[]);
        if((bool)$current['exists']!==(bool)($expected['exists']??false)||(string)$current['status']!==(string)($expected['status']??'draft')||(string)$current['updatedAt']!==(string)($expected['updatedAt']??'')){sac_pending_clear($org,$userId);throw new InvalidArgumentException('That schedule week changed after I proposed publishing it. Review the week and ask again.');}
        $row=scheduling_ensure_week($pdo,$org,$week,$userId);$pdo->prepare("UPDATE schedule_weeks SET status='published',published_at=NOW(6),published_by=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$userId,$userId,(int)$row['id'],$org]);app_audit($pdo,$org,$userId,'schedule.agent_week_published','schedule_week',$week,null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'schedule.action_confirmed','answer'=>'Confirmed. Published the schedule for the week of '.$week.'.','data'=>['action'=>'week_publish','week'=>$week],'sources'=>['Staff Scheduling']];
    }
    if($type==='employee_message'){
        if(!employee_shift_comms_ready($pdo))throw new RuntimeException('Employee shift communications are not installed. Run upgrade.php.');
        if(!sac_has($user,'employee.handoffs.manage')&&!sac_has($user,'staff.manage'))throw new SchedulingAgentPermissionException('Employee communication management permission is required.');
        $target=scheduling_staff_user($pdo,$org,(int)($payload['userId']??0));if(!$target)throw new InvalidArgumentException('The selected employee is no longer active restaurant staff.');
        $row=employee_shift_message_save($pdo,$org,['targetType'=>'user','targetId'=>(int)$target['user_id'],'messageType'=>'announcement','priority'=>'normal','title'=>'Schedule update','body'=>(string)($payload['body']??'')],$userId,true);app_audit($pdo,$org,$userId,'schedule.agent_employee_message','employee_shift_message',(string)($row['public_id']??''),null,['targetUserId'=>(int)$target['user_id'],'proposalId'=>$proposal['id']]);
        return ['skill'=>'schedule.action_confirmed','answer'=>'Confirmed. Sent the schedule message to '.sac_staff_name($target).'.','data'=>['action'=>'employee_message','messageId'=>$row['public_id']??null],'sources'=>['Employee Shift Communications']];
    }
    throw new InvalidArgumentException('That pending Scheduling Agent action is no longer supported.');
}

function scheduling_agent_handle(PDO $pdo,array $user,array $input): array
{
    $org=(int)$user['organization_id'];$userId=(int)$user['id'];
    $message=trim((string)($input['message']??''));if($message===''||mb_strlen($message,'UTF-8')>1800)throw new InvalidArgumentException('Ask Gelato a scheduling question no longer than 1,800 characters.');
    if(!sac_view_all($user)&&!sac_view_self($user)&&!sac_has($user,'staff.view')&&!sac_has($user,'staff.manage'))throw new SchedulingAgentPermissionException('Scheduling or staff visibility permission is required.');
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];$contextModule=(string)($context['module']??'');
    $contextWeek=preg_match('/^20\d{2}-\d{2}-\d{2}$/',(string)($context['week']??''))?(string)$context['week']:'';$week=scheduling_week_start($contextWeek!==''?$contextWeek:(string)($input['week']??''));
    $viewAll=sac_view_all($user);$staff=$viewAll||sac_has($user,'staff.manage')?scheduling_staff($pdo,$org):array_values(array_filter([scheduling_staff_user($pdo,$org,$userId)]));
    $selectedShift=$contextModule==='scheduling'?sac_selected_shift($pdo,$org,$userId,$user,$context):null;
    $selectedStaff=$contextModule==='scheduling'?sac_selected_staff($pdo,$org,$userId,$user,$context):null;
    $pending=sac_pending_get($org,$userId);

    if(preg_match('/^(?:confirm|yes|yes please|do it|go ahead|execute|apply)(?:\s+(?:it|that|change|action))?[.!]?$/i',$message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Scheduling Agent action to confirm.');
        $result=sac_execute_pending($pdo,$org,$userId,$user,$pending);sac_pending_clear($org,$userId);app_audit($pdo,$org,$userId,'schedule.agent_action_confirmed','schedule_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);return ['ok'=>true]+$result;
    }
    if(preg_match('/^(?:cancel|cancel it|discard|never mind|nevermind|stop)[.!]?$/i',$message)&&$pending){sac_pending_clear($org,$userId);app_audit($pdo,$org,$userId,'schedule.agent_action_discarded','schedule_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);return ['ok'=>true,'skill'=>'schedule.action_cancelled','answer'=>'Cancelled. I did not change the schedule.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Staff Scheduling']];}

    if($selectedShift&&preg_match('/\b(?:who can cover|who could cover|coverage candidates?|available to cover)\b/i',$message)){
        if(!$viewAll)throw new SchedulingAgentPermissionException('Schedule view permission is required to compare coverage candidates.');$candidates=[];
        foreach($staff as $row){$target=(int)$row['user_id'];if($target===(int)($selectedShift['user_id']??0))continue;$availability=scheduling_availability_check($pdo,$org,$target,(string)$selectedShift['starts_at'],(string)$selectedShift['ends_at']);$conflicts=scheduling_shift_conflicts($pdo,$org,$target,(string)$selectedShift['starts_at'],(string)$selectedShift['ends_at']);if($availability['ok']&&!$conflicts)$candidates[]=sac_staff_name($row);}
        return ['ok'=>true,'skill'=>'schedule.context_coverage_candidates','answer'=>$candidates?'Recorded availability and overlap checks identify these candidates for the selected shift: '.implode(', ',$candidates).'. Verify role/skill suitability before assigning.':'No active staff pass both the recorded availability and overlap checks for the selected shift.','data'=>['shiftId'=>$selectedShift['public_id'],'candidateCount'=>count($candidates)],'sources'=>['Staff Scheduling','Employee Availability']];
    }
    if($selectedShift&&preg_match('/\b(?:what is|show|describe|details?|about)\s+(?:this|selected)\s+shift\b/i',$message))return ['ok'=>true,'skill'=>'schedule.context_shift','answer'=>sac_shift_summary($selectedShift).'.','data'=>['shiftId'=>$selectedShift['public_id']],'sources'=>['Staff Scheduling']];
    if($selectedStaff&&preg_match('/\b(?:when do they work|when does (?:he|she) work|this employee|their schedule|their shifts?|what about them|when do they work next)\b/i',$message)){if(!$viewAll&&(int)$selectedStaff['user_id']!==$userId)throw new SchedulingAgentPermissionException('Schedule view permission is required for another employee’s shifts.');return ['ok'=>true,'skill'=>'schedule.context_employee','answer'=>sac_staff_schedule_answer($pdo,$org,$week,$selectedStaff),'data'=>['userId'=>(int)$selectedStaff['user_id'],'week'=>$week],'sources'=>['Staff Scheduling']];}

    if($selectedShift&&preg_match('/\b(?:cancel|remove|delete)\s+(?:this|selected)\s+shift\b/i',$message)){
        sac_require($user,'schedule.manage','Schedule manage permission is required for Agent shift cancellation.');$summary='Proposed action: cancel '.sac_shift_summary($selectedShift).'.';$proposal=sac_pending_store($org,$userId,'shift_cancel',['shiftId'=>$selectedShift['public_id'],'expectedUpdatedAt'=>(string)($selectedShift['updated_at']??'')],$summary);app_audit($pdo,$org,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'shift_cancel']);return sac_proposal_result($proposal,['Staff Scheduling']);
    }
    if($selectedShift&&preg_match('/\b(?:move|change|update|reschedule)\s+(?:this|selected)\s+shift\b/i',$message)){
        sac_require($user,'schedule.manage','Schedule manage permission is required for Agent shift changes.');$range=sac_time_range($message);if(!$range)throw new InvalidArgumentException('Include the new start and end time, for example “move this shift to Friday 5 pm to 11 pm”.');$date=sac_day_date($message,$week)?:substr((string)$selectedShift['starts_at'],0,10);
        $changes=['title'=>$selectedShift['title'],'startsAt'=>$date.' '.$range[0].':00','endsAt'=>$date.' '.$range[1].':00','userId'=>$selectedShift['user_id']?:'','positionId'=>$selectedShift['position_id']?:'','locationId'=>$selectedShift['location_id']?:'','breakMinutes'=>$selectedShift['break_minutes']??0,'status'=>$selectedShift['status'],'notes'=>$selectedShift['notes']??'','enforceAvailability'=>true];if(!empty($selectedShift['user_id']))sac_validate_candidate($pdo,$org,(int)$selectedShift['user_id'],$changes['startsAt'],$changes['endsAt'],(int)$selectedShift['id']);
        $summary='Proposed action: move '.($selectedShift['display_name']?:'the selected employee')."'s ".$selectedShift['title'].' shift to '.date('l M j',strtotime($date)).' '.date('g:i A',strtotime($changes['startsAt'])).'–'.date('g:i A',strtotime($changes['endsAt'])).'.';$proposal=sac_pending_store($org,$userId,'shift_update',['shiftId'=>$selectedShift['public_id'],'expectedUpdatedAt'=>(string)($selectedShift['updated_at']??''),'changes'=>$changes],$summary);app_audit($pdo,$org,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'shift_update']);return sac_proposal_result($proposal,['Staff Scheduling','Employee Availability']);
    }
    if($selectedShift&&preg_match('/\b(?:assign|give)\s+(?:this|selected)\s+shift\s+to\s+(.+)$/i',$message,$match)){
        sac_require($user,'schedule.manage','Schedule manage permission is required for Agent shift assignment.');$person=sac_staff_match(scheduling_staff($pdo,$org),trim($match[1]));if(!$person)throw new InvalidArgumentException('I could not match that employee name.');sac_validate_candidate($pdo,$org,(int)$person['user_id'],(string)$selectedShift['starts_at'],(string)$selectedShift['ends_at'],(int)$selectedShift['id']);
        $changes=['title'=>$selectedShift['title'],'startsAt'=>$selectedShift['starts_at'],'endsAt'=>$selectedShift['ends_at'],'userId'=>(int)$person['user_id'],'positionId'=>$selectedShift['position_id']?:'','locationId'=>$selectedShift['location_id']?:'','breakMinutes'=>$selectedShift['break_minutes']??0,'status'=>'scheduled','notes'=>$selectedShift['notes']??'','enforceAvailability'=>true];$summary='Proposed action: assign the selected '.$selectedShift['title'].' shift on '.date('l M j',strtotime($selectedShift['starts_at'])).' to '.sac_staff_name($person).'.';$proposal=sac_pending_store($org,$userId,'shift_update',['shiftId'=>$selectedShift['public_id'],'expectedUpdatedAt'=>(string)($selectedShift['updated_at']??''),'changes'=>$changes],$summary);app_audit($pdo,$org,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'shift_assign']);return sac_proposal_result($proposal,['Staff Scheduling','Employee Availability']);
    }
    if($selectedStaff&&preg_match('/^\s*(?:message|tell|notify)\s+(?:this employee|them|him|her)(?:\s+(?:that|:|-))?\s+(.+)$/iu',$message,$match)){
        if(!sac_has($user,'employee.handoffs.manage')&&!sac_has($user,'staff.manage'))throw new SchedulingAgentPermissionException('Employee communication management permission is required.');$body=mb_substr(trim($match[1]),0,1200,'UTF-8');if($body==='')throw new InvalidArgumentException('Include the message you want to send.');$summary='Proposed action: send '.sac_staff_name($selectedStaff).' this schedule message: “'.mb_substr($body,0,360,'UTF-8').'”';$proposal=sac_pending_store($org,$userId,'employee_message',['userId'=>(int)$selectedStaff['user_id'],'body'=>$body],$summary);app_audit($pdo,$org,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'employee_message']);return sac_proposal_result($proposal,['Employee Shift Communications']);
    }
    if(preg_match('/\bpublish\s+(?:this|the|visible)?\s*(?:schedule\s+)?week\b/i',$message)){
        sac_require($user,'schedule.manage','Schedule manage permission is required to publish schedules.');$expected=sac_week_snapshot($pdo,$org,$week);$summary='Proposed action: publish the schedule for the week of '.$week.'.';$proposal=sac_pending_store($org,$userId,'week_publish',['week'=>$week,'expected'=>$expected],$summary);app_audit($pdo,$org,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'week_publish']);return sac_proposal_result($proposal,['Staff Scheduling']);
    }
    if(preg_match('/\b(schedule|assign)\s+(.+?)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday|20\d{2}-\d{2}-\d{2})\s+(?:from\s+)?([0-9: ]+(?:am|pm)?)\s+(?:to|-)\s+([0-9: ]+(?:am|pm)?)/i',$message,$match)){
        sac_require($user,'schedule.manage','Schedule manage permission is required for Agent shift creation.');$person=sac_staff_match(scheduling_staff($pdo,$org),trim($match[2]));if(!$person)throw new InvalidArgumentException('I could not match that employee name.');$date=sac_day_date($match[3],$week);$start=sac_time(trim($match[4]),false);$end=sac_time(trim($match[5]),true);if(!$date||!$start||!$end)throw new InvalidArgumentException('I could not parse the requested shift date/time. Try “schedule Anthony Friday 4 pm to 10 pm”.');$payload=['title'=>($person['job_title']?:'Shift'),'startsAt'=>$date.' '.$start.':00','endsAt'=>$date.' '.$end.':00','userId'=>(int)$person['user_id'],'status'=>'scheduled','enforceAvailability'=>true];sac_validate_candidate($pdo,$org,(int)$person['user_id'],$payload['startsAt'],$payload['endsAt']);$summary='Proposed action: schedule '.sac_staff_name($person).' on '.date('l M j',strtotime($date)).' from '.date('g:i A',strtotime($payload['startsAt'])).' to '.date('g:i A',strtotime($payload['endsAt'])).'.';$proposal=sac_pending_store($org,$userId,'shift_create',$payload,$summary);app_audit($pdo,$org,$userId,'schedule.agent_action_proposed','schedule_agent_proposal',(string)$proposal['id'],null,['type'=>'shift_create']);return sac_proposal_result($proposal,['Staff Scheduling','Employee Availability']);
    }

    if(preg_match('/\b(who\s+(?:is\s+)?works?|who\s+is\s+working|working)\b/i',$message)&&($date=sac_day_date($message,$week))){if(!$viewAll)throw new SchedulingAgentPermissionException('Schedule view permission is required for the restaurant roster.');$rows=array_values(array_filter(scheduling_shifts($pdo,$org,$week),fn($shift)=>substr((string)$shift['starts_at'],0,10)===$date&&$shift['status']!=='cancelled'));$answer='No shifts are scheduled for '.date('l M j',strtotime($date)).'.';if($rows){$parts=[];foreach($rows as $shift)$parts[]=($shift['display_name']?:'Open shift').' '.date('g:i A',strtotime($shift['starts_at'])).'–'.date('g:i A',strtotime($shift['ends_at'])).($shift['position_name']?' '.$shift['position_name']:'');$answer=date('l M j',strtotime($date)).': '.implode('; ',$parts).'.';}return ['ok'=>true,'skill'=>'schedule.day_roster','answer'=>$answer,'data'=>['week'=>$week,'date'=>$date],'sources'=>['Staff Scheduling']];}
    if(preg_match('/\b(open|unassigned)\s+shifts?\b/i',$message)){if(!$viewAll)throw new SchedulingAgentPermissionException('Schedule view permission is required for open shifts.');$rows=array_values(array_filter(scheduling_shifts($pdo,$org,$week),fn($shift)=>!$shift['user_id']&&$shift['status']==='open'));$answer='There are no open shifts for the week of '.$week.'.';if($rows){$parts=[];foreach($rows as $shift)$parts[]=date('D M j',strtotime($shift['starts_at'])).' '.date('g:i A',strtotime($shift['starts_at'])).'–'.date('g:i A',strtotime($shift['ends_at'])).' '.$shift['title'];$answer=count($rows).' open shift'.(count($rows)===1?'':'s').': '.implode('; ',$parts).'.';}return ['ok'=>true,'skill'=>'schedule.open_shifts','answer'=>$answer,'data'=>['week'=>$week,'openShifts'=>count($rows)],'sources'=>['Staff Scheduling']];}
    if(preg_match('/\b(coverage|short staffed|short-staffed|staffing gap|staffing gaps)\b/i',$message)){if(!$viewAll)throw new SchedulingAgentPermissionException('Schedule view permission is required for coverage analysis.');$coverage=scheduling_coverage($pdo,$org,$week);$gaps=array_values(array_filter($coverage,fn($row)=>(int)$row['gap']>0));$answer='No minimum-staff coverage gaps are detected for the week of '.$week.'.';if($gaps){$parts=[];foreach($gaps as $row)$parts[]=date('D M j',strtotime($row['date'])).' '.$row['label'].' is '.$row['gap'].' short ('.$row['scheduled'].' scheduled / '.$row['minimum'].' minimum)';$answer='Coverage gaps: '.implode('; ',$parts).'.';}return ['ok'=>true,'skill'=>'schedule.coverage','answer'=>$answer,'data'=>['week'=>$week,'coverageGaps'=>count($gaps)],'sources'=>['Staff Scheduling','Coverage Rules']];}
    if(preg_match('/\b(workload|task hours|prep hours|labor hours)\b/i',$message)){if(!$viewAll)throw new SchedulingAgentPermissionException('Schedule view permission is required for workload analysis.');$summary=scheduling_summary($pdo,$org,$week);return ['ok'=>true,'skill'=>'schedule.workload','answer'=>'Week of '.$week.': '.$summary['scheduledHours'].' scheduled labor hours, '.$summary['taskHours'].' open task/prep hours, '.$summary['shiftCount'].' shifts, '.$summary['openShifts'].' open shifts, and '.$summary['coverageGaps'].' coverage gaps.','data'=>['week'=>$week,'summary'=>$summary],'sources'=>['Staff Scheduling','Operations Workload']];}
    if(preg_match('/\b(available|availability)\b/i',$message)&&($date=sac_day_date($message,$week))){if(!$viewAll)throw new SchedulingAgentPermissionException('Schedule view permission is required to compare staff availability.');$available=[];foreach($staff as $row){$ok=scheduling_availability_check($pdo,$org,(int)$row['user_id'],$date.' 16:00:00',$date.' 22:00:00');$conflicts=scheduling_shift_conflicts($pdo,$org,(int)$row['user_id'],$date.' 16:00:00',$date.' 22:00:00');if($ok['ok']&&!$conflicts)$available[]=sac_staff_name($row);}return ['ok'=>true,'skill'=>'schedule.availability','answer'=>$available?'Available with no recorded shift overlap for a 4 PM–10 PM check on '.date('l M j',strtotime($date)).': '.implode(', ',$available).'.':'No staff pass both the recorded availability and overlap check for 4 PM–10 PM on '.date('l M j',strtotime($date)).'.','data'=>['week'=>$week,'date'=>$date,'availableCount'=>count($available)],'sources'=>['Staff Scheduling','Employee Availability']];}

    $matched=null;foreach($staff as $row){foreach(array_filter([(string)($row['preferred_name']??''),(string)($row['display_name']??''),(string)($row['first_name']??'')]) as $name)if($name!==''&&preg_match('/\b'.preg_quote($name,'/').'\b/i',$message)){$matched=$row;break 2;}}
    if($matched&&preg_match('/\b(schedule|shifts?|working)\b/i',$message)){if(!$viewAll&&(int)$matched['user_id']!==$userId)throw new SchedulingAgentPermissionException('Schedule view permission is required for another employee’s shifts.');return ['ok'=>true,'skill'=>'schedule.employee','answer'=>sac_staff_schedule_answer($pdo,$org,$week,$matched),'data'=>['week'=>$week,'userId'=>(int)$matched['user_id']],'sources'=>['Staff Scheduling']];}
    if(!$viewAll){$self=scheduling_staff_user($pdo,$org,$userId);return ['ok'=>true,'skill'=>'schedule.self','answer'=>$self?sac_staff_schedule_answer($pdo,$org,$week,$self):'No active staff scheduling profile is available for your account.','data'=>['week'=>$week,'userId'=>$userId],'sources'=>['Staff Scheduling']];}
    $summary=scheduling_summary($pdo,$org,$week);return ['ok'=>true,'skill'=>'schedule.summary','answer'=>'Week of '.$week.': '.$summary['scheduledHours'].' labor hours across '.$summary['shiftCount'].' shifts, '.$summary['openShifts'].' open shifts, '.$summary['scheduledStaff'].' scheduled staff, '.$summary['coverageGaps'].' coverage gaps, and '.$summary['taskHours'].' open task hours.','data'=>['week'=>$week,'summary'=>$summary],'sources'=>['Staff Scheduling']];
}