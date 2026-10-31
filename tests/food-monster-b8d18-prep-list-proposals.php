<?php
declare(strict_types=1);
function app_has_permission(string $name,array $actor):bool
{
    return in_array($name,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-prep-list-proposals.php';
$checks=0;
function p_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J2A FAIL: $label\n");exit(1);}
    ++$checks;
}
function p_reject(callable $operation,string $label):void
{
    try{$operation();p_check(false,$label);}catch(InvalidArgumentException|DomainException){p_check(true,$label);}
}
$manager=['id'=>9,'organization_id'=>17,'permissions'=>['prep.intelligence.manage']];
$employee=['id'=>9,'organization_id'=>17,'permissions'=>['prep.intelligence.view']];
p_check(fm8d18j2a_can($manager),'authorized manager');
p_check(!fm8d18j2a_can($employee),'read-only employee cannot create list');
p_check(fm8d18j2a_catalogue($employee)===[],'no employee write tool');
p_check(fm8d18j2a_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'room voice blocked');
p_check(isset(fm8d18j2a_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true])['propose_create_prep_list']),'approved voice tool exposed');
$tool=fm8d18j2a_catalogue($manager)['propose_create_prep_list'];
p_check($tool['impact']==='read','model can only stage a proposal');
p_check(($tool['validate'])([],$manager)===['date','service'],'required exact inputs');
p_check(($tool['validate'])(['date'=>'2030-01-07','service'=>'invalid'],$manager)===['service'],'service enum validated');
p_check(($tool['validate'])(['date'=>['2030-01-07'],'service'=>'all'],$manager)===['date'],'nested date blocked');
p_check(fm8d18j2a_payload(['date'=>'2030-01-07','service'=>'morning'])===['date'=>'2030-01-07','service'=>'morning'],'canonical payload');
p_reject(static fn()=>fm8d18j2a_payload(['date'=>'2030-02-30','service'=>'all']),'invalid date rejected');
p_reject(static fn()=>fm8d18j2a_payload(['date'=>'2020-01-01','service'=>'all']),'past date rejected');
p_check(!in_array('organization_id',$tool['allowedArguments'],true),'model cannot select tenant');
p_check(!in_array('user_id',$tool['allowedArguments'],true),'model cannot impersonate requester');
p_check(!in_array('items',$tool['allowedArguments'],true),'empty-list tool cannot invent prep items');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-prep-list-proposals.php');
$specialist=file_get_contents(dirname(__DIR__).'/includes/prep-agent-core.php');
p_check(str_contains($runtime,'fm8d18j2a_catalogue($principal,$context)'),'model tool registered');
p_check(str_contains($runtime,"'propose_create_prep_list'"),'must be a separate tool turn');
p_check(str_contains($adapter,"gac_pending_store('prep'"),'existing prep confirmation owns proposal');
p_check(str_contains($specialist,"if(\$type==='create_plan')"),'prep specialist confirms actual creation');
p_check(str_contains($specialist,"pac_require(\$user,'prep.intelligence.manage'"),'permission freshly checked by specialist');
p_check(str_contains($adapter,'tv_voice_event_valid('),'private voice event freshly checked');
p_check(str_contains($adapter,'prep_plan_for_period('),'duplicate plan preflight');
echo "Food Monster B8D18J2A: $checks checks passed.\n";
