<?php
declare(strict_types=1);
/** FM-B8D13A: transactional preflight of model-selected skill batches. */
if(!function_exists('mb_substr')){function mb_substr(string $s,int $o,?int $l=null,?string $e=null):string{return substr($s,$o,$l);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $e=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $e=null):string{return strtolower($s);}}
function app_has_permission(string $name,array $actor):bool{
    return in_array('*',$actor['permissions']??[],true)
        ||in_array($name,$actor['permissions']??[],true);
}
require dirname(__DIR__).'/includes/food-monster-llm.php';
require dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';
$count=0;$effects=0;
function b8d13_check(bool $ok,string $name):void{
    global $count;if(!$ok){fwrite(STDERR,"FM-B8D13A FAIL: ".$name."\n");exit(1);}++$count;
}
$actor=['id'=>21,'organization_id'=>8,'display_name'=>'Shift Manager',
    'permissions'=>['sample.read','sample.write']];
$good=static fn():array=>$actor;
$readTool=static function(string $label)use(&$effects):array{
    return [
        'description'=>'Read a verified '.$label,
        'parameters'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
        'allowedArguments'=>[],'impact'=>'read',
        'authorize'=>static fn(array $user,array $args):bool=>app_has_permission('sample.read',$user),
        'validate'=>static fn(array $args,array $user):array=>[],
        'execute'=>static function(array $args,array $user)use(&$effects,$label):array{
            ++$effects;
            return ['ok'=>true,'data'=>['answer'=>$label.' verified private detail',
                'source'=>'Authenticated '.$label]];
        },
    ];
};
$catalogue=['read_one'=>$readTool('First'),'read_two'=>$readTool('Second')];
$catalogue['routine_save']=[
    'description'=>'Save a routine test record',
    'parameters'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
    'allowedArguments'=>[],'impact'=>'routine',
    'authorize'=>static fn(array $u,array $args):bool=>app_has_permission('sample.write',$u),
    'validate'=>static fn(array $a,array $u):array=>[],
    'execute'=>static function(array $args,array $u)use(&$effects):array{
        ++$effects;return ['ok'=>true,'data'=>['answer'=>'Routine change saved','source'=>'Gelato']];
    },
];
$catalogue['read_required']=array_replace($readTool('Required'),[
    'allowedArguments'=>['query'],
    'parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']],
        'required'=>['query'],'additionalProperties'=>false],
    'validate'=>static fn(array $a,array $u):array=>empty($a['query'])?['query']:[],
]);
$plan=static fn(string $name,array $args=[],?string $key=null):array=>[
    'tool'=>$name,'arguments'=>$args]+($key!==null?['idempotencyKey'=>$key]:[]);
$pass=[$plan('read_one'),$plan('read_two')];
$r=fm8d13_preflight($pass,$actor,$catalogue,$good);
b8d13_check($r['status']==='ready'&&$r['planned']===2&&$r['executed']===0,
    'independent authorized reads are preflighted without execution');
b8d13_check($effects===0,'preflight causes no effects');
$r=fm8d13_preflight([$plan('read_one'),$plan('unknown_skill')],$actor,$catalogue,$good);
b8d13_check($r['status']==='denied'&&$r['blockedAt']===1&&$effects===0,
    'unknown second tool blocks entire batch before first read');
$r=fm8d13_preflight([$plan('read_one'),$plan('read_required')],$actor,$catalogue,$good);
b8d13_check($r['status']==='needs_input'&&str_contains($r['reason'],'query')
    &&$effects===0,'missing detail in later step prevents partial execution');
$r=fm8d13_preflight([$plan('read_one'),$plan('read_two',['url'=>'https://evil.invalid'])],
    $actor,$catalogue,$good);
b8d13_check($r['status']==='denied'&&$effects===0,
    'unregistered model argument cannot be sent to any skill');
$r=fm8d13_preflight([$plan('read_one'),$plan('read_one')],$actor,$catalogue,$good);
b8d13_check($r['status']==='needs_input'&&$effects===0,'duplicate reads blocked');
$r=fm8d13_preflight([$plan('read_one'),$plan('routine_save',[],'op-a12345678')],
    $actor,$catalogue,$good);
b8d13_check($r['status']==='needs_input'&&$effects===0,
    'model cannot chain an unread result to a routine write');
$r=fm8d13_preflight([$plan('routine_save',[],'op-a12345678'),
    $plan('routine_save',[],'op-b12345678')],$actor,$catalogue,$good);
b8d13_check($r['status']==='needs_input'&&$effects===0,
    'multiple writes forbidden in one model-selected turn');
$r=fm8d13_preflight([$plan('routine_save')],$actor,$catalogue,$good);
b8d13_check($r['status']==='denied'&&$effects===0,
    'routine write cannot omit idempotency key');
$r=fm8d13_preflight([$plan('routine_save',[],'op-a12345678')],$actor,$catalogue,$good);
b8d13_check($r['status']==='ready'&&$effects===0,
    'single authorized keyed routine action can proceed');
