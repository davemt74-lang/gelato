<?php
declare(strict_types=1);
require_once __DIR__.'/food-monster-conversation-memory.php';

/**
 * FM-G11D: voluntary conversational help grounded in two distinct, previous
 * canonical questions from the SAME server-resolved employee. No background
 * microphone, actions, performance scoring, unsolicited staff surveillance,
 * or authority inferred from memory.
 */
function fm11d_suggest_from_memory(array $memory,string $utterance,string $currentMessageId):?array
{
    $category=fm9e_classify($utterance);
    if(!in_array($category,['ingredient_question','training_question'],true))return null;
    if(!preg_match('/^msg-[a-f0-9]{20}$/D',$currentMessageId))return null;
    $matches=fm9f_relevant_context($memory,$utterance,3);
    $eligible=[];$seen=[];$evidenceText=[];
    foreach($matches as $match){
        if(($match['type']??'')!==$category)continue;
        $item=(array)($match['item']??[]);
        $id=(string)($item['messageId']??'');
        if(!preg_match('/^msg-[a-f0-9]{20}$/D',$id)
           ||$id===$currentMessageId||isset($seen[$id]))continue;
        // Two message IDs repeating identical text are not independent learning evidence.
        // Do not turn one duplicated voice recognition into a repeated-needs signal.
        // Reject exact and near-identical questions, including recognition
        // duplicates with a different prefix, filler wording, or punctuation.
        $content=mb_strtolower(trim(preg_replace('/\\s+/u',' ',(string)($item['text']??''))??''),'UTF-8');
        if($content==='')continue;
        $tokens=fm9f_keywords($content);
        $tokens=array_values(array_unique($tokens));sort($tokens,SORT_STRING);
        $signature=$tokens?implode('|',$tokens):$content;
        if(isset($evidenceText[$signature]))continue;
        $evidenceText[$signature]=true;
        $seen[$id]=true;$eligible[]=$id;
    }
    if(count($eligible)<2)return null;
    return [
      'type'=>$category==='training_question'?'training_recap':'ingredient_recap',
      'text'=>$category==='training_question'
        ?'Would a quick step-by-step refresher on this preparation topic help?'
        :'Would a quick ingredient recap help with this question?',
      'sourceMessageIds'=>array_slice($eligible,0,2),
      'requiresApproval'=>false,'executed'=>false,
    ];
}
function fm11d_verified_turn_suggestion(PDO $pdo,array $operator,array $pending,string $text):?array
{
    if(!fm9a_is_super_admin_session($operator))return null;
    $personId=(int)($pending['personId']??0);
    $messageId=(string)($pending['personMessageId']??'');
    if($personId<1||!preg_match('/^msg-[a-f0-9]{20}$/D',$messageId)
       ||time()-(int)($pending['createdAt']??0)>120
       ||time()<(int)($pending['createdAt']??0))return null;
    $memory=fm9e_person_memory($pdo,$operator,$personId,10);
    $hint=fm11d_suggest_from_memory($memory,$text,$messageId);
    if(!$hint)return null;
    // One suggestion per employee per 20 minutes in the signed-in owner's
    // session. This does not write an employee progress assessment.
    $key=(int)$operator['organization_id'].':'.(int)$operator['id'].':'.$personId;
    if(!isset($_SESSION['fm11d_hint_times'])||!is_array($_SESSION['fm11d_hint_times']))
        $_SESSION['fm11d_hint_times']=[];
    if(!isset($_SESSION['fm12d_hint_fingerprints'])||!is_array($_SESSION['fm12d_hint_fingerprints']))
        $_SESSION['fm12d_hint_fingerprints']=[];
    if(!fm12d_advice_gate($_SESSION['fm11d_hint_times'],
       $_SESSION['fm12d_hint_fingerprints'],$key,$hint,time()))return null;
    return $hint;
}

/**
 * FM-G12D: deterministic, testable session-only Agent Brain suggestion policy.
 * Prevent repeat offers even when source order changes or cooldown expires.
 * Keep at most 32 people, 16 fingerprints per person, and a one-day horizon.
 * A suggestion grants no task or account permissions.
 */
function fm12d_advice_gate(array &$times,array &$history,string $key,array $hint,int $now):bool
{
    if($key===''||$now<=0||!in_array($hint['type']??'', ['training_recap','ingredient_recap'],true))return false;
    $ids=$hint['sourceMessageIds']??[];
    if(!is_array($ids)||count($ids)!==2||count(array_unique($ids))!==2)return false;
    foreach($ids as $id)if(!is_string($id)||!preg_match('/^msg-[a-f0-9]{20}$/D',$id))return false;
    sort($ids,SORT_STRING);
    $fingerprint=hash('sha256',$hint['type'].'|'.implode('|',$ids));
    $latest=(int)($times[$key]??0);
    if($latest>$now||($latest>0&&$latest>$now-1200))return false;
    $seen=is_array($history[$key]??null)?$history[$key]:[];
    foreach($seen as $hash=>$at)if((int)$at<=$now-86400||!preg_match('/^[a-f0-9]{64}$/D',(string)$hash))unset($seen[$hash]);
    if(isset($seen[$fingerprint]))return false;
    $seen[$fingerprint]=$now;
    arsort($seen,SORT_NUMERIC);
    $history[$key]=array_slice($seen,0,16,true);
    $times[$key]=$now;
    arsort($times,SORT_NUMERIC);
    $times=array_slice($times,0,32,true);
    $history=array_intersect_key($history,$times);
    return true;
}
