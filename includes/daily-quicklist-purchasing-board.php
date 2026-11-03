<?php
declare(strict_types=1);
/** Section 3K2H: read-only links to canonical Purchasing; no inventory writes. */
require_once __DIR__.'/daily-quicklist-receipts.php';
function daily_quicklist_purchasing_board(PDO $pdo,int $org,string $date): array
{
    if(!daily_quicklist_receipts_ready($pdo))throw new RuntimeException('Run upgrade.php first.');
    foreach(['purchase_orders','purchase_order_items','goods_receipts','goods_receipt_items'] as $table)
        if(!restaurant_brain_table_ready($pdo,$table))throw new RuntimeException('Purchasing is not installed.');
    $q=$pdo->prepare("SELECT inv.public_id stock_id,po.public_id order_id,po.order_number,
        po.status,po.expected_delivery_date,v.name vendor_name,poi.ordered_base_quantity,
        poi.received_base_quantity,inv.base_unit FROM purchase_order_items poi
        JOIN purchase_orders po ON po.id=poi.purchase_order_id AND po.organization_id=poi.organization_id
        JOIN vendors v ON v.id=po.vendor_id AND v.organization_id=po.organization_id
        JOIN inventory_items inv ON inv.id=poi.inventory_item_id AND inv.organization_id=poi.organization_id
        WHERE poi.organization_id=? AND po.archived_at IS NULL
        AND po.status IN ('draft','submitted','partially_received')
        ORDER BY po.expected_delivery_date,po.id DESC LIMIT 500");
    $q->execute([$org]);$orders=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)
        $orders[(string)$r['stock_id']][]=['orderId'=>$r['order_id'],
            'orderNumber'=>$r['order_number'],'status'=>$r['status'],
            'expectedDate'=>$r['expected_delivery_date'],'vendor'=>$r['vendor_name'],
            'orderedQuantity'=>(float)$r['ordered_base_quantity'],
            'receivedQuantity'=>(float)$r['received_base_quantity'],'unit'=>$r['base_unit']];
    $q=$pdo->prepare("SELECT inv.public_id stock_id,gr.receipt_number,gr.received_at,
        po.order_number,v.name vendor_name,gri.received_base_quantity,gri.unit
        FROM goods_receipt_items gri JOIN goods_receipts gr
        ON gr.id=gri.goods_receipt_id AND gr.organization_id=gri.organization_id
        JOIN purchase_orders po ON po.id=gr.purchase_order_id AND po.organization_id=gr.organization_id
        JOIN vendors v ON v.id=gr.vendor_id AND v.organization_id=gr.organization_id
        JOIN inventory_items inv ON inv.id=gri.inventory_item_id AND inv.organization_id=gri.organization_id
        WHERE gri.organization_id=? ORDER BY gr.received_at DESC,gr.id DESC LIMIT 500");
    $q->execute([$org]);$deliveries=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $key=(string)$r['stock_id'];if(count($deliveries[$key]??[])>=5)continue;
        $deliveries[$key][]=['receiptNumber'=>$r['receipt_number'],'receivedAt'=>$r['received_at'],
            'orderNumber'=>$r['order_number'],'vendor'=>$r['vendor_name'],
            'quantity'=>(float)$r['received_base_quantity'],'unit'=>$r['unit']];
    }
    $rows=[];$totals=['requests'=>0,'numericOutstanding'=>0,'withOpenOrders'=>0,'withRecentDeliveries'=>0];
    foreach(daily_quicklist_items($pdo,$org,$date) as $item){
        if($item['type']!=='inventory'||$item['status']==='received')continue;
        $stock=(string)($item['inventoryId']??'');
        $progress=$stock!==''?daily_quicklist_receipt_progress($pdo,$org,(string)$item['id']):null;
        $open=$stock!==''?($orders[$stock]??[]):[];$recent=$stock!==''?($deliveries[$stock]??[]):[];
        $rows[]=['id'=>$item['id'],'title'=>$item['title'],'status'=>$item['status'],
            'quantityNote'=>$item['quantityNote'],'progress'=>$progress,
            'openOrders'=>$open,'recentDeliveries'=>$recent];
        ++$totals['requests'];
        if($progress!==null&&$progress['remainingQuantity']!==null&&$progress['remainingQuantity']>0)
            ++$totals['numericOutstanding'];
        if($open)++$totals['withOpenOrders'];
        if($recent)++$totals['withRecentDeliveries'];
    }
    return ['date'=>$date,'totals'=>$totals,'requests'=>$rows,
        'disclaimer'=>'Orders and deliveries are associated by saved stock item, not automatically allocated. Confirmed physical inventory receipts alone reconcile Need More.'];
}
