<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/purchasing-agent-core.php';
require_once __DIR__.'/../includes/daily-quicklist-purchase-drafts.php';
require_once __DIR__.'/../includes/daily-quicklist-purchasing-board.php';

final class HbipBarrierPDO extends PDO
{
    public bool $pauseBeforeTransaction=false;
    public function beginTransaction(): bool
    {
        if($this->pauseBeforeTransaction){
            $this->pauseBeforeTransaction=false;
            fwrite(STDOUT,"ready\n");fflush(STDOUT);
            if(trim((string)fgets(STDIN))!=='go')throw new RuntimeException('Missing transaction barrier release.');
        }
        return parent::beginTransaction();
    }
}
function hbip_connection(bool $pause=false): HbipBarrierPDO
{
    $db=app_config()['database'];
    $dsn=(string)($db['dsn']??'');
    if($dsn==='')$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$db['host']??'127.0.0.1',$db['port']??3306,$db['name'],$db['charset']??'utf8mb4');
    $pdo=new HbipBarrierPDO($dsn,(string)$db['username'],(string)$db['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=12');$pdo->pauseBeforeTransaction=$pause;return $pdo;
}
if(($argv[1]??'')==='worker'){
    $in=json_decode((string)fgets(STDIN),true,512,JSON_THROW_ON_ERROR);
    $db=hbip_connection(!empty($in['pause']));
    if(empty($in['pause'])){fwrite(STDOUT,"ready\n");fflush(STDOUT);}
    try{
        $result=match($in['action']){
            'adjust'=>operations_inventory_adjust($db,$in['org'],$in['stock'],-2,'use',$in['uid'],'Concurrent reduction'),
            'receive'=>purchasing_receive($db,$in['org'],$in['po'],$in['lines'],$in['uid'],'','Concurrency test',$in['expected']??null),
            'cancel'=>purchasing_cancel($db,$in['org'],$in['po'],$in['uid'],$in['expected']??null),
            'submit'=>purchasing_submit($db,$in['org'],$in['po'],$in['uid'],$in['expected']??null),
        };
        echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
    }catch(Throwable $error){echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR)."\n";}
    exit;
}
$checks=0;
function hbip_assert(bool $condition,string $message): void
{
    global $checks;if(!$condition)throw new RuntimeException($message);$checks++;
}
function hbip_scalar(PDO $pdo,string $sql,array $args=[]): mixed
{
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();
}
function hbip_reject(callable $callback,string $needle,string $message): void
{
    try{$callback();}catch(Throwable $error){hbip_assert(str_contains($error->getMessage(),$needle),$message.' Unexpected error: '.$error->getMessage());return;}
    hbip_assert(false,$message);
}
function hbip_snapshot(PDO $pdo,int $org): string
{
    $out=[];foreach(['inventory_items','inventory_transactions','purchase_orders','purchase_order_items','goods_receipts','goods_receipt_items','purchase_order_events','vendor_price_history','inventory_vendor_items','audit_log'] as $table){
        $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE organization_id=? ORDER BY id');$q->execute([$org]);$out[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return json_encode($out,JSON_THROW_ON_ERROR);
}
function hbip_worker(array $input): array
{
    $process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start inventory/purchasing concurrency worker.');
    fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);
    stream_set_timeout($pipes[1],15);
    if(trim((string)fgets($pipes[1]))!=='ready')throw new RuntimeException('Inventory/purchasing worker did not become ready.');
    return [$process,$pipes];
}
function hbip_finish(array $worker,bool $release=false): array
{
    [$process,$pipes]=$worker;
    if($release){fwrite($pipes[0],"go\n");fflush($pipes[0]);}fclose($pipes[0]);
    $result=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    if($exit!==0)throw new RuntimeException('Inventory/purchasing worker failed: '.$errors);
    $decoded=json_decode($result,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($decoded))throw new RuntimeException('Invalid concurrency result.');return $decoded;
}

