<?php
declare(strict_types=1);
function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-catering-reads.php';
$checks=0;
function j7a_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J7A FAIL: $label\n");exit(1);}
    ++$checks;
}
$viewer=['id'=>10,'organization_id'=>3,'permissions'=>['catering.view','catering.agent']];
$employee=['id'=>10,'organization_id'=>3,'permissions'=>['catering.view']];
j7a_check(fm8d18j7a_catalogue($employee)===[],'Catering Agent permission required');
j7a_check(fm8d18j7a_catalogue($viewer,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved private voice excluded');
j7a_check(count(fm8d18j7a_catalogue($viewer,['channel'=>'voice','privateVoiceApproved'=>true]))===2,'approved private voice has both reads');
$noOrg=$viewer;$noOrg['organization_id']=0;
$noUser=$viewer;$noUser['id']=0;
j7a_check(fm8d18j7a_catalogue($noOrg)===[],'tenant mandatory');
j7a_check(fm8d18j7a_catalogue($noUser)===[],'authenticated user mandatory');
$tools=fm8d18j7a_catalogue($viewer);
$search=$tools['search_catering_operations'];$profile=$tools['read_catering_operation'];
j7a_check($search['impact']==='read'&&$profile['impact']==='read','both tools read-only');
j7a_check($search['allowedArguments']===['query'],'no user-supplied tenant');
j7a_check($profile['allowedArguments']===['operation_public_id','section'],'profile requires exact saved ID and section');
j7a_check(($search['authorize'])($employee,[])===false,'permission rechecked at execution');
foreach(['Wedding','catering-123','Holiday Event'] as $query)
    j7a_check(($search['validate'])(['query'=>$query],$viewer)===[],'valid query');
foreach(['','x','%','Jo_n','Amy; DROP',"bad\nname",str_repeat('a',121)] as $query)
    j7a_check(($search['validate'])(['query'=>$query],$viewer)===['query'],'invalid query denied');
foreach(['readiness','tasks','ingredients','staff','menu'] as $section)
    j7a_check(($profile['validate'])(['operation_public_id'=>'operation-123','section'=>$section],$viewer)===[],'valid profile section');
foreach(['','x','op%','op name',str_repeat('b',121)] as $id)
    j7a_check(in_array('operation_public_id',($profile['validate'])(['operation_public_id'=>$id,'section'=>'tasks'],$viewer),true),'invalid ID rejected');
foreach(['all','email','contact','write','',null] as $section)
    j7a_check(in_array('section',($profile['validate'])(['operation_public_id'=>'operation-123','section'=>$section],$viewer),true),'unknown section rejected');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$adapter=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-catering-reads.php');
j7a_check(str_contains($runtime,'fm8d18j7a_catalogue($principal,$context)'),'runtime registered');
j7a_check(str_contains($adapter,'WHERE organization_id=? AND archived_at IS NULL'),'search tenant scoped');
j7a_check(str_contains($adapter,'LIMIT 9'),'search bounded with truncation');
j7a_check(str_contains($adapter,'catering_operation_row($pdo,$org,$public)'),'exact detail tenant scoped');
j7a_check(str_contains($adapter,'catering_operations_readiness($pdo,$org,$id,false)'),'readiness recalculated without persisting');
j7a_check(!str_contains($adapter,'catering_operations_context('),'writing readiness context avoided');
j7a_check(!str_contains($adapter,"'email'=>"),'no customer email');
j7a_check(!str_contains($adapter,"'phone'=>"),'no customer phone');
j7a_check(!str_contains($adapter,'gac_pending_store('),'read-only tools do not prepare actions');
echo "Food Monster B8D18J7A: $checks checks passed.\n";
