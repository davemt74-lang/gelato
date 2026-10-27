<?php
declare(strict_types=1);
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $enc=null):string{return strtolower($s);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $enc=null):int{return strlen($s);}}
if(!function_exists('mb_substr')){function mb_substr(string $s,int $start,?int $len=null,?string $enc=null):string{return substr($s,$start,$len);}}
function app_has_permission(string $permission,array $user):bool
{
    $p=(array)($user['permissions']??[]);return in_array('*',$p,true)||in_array($permission,$p,true);
}
require dirname(__DIR__).'/includes/food-monster-conversation-router.php';
require dirname(__DIR__).'/includes/food-monster-unified-knowledge.php';
$count=0;
function b8d1_assert(bool $yes,string $reason):void
{
    global $count;
    if(!$yes){fwrite(STDERR,'B8D1 FAIL: '.$reason."\n");exit(1);}
    ++$count;
}
foreach([
    'Tell me why I should put honey in my pizza dough recipe',
    'Why should I put honey in my pizza dough?',
    'Explain how yeast works in pizza dough',
    'How does honey affect browning?',
    'What happens if I reduce the fresh yeast?',
    'What are the benefits of using honey in pizza dough?',
    'Should I add oil to my dough?',
    'Why is dough fermentation important?',
    'What is the difference between active dry and fresh yeast?',
    'Is it better to ferment dough cold?',
    'Explain why cheese releases water when heated',
    'Why should we let pizza dough rest?',
] as $ask){
    $p=fmcr_plan($ask);
    b8d1_assert($p['intent']==='general_reasoning','Science explanation must bypass data broker: '.$ask);
    b8d1_assert($p['requiresRecords']===false,'Science has no data prerequisite: '.$ask);
}
foreach([
    'Can you tell me the ingredients on a Testarosa Pizza',
    'What is on the Margherita pizza?',
    'Show our menu prices',
    'What is our house dough recipe?',
    'What are my shifts tomorrow?',
    'Explain why our sales dropped yesterday',
    'Why was my shift changed?',
    'Find the Testarosa Pizza menu item',
] as $ask){
    $p=fmcr_plan($ask);
    b8d1_assert($p['intent']==='system_lookup','Real restaurant facts require lookup: '.$ask);
    b8d1_assert($p['requiresRecords']===true,'Records required for factual answer: '.$ask);
}
foreach([
    'Schedule David for Friday','Clock me in','Create a new recipe',
    'Approve the purchase order','Delete the old menu item',
    'Add a new employee','Publish the menu'
] as $ask)
    b8d1_assert(fmcr_plan($ask)['intent']==='governed_action',
        'Imperative action must remain governed: '.$ask);
foreach(['Hey Food Monster','How are you?','What were we talking about?','Can you help me?'] as $ask)
    b8d1_assert(fmcr_plan($ask)['intent']==='conversation',
        'Ordinary chat uses conversation: '.$ask);
b8d1_assert(fmuk_topics('Can you tell me the ingredients on a Testarosa Pizza')===['menu'],
    'Named-pizza ingredients use staff-facing menu only');
b8d1_assert(fmuk_topics('Testarosa Pizza toppings')===['menu'],'Toppings query uses menu');
b8d1_assert(in_array('recipes',fmuk_topics('What is our pizza dough recipe?'),true),
    'Real production recipe remains protected');
b8d1_assert(fmuk_topics('Create a recipe')===[],'Read broker never authorizes writes');
$evidence=['context'=>"Authorized: menu\nmenu: Testarosa Pizza | prices: 12 inch 17.00 USD | ingredients: Tomato, Fresh Mozzarella, Basil\n\nrecipes: NOT AUTHORIZED for this speaker/account; do not disclose records."];
$answer=fmuk_reply_from_evidence($evidence,'Can you tell me the ingredients on a Testarosa Pizza');
b8d1_assert(str_contains($answer,'Testarosa Pizza')&&str_contains($answer,'Fresh Mozzarella'),
    'Verified item ingredients are answered');
b8d1_assert(!str_contains($answer,'NOT AUTHORIZED')&&!str_contains($answer,'Authorized:'),
    'Authorization diagnostics never appear in user answer');
b8d1_assert(!str_contains($answer,'17.00'),'Ingredient request omits pricing');
$price=fmuk_reply_from_evidence($evidence,'How much does the Testarosa Pizza cost?');
b8d1_assert(str_contains($price,'17.00 USD'),'Real price can be answered');
$missing=fmuk_reply_from_evidence([
 'context'=>"Authorized: menu\nmenu: No matching recorded data was available. Do not assume zero or absence of activity."
], 'Ingredients on Testarosa Pizza');
b8d1_assert(str_contains($missing,'could not find a matching item'),'Missing item invites clarification');
b8d1_assert(!str_contains($missing,'Do not assume'),'No internal sentinel in answer');
$denied=fmuk_reply_from_evidence([
 'context'=>"Authorized: none\nrecipes: NOT AUTHORIZED for this speaker/account; do not disclose records."
], 'Our dough recipe');
b8d1_assert(str_contains($denied,'cannot access'),'Actual permission denial retained');
b8d1_assert(!str_contains($denied,'NOT AUTHORIZED'),'Raw permission marker hidden');
$multi=fmuk_reply_from_evidence([
 'context'=>"Authorized: menu\nmenu: Testarosa Pizza | prices: 17 USD | ingredients: Tomato\nMargherita Pizza | prices: 16 USD | ingredients: Basil"
], 'Show our pizzas');
b8d1_assert(str_contains($multi,'Margherita Pizza'),'Multiple menu rows preserved');
$unavailable=fmuk_reply_from_evidence([
 'context'=>"Authorized: menu\nmenu: Source temporarily unavailable. Do not invent data."
], 'Show our menu');
b8d1_assert(str_contains($unavailable,'temporarily unavailable'),'Data outage not presented as empty');
b8d1_assert(!str_contains($unavailable,'Do not invent'),'No internal error instructions');
$root=dirname(__DIR__);
$route=(string)file_get_contents($root.'/api/agent-workspace.php');
$planPos=strpos($route,'$conversationPlan=fmcr_plan($message)');
$brokerPos=strpos($route,'if(fmuk_prefers_broker($message,$user)');
b8d1_assert($planPos!==false&&$brokerPos!==false&&$planPos<$brokerPos,
    'Conversation decision precedes keyword broker');
b8d1_assert(str_contains($route,"'domain'=>'conversation_reasoning'"),
    'General reasoning uses model route');
$llm=(string)file_get_contents($root.'/includes/food-monster-llm.php');
b8d1_assert(str_contains($llm,'Questions about general knowledge or cooking science'),
    'Provider receives explanatory conversation instructions');
$browser=(string)file_get_contents($root.'/js/global-agent.js');
b8d1_assert(str_contains($browser,"fetch('api/food-monster-llm.php'"),
    'Model request works even without optional selector UI');
b8d1_assert(!str_contains($browser,'answer: capabilityMessage(),'),
    'General chat cannot silently turn into feature list');
$api=(string)file_get_contents($root.'/api/food-monster-unified-knowledge.php');
b8d1_assert(str_contains($api,'app_require_auth()')&&str_contains($api,'app_verify_request_csrf'),
    'Authenticated read remains protected');
b8d1_assert(str_contains($api,'gavp_was_approved')&&!str_contains($api,'avc_verified_speaker'),
    'Private voice evidence requires the operator\'s approved canonical turn');
echo 'Food Monster B8D1 Conversation Foundation PASS ('.$count." assertions)\n";
