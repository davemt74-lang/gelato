<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-availability-proposals.php';
$checks=0;
function j4b_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J4B FAIL: $label\n");exit(1);}
    ++$checks;
}
$employee=['id'=>9,'organization_id'=>17,'permissions'=>['schedule.agent','schedule.self']];
$manager=['id'=>8,'organization_id'=>17,'permissions'=>['schedule.agent','schedule.manage']];
j4b_check(fm8d18j4b_catalogue($manager)===[],'manager without personal permission excluded');
j4b_check(fm8d18j4b_catalogue($employee,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice hidden');
j4b_check(isset(fm8d18j4b_catalogue($employee,['channel'=>'voice','privateVoiceApproved'=>true])['propose_set_my_weekday_availability']),'approved private voice eligible');
$tool=fm8d18j4b_catalogue($employee)['propose_set_my_weekday_availability'];
j4b_check($tool['impact']==='read','model only proposes');
j4b_check($tool['allowedArguments']===['weekday','start','end','type'],'no model account or org selection');
$valid=['weekday'=>1,'start'=>'09:00','end'=>'17:30','type'=>'available'];
j4b_check(($tool['validate'])($valid,$employee)===[],'exact Monday range');
foreach([0,1,2,3,4,5,6] as $day){$args=$valid;$args['weekday']=$day;j4b_check(($tool['validate'])($args,$employee)===[],'valid day '.$day);}
foreach([-1,7,'1',1.5] as $day){$args=$valid;$args['weekday']=$day;j4b_check(in_array('weekday',($tool['validate'])($args,$employee),true),'invalid day');}
foreach(['25:00','09:60','9:00','Monday'] as $time){$args=$valid;$args['start']=$time;j4b_check(in_array('start',($tool['validate'])($args,$employee),true),'invalid start');}
$args=$valid;$args['end']='09:00';
j4b_check(in_array('end',($tool['validate'])($args,$employee),true),'zero window rejected');
$args=$valid;$args['end']='08:59';
j4b_check(in_array('end',($tool['validate'])($args,$employee),true),'backwards window rejected');
$args=$valid;$args['type']='maybe';
j4b_check(in_array('type',($tool['validate'])($args,$employee),true),'type required');
$invalid=$employee;$invalid['id']=0;
j4b_check(fm8d18j4b_catalogue($invalid)===[],'signed-in account required');
$invalid=$employee;$invalid['permissions']=['schedule.self'];
j4b_check(fm8d18j4b_catalogue($invalid)===[],'Scheduling Agent permission required');
$a=[['id'=>1,'weekday'=>1,'start_time'=>'09:00:00','end_time'=>'12:00:00','availability_type'=>'available']];
$b=$a;$b[0]['end_time']='13:00:00';
j4b_check(fm8d18j4b_fingerprint($a)!==fm8d18j4b_fingerprint($b),'changed window invalidates proposal');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$core=file_get_contents(dirname(__DIR__).'/includes/scheduling-agent-core.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-availability-proposals.php');
j4b_check(str_contains($runtime,'fm8d18j4b_catalogue($principal,$context)'),'model tool registered');
j4b_check(str_contains($adapter,"'self_availability_day'"),'Scheduling Agent pending action used');
j4b_check(str_contains($adapter,'tv_voice_event_valid('),'voice identity checked');
j4b_check(str_contains($core,'fm8d18j4b_apply('),'specialist executes on Confirm');
j4b_check(str_contains($adapter,'FOR UPDATE'),'transaction locks membership and windows');
j4b_check(str_contains($adapter,'fm8d18j4b_fingerprint($current)'),'stale-record guard');
j4b_check(str_contains($adapter,'WHERE organization_id=? AND user_id=? AND weekday=?'),'only selected weekday deleted');
j4b_check(str_contains($adapter,'$pdo->beginTransaction()'),'transactional write');
j4b_check(str_contains($adapter,'$pdo->rollBack()'),'atomic rollback on failure');
echo "Food Monster B8D18J4B: $checks checks passed.\n";
