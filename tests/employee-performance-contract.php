<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/employee-performance-core.php';

$pdo=app_pdo();
function ep_must(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Employee Performance CI','active','America/Phoenix'),('Employee Performance Other','active','America/Phoenix')");
$org=(int)$pdo->query("SELECT id FROM organizations WHERE name='Employee Performance CI'")->fetchColumn();$other=(int)$pdo->query("SELECT id FROM organizations WHERE name='Employee Performance Other'")->fetchColumn();
$pdo->prepare("INSERT INTO locations (organization_id,name,status) VALUES (?,'Main','active'),(?,'Other','active')")->execute([$org,$other]);$loc=(int)$pdo->query("SELECT id FROM locations WHERE organization_id={$org} LIMIT 1")->fetchColumn();$otherLoc=(int)$pdo->query("SELECT id FROM locations WHERE organization_id={$other} LIMIT 1")->fetchColumn();
$hash=password_hash('Employee-Performance-CI-2026',PASSWORD_DEFAULT);$u=$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')");$u->execute(['employee-perf@example.test',$hash,'Casey','Employee','Casey Employee']);$employee=(int)$pdo->lastInsertId();$u->execute(['manager-perf@example.test',$hash,'Morgan','Manager','Morgan Manager']);$manager=(int)$pdo->lastInsertId();$u->execute(['other-perf@example.test',$hash,'Other','Employee','Other Employee']);$otherEmployee=(int)$pdo->lastInsertId();
$m=$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,?,'active')");$m->execute([$org,$employee,$loc,'Server']);$m->execute([$org,$manager,$loc,'Manager']);$m->execute([$other,$otherEmployee,$otherLoc,'Server']);
$pdo->prepare("INSERT INTO staff_profiles (organization_id,user_id,preferred_name,employment_type,created_by,updated_by) VALUES (?,?,?,'hourly',?,?)")->execute([$org,$employee,'Casey',$manager,$manager]);

ep_must(employee_performance_ready($pdo),'Employee performance schema is not ready.');
$pdo->prepare("INSERT INTO attendance_events (organization_id,user_id,event_type,severity,summary,actor_user_id) VALUES (?,?,'late_clock_in','warning','Clocked in 8 minutes after scheduled start.',?)")->execute([$org,$employee,$manager]);
$pdo->prepare("INSERT INTO time_clock_entries (organization_id,public_id,user_id,clocked_in_at,clocked_out_at,status,created_by,updated_by) VALUES (?,'clock-perf',?,DATE_SUB(NOW(6),INTERVAL 2 DAY),DATE_SUB(NOW(6),INTERVAL 2 DAY)+INTERVAL 8 HOUR,'closed',?,?)")->execute([$org,$employee,$manager,$manager]);
$pdo->prepare("INSERT INTO restaurant_tasks (organization_id,public_id,title,status,priority,due_at,created_by,updated_by) VALUES (?,'task-perf','Closing dining room','verified','normal',DATE_SUB(NOW(6),INTERVAL 1 DAY),?,?)")->execute([$org,$manager,$manager]);$task=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO restaurant_task_assignments (organization_id,task_id,user_id,assigned_by,assigned_at,accepted_at,completed_at) VALUES (?,?,?,?,DATE_SUB(NOW(6),INTERVAL 3 DAY),DATE_SUB(NOW(6),INTERVAL 3 DAY),DATE_SUB(NOW(6),INTERVAL 2 DAY))")->execute([$org,$task,$employee,$manager]);

$note=employee_performance_note_save($pdo,$org,$employee,['title'=>'Guest recovery follow-up','body'=>'Reviewed the service recovery steps used during Friday dinner.','noteType'=>'one_on_one'],$manager);ep_must(str_starts_with((string)$note['public_id'],'coach-'),'Coaching note was not saved.');
$recognition=employee_performance_recognition_save($pdo,$org,$employee,['title'=>'Strong close','body'=>'Completed the closing checklist and helped reset the dining room.','visibility'=>'employee_visible'],$manager);ep_must((string)$recognition['visibility']==='employee_visible','Recognition visibility was not preserved.');
$goal=employee_performance_goal_save($pdo,$org,$employee,['title'=>'Menu knowledge refresh','objective'=>'Complete the current menu knowledge refresher with the manager.','successCriteria'=>'Manager records completion after the refresher conversation.','status'=>'active'],$manager);ep_must((string)$goal['status']==='active','Development goal was not saved.');

$brief=employee_performance_brief($pdo,$org,$employee,90);ep_must((int)$brief['evidence']['tasks']['summary']['assigned']===1,'Assigned task evidence is incorrect.');ep_must((int)$brief['evidence']['tasks']['summary']['completed']===1,'Completed task evidence is incorrect.');ep_must(count($brief['evidence']['attendance']['recent'])===1,'Attendance evidence is incorrect.');ep_must(count($brief['evidence']['notes'])===1,'Coaching note missing from private brief.');ep_must(count($brief['evidence']['recognitions'])===1,'Recognition missing from private brief.');ep_must(count($brief['evidence']['goals'])===1,'Development goal missing from private brief.');
$text=mb_strtolower((string)$brief['summary'],'UTF-8');ep_must(str_contains($text,'does not assign an employee score'),'Brief safety disclosure is missing.');ep_must(str_contains($text,'does not')&&str_contains($text,'promotion'),'Brief does not state the decision boundary.');
$blocked=false;try{employee_performance_note_save($pdo,$org,$otherEmployee,['title'=>'Cross org','body'=>'Must fail.'],$manager);}catch(InvalidArgumentException){$blocked=true;}ep_must($blocked,'Cross-organization coaching write was accepted.');

echo "employee-performance-ok\n";
