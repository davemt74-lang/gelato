<?php
declare(strict_types=1);
/**
 * B8D13C runs the certified B8D13B integration harness first, then exercises
 * actual SQL-backed per-step checkpoints and interrupted-plan continuation.
 */
require __DIR__.'/food-monster-b8d13b-task-journal.php';
$more=0;
function b8d13c_check(bool $good,string $name):void {
    global $more;
    if(!$good)throw new RuntimeException('FM-B8D13C FAIL: '.$name);
    $more++;
}
b8d13c_check(fm8d13c_ready($pdo),'new per-step migration installed');
$secondEffects=0;
$catalogue['read_second']=array_replace($catalogue['read_fact'],[
    'description'=>'Read a second independent verified fact.',
    'execute'=>static function(array $args,array $who)use(&$secondEffects):array{
        $secondEffects++;
        return ['ok'=>true,'data'=>['answer'=>'Second verified fact: 18',
            'source'=>'Gelato training dashboard']];
    },
]);
$plan=[
    ['tool'=>'read_fact','arguments'=>[]],
    ['tool'=>'read_second','arguments'=>[]],
];
b8d13c_check(fm8d13_preflight($plan,$actor,$catalogue,$refresh)['status']==='ready',
    'two independent reads pass complete-plan preflight');
$ctx=$send('Give me the two verified facts even if the browser reloads.');
$claim=fm8d13b_claim($pdo,$actor,$ctx,$plan,$catalogue);
b8d13c_check(($claim['kind']??'')==='acquired',
    'canonical read task has one exclusive server lease');
$firstObservation=['answer'=>'Verified private hours: 24.5','source'=>'Gelato timecards'];
$before=$effects;
$real=$catalogue['read_fact']['execute']([],$actor);
b8d13c_check($real['ok']===true&&$effects===$before+1,
    'first live read executed exactly once');
fm8d13c_checkpoint($pdo,$claim,$plan,[
    'index'=>0,'tool'=>'read_fact','status'=>'completed',
    'observation'=>$real['data'],
]);
$state=fm8d13b_status($pdo,$actor,$thread,$ctx['messageId']);
b8d13c_check(($state['completedSteps']??null)===1
    &&$state['plannedSteps']===2,'first step is durably checkpointed');
$trace=fm8d13c_trace($pdo,$claim,$plan);
b8d13c_check(count($trace)===1&&$trace[0]['observation']===$firstObservation,
    'checkpoint recovers exact private local observation');
fm8d13c_checkpoint($pdo,$claim,$plan,[
    'index'=>0,'tool'=>'read_fact','status'=>'completed',
    'observation'=>$real['data'],
]);
b8d13c_check(count(fm8d13c_trace($pdo,$claim,$plan))===1,
    'identical checkpoint retries do not duplicate a step');
try{
    fm8d13c_checkpoint($pdo,$claim,$plan,[
        'index'=>0,'tool'=>'read_fact','status'=>'completed',
        'observation'=>['answer'=>'Forger changed record'],
    ]);
    $collision=false;
}catch(DomainException){$collision=true;}
b8d13c_check($collision,'changed checkpoint result is rejected');
try{fm8d13c_trace($pdo,$claim,array_reverse($plan));$altered=false;}
catch(DomainException){$altered=true;}
b8d13c_check($altered,'modified plan cannot read saved observations');
$pdo->prepare("UPDATE food_monster_agent_task_runs
    SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 2 SECOND)
    WHERE organization_id=? AND actor_user_id=? AND turn_public_id=?")
    ->execute([$org,$uid,$ctx['messageId']]);
$prior=fm8d13b_prior($pdo,$actor,$ctx,$catalogue,$refresh);
b8d13c_check(($prior['kind']??'')==='resume',
    'an interrupted read request is eligible for fresh-authority recovery');
$beforeFirst=$effects;$beforeSecond=$secondEffects;
$recover=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Give me two verified facts']],'',
    static function():array{throw new RuntimeException('Never replan on provider');},
    $catalogue,$refresh,$record,$ctx);
b8d13c_check(($recover['data']['status']??'')==='completed'
    &&str_contains($recover['answer'],'24.5')
    &&str_contains($recover['answer'],'18'),
    'recovering task combines historical and newly verified observations locally');
b8d13c_check($effects===$beforeFirst&&$secondEffects===$beforeSecond+1,
    'first completed step was NOT executed twice');
$state=fm8d13b_status($pdo,$actor,$thread,$ctx['messageId']);
b8d13c_check(($state['state']??'')==='completed'
    &&($state['completedSteps']??null)===2,
    'task reaches durable two-step completed status');
$cached=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Give me two verified facts']],'',
    static function():array{throw new RuntimeException('Do not contact model');},
    $catalogue,$refresh,$record,$ctx);
b8d13c_check($cached['answer']===$recover['answer']
    &&$effects===$beforeFirst&&$secondEffects===$beforeSecond+1,
    'completed task returns saved answer with no new skill calls');
$ctxRevoked=$send('Read both private facts.');
$claimRevoked=fm8d13b_claim($pdo,$actor,$ctxRevoked,$plan,$catalogue);
fm8d13c_checkpoint($pdo,$claimRevoked,$plan,[
    'index'=>0,'tool'=>'read_fact','status'=>'completed',
    'observation'=>$firstObservation,
]);
$pdo->prepare("UPDATE food_monster_agent_task_runs
    SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 2 SECOND)
    WHERE organization_id=? AND actor_user_id=? AND turn_public_id=?")
    ->execute([$org,$uid,$ctxRevoked['messageId']]);
$deny=fm8d13b_prior($pdo,$actor,$ctxRevoked,$catalogue,
    static fn():array=>array_replace($actor,['permissions'=>[]]));
b8d13c_check(($deny['result']['data']['status']??'')==='denied'
    &&!str_contains($deny['result']['answer'],'24.5'),
    'authorization revocation blocks reuse of private checkpoint');
$badPlan=[['tool'=>'routine_change','arguments'=>[],
    'idempotencyKey'=>'fixed-task-'.bin2hex(random_bytes(6))]];
$ctxRoutine=$send('Apply a routine training edit.');
$routineClaim=fm8d13b_claim($pdo,$actor,$ctxRoutine,$badPlan,$catalogue);
fm8d13c_checkpoint($pdo,$routineClaim,$badPlan,[
    'index'=>0,'tool'=>'routine_change','status'=>'completed',
    'observation'=>['answer'=>'Routine update completed'],
]);
try{fm8d13c_trace($pdo,$routineClaim,$badPlan);$writeReuse=false;}
catch(DomainException){$writeReuse=true;}
b8d13c_check($writeReuse,'routine write checkpoints cannot be resumed as reads');
echo "FM-B8D13C per-step checkpoint recovery: PASS ($more assertions)\n";
