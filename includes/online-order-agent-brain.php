<?php
declare(strict_types=1);

require_once __DIR__.'/online-order-agent-core.php';

function online_order_agent_brain_signal_rows(PDO $pdo,array $user): array
{
    if(!online_order_agent_can_view($user)||!online_order_agent_ready($pdo))return [];
    $queue=online_order_agent_queue_summary($pdo,$user,null);$recovery=online_order_agent_recovery_summary($pdo,$user,null);
    $risk=(int)$queue['pastPromise']+(int)$queue['readyAging']+(int)$recovery['escalated'];
    if($risk<1)return [];
    $severity=((int)$recovery['escalated']>0||(int)$queue['pastPromise']>=3)?'high':'normal';
    $score=$severity==='high'?76:58;
    $parts=[];
    if((int)$queue['pastPromise']>0)$parts[]=$queue['pastPromise'].' past promise';
    if((int)$queue['readyAging']>0)$parts[]=$queue['readyAging'].' ready 10+ minutes';
    if((int)$recovery['escalated']>0)$parts[]=$recovery['escalated'].' escalated recovery';
    return [[
        'key'=>'online-orders:pickup-risk','node'=>'online_orders','nodeLabel'=>'Online Ordering + Pickup Fulfillment','severity'=>$severity,'score'=>$score,
        'title'=>'Pickup orders need attention','detail'=>implode(', ',$parts).'.','href'=>'pickup-fulfillment.php',
        'evidence'=>['active'=>(int)$queue['active'],'ready'=>(int)$queue['ready'],'pastPromise'=>(int)$queue['pastPromise'],'readyAging'=>(int)$queue['readyAging'],'paymentDue'=>(int)$queue['paymentDue'],'escalatedRecovery'=>(int)$recovery['escalated']],
        'nextMove'=>['node'=>'online_orders','nodeLabel'=>'Online Ordering + Pickup Fulfillment','mode'=>'review','label'=>'Review pickups','prompt'=>'Review online pickup orders that are late, aging after ready, payment-blocked, or escalated and tell me what needs attention first.'],
    ]];
}

function online_order_agent_merge_brain_snapshot(PDO $pdo,array $user,array $snapshot): array
{
    $extra=online_order_agent_brain_signal_rows($pdo,$user);if(!$extra)return $snapshot;
    $byKey=[];foreach(array_merge((array)($snapshot['signals']??[]),$extra) as $signal)$byKey[(string)$signal['key']]=$signal;
    $signals=array_values($byKey);$rank=static fn(string $severity):int=>match($severity){'critical'=>4,'high'=>3,'normal'=>2,'low'=>1,default=>0};
    usort($signals,static fn(array $a,array $b):int=>((int)$b['score']<=>(int)$a['score'])?:($rank((string)$b['severity'])<=>$rank((string)$a['severity']))?:strcmp((string)$a['title'],(string)$b['title']));
    $snapshot['signals']=$signals;
    $snapshot['nextMoves']=array_values(array_map(static fn(array $signal):array=>['key'=>$signal['key'],'node'=>$signal['node'],'nodeLabel'=>$signal['nodeLabel'],'severity'=>$signal['severity'],'score'=>$signal['score'],'title'=>$signal['title'],'detail'=>$signal['detail'],'href'=>$signal['href'],'mode'=>$signal['nextMove']['mode'],'label'=>$signal['nextMove']['label'],'prompt'=>$signal['nextMove']['prompt']],array_slice($signals,0,12)));
    return $snapshot;
}
