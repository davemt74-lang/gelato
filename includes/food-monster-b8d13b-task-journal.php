<?php
declare(strict_types=1);

/**
 * FM-B8D13B. Persistent, user-and-tenant-bound Agent execution.
 *
 * Only a canonical Agent Chat turn can own a task. The plan is preflighted
 * by FM-B8D13A before claim and again on read-only recovery. Leases prevent
 * concurrent execution; expired writes are unverified, never retried.
 * Completed observations remain local to Gelato and are not sent to an LLM.
 */
function fm8d13b_ready(PDO $pdo):bool
{
    try{
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE
            table_schema=DATABASE() AND table_name='food_monster_agent_task_runs'");
        return (int)$q->fetchColumn()===1;
    }catch(Throwable){return false;}
}
function fm8d13b_identity(array $principal,array $context):?array
{
    $org=(int)($principal['organization_id']??0);
    $user=(int)($principal['id']??0);
    $thread=(string)($context['threadId']??'');
    $turn=(string)($context['messageId']??'');
    if($org<1||$user<1||!preg_match('/^agent-[a-f0-9]{20}$/D',$thread)
        ||!preg_match('/^msg-[a-f0-9]{20}$/D',$turn))return null;
    return [$org,$user,$thread,$turn];
}
function fm8d13b_fetch(PDO $pdo,array $id,bool $lock=false):?array
{
    [$org,$user,$thread,$turn]=$id;
    $q=$pdo->prepare("SELECT * FROM food_monster_agent_task_runs
        WHERE organization_id=? AND actor_user_id=? AND turn_public_id=?"
        .($lock?' FOR UPDATE':''));
    $q->execute([$org,$user,$turn]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))return null;
    // Never reveal a task from another chat thread even for the same user.
    return hash_equals((string)$row['thread_public_id'],$thread)?$row:null;
}
function fm8d13b_stop(string $reason,string $status='denied'):array
{
    return ['ok'=>true,'skill'=>'agent.task_journal',
        'answer'=>$reason,'sources'=>[],
        'data'=>['status'=>$status,'toolCalls'=>0]];
}
function fm8d13b_decode_plan(array $row):?array
{
    $plan=json_decode((string)($row['plan_json']??''),true);
    return is_array($plan)&&array_is_list($plan)&&count($plan)>0&&count($plan)<=4?$plan:null;
}
function fm8d13b_impact(array $plan,array $catalogue):string
{
    foreach($plan as $step){
        $type=$catalogue[$step['tool']??'']['impact']??null;
        if($type!=='read')return 'routine';
    }
    return 'read';
}
function fm8d13b_hash(array $plan):string
{
    return hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
}
/**
 * Read an existing task BEFORE spending another provider request. On an
 * expired read-only task return its original plan for fresh preflight/retry.
 * Cached results are served only after rechecking current authority.
 */
