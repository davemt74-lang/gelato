<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $user):bool {
 return in_array('*',(array)($user['permissions']??[]),true)
    ||in_array($permission,(array)($user['permissions']??[]),true);
}
require __DIR__.'/../includes/food-monster-g13b-knowledge.php';
$n=0;
function check13b(bool $pass,string $message):void{
 global $n;
 if(!$pass)throw new RuntimeException('FM-G13B FAIL: '.$message);
 $n++;
}
$staff=['organization_id'=>7,'permissions'=>['menu.view','voice.agent']];
$owner=['organization_id'=>7,'permissions'=>['menu.view','recipes.view','recipes.agent']];
$none=['organization_id'=>7,'permissions'=>[]];
check13b(fm13b_allowed($staff,'allergen',false,true),'staff may ask menu allergen facts');
check13b(fm13b_allowed($staff,'training',false,true),'staff may ask documented menu prep');
check13b(!fm13b_allowed($staff,'recipe',false,true),'voice staff cannot borrow private recipe access');
check13b(!fm13b_allowed($owner,'recipe',false,true),'unknown voice cannot borrow owner recipe access');
check13b(fm13b_allowed($owner,'recipe',true,true),'verified owner voice accesses governed recipe reads');
check13b(fm13b_allowed($owner,'recipe',false,false),'signed-in typed owner retains recipe access');
check13b(!fm13b_allowed($none,'allergen',false,true),'no menu permission blocks retrieval');
check13b(!fm13b_allowed($owner,'unlisted',true,false),'unknown intent denied');
$questions=['Does the pesto pizza contain milk?'=>'allergen',
 'Which allergens are in the Margherita pizza?'=>'allergen',
 'Show me the house dough recipe'=>'recipe',
 'What ingredients are in the pesto pizza?'=>'menu',
 'How do I prepare the pesto pizza?'=>'training',
 'How much is the pepperoni pizza?'=>'menu'];
foreach($questions as $q=>$expected)check13b(fm13b_intent($q)===$expected,'intent: '.$q);
foreach(['Change the recipe price','Delete the pesto pizza','Add milk to the dough','Refund the pasta'] as $q)
 check13b(fm13b_intent($q)===null,'mutation never enters evidence route: '.$q);
check13b(fm13b_subject('Does the pesto pizza contain dairy?')==='pesto pizza','safe subject stripping retains menu name');
check13b(fm13b_subject('How do I prepare the pesto pizza?')==='pesto pizza','training words not search terms');
check13b(fm13b_allergen_key('Does this contain milk?')==='milk','milk allergen selector');
check13b(fm13b_allergen_key('Does this contain gluten?')==='wheat','gluten mapped to wheat indicator not clearance');
$pepper=['id'=>4,'name'=>'Pesto Pizza','description'=>'Basil pesto pizza','ingredients'=>['Mozzarella','Pesto','Dough'],
 'preparationNotes'=>'Bake in the pizza oven until cheese melts',
 'allergens'=>['direct'=>[['id'=>'milk','name'=>'Milk']], 'verify'=>[['id'=>'tree-nuts','name'=>'Tree nuts']]]];
$other=['id'=>8,'name'=>'Pesto Pasta','ingredients'=>['Pasta']];
$m=fm13b_menu_answer([$pepper],'allergen','Does pesto pizza contain milk?');
check13b(str_contains($m['answer'],'Milk')&&str_contains($m['answer'],'not an allergy-safety clearance'),'direct indicator with safety warning');
check13b(count($m['sources'])===1&&str_contains($m['sources'][0],'#4'),'source identifies canonical menu item');
$v=fm13b_menu_answer([$pepper],'allergen','Does pesto pizza contain nuts?');
check13b(str_contains($v['answer'],'possible Tree nuts concern'),'heuristic indicator never confirmed allergen');
$u=fm13b_menu_answer([$pepper],'allergen','Does pesto pizza contain sesame?');
check13b(str_contains($u['answer'],'does not establish')&&!str_contains($u['answer'],'sesame-free'),'unknown ingredient cannot certify allergen absence');
$ing=fm13b_menu_answer([$pepper],'menu','What ingredients are in pesto pizza?');
check13b(str_contains($ing['answer'],'Mozzarella, Pesto, Dough'),'listed menu ingredients from record');
$prep=fm13b_menu_answer([$pepper],'training','How do I prepare pesto pizza?');
check13b(str_contains($prep['answer'],'Bake in the pizza oven'),'read prep notes without inventing methods');
$empty=$pepper;$empty['preparationNotes']='';$empty['ingredients']=[];
check13b(str_contains(fm13b_menu_answer([$empty],'training','How to prepare pesto pizza?')['answer'],'No verified preparation steps'),'missing prep notes marked missing');
check13b(str_contains(fm13b_menu_answer([$empty],'menu','What ingredients?')['answer'],'not recorded'),'empty ingredient list not fabricated');
check13b(count(fm13b_menu_answer([$pepper,$other],'menu','pesto')['sources'])===0,'ambiguous item does not pretend match');
check13b(count(fm13b_menu_answer([],'allergen','pesto')['sources'])===0,'no matches returns no sources');
$recipe=['public_id'=>'recipe-'.str_repeat('a',20),'name'=>'House Dough','status'=>'active',
 'ingredients_json'=>json_encode([['ingredient'=>'Flour'],['ingredient'=>'Water']]),
 'instructions_json'=>json_encode([['text'=>'Mix flour and water'],['text'=>'Knead dough']])];
$a=fm13b_recipe_answer([$recipe],'How do I prepare house dough?');
check13b(str_contains($a['answer'],'Mix flour and water')&&str_contains($a['answer'],'Knead dough'),'recipe steps grounded');
check13b($a['sources']===['Gelato active recipe '.$recipe['public_id']],'recipe source references canonical ID');
$inactive=$recipe;$inactive['status']='inactive';
check13b(fm13b_recipe_answer([$inactive],'house dough')['sources']===[],'inactive recipes excluded');
check13b(fm13b_recipe_answer([$recipe,$recipe],'house dough')['sources']===[],'ambiguous recipes not guessed');
$contents=file_get_contents(__DIR__.'/../api/food-monster-knowledge.php');
$router=file_get_contents(__DIR__.'/../api/agent-workspace.php');
$client=file_get_contents(__DIR__.'/../js/global-agent.js');
check13b(str_contains($contents,'gaw_messages($pdo,$org,$uid,$thread,1)')&&str_contains($contents,'app_verify_request_csrf'),
 'knowledge endpoint requires canonical scoped chat and CSRF');
check13b(str_contains($contents,'fm9d_person_collections($pdo,$user,$p)')&&str_contains($contents,'fm13a_engagement_plan'),
 'followup knowledge restricted to same authorized person and room');
check13b(str_contains($router,"'nodeMode'=>'read_only'")&&str_contains($router,"'route'=>'api/food-monster-knowledge.php'"),
 'routing explicitly offers read-only endpoint');
check13b(str_contains($client,"'api/food-monster-knowledge.php'"),'client submits canonical thread to evidence route');
echo 'FM-G13B grounded staff knowledge PASS ('.$n.' assertions)'."\n";
