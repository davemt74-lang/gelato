<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-allocation.php';
$checks=0;
function j8b_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J8B FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>10,'organization_id'=>3,'permissions'=>['wholesale.view','wholesale.agent','wholesale.manage']];
$viewer=$manager;$viewer['permissions']=['wholesale.view','wholesale.agent'];
j8b_check(fm8d18j8b_catalogue($viewer)===[],'management required');
j8b_check(fm8d18j8b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice excluded');
j8b_check(count(fm8d18j8b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true]))===1,'approved voice proposal available');
$tool=fm8d18j8b_catalogue($manager)['propose_wholesale_allocate_remaining'];
j8b_check($tool['impact']==='read','only stages proposal');
j8b_check(($tool['authorize'])($viewer,[])===false,'permission rechecked');
$valid=['order_public_id'=>'worder-42','order_number'=>'WO-2026-0042','business_name'=>'Market Street','all_remaining'=>true];
j8b_check(($tool['validate'])($valid,$manager)===[],'valid exact order');
foreach(['order_public_id','order_number','business_name','all_remaining'] as $field){
    $bad=$valid;unset($bad[$field]);
    j8b_check(in_array($field,($tool['validate'])($bad,$manager),true),'missing '.$field.' blocked');
}
foreach(['','x','bad id','%'] as $id){
    $bad=$valid;$bad['order_public_id']=$id;
    j8b_check(in_array('order_public_id',($tool['validate'])($bad,$manager),true),'invalid saved ID');
}
$bad=$valid;$bad['all_remaining']=false;
j8b_check(in_array('all_remaining',($tool['validate'])($bad,$manager),true),'broad allocation requires explicit true');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-allocation.php');
j8b_check(str_contains($runtime,'fm8d18j8b_catalogue($principal,$context)'),'model catalogue wired');
j8b_check(str_contains($runtime,"'propose_wholesale_allocate_remaining'],true)"),'proposal excludes batching');
j8b_check(str_contains($adapter,"gac_pending_store('wholesale'"),'specialist pending action used');
j8b_check(str_contains($adapter,"'create_remaining_batch'"),'existing specialist action used');
j8b_check(str_contains($adapter,'waa_progress_signature($progress)'),'stale allocation signature captured');
j8b_check(str_contains($adapter,'waa_order_version($order)'),'stale order version captured');
j8b_check(!str_contains($adapter,'wholesale_fulfillment_create_batch('),'model never creates batch');
echo "Food Monster B8D18J8B: $checks checks passed.\n";
