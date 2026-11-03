<?php
declare(strict_types=1);
require_once __DIR__.'/purchasing-orders.php';

function purchasing_receive(PDO $pdo,int $org,string $publicId,array $lines,int $uid,string $invoice='',string $notes='',?array $expectedVersion=null): array
{
    if($pdo->inTransaction())throw new LogicException('Receiving a purchase order requires its own transaction.');
    if(!$lines||count($lines)>100)throw new InvalidArgumentException('Select between 1 and 100 purchase-order lines.');
    $requested=[];
    foreach($lines as $line){
        if(!is_array($line))throw new InvalidArgumentException('Each received line must identify a saved purchase-order item.');
        $id=trim((string)($line['itemId']??''));
        if($id===''||strlen($id)>80||isset($requested[$id]))
            throw new InvalidArgumentException('Each purchase-order item may appear only once in a receipt.');
        $quantity=operations_quantity_ticks($line['quantity']??0,'Received quantity');
        $damaged=operations_quantity_ticks(($line['damagedQuantity']??'')===''?0:$line['damagedQuantity'],'Damaged quantity');
        $missing=operations_quantity_ticks(($line['missingQuantity']??'')===''?0:$line['missingQuantity'],'Missing quantity');
        if($quantity<0||$damaged<0||$missing<0)throw new InvalidArgumentException('Receiving quantities cannot be negative.');
        if($damaged>$quantity)throw new InvalidArgumentException('Damaged quantity cannot exceed the delivered quantity.');
        $requested[$id]=$line+['itemId'=>$id];
        $requested[$id]['quantityTicks']=$quantity;$requested[$id]['damagedTicks']=$damaged;$requested[$id]['missingTicks']=$missing;
    }
    if(!array_filter($requested,static fn(array $line):bool=>$line['quantityTicks']>0))
        throw new InvalidArgumentException('Enter at least one received quantity greater than zero.');
    // Draft writers already lock stock before checking open PO headers. Receiving
    // follows that same order; header-first then stock would introduce a deadlock.
    // This read resolves identities only. Status and quantities are checked later
    // under locks, after any concurrent cancellation or receipt has committed.
    $q=$pdo->prepare('SELECT DISTINCT poi.inventory_item_id FROM purchase_order_items poi
        INNER JOIN purchase_orders po ON po.id=poi.purchase_order_id AND po.organization_id=poi.organization_id
        WHERE po.organization_id=? AND po.public_id=? AND po.archived_at IS NULL ORDER BY poi.inventory_item_id');
    $q->execute([$org,$publicId]);$stockIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $pdo->beginTransaction();
    try{
        $stock=[];
        $lock=$pdo->prepare('SELECT * FROM inventory_items WHERE organization_id=? AND id=? LIMIT 1 FOR UPDATE');
        foreach($stockIds as $id){$lock->execute([$org,$id]);$row=$lock->fetch(PDO::FETCH_ASSOC);if($row)$stock[$id]=$row;}
        $po=purchasing_order_lock($pdo,$org,$publicId,$expectedVersion);
        if(!in_array((string)$po['status'],['submitted','partially_received'],true))
            throw new InvalidArgumentException('Only submitted or partially received purchase orders can be received.');
        $q=$pdo->prepare('SELECT * FROM purchase_order_items WHERE organization_id=? AND purchase_order_id=? ORDER BY id FOR UPDATE');
        $q->execute([$org,(int)$po['id']]);$saved=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(!array_key_exists((int)$row['inventory_item_id'],$stock))
                throw new InvalidArgumentException('Purchase-order stock links changed. Review the order and try again.');
            $saved[(string)$row['public_id']]=$row;
        }
        $prepared=[];
        foreach($requested as $lineId=>$line){
            $poi=$saved[$lineId]??null;
            if(!$poi)throw new InvalidArgumentException('Purchase-order item not found.');
            $quantity=$line['quantityTicks'];if($quantity===0)continue;
            $item=$stock[(int)$poi['inventory_item_id']];
            if($item['archived_at']!==null||($item['status']??'active')!=='active')
                throw new InvalidArgumentException('An active inventory item is required for receiving.');
            $unit=trim((string)$poi['unit']);
            if($unit===''||strcasecmp($unit,trim((string)$item['base_unit']))!==0)
                throw new InvalidArgumentException('The saved purchase-order unit differs from the current stock unit. Review the item before receiving.');
            $ordered=operations_quantity_ticks($poi['ordered_base_quantity']);
            $received=operations_quantity_ticks($poi['received_base_quantity']);
            $outstanding=max(0,$ordered-$received);
            if($quantity>$outstanding)throw new InvalidArgumentException('Received quantity exceeds the outstanding purchase-order quantity.');
            if($line['missingTicks']>$outstanding-$quantity)
                throw new InvalidArgumentException('Missing quantity exceeds the unreceived purchase-order quantity.');
            $usable=$quantity-$line['damagedTicks'];
            $new=operations_quantity_ticks($item['on_hand_quantity'],'Saved stock')+$usable;
            if($new<0||$new>99999999999999)throw new InvalidArgumentException('The resulting stock quantity is outside the supported range.');
            $actual=purchasing_number(isset($line['actualPricePerPack'])&&$line['actualPricePerPack']!==''
                ?$line['actualPricePerPack']:$poi['price_per_pack'],'Actual price per pack',0,99999999.9999,true);
            if($actual!==null)$actual=operations_quantity_decimal(operations_quantity_ticks($actual,'Actual price per pack'));
            $expires=trim((string)($line['expiresOn']??''));
            if($expires!==''){
                $date=DateTimeImmutable::createFromFormat('!Y-m-d',$expires);
                if(!$date||$date->format('Y-m-d')!==$expires)throw new InvalidArgumentException('Expiry date must be a valid YYYY-MM-DD date.');
            }
            $prepared[]=['line'=>$line,'poi'=>$poi,'item'=>$item,'usable'=>$usable,'new'=>$new,
                'received'=>$received+$usable,'actual'=>$actual,'expires'=>$expires?:null];
            // Legacy orders may contain more than one line for the same stock item.
            $stock[(int)$item['id']]['on_hand_quantity']=operations_quantity_decimal($new);
        }
        $receiptPublic=purchasing_public_id('receipt');$receiptNumber='RCV-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
        $pdo->prepare('INSERT INTO goods_receipts (organization_id,purchase_order_id,vendor_id,public_id,receipt_number,received_by,vendor_invoice_number,notes) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$org,(int)$po['id'],(int)$po['vendor_id'],$receiptPublic,$receiptNumber,$uid,mb_substr(trim($invoice),0,120,'UTF-8')?:null,$notes?:null]);
        $receiptId=(int)$pdo->lastInsertId();
        foreach($prepared as $entry){
            ['line'=>$line,'poi'=>$poi,'item'=>$item,'usable'=>$usable,'new'=>$new,'actual'=>$actual]=$entry;
            $pdo->prepare('INSERT INTO goods_receipt_items (organization_id,goods_receipt_id,purchase_order_item_id,inventory_item_id,public_id,received_base_quantity,unit,actual_price_per_pack,damaged_quantity,missing_quantity,lot_code,expires_on,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$org,$receiptId,(int)$poi['id'],(int)$poi['inventory_item_id'],purchasing_public_id('rcvi'),
                    operations_quantity_decimal($line['quantityTicks']),$poi['unit'],$actual,
                    operations_quantity_decimal($line['damagedTicks']),operations_quantity_decimal($line['missingTicks']),
                    mb_substr(trim((string)($line['lotCode']??'')),0,120,'UTF-8')?:null,$entry['expires'],
                    mb_substr(trim((string)($line['notes']??'')),0,1000,'UTF-8')?:null]);
            $pdo->prepare('UPDATE inventory_items SET on_hand_quantity=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')
                ->execute([operations_quantity_decimal($new),$uid,$org,(int)$item['id']]);
            $pdo->prepare('INSERT INTO inventory_transactions (organization_id,inventory_item_id,transaction_type,quantity_delta,resulting_quantity,unit,note,source_type,source_public_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$org,(int)$item['id'],'receive',operations_quantity_decimal($usable),operations_quantity_decimal($new),$poi['unit'],
                    'Received on '.$po['order_number'].($line['damagedTicks']>0?' ('.operations_quantity_decimal($line['damagedTicks']).' damaged excluded from usable stock)':''),
                    'purchase_order',$po['public_id'],$uid]);
            $pdo->prepare('UPDATE purchase_order_items SET received_base_quantity=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')
                ->execute([operations_quantity_decimal($entry['received']),$org,(int)$poi['id']]);
            if($actual!==null){
                $pdo->prepare('INSERT INTO vendor_price_history (organization_id,vendor_id,inventory_item_id,vendor_item_id,price_per_pack,pack_size,unit,source_type,source_public_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$org,(int)$po['vendor_id'],(int)$poi['inventory_item_id'],$poi['vendor_item_id']?(int)$poi['vendor_item_id']:null,$actual,$poi['pack_size'],$poi['unit'],'receiving',$receiptPublic,$uid]);
                if($poi['vendor_item_id'])$pdo->prepare('UPDATE inventory_vendor_items SET price_per_pack=?,price_effective_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')
                    ->execute([$actual,$uid,$org,(int)$poi['vendor_item_id']]);
            }
        }
        $q=$pdo->prepare('SELECT COUNT(*) FROM purchase_order_items WHERE organization_id=? AND purchase_order_id=? AND received_base_quantity<ordered_base_quantity');
        $q->execute([$org,(int)$po['id']]);$status=(int)$q->fetchColumn()===0?'received':'partially_received';
        $q=$pdo->prepare("UPDATE purchase_orders SET status=?,received_at=IF(?='received',NOW(6),received_at),received_by=IF(?='received',?,received_by),updated_by=?,updated_at=NOW(6)
            WHERE organization_id=? AND id=? AND status=? AND archived_at IS NULL");
        $q->execute([$status,$status,$status,$uid,$uid,$org,(int)$po['id'],(string)$po['status']]);
        if($q->rowCount()!==1)throw new RuntimeException('The purchase order changed before receiving completed.');
        $metadata=['receiptId'=>$receiptPublic,'status'=>$status,'lines'=>count($prepared)];
        purchasing_event($pdo,$org,(int)$po['id'],'received','Goods receipt '.$receiptNumber.' recorded.',$uid,$metadata);
        if(function_exists('app_audit'))app_audit($pdo,$org,$uid,'receiving.goods_received','purchase_order',$publicId,
            ['status'=>$po['status']],$metadata);
        $order=purchasing_order($pdo,$org,$publicId);$pdo->commit();
        return ['order'=>$order,'receiptId'=>$receiptPublic,'receiptNumber'=>$receiptNumber,'status'=>$status];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function purchasing_receipts(PDO $pdo,int $org,int $limit=100):array{$limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT r.*,po.public_id purchase_order_public_id,po.order_number,v.name vendor_name,(SELECT COUNT(*) FROM goods_receipt_items ri WHERE ri.goods_receipt_id=r.id) line_count FROM goods_receipts r JOIN purchase_orders po ON po.id=r.purchase_order_id JOIN vendors v ON v.id=r.vendor_id WHERE r.organization_id=? ORDER BY r.received_at DESC LIMIT {$limit}");$q->execute([$org]);return $q->fetchAll();}
