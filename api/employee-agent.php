<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/operations-core.php';
require_once __DIR__.'/../includes/employee-home-core.php';
$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
$employeeAccess=app_has_permission('employee.self',$user)||app_has_permission('tasks.self',$user)||app_has_permission('agent.employee_view',$user)||app_has_permission('schedule.self',$user)||app_has_permission('timeclock.self',$user)||app_has_permission('training.self_view',$user);
if(!$employeeAccess)app_json_response(['ok'=>false,'message'=>'Employee Agent permission required.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$in=app_json_input();app_verify_request_csrf($in);$message=trim((string)($in['message']??''));if($message===''||mb_strlen($message,'UTF-8')>2000)app_json_response(['ok'=>false,'message'=>'Enter an employee Agent request no longer than 2,000 characters.'],422);
$normalized=mb_strtolower(preg_replace('/^hey\s+gelato[,\s]*/iu','',$message)??$message,'UTF-8');
$homeReady=employee_home_ready($pdo);

if($homeReady&&preg_match('/\b(my day|today|employee home|what.*need.*do|what.*doing|overview|brief me)\b/u',$normalized)){
    $d=employee_home_dashboard($pdo,$org,$uid);$next=$d['shifts'][0]??null;$clock=$d['clock']['entry']??null;$parts=[];$parts[]=$clock?'You are currently clocked in.':'You are currently off the clock.';$parts[]=$next?'Your next published shift is '.$next['title'].' on '.date('D M j, g:i a',strtotime((string)$next['starts_at'])).'.':'You have no published shift in the next two weeks.';$parts[]='You have '.count($d['tasks']).' open assigned task(s), '.(int)$d['summary']['openTraining'].' open training assignment(s), and '.(int)$d['summary']['openChecklist'].' open checklist item(s).';if((int)$d['summary']['unreadAnnouncements']>0)$parts[]='You have '.(int)$d['summary']['unreadAnnouncements'].' unread restaurant update(s).';if((int)$d['summary']['unacknowledgedPolicies']>0)$parts[]='You have '.(int)$d['summary']['unacknowledgedPolicies'].' policy acknowledgement(s) waiting.';
    app_audit($pdo,$org,$uid,'agent.employee_day_used','user',(string)$uid);app_json_response(['ok'=>true,'skill'=>'employee.day','answer'=>implode(' ',$parts),'data'=>$d['summary'],'sources'=>['employee-home.php']]);
}

if($homeReady&&preg_match('/\b(announcement|announcements|restaurant update|restaurant updates|message from|updates from)\b/u',$normalized)){
    $rows=employee_home_announcements($pdo,$org,$uid);if(!$rows)app_json_response(['ok'=>true,'skill'=>'employee.announcements','answer'=>'There are no current restaurant announcements.','data'=>[],'sources'=>['employee-home.php']]);$lines=[];foreach(array_slice($rows,0,12) as $r)$lines[]=$r['title'].($r['read_at']?'':' — unread').': '.mb_substr((string)$r['body'],0,280,'UTF-8');app_json_response(['ok'=>true,'skill'=>'employee.announcements','answer'=>"Current restaurant updates:\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')]);
}

if($homeReady&&preg_match('/\b(handbook|policy|policies|acknowledg|rules for employees)\b/u',$normalized)){
    $rows=employee_home_policies($pdo,$org,$uid);if(!$rows)app_json_response(['ok'=>true,'skill'=>'employee.policies','answer'=>'There are no published employee policies in Employee Home.','data'=>[],'sources'=>['employee-home.php']]);$lines=[];foreach(array_slice($rows,0,16) as $r)$lines[]=$r['title'].' v'.$r['version_label'].((int)$r['requires_acknowledgement']===1?($r['acknowledged_at']?' — acknowledged':' — acknowledgement needed'):'');app_json_response(['ok'=>true,'skill'=>'employee.policies','answer'=>"Published employee policies:\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')]);
}

if($homeReady&&preg_match('/\b(certification|certifications|training|training due|course|courses)\b/u',$normalized)){
    $t=employee_home_training($pdo,$org,$uid);$open=array_values(array_filter($t['assignments'],static fn(array $r):bool=>empty($r['completed_at'])));$active=array_values(array_filter($t['certifications'],static fn(array $r):bool=>(string)$r['status']==='issued'&&empty($r['revoked_at'])));$lines=['You have '.count($open).' open training assignment(s) and '.count($active).' active certification(s).'];foreach(array_slice($open,0,8) as $r)$lines[]='Training: '.$r['assignment_type'].' · '.$r['assignment_reference_id'].($r['due_at']?' · due '.$r['due_at']:'');foreach(array_slice($active,0,8) as $r)$lines[]='Certification: '.$r['certification_type'].($r['expires_at']?' · expires '.$r['expires_at']:'');app_json_response(['ok'=>true,'skill'=>'employee.training','answer'=>implode("\n",$lines),'data'=>$t,'sources'=>['employee-home.php']]);
}

if($homeReady&&preg_match('/\b(onboarding|checklist|orientation|new employee)\b/u',$normalized)){
    $rows=employee_home_checklist($pdo,$org,$uid);$open=array_values(array_filter($rows,static fn(array $r):bool=>(string)$r['status']==='open'));if(!$open)app_json_response(['ok'=>true,'skill'=>'employee.checklist','answer'=>'You have no open employee checklist items.','data'=>[],'sources'=>['employee-home.php']]);$lines=[];foreach(array_slice($open,0,16) as $r)$lines[]=$r['title'].($r['due_at']?' — due '.$r['due_at']:'').((int)$r['self_completable']===1?'':' — manager completion');app_json_response(['ok'=>true,'skill'=>'employee.checklist','answer'=>"Your open checklist:\n- ".implode("\n- ",$lines),'data'=>$open,'sources'=>array_column($open,'public_id')]);
}

if($homeReady&&preg_match('/\b(employee profile|my profile|emergency contact|job title|hire date|location)\b/u',$normalized)){
    $p=employee_home_profile($pdo,$org,$uid);if(!$p)app_json_response(['ok'=>false,'message'=>'Employee profile not found.'],404);$answer='Your employee profile: '.($p['job_title']?:'Employee').($p['location_name']?' at '.$p['location_name']:'').($p['hire_date']?' · hire date '.$p['hire_date']:'').'.';if(preg_match('/\bemergency contact\b/u',$normalized))$answer.=' Your emergency contact is '.($p['emergency_contact_name']?:'not set').($p['emergency_contact_relationship']?' ('.$p['emergency_contact_relationship'].')':'').($p['emergency_contact_phone']?' · '.$p['emergency_contact_phone']:'').'. You can update it in Employee Home.';app_json_response(['ok'=>true,'skill'=>'employee.profile','answer'=>$answer,'data'=>['jobTitle'=>$p['job_title'],'location'=>$p['location_name'],'hireDate'=>$p['hire_date'],'onboardingStatus'=>$p['onboarding_status']],'sources'=>['employee-home.php']]);
}

if(operations_core_ready($pdo)&&preg_match('/\b(prep|task|tasks|assigned|assignment|to do|todo|opening|closing|cleaning)\b/u',$normalized)){
    $category=preg_match('/\bprep\b/u',$normalized)?'prep':'';$rows=operations_task_list($pdo,$org,['category'=>$category,'userId'=>$uid,'query'=>'']);if(!$rows){$answer=$category?'You do not have any open prep tasks assigned to you.':'You do not have any open restaurant tasks assigned to you.';app_json_response(['ok'=>true,'skill'=>$category?'employee.prep':'employee.tasks','answer'=>$answer,'data'=>[],'sources'=>[]]);}$lines=[];foreach(array_slice($rows,0,30) as $row)$lines[]=$row['title'].' — '.($row['status']??'queued').($row['due_at']?' — due '.$row['due_at']:'').($row['station']?' — '.$row['station']:'');$answer=($category?'Your assigned prep:':'Your assigned restaurant tasks:')."\n- ".implode("\n- ",$lines);app_audit($pdo,$org,$uid,'agent.employee_tasks_used','user',(string)$uid,null,['count'=>count($rows),'category'=>$category?:'all']);app_json_response(['ok'=>true,'skill'=>$category?'employee.prep':'employee.tasks','answer'=>$answer,'data'=>$rows,'sources'=>array_column($rows,'public_id')]);
}
app_json_response(['ok'=>true,'skill'=>'employee.context','answer'=>'I can help with your employee day, published shifts, time clock, assigned prep and tasks, training, certifications, onboarding checklist, policies, restaurant announcements, and your employee profile.','data'=>null,'sources'=>['employee-home.php']]);
