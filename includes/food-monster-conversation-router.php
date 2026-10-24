<?php
declare(strict_types=1);

/**
 * B8D1: conversation-first intent planning.
 *
 * This layer decides whether a turn is general explanation, a factual
 * restaurant lookup, a governed action, or ordinary conversation. It never
 * authorizes tools or executes actions. All record and write permissions
 * remain enforced by the server-side specialist endpoints.
 */
function fmcr_plan(string $message): array
{
    $text=mb_strtolower(trim($message),'UTF-8');
    if($text===''||mb_strlen($text,'UTF-8')>4500)
        return ['intent'=>'conversation','requiresRecords'=>false];

    // Actual business actions must continue through the governed tool router.
    // "Why should I add honey?" is a question, NOT an instruction to add it.
    $command='/^(?:please\s+|can you\s+|could you\s+|would you\s+)?(?:schedule|clock\s+(?:me|us|.+?)\s+(?:in|out)|create|add|remove|delete|edit|update|publish|approve|assign|order|purchase|refund|void|save|submit|send|start|stop|cancel|book|reserve|set up|change)\b/u';
    if(preg_match($command,$text))
        return ['intent'=>'governed_action','requiresRecords'=>true];

    // Questions about *actual* business performance, decisions and activity
    // require records even when they begin with "why" or "explain".
    $recordExplanation='/\b(?:why|explain|how come)\b.{0,85}\b(?:our|my|this|that|current|today|yesterday|last|recorded|saved|actual)\s+(?:sales|revenue|inventory|stock|orders?|schedule|shifts?|attendance|tasks?|employees?|staffing|customers?|prices?|costs?|booking|reservations?|purchase orders?|payroll|menu changes?|recipe changes?)\b/u';
    if(preg_match($recordExplanation,$text))
        return ['intent'=>'system_lookup','requiresRecords'=>true];

    // "Why does honey improve dough?" is cooking science, even though
    // "dough" and "pizza" are also names of restaurant tool domains.
    $reasoning='/\b(?:why\b|explain\s+(?:how|why|the|what)|how\s+does\b|how\s+do\s+(?:you|we)\s+(?:know|decide)\b|what\s+happens\s+(?:if|when)\b|what\s+are\s+the\s+(?:benefits?|advantages?|disadvantages?|pros?\s+and\s+cons?)\b|what(?:.s| is)\s+the\s+difference\s+between\b|is\s+it\s+better\s+to\b|should\s+i\s+(?:use|put|add|avoid|include|reduce|increase)\b)/u';
    if(preg_match($reasoning,$text))
        return ['intent'=>'general_reasoning','requiresRecords'=>false];

    // An explicit request for Gelato's recorded facts still uses real sources.
    $lookup='/\b(?:show|list|find|look up|check|retrieve|read|what(?:.s| is| are) (?:on|in|our|my|the)|ingredients?\s+(?:on|in|for|of)|how much (?:is|does|do)|who\s+(?:is|are)\s+(?:working|scheduled)|when\s+(?:am|is|are)\s+.+scheduled)\b/u';
    $business='/\b(?:menu|pizza|pasta|dish|recipes?|formula|shifts?|schedule|inventory|stock|staff|employees?|tasks?|training|vision lab|orders?|bookings?|customers?|price|ingredients?)\b/u';
    if(preg_match($lookup,$text)&&preg_match($business,$text))
        return ['intent'=>'system_lookup','requiresRecords'=>true];

    return ['intent'=>'conversation','requiresRecords'=>false];
}
