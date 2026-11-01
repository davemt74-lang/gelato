<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-marketing-tagline-proposals.php';
$checks=0;
function j6d_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J6D FAIL: $label\n");exit(1);}
    ++$checks;
}
$editor=['id'=>51,'organization_id'=>5,'permissions'=>['public_pages.edit']];
$owner=['id'=>51,'organization_id'=>5,'permissions'=>['settings.organization_edit']];
$viewer=['id'=>51,'organization_id'=>5,'permissions'=>['packages.view']];
j6d_check(fm8d18j6d_catalogue($viewer)===[],'package viewer cannot change public tagline');
j6d_check(isset(fm8d18j6d_catalogue($editor)['propose_public_site_tagline']),'site editor authorized');
j6d_check(isset(fm8d18j6d_catalogue($owner)['propose_public_site_tagline']),'organization editor authorized');
j6d_check(fm8d18j6d_catalogue($editor,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved private voice blocked');
j6d_check(isset(fm8d18j6d_catalogue($owner,['channel'=>'voice','privateVoiceApproved'=>true])['propose_public_site_tagline']),'approved private voice eligible');
$noOrg=$editor;$noOrg['organization_id']=0;
$noUser=$editor;$noUser['id']=0;
j6d_check(fm8d18j6d_catalogue($noOrg)===[],'tenant required');
j6d_check(fm8d18j6d_catalogue($noUser)===[],'user required');
$tool=fm8d18j6d_catalogue($editor)['propose_public_site_tagline'];
j6d_check($tool['impact']==='read','only pending proposal');
j6d_check($tool['allowedArguments']===['tagline'],'no model-supplied tenant, version or role');
j6d_check(($tool['authorize'])($viewer,[])===false,'fresh editor permission checked');
foreach(['Fresh made daily','Family recipes, community tables','Handmade gelato in Phoenix'] as $tagline)
    j6d_check(($tool['validate'])(['tagline'=>$tagline],$editor)===[],'plain text accepted');
foreach(['','  ',"one\ntwo",'<script>alert(1)</script>',str_repeat('x',256)] as $tagline)
    j6d_check(($tool['validate'])(['tagline'=>$tagline],$editor)===['tagline'],'unsafe tagline rejected');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-marketing-tagline-proposals.php');
$specialist=file_get_contents(dirname(__DIR__).'/includes/marketing-agent-core.php');
j6d_check(str_contains($runtime,'fm8d18j6d_catalogue($principal,$context)'),'tool registered');
j6d_check(str_contains($adapter,'mac_site_version($pdo,$org)'),'tenant-bound prior version read');
j6d_check(str_contains($adapter,'expectedTagline'),'prior tagline recorded');
j6d_check(str_contains($adapter,'expectedUpdatedAt'),'prior timestamp recorded');
j6d_check(str_contains($adapter,'gaw_pending_action_node($org,$uid)'),'existing pending action protected');
j6d_check(str_contains($adapter,"gac_pending_store('marketing'"),'specialist pending proposal');
j6d_check(str_contains($adapter,"'public_tagline'"),'exact specialist proposal type');
j6d_check(str_contains($specialist,'mac_require_public_edit($user)'),'specialist rechecks public editing permission');
j6d_check(str_contains($specialist,'mac_site_version($pdo,$org)'),'specialist rechecks unchanged setting');
j6d_check(str_contains($specialist,"if($"."type==='public_tagline')"),'specialist handles tagline');
j6d_check(!str_contains($adapter,'INSERT INTO public_site_settings'),'model does not write public settings');
j6d_check(!str_contains($adapter,'crm_consent_set('),'no marketing consent change');
echo "Food Monster B8D18J6D: $checks checks passed.\n";
