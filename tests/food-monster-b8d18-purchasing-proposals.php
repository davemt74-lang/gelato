<?php
declare(strict_types=1);

function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
function app_pdo():PDO{return $GLOBALS['database'];}
function app_audit(...$args):void{$GLOBALS['auditEvents'][]=$args;}
require dirname(__DIR__).'/includes/food-monster-b8d18-purchasing-proposals.php';

$checks=0;
function b18c_check(bool $condition,string $message):void
{
    global $checks;
    if(!$condition){fwrite(STDERR,"B8D18C FAILED: $message\n");exit(1);}
    ++$checks;
}
function b18c_invalid(callable $run,string $message):void
{
    try{$run();}catch(InvalidArgumentException $e){
        b18c_check(str_contains($e->getMessage(),$message),'rejected: '.$message);return;
    }
    b18c_check(false,'missing rejection: '.$message);
}

$scope=['purchasing.agent','purchasing.view','purchasing.manage'];
$actor=['id'=>101,'organization_id'=>7,'permissions'=>array_merge($scope,['receiving.manage'])];
$viewer=['id'=>101,'organization_id'=>7,'permissions'=>['purchasing.agent','purchasing.view']];
$receiving=['id'=>101,'organization_id'=>7,'permissions'=>['purchasing.agent','purchasing.view','receiving.manage']];
$catalogue=fm8d18p_catalogue($actor);
b18c_check(count($catalogue)===3,'all three native purchasing proposal tools registered');
b18c_check(fm8d18p_catalogue($viewer)===[],'view-only user cannot stage purchasing writes');
b18c_check(array_keys(fm8d18p_catalogue($receiving))===['propose_purchase_order_full_receipt'],'receiving right independent of purchasing manage');
b18c_check(!fm8d18p_can(['id'=>0,'organization_id'=>7,'permissions'=>$scope],'manage'),'invalid operator denied');
foreach($catalogue as $tool)b18c_check(($tool['impact']??'')==='read','tools do not execute business writes');
b18c_check(fm8d18p_fields(['vendor_name'=>'Fresh Foods'],'vendor_name')===[],'exact vendor allowed');
b18c_check(fm8d18p_fields(['vendor_name'=>"Fresh Foods; order extra"],'vendor_name')===['vendor_name'],'vendor command chain rejected');
b18c_check(fm8d18p_fields(['order_number'=>"PO-5\nCancel all"],'order_number')===['order_number'],'PO command injection rejected');
b18c_check(($catalogue['propose_purchase_order_status']['validate'])(['order_number'=>'PO-5','action'=>'delete'],$actor)===['action'],'destructive unknown status rejected');
b18c_check(($catalogue['propose_purchase_order_full_receipt']['validate'])(['order_number'=>'PO-5','all_items_received_in_full'=>'true'],$actor)===['all_items_received_in_full'],'string truth does not pass receipt consent');
b18c_check(($catalogue['propose_purchase_order_full_receipt']['validate'])(['order_number'=>'PO-5','all_items_received_in_full'=>false],$actor)===['all_items_received_in_full'],'missing full-receipt consent rejected');

$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('FIELD',static function($value,...$possible):int{
    $index=array_search($value,$possible,true);return $index===false?0:$index+1;
},-1);
$GLOBALS['database']=$db;
$db->exec('CREATE TABLE vendors (id INT,organization_id INT,public_id TEXT,name TEXT,status TEXT,archived_at TEXT,minimum_order_amount REAL,lead_time_days INT,cutoff_time TEXT)');
$db->exec('CREATE TABLE vendor_contacts (organization_id INT,vendor_id INT)');
$db->exec('CREATE TABLE inventory_vendor_items (organization_id INT,vendor_id INT,inventory_item_id INT,archived_at TEXT,status TEXT)');
$db->exec('CREATE TABLE purchase_orders (id INT,organization_id INT,public_id TEXT,vendor_id INT,order_number TEXT,status TEXT,archived_at TEXT,expected_delivery_date TEXT,created_at TEXT,updated_at TEXT,total_amount REAL)');
$db->exec('CREATE TABLE purchase_order_items (id INT,organization_id INT,purchase_order_id INT,inventory_item_id INT,public_id TEXT,ordered_base_quantity REAL,received_base_quantity REAL,unit TEXT)');
$db->exec('CREATE TABLE inventory_items (id INT,organization_id INT,public_id TEXT,name TEXT)');
$db->exec('CREATE TABLE purchase_order_events (organization_id INT,purchase_order_id INT,event_type TEXT,summary TEXT,created_at TEXT)');
$db->exec("INSERT INTO vendors VALUES (1,7,'vendor-1','Valley Foods','active',NULL,0,1,NULL),(2,8,'vendor-2','Valley Foods','active',NULL,0,1,NULL),(3,7,'vendor-3','Inactive Foods','inactive',NULL,0,1,NULL)");
$db->exec("INSERT INTO purchase_orders VALUES (1,7,'po-1',1,'PO-7-DRAFT','draft',NULL,NULL,'2026-10-30','2026-10-30 11:00:00',240),(2,7,'po-2',1,'PO-7-OPEN','submitted',NULL,NULL,'2026-10-30','2026-10-30 11:00:00',190),(3,8,'po-3',2,'PO-8-PRIVATE','draft',NULL,NULL,'2026-10-30','2026-10-30 11:00:00',999),(4,7,'po-4',1,'PO-7-RECEIVED','received',NULL,NULL,'2026-10-30','2026-10-30 11:00:00',50)");
$db->exec("INSERT INTO inventory_items VALUES (1,7,'inv-1','Mozzarella'),(2,8,'inv-2','Private item')");
$db->exec("INSERT INTO purchase_order_items VALUES (1,7,2,1,'poi-1',10,3,'lb')");

