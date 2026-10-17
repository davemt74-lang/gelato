<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-g13c-training.php';

/** Explicit read-only Agent Brain requests. No generic "training" hijack. */
function fm13e_intent(string $message):?string
{
    $s=mb_strtolower(trim(preg_replace('/\s+/u',' ',$message)??$message),'UTF-8');
    $s=rtrim($s," .!?");
    if(in_array($s,['my training insights','my learning history','my guided practice summary','my food monster training history'],true))return 'self';
    if(in_array($s,['team training insights','employee training intelligence','training coaching overview','food monster training intelligence'],true))return 'team';
    return null;
}

/** Never expose team data through the room microphone or a staff login. */
function fm13e_scope(array $user,?string $intent,string $channel,bool $owner):?string
{
    if($channel!=='text')return null;
    if($intent==='team')return $owner?'team':null;
    if($intent==='self')return $owner
      ||app_has_permission('training.self_view',$user)
      ||app_has_permission('employee.self',$user)?'self':null;
    return null;
}

/**
 * Input rows are server-read canonical system messages from a tenant-scoped
 * training thread. Ordered newest-first. Never consume user-supplied events.
 * This is interaction evidence only, not a measure of staff performance.
 */
function fm13e_aggregate(array $rows,array $allowedIds,int $maxPeople=30):array
{
    $allow=array_fill_keys(array_map('intval',$allowedIds),true);
    $totals=[];$histories=[];
    foreach($rows as $row){
        $personId=(int)($row['person_id']??0);
        if($personId<1||!isset($allow[$personId])||($row['role']??'')!=='system')continue;
        $meta=$row['structured']??null;
        if(!is_array($meta)||($meta['collection']??'')!=='fm13c_guided_training'
          ||($meta['source']??'')!=='gelato_training')continue;
        $state=$meta['state']??null;
        if(!is_array($state)||!in_array($state['kind']??'', ['menu','recipe'],true)
          ||trim((string)($state['id']??''))===''||!in_array($state['status']??'', ['active','paused','completed'],true)
          ||!is_numeric($state['index']??null)||!is_numeric($state['stepCount']??null)
          ||(int)$state['index']<0||(int)$state['stepCount']<1
          ||(int)$state['stepCount']>16||(int)$state['index']>=(int)$state['stepCount'])continue;
        if(!isset($totals[$personId])){
            if(count($totals)>=$maxPeople)continue;
            $totals[$personId]=[
              'personId'=>$personId,'current'=>[
                'kind'=>(string)$state['kind'],'id'=>(string)$state['id'],
                'status'=>(string)$state['status'],'stepIndex'=>(int)$state['index'],
                'stepCount'=>(int)$state['stepCount'],
                'practiceAttempts'=>max(0,min(100000,(int)($state['practiceAttempts']??0))),
                'practiceCorrect'=>max(0,min(100000,(int)($state['practiceCorrect']??0))),
                'digest'=>(string)($state['digest']??'')],
              'lastActivity'=>(string)($row['created_at']??''),
              'events'=>0,'actionCounts'=>[],'procedures'=>[]];
        }
        $record=&$totals[$personId];
        $record['events']++;
        $key=$state['kind'].':'.$state['id'];
        $record['procedures'][$key]=($record['procedures'][$key]??0)+1;
        $action=(string)($meta['action']??'');
        if(in_array($action,['start','next','repeat','previous','pause','resume',
          'restart','question','quiz','answer','hint','progress'],true)){
            $record['actionCounts'][$action]=($record['actionCounts'][$action]??0)+1;
        }
        unset($record);
    }
    foreach($totals as &$record){
        $actions=$record['actionCounts'];
        $record['revisits']=($actions['repeat']??0)+($actions['previous']??0)+($actions['restart']??0);
        $record['helpRequests']=($actions['hint']??0)+($actions['question']??0);
        $record['coachingSuggestion']=$record['helpRequests']>=3||$record['revisits']>=4
            ?'Offer an optional walkthrough or ask whether the recorded procedure needs clarification.'
            :null;
        arsort($record['procedures'],SORT_NUMERIC);
        $record['frequentProcedures']=array_slice(
          array_map(static fn($key,$count)=>['source'=>$key,'recordedInteractions'=>$count],
            array_keys($record['procedures']),array_values($record['procedures'])),0,3);
        unset($record['procedures']);
    }
    unset($record);
    return ['participants'=>array_values($totals),'peopleWithActivity'=>count($totals),
        'recordedEvents'=>array_sum(array_column($totals,'events'))];
}

