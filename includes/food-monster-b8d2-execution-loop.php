<?php
declare(strict_types=1);

/**
 * FM-B8D2. Bounded, request-local plan -> authorize -> execute -> observe loop.
 *
 * Only trusted PHP callers construct the catalogue and plan; a model or
 * browser may not supply callback names, routes, SQL, or arbitrary URLs.
 * Durable goals, cross-request idempotency and write skill adapters follow
 * in separate sections. Existing node approval contracts are not bypassed.
 */
function fm8d2_refresh_operator(PDO $pdo,array $principal):?array
{
    $uid=(int)($principal['id']??0);
    $org=(int)($principal['organization_id']??0);
    if($uid<1||$org<1)return null;
    $q=$pdo->prepare("SELECT r.slug AS role_slug,r.is_owner_role,r.is_system_role
        FROM users u
        JOIN organization_memberships om ON om.user_id=u.id AND om.organization_id=?
        JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL
        JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id
        WHERE u.id=? AND u.status='active' AND u.archived_at IS NULL
          AND om.status='active'
        ORDER BY (r.is_owner_role=1 OR (r.slug='super_admin' AND r.is_system_role=1)) DESC,
            r.is_owner_role DESC,r.id ASC LIMIT 1");
    $q->execute([$org,$uid]);
    $role=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($role)||!$role)return null;
    $q=$pdo->prepare("SELECT DISTINCT p.permission_key
        FROM organization_memberships om
        JOIN user_roles ur ON ur.membership_id=om.id AND ur.revoked_at IS NULL
        JOIN roles r ON r.id=ur.role_id AND r.organization_id=om.organization_id
        JOIN role_permissions rp ON rp.role_id=ur.role_id
        JOIN permissions p ON p.id=rp.permission_id
        WHERE om.user_id=? AND om.organization_id=? AND om.status='active'");
    $q->execute([$uid,$org]);
    $owner=((int)$role['is_owner_role']===1
        ||($role['role_slug']==='super_admin'&&(int)$role['is_system_role']===1));
    return array_replace($principal,$role,[
        'is_owner_role'=>$owner?1:0,
        'permissions'=>$owner?['*']:array_values($q->fetchAll(PDO::FETCH_COLUMN)),
    ]);
}

/**
 * All callbacks are server-registered. authorize and validate are evaluated
 * for each step. refreshActor MUST read current server authority, not reuse
 * the page's cached session user. No model-generated tool code is evaluated.
 */
function fm8d2_run(array $plan,array $principal,array $catalogue,array $policy):array
{
    $uid=(int)($principal['id']??0);
    $org=(int)($principal['organization_id']??0);
    if($uid<1||$org<1)throw new InvalidArgumentException('Authenticated operator required.');
    if(!isset($policy['refreshActor'])||!is_callable($policy['refreshActor']))
        throw new InvalidArgumentException('Fresh server authorization callback required.');
    $maxSteps=max(1,min(8,(int)($policy['maxSteps']??6)));
    $maxMs=max(50,min(10000,(int)($policy['maxMs']??4000)));
    $started=microtime(true);
    $trace=[];$keys=[];$requested=count($plan);
    $finish=static function(string $status,string $reason='',?int $blockedAt=null)use(&$trace,$requested,$started):array{
        return [
            'status'=>$status,'reason'=>$reason,'completed'=>count(array_filter($trace,
                static fn($step)=>($step['status']??'')==='completed')),
            'requested'=>$requested,'blockedAt'=>$blockedAt,
            'durationMs'=>(int)round((microtime(true)-$started)*1000),
            'steps'=>$trace,
        ];
    };
    if($requested===0)return $finish('needs_input','No registered tasks were selected.',0);
    if($requested>$maxSteps)return $finish('budget_exhausted','Step limit exceeded.',0);
    foreach(array_values($plan) as $i=>$step){
        if((microtime(true)-$started)*1000>$maxMs)
            return $finish('budget_exhausted','Execution time budget exhausted.',$i);
        if(!is_array($step)||!is_string($step['tool']??null)
            ||!preg_match('/^[a-z][a-z0-9_.]{2,90}$/D',$step['tool'])
            ||!isset($catalogue[$step['tool']])||!is_array($catalogue[$step['tool']]))
            return $finish('denied','Unknown or unregistered skill.',$i);
        $id=$step['tool'];$tool=$catalogue[$id];
        $args=$step['arguments']??[];
        if(!is_array($args)||array_is_list($args)&&$args!==[])
            return $finish('denied','Invalid skill arguments.',$i);
        if(!is_callable($tool['authorize']??null)||!is_callable($tool['execute']??null)
            ||!is_callable($tool['validate']??null)
            ||!isset($tool['allowedArguments'])||!is_array($tool['allowedArguments']))
            return $finish('denied','Incomplete server skill contract.',$i);
        foreach(array_keys($args) as $name)
            if(!is_string($name)||!in_array($name,$tool['allowedArguments'],true))
                return $finish('denied','Unsupported skill argument.',$i);
        try{$actor=($policy['refreshActor'])();}
        catch(Throwable){return $finish('denied','Current account authority unavailable.',$i);}
        if(!is_array($actor)||(int)($actor['id']??0)!==$uid
            ||(int)($actor['organization_id']??0)!==$org)
            return $finish('denied','Account access changed; please sign in again.',$i);
        try{$allowed=($tool['authorize'])($actor,$args)===true;}
        catch(Throwable){$allowed=false;}
        if(!$allowed)return $finish('denied','This skill is not available to your account.',$i);
        $impact=(string)($tool['impact']??'');
        if(!in_array($impact,['read','routine','high_impact'],true))
            return $finish('denied','Unsupported skill impact.',$i);
        if($impact==='high_impact'||!empty($tool['requiresConfirmation']))
            return $finish('approval_required','Use the existing verified approval workflow.',$i);
        try{$missing=($tool['validate'])($args,$actor);}
        catch(Throwable){return $finish('denied','Invalid skill request.',$i);}
        if(!is_array($missing)||array_filter($missing,static fn($v)=>!is_string($v)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,60}$/D',$v)))
            return $finish('denied','Invalid skill input contract.',$i);
        if($missing)return $finish('needs_input','Additional details required: '.implode(', ',$missing),$i);
        if($impact==='routine'){
            $key=$step['idempotencyKey']??null;
            // This prevents duplicates within this run only. B8D3 must add
            // durable storage before unattended, retryable routine writes.
            if(!is_string($key)||!preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D',$key)
                ||isset($keys[$key])||!is_callable($policy['audit']??null))
                return $finish('denied','Routine execution requires a unique operation key and audit.',$i);
            $keys[$key]=true;
        }
        try{$observation=($tool['execute'])($args,$actor);}
        catch(Throwable){$observation=null;}
        if(!is_array($observation)||($observation['ok']??null)!==true
            ||!is_array($observation['data']??null)){
            $trace[]=['index'=>$i,'tool'=>$id,'status'=>'unverified'];
            return $finish($impact==='routine'?'execution_unverified':'failed',
                'Skill returned no verified observation; do not retry automatically.',$i);
        }
        try{$json=json_encode($observation['data'],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);}
        catch(Throwable){$json=false;}
        if(!is_string($json)||strlen($json)>3072){
            $trace[]=['index'=>$i,'tool'=>$id,'status'=>'unverified'];
            return $finish($impact==='routine'?'execution_unverified':'failed',
                'Skill observation exceeded its contract; do not retry automatically.',$i);
        }
        $trace[]=['index'=>$i,'tool'=>$id,'status'=>'completed','observation'=>$observation['data']];
        if(is_callable($policy['audit']??null)){
            try{($policy['audit'])([
                'tool'=>$id,'step'=>$i,'status'=>'completed',
                'impact'=>$impact,'organizationId'=>$org,'operatorId'=>$uid,
            ]);}
            catch(Throwable){
                // An executed operation must never be silently retried after
                // an audit failure; explicitly surface uncertain audit state.
                return $finish('audit_failed','Skill completed but its audit record failed.',$i);
            }
        }
    }
    return $finish('completed');
}
