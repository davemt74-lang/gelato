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
    'distribution'=>['publicMenu'=>true,'onlineOrder'=>true],
    'ingredients'=>[
        ['name'=>'Pizza Dough','canRemove'=>false],
        ['name'=>'Roasted Garlic','canRemove'=>true],
        ['name'=>'Ricotta','canRemove'=>true],
        ['name'=>'Fresh Mozzarella','canRemove'=>true],
    ],
],$uid);
b8d5_assert((int)$item['id']>0,'Testarosa fixture saved to real menu database');
menu_manager_set_status($pdo,$org,(int)$item['id'],'publish',$uid);
b8d5_assert(menu_training_search_items($pdo,$org,'Testarosa Pizza')!==[],
    'acceptance fixture is published and available to the active-menu broker');
$draft=menu_manager_save_item($pdo,$org,null,[
    'name'=>'Secret Draft Pizza','categoryId'=>(int)$category['id'],
    'sizes'=>[['label'=>'12-inch','sizeCode'=>'12','amount'=>99.99,'sortOrder'=>1]],
    'distribution'=>['publicMenu'=>true],
],$uid);
b8d5_assert((int)$draft['id']>0,'private draft fixture saved');
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
b8d5_assert(isset($reg['get_menu_item'],$reg['list_available_menu_items'],
    $reg['search_menu_ingredients'],
    $reg['get_recipe_details'],$reg['find_recipes_using_ingredient']),'all approved menu and recipe tools registered');
$none=fm8d3_catalogue(array_replace($principal,['permissions'=>[]]));
b8d5_assert(!isset($none['get_menu_item'],$none['get_recipe_details'],
    $none['list_available_menu_items']),
    'users without menu/recipe permissions cannot discover tools');
$menuOnly=fm8d3_catalogue(array_replace($principal,['permissions'=>['menu.view']]));
b8d5_assert(isset($menuOnly['get_menu_item'])&&!isset($menuOnly['get_recipe_details']),
    'menu viewer is not silently granted private recipe access');
$publicOnly=['list_available_menu_items'=>$reg['list_available_menu_items']];
b8d5_assert(!isset($publicOnly['get_menu_item'],$publicOnly['get_recipe_details']),
    'unknown room speakers receive no private menu ingredients or recipes');
$listMenu=$reg['list_available_menu_items']['execute'];
$menuList=$listMenu(['category'=>'pizzas'],$principal);
b8d5_assert(str_contains($menuList['data']['answer'],'Testarosa Pizza')
    &&str_contains($menuList['data']['answer'],'18.00'),
    'published menu-list tool retrieves live pizza and price without names hardcoded');
b8d5_assert(!str_contains($menuList['data']['answer'],'Secret Draft')
    &&!str_contains($menuList['data']['answer'],'99.99'),
    'menu-list tool excludes draft items');
$brokerPizzas=fmuk_answer($pdo,$principal,'Show me the menu');
b8d5_assert(str_contains($brokerPizzas['answer'],'Testarosa Pizza')
    &&!str_contains($brokerPizzas['answer'],'Secret Draft'),
    'offline menu broker uses only published records');
$draftDetail=fm8d5_menu_find($pdo,$org,'Secret Draft Pizza');
b8d5_assert(isset($draftDetail['error']),
    'named-item lookup cannot disclose draft details');
$getMenu=$reg['get_menu_item']['execute'];
$details=$getMenu(['item_name'=>'Testarosa Pizza'],$principal);
b8d5_assert(str_contains($details['data']['answer'],'Roasted Garlic')&&
    str_contains($details['data']['answer'],'Fresh Mozzarella'),'real named item ingredient list');
b8d5_assert(str_contains($details['data']['answer'],'supplier labels'),
    'allergen advisory included without invented clearance');

