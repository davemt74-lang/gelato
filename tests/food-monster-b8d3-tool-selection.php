<?php
declare(strict_types=1);
if(!function_exists('mb_substr')){function mb_substr(string $s,int $offset,?int $length=null,?string $enc=null):string{return substr($s,$offset,$length);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $enc=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $enc=null):string{return strtolower($s);}}
function app_has_permission(string $name,array $actor):bool{
    return in_array('*',$actor['permissions']??[],true)||in_array($name,$actor['permissions']??[],true);
}
require dirname(__DIR__).'/includes/food-monster-llm.php';
require dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';
$n=0;
function b8d3_check(bool $yes,string $reason):void{
 global $n;if(!$yes){fwrite(STDERR,"B8D3 FAIL: $reason\n");exit(1);}++$n;
}
$tz=new DateTimeZone('America/Phoenix');
$now=new DateTimeImmutable('2026-10-24 16:30:00',$tz);
$period=fm8d3_hours_period(['period'=>'last_14_days'],$now);
b8d3_check($period['startDate']==='2026-10-11'&&$period['endDate']==='2026-10-24','last two weeks means rolling 14 dates');
b8d3_check($period['days']===14,'inclusive 14 days');
$custom=fm8d3_hours_period(['period'=>'custom','start_date'=>'2026-10-01','end_date'=>'2026-10-14'],$now);
b8d3_check($custom['days']===14,'custom inclusive date period');
b8d3_check(fm8d3_hours_period(['period'=>'custom','start_date'=>'2026-10-01'],$now)['missing']===['end_date'],'asks for missing custom end');
try{fm8d3_hours_period(['period'=>'custom','start_date'=>'2026-10-99','end_date'=>'2026-10-14'],$now);b8d3_check(false,'invalid date');}
catch(InvalidArgumentException){b8d3_check(true,'invalid date rejected');}
try{fm8d3_hours_period(['period'=>'custom','start_date'=>'2026-01-01','end_date'=>'2026-10-01'],$now);b8d3_check(false,'long period');}
catch(InvalidArgumentException){b8d3_check(true,'excessive date range rejected');}
$rows=[
 ['id'=>1,'clocked_in_at'=>'2026-10-22 09:00:00','clocked_out_at'=>'2026-10-22 17:00:00'],
 ['id'=>2,'clocked_in_at'=>'2026-10-23 22:00:00','clocked_out_at'=>'2026-10-24 02:00:00'],
 ['id'=>3,'clocked_in_at'=>'2026-10-24 14:00:00','clocked_out_at'=>null],
 ['id'=>4,'clocked_in_at'=>'2026-10-01 08:00:00','clocked_out_at'=>'2026-10-01 10:00:00'],
];
$breaks=[
 ['time_clock_entry_id'=>1,'started_at'=>'2026-10-22 12:00:00','ended_at'=>'2026-10-22 12:30:00'],
 ['time_clock_entry_id'=>2,'started_at'=>'2026-10-23 23:45:00','ended_at'=>'2026-10-24 00:00:00'],
 ['time_clock_entry_id'=>3,'started_at'=>'2026-10-24 15:00:00','ended_at'=>'2026-10-24 15:15:00'],
];
$total=fm8d3_hours_calculate($rows,$breaks,$period,$now);
b8d3_check($total['completedEntries']===2&&$total['openEntries']===1,'completed and active entries separate');
b8d3_check($total['completedSeconds']===40500,'breaks and overnight shifts subtracted correctly');
b8d3_check($total['openSeconds']===8100,'in progress time measured separately');
b8d3_check(fm8d3_hours_format($total['completedSeconds'])==='11h 15m','formatted actual clock time');
$answer=fm8d3_hours_answer('David',$period,$total);
b8d3_check(str_contains($answer,'David')&&str_contains($answer,'11h 15m'),'personal answer with recorded total');
b8d3_check(str_contains($answer,'open')&&str_contains($answer,'not included'),'active time not reported as completed');
$missing=fm8d3_hours_answer('David',$period,fm8d3_hours_calculate([],[],$period,$now));
b8d3_check(str_contains($missing,'not proof you worked zero'),'no rows is not proof zero');
$principal=['id'=>7,'organization_id'=>21,'display_name'=>'David Evans','first_name'=>'David','permissions'=>['timeclock.self']];
$registry=fm8d3_catalogue($principal);
b8d3_check(isset($registry['get_my_hours'])&&isset($registry['get_current_user']),'authorized model sees both skills');
b8d3_check(!isset(fm8d3_catalogue(array_replace($principal,['permissions'=>[]]))['get_my_hours']),'unprivileged model cannot discover timecards');
b8d3_check(!in_array('user_id',$registry['get_my_hours']['allowedArguments'],true),'no model-controlled employee selector');
b8d3_check(count(fm8d3_provider_tools('openai',$registry))===4,'OpenAI receives registered schema including scoped record reads');
b8d3_check(count(fm8d3_provider_tools('anthropic',$registry))===4,'Claude receives registered schema including scoped record reads');
b8d3_check(fm8d3_provider_tools('anthropic',$registry)[1]['name']==='get_my_hours','Anthropic tool registered');
$manager=['id'=>7,'organization_id'=>21,'display_name'=>'David Evans',
    'role_slug'=>'manager',
    'permissions'=>['schedule.view','schedule.manage','schedule.agent']];
