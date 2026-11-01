<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-availability-exception-proposals.php';
$checks=0;
function j4c_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J4C FAIL: $label\n");exit(1);}
    ++$checks;
}
function j4c_reject(callable $call,string $label):void
{
    try{$call();j4c_check(false,$label);}catch(InvalidArgumentException|DomainException){j4c_check(true,$label);}
}
$employee=['id'=>9,'organization_id'=>17,'permissions'=>['schedule.agent','schedule.self']];
$manager=['id'=>8,'organization_id'=>17,'permissions'=>['schedule.agent','schedule.manage']];
j4c_check(fm8d18j4c_catalogue($manager)===[],'no manager impersonation through self tool');
j4c_check(fm8d18j4c_catalogue($employee,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice hidden');
j4c_check(isset(fm8d18j4c_catalogue($employee,['channel'=>'voice','privateVoiceApproved'=>true])['propose_my_availability_exception']),'approved voice eligible');
$tool=fm8d18j4c_catalogue($employee)['propose_my_availability_exception'];
j4c_check($tool['impact']==='read','no direct model write');
j4c_check(!in_array('user_id',$tool['allowedArguments'],true),'model cannot name other employee');
$future=(new DateTimeImmutable('today',new DateTimeZone('America/Phoenix')))->modify('+7 days')->format('Y-m-d');
$full=['date'=>$future,'type'=>'unavailable','all_day'=>true];
j4c_check(($tool['validate'])($full,$employee)===[],'valid full day');
$partial=['date'=>$future,'type'=>'available','all_day'=>false,'start'=>'13:00','end'=>'17:30'];
j4c_check(($tool['validate'])($partial,$employee)===[],'valid partial day');
j4c_check(fm8d18j4c_payload($partial)['end']==='17:30','partial time retained');
foreach(['2030-02-30','2030-13-01','tomorrow'] as $bad){
    $args=$full;$args['date']=$bad;
    j4c_check(in_array('date',($tool['validate'])($args,$employee),true),'invalid date');
}
$args=$full;$args['start']='09:00';
j4c_check(in_array('start/end',($tool['validate'])($args,$employee),true),'full-day time ambiguity rejected');
$args=$partial;unset($args['end']);
j4c_check(in_array('end',($tool['validate'])($args,$employee),true),'partial end required');
$args=$partial;$args['end']='11:00';
j4c_check(in_array('end',($tool['validate'])($args,$employee),true),'partial backwards time rejected');
$args=$full;$args['type']='time_off';
j4c_check(in_array('type',($tool['validate'])($args,$employee),true),'time off delegated to existing tool');
$args=$full;$args['all_day']='true';
j4c_check(in_array('all_day',($tool['validate'])($args,$employee),true),'explicit boolean required');
$args=$full;$args['date']='2020-01-01';
j4c_reject(static fn()=>fm8d18j4c_payload($args),'past dates rejected');
$noPermission=$employee;$noPermission['permissions']=['schedule.self'];
j4c_check(fm8d18j4c_catalogue($noPermission)===[],'Scheduling Agent permission required');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$core=file_get_contents(dirname(__DIR__).'/includes/scheduling-agent-core.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-availability-exception-proposals.php');
j4c_check(str_contains($runtime,'fm8d18j4c_catalogue($principal,$context)'),'model tool registered');
j4c_check(str_contains($adapter,"'availability_exception_request'"),'specialist proposal path');
j4c_check(str_contains($adapter,'tv_voice_event_valid('),'voice identity checked');
j4c_check(str_contains($adapter,'status IN (\'pending\',\'approved\')'),'duplicate request check');
j4c_check(str_contains($core,'fm8d18j4c_apply('),'specialist Confirm path');
j4c_check(str_contains($adapter,'scheduling_exception_create($pdo,$org,$uid,'),'existing exception handler');
j4c_check(str_contains($adapter,'],\$uid,false)')===false,'no forged manager approval call');
j4c_check(str_contains($adapter,'$uid,false);'),'request is pending not manager auto-approved');
j4c_check(str_contains($adapter,'FOR UPDATE'),'member row lock on confirm');
j4c_check(str_contains($adapter,'$pdo->beginTransaction()'),'transactional confirmed request');
echo "Food Monster B8D18J4C: $checks checks passed.\n";
