<?php
declare(strict_types=1);

require_once __DIR__ . '/scheduling-core.php';

function workspace_workforce_table_ready(PDO $pdo,string $table): bool
{
    try{
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        return (int)$q->fetchColumn()===1;
    }catch(Throwable){return false;}
}

function workspace_workforce_can(array $user,string ...$permissions): bool
{
    foreach($permissions as $permission){
        if(app_has_permission($permission,$user))return true;
    }
    return false;
}

function workspace_workforce_resumes(PDO $pdo,int $organizationId,int $limit=6): array
{
    $empty=['available'=>false,'newCount'=>0,'latest'=>[]];
    if(!workspace_workforce_table_ready($pdo,'resume_submissions'))return $empty;
    $limit=max(1,min(20,$limit));
    try{
        $q=$pdo->prepare("SELECT COUNT(*) FROM resume_submissions WHERE organization_id=? AND archived_at IS NULL AND status='new'");
        $q->execute([$organizationId]);
        $new=(int)$q->fetchColumn();
        $q=$pdo->prepare("SELECT r.id,r.first_name,r.last_name,r.email,r.phone,r.position_interest,r.status,r.source,r.submitted_at,j.title job_title
            FROM resume_submissions r
            LEFT JOIN jobs j ON j.id=r.job_id AND j.organization_id=r.organization_id
            WHERE r.organization_id=? AND r.archived_at IS NULL
            ORDER BY (r.status='new') DESC,r.submitted_at DESC,r.id DESC
            LIMIT {$limit}");
        $q->execute([$organizationId]);
        $rows=array_map(static fn(array $r):array=>[
            'id'=>(int)$r['id'],
            'name'=>trim((string)$r['first_name'].' '.(string)$r['last_name']),
            'email'=>(string)$r['email'],
            'phone'=>$r['phone'],
            'position'=>(string)($r['job_title']?:$r['position_interest']?:'General application'),
            'status'=>(string)$r['status'],
            'source'=>(string)($r['source']??''),
            'submittedAt'=>(string)$r['submitted_at'],
        ],$q->fetchAll());
        return ['available'=>true,'newCount'=>$new,'latest'=>$rows];
    }catch(Throwable){return $empty;}
}

function workspace_workforce_activity_label(string $action): string
{
    $map=[
        'login.success'=>'Signed in','logout.success'=>'Signed out','quiz.completed'=>'Completed a quiz',
        'daily_training.completed'=>'Completed daily training','flashcard_session.completed'=>'Completed flashcards',
        'kitchen_verification.completed'=>'Completed kitchen verification','certification.issued'=>'Certification issued',
        'training.assigned'=>'Training assigned','profile.updated'=>'Updated profile',
        'timeclock.clock_in'=>'Clocked in','timeclock.clock_out'=>'Clocked out','timeclock.break_start'=>'Started break','timeclock.break_end'=>'Ended break',
        'employee.handoff_created'=>'Added a shift handoff','employee.task_completed'=>'Completed a task',
        'schedule.shift_created'=>'Shift scheduled','schedule.shift_updated'=>'Shift updated','schedule.shift_swapped'=>'Shift swap updated',
    ];
    if(isset($map[$action]))return $map[$action];
    $text=preg_replace('/[._]+/',' ',$action)?:$action;
    return ucfirst(trim($text));
}

function workspace_workforce_recent_activity(PDO $pdo,int $organizationId,int $limit=10): array
{
    if(!workspace_workforce_table_ready($pdo,'audit_log'))return [];
    $limit=max(1,min(30,$limit));
    $patterns=[
        'login.%','logout.%','timeclock.%','attendance.%','schedule.%','shift.%','employee.%','training.%',
        'quiz.%','daily_training.%','flashcard_session.%','kitchen_verification.%','task.%','handoff.%','certification.%','profile.updated'
    ];
    $where=implode(' OR ',array_fill(0,count($patterns),'a.action LIKE ?'));
    try{
        $sql="SELECT a.id,a.action,a.entity_type,a.entity_id,a.created_at,a.new_values_json,u.id actor_id,u.display_name,u.first_name,u.last_name,om.job_title
              FROM audit_log a
              LEFT JOIN users u ON u.id=a.actor_user_id
              LEFT JOIN organization_memberships om ON om.organization_id=a.organization_id AND om.user_id=a.actor_user_id
              WHERE a.organization_id=? AND ({$where})
              ORDER BY a.created_at DESC,a.id DESC LIMIT {$limit}";
        $q=$pdo->prepare($sql);$q->execute(array_merge([$organizationId],$patterns));
        return array_map(static function(array $r):array{
            $name=trim((string)($r['display_name']??''));if($name==='')$name='System';
            return ['id'=>(int)$r['id'],'action'=>(string)$r['action'],'label'=>workspace_workforce_activity_label((string)$r['action']),'actorId'=>$r['actor_id']!==null?(int)$r['actor_id']:null,'actor'=>$name,'jobTitle'=>(string)($r['job_title']??''),'entityType'=>(string)$r['entity_type'],'entityId'=>$r['entity_id'],'createdAt'=>(string)$r['created_at']];
        },$q->fetchAll());
    }catch(Throwable){return [];}
}

function workspace_workforce_scheduling(PDO $pdo,int $organizationId,int $limit=6): array
{
    $empty=['available'=>false,'weekStart'=>null,'scheduledHours'=>0,'shiftCount'=>0,'openShifts'=>0,'scheduledStaff'=>0,'coverageGaps'=>0,'taskHours'=>0,'nextShifts'=>[],'pendingTimeOff'=>0];
    if(!scheduling_core_ready($pdo))return $empty;
    try{
        $week=scheduling_week_start();
        $summary=scheduling_summary($pdo,$organizationId,$week);
        $limit=max(1,min(20,$limit));
        $q=$pdo->prepare("SELECT s.public_id,s.title,s.starts_at,s.ends_at,s.user_id,u.display_name,l.name location_name,p.name position_name
            FROM schedule_shifts s
            LEFT JOIN users u ON u.id=s.user_id
            LEFT JOIN locations l ON l.id=s.location_id
            LEFT JOIN positions p ON p.id=s.position_id
            WHERE s.organization_id=? AND s.archived_at IS NULL AND s.status='scheduled' AND s.starts_at>=NOW(6)
            ORDER BY s.starts_at,s.id LIMIT {$limit}");
        $q->execute([$organizationId]);
        $next=array_map(static fn(array $r):array=>['id'=>(string)$r['public_id'],'title'=>(string)($r['title']?:$r['position_name']?:'Shift'),'startsAt'=>(string)$r['starts_at'],'endsAt'=>(string)$r['ends_at'],'employee'=>(string)($r['display_name']?:'Open shift'),'location'=>(string)($r['location_name']??'')],$q->fetchAll());
        $pending=0;
        if(workspace_workforce_table_ready($pdo,'staff_availability_exceptions')){
            $q=$pdo->prepare("SELECT COUNT(*) FROM staff_availability_exceptions WHERE organization_id=? AND status='pending' AND exception_type='time_off' AND exception_date>=CURRENT_DATE()");
            $q->execute([$organizationId]);$pending=(int)$q->fetchColumn();
        }
        return ['available'=>true,'weekStart'=>$summary['weekStart']??$week,'scheduledHours'=>(float)($summary['scheduledHours']??0),'shiftCount'=>(int)($summary['shiftCount']??0),'openShifts'=>(int)($summary['openShifts']??0),'scheduledStaff'=>(int)($summary['scheduledStaff']??0),'coverageGaps'=>(int)($summary['coverageGaps']??0),'taskHours'=>(float)($summary['taskHours']??0),'nextShifts'=>$next,'pendingTimeOff'=>$pending];
    }catch(Throwable){return $empty;}
}

function workspace_workforce_snapshot(PDO $pdo,array $user): array
{
    $org=(int)$user['organization_id'];
    $canResumes=workspace_workforce_can($user,'resumes.view','employee.manage','staff.manage');
    $canActivity=workspace_workforce_can($user,'audit.view','employee.manage','staff.manage','employee.performance.view');
    $canSchedule=workspace_workforce_can($user,'schedule.view','schedule.manage','staff.manage','employee.manage');
    return [
        'generatedAt'=>date(DATE_ATOM),
        'capabilities'=>['resumes'=>$canResumes,'activity'=>$canActivity,'scheduling'=>$canSchedule],
        'resumes'=>$canResumes?workspace_workforce_resumes($pdo,$org):['available'=>false,'newCount'=>0,'latest'=>[]],
        'activity'=>$canActivity?workspace_workforce_recent_activity($pdo,$org):[],
        'scheduling'=>$canSchedule?workspace_workforce_scheduling($pdo,$org):['available'=>false,'weekStart'=>null,'scheduledHours'=>0,'shiftCount'=>0,'openShifts'=>0,'scheduledStaff'=>0,'coverageGaps'=>0,'taskHours'=>0,'nextShifts'=>[],'pendingTimeOff'=>0],
    ];
}

function workspace_workforce_agent_answer(array $snapshot,string $message): array
{
    $text=mb_strtolower($message,'UTF-8');$sources=[];$data=[];
    if(preg_match('/\b(resume|resumes|applicant|applicants|hiring|candidate|candidates)\b/u',$text)){
        if(empty($snapshot['capabilities']['resumes']))return ['answer'=>'Your account does not have access to the hiring resume queue.','data'=>[],'sources'=>[]];
        $r=$snapshot['resumes'];$sources[]='Hiring / Resume Queue';$data=$r;
        $latest=array_slice($r['latest']??[],0,4);$names=array_map(static fn(array $x):string=>$x['name'].' — '.$x['position'],$latest);
        return ['answer'=>'There '.((int)$r['newCount']===1?'is':'are').' '.number_format((int)$r['newCount']).' new resume'.((int)$r['newCount']===1?'':'s').' waiting for review'.($names?'. Latest candidates: '.implode('; ',$names).'.':'.'),'data'=>$data,'sources'=>$sources];
    }
    if(preg_match('/\b(employee activity|staff activity|recent activity|what.*employees|what.*staff|team activity)\b/u',$text)){
        if(empty($snapshot['capabilities']['activity']))return ['answer'=>'Your account does not have access to employee activity.','data'=>[],'sources'=>[]];
        $rows=array_slice($snapshot['activity']??[],0,8);$sources[]='Audit Log';$data=$rows;
        if(!$rows)return ['answer'=>'No recent employee activity is recorded yet.','data'=>[],'sources'=>$sources];
        $parts=array_map(static fn(array $x):string=>$x['actor'].' — '.$x['label'],$rows);
        return ['answer'=>'Recent employee activity: '.implode('; ',$parts).'.','data'=>$data,'sources'=>$sources];
    }
    $s=$snapshot['scheduling'];
    if(!empty($snapshot['capabilities']['scheduling'])&&!empty($s['available'])){
        $sources[]='Scheduling';$data=$s;
        return ['answer'=>'This week has '.number_format((int)$s['shiftCount']).' scheduled shift'.((int)$s['shiftCount']===1?'':'s').', '.number_format((float)$s['scheduledHours'],1).' scheduled labor hours, '.number_format((int)$s['openShifts']).' open shift'.((int)$s['openShifts']===1?'':'s').', and '.number_format((int)$s['coverageGaps']).' coverage gap'.((int)$s['coverageGaps']===1?'':'s').'. '.number_format((int)$s['pendingTimeOff']).' time-off request'.((int)$s['pendingTimeOff']===1?' is':'s are').' pending.','data'=>$data,'sources'=>$sources];
    }
    return ['answer'=>'I can review new resumes, recent employee activity, and current staffing or scheduling coverage from the Restaurant Command Center.','data'=>$snapshot,'sources'=>['Restaurant Command Center']];
}
