<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-availability-read.php';
$checks=0;
function j4a_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J4A FAIL: $label\n");exit(1);}
    ++$checks;
}
$employee=['id'=>9,'organization_id'=>17,'permissions'=>['schedule.self']];
$manager=['id'=>9,'organization_id'=>17,'permissions'=>['schedule.view','schedule.manage']];
j4a_check(fm8d18j4a_catalogue($manager)===[],'manager view alone does not reveal personal record');
j4a_check(fm8d18j4a_catalogue($employee,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice cannot read personal records');
j4a_check(isset(fm8d18j4a_catalogue($employee,['channel'=>'voice','privateVoiceApproved'=>true])['get_my_availability']),'approved voice eligible');
$tool=fm8d18j4a_catalogue($employee)['get_my_availability'];
j4a_check($tool['impact']==='read','no write impact');
j4a_check($tool['allowedArguments']===['status'],'model cannot choose other user or tenant');
foreach(['all','pending','approved','rejected'] as $status)
    j4a_check(($tool['validate'])(['status'=>$status],$employee)===[],'supported status '.$status);
j4a_check(($tool['validate'])([],$employee)===[],'default all status');
j4a_check(($tool['validate'])(['status'=>'cancelled'],$employee)===['status'],'unknown status rejected');
$noOrg=$employee;$noOrg['organization_id']=0;
j4a_check(fm8d18j4a_catalogue($noOrg)===[],'tenant identity required');
$noUser=$employee;$noUser['id']=0;
j4a_check(fm8d18j4a_catalogue($noUser)===[],'authenticated account required');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-availability-read.php');
j4a_check(str_contains($runtime,'fm8d18j4a_catalogue($principal,$context)'),'registered model tool');
j4a_check(str_contains($adapter,'scheduling_availability($pdo,$org,$uid)'),'self recurring lookup');
j4a_check(str_contains($adapter,'scheduling_exceptions($pdo,$org,$uid,'),'self exception lookup');
j4a_check(str_contains($adapter,"'readOnly'=>true"),'explicit read-only observation');
j4a_check(!str_contains($adapter,'scheduling_availability_replace('),'no availability write path');
j4a_check(!str_contains($adapter,'sac_pending_store('),'no pending mutation');
echo "Food Monster B8D18J4A: $checks checks passed.\n";