$pdo=app_pdo();$suffix='hbip-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$suffix]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute([$suffix.'-other']);$other=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,'x','Stock','Tester','Stock Tester','active')")->execute([$suffix.'@example.test']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$vendor=purchasing_save_vendor($pdo,$org,['name'=>'Integrity supplier '.$suffix],$uid);
$stock=static function(string $label,string $unit='kg',float $quantity=0)use($pdo,$org,$uid,$suffix):array{
    $public=$suffix.'-'.$label;
    $pdo->prepare("INSERT INTO inventory_items(organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,status,created_by,updated_by) VALUES (?,?,?,?,?,?,'active',?,?)")
        ->execute([$org,$public,$public,$label,$unit?:null,$quantity,$uid,$uid]);
    return ['public'=>$public,'id'=>(int)$pdo->lastInsertId()];
};
$order=static function(string $label,float $quantity=5,bool $submit=true)use($pdo,$org,$uid,$stock,$vendor):array{
    $item=$stock($label);
    purchasing_save_vendor_item($pdo,$org,['vendorId'=>$vendor['public_id'],'inventoryId'=>$item['public'],'vendorSku'=>$label,'packSize'=>1,'packUnit'=>'kg','pricePerPack'=>3,'preferred'=>true],$uid);
    $po=purchasing_create_order($pdo,$org,$vendor['public_id'],[['inventoryId'=>$item['public'],'packs'=>$quantity]],$uid);
    if($submit)$po=purchasing_submit($pdo,$org,$po['public_id'],$uid);
    return [$po,$item];
};
$lines=static fn(array $po,float $quantity):array=>[['itemId'=>$po['items'][0]['public_id'],'quantity'=>$quantity]];
$balance=static fn(array $item):string=>(string)hbip_scalar($pdo,'SELECT on_hand_quantity FROM inventory_items WHERE organization_id=? AND id=?',[$org,$item['id']]);

