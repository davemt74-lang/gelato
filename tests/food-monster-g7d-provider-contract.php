<?php
declare(strict_types=1);
if(!function_exists('mb_substr')){function mb_substr(string $s,int $start,?int $len=null,?string $enc=null):string{return substr($s,$start,$len);}}
require __DIR__.'/../includes/food-monster-llm.php';
$n=0;
function g7d_gate(bool $ok,string $name):void{global $n;if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);}++$n;}
g7d_gate(fmllm_model('anthropic')==='claude-haiku-5-5','Active Claude API model ID');
g7d_gate(fmllm_model('openai')==='gpt-5-mini','OpenAI unchanged');
$payload=fmllm_payload('anthropic',[['role'=>'user','content'=>'Hi']]);
g7d_gate($payload['model']==='claude-haiku-5-5','API model request');
g7d_gate(($payload['thinking']['type']??'')==='disabled','Fast voice output without thought-only cutoff');
g7d_gate($payload['max_tokens']>=450&&$payload['max_tokens']<=800,'Bounded voice response');
g7d_gate(!isset($payload['temperature'])&&!isset($payload['top_p'])&&!isset($payload['top_k']),'No forbidden sampling settings');
g7d_gate(!isset($payload['tools']),'No LLM-side tool permissions');
g7d_gate(fmllm_parse('anthropic',['content'=>[['type'=>'thinking','thinking'=>'private'],['type'=>'text','text'=>'Hello']]])==='Hello','Do not speak thinking');
g7d_gate(fmllm_exchange('anthropic','test',[['role'=>'user','content'=>'Hi']],static function($url,$headers,$body){
 g7d_gate($body['model']==='claude-haiku-5-5','Transport model');
 return ['content'=>[['type'=>'text','text'=>'Hello user']]];
})==='Hello user','Parser and transport');
foreach([[401,'authentication'],[402,'billing'],[403,'permissions'],[404,'model_unavailable'],[429,'rate_limited'],[503,'upstream_unavailable']] as [$http,$reason]){
 $err=fmllm_provider_failure('anthropic',$http);
 g7d_gate($err->reason===$reason,'HTTP '.$http.' classification');
 g7d_gate(!str_contains($err->getMessage(),'sk-ant'),'Secret-safe diagnosis');
}
foreach([[6,'dns'],[28,'timeout'],[60,'tls']] as [$curl,$reason])
 g7d_gate(fmllm_provider_failure('anthropic',0,$curl)->reason===$reason,'cURL '.$curl.' diagnosis');
$api=file_get_contents(__DIR__.'/../api/food-monster-llm.php');
g7d_gate(str_contains($api,"'model'=>fmllm_model("),'Receipt reports active model');
g7d_gate(str_contains($api,'credential_decryption'),'Credential recovery guidance');
g7d_gate(str_contains($api,'app_require_auth()')&&str_contains($api,'app_verify_request_csrf'),'Auth and CSRF retained');
echo "FM-G7D Haiku and diagnostics: PASS ($n assertions)\n";