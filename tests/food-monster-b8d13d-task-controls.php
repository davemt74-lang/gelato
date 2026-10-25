<?php
declare(strict_types=1);
require __DIR__.'/food-monster-b8d13c-step-checkpoints.php';
$checksD=0;
function d13d_check(bool $ok,string $label):void{
    global $checksD;
    if(!$ok)throw new RuntimeException('FM-B8D13D FAIL: '.$label);
    $checksD++;
}
d13d_check(fm8d13d_ready($pdo),'cooperative cancellation migration installed');
$recent=fm8d13d_list($pdo,$actor,$thread);
d13d_check(count($recent)>=4,'recent task history available in canonical thread');
d13d_check(($recent[0]['turnId']??'')!=='' && isset($recent[0]['state'],$recent[0]['completedSteps']),
    'task listing exposes stable progress receipts');
d13d_check(!str_contains(json_encode($recent),'24.5')
    &&!str_contains(json_encode($recent),'Verified private')
    &&!str_contains(json_encode($recent),'idempotencyKey'),
    'status exposes no private observations or model arguments');
d13d_check(fm8d13d_list($pdo,array_replace($actor,['id'=>$uid+400]),$thread)===[],
    'cross-user task list is isolated');
d13d_check(fm8d13d_list($pdo,array_replace($actor,['organization_id'=>$org+400]),$thread)===[],
    'cross-tenant task list is isolated');
d13d_check(fm8d13d_status($pdo,$actor,$thread,$ctx['messageId'])['state']==='completed',
    'specific completed task remains queryable');
d13d_check(fm8d13d_status($pdo,$actor,'agent-'.str_repeat('c',20),$ctx['messageId'])===null,
    'other chat thread cannot access task receipt');

$ctxCancel=$send('Stop my two-step read at the next safe boundary.');
$cancelPlan=[['tool'=>'read_fact','arguments'=>[]],['tool'=>'read_second','arguments'=>[]]];
$claimCancel=fm8d13b_claim($pdo,$actor,$ctxCancel,$cancelPlan,$catalogue);
d13d_check($claimCancel['kind']==='acquired','read task acquired before request');
d13d_check(fm8d13d_should_stop($pdo,$claimCancel)===false,'new lease is not cancelled');
$cancelled=fm8d13d_request_cancel($pdo,$actor,$thread,$ctxCancel['messageId']);
d13d_check(($cancelled['state']??'')==='running'&&$cancelled['cancelRequested']===true,
    'live stop request is recorded without claiming work was rolled back');
d13d_check(($cancelled['canRequestCancel']??true)===false,
    'duplicate cancel buttons cannot request additional effects');
$repeat=fm8d13d_request_cancel($pdo,$actor,$thread,$ctxCancel['messageId']);
d13d_check($repeat['cancelRequested']===true&&$repeat['state']==='running',
    'repeated stop request is idempotent');
d13d_check(fm8d13d_should_stop($pdo,$claimCancel)===true,
    'server executor sees stop intent');
$before=$effects;
$traceResult=fm8d2_run($cancelPlan,$actor,$catalogue,[
    'refreshActor'=>$refresh,'audit'=>$record,
    'maxSteps'=>4,'shouldStop'=>static fn():bool=>fm8d13d_should_stop($pdo,$claimCancel),
]);
d13d_check($traceResult['status']==='cancelled'&&$effects===$before,
    'cancelled read starts no new tool action');
$saved=['ok'=>true,'data'=>['status'=>'cancelled'],'answer'=>'Stopped.'];
d13d_check(fm8d13b_finish($pdo,$claimCancel['identity'],$claimCancel['lease'],$saved,0,2),
    'cancelled result saved against exact lease');
d13d_check(fm8d13d_status($pdo,$actor,$thread,$ctxCancel['messageId'])['state']==='cancelled',
    'cancelled state is durable');
$prior=fm8d13b_prior($pdo,$actor,$ctxCancel,$catalogue,$refresh);
d13d_check(($prior['result']['data']['status']??'')==='cancelled',
    'cancelled task cannot be restarted on the same canonical turn');

