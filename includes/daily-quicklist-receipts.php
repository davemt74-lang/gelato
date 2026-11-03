<?php
declare(strict_types=1);

/** 3K2F: link recorded physical receipts to Need More without adjusting stock. */
require_once __DIR__.'/daily-quicklist-core.php';

function daily_quicklist_receipts_ready(PDO $pdo): bool
{
    return daily_quicklist_ready($pdo)
        &&restaurant_brain_table_ready($pdo,'inventory_transactions')
        &&restaurant_brain_table_ready($pdo,'daily_quicklist_receipts');
}
function daily_quicklist_receipt_permission(array $user): bool
{
    return daily_quicklist_can_manage($user)
        &&(app_has_permission('inventory.view',$user)||app_has_permission('inventory.manage',$user));
}
/** Never search by fuzzy stock name: only a saved tenant-scoped inventory link. */
function daily_quicklist_receipt_candidates(PDO $pdo,int $org,string $publicId): array
{
    if(!daily_quicklist_receipts_ready($pdo))throw new RuntimeException('Receipt reconciliation is not installed. Run upgrade.php.');
    $q=$pdo->prepare("SELECT q.id,q.title,q.list_type,q.status,q.source_inventory_public_id,q.created_at
        FROM daily_quicklist_items q WHERE q.organization_id=? AND q.public_id=? AND q.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$publicId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||$row['list_type']!=='inventory')throw new InvalidArgumentException('Choose an existing Need More request.');
    if($row['status']==='received')throw new InvalidArgumentException('That request is already marked received.');
    $stockId=(string)($row['source_inventory_public_id']??'');
    if($stockId==='')throw new InvalidArgumentException('This request has no saved stock link. Remove and re-add it from the Inventory stock directory before reconciling.');
    $q=$pdo->prepare("SELECT t.id,t.quantity_delta,t.unit,t.created_at,t.note,inv.name,inv.base_unit
        FROM inventory_transactions t
        INNER JOIN inventory_items inv ON inv.id=t.inventory_item_id AND inv.organization_id=t.organization_id
        LEFT JOIN daily_quicklist_receipts link ON link.inventory_transaction_id=t.id
        WHERE t.organization_id=? AND inv.public_id=? AND inv.archived_at IS NULL
          AND t.created_at>=? AND t.transaction_type='receive' AND t.quantity_delta>0
          AND link.id IS NULL ORDER BY t.created_at DESC,t.id DESC LIMIT 30");
    $q->execute([$org,$stockId,$row['created_at']]);
    $out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $candidate){
        // The receipt must already be recorded in the stock item's canonical unit.
        if(trim((string)$candidate['unit'])===''||
            strcasecmp(trim((string)$candidate['unit']),trim((string)$candidate['base_unit']))!==0)continue;
        $out[]=['id'=>(int)$candidate['id'],'quantity'=>(float)$candidate['quantity_delta'],
            'unit'=>(string)$candidate['unit'],'when'=>(string)$candidate['created_at'],
            'stockName'=>(string)$candidate['name']];
        if(count($out)>=20)break;
    }
    return $out;
}
function daily_quicklist_receipt_links(PDO $pdo,int $org): array
{
    if(!daily_quicklist_receipts_ready($pdo))return [];
    $q=$pdo->prepare('SELECT q.public_id request_id,r.inventory_transaction_id,t.quantity_delta,t.unit,t.created_at
        FROM daily_quicklist_receipts r
        INNER JOIN daily_quicklist_items q ON q.id=r.item_id AND q.organization_id=r.organization_id
        INNER JOIN inventory_transactions t ON t.id=r.inventory_transaction_id AND t.organization_id=r.organization_id
        WHERE r.organization_id=? ORDER BY r.id DESC LIMIT 300');
    $q->execute([$org]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$out[(string)$row['request_id']]=[
        'receiptId'=>(int)$row['inventory_transaction_id'],
        'quantity'=>(float)$row['quantity_delta'],'unit'=>(string)$row['unit'],'when'=>(string)$row['created_at']];
    return $out;
}
/** One atomic status/evidence link. The stock balance and ledger are read-only. */
function daily_quicklist_reconcile_receipt(PDO $pdo,int $org,int $uid,array $user,
    string $publicId,int $transactionId,?string $expectedFingerprint=null): array
{
    if((int)($user['organization_id']??0)!==$org||(int)($user['id']??0)!==$uid
        ||!daily_quicklist_receipt_permission($user))
        throw new DomainException('Inventory View and manager permissions are required to reconcile a receipt.');
    if(!daily_quicklist_receipts_ready($pdo))throw new RuntimeException('Receipt reconciliation is not installed. Run upgrade.php.');
    if($transactionId<1)throw new InvalidArgumentException('Choose an actual inventory receipt.');
    $pdo->beginTransaction();
    try{
        $row=daily_quicklist_row_lock($pdo,$org,$publicId);
        if($row['list_type']!=='inventory'||$row['status']==='received'||!$row['source_inventory_public_id'])
            throw new InvalidArgumentException('That Need More request is not eligible for receipt reconciliation.');
        if($expectedFingerprint!==null&&(!function_exists('pqa_item_fingerprint')||
            !hash_equals($expectedFingerprint,pqa_item_fingerprint(daily_quicklist_item_public($row)))))
            throw new InvalidArgumentException('The Need More request changed. Review the receipt and try again.');
        $q=$pdo->prepare("SELECT t.id,t.quantity_delta,t.unit,t.created_at,inv.name,inv.base_unit
            FROM inventory_transactions t
            INNER JOIN inventory_items inv ON inv.id=t.inventory_item_id AND inv.organization_id=t.organization_id
            WHERE t.id=? AND t.organization_id=? AND inv.public_id=? AND inv.archived_at IS NULL
              AND t.created_at>=? AND t.transaction_type='receive' AND t.quantity_delta>0
            LIMIT 1 FOR UPDATE");
        $q->execute([$transactionId,$org,$row['source_inventory_public_id'],$row['created_at']]);
        $receipt=$q->fetch(PDO::FETCH_ASSOC);
        if(!$receipt||trim((string)$receipt['unit'])===''||
            strcasecmp(trim((string)$receipt['unit']),trim((string)$receipt['base_unit']))!==0)
            throw new InvalidArgumentException('That saved receipt does not match this stock item, unit or request date.');
        $q=$pdo->prepare('SELECT id FROM daily_quicklist_receipts WHERE item_id=? OR inventory_transaction_id=? LIMIT 1');
        $q->execute([(int)$row['id'],$transactionId]);
        if($q->fetchColumn()!==false)throw new InvalidArgumentException('That request or inventory receipt was already reconciled.');
        $pdo->prepare('INSERT INTO daily_quicklist_receipts
            (organization_id,item_id,inventory_transaction_id,linked_by) VALUES (?,?,?,?)')
            ->execute([$org,(int)$row['id'],$transactionId,$uid]);
        $pdo->prepare("UPDATE daily_quicklist_items SET status='received',updated_by=? WHERE id=?")
            ->execute([$uid,(int)$row['id']]);
        $evidence=['inventoryTransactionId'=>$transactionId,'quantity'=>(float)$receipt['quantity_delta'],
            'unit'=>(string)$receipt['unit'],'receiptAt'=>(string)$receipt['created_at'],
            'beforeStatus'=>(string)$row['status'],'afterStatus'=>'received'];
        daily_quicklist_event($pdo,$org,(int)$row['id'],$uid,'receipt_reconciled',$evidence);
        daily_quicklist_notify_managers($pdo,$org,$uid,'Need More reconciled: '.$row['title'],
            $row['title'].' was marked received against an existing inventory receipt.');
        $pdo->commit();
        return ['id'=>$publicId,'status'=>'received','receipt'=>$evidence];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        if($error instanceof PDOException&&$error->getCode()==='23000')
            throw new InvalidArgumentException('That receipt is already linked to a Need More request.',0,$error);
        throw $error;
    }
}
