<?php
declare(strict_types=1);

/** 3K2I-B: explicitly reviewed, exact-vendor drafts; canonical Purchasing owns orders. */
require_once __DIR__.'/daily-quicklist-vendor-intelligence.php';

function dqpo_quote_from_context(PDO $pdo,int $org,array $context,string $vendorItemId=''): array
{
    $comparison=dqpo_comparison_from_context($pdo,$org,$context);
    if(!$comparison['canDraft'])throw new InvalidArgumentException($comparison['blockers'][0]);
    $selected=null;
    foreach($comparison['options'] as $option){
        if($vendorItemId!==''&&$option['vendorItemId']===$vendorItemId){$selected=$option;break;}
        if($vendorItemId===''&&$option['eligible']){$selected=$option;break;}
    }
    if($selected===null)throw new InvalidArgumentException('Choose an active vendor mapping for this exact stock item.');
    if(!$selected['eligible'])throw new InvalidArgumentException($selected['blockers'][0]);
    $quote=$selected+[
        'requestId'=>$comparison['requestId'],'stockId'=>$comparison['stockId'],'name'=>$comparison['name'],
        'remainingQuantity'=>$comparison['remainingQuantity'],'unit'=>$comparison['unit'],
        'warning'=>$selected['warnings']?implode(' ',$selected['warnings']):null,
    ];
    $quote['quoteHash']=hash('sha256',json_encode([
        'comparisonHash'=>$comparison['comparisonHash'],'selectedVendorItemId'=>$selected['vendorItemId'],
    ],JSON_THROW_ON_ERROR));
    return $quote;
}

/** Existing callers may omit vendorItemId; explicit selections never silently fall back. */
function dqpo_quote(PDO $pdo,int $org,string $publicId,?array $lockedRequest=null,string $vendorItemId=''): array
{
    return dqpo_quote_from_context($pdo,$org,dqpo_request_context($pdo,$org,trim($publicId),$lockedRequest),trim($vendorItemId));
}

/** Serializes all purchasing paths on the canonical stock row, then rechecks reviewed terms. */
function dqpo_create_draft(PDO $pdo,int $org,int $uid,array $actor,string $publicId,string $quoteHash,string $vendorItemId=''): array
{
    dqpo_require_actor($org,$uid,$actor);
    if(!preg_match('/^[a-f0-9]{64}$/D',$quoteHash))
        throw new InvalidArgumentException('Preview the current draft quantities before confirming.');
    if(!dqpo_ready($pdo))throw new RuntimeException('Purchasing/Need More is not installed. Run upgrade.php.');
    if($pdo->inTransaction())throw new LogicException('A purchase draft requires its own transaction.');
    $pdo->beginTransaction();
    try{
        $context=dqpo_request_context($pdo,$org,trim($publicId));
        $stockId=(int)$context['stock']['id'];
        purchasing_assert_no_open_order($pdo,$org,$stockId);
        $quote=dqpo_quote_from_context($pdo,$org,$context,trim($vendorItemId));
        if(!hash_equals($quote['quoteHash'],$quoteHash))
            throw new InvalidArgumentException('Need More quantities or vendor terms changed. Review a new preview before creating the draft.');
        $q=$pdo->prepare("SELECT vi.id,v.id vendor_id FROM inventory_vendor_items vi
            INNER JOIN vendors v ON v.id=vi.vendor_id AND v.organization_id=vi.organization_id
            WHERE vi.organization_id=? AND vi.inventory_item_id=? AND vi.public_id=? AND v.public_id=?
              AND vi.status='active' AND vi.archived_at IS NULL AND v.status='active' AND v.archived_at IS NULL
            LIMIT 1 FOR UPDATE");
        $q->execute([$org,$stockId,$quote['vendorItemId'],$quote['vendorId']]);$mapping=$q->fetch(PDO::FETCH_ASSOC);
        if(!$mapping)throw new InvalidArgumentException('The selected vendor mapping is no longer available.');
        $public=purchasing_public_id('po');
        $number='PO-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
        $notes='Need More request '.$publicId.' · '.$quote['name'].' · '.
            daily_quicklist_format_quantity($quote['remainingQuantity']).' '.$quote['unit'].
            ' outstanding at draft time. Review quantities and vendor terms before submission.';
        if($quote['warning'])$notes.=' '.$quote['warning'];
        $pdo->prepare('INSERT INTO purchase_orders
            (organization_id,public_id,vendor_id,order_number,notes,created_by,updated_by)
            VALUES (?,?,?,?,?,?,?)')->execute([$org,$public,(int)$mapping['vendor_id'],$number,$notes,$uid,$uid]);
        $poId=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO purchase_order_items
            (organization_id,purchase_order_id,inventory_item_id,vendor_item_id,public_id,description,
             ordered_packs,pack_size,unit,ordered_base_quantity,price_per_pack,line_total,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $org,$poId,$stockId,(int)$mapping['id'],purchasing_public_id('poi'),$quote['name'],
                $quote['packs'],$quote['packSize'],$quote['unit'],$quote['orderedQuantity'],
                $quote['pricePerPack'],$quote['estimatedSubtotal']??0,'From Need More request '.$publicId,
            ]);
        purchasing_recalc($pdo,$org,$poId);
        purchasing_event($pdo,$org,$poId,'created_from_need_more',
            'Draft created from an outstanding Need More request with the reviewed vendor mapping.',$uid,[
                'needMoreId'=>$publicId,'remainingAtDraft'=>$quote['remainingQuantity'],'unit'=>$quote['unit'],
                'packs'=>$quote['packs'],'vendorId'=>$quote['vendorId'],'vendorItemId'=>$quote['vendorItemId'],
                'pricePerPack'=>$quote['pricePerPack'],'minimumOrderAmount'=>$quote['minimumOrderAmount'],
                'warnings'=>$quote['warnings'],
            ]);
        daily_quicklist_event($pdo,$org,(int)$context['request']['id'],$uid,'po_draft_created',[
            'purchaseOrderId'=>$public,'orderNumber'=>$number,'vendorId'=>$quote['vendorId'],'vendorItemId'=>$quote['vendorItemId'],
            'quantity'=>$quote['orderedQuantity'],'unit'=>$quote['unit'],'needMoreRemaining'=>$quote['remainingQuantity'],
        ]);
        // Existing manager inbox; no vendor messages or purchasing costs in the notification.
        daily_quicklist_notify_managers($pdo,$org,$uid,'Need More purchase draft',
            'A manager created '.$number.' from a Need More request. Review the draft in Purchasing.');
        $pdo->commit();
        return ['orderId'=>$public,'orderNumber'=>$number,'vendor'=>$quote['vendor'],'vendorItemId'=>$quote['vendorItemId'],
            'orderedQuantity'=>$quote['orderedQuantity'],'unit'=>$quote['unit'],'status'=>'draft','requestId'=>$publicId];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
