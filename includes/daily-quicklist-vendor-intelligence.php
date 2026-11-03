<?php
declare(strict_types=1);

/** 3K2I-B: source-backed vendor comparisons for one exact Need More request. */
require_once __DIR__.'/daily-quicklist-purchasing-board.php';
require_once __DIR__.'/purchasing-orders.php';

function dqpo_can_compare(array $actor): bool
{
    return daily_quicklist_receipt_permission($actor)
        && app_has_permission('purchasing.view',$actor);
}

function dqpo_can_draft(array $actor): bool
{
    return dqpo_can_compare($actor) && app_has_permission('purchasing.manage',$actor);
}

function dqpo_ready(PDO $pdo): bool
{
    return daily_quicklist_receipts_ready($pdo) && purchasing_ready($pdo);
}

/** Private context: names and quantities always come from saved, tenant-scoped records. */
function dqpo_request_context(PDO $pdo,int $org,string $publicId,?array $lockedRequest=null): array
{
    if(!dqpo_ready($pdo))throw new RuntimeException('Purchasing/Need More is not installed. Run upgrade.php.');
    if($org<1||!preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D',$publicId))
        throw new InvalidArgumentException('Choose an existing Need More request.');
    $current=$pdo->inTransaction()?' FOR UPDATE':'';
    if($lockedRequest===null){
        $q=$pdo->prepare('SELECT * FROM daily_quicklist_items WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1'.$current);
        $q->execute([$org,$publicId]);$request=$q->fetch(PDO::FETCH_ASSOC);
    }else $request=$lockedRequest;
    if(!$request||(int)$request['organization_id']!==$org||(string)$request['public_id']!==$publicId
        ||!empty($request['archived_at'])||$request['list_type']!=='inventory'||$request['status']==='received'
        ||empty($request['source_inventory_public_id']))
        throw new InvalidArgumentException('This Need More request needs an active saved inventory item.');
    $q=$pdo->prepare("SELECT id,public_id,name,base_unit FROM inventory_items
        WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL LIMIT 1".$current);
    $q->execute([$org,(string)$request['source_inventory_public_id']]);$stock=$q->fetch(PDO::FETCH_ASSOC);
    if(!$stock)throw new InvalidArgumentException('The saved inventory item is unavailable.');
    if($pdo->inTransaction()){
        // A current read after the request/stock locks avoids an older repeatable-read snapshot.
        $q=$pdo->prepare('SELECT allocated_quantity FROM daily_quicklist_receipts WHERE organization_id=? AND item_id=? FOR UPDATE');
        $q->execute([$org,(int)$request['id']]);
        $received=round(array_sum(array_map('floatval',$q->fetchAll(PDO::FETCH_COLUMN))),3);
        $target=$request['requested_stock_quantity']===null?null:(float)$request['requested_stock_quantity'];
        $progress=['requestedQuantity'=>$target,'receivedQuantity'=>(float)$received,
            'remainingQuantity'=>$target===null?null:max(0.0,round($target-$received,3)),
            'unit'=>(string)$stock['base_unit']];
    }else $progress=daily_quicklist_receipt_progress($pdo,$org,$publicId);
    return ['request'=>$request,'stock'=>$stock,'progress'=>$progress];
}

function dqpo_same_unit(string $left,string $right): bool
{
    return trim($left)!==''&&trim($right)!==''&&strcasecmp(trim($left),trim($right))===0;
}

/** No implicit lb/kg, case/each, or weight/volume conversion. */
function dqpo_vendor_option(array $row,string $unit,?float $remaining): array
{
    $blockers=[];$warnings=[];
    $pack=$row['pack_size']===null?null:(float)$row['pack_size'];
    $price=$row['price_per_pack']===null?null:(float)$row['price_per_pack'];
    $minimum=$row['minimum_order_amount']===null?null:(float)$row['minimum_order_amount'];
    $validPack=$pack!==null&&is_finite($pack)&&$pack>=0.001&&$pack<=1000000;
    $validPrice=$price===null||(is_finite($price)&&$price>=0&&$price<=99999999.9999);
    $sameUnit=dqpo_same_unit((string)$row['pack_unit'],$unit);
    if(!$validPack)$blockers[]='Set a positive vendor pack size before ordering.';
    if(!$sameUnit)$blockers[]='Vendor pack unit differs from the saved stock unit; a verified conversion is required.';
    if(!$validPrice)$blockers[]='The saved vendor price is invalid.';
    if($minimum!==null&&(!is_finite($minimum)||$minimum<0))$blockers[]='The saved vendor minimum is invalid.';
    $packs=null;$ordered=null;$overage=null;$subtotal=null;
    if($validPack&&$sameUnit&&$remaining!==null&&$remaining>=0.001){
        $packs=(int)ceil(($remaining-0.00000001)/$pack);
        $ordered=round($packs*$pack,4);
        if($packs<1||$packs>100000||$ordered>1000000)$blockers[]='The quantity exceeds the single-order limit.';
        $overage=max(0.0,round($ordered-$remaining,4));
        if($validPrice&&$price!==null){
            $subtotal=round($packs*$price,2);
            if(!is_finite($subtotal)||$subtotal>999999999999.99)$blockers[]='The estimated subtotal exceeds the order limit.';
        }
    }
    if($price===null)$warnings[]='Vendor price is not saved; the cost and minimum-order check are unconfirmed.';
    $shortfall=$minimum!==null&&$subtotal!==null?max(0.0,round($minimum-$subtotal,2)):null;
    if($shortfall!==null&&$shortfall>0)
        $warnings[]='This item draft is below the vendor minimum by $'.number_format($shortfall,2).'. Review the full order before submission.';
    if($overage!==null&&$overage>0)$warnings[]='Whole vendor packs exceed the outstanding request; review the displayed overage.';
    $unitPrice=$validPack&&$sameUnit&&$validPrice&&$price!==null?round($price/$pack,6):null;
    if($unitPrice!==null&&$unitPrice>99999999.9999)$blockers[]='The cost per stock unit exceeds the supported catalog limit.';
    return [
        'vendorItemId'=>(string)$row['public_id'],'vendorId'=>(string)$row['vendor_public_id'],
        'vendor'=>(string)$row['vendor_name'],'vendorSku'=>(string)($row['vendor_sku']??''),
        'preferred'=>(bool)$row['is_preferred'],'packSize'=>$pack,'packUnit'=>(string)($row['pack_unit']??''),
        'pricePerPack'=>$price,'unitPrice'=>$unitPrice,
        'packs'=>$packs,'orderedQuantity'=>$ordered,'overageQuantity'=>$overage,'estimatedSubtotal'=>$subtotal,
        'minimumOrderAmount'=>$minimum,'minimumShortfall'=>$shortfall,'leadTimeDays'=>(int)$row['lead_time_days'],
        'priceEffectiveAt'=>$row['price_effective_at'],'eligible'=>!$blockers,
        'blockers'=>$blockers,'warnings'=>$warnings,'priceHistory'=>[],
    ];
}

function dqpo_comparison_from_context(PDO $pdo,int $org,array $context): array
{
    ['request'=>$request,'stock'=>$stock,'progress'=>$progress]=$context;
    $current=$pdo->inTransaction()?' FOR UPDATE':'';
    $q=$pdo->prepare("SELECT vi.*,v.public_id vendor_public_id,v.name vendor_name,
        v.minimum_order_amount,v.lead_time_days,v.updated_at vendor_updated
        FROM inventory_vendor_items vi
        INNER JOIN vendors v ON v.id=vi.vendor_id AND v.organization_id=vi.organization_id
        WHERE vi.organization_id=? AND vi.inventory_item_id=?
          AND vi.archived_at IS NULL AND vi.status='active'
          AND v.archived_at IS NULL AND v.status='active'
        ORDER BY vi.is_preferred DESC,v.name,vi.id LIMIT 61".$current);
    $q->execute([$org,(int)$stock['id']]);$mappings=$q->fetchAll(PDO::FETCH_ASSOC);
    $truncated=count($mappings)>60;$mappings=array_slice($mappings,0,60);
    $history=$pdo->prepare('SELECT price_per_pack,pack_size,unit,effective_at,source_type
        FROM vendor_price_history WHERE organization_id=? AND inventory_item_id=? AND vendor_id=? AND vendor_item_id=?
        ORDER BY effective_at DESC,id DESC LIMIT 5');
    $options=[];$versions=[];
    foreach($mappings as $mapping){
        $option=dqpo_vendor_option($mapping,(string)$stock['base_unit'],$progress['remainingQuantity']);
        $history->execute([$org,(int)$stock['id'],(int)$mapping['vendor_id'],(int)$mapping['id']]);
        foreach($history->fetchAll(PDO::FETCH_ASSOC) as $h){
            $hp=$h['pack_size']===null?null:(float)$h['pack_size'];$price=(float)$h['price_per_pack'];
            $option['priceHistory'][]=['pricePerPack'=>$price,'packSize'=>$hp,'unit'=>(string)($h['unit']??''),
                'unitPrice'=>$hp!==null&&$hp>0&&$price>=0&&dqpo_same_unit((string)($h['unit']??''),(string)$stock['base_unit'])?round($price/$hp,6):null,
                'recordedAt'=>(string)$h['effective_at'],'source'=>(string)$h['source_type']];
        }
        $options[]=$option;
        $versions[]=[(string)$mapping['public_id'],(string)$mapping['updated_at'],(string)$mapping['vendor_updated']];
    }
    $q=$pdo->prepare("SELECT po.public_id,po.order_number,po.status,po.expected_delivery_date,
        v.name vendor_name,poi.ordered_base_quantity,poi.received_base_quantity,poi.unit
        FROM purchase_order_items poi
        INNER JOIN purchase_orders po ON po.id=poi.purchase_order_id AND po.organization_id=poi.organization_id
        INNER JOIN vendors v ON v.id=po.vendor_id AND v.organization_id=po.organization_id
        WHERE poi.organization_id=? AND poi.inventory_item_id=? AND po.archived_at IS NULL
          AND po.status IN ('draft','submitted','partially_received')
          AND poi.ordered_base_quantity>poi.received_base_quantity
        ORDER BY po.id DESC,poi.id LIMIT 51".$current);
    $q->execute([$org,(int)$stock['id']]);$open=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$open[]=[
        'orderId'=>(string)$r['public_id'],'orderNumber'=>(string)$r['order_number'],
        'status'=>(string)$r['status'],'vendor'=>(string)$r['vendor_name'],
        'outstandingQuantity'=>round((float)$r['ordered_base_quantity']-(float)$r['received_base_quantity'],4),
        'unit'=>(string)($r['unit']??''),'expectedDate'=>$r['expected_delivery_date'],
    ];
    $blockers=[];
    if($progress['requestedQuantity']===null||$progress['remainingQuantity']===null)
        $blockers[]='Set an explicit numeric Need More target in the saved stock unit first.';
    elseif($progress['remainingQuantity']<0.001)$blockers[]='There is no remaining Need More quantity to purchase.';
    if($open)$blockers[]='An open purchase order ('.$open[0]['orderNumber'].') already covers this stock item. Review it in Purchasing before drafting another.';
    if(!array_filter($options,static fn(array $option):bool=>$option['eligible']))
        $blockers[]='Map this stock item to an active vendor with a verified pack size and stock unit in Purchasing first.';
    $comparison=[
        'requestId'=>(string)$request['public_id'],'stockId'=>(string)$stock['public_id'],'name'=>(string)$stock['name'],
        'remainingQuantity'=>$progress['remainingQuantity'],'unit'=>(string)$stock['base_unit'],
        'options'=>$options,'optionsTruncated'=>$truncated,'openOrders'=>array_slice($open,0,50),
        'openOrdersTruncated'=>count($open)>50,'canDraft'=>!$blockers,'blockers'=>$blockers,
    ];
    // Include all reviewed source versions and terms, so changed minimums/pack sizes/preferences invalidate consent.
    $comparison['comparisonHash']=hash('sha256',json_encode([
        'organizationId'=>$org,'requestUpdated'=>(string)$request['updated_at'],
        'requested'=>$progress['requestedQuantity'],'allocated'=>$progress['receivedQuantity'],
        'versions'=>$versions,'comparison'=>$comparison,
    ],JSON_THROW_ON_ERROR));
    return $comparison;
}

/** Read-only. Callers must enforce dqpo_can_compare before exposing purchasing data. */
function dqpo_comparison(PDO $pdo,int $org,string $publicId): array
{
    return dqpo_comparison_from_context($pdo,$org,dqpo_request_context($pdo,$org,trim($publicId)));
}

function dqpo_require_actor(int $org,int $uid,array $actor): void
{
    if($org<1||$uid<1||(int)($actor['organization_id']??0)!==$org||(int)($actor['id']??0)!==$uid||!dqpo_can_draft($actor))
        throw new DomainException('Purchasing management and Need More manager permissions are required.');
}

/** Preference only. Keeps canonical vendor and unit-cost metadata consistent, without writing stock quantities. */
function dqpo_set_preferred(PDO $pdo,int $org,int $uid,array $actor,string $publicId,string $vendorItemId,string $comparisonHash): array
{
    dqpo_require_actor($org,$uid,$actor);
    if(!preg_match('/^[a-f0-9]{64}$/D',$comparisonHash))throw new InvalidArgumentException('Review current vendor options before confirming a preference.');
    if($pdo->inTransaction())throw new LogicException('A vendor preference requires its own transaction.');
    $pdo->beginTransaction();
    try{
        $context=dqpo_request_context($pdo,$org,trim($publicId));
        $comparison=dqpo_comparison_from_context($pdo,$org,$context);
        if(!hash_equals($comparison['comparisonHash'],$comparisonHash))
            throw new InvalidArgumentException('The request or vendor terms changed. Review current options before confirming.');
        $selected=null;
        foreach($comparison['options'] as $option)if($option['vendorItemId']===$vendorItemId)$selected=$option;
        if($selected===null||!$selected['eligible'])throw new InvalidArgumentException('Choose an eligible vendor mapping for this stock item.');
        $stockId=(int)$context['stock']['id'];
        $q=$pdo->prepare('SELECT id FROM inventory_vendor_items WHERE organization_id=? AND inventory_item_id=? AND is_preferred=1 FOR UPDATE');
        $q->execute([$org,$stockId]);$preferredCount=count($q->fetchAll(PDO::FETCH_COLUMN));
        if(!$selected['preferred']||$preferredCount!==1){
            $pdo->prepare('UPDATE inventory_vendor_items SET is_preferred=0,updated_by=?,updated_at=NOW(6)
                WHERE organization_id=? AND inventory_item_id=? AND is_preferred=1')->execute([$uid,$org,$stockId]);
            $pdo->prepare("UPDATE inventory_vendor_items SET is_preferred=1,updated_by=?,updated_at=NOW(6)
                WHERE organization_id=? AND inventory_item_id=? AND public_id=? AND status='active' AND archived_at IS NULL")
                ->execute([$uid,$org,$stockId,$vendorItemId]);
            $pdo->prepare('UPDATE inventory_items SET vendor_name=?,vendor_sku=?,unit_cost=COALESCE(?,unit_cost),updated_by=?,updated_at=NOW(6)
                WHERE organization_id=? AND id=?')->execute([$selected['vendor'],$selected['vendorSku']?:null,$selected['unitPrice'],$uid,$org,$stockId]);
            daily_quicklist_event($pdo,$org,(int)$context['request']['id'],$uid,'vendor_preferred',[
                'vendorId'=>$selected['vendorId'],'vendorItemId'=>$vendorItemId,'vendor'=>$selected['vendor'],
            ]);
        }
        $result=dqpo_comparison_from_context($pdo,$org,$context);
        $pdo->commit();
        return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
