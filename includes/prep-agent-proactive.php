<?php
declare(strict_types=1);

/**
 * Read-only, actor-scoped prep alerts for the existing Proactive Agent pipeline.
 * Notify on blocker changes, unassigned work and approaching/missed deadlines.
 * Each threshold uses a stable dedupe key; completing the task removes the alert.
 */
function pac_proactive_signals(PDO $pdo,int $org,int $uid,array $user,string $day,?DateTimeImmutable $now=null): array
{
    if(!app_has_permission('prep.intelligence.view',$user))return [];
    $manager=app_has_permission('prep.intelligence.manage',$user)&&app_has_permission('tasks.manage',$user);
    $timezone=new DateTimeZone((string)(app_config()['app']['timezone']??'America/Phoenix'));
    $now=$now?->setTimezone($timezone)??new DateTimeImmutable('now',$timezone);
    $q=$pdo->prepare("SELECT p.public_id plan_public_id,p.plan_date,t.public_id task_public_id,
        t.title,t.status,t.due_at,
        (SELECT GROUP_CONCAT(u.display_name ORDER BY u.display_name SEPARATOR ', ')
          FROM restaurant_task_assignments a JOIN users u ON u.id=a.user_id
          WHERE a.organization_id=t.organization_id AND a.task_id=t.id) assigned_names,
        (SELECT e.id FROM restaurant_task_events e
          WHERE e.organization_id=t.organization_id AND e.task_id=t.id
            AND e.event_type='prep_blocker_reported'
            AND e.id>COALESCE((SELECT MAX(e2.id) FROM restaurant_task_events e2
              WHERE e2.organization_id=e.organization_id AND e2.task_id=e.task_id
                AND e2.event_type='status_changed'),0)
          ORDER BY e.id DESC LIMIT 1) blocker_event_id,
        (SELECT e.metadata_json FROM restaurant_task_events e
          WHERE e.organization_id=t.organization_id AND e.task_id=t.id
            AND e.event_type='prep_blocker_reported'
            AND e.id>COALESCE((SELECT MAX(e2.id) FROM restaurant_task_events e2
              WHERE e2.organization_id=e.organization_id AND e2.task_id=e.task_id
                AND e2.event_type='status_changed'),0)
          ORDER BY e.id DESC LIMIT 1) blocker_json,
        (SELECT COUNT(*) FROM restaurant_task_assignments a
          WHERE a.organization_id=t.organization_id AND a.task_id=t.id) assignee_count,
        (SELECT COUNT(*) FROM restaurant_task_assignments a
          WHERE a.organization_id=t.organization_id AND a.task_id=t.id AND a.user_id=?) assigned_to_me
        FROM prep_plan_tasks pt
        JOIN prep_plans p ON p.id=pt.plan_id AND p.organization_id=pt.organization_id
        JOIN restaurant_tasks t ON t.id=pt.task_id AND t.organization_id=pt.organization_id
        WHERE pt.organization_id=? AND p.plan_date=? AND p.status<>'closed'
          AND t.archived_at IS NULL AND t.status NOT IN ('completed','verified','cancelled')
        ORDER BY FIELD(t.status,'blocked','in_progress','assigned','queued','accepted'),t.due_at,t.title LIMIT 100");
    $q->execute([$uid,$org,$day]);$groups=[[],[],[],[]];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $blocked=$row['status']==='blocked';
        $mine=(int)$row['assigned_to_me']>0;
        $unassigned=(int)$row['assignee_count']===0;
        if(!$manager&&!$mine)continue;
        $due=trim((string)($row['due_at']??''));
        $seconds=$due===''?null:(new DateTimeImmutable($due,$timezone))->getTimestamp()-$now->getTimestamp();
        $kind='';
        if($blocked)$kind='prep.task_blocked';
        elseif($manager&&$unassigned)$kind='prep.task_unassigned';
        elseif($seconds!==null&&$seconds<0)$kind='prep.task_overdue';
        elseif($seconds!==null&&$seconds<=3600)$kind='prep.task_due_soon';
        else continue;
        $priority=in_array($kind,['prep.task_blocked','prep.task_overdue'],true)?'high':'normal';
        $blocker=$row['blocker_json']?json_decode((string)$row['blocker_json'],true):null;
        $reason=trim((string)($blocker['reason']??''));
        $assignees=trim((string)($row['assigned_names']??''));
        $taskName=(string)$row['title'];
        if($kind==='prep.task_blocked'){
            $message='Prep task blocked: '.$taskName.($assignees!==''?' ('.$assignees.')':'').'. '.
                ($reason!==''?'Reason: '.$reason.'.':'Ask the assignee for the blocker reason.');
            $group=0;
        }elseif($kind==='prep.task_overdue'){
            $message='Overdue prep task: '.$taskName.($manager&&$assignees!==''?' ('.$assignees.')':'').
                '. Due '.$due.'. Check progress before service.';
            $group=1;
        }elseif($kind==='prep.task_unassigned'){
            $message='Unassigned prep task: '.$taskName.'. Assign an employee before service.';
            $group=2;
        }else{
            $message='Prep task due within one hour: '.$taskName.($manager&&$assignees!==''?' ('.$assignees.')':'').
                '. Due '.$due.'.';
            $group=3;
        }
        $key='prep-task:'.$row['plan_public_id'].':'.$row['task_public_id'].':'.$kind.':'.$day;
        if($blocked)$key.=':'.(int)($row['blocker_event_id']??0);
        if($kind==='prep.task_overdue'||$kind==='prep.task_due_soon')$key.=':'.$due;
        $groups[$group][]=[
            'type'=>$kind,'priority'=>$priority,'message'=>$message,'key'=>$key,
            'url'=>'prep-intelligence.php',
            'meta'=>['planId'=>$row['plan_public_id'],'taskId'=>$row['task_public_id'],'date'=>$day],
        ];
    }
    return array_slice(array_merge(...$groups),0,10);
}
