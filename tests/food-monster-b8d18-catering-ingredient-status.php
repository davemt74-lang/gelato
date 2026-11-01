<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-catering-ingredient-status.php';
$checks=0;
function j7c_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J7C FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>12,'organization_id'=>9,'permissions'=>['catering.view','catering.agent','catering.manage']];
$viewer=['id'=>12,'organization_id'=>9,'permissions'=>['catering.view','catering.agent']];
j7c_check(fm8d18j7c_catalogue($viewer)===[],'manage required');
j7c_check(fm8d18j7c_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved private voice denied');
j7c_check(isset(fm8d18j7c_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true])['propose_catering_ingredient_status']),'approved voice eligible');
$noOrg=$manager;$noOrg['organization_id']=0;
j7c_check(fm8d18j7c_catalogue($noOrg)===[],'tenant required');
$tool=fm8d18j7c_catalogue($manager)['propose_catering_ingredient_status'];
j7c_check($tool['impact']==='read','proposal-only impact');
j7c_check($tool['allowedArguments']===['operation_public_id','operation_title','requirement_id','ingredient_name','status'],'no model-supplied tenant/user');
j7c_check(($tool['authorize'])($viewer,[])===false,'fresh permission check');
foreach(['planned','ordered','ready','unavailable'] as $state)
    j7c_check(($tool['validate'])(['operation_public_id'=>'operation-123','operation_title'=>'Wedding','requirement_id'=>8,'ingredient_name'=>'Tomatoes','status'=>$state],$manager)===[],'valid ingredient status');
foreach(['cancelled','done','used','',null] as $state)
    j7c_check(in_array('status',($tool['validate'])(['operation_public_id'=>'operation-123','operation_title'=>'Wedding','requirement_id'=>8,'ingredient_name'=>'Tomatoes','status'=>$state],$manager),true),'unsupported status rejected');
foreach([0,-1,'8',null] as $id)
    j7c_check(in_array('requirement_id',($tool['validate'])(['operation_public_id'=>'operation-123','operation_title'=>'Wedding','requirement_id'=>$id,'ingredient_name'=>'Tomatoes','status'=>'ready'],$manager),true),'invalid requirement ID rejected');
foreach(['','  ',"bad\nname",str_repeat('x',221)] as $name)
    j7c_check(in_array('ingredient_name',($tool['validate'])(['operation_public_id'=>'operation-123','operation_title'=>'Wedding','requirement_id'=>8,'ingredient_name'=>$name,'status'=>'ready'],$manager),true),'invalid name denied');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-catering-ingredient-status.php');
$specialist=file_get_contents(dirname(__DIR__).'/includes/catering-agent-actions.php');
j7c_check(str_contains($runtime,'fm8d18j7c_catalogue($principal,$context)'),'registered model tool');
j7c_check(str_contains($adapter,'caa_requirement_row($pdo,$org,'),'requirement lookup tenant and event scoped');
j7c_check(str_contains($adapter,'caa_operation_writable($operation)')===false,'writable gate reused in shared operation helper');
j7c_check(str_contains($adapter,'fm8d18j7b_operation($pdo,$actor,$args)'),'uses shared event writable/identity gate');
j7c_check(str_contains($adapter,"(string)$"."row['ingredient_name']!==trim("),'exact ingredient identity checked');
j7c_check(str_contains($adapter,'expectedUpdatedAt'),'stale requirement version captured');
j7c_check(str_contains($adapter,"fm8d18j7b_stage($actor,'requirement_status'"),'existing confirmation staging');
j7c_check(str_contains($specialist,'caa_requirement_row($pdo,$org,$operationId,'),'specialist rechecks exact requirement');
j7c_check(str_contains($specialist,'expectedUpdatedAt'),'specialist rejects changed requirement');
j7c_check(!str_contains($adapter,'UPDATE restaurant_operation_requirements'),'model tool does not write requirements');
j7c_check(!str_contains($adapter,'catering_operations_rebuild_requirements('),'no destructive rebuild');
echo "Food Monster B8D18J7C: $checks checks passed.\n";
