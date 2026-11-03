<?php
declare(strict_types=1);

/** 3K2F–3K2G: reconcile previously recorded physical receipts; never write stock. */
require_once __DIR__.'/daily-quicklist-core.php';

function daily_quicklist_receipts_ready(PDO $pdo): bool
{
    if(!daily_quicklist_ready($pdo)
        ||!restaurant_brain_table_ready($pdo,'inventory_transactions')
        ||!restaurant_brain_table_ready($pdo,'daily_quicklist_receipts'))return false;
    try{
        $pdo->query('SELECT requested_stock_quantity FROM daily_quicklist_items LIMIT 0');
        $pdo->query('SELECT allocated_quantity FROM daily_quicklist_receipts LIMIT 0');
        return true;
    }catch(PDOException){return false;}
}
function daily_quicklist_receipt_permission(array $user): bool
{
    return daily_quicklist_can_manage($user)
        &&(app_has_permission('inventory.view',$user)||app_has_permission('inventory.manage',$user));
}
function daily_quicklist_receipt_progress(PDO $pdo,int $org,string $publicId): array
{
    if(!daily_quicklist_receipts_ready($pdo))
        throw new RuntimeException('Partial receipt reconciliation is not installed. Run upgrade.php.');
    $q=$pdo->prepare("SELECT q.id,q.requested_stock_quantity,inv.base_unit
        FROM daily_quicklist_items q
        INNER JOIN inventory_items inv ON inv.organization_id=q.organization_id
            AND inv.public_id=q.source_inventory_public_id AND inv.archived_at IS NULL
        WHERE q.organization_id=? AND q.public_id=? AND q.list_type='inventory'
          AND q.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$publicId]);$item=$q->fetch(PDO::FETCH_ASSOC);
    if(!$item)throw new InvalidArgumentException('Choose an inventory-linked active Need More request.');
    $q=$pdo->prepare('SELECT COALESCE(SUM(allocated_quantity),0) FROM daily_quicklist_receipts
        WHERE organization_id=? AND item_id=?');
    $q->execute([$org,(int)$item['id']]);$received=(float)$q->fetchColumn();
    $target=$item['requested_stock_quantity']===null?null:(float)$item['requested_stock_quantity'];
    return ['requestedQuantity'=>$target,'receivedQuantity'=>$received,
        'remainingQuantity'=>$target===null?null:max(0,round($target-$received,3)),
        'unit'=>(string)$item['base_unit']];
}
/** Exact saved inventory link, actual positive receive ledger, same canonical unit. */
function daily_quicklist_receipt_candidates(PDO $pdo,int $org,string $publicId): array
{
    if(!daily_quicklist_receipts_ready($pdo))
        throw new RuntimeException('Partial receipt reconciliation is not installed. Run upgrade.php.');
    $q=$pdo->prepare("SELECT q.id,q.title,q.list_type,q.status,q.source_inventory_public_id,q.created_at
        FROM daily_quicklist_items q
        WHERE q.organization_id=? AND q.public_id=? AND q.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$publicId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||$row['list_type']!=='inventory')throw new InvalidArgumentException('Choose an existing Need More request.');
    if($row['status']==='received')throw new InvalidArgumentException('That request is already marked received.');
    $stockId=(string)($row['source_inventory_public_id']??'');
    if($stockId==='')throw new InvalidArgumentException('This request has no saved stock link. Remove and re-add it from the Inventory stock directory before reconciling.');
    $progress=daily_quicklist_receipt_progress($pdo,$org,$publicId);
    if($progress['remainingQuantity']!==null&&$progress['remainingQuantity']<=0)
        throw new InvalidArgumentException('The requested quantity is fully received.');
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
        if(trim((string)$candidate['unit'])===''||
            strcasecmp(trim((string)$candidate['unit']),trim((string)$candidate['base_unit']))!==0)continue;
        $out[]=['id'=>(int)$candidate['id'],'quantity'=>(float)$candidate['quantity_delta'],
            'unit'=>(string)$candidate['unit'],'when'=>(string)$candidate['created_at'],
            'stockName'=>(string)$candidate['name'],
            'suggestedAllocation'=>$progress['remainingQuantity']===null
                ?(float)$candidate['quantity_delta']
                :min((float)$candidate['quantity_delta'],$progress['remainingQuantity'])];
        if(count($out)>=20)break;
    }
    return $out;
}
/** Manager-only grouped progress and the immutable receipts allocated to each request. */
function daily_quicklist_receipt_links(PDO $pdo,int $org): array
{
    if(!daily_quicklist_receipts_ready($pdo))return [];
    $q=$pdo->prepare('SELECT q.public_id request_id,q.requested_stock_quantity,
        r.inventory_transaction_id,r.allocated_quantity,t.unit,t.created_at
        FROM daily_quicklist_receipts r
        INNER JOIN daily_quicklist_items q ON q.id=r.item_id AND q.organization_id=r.organization_id
        INNER JOIN inventory_transactions t ON t.id=r.inventory_transaction_id AND t.organization_id=r.organization_id
        WHERE r.organization_id=? ORDER BY r.id DESC LIMIT 300');
    $q->execute([$org]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $id=(string)$row['request_id'];$allocation=(float)$row['allocated_quantity'];
        if(!isset($out[$id])){
            $out[$id]=['receiptId'=>(int)$row['inventory_transaction_id'],
                'quantity'=>0.0,'unit'=>(string)$row['unit'],'when'=>(string)$row['created_at'],
                'requestedQuantity'=>$row['requested_stock_quantity']===null
                    ?null:(float)$row['requested_stock_quantity'],'receipts'=>[]];
        }
        $out[$id]['quantity']+=$allocation;
        $out[$id]['receipts'][]=['receiptId'=>(int)$row['inventory_transaction_id'],
            'quantity'=>$allocation,'unit'=>(string)$row['unit'],'when'=>(string)$row['created_at']];
    }
    foreach($out as &$item){
        $item['quantity']=round($item['quantity'],3);
        $item['remainingQuantity']=$item['requestedQuantity']===null?null:
            max(0,round($item['requestedQuantity']-$item['quantity'],3));
    }
    unset($item);
    return $out;
}
/** Atomic allocation of one immutable stock transaction to one Need More request. */
function daily_quicklist_reconcile_receipt(PDO $pdo,int $org,int $uid,array $user,
    string $publicId,int $transactionId,?string $expectedFingerprint=null,
    ?float $allocatedQuantity=null): array
{
    if((int)($user['organization_id']??0)!==$org||(int)($user['id']??0)!==$uid
        ||!daily_quicklist_receipt_permission($user))
        throw new DomainException('Inventory View and manager permissions are required to reconcile a receipt.');
    if(!daily_quicklist_receipts_ready($pdo))
        throw new RuntimeException('Partial receipt reconciliation is not installed. Run upgrade.php.');
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
        $q=$pdo->prepare('SELECT id FROM daily_quicklist_receipts WHERE inventory_transaction_id=? LIMIT 1');
        $q->execute([$transactionId]);
        if($q->fetchColumn()!==false)throw new InvalidArgumentException('That inventory receipt was already reconciled.');
        $q=$pdo->prepare('SELECT COALESCE(SUM(allocated_quantity),0) FROM daily_quicklist_receipts
            WHERE organization_id=? AND item_id=?');
        $q->execute([$org,(int)$row['id']]);$prior=(float)$q->fetchColumn();
        $target=$row['requested_stock_quantity']===null?null:(float)$row['requested_stock_quantity'];
        if($target===null&&$prior>0)
            throw new InvalidArgumentException('This legacy request already has a recorded receipt.');
        $remaining=$target===null?null:round($target-$prior,3);
        if($remaining!==null&&$remaining<=0)
            throw new InvalidArgumentException('The requested stock quantity has already been fulfilled.');
        $available=(float)$receipt['quantity_delta'];
        $allocation=$allocatedQuantity??($remaining===null?$available:min($available,$remaining));
        if(!is_finite($allocation)||$allocation<0.001||$allocation>1000000
            ||abs($allocation-round($allocation,3))>0.0000001
            ||$allocation>$available+0.0000001
            ||($remaining!==null&&$allocation>$remaining+0.0000001))
            throw new InvalidArgumentException('Allocate a positive quantity no larger than the recorded receipt and outstanding request, in the saved stock unit.');
        if($target===null&&abs($allocation-$available)>0.0000001)
            throw new InvalidArgumentException('Set a numeric requested stock quantity to reconcile a partial receipt.');
        $allocation=round($allocation,3);
        $pdo->prepare('INSERT INTO daily_quicklist_receipts
            (organization_id,item_id,inventory_transaction_id,allocated_quantity,linked_by)
            VALUES (?,?,?,?,?)')->execute([$org,(int)$row['id'],$transactionId,$allocation,$uid]);
        $after=round($prior+$allocation,3);
        $afterStatus=($target===null||$after>=$target-0.0000001)
            ?'received':($row['status']==='need_more'?'ordered':(string)$row['status']);
        $pdo->prepare('UPDATE daily_quicklist_items
            SET status=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?')
            ->execute([$afterStatus,$uid,(int)$row['id']]);
        $evidence=['inventoryTransactionId'=>$transactionId,'quantity'=>$allocation,
            'recordedReceiptQuantity'=>$available,'unit'=>(string)$receipt['unit'],
            'receiptAt'=>(string)$receipt['created_at'],
            'requestedQuantity'=>$target,'beforeReceived'=>$prior,'afterReceived'=>$after,
            'remainingQuantity'=>$target===null?null:max(0,round($target-$after,3)),
            'beforeStatus'=>(string)$row['status'],'afterStatus'=>$afterStatus];
        $event=$afterStatus==='received'?'receipt_reconciled':'receipt_partially_reconciled';
        daily_quicklist_event($pdo,$org,(int)$row['id'],$uid,$event,$evidence);
        daily_quicklist_notify_managers($pdo,$org,$uid,
            ($afterStatus==='received'?'Need More reconciled: ':'Need More partially received: ').$row['title'],
            $row['title'].' has '.daily_quicklist_format_quantity($after).' '.
            $receipt['unit'].' allocated from already recorded inventory receipts.'.
            ($target===null?'':' '.daily_quicklist_format_quantity(max(0,$target-$after)).' '.$receipt['unit'].' remaining.'));
        $pdo->commit();
        return ['id'=>$publicId,'status'=>$afterStatus,'receipt'=>$evidence];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        if($error instanceof PDOException&&$error->getCode()==='23000')
            throw new InvalidArgumentException('That receipt is already linked to a Need More request.',0,$error);
        throw $error;
    }
}
