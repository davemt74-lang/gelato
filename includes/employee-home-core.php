<?php
declare(strict_types=1);

require_once __DIR__.'/operations-core.php';
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/timeclock-voice-core.php';

function employee_home_ready(PDO $pdo): bool
{
    foreach (['employee_lifecycle_profiles','employee_announcements','employee_announcement_reads','employee_policy_documents','employee_policy_acknowledgements','employee_checklist_items'] as $table) {
        if (!restaurant_brain_table_ready($pdo,$table)) return false;
    }
    return true;
}

function employee_home_public_id(string $prefix): string
{
    return $prefix.'-'.bin2hex(random_bytes(10));
}

function employee_home_staff_exists(PDO $pdo,int $org,int $userId): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM organization_memberships om INNER JOIN users u ON u.id=om.user_id WHERE om.organization_id=? AND om.user_id=? AND om.status='active' AND u.status='active' AND u.archived_at IS NULL");
    $q->execute([$org,$userId]);
    return (int)$q->fetchColumn()===1;
}

function employee_home_profile(PDO $pdo,int $org,int $userId): ?array
{
    $q=$pdo->prepare("SELECT u.id user_id,u.email,u.first_name,u.last_name,u.display_name,u.phone,
        om.employee_number,om.job_title,om.hire_date,om.primary_location_id,
        l.name location_name,
        sp.preferred_name,sp.employment_type,sp.skills_json,
        ep.start_date,ep.onboarding_status,ep.emergency_contact_name,ep.emergency_contact_relationship,ep.emergency_contact_phone
      FROM users u
      INNER JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=? AND om.status='active'
      LEFT JOIN locations l ON l.id=om.primary_location_id AND l.organization_id=om.organization_id
      LEFT JOIN staff_profiles sp ON sp.organization_id=om.organization_id AND sp.user_id=u.id
      LEFT JOIN employee_lifecycle_profiles ep ON ep.organization_id=om.organization_id AND ep.user_id=u.id
      WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$userId]);$row=$q->fetch();if(!$row)return null;
    $row['skills']=$row['skills_json']?json_decode((string)$row['skills_json'],true):[];unset($row['skills_json']);
    return $row;
}

function employee_home_profile_self_save(PDO $pdo,int $org,int $userId,array $input,int $actor): array
{
    if(!employee_home_staff_exists($pdo,$org,$userId))throw new InvalidArgumentException('Active employee account required.');
    $name=mb_substr(trim((string)($input['emergencyContactName']??'')),0,180,'UTF-8');
    $relationship=mb_substr(trim((string)($input['emergencyContactRelationship']??'')),0,120,'UTF-8');
    $phone=mb_substr(trim((string)($input['emergencyContactPhone']??'')),0,40,'UTF-8');
    $pdo->prepare("INSERT INTO employee_lifecycle_profiles (organization_id,user_id,emergency_contact_name,emergency_contact_relationship,emergency_contact_phone,created_by,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE emergency_contact_name=VALUES(emergency_contact_name),emergency_contact_relationship=VALUES(emergency_contact_relationship),emergency_contact_phone=VALUES(emergency_contact_phone),updated_by=VALUES(updated_by),updated_at=NOW(6)")
        ->execute([$org,$userId,$name?:null,$relationship?:null,$phone?:null,$actor,$actor]);
    return employee_home_profile($pdo,$org,$userId)??[];
}

function employee_home_profile_manager_save(PDO $pdo,int $org,int $userId,array $input,int $actor): array
{
    if(!employee_home_staff_exists($pdo,$org,$userId))throw new InvalidArgumentException('Employee not found.');
    $start=trim((string)($input['startDate']??''));if($start!==''){$d=DateTimeImmutable::createFromFormat('Y-m-d',$start);if(!$d||$d->format('Y-m-d')!==$start)throw new InvalidArgumentException('Start date is invalid.');}
    $onboarding=(string)($input['onboardingStatus']??'not_started');if(!in_array($onboarding,['not_started','in_progress','complete','not_applicable'],true))throw new InvalidArgumentException('Unsupported onboarding status.');
    $pdo->prepare("INSERT INTO employee_lifecycle_profiles (organization_id,user_id,start_date,onboarding_status,created_by,updated_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE start_date=VALUES(start_date),onboarding_status=VALUES(onboarding_status),updated_by=VALUES(updated_by),updated_at=NOW(6)")
        ->execute([$org,$userId,$start?:null,$onboarding,$actor,$actor]);
    if($start!=='')$pdo->prepare('UPDATE organization_memberships SET hire_date=COALESCE(hire_date,?),updated_at=NOW(6) WHERE organization_id=? AND user_id=?')->execute([$start,$org,$userId]);
    return employee_home_profile($pdo,$org,$userId)??[];
}

