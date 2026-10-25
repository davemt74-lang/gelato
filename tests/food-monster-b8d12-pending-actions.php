<?php
declare(strict_types=1);
/** FM-B8D12: model-selected continuation of a verified pending Agent action. */
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
function b8d12_check(bool $ok,string $name):void{
    global $n;if(!$ok){fwrite(STDERR,"FM-B8D12 FAIL: ".$name."\n");exit(1);}++$n;
}
$actor=['id'=>19,'organization_id'=>31,'display_name'=>'Restaurant Manager',
    'permissions'=>['crm.view'],'role_slug'=>'manager'];
$ctx=['threadId'=>'agent-'.str_repeat('a',20),'messageId'=>'msg-'.str_repeat('b',20)];
$key='31:19';
$_SESSION=[];
b8d12_check(fm8d12_pending_catalogue($actor,$ctx)===[],
    'no pending means no confirmation tool');
$_SESSION['agent_node_pending']['crm'][$key]=[
    'id'=>'gap-a1b2c3d4e5f6a7b8','node'=>'crm','type'=>'customer_follow_up',
    'organizationId'=>31,'userId'=>19,'created'=>time()-4,
    'expires'=>time()+240,'summary'=>'Proposed customer follow-up'];
$cat=fm8d12_pending_catalogue($actor,$ctx);
b8d12_check(isset($cat['continue_pending_restaurant_action'])
    &&$cat['continue_pending_restaurant_action']['impact']==='read',
    'pending action selects a non-writing handoff');
b8d12_check($cat['continue_pending_restaurant_action']['allowedArguments']===['decision']
    &&$cat['continue_pending_restaurant_action']['parameters']['properties']['decision']['enum']===['confirm','cancel'],
    'model can only select explicit confirm or cancel, never route or payload');
b8d12_check(fm8d12_pending_catalogue($actor,[])===[],
    'canonical thread and message required');
$revoked=array_replace($actor,['permissions'=>[]]);
b8d12_check(fm8d12_pending_catalogue($revoked,$ctx)===[],
    'pending grant disappears after permission revocation');
$other=array_replace($actor,['organization_id'=>32]);
b8d12_check(fm8d12_pending_catalogue($other,$ctx)===[],
    'pending action cannot cross tenant');
$bad=$_SESSION['agent_node_pending']['crm'][$key];
$_SESSION['agent_node_pending']['crm'][$key]['organizationId']=32;
b8d12_check(fm8d12_pending_catalogue($actor,$ctx)===[],
    'forged pending organization blocked');
$_SESSION['agent_node_pending']['crm'][$key]=$bad;
$_SESSION['agent_node_pending']['crm'][$key]['userId']=24;
b8d12_check(fm8d12_pending_catalogue($actor,$ctx)===[],
    'forged pending owner blocked');
$_SESSION['agent_node_pending']['crm'][$key]=$bad;
$_SESSION['agent_node_pending']['crm'][$key]['expires']=time()-1;
b8d12_check(fm8d12_pending_catalogue($actor,$ctx)===[],
    'expired proposal cannot execute');
$_SESSION['agent_node_pending']['crm'][$key]=$bad;
$cat=fm8d12_pending_catalogue($actor,$ctx);
$pdo=new PDO('sqlite::memory:');$audits=[];
$audit=static function(array $event)use(&$audits):void{$audits[]=$event;};
$transport=static fn()=>['output'=>[['type'=>'function_call',
    'name'=>'continue_pending_restaurant_action',
    'arguments'=>'{"decision":"confirm"}']]];
$history=[['role'=>'user','content'=>'Yes, please go ahead with that last proposal.']];
$r=fm8d3_turn($pdo,$actor,'openai','test',$history,'',$transport,$cat,
    static fn():array=>$actor,$audit,$ctx);
$handoff=$r['data']['delegation']??[];
b8d12_check(($r['data']['toolCalls']??0)===1
    &&($handoff['route']??'')==='api/customer-crm-agent.php',
    'model confirms exactly one canonical existing Agent');
b8d12_check(($handoff['continuation']??false)===true
    &&($handoff['forwardMessage']??'')==='Confirm'
    &&($handoff['node']??'')==='crm',
    'server generates canonical confirm token, not arbitrary model words');
b8d12_check(($handoff['threadId']??'')===$ctx['threadId']
    &&($handoff['sourceMessageId']??'')===$ctx['messageId'],
    'decision is bound to the latest authenticated message');
b8d12_check(count($audits)===1,'confirmed handoff audited without direct write');
$reject=static fn()=>['content'=>[['type'=>'tool_use',
    'name'=>'continue_pending_restaurant_action',
    'input'=>['decision'=>'cancel']]]];
$r=fm8d3_turn($pdo,$actor,'anthropic','test',$history,'',$reject,$cat,
    static fn():array=>$actor,$audit,$ctx);
b8d12_check(($r['data']['delegation']['forwardMessage']??'')==='Cancel',
    'Claude selects natural-language cancellation');
$mutate=static fn()=>['output'=>[['type'=>'function_call',
    'name'=>'continue_pending_restaurant_action',
    'arguments'=>'{"decision":"confirm","route":"https://evil.invalid"}']]];
$r=fm8d3_turn($pdo,$actor,'openai','test',$history,'',$mutate,$cat,
    static fn():array=>$actor,$audit,$ctx);
b8d12_check(($r['data']['status']??'')==='denied',
    'model cannot supply a different route or arbitrary argument');
$r=fm8d3_turn($pdo,$actor,'openai','test',$history,'',$transport,$cat,
    static fn():array=>$revoked,$audit,$ctx);
b8d12_check(($r['data']['status']??'')==='denied',
    'fresh permission revocation stops pending handoff');
$twice=static fn()=>['output'=>[
    ['type'=>'function_call','name'=>'continue_pending_restaurant_action',
        'arguments'=>'{"decision":"confirm"}'],
    ['type'=>'function_call','name'=>'continue_pending_restaurant_action',
        'arguments'=>'{"decision":"confirm"}'],
]];
$r=fm8d3_turn($pdo,$actor,'openai','test',$history,'',$twice,$cat,
    static fn():array=>$actor,$audit,$ctx);
b8d12_check(($r['skill']??'')==='agent.delegation_needs_separate_turn',
    'multiple confirmations rejected in one turn');
$_SESSION['agent_node_pending']['crm'][$key]['expires']=time()-1;
$r=fm8d3_turn($pdo,$actor,'openai','test',$history,'',$transport,$cat,
    static fn():array=>$actor,$audit,$ctx);
b8d12_check(($r['data']['status']??'')==='denied',
    'proposal expiration between planning and execution blocks handoff');
$client=(string)file_get_contents(dirname(__DIR__).'/js/global-agent.js');
b8d12_check(str_contains($client,'delegation.continuation===true')
    &&str_contains($client,'delegatedMessage=delegation.forwardMessage')
    &&str_contains($client,'message:delegatedMessage'),
    'client sends only the server-approved canonical pending decision');
b8d12_check(str_contains($client,"serverMessage!==String(submitted?.message?.id||'')")
    &&str_contains($client,"serverThread!==S.thread"),
    'pending delegation remains bound to authenticated chat turn');
$runtime=(string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
b8d12_check(str_contains($runtime,'fm8d12_pending_catalogue($principal,$context)')
    &&str_contains($runtime,"['delegate_restaurant_domain','continue_pending_restaurant_action']"),
    'runtime exposes safe pending skill and rejects mixed handoffs');
echo "FM-B8D12 pending Agent continuation: PASS ($n assertions)\n";
