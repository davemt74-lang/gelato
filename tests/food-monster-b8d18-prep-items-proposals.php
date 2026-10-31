<?php
declare(strict_types=1);
function app_has_permission(string $name,array $actor):bool
{
    return in_array($name,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-prep-items-proposals.php';
$checks=0;
function j_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J2B FAIL: $label\n");exit(1);}
    ++$checks;
}
function j_reject(callable $operation,string $label):void
{
    try{$operation();j_check(false,$label);}catch(InvalidArgumentException|DomainException){j_check(true,$label);}
}
$manager=['id'=>9,'organization_id'=>17,'permissions'=>['prep.intelligence.manage']];
$employee=['id'=>9,'organization_id'=>17,'permissions'=>['prep.intelligence.view']];
j_check(fm8d18j2b_catalogue($employee)===[],'employee cannot stage additions');
j_check(fm8d18j2b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved room voice blocked');
j_check(isset(fm8d18j2b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true])['propose_add_prep_items']),'approved voice eligible');
$tool=fm8d18j2b_catalogue($manager)['propose_add_prep_items'];
j_check($tool['impact']==='read','no model write');
$valid=['date'=>'2030-01-07','service'=>'dinner','items'=>[
    ['title'=>'dough','quantity'=>6,'unit'=>'pans'],
    ['title'=>'sauce','quantity'=>4.5,'unit'=>'containers']]];
j_check(($tool['validate'])($valid,$manager)===[],'two explicit valid items');
$payload=fm8d18j2b_payload($valid);
j_check(count($payload['items'])===2,'all items retained');
j_check($payload['items'][0]['unit']==='pan'&&$payload['items'][1]['unit']==='containers','units normalized without guessing');
j_check($payload['items'][1]['quantity']===4.5,'fractional quantities retained');
$bad=$valid;$bad['items'][0]['quantity']='6';
j_check(($tool['validate'])($bad,$manager)===['items'],'string quantity rejected');
$bad=$valid;$bad['items'][0]['quantity']=-1;
j_reject(static fn()=>fm8d18j2b_payload($bad),'negative quantity rejected');
$bad=$valid;$bad['items'][0]['unit']='';
j_reject(static fn()=>fm8d18j2b_payload($bad),'missing unit rejected');
$bad=$valid;$bad['items'][0]['title']='';
j_reject(static fn()=>fm8d18j2b_payload($bad),'missing title rejected');
$bad=$valid;$bad['items']=array_fill(0,31,$valid['items'][0]);
j_reject(static fn()=>fm8d18j2b_payload($bad),'over thirty items rejected');
$bad=$valid;$bad['date']='2020-01-01';
j_reject(static fn()=>fm8d18j2b_payload($bad),'past date rejected');
j_check(!in_array('user_id',$tool['allowedArguments'],true),'model cannot impersonate account');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-prep-items-proposals.php');
$specialist=file_get_contents(dirname(__DIR__).'/includes/prep-agent-core.php');
j_check(str_contains($runtime,'fm8d18j2b_catalogue($principal,$context)'),'native tool registered');
j_check(str_contains($runtime,"'propose_add_prep_items'"),'proposal cannot batch');
j_check(str_contains($adapter,"gac_pending_store('prep'"),'existing Prep Agent confirmation');
j_check(str_contains($specialist,"if(\$type==='add_items')"),'specialist handles actual item write');
j_check(str_contains($specialist,'pac_resolve_plan('),'version checked on confirm');
j_check(str_contains($specialist,'$pdo->beginTransaction()'),'confirmed item write transactional');
j_check(str_contains($adapter,'tv_voice_event_valid('),'private voice identity checked');
j_check(str_contains($adapter,'prep_plan_for_period('),'existing exact plan required');
echo "Food Monster B8D18J2B: $checks checks passed.\n";
