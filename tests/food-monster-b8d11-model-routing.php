<?php
declare(strict_types=1);
/** FM-B8D11: model-first, server-authorized native/legacy skill handoff contract. */
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
function b8d11_check(bool $ok,string $name):void{
    global $n;if(!$ok){fwrite(STDERR,"FM-B8D11 FAIL: ".$name."\n");exit(1);}++$n;
}
$actor=['id'=>19,'organization_id'=>31,'display_name'=>'Test Manager',
    'permissions'=>['crm.view','schedule.view','menu.view','purchasing.view',
        'purchasing.agent','employee.self','timeclock.self']];
$context=['threadId'=>'agent-'.str_repeat('a',20),
    'messageId'=>'msg-'.str_repeat('b',20)];
$known=fm8d11_available_domains($actor);
b8d11_check(isset($known['crm'],$known['scheduling'],$known['purchasing'],
    $known['menu_brain'],$known['employee_home']),'authorized domains discoverable');
b8d11_check(!isset($known['equipment'],$known['marketing'],$known['operations']),
    'unauthorized domains hidden');
b8d11_check($known['crm']['route']==='api/customer-crm-agent.php',
    'server registry fixes CRM destination');
b8d11_check(fm8d11_catalogue($actor)===[],
    'no delegation without canonical thread and turn');
$restricted=array_replace($actor,['permissions'=>[]]);
b8d11_check(fm8d11_catalogue($restricted,$context)===[],
    'unprivileged user sees no destination list');
$owner=array_replace($actor,['role_slug'=>'super_admin','permissions'=>['*']]);
$ownerDomains=fm8d11_available_domains($owner);
b8d11_check(($ownerDomains['high_impact_approval']['route']??'')==='api/food-monster-approvals.php',
    'owner can still reach typed high-impact approval governance');
b8d11_check(!isset($known['high_impact_approval']),
    'regular staff cannot discover owner approval gate');
$tools=fm8d11_catalogue($actor,$context);
b8d11_check(isset($tools['delegate_restaurant_domain'])
    &&$tools['delegate_restaurant_domain']['impact']==='read',
    'delegation is registered as a non-writing proposal');
b8d11_check(!in_array('url',$tools['delegate_restaurant_domain']['allowedArguments'],true)
    &&!in_array('route',$tools['delegate_restaurant_domain']['allowedArguments'],true),
    'model may only select a destination key');
b8d11_check(in_array('crm',$tools['delegate_restaurant_domain']['parameters']['properties']['domain']['enum'],true),
    'provider receives permission-scoped domain schema');
b8d11_check(!in_array('equipment',$tools['delegate_restaurant_domain']['parameters']['properties']['domain']['enum'],true),
    'unauthorized domain excluded from provider schema');

$db=new PDO('sqlite::memory:');$audits=[];$payloads=[];
$transport=static function($url,$headers,$payload)use(&$payloads):array{
    $payloads[]=$payload;
    return ['output'=>[['type'=>'function_call','call_id'=>'b8d11-crm',
        'name'=>'delegate_restaurant_domain','arguments'=>'{"domain":"crm"}']]];
};
$audit=static function(array $event)use(&$audits):void{$audits[]=$event;};
$history=[['role'=>'user','content'=>'Find the customers who have not visited lately.']];
$r=fm8d3_turn($db,$actor,'openai','fake',$history,'',$transport,$tools,
    static fn():array=>$actor,$audit,$context);
$d=$r['data']['delegation']??null;
b8d11_check(($r['data']['toolCalls']??0)===1&&is_array($d),
    'OpenAI chooses a single permitted domain tool');
b8d11_check($d['route']==='api/customer-crm-agent.php'
    &&$d['node']==='crm','server emits canonical local route');
b8d11_check($d['threadId']===$context['threadId']
    &&$d['sourceMessageId']===$context['messageId'],
    'handoff is bound to exact committed chat turn');
