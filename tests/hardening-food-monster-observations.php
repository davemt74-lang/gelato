<?php
declare(strict_types=1);
// Deployment archives retain tests. They must never be executable over HTTP.
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}

function app_has_permission(string $permission,array $actor): bool
{
    return in_array('*',$actor['permissions']??[],true)||in_array($permission,$actor['permissions']??[],true);
}
require_once dirname(__DIR__).'/includes/food-monster-llm.php';
require_once dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';
$checks=0;
function hfo_check(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException('AI-01 observation contract: '.$message);
    ++$GLOBALS['checks'];
}
$owner=['id'=>7,'organization_id'=>21,'role_slug'=>'super_admin','is_owner_role'=>1,
    'is_system_role'=>1,'display_name'=>'Observation Owner','permissions'=>['*']];
$refreshes=0;
$refresh=static function()use($owner,&$refreshes):array{++$refreshes;return $owner;};
$audit=static function(array $event):void{};
$catalogue=fm8d3_catalogue($owner);
$full=fm8d17a_snapshot($owner);
hfo_check(strlen(fm8do_json($full))>10000,'real owner snapshot reproduces the old blanket-cap defect');
foreach($catalogue as $id=>$tool){
    if(($tool['impact']??'')!=='read'||isset(fm8do_contracts()[$id]))continue;
    hfo_check(str_starts_with($id,'propose_')||in_array($id,
        ['delegate_restaurant_domain','continue_pending_restaurant_action','navigate_to_gelato_page'],true),
        'every actual registered read has an audited contract: '.$id);
}
$plan=[['tool'=>'list_my_gelato_capabilities','arguments'=>[]]];
$result=fm8d2_run($plan,$owner,$catalogue,['refreshActor'=>$refresh,'audit'=>$audit]);
hfo_check($result['status']==='completed'&&$refreshes===1,'real owner catalogue executes through authorize/observe');
$cap=$result['steps'][0]['observation'];
hfo_check(fm8do_fits('list_my_gelato_capabilities',$cap),'capability observation fits its encoded-byte contract');
hfo_check($cap['capabilityTruth']['counts']===$full['counts']&&!isset($cap['capabilityTruth']['planned']),
    'counts remain complete while unused planned descriptors are omitted');
hfo_check($cap['capabilityTruth']['executionVerified']===false&&str_contains($cap['answer'],'not proof')
    &&str_contains($cap['answer'],'separate confirmation'),'capability result does not invent execution or authority');
$staff=fm8d17a_observation(fm8d17a_snapshot(array_replace($owner,['role_slug'=>'employee','permissions'=>[]])));
hfo_check($staff['capabilityTruth']['counts']['plannedNotExecutable']===null,'compact snapshot preserves owner-only disclosure');
$pdo=new PDO('sqlite::memory:');$payloads=[];
$transport=static function($endpoint,$headers,$payload)use(&$payloads):array{
    $payloads[]=$payload;
    return ['output'=>[['type'=>'function_call','call_id'=>'cap-real',
        'name'=>'list_my_gelato_capabilities','arguments'=>'{}']]];
};
$turn=fm8d3_turn($pdo,$owner,'openai','test-key',[['role'=>'user','content'=>'Which Gelato capabilities are registered?']],
    '',$transport,null,$refresh,$audit);
hfo_check(($turn['data']['status']??'')==='completed'&&count($payloads)===1,'actual runtime catalogue succeeds with one stub provider request');
hfo_check(($turn['data']['readDetails'][0]['url']??'')==='agent-canvas.php','terminal response keeps a server-owned detail link');
hfo_check(strlen(json_encode($turn,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE))<=24000,'capability reply fits durable task result contract');

$base=['allowedArguments'=>[],'impact'=>'read','authorize'=>static fn():bool=>true,
    'validate'=>static fn():array=>[],'execute'=>static fn():array=>['ok'=>true,'data'=>[]]];
$run=static function(string $id,array $data,string $impact='read')use($owner,$refresh,$audit,$base):array{
    $tool=array_replace($base,['impact'=>$impact,'execute'=>static fn():array=>['ok'=>true,'data'=>$data]]);
    $step=['tool'=>$id,'arguments'=>[]];if($impact==='routine')$step['idempotencyKey']='observation-write-once';
    return fm8d2_run([$step],$owner,[$id=>$tool],['refreshActor'=>$refresh,'audit'=>$audit]);
};
hfo_check($run('unreviewed_read',['answer'=>str_repeat('x',4000)])['status']==='failed','unregistered observation policy keeps strict 3 KiB cap');
hfo_check(fm8d2_run([['tool'=>'not_in_catalogue','arguments'=>[]]],$owner,$catalogue,
    ['refreshActor'=>$refresh])['status']==='denied','unknown model tool never runs');
hfo_check($run('get_my_hours',['answer'=>'Verified','source'=>'Test','unexpected'=>str_repeat('x',8000)])['status']==='failed',
    'unexpected oversized fields are not silently discarded');
