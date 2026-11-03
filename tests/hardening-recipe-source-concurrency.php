<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operations-core.php';

if(($argv[1]??'')==='worker'){
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$pdo=app_pdo();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=12');
    echo json_encode(['connection'=>(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()],JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
    try{
        $sync=operations_sync_inventory_sources($pdo,(int)$input['org'],(int)$input['actor']);
        echo json_encode(['ok'=>true,'recipeReferences'=>$sync['recipeReferences']],JSON_THROW_ON_ERROR);
    }catch(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");exit(1);}
    exit;
}

$checks=0;
function hrsc_check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function hrsc_value(PDO $pdo,string $sql,array $args=[]): mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
$pdo=app_pdo();$pdo->exec('SET SESSION innodb_lock_wait_timeout=12');$suffix=bin2hex(random_bytes(5));
$catalog=json_decode((string)file_get_contents(RESTAURANT_APP_ROOT.'/data/ingredient-catalog.json'),true,512,JSON_THROW_ON_ERROR);$name='';
foreach($catalog['ingredients']??[] as $ingredient){$name=trim((string)($ingredient['name']??''));if($name!=='')break;}
if($name==='')throw new RuntimeException('The real ingredient catalog must contain an inventory source.');
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Source lock ordering '.$suffix]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture','Source','Worker','Source Worker','active')")->execute(['hrsc-'.$suffix.'@example.test']);$actor=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$actor]);
$recipe='hrsc-'.$suffix;
$pdo->prepare("INSERT INTO recipes(organization_id,public_id,name,ingredients_json,yield_quantity,yield_unit,status,created_by,updated_by) VALUES (?,?,?,?,1,'batch','active',?,?)")
    ->execute([$org,$recipe,'Source lock recipe '.$suffix,json_encode([['name'=>$name,'quantity'=>1,'unit'=>'kg']],JSON_THROW_ON_ERROR),$actor,$actor]);
operations_sync_inventory_sources($pdo,$org,$actor);
$key=operations_slug(operations_normalized_ingredient($name));
$q=$pdo->prepare('SELECT id,public_id FROM inventory_items WHERE organization_id=? AND normalized_key=?');$q->execute([$org,$key]);$item=$q->fetch(PDO::FETCH_ASSOC);
if(!$item)throw new RuntimeException('The first synchronized catalog item must exist.');$itemId=(int)$item['id'];
$pdo->prepare("UPDATE inventory_items SET base_unit='kg',on_hand_quantity=5 WHERE organization_id=? AND id=?")->execute([$org,$itemId]);
$ledgerBefore=(int)hrsc_value($pdo,'SELECT COUNT(*) FROM inventory_transactions WHERE organization_id=? AND inventory_item_id=?',[$org,$itemId]);
$connection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();$process=null;$pipes=[];
try{
    // Hold the same first item lock as the stock writer. The sync worker will
    // reach that exact item first in the real menu catalog upsert sequence.
    $pdo->beginTransaction();
    $lock=$pdo->prepare('SELECT * FROM inventory_items WHERE organization_id=? AND id=? FOR UPDATE');$lock->execute([$org,$itemId]);$saved=$lock->fetch(PDO::FETCH_ASSOC);
    $process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Could not launch inventory source worker.');
    fwrite($pipes[0],json_encode(['org'=>$org,'actor'=>$actor],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],15);
    $ready=json_decode((string)fgets($pipes[1]),true,512,JSON_THROW_ON_ERROR);$worker=(int)$ready['connection'];
    $wait=$pdo->prepare("SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE r.trx_state='LOCK WAIT' AND r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=?");
    $deadline=microtime(true)+8;$waiting=false;
    do{$wait->execute([$worker,$connection]);$waiting=(int)$wait->fetchColumn()>0;if($waiting)break;usleep(200000);}while(microtime(true)<$deadline);
    hrsc_check($waiting,'Source refresh must be observed waiting on the stock transaction before its ledger insert.');

    // This real ledger insert must acquire the organization's foreign-key
    // shared lock while sync is waiting for our item. An exclusive tenant lock
    // in sync would create item -> tenant -> item and one transaction would die.
    $resulting=operations_quantity_decimal(operations_quantity_ticks($saved['on_hand_quantity'])+operations_quantity_ticks(1));
    $pdo->prepare('UPDATE inventory_items SET on_hand_quantity=?,updated_by=? WHERE organization_id=? AND id=?')->execute([$resulting,$actor,$org,$itemId]);
    $pdo->prepare("INSERT INTO inventory_transactions(organization_id,inventory_item_id,transaction_type,quantity_delta,resulting_quantity,unit,created_by) VALUES (?,?,'receive',1,?,'kg',?)")->execute([$org,$itemId,$resulting,$actor]);
    $transactionId=(int)$pdo->lastInsertId();
    app_audit($pdo,$org,$actor,'inventory.stock_changed','inventory_item',(string)$item['public_id'],['quantity'=>$saved['on_hand_quantity']],['type'=>'receive','quantity'=>'1.0000','result'=>$resulting,'transactionId'=>$transactionId]);
    $pdo->commit();
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$process=null;
    hrsc_check($exit===0,'The waiting source refresh must finish without a deadlock or database error: '.$error);
    $sync=json_decode($output,true,512,JSON_THROW_ON_ERROR);
    hrsc_check($sync===['ok'=>true,'recipeReferences'=>1],'The source worker must finish its complete recipe projection.');
    hrsc_check((float)hrsc_value($pdo,'SELECT on_hand_quantity FROM inventory_items WHERE organization_id=? AND id=?',[$org,$itemId])===6.0,'Concurrent source refresh must preserve the committed stock movement.');
    hrsc_check((int)hrsc_value($pdo,'SELECT COUNT(*) FROM inventory_transactions WHERE organization_id=? AND inventory_item_id=?',[$org,$itemId])===$ledgerBefore+1,'Concurrent source refresh must preserve exactly one stock ledger entry.');
    hrsc_check((float)hrsc_value($pdo,"SELECT quantity_per_source FROM inventory_item_sources WHERE organization_id=? AND inventory_item_id=? AND source_type='recipe' AND source_public_id=?",[$org,$itemId,'recipe:'.$recipe])===1.0,'The recipe source remains complete after the stock writer releases its lock.');
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    if(is_resource($process))proc_terminate($process);
    foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
    if(is_resource($process))proc_close($process);
}
echo "recipe-source stock concurrency: {$checks} checks passed\n";
