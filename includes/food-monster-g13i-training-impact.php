<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-g13h-unified-audit.php';
require_once __DIR__.'/food-monster-g13e-intelligence.php';

/** Ask expressly for change impact; never intercept ticket or prep commands. */
function fm13i_intent(string $message):?string
{
    $s=mb_strtolower(trim(preg_replace('/\s+/u',' ',$message)??$message),'UTF-8');
    $s=rtrim($s," .!?");
    if(in_array($s,['my training updates','what changed in my training','review my procedure changes'],true))return 'self';
    if(in_array($s,['team training updates','team procedure change impact','review changed training procedures'],true))return 'team';
    return null;
}
function fm13i_allowed(array $user,string $intent,string $channel,bool $isOwner):bool
{
    return $channel==='text'
        &&($intent==='self'?(app_has_permission('training.self_view',$user)
           ||app_has_permission('employee.self',$user)||$isOwner)
          :($intent==='team'&&$isOwner&&app_has_permission('menu.manage',$user)
             &&app_has_permission('menu.view',$user)));
}
function fm13i_revisions(array $events,int $org,int $owner):array
{
    $found=[];
    foreach($events as $row){
        $revision=fm13h_normalize($row,$org,$owner);
        if($revision===null)continue;
        $id=(int)$revision['itemId'];
        if(!isset($found[$id])||$revision['publishedAt']>=$found[$id]['publishedAt'])
            $found[$id]=$revision;
    }
    return $found;
}
/**
 * A progress ledger is not a certification. A source mismatch is an impact
 * flag: G13C already pauses the session the next time it advances.
 */
function fm13i_assess(array $state,?array $material,?array $revision):array
{
    $saved=(string)($state['digest']??'');
    $current=(string)($material['digest']??'');
    $status=$material===null?'source_missing':($saved!==''&&hash_equals($saved,$current)
       ?'current':'needs_review');
    $ack=fm13j_status($state,$material);
    return [
      'reviewedUpdate'=>$ack['reviewedUpdate'],
      'acknowledgedUpdate'=>$ack['acknowledgedUpdate'],
      'refreshInProgress'=>$ack['refreshInProgress'],
      'refreshCompleted'=>$ack['refreshCompleted'],
      'kind'=>(string)($state['kind']??''),
      'itemId'=>(int)($state['id']??0),
      'trainingStatus'=>(string)($state['status']??'unknown'),
      'step'=>(int)($state['index']??0)+1,
      'stepCount'=>(int)($state['stepCount']??0),
      'impact'=>$status,
      'recordedChange'=>is_array($revision),
      'lastChangedAt'=>$revision['publishedAt']??null,
      'sourceName'=>$material!==null?mb_substr((string)($material['name']??''),0,100,'UTF-8'):null,
      'recommendation'=>$status==='current'
        ?($ack['refreshCompleted']?'The revised procedure walkthrough is complete. This is not competency certification.'
          :($ack['refreshInProgress']?'Continue the new guided review of the acknowledged procedure.'
            :'Continue the recorded procedure.'))
        :($status==='source_missing'
          ?'Ask a manager to verify the missing active procedure before resuming.'
          :($ack['acknowledgedUpdate']?'The revised source is acknowledged. Say "start updated training" for a guided review.'
            :'Review the updated preparation steps. Say "review updated training", then acknowledge the change before refreshing practice.'))
    ];
}
function fm13i_summary(array $people,string $scope,bool $partial):array
{
    $affected=count(array_filter($people,static fn($p)=>$p['impact']!=='current'));
    $answer=$affected
        ?$affected.' authorized training session(s) need source review before the next guided step.'
        :'No outdated source was detected in the authorized active training sessions checked.';
    $answer.=' No progress was reset, no employee was graded, and no procedure was published.';
    if($partial)$answer.=' Results are limited to the first 30 authorized people or the most recent 500 audit events.';
    return ['skill'=>'food_monster.training_change_impact','answer'=>$answer,
        'sources'=>['Gelato active menu procedures','Person-scoped guided training history',
            'Unified Food Monster and Menu Manager audit'],
        'data'=>['scope'=>$scope,'participants'=>$people,'affected'=>$affected,
            'partial'=>$partial,'readOnly'=>true]];
}
