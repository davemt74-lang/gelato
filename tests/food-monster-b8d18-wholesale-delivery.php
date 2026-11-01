<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-delivery.php';
$checks=0;
function j8d_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J8D FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>10,'organization_id'=>3,'permissions'=>['wholesale.view','wholesale.agent','wholesale.manage','inventory.manage']];
$without=$manager;$without['permissions']=['wholesale.view','wholesale.agent','wholesale.manage'];
j8d_check(fm8d18j8d_catalogue($without)===[],'inventory.manage mandatory');
j8d_check(fm8d18j8d_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice excluded');
j8d_check(count(fm8d18j8d_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true]))===1,'approved voice permitted');
$tool=fm8d18j8d_catalogue($manager)['propose_wholesale_batch_delivery'];
j8d_check($tool['impact']==='read','delivery tool stages only');
j8d_check(($tool['authorize'])($without,[])===false,'inventory permission rechecked');
$args=['order_public_id'=>'worder-42','order_number'=>'WO-42','batch_public_id'=>'wful-123','batch_number'=>'WO-42-F01','consume_inventory'=>true];
j8d_check(($tool['validate'])($args,$manager)===[],'exact batch and consent');
foreach(array_keys($args) as $field){
    $bad=$args;unset($bad[$field]);
    j8d_check(in_array($field,($tool['validate'])($bad,$manager),true),'missing '.$field.' denied');
}
$bad=$args;$bad['consume_inventory']=false;
j8d_check(in_array('consume_inventory',($tool['validate'])($bad,$manager),true),'inventory acknowledgement required');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-delivery.php');
$core=file_get_contents(dirname(__DIR__).'/includes/wholesale-agent-core.php');
j8d_check(str_contains($runtime,'fm8d18j8d_catalogue($principal,$context)'),'catalogue registered');
j8d_check(str_contains($runtime,"'propose_wholesale_batch_delivery'],true)"),'delivery cannot batch');
j8d_check(str_contains($adapter,"'batch_deliver'"),'existing specialist proposal');
j8d_check(str_contains($adapter,'waa_delivery_signature($availability)'),'requirements snapshot captured');
j8d_check(str_contains($core,"isset(\$payload['requirementsSignature'])"),'specialist rechecks only new proposals');
j8d_check(str_contains($core,'hash_equals('),'snapshot checked safely');
j8d_check(str_contains($core,'wholesale_fulfillment_deliver('),'specialist runs existing inventory transaction');
j8d_check(!str_contains($adapter,'wholesale_fulfillment_deliver('),'model does not deduct inventory');
echo "Food Monster B8D18J8D: $checks checks passed.\n";