foreach([['answer'=>str_repeat('x',8000),'source'=>'Test','bad'=>NAN],
    ['answer'=>"invalid\xff",'source'=>'Test'],['answer'=>'Valid','source'=>'Test','bad'=>new stdClass()]] as $bad)
    hfo_check($run('get_my_hours',$bad)['status']==='failed','malformed observations fail before compaction');
$outcome=['answer'=>str_repeat('Write completed. ',400),'source'=>'Test','requiresConfirmation'=>false];
hfo_check($run('routine_changed',$outcome,'routine')['status']==='execution_unverified','large routine write outcome is not shortened into success');
hfo_check($run('propose_daily_prep_action',$outcome)['status']==='failed','proposal staging outcome is never generically shortened');
hfo_check($run('get_my_hours',$outcome)['status']==='failed','action-shaped result cannot enter a pure-read compactor');

$records=[];
for($i=0;$i<60;++$i)$records[]=['id'=>'record-'.$i,'name'=>'Café 食材 '.str_repeat('長',80),'note'=>str_repeat('Recorded note. ',30)];
$long=['answer'=>str_repeat('Saved customer facts. ',300),'source'=>'Gelato private fixture','records'=>$records];
$bounded=$run('read_crm_customer_profile',$long);
hfo_check($bounded['status']==='completed','composed long prose plus structured reads complete');
$observation=$bounded['steps'][0]['observation'];
$count=$observation['observationSummary']['collections']['records'];
hfo_check($count['retrieved']===60&&$count['included']<60&&$observation['truncated'],
    'row omission has truthful retrieved/included counts');
hfo_check($observation['records']===array_slice($records,0,$count['included']),
    'compaction keeps exact complete records, order, names and numeric values');
hfo_check(preg_match('//u',$observation['answer'])===1&&str_contains($observation['answer'],'shortened'),
    'encoded-byte truncation preserves Unicode and discloses omitted details');
hfo_check(strlen(json_encode($observation['answer'],JSON_THROW_ON_ERROR))<=3000,'ordinary terminal answers reserve room for four-read replies');
$executions=0;
$resumeTool=array_replace($base,['execute'=>static function()use(&$executions):array{
    ++$executions;return ['ok'=>true,'data'=>['answer'=>'Do not execute','source'=>'Test']];
}]);
$prior=[['index'=>0,'tool'=>'read_crm_customer_profile','status'=>'completed','observation'=>$long]];
$resume=fm8d2_run([['tool'=>'read_crm_customer_profile','arguments'=>[]]],$owner,
    ['read_crm_customer_profile'=>$resumeTool],['refreshActor'=>$refresh,'priorTrace'=>$prior]);
hfo_check($resume['status']==='denied'&&$executions===0,'oversized saved observations are rejected, never re-compacted or re-executed');
$prior[0]['observation']=$observation;
$denied=fm8d2_run([['tool'=>'read_crm_customer_profile','arguments'=>[]]],$owner,
    ['read_crm_customer_profile'=>array_replace($resumeTool,['authorize'=>static fn():bool=>false])],
    ['refreshActor'=>$refresh,'priorTrace'=>$prior]);
hfo_check($denied['status']==='denied'&&!$denied['steps'],'fresh authorization precedes private checkpoint reuse');

$calls=[];$batchCatalogue=[];
foreach(['read_crm_customer_profile','read_catering_operation','read_wholesale_order','read_wholesale_invoice'] as $id){
    $calls[]=['type'=>'function_call','call_id'=>$id,'name'=>$id,'arguments'=>'{}'];
    $batchCatalogue[$id]=array_replace($base,['description'=>'Local read fixture',
        'parameters'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],
        'execute'=>static fn():array=>['ok'=>true,'data'=>$long]]);
}
$batchCalls=0;
$batch=fm8d3_turn($pdo,$owner,'openai','test',[['role'=>'user','content'=>'Read four independent summaries.']],
    '',static function()use(&$batchCalls,$calls):array{++$batchCalls;return ['output'=>$calls];},
    $batchCatalogue,$refresh,$audit);
hfo_check(($batch['data']['status']??'')==='completed'&&$batchCalls===1,'four large reads combine locally after one selection request');
hfo_check(strlen(json_encode($batch,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE))<=24000,
    'worst-case four-read answer stays within unchanged journal result cap');
$selectionCalls=[['type'=>'function_call','call_id'=>'sel1','name'=>'get_need_more_vendor_options','arguments'=>'{}'],
    ['type'=>'function_call','call_id'=>'sel2','name'=>'get_current_user','arguments'=>'{}']];
$blocked=fm8d3_turn($pdo,$owner,'openai','test',[['role'=>'user','content'=>'Compare two lists.']],
    '',static fn():array=>['output'=>$selectionCalls],$catalogue,$refresh,$audit);
hfo_check(($blocked['data']['toolCalls']??-1)===0&&($blocked['data']['status']??'')==='needs_input'
    &&str_contains($blocked['answer'],'one Daily Prep'),'numbered read batching stops before execution with an actionable explanation');
echo 'AI-01 Food Monster observation contracts: '.$checks." checks passed.\n";
