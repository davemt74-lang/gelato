<?php
declare(strict_types=1);

require_once __DIR__ . '/operations-core.php';
require_once __DIR__ . '/wholesale-portal.php';

function operations_wholesale_ready(PDO $pdo): bool
{
    return operations_core_ready($pdo)
        && restaurant_brain_table_ready($pdo, 'wholesale_orders')
        && restaurant_brain_table_ready($pdo, 'wholesale_accounts');
}

function operations_wholesale_due_at(array $order): ?string
{
    $promised = trim((string)($order['promised_for'] ?? ''));
    if ($promised !== '') return $promised;
    $requested = trim((string)($order['requested_for'] ?? ''));
    if ($requested !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested)) return $requested . ' 23:59:59';
    return null;
}

function operations_wholesale_item_data(mixed $raw, int $index): array
{
    if (is_string($raw)) {
        $name = trim($raw);
        return ['name'=>$name !== '' ? $name : 'Wholesale item '.($index + 1), 'quantity'=>null, 'unit'=>null];
    }
    if (!is_array($raw)) return ['name'=>'Wholesale item '.($index + 1), 'quantity'=>null, 'unit'=>null];
    $name = trim((string)($raw['product'] ?? $raw['name'] ?? $raw['item'] ?? $raw['flavor'] ?? $raw['description'] ?? ''));
    $quantity = $raw['quantity'] ?? $raw['qty'] ?? null;
    $unit = trim((string)($raw['unit'] ?? $raw['package'] ?? $raw['size'] ?? ''));
    return [
        'name'=>$name !== '' ? $name : 'Wholesale item '.($index + 1),
        'quantity'=>is_numeric($quantity) ? (float)$quantity : null,
        'unit'=>$unit !== '' ? $unit : null,
    ];
}

function operations_wholesale_task_status(string $orderStatus, string $kind): string
{
    if ($kind === 'item') {
        return match ($orderStatus) {
            'ready', 'out_for_delivery', 'delivered' => 'completed',
            'cancelled' => 'cancelled',
            default => 'queued',
        };
    }
    return match ($orderStatus) {
        'out_for_delivery' => 'in_progress',
        'delivered' => 'completed',
        'cancelled' => 'cancelled',
        default => 'queued',
    };
}

function operations_wholesale_order_for_task(PDO $pdo,int $organizationId,array $task): ?array
{
    if(!in_array((string)($task['source_type']??''),['wholesale_order_item','wholesale_order_fulfillment'],true))return null;
    if(!preg_match('/^wholesale-order-(\d+)-(?:item-\d+|fulfillment)$/',(string)($task['source_public_id']??''),$match))return null;
    $statement=$pdo->prepare('SELECT * FROM wholesale_orders WHERE id=? AND organization_id=? LIMIT 1');
    $statement->execute([(int)$match[1],$organizationId]);$row=$statement->fetch();return $row?:null;
}

function operations_wholesale_validate_task_transition(PDO $pdo,int $organizationId,array $task,string $newStatus): void
{
    $sourceType=(string)($task['source_type']??'');
    if(!in_array($sourceType,['wholesale_order_item','wholesale_order_fulfillment'],true))return;
    $order=operations_wholesale_order_for_task($pdo,$organizationId,$task);if(!$order)return;
    $orderStatus=(string)$order['status'];$taskStatus=(string)($task['status']??'');
    if($orderStatus==='delivered'&&in_array($newStatus,['completed','verified'],true)&&in_array($taskStatus,['completed','verified'],true))return;
    if($orderStatus==='cancelled'&&$newStatus==='cancelled'&&$taskStatus==='cancelled')return;
    if($newStatus==='cancelled')throw new InvalidArgumentException('Cancel wholesale orders from the Wholesale Customers workspace so the whole order stays consistent.');
    if(in_array($orderStatus,['delivered','cancelled'],true))throw new InvalidArgumentException('This wholesale order is '.$orderStatus.' and its Operations tasks are read-only.');
    if($sourceType==='wholesale_order_item'&&in_array($orderStatus,['ready','out_for_delivery'],true)&&!in_array($newStatus,['completed','verified'],true))throw new InvalidArgumentException('Wholesale production is already complete for this order.');
    if($sourceType==='wholesale_order_fulfillment'&&in_array($newStatus,['in_progress','completed','verified'],true)){
        $counts=$pdo->prepare("SELECT COUNT(*) total,SUM(status IN ('completed','verified')) complete_count FROM restaurant_tasks WHERE organization_id=? AND source_type='wholesale_order_item' AND source_public_id LIKE ? AND archived_at IS NULL");
        $counts->execute([$organizationId,'wholesale-order-'.(int)$order['id'].'-item-%']);$row=$counts->fetch()?:[];
        if((int)($row['total']??0)>0&&(int)($row['complete_count']??0)!==(int)$row['total'])throw new InvalidArgumentException('Complete all wholesale production items before starting fulfillment.');
    }
}

