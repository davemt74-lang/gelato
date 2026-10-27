<?php
declare(strict_types=1);

/**
 * Read-only, actor-scoped prep alerts for the existing Proactive Agent pipeline.
 * The delivery layer handles one-use dedupe and per-user listening preferences.
 */
function pac_proactive_signals(PDO $pdo,int $org,int $uid,array $user,string $day): array
{
    if(!app_has_permission('prep.intelligence.view',$user))return [];
    $manager=app_has_permission('prep.intelligence.manage',$user)&&app_has_permission('tasks.manage',$user);
    $q=$pdo->prepare("SELECT p.public_id plan_public_id,p.plan_date,t.public_id task_public_id,
        t.title,t.status,t.due_at,
        (SELECT COUNT(*) FROM restaurant_task_assignments a
          WHERE a.organization_id=t.organization_id AND a.task_id=t.id) assignee_count,
        (SELECT COUNT(*) FROM restaurant_task_assignments a
          WHERE a.organization_id=t.organization_id AND a.task_id=t.id AND a.user_id=?) assigned_to_me
        FROM prep_plan_tasks pt
        JOIN prep_plans p ON p.id=pt.plan_id AND p.organization_id=pt.organization_id
        JOIN restaurant_tasks t ON t.id=pt.task_id AND t.organization_id=pt.organization_id
        WHERE pt.organization_id=? AND p.plan_date=? AND p.status<>'closed'
          AND t.archived_at IS NULL AND t.status NOT IN ('completed','verified','cancelled')
        ORDER BY FIELD(t.status,'blocked','in_progress','assigned','queued','accepted'),t.due_at,t.title LIMIT 60");
    $q->execute([$uid,$org,$day]);$signals=[];
    foreach($q->fetchAll() as $row){
        $blocked=$row['status']==='blocked';
        $mine=(int)$row['assigned_to_me']>0;
        $unassigned=(int)$row['assignee_count']===0;
        if(!$manager&&!$mine)continue;
        if(!$blocked&&!($manager&&$unassigned))continue;
        $kind=$blocked?'prep.task_blocked':'prep.task_unassigned';
        $priority=$blocked?'high':'normal';
        $message=$blocked?('Prep task blocked: '.$row['title'].'. Review the blocker before service.')
            :('Unassigned prep task: '.$row['title'].'. Assign an employee before service.');
        $signals[]=[
            'type'=>$kind,'priority'=>$priority,'message'=>$message,
            'key'=>'prep-task:'.$row['plan_public_id'].':'.$row['task_public_id'].':'.$kind.':'.$day,
            'url'=>'prep-intelligence.php',
            'meta'=>['planId'=>$row['plan_public_id'],'taskId'=>$row['task_public_id'],'date'=>$day],
        ];
    }
    return array_slice($signals,0,10);
}
