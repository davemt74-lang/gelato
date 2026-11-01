<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-catering-task-proposals.php';
$checks=0;
function j7b_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J7B FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>11,'organization_id'=>4,'permissions'=>['catering.view','catering.agent','catering.manage']];
$viewer=['id'=>11,'organization_id'=>4,'permissions'=>['catering.view','catering.agent']];
j7b_check(fm8d18j7b_catalogue($viewer)===[],'manage permission required');
j7b_check(fm8d18j7b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice denied');
j7b_check(count(fm8d18j7b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true]))===2,'approved voice exposes both tools');
$noOrg=$manager;$noOrg['organization_id']=0;
$noUser=$manager;$noUser['id']=0;
j7b_check(fm8d18j7b_catalogue($noOrg)===[],'tenant required');
j7b_check(fm8d18j7b_catalogue($noUser)===[],'user required');
$tools=fm8d18j7b_catalogue($manager);$add=$tools['propose_catering_task_add'];$status=$tools['propose_catering_task_status'];
j7b_check($add['impact']==='read'&&$status['impact']==='read','both are proposal-only');
j7b_check($add['allowedArguments']===['operation_public_id','operation_title','task_title'],'no tenant/user injection in task add');
j7b_check($status['allowedArguments']===['operation_public_id','operation_title','task_id','task_title','status'],'status requires exact saved task');
j7b_check(($add['authorize'])($viewer,[])===false,'fresh manage permission checked');
$args=['operation_public_id'=>'operation-123','operation_title'=>'Wedding Catering','task_title'=>'Pack serving trays'];
j7b_check(($add['validate'])($args,$manager)===[],'valid task add');
foreach(['','x','bad%','bad ID',str_repeat('a',121)] as $id)
    j7b_check(in_array('operation_public_id',($add['validate'])(['operation_public_id'=>$id,'operation_title'=>'Wedding','task_title'=>'Pack food'],$manager),true),'bad operation ID rejected');
foreach(['','  ',"bad\nname",str_repeat('a',241)] as $title)
    j7b_check(in_array('task_title',($add['validate'])(['operation_public_id'=>'operation-123','operation_title'=>'Wedding','task_title'=>$title],$manager),true),'invalid task rejected');
foreach(['open','in_progress','blocked','done','cancelled'] as $state)
    j7b_check(($status['validate'])(['operation_public_id'=>'operation-123','operation_title'=>'Wedding','task_id'=>54,'task_title'=>'Pack food','status'=>$state],$manager)===[],'valid task state');
foreach([0,-1,'54',null] as $id)
    j7b_check(in_array('task_id',($status['validate'])(['operation_public_id'=>'operation-123','operation_title'=>'Wedding','task_id'=>$id,'task_title'=>'Pack food','status'=>'done'],$manager),true),'bad task ID rejected');
foreach(['ready','complete','archive','',null] as $state)
    j7b_check(in_array('status',($status['validate'])(['operation_public_id'=>'operation-123','operation_title'=>'Wedding','task_id'=>5,'task_title'=>'Pack food','status'=>$state],$manager),true),'unsupported state rejected');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-catering-task-proposals.php');
$specialist=file_get_contents(dirname(__DIR__).'/includes/catering-agent-actions.php');
j7b_check(str_contains($runtime,'fm8d18j7b_catalogue($principal,$context)'),'model tools registered');
j7b_check(str_contains($adapter,'catering_operation_row($pdo,$org,'),'exact operation tenant-bound');
j7b_check(str_contains($adapter,'caa_operation_writable($operation)'),'completed/cancelled events blocked');
j7b_check(str_contains($adapter,'caa_task_row($pdo,$org,'),'task scoped by event and tenant');
j7b_check(str_contains($adapter,'expectedUpdatedAt'),'task status version saved');
j7b_check(str_contains($adapter,"gac_pending_store('catering'"),'existing confirmation store used');
j7b_check(str_contains($adapter,'gaw_pending_action_node($org,$uid)'),'pending conflict blocked');
j7b_check(str_contains($specialist,"caa_require($user,'catering.manage'"),'specialist rechecks permission');
j7b_check(str_contains($specialist,"if($"."type==='task_add')"),'specialist handles add');
j7b_check(str_contains($specialist,"if($"."type==='task_status')"),'specialist handles status');
j7b_check(!str_contains($adapter,'UPDATE restaurant_operation_tasks'),'model tool cannot directly update tasks');
j7b_check(!str_contains($adapter,'INSERT INTO restaurant_operation_tasks'),'model tool cannot directly insert tasks');
echo "Food Monster B8D18J7B: $checks checks passed.\n";
