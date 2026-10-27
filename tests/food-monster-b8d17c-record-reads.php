<?php
declare(strict_types=1);

if(!function_exists('mb_substr')){function mb_substr(string $s,int $o,?int $l=null,?string $e=null):string{return substr($s,$o,$l);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $e=null):int{return strlen($s);}}
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $e=null):string{return strtolower($s);}}
function app_has_permission(string $name,array $user):bool
{
    return in_array('*',$user['permissions']??[],true)||in_array($name,$user['permissions']??[],true);
}
function app_pdo():PDO
{
    static $pdo=null;
    if($pdo===null)$pdo=new PDO('sqlite::memory:');
    return $pdo;
}
require dirname(__DIR__).'/includes/food-monster-llm.php';
require dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';

$n=0;
function b8d17c_check(bool $ok,string $message):void
{
    global $n;
    if(!$ok){fwrite(STDERR,"B8D17C FAIL: $message\n");exit(1);}
    ++$n;
}

$topics=fmuk_read_topics();
b8d17c_check(count($topics)===20&&count($topics)===count(array_unique($topics)),'stable twenty-topic read broker');
$owner=['id'=>7,'organization_id'=>21,'role_slug'=>'super_admin','permissions'=>['*']];
$employee=['id'=>8,'organization_id'=>21,'permissions'=>['timeclock.self','inventory.view','tasks.self']];
$context=['channel'=>'text','userQuestion'=>'What do we have in stock?'];
$registry=fm8d17c_catalogue($employee,$context);
b8d17c_check(isset($registry['read_gelato_records']),'authorized native record read registered');
$read=$registry['read_gelato_records'];
$allowed=$read['parameters']['properties']['topic']['enum'];
b8d17c_check(in_array('inventory',$allowed,true)&&in_array('attendance',$allowed,true)
    &&in_array('tasks',$allowed,true),'existing self and inventory permissions preserved');
b8d17c_check(!in_array('crm',$allowed,true)&&!in_array('purchasing',$allowed,true)
    &&!in_array('sales',$allowed,true),'unauthorized topics absent from tool schema');
b8d17c_check($read['allowedArguments']===['topic']
    &&$read['parameters']['additionalProperties']===false,'model cannot supply record IDs, SQL, employee IDs or org selectors');
b8d17c_check($read['impact']==='read','broker is read-only');
b8d17c_check(($read['authorize'])($employee,['topic'=>'inventory']),'authorized inventory read');
b8d17c_check(!($read['authorize'])($employee,['topic'=>'crm']),'ungranted CRM denied');
b8d17c_check(!($read['authorize'])($employee,['topic'=>'mysql']),'unknown topic denied');
b8d17c_check(($read['validate'])(['topic'=>'sql'],$employee)===['topic'],'unknown topic rejected at validation');
b8d17c_check(!($read['authorize'])(array_replace($employee,['permissions'=>[]]),['topic'=>'inventory']),
    'refreshed account permission revocation denies read');
b8d17c_check(!($read['authorize'])(array_replace($employee,['organization_id'=>0]),['topic'=>'inventory']),
    'missing authenticated org denies read');
$denied=($read['execute'])(['topic'=>'crm'],$employee);
b8d17c_check($denied['ok']===false&&$denied['data']===[],'execute independently checks permission');
$emptyQuestion=fm8d17c_catalogue($employee,['channel'=>'text'])['read_gelato_records'];
$noQuestion=($emptyQuestion['execute'])(['topic'=>'inventory'],$employee);
b8d17c_check($noQuestion['ok']===true&&str_contains($noQuestion['data']['answer'],'authenticated Agent Chat'),
    'model-selected tool cannot invent a question when no canonical saved message exists');

$ownerRead=fm8d17c_catalogue($owner,$context)['read_gelato_records'];
b8d17c_check($ownerRead['parameters']['properties']['topic']['enum']===$topics,
    'super admin sees all twenty existing authorized read domains');
$voice=fm8d17c_catalogue($employee,['channel'=>'voice','privateVoiceApproved'=>false,
    'userQuestion'=>'Read our inventory']);
b8d17c_check($voice===[],'unverified voice cannot discover inventory or private record tools');
$voiceOwner=fm8d17c_catalogue($owner,['channel'=>'voice','privateVoiceApproved'=>false]);
b8d17c_check(isset($voiceOwner['read_gelato_records'])
    &&$voiceOwner['read_gelato_records']['parameters']['properties']['topic']['enum']===['menu'],
    'unverified voice may discover only explicitly public menu topic');
b8d17c_check(!($voiceOwner['read_gelato_records']['authorize'])($owner,['topic'=>'inventory']),
    'unverified super admin voice cannot inherit owner privileges');
$verifiedVoice=fm8d17c_catalogue($employee,['channel'=>'voice','privateVoiceApproved'=>true]);
b8d17c_check(in_array('inventory',$verifiedVoice['read_gelato_records']['parameters']['properties']['topic']['enum'],true),
    'once-approved voice uses same user permission scope');

$api=(string)file_get_contents(dirname(__DIR__).'/api/food-monster-llm.php');
$runtime=(string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
b8d17c_check(str_contains($api,"'userQuestion'=>(string)\$last['content']"),
    'model input bound to server-authorized saved last chat message');
b8d17c_check(str_contains($runtime,'fm8d17c_catalogue($principal,$context)'),
    'native tool connected to the live model catalogue');
b8d17c_check(str_contains($runtime,'read_gelato_records'),
    'model gets explicit routing instruction for real Gelato records');
b8d17c_check(str_contains($runtime,'never round-tripped to the external LLM provider'),
    'record observations remain terminal local outputs');

// Even a model-driven topic is permission checked inside the source broker.
$broker=fmuk_evidence(app_pdo(),$employee,'show customer contacts',false,false,['crm']);
b8d17c_check($broker['topics']===[]&&str_contains($broker['context'],'NOT AUTHORIZED'),
    'source broker denies ungranted model-selected topics before querying');
$unknown=fmuk_evidence(app_pdo(),$employee,'arbitrary',false,false,['sql','../../etc/passwd']);
b8d17c_check($unknown['topics']===[]&&!str_contains($unknown['context'],'etc/passwd'),
    'unknown topic selectors never reach broker or its evidence');

echo "Food Monster B8D17C: $n assertions passed.\n";
