<?php
declare(strict_types=1);

require_once __DIR__.'/employee-home-core.php';

function employee_performance_ready(PDO $pdo): bool
{
    foreach(['employee_coaching_notes','employee_recognitions','employee_development_goals','attendance_events','restaurant_task_assignments','training_assignments'] as $table){
        if(!restaurant_brain_table_ready($pdo,$table))return false;
    }
    return true;
}

function employee_performance_public_id(string $prefix): string
{
    return $prefix.'-'.bin2hex(random_bytes(10));
}

function employee_performance_require_employee(PDO $pdo,int $org,int $userId): void
{
    if($userId<=0||!employee_home_staff_exists($pdo,$org,$userId))throw new InvalidArgumentException('Employee not found.');
}

function employee_performance_attendance(PDO $pdo,int $org,int $userId,int $days=90): array
{
    $days=max(7,min(365,$days));
    $q=$pdo->prepare("SELECT event_type,severity,COUNT(*) event_count,MAX(created_at) last_event_at FROM attendance_events WHERE organization_id=? AND user_id=? AND created_at>=DATE_SUB(NOW(6),INTERVAL {$days} DAY) GROUP BY event_type,severity ORDER BY event_count DESC,event_type");
    $q->execute([$org,$userId]);$groups=$q->fetchAll();
    $q=$pdo->prepare("SELECT event_type,severity,summary,created_at FROM attendance_events WHERE organization_id=? AND user_id=? AND created_at>=DATE_SUB(NOW(6),INTERVAL {$days} DAY) ORDER BY created_at DESC LIMIT 25");
    $q->execute([$org,$userId]);$recent=$q->fetchAll();
    $q=$pdo->prepare("SELECT COUNT(*) entries, SUM(clocked_out_at IS NOT NULL) closed_entries, MIN(clocked_in_at) first_entry_at, MAX(COALESCE(clocked_out_at,clocked_in_at)) last_entry_at FROM time_clock_entries WHERE organization_id=? AND user_id=? AND clocked_in_at>=DATE_SUB(NOW(6),INTERVAL {$days} DAY)");
    $q->execute([$org,$userId]);$clock=$q->fetch()?:[];
    return ['days'=>$days,'groups'=>$groups,'recent'=>$recent,'clock'=>$clock];
}

function employee_performance_tasks(PDO $pdo,int $org,int $userId,int $days=90): array
{
    $days=max(7,min(365,$days));
    $q=$pdo->prepare("SELECT COUNT(*) assigned, SUM(a.completed_at IS NOT NULL) completed, SUM(t.status='verified') verified, SUM(t.status NOT IN ('completed','verified','cancelled')) open_tasks, SUM(t.due_at IS NOT NULL AND t.due_at<NOW(6) AND t.status NOT IN ('completed','verified','cancelled')) overdue_open FROM restaurant_task_assignments a INNER JOIN restaurant_tasks t ON t.id=a.task_id AND t.organization_id=a.organization_id WHERE a.organization_id=? AND a.user_id=? AND a.assigned_at>=DATE_SUB(NOW(6),INTERVAL {$days} DAY)");
    $q->execute([$org,$userId]);$summary=$q->fetch()?:[];
    $q=$pdo->prepare("SELECT t.public_id,t.title,t.status,t.priority,t.due_at,a.assigned_at,a.completed_at,t.verified_at FROM restaurant_task_assignments a INNER JOIN restaurant_tasks t ON t.id=a.task_id AND t.organization_id=a.organization_id WHERE a.organization_id=? AND a.user_id=? AND a.assigned_at>=DATE_SUB(NOW(6),INTERVAL {$days} DAY) ORDER BY COALESCE(a.completed_at,a.assigned_at) DESC LIMIT 25");
    $q->execute([$org,$userId]);
    return ['days'=>$days,'summary'=>$summary,'recent'=>$q->fetchAll()];
}

function employee_performance_training(PDO $pdo,int $org,int $userId): array
{
    $q=$pdo->prepare("SELECT COUNT(*) total, SUM(completed_at IS NOT NULL) completed, SUM(completed_at IS NULL) open_assignments, SUM(completed_at IS NULL AND due_at IS NOT NULL AND due_at<NOW(6)) overdue_assignments FROM training_assignments WHERE organization_id=? AND user_id=?");
    $q->execute([$org,$userId]);$summary=$q->fetch()?:[];
    $q=$pdo->prepare("SELECT assignment_type,assignment_reference_id,assigned_at,due_at,completed_at FROM training_assignments WHERE organization_id=? AND user_id=? ORDER BY completed_at IS NULL DESC,COALESCE(due_at,'2999-12-31'),assigned_at DESC LIMIT 25");
    $q->execute([$org,$userId]);$assignments=$q->fetchAll();
    $q=$pdo->prepare("SELECT certification_type,status,score,issued_at,expires_at,revoked_at FROM certifications WHERE organization_id=? AND user_id=? ORDER BY status='issued' DESC,COALESCE(expires_at,'2999-12-31'),issued_at DESC LIMIT 25");
    $q->execute([$org,$userId]);$certs=$q->fetchAll();
    return ['summary'=>$summary,'assignments'=>$assignments,'certifications'=>$certs];
}