/** One bounded query; belongs to selected tenant and explicitly selected users. */
function fm13e_recent_records(PDO $pdo,int $org,array $allowedIds):array
{
    $ids=array_slice(array_values(array_unique(array_filter(array_map('intval',$allowedIds),
        static fn($x)=>$x>0))),0,30);
    if(!$ids)return ['rows'=>[],'capped'=>false];
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT c.user_id person_id,m.role,m.structured_json,m.created_at
      FROM agent_conversations c
      JOIN agent_messages m ON m.conversation_id=c.id
        AND m.organization_id=c.organization_id
      WHERE c.organization_id=? AND c.user_id IN ($marks)
        AND c.title='Food Monster Guided Training'
        AND m.role='system' AND m.created_at>=DATE_SUB(NOW(6),INTERVAL 60 DAY)
        AND m.structured_json LIKE '%\"fm13c_guided_training\"%'
      ORDER BY m.id DESC LIMIT 501");
    $q->execute(array_merge([$org],$ids));
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $capped=count($rows)>500;
    $rows=array_slice($rows,0,500);
    foreach($rows as &$row){
        $row['structured']=is_string($row['structured_json']??null)
            ?json_decode($row['structured_json'],true):null;
        unset($row['structured_json']);
    }
    unset($row);
    return ['rows'=>$rows,'capped'=>$capped];
}

function fm13e_answer(array $insights,string $scope,array $people):array
{
    $list=$insights['participants'];
    $names=[];
    foreach($people as $person)$names[(int)$person['id']]=
        mb_substr((string)($person['display_name']??'Employee'),0,70,'UTF-8');
    $lines=[];
    foreach(array_slice($list,0,12) as $person){
        $current=$person['current'];
        $name=$scope==='self'?'Your training':($names[$person['personId']]??'Participant')."'s training";
        $source=empty($person['sourceAvailable'])
            ?'source no longer confirmed'
            :mb_substr((string)($person['sourceTitle']??''),0,100,'UTF-8');
        if($source==='')$source='source not identified';
        $lines[]=$name.' ('.$source.'): '.$current['status'].', step '.($current['stepIndex']+1).
            '/'.$current['stepCount'].'; recorded interactions in the last 60 days: '.$person['events'].
            '; recorded practice attempts (current session): '.$current['practiceAttempts'].
            ', correct selections: '.$current['practiceCorrect'].'.'.
            ($person['coachingSuggestion']?' Optional support: '.$person['coachingSuggestion']:'');
    }
    $message=$lines?implode("\n",$lines):
        'No recorded guided-training activity is available for this authorized view in the last 60 days.';
    if($scope==='team'&&count($list)>12)$message.="\nAdditional participants appear in the structured Agent Brain results.";
    if(!empty($insights['capped']))$message.="\nThis view reached its 500-event limit; older events may be omitted.";
    return ['skill'=>'food_monster.training_intelligence',
        'answer'=>$message."\nThese are practice and help-request observations, not competency assessments, employee ratings, or certifications.",
        'sources'=>['Gelato canonical, person-scoped Guided Training Agent Brain history'],
        'data'=>['scope'=>$scope,'windowDays'=>60,'isPartial'=>!empty($insights['capped']),
           'recordedEvents'=>$insights['recordedEvents'],
           'peopleWithActivity'=>$insights['peopleWithActivity'],
           'participants'=>$list]];
}
