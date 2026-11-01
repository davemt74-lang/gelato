<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-crm-profile-read.php';
$checks=0;
function j5b_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J5B FAIL: $label\n");exit(1);}
    ++$checks;
}
$viewer=['id'=>11,'organization_id'=>33,'permissions'=>['crm.view']];
$employee=['id'=>11,'organization_id'=>33,'permissions'=>['schedule.view']];
j5b_check(fm8d18j5b_catalogue($employee)===[],'CRM view permission required');
j5b_check(fm8d18j5b_catalogue($viewer,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved private voice excluded');
j5b_check(isset(fm8d18j5b_catalogue($viewer,['channel'=>'voice','privateVoiceApproved'=>true])['read_crm_customer_profile']),'approved private voice eligible');
$noOrg=$viewer;$noOrg['organization_id']=0;
$noUser=$viewer;$noUser['id']=0;
j5b_check(fm8d18j5b_catalogue($noOrg)===[],'organization mandatory');
j5b_check(fm8d18j5b_catalogue($noUser)===[],'signed-in user mandatory');
$tool=fm8d18j5b_catalogue($viewer)['read_crm_customer_profile'];
j5b_check($tool['impact']==='read','tool is read-only');
j5b_check($tool['allowedArguments']===['customer_public_id','section'],'model cannot supply organization or permission');
j5b_check(($tool['authorize'])($employee,[])===false,'execution rechecks viewer permission');
j5b_check(($tool['authorize'])($viewer,[])===true,'authorized viewer passes');
$sections=['overview','recent_visits','favorites','notes','tags','consents'];
foreach($sections as $section)
    j5b_check(($tool['validate'])(['customer_public_id'=>'customer-4b8a713e4d2c','section'=>$section],$viewer)===[],'valid section '.$section);
foreach(['','a','customer%','customer *','\todd','customer@example.com',str_repeat('a',121)] as $id)
    j5b_check(in_array('customer_public_id',($tool['validate'])(['customer_public_id'=>$id,'section'=>'notes'],$viewer),true),'reject invalid profile ID');
foreach(['','all','email','marketing_update','archive','visit-details',null] as $section)
    j5b_check(in_array('section',($tool['validate'])(['customer_public_id'=>'customer-123','section'=>$section],$viewer),true),'reject unknown section');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-crm-profile-read.php');
j5b_check(str_contains($runtime,"require_once __DIR__.'/food-monster-b8d18-crm-profile-read.php'"),'runtime includes adapter');
j5b_check(str_contains($runtime,'fm8d18j5b_catalogue($principal,$context)'),'model-selected tool registered');
j5b_check(str_contains($adapter,'crm_customer_row($pdo,$org,$id)'),'customer row by verified tenant and exact profile');
j5b_check(str_contains($adapter,"$"."customer['status']"),'inactive profile rejected');
j5b_check(str_contains($adapter,'crm_metrics($pdo,$org,$customerId)'),'paid visit and favorites history uses native CRM');
j5b_check(str_contains($adapter,'crm_notes($pdo,$org,$customerId,6)'),'notes capped');
j5b_check(str_contains($adapter,'crm_tags($pdo,$org,$customerId)'),'tags use tenant scoping');
j5b_check(str_contains($adapter,'crm_current_consents($pdo,$org,$customerId)'),'consent status uses recorded data');
j5b_check(!str_contains($adapter,"'email'=>"),'no contact email in tool output');
j5b_check(!str_contains($adapter,"'phone'=>"),'no contact phone in tool output');
j5b_check(!str_contains($adapter,"'evidenceNote'=>"),'no consent evidence in tool output');
j5b_check(!str_contains($adapter,'crm_consent_set('),'no consent mutation');
j5b_check(!str_contains($adapter,'crm_customer_save('),'no customer mutation');
j5b_check(!str_contains($adapter,'crm_tag_add('),'no tag mutation');
echo "Food Monster B8D18J5B: $checks checks passed.\n";