function fm8d13b_prior(PDO $pdo,array $principal,array $context,
    array $catalogue,callable $refresh):?array
{
    $id=fm8d13b_identity($principal,$context);
    if($id===null||!fm8d13b_ready($pdo))return null;
    $row=fm8d13b_fetch($pdo,$id);
    if($row===null)return null;
    $plan=fm8d13b_decode_plan($row);
    if($plan===null||!hash_equals((string)$row['plan_fingerprint'],fm8d13b_hash($plan)))
        return ['kind'=>'response','result'=>fm8d13b_stop('The saved task could not be verified. No action was retried.')];
    $pre=fm8d13_preflight($plan,$principal,$catalogue,$refresh);
    if(($pre['status']??'')!=='ready')
        return ['kind'=>'response','result'=>fm8d13b_stop('Your current permissions or task inputs no longer authorize this task. No action was repeated.')];
    $state=(string)$row['state'];
    if($state==='completed'){
        $result=json_decode((string)($row['result_json']??''),true);
        return ['kind'=>'response','result'=>is_array($result)&&($result['ok']??false)===true
            ?$result:fm8d13b_stop('The completed task receipt is unavailable; no action was repeated.','unverified')];
    }
    if($state==='running'){
        if(strtotime((string)$row['lease_expires_at'].' UTC')>time())
            return ['kind'=>'response','result'=>fm8d13b_stop('I am still processing that task. Please check again shortly.','in_progress')];
        if((string)$row['impact']==='read')
            return ['kind'=>'resume','plan'=>$plan];
        // Mark a lost write lease uncertain, without executing it again.
        $q=$pdo->prepare("UPDATE food_monster_agent_task_runs SET state='unverified',
            resolved_at=UTC_TIMESTAMP(6) WHERE id=? AND state='running'
            AND lease_expires_at<=UTC_TIMESTAMP(6)");
        $q->execute([(int)$row['id']]);
        return ['kind'=>'response','result'=>fm8d13b_stop(
            'The earlier update may have completed before the connection stopped. I will not repeat it automatically; please verify the affected record.','execution_unverified')];
    }
    return ['kind'=>'response','result'=>fm8d13b_stop(
        'That Agent task is recorded as '.$state.'. Start a new chat request after reviewing the result.',$state)];
}
/**
 * Transactional claim. A turn cannot change its chosen plan after execution
 * starts. Return acquired lease or a stable no-execution response.
 */
function fm8d13b_claim(PDO $pdo,array $principal,array $context,
    array $plan,array $catalogue):array
{
    $id=fm8d13b_identity($principal,$context);
    $impact=fm8d13b_impact($plan,$catalogue);
    if($id===null){
        if($impact==='read')return ['kind'=>'untracked'];
        return ['kind'=>'response','result'=>fm8d13b_stop(
            'Save this message in Agent Chat before an update can run.','needs_input')];
    }
    if(!fm8d13b_ready($pdo)){
        if($impact==='read')return ['kind'=>'untracked'];
        return ['kind'=>'response','result'=>fm8d13b_stop(
            'Run the Gelato database upgrade before the Agent can safely track updates.','migration_required')];
    }
    [$org,$user,$thread,$turn]=$id;
    $json=json_encode($plan,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
    if(strlen($json)>12000)return ['kind'=>'response',
        'result'=>fm8d13b_stop('The task plan exceeds the safe storage limit.')];
    $fingerprint=hash('sha256',$json);
    $token=bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try{
        $row=fm8d13b_fetch($pdo,$id,true);
        if($row===null){
            $q=$pdo->prepare("INSERT INTO food_monster_agent_task_runs
                (organization_id,actor_user_id,thread_public_id,turn_public_id,
                 plan_fingerprint,plan_json,impact,state,lease_token,lease_expires_at,
                 planned_steps) VALUES (?,?,?,?,?,?,?,'running',?,
                 DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 45 SECOND),?)");
            $q->execute([$org,$user,$thread,$turn,$fingerprint,$json,$impact,$token,count($plan)]);
        }else{
            if(!hash_equals((string)$row['plan_fingerprint'],$fingerprint)
                ||(string)$row['impact']!==$impact){
                $pdo->commit();
                return ['kind'=>'response','result'=>fm8d13b_stop(
                    'This message already has a different saved task. No action was executed.','conflict')];
            }
            if($row['state']!=='running'){
                $pdo->commit();
                return ['kind'=>'response','result'=>fm8d13b_stop(
                    'This task was already finalized. No action was repeated.','already_finalized')];
            }
            if(strtotime((string)$row['lease_expires_at'].' UTC')>time()){
                $pdo->commit();
                return ['kind'=>'response','result'=>fm8d13b_stop(
                    'This task is already running. No duplicate action was started.','in_progress')];
            }
            if($impact!=='read'){
                $q=$pdo->prepare("UPDATE food_monster_agent_task_runs
                    SET state='unverified',resolved_at=UTC_TIMESTAMP(6) WHERE id=?");
                $q->execute([(int)$row['id']]);
                $pdo->commit();
                return ['kind'=>'response','result'=>fm8d13b_stop(
                    'The previous update has an uncertain outcome. Verify it before requesting another change.','execution_unverified')];
            }
            $q=$pdo->prepare("UPDATE food_monster_agent_task_runs SET lease_token=?,
                lease_expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 45 SECOND),
                attempts=attempts+1,completed_steps=0 WHERE id=?");
            $q->execute([$token,(int)$row['id']]);
        }
        $pdo->commit();
        return ['kind'=>'acquired','lease'=>$token,'identity'=>$id];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
/** Store only the final verified local answer, never provider-bound context. */
function fm8d13b_finish(PDO $pdo,array $id,string $token,array $response,
    int $completed,int $planned):bool
{
    $json=json_encode($response,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
    if(strlen($json)>24000)throw new RuntimeException('Verified task result is too large.');
    $state=(string)($response['data']['status']??'');
    $status=$state==='completed'?'completed':
        ($state==='execution_unverified'||$state==='unverified'?'unverified':'stopped');
    [$org,$user,$thread,$turn]=$id;
    $q=$pdo->prepare("UPDATE food_monster_agent_task_runs
        SET state=?,result_json=?,completed_steps=?,planned_steps=?,
            resolved_at=UTC_TIMESTAMP(6)
        WHERE organization_id=? AND actor_user_id=? AND thread_public_id=?
            AND turn_public_id=? AND lease_token=? AND state='running'");
    $q->execute([$status,$json,max(0,min($completed,$planned)),$planned,
        $org,$user,$thread,$turn,$token]);
    return $q->rowCount()===1;
}
/** Public metadata only; observations and private tool arguments stay hidden. */
function fm8d13b_status(PDO $pdo,array $principal,string $thread,string $turn):?array
{
    $id=fm8d13b_identity($principal,['threadId'=>$thread,'messageId'=>$turn]);
    if($id===null||!fm8d13b_ready($pdo))return null;
    $row=fm8d13b_fetch($pdo,$id);
    if($row===null)return null;
    return ['state'=>(string)$row['state'],
        'attempts'=>(int)$row['attempts'],
        'completedSteps'=>(int)$row['completed_steps'],
        'plannedSteps'=>(int)$row['planned_steps'],
        'interrupted'=>$row['state']==='running'
            &&strtotime((string)$row['lease_expires_at'].' UTC')<=time(),
        'createdAt'=>(string)$row['created_at'],
        'updatedAt'=>(string)$row['updated_at']];
}
