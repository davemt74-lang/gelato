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
        $content=mb_strtolower(trim(preg_replace('/\\s+/u',' ',(string)($item['text']??''))??''),'UTF-8');
        if($content===''||isset($evidenceText[$content]))continue;
        $evidenceText[$content]=true;
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
    $last=(int)($_SESSION['fm11d_hint_times'][$key]??0);
    if($last>time()-1200)return null;
    // Do not repeatedly re-offer the same advice with the same two source
    // messages. This is ephemeral in-session Agent Brain deduplication only.
    $fingerprint=hash('sha256',$hint['type'].'|'.implode('|',$hint['sourceMessageIds']));
    if(($_SESSION['fm12d_hint_fingerprints'][$key]??'')===$fingerprint)return null;
    $_SESSION['fm11d_hint_times'][$key]=time();
    $_SESSION['fm12d_hint_fingerprints'][$key]=$fingerprint;
    if(count($_SESSION['fm12d_hint_fingerprints'])>32){
      $_SESSION['fm12d_hint_fingerprints']=array_intersect_key(
        $_SESSION['fm12d_hint_fingerprints'],$_SESSION['fm11d_hint_times']);
    }
    if(count($_SESSION['fm11d_hint_times'])>32){
      asort($_SESSION['fm11d_hint_times'],SORT_NUMERIC);
      $_SESSION['fm11d_hint_times']=array_slice($_SESSION['fm11d_hint_times'],-32,null,true);
    }
    return $hint;
}
