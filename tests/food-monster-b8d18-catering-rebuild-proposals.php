<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-catering-rebuild-proposals.php';
$checks=0;
function j7d_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J7D FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>14,'organization_id'=>7,'permissions'=>['catering.view','catering.agent','catering.manage']];
$viewer=['id'=>14,'organization_id'=>7,'permissions'=>['catering.view','catering.agent']];
j7d_check(fm8d18j7d_catalogue($viewer)===[],'manage permission required');
j7d_check(fm8d18j7d_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice excluded');
j7d_check(isset(fm8d18j7d_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true])['propose_catering_ingredient_rebuild']),'approved private voice eligible');
$noOrg=$manager;$noOrg['organization_id']=0;
j7d_check(fm8d18j7d_catalogue($noOrg)===[],'tenant required');
$tool=fm8d18j7d_catalogue($manager)['propose_catering_ingredient_rebuild'];
j7d_check($tool['impact']==='read','only stages proposal');
j7d_check($tool['allowedArguments']===['operation_public_id','operation_title','reset_existing_statuses'],'no tenant or user override');
j7d_check(($tool['authorize'])($viewer,[])===false,'fresh manage permission checked');
$input=['operation_public_id'=>'operation-123','operation_title'=>'Wedding','reset_existing_statuses'=>true];
j7d_check(($tool['validate'])($input,$manager)===[],'explicit reset acknowledgement accepted');
foreach([false,0,1,'true','yes',null] as $value){
    $input['reset_existing_statuses']=$value;
    j7d_check(in_array('reset_existing_statuses',($tool['validate'])($input,$manager),true),'non-explicit acknowledgement denied');
}
$input['reset_existing_statuses']=true;
foreach(['','x','bad%','bad ID',str_repeat('x',121)] as $id){
    $input['operation_public_id']=$id;
    j7d_check(in_array('operation_public_id',($tool['validate'])($input,$manager),true),'invalid event ID rejected');
}
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-catering-rebuild-proposals.php');
$specialist=file_get_contents(dirname(__DIR__).'/includes/catering-agent-actions.php');
$core=file_get_contents(dirname(__DIR__).'/includes/catering-operations.php');
j7d_check(str_contains($runtime,'fm8d18j7d_catalogue($principal,$context)'),'registered tool');
j7d_check(str_contains($adapter,'fm8d18j7b_operation($pdo,$actor,$args)'),'exact writable tenant operation required');
j7d_check(str_contains($adapter,'recipe_id'),'recipe-linked menu required');
j7d_check(str_contains($adapter,'DELETE and REPLACE'),'explicit destructive reset warning');
j7d_check(str_contains($adapter,'manual notes'),'warns notes loss');
j7d_check(str_contains($adapter,'resetAcknowledged'),'separate confirmation carries acknowledgement');
j7d_check(str_contains($adapter,'menuInputsSignature'),'menu plus recipe snapshot stored');
j7d_check(str_contains($adapter,'requirementsSignature'),'current ingredient snapshot stored');
j7d_check(str_contains($adapter,"fm8d18j7b_stage(\$actor,'requirements_rebuild'"),'uses existing Catering Agent pending confirmation');
j7d_check(str_contains($specialist,'function caa_rebuild_inputs_signature('),'recipe and menu content fingerprint');
j7d_check(str_contains($specialist,'function caa_requirements_signature('),'current ingredient rows fingerprint');
j7d_check(str_contains($specialist,'resetAcknowledged'),'specialist requires acknowledged reset');
j7d_check(str_contains($specialist,'FOR UPDATE'),'specialist locks state before destructive rebuild');
j7d_check(str_contains($specialist,'hash_equals('),'specialist rechecks signatures before rebuild');
j7d_check(str_contains($specialist,'rollBack()'),'failed regeneration rolls back');
j7d_check(str_contains($core,'DELETE FROM restaurant_operation_requirements'),'underlying reset is destructive');
j7d_check(!str_contains($adapter,'DELETE FROM restaurant_operation_requirements'),'model tool does not delete directly');
echo "Food Monster B8D18J7D: $checks checks passed.\n";
