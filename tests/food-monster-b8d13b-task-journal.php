<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-workspace-core.php';
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';
$checks=0;$effects=0;$providerCalls=0;$audit=[];
function b8d13b_check(bool $good,string $name):void{
    global $checks;
    if(!$good)throw new RuntimeException('FM-B8D13B FAIL: '.$name);
    $checks++;
}
$pdo=app_pdo();
b8d13b_check(fm8d13b_ready($pdo),'durable task migration available');
$slug='b8d13b-'.bin2hex(random_bytes(6));
$pdo->prepare("INSERT INTO organizations (name,status,timezone)
    VALUES (?,'active','America/Phoenix')")->execute(['Task journal '.$slug]);
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','hash','Task','Owner','Task Owner']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status)
    VALUES (?,?,'active')")->execute([$org,$uid]);
$membership=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO roles
    (organization_id,name,slug,is_system_role,is_owner_role,is_assignable)
    VALUES (?,'Test Owner',?,0,1,1)")->execute([$org,'owner_'.$slug]);
$role=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO user_roles (membership_id,role_id) VALUES (?,?)")
    ->execute([$membership,$role]);
$base=['id'=>$uid,'organization_id'=>$org,'display_name'=>'Task Owner'];
$actor=fm8d2_refresh_operator($pdo,$base);
b8d13b_check(is_array($actor)&&in_array('*',$actor['permissions'],true),
    'fresh authenticated owner permissions');
$thread=(string)gaw_create_thread($pdo,$org,$uid,'text','Journal Test')['public_id'];
$send=static function(string $prompt)use($pdo,$org,$uid,$thread):array{
    $row=gaw_append($pdo,$org,$uid,$thread,'user',$prompt);
    return ['threadId'=>$thread,'messageId'=>(string)$row['id'],
        'channel'=>'text','verifiedSameUser'=>true];
};
$ctx=$send('Tell me my verified facts.');
b8d13b_check(fm8d13b_identity($actor,$ctx)!==null,
    'journal accepts canonical chat identity');
b8d13b_check(fm8d13b_identity($actor,['threadId'=>$thread,'messageId'=>'bad'])===null,
    'journal rejects forged message identifier');
$catalogue=[];
$catalogue['read_fact']=[
    'description'=>'Read one verified private fact.',
    'parameters'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
    'allowedArguments'=>[],'impact'=>'read',
    'authorize'=>static fn(array $who,array $args):bool=>app_has_permission('journal.read',$who),
    'validate'=>static fn(array $args,array $who):array=>[],
    'execute'=>static function(array $args,array $who)use(&$effects):array{
        $effects++;return ['ok'=>true,'data'=>[
            'answer'=>'Verified private hours: 24.5','source'=>'Gelato timecards']];
    },
];
$catalogue['routine_change']=array_replace($catalogue['read_fact'],[
    'impact'=>'routine','description'=>'Safe audited training update',
    'authorize'=>static fn(array $who,array $args):bool=>app_has_permission('journal.write',$who),
    'execute'=>static function(array $args,array $who)use(&$effects):array{
        $effects++;return ['ok'=>true,'data'=>[
            'answer'=>'Verified training update','source'=>'Gelato training ledger']];
    },
]);
$refresh=static fn():?array=>fm8d2_refresh_operator($pdo,$base);
$record=static function(array $event)use(&$audit):void{$audit[]=$event;};
$history=[['role'=>'user','content'=>'Tell me my verified facts.']];
$transport=static function()use(&$providerCalls):array{
    $providerCalls++;
    return ['output'=>[['type'=>'function_call','name'=>'read_fact',
        'arguments'=>'{}','call_id'=>'read-1']]];
};
$r=fm8d3_turn($pdo,$actor,'openai','test',$history,'',$transport,
    $catalogue,$refresh,$record,$ctx);
b8d13b_check(($r['data']['status']??'')==='completed'&&$effects===1,
    'model-selected read executes and records verified outcome');
$status=fm8d13b_status($pdo,$actor,$thread,$ctx['messageId']);
b8d13b_check(($status['state']??'')==='completed'
    &&$status['completedSteps']===1&&$status['plannedSteps']===1,
    'task status survives a new HTTP request');
$replay=fm8d3_turn($pdo,$actor,'openai','test',$history,'',
    static function():array{throw new RuntimeException('provider must not be called');},
    $catalogue,$refresh,$record,$ctx);
b8d13b_check($replay['answer']===$r['answer']&&$effects===1&&$providerCalls===1,
    'exact chat-turn replay returns only previously verified response');
b8d13b_check(!str_contains(json_encode($status),'private hours')
    &&!str_contains(json_encode($status),'routine_change'),
    'status exposes metadata, not tool observations or plan arguments');
$foreign=array_replace($actor,['id'=>$uid+100]);
b8d13b_check(fm8d13b_status($pdo,$foreign,$thread,$ctx['messageId'])===null,
    'another user cannot retrieve the task');
b8d13b_check(fm8d13b_status($pdo,$actor,'agent-'.str_repeat('b',20),
    $ctx['messageId'])===null,'another thread cannot retrieve task');
$revoked=static fn():array=>array_replace($actor,['permissions'=>[]]);
$blocked=fm8d13b_prior($pdo,$actor,$ctx,$catalogue,$revoked);
b8d13b_check(($blocked['result']['data']['status']??'')==='denied'
    &&!str_contains($blocked['result']['answer'],'24.5'),
    'a revoked account cannot replay cached private results');
$readPlan=[['tool'=>'read_fact','arguments'=>[]]];
$ctx2=$send('Read that again if the server restarts.');
$claim=fm8d13b_claim($pdo,$actor,$ctx2,$readPlan,$catalogue);
b8d13b_check($claim['kind']==='acquired','read-only task receives durable lease');
$pending=fm8d13b_claim($pdo,$actor,$ctx2,$readPlan,$catalogue);
b8d13b_check(($pending['result']['data']['status']??'')==='in_progress',
    'concurrent duplicate task is not executed');
$pdo->prepare("UPDATE food_monster_agent_task_runs
    SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 2 SECOND)
    WHERE organization_id=? AND actor_user_id=? AND turn_public_id=?")
    ->execute([$org,$uid,$ctx2['messageId']]);
$recover=fm8d13b_prior($pdo,$actor,$ctx2,$catalogue,$refresh);
b8d13b_check($recover['kind']==='resume'&&$recover['plan']===$readPlan,
    'expired pure-read task can resume its original server-owned plan');
$beforeProvider=$providerCalls;
$resumed=fm8d3_turn($pdo,$actor,'openai','test',$history,'',
    static function():array{throw new RuntimeException('recovery must not call AI provider');},
    $catalogue,$refresh,$record,$ctx2);
$status2=fm8d13b_status($pdo,$actor,$thread,$ctx2['messageId']);
b8d13b_check(($resumed['data']['status']??'')==='completed'
    &&$status2['attempts']===2&&$providerCalls===$beforeProvider,
    'read task recovers without a second model plan');
$ctx3=$send('Apply one audited training change.');
$writePlan=[['tool'=>'routine_change','arguments'=>[],
    'idempotencyKey'=>'unique-test-'.bin2hex(random_bytes(8))]];
$claim=fm8d13b_claim($pdo,$actor,$ctx3,$writePlan,$catalogue);
b8d13b_check($claim['kind']==='acquired','routine write obtains dedicated lease');
$pdo->prepare("UPDATE food_monster_agent_task_runs
    SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 2 SECOND)
    WHERE organization_id=? AND actor_user_id=? AND turn_public_id=?")
    ->execute([$org,$uid,$ctx3['messageId']]);
$before=$effects;
$uncertain=fm8d13b_prior($pdo,$actor,$ctx3,$catalogue,$refresh);
b8d13b_check(($uncertain['result']['data']['status']??'')==='execution_unverified'
    &&$effects===$before,'expired write is never retried automatically');
$status3=fm8d13b_status($pdo,$actor,$thread,$ctx3['messageId']);
b8d13b_check(($status3['state']??'')==='unverified',
    'uncertain routine write receives persistent terminal status');
$ctx4=$send('Make one approved routine training change.');
$writeTransport=static function()use(&$providerCalls):array{
    $providerCalls++;return ['output'=>[['type'=>'function_call',
        'name'=>'routine_change','arguments'=>'{}','call_id'=>'write-1']]];
};
$write=fm8d3_turn($pdo,$actor,'openai','test',$history,'',
    $writeTransport,$catalogue,$refresh,$record,$ctx4);
b8d13b_check(($write['data']['status']??'')==='completed'
    &&$effects===$before+1,'routine tool writes once under task journal');
$noRepeat=fm8d3_turn($pdo,$actor,'openai','test',$history,'',
    static function():array{throw new RuntimeException('should not repeat write');},
    $catalogue,$refresh,$record,$ctx4);
b8d13b_check($noRepeat['answer']===$write['answer']&&$effects===$before+1,
    'completed write returns stored receipt without duplicate mutation');
$ctx5=$send('Store one plan only.');
$a=fm8d13b_claim($pdo,$actor,$ctx5,$readPlan,$catalogue);
$b=fm8d13b_claim($pdo,$actor,$ctx5,$writePlan,$catalogue);
b8d13b_check($a['kind']==='acquired'
    &&($b['result']['data']['status']??'')==='conflict',
    'canonical message cannot switch the selected plan mid-flight');
$providerJson=json_encode(['calls'=>$providerCalls]);
b8d13b_check(!str_contains($providerJson,'24.5'),
    'private response is never echoed to external model payload');
echo "FM-B8D13B restart-safe task journal: PASS ($checks assertions)\n";
