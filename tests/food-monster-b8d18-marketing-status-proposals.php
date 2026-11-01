<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-marketing-status-proposals.php';
$checks=0;
function j6b_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J6B FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>30,'organization_id'=>12,'permissions'=>['packages.view','packages.manage']];
$viewer=['id'=>30,'organization_id'=>12,'permissions'=>['packages.view']];
$editor=['id'=>30,'organization_id'=>12,'permissions'=>['public_pages.edit','packages.manage']];
j6b_check(fm8d18j6b_catalogue($viewer)===[],'view permission alone cannot edit');
j6b_check(fm8d18j6b_catalogue($editor)===[],'site editor without package view cannot edit');
j6b_check(fm8d18j6b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice excluded');
j6b_check(isset(fm8d18j6b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true])['propose_marketing_package_status']),'approved voice eligible');
$noUser=$manager;$noUser['id']=0;
$noOrg=$manager;$noOrg['organization_id']=0;
j6b_check(fm8d18j6b_catalogue($noUser)===[],'signed-in user required');
j6b_check(fm8d18j6b_catalogue($noOrg)===[],'tenant required');
$tool=fm8d18j6b_catalogue($manager)['propose_marketing_package_status'];
j6b_check($tool['impact']==='read','staged proposal does not directly write');
j6b_check($tool['allowedArguments']===['package_public_id','package_name','action'],'no model-supplied tenant or override');
j6b_check(($tool['authorize'])($viewer,[])===false,'fresh permission check');
foreach(['activate','pause','resume','end','archive'] as $action)
    j6b_check(($tool['validate'])(['package_public_id'=>'package-123','package_name'=>'Holiday Family Deal','action'=>$action],$manager)===[],'accept known action '.$action);
foreach(['','a','package%','bad ID',str_repeat('x',121)] as $id)
    j6b_check(in_array('package_public_id',($tool['validate'])(['package_public_id'=>$id,'package_name'=>'Family Deal','action'=>'end'],$manager),true),'reject invalid ID');
foreach(['','  ','bad;name',"bad\nname",str_repeat('x',241)] as $name)
    j6b_check(in_array('package_name',($tool['validate'])(['package_public_id'=>'package-123','package_name'=>$name,'action'=>'end'],$manager),true),'reject invalid name');
foreach(['delete','publish','change_discount','',null] as $action)
    j6b_check(in_array('action',($tool['validate'])(['package_public_id'=>'package-123','package_name'=>'Family Deal','action'=>$action],$manager),true),'reject nonstatus action');
$allowed=['draft'=>['activate','end','archive'],'active'=>['pause','end','archive'],'paused'=>['activate','resume','end','archive'],'ended'=>['archive'],'archived'=>[]];
foreach($allowed as $status=>$actions)foreach(['activate','pause','resume','end','archive'] as $action)
    j6b_check(fm8d18j6b_state_allows($status,$action)===in_array($action,$actions,true),'state '.$status.' action '.$action);
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-marketing-status-proposals.php');
$specialist=file_get_contents(dirname(__DIR__).'/includes/marketing-agent-core.php');
j6b_check(str_contains($runtime,'fm8d18j6b_catalogue($principal,$context)'),'native tool registered');
j6b_check(str_contains($adapter,'WHERE organization_id=? AND public_id=?'),'exact tenant-scoped promotion query');
j6b_check(str_contains($adapter,'expectedUpdatedAt'),'snapshot version recorded');
j6b_check(str_contains($adapter,"gac_pending_store('marketing'"),'specialist pending confirmation used');
j6b_check(str_contains($adapter,'gaw_pending_action_node($org,$uid)'),'pending node collision rejected');
j6b_check(str_contains($specialist,"mac_guard_package($pdo,$org,$payload)"),'specialist rechecks unchanged package');
j6b_check(str_contains($specialist,"mac_require_package_manage($user)"),'specialist rechecks manage permission');
j6b_check(str_contains($specialist,'package_deal_set_status('),'specialist owns business rules');
j6b_check(!str_contains($adapter,'package_deal_set_status('),'model cannot write package status directly');
j6b_check(!str_contains($adapter,'crm_consent_set('),'model cannot alter consent');
echo "Food Monster B8D18J6B: $checks checks passed.\n";
