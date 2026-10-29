<?php
declare(strict_types=1);

/**
 * Live handoff from canonical prep assignments. No copied handoff records,
 * implicit writes, or restaurant-wide details for staff-only viewers.
 */
function pac_shift_service(DateTimeImmutable $start): string
{
    $hour=(int)$start->format('G');
    if($hour<10)return 'morning';
    if($hour<15)return 'lunch';
    if($hour<20)return 'dinner';
    return 'closing';
}

function pac_shift_handoff(PDO $pdo,int $org,int $uid,array $user,?array $shift=null,string $message='',?DateTimeImmutable $now=null): ?array
{
    if(!app_has_permission('prep.intelligence.view',$user)||!app_has_permission('prep.intelligence.agent',$user)
        ||!prep_intelligence_ready($pdo))return null;
    $timezone=new DateTimeZone((string)(app_config()['app']['timezone']??'America/Phoenix'));
    $now=$now?->setTimezone($timezone)??new DateTimeImmutable('now',$timezone);
    $explicitDate=$message!==''&&pac_has_explicit_date($message);
    $explicitService=$message!==''&&preg_match('/\b(?:morning|lunch|dinner|closing)\b/iu',$message)===1;
    $start=!empty($shift['starts_at'])?new DateTimeImmutable((string)$shift['starts_at'],$timezone):null;
    if(!$start&&!$explicitDate&&!$explicitService){
        return ['ok'=>true,'skill'=>'prep.shift_handoff','answer'=>'I could not find a published upcoming shift. Ask for a specific service, such as “prep handoff for lunch today”.',
            'data'=>['state'=>'no_shift','date'=>null,'service'=>null,'scope'=>(app_has_permission('prep.intelligence.manage',$user)&&app_has_permission('tasks.manage',$user))?'manager':'assigned_employee',
                'counts'=>[],'priorities'=>[],'proof'=>['url'=>'employee-home.php']],'sources'=>[]];
    }
    $date=$explicitDate?prep_parse_date_phrase($message,$now):($start?$start->format('Y-m-d'):$now->format('Y-m-d'));
    $service=$explicitService?pac_service($message):($start?pac_shift_service($start):'all');
    $readiness=pac_readiness($pdo,$org,$uid,$user,'prep readiness '.$service.' on '.$date,$now);
    $data=$readiness['data'];
    // Read current blocker reasons only for the already-authorized task set.
    // This cannot leak another employee's blockers or resurrect a stale reason.
    $blockers=[];
    $reasonQuery=$pdo->prepare("SELECT e.metadata_json FROM restaurant_task_events e
        JOIN restaurant_tasks t ON t.id=e.task_id AND t.organization_id=e.organization_id
        WHERE e.organization_id=? AND t.public_id=? AND e.event_type='prep_blocker_reported'
          AND e.id>COALESCE((SELECT MAX(e2.id) FROM restaurant_task_events e2
            WHERE e2.organization_id=e.organization_id AND e2.task_id=e.task_id
              AND e2.event_type='status_changed'),0)
        ORDER BY e.id DESC LIMIT 1");
    foreach($data['tasks'] as $task){
        if((string)$task['status']!=='blocked')continue;
        $reasonQuery->execute([$org,(string)$task['public_id']]);
        $metadata=json_decode((string)($reasonQuery->fetchColumn()?:''),true);
        $reason=trim((string)($metadata['reason']??''))?:'No reason reported';
        $blockers[]=['taskId'=>(string)$task['public_id'],'title'=>(string)$task['title'],
            'reason'=>$reason];
        if(count($blockers)>=6)break;
    }
    $data['blockers']=$blockers;
    $data['shiftId']=(!$explicitDate&&!$explicitService)?($shift['public_id']??null):null;
    $data['proof']=['url'=>'employee-home.php','prepUrl'=>'prep-intelligence.php'];
    $intro='Prep handoff for '.$date.' '.($service==='all'?'all services':$service).' service. ';
    $blockerText=$blockers?' Blocker details: '.implode('; ',array_map(
        static fn(array $row):string=>$row['title'].' — '.$row['reason'],array_slice($blockers,0,3))).'.':'';
    return ['ok'=>true,'skill'=>'prep.shift_handoff','answer'=>$intro.$readiness['answer'].$blockerText,
        'data'=>$data,'sources'=>$readiness['sources']];
}