function operations_sync_wholesale_tasks(PDO $pdo, int $organizationId, ?int $userId=null): int
{
    if (!operations_wholesale_ready($pdo)) return 0;
    operations_ensure_default_categories($pdo, $organizationId, $userId);
    $wholesaleCategory = operations_category_by_slug($pdo, $organizationId, 'wholesale');
    $deliveryCategory = operations_category_by_slug($pdo, $organizationId, 'delivery');
    if (!$wholesaleCategory || !$deliveryCategory) return 0;

    $orders = $pdo->prepare("SELECT o.*,a.business_name FROM wholesale_orders o INNER JOIN wholesale_accounts a ON a.id=o.wholesale_account_id AND a.organization_id=o.organization_id WHERE o.organization_id=? AND a.archived_at IS NULL ORDER BY o.created_at");
    $orders->execute([$organizationId]);
    $upsertItem = $pdo->prepare("INSERT INTO restaurant_tasks (organization_id,public_id,category_id,source_type,source_public_id,title,description,quantity,unit,station,priority,status,due_at,created_by,updated_by) VALUES (?,?,?,'wholesale_order_item',?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),title=VALUES(title),description=VALUES(description),quantity=VALUES(quantity),unit=VALUES(unit),station=VALUES(station),priority=VALUES(priority),status=CASE WHEN VALUES(status)='cancelled' THEN 'cancelled' WHEN VALUES(status)='completed' THEN IF(status='verified','verified','completed') ELSE status END,due_at=VALUES(due_at),updated_by=COALESCE(VALUES(updated_by),updated_by),updated_at=NOW(6)");
    $upsertFulfillment = $pdo->prepare("INSERT INTO restaurant_tasks (organization_id,public_id,category_id,source_type,source_public_id,title,description,station,priority,status,due_at,created_by,updated_by) VALUES (?,?,?,'wholesale_order_fulfillment',?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),title=VALUES(title),description=VALUES(description),station=VALUES(station),priority=VALUES(priority),status=CASE WHEN VALUES(status)='cancelled' THEN 'cancelled' WHEN VALUES(status)='completed' THEN IF(status='verified','verified','completed') WHEN VALUES(status)='in_progress' AND status NOT IN ('completed','verified') THEN 'in_progress' ELSE status END,due_at=VALUES(due_at),updated_by=COALESCE(VALUES(updated_by),updated_by),updated_at=NOW(6)");
    $count = 0;

    foreach ($orders->fetchAll() as $order) {
        $orderId = (int)$order['id'];
        $orderNumber = (string)$order['order_number'];
        $orderStatus = (string)$order['status'];
        $dueAt = operations_wholesale_due_at($order);
        $customerNotes = trim((string)($order['customer_notes'] ?? ''));
        $items = json_decode((string)($order['items_json'] ?? '[]'), true);
        if (!is_array($items)) $items = [];

        foreach (array_values($items) as $index => $raw) {
            $item = operations_wholesale_item_data($raw, $index);
            $sourceId = 'wholesale-order-'.$orderId.'-item-'.$index;
            $publicId = 'task-'.substr(hash('sha256', $organizationId.'|'.$sourceId), 0, 20);
            $quantityText = $item['quantity'] !== null ? rtrim(rtrim(number_format((float)$item['quantity'], 3, '.', ''), '0'), '.') : '';
            $titleParts = array_values(array_filter([$quantityText, $item['unit'], $item['name']], static fn($v)=>$v !== null && $v !== ''));
            $title = 'Wholesale '.$orderNumber.': '.implode(' ', $titleParts);
            $description = 'Wholesale production for '.$order['business_name'].' · order '.$orderNumber.'.';
            if ($customerNotes !== '') $description .= ' Customer notes: '.$customerNotes;
            $upsertItem->execute([
                $organizationId,$publicId,(int)$wholesaleCategory['id'],$sourceId,mb_substr($title,0,240,'UTF-8'),$description,
                $item['quantity'],$item['unit'],'Wholesale Production','normal',operations_wholesale_task_status($orderStatus,'item'),$dueAt,$userId,$userId
            ]);
            $count++;
        }

        $fulfillmentSource = 'wholesale-order-'.$orderId.'-fulfillment';
        $fulfillmentPublic = 'task-'.substr(hash('sha256', $organizationId.'|'.$fulfillmentSource), 0, 20);
        $fulfillmentType = trim((string)($order['fulfillment_type'] ?? ''));
        $isDelivery = stripos($fulfillmentType, 'delivery') !== false;
        $fulfillmentTitle = ($isDelivery ? 'Deliver ' : 'Fulfill ').'wholesale order '.$orderNumber.' — '.$order['business_name'];
        $fulfillmentDescription = 'Wholesale fulfillment for order '.$orderNumber.'.'.($fulfillmentType !== '' ? ' Method: '.$fulfillmentType.'.' : '');
        if ($customerNotes !== '') $fulfillmentDescription .= ' Customer notes: '.$customerNotes;
        $upsertFulfillment->execute([
            $organizationId,$fulfillmentPublic,(int)($isDelivery ? $deliveryCategory['id'] : $wholesaleCategory['id']),$fulfillmentSource,
            mb_substr($fulfillmentTitle,0,240,'UTF-8'),$fulfillmentDescription,$isDelivery ? 'Delivery' : 'Wholesale Fulfillment','normal',
            operations_wholesale_task_status($orderStatus,'fulfillment'),$dueAt,$userId,$userId
        ]);
        $count++;
    }
    return $count;
}

