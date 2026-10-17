<?php
declare(strict_types=1);

/**
 * FM-G13D: source-only practice dialogue. This is rehearsal, NOT a scored
 * certification, observed kitchen performance, or a model-derived assessment.
 */
function fm13d_options(array $material,int $stepIndex):?array
{
    $steps=(array)($material['steps']??[]);
    if(count($steps)<2||$stepIndex<0||$stepIndex>=count($steps))return null;
    $seen=[];$candidates=[];
    // Actual saved steps are the only choices; the order is deterministic.
    for($offset=0;$offset<count($steps);$offset++){
        $index=($stepIndex+$offset)%count($steps);
        $line=trim((string)$steps[$index]);
        $key=mb_strtolower($line,'UTF-8');
        if($line===''||isset($seen[$key]))continue;
        $seen[$key]=true;
        $candidates[]=['index'=>$index,'text'=>mb_substr($line,0,350,'UTF-8')];
        if(count($candidates)===3)break;
    }
    if(count($candidates)<2)return null;
    // Rotate to avoid making the correct answer invariably option 1.
    $rotation=($stepIndex+1)%count($candidates);
    for($j=0;$j<$rotation;$j++)$candidates[]=array_shift($candidates);
    $correct=null;$options=[];
    foreach($candidates as $i=>$candidate){
        $options[]=($i+1).') '.$candidate['text'];
        if($candidate['index']===$stepIndex)$correct=$i+1;
    }
    if($correct===null)return null;
    return ['options'=>$options,'correct'=>$correct];
}
function fm13d_quiz_question(array $state,array $material):?array
{
    $index=(int)($state['index']??-1);
    $options=fm13d_options($material,$index);
    if(!$options)return null;
    $question='Practice check for '.$material['name'].', step '.($index+1).
        ': Which of these recorded instructions belongs at this step? '.
        implode('  ',$options['options']).' Say "training answer 1", "training answer 2",'.
        (count($options['options'])>2?' or "training answer 3"':'').
        '. This is only practice, not certification.';
    return ['answer'=>$question,'correct'=>$options['correct'],'optionCount'=>count($options['options'])];
}
function fm13d_explanation(array $state,array $material):string
{
    $index=max(0,min(count($material['steps'])-1,(int)($state['index']??0)));
    $step=(string)$material['steps'][$index];
    $notes=trim((string)($material['notes']??''));
    $answer='The recorded instruction for step '.($index+1).' is: '.$step.'.';
    if($notes!=='')$answer.=' Recorded source notes: '.mb_substr($notes,0,420,'UTF-8').'.';
    return $answer.' The saved instruction does not establish additional reasons, timing, temperatures, or allergy safety unless explicitly stated. Ask your kitchen manager when details are missing.';
}
function fm13d_progress(array $state,array $material):string
{
    $status=(string)($state['status']??'unavailable');
    $step=max(1,min(count($material['steps']),(int)($state['index']??0)+1));
    $attempts=max(0,(int)($state['practiceAttempts']??0));
    $correct=max(0,min($attempts,(int)($state['practiceCorrect']??0)));
    return 'Guided practice for '.$material['name'].': '.$status.', step '.$step.
        ' of '.count($material['steps']).'. Knowledge-check attempts: '.$attempts.
        '; correct selections: '.$correct.'. These reflect only in-chat practice and do not establish competency, qualification, or employee certification.';
}