$providerCalls=0;
$providerRefusal=static function()use(&$providerCalls):array{
    $providerCalls++;
    return ['output'=>[['type'=>'message','content'=>[[
        'type'=>'output_text','text'=>"I can't verify the Testarossa Pizza without the live menu."
    ]]]]];
};
$activeMenuContext=['channel'=>'text','verifiedSameUser'=>false];
foreach([
    "What's on the Testarossa Pizza?",
    'What toppings are on the Testarossa Pizza?',
    'How much is the Testarossa Pizza?',
] as $question){
    $firstAttempt=true;
    $priceQuestion=str_contains($question,'How much');
    $cognitiveNamed=static function($endpoint,$headers,$payload)use(
        &$providerCalls,&$firstAttempt,$priceQuestion
    ):array{
        $providerCalls++;
        if($firstAttempt){
            $firstAttempt=false;
            return ['output'=>[['type'=>'message','content'=>[[
                'type'=>'output_text','text'=>"I can't verify this pizza without a menu read."
            ]]]]];
        }
        $args=json_encode(['item_name'=>'Testarosa Pizza','include_prices'=>$priceQuestion],JSON_THROW_ON_ERROR);
        return ['output'=>[['type'=>'function_call','call_id'=>'named-menu',
            'name'=>'get_menu_item','arguments'=>$args]]];
    };
    $grounded=fm8d3_turn($pdo,$principal,'openai','fake',
        [['role'=>'user','content'=>$question]],'',$cognitiveNamed,null,
        static fn():array=>$principal,static function(array $event):void{},$activeMenuContext);
    b8d5_assert(($grounded['data']['cognitiveRetrySelectedRead']??false)===true
        &&($grounded['data']['tools'][0]??'')==='get_menu_item',
        'second cognitive attempt selects real named-item DB tool: '.$question);
    if(!str_contains($grounded['answer'],'Testarosa Pizza')){
        fwrite(STDERR,'B8D5 named menu diagnosis: '.json_encode([
            'question'=>$question,'subject'=>fmuk_menu_subject($question),
            'answer'=>$grounded['answer'],'sources'=>$grounded['sources']??[],
            'directMatches'=>array_column(fmuk_menu_items($pdo,$org,$question),'name'),
        ],JSON_UNESCAPED_UNICODE)."\n");
    }
    b8d5_assert(str_contains($grounded['answer'],'Testarosa Pizza'),
        'recorded name is returned even for a one-letter spelling difference');
    if($priceQuestion){
        b8d5_assert(str_contains($grounded['answer'],'18.00'),
            'recorded menu price replaces model refusal: '.$question);
    }else{
        b8d5_assert(str_contains($grounded['answer'],'Roasted Garlic')
            &&str_contains($grounded['answer'],'Ricotta'),
            'recorded toppings answer replaces model refusal: '.$question);
    }
    b8d5_assert(!empty($grounded['sources']),
        'grounded menu item includes verifiable source: '.$question);
}
b8d5_assert($providerCalls===6,'three named questions received precisely one cognitive retry each');
$verifiedNamedProvider=static fn():array=>['output'=>[['type'=>'function_call',
    'call_id'=>'owner-voice','name'=>'get_menu_item',
    'arguments'=>'{"item_name":"Testarosa Pizza"}']]];
$verifiedMenuVoice=fm8d3_turn($pdo,$principal,'openai','fake',
    [['role'=>'user','content'=>"What's on the Testarossa Pizza?"]],'',
    $verifiedNamedProvider,null,static fn():array=>$principal,
    static function(array $event):void{},['channel'=>'voice','verifiedSameUser'=>true]);
b8d5_assert(str_contains($verifiedMenuVoice['answer'],'Roasted Garlic'),
    'verified owner voice can read authorized menu item');
$unverifiedMenuVoice=fm8d3_turn($pdo,$principal,'openai','fake',
    [['role'=>'user','content'=>"What's on the Testarossa Pizza?"]],'',
    $providerRefusal,$publicOnly,static fn():array=>$principal,
    static function(array $event):void{},['channel'=>'voice','verifiedSameUser'=>false]);
b8d5_assert(!str_contains($unverifiedMenuVoice['answer'],'Roasted Garlic'),
    'unverified participant speech cannot read owner menu data');
$listingProvider=static function()use(&$providerCalls):array{
    $providerCalls++;
    return ['output'=>[['type'=>'function_call','call_id'=>'public-menu',
        'name'=>'list_available_menu_items','arguments'=>'{"category":"pizzas"}']]];
};
$unverifiedPublicVoice=fm8d3_turn($pdo,$principal,'openai','fake',
    [['role'=>'user','content'=>'Show me the pizzas']],'',
    $listingProvider,$publicOnly,static fn():array=>$principal,
    static function(array $event):void{},['channel'=>'voice','verifiedSameUser'=>false]);
b8d5_assert(str_contains($unverifiedPublicVoice['answer'],'Testarosa Pizza')
    &&($unverifiedPublicVoice['data']['tools'][0]??'')==='list_available_menu_items',
    'unknown voice can ask the same natural-language public-menu question');
b8d5_assert(!str_contains($unverifiedPublicVoice['answer'],'Secret Draft')
    &&!str_contains($unverifiedPublicVoice['answer'],'Roasted Garlic'),
    'unknown voice has published menu only, not private item details');
