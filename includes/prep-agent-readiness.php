<?php
declare(strict_types=1);

/**
 * Read-only readiness snapshot built exclusively from linked canonical prep tasks.
 * Managers see restaurant-wide readiness; employees see only their own assigned
 * work and must never receive a restaurant-wide "ready" assertion.
 */
function pac_readiness(PDO $pdo,int $org,int $uid,array $user,string $text,?DateTimeImmutable $now=null): array
{
    $date=prep_parse_date_phrase($text,$now);
    $service=pac_service($text);
    $manager=app_has_permission('prep.intelligence.manage',$user)&&app_has_permission('tasks.manage',$user);
    $timezone=new DateTimeZone((string)(app_config()['app']['timezone']??'America/Phoenix'));
    $now=$now?->setTimezone($timezone)??new DateTimeImmutable('now',$timezone);
    $filter=$service==='all'?'':" AND p.service_period IN (?,'all')";
    $params=[$org,$date];
    if($service!=='all')$params[]=$service;
    $plans=[];
    if($manager){
        $stmt=$pdo->prepare("SELECT public_id,service_period,status FROM prep_plans p
            WHERE p.organization_id=? AND p.plan_date=? AND p.status<>'closed'".$filter."
            ORDER BY FIELD(service_period,'morning','lunch','dinner','closing','all')");
        $stmt->execute($params);
        $plans=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $actorClause=$manager?'':" AND EXISTS (
        SELECT 1 FROM restaurant_task_assignments mine
        WHERE mine.organization_id=t.organization_id AND mine.task_id=t.id AND mine.user_id=?)";
    $taskParams=$params;
    if(!$manager)$taskParams[]=$uid;
    $q=$pdo->prepare("SELECT p.public_id plan_public_id,p.service_period,p.status plan_status,
            t.public_id,t.title,t.status,t.priority,t.due_at,
            (SELECT GROUP_CONCAT(u.display_name ORDER BY u.display_name SEPARATOR ', ')
             FROM restaurant_task_assignments a JOIN users u ON u.id=a.user_id
             WHERE a.organization_id=t.organization_id AND a.task_id=t.id) assigned_names
         FROM prep_plan_tasks pt
         JOIN prep_plans p ON p.id=pt.plan_id AND p.organization_id=pt.organization_id
         JOIN restaurant_tasks t ON t.id=pt.task_id AND t.organization_id=pt.organization_id
         WHERE pt.organization_id=? AND p.plan_date=? AND p.status<>'closed'
           AND t.archived_at IS NULL".$filter.$actorClause."
         ORDER BY FIELD(t.status,'blocked','in_progress','accepted','assigned','queued','completed','verified','cancelled'),
             t.due_at IS NULL,t.due_at,t.title LIMIT 501");
    $q->execute($taskParams);
    $all=$q->fetchAll(PDO::FETCH_ASSOC);
    $truncated=count($all)>500;
    $tasks=[];
    foreach(array_slice($all,0,500) as $row)$tasks[(string)$row['public_id']]=$row;
    $counts=['total'=>count($tasks),'completed'=>0,'open'=>0,'blocked'=>0,
        'overdue'=>0,'dueSoon'=>0,'unassigned'=>0,'cancelled'=>0];
    $issues=[];
    foreach($tasks as &$task){
        $status=(string)$task['status'];
        if($status==='cancelled'){$counts['cancelled']++;continue;}
        if(in_array($status,['completed','verified'],true)){$counts['completed']++;continue;}
        $counts['open']++;
        $due=trim((string)($task['due_at']??''));
        $seconds=null;
        if($due!=='')$seconds=(new DateTimeImmutable($due,$timezone))->getTimestamp()-$now->getTimestamp();
        $blocked=$status==='blocked';
        $unassigned=trim((string)($task['assigned_names']??''))==='';
        if($blocked)$counts['blocked']++;
        if($unassigned)$counts['unassigned']++;
        if($seconds!==null&&$seconds<0)$counts['overdue']++;
        elseif($seconds!==null&&$seconds<=3600)$counts['dueSoon']++;
        $kind=$blocked?'blocked':($seconds!==null&&$seconds<0?'overdue':($unassigned?'unassigned':($seconds!==null&&$seconds<=3600?'due soon':null)));
        if($kind!==null){
            $label=(string)$task['title'].' ('.$kind;
            if($manager&&$task['assigned_names'])$label.=', '.$task['assigned_names'];
            $label.=')';
            $issues[]=['rank'=>match($kind){'blocked'=>0,'overdue'=>1,'unassigned'=>2,default=>3},
                'taskId'=>(string)$task['public_id'],'title'=>(string)$task['title'],
                'kind'=>$kind,'label'=>$label];
        }
    }unset($task);
    usort($issues,static fn(array $a,array $b):int=>[$a['rank'],$a['title']]<=>[$b['rank'],$b['title']]);
    $actionable=$counts['total']-$counts['cancelled'];
    $percent=$actionable>0?(int)round(100*$counts['completed']/$actionable):0;
    $unpublished=$manager&&array_filter($plans,static fn(array $p):bool=>!in_array((string)$p['status'],['published'],true));
    $state=$truncated?'incomplete':($manager&&$plans===[]?'no_plan':($actionable===0?'no_tasks':
        (($counts['blocked']||$counts['overdue']||$counts['unassigned'])?'at_risk':
            ($unpublished?'unpublished':($counts['open']===0?'ready':'in_progress')))));
    $scope=$manager?($service==='all'?'Prep':ucfirst($service).' prep'):'Your assigned prep';
    $answer=$scope.' for '.$date.': ';
    if($state==='no_plan')$answer.='no active prep plan exists. Create or publish a dated prep plan before calling service ready.';
    elseif($state==='no_tasks')$answer.=$manager?'no active linked tasks. This does not establish service readiness.':'you have no assigned active prep tasks.';
    else{
        $answer.=$percent.'% complete ('.$counts['completed'].' of '.$actionable.' actionable tasks); '.
            $counts['open'].' open, '.$counts['blocked'].' blocked, '.$counts['overdue'].' overdue, '.
            $counts['dueSoon'].' due within an hour';
        if($manager)$answer.=', '.$counts['unassigned'].' unassigned';
        $answer.='. ';
        if($state==='incomplete')$answer.='More than 500 task records matched. Readiness is incomplete; open the prep dashboard for the full list.';
        elseif($state==='at_risk')$answer.=$manager?'Service prep is at risk.':'Your prep has urgent items.';
        elseif($state==='unpublished')$answer.='One or more prep plans have not been published; service is not yet ready.';
        elseif($state==='ready')$answer.=$manager?'The tracked prep tasks are ready for service.':'Your assigned prep tasks are complete.';
        else $answer.='Prep work is in progress.';
        if($issues)$answer.=' Priorities: '.implode('; ',array_column(array_slice($issues,0,5),'label')).'.';
    }
    if($manager&&$truncated)$answer.=' The task counts shown are partial.';
    return ['ok'=>true,'skill'=>'prep.readiness','answer'=>$answer,
        'data'=>['date'=>$date,'service'=>$service,'scope'=>$manager?'manager':'assigned_employee',
            'state'=>$state,'percent'=>$percent,'counts'=>$counts,'truncated'=>$truncated,
            'plans'=>$manager?$plans:[],
            'priorities'=>array_slice($issues,0,20),
            'tasks'=>array_values(array_slice($tasks,0,50)),'proof'=>['url'=>'prep-intelligence.php']],
        'sources'=>array_keys($tasks)];
}
