<?php
declare(strict_types=1);

/**
 * FM-B8D14: a model-first turn may return prose without choosing a tool.
 * Explicit requests for Gelato's recorded facts must still use the existing
 * permission-scoped local broker. Never forward retrieved records to the LLM.
 */
require_once __DIR__.'/food-monster-conversation-router.php';
require_once __DIR__.'/food-monster-unified-knowledge.php';

function fm8d14_needs_grounded_read(string $question): bool
{
    $question=trim($question);
    if($question===''||mb_strlen($question,'UTF-8')>4500)return false;
    $intent=fmcr_plan($question)['intent']??'conversation';
    // Explanations and actions belong to their normal conversation/governance
    // routes. A model abstention cannot silently turn a write into a read.
    if($intent==='general_reasoning'||$intent==='governed_action')return false;
    if(fmuk_capability_question($question)||fmuk_menu_overview_question($question))
        return true;
    if(!fmuk_topics($question))return false;
    if($intent==='system_lookup')return true;
    // A business topic word alone ("inventory", "staff", "pizza") must not
    // turn generic questions into a private database lookup. Require clear
    // evidence of an actual restaurant record or explicit retrieval intent.
    return preg_match('/\b(?:gelato|restaurant|our|my|current|today|tonight|tomorrow|yesterday|this week|last week|recorded|saved|active|available|availability|actual|show|list|find|look up|check|retrieve|read|prices?|costs?|how much|ingredients?|toppings?|specials?|(?:we|you)\s+(?:serve|sell|offer))\b/iu',$question)===1;
}

/**
 * Return an authoritative local read only after the configured model supplied
 * no tool call. This uses the SAME server-scoped broker as canonical Agent
 * Chat: no client-supplied user, tenant, permissions, or SQL.
 */
function fm8d14_no_tool_read(PDO $pdo,array $principal,array $history,array $context=[]): ?array
{
    $last=end($history);
    if(!is_array($last)||($last['role']??'')!=='user')return null;
    $question=trim((string)($last['content']??''));
    if(!fm8d14_needs_grounded_read($question))return null;
    $voice=($context['channel']??'text')==='voice';
    $sameUser=($context['verifiedSameUser']??false)===true;
    // Only the pre-existing verified owner voice authorization can read
    // private records; unidentified participant speech remains conversational.
    if($voice&&!$sameUser)return null;
    $result=fmuk_answer($pdo,$principal,$question,$voice,$sameUser);
    $result['ok']=true;
    $result['data']=array_merge((array)($result['data']??[]),[
        'toolCalls'=>0,
        'groundedReadFallback'=>true,
    ]);
    return $result;
}