// DATA-02: all canonical movement paths obey the exact database-scale ledger equation.
$item=$stock('stock', 'kg',3);$before=hbip_snapshot($pdo,$org);
foreach([['use',-5.0],['waste',-5.0],['adjust',-5.0],['count',-1.0],['receive',-1.0],['use',1.0],['adjust',INF],['adjust',0.00001]] as [$type,$quantity]){
    hbip_reject(static fn()=>operations_inventory_adjust($pdo,$org,$item['public'],$quantity,$type,$uid),
        match($type){'use','waste'=>$quantity<0?'insufficient':'reduce','count','receive'=>'negative',default=>!is_finite($quantity)?'range':($quantity<0?'insufficient':'decimal')},'Invalid stock movement must fail.');
    hbip_assert(hbip_snapshot($pdo,$org)===$before,'Rejected stock movement must preserve stock, ledger and audit.');
}
$expected=30000;
foreach([['use',-1.2345],['receive',0.1001],['waste',-0.0001],['adjust',-0.0001],['count',0.5000],['count',0.0]] as [$type,$quantity]){
    $result=operations_inventory_adjust($pdo,$org,$item['public'],$quantity,$type,$uid,'Exact ledger fixture');
    $q=$pdo->prepare('SELECT quantity_delta,resulting_quantity FROM inventory_transactions WHERE id=? AND organization_id=?');$q->execute([$result['last_transaction_id'],$org]);$tx=$q->fetch();
    $next=operations_quantity_ticks($tx['resulting_quantity']);
    hbip_assert($expected+operations_quantity_ticks($tx['quantity_delta'])===$next,'Previous balance plus recorded delta must equal result at four decimals.');
    hbip_assert(operations_quantity_ticks($balance($item))===$next,'Saved stock must equal the immutable transaction result.');$expected=$next;
}
hbip_assert((int)hbip_scalar($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND entity_id=? AND action='inventory.stock_changed'",[$org,$item['public']])===6,'Every successful movement must have a transactional audit record.');
hbip_reject(static fn()=>operations_inventory_adjust($pdo,$other,$item['public'],1,'receive',$uid),'not found','Stock writes must remain tenant scoped.');
hbip_reject(static fn()=>operations_inventory_adjust($pdo,$org,$item['public'],1,'receive',$uid,'stale',3),'Stock changed','Stale agent stock proposals must still fail.');

// Established units cannot be relabelled, even after the numeric balance returns to zero.
foreach(['g','each','case',''] as $unit){
    $before=hbip_snapshot($pdo,$org);
    hbip_reject(static fn()=>operations_inventory_save($pdo,$org,$item['public'],['baseUnit'=>$unit],$uid),'cannot be changed','Established unit changes must fail.');
    hbip_assert(hbip_snapshot($pdo,$org)===$before,'Rejected unit changes preserve dimensional history and metadata.');
}
$saved=operations_inventory_save($pdo,$org,$item['public'],['name'=>'Renamed stock','baseUnit'=>'KG','parLevel'=>'4.25'],$uid);
hbip_assert($saved['name']==='Renamed stock'&&$saved['base_unit']==='kg'&&(float)$saved['par_level']===4.25,'Safe metadata updates preserve the canonical unit spelling.');
$blank=$stock('unconfigured','');
hbip_reject(static fn()=>operations_inventory_adjust($pdo,$org,$blank['public'],1,'receive',$uid),'Set a stock unit','New unitless stock must not acquire ambiguous movement history.');
$assigned=operations_inventory_save($pdo,$org,$blank['public'],['baseUnit'=>'each'],$uid);
hbip_assert($assigned['base_unit']==='each','An unused unconfigured item may receive its initial unit.');
$legacy=$stock('legacy-unitless','',2);
hbip_reject(static fn()=>operations_inventory_save($pdo,$org,$legacy['public'],['baseUnit'=>'kg'],$uid),'quantities or costs','A missing unit cannot be assigned over an existing numeric balance.');

// Atomic audit failure: business changes must roll back, including appended ledger rows.
$trigger='hbip_audit_'.bin2hex(random_bytes(4));
$pdo->exec("CREATE TRIGGER {$trigger} BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.organization_id={$org} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HBIP forced audit failure'; END IF; END");
try{
    $before=hbip_snapshot($pdo,$org);
    hbip_reject(static fn()=>operations_inventory_adjust($pdo,$org,$item['public'],1,'receive',$uid),'HBIP forced audit failure','Audit failure must fail the movement.');
    hbip_assert(hbip_snapshot($pdo,$org)===$before,'Audit failure must roll back inventory and ledger writes.');
    hbip_reject(static fn()=>operations_inventory_save($pdo,$org,$item['public'],['name'=>'Must roll back'],$uid),'HBIP forced audit failure','Audit failure must fail metadata writes.');
    hbip_assert(hbip_snapshot($pdo,$org)===$before,'Audit failure must roll back inventory metadata.');
}finally{$pdo->exec('DROP TRIGGER '.$trigger);}

// Independent processes compete for the final available stock.
$concurrent=$stock('concurrent','kg',3);$pdo->beginTransaction();
$pdo->query('SELECT id FROM inventory_items WHERE id='.$concurrent['id'].' FOR UPDATE')->fetch();
$workers=[];
try{
    for($i=0;$i<2;$i++)$workers[]=hbip_worker(['action'=>'adjust','org'=>$org,'uid'=>$uid,'stock'=>$concurrent['public']]);
    $pdo->commit();$outcomes=array_map('hbip_finish',$workers);
    hbip_assert(count(array_filter($outcomes,static fn(array $row):bool=>$row['ok']))===1,'Concurrent reductions must have exactly one winner when stock is insufficient for both.');
    hbip_assert($balance($concurrent)==='1.0000','Concurrent reductions must preserve the exact remaining stock.');
    hbip_assert((int)hbip_scalar($pdo,'SELECT COUNT(*) FROM inventory_transactions WHERE organization_id=? AND inventory_item_id=?',[$org,$concurrent['id']])===1,'The losing reduction cannot append a ledger row.');
}finally{if($pdo->inTransaction())$pdo->rollBack();}

// DATA-04: pause before the transaction, after a legacy receiver would read status.
[$cancelPo,$cancelStock]=$order('cancel-wins');
$worker=hbip_worker(['action'=>'receive','org'=>$org,'uid'=>$uid,'po'=>$cancelPo['public_id'],'lines'=>$lines($cancelPo,5),'pause'=>true]);
$cancelled=purchasing_cancel($pdo,$org,$cancelPo['public_id'],$uid);$result=hbip_finish($worker,true);
hbip_assert($cancelled['status']==='cancelled'&&!$result['ok'],'A cancellation committed before receiving locks must prevent receipt.');
hbip_assert($balance($cancelStock)==='0.0000'&&(int)hbip_scalar($pdo,'SELECT COUNT(*) FROM goods_receipts WHERE organization_id=? AND purchase_order_id=?',[$org,$cancelPo['id']])===0,'Cancellation winner must leave no receipt or stock movement.');
hbip_assert(purchasing_order($pdo,$org,$cancelPo['public_id'])['status']==='cancelled','A stale receiver cannot reopen a cancelled PO.');

// A pending submission cannot resurrect a draft cancelled while it waits.
[$draft,$draftStock]=$order('cancel-before-submit',5,false);
$worker=hbip_worker(['action'=>'submit','org'=>$org,'uid'=>$uid,'po'=>$draft['public_id'],'pause'=>true]);
purchasing_cancel($pdo,$org,$draft['public_id'],$uid);$result=hbip_finish($worker,true);
hbip_assert(!$result['ok']&&purchasing_order($pdo,$org,$draft['public_id'])['status']==='cancelled','Concurrent cancellation must prevent stale submission.');

// Receive winner prevents a stale agent cancellation, and a completed PO remains final.
[$receivePo,$receiveStock]=$order('receive-wins');
$worker=hbip_worker(['action'=>'cancel','org'=>$org,'uid'=>$uid,'po'=>$receivePo['public_id'],'pause'=>true,'expected'=>pac_order_version($receivePo)]);
$receipt=purchasing_receive($pdo,$org,$receivePo['public_id'],$lines($receivePo,5),$uid);$result=hbip_finish($worker,true);
hbip_assert($receipt['status']==='received'&&!$result['ok'],'A completed receipt must defeat stale cancellation.');
hbip_assert($balance($receiveStock)==='5.0000','A completed receipt must increase stock once.');
hbip_reject(static fn()=>purchasing_cancel($pdo,$org,$receivePo['public_id'],$uid),'cannot be cancelled','A fresh cancellation of a completed PO must also fail.');
hbip_reject(static fn()=>purchasing_receive($pdo,$org,$receivePo['public_id'],$lines($receivePo,5),$uid),'Only submitted','A completed receipt cannot replay.');
hbip_reject(static fn()=>purchasing_cancel($pdo,$other,$receivePo['public_id'],$uid),'not found','Cancellation must remain tenant scoped.');

// Two independent receivers cannot each post the same remaining delivery.
[$racePo,$raceStock]=$order('receive-race');$pdo->beginTransaction();
$pdo->query('SELECT id FROM inventory_items WHERE id='.$raceStock['id'].' FOR UPDATE')->fetch();
$workers=[];
try{
    for($i=0;$i<2;$i++)$workers[]=hbip_worker(['action'=>'receive','org'=>$org,'uid'=>$uid,'po'=>$racePo['public_id'],'lines'=>$lines($racePo,5)]);
    $pdo->commit();$outcomes=array_map('hbip_finish',$workers);
    hbip_assert(count(array_filter($outcomes,static fn(array $row):bool=>$row['ok']))===1,'Concurrent full receipts must have exactly one winner.');
    hbip_assert($balance($raceStock)==='5.0000'&&(int)hbip_scalar($pdo,'SELECT COUNT(*) FROM goods_receipts WHERE organization_id=? AND purchase_order_id=?',[$org,$racePo['id']])===1,'Concurrent receiving cannot duplicate stock or receipts.');
}finally{if($pdo->inTransaction())$pdo->rollBack();}

// Partial cancellation preserves physical receipts and closes only the unreceived remainder.
[$partialPo,$partialStock]=$order('partial-cancel');
$request=daily_quicklist_add($pdo,$org,$uid,['type'=>'inventory','inventoryId'=>$partialStock['public'],'title'=>'Ignored'],false);
daily_quicklist_mutate($pdo,$org,$uid,'update',['id'=>$request,'requestedStockQuantity'=>5],true);
purchasing_receive($pdo,$org,$partialPo['public_id'],$lines($partialPo,2),$uid);
$actor=['id'=>$uid,'organization_id'=>$org,'permissions'=>['tasks.manage','inventory.view','purchasing.view','purchasing.manage']];
$tx=(int)hbip_scalar($pdo,'SELECT id FROM inventory_transactions WHERE organization_id=? AND inventory_item_id=? ORDER BY id DESC LIMIT 1',[$org,$partialStock['id']]);
daily_quicklist_reconcile_receipt($pdo,$org,$uid,$actor,$request,$tx,null,2);
$partial=purchasing_order($pdo,$org,$partialPo['public_id']);$closed=pac_cancel_order($pdo,$org,$partial,$uid);
hbip_assert($closed['status']==='cancelled'&&(float)$closed['items'][0]['received_base_quantity']===2.0&&$balance($partialStock)==='2.0000','Partial cancellation must preserve received quantities and stock.');
hbip_assert(pac_outstanding_lines($closed)===[]&&str_contains(pac_order_answer($closed),'cancelled)'),'Agent context must describe cancelled quantities as closed.');
$progress=daily_quicklist_receipt_progress($pdo,$org,$request);$comparison=dqpo_comparison($pdo,$org,$request);
hbip_assert($progress['receivedQuantity']===2.0&&$progress['remainingQuantity']===3.0&&$comparison['canDraft']&&$comparison['openOrders']===[],'Cancelled PO coverage must disappear while unfulfilled Need More quantities remain.');
$event=hbip_scalar($pdo,"SELECT metadata_json FROM purchase_order_events WHERE organization_id=? AND purchase_order_id=? AND event_type='cancelled' ORDER BY id DESC LIMIT 1",[$org,$partialPo['id']]);
$event=json_decode((string)$event,true,512,JSON_THROW_ON_ERROR);
hbip_assert($event['cancelledRemainder'][0]['quantity']==='3.0000'&&$event['receiptsPreserved']===true,'Cancellation history must state the exact closed remainder.');

// DATA-05: old mismatched units, blank units and archived stock fail without partial writes.
foreach(['g','each','case',''] as $unit){
    [$po,$linked]=$order('mismatch-'.($unit?:'missing'));
    $pdo->prepare('UPDATE inventory_items SET base_unit=? WHERE organization_id=? AND id=?')->execute([$unit?:null,$org,$linked['id']]);
    $requestId=daily_quicklist_add($pdo,$org,$uid,['type'=>'inventory','inventoryId'=>$linked['public'],'title'=>'Ignored','date'=>date('Y-m-d')],false);
    $before=hbip_snapshot($pdo,$org);
    hbip_reject(static fn()=>purchasing_receive($pdo,$org,$po['public_id'],$lines($po,5),$uid),'unit differs','Receiving must reject a legacy mismatched or missing stock unit.');
    hbip_assert(hbip_snapshot($pdo,$org)===$before,'Unit rejection must preserve PO, stock, receipt, price and audit state.');
    $board=daily_quicklist_purchasing_board($pdo,$org,date('Y-m-d'));
    $boardRequest=array_values(array_filter($board['requests'],static fn(array $row):bool=>$row['id']===$requestId));
    hbip_assert(count($boardRequest)===1&&$boardRequest[0]['openOrders'][0]['unit']==='kg','Need More board must label legacy order quantities with their immutable PO unit.');
}
[$precisePo,$preciseStock]=$order('last-tick');
$pdo->prepare('UPDATE purchase_order_items SET received_base_quantity=4.9999 WHERE organization_id=? AND purchase_order_id=?')->execute([$org,$precisePo['id']]);
$pdo->prepare("UPDATE purchase_orders SET status='partially_received' WHERE organization_id=? AND id=?")->execute([$org,$precisePo['id']]);
$last=purchasing_order($pdo,$org,$precisePo['public_id']);
hbip_assert(count(pac_outstanding_lines($last))===1,'The final 0.0001 unit must remain visible as outstanding.');
$receipt=purchasing_receive($pdo,$org,$last['public_id'],$lines($last,0.0001),$uid);
hbip_assert($receipt['status']==='received'&&$balance($preciseStock)==='0.0001','Receiving the final four-decimal tick must close the order exactly.');

// Failure at the final audit insertion cannot leave a partial receiving/cancellation state.
[$auditPo,$auditStock]=$order('audit-rollback');
$trigger='hbip_purchase_audit_'.bin2hex(random_bytes(4));
$pdo->exec("CREATE TRIGGER {$trigger} BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.organization_id={$org} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HBIP forced audit failure'; END IF; END");
try{
    $before=hbip_snapshot($pdo,$org);
    hbip_reject(static fn()=>purchasing_receive($pdo,$org,$auditPo['public_id'],$lines($auditPo,5),$uid),'HBIP forced audit failure','Receiving must fail when its audit cannot be committed.');
    hbip_assert(hbip_snapshot($pdo,$org)===$before,'Failed receiving audit must roll back receipt, inventory, status and event writes.');
    hbip_reject(static fn()=>purchasing_cancel($pdo,$org,$auditPo['public_id'],$uid),'HBIP forced audit failure','Cancellation must fail when its audit cannot be committed.');
    hbip_assert(hbip_snapshot($pdo,$org)===$before,'Failed cancellation audit must roll back status and event writes.');
}finally{$pdo->exec('DROP TRIGGER '.$trigger);}

echo 'hardening-inventory-purchasing-contract-ok checks='.$checks."\n";
