<?php
declare(strict_types=1);

function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
function app_pdo():PDO{return $GLOBALS['database'];}
function app_audit(...$args):void{$GLOBALS['auditEvents'][]=$args;}
require dirname(__DIR__).'/includes/food-monster-b8d18-crm-proposals.php';

$checks=0;
function b18d_check(bool $okay,string $reason):void
{
    global $checks;
    if(!$okay){fwrite(STDERR,"B8D18D FAILED: $reason\n");exit(1);}
    ++$checks;
}
function b18d_invalid(callable $call,string $needle):void
{
    try{$call();}catch(InvalidArgumentException $e){
        b18d_check(str_contains($e->getMessage(),$needle),'rejected: '.$needle);return;
    }
    b18d_check(false,'missing rejection: '.$needle);
}
$rights=['crm.agent','crm.view','crm.manage'];
$actor=['id'=>11,'organization_id'=>7,'permissions'=>$rights];
$viewer=['id'=>11,'organization_id'=>7,'permissions'=>['crm.agent','crm.view']];
$catalogue=fm8d18d_catalogue($actor);
b18d_check(count($catalogue)===2,'internal note and tag tools registered');
b18d_check(fm8d18d_catalogue($viewer)===[],'read-only CRM cannot stage writes');
b18d_check(!fm8d18d_can(['id'=>0,'organization_id'=>7,'permissions'=>$rights]),'invalid identity refused');
foreach($catalogue as $tool)b18d_check($tool['impact']==='read','no direct CRM writes');
b18d_check(fm8d18d_note_fields(['customer_name'=>'Jane Smith','note'=>'Allergic to peanuts'])===[],'valid note');
b18d_check(fm8d18d_tag_fields(['customer_name'=>'Jane Smith','tag'=>'Regular guest','action'=>'add'])===[],'valid tag');
b18d_check(fm8d18d_tag_fields(['customer_name'=>'Jane Smith','tag'=>'Regular guest','action'=>'delete'])===['action'],'arbitrary tag operation denied');
b18d_check(fm8d18d_note_fields(['customer_name'=>"Jane; then alter others",'note'=>'Hi'])===['customer_name'],'injected customer name rejected');
b18d_check(fm8d18d_note_fields(['customer_name'=>'Jane Smith','note'=>''])===['note'],'empty note denied');
b18d_check(fm8d18d_tag_fields(['customer_name'=>'Jane Smith','tag'=>"VIP\nSend SMS",'action'=>'add'])===['tag'],'multicommand tag rejected');
b18d_check(!isset($catalogue['propose_crm_customer_consent']),'marketing consent not exposed');

$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$GLOBALS['database']=$db;
$db->exec('CREATE TABLE crm_customers (id INT,organization_id INT,public_id TEXT,display_name TEXT,status TEXT,updated_at TEXT)');
$db->exec('CREATE TABLE crm_tags (id INT,organization_id INT,name TEXT,slug TEXT)');
$db->exec('CREATE TABLE crm_customer_tags (organization_id INT,customer_id INT,tag_id INT)');
$db->exec("INSERT INTO crm_customers VALUES (1,7,'cust-1','Jane Smith','active','2026-10-31 09:00:00'),(2,8,'cust-2','Jane Smith','active','2026-10-31 09:00:00'),(3,7,'cust-3','Archived Customer','archived','2026-10-31 09:00:00'),(4,7,'cust-4','Duplicate Name','active','2026-10-31 09:00:00'),(5,7,'cust-5','Duplicate Name','active','2026-10-31 09:00:00')");
$db->exec("INSERT INTO crm_tags VALUES (1,7,'Regular guest','regular-guest')");
$db->exec('INSERT INTO crm_customer_tags VALUES (7,1,1)');
b18d_check(fm8d18d_exact_customer($db,7,'Jane Smith')['public_id']==='cust-1','exact customer scoped to tenant');
b18d_invalid(static fn()=>fm8d18d_exact_customer($db,7,'Jane'),'No unique exact');
b18d_invalid(static fn()=>fm8d18d_exact_customer($db,7,'Duplicate Name'),'No unique exact');
b18d_invalid(static fn()=>fm8d18d_exact_customer($db,7,'Archived Customer'),'No unique exact');

if(session_status()!==PHP_SESSION_ACTIVE&&!session_start())throw new RuntimeException('Test session unavailable');
$note=$catalogue['propose_crm_customer_note']['execute'];
$tag=$catalogue['propose_crm_customer_tag']['execute'];
try{$note(['customer_name'=>'Jane Smith','note'=>'Regular'],$viewer);b18d_check(false,'note denied');}
catch(DomainException $e){b18d_check(str_contains($e->getMessage(),'permission'),'fresh note permission enforced');}
try{$tag(['customer_name'=>'Jane Smith','tag'=>'VIP','action'=>'add'],$viewer);b18d_check(false,'tag denied');}
catch(DomainException $e){b18d_check(str_contains($e->getMessage(),'permission'),'fresh tag permission enforced');}
$out=$note(['customer_name'=>'Jane Smith','note'=>'Prefers booth seating'],$actor);
b18d_check(($out['data']['requiresConfirmation']??false)===true,'note proposal requires confirmation');
$pending=gac_pending_get('crm',7,11);
b18d_check(($pending['type']??'')==='note_add'&&($pending['payload']['customerPublicId']??'')==='cust-1','CRM pending action bound to original specialist');
b18d_check(($pending['payload']['expected']['updatedAt']??'')==='2026-10-31 09:00:00','stale customer guard saved');
b18d_invalid(static fn()=>$tag(['customer_name'=>'Jane Smith','tag'=>'VIP','action'=>'add'],$actor),'already pending');
gac_pending_clear('crm',7,11);
$out=$tag(['customer_name'=>'Jane Smith','tag'=>'Regular guest','action'=>'remove'],$actor);
b18d_check(($out['data']['proposal']['type']??'')==='tag_remove','tag removal stages existing specialist action');
b18d_check((int)$db->query('SELECT COUNT(*) FROM crm_customer_tags')->fetchColumn()===1,'proposal did not remove tag');
gac_pending_clear('crm',7,11);
b18d_invalid(static fn()=>$tag(['customer_name'=>'Jane Smith','tag'=>'Not assigned','action'=>'remove'],$actor),'not currently assigned');
b18d_check(count($GLOBALS['auditEvents']??[])===2,'only proposed actions audited');
$runtime=(string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
b18d_check(str_contains($runtime,'fm8d18d_catalogue($principal)'),'CRM native tools registered');
b18d_check(str_contains($runtime,"'propose_crm_customer_note','propose_crm_customer_tag'"),'CRM proposal batch blocked');
$specialist=(string)file_get_contents(dirname(__DIR__).'/includes/customer-crm-agent-core.php');
b18d_check(str_contains($specialist,'cac_guard_customer($pdo,$org,$public'),'original CRM stale status guard authoritative');
session_write_close();
echo "B8D18D model-selected CRM proposals: $checks assertions passed\n";