$ctxForeign=$send('Only I may stop this task.');
$foreignClaim=fm8d13b_claim($pdo,$actor,$ctxForeign,$readPlan,$catalogue);
d13d_check(fm8d13d_request_cancel($pdo,array_replace($actor,['id'=>$uid+300]),$thread,$ctxForeign['messageId'])===null,
    'another account cannot cancel this task');
d13d_check(fm8d13d_request_cancel($pdo,$actor,'agent-'.str_repeat('d',20),$ctxForeign['messageId'])===null,
    'wrong thread cannot cancel valid task');
d13d_check(fm8d13d_should_stop($pdo,$foreignClaim)===false,
    'forged cancellation left original task running');
$pdo->prepare("UPDATE food_monster_agent_task_runs
  SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 2 SECOND)
  WHERE organization_id=? AND actor_user_id=? AND turn_public_id=?")
  ->execute([$org,$uid,$ctxForeign['messageId']]);
$expiredRead=fm8d13d_request_cancel($pdo,$actor,$thread,$ctxForeign['messageId']);
d13d_check($expiredRead['state']==='cancelled'&&$expiredRead['interrupted']===false,
    'expired read is finalized as cancelled without replay');
d13d_check(fm8d13d_should_stop($pdo,$foreignClaim)===true,
    'stale lease cannot run another effect');

$ctxWrite=$send('Stop a routine task at next safe boundary.');
$writeKey=[['tool'=>'routine_change','arguments'=>[],
    'idempotencyKey'=>'control-'.bin2hex(random_bytes(8))]];
$claimWrite=fm8d13b_claim($pdo,$actor,$ctxWrite,$writeKey,$catalogue);
d13d_check($claimWrite['kind']==='acquired','routine plan acquired once');
$writeRequested=fm8d13d_request_cancel($pdo,$actor,$thread,$ctxWrite['messageId']);
d13d_check($writeRequested['state']==='running'&&$writeRequested['cancelRequested']===true,
    'write cancellation is a request, never a false rollback');
$before=$effects;
$blocked=fm8d2_run($writeKey,$actor,$catalogue,[
    'refreshActor'=>$refresh,'audit'=>$record,
    'shouldStop'=>static fn():bool=>fm8d13d_should_stop($pdo,$claimWrite),
]);
d13d_check($blocked['status']==='cancelled'&&$effects===$before,
    'routine mutation did not start after cancellation intent');
d13d_check(fm8d13b_finish($pdo,$claimWrite['identity'],$claimWrite['lease'],$saved,0,1),
    'routine cancelled receipt saved before effect');
$ctxWriteExpired=$send('Report uncertain outcome of interrupted write.');
$claimExpired=fm8d13b_claim($pdo,$actor,$ctxWriteExpired,$writeKey,$catalogue);
$pdo->prepare("UPDATE food_monster_agent_task_runs
  SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 2 SECOND)
  WHERE organization_id=? AND actor_user_id=? AND turn_public_id=?")
  ->execute([$org,$uid,$ctxWriteExpired['messageId']]);
$unverified=fm8d13d_request_cancel($pdo,$actor,$thread,$ctxWriteExpired['messageId']);
d13d_check($unverified['state']==='unverified',
    'lost write lease marked uncertain instead of cancelled');
d13d_check(fm8d13d_should_stop($pdo,$claimExpired)===true,
    'expired routine lease cannot perform effects');
$after=fm8d13b_prior($pdo,$actor,$ctxWriteExpired,$catalogue,$refresh);
d13d_check(($after['result']['data']['status']??'')==='unverified',
    'uncertain write cannot be resumed');

$completedOriginal=fm8d13d_request_cancel($pdo,$actor,$thread,$ctx['messageId']);
d13d_check($completedOriginal['state']==='completed'&&!$completedOriginal['cancelRequested'],
    'late cancellation never rewrites verified completion');
d13d_check(fm8d13d_should_stop($pdo,['kind'=>'untracked'])===false,
    'legacy untracked reads retain existing behavior');
echo "FM-B8D13D chat/brain task controls: PASS ($checksD assertions)\n";
