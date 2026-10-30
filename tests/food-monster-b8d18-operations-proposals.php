<?php
declare(strict_types=1);

function app_has_permission(string $permission,array $actor):bool
{
    return in_array($permission,(array)($actor['permissions']??[]),true);
}
function app_pdo():PDO
{
    return $GLOBALS['database'];
}
function app_audit(...$args):void
{
    $GLOBALS['auditEvents'][]=$args;
}
require dirname(__DIR__).'/includes/food-monster-b8d18-operations-proposals.php';

$checks=0;
function b18a_check(bool $condition,string $reason):void
{
    global $checks;
    if(!$condition){fwrite(STDERR,"B8D18A FAILED: $reason\n");exit(1);}
    ++$checks;
}
function b18a_invalid(callable $run,string $needle):void
{
    try{$run();}catch(InvalidArgumentException $e){
        b18a_check(str_contains($e->getMessage(),$needle),'expected '.$needle);return;
    }
    b18a_check(false,'missing rejection '.$needle);
}

$inventory=['inventory.agent','inventory.view','inventory.manage'];
$tasks=['tasks.agent','tasks.view','tasks.manage'];
$actor=['id'=>101,'organization_id'=>7,'permissions'=>array_merge($inventory,$tasks)];
$onlyRead=['id'=>101,'organization_id'=>7,'permissions'=>['inventory.agent','inventory.view','tasks.agent','tasks.view']];
$catalogue=fm8d18_catalogue($actor);
b18a_check(isset($catalogue['propose_inventory_stock_change'],$catalogue['propose_prep_tasks']),'both native tools exposed');
b18a_check(fm8d18_catalogue($onlyRead)===[],'no write-capability proposals for read-only accounts');
b18a_check(!($catalogue['propose_inventory_stock_change']['authorize'])($onlyRead,[]),'permission rechecked on execution');
b18a_check(($catalogue['propose_prep_tasks']['authorize'])($actor,[]),'task action allowed only to scoped manager');
b18a_check(($catalogue['propose_inventory_stock_change']['impact']??'')==='read'
    &&($catalogue['propose_prep_tasks']['impact']??'')==='read','proposal only, no business write');
b18a_check(fm8d18_stock_fields(['item_name'=>'Fresh Mozzarella','action'=>'receive','quantity'=>5,'unit'=>'pounds'])===[],'spoken unit normalized');
b18a_check(fm8d18_stock_fields(['item_name'=>'Fresh Mozzarella','action'=>'count','quantity'=>0,'unit'=>'lb'])===[],'zero actual count supported');
b18a_check(in_array('quantity',fm8d18_stock_fields(['item_name'=>'Fresh Mozzarella','action'=>'receive','quantity'=>0,'unit'=>'lb']),true),'zero received blocked');
b18a_check(in_array('quantity',fm8d18_stock_fields(['item_name'=>'Fresh Mozzarella','action'=>'receive','quantity'=>'5','unit'=>'lb']),true),'numeric strings do not bypass type validation');
b18a_check(in_array('unit',fm8d18_stock_fields(['item_name'=>'Fresh Mozzarella','action'=>'receive','quantity'=>5,'unit'=>'cups-of-magic']),true),'unrecognized unit blocked');
b18a_check(in_array('action',fm8d18_stock_fields(['item_name'=>'Fresh Mozzarella','action'=>'archive','quantity'=>5,'unit'=>'lb']),true),'dangerous action not registered');
b18a_check(in_array('item_name',fm8d18_stock_fields(['item_name'=>"Fresh Mozzarella; change another item",'action'=>'receive','quantity'=>5,'unit'=>'lb']),true),'multiple command injected in name blocked');
b18a_check(fm8d18_prep_fields(['items'=>['Ball dough','Portion cheese']])===[],'typed task list');
b18a_check(fm8d18_prep_fields(['items'=>['Ball dough','ball dough']])===['items'],'duplicate prep task rejected');
b18a_check(in_array('quantity',fm8d18_stock_fields(['item_name'=>'Fresh Mozzarella','action'=>'receive','quantity'=>1.12345,'unit'=>'lb']),true),'fractional precision beyond stock ledger rejected');
b18a_check(fm8d18_prep_fields(['items'=>[]])===['items'],'empty prep rejected');
b18a_check(fm8d18_prep_fields(['items'=>array_fill(0,31,'Ball dough')])===['items'],'oversize batch rejected');
b18a_check(fm8d18_prep_fields(['items'=>['Prep dough','and modify inventory;']])===['items'],'embedded command blocked');
b18a_check(fm8d18_prep_fields(['items'=>['Ball dough',42]])===['items'],'non-string item rejected');

