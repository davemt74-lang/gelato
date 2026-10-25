<?php
declare(strict_types=1);

/**
 * FM-B8D13A: preflight the ENTIRE model-authored tool plan before executing
 * any skill. A user request may combine independently authorized reads, but
 * routine writes are isolated to one tool/turn. No observation is given back
 * to the remote model, and execution repeats fresh authorization per step.
 */
function fm8d13_preflight(array $plan,array $principal,array $catalogue,callable $refresh):array
{
    $stop=static fn(string $status,string $reason,int $index=0):array=>[
        'status'=>$status,'reason'=>$reason,'blockedAt'=>$index,
        'planned'=>count($plan),'executed'=>0,
    ];
    $org=(int)($principal['organization_id']??0);
    $uid=(int)($principal['id']??0);
    if($org<1||$uid<1)return $stop('denied','Authenticated account required.');
    $count=count($plan);
    if($count===0)return $stop('needs_input','No registered skills were selected.');
    if($count>4)return $stop('budget_exhausted','At most four skills can be planned at once.');
    $seen=[];$routine=0;
    foreach(array_values($plan) as $index=>$step){
        $name=$step['tool']??null;
        if(!is_array($step)||!is_string($name)
           ||!preg_match('/^[a-z][a-z0-9_.]{2,90}$/D',$name)
           ||!isset($catalogue[$name])||!is_array($catalogue[$name]))
            return $stop('denied','Unregistered skill in the plan.',$index);
        $tool=$catalogue[$name];
        $args=$step['arguments']??[];
        if(!is_array($args)||(array_is_list($args)&&$args!==[]))
            return $stop('denied','Invalid skill arguments.',$index);
        if(!isset($tool['allowedArguments'])||!is_array($tool['allowedArguments'])
           ||!is_callable($tool['authorize']??null)
           ||!is_callable($tool['validate']??null)
           ||!is_callable($tool['execute']??null))
            return $stop('denied','Incomplete registered skill contract.',$index);
        foreach(array_keys($args) as $argument){
            if(!is_string($argument)||!in_array($argument,$tool['allowedArguments'],true))
                return $stop('denied','Unsupported skill argument.',$index);
        }
        $impact=(string)($tool['impact']??'');
        if(!in_array($impact,['read','routine','high_impact'],true))
            return $stop('denied','Unsupported skill impact.',$index);
        if($impact==='high_impact'||!empty($tool['requiresConfirmation']))
            return $stop('approval_required','Use the existing verified approval workflow.',$index);
        if(in_array($name,['delegate_restaurant_domain','continue_pending_restaurant_action'],true)&&$count>1)
            return $stop('needs_input','A restaurant-agent handoff must run alone.',$index);
        if($impact==='routine')$routine++;
        if($routine>1||($routine>0&&$count>1))
            return $stop('needs_input','Run each routine change separately after reviewing its context.',$index);
        $fingerprint=hash('sha256',$name.'|'.json_encode($args,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
        if(isset($seen[$fingerprint]))
            return $stop('needs_input','Repeated skill call in the same plan.',$index);
        $seen[$fingerprint]=true;
        if($impact==='routine'&&(!is_string($step['idempotencyKey']??null)
            ||!preg_match('/^[a-zA-Z0-9:_-]{8,128}$/D',(string)$step['idempotencyKey'])))
            return $stop('denied','Routine action lacks its operation key.',$index);
        try{$actor=$refresh();}
        catch(Throwable){return $stop('denied','Current account authority unavailable.',$index);}
        if(!is_array($actor)||(int)($actor['id']??0)!==$uid
           ||(int)($actor['organization_id']??0)!==$org)
            return $stop('denied','Account access changed; please sign in again.',$index);
        try{$allowed=($tool['authorize'])($actor,$args)===true;}
        catch(Throwable){$allowed=false;}
        if(!$allowed)return $stop('denied','This skill is not available to this account.',$index);
        try{$missing=($tool['validate'])($args,$actor);}
        catch(Throwable){return $stop('denied','Invalid skill input contract.',$index);}
        if(!is_array($missing)||array_filter($missing,static fn($v)=>
            !is_string($v)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,60}$/D',$v)))
            return $stop('denied','Invalid skill input contract.',$index);
        if($missing)
            return $stop('needs_input','Additional details required: '.implode(', ',$missing),$index);
    }
    return ['status'=>'ready','reason'=>'','blockedAt'=>null,
        'planned'=>$count,'executed'=>0];
}
