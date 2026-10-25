<?php
declare(strict_types=1);
/**
 * FM-B8D13C. Local-only, per-step checkpoints for model-selected Agent tasks.
 * A checkpoint is signed by its server-held execution lease and bound to
 * the same plan fingerprint, organization, user, turn and tool index.
 * Historical observations NEVER round-trip to an LLM provider.
 */
function fm8d13c_ready(PDO $pdo):bool
{
    try{
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE
            table_schema=DATABASE() AND table_name='food_monster_agent_task_steps'");
        return (int)$q->fetchColumn()===1;
    }catch(Throwable){return false;}
}
function fm8d13c_lease(PDO $pdo,array $claim,bool $lock=false):array
{
    if(($claim['kind']??'')!=='acquired'
        ||!isset($claim['identity'],$claim['lease']))
        throw new DomainException('A trusted task lease is required.');
    [$org,$user,$thread,$turn]=$claim['identity'];
    $q=$pdo->prepare("SELECT id,plan_fingerprint,impact,planned_steps
        FROM food_monster_agent_task_runs
        WHERE organization_id=? AND actor_user_id=? AND thread_public_id=?
          AND turn_public_id=? AND lease_token=? AND state='running'
          AND lease_expires_at>UTC_TIMESTAMP(6)"
        .($lock?' FOR UPDATE':''));
    $q->execute([$org,$user,$thread,$turn,$claim['lease']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new DomainException('Agent task lease is no longer current.');
    return $row;
}
/** Resume only contiguous, verified observations from the exact saved plan. */
function fm8d13c_trace(PDO $pdo,array $claim,array $plan):array
{
    if(!fm8d13c_ready($pdo)||($claim['kind']??'')!=='acquired')return [];
    $task=fm8d13c_lease($pdo,$claim);
    if(!hash_equals((string)$task['plan_fingerprint'],fm8d13b_hash($plan)))
        throw new DomainException('Checkpoint plan fingerprint changed.');
    $q=$pdo->prepare("SELECT step_index,tool_name,plan_fingerprint,observation_json
        FROM food_monster_agent_task_steps WHERE task_run_id=?
        ORDER BY step_index ASC");
    $q->execute([(int)$task['id']]);
    $trace=[];$next=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $index=(int)$row['step_index'];
        if($index!==$next||!isset($plan[$index])
            ||$row['tool_name']!==($plan[$index]['tool']??null)
            ||!hash_equals((string)$task['plan_fingerprint'],(string)$row['plan_fingerprint']))
            throw new DomainException('Task checkpoint sequence does not match its plan.');
        // Only read observations may be automatically reused after lease loss.
        if($task['impact']!=='read')
            throw new DomainException('Routine updates cannot resume checkpoints.');
        $json=(string)$row['observation_json'];
        $observation=json_decode($json,true);
        if(strlen($json)>3072||!is_array($observation))
            throw new DomainException('Task checkpoint observation is invalid.');
        $trace[]=['index'=>$index,'tool'=>(string)$row['tool_name'],
            'status'=>'completed','observation'=>$observation];
        $next++;
    }
    return $trace;
}
/**
 * Called only after a skill executes, its observation contract validates,
 * and its regular audit succeeds. Transactionally stores a verified result.
 */
function fm8d13c_checkpoint(PDO $pdo,array $claim,array $plan,array $step):void
{
    if(!fm8d13c_ready($pdo)||($claim['kind']??'')!=='acquired')return;
    $index=$step['index']??null;
    $tool=$step['tool']??null;
    $obs=$step['observation']??null;
    if(!is_int($index)||$index<0||$index>=count($plan)
        ||!is_string($tool)||$tool!==($plan[$index]['tool']??null)
        ||($step['status']??'')!=='completed'||!is_array($obs))
        throw new DomainException('Only an executed, verified plan step may be saved.');
    $json=json_encode($obs,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
    if(strlen($json)>3072)throw new DomainException('Checkpoint observation is too large.');
    $pdo->beginTransaction();
    try{
        $task=fm8d13c_lease($pdo,$claim,true);
        $fingerprint=fm8d13b_hash($plan);
        if(!hash_equals((string)$task['plan_fingerprint'],$fingerprint))
            throw new DomainException('Cannot checkpoint a modified plan.');
        $q=$pdo->prepare("SELECT tool_name,plan_fingerprint,observation_json
            FROM food_monster_agent_task_steps
            WHERE task_run_id=? AND step_index=? FOR UPDATE");
        $q->execute([(int)$task['id'],$index]);
        $existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if($existing['tool_name']!==$tool
                ||!hash_equals((string)$existing['plan_fingerprint'],$fingerprint)
                ||!hash_equals((string)$existing['observation_json'],$json))
                throw new DomainException('Checkpoint collision; do not replay tool.');
        }else{
            $q=$pdo->prepare("INSERT INTO food_monster_agent_task_steps
                (task_run_id,step_index,tool_name,plan_fingerprint,observation_json)
                VALUES (?,?,?,?,?)");
            $q->execute([(int)$task['id'],$index,$tool,$fingerprint,$json]);
        }
        $q=$pdo->prepare("UPDATE food_monster_agent_task_runs
            SET completed_steps=GREATEST(completed_steps,?)
            WHERE id=? AND lease_token=? AND state='running'");
        $q->execute([$index+1,(int)$task['id'],$claim['lease']]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