function employee_home_shifts(PDO $pdo,int $org,int $userId,int $days=14): array
{
    $days=max(1,min(60,$days));
    $q=$pdo->prepare("SELECT s.public_id,s.title,s.starts_at,s.ends_at,s.break_minutes,s.status,l.name location_name,p.name position_name
      FROM schedule_shifts s
      INNER JOIN schedule_weeks w ON w.id=s.schedule_week_id AND w.organization_id=s.organization_id AND w.status IN ('published','locked')
      LEFT JOIN locations l ON l.id=s.location_id LEFT JOIN positions p ON p.id=s.position_id
      WHERE s.organization_id=? AND s.user_id=? AND s.archived_at IS NULL AND s.status<>'cancelled' AND s.ends_at>=NOW(6) AND s.starts_at<DATE_ADD(NOW(6),INTERVAL {$days} DAY)
      ORDER BY s.starts_at LIMIT 30");
    $q->execute([$org,$userId]);return $q->fetchAll();
}

function employee_home_clock(PDO $pdo,int $org,int $userId): array
{
    $clock=tv_open_clock($pdo,$org,$userId);$break=null;
    if($clock){$q=$pdo->prepare('SELECT id,started_at,break_type,source FROM time_clock_breaks WHERE organization_id=? AND time_clock_entry_id=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');$q->execute([$org,(int)$clock['id']]);$break=$q->fetch()?:null;}
    return ['entry'=>$clock,'break'=>$break];
}

function employee_home_tasks(PDO $pdo,int $org,int $userId): array
{
    $q=$pdo->prepare("SELECT t.public_id,t.title,t.description,t.quantity,t.unit,t.station,t.priority,t.status,t.due_at,c.slug category_slug,c.name category_name
      FROM restaurant_tasks t
      INNER JOIN restaurant_task_assignments a ON a.task_id=t.id AND a.organization_id=t.organization_id AND a.user_id=?
      LEFT JOIN task_categories c ON c.id=t.category_id AND c.organization_id=t.organization_id
      WHERE t.organization_id=? AND t.archived_at IS NULL AND t.status NOT IN ('completed','verified','cancelled')
      ORDER BY COALESCE(t.due_at,'2999-12-31'),FIELD(t.priority,'critical','high','normal','low'),t.title LIMIT 40");
    $q->execute([$userId,$org]);return $q->fetchAll();
}

function employee_home_training(PDO $pdo,int $org,int $userId): array
{
    $assignments=[];$certifications=[];
    try{$q=$pdo->prepare("SELECT id,assignment_type,assignment_reference_id,assigned_at,due_at,completed_at FROM training_assignments WHERE organization_id=? AND user_id=? ORDER BY completed_at IS NULL DESC,COALESCE(due_at,'2999-12-31'),assigned_at DESC LIMIT 60");$q->execute([$org,$userId]);$assignments=$q->fetchAll();}catch(Throwable){}
    try{$q=$pdo->prepare("SELECT id,certification_type,status,score,issued_at,expires_at,revoked_at FROM certifications WHERE organization_id=? AND user_id=? ORDER BY status='issued' DESC,COALESCE(expires_at,'2999-12-31'),issued_at DESC LIMIT 60");$q->execute([$org,$userId]);$certifications=$q->fetchAll();}catch(Throwable){}
    return ['assignments'=>$assignments,'certifications'=>$certifications];
}

function employee_home_announcements(PDO $pdo,int $org,int $userId,bool $includeInactive=false): array
{
    $where=$includeInactive?'a.organization_id=? AND a.archived_at IS NULL':"a.organization_id=? AND a.archived_at IS NULL AND a.status='published' AND (a.starts_at IS NULL OR a.starts_at<=NOW(6)) AND (a.ends_at IS NULL OR a.ends_at>=NOW(6))";
    $q=$pdo->prepare("SELECT a.public_id,a.title,a.body,a.priority,a.status,a.starts_at,a.ends_at,a.published_at,a.created_at,r.read_at FROM employee_announcements a LEFT JOIN employee_announcement_reads r ON r.announcement_id=a.id AND r.organization_id=a.organization_id AND r.user_id=? WHERE {$where} ORDER BY FIELD(a.priority,'urgent','high','normal','low'),COALESCE(a.published_at,a.created_at) DESC LIMIT 80");
    $q->execute([$userId,$org]);return $q->fetchAll();
}

function employee_home_announcement_save(PDO $pdo,int $org,array $input,int $actor): array
{
    $id=trim((string)($input['id']??''));$title=mb_substr(trim((string)($input['title']??'')),0,220,'UTF-8');$body=trim((string)($input['body']??''));if($title===''||$body==='')throw new InvalidArgumentException('Announcement title and message are required.');
    $priority=(string)($input['priority']??'normal');if(!in_array($priority,['low','normal','high','urgent'],true))$priority='normal';$status=(string)($input['status']??'draft');if(!in_array($status,['draft','published'],true))$status='draft';$starts=trim((string)($input['startsAt']??''))?:null;$ends=trim((string)($input['endsAt']??''))?:null;
    if($id===''){$public=employee_home_public_id('announce');$pdo->prepare("INSERT INTO employee_announcements (organization_id,public_id,title,body,priority,status,starts_at,ends_at,published_at,published_by,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,IF(?='published',NOW(6),NULL),IF(?='published',?,NULL),?,?)")->execute([$org,$public,$title,$body,$priority,$status,$starts,$ends,$status,$status,$actor,$actor,$actor]);$id=$public;}
    else{$q=$pdo->prepare('SELECT id FROM employee_announcements WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$q->execute([$org,$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Announcement not found.');$pdo->prepare("UPDATE employee_announcements SET title=?,body=?,priority=?,status=?,starts_at=?,ends_at=?,published_at=IF(?='published',COALESCE(published_at,NOW(6)),published_at),published_by=IF(?='published',COALESCE(published_by,?),published_by),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=?")->execute([$title,$body,$priority,$status,$starts,$ends,$status,$status,$actor,$actor,$org,$id]);}
    $q=$pdo->prepare('SELECT * FROM employee_announcements WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$id]);return $q->fetch()?:[];
}

function employee_home_announcement_read(PDO $pdo,int $org,int $userId,string $publicId): void
{
    $q=$pdo->prepare("SELECT id FROM employee_announcements WHERE organization_id=? AND public_id=? AND status='published' AND archived_at IS NULL LIMIT 1");$q->execute([$org,$publicId]);$id=(int)($q->fetchColumn()?:0);if(!$id)throw new InvalidArgumentException('Announcement not found.');$pdo->prepare('INSERT INTO employee_announcement_reads (organization_id,announcement_id,user_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE read_at=NOW(6)')->execute([$org,$id,$userId]);
}

function employee_home_policies(PDO $pdo,int $org,int $userId,bool $includeInactive=false): array
{
    $where=$includeInactive?'p.organization_id=? AND p.archived_at IS NULL':"p.organization_id=? AND p.archived_at IS NULL AND p.status='published'";
    $q=$pdo->prepare("SELECT p.public_id,p.title,p.version_label,p.body_text,p.effective_date,p.requires_acknowledgement,p.status,p.published_at,a.acknowledged_at FROM employee_policy_documents p LEFT JOIN employee_policy_acknowledgements a ON a.policy_document_id=p.id AND a.organization_id=p.organization_id AND a.user_id=? AND a.version_label=p.version_label WHERE {$where} ORDER BY COALESCE(p.effective_date,'1900-01-01') DESC,p.title");$q->execute([$userId,$org]);return $q->fetchAll();
}

function employee_home_policy_save(PDO $pdo,int $org,array $input,int $actor): array
{
    $id=trim((string)($input['id']??''));$title=mb_substr(trim((string)($input['title']??'')),0,220,'UTF-8');$body=trim((string)($input['body']??''));if($title===''||$body==='')throw new InvalidArgumentException('Policy title and text are required.');$version=mb_substr(trim((string)($input['version']??'1.0')),0,60,'UTF-8')?:'1.0';$effective=trim((string)($input['effectiveDate']??''))?:null;$requires=!array_key_exists('requiresAcknowledgement',$input)||!empty($input['requiresAcknowledgement'])?1:0;$status=(string)($input['status']??'draft');if(!in_array($status,['draft','published'],true))$status='draft';
    if($id===''){$id=employee_home_public_id('policy');$pdo->prepare("INSERT INTO employee_policy_documents (organization_id,public_id,title,version_label,body_text,effective_date,requires_acknowledgement,status,published_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,IF(?='published',NOW(6),NULL),?,?)")->execute([$org,$id,$title,$version,$body,$effective,$requires,$status,$status,$actor,$actor]);}
    else{$q=$pdo->prepare('SELECT id FROM employee_policy_documents WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$q->execute([$org,$id]);if(!$q->fetchColumn())throw new InvalidArgumentException('Policy not found.');$pdo->prepare("UPDATE employee_policy_documents SET title=?,version_label=?,body_text=?,effective_date=?,requires_acknowledgement=?,status=?,published_at=IF(?='published',COALESCE(published_at,NOW(6)),published_at),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=?")->execute([$title,$version,$body,$effective,$requires,$status,$status,$actor,$org,$id]);}
    $q=$pdo->prepare('SELECT * FROM employee_policy_documents WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$id]);return $q->fetch()?:[];
}

function employee_home_policy_acknowledge(PDO $pdo,int $org,int $userId,string $publicId): void
{
    $q=$pdo->prepare("SELECT id,version_label,requires_acknowledgement FROM employee_policy_documents WHERE organization_id=? AND public_id=? AND status='published' AND archived_at IS NULL LIMIT 1");$q->execute([$org,$publicId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Policy not found.');$pdo->prepare('INSERT INTO employee_policy_acknowledgements (organization_id,policy_document_id,user_id,version_label) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE acknowledged_at=NOW(6)')->execute([$org,(int)$row['id'],$userId,(string)$row['version_label']]);
}

function employee_home_checklist(PDO $pdo,int $org,int $userId,bool $includeClosed=true): array
{
    $sql="SELECT c.public_id,c.phase,c.title,c.description,c.due_at,c.status,c.self_completable,c.completed_at,c.created_at,u.display_name assigned_by_name FROM employee_checklist_items c LEFT JOIN users u ON u.id=c.assigned_by WHERE c.organization_id=? AND c.user_id=? AND c.archived_at IS NULL";if(!$includeClosed)$sql.=" AND c.status='open'";$sql.=' ORDER BY c.status<>\'open\',COALESCE(c.due_at,\'2999-12-31\'),c.created_at';$q=$pdo->prepare($sql);$q->execute([$org,$userId]);return $q->fetchAll();
}

function employee_home_checklist_save(PDO $pdo,int $org,array $input,int $actor): array
{
    $public=trim((string)($input['id']??''));$userId=(int)($input['userId']??0);if($userId<=0||!employee_home_staff_exists($pdo,$org,$userId))throw new InvalidArgumentException('Employee is required.');$title=mb_substr(trim((string)($input['title']??'')),0,220,'UTF-8');if($title==='')throw new InvalidArgumentException('Checklist title is required.');$description=mb_substr(trim((string)($input['description']??'')),0,1200,'UTF-8');$phase=(string)($input['phase']??'onboarding');if(!in_array($phase,['onboarding','general','offboarding'],true))$phase='general';$due=trim((string)($input['dueAt']??''))?:null;$self=!empty($input['selfCompletable'])?1:0;
    if($public===''){$public=employee_home_public_id('check');$pdo->prepare('INSERT INTO employee_checklist_items (organization_id,public_id,user_id,phase,title,description,due_at,self_completable,assigned_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$org,$public,$userId,$phase,$title,$description?:null,$due,$self,$actor]);}
    else{$q=$pdo->prepare('SELECT id FROM employee_checklist_items WHERE organization_id=? AND public_id=? AND archived_at IS NULL');$q->execute([$org,$public]);if(!$q->fetchColumn())throw new InvalidArgumentException('Checklist item not found.');$pdo->prepare('UPDATE employee_checklist_items SET phase=?,title=?,description=?,due_at=?,self_completable=?,updated_at=NOW(6) WHERE organization_id=? AND public_id=?')->execute([$phase,$title,$description?:null,$due,$self,$org,$public]);}
    $q=$pdo->prepare('SELECT * FROM employee_checklist_items WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$public]);return $q->fetch()?:[];
}

function employee_home_checklist_complete(PDO $pdo,int $org,int $userId,string $publicId,int $actor,bool $manager=false): void
{
    $q=$pdo->prepare('SELECT * FROM employee_checklist_items WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');$q->execute([$org,$publicId]);$row=$q->fetch();if(!$row)throw new InvalidArgumentException('Checklist item not found.');if(!$manager&&(int)$row['user_id']!==$userId)throw new RuntimeException('You can only complete your own checklist items.');if(!$manager&&!(int)$row['self_completable'])throw new RuntimeException('This checklist item requires manager completion.');$pdo->prepare("UPDATE employee_checklist_items SET status='completed',completed_by=?,completed_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$actor,$org,(int)$row['id']]);
}

function employee_home_dashboard(PDO $pdo,int $org,int $userId): array
{
    $profile=employee_home_profile($pdo,$org,$userId);if(!$profile)throw new InvalidArgumentException('Employee profile not found.');$shifts=employee_home_shifts($pdo,$org,$userId);$clock=employee_home_clock($pdo,$org,$userId);$tasks=operations_core_ready($pdo)?employee_home_tasks($pdo,$org,$userId):[];$training=employee_home_training($pdo,$org,$userId);$announcements=employee_home_announcements($pdo,$org,$userId);$policies=employee_home_policies($pdo,$org,$userId);$checklist=employee_home_checklist($pdo,$org,$userId);
    $openTraining=count(array_filter($training['assignments'],static fn(array $r):bool=>empty($r['completed_at'])));$activeCerts=count(array_filter($training['certifications'],static fn(array $r):bool=>(string)$r['status']==='issued'&&empty($r['revoked_at'])));$unread=count(array_filter($announcements,static fn(array $r):bool=>empty($r['read_at'])));$unacked=count(array_filter($policies,static fn(array $r):bool=>(int)$r['requires_acknowledgement']===1&&empty($r['acknowledged_at'])));$openChecklist=count(array_filter($checklist,static fn(array $r):bool=>(string)$r['status']==='open'));
    return ['profile'=>$profile,'clock'=>$clock,'shifts'=>$shifts,'tasks'=>$tasks,'training'=>$training,'announcements'=>$announcements,'policies'=>$policies,'checklist'=>$checklist,'summary'=>['openTasks'=>count($tasks),'openTraining'=>$openTraining,'activeCertifications'=>$activeCerts,'unreadAnnouncements'=>$unread,'unacknowledgedPolicies'=>$unacked,'openChecklist'=>$openChecklist]];
}

function employee_home_team(PDO $pdo,int $org): array
{
    $rows=scheduling_staff($pdo,$org);$out=[];foreach($rows as $row){$uid=(int)$row['user_id'];$profile=employee_home_profile($pdo,$org,$uid);if(!$profile)continue;$shifts=employee_home_shifts($pdo,$org,$uid,7);$clock=employee_home_clock($pdo,$org,$uid);$check=employee_home_checklist($pdo,$org,$uid,false);$out[]=['userId'=>$uid,'displayName'=>$profile['display_name'],'preferredName'=>$profile['preferred_name']?:null,'jobTitle'=>$profile['job_title'],'location'=>$profile['location_name'],'onboardingStatus'=>$profile['onboarding_status']?:'not_started','nextShift'=>$shifts[0]??null,'clockedIn'=>!empty($clock['entry']),'openChecklist'=>count($check)];}return $out;
}
