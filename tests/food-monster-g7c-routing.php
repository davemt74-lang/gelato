<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-workspace-core.php';
$n=0;
function g7c_assert(bool $yes,string $reason):void{global $n;if(!$yes){fwrite(STDERR,"FAIL: $reason\n");exit(1);}++$n;}
$user=['role_slug'=>'manager','permissions'=>['menu.view','recipes.view']];
foreach(['Hello Food Monster','How are you today?','Tell me a story about outer space','What is your favorite color?','Explain how magnets work'] as $ask){
 $r=gaw_route($user,$ask);g7c_assert(array_key_exists('route',$r)&&$r['route']===null,'General chat routes to model: '.$ask);
}
foreach(['Show our menu items','What toppings are available?','Are any pizzas sold out?'] as $ask){
 $r=gaw_route($user,$ask);g7c_assert(($r['route']??'')==='api/agent-brain.php','Menu remains permission-gated: '.$ask);
}
$brain=file_get_contents(dirname(__DIR__).'/api/agent-brain.php');
g7c_assert(!str_contains($brain,'if(brain_can_menu($user)){ $result=brain_menu_answer'),'No broad menu fallback');
$llm=file_get_contents(dirname(__DIR__).'/includes/food-monster-llm.php');
g7c_assert(str_contains($llm,"['anthropic','openai']"),'Claude preferred for Auto model selection');
echo "FM-G7C Claude and menu intent: PASS ($n checks)\n";