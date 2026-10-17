<?php
declare(strict_types=1);

/**
 * FM-G13J employee change acknowledgement and source-bound guided refresh.
 * This modifies only the canonical person's guided-training state, using the
 * existing authenticated voice/typed identity and SQL locking in G13C.
 * Acknowledging an update is not training completion or certification.
 */
function fm13j_is_action(string $action):bool
{
    return in_array($action,['review_update','acknowledge_update','start_refresh'],true);
}
function fm13j_source_changed(?array $prior,?array $material):bool
{
    return is_array($prior)&&is_array($material)
        &&($prior['kind']??'')==='menu'&&($material['kind']??'')==='menu'
        &&(string)($prior['id']??'')===(string)($material['id']??'')
        &&preg_match('/^[a-f0-9]{64}$/D',(string)($prior['digest']??''))===1
        &&preg_match('/^[a-f0-9]{64}$/D',(string)($material['digest']??''))===1
        &&!hash_equals((string)$prior['digest'],(string)$material['digest']);
}
function fm13j_status(array $state,?array $material):array
{
    $r=is_array($state['refresh']??null)?$state['refresh']:[];
    $current=(string)($material['digest']??'');
    $saved=(string)($state['digest']??'');
    return [
        'reviewedUpdate'=>$current!==''&&hash_equals((string)($r['reviewDigest']??''),$current),
        'acknowledgedUpdate'=>$current!==''&&hash_equals((string)($r['ackDigest']??''),$current)
             &&$saved!==$current,
        'refreshInProgress'=>$current!==''&&$saved===$current
             &&($state['status']??'')==='active'&&isset($r['startedAt'])
             &&($r['sourceDigest']??'')===$current,
        'refreshCompleted'=>$current!==''&&$saved===$current
             &&($state['status']??'')==='completed'&&isset($r['completedAt'])
             &&($r['sourceDigest']??'')===$current,
    ];
}

/** Pure transition. G13C validates the saved message ID/replay before entry. */
function fm13j_transition(array $prior,array $command,?array $material,string $turnId,int $now):array
{
    $action=(string)($command['action']??'');
    $fail=static fn(string $message):array=>['changed'=>false,'state'=>$prior,'answer'=>$message];
    if(!fm13j_is_action($action)||!preg_match('/^msg-[a-f0-9]{20}$/D',$turnId)||$now<=0)
        return $fail('A canonical training turn is required.');
    if(($prior['kind']??'')!=='menu')
        return $fail('Updated training acknowledgements currently apply only to active menu preparation procedures.');
    if(!fm13j_source_changed($prior,$material)){
        return $fail($material===null
          ?'The active recorded procedure is unavailable. Ask a manager to verify the source before acknowledging.'
          :'No unreviewed procedure change was detected for this saved training session. Existing progress has not been altered.');
    }
    $digest=(string)$material['digest'];
    $refresh=is_array($prior['refresh']??null)?$prior['refresh']:[];
    $state=$prior;
    if($action==='review_update'){
        $refresh=[
            'reviewDigest'=>$digest,'reviewedAt'=>$now,'reviewTurnId'=>$turnId,
            'priorDigest'=>(string)$prior['digest'],
            'priorStepIndex'=>(int)($prior['index']??0),
            'priorStatus'=>(string)($prior['status']??''),
        ];
        $state['refresh']=$refresh;
        $state['status']='paused';
        $first=implode(' ',array_map(static fn($step,$index)=>($index+1).'. '.$step,
            array_slice($material['steps'],0,2),array_keys(array_slice($material['steps'],0,2))));
        $answer='The approved '.$material['name'].' preparation instructions changed. '.
            'Your previous step and practice remain in your saved history. '.
            'The updated procedure contains '.count($material['steps']).' recorded steps. '.
            'Preview: '.$first.'. '.
            'Say "acknowledge updated training" to acknowledge the change, then "start updated training" for a full step-by-step review. '.
            'Acknowledgement is not training completion or competency certification.';
    }elseif($action==='acknowledge_update'){
        if(($refresh['reviewDigest']??'')!==$digest
           ||($refresh['priorDigest']??'')!==(string)$prior['digest'])
            return $fail('Review the latest approved procedure first by saying "review updated training". Nothing was acknowledged.');
        if(($refresh['ackDigest']??'')===$digest)
            return $fail('This version has already been acknowledged. Say "start updated training" to begin a guided refresh.');
        $refresh['ackDigest']=$digest;
        $refresh['ackAt']=$now;
        $refresh['ackTurnId']=$turnId;
        $state['refresh']=$refresh;
        $state['status']='paused';
        $answer='You acknowledged that the '.$material['name'].' procedure was revised. '.
            'This does not confirm you have read every step or demonstrated competence. '.
            'Your earlier progress is preserved. Say "start updated training" to review the currently approved steps.';
    }else{
        if(($refresh['ackDigest']??'')!==$digest
           ||($refresh['priorDigest']??'')!==(string)$prior['digest'])
            return $fail('This exact revised procedure must be reviewed and acknowledged before starting a guided refresh.');
        $state['digest']=$digest;
        $state['index']=0;$state['stepCount']=count($material['steps']);
        $state['status']='active';$state['quiz']=null;
        $state['practiceAttempts']=0;$state['practiceCorrect']=0;
        $refresh['sourceDigest']=$digest;$refresh['startedAt']=$now;
        $refresh['completedAt']=null;
        $state['refresh']=$refresh;
        $answer=fm13c_answer($state,$material,
            'Guided review started for the acknowledged revision. Previous practice remains in your event history.');
    }
    $state['lastTurnId']=$turnId;$state['updatedAt']=$now;
    $state['lastAnswer']=mb_substr($answer,0,1100,'UTF-8');
    return ['changed'=>true,'state'=>$state,'answer'=>$answer];
}
