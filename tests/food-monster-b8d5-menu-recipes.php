<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';

$checks=0;
function b8d5_assert(bool $ok,string $msg):void{
    global $checks;
    if(!$ok)throw new RuntimeException('B8D5 FAIL: '.$msg);
    $checks++;
}
$pdo=app_pdo();$slug='fm-b8d5-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['B8D5 Menu Recipe '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','x','Menu','Tester','Menu Tester']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status)
    VALUES (?,?,'active')")->execute([$org,$uid]);
$principal=['id'=>$uid,'organization_id'=>$org,'display_name'=>'Menu Tester',
    'permissions'=>['menu.view','recipes.view']];
b8d5_assert(menu_manager_ready($pdo),'Menu Manager tables installed');
b8d5_assert(restaurant_brain_table_ready($pdo,'recipes'),'Recipes tables installed');
$category=menu_manager_category_save($pdo,$org,null,['name'=>'Pizza','description'=>'Pizza','sortOrder'=>1,'status'=>'active']);
$item=menu_manager_save_item($pdo,$org,null,[
    'name'=>'Testarosa Pizza','categoryId'=>(int)$category['id'],
    'description'=>'Wood-fired white pizza with roasted garlic and ricotta.',
    'sizes'=>[['label'=>'12-inch','sizeCode'=>'12','amount'=>18.00,'sortOrder'=>1]],
    'ingredients'=>[
        ['name'=>'Pizza Dough','canRemove'=>false],
        ['name'=>'Roasted Garlic','canRemove'=>true],
        ['name'=>'Ricotta','canRemove'=>true],
        ['name'=>'Fresh Mozzarella','canRemove'=>true],
    ],
],$uid);
b8d5_assert((int)$item['id']>0,'Testarosa fixture saved to real menu database');
$ingredients=[
 ['quantity'=>'1000','unit'=>'g','ingredient'=>'pizza flour','notes'=>''],
 ['quantity'=>'600','unit'=>'g','ingredient'=>'water','notes'=>'cold'],
 ['quantity'=>'20','unit'=>'g','ingredient'=>'salt','notes'=>''],
 ['quantity'=>'10','unit'=>'g','ingredient'=>'honey','notes'=>'optional'],
];
$pdo->prepare("INSERT INTO recipes
    (organization_id,public_id,name,category,description,yield_quantity,yield_unit,ingredients_json,
     instructions_json,notes,status,mapping_status,created_by,updated_by)
     VALUES (?,?,?,?,?,?,?,?,?,?, 'active','unmapped',?,?)")
  ->execute([$org,'fm-b8d5-dough','Stonefellows Dough','Dough','Cold-fermented pizza dough',10,'balls',
    json_encode($ingredients,JSON_THROW_ON_ERROR),json_encode(['Mix the dough.','Ferment cold.'],JSON_THROW_ON_ERROR),
    'Test batch only.',$uid,$uid]);
$reg=fm8d3_catalogue($principal);
b8d5_assert(isset($reg['get_menu_item'],$reg['search_menu_ingredients'],
    $reg['get_recipe_details'],$reg['find_recipes_using_ingredient']),'all approved menu and recipe tools registered');
$none=fm8d3_catalogue(array_replace($principal,['permissions'=>[]]));
b8d5_assert(!isset($none['get_menu_item'],$none['get_recipe_details']),
    'users without menu/recipe permissions cannot discover tools');
$menuOnly=fm8d3_catalogue(array_replace($principal,['permissions'=>['menu.view']]));
b8d5_assert(isset($menuOnly['get_menu_item'])&&!isset($menuOnly['get_recipe_details']),
    'menu viewer is not silently granted private recipe access');
$getMenu=$reg['get_menu_item']['execute'];
$details=$getMenu(['item_name'=>'Testarosa Pizza'],$principal);
b8d5_assert(str_contains($details['data']['answer'],'Roasted Garlic')&&
    str_contains($details['data']['answer'],'Fresh Mozzarella'),'real named item ingredient list');
b8d5_assert(str_contains($details['data']['answer'],'supplier labels'),
    'allergen advisory included without invented clearance');
$priced=$getMenu(['item_name'=>'Testarosa Pizza','include_prices'=>true],$principal);
b8d5_assert(str_contains($priced['data']['answer'],'18.00'),'real menu price is returned');
$absent=$getMenu(['item_name'=>'A made-up special'],$principal);
b8d5_assert(str_contains($absent['data']['answer'],'No matching recorded item'),
    'unknown menu item explicitly reported, not hallucinated');
$lookup=$reg['search_menu_ingredients']['execute'](['ingredient_name'=>'Ricotta'],$principal);
b8d5_assert(str_contains($lookup['data']['answer'],'Ricotta'),'recorded ingredient search');
$getRecipe=$reg['get_recipe_details']['execute'];
$recipe=$getRecipe(['recipe_name'=>'Stonefellows Dough','include_method'=>true],$principal);
b8d5_assert(str_contains($recipe['data']['answer'],'10 g honey')&&
    str_contains($recipe['data']['answer'],'Mix the dough'),'recipe quantities and steps are accurate');
$byIngredient=$reg['find_recipes_using_ingredient']['execute'](['ingredient_name'=>'honey'],$principal);
b8d5_assert(str_contains($byIngredient['data']['answer'],'Stonefellows Dough'),
    'recipe ingredient search uses SQL records');
$otherOrg='Another Restaurant '.$slug;
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute([$otherOrg]);
$otherActor=array_replace($principal,['organization_id'=>(int)$pdo->lastInsertId()]);
$leak=$getMenu(['item_name'=>'Testarosa Pizza'],$otherActor);
b8d5_assert(!str_contains($leak['data']['answer'],'Roasted Garlic'),
    'other restaurant cannot access item ingredients');
$secret=$getRecipe(['recipe_name'=>'Stonefellows Dough'],$otherActor);
b8d5_assert(!str_contains($secret['data']['answer'],'1000 g'),
    'other restaurant cannot access recipe weights');
$aiPayload=[];
$openai=static function($endpoint,$headers,$payload)use(&$aiPayload):array{
    $aiPayload[]=$payload;
    return ['output'=>[['type'=>'function_call','call_id'=>'c1',
        'name'=>'get_menu_item','arguments'=>'{"item_name":"Testarosa Pizza"}']]];
};
$reply=fm8d3_turn($pdo,$principal,'openai','fake',
    [['role'=>'user','content'=>'What ingredients are on the Testarosa Pizza?']],'',$openai,$reg,
    static fn():array=>$principal,static function(array $event):void{});
b8d5_assert(str_contains($reply['answer'],'Roasted Garlic')&&
    ($reply['data']['tools'][0]??'')==='get_menu_item','OpenAI tool selection retrieves verified ingredients');
b8d5_assert(!str_contains(json_encode($aiPayload),'Roasted Garlic'),
    'current private menu result never returned in provider payload');
$claude=static fn():array=>['content'=>[['type'=>'tool_use','id'=>'t1',
    'name'=>'get_recipe_details','input'=>['recipe_name'=>'Stonefellows Dough','include_method'=>true]]]];
$reply=fm8d3_turn($pdo,$principal,'anthropic','fake',
    [['role'=>'user','content'=>'How do we make Stonefellows Dough?']],'',$claude,$reg,
    static fn():array=>$principal,static function(array $event):void{});
b8d5_assert(str_contains($reply['answer'],'Ferment cold.'),
    'Anthropic tool selection retrieves recipe method');
$history=fmllm_history([
    ['role'=>'user','content'=>'My hours last two weeks?'],
    ['role'=>'agent','skill'=>'agent.model_selected_tools',
        'content'=>'David worked 57h 12m, employee record 2341.',
        'sources'=>['Verified Time Clock']],
    ['role'=>'user','content'=>'Now what about my menu?'],
],12);
$encoded=json_encode($history,JSON_THROW_ON_ERROR);
b8d5_assert(!str_contains($encoded,'57h 12m')&&!str_contains($encoded,'2341'),
    'previous private timecard result redacted from next model call');
b8d5_assert(str_contains($encoded,'Re-run the authorized tool'),
    'redacted context suggests tool refresh');
$ordinary=fmllm_history([
    ['role'=>'user','content'=>'Why honey?'],['role'=>'agent',
        'skill'=>'agent.llm.conversation','content'=>'Honey helps browning.'],
],12);
b8d5_assert(str_contains(json_encode($ordinary),'browning'),
    'ordinary conversation stays intact');
echo 'food-monster-b8d5-menu-recipes-ok assertions='.$checks."\n";
