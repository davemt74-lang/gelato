<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-marketing-search.php';
$checks=0;
function j6a_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J6A FAIL: $label\n");exit(1);}
    ++$checks;
}
$viewer=['id'=>30,'organization_id'=>12,'permissions'=>['packages.view']];
$manager=['id'=>30,'organization_id'=>12,'permissions'=>['public_pages.edit']];
j6a_check(fm8d18j6a_catalogue($manager)===[],'site editor without package view cannot browse promotions');
j6a_check(fm8d18j6a_catalogue($viewer,['channel'=>'voice','privateVoiceApproved'=>false])===[],'private voice approval required');
j6a_check(isset(fm8d18j6a_catalogue($viewer,['channel'=>'voice','privateVoiceApproved'=>true])['search_marketing_packages']),'approved private voice');
$noUser=$viewer;$noUser['id']=0;
$noOrg=$viewer;$noOrg['organization_id']=0;
j6a_check(fm8d18j6a_catalogue($noUser)===[],'authenticated user required');
j6a_check(fm8d18j6a_catalogue($noOrg)===[],'organization required');
$tool=fm8d18j6a_catalogue($viewer)['search_marketing_packages'];
j6a_check($tool['impact']==='read','read-only impact');
j6a_check($tool['allowedArguments']===['query'],'model cannot choose tenant or limit');
j6a_check(($tool['authorize'])($manager,[])===false,'fresh user permission checked');
foreach(['Family Pack','Winter Deal','package-123'] as $query)
    j6a_check(($tool['validate'])(['query'=>$query],$viewer)===[],'safe query accepted');
foreach(['','X','%','ab_','test; DROP',str_repeat('x',101),"bad\nquery"] as $query)
    j6a_check(($tool['validate'])(['query'=>$query],$viewer)===['query'],'unsafe query rejected');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-marketing-search.php');
j6a_check(str_contains($runtime,'fm8d18j6a_catalogue($principal,$context)'),'native tool registered');
j6a_check(str_contains($adapter,'WHERE organization_id=?'),'SQL scoped to signed-in organization');
j6a_check(str_contains($adapter,'LIMIT 9'),'bounded results with truncation check');
j6a_check(str_contains($adapter,'LOCATE(LOWER(?),LOWER(name))'),'literal matching, no wildcard expansion');
j6a_check(str_contains($adapter,"'publicId'"),'exact saved IDs included');
j6a_check(!str_contains($adapter,"'email'=>"),'no contact email in output');
j6a_check(!str_contains($adapter,'package_deal_set_status('),'no promotion status write');
j6a_check(!str_contains($adapter,'gac_pending_store('),'read only, no proposal');
echo "Food Monster B8D18J6A: $checks checks passed.\n";
