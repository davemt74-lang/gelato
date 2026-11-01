<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-status.php';
$checks=0;
function j8c_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J8C FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>10,'organization_id'=>3,'permissions'=>['wholesale.view','wholesale.agent','wholesale.manage']];
$viewer=$manager;$viewer['permissions']=['wholesale.view','wholesale.agent'];
j8c_check(fm8d18j8c_catalogue($viewer)===[],'management required');
j8c_check(fm8d18j8c_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice excluded');
j8c_check(count(fm8d18j8c_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true]))===1,'approved voice action available');
$tool=fm8d18j8c_catalogue($manager)['propose_wholesale_batch_transition'];
j8c_check($tool['impact']==='read','proposal only');
j8c_check(($tool['authorize'])($viewer,[])===false,'permission rechecked');
$args=['order_public_id'=>'worder-42','order_number'=>'WO-42','batch_public_id'=>'wful-123','batch_number'=>'WO-42-F01','transition'=>'ready'];
foreach(['ready','dispatch','cancel'] as $status){
    $candidate=$args;$candidate['transition']=$status;
    j8c_check(($tool['validate'])($candidate,$manager)===[],'valid transition '.$status);
}
foreach(['order_public_id','order_number','batch_public_id','batch_number','transition'] as $field){
    $bad=$args;unset($bad[$field]);
    j8c_check(in_array($field,($tool['validate'])($bad,$manager),true),'missing '.$field.' denied');
}
foreach(['delivered','cancelled','dispatched','',null] as $status){
    $bad=$args;$bad['transition']=$status;
    j8c_check(in_array('transition',($tool['validate'])($bad,$manager),true),'no unsafe transition');
}
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$guardStart=strpos($runtime,'if(count($calls)>1){');
$guardEnd=$guardStart===false?false:strpos($runtime,'    $plan=[];',$guardStart);
$guard=$guardStart!==false&&$guardEnd!==false?substr($runtime,$guardStart,$guardEnd-$guardStart):'';

$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-status.php');
j8c_check(str_contains($runtime,'fm8d18j8c_catalogue($principal,$context)'),'runtime registered');
j8c_check(str_contains($guard,"'propose_wholesale_batch_transition'"),'unbatched proposal');
j8c_check(str_contains($adapter,"'batch_ready'")&&str_contains($adapter,"'batch_dispatch'")&&str_contains($adapter,"'batch_cancel'"),'existing specialist actions');
j8c_check(str_contains($adapter,'waa_batch_version($batch)'),'stale batch guard stored');
j8c_check(str_contains($adapter,'order_status'),'production gate present');
j8c_check(!str_contains($adapter,'wholesale_fulfillment_set_status('),'no direct model writes');
echo "Food Monster B8D18J8C: $checks checks passed.\n";