b18c_check(fm8d18p_exact_vendor($db,7,'valley foods')['public_id']==='vendor-1','vendor match exact and tenant bound');
b18c_invalid(static fn()=>fm8d18p_exact_vendor($db,7,'Valley'),'No unique exact vendor');
b18c_invalid(static fn()=>fm8d18p_exact_vendor($db,7,'Inactive Foods'),'inactive');
b18c_check(fm8d18p_exact_order($db,7,'PO-7-DRAFT')['public_id']==='po-1','PO matched exactly');
b18c_invalid(static fn()=>fm8d18p_exact_order($db,7,'PO-8-PRIVATE'),'No unique exact purchase order');
b18c_invalid(static fn()=>fm8d18p_exact_order($db,7,'PO-7'),'No unique exact purchase order');

if(session_status()!==PHP_SESSION_ACTIVE&&!session_start())throw new RuntimeException('Test session unavailable');
$status=$catalogue['propose_purchase_order_status']['execute'];
$receipt=$catalogue['propose_purchase_order_full_receipt']['execute'];
$draft=$catalogue['propose_purchase_order_draft']['execute'];
try{$status(['order_number'=>'PO-7-DRAFT','action'=>'submit'],$viewer);b18c_check(false,'denied status call');}
catch(DomainException $e){b18c_check(str_contains($e->getMessage(),'permission'),'fresh status permission checked');}
try{$receipt(['order_number'=>'PO-7-OPEN','all_items_received_in_full'=>true],$viewer);b18c_check(false,'denied receipt call');}
catch(DomainException $e){b18c_check(str_contains($e->getMessage(),'permission'),'fresh receiving permission checked');}
try{$draft(['vendor_name'=>'Valley Foods'],$viewer);b18c_check(false,'denied draft call');}
catch(DomainException $e){b18c_check(str_contains($e->getMessage(),'permission'),'fresh draft permission checked');}
b18c_invalid(static fn()=>$status(['order_number'=>'PO-7-OPEN','action'=>'submit'],$actor),'Only a draft');
b18c_invalid(static fn()=>$status(['order_number'=>'PO-7-RECEIVED','action'=>'cancel'],$actor),'cannot be cancelled');
b18c_invalid(static fn()=>$status(['order_number'=>'PO-8-PRIVATE','action'=>'cancel'],$actor),'No unique exact purchase order');

$answer=$status(['order_number'=>'PO-7-DRAFT','action'=>'submit'],$actor);
b18c_check(($answer['data']['requiresConfirmation']??false)===true,'status staging only');
b18c_check(str_contains($answer['data']['answer'],'Super Admin'),'high impact owner approval clearly disclosed');
$pending=fm8d18p_pending_get(7,101);
b18c_check(($pending['type']??'')==='submit_po'&&($pending['payload']['purchaseOrderId']??'')==='po-1','uses existing Purchasing Agent pending contract');
b18c_check(($pending['payload']['expected']['status']??'')==='draft','stale PO status saved for confirmation');
b18c_check($db->query("SELECT status FROM purchase_orders WHERE public_id='po-1'")->fetchColumn()==='draft','submit proposal writes no PO changes');
b18c_invalid(static fn()=>$receipt(['order_number'=>'PO-7-OPEN','all_items_received_in_full'=>true],$actor),'already a pending restaurant action');
fm8d18p_pending_clear(7,101);
$receiptAnswer=$receipt(['order_number'=>'PO-7-OPEN','all_items_received_in_full'=>true],$receiving);
b18c_check(($receiptAnswer['data']['proposal']['type']??'')==='receive_po_full','full receipt stages original action');
$pending=fm8d18p_pending_get(7,101);
b18c_check(($pending['payload']['purchaseOrderId']??'')==='po-2'&&strlen((string)($pending['payload']['outstandingSignature']??''))===64,'receipt binds exact order and outstanding quantity signature');
b18c_check((float)$db->query("SELECT received_base_quantity FROM purchase_order_items WHERE public_id='poi-1'")->fetchColumn()===3.0,'receipt proposal does not alter inventory');
fm8d18p_pending_clear(7,101);
b18c_invalid(static fn()=>$receipt(['order_number'=>'PO-7-DRAFT','all_items_received_in_full'=>true],$actor),'Only a submitted');
b18c_invalid(static fn()=>$draft(['vendor_name'=>'Missing Vendor'],$actor),'No unique exact vendor');
b18c_check(count($GLOBALS['auditEvents']??[])===2,'only successful proposals audited');
$runtime=(string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
b18c_check(str_contains($runtime,'fm8d18p_catalogue($principal)'),'purchasing native catalogue registered');
b18c_check(str_contains($runtime,"'propose_purchase_order_draft','propose_purchase_order_status','propose_purchase_order_full_receipt'"),'no mixed proposals or reads');
$engine=(string)file_get_contents(dirname(__DIR__).'/includes/purchasing-agent-core.php');
b18c_check(str_contains($engine,'fm9g_authorize_purchase($user,$proposal)'),'Super Admin grant remains authoritative');
b18c_check(str_contains($engine,'pac_guard_order($po,(array)$payload[\'expected\'])'),'existing stale-order guard remains authoritative');
b18c_check(str_contains($engine,"'purchasing_agent_pending'"),'adapter writes the specialist pending-session contract');
b18c_check(!str_contains((string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d18-purchasing-proposals.php'),"require_once __DIR__.'/purchasing-agent-core.php'"),'avoids Prep/Purchasing pac_require naming collision');
session_write_close();
echo "B8D18C model-selected Purchasing proposals: $checks assertions passed\n";
