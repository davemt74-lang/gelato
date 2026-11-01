<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-reads.php';
$checks=0;
function j8a_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J8A FAIL: $label\n");exit(1);}
    ++$checks;
}
$viewer=['id'=>10,'organization_id'=>3,'permissions'=>['wholesale.view','wholesale.agent']];
$employee=['id'=>10,'organization_id'=>3,'permissions'=>['wholesale.view']];
j8a_check(fm8d18j8a_catalogue($employee)===[],'Wholesale Agent permission required');
j8a_check(fm8d18j8a_catalogue($viewer,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved private voice excluded');
j8a_check(count(fm8d18j8a_catalogue($viewer,['channel'=>'voice','privateVoiceApproved'=>true]))===3,'approved voice reads');
$bad=$viewer;$bad['id']=0;j8a_check(fm8d18j8a_catalogue($bad)===[],'signed-in user required');
$bad=$viewer;$bad['organization_id']=0;j8a_check(fm8d18j8a_catalogue($bad)===[],'tenant required');
$catalogue=fm8d18j8a_catalogue($viewer);
foreach($catalogue as $tool){
    j8a_check($tool['impact']==='read','no native read action mutates');
    j8a_check(($tool['authorize'])($employee,[])===false,'permission rechecked');
}
foreach(['#2026-001','worder-123','Main Market'] as $query)
    j8a_check(($catalogue['search_wholesale_orders']['validate'])(['query'=>$query],$viewer)===[],'valid specific lookup');
foreach(['','x','%','A_b',"bad\nname",str_repeat('x',121)] as $query)
    j8a_check(($catalogue['search_wholesale_orders']['validate'])(['query'=>$query],$viewer)===['query'],'invalid query denied');
foreach(['overview','lines','batches','shortages'] as $section)
    j8a_check(($catalogue['read_wholesale_order']['validate'])(['order_public_id'=>'worder-123','section'=>$section],$viewer)===[],'valid order section');
foreach(['status','items','stock'] as $section)
    j8a_check(($catalogue['read_wholesale_batch']['validate'])(['order_public_id'=>'worder-123','batch_public_id'=>'wful-123','section'=>$section],$viewer)===[],'valid batch section');
foreach(['','x','bad id','%','a/b',str_repeat('b',121)] as $id)
    j8a_check(in_array('order_public_id',($catalogue['read_wholesale_order']['validate'])(['order_public_id'=>$id,'section'=>'lines'],$viewer),true),'invalid ID');
foreach(['contacts','customers','write',null] as $section)
    j8a_check(in_array('section',($catalogue['read_wholesale_order']['validate'])(['order_public_id'=>'worder-123','section'=>$section],$viewer),true),'invalid section');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-reads.php');
j8a_check(str_contains($runtime,'fm8d18j8a_catalogue($principal,$context)'),'catalogue wired');
j8a_check(str_contains($adapter,'a.organization_id=o.organization_id'),'tenant-scoped account join');
j8a_check(str_contains($adapter,'LIMIT 9'),'bounded result');
j8a_check(str_contains($adapter,"wholesale_fulfillment_order($pdo,$org"),'exact order lookup');
j8a_check(str_contains($adapter,"wholesale_fulfillment_batch($pdo,$org"),'exact batch lookup');
j8a_check(!str_contains($adapter,"'email'=>")&&!str_contains($adapter,"'phone'=>"),'contacts excluded');
j8a_check(!str_contains($adapter,'gac_pending_store('),'reads have no proposal side effect');
echo "Food Monster B8D18J8A: $checks checks passed.\n";