$cognitiveMenu=static function($endpoint,$headers,$payload)use(&$providerCalls):array{
    $providerCalls++;
    if(($payload['tool_choice']??'')==='required')
        return ['output'=>[['type'=>'function_call','call_id'=>'retry-public-menu',
            'name'=>'list_available_menu_items','arguments'=>'{"category":"pizzas"}']]];
    return ['output'=>[['type'=>'message','content'=>[[
        'type'=>'output_text','text'=>"I can't access the menu in this reply."
    ]]]]];
};
$abstainingVoice=fm8d3_turn($pdo,$principal,'openai','fake',
    [['role'=>'user','content'=>'Show me the pizzas']],'',
    $cognitiveMenu,$publicOnly,static fn():array=>$principal,
    static function(array $event):void{},['channel'=>'voice','verifiedSameUser'=>false]);
b8d5_assert(str_contains($abstainingVoice['answer'],'Testarosa Pizza')
    &&!str_contains($abstainingVoice['answer'],'Roasted Garlic')
    &&($abstainingVoice['data']['cognitiveRetrySelectedRead']??false),
    'LLM retry turns voice abstention into authorized public-menu read');
$modelFirstMenu=fm8d3_turn($pdo,$principal,'openai','fake',
    [['role'=>'user','content'=>'Pizzas available today?']],'',
    $listingProvider,$reg,static fn():array=>$principal,
    static function(array $event):void{},['channel'=>'text','verifiedSameUser'=>false]);
b8d5_assert(str_contains($modelFirstMenu['answer'],'Testarosa Pizza')
    &&($modelFirstMenu['data']['tools'][0]??'')==='list_available_menu_items',
    'typed natural-language menu question uses the LLM-selected read tool');
$abstainingText=fm8d3_turn($pdo,$principal,'openai','fake',
    [['role'=>'user','content'=>'Show me the pizzas']],'',
    $cognitiveMenu,$reg,static fn():array=>$principal,
    static function(array $event):void{},['channel'=>'text','verifiedSameUser'=>false]);
b8d5_assert(str_contains($abstainingText['answer'],'Testarosa Pizza')
    &&($abstainingText['data']['cognitiveRetrySelectedRead']??false),
    'LLM retry turns typed abstention into the same published menu as voice');
$maliciousVoiceModel=static function($endpoint,$headers,$payload):array{
    if(($payload['tool_choice']??'')==='required')
        return ['output'=>[['type'=>'function_call','call_id'=>'malicious',
            'name'=>'get_recipe_details',
            'arguments'=>'{"recipe_name":"Stonefellows Dough","include_method":true}']]];
    return ['output'=>[['type'=>'message','content'=>[[
        'type'=>'output_text','text'=>'I cannot use a private recipe here.'
    ]]]]];
};
$blockedVoice=fm8d3_turn($pdo,$principal,'openai','fake',
    [['role'=>'user','content'=>'Give me the private dough recipe']],'',
    $maliciousVoiceModel,$publicOnly,static fn():array=>$principal,
    static function(array $event):void{},['channel'=>'voice','verifiedSameUser'=>false]);
b8d5_assert(!str_contains($blockedVoice['answer'],'1000 g')
    &&($blockedVoice['data']['toolCalls']??-1)===0,
    'unverified voice cannot exploit retry to obtain a private production recipe');

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
$brokerHistory=fmllm_history([
    ['role'=>'user','content'=>'Which pizzas are listed?'],
    ['role'=>'agent','skill'=>'food_monster.unified_knowledge',
        'content'=>'PRIVATE MENU: Chef Only Pizza costs $900.00',
        'sources'=>['Gelato published menu']],
    ['role'=>'user','content'=>'And the desserts?'],
],12);
b8d5_assert(!str_contains(json_encode($brokerHistory,JSON_THROW_ON_ERROR),'Chef Only Pizza')
    &&!str_contains(json_encode($brokerHistory,JSON_THROW_ON_ERROR),'$900.00'),
    'unified knowledge results never leak into the next external model request');
$delegationSource=file_get_contents(dirname(__DIR__).'/js/global-agent.js');
b8d5_assert(is_string($delegationSource)
    &&str_contains($delegationSource,"routeQuery==='?threadId='+encodeURIComponent(S.thread)"),
    'offline knowledge-agent handoff permits only the canonical same-thread query');
$voiceApiSource=file_get_contents(dirname(__DIR__).'/api/food-monster-llm.php');
b8d5_assert(is_string($voiceApiSource)
    &&str_contains($voiceApiSource,"if((\$last['channel']??'')==='voice')\$personMemory='';"),
    'unknown room speakers cannot inherit the signed-in owner memory');
$ordinary=fmllm_history([
    ['role'=>'user','content'=>'Why honey?'],['role'=>'agent',
        'skill'=>'agent.llm.conversation','content'=>'Honey helps browning.'],
],12);
b8d5_assert(str_contains(json_encode($ordinary),'browning'),
    'ordinary conversation stays intact');
echo 'food-monster-b8d5-menu-recipes-ok assertions='.$checks."\n";
