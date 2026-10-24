<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';

$checks=0;
function b8d7_assert(bool $yes,string $msg):void{
    global $checks;
    if(!$yes)throw new RuntimeException('B8D7 FAIL: '.$msg);
    $checks++;
}
$pdo=app_pdo();$slug='b8d7-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Vision Skills '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test','x','Vision','Tester','Vision Tester']);
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status)
    VALUES (?,?,'active')")->execute([$org,$uid]);
$actor=['id'=>$uid,'organization_id'=>$org,'display_name'=>'Vision Tester',
    'permissions'=>['glasses.view']];
$registered=fm8d7_vision_catalogue($actor);
$names=['get_vision_lab_overview','get_vision_device_health','get_vision_model_rollouts',
    'get_recent_vision_observations','get_recent_product_vision_checks','get_vision_fleet_analyses'];
b8d7_assert(count($registered)===6&&!array_diff($names,array_keys($registered)),
    'six recorded Vision Lab capability tools registered');
b8d7_assert(fm8d7_vision_catalogue(array_replace($actor,['permissions'=>[]]))===[],
    'unauthorized speaker cannot discover private Vision Lab skills');
b8d7_assert(glasses_vision_lab_ready($pdo),'Vision Lab schema upgraded');
b8d7_assert(glasses_vision_ops_ready($pdo),'Glasses Operations schema upgraded');
b8d7_assert(glasses_vision_models_ready($pdo),'Vision model registry schema upgraded');
b8d7_assert(glasses_vision_scene_ready($pdo),'Vision Scene schema upgraded');
b8d7_assert(glasses_vision_final_ready($pdo),'Final validation schema upgraded');
b8d7_assert(glasses_vision_fleet_health_ready($pdo),'Fleet analytics schema upgraded');
$toolResults=[];
foreach($names as $name){
    $v=$registered[$name];
    b8d7_assert($v['impact']==='read'&&$v['authorize']($actor,[])===true,
        $name.' is authorized as read only');
    b8d7_assert($v['authorize'](array_replace($actor,['permissions'=>[]]),[])===false,
        $name.' authorization rejects revoked view permission');
    $result=$v['execute'](['limit'=>3],$actor);
    b8d7_assert(($result['ok']??false)===true&&is_string($result['data']['answer']??null)
        &&$result['data']['answer']!=='',$name.' observes genuine tenant-scoped data');
    $toolResults[$name]=$result['data']['answer'];
}
b8d7_assert(str_contains($toolResults['get_vision_lab_overview'],'registered devices'),
    'Vision inventory uses installed database rather than inference');
b8d7_assert(str_contains($toolResults['get_recent_vision_observations'],'No saved Vision Scene'),
    'empty scene data does not falsely claim live camera perception');
b8d7_assert(str_contains($toolResults['get_recent_product_vision_checks'],'No recorded'),
    'absent final product evidence is not fabricated');
$providerPayload=[];
$openai=static function($url,$headers,$payload)use(&$providerPayload):array{
    $providerPayload=$payload;
    return ['output'=>[['type'=>'function_call','name'=>'get_vision_lab_overview','call_id'=>'c-1','arguments'=>'{}']]];
};
$reply=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Tell me what Vision Lab has observed and collected']],'',
    $openai,$registered,static fn():array=>$actor,static function(array $event):void{});
b8d7_assert(($reply['data']['tools'][0]??'')==='get_vision_lab_overview'&&
    str_contains($reply['answer'],'registered devices'),
    'OpenAI independently selects Vision Lab overview tool');
b8d7_assert(!str_contains(json_encode($providerPayload),'registered devices'),
    'private Vision Lab observation never returned to provider');
$claude=static fn():array=>['content'=>[['type'=>'tool_use','id'=>'t1',
    'name'=>'get_recent_vision_observations','input'=>['limit'=>3]]]];
$reply=fm8d3_turn($pdo,$actor,'anthropic','fake',
    [['role'=>'user','content'=>'What was the camera seeing earlier?']],'',
    $claude,$registered,static fn():array=>$actor,static function(array $event):void{});
b8d7_assert(str_contains($reply['answer'],'No saved Vision Scene'),
    'Anthropic selects bounded historical scene skill');
$denied=fm8d3_turn($pdo,$actor,'openai','fake',
    [['role'=>'user','content'=>'Show model status']],'',$openai,$registered,
    static fn():array=>array_replace($actor,['permissions'=>[]]),
    static function(array $event):void{});
b8d7_assert(($denied['data']['status']??'')==='denied',
    'fresh permission revocation blocks model-selected vision access');
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Another Vision '.$slug]);
$other=array_replace($actor,['organization_id'=>(int)$pdo->lastInsertId()]);
$foreign=$registered['get_recent_vision_observations']['execute']([],$other);
b8d7_assert(str_contains($foreign['data']['answer'],'No saved'),
    'scenes from another restaurant never appear across tenant boundary');
echo 'food-monster-b8d7-vision-ok assertions='.$checks."\n";