$GLOBALS['database']=new PDO('sqlite::memory:');
$GLOBALS['database']->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$GLOBALS['database']->exec('CREATE TABLE inventory_items (public_id TEXT, organization_id INT, name TEXT, base_unit TEXT, on_hand_quantity REAL, archived_at TEXT)');
$insert=$GLOBALS['database']->prepare('INSERT INTO inventory_items VALUES (?,?,?,?,?,NULL)');
$insert->execute(['inv-1',7,'Fresh Mozzarella','lb',10]);
$insert->execute(['inv-2',8,'Fresh Mozzarella','lb',50]);
if(session_status()!==PHP_SESSION_ACTIVE&&!session_start())throw new RuntimeException('Test session unavailable');
$stock=$catalogue['propose_inventory_stock_change']['execute'];
$prep=$catalogue['propose_prep_tasks']['execute'];
$stockArgs=['item_name'=>'Fresh Mozzarella','action'=>'receive','quantity'=>5,'unit'=>'lb'];
$denied=['id'=>102,'organization_id'=>9,'permissions'=>$inventory];
try{$stock($stockArgs,$onlyRead);b18a_check(false,'unauthorized direct stock call blocked');}
catch(DomainException $e){b18a_check(str_contains($e->getMessage(),'permission'),'unauthorized direct stock call blocked');}
try{$prep(['items'=>['Ball dough']],$onlyRead);b18a_check(false,'unauthorized direct prep call blocked');}
catch(DomainException $e){b18a_check(str_contains($e->getMessage(),'permission'),'unauthorized direct prep call blocked');}
b18a_invalid(static fn()=>$stock($stockArgs,$denied),'No exact inventory match');
b18a_invalid(static fn()=>$stock(['item_name'=>'Mozzarella','action'=>'receive','quantity'=>5,'unit'=>'lb'],$actor),'No exact inventory match');
b18a_invalid(static fn()=>$stock(['item_name'=>'Fresh Mozzarella','action'=>'receive','quantity'=>5,'unit'=>'kg'],$actor),'Unit mismatch');
$quantityBefore=(float)$GLOBALS['database']->query("SELECT on_hand_quantity FROM inventory_items WHERE organization_id=7")->fetchColumn();
$proposed=$stock($stockArgs,$actor);
b18a_check($proposed['ok']===true&&$proposed['data']['requiresConfirmation']===true,'stock request is only proposal');
b18a_check(str_contains($proposed['data']['answer'],'10.0000 → 15.0000 lb'),'stock preview grounded in saved quantities');
b18a_check((float)$GLOBALS['database']->query("SELECT on_hand_quantity FROM inventory_items WHERE organization_id=7")->fetchColumn()===$quantityBefore,'proposal does not touch stock rows');
$pending=gac_pending_get('operations',7,101);
b18a_check(($pending['type']??'')==='inventory_adjust'&&($pending['payload']['publicId']??'')==='inv-1','proposal binds exact row and tenant');
b18a_check(($pending['payload']['expectedOnHand']??null)===10.0,'stale-count check retained for confirmation');
b18a_invalid(static fn()=>$prep(['items'=>['Ball dough']],$actor),'already a pending Operations action');
gac_pending_clear('operations',7,101);
$result=$prep(['items'=>['Ball dough','Portion mozzarella']],$actor);
b18a_check(($result['data']['proposal']['type']??'')==='prep_tasks','prep uses existing Operations Agent confirm protocol');
$pending=gac_pending_get('operations',7,101);
b18a_check($pending['payload']['items']===['Ball dough','Portion mozzarella'],'no inferred tasks added');
b18a_check($pending['payload']['assigneeIds']===[],'no inferred employee assignment');
b18a_check(count($GLOBALS['auditEvents']??[])===2,'both proposal types are audited, rejected requests are not');
$runtime=(string)file_get_contents(dirname(__DIR__).'/includes/food-monster-b8d3-tool-runtime.php');
b18a_check(str_contains($runtime,"fm8d18_catalogue(\$principal)"),'native tools registered with Food Monster');
b18a_check(str_contains($runtime,"'propose_inventory_stock_change','propose_prep_tasks'"),'multiple proposal tool calls rejected');
$operations=(string)file_get_contents(dirname(__DIR__).'/api/operations-agent.php');
b18a_check(str_contains($operations,"'inventory_adjust'")&&str_contains($operations,"'prep_tasks'")
    &&str_contains($operations,'session_write_close()'),'existing one-use approval and Operations execution retained');
gac_pending_clear('operations',7,101);
session_write_close();
echo "B8D18A model-selected Operations proposals: $checks assertions passed\n";