function employee_performance_notes(PDO $pdo,int $org,int $userId): array
{
    $q=$pdo->prepare("SELECT n.public_id,n.note_type,n.title,n.body,n.occurred_at,n.created_at,n.updated_at,u.display_name created_by_name FROM employee_coaching_notes n LEFT JOIN users u ON u.id=n.created_by WHERE n.organization_id=? AND n.user_id=? AND n.archived_at IS NULL ORDER BY COALESCE(n.occurred_at,n.created_at) DESC LIMIT 100");
    $q->execute([$org,$userId]);return $q->fetchAll();
}

function employee_performance_recognitions(PDO $pdo,int $org,int $userId,bool $managerView=true): array
{
    $sql="SELECT r.public_id,r.recognition_type,r.title,r.body,r.visibility,r.awarded_at,u.display_name awarded_by_name FROM employee_recognitions r LEFT JOIN users u ON u.id=r.awarded_by WHERE r.organization_id=? AND r.user_id=? AND r.archived_at IS NULL";
    if(!$managerView)$sql.=" AND r.visibility='employee_visible'";
    $sql.=' ORDER BY r.awarded_at DESC LIMIT 100';$q=$pdo->prepare($sql);$q->execute([$org,$userId]);return $q->fetchAll();
}

function employee_performance_goals(PDO $pdo,int $org,int $userId): array
{
    $q=$pdo->prepare("SELECT g.public_id,g.title,g.objective,g.success_criteria,g.status,g.start_date,g.target_date,g.completed_at,g.manager_notes,g.created_at,g.updated_at,u.display_name manager_name FROM employee_development_goals g LEFT JOIN users u ON u.id=g.manager_user_id WHERE g.organization_id=? AND g.user_id=? AND g.archived_at IS NULL ORDER BY FIELD(g.status,'active','draft','completed','cancelled'),COALESCE(g.target_date,'2999-12-31'),g.created_at DESC LIMIT 100");
    $q->execute([$org,$userId]);return $q->fetchAll();
}

function employee_performance_snapshot(PDO $pdo,int $org,int $userId,int $days=90): array
{
    employee_performance_require_employee($pdo,$org,$userId);
    $profile=employee_home_profile($pdo,$org,$userId);
    return ['profile'=>$profile,'attendance'=>employee_performance_attendance($pdo,$org,$userId,$days),'tasks'=>employee_performance_tasks($pdo,$org,$userId,$days),'training'=>employee_performance_training($pdo,$org,$userId),'recognitions'=>employee_performance_recognitions($pdo,$org,$userId,true),'notes'=>employee_performance_notes($pdo,$org,$userId),'goals'=>employee_performance_goals($pdo,$org,$userId)];
}

function employee_performance_brief(PDO $pdo,int $org,int $userId,int $days=90): array
{
    $d=employee_performance_snapshot($pdo,$org,$userId,$days);$name=(string)($d['profile']['preferred_name']??$d['profile']['display_name']??'Employee');
    $task=$d['tasks']['summary'];$training=$d['training']['summary'];$attendance=$d['attendance'];
    $eventCounts=[];foreach($attendance['groups'] as $g){$eventCounts[]=(int)$g['event_count'].' '.str_replace('_',' ',(string)$g['event_type']);}
    $parts=[];$parts[]=$name.' — factual development brief for the last '.(int)$days.' days.';
    $parts[]='Tasks: '.(int)($task['assigned']??0).' assigned, '.(int)($task['completed']??0).' completed, '.(int)($task['verified']??0).' verified, '.(int)($task['overdue_open']??0).' currently overdue.';
    $parts[]='Training: '.(int)($training['completed']??0).' completed assignments, '.(int)($training['open_assignments']??0).' open, '.(int)($training['overdue_assignments']??0).' overdue.';
    $parts[]='Attendance records: '.($eventCounts?implode(', ',$eventCounts):'no attendance exception events recorded in this window').'.';
    $parts[]='Development record: '.count($d['recognitions']).' recognition item(s), '.count($d['notes']).' private coaching note(s), '.count(array_filter($d['goals'],static fn(array $g):bool=>(string)$g['status']==='active')).' active manager-authored goal(s).';
    $parts[]='This brief reports recorded job-related facts only; it does not assign an employee score or recommend promotion, discipline, compensation, scheduling, or termination decisions.';
    return ['headline'=>$name.' development brief','summary'=>implode(' ',$parts),'evidence'=>$d];
}

