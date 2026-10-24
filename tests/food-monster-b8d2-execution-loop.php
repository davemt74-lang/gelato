<?php
declare(strict_types=1);
if(!function_exists('mb_strtolower')){function mb_strtolower(string $s,?string $encoding=null):string{return strtolower($s);}}
if(!function_exists('mb_strlen')){function mb_strlen(string $s,?string $encoding=null):int{return strlen($s);}}
if(!function_exists('mb_substr')){function mb_substr(string $s,int $offset,?int $length=null,?string $encoding=null):string{return substr($s,$offset,$length);}}
require dirname(__DIR__).'/includes/food-monster-b8d2-execution-loop.php';
require dirname(__DIR__).'/includes/food-monster-multi-node.php';
$count=0;
function b8d2_check(bool $ok,string $message):void{
    global $count;
    if(!$ok){fwrite(STDERR,'B8D2 FAIL: '.$message."\n");exit(1);}
    $count++;
}
$principal=['id'=>7,'organization_id'=>21,'permissions'=>['schedule.view','inventory.view']];
$now=$principal;$executed=0;$audited=[];$refreshes=0;
$catalogue=[
 'brain.review'=>[
   'impact'=>'read','allowedArguments'=>['domain'],
   'authorize'=>static fn(array $user,array $args):bool=>in_array('schedule.view',$user['permissions']??[],true),
   'validate'=>static fn(array $args,array $user):array=>isset($args['domain'])?[]:['domain'],
   'execute'=>static function(array $args,array $user)use(&$executed):array{
       $executed++;
       return ['ok'=>true,'data'=>['domain'=>$args['domain'],'org'=>$user['organization_id']]];
   },
 ],
 'schedule.create'=>[
   'impact'=>'routine','allowedArguments'=>['day','start','end'],
   'authorize'=>static fn(array $user,array $args):bool=>in_array('schedule.view',$user['permissions']??[],true),
   'validate'=>static fn(array $args,array $user):array=>array_values(array_filter(['day','start','end'],
        static fn(string $field):bool=>empty($args[$field]))),
   'execute'=>static function(array $args,array $user)use(&$executed):array{
       $executed++;
       return ['ok'=>true,'data'=>['shift'=>'saved']];
   },
 ],
 'purchase.submit'=>[
   'impact'=>'high_impact','allowedArguments'=>[],
   'authorize'=>static fn(array $user,array $args):bool=>true,
   'validate'=>static fn(array $args,array $user):array=>[],
   'execute'=>static function()use(&$executed):array{$executed++;return ['ok'=>true,'data'=>[]];},
 ],
 'menu.publish'=>[
   'impact'=>'routine','requiresConfirmation'=>true,'allowedArguments'=>[],
   'authorize'=>static fn(array $user,array $args):bool=>true,
   'validate'=>static fn(array $args,array $user):array=>[],
   'execute'=>static function()use(&$executed):array{$executed++;return ['ok'=>true,'data'=>[]];},
 ],
];
$policy=[
 'refreshActor'=>static function()use(&$now,&$refreshes):?array{$refreshes++;return $now;},
 'audit'=>static function(array $event)use(&$audited):void{$audited[]=$event;},
];
$plan=[
 ['tool'=>'brain.review','arguments'=>['domain'=>'scheduling']],
 ['tool'=>'brain.review','arguments'=>['domain'=>'inventory']],
];
$r=fm8d2_run($plan,$principal,$catalogue,$policy);
b8d2_check($r['status']==='completed','two-step tool plan completes');
b8d2_check($r['completed']===2&&$executed===2,'exactly two tool calls');
b8d2_check(count($audited)===2&&count($r['steps'])===2,'per-step observations and audit');
b8d2_check($refreshes===2,'revalidation on every step');
b8d2_check($r['steps'][1]['observation']['domain']==='inventory','observation preserves domain');
b8d2_check(!isset($r['steps'][0]['arguments']),'trace does not expose tool input');
foreach($audited as $event){
 b8d2_check($event['operatorId']===7&&$event['organizationId']===21,'audited identity is server principal');
 b8d2_check($event['impact']==='read'&&$event['status']==='completed','audit metadata includes outcome');
 b8d2_check(!isset($event['arguments']),'audit does not capture raw arguments');
}
$before=$executed;
$r=fm8d2_run(array_fill(0,7,$plan[0]),$principal,$catalogue,$policy);
b8d2_check($r['status']==='budget_exhausted'&&$executed===$before,'oversized plan rejected atomically');
$r=fm8d2_run([['tool'=>'not.registered','arguments'=>[]]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='denied'&&$executed===$before,'unregistered tools never execute');
$r=fm8d2_run([['tool'=>'../../api/scheduling.php','arguments'=>[]]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='denied','arbitrary routes are not tools');
$r=fm8d2_run([['tool'=>'brain.review','arguments'=>['domain'=>'scheduling','url'=>'https://bad.test']]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='denied','unknown arguments rejected before dispatch');
$r=fm8d2_run([['tool'=>'brain.review','arguments'=>['scheduling']]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='denied','positional parameter smuggling rejected');
$r=fm8d2_run([['tool'=>'brain.review','arguments'=>[]]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='needs_input'&&str_contains($r['reason'],'domain'),'asks for missing domain');
b8d2_check($r['completed']===0,'missing required input never executes');
$now=['id'=>9,'organization_id'=>21,'permissions'=>['schedule.view']];
$r=fm8d2_run([$plan[0]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='denied'&&$r['completed']===0,'identity switch blocked');
$now=['id'=>7,'organization_id'=>88,'permissions'=>['schedule.view']];
$r=fm8d2_run([$plan[0]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='denied','cross-organization actor blocked');
$now=['id'=>7,'organization_id'=>21,'permissions'=>[]];
$r=fm8d2_run([$plan[0]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='denied','revoke access before first skill');
$now=$principal;
$refreshNumber=0;
$policy2=$policy;
$policy2['refreshActor']=static function()use(&$refreshNumber,$principal):array{
 $refreshNumber++;return $refreshNumber===1?$principal:array_replace($principal,['permissions'=>[]]);
};
$r=fm8d2_run($plan,$principal,$catalogue,$policy2);
b8d2_check($r['status']==='denied'&&$r['completed']===1,'permission revocation halts between steps');
b8d2_check(count($r['steps'])===1,'revoked step was not dispatched');
$r=fm8d2_run([['tool'=>'purchase.submit','arguments'=>[]]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='approval_required','high-impact action requires existing approval');
$r=fm8d2_run([['tool'=>'menu.publish','arguments'=>[],'idempotencyKey'=>'idem-key-0001']],$principal,$catalogue,$policy);
b8d2_check($r['status']==='approval_required','confirmation-only write not auto-executed');
$r=fm8d2_run([['tool'=>'schedule.create','arguments'=>['day'=>'fri']]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='needs_input'&&str_contains($r['reason'],'start'),'shift creation asks for missing times');
$r=fm8d2_run([['tool'=>'schedule.create','arguments'=>['day'=>'fri','start'=>'09:00','end'=>'17:00']]],$principal,$catalogue,$policy);
b8d2_check($r['status']==='denied','routine writes need operation key');
$write=['tool'=>'schedule.create','arguments'=>['day'=>'fri','start'=>'09:00','end'=>'17:00'],'idempotencyKey'=>'shift-fri-001'];
$r=fm8d2_run([$write,$write],$principal,$catalogue,$policy);
b8d2_check($r['status']==='denied'&&$r['completed']===1,'same-run duplicate write blocked');
b8d2_check($r['steps'][0]['observation']['shift']==='saved','successful routine action reports result');
$r=fm8d2_run([$write],$principal,$catalogue,['refreshActor'=>$policy['refreshActor']]);
b8d2_check($r['status']==='denied','routine writes require auditing');
try{fm8d2_run($plan,$principal,$catalogue,[]);b8d2_check(false,'refresh required');}
catch(InvalidArgumentException){b8d2_check(true,'missing refresh callback fails closed');}
$broken=$catalogue;
$broken['brain.review']['execute']=static fn():array=>['ok'=>false,'data'=>[]];
$r=fm8d2_run([$plan[0]],$principal,$broken,$policy);
b8d2_check($r['status']==='failed','failed tool does not report success');
$broken['brain.review']['execute']=static function():array{throw new RuntimeException('SECRET_ERROR_DETAILS');};
$r=fm8d2_run([$plan[0]],$principal,$broken,$policy);
b8d2_check($r['status']==='failed'&&!str_contains(json_encode($r),'SECRET_ERROR_DETAILS'),'tool exception details are private');
$broken['brain.review']['execute']=static fn():array=>['ok'=>true,'data'=>['private'=>str_repeat('x',4000)]];
$r=fm8d2_run([$plan[0]],$principal,$broken,$policy);
b8d2_check($r['status']==='failed','oversized observations rejected');
$broken['brain.review']['execute']=static fn():array=>['ok'=>true,'data'=>'not-an-array'];
$r=fm8d2_run([$plan[0]],$principal,$broken,$policy);
b8d2_check($r['status']==='failed','malformed observations rejected');
$slow=$catalogue;
$slow['brain.review']['execute']=static function(array $args):array{
 usleep(75000);return ['ok'=>true,'data'=>['domain'=>$args['domain']]];
};
$r=fm8d2_run($plan,$principal,$slow,$policy+['maxMs'=>50]);
b8d2_check($r['status']==='budget_exhausted'&&$r['completed']===1,'time budget halts subsequent steps');
$brokenPolicy=$policy;
$brokenPolicy['audit']=static function(array $event):void{throw new RuntimeException('AUDIT_CONNECTION_ERROR');};
$r=fm8d2_run([$plan[0]],$principal,$catalogue,$brokenPolicy);
b8d2_check($r['status']==='audit_failed'&&$r['completed']===1,'audit failure does not hide successful execution');
b8d2_check(!str_contains(json_encode($r),'AUDIT_CONNECTION_ERROR'),'audit exception concealed');
$snapshot=['generatedAt'=>'2026-10-24T00:00:00+00:00','signals'=>[
 ['key'=>'scheduling:coverage','node'=>'scheduling','severity'=>'high','score'=>90,
  'title'=>'Two shifts uncovered','detail'=>'Friday','nextMove'=>['prompt'=>'Review coverage']],
 ['key'=>'operations:low-stock','node'=>'operations','severity'=>'high','score'=>70,
  'title'=>'Low mozzarella','detail'=>'Reorder soon','nextMove'=>['prompt'=>'Review inventory']],
]];
$domains=['scheduling','inventory'];
$reviewed=[];
$readCatalogue=['brain.domain'=>[
 'impact'=>'read','allowedArguments'=>['domain'],
 'authorize'=>static fn(array $user,array $args):bool=>true,
 'validate'=>static fn(array $args,array $user):array=>in_array((string)($args['domain']??''),$domains,true)?[]:['domain'],
 'execute'=>static function(array $args,array $actor)use($snapshot,&$reviewed):array{
   $result=fm7_briefing($snapshot,[$args['domain']]);$reviewed[]=$result;
   return ['ok'=>true,'data'=>['domain'=>$args['domain'],'signalCount'=>$result['data']['steps'][0]['signalCount']]];
 },
]];
$r=fm8d2_run(array_map(static fn($d)=>['tool'=>'brain.domain','arguments'=>['domain'=>$d]],$domains),
 $principal,$readCatalogue,$policy);
b8d2_check($r['status']==='completed'&&count($reviewed)===2,'real Agent Brain domain reads through loop');
b8d2_check($r['steps'][0]['observation']['signalCount']===1,'verified scheduling signal');
b8d2_check($r['steps'][1]['observation']['signalCount']===1,'verified inventory signal');
b8d2_check($reviewed[0]['data']['mode']==='read_only','domain review maintains read-only mode');
$endpoint=file_get_contents(dirname(__DIR__).'/api/food-monster-orchestrate.php');
b8d2_check(str_contains($endpoint,'fm8d2_run('),'live Agent Brain endpoint uses runtime');
b8d2_check(str_contains($endpoint,'fm8d2_refresh_operator('),'endpoint refreshes authority per step');
b8d2_check(str_contains($endpoint,'fm7_briefing('),'existing verified briefing remains intact');
b8d2_check(str_contains($endpoint,'app_audit('),'execution has server audit');
echo 'Food Monster B8D2: '.$count." assertions passed.\n";
