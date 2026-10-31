<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-prep-shortages-read.php';
$checks=0;
function j2c_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J2C FAIL: $label\n");exit(1);}
    ++$checks;
}
$actor=['id'=>8,'organization_id'=>4,'permissions'=>['prep.intelligence.view','prep.intelligence.agent','inventory.forecast.view']];
$tool=fm8d18j2c_catalogue($actor)['get_prep_shortages'];
j2c_check($tool['impact']==='read','read-only tool');
j2c_check($tool['allowedArguments']===['date','service'],'no tenant or user identity parameters');
j2c_check(($tool['validate'])(['date'=>'2030-02-28','service'=>'lunch'],$actor)===[],'valid exact period');
foreach(['2030-02-30','2027-13-01','tomorrow','2030-2-1',''] as $bad)
    j2c_check(in_array('date',($tool['validate'])(['date'=>$bad,'service'=>'lunch'],$actor),true),'bad date rejected: '.$bad);
j2c_check(in_array('service',($tool['validate'])(['date'=>'2030-02-28','service'=>'brunch'],$actor),true),'unknown service rejected');
$without=$actor;$without['permissions']=['prep.intelligence.view','prep.intelligence.agent'];
j2c_check(fm8d18j2c_catalogue($without)===[],'forecast permission required');
$without=$actor;$without['permissions']=['inventory.view','prep.intelligence.view'];
j2c_check(fm8d18j2c_catalogue($without)===[],'agent permission required');
$without=$actor;$without['organization_id']=0;
j2c_check(fm8d18j2c_catalogue($without)===[],'tenant scope required');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-prep-shortages-read.php');
j2c_check(str_contains($runtime,'fm8d18j2c_catalogue($principal)'),'registered model tool');
j2c_check(str_contains($adapter,'prep_plan_for_period($pdo,$org,'),'tenant-bounded exact plan lookup');
j2c_check(str_contains($adapter,'prep_plan_detail($pdo,$org,'),'tenant-bounded detail lookup');
j2c_check(str_contains($adapter,"'forecastAsOf'=>'stored'"),'saved forecast identified');
j2c_check(!str_contains($adapter,'gac_pending_store('),'read never stages write');
echo "Food Monster B8D18J2C: $checks checks passed.\n";