function employee_performance_note_save(PDO $pdo,int $org,int $userId,array $input,int $actor): array
{
    employee_performance_require_employee($pdo,$org,$userId);$title=mb_substr(trim((string)($input['title']??'')),0,220,'UTF-8');$body=trim((string)($input['body']??''));if($title===''||$body==='')throw new InvalidArgumentException('Coaching note title and body are required.');
    $type=(string)($input['noteType']??'coaching');if(!in_array($type,['coaching','one_on_one','training_followup','manager_observation'],true))$type='coaching';$occurred=trim((string)($input['occurredAt']??''))?:null;$public=employee_performance_public_id('coach');
    $pdo->prepare('INSERT INTO employee_coaching_notes (organization_id,public_id,user_id,note_type,title,body,occurred_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$org,$public,$userId,$type,$title,$body,$occurred,$actor,$actor]);
    $q=$pdo->prepare('SELECT * FROM employee_coaching_notes WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$public]);return $q->fetch()?:[];
}

function employee_performance_recognition_save(PDO $pdo,int $org,int $userId,array $input,int $actor): array
{
    employee_performance_require_employee($pdo,$org,$userId);$title=mb_substr(trim((string)($input['title']??'')),0,220,'UTF-8');$body=trim((string)($input['body']??''));if($title===''||$body==='')throw new InvalidArgumentException('Recognition title and description are required.');
    $type=mb_substr(trim((string)($input['recognitionType']??'manager_recognition')),0,40,'UTF-8')?:'manager_recognition';$visibility=(string)($input['visibility']??'employee_visible');if(!in_array($visibility,['employee_visible','manager_private'],true))$visibility='employee_visible';$public=employee_performance_public_id('recognition');
    $pdo->prepare('INSERT INTO employee_recognitions (organization_id,public_id,user_id,recognition_type,title,body,visibility,awarded_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$org,$public,$userId,$type,$title,$body,$visibility,$actor]);
    $q=$pdo->prepare('SELECT * FROM employee_recognitions WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$public]);return $q->fetch()?:[];
}

function employee_performance_goal_save(PDO $pdo,int $org,int $userId,array $input,int $actor): array
{
    employee_performance_require_employee($pdo,$org,$userId);$public=trim((string)($input['id']??''));$title=mb_substr(trim((string)($input['title']??'')),0,220,'UTF-8');$objective=trim((string)($input['objective']??''));if($title===''||$objective==='')throw new InvalidArgumentException('Development goal title and objective are required.');$criteria=trim((string)($input['successCriteria']??''))?:null;$notes=trim((string)($input['managerNotes']??''))?:null;$status=(string)($input['status']??'active');if(!in_array($status,['draft','active','completed','cancelled'],true))throw new InvalidArgumentException('Unsupported goal status.');$start=trim((string)($input['startDate']??''))?:null;$target=trim((string)($input['targetDate']??''))?:null;
    if($public===''){$public=employee_performance_public_id('goal');$pdo->prepare("INSERT INTO employee_development_goals (organization_id,public_id,user_id,title,objective,success_criteria,status,start_date,target_date,completed_at,manager_user_id,manager_notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,IF(?='completed',NOW(6),NULL),?,?,?,?)")->execute([$org,$public,$userId,$title,$objective,$criteria,$status,$start,$target,$status,$actor,$notes,$actor,$actor]);}
    else{$q=$pdo->prepare('SELECT id FROM employee_development_goals WHERE organization_id=? AND public_id=? AND user_id=? AND archived_at IS NULL');$q->execute([$org,$public,$userId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Development goal not found.');$pdo->prepare("UPDATE employee_development_goals SET title=?,objective=?,success_criteria=?,status=?,start_date=?,target_date=?,completed_at=IF(?='completed',COALESCE(completed_at,NOW(6)),NULL),manager_user_id=?,manager_notes=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=? AND user_id=?")->execute([$title,$objective,$criteria,$status,$start,$target,$status,$actor,$notes,$actor,$org,$public,$userId]);}
    $q=$pdo->prepare('SELECT * FROM employee_development_goals WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$public]);return $q->fetch()?:[];
}
