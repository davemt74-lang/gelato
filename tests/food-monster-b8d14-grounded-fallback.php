<?php
declare(strict_types=1);
/** B8D14: no keyword intent classifier; one read-only model retry instead. */
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

$read=['description'=>'Read live published restaurant menu',
    'parameters'=>['type'=>'object','properties'=>['category'=>['type'=>'string']],
        'additionalProperties'=>false],
    'impact'=>'read'];
$write=['description'=>'Governed write requiring human approval',
    'parameters'=>['type'=>'object','properties'=>new stdClass(),
        'additionalProperties'=>false],
    'impact'=>'routine'];
$catalogue=['list_available_menu_items'=>$read,'update_employee_record'=>$write];
$history=[['role'=>'user','content'=>'Show me the pizzas available tonight']];
$payload=fmllm_payload('openai',$history);
$captured=[];
$selected=static function($endpoint,$headers,$request)use(&$captured):array{
    $captured[]=$request;
    return ['output'=>[['type'=>'function_call','call_id'=>'retry-1',
        'name'=>'list_available_menu_items','arguments'=>'{"category":"pizza"}']]];
};
$calls=fm8d14_cognitive_read_retry('openai','fake',$payload,$catalogue,$selected);
b8d14_check(count($calls)===1&&$calls[0]['name']==='list_available_menu_items',
    'OpenAI cognitive retry selects a real menu tool');
b8d14_check(($captured[0]['tool_choice']??'')==='required',
    'second model request requires an explicit cognitive choice');
$offered=array_column($captured[0]['tools'],'name');
b8d14_check(in_array('list_available_menu_items',$offered,true)
    &&in_array('answer_without_restaurant_tools',$offered,true),
    'read and ordinary-conversation choices are offered');
b8d14_check(!in_array('update_employee_record',$offered,true),
    'retry never offers governed or routine write tools');
b8d14_check(!str_contains(json_encode($captured,JSON_THROW_ON_ERROR),'Roasted Garlic'),
    'no private database observation is supplied to model retry');

$anthropicPayload=fmllm_payload('anthropic',$history);
$claude=static function($endpoint,$headers,$request):array{
    b8d14_check(($request['tool_choice']['type']??'')==='any',
        'Anthropic cognitive retry requires a tool decision');
    return ['content'=>[['type'=>'tool_use','id'=>'claude-1',
        'name'=>'list_available_menu_items','input'=>['category'=>'pizza']]]];
};
$calls=fm8d14_cognitive_read_retry('anthropic','fake',$anthropicPayload,$catalogue,$claude);
b8d14_check(count($calls)===1&&$calls[0]['arguments']['category']==='pizza',
    'Claude cognitive retry chooses an authorized read');

$ordinary=static fn():array=>['output'=>[['type'=>'function_call','call_id'=>'general',
    'name'=>'answer_without_restaurant_tools','arguments'=>'{}']]];
b8d14_check(fm8d14_cognitive_read_retry('openai','fake',$payload,$catalogue,$ordinary)===[],
    'LLM can choose ordinary conversation without a database tool');
$unsafe=static fn():array=>['output'=>[['type'=>'function_call','call_id'=>'bad',
    'name'=>'update_employee_record','arguments'=>'{}']]];
b8d14_check(fm8d14_cognitive_read_retry('openai','fake',$payload,$catalogue,$unsafe)===[],
    'forged write call from retry is discarded');
$mixed=static fn():array=>['output'=>[
    ['type'=>'function_call','call_id'=>'one','name'=>'list_available_menu_items','arguments'=>'{}'],
    ['type'=>'function_call','call_id'=>'two','name'=>'answer_without_restaurant_tools','arguments'=>'{}'],
]];
b8d14_check(fm8d14_cognitive_read_retry('openai','fake',$payload,$catalogue,$mixed)===[],
    'ambiguous mixed read and conversation decision is discarded');
$invalidArgs=static fn():array=>['output'=>[['type'=>'function_call','call_id'=>'bad-args',
    'name'=>'list_available_menu_items','arguments'=>'bad-json']]];
b8d14_check(fm8d14_cognitive_read_retry('openai','fake',$payload,$catalogue,$invalidArgs)===[],
    'malformed model-selected arguments are not executed');
$unexpectedCall=static function():array{throw new RuntimeException('No provider call expected');};
b8d14_check(fm8d14_cognitive_read_retry('openai','fake',$payload,
    ['update_employee_record'=>$write],$unexpectedCall)===[],
    'catalogues without read tools do not consume retry tokens');

// On a general question the initial conversational answer survives when the
// second model pass explicitly chooses no database tool.
$db=new PDO('sqlite::memory:');
$actor=['id'=>17,'organization_id'=>31,'display_name'=>'Dave','permissions'=>['menu.view']];
$attempt=0;
$generalTransport=static function()use(&$attempt):array{
    $attempt++;
    if($attempt===1)return ['output'=>[['type'=>'message','content'=>[[
        'type'=>'output_text','text'=>'Honey helps browning by increasing surface sugars.'
    ]]]]];
    return ['output'=>[['type'=>'function_call','call_id'=>'ordinary',
        'name'=>'answer_without_restaurant_tools','arguments'=>'{}']]];
};
$reply=fm8d3_turn($db,$actor,'openai','fake',
    [['role'=>'user','content'=>'Why does honey help browning?']],'',
    $generalTransport,['list_available_menu_items'=>$read],
    static fn():array=>$actor,static function(array $event):void{});
b8d14_check($attempt===2&&str_contains($reply['answer'],'surface sugars'),
    'one retry keeps natural conversation and preserves initial LLM answer');
b8d14_check(($reply['data']['toolCalls']??-1)===0,
    'ordinary conversation does not falsely claim a database tool ran');
echo "Food Monster B8D14 cognitive retry: PASS ($n assertions)\n";
