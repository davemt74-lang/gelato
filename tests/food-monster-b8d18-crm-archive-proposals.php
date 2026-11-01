<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-crm-archive-proposals.php';
$checks=0;
function j5c_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J5C FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>12,'organization_id'=>7,'permissions'=>['crm.agent','crm.view','crm.manage']];
$viewer=['id'=>12,'organization_id'=>7,'permissions'=>['crm.view']];
j5c_check(fm8d18j5c_catalogue($viewer)===[],'CRM management and Agent access mandatory');
j5c_check(fm8d18j5c_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved private voice rejected');
j5c_check(isset(fm8d18j5c_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true])['propose_crm_customer_archive']),'authorized private voice eligible');
$noOrg=$manager;$noOrg['organization_id']=0;
$noUser=$manager;$noUser['id']=0;
j5c_check(fm8d18j5c_catalogue($noOrg)===[],'signed-in tenant required');
j5c_check(fm8d18j5c_catalogue($noUser)===[],'signed-in user required');
$tool=fm8d18j5c_catalogue($manager)['propose_crm_customer_archive'];
j5c_check($tool['impact']==='read','tool only creates pending proposal');
j5c_check($tool['allowedArguments']===['customer_public_id','customer_name'],'no client-supplied tenant/override');
j5c_check(($tool['authorize'])($viewer,[])===false,'recheck CRM manager permissions');
j5c_check(($tool['authorize'])($manager,[])===true,'authorized manager');
j5c_check(($tool['validate'])(['customer_public_id'=>'customer-a7b9','customer_name'=>'Alex Chen'],$manager)===[],'exact profile and name accepted');
foreach(['','x','customer%','customer*','bad name','customer@example.com',str_repeat('x',121)] as $id)
    j5c_check(in_array('customer_public_id',($tool['validate'])(['customer_public_id'=>$id,'customer_name'=>'Alex Chen'],$manager),true),'invalid ID rejected');
foreach(['','   ','Alex; DROP','Alex'."\n".'Chen',str_repeat('z',241)] as $name)
    j5c_check(in_array('customer_name',($tool['validate'])(['customer_public_id'=>'customer-123','customer_name'=>$name],$manager),true),'invalid customer name rejected');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-crm-archive-proposals.php');
$specialist=file_get_contents(dirname(__DIR__).'/includes/customer-crm-agent-core.php');
$existing=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-crm-proposals.php');
j5c_check(str_contains($runtime,'fm8d18j5c_catalogue($principal,$context)'),'archive tool model-registered');
j5c_check(str_contains($adapter,'crm_customer_row($pdo,$org,'),'lookup scoped by organization');
j5c_check(str_contains($adapter,"$"."customer['status']"),'active-only guard');
j5c_check(str_contains($adapter,"$"."customer['display_name']!==trim("),'exact name matches the saved profile');
j5c_check(str_contains($adapter,"'updatedAt'=>(string)$"."customer['updated_at']"),'version token recorded');
j5c_check(str_contains($adapter,"fm8d18d_propose(\$actor,'archive_customer'"),'stages existing CRM pending proposal');
j5c_check(str_contains($existing,"gac_pending_store('crm'"),'proposal uses secure pending confirmation');
j5c_check(str_contains($specialist,'cac_guard_customer($pdo,$org,$public'),'confirmation rechecks unchanged active row');
j5c_check(str_contains($specialist,"cac_require(\$user,'crm.manage'"),'confirmation rechecks manager permission');
j5c_check(str_contains($specialist,"if(\$type==='archive_customer')"),'specialist handles exact proposal type');
j5c_check(!str_contains($adapter,'crm_customer_archive('),'model never archives directly');
j5c_check(!str_contains($adapter,'crm_consent_set('),'model never changes marketing consent');
echo "Food Monster B8D18J5C: $checks checks passed.\n";
