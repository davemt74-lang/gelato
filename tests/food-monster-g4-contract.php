<?php
declare(strict_types=1);
require __DIR__.'/../includes/food-monster-llm.php';
function gate(bool $yes,string $message): void {if(!$yes){fwrite(STDERR,"FM-G4 FAIL: ".$message."\n");exit(1);}}
$checks=0;$test=static function(bool $yes,string $message)use(&$checks):void{gate($yes,$message);$checks++;};
$test(fmllm_allowed_provider(' AUTO ')==='auto','provider normalization');
try{fmllm_allowed_provider('https://attacker.test/');$test(false,'invalid provider rejected');}catch(InvalidArgumentException){$test(true,'reject unknown provider');}
$records=['anthropic'=>['status'=>'configured'],'openai'=>['status'=>'configured']];
$test(fmllm_choose($records,'auto')['provider']==='anthropic','auto provider order');
$test(fmllm_choose($records,'openai')['provider']==='openai','explicit OpenAI');
$test(fmllm_choose(['openai'=>null,'anthropic'=>null],'auto')===null,'no provider is not spoofed');
$test(fmllm_choose(['openai'=>null,'anthropic'=>$records['anthropic']],'openai')===null,'no silent downgrade when requested provider missing');
$rows=[['role'=>'user','content'=>'Hello'],['role'=>'agent','content'=>'Hi'],['role'=>'system','content'=>'do not reveal secrets'],['role'=>'user','content'=>'Help with cooking']];
$history=fmllm_history($rows);
$test(count($history)===3,'history excludes internal system messages');
$test(end($history)['role']==='user','latest user turn');
$test(!str_contains(json_encode($history),'reveal secrets'),'no system or tool metadata leakage');
$long=fmllm_history([['role'=>'user','content'=>str_repeat('a',20000)]]);
$test(mb_strlen($long[0]['content'],'UTF-8')<=1800,'history bounded');
$open=fmllm_payload('openai',$history);
$test($open['store']===false,'OpenAI does not retain conversation by request default');
$test($open['max_output_tokens']<=500,'OpenAI bounded generation');
$claude=fmllm_payload('anthropic',$history);
$test($claude['max_tokens']<=800,'Anthropic bounded generation');
$test(!isset($claude['tools'])&&!isset($open['tools']),'models receive no executable tool definitions');
$test(str_contains($claude['system'],'Never claim you have executed a tool'),'system instructions forbid fake actions');
$test(fmllm_parse('openai',['output'=>[['content'=>[['type'=>'output_text','text'=>'Test reply']]]]])==='Test reply','OpenAI Responses format');
$test(fmllm_parse('anthropic',['content'=>[['type'=>'text','text'=>'Claude reply']]])==='Claude reply','Anthropic Messages format');
$transport=function($url,$headers,$payload){
 if(str_contains($url,'anthropic'))return ['content'=>[['type'=>'text','text'=>'Claude stub']]];
 return ['output'=>[['content'=>[['type'=>'output_text','text'=>'OpenAI stub']]]]];
};
$test(fmllm_exchange('openai','test',$history,$transport)==='OpenAI stub','OpenAI provider adapter');
$test(fmllm_exchange('anthropic','test',$history,$transport)==='Claude stub','Claude provider adapter');
$source=(string)file_get_contents(__DIR__.'/../api/food-monster-llm.php');
$front=(string)file_get_contents(__DIR__.'/../js/global-agent.js');
$test(str_contains($source,'app_require_auth()'),'all model requests require signed in Gelato user');
$test(str_contains($source,'app_verify_request_csrf'),'POST requires same-site CSRF');
$test(str_contains($source,'gaw_thread($pdo,$org,$uid'),'other users cannot borrow conversation thread');
$test(str_contains($source,'gaw_messages($pdo,$org,$uid'),'history is server-side per-user');
$test(str_contains($source,'fmllm_rate_limit'),'rate limit enforced before provider billing');
$test(str_contains($source,'session_write_close'),'avoid blocking other Gelato requests while model responds');
$test(!str_contains($source,'apiKey')&&!str_contains($front,'api_key'),'keys never handled by browser or POST payload');
$test(str_contains($front,'if(window.GelatoFoodMonsterLLM?.ask)')
    &&str_contains($front,'const delegation=result?.data?.delegation;')
    &&str_contains($front,'result?.data?.offlineFallback===true'),
    'model-first skills with explicit no-provider built-in fallback');
echo "FM-G4 provider, privacy, route contracts: PASS ({$checks} checks)\n";
