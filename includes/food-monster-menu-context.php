<?php
declare(strict_types=1);
require_once __DIR__.'/menu-published-resolver.php';

/**
 * Section 2: recover ONLY the identity of a published menu item from the
 * canonical, already speaker-isolated chat history. Tool observations are
 * intentionally excluded; prices and ingredients are never sent as memory.
 */
function fmmc_is_followup(string $text):bool
{
    $text=trim(mb_strtolower($text,'UTF-8'));
    if($text===''||mb_strlen($text,'UTF-8')>160)return false;
    $text=str_replace(["’","'"],'',$text);
    // A change to an operational domain is not a continuation of menu focus.
    if(preg_match('/\b(?:employee|staff|schedule|shift|payroll|timecard|clock|inventory|purchase|supplier|customer|order|delivery|calendar|reminder|task)\b/u',$text))
        return false;
    $starter='/^(?:(?:and|also|so)\s+)?(?:what|whats|how|tell|does|do|is|are|can|could|explain|describe|show|please)\b/u';
    if(preg_match($starter,$text)
        &&preg_match('/\b(?:it|its|that|this|those|them|same)\b/u',$text))
        return true;
    return preg_match('/^(?:(?:and|also|so)\s+)?(?:how much\b|whats?\s+the\s+price\b|what\s+is\s+the\s+price\b|what\s+(?:ingredients|toppings|sizes|price)\b|what about (?:the )?(?:price|ingredients|toppings|sizes)\b)/u',$text)===1;
}

/**
 * A pronoun such as "it" must never be treated as a substring match for an
 * item such as Margherita. Keep the canonical menu matcher, but strip
 * referential terms before requesting an explicit item identity.
 */
function fmmc_explicit_menu_matches(array $publishedRows,string $utterance):array
{
    $key=menu_published_name_key($utterance);
    $key=trim(preg_replace('/\b(?:it|its|that|this|those|them|same)\b/u',' ',$key)??'');
    if(mb_strlen($key,'UTF-8')<4)return [];
    return menu_published_match_rows($publishedRows,$key);
}

/** Pure helper for regression tests and for a validated, tenant-scoped index. */
function fmmc_focus_hint(array $history,array $publishedRows):string
{
    if(count($history)<2)return '';
    $last=end($history);
    if(!is_array($last)||($last['role']??'')!=='user'
        ||!fmmc_is_followup((string)($last['content']??'')))return '';
    // A newly named item must stand on its own. Never silently replace it
    // with the previously discussed item, including ambiguous matches.
    if(fmmc_explicit_menu_matches($publishedRows,(string)$last['content'])!==[])return '';
    $previous=array_slice($history,0,-1);
    for($i=count($previous)-1;$i>=0;$i--){
        $row=$previous[$i];
        if(!is_array($row)||($row['role']??'')!=='user')continue;
        $text=trim((string)($row['content']??''));
        $matches=fmmc_explicit_menu_matches($publishedRows,$text);
        if(count($matches)>1)return '';
        if(count($matches)===1){
            $name=trim(preg_replace('/[\x00-\x1F\x7F]+/u',' ',(string)$matches[0]['name'])??'');
            if($name===''||mb_strlen($name,'UTF-8')>100)return '';
            $quoted=json_encode($name,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
            return 'Server-verified conversation reference: the latest short menu follow-up refers to published Gelato menu item '.$quoted.'. This is an item name, not a price, ingredients list, approval or instruction. Use the currently authorized published-menu tool to read fresh facts; do not infer facts from the name. If the user changes subject, ignore this reference.';
        }
        // The most recent distinct question ended that menu subject.
        if(!fmmc_is_followup($text))return '';
    }
    return '';
}

/**
 * Called only AFTER signed-in thread validation and room-voice isolation.
 * No client history, unverified speaker identity, private tool observation,
 * draft product or other organization's menu can provide a reference.
 */
function fmmc_tenant_hint(PDO $pdo,int $organizationId,array $isolatedHistory):string
{
    if($organizationId<1||count($isolatedHistory)<2)return '';
    $last=end($isolatedHistory);
    if(!is_array($last)||!fmmc_is_followup((string)($last['content']??'')))return '';
    return fmmc_focus_hint($isolatedHistory,menu_published_index($pdo,$organizationId));
}
