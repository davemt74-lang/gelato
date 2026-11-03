<?php
declare(strict_types=1);
require_once __DIR__.'/purchasing-core.php';

/** Validate numeric catalog/order values without coercing text, NaN or negatives. */
function purchasing_number(mixed $value,string $label,float $minimum,float $maximum,bool $optional=false): ?float
{
    if($optional&&($value===null||$value===''))return null;
    if(is_bool($value)||(!is_int($value)&&!is_float($value)&&!is_string($value))||!is_numeric($value))
        throw new InvalidArgumentException($label.' must be a valid number.');
    $number=(float)$value;
    if(!is_finite($number)||$number<$minimum||$number>$maximum)
        throw new InvalidArgumentException($label.' is outside the supported range.');
    return $number;
}

/**
 * All draft writers hold the canonical inventory row before this check.
 * A locking read observes a draft committed while the caller waited for that row,
 * even when the transaction already has a repeatable-read snapshot.
 */
function purchasing_assert_no_open_order(PDO $pdo,int $org,int $stockId): void
{
    $sql="SELECT po.order_number FROM purchase_order_items poi
        INNER JOIN purchase_orders po ON po.id=poi.purchase_order_id
            AND po.organization_id=poi.organization_id
        INNER JOIN inventory_items inv ON inv.id=poi.inventory_item_id
            AND inv.organization_id=poi.organization_id
        WHERE poi.organization_id=? AND poi.inventory_item_id=?
          AND po.archived_at IS NULL AND po.status IN ('draft','submitted','partially_received')
          AND poi.ordered_base_quantity>poi.received_base_quantity
        ORDER BY po.id DESC LIMIT 1";
    if($pdo->inTransaction())$sql.=' FOR UPDATE';
    $q=$pdo->prepare($sql);$q->execute([$org,$stockId]);$open=$q->fetchColumn();
    if($open!==false)
        throw new InvalidArgumentException('An open purchase order ('.$open.') already covers this stock item. Review it in Purchasing before drafting another.');
}