$managerTools=fm8d3_catalogue($manager);
b8d3_check(isset($managerTools['get_my_schedule'])
    &&isset($managerTools['get_employee_schedule'])
    &&isset($managerTools['get_day_roster'])
    &&isset($managerTools['check_staff_availability'])
    &&isset($managerTools['propose_staff_shift']),
    'manager model receives existing schedule, roster and confirmed shift tools');
$unprivileged=fm8d3_catalogue(array_replace($manager,['permissions'=>[]]));
b8d3_check(!isset($unprivileged['get_employee_schedule'])
    &&!isset($unprivileged['get_day_roster'])
    &&!isset($unprivileged['propose_staff_shift']),
    'unprivileged voice or text must not discover protected roster and shift tools');
b8d3_check(isset($managerTools['list_my_gelato_capabilities']),
    'LLM receives runtime capability introspection tool');
$capabilities=($managerTools['list_my_gelato_capabilities']['execute'])([],$manager);
b8d3_check(str_contains($capabilities['data']['answer'],'get_day_roster')
    &&str_contains($capabilities['data']['answer'],'Scheduling'),
    'capability answer reflects permission-scoped executable tools and existing agents');
$fakeDb=new PDO('sqlite::memory:');
$executed=0;$audit=[];$payloads=[];
$mockCatalogue=[
 'get_my_hours'=>[
   'description'=>'Read authenticated hours','parameters'=>['type'=>'object','properties'=>['period'=>['type'=>'string']]],
   'allowedArguments'=>['period'],'impact'=>'read',
   'authorize'=>static fn($u,$a):bool=>in_array('timeclock.self',$u['permissions']??[],true),
   'validate'=>static fn($a,$u):array=>[],
   'execute'=>static function($a,$u)use(&$executed):array{
     $executed++;
     return ['ok'=>true,'data'=>['answer'=>'David worked 11h 15m of completed clock time.','source'=>'Verified Time Clock']];
   },
 ],
 'get_current_user'=>[
   'description'=>'Read signed in person','parameters'=>['type'=>'object','properties'=>new stdClass()],
   'allowedArguments'=>[],'impact'=>'read',
   'authorize'=>static fn($u,$a):bool=>true,
   'validate'=>static fn($a,$u):array=>[],
   'execute'=>static fn($a,$u):array=>['ok'=>true,'data'=>['answer'=>'Signed in as David Evans.','source'=>'Gelato account']],
 ],
];
$refresh=static fn():array=>$principal;
$auditCallback=static function(array $event)use(&$audit):void{$audit[]=$event;};
$openAi=static function($endpoint,$headers,$payload)use(&$payloads):array{
 $payloads[]=$payload;
 return ['output'=>[['type'=>'function_call','call_id'=>'call_123',
    'name'=>'get_my_hours','arguments'=>'{"period":"last_14_days"}']]];
};
$r=fm8d3_turn($fakeDb,$principal,'openai','dummy',$history=[['role'=>'user','content'=>'My hours for the last two weeks?']], '',$openAi,$mockCatalogue,$refresh,$auditCallback);
b8d3_check($r['skill']==='agent.model_selected_tools'&&$r['answer']==='David worked 11h 15m of completed clock time.','OpenAI tool request executes and answers');
b8d3_check($executed===1&&count($audit)===1,'one tool call one audit');
b8d3_check(($payloads[0]['tools'][0]['name']??'')==='get_my_hours','model received allowed tool schema');
b8d3_check(!str_contains(json_encode($payloads),'11h 15m'),'personal totals never sent to external model');
b8d3_check(!str_contains(json_encode($payloads),'dummy'),'provider key not in payload');
$claude=static fn()=>['content'=>[['type'=>'tool_use','id'=>'toolu_01','name'=>'get_my_hours',
 'input'=>['period'=>'last_14_days']]]];
