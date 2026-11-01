<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-marketing-feature-proposals.php';
$checks=0;
function j6c_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J6C FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>20,'organization_id'=>7,'permissions'=>['packages.view','packages.manage']];
$viewer=['id'=>20,'organization_id'=>7,'permissions'=>['packages.view']];
j6c_check(fm8d18j6c_catalogue($viewer)===[],'view-only user denied');
j6c_check(fm8d18j6c_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice denied');
j6c_check(isset(fm8d18j6c_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true])['propose_marketing_package_feature']),'approved voice eligible');
$noOrg=$manager;$noOrg['organization_id']=0;
j6c_check(fm8d18j6c_catalogue($noOrg)===[],'tenant required');
$tool=fm8d18j6c_catalogue($manager)['propose_marketing_package_feature'];
j6c_check($tool['impact']==='read','tool only stages proposal');
j6c_check($tool['allowedArguments']===['package_public_id','package_name','featured'],'no tenant or user override');
foreach([true,false] as $featured)
    j6c_check(($tool['validate'])(['package_public_id'=>'package-012','package_name'=>'Family Deal','featured'=>$featured],$manager)===[],'boolean featured accepted');
foreach(['true','false',1,0,null] as $featured)
    j6c_check(in_array('featured',($tool['validate'])(['package_public_id'=>'package-012','package_name'=>'Family Deal','featured'=>$featured],$manager),true),'nonboolean featured rejected');
foreach(['','x','package%','bad id',str_repeat('x',121)] as $id)
    j6c_check(in_array('package_public_id',($tool['validate'])(['package_public_id'=>$id,'package_name'=>'Family Deal','featured'=>true],$manager),true),'invalid ID denied');
foreach(['','  ',"bad\nname",'bad;name'] as $name)
    j6c_check(in_array('package_name',($tool['validate'])(['package_public_id'=>'package-012','package_name'=>$name,'featured'=>true],$manager),true),'invalid name denied');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-marketing-feature-proposals.php');
$specialist=file_get_contents(dirname(__DIR__).'/includes/marketing-agent-core.php');
j6c_check(str_contains($runtime,'fm8d18j6c_catalogue($principal,$context)'),'native tool registered');
j6c_check(str_contains($adapter,'WHERE organization_id=? AND public_id=?'),'scoped exact saved package');
j6c_check(str_contains($adapter,"(string)$"."row['name']!==trim("),'saved name must match');
j6c_check(str_contains($adapter,"$"."row['status']==='archived'"),'archived package rejected');
j6c_check(str_contains($adapter,'expectedUpdatedAt'),'version token recorded');
j6c_check(str_contains($adapter,"gac_pending_store('marketing'"),'pending Marketing Agent proposal');
j6c_check(str_contains($adapter,"'package_feature'"),'exact specialist feature type');
j6c_check(str_contains($specialist,'mac_guard_package($pdo,$org,$payload)'),'specialist rechecks exact record');
j6c_check(str_contains($specialist,'mac_require_package_manage($user)'),'specialist rechecks manage permission');
j6c_check(str_contains($specialist,"if($"."type==='package_feature')"),'specialist owns feature action');
j6c_check(!str_contains($adapter,'UPDATE package_deals'),'model tool cannot change Featured directly');
echo "Food Monster B8D18J6C: $checks checks passed.\n";
