<?php
declare(strict_types=1);

require_once __DIR__.'/customer-crm-intelligence.php';

function customer_crm_brain_signal_rows(PDO $pdo,array $user): array
{
    if(!app_has_permission('crm.view',$user))return [];
    $rows=[];
    foreach(cri_global_opportunities($pdo,$user,10) as $op){
        $rows[]=[
            'key'=>'crm:'.(string)$op['key'],
            'node'=>'crm',
            'nodeLabel'=>'Customer CRM',
            'severity'=>(string)$op['severity'],
            'score'=>(int)$op['score'],
            'title'=>(string)$op['title'],
            'detail'=>(string)$op['detail'],
            'href'=>'customer-crm.php',
            'evidence'=>['customerPublicId'=>$op['customer']['publicId'],'opportunityType'=>$op['type']],
            'nextMove'=>['node'=>'crm','nodeLabel'=>'Customer CRM','mode'=>'review','label'=>'Review','prompt'=>(string)$op['prompt']],
        ];
    }
    return $rows;
}

function customer_crm_merge_brain_snapshot(PDO $pdo,array $user,array $snapshot): array
{
    $extra=customer_crm_brain_signal_rows($pdo,$user);if(!$extra)return $snapshot;
    $byKey=[];foreach(array_merge((array)($snapshot['signals']??[]),$extra) as $signal)$byKey[(string)$signal['key']]=$signal;
    $signals=array_values($byKey);
    $rank=static fn(string $severity):int=>match($severity){'critical'=>4,'high'=>3,'normal'=>2,'low'=>1,default=>0};
    usort($signals,static fn(array $a,array $b):int=>((int)$b['score']<=>(int)$a['score'])?:($rank((string)$b['severity'])<=>$rank((string)$a['severity']))?:strcmp((string)$a['title'],(string)$b['title']));
    $snapshot['signals']=$signals;
    $snapshot['nextMoves']=array_values(array_map(static fn(array $signal):array=>['key'=>$signal['key'],'node'=>$signal['node'],'nodeLabel'=>$signal['nodeLabel'],'severity'=>$signal['severity'],'score'=>$signal['score'],'title'=>$signal['title'],'detail'=>$signal['detail'],'href'=>$signal['href'],'mode'=>$signal['nextMove']['mode'],'label'=>$signal['nextMove']['label'],'prompt'=>$signal['nextMove']['prompt']],array_slice($signals,0,12)));
    return $snapshot;
}

function customer_crm_proactive_events(PDO $pdo,array $user): array
{
    $events=[];
    foreach(array_slice(customer_crm_brain_signal_rows($pdo,$user),0,6) as $signal){
        $type=(string)($signal['evidence']['opportunityType']??'relationship');
        if(!in_array($type,['in_house_relationship','birthday','reengagement'],true))continue;
        $priority=$type==='in_house_relationship'?'normal':'low';
        $events[]=[
            'type'=>'brain.'.str_replace(':','.',(string)$signal['key']),
            'priority'=>$priority,
            'message'=>$signal['title'].'. '.$signal['detail'],
            'key'=>'brain:'.$signal['key'].':'.date('Y-m-d-H'),
            'url'=>$signal['href'],
            'meta'=>['node'=>'crm','score'=>$signal['score'],'nextMove'=>$signal['nextMove'],'customerPublicId'=>$signal['evidence']['customerPublicId']??null],
        ];
    }
    return $events;
}
