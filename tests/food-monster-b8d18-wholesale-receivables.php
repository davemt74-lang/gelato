<?php
declare(strict_types=1);

function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
require dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-receivables-reads.php';
require dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-receivables-proposals.php';

$checks=0;
function j10_check(bool $ok,string $label):void
{
    global $checks;
    if(!$ok){fwrite(STDERR,"B8D18J10 FAIL: $label\n");exit(1);}
    ++$checks;
}
$manager=['id'=>10,'organization_id'=>3,
    'permissions'=>['wholesale.view','wholesale.agent','wholesale.manage','wholesale.receivables.view','wholesale.receivables.manage']];
$reader=$manager;$reader['permissions']=['wholesale.view','wholesale.agent','wholesale.receivables.view'];
$noFinancial=$manager;$noFinancial['permissions']=['wholesale.view','wholesale.agent','wholesale.manage'];
$noAgent=$manager;$noAgent['permissions']=['wholesale.view','wholesale.receivables.view','wholesale.receivables.manage'];
j10_check(count(fm8d18j10a_catalogue($manager))===3,'three receivables read tools');
j10_check(count(fm8d18j10b_catalogue($manager))===2,'two draft-only proposal tools');
j10_check(count(fm8d18j10a_catalogue($reader))===3,'reader can see receivables');
j10_check(fm8d18j10b_catalogue($reader)===[],'reader cannot change receivables');
j10_check(fm8d18j10a_catalogue($noFinancial)===[],'receivables view permission mandatory');
j10_check(fm8d18j10b_catalogue($noFinancial)===[],'receivables manage permission mandatory');
j10_check(fm8d18j10a_catalogue($noAgent)===[],'Wholesale Agent permission mandatory');
j10_check(fm8d18j10a_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice cannot read financials');
j10_check(fm8d18j10b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>false])===[],'unapproved voice cannot propose finance changes');
j10_check(count(fm8d18j10b_catalogue($manager,['channel'=>'voice','privateVoiceApproved'=>true]))===2,'approved voice may stage');
foreach(fm8d18j10a_catalogue($manager) as $tool){
    j10_check($tool['impact']==='read','financial reads have read impact');
    j10_check(($tool['authorize'])($noFinancial,[])===false,'financial read rechecks permission');
}
foreach(fm8d18j10b_catalogue($manager) as $tool){
    j10_check($tool['impact']==='read','financial proposal has no direct write impact');
    j10_check(($tool['authorize'])($reader,[])===false,'financial proposal rechecks management permission');
    j10_check($tool['parameters']['additionalProperties']===false,'extra arguments rejected');
}
$create=['order_public_id'=>'worder-ci','order_number'=>'W-001','business_name'=>'Cafe',
    'saved_total'=>180.0,'issue_date'=>'2026-11-01','create_draft'=>true];
$void=['invoice_public_id'=>'winv-ci','invoice_number'=>'WINV-001',
    'order_number'=>'W-001','business_name'=>'Cafe',
    'reason'=>'Duplicate draft','void_draft'=>true];
$catalogue=fm8d18j10b_catalogue($manager);
foreach([['propose_wholesale_invoice_draft',$create],['propose_wholesale_invoice_draft_void',$void]] as [$name,$args]){
    $tool=$catalogue[$name];
    j10_check(($tool['validate'])($args,$manager)===[],$name.' exact fields valid');
    foreach(array_keys($args) as $field){
        $bad=$args;unset($bad[$field]);
        j10_check(($tool['validate'])($bad,$manager)!==[],$name.' requires '.$field);
    }
}
$bad=$create;$bad['create_draft']=false;
j10_check(fm8d18j10b_draft_fields($bad)!==[],'draft creation consent cannot be false');
$bad=$create;$bad['issue_date']='tomorrow';
j10_check(fm8d18j10b_draft_fields($bad)!==[],'draft requires exact date');
$bad=$void;$bad['void_draft']=false;
j10_check(fm8d18j10b_void_fields($bad)!==[],'draft void consent cannot be false');
j10_check(fm8d18j10a_detail_fields(['invoice_public_id'=>'winv-ci','section'=>'ledger'])===[],'exact ledger read');
j10_check(fm8d18j10a_detail_fields(['invoice_public_id'=>'winv-ci','section'=>'customer_notes'])!==[],'private details section denied');
$runtime=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
$core=file_get_contents(dirname(__DIR__).'/includes/wholesale-agent-core.php');
$services=file_get_contents(dirname(__DIR__).'/includes/wholesale-receivables.php');
$proposalSource=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-receivables-proposals.php');
$readSource=file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-wholesale-receivables-reads.php');
$start=strpos($runtime,'if(count($calls)>1){');$end=$start===false?false:strpos($runtime,'    $plan=[];',$start);
$guard=$start!==false&&$end!==false?substr($runtime,$start,$end-$start):'';
j10_check(str_contains($runtime,'fm8d18j10a_catalogue($principal,$context)'),'read tools registered');
j10_check(str_contains($runtime,'fm8d18j10b_catalogue($principal,$context)'),'proposals registered');
j10_check(str_contains($guard,"'propose_wholesale_invoice_draft'"),'draft cannot batch');
j10_check(str_contains($guard,"'propose_wholesale_invoice_draft_void'"),'draft void cannot batch');
j10_check(str_contains($core,'return wa10_execute_pending('),'confirmation handed to specialist');
j10_check(str_contains($services,'?array $expectedOrder=null'),'canonical draft receives lock-time snapshot');
j10_check(str_contains($services,'?array $expectedInvoice=null'),'canonical void receives lock-time snapshot');
j10_check(str_contains($services,'Wholesale order changed after the draft proposal'),'stale order denied in existing service');
j10_check(str_contains($services,'Wholesale invoice changed after the draft-void proposal'),'stale invoice denied in existing service');
j10_check(str_contains($services,'Cannot void a draft that has financial ledger entries'),'draft ledger guarded');
j10_check(!str_contains($proposalSource,'UPDATE wholesale_invoices'),'model proposal cannot mutate invoice');
j10_check(!str_contains($proposalSource,'wholesale_receivables_issue('),'model cannot issue invoice');
j10_check(!str_contains($proposalSource,'wholesale_receivables_record('),'model cannot record payment or credit');
j10_check(!str_contains($readSource,'payment_method'),'model read excludes payment method');
j10_check(!str_contains($readSource,'external_reference'),'model read excludes external payment reference');
echo "Food Monster B8D18J10: $checks checks passed.\n";
