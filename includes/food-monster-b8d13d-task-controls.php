<?php
declare(strict_types=1);

/**
 * FM-B8D13D: owner-of-turn task visibility and cooperative cancellation.
 * The browser never receives plan inputs, private observations or lease keys.
 * Cancellation is an intent, not proof that an in-flight write was undone.
 */
function fm8d13d_ready(PDO $pdo):bool
{
    if(!fm8d13b_ready($pdo))return false;
    try{
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='food_monster_agent_task_runs'
            AND column_name='cancel_requested_at'");
        return (int)$q->fetchColumn()===1;
    }catch(Throwable){return false;}
}
function fm8d13d_public(array $row):array
{
    $state=(string)($row['state']??'unverified');
    $expired=$state==='running'
        &&strtotime((string)($row['lease_expires_at']??'').' UTC')<=time();
    $requested=!empty($row['cancel_requested_at']);
    // Only names of registered tasks, never private model arguments/results.
    $plan=fm8d13b_decode_plan($row);
    $tools=[];
    foreach($plan??[] as $step){
        $name=is_array($step)?($step['tool']??null):null;
        if(is_string($name)&&preg_match('/^[a-z][a-z0-9_.]{2,90}$/D',$name))
            $tools[$name]=$name;
    }
    return ['turnId'=>(string)$row['turn_public_id'],
        'state'=>$state,'impact'=>(string)$row['impact'],
        'attempts'=>(int)$row['attempts'],
        'completedSteps'=>(int)$row['completed_steps'],
        'plannedSteps'=>(int)$row['planned_steps'],
        'interrupted'=>$expired,'cancelRequested'=>$requested,
        'canRequestCancel'=>$state==='running'&&!$requested,
        'tools'=>array_values($tools),
        'createdAt'=>(string)$row['created_at'],
        'updatedAt'=>(string)$row['updated_at']];
}
function fm8d13d_list(PDO $pdo,array $principal,string $thread,int $limit=8):array
{
    $org=(int)($principal['organization_id']??0);
    $uid=(int)($principal['id']??0);
    if($org<1||$uid<1||!preg_match('/^agent-[a-f0-9]{20}$/D',$thread))
        throw new InvalidArgumentException('Invalid task conversation.');
    if(!fm8d13d_ready($pdo))return [];
    $limit=max(1,min(20,$limit));
    $q=$pdo->prepare("SELECT * FROM food_monster_agent_task_runs
        WHERE organization_id=? AND actor_user_id=? AND thread_public_id=?
        ORDER BY created_at DESC,id DESC LIMIT ".$limit);
    $q->execute([$org,$uid,$thread]);
    return array_map('fm8d13d_public',$q->fetchAll(PDO::FETCH_ASSOC));
}
function fm8d13d_status(PDO $pdo,array $principal,string $thread,string $turn):?array
{
    $id=fm8d13b_identity($principal,['threadId'=>$thread,'messageId'=>$turn]);
    if($id===null||!fm8d13d_ready($pdo))return null;
    $row=fm8d13b_fetch($pdo,$id);
    return $row?fm8d13d_public($row):null;
}
/**
 * Only the originating signed-in account can request its own task stopped.
 * An expired read is safely cancelled; an expired write remains unverified.
 */
function fm8d13d_request_cancel(PDO $pdo,array $principal,string $thread,string $turn):?array
{
    $id=fm8d13b_identity($principal,['threadId'=>$thread,'messageId'=>$turn]);
    if($id===null||!fm8d13d_ready($pdo))return null;
    $pdo->beginTransaction();
    try{
        $row=fm8d13b_fetch($pdo,$id,true);
        if($row===null){$pdo->commit();return null;}
        if((string)$row['state']==='running'){
            $expired=strtotime((string)$row['lease_expires_at'].' UTC')<=time();
            if($expired){
                $next=(string)$row['impact']==='read'?'cancelled':'unverified';
                $q=$pdo->prepare("UPDATE food_monster_agent_task_runs
                    SET cancel_requested_at=COALESCE(cancel_requested_at,UTC_TIMESTAMP(6)),
                    state=?,resolved_at=UTC_TIMESTAMP(6)
                    WHERE id=? AND state='running'");
                $q->execute([$next,(int)$row['id']]);
            }else{
                $q=$pdo->prepare("UPDATE food_monster_agent_task_runs
                    SET cancel_requested_at=COALESCE(cancel_requested_at,UTC_TIMESTAMP(6))
                    WHERE id=? AND state='running'");
                $q->execute([(int)$row['id']]);
            }
        }
        $fresh=fm8d13b_fetch($pdo,$id,true);
        $pdo->commit();
        return $fresh?fm8d13d_public($fresh):null;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
/** Called at executor safe points, before a new skill effect can begin. */
function fm8d13d_should_stop(PDO $pdo,array $claim):bool
{
    if(($claim['kind']??'')!=='acquired')return false;
    if(!fm8d13d_ready($pdo))return false;
    [$org,$uid,$thread,$turn]=$claim['identity'];
    $q=$pdo->prepare("SELECT state,cancel_requested_at
        FROM food_monster_agent_task_runs
        WHERE organization_id=? AND actor_user_id=? AND thread_public_id=?
        AND turn_public_id=? AND lease_token=? LIMIT 1");
    $q->execute([$org,$uid,$thread,$turn,$claim['lease']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    // Missing/changed lease is a hard stop: never run another effect.
    return !is_array($row)||$row['state']!=='running'
        ||!empty($row['cancel_requested_at']);
}