$r=fm8d3_turn($fakeDb,$principal,'anthropic','dummy',[['role'=>'user','content'=>'How many hours did I work?']], '',$claude,$mockCatalogue,$refresh,$auditCallback);
b8d3_check($r['skill']==='agent.model_selected_tools'&&$executed===2,'Anthropic tool-use integration');
$bad=static fn()=>['output'=>[['type'=>'function_call','call_id'=>'call_bad',
 'name'=>'get_my_hours','arguments'=>'{"period":"last_14_days","user_id":44}']]];
$r=fm8d3_turn($fakeDb,$principal,'openai','dummy',$history,'',$bad,$mockCatalogue,$refresh,$auditCallback);
b8d3_check($r['data']['status']==='denied'&&$executed===2,'model cannot impersonate another employee');
$inject=static fn()=>['output'=>[['type'=>'function_call','call_id'=>'call_bad',
 'name'=>'run_sql','arguments'=>'{"query":"SELECT * FROM users"}']]];
$r=fm8d3_turn($fakeDb,$principal,'openai','dummy',$history,'',$inject,$mockCatalogue,$refresh,$auditCallback);
b8d3_check($r['data']['status']==='denied'&&$executed===2,'arbitrary SQL tool denied');
$invalid=static fn()=>['output'=>[['type'=>'function_call','call_id'=>'call_bad','name'=>'get_my_hours','arguments'=>'not-json']]];
$r=fm8d3_turn($fakeDb,$principal,'openai','dummy',$history,'',$invalid,$mockCatalogue,$refresh,$auditCallback);
b8d3_check($r['data']['status']==='denied','invalid provider function arguments denied');
$plain=static fn()=>['output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>'Honey can aid browning.']]]]];
$r=fm8d3_turn($fakeDb,$principal,'openai','dummy',[['role'=>'user','content'=>'Why honey?']],'',$plain,$mockCatalogue,$refresh,$auditCallback);
b8d3_check(str_contains($r['answer'],'browning')&&$r['data']['toolCalls']===0,'normal conversation still works');
$revoked=static fn():array=>['id'=>7,'organization_id'=>21,'permissions'=>[]];
$r=fm8d3_turn($fakeDb,$principal,'openai','dummy',$history,'',$openAi,$mockCatalogue,$revoked,$auditCallback);
b8d3_check($r['data']['status']==='denied'&&$executed===2,'fresh permission revocation blocks tool');
$cross=static fn():array=>['id'=>7,'organization_id'=>30,'permissions'=>['timeclock.self']];
$r=fm8d3_turn($fakeDb,$principal,'openai','dummy',$history,'',$openAi,$mockCatalogue,$cross,$auditCallback);
b8d3_check($r['data']['status']==='denied'&&$executed===2,'cross-organization identity blocked');
$both=static fn()=>['content'=>[
 ['type'=>'tool_use','id'=>'a','name'=>'get_current_user','input'=>[]],
 ['type'=>'tool_use','id'=>'b','name'=>'get_my_hours','input'=>['period'=>'last_14_days']],
]];
$r=fm8d3_turn($fakeDb,$principal,'anthropic','dummy',$history,'',$both,$mockCatalogue,$refresh,$auditCallback);
b8d3_check($r['data']['toolCalls']===2&&str_contains($r['answer'],'Signed in as David Evans'),'two sequential selected skills work');
$limit=static fn()=>['output'=>array_fill(0,5,['type'=>'function_call','call_id'=>'id','name'=>'get_my_hours','arguments'=>'{}'])];
$r=fm8d3_turn($fakeDb,$principal,'openai','dummy',$history,'',$limit,$mockCatalogue,$refresh,$auditCallback);
b8d3_check($r['data']['status']==='budget_exhausted','excess model-selected actions stopped');
$api=(string)file_get_contents(dirname(__DIR__).'/api/food-monster-llm.php');
b8d3_check(str_contains($api,'fm8d3_turn('),'live model endpoint uses tool selection');
b8d3_check(str_contains($api,'gavp_consume('),'voice cannot inherit owner tool grants without one-use operator approval');
b8d3_check(str_contains($api,'$allowTools'),'voice tools require explicit private turn approval');
b8d3_check(str_contains($api,'session_write_close()'),'long network call releases session lock');
b8d3_check(str_contains($api,"'voiceIdentityRequired'")
    &&str_contains($api,"'privateToolsAvailable'"),
    'API reports the real voice identity gate instead of claiming scheduling tools are absent');
echo "Food Monster B8D3: $n assertions passed.\n";
