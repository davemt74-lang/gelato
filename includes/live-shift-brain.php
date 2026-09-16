<?php
declare(strict_types=1);

require_once __DIR__.'/live-shift-agent-core.php';

function live_shift_brain_signal_rows(PDO $pdo,array $user): array
{
    if(!live_shift_can_view($user))return [];
    try{$locationId=live_shift_location($pdo,$user,[]);$live=live_shift_snapshot($pdo,$user,$locationId);}catch(Throwable){return [];}
    $rows=[];
    foreach((array)$live['nextMoves'] as $move){
        $rows[]=[
            'key'=>'live:'.(string)$move['key'],
            'node'=>'live_shift',
            'nodeLabel'=>'Live Shift Orchestration',
            'severity'=>(string)$move['severity'],
            'score'=>(int)$move['score'],
            'title'=>(string)$move['title'],
            'detail'=>(string)$move['detail'],
            'href'=>'table-service.php',
            'evidence'=>['locationId'=>$locationId,'checkPublicId'=>$move['checkPublicId']??null,'tablePublicId'=>$move['tablePublicId']??null],
            'nextMove'=>['node'=>'live_shift','nodeLabel'=>'Live Shift Orchestration','mode'=>'review','label'=>'Review','prompt'=>(string)$move['prompt']],
        ];
    }
    return $rows;
}

function live_shift_merge_brain_snapshot(PDO $pdo,array $user,array $snapshot): array
{
    $extra=live_shift_brain_signal_rows($pdo,$user);if(!$extra)return $snapshot;
    $byKey=[];foreach(array_merge((array)($snapshot['signals']??[]),$extra) as $signal)$byKey[(string)$signal['key']]=$signal;
    $signals=array_values($byKey);
    $rank=static fn(string $severity):int=>match($severity){'critical'=>4,'high'=>3,'normal'=>2,'low'=>1,default=>0};
    usort($signals,static fn(array $a,array $b):int=>((int)$b['score']<=>(int)$a['score'])?:($rank((string)$b['severity'])<=>$rank((string)$a['severity']))?:strcmp((string)$a['title'],(string)$b['title']));
    $snapshot['signals']=$signals;
    $snapshot['nextMoves']=array_values(array_map(static fn(array $signal):array=>['key'=>$signal['key'],'node'=>$signal['node'],'nodeLabel'=>$signal['nodeLabel'],'severity'=>$signal['severity'],'score'=>$signal['score'],'title'=>$signal['title'],'detail'=>$signal['detail'],'href'=>$signal['href'],'mode'=>$signal['nextMove']['mode'],'label'=>$signal['nextMove']['label'],'prompt'=>$signal['nextMove']['prompt']],array_slice($signals,0,12)));
    return $snapshot;
}

function live_shift_proactive_events(PDO $pdo,array $user): array
{
    $events=[];foreach(array_slice(live_shift_brain_signal_rows($pdo,$user),0,6) as $signal){if(!in_array((string)$signal['severity'],['critical','high'],true))continue;$events[]=['type'=>'brain.'.str_replace(':','.',(string)$signal['key']),'priority'=>'high','message'=>$signal['title'].'. '.$signal['detail'],'key'=>'brain:'.$signal['key'].':'.date('Y-m-d-H'),'url'=>$signal['href'],'meta'=>['node'=>'live_shift','score'=>$signal['score'],'nextMove'=>$signal['nextMove']]];}return $events;
}