function purchasing_save_vendor_item(PDO $pdo,int $org,array $in,int $uid): array
{
    $vendorPublic=trim((string)($in['vendorId']??''));
    $stockPublic=trim((string)($in['inventoryId']??''));
    $public=trim((string)($in['id']??''));
    $price=purchasing_number($in['pricePerPack']??null,'Price per pack',0,99999999.9999,true);
    $pack=purchasing_number($in['packSize']??null,'Pack size',0.0001,1000000,true);
    $units=purchasing_number($in['unitsPerPack']??null,'Units per pack',0.0001,1000000,true);
    $preferred=!empty($in['preferred'])?1:0;
    $sku=mb_substr(trim((string)($in['vendorSku']??'')),0,120,'UTF-8')?:null;
    $notes=mb_substr(trim((string)($in['notes']??'')),0,1000,'UTF-8')?:null;
    if($pdo->inTransaction())throw new LogicException('A vendor mapping update requires its own transaction.');
    $pdo->beginTransaction();
    try{
        // Same lock as every draft writer and the preferred-vendor control.
        $q=$pdo->prepare("SELECT * FROM inventory_items WHERE organization_id=? AND public_id=?
            AND archived_at IS NULL AND status='active' LIMIT 1 FOR UPDATE");
        $q->execute([$org,$stockPublic]);$item=$q->fetch(PDO::FETCH_ASSOC);
        if(!$item)throw new InvalidArgumentException('Active inventory item not found.');
        $q=$pdo->prepare("SELECT * FROM vendors WHERE organization_id=? AND public_id=?
            AND archived_at IS NULL AND status='active' LIMIT 1 FOR UPDATE");
        $q->execute([$org,$vendorPublic]);$vendor=$q->fetch(PDO::FETCH_ASSOC);
        if(!$vendor)throw new InvalidArgumentException('Active vendor not found.');
        $unit=mb_substr(trim((string)($in['packUnit']??'')),0,80,'UTF-8')?:(string)$item['base_unit'];
        if($preferred&&($pack===null||strcasecmp($unit,trim((string)$item['base_unit']))!==0))
            throw new InvalidArgumentException('A preferred vendor needs a valid pack size in the saved stock unit.');
        $unitCost=$price!==null&&$pack!==null?$price/$pack:null;
        if($preferred&&$unitCost!==null&&(!is_finite($unitCost)||$unitCost>99999999.9999))
            throw new InvalidArgumentException('The preferred vendor unit cost exceeds the supported inventory cost limit.');
        $name=mb_substr(trim((string)($in['vendorItemName']??'')),0,240,'UTF-8')?:(string)$item['name'];
        if($public!==''){
            $q=$pdo->prepare("SELECT id FROM inventory_vendor_items WHERE organization_id=? AND public_id=?
                AND inventory_item_id=? AND vendor_id=? AND archived_at IS NULL AND status='active' LIMIT 1 FOR UPDATE");
            $q->execute([$org,$public,(int)$item['id'],(int)$vendor['id']]);
            if($q->fetchColumn()===false)
                throw new InvalidArgumentException('The vendor mapping does not belong to the selected stock item and vendor.');
        }
        if($preferred)$pdo->prepare('UPDATE inventory_vendor_items SET is_preferred=0 WHERE organization_id=? AND inventory_item_id=?')
            ->execute([$org,(int)$item['id']]);
        if($public===''){
            $public=purchasing_public_id('vitem');
            $pdo->prepare('INSERT INTO inventory_vendor_items
                (organization_id,inventory_item_id,vendor_id,public_id,vendor_sku,vendor_item_name,
                 pack_size,pack_unit,units_per_pack,price_per_pack,price_effective_at,is_preferred,notes,created_by,updated_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW(6),?,?,?,?)')
                ->execute([$org,(int)$item['id'],(int)$vendor['id'],$public,$sku,$name,$pack,$unit,$units,$price,$preferred,$notes,$uid,$uid]);
        }else{
            $pdo->prepare('UPDATE inventory_vendor_items SET vendor_sku=?,vendor_item_name=?,pack_size=?,pack_unit=?,
                units_per_pack=?,price_per_pack=?,price_effective_at=NOW(6),is_preferred=?,notes=?,updated_by=?,updated_at=NOW(6)
                WHERE organization_id=? AND public_id=? AND inventory_item_id=? AND vendor_id=?')
                ->execute([$sku,$name,$pack,$unit,$units,$price,$preferred,$notes,$uid,$org,$public,(int)$item['id'],(int)$vendor['id']]);
        }
        if($price!==null)$pdo->prepare("INSERT INTO vendor_price_history
            (organization_id,vendor_id,inventory_item_id,vendor_item_id,price_per_pack,pack_size,unit,source_type,source_public_id,created_by)
            SELECT organization_id,vendor_id,inventory_item_id,id,price_per_pack,pack_size,pack_unit,'catalog',public_id,?
            FROM inventory_vendor_items WHERE organization_id=? AND public_id=?")
            ->execute([$uid,$org,$public]);
        if($preferred){
            $pdo->prepare('UPDATE inventory_items SET vendor_name=?,vendor_sku=?,unit_cost=COALESCE(?,unit_cost),updated_by=?,updated_at=NOW(6)
                WHERE organization_id=? AND id=?')->execute([(string)$vendor['name'],$sku,$unitCost,$uid,$org,(int)$item['id']]);
        }
        $saved=[];
        foreach(purchasing_vendor_catalog($pdo,$org,$vendorPublic) as $row)
            if((string)$row['public_id']===$public){$saved=$row;break;}
        if(!$saved)throw new RuntimeException('The saved vendor mapping could not be read.');
        $pdo->commit();
        return $saved;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
function purchasing_suggestions(PDO $pdo,int $org):array{
    $commitReady=restaurant_brain_table_ready($pdo,'inventory_commitments');
    $commitJoin=$commitReady?" LEFT JOIN (SELECT organization_id,inventory_item_id,SUM(quantity) committed_quantity FROM inventory_commitments WHERE status='active' AND commitment_date<=DATE_ADD(CURDATE(),INTERVAL 7 DAY) GROUP BY organization_id,inventory_item_id) wc ON wc.organization_id=i.organization_id AND wc.inventory_item_id=i.id ":'';
    $commitSelect=$commitReady?',COALESCE(MAX(wc.committed_quantity),0) committed_quantity':'';
    $q=$pdo->prepare("SELECT i.*,COALESCE(MAX(CASE WHEN p.plan_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND p.status IN ('draft','published') THEN f.restock_quantity ELSE 0 END),0) forecast_restock,COALESCE(MIN(CASE WHEN p.plan_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND p.status IN ('draft','published') THEN f.projected_quantity ELSE NULL END),i.on_hand_quantity) forecast_projected{$commitSelect} FROM inventory_items i LEFT JOIN inventory_forecasts f ON f.inventory_item_id=i.id LEFT JOIN prep_plans p ON p.id=f.plan_id {$commitJoin} WHERE i.organization_id=? AND i.archived_at IS NULL AND i.status='active' GROUP BY i.id ORDER BY i.name");$q->execute([$org]);$rows=[];
    $map=$pdo->prepare("SELECT vi.*,v.public_id vendor_public_id,v.name vendor_name,v.minimum_order_amount,v.lead_time_days,v.delivery_days_json,v.cutoff_time FROM inventory_vendor_items vi JOIN vendors v ON v.id=vi.vendor_id WHERE vi.organization_id=? AND vi.inventory_item_id=? AND vi.archived_at IS NULL AND vi.status='active' AND v.status='active' ORDER BY vi.is_preferred DESC,vi.price_per_pack IS NULL,vi.price_per_pack LIMIT 1");
    $ordered=$pdo->prepare("SELECT COALESCE(SUM(GREATEST(0,poi.ordered_base_quantity-poi.received_base_quantity)),0) FROM purchase_order_items poi JOIN purchase_orders po ON po.id=poi.purchase_order_id AND po.organization_id=poi.organization_id WHERE poi.organization_id=? AND poi.inventory_item_id=? AND po.archived_at IS NULL AND po.status IN ('draft','submitted','partially_received')");
    foreach($q->fetchAll() as $r){
        $on=(float)$r['on_hand_quantity'];$parNeed=$r['par_level']!==null?max(0,(float)$r['par_level']-$on):0;$reorderNeed=($r['reorder_point']!==null&&$on<=(float)$r['reorder_point'])?max($parNeed,(float)$r['reorder_point']-$on):0;
        $committed=$commitReady?(float)($r['committed_quantity']??0):0.0;$forecastProjected=(float)($r['forecast_projected']??$on);$afterCommitments=$forecastProjected-$committed;$target=$r['par_level']!==null?(float)$r['par_level']:($r['reorder_point']!==null?(float)$r['reorder_point']:0.0);$commitmentNeed=max(0,$target-$afterCommitments);$commitmentShortage=max(0,-$afterCommitments);
        $gross=max($parNeed,$reorderNeed,(float)$r['forecast_restock'],$commitmentNeed,$commitmentShortage);$ordered->execute([$org,(int)$r['id']]);$openQty=(float)$ordered->fetchColumn();$need=max(0,$gross-$openQty);if($need<=0)continue;
        $map->execute([$org,(int)$r['id']]);$m=$map->fetch()?:null;$pack=$m&&$m['pack_size']!==null?(float)$m['pack_size']:0;$rows[]=['inventoryId'=>$r['public_id'],'name'=>$r['name'],'unit'=>$r['base_unit'],'onHand'=>$on,'par'=>$r['par_level']!==null?(float)$r['par_level']:null,'forecastRestock'=>(float)$r['forecast_restock'],'forecastProjected'=>$forecastProjected,'committedQuantity'=>$committed,'availableToPromise'=>round($on-$committed,4),'projectedAfterCommitments'=>round($afterCommitments,4),'openOrderQuantity'=>$openQty,'grossNeedQuantity'=>$gross,'needQuantity'=>$need,'vendor'=>$m?['id'=>$m['vendor_public_id'],'name'=>$m['vendor_name'],'vendorItemId'=>$m['public_id'],'sku'=>$m['vendor_sku'],'packSize'=>$pack,'packUnit'=>$m['pack_unit'],'pricePerPack'=>$m['price_per_pack']!==null?(float)$m['price_per_pack']:null,'preferred'=>(bool)$m['is_preferred'],'minimumOrderAmount'=>$m['minimum_order_amount']!==null?(float)$m['minimum_order_amount']:null,'leadTimeDays'=>(int)$m['lead_time_days'],'deliveryDays'=>$m['delivery_days_json']?json_decode((string)$m['delivery_days_json'],true):[],'cutoffTime'=>$m['cutoff_time']]:null,'suggestedPacks'=>$pack>0?(float)ceil($need/$pack):$need];
    }
    usort($rows,static fn($a,$b)=>$b['needQuantity']<=>$a['needQuantity']);return $rows;
}
function purchasing_orders(PDO $pdo,int $org,string $status=''):array{$where='po.organization_id=? AND po.archived_at IS NULL';$p=[$org];if($status!==''){$where.=' AND po.status=?';$p[]=$status;}$q=$pdo->prepare("SELECT po.*,v.name vendor_name,(SELECT COUNT(*) FROM purchase_order_items x WHERE x.purchase_order_id=po.id) item_count FROM purchase_orders po JOIN vendors v ON v.id=po.vendor_id WHERE {$where} ORDER BY FIELD(po.status,'draft','submitted','partially_received','received','cancelled'),COALESCE(po.expected_delivery_date,'9999-12-31'),po.created_at DESC");$q->execute($p);return $q->fetchAll();}
function purchasing_order(PDO $pdo,int $org,string $publicId):?array{$q=$pdo->prepare('SELECT po.*,v.name vendor_name,v.public_id vendor_public_id,v.minimum_order_amount,v.lead_time_days,v.cutoff_time FROM purchase_orders po JOIN vendors v ON v.id=po.vendor_id WHERE po.organization_id=? AND po.public_id=? AND po.archived_at IS NULL LIMIT 1');$q->execute([$org,$publicId]);$po=$q->fetch();if(!$po)return null;$i=$pdo->prepare('SELECT poi.*,inv.public_id inventory_public_id,inv.name inventory_name FROM purchase_order_items poi JOIN inventory_items inv ON inv.id=poi.inventory_item_id WHERE poi.organization_id=? AND poi.purchase_order_id=? ORDER BY poi.id');$i->execute([$org,(int)$po['id']]);$po['items']=$i->fetchAll();$e=$pdo->prepare('SELECT event_type,summary,created_at FROM purchase_order_events WHERE organization_id=? AND purchase_order_id=? ORDER BY id DESC LIMIT 40');$e->execute([$org,(int)$po['id']]);$po['events']=$e->fetchAll();$po['documents']=purchasing_documents_for_order($pdo,$org,(int)$po['id']);return $po;}
function purchasing_recalc(PDO $pdo,int $org,int $poId):void{$q=$pdo->prepare('SELECT COALESCE(SUM(line_total),0) FROM purchase_order_items WHERE organization_id=? AND purchase_order_id=?');$q->execute([$org,$poId]);$subtotal=(float)$q->fetchColumn();$pdo->prepare('UPDATE purchase_orders SET subtotal=?,total_amount=?+tax_amount+shipping_amount,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$subtotal,$subtotal,$org,$poId]);}
function purchasing_create_order(PDO $pdo,int $org,string $vendorPublic,array $lines,int $uid,?string $expected=null,string $notes=''): array
{
    if($pdo->inTransaction())throw new LogicException('A purchase draft requires its own transaction.');
    if(!$lines||count($lines)>100)throw new InvalidArgumentException('A purchase order needs between 1 and 100 lines.');
    $requested=[];
    foreach($lines as $line){
        if(!is_array($line)||!is_string($line['inventoryId']??null))
            throw new InvalidArgumentException('Choose a saved inventory item for each order line.');
        $public=trim($line['inventoryId']);$key=strtolower($public);
        if($public===''||strlen($public)>80||isset($requested[$key]))
            throw new InvalidArgumentException('Each saved inventory item may appear only once in a purchase order.');
        $line['packs']=purchasing_number($line['packs']??null,'Ordered packs',0.001,100000);
        if(abs($line['packs']-round($line['packs'],3))>0.00000001)
            throw new InvalidArgumentException('Ordered packs support at most three decimal places.');
        $requested[$key]=$line;
    }
    // Resolve canonical IDs before opening the transaction, then revalidate each
    // row under its lock. All multi-item writers lock in numeric stock-ID order.
    $placeholders=implode(',',array_fill(0,count($requested),'?'));
    $q=$pdo->prepare("SELECT id,public_id FROM inventory_items WHERE organization_id=? AND public_id IN ({$placeholders})");
    $q->execute(array_merge([$org],array_map(static fn(array $line):string=>trim($line['inventoryId']),array_values($requested))));
    $identities=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($identities)!==count($requested))throw new InvalidArgumentException('An inventory item in the order was not found.');
    usort($identities,static fn(array $a,array $b):int=>(int)$a['id']<=>(int)$b['id']);
    $pdo->beginTransaction();
    try{
        $stock=[];
        $lock=$pdo->prepare("SELECT * FROM inventory_items WHERE organization_id=? AND id=? AND public_id=?
            AND archived_at IS NULL AND status='active' LIMIT 1 FOR UPDATE");
        foreach($identities as $identity){
            $lock->execute([$org,(int)$identity['id'],(string)$identity['public_id']]);
            $item=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$item)throw new InvalidArgumentException('An active inventory item in the order was not found.');
            $stock[]=$item;
        }
        $q=$pdo->prepare("SELECT * FROM vendors WHERE organization_id=? AND public_id=?
            AND archived_at IS NULL AND status='active' LIMIT 1 FOR UPDATE");
        $q->execute([$org,trim($vendorPublic)]);$vendor=$q->fetch(PDO::FETCH_ASSOC);
        if(!$vendor)throw new InvalidArgumentException('Active vendor not found.');
        $mapping=$pdo->prepare("SELECT * FROM inventory_vendor_items WHERE organization_id=? AND vendor_id=?
            AND inventory_item_id=? AND archived_at IS NULL AND status='active'
            ORDER BY is_preferred DESC,price_per_pack IS NULL,id LIMIT 1 FOR UPDATE");
        $prepared=[];
        foreach($stock as $item){
            purchasing_assert_no_open_order($pdo,$org,(int)$item['id']);
            $line=$requested[strtolower((string)$item['public_id'])];
            $mapping->execute([$org,(int)$vendor['id'],(int)$item['id']]);$m=$mapping->fetch(PDO::FETCH_ASSOC);
            if(!$m)throw new InvalidArgumentException('Map each stock item to this active vendor before ordering.');
            $unit=trim((string)$m['pack_unit']);
            if($unit===''||strcasecmp($unit,trim((string)$item['base_unit']))!==0)
                throw new InvalidArgumentException('Vendor pack unit differs from the saved stock unit. Configure a verified conversion before ordering.');
            $savedPack=purchasing_number($m['pack_size'],'Saved vendor pack size',0.0001,1000000);
            $pack=array_key_exists('packSize',$line)&&$line['packSize']!==null&&$line['packSize']!==''
                ?purchasing_number($line['packSize'],'Pack size',0.0001,1000000):$savedPack;
            $price=array_key_exists('pricePerPack',$line)&&$line['pricePerPack']!==null&&$line['pricePerPack']!==''
                ?purchasing_number($line['pricePerPack'],'Price per pack',0,99999999.9999)
                :purchasing_number($m['price_per_pack'],'Saved vendor price',0,99999999.9999,true);
            $base=$line['packs']*$pack;
            if(!is_finite($base)||$base<0.0001||$base>1000000)
                throw new InvalidArgumentException('Calculated order quantity is outside the supported range.');
            $total=$price===null?0:round($price*$line['packs'],2);
            if(!is_finite($total)||$total>999999999999.99)
                throw new InvalidArgumentException('The order line total is outside the supported range.');
            $prepared[]=['stock'=>$item,'mapping'=>$m,'packs'=>$line['packs'],'pack'=>$pack,'price'=>$price,
                'quantity'=>round($base,4),'total'=>$total,
                'notes'=>mb_substr(trim((string)($line['notes']??'')),0,1000,'UTF-8')?:null];
        }
        $public=purchasing_public_id('po');$number='PO-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
        $pdo->prepare('INSERT INTO purchase_orders (organization_id,public_id,vendor_id,order_number,expected_delivery_date,notes,created_by,updated_by)
            VALUES (?,?,?,?,?,?,?,?)')->execute([$org,$public,(int)$vendor['id'],$number,$expected?:null,$notes?:null,$uid,$uid]);
        $poId=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare('INSERT INTO purchase_order_items
            (organization_id,purchase_order_id,inventory_item_id,vendor_item_id,public_id,description,
             ordered_packs,pack_size,unit,ordered_base_quantity,price_per_pack,line_total,notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($prepared as $line)$ins->execute([$org,$poId,(int)$line['stock']['id'],(int)$line['mapping']['id'],
            purchasing_public_id('poi'),$line['stock']['name'],$line['packs'],$line['pack'],$line['stock']['base_unit'],
            $line['quantity'],$line['price'],$line['total'],$line['notes']]);
        purchasing_recalc($pdo,$org,$poId);
        purchasing_event($pdo,$org,$poId,'created','Purchase order created as draft.',$uid,['vendor'=>$vendor['name']]);
        $pdo->commit();
        return purchasing_order($pdo,$org,$public)??[];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
/** Every status transition rechecks the canonical header inside its transaction. */
function purchasing_order_lock(PDO $pdo,int $org,string $publicId,?array $expectedVersion=null): array
{
    if(!$pdo->inTransaction())throw new LogicException('Purchase-order locks require a transaction.');
    $q=$pdo->prepare('SELECT * FROM purchase_orders WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1 FOR UPDATE');
    $q->execute([$org,$publicId]);$po=$q->fetch(PDO::FETCH_ASSOC);
    if(!$po)throw new InvalidArgumentException('Purchase order not found.');
    if($expectedVersion!==null&&((string)($expectedVersion['status']??'')!==(string)$po['status']
        ||(string)($expectedVersion['updatedAt']??'')!==(string)$po['updated_at']))
        throw new InvalidArgumentException('That purchase order changed after the action was reviewed. Review the current PO and try again.');
    return $po;
}
function purchasing_submit(PDO $pdo,int $org,string $publicId,int $uid,?array $expectedVersion=null): array
{
    if($pdo->inTransaction())throw new LogicException('Submitting a purchase order requires its own transaction.');
    $pdo->beginTransaction();
    try{
        $po=purchasing_order_lock($pdo,$org,$publicId,$expectedVersion);
        if($po['status']!=='draft')throw new InvalidArgumentException('Only draft purchase orders can be submitted.');
        $q=$pdo->prepare("SELECT minimum_order_amount FROM vendors WHERE organization_id=? AND id=? AND archived_at IS NULL AND status='active'");
        $q->execute([$org,(int)$po['vendor_id']]);$minimum=$q->fetchColumn();
        if($minimum===false)throw new InvalidArgumentException('The purchase-order vendor is unavailable.');
        if((float)$minimum>0&&(float)$po['total_amount']<(float)$minimum)
            throw new InvalidArgumentException('This purchase order is below the vendor minimum of $'.number_format((float)$minimum,2).'.');
        $q=$pdo->prepare("UPDATE purchase_orders SET status='submitted',submitted_at=NOW(6),submitted_by=?,updated_by=?,updated_at=NOW(6)
            WHERE organization_id=? AND id=? AND status='draft' AND archived_at IS NULL");
        $q->execute([$uid,$uid,$org,(int)$po['id']]);
        if($q->rowCount()!==1)throw new RuntimeException('The purchase order changed before submission.');
        purchasing_event($pdo,$org,(int)$po['id'],'submitted','Purchase order marked submitted.',$uid);
        if(function_exists('app_audit'))app_audit($pdo,$org,$uid,'purchasing.po_submitted','purchase_order',$publicId,
            ['status'=>'draft'],['status'=>'submitted']);
        $saved=purchasing_order($pdo,$org,$publicId);$pdo->commit();return $saved??[];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

/** Cancels the outstanding remainder only. Existing stock and receipt history are immutable. */
function purchasing_cancel(PDO $pdo,int $org,string $publicId,int $uid,?array $expectedVersion=null): array
{
    if($pdo->inTransaction())throw new LogicException('Cancelling a purchase order requires its own transaction.');
    $pdo->beginTransaction();
    try{
        $po=purchasing_order_lock($pdo,$org,$publicId,$expectedVersion);
        if(!in_array((string)$po['status'],['draft','submitted','partially_received'],true))
            throw new InvalidArgumentException('A received or already-cancelled purchase order cannot be cancelled.');
        $q=$pdo->prepare('SELECT public_id,ordered_base_quantity,received_base_quantity,unit FROM purchase_order_items WHERE organization_id=? AND purchase_order_id=? ORDER BY id');
        $q->execute([$org,(int)$po['id']]);$remainder=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $line){
            $quantity=max(0,operations_quantity_ticks($line['ordered_base_quantity'])-operations_quantity_ticks($line['received_base_quantity']));
            if($quantity>0)$remainder[]=['itemId'=>$line['public_id'],'quantity'=>operations_quantity_decimal($quantity),'unit'=>$line['unit']];
        }
        // A legacy inconsistent header must not permit cancellation of a completed order.
        if(!$remainder&&$po['status']!=='draft')throw new InvalidArgumentException('A fully received purchase order cannot be cancelled.');
        $q=$pdo->prepare("UPDATE purchase_orders SET status='cancelled',cancelled_at=NOW(6),updated_by=?,updated_at=NOW(6)
            WHERE organization_id=? AND id=? AND status=? AND archived_at IS NULL");
        $q->execute([$uid,$org,(int)$po['id'],(string)$po['status']]);
        if($q->rowCount()!==1)throw new RuntimeException('The purchase order changed before cancellation.');
        $metadata=['previousStatus'=>$po['status'],'cancelledRemainder'=>$remainder,'receiptsPreserved'=>true];
        purchasing_event($pdo,$org,(int)$po['id'],'cancelled','Unreceived purchase-order quantities cancelled; existing receipts and stock preserved.',$uid,$metadata);
        if(function_exists('app_audit'))app_audit($pdo,$org,$uid,'purchasing.po_cancelled','purchase_order',$publicId,
            ['status'=>$po['status']],['status'=>'cancelled']+$metadata);
        $saved=purchasing_order($pdo,$org,$publicId);$pdo->commit();return $saved??[];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function purchasing_price_compare(PDO $pdo,int $org,string $query): array
{
    $q=$pdo->prepare("SELECT i.public_id inventory_id,i.name inventory_name,v.public_id vendor_id,v.name vendor_name,
        vi.vendor_sku,vi.pack_size,vi.pack_unit,vi.price_per_pack,
        CASE WHEN vi.pack_size>0 AND vi.price_per_pack>=0 AND TRIM(vi.pack_unit)<>''
            AND LOWER(TRIM(vi.pack_unit))=LOWER(TRIM(i.base_unit))
            THEN vi.price_per_pack/vi.pack_size ELSE NULL END unit_price,vi.is_preferred
        FROM inventory_vendor_items vi
        INNER JOIN inventory_items i ON i.id=vi.inventory_item_id AND i.organization_id=vi.organization_id
        INNER JOIN vendors v ON v.id=vi.vendor_id AND v.organization_id=vi.organization_id
        WHERE vi.organization_id=? AND vi.archived_at IS NULL AND vi.status='active'
          AND v.archived_at IS NULL AND v.status='active' AND i.archived_at IS NULL AND i.status='active' AND i.name LIKE ?
        ORDER BY unit_price IS NULL,unit_price,v.name,vi.id LIMIT 40");
    $q->execute([$org,'%'.trim($query).'%']);return $q->fetchAll();
}
function purchasing_summary(PDO $pdo,int $org):array{$s=purchasing_suggestions($pdo,$org);$q=$pdo->prepare("SELECT SUM(status='draft') drafts,SUM(status IN ('submitted','partially_received')) open_orders,COALESCE(SUM(CASE WHEN status IN ('submitted','partially_received') THEN total_amount ELSE 0 END),0) committed FROM purchase_orders WHERE organization_id=? AND archived_at IS NULL");$q->execute([$org]);$r=$q->fetch()?:[];return ['suggestions'=>count($s),'unmapped'=>count(array_filter($s,static fn($x)=>empty($x['vendor']))),'drafts'=>(int)($r['drafts']??0),'openOrders'=>(int)($r['open_orders']??0),'committed'=>(float)($r['committed']??0)];}