function operations_wholesale_task_status_changed(PDO $pdo, int $organizationId, array $task, string $newStatus, int $userId): ?string
{
    $sourceType = (string)($task['source_type'] ?? '');
    if (!in_array($sourceType, ['wholesale_order_item','wholesale_order_fulfillment'], true)) return null;
    operations_wholesale_validate_task_transition($pdo,$organizationId,$task,$newStatus);
    $order=operations_wholesale_order_for_task($pdo,$organizationId,$task);
    if(!$order)return null;
    $orderId=(int)$order['id'];

    $next = (string)$order['status'];
    if ($sourceType === 'wholesale_order_item') {
        if ($newStatus === 'in_progress' && in_array($next,['requested','confirmed'],true)) $next = 'in_production';
        $counts = $pdo->prepare("SELECT COUNT(*) total,SUM(status IN ('completed','verified')) complete_count FROM restaurant_tasks WHERE organization_id=? AND source_type='wholesale_order_item' AND source_public_id LIKE ? AND archived_at IS NULL");
        $counts->execute([$organizationId,'wholesale-order-'.$orderId.'-item-%']);
        $row = $counts->fetch() ?: [];
        if ((int)($row['total'] ?? 0) > 0 && (int)($row['complete_count'] ?? 0) === (int)$row['total'] && !in_array($next,['out_for_delivery','delivered','cancelled'],true)) $next = 'ready';
    } else {
        if ($newStatus === 'in_progress') {
            $next = stripos((string)($order['fulfillment_type'] ?? ''),'delivery') !== false ? 'out_for_delivery' : 'ready';
        } elseif (in_array($newStatus,['completed','verified'],true)) {
            $next = 'delivered';
        }
    }

    if ($next !== (string)$order['status']) {
        $update = $pdo->prepare("UPDATE wholesale_orders SET status=?,delivered_at=IF(?='delivered',COALESCE(delivered_at,NOW(6)),delivered_at),updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?");
        $update->execute([$next,$next,$userId,$orderId,$organizationId]);
        if (function_exists('app_audit')) app_audit($pdo,$organizationId,$userId,'wholesale.order_status_from_operations','wholesale_order',(string)$order['public_id'],['status'=>$order['status']],['status'=>$next,'task'=>$task['public_id'] ?? null]);
        wholesale_portal_sync_account_knowledge($pdo,$organizationId,(int)$order['wholesale_account_id'],$userId);
    }
    return $next;
}
