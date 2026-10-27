<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/operations-agent-inventory-actions.php';
require dirname(__DIR__).'/includes/operations-core.php';

$checks=0;
function d17d_assert(bool $condition,string $reason):void
{
    global $checks;
    if(!$condition){fwrite(STDERR,"B8D17D FAILED: $reason\n");exit(1);}
    ++$checks;
}
function d17d_error(callable $callback,string $needle):void
{
    try{$callback();}catch(InvalidArgumentException|RuntimeException $e){
        d17d_assert(str_contains($e->getMessage(),$needle),'error includes '.$needle);return;
    }
    d17d_assert(false,'expected error including '.$needle);
}

$receive=ops_agent_inventory_intent('Hey Gelato, receive 5 lbs of Fresh Mozzarella.');
d17d_assert($receive!==null&&$receive['type']==='receive'&&$receive['quantity']===5.0&&$receive['name']==='Fresh Mozzarella','receive parsing');
d17d_assert(ops_agent_inventory_intent('use 2.25 lb of Fresh Mozzarella')['type']==='use','use parsing');
d17d_assert(ops_agent_inventory_intent('waste 1 lb of Fresh Mozzarella')['type']==='waste','waste parsing');
d17d_assert(ops_agent_inventory_intent('count 0 lb of Fresh Mozzarella')['quantity']===0.0,'zero count allowed');
d17d_assert(ops_agent_inventory_intent('set inventory count for Fresh Mozzarella to 12.5 lbs')['quantity']===12.5,'set count parsing');
d17d_assert(ops_agent_inventory_intent('could you maybe change stock?')===null,'speculative speech never proposes write');
d17d_error(static fn()=>ops_agent_inventory_intent('receive 4.5 lbs of Fresh Mozzarella and take 2 lbs out'),'only one stock change');
d17d_assert(ops_agent_inventory_unit('pounds')==='lb'&&ops_agent_inventory_unit('grams')==='g','unit synonyms without conversion');
d17d_error(static fn()=>ops_agent_inventory_intent('receive 0 lb of Fresh Mozzarella'),'Specify');
d17d_error(static fn()=>ops_agent_inventory_intent('count 10000001 lb of Fresh Mozzarella'),'Specify');
d17d_assert(ops_agent_inventory_intent('receive -3 lb of Fresh Mozzarella')===null,'negative syntax never executes');

$sqlite=new PDO('sqlite::memory:');$sqlite->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$sqlite->exec('CREATE TABLE inventory_items (public_id TEXT, organization_id INTEGER, name TEXT, base_unit TEXT, on_hand_quantity REAL, archived_at TEXT)');
$insert=$sqlite->prepare('INSERT INTO inventory_items VALUES (?,?,?,?,?,NULL)');
$insert->execute(['inv-1',7,'Fresh Mozzarella','lb',10]);
$insert->execute(['inv-2',8,'Fresh Mozzarella','lb',70]);
$insert->execute(['inv-3',7,'Other Cheese','kg',9]);
$payload=ops_agent_inventory_prepare($sqlite,7,$receive);
d17d_assert($payload['publicId']==='inv-1'&&$payload['expectedOnHand']===10.0&&$payload['resultOnHand']===15.0,'exact unique scoped match');
$use=ops_agent_inventory_prepare($sqlite,7,ops_agent_inventory_intent('use 2 lb of Fresh Mozzarella'));
d17d_assert($use['delta']===-2.0&&$use['resultOnHand']===8.0,'use decreases stock');
d17d_error(static fn()=>ops_agent_inventory_prepare($sqlite,7,ops_agent_inventory_intent('receive 2 kg of Fresh Mozzarella')),'Unit mismatch');
d17d_error(static fn()=>ops_agent_inventory_prepare($sqlite,7,ops_agent_inventory_intent('receive 2 lb of Mozzarella')),'No exact inventory match');
d17d_error(static fn()=>ops_agent_inventory_prepare($sqlite,9,$receive),'No exact inventory match');
d17d_error(static fn()=>ops_agent_inventory_prepare($sqlite,7,ops_agent_inventory_intent('waste 11 lb of Fresh Mozzarella')),'below zero');
$insert->execute(['inv-4',7,'fresh mozzarella','lb',1]);
d17d_error(static fn()=>ops_agent_inventory_prepare($sqlite,7,$receive),'Multiple inventory items');

$api=(string)file_get_contents(dirname(__DIR__).'/api/operations-agent.php');
$core=(string)file_get_contents(dirname(__DIR__).'/includes/operations-core.php');
d17d_assert(str_contains($api,"'inventory_adjust'")&&str_contains($api,"'inventory.manage'")
    &&str_contains($api,'session_write_close()'),'manager-only confirm and consume before write');
d17d_assert(str_contains($api,"'transactionId'")&&str_contains($api,"'proof'")
    &&str_contains($api,"'url'=>'operations.php'"),'real transaction receipt and review location');
d17d_assert(str_contains($core,'FOR UPDATE')&&str_contains($core,'$expectedOnHand')
    &&str_contains($core,'$pdo->rollBack()'),'optimistic stale stock guard inside row lock');

if(getenv('B8D17D_DSN')){
    $db=new PDO((string)getenv('B8D17D_DSN'),(string)getenv('B8D17D_USER'),(string)getenv('B8D17D_PASS'));
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE inventory_items (id BIGINT PRIMARY KEY AUTO_INCREMENT, organization_id BIGINT NOT NULL, public_id VARCHAR(80) NOT NULL, name VARCHAR(220) NOT NULL, base_unit VARCHAR(80), on_hand_quantity DECIMAL(14,4) NOT NULL, last_counted_at DATETIME(6), updated_by BIGINT, updated_at DATETIME(6), archived_at DATETIME(6), KEY(organization_id, public_id)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE inventory_transactions (id BIGINT PRIMARY KEY AUTO_INCREMENT, organization_id BIGINT NOT NULL, inventory_item_id BIGINT NOT NULL, transaction_type VARCHAR(40), quantity_delta DECIMAL(14,4), resulting_quantity DECIMAL(14,4), unit VARCHAR(80), note VARCHAR(1000), created_by BIGINT, created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB');
    $db->exec("INSERT INTO inventory_items(organization_id, public_id, name, base_unit, on_hand_quantity) VALUES(7,'inv-1','Fresh Mozzarella','lb',10),(8,'inv-2','Fresh Mozzarella','lb',70)");
    $one=operations_inventory_adjust($db,7,'inv-1',5,'receive',101,'B8D17D confirm',10.0);
    d17d_assert((float)$one['on_hand_quantity']===15.0&&(int)$one['last_transaction_id']>0,'confirmed stock write yields transaction id');
    d17d_error(static fn()=>operations_inventory_adjust($db,7,'inv-1',5,'receive',101,'B8D17D repeat',10.0),'Stock changed');
    d17d_error(static fn()=>operations_inventory_adjust($db,7,'inv-1',-99,'waste',101,'B8D17D invalid',15.0),'insufficient');
    d17d_error(static fn()=>operations_inventory_adjust($db,7,'inv-2',2,'receive',101,'B8D17D cross org',70.0),'not found');
    $qty=(float)$db->query("SELECT on_hand_quantity FROM inventory_items WHERE organization_id=7 AND public_id='inv-1'")->fetchColumn();
    $records=(int)$db->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn();
    d17d_assert($qty===15.0&&$records===1,'failed/replayed proposals leave one verifiable transaction');
}
echo "B8D17D inventory actions: $checks assertions passed\n";
