<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/employee-home-core.php';

$pdo=app_pdo();
function must(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Employee Home CI','active','America/Phoenix'),('Other Restaurant','active','America/Phoenix')");
$org=(int)$pdo->query("SELECT id FROM organizations WHERE name='Employee Home CI'")->fetchColumn();$otherOrg=(int)$pdo->query("SELECT id FROM organizations WHERE name='Other Restaurant'")->fetchColumn();
$hash=password_hash('EmployeeHome-CI-2026',PASSWORD_DEFAULT);
$u=$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')");
$u->execute(['employee@example.test',$hash,'CI','Employee','CI Employee']);$uid=(int)$pdo->lastInsertId();
$u->execute(['coworker@example.test',$hash,'CI','Coworker','CI Coworker']);$coworker=(int)$pdo->lastInsertId();
$u->execute(['outsider@example.test',$hash,'Other','Employee','Other Employee']);$outsider=(int)$pdo->lastInsertId();
$m=$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,job_title,hire_date,status) VALUES (?,?,?,?,'active')");$m->execute([$org,$uid,'Server','2026-08-01']);$m->execute([$org,$coworker,'Cook','2026-08-02']);$m->execute([$otherOrg,$outsider,'Server','2026-08-03']);
$pdo->prepare("INSERT INTO staff_profiles (organization_id,user_id,preferred_name,employment_type,created_by,updated_by) VALUES (?,?,?,'hourly',?,?)")->execute([$org,$uid,'Casey',$uid,$uid]);
$pdo->prepare("INSERT INTO staff_profiles (organization_id,user_id,preferred_name,employment_type,created_by,updated_by) VALUES (?,?,?,'hourly',?,?)")->execute([$org,$coworker,'Taylor',$uid,$uid]);

must(employee_home_ready($pdo),'Employee Home schema is not ready.');
$p=employee_home_profile_self_save($pdo,$org,$uid,['emergencyContactName'=>'Alex Employee','emergencyContactRelationship'=>'Sibling','emergencyContactPhone'=>'555-0102'],$uid);
must(($p['emergency_contact_name']??'')==='Alex Employee','Self profile save failed.');must(($p['preferred_name']??'')==='Casey','Staff profile projection failed.');
$p=employee_home_profile_manager_save($pdo,$org,$uid,['startDate'=>'2026-08-01','onboardingStatus'=>'in_progress'],$uid);must(($p['onboarding_status']??'')==='in_progress','Onboarding status failed.');

$ann=employee_home_announcement_save($pdo,$org,['title'=>'Team meeting','body'=>'Meet by the prep table at 3 PM.','priority'=>'high','status'=>'published'],$uid);$anns=employee_home_announcements($pdo,$org,$uid);must(count($anns)===1&&empty($anns[0]['read_at']),'Announcement projection failed.');employee_home_announcement_read($pdo,$org,$uid,(string)$ann['public_id']);$anns=employee_home_announcements($pdo,$org,$uid);must(!empty($anns[0]['read_at']),'Announcement read failed.');must(employee_home_announcements($pdo,$otherOrg,$outsider)===[],'Announcement organization isolation failed.');

$policy=employee_home_policy_save($pdo,$org,['title'=>'Uniform policy','version'=>'2.0','body'=>'Wear the approved uniform during scheduled shifts.','effectiveDate'=>'2026-09-14','requiresAcknowledgement'=>true,'status'=>'published'],$uid);$policies=employee_home_policies($pdo,$org,$uid);must(count($policies)===1&&empty($policies[0]['acknowledged_at']),'Policy projection failed.');employee_home_policy_acknowledge($pdo,$org,$uid,(string)$policy['public_id']);$policies=employee_home_policies($pdo,$org,$uid);must(!empty($policies[0]['acknowledged_at']),'Policy acknowledgement failed.');

$item=employee_home_checklist_save($pdo,$org,['userId'=>$uid,'phase'=>'onboarding','title'=>'Review kitchen map','description'=>'Know exits and hand sinks.','selfCompletable'=>true],$uid);employee_home_checklist_complete($pdo,$org,$uid,(string)$item['public_id'],$uid,false);$rows=employee_home_checklist($pdo,$org,$uid);must(($rows[0]['status']??'')==='completed','Checklist self completion failed.');
$otherItem=employee_home_checklist_save($pdo,$org,['userId'=>$coworker,'phase'=>'onboarding','title'=>'Meet trainer','selfCompletable'=>true],$uid);$blocked=false;try{employee_home_checklist_complete($pdo,$org,$uid,(string)$otherItem['public_id'],$uid,false);}catch(RuntimeException){$blocked=true;}must($blocked,'Employee could complete another employee checklist item.');

$pdo->prepare("INSERT INTO schedule_weeks (organization_id,week_start,status,published_at,published_by,created_by,updated_by) VALUES (?,'2026-09-14','published',NOW(6),?,?,?)")->execute([$org,$uid,$uid,$uid]);$publishedWeek=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO schedule_weeks (organization_id,week_start,status,created_by,updated_by) VALUES (?,'2026-09-21','draft',?,?)")->execute([$org,$uid,$uid]);$draftWeek=(int)$pdo->lastInsertId();
$s=$pdo->prepare("INSERT INTO schedule_shifts (organization_id,public_id,schedule_week_id,user_id,title,starts_at,ends_at,status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,'scheduled',?,?)");$s->execute([$org,'shift-published-ci',$publishedWeek,$uid,'Dinner shift','2026-09-15 16:00:00','2026-09-15 22:00:00',$uid,$uid]);$s->execute([$org,'shift-draft-ci',$draftWeek,$uid,'Hidden draft shift','2026-09-22 16:00:00','2026-09-22 22:00:00',$uid,$uid]);
$shifts=employee_home_shifts($pdo,$org,$uid,30);must(count($shifts)===1&&$shifts[0]['public_id']==='shift-published-ci','Draft schedule leaked into Employee Home.');

$pdo->prepare("INSERT INTO certifications (organization_id,user_id,certification_type,status,score,issued_by,issued_at,expires_at) VALUES (?,?,'Food Handler','issued',95,?,NOW(6),DATE_ADD(NOW(6),INTERVAL 20 DAY))")->execute([$org,$uid,$uid]);$training=employee_home_training($pdo,$org,$uid);must(count($training['certifications'])===1,'Certification projection failed.');
$dashboard=employee_home_dashboard($pdo,$org,$uid);must(($dashboard['profile']['user_id']??0)===$uid,'Dashboard profile mismatch.');must((int)$dashboard['summary']['activeCertifications']===1,'Dashboard certification summary failed.');must((int)$dashboard['summary']['unreadAnnouncements']===0,'Read announcement summary failed.');must((int)$dashboard['summary']['unacknowledgedPolicies']===0,'Acknowledged policy summary failed.');

echo "employee-home-ok\n";
