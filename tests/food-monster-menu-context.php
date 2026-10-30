<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-menu-context.php';

$checks=0;
function fmmc_check(bool $ok,string $description):void
{
    global $checks;
    if(!$ok)throw new RuntimeException('Menu context FAIL: '.$description);
    $checks++;
}
$published=[
    ['id'=>11,'name'=>'Testarossa','section'=>'Wood Fired Pizza'],
    ['id'=>12,'name'=>'Margherita Pizza','section'=>'Wood Fired Pizza'],
    ['id'=>13,'name'=>'Pepperoni Pizza','section'=>'Wood Fired Pizza'],
    ['id'=>14,'name'=>'Pepperoni Calzone','section'=>'Calzones'],
];
$subject=[
    ['role'=>'user','content'=>'What is on the Testarossa Pizza?'],
    ['role'=>'assistant','content'=>'[A previous private Gelato tool answer was shown locally.]'],
    ['role'=>'user','content'=>'How much is it?'],
];
$hint=fmmc_focus_hint($subject,$published);
fmmc_check(str_contains($hint,'Testarossa'),'follow-up retains exact published subject');
fmmc_check(str_contains($hint,'read fresh facts'),'tool re-read required instead of guessed price');
fmmc_check(!str_contains($hint,'$')&&!str_contains($hint,'Ricotta'),'no private tool facts in hint');
fmmc_check(fmmc_focus_hint(array_slice($subject,-1),$published)==='','single voice question cannot inherit room history');
fmmc_check(fmmc_focus_hint([
    ['role'=>'assistant','content'=>'Testarossa is $18 and contains private cheese'],
    ['role'=>'user','content'=>'How much is it?'],
],$published)==='','assistant tool output never serves as subject evidence');
fmmc_check(fmmc_focus_hint([
    ['role'=>'user','content'=>'Tell me about Secret Draft Pizza'],
    ['role'=>'assistant','content'=>'private draft'],
    ['role'=>'user','content'=>'What toppings does it have?'],
],$published)==='','unpublished menu item cannot become a subject');
fmmc_check(fmmc_focus_hint([
    ['role'=>'user','content'=>'What is on the Testarossa Pizza?'],
    ['role'=>'user','content'=>'What is my employee schedule?'],
    ['role'=>'user','content'=>'How much is it?'],
],$published)==='','intervening operational topic clears menu focus');
fmmc_check(fmmc_focus_hint([
    ['role'=>'user','content'=>'Tell me about Pepperoni'],
    ['role'=>'user','content'=>'What is the price?'],
],$published)==='','ambiguous published names never guessed');
fmmc_check(fmmc_focus_hint([
    ['role'=>'user','content'=>'Tell me about Testarossa'],
    ['role'=>'user','content'=>'What toppings does that pizza have?'],
],$published)!=='','demonstrative follow-up resolves published item');
fmmc_check(fmmc_focus_hint([
    ['role'=>'user','content'=>'Tell me about Testarossa'],
    ['role'=>'user','content'=>'What is the price of Margherita Pizza?'],
],$published)==='','new named item never overwritten by old reference');
fmmc_check(fmmc_is_followup('How much is it?'),'short price pronoun detected');
fmmc_check(fmmc_is_followup('What is the price?'),'short elliptical price detected');
fmmc_check(fmmc_is_followup('What ingredients does it have?'),'ingredient pronoun detected');
fmmc_check(!fmmc_is_followup('What is my schedule?'),'unrelated schedule not treated as menu follow-up');
$room=[
    ['role'=>'user','content'=>'Tell me about Testarossa'],
    ['role'=>'assistant','content'=>'private result'],
    ['role'=>'user','content'=>'How much is it?'],
];
$last=['channel'=>'voice','content'=>'How much is it?'];
$unverified=fm12e_isolate_voice_history($room,[],$last,false);
fmmc_check(fmmc_focus_hint($unverified,$published)==='','unverified room voice cannot inherit another speaker');
$person=[
    ['role'=>'user','content'=>'Tell me about Margherita Pizza'],
    ['role'=>'assistant','content'=>'private result'],
    ['role'=>'user','content'=>'How much is it?'],
];
$verified=fm12e_isolate_voice_history($room,$person,$last,true);
fmmc_check(str_contains(fmmc_focus_hint($verified,$published),'Margherita')
    &&!str_contains(fmmc_focus_hint($verified,$published),'Testarossa'),
    'verified speaker only inherits own isolated menu item');
$otherPerson=$person;
$otherPerson[2]['content']='What else is on the menu?';
fmmc_check(fmmc_focus_hint(fm12e_isolate_voice_history($room,$otherPerson,$last,true),$published)==='',
    'person history mismatch fails closed');
echo 'Gelato menu follow-up context PASS ('.$checks.' assertions)'.PHP_EOL;
