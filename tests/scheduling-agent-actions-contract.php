<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/scheduling-core.php';
require_once __DIR__.'/../includes/employee-shift-communications.php';
require_once __DIR__.'/../includes/scheduling-agent-core.php';

function saa_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$pdo=app_pdo();
$slug='sched-agent-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Scheduling Agent CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,?,'active')")->execute([$org,'Main Restaurant']);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO positions (organization_id,name,slug,status) VALUES (?,?,?,'active')")->execute([$org,'Line Cook','line-cook-'.$slug]);$position=(int)$pdo->lastInsertId();

$makeUser=function(string $email,string $first,string $last)use($pdo):int{$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$email,'x',$first,$last,$first.' '.$last]);return (int)$pdo->lastInsertId();};
$managerId=$makeUser('manager-'.$slug.'@example.test','Manager','One');
$workerId=$makeUser('worker-'.$slug.'@example.test','Anthony','Cook');
foreach([[$managerId,'General Manager'],[$workerId,'Line Cook']] as [$uid,$title]){$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,?,'active')")->execute([$org,$uid,$location,$title]);$membership=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO user_positions (membership_id,position_id,is_primary,status,assigned_by) VALUES (?,?,1,'active',?)")->execute([$membership,$position,$managerId]);}
scheduling_availability_replace($pdo,$org,$workerId,[['weekday'=>5,'start'=>'16:00','end'=>'23:30','type'=>'available']],$managerId);

$manager=['id'=>$managerId,'organization_id'=>$org,'permissions'=>['schedule.agent','schedule.manage','schedule.view','staff.manage','employee.handoffs.manage']];
$selfOnly=['id'=>$workerId,'organization_id'=>$org,'permissions'=>['schedule.agent','schedule.self']];
$context=['module'=>'scheduling','week'=>'2026-09-14','activeTab'=>'schedule','selectedShiftPublicId'=>'','selectedStaffUserId'=>0];

$before=(int)$pdo->query("SELECT COUNT(*) FROM schedule_shifts WHERE organization_id={$org}")->fetchColumn();
$proposal=scheduling_agent_handle($pdo,$manager,['message'=>'schedule Anthony Friday 4 pm to 10 pm','pageContext'=>$context]);
saa_assert(($proposal['skill']??'')==='schedule.action_proposal','Shift request must return an action proposal.');
saa_assert(($proposal['data']['requiresConfirmation']??false)===true,'Shift proposal must explicitly require confirmation.');
$afterProposal=(int)$pdo->query("SELECT COUNT(*) FROM schedule_shifts WHERE organization_id={$org}")->fetchColumn();
saa_assert($afterProposal===$before,'Shift proposal must not write before confirmation.');

$confirmed=scheduling_agent_handle($pdo,$manager,['message'=>'Confirm','pageContext'=>$context]);
saa_assert(($confirmed['skill']??'')==='schedule.action_confirmed'&&($confirmed['data']['action']??'')==='shift_create','Confirm must execute the pending shift creation.');
$shiftId=(string)($confirmed['data']['shiftId']??'');$shift=scheduling_shift_by_public_id($pdo,$org,$shiftId);
saa_assert($shift!==null&&(int)$shift['user_id']===$workerId,'Confirmed shift must be assigned to the intended employee.');
saa_assert(substr((string)$shift['starts_at'],0,16)==='2026-09-18 16:00'&&substr((string)$shift['ends_at'],0,16)==='2026-09-18 22:00','Confirmed shift times are incorrect.');

$context['selectedShiftPublicId']=$shiftId;
$move=scheduling_agent_handle($pdo,$manager,['message'=>'move this shift to Friday 5 pm to 11 pm','pageContext'=>$context]);
saa_assert(($move['skill']??'')==='schedule.action_proposal','Selected shift change must require confirmation.');
$pdo->prepare("UPDATE schedule_shifts SET updated_at=DATE_ADD(NOW(6),INTERVAL 5 SECOND) WHERE organization_id=? AND public_id=?")->execute([$org,$shiftId]);
$staleBlocked=false;try{scheduling_agent_handle($pdo,$manager,['message'=>'Confirm','pageContext'=>$context]);}catch(InvalidArgumentException $e){$staleBlocked=str_contains($e->getMessage(),'changed after I proposed');}
saa_assert($staleBlocked,'A stale selected-shift proposal must be rejected at confirmation.');
$unchanged=scheduling_shift_by_public_id($pdo,$org,$shiftId);saa_assert(substr((string)$unchanged['starts_at'],0,16)==='2026-09-18 16:00','Stale confirmation must not overwrite the shift.');

$rosterBlocked=false;try{scheduling_agent_handle($pdo,$selfOnly,['message'=>'who works Friday?','pageContext'=>['module'=>'scheduling','week'=>'2026-09-14']]);}catch(SchedulingAgentPermissionException){$rosterBlocked=true;}
saa_assert($rosterBlocked,'schedule.agent plus schedule.self must not reveal the restaurant roster.');
$selfSchedule=scheduling_agent_handle($pdo,$selfOnly,['message'=>'my schedule','pageContext'=>['module'=>'scheduling','week'=>'2026-09-14']]);
saa_assert(($selfSchedule['skill']??'')==='schedule.self'&&str_contains((string)$selfSchedule['answer'],'Anthony'),'Self-only scheduling access must still return the authenticated employee schedule.');

$context['selectedShiftPublicId']='';
$publishProposal=scheduling_agent_handle($pdo,$manager,['message'=>'publish this week','pageContext'=>$context]);
saa_assert(($publishProposal['skill']??'')==='schedule.action_proposal','Week publication must require confirmation.');
$cancelled=scheduling_agent_handle($pdo,$manager,['message'=>'Cancel','pageContext'=>$context]);
saa_assert(($cancelled['skill']??'')==='schedule.action_cancelled','Cancel must discard a pending publication.');
$week=scheduling_week_start('2026-09-14');$stmt=$pdo->prepare('SELECT status FROM schedule_weeks WHERE organization_id=? AND week_start=?');$stmt->execute([$org,$week]);saa_assert((string)$stmt->fetchColumn()==='draft','Cancelled publication must leave the week draft.');

$publishProposal=scheduling_agent_handle($pdo,$manager,['message'=>'publish this week','pageContext'=>$context]);
$published=scheduling_agent_handle($pdo,$manager,['message'=>'Confirm','pageContext'=>$context]);
saa_assert(($published['data']['action']??'')==='week_publish','Confirm must execute the week publication proposal.');$stmt->execute([$org,$week]);saa_assert((string)$stmt->fetchColumn()==='published','Confirmed publication must publish the visible week.');

$events=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE organization_id={$org} AND action LIKE 'schedule.agent_%'")->fetchColumn();
saa_assert($events>=4,'Scheduling Agent actions must leave an audit trail.');
echo "scheduling-agent-actions-contract-ok shift={$shiftId} audit={$events}\n";