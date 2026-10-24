<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-workspace-core.php';
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';

$checks=0;
function b8d6_check(bool $ok,string $why):void{
    global $checks;
    if(!$ok)throw new RuntimeException('B8D6 FAIL: '.$why);
    $checks++;
}
$pdo=app_pdo();$slug='b8d6-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Training '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','x','Training','Student','Training Student']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status)
    VALUES (?,?,'active')")->execute([$org,$uid]);
$actor=['id'=>$uid,'organization_id'=>$org,'display_name'=>'Training Student',
    'permissions'=>['menu.view','employee.self','training.self_view','recipes.view','recipes.agent']];
b8d6_check(gaw_ready($pdo)&&menu_manager_ready($pdo),'training, conversation and menu schema installed');
$category=menu_manager_category_save($pdo,$org,null,['name'=>'Pizzas','description'=>'Pizza','sortOrder'=>1,'status'=>'active']);
menu_manager_save_item($pdo,$org,null,[
    'name'=>'Practice Pizza','categoryId'=>(int)$category['id'],
    'preparationNotes'=>"1. Stretch dough.\n2. Add cheese.\n3. Bake the pizza.",
    'sizes'=>[['label'=>'12-inch','sizeCode'=>'12','amount'=>12.00,'sortOrder'=>1]],
],$uid);
$room=(string)gaw_create_thread($pdo,$org,$uid,'text','Training via Agent')['public_id'];
$first=gaw_append($pdo,$org,$uid,$room,'user','Teach me to make Practice Pizza');
$ctx=['threadId'=>$room,'messageId'=>(string)$first['id'],
    'channel'=>'text','voiceEventId'=>'','verifiedSameUser'=>true];
$tools=fm8d6_training_catalogue($actor,$ctx);
b8d6_check(isset($tools['get_my_training_progress'],$tools['run_training_step']),'training and progress tools discoverable for employee');
$empty=$tools['get_my_training_progress']['execute']([],$actor);
b8d6_check(str_contains($empty['data']['answer'],'no saved'),'new user has no fabricated progress');
$start=$tools['run_training_step']['execute'](['action'=>'start','kind'=>'menu','subject'=>'Practice Pizza'],$actor);
b8d6_check(str_contains($start['data']['answer'],'step 1 of 3'),'named procedure started from real menu preparation');
$progress=$tools['get_my_training_progress']['execute']([],$actor);
b8d6_check(str_contains($progress['data']['answer'],'step 1 of 3'),'saved personal progress readable');
$thread=fm13c_training_thread($pdo,$org,$uid,false);
$prior=fm13c_read_state($pdo,$org,$uid,$thread);
b8d6_check(($prior['index']??-1)===0&&($prior['lastTurnId']??'')===$ctx['messageId'],
    'transactional state tied to canonical message');
$replay=$tools['run_training_step']['execute'](['action'=>'next'],$actor);
$afterReplay=fm13c_read_state($pdo,$org,$uid,$thread);
b8d6_check(($afterReplay['index']??-1)===0,'same saved chat message cannot advance twice');
$second=gaw_append($pdo,$org,$uid,$room,'user','Okay, move to the next instruction');
$ctx2=array_replace($ctx,['messageId'=>(string)$second['id']]);
$tools2=fm8d6_training_catalogue($actor,$ctx2);
$advance=$tools2['run_training_step']['execute'](['action'=>'next'],$actor);
b8d6_check(str_contains($advance['data']['answer'],'Add cheese')&&
    (fm13c_read_state($pdo,$org,$uid,$thread)['index']??-1)===1,'second canonical turn advances exactly one step');
$none=fm8d6_training_catalogue(array_replace($actor,['permissions'=>[]]),$ctx2);
b8d6_check(!isset($none['get_my_training_progress'],$none['run_training_step']),
    'revoked user discovers no training tools');
$bad=fm8d6_canonical_turn($pdo,$actor,array_replace($ctx2,['messageId'=>'msg-'.str_repeat('a',20)]));
b8d6_check($bad===null,'browser-forged turn id rejected');
$third=gaw_append($pdo,$org,$uid,$room,'user','Can we continue?');
$stale=fm8d6_canonical_turn($pdo,$actor,$ctx2);
b8d6_check($stale===null,'superseded message cannot advance after newer turn');
$ctx3=array_replace($ctx,['messageId'=>(string)$third['id']]);
$catalogue=fm8d3_catalogue($actor,$ctx3);
$transport=static fn($url,$headers,$payload):array=>[
    'output'=>[['type'=>'function_call','name'=>'run_training_step',
        'call_id'=>'call-1','arguments'=>'{"action":"repeat"}']]
];
$answer=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Repeat that step in training, please']],'',
    $transport,$catalogue,static fn():array=>$actor,static function(array $event):void{},$ctx3);
b8d6_check(str_contains($answer['answer'],'Add cheese')&&
    ($answer['data']['tools'][0]??'')==='run_training_step',
    'OpenAI independently selected a routine training tool, with idempotency');
$claude=static fn():array=>['content'=>[['type'=>'tool_use','name'=>'get_my_training_progress',
    'id'=>'t2','input'=>[]]]];
$read=fm8d3_turn($pdo,$actor,'anthropic','fake',
    [['role'=>'user','content'=>'What is my guided training progress?']],'',
    $claude,$catalogue,static fn():array=>$actor,static function(array $event):void{},$ctx3);
b8d6_check(str_contains($read['answer'],'step 2 of 3'),
    'Claude selected private progress read without exposing it to provider');
$denied=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Advance again']],'',$transport,$catalogue,
    static fn():array=>array_replace($actor,['permissions'=>[]]),
    static function(array $event):void{},$ctx3);
b8d6_check(($denied['data']['status']??'')==='denied',
    'freshly revoked skill permission stops execution');
$owner=array_replace($actor,['is_owner_role'=>1,'role_slug'=>'owner']);
$team=fm8d6_training_catalogue($owner,array_replace($ctx3,['channel'=>'text']));
b8d6_check(isset($team['get_team_training_insights']),'owner sees team intelligence in typed chat');
$summary=$team['get_team_training_insights']['execute']([],$owner);
b8d6_check(str_contains($summary['data']['answer'],'Training Student')&&
    str_contains($summary['data']['answer'],'not competency'),
    'real team guided-training summary is not a certification');
$otherOrg='Other '.$slug;
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute([$otherOrg]);
$foreign=array_replace($actor,['organization_id'=>(int)$pdo->lastInsertId()]);
$foreignRead=fm8d6_my_progress($pdo,$foreign);
b8d6_check(!str_contains($foreignRead['data']['answer'],'Practice Pizza'),
    'other tenant cannot access personal training source');
echo 'food-monster-b8d6-training-ok assertions='.$checks."\n";
