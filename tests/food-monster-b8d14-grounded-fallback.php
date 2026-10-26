<?php
declare(strict_types=1);
/** FM-B8D14: authorized restaurant facts survive model tool abstention. */
if(!function_exists('mb_substr')){function mb_substr(string $s,int $o,?int $l=null,?string $e=null):string{return substr($s,$o,$l);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $e=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $e=null):string{return strtolower($s);}}
function app_has_permission(string $name,array $actor):bool{
    return in_array('*',$actor['permissions']??[],true)
        ||in_array($name,$actor['permissions']??[],true);
}
require dirname(__DIR__).'/includes/food-monster-llm.php';
require dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';
$n=0;
function b8d14_check(bool $pass,string $description):void{
    global $n;
    if(!$pass){fwrite(STDERR,'B8D14 FAIL: '.$description."\n");exit(1);}
    ++$n;
}
foreach([
    'What is on the menu?',
    'Show me the menu',
    'What are your current menu prices?',
    'How much does Testarosa Pizza cost?',
    'What ingredients are listed for Testarosa Pizza?',
    'Do you have access to the menu?',
    'What are our employee schedules?',
    'Show my training assignments',
    'List our inventory and sales',
    'Which kitchen tickets are active today?',
    'Read our policy documents',
] as $question)b8d14_check(fm8d14_needs_grounded_read($question),'grounded read: '.$question);
foreach([
    'Hello there!',
    'What is a pizza?',
    'What is inventory?',
    'What is a schedule?',
    'What is an employee?',
    'Why does honey improve pizza dough?',
    'Explain why pizza crust browns',
    'Is pizza better than pasta?',
    'Can you schedule Sam for tomorrow?',
    'Create an order',
    'Approve the refund',
    'Delete our menu item',
    'What are the pros and cons of more dough hydration?',
    'Tell me a joke about pizza',
] as $question)b8d14_check(!fm8d14_needs_grounded_read($question),'leave conversation/write to owner: '.$question);
b8d14_check(fmuk_menu_subject("What's on the Testarossa Pizza?")==='testarossa pizza',
    'apostrophe question yields clean named menu subject');
b8d14_check(fmuk_menu_subject('What toppings are on the Testarossa Pizza?')==='testarossa pizza',
    'toppings question yields clean named menu subject');
b8d14_check(fmuk_menu_subject('How much is the Testarossa Pizza?')==='testarossa pizza',
    'price question yields clean named menu subject');
foreach([
    "What's on the Testarossa Pizza?",
    'What toppings are on the Testarossa Pizza?',
    'How much is the Testarossa Pizza?',
] as $question)b8d14_check(fmuk_named_menu_item_question($question),
    'named menu read: '.$question);
foreach([
    'What is a pizza?',
    'Tell me a joke about pizza',
    'Why does pizza dough rise?',
    'Delete the Testarossa Pizza',
    'Does the Testarossa Pizza contain nuts?',
] as $question)b8d14_check(!fmuk_named_menu_item_question($question),
    'general, governed or allergen question is not a named menu read: '.$question);

$db=new PDO('sqlite::memory:');
$principal=['id'=>17,'organization_id'=>31,'display_name'=>'Dave','permissions'=>['menu.view','schedule.view']];
$context=['channel'=>'text','verifiedSameUser'=>false,'threadId'=>'agent-'.str_repeat('a',20),
    'messageId'=>'msg-'.str_repeat('b',20)];
$refresh=static fn():array=>$principal;
$audit=static function(array $event):void{};
$noToolOpenAi=static fn():array=>['output'=>[['type'=>'message','content'=>[[
    'type'=>'output_text','text'=>"I can't pull the live menu or verified prices in this reply."
]]]]];
$question='Do you have access to the menu?';
$history=[['role'=>'user','content'=>$question]];
$result=fm8d3_turn($db,$principal,'openai','mock',$history,'',$noToolOpenAi,[],$refresh,$audit,$context);
b8d14_check(($result['data']['groundedReadFallback']??false)===true,'no-tool OpenAI invokes local read');
b8d14_check($result['skill']==='food_monster.unified_capabilities','canonical broker used');
b8d14_check(str_contains($result['answer'],'active menu and prices'),'capabilities speak for actual authorization');
b8d14_check(!str_contains($result['answer'],"can't pull"),'generic refusal discarded');
b8d14_check(($result['data']['toolCalls']??-1)===0,'no model-selected tools falsely counted');

$result=fm8d3_turn($db,$principal,'openai','mock',
    [['role'=>'user','content'=>'What is on the menu?']],
    '',$noToolOpenAi,[],$refresh,$audit,$context);
b8d14_check(($result['data']['groundedReadFallback']??false)===true,
    'menu overview always attempts verified broker');
b8d14_check(!str_contains($result['answer'],"can't pull"),
    'menu overview never repeats ungrounded model refusal');
b8d14_check(str_contains($result['answer'],'temporarily unavailable')
    || str_contains($result['answer'],'matching'), 'missing fixture reports missing source truthfully');

$noToolClaude=static fn():array=>['content'=>[[
    'type'=>'text','text'=>"I cannot access your live schedule."
]]];
$result=fm8d3_turn($db,$principal,'anthropic','mock',$history,'',$noToolClaude,[],$refresh,$audit,$context);
b8d14_check(($result['data']['groundedReadFallback']??false)===true,
    'no-tool Claude also invokes broker');

$unprivileged=array_replace($principal,['permissions'=>[]]);
$refreshDenied=static fn():array=>$unprivileged;
$result=fm8d3_turn($db,$unprivileged,'openai','mock',
    [['role'=>'user','content'=>'What is on the menu?']],
    '',$noToolOpenAi,[],$refreshDenied,$audit,$context);
b8d14_check(str_contains($result['answer'],'cannot access'),'unauthorized menu denied');
b8d14_check(empty($result['sources']),'unauthorized user obtains no source IDs');

$unverifiedVoice=array_replace($context,['channel'=>'voice','verifiedSameUser'=>false]);
$result=fm8d3_turn($db,$principal,'openai','mock',
    [['role'=>'user','content'=>'What is on the menu?']],
    '',$noToolOpenAi,[],$refresh,$audit,$unverifiedVoice);
b8d14_check(($result['data']['groundedReadFallback']??false)!==true,
    'unverified shared-room speech cannot borrow logged-in tools');
$verifiedVoice=array_replace($context,['channel'=>'voice','verifiedSameUser'=>true]);
$result=fm8d3_turn($db,$principal,'openai','mock',$history,'',
    $noToolOpenAi,[],$refresh,$audit,$verifiedVoice);
b8d14_check(($result['data']['groundedReadFallback']??false)===true,
    'verified same-user voice retains authorized broker access');
$result=fm8d3_turn($db,$principal,'openai','mock',
    [['role'=>'user','content'=>'Why does honey improve pizza dough?']],
    '',$noToolOpenAi,[],$refresh,$audit,$context);
b8d14_check(($result['data']['groundedReadFallback']??false)!==true,
    'general reasoning remains model-first conversation');
$result=fm8d3_turn($db,$principal,'openai','mock',
    [['role'=>'user','content'=>'Approve the refund']],
    '',$noToolOpenAi,[],$refresh,$audit,$context);
b8d14_check(($result['data']['groundedReadFallback']??false)!==true,
    'model abstention cannot silently route writes');
echo "Food Monster B8D14 grounded fallback: PASS ($n assertions)\n";
