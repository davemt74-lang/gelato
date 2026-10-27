<?php
declare(strict_types=1);

function app_has_permission(string $name,array $actor):bool
{
    return in_array('*',(array)($actor['permissions']??[]),true)
        ||in_array($name,(array)($actor['permissions']??[]),true);
}

if(!function_exists('mb_substr')){
    function mb_substr(string $s,int $offset,?int $length=null,?string $encoding=null):string{
        return substr($s,$offset,$length);
    }
}
require dirname(__DIR__).'/includes/food-monster-llm.php';
require dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php';

$assertions=0;
function b8d17a_check(bool $ok,string $label):void
{
    global $assertions;
    if(!$ok){fwrite(STDERR,"B8D17A FAIL: $label\n");exit(1);}
    ++$assertions;
}

$employee=['id'=>5,'organization_id'=>21,'role_slug'=>'employee','permissions'=>[]];
$employeeReport=fm8d17a_snapshot($employee);
b8d17a_check(($employeeReport['schemaVersion']??'')==='1.0.0','stable structured contract');
b8d17a_check(($employeeReport['inventoryVisibility']??'')==='withheld_for_this_account','staff cannot list privileged proposed actions');
b8d17a_check($employeeReport['planned']===[],'staff proposed action inventory withheld');
b8d17a_check(array_key_exists('plannedNotExecutable',$employeeReport['counts'])
    &&$employeeReport['counts']['plannedNotExecutable']===null,'staff does not receive privileged count');
b8d17a_check(in_array('get_current_user',array_column($employeeReport['nativeTools'],'id'),true),'authenticated identity read shown');
b8d17a_check(!in_array('get_employee_schedule',array_column($employeeReport['nativeTools'],'id'),true),'staff cannot discover ungranted scheduling read');
b8d17a_check(!in_array('scheduling',array_column($employeeReport['delegatedAgents'],'id'),true),'ungranted agent handoff absent');
b8d17a_check(($employeeReport['readiness']['provider']??'')==='not_tested_by_capability_snapshot','provider is not falsely certified');
b8d17a_check(($employeeReport['readiness']['voiceBargeIn']??'')==='requires_device_acceptance','device voice status accurately pending');

$manager=['id'=>9,'organization_id'=>21,'role_slug'=>'manager',
    'permissions'=>['schedule.view','schedule.agent','schedule.manage']];
$managerReport=fm8d17a_snapshot($manager);
b8d17a_check(in_array('get_day_roster',array_column($managerReport['nativeTools'],'id'),true),'manager scheduling tool registered');
b8d17a_check(in_array('scheduling',array_column($managerReport['delegatedAgents'],'id'),true),'manager scheduling agent registered');
b8d17a_check(($managerReport['counts']['nativeRegistered']??0)>($employeeReport['counts']['nativeRegistered']??0),'tool count genuinely permission scoped');
b8d17a_check(!str_contains(json_encode($managerReport), 'schedule.shift_save'),'manager does not get privileged source-only inventory');

$executions=0;
$fakeTools=['example'=>['impact'=>'routine','execute'=>static function()use(&$executions){++$executions;}]];
$fakeDomains=['example'=>['label'=>'Example operations','mode'=>'read_confirmed_write']];
$fakePrompts=[['id'=>'demo','label'=>'Demo prompt']];
$candidates=['status'=>'source_inventory_only','items'=>[
    ['id'=>'proposed.privileged','label'=>'Never granted','feature'=>'admin_settings',
     'status'=>'proposed_not_executable','executionVerified'=>false],
]];
$report=fm8d17a_snapshot($manager,[],$fakeTools,$fakeDomains,$fakePrompts,$candidates);
b8d17a_check($executions===0,'report never calls execute callbacks');
b8d17a_check($report['nativeTools'][0]['status']==='registered_for_current_account','native registration not execution');
b8d17a_check($report['nativeTools'][0]['executionVerified']===false,'native execution unverified');
b8d17a_check($report['delegatedAgents'][0]['destinationPolicyRequired']===true,'destination separately authorizes');
b8d17a_check($report['chatPrompts'][0]['status']==='prompt_template_only','chat prompts are nonexecuting');
b8d17a_check($report['planned']===[],'injected privileged candidates withheld for manager');
b8d17a_check($report['inventoryStatus']==='not_requested','no unneeded inventory read for manager');

$owner=array_replace($manager,['role_slug'=>'super_admin','permissions'=>['*']]);
$ownerReport=fm8d17a_snapshot($owner);
b8d17a_check(($ownerReport['inventoryStatus']??'')==='source_inventory_only','source inventory remains discovery only');
b8d17a_check(count($ownerReport['planned'])===73,'all 73 audited proposed contracts included');
b8d17a_check(in_array('schedule.shift_save',array_column($ownerReport['planned'],'id'),true),'priority schedule candidate visible to owner');
b8d17a_check(in_array('vision.dataset_create',array_column($ownerReport['planned'],'id'),true),'priority vision candidate visible to owner');
b8d17a_check(count(array_filter($ownerReport['planned'],
    static fn(array $x):bool=>$x['status']!=='proposed_not_executable'))===0,'no proposed contract accidentally granted');
b8d17a_check(count(array_filter($ownerReport['nativeTools'],
    static fn(array $x):bool=>$x['executionVerified']!==false))===0,'no native runtime falsely marked proven');
b8d17a_check(count(array_filter($ownerReport['delegatedAgents'],
    static fn(array $x):bool=>$x['executionVerified']!==false))===0,'no delegated agent falsely marked proven');
b8d17a_check(str_contains(fm8d17a_summary($managerReport),'Scheduling'),'LLM reply names scoped specialist');
b8d17a_check(str_contains(fm8d17a_summary($managerReport),'not proof'),'LLM disclosure is truthful');
b8d17a_check(!str_contains(json_encode($ownerReport),'encrypted_key'),'report never returns credential material');

try{fm8d17a_snapshot(array_replace($manager,['id'=>0]));b8d17a_check(false,'anonymous principal');}
catch(DomainException){b8d17a_check(true,'anonymous principal blocked');}
try{fm8d17a_snapshot(array_replace($manager,['organization_id'=>0]));b8d17a_check(false,'missing organization');}
catch(DomainException){b8d17a_check(true,'missing organization blocked');}

$api=(string)file_get_contents(dirname(__DIR__).'/api/agent-workspace.php');
$canvas=(string)file_get_contents(dirname(__DIR__).'/agent-canvas.php');
$js=(string)file_get_contents(dirname(__DIR__).'/js/agent-canvas.js');
b8d17a_check(str_contains($api,"'food_monster_capability_truth'"),'live authenticated Agent Workspace route');
b8d17a_check(str_contains($api,'fm8d17a_snapshot($user)'),'live route uses authenticated user');
b8d17a_check(str_contains($canvas,'capabilityTruthButton'),'canvas displays explicit action');
b8d17a_check(str_contains($js,'food_monster_capability_truth'),'canvas fetches status on demand');
b8d17a_check(str_contains($js,'esc(label)')&&str_contains($js,'esc(value)'),
    'capability UI escapes untrusted labels and values');
echo "Food Monster B8D17A: $assertions assertions passed.\n";
