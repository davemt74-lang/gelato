<?php
declare(strict_types=1);

/**
 * 3K2I-A: one reviewed draft purchase order from one outstanding, stock-linked
 * Need More request. Canonical Purchasing remains the owner of purchase orders
 * and Operations/Receiving remains the only writer of physical stock.
 */
require_once __DIR__.'/daily-quicklist-purchasing-board.php';
require_once __DIR__.'/purchasing-orders.php';

function dqpo_can_draft(array $actor): bool
{
    return daily_quicklist_receipt_permission($actor)
        && app_has_permission('purchasing.view',$actor)
        && app_has_permission('purchasing.manage',$actor);
}

function dqpo_ready(PDO $pdo): bool
{
    return daily_quicklist_receipts_ready($pdo)
        && purchasing_ready($pdo);
}

/** Read the current single-item quote; never guess quantities from free-form notes. */
function dqpo_quote(PDO $pdo,int $org,string $publicId,?array $lockedRequest=null): array
{
    if(!dqpo_ready($pdo))throw new RuntimeException('Purchasing/Need More is not installed. Run upgrade.php.');
    $publicId=trim($publicId);
    if(!preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D',$publicId))
        throw new InvalidArgumentException('Choose an existing Need More request.');
    if($lockedRequest===null){
        $q=$pdo->prepare('SELECT * FROM daily_quicklist_items WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
        $q->execute([$org,$publicId]);$request=$q->fetch(PDO::FETCH_ASSOC);
    }else $request=$lockedRequest;
    if(!$request||$request['list_type']!=='inventory'||$request['status']==='received'
        ||empty($request['source_inventory_public_id']))
        throw new InvalidArgumentException('This Need More request needs an active saved inventory item.');
    $q=$pdo->prepare("SELECT id,public_id,name,base_unit FROM inventory_items
        WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL LIMIT 1");
    $q->execute([$org,(string)$request['source_inventory_public_id']]);$stock=$q->fetch(PDO::FETCH_ASSOC);
    if(!$stock)throw new InvalidArgumentException('The saved inventory item is unavailable.');

    $progress=daily_quicklist_receipt_progress($pdo,$org,$publicId);
    $remaining=$progress['remainingQuantity'];
    if($progress['requestedQuantity']===null||$remaining===null)
        throw new InvalidArgumentException('Set an explicit numeric Need More target in the saved stock unit first.');
    if($remaining<0.001)
        throw new InvalidArgumentException('There is no remaining Need More quantity to purchase.');

    // Block existing open PO lines, regardless of their vendor. Drafts count.
    $q=$pdo->prepare("SELECT po.order_number FROM purchase_order_items poi
        INNER JOIN purchase_orders po ON po.id=poi.purchase_order_id AND po.organization_id=poi.organization_id
        WHERE poi.organization_id=? AND poi.inventory_item_id=?
          AND po.archived_at IS NULL AND po.status IN ('draft','submitted','partially_received')
          AND poi.ordered_base_quantity>poi.received_base_quantity
        ORDER BY po.id DESC LIMIT 1");
    $q->execute([$org,(int)$stock['id']]);$open=$q->fetchColumn();
    if($open!==false)
        throw new InvalidArgumentException('An open purchase order ('.$open.') already covers this stock item. Review it in Purchasing before drafting another.');

    // 3K2I-A uses the active preferred mapping. Vendor choice is a later phase.
    $q=$pdo->prepare("SELECT vi.id,vi.pack_size,vi.pack_unit,vi.price_per_pack,vi.is_preferred,
        v.public_id vendor_public_id,v.name vendor_name
        FROM inventory_vendor_items vi
        INNER JOIN vendors v ON v.id=vi.vendor_id AND v.organization_id=vi.organization_id
        WHERE vi.organization_id=? AND vi.inventory_item_id=?
          AND vi.archived_at IS NULL AND vi.status='active'
          AND v.archived_at IS NULL AND v.status='active'
        ORDER BY vi.is_preferred DESC,vi.price_per_pack IS NULL,vi.id ASC LIMIT 1");
    $q->execute([$org,(int)$stock['id']]);$vendorItem=$q->fetch(PDO::FETCH_ASSOC);
    if(!$vendorItem)throw new InvalidArgumentException('Map this stock item to an active vendor in Purchasing first.');
    $pack=(float)($vendorItem['pack_size']??0);
    if(!is_finite($pack)||$pack<0.001||$pack>1000000)
        throw new InvalidArgumentException('Set a valid positive vendor pack size before drafting a PO.');
    if(trim((string)$vendorItem['pack_unit'])===''||
        strcasecmp(trim((string)$vendorItem['pack_unit']),trim((string)$stock['base_unit']))!==0)
        throw new InvalidArgumentException('Vendor pack unit differs from the saved stock unit. Configure a verified conversion in Purchasing before ordering.');
    $packs=(int)ceil(($remaining-0.00000001)/$pack);
    if($packs<1||$packs>100000)
        throw new InvalidArgumentException('The quantity exceeds the single-order pack limit.');
    $ordered=round($packs*$pack,4);
    if($ordered>1000000)
        throw new InvalidArgumentException('The calculated order quantity exceeds the safe order limit.');
    $price=$vendorItem['price_per_pack']===null?null:(float)$vendorItem['price_per_pack'];
    if($price!==null&&(!is_finite($price)||$price<0))throw new InvalidArgumentException('Vendor price is invalid.');
    $snapshot=[
        'requestId'=>$publicId,'requestUpdated'=>(string)$request['updated_at'],
        'requested'=>(float)$progress['requestedQuantity'],'allocated'=>(float)$progress['receivedQuantity'],
        'remaining'=>(float)$remaining,'unit'=>(string)$stock['base_unit'],
        'stockId'=>(string)$stock['public_id'],'vendorId'=>(string)$vendorItem['vendor_public_id'],
        'vendorItemId'=>(int)$vendorItem['id'],'packSize'=>$pack,
        'packs'=>$packs,'price'=>$price,
    ];
    return [
        'requestId'=>$publicId,'name'=>(string)$stock['name'],
        'remainingQuantity'=>(float)$remaining,'unit'=>(string)$stock['base_unit'],
        'vendor'=>(string)$vendorItem['vendor_name'],
        'vendorId'=>(string)$vendorItem['vendor_public_id'],
        'packs'=>$packs,'packSize'=>$pack,'orderedQuantity'=>$ordered,
        'overageQuantity'=>max(0.0,round($ordered-$remaining,4)),
        'pricePerPack'=>$price,'estimatedSubtotal'=>$price===null?null:round($price*$packs,2),
        'quoteHash'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
        'warning'=>$price===null?'Vendor price is not saved; review the PO subtotal before submission.':null,
    ];
}

/** Serializes requests for the same stock item before checking for an open PO. */
function dqpo_create_draft(PDO $pdo,int $org,int $uid,array $actor,string $publicId,string $quoteHash): array
{
    if((int)($actor['organization_id']??0)!==$org||(int)($actor['id']??0)!==$uid||!dqpo_can_draft($actor))
        throw new DomainException('Purchasing management and Need More manager permissions are required.');
    if(!preg_match('/^[a-f0-9]{64}$/D',$quoteHash))
        throw new InvalidArgumentException('Preview the current draft quantities before confirming.');
    if(!dqpo_ready($pdo))throw new RuntimeException('Purchasing/Need More is not installed. Run upgrade.php.');
    $pdo->beginTransaction();
    try{
        $request=daily_quicklist_row_lock($pdo,$org,$publicId);
        if($request['list_type']!=='inventory'||empty($request['source_inventory_public_id']))
            throw new InvalidArgumentException('Select a stock-linked Need More request.');
        $lock=$pdo->prepare("SELECT id FROM inventory_items WHERE organization_id=? AND public_id=?
            AND archived_at IS NULL AND status='active' LIMIT 1 FOR UPDATE");
        $lock->execute([$org,(string)$request['source_inventory_public_id']]);
        if($lock->fetchColumn()===false)throw new InvalidArgumentException('The stock item has changed.');
        $quote=dqpo_quote($pdo,$org,$publicId,$request);
        if(!hash_equals($quote['quoteHash'],$quoteHash))
            throw new InvalidArgumentException('Need More quantity or vendor pricing changed. Review a new preview before creating the draft.');

        $vendor=purchasing_vendor($pdo,$org,$quote['vendorId']);
        if(!$vendor||$vendor['status']!=='active')throw new InvalidArgumentException('The vendor is no longer active.');
        $public=purchasing_public_id('po');
        $number='PO-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
        $notes='Need More request '.$publicId.' · '.$quote['name'].' · '.
            daily_quicklist_format_quantity($quote['remainingQuantity']).' '.$quote['unit'].
            ' outstanding at draft time. Review quantities and vendor terms before submission.';
        $pdo->prepare('INSERT INTO purchase_orders
            (organization_id,public_id,vendor_id,order_number,notes,created_by,updated_by)
            VALUES (?,?,?,?,?,?,?)')
            ->execute([$org,$public,(int)$vendor['id'],$number,$notes,$uid,$uid]);
        $poId=(int)$pdo->lastInsertId();
        $q=$pdo->prepare('SELECT id FROM inventory_items WHERE organization_id=? AND public_id=? LIMIT 1');
        $q->execute([$org,(string)$request['source_inventory_public_id']]);$stockId=(int)$q->fetchColumn();
        $q=$pdo->prepare('SELECT id FROM inventory_vendor_items WHERE organization_id=? AND inventory_item_id=? AND vendor_id=? AND archived_at IS NULL AND status=? ORDER BY is_preferred DESC,id LIMIT 1');
        $q->execute([$org,$stockId,(int)$vendor['id'],'active']);$mappingId=(int)$q->fetchColumn();
        $pdo->prepare('INSERT INTO purchase_order_items
            (organization_id,purchase_order_id,inventory_item_id,vendor_item_id,public_id,description,
             ordered_packs,pack_size,unit,ordered_base_quantity,price_per_pack,line_total,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$org,$poId,$stockId,$mappingId?:null,purchasing_public_id('poi'),
                $quote['name'],$quote['packs'],$quote['packSize'],$quote['unit'],
                $quote['orderedQuantity'],$quote['pricePerPack'],$quote['estimatedSubtotal']??0,
                'From Need More request '.$publicId]);
        purchasing_recalc($pdo,$org,$poId);
        purchasing_event($pdo,$org,$poId,'created_from_need_more',
            'Draft created from an outstanding Need More request.',$uid,
            ['needMoreId'=>$publicId,'remainingAtDraft'=>$quote['remainingQuantity'],
             'unit'=>$quote['unit'],'packs'=>$quote['packs'],'vendorId'=>$quote['vendorId']]);
        daily_quicklist_event($pdo,$org,(int)$request['id'],$uid,'po_draft_created',
            ['purchaseOrderId'=>$public,'orderNumber'=>$number,
             'quantity'=>$quote['orderedQuantity'],'unit'=>$quote['unit'],
             'needMoreRemaining'=>$quote['remainingQuantity']]);
        $pdo->commit();
        return ['orderId'=>$public,'orderNumber'=>$number,'vendor'=>$quote['vendor'],
            'orderedQuantity'=>$quote['orderedQuantity'],'unit'=>$quote['unit'],
            'status'=>'draft','requestId'=>$publicId];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
