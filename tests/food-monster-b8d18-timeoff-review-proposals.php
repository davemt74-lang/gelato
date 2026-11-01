<?php
declare(strict_types=1);
function app_has_permission(string $name,array $actor):bool
{
    return in_array($name,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-timeoff-review-proposals.php';
$checks=0;
function j3a_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J3A FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>7,'organization_id'=>12,'permissions'=>['schedule.agent','schedule.manage']];
$employee=['id'=>8,'organization_id'=>12,'permissions'=>['schedule.agent','schedule.self']];
j3a_check(fm8d18j3a_catalogue($employee)===[],'employee cannot review time off');
j3a_check(fm8d18j3a_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice hidden');
j3a_check(isset(fm8d18j3a_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true])['propose_review_time_off_request']),'approved voice eligible');
$tool=fm8d18j3a_catalogue($manager)['propose_review_time_off_request'];
j3a_check($tool['impact']==='read','model cannot write');
j3a_check($tool['allowedArguments']===['request_id','decision'],'no forged user or tenant');
foreach(['approved','rejected'] as $decision)
    j3a_check(($tool['validate'])(['request_id'=>'availability-123','decision'=>$decision],$manager)===[],'valid explicit decision '.$decision);
foreach(['','bad id','foo/../bar',str_repeat('x',121)] as $bad)
    j3a_check(in_array('request_id',($tool['validate'])(['request_id'=>$bad,'decision'=>'approved'],$manager),true),'reject invalid id');
j3a_check(in_array('decision',($tool['validate'])(['request_id'=>'availability-123','decision'=>'accepted'],$manager),true),'only reviewed statuses allowed');
$other=$manager;$other['organization_id']=0;
j3a_check(fm8d18j3a_catalogue($other)===[],'valid tenant mandatory');
$other=$manager;$other['permissions']=['schedule.manage'];
j3a_check(fm8d18j3a_catalogue($other)===[],'agent permission mandatory');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$core=file_get_contents(dirname(__DIR__).'/includes/scheduling-agent-core.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-timeoff-review-proposals.php');
j3a_check(str_contains($runtime,'fm8d18j3a_catalogue($principal,$context)'),'model tool registered');
j3a_check(str_contains($adapter,"sac_pending_store($org,$uid,'time_off_review'"),'specialist proposal only');
j3a_check(str_contains($adapter,'tv_voice_event_valid('),'voice identity rechecked');
j3a_check(str_contains($adapter,"e.status='pending'"),'proposal checks pending state');
j3a_check(str_contains($adapter,"e.exception_type='time_off'"),'not other exception types');
j3a_check(str_contains($core,"if($type==='time_off_review')"),'specialist confirm branch');
j3a_check(str_contains($core,"status='pending' AND updated_at <=> ?"),'atomic stale version guard');
j3a_check(str_contains($core,"'schedule.manage'"),'fresh management permission');
j3a_check(str_contains($core,'$q->rowCount()!==1'),'concurrent review rejected');
j3a_check(str_contains($core,'$pdo->beginTransaction()'),'transactional review');
echo "Food Monster B8D18J3A: $checks checks passed.\n";