b8d11_check(count($audits)===1,'model handoff produces a tool audit');
b8d11_check(!str_contains(json_encode($payloads),'customer email')
    &&!str_contains(json_encode($payloads),'fake'),
    'no private CRM result or key sent in model tool payload');
$revoke=static fn():array=>array_replace($actor,['permissions'=>[]]);
$r=fm8d3_turn($db,$actor,'openai','fake',$history,'',$transport,$tools,
    $revoke,$audit,$context);
b8d11_check(($r['data']['status']??'')==='denied'&&count($audits)===1,
    'current permission revocation prevents handoff');
$otherOrg=static fn():array=>array_replace($actor,['organization_id'=>32]);
$r=fm8d3_turn($db,$actor,'openai','fake',$history,'',$transport,$tools,
    $otherOrg,$audit,$context);
b8d11_check(($r['data']['status']??'')==='denied',
    'cross-tenant session change blocks handoff');
$unknown=static fn()=>['output'=>[['type'=>'function_call',
    'name'=>'delegate_restaurant_domain','arguments'=>'{"domain":"equipment"}']]];
$r=fm8d3_turn($db,$actor,'openai','fake',$history,'',$unknown,$tools,
    static fn():array=>$actor,$audit,$context);
b8d11_check(($r['data']['status']??'')==='denied',
    'model cannot select an unpermitted domain');
$injection=static fn()=>['output'=>[['type'=>'function_call',
    'name'=>'delegate_restaurant_domain','arguments'=>'{"domain":"crm","route":"https://evil.invalid/"}']]];
$r=fm8d3_turn($db,$actor,'openai','fake',$history,'',$injection,$tools,
    static fn():array=>$actor,$audit,$context);
b8d11_check(($r['data']['status']??'')==='denied',
    'arbitrary destination URL rejected');
$two=static fn()=>['content'=>[
    ['type'=>'tool_use','name'=>'delegate_restaurant_domain','input'=>['domain'=>'crm']],
    ['type'=>'tool_use','name'=>'delegate_restaurant_domain','input'=>['domain'=>'scheduling']],
]];
$r=fm8d3_turn($db,$actor,'anthropic','fake',$history,'',$two,$tools,
    static fn():array=>$actor,$audit,$context);
b8d11_check(($r['skill']??'')==='agent.delegation_needs_separate_turn'
    &&count($audits)===1,'multi-agent double dispatch prevented');
$claude=static fn()=>['content'=>[
    ['type'=>'tool_use','name'=>'delegate_restaurant_domain','input'=>['domain'=>'scheduling']],
]];
$r=fm8d3_turn($db,$actor,'anthropic','fake',$history,'',$claude,$tools,
    static fn():array=>$actor,$audit,$context);
b8d11_check(($r['data']['delegation']['node']??'')==='scheduling',
    'Claude selects a different permitted domain by intent');

$client=file_get_contents(dirname(__DIR__).'/js/global-agent.js');
b8d11_check(is_string($client)
    &&!str_contains($client,"const route = await post('api/agent-workspace.php'")
    &&!str_contains($client,"result?.data?.offlineFallback===true"),
    'main Agent Chat does not route private tools in an offline phrase fallback');
b8d11_check(str_contains($client,"const delegation=result?.data?.delegation;")
    &&str_contains($client,"serverMessage!==String(submitted?.message?.id||'')"),
    'client verifies delegation tied to exact turn');
$providerApi=file_get_contents(dirname(__DIR__).'/api/food-monster-llm.php');
b8d11_check(str_contains((string)$providerApi,"'code'=>'provider_not_configured'")
    &&!str_contains($client,"result?.code==='provider_not_configured'"),
    'no-provider failures remain explicit and do not enable private routing');
b8d11_check(str_contains($client,"!/^api\\/"),
    'client rejects external or arbitrary handoff destinations');
echo "FM-B8D11 model-first domain routing: PASS ($n assertions)\n";
