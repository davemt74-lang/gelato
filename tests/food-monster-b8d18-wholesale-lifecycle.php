<?php
declare(strict_types=1);

function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-lifecycle-reads.php';
require dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-lifecycle-proposals.php';

$checks=0;
function j9_check(bool $condition,string $label):void
{
    global $checks;
    if(!$condition){fwrite(STDERR,"B8D18J9 FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>10,'organization_id'=>3,'permissions'=>['wholesale.view','wholesale.agent','wholesale.manage']];
$reader=$manager;$reader['permissions']=['wholesale.view','wholesale.agent'];
$visitor=$manager;$visitor['permissions']=['wholesale.view'];
j9_check(count(fm8d18j9a_catalogue($manager))===3,'three read tools registered');
j9_check(count(fm8d18j9b_catalogue($manager))===4,'four proposal tools registered');
j9_check(count(fm8d18j9a_catalogue($reader))===3,'authorized reader has quote/order reads');
j9_check(fm8d18j9b_catalogue($reader)===[],'reader cannot propose manager writes');
j9_check(fm8d18j9a_catalogue($visitor)===[],'wholesale.agent is required for reads');
j9_check(fm8d18j9a_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unverified voice cannot read private records');
j9_check(fm8d18j9b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unverified voice cannot propose writes');
j9_check(count(fm8d18j9b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true]))===4,'approved voice can propose');
foreach(fm8d18j9a_catalogue($manager) as $tool){
    j9_check(($tool['impact']??null)==='read','reads have read impact');
    j9_check(($tool['authorize'])($visitor,[])===false,'read tool rechecks permission');
}
foreach(fm8d18j9b_catalogue($manager) as $tool){
    j9_check(($tool['impact']??null)==='read','proposals stage only');
    j9_check(($tool['authorize'])($reader,[])===false,'proposal rechecks manager permission');
    j9_check(($tool['parameters']['additionalProperties']??null)===false,'proposals reject extra arguments');
}
$base=['order_public_id'=>'worder-ci','order_number'=>'W-001','business_name'=>'CI Cafe'];
$cases=[
    'propose_wholesale_order_confirmation'=>$base+['saved_total'=>180.0,'acknowledge_saved_total'=>true],
    'propose_wholesale_order_cancellation'=>$base+['reason'=>'Buyer withdrew before production','acknowledge_cancel'=>true],
    'propose_wholesale_promised_window'=>$base+['promised_start'=>'2026-12-03T09:30','promised_end'=>'2026-12-03T10:30'],
    'propose_wholesale_order_location'=>$base+['location_public_id'=>'wloc-ci','location_name'=>'Main'],
];
foreach($cases as $name=>$args){
    $tool=fm8d18j9b_catalogue($manager)[$name];
    j9_check(($tool['validate'])($args,$manager)===[],$name.' accepts exact arguments');
    foreach(array_keys($args) as $field){
        $bad=$args;unset($bad[$field]);
        j9_check(count(($tool['validate'])($bad,$manager))>0,$name.' requires '.$field);
    }
}
$wrong=$cases['propose_wholesale_order_confirmation'];$wrong['acknowledge_saved_total']=false;
j9_check(($tool=fm8d18j9b_catalogue($manager)['propose_wholesale_order_confirmation'])['validate']($wrong,$manager)!==[],'total consent cannot be false');
$wrong=$cases['propose_wholesale_order_cancellation'];$wrong['acknowledge_cancel']=false;
j9_check((fm8d18j9b_catalogue($manager)['propose_wholesale_order_cancellation']['validate'])($wrong,$manager)!==[],'cancel consent cannot be false');
$wrong=$cases['propose_wholesale_promised_window'];$wrong['promised_start']='tomorrow morning';
j9_check((fm8d18j9b_catalogue($manager)['propose_wholesale_promised_window']['validate'])($wrong,$manager)!==[],'promise requires exact local datetime');
j9_check(fm8d18j9a_quote_fields(['quote_public_id'=>'wquote-ci','section'=>'events'])===[],'quote events exact read');
j9_check(fm8d18j9a_order_fields(['order_public_id'=>'worder-ci','section'=>'plan'])===[],'order plan exact read');

$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$core=file_get_contents(dirname(__DIR__).'/includes/wholesale-agent-core.php');
$actions=file_get_contents(dirname(__DIR__).'/includes/wholesale-order-lifecycle-actions.php');
$start=strpos($runtime,'if(count($calls)>1){');$end=$start===false?false:strpos($runtime,'    $plan=[];',$start);
$guard=$start!==false&&$end!==false?substr($runtime,$start,$end-$start):'';
j9_check(str_contains($runtime,'fm8d18j9a_catalogue($principal,$context)'),'read catalogue wired');
j9_check(str_contains($runtime,'fm8d18j9b_catalogue($principal,$context)'),'proposal catalogue wired');
foreach(array_keys($cases) as $name)j9_check(str_contains($guard,"'".$name."'"),$name.' cannot batch');
j9_check(str_contains($core,'return wol_execute_pending('),'specialist Confirm dispatches');
j9_check(str_contains($actions,'wholesale_fulfillment_order($pdo,$org,(string)($payload[\'orderPublicId\']??\'\'),true)'),'specialist locks order');
j9_check(str_contains($actions,'wol_expected($order,'),'specialist checks stale order');
j9_check(str_contains($actions,'wol_no_batches($pdo,$org,$id)'),'specialist blocks existing batches');
j9_check(str_contains($actions,'wholesale_invoices'),'specialist blocks invoices');
j9_check(str_contains($actions,'wholesale_fulfillment_save_order_plan('),'canonical plan service used');
j9_check(!str_contains(file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-lifecycle-proposals.php'),'UPDATE wholesale_orders'),'model proposal has no direct order write');
j9_check(str_contains($actions,'$pdo->rollBack()'),'failed confirmation rolls back');
echo "Food Monster B8D18J9: $checks checks passed.\n";
