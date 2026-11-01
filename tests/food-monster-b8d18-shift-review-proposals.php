<?php
declare(strict_types=1);
function app_has_permission(string $name,array $actor):bool
{
    return in_array($name,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-shift-review-proposals.php';
$checks=0;
function j3b_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J3B FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>7,'organization_id'=>12,'permissions'=>['schedule.agent','schedule.manage']];
$employee=['id'=>8,'organization_id'=>12,'permissions'=>['schedule.agent','schedule.self']];
j3b_check(fm8d18j3b_catalogue($employee)===[],'employee cannot review shift requests');
j3b_check(fm8d18j3b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice hidden');
j3b_check(isset(fm8d18j3b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true])['propose_review_shift_request']),'approved voice eligible');
$tool=fm8d18j3b_catalogue($manager)['propose_review_shift_request'];
j3b_check($tool['impact']==='read','model cannot directly reassign shift');
j3b_check($tool['allowedArguments']===['request_id','decision','note'],'no model-supplied user or tenant');
foreach(['approved','rejected'] as $decision)
    j3b_check(($tool['validate'])(['request_id'=>'shift-request-123','decision'=>$decision],$manager)===[],'valid decision '.$decision);
foreach(['','bad id','foo/../bar',str_repeat('x',121)] as $bad)
    j3b_check(in_array('request_id',($tool['validate'])(['request_id'=>$bad,'decision'=>'approved'],$manager),true),'reject invalid ID');
j3b_check(in_array('decision',($tool['validate'])(['request_id'=>'shift-request-1','decision'=>'cancelled'],$manager),true),'only approve/reject');
j3b_check(in_array('note',($tool['validate'])(['request_id'=>'shift-request-1','decision'=>'approved','note'=>str_repeat('a',1001)],$manager),true),'note length bounded');
$other=$manager;$other['organization_id']=0;
j3b_check(fm8d18j3b_catalogue($other)===[],'tenant mandatory');
$other=$manager;$other['permissions']=['schedule.manage'];
j3b_check(fm8d18j3b_catalogue($other)===[],'Scheduling Agent permission mandatory');
$row=['request_type'=>'swap','updated_at'=>'v1','shift_updated_at'=>'v1','requester_name'=>'A','target_name'=>'B'];
$before=fm8d18j3b_fingerprint($row);$row['requester_name']='Another display';
j3b_check($before===fm8d18j3b_fingerprint($row),'display names excluded from fingerprint');
$row['shift_updated_at']='v2';
j3b_check($before!==fm8d18j3b_fingerprint($row),'shift version changes fingerprint');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$core=file_get_contents(dirname(__DIR__).'/includes/scheduling-agent-core.php');
$scheduling=file_get_contents(dirname(__DIR__).'/includes/scheduling-core.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-shift-review-proposals.php');
j3b_check(str_contains($runtime,'fm8d18j3b_catalogue($principal,$context)'),'native model registration');
j3b_check(str_contains($adapter,'sac_pending_store('),'specialist proposal not direct write');
j3b_check(str_contains($adapter,'tv_voice_event_valid('),'private voice identity checked');
j3b_check(str_contains($adapter,"r.status='pending'"),'pending request preflight');
j3b_check(str_contains($adapter,'shift_updated_at'),'shift version snapshot');
j3b_check(str_contains($adapter,'proposed_updated_at'),'second shift version snapshot');
j3b_check(str_contains($core,"'shift_request_review'"),'specialist confirmation branch');
j3b_check(str_contains($core,'fm8d18j3b_fingerprint($current)'),'confirm rechecks fingerprint');
j3b_check(str_contains($core,'scheduling_request_review($pdo,$org,'),'existing specialist executes decision');
j3b_check(str_contains($scheduling,"AND status='pending'"),'concurrent review status guard');
j3b_check(str_contains($scheduling,'$review->rowCount()!==1'),'concurrent review rollback guard');
echo "Food Monster B8D18J3B: $checks checks passed.\n";
