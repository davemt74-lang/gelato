<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-crm-search.php';
$checks=0;
function j5a_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J5A FAIL: $label\n");exit(1);}
    ++$checks;
}
$viewer=['id'=>9,'organization_id'=>17,'permissions'=>['crm.view']];
$employee=['id'=>9,'organization_id'=>17,'permissions'=>['tasks.view']];
j5a_check(fm8d18j5a_catalogue($employee)===[],'no CRM read permission');
j5a_check(fm8d18j5a_catalogue($viewer,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice cannot search customer records');
j5a_check(isset(fm8d18j5a_catalogue($viewer,['channel'=>'voice','privateVoiceApproved'=>true])['search_crm_customers']),'approved voice eligible');
$tool=fm8d18j5a_catalogue($viewer)['search_crm_customers'];
j5a_check($tool['impact']==='read','read-only impact');
j5a_check($tool['allowedArguments']===['query'],'no model-supplied tenant or user');
foreach(['David Evans','5551234','test@example.com'] as $query)
    j5a_check(($tool['validate'])(['query'=>$query],$viewer)===[],'accepted query '.$query);
foreach(['','x','%','Jo_n','Joe%','Robert; DROP',str_repeat('a',121)] as $query)
    j5a_check(($tool['validate'])(['query'=>$query],$viewer)===['query'],'unsafe or vague query rejected');
$noOrg=$viewer;$noOrg['organization_id']=0;
j5a_check(fm8d18j5a_catalogue($noOrg)===[],'tenant mandatory');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-crm-search.php');
j5a_check(str_contains($runtime,'fm8d18j5a_catalogue($principal,$context)'),'model tool registered');
j5a_check(str_contains($adapter,'crm_search($pdo,$org,$query,8,false)'),'tenant-scoped existing CRM search used');
j5a_check(str_contains($adapter,"'publicId'"),'public IDs returned');
j5a_check(!str_contains($adapter,"'email'=>"),'no contact emails returned');
j5a_check(!str_contains($adapter,"'phone'=>"),'no contact phones returned');
j5a_check(str_contains($adapter,"'truncated'"),'limit disclosure');
j5a_check(!str_contains($adapter,'crm_customer_save('),'no direct write path');
echo "Food Monster B8D18J5A: $checks checks passed.\n";