$changed=static fn():array=>array_replace($actor,['permissions'=>[]]);
$r=fm8d13_preflight([$plan('read_one')],$actor,$catalogue,$changed);
b8d13_check($r['status']==='denied'&&$effects===0,
    'live permission revocation blocks preflight');
$foreign=static fn():array=>array_replace($actor,['organization_id'=>9]);
$r=fm8d13_preflight([$plan('read_one')],$actor,$catalogue,$foreign);
b8d13_check($r['status']==='denied'&&$effects===0,
    'cross-tenant identity change is rejected');
$r=fm8d13_preflight([$plan('read_one')],$actor,$catalogue,
    static function():array{throw new RuntimeException('DB error');});
b8d13_check($r['status']==='denied'&&$effects===0,
    'stale/unavailable account authority is fail-closed');
$catalogue['risky_change']=array_replace($catalogue['routine_save'],['impact'=>'high_impact']);
$r=fm8d13_preflight([$plan('risky_change')],$actor,$catalogue,$good);
b8d13_check($r['status']==='approval_required'&&$effects===0,
    'high-impact operation never executes from a model plan');
$catalogue['needs_confirm']=array_replace($catalogue['read_one'],['requiresConfirmation'=>true]);
$r=fm8d13_preflight([$plan('needs_confirm')],$actor,$catalogue,$good);
b8d13_check($r['status']==='approval_required'&&$effects===0,
    'explicit confirmation gate is honored before execution');
$r=fm8d13_preflight(array_fill(0,5,$plan('read_one')),$actor,$catalogue,$good);
b8d13_check($r['status']==='budget_exhausted'&&$effects===0,
    'bounded maximum four planned skills');
$catalogue['delegate_restaurant_domain']=array_replace($readTool('Legacy'),[]);
$r=fm8d13_preflight([$plan('read_one'),$plan('delegate_restaurant_domain')],
    $actor,$catalogue,$good);
b8d13_check($r['status']==='needs_input'&&$effects===0,
    'domain-agent handoffs must be isolated');
$db=new PDO('sqlite::memory:');$audit=[];$providerRequests=[];
$transport=static function($endpoint,$headers,$payload)use(&$providerRequests):array{
    $providerRequests[]=$payload;
    return ['output'=>[
        ['type'=>'function_call','name'=>'read_one','arguments'=>'{}','call_id'=>'test-1'],
        ['type'=>'function_call','name'=>'read_two','arguments'=>'{}','call_id'=>'test-2'],
    ]];
};
$record=static function(array $event)use(&$audit):void{$audit[]=$event;};
$history=[['role'=>'user','content'=>'Show my two independent read-only facts.']];
$r=fm8d3_turn($db,$actor,'openai','test',$history,'',$transport,$catalogue,
    $good,$record,['messageId'=>'msg-'.str_repeat('a',20)]);
b8d13_check(($r['data']['toolCalls']??0)===2&&$r['skill']==='agent.model_selected_tools',
    'OpenAI can select two read-only tools in one request');
b8d13_check(str_contains($r['answer'],'First verified private detail')
    &&str_contains($r['answer'],'Second verified private detail')
    &&count($r['sources'])===2,'independent private observations combined locally');
b8d13_check(count($audit)===2&&$effects===2,'every read executes and audits once');
b8d13_check(count($providerRequests)===1
    &&!str_contains(json_encode($providerRequests),'verified private detail'),
    'private observations never roundtrip to AI provider');
$malformed=static fn()=>['output'=>[
    ['type'=>'function_call','name'=>'read_one','arguments'=>'{}'],
    ['type'=>'function_call','name'=>'unknown_skill','arguments'=>'{}'],
]];
$before=$effects;
$r=fm8d3_turn($db,$actor,'openai','test',$history,'',$malformed,$catalogue,
    $good,$record,[]);
b8d13_check(($r['data']['status']??'')==='denied'&&$effects===$before,
    'model-selected invalid second skill never causes partial execution');
$mix=static fn()=>['content'=>[
    ['type'=>'tool_use','name'=>'read_one','input'=>[]],
    ['type'=>'tool_use','name'=>'routine_save','input'=>[]],
]];
$r=fm8d3_turn($db,$actor,'anthropic','test',$history,'',$mix,$catalogue,
    $good,$record,['messageId'=>'msg-'.str_repeat('b',20)]);
b8d13_check(($r['data']['status']??'')==='needs_input'&&$effects===$before,
    'Claude mixed read/write plan is blocked before reads or mutations');
$runtime=(string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
b8d13_check(str_contains($runtime,'fm8d13_preflight($plan,$principal,$catalogue,$refresh)')
    &&str_contains($runtime,"'skill'=>'agent.plan_preflight_stopped'"),
    'real Agent Chat runtime invokes preflight before executing all tools');
echo "FM-B8D13A complete-plan preflight: PASS ($count assertions)\n";
