<?php
declare(strict_types=1);

require_once __DIR__ . '/operations-wholesale.php';

function operations_agent_wholesale_answer(PDO $pdo,int $organizationId,string $message): array
{
    operations_sync_wholesale_tasks($pdo,$organizationId,null);
    $normalized=mb_strtolower($message,'UTF-8');
    $status=null;
    if(preg_match('/\bin\s+production\b/u',$normalized))$status='in_production';
    elseif(preg_match('/\bout\s+for\s+delivery\b/u',$normalized))$status='out_for_delivery';
    elseif(preg_match('/\bdelivered\b/u',$normalized))$status='delivered';
    elseif(preg_match('/\bready\b/u',$normalized))$status='ready';
    elseif(preg_match('/\bconfirmed\b/u',$normalized))$status='confirmed';
    elseif(preg_match('/\brequested\b/u',$normalized))$status='requested';
    elseif(preg_match('/\bcancelled\b|\bcanceled\b/u',$normalized))$status='cancelled';

    $where='o.organization_id=?';$params=[$organizationId];
    if($status!==null){$where.=' AND o.status=?';$params[]=$status;}
    elseif(preg_match('/\b(active|open|current|pending)\b/u',$normalized))$where.=" AND o.status NOT IN ('delivered','cancelled')";
    $statement=$pdo->prepare("SELECT o.id,o.public_id,o.order_number,o.status,o.fulfillment_type,o.requested_for,o.promised_for,a.business_name,(SELECT COUNT(*) FROM restaurant_tasks t WHERE t.organization_id=o.organization_id AND t.source_type='wholesale_order_item' AND t.source_public_id LIKE CONCAT('wholesale-order-',o.id,'-item-%') AND t.archived_at IS NULL) production_total,(SELECT COUNT(*) FROM restaurant_tasks t WHERE t.organization_id=o.organization_id AND t.source_type='wholesale_order_item' AND t.source_public_id LIKE CONCAT('wholesale-order-',o.id,'-item-%') AND t.status IN ('completed','verified') AND t.archived_at IS NULL) production_complete,(SELECT t.status FROM restaurant_tasks t WHERE t.organization_id=o.organization_id AND t.source_type='wholesale_order_fulfillment' AND t.source_public_id=CONCAT('wholesale-order-',o.id,'-fulfillment') AND t.archived_at IS NULL LIMIT 1) fulfillment_task_status FROM wholesale_orders o INNER JOIN wholesale_accounts a ON a.id=o.wholesale_account_id AND a.organization_id=o.organization_id WHERE {$where} AND a.archived_at IS NULL ORDER BY COALESCE(o.promised_for,CONCAT(o.requested_for,' 23:59:59'),o.created_at),o.created_at LIMIT 25");
    $statement->execute($params);$rows=$statement->fetchAll();
    if(!$rows)return ['skill'=>'wholesale.operations','answer'=>$status!==null?'No wholesale orders are currently '.str_replace('_',' ',$status).'.':'No matching wholesale orders were found.','data'=>[],'sources'=>[]];
    $safe=[];$lines=[];
    foreach($rows as $row){
        $entry=['publicId'=>$row['public_id'],'orderNumber'=>$row['order_number'],'businessName'=>$row['business_name'],'status'=>$row['status'],'fulfillmentType'=>$row['fulfillment_type'],'requestedFor'=>$row['requested_for'],'promisedFor'=>$row['promised_for'],'productionTotal'=>(int)$row['production_total'],'productionComplete'=>(int)$row['production_complete'],'fulfillmentTaskStatus'=>$row['fulfillment_task_status']];$safe[]=$entry;
        $line=$entry['orderNumber'].' · '.$entry['businessName'].' · '.str_replace('_',' ',$entry['status']).' · production '.$entry['productionComplete'].'/'.$entry['productionTotal'];
        if($entry['fulfillmentTaskStatus'])$line.=' · fulfillment '.str_replace('_',' ',$entry['fulfillmentTaskStatus']);
        if($entry['promisedFor'])$line.=' · promised '.$entry['promisedFor'];elseif($entry['requestedFor'])$line.=' · requested '.$entry['requestedFor'];
        $lines[]=$line;
    }
    return ['skill'=>'wholesale.operations','answer'=>'Wholesale order operations:' . "\n- " . implode("\n- ",$lines),'data'=>$safe,'sources'=>array_column($safe,'publicId')];
}
