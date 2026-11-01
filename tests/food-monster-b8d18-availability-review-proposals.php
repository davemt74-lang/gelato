<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-availability-review-proposals.php';
$checks=0;
function j4d_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J4D FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>5,'organization_id'=>12,'permissions'=>['schedule.agent','schedule.manage']];
$employee=['id'=>6,'organization_id'=>12,'permissions'=>['schedule.self','schedule.agent']];
j4d_check(fm8d18j4d_catalogue($employee)===[],'employee cannot review exceptions');
j4d_check(fm8d18j4d_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice blocked');
j4d_check(isset(fm8d18j4d_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true])['propose_review_availability_exception']),'approved voice eligible');
$tool=fm8d18j4d_catalogue($manager)['propose_review_availability_exception'];
j4d_check($tool['impact']==='read','model cannot directly change review state');
j4d_check($tool['allowedArguments']===['request_id','decision'],'no user or tenant model inputs');
foreach(['approved','rejected'] as $decision)
    j4d_check(($tool['validate'])(['request_id'=>'availability-123','decision'=>$decision],$manager)===[],'explicit decision');
foreach(['','bad id','../../test',str_repeat('a',121)] as $invalid){
    j4d_check(in_array('request_id',($tool['validate'])(['request_id'=>$invalid,'decision'=>'approved'],$manager),true),'invalid public ID rejected');
}
j4d_check(in_array('decision',($tool['validate'])(['request_id'=>'availability-123','decision'=>'cancelled'],$manager),true),'only approved/rejected decisions');
$noAgent=$manager;$noAgent['permissions']=['schedule.manage'];
j4d_check(fm8d18j4d_catalogue($noAgent)===[],'agent permission required');
$noOrg=$manager;$noOrg['organization_id']=0;
j4d_check(fm8d18j4d_catalogue($noOrg)===[],'tenant required');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$core=file_get_contents(dirname(__DIR__).'/includes/scheduling-agent-core.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-availability-review-proposals.php');
j4d_check(str_contains($runtime,'fm8d18j4d_catalogue($principal,$context)'),'model tool registered');
j4d_check(str_contains($adapter,"'availability_exception_review'"),'specialist pending proposal');
j4d_check(str_contains($adapter,'tv_voice_event_valid('),'spoken voice identity checked');
j4d_check(str_contains($adapter,"e.exception_type IN ('available','unavailable')"),'proposal excludes time off');
j4d_check(str_contains($adapter,"e.status='pending'"),'only pending proposal');
j4d_check(str_contains($core,"'availability_exception_review'"),'specialist Confirm branch');
j4d_check(str_contains($core,"exception_type IN ('available','unavailable')"),'confirmed update excludes time off');
j4d_check(str_contains($core,"status='pending' AND updated_at <=> ?"),'atomic stale state guard');
j4d_check(str_contains($core,'$q->rowCount()!==1'),'already reviewed request rejected');
j4d_check(str_contains($core,'$pdo->beginTransaction()'),'transactional manager review');
echo "Food Monster B8D18J4D: $checks checks passed.\n";
