<?php
declare(strict_types=1);

require_once __DIR__.'/wholesale-portal.php';
require_once __DIR__.'/wholesale-commerce.php';
require_once __DIR__.'/wholesale-fulfillment.php';
require_once __DIR__.'/wholesale-receivables.php';
require_once __DIR__.'/operations-wholesale.php';

function wholesale_portal_operations_ready(PDO $pdo): bool
{
    return wholesale_commerce_ready($pdo)
        && wholesale_fulfillment_ready($pdo)
        && wholesale_receivables_ready($pdo)
        && wholesale_portal_table_ready($pdo,'wholesale_customer_requests');
}

function wholesale_portal_catalog(PDO $pdo,int $org,int $accountId): array
{
    $rows=[];
    foreach(wholesale_commerce_catalog($pdo,$org,$accountId) as $row){
        if($row['unitPrice']===null)continue;
        $rows[]=[
            'id'=>$row['id'],'sku'=>$row['sku'],'name'=>$row['name'],'productName'=>$row['productName'],'category'=>$row['category'],
            'sellUom'=>$row['sellUom'],'minimumQuantity'=>$row['minimumQuantity'],'quantityIncrement'=>$row['quantityIncrement'],
            'unitPrice'=>$row['unitPrice'],'priceMinimumQuantity'=>$row['priceMinimumQuantity'],'priceList'=>$row['priceList']
        ];
    }
    return $rows;
}

function wholesale_portal_safe_fulfillment(PDO $pdo,int $org,int $accountId,string $orderPublic): ?array
{
    $q=$pdo->prepare('SELECT id FROM wholesale_orders WHERE organization_id=? AND wholesale_account_id=? AND public_id=? LIMIT 1');
    $q->execute([$org,$accountId,$orderPublic]);
    if(!(int)$q->fetchColumn())return null;
    if(!wholesale_fulfillment_ready($pdo))return null;
    $detail=wholesale_fulfillment_order_detail($pdo,$org,$orderPublic);
    $progress=$detail['progress'];
    $batches=[];
    foreach($detail['batches'] as $batch){
        $batches[]=[
            'id'=>$batch['id'],'number'=>$batch['number'],'status'=>$batch['status'],'fulfillmentType'=>$batch['fulfillmentType'],
            'requestedWindowStart'=>$batch['requestedWindowStart'],'requestedWindowEnd'=>$batch['requestedWindowEnd'],
            'promisedWindowStart'=>$batch['promisedWindowStart'],'promisedWindowEnd'=>$batch['promisedWindowEnd'],
            'dispatchedAt'=>$batch['dispatchedAt'],'deliveredAt'=>$batch['deliveredAt'],
            'items'=>array_map(static fn(array $item):array=>[
                'sku'=>$item['sku'],'name'=>$item['name'],'quantity'=>$item['quantity'],'unit'=>$item['unit']
            ],$batch['items'])
        ];
    }
    return [
        'status'=>$detail['order']['status'],'fulfillmentType'=>$detail['order']['fulfillmentType'],
        'requestedFor'=>$detail['order']['requestedFor'],'promisedFor'=>$detail['order']['promisedFor'],
        'requestedWindowStart'=>$detail['order']['requestedWindowStart'],'requestedWindowEnd'=>$detail['order']['requestedWindowEnd'],
        'promisedWindowStart'=>$detail['order']['promisedWindowStart'],'promisedWindowEnd'=>$detail['order']['promisedWindowEnd'],
        'deliveredAt'=>$detail['order']['deliveredAt'],
        'progress'=>[
            'ordered'=>$progress['ordered'],'allocated'=>$progress['allocated'],'delivered'=>$progress['delivered'],
            'allAllocated'=>$progress['allAllocated'],'allDelivered'=>$progress['allDelivered'],'partialDelivered'=>$progress['partialDelivered'],
            'allocationPercent'=>$progress['allocationPercent'],'deliveryPercent'=>$progress['deliveryPercent'],
            'lines'=>array_map(static fn(array $line):array=>[
                'lineNumber'=>$line['lineNumber'],'sku'=>$line['sku'],'name'=>$line['name'],'ordered'=>$line['ordered'],'unit'=>$line['unit'],
                'allocated'=>$line['allocated'],'delivered'=>$line['delivered'],'remainingToDeliver'=>$line['remainingToDeliver']
            ],$progress['lines'])
        ],
        'batches'=>$batches
    ];
}

function wholesale_portal_safe_invoice(PDO $pdo,int $org,int $accountId,string $invoicePublic): ?array
{
    $q=$pdo->prepare('SELECT id FROM wholesale_invoices WHERE organization_id=? AND wholesale_account_id=? AND public_id=? LIMIT 1');
    $q->execute([$org,$accountId,$invoicePublic]);
    if(!(int)$q->fetchColumn())return null;
    $detail=wholesale_receivables_detail($pdo,$org,$invoicePublic);$i=$detail['invoice'];
    return [
        'id'=>$i['id'],'number'=>$i['number'],'status'=>$i['status'],'orderId'=>$i['orderId'],'orderNumber'=>$i['orderNumber'],
        'issueDate'=>$i['issueDate'],'dueDate'=>$i['dueDate'],'paymentTerms'=>$i['paymentTerms'],'currency'=>$i['currency'],
        'subtotal'=>$i['subtotal'],'deliveryFee'=>$i['deliveryFee'],'taxTotal'=>$i['taxTotal'],'total'=>$i['total'],'balance'=>$i['balance'],
        'daysOverdue'=>$i['daysOverdue'],'customerReference'=>$i['customerReference'],'customerNote'=>$i['customerNote'],
        'issuedAt'=>$i['issuedAt'],'paidAt'=>$i['paidAt'],'voidedAt'=>$i['voidedAt'],
        'items'=>array_map(static fn(array $item):array=>[
            'lineNumber'=>(int)$item['line_number'],'sku'=>$item['sku_snapshot'],'name'=>$item['item_name_snapshot'],'quantity'=>(float)$item['quantity'],
            'unit'=>$item['sell_uom_snapshot'],'unitPrice'=>(float)$item['unit_price_snapshot'],'lineSubtotal'=>(float)$item['line_subtotal']
        ],$detail['items']),
        'entries'=>array_map(static fn(array $entry):array=>[
            'id'=>$entry['public_id'],'type'=>$entry['entry_type'],'amount'=>(float)$entry['amount_delta'],'effectiveDate'=>$entry['effective_date'],
            'paymentMethod'=>$entry['payment_method'],'reference'=>$entry['external_reference'],'createdAt'=>$entry['created_at']
        ],$detail['entries'])
    ];
}

function wholesale_portal_safe_invoices(PDO $pdo,int $org,int $accountId): array
{
    if(!wholesale_receivables_ready($pdo))return [];
    $q=$pdo->prepare('SELECT public_id FROM wholesale_invoices WHERE organization_id=? AND wholesale_account_id=? ORDER BY created_at DESC LIMIT 100');
    $q->execute([$org,$accountId]);$rows=[];
    foreach($q->fetchAll() as $row){$invoice=wholesale_portal_safe_invoice($pdo,$org,$accountId,(string)$row['public_id']);if($invoice)$rows[]=$invoice;}
    return $rows;
}

function wholesale_portal_safe_orders(PDO $pdo,int $org,int $accountId): array
{
    $ctx=wholesale_portal_customer_safe_context($pdo,$org,$accountId);$rows=[];
    $invoiceByOrder=[];
    foreach(wholesale_portal_safe_invoices($pdo,$org,$accountId) as $invoice)$invoiceByOrder[$invoice['orderId']]=$invoice;
    foreach($ctx['orders']??[] as $order){
        $fulfillment=wholesale_portal_safe_fulfillment($pdo,$org,$accountId,(string)$order['public_id']);
        $invoice=$invoiceByOrder[$order['public_id']]??null;
        $order['fulfillment']=$fulfillment;
        $order['invoice']=$invoice?[
            'id'=>$invoice['id'],'number'=>$invoice['number'],'status'=>$invoice['status'],'total'=>$invoice['total'],'balance'=>$invoice['balance'],
            'dueDate'=>$invoice['dueDate'],'daysOverdue'=>$invoice['daysOverdue']
        ]:null;
        $rows[]=$order;
    }
    return $rows;
}

function wholesale_portal_history(PDO $pdo,int $org,int $accountId): array
{
    $ctx=wholesale_portal_customer_safe_context($pdo,$org,$accountId);$events=[];
    $add=static function(array &$events,?string $at,string $type,string $title,string $detail='',?string $ref=null):void{
        if(!$at)return;$events[]=['at'=>$at,'type'=>$type,'title'=>$title,'detail'=>$detail,'ref'=>$ref];
    };
    foreach($ctx['quotes']??[] as $q){
        $add($events,$q['created_at']??null,'quote','Quote '.$q['quote_number'].' created','$'.number_format((float)$q['total'],2),$q['public_id']);
        $add($events,$q['sent_at']??null,'quote','Quote '.$q['quote_number'].' sent','',$q['public_id']);
        $add($events,$q['accepted_at']??null,'quote','Quote '.$q['quote_number'].' accepted','',$q['public_id']);
        $add($events,$q['declined_at']??null,'quote','Quote '.$q['quote_number'].' declined','',$q['public_id']);
    }
    foreach($ctx['orders']??[] as $o){
        $add($events,$o['created_at']??null,'order','Order '.$o['order_number'].' created','$'.number_format((float)$o['total'],2),$o['public_id']);
        $add($events,$o['delivered_at']??null,'order','Order '.$o['order_number'].' delivered','',$o['public_id']);
    }
    foreach($ctx['requests']??[] as $r)$add($events,$r['created_at']??null,'request','Request: '.$r['subject'],ucfirst((string)$r['status']),$r['public_id']);
    if(wholesale_portal_table_ready($pdo,'wholesale_invoices')){
        $q=$pdo->prepare('SELECT public_id,invoice_number,total,issued_at,paid_at,voided_at FROM wholesale_invoices WHERE organization_id=? AND wholesale_account_id=? ORDER BY created_at DESC LIMIT 100');
        $q->execute([$org,$accountId]);foreach($q->fetchAll() as $i){
            $add($events,$i['issued_at'],'invoice','Invoice '.$i['invoice_number'].' issued','$'.number_format((float)$i['total'],2),$i['public_id']);
            $add($events,$i['paid_at'],'invoice','Invoice '.$i['invoice_number'].' paid','',$i['public_id']);
            $add($events,$i['voided_at'],'invoice','Invoice '.$i['invoice_number'].' voided','',$i['public_id']);
        }
    }
    if(wholesale_portal_table_ready($pdo,'wholesale_fulfillments')){
        $q=$pdo->prepare("SELECT f.public_id,f.fulfillment_number,f.status,f.dispatched_at,f.delivered_at,o.order_number FROM wholesale_fulfillments f JOIN wholesale_orders o ON o.id=f.wholesale_order_id AND o.organization_id=f.organization_id WHERE f.organization_id=? AND o.wholesale_account_id=? ORDER BY f.created_at DESC LIMIT 100");
        $q->execute([$org,$accountId]);foreach($q->fetchAll() as $f){
            $add($events,$f['dispatched_at'],'fulfillment','Fulfillment '.$f['fulfillment_number'].' dispatched','Order '.$f['order_number'],$f['public_id']);
            $add($events,$f['delivered_at'],'fulfillment','Fulfillment '.$f['fulfillment_number'].' delivered','Order '.$f['order_number'],$f['public_id']);
        }
    }
    usort($events,static fn(array $a,array $b):int=>strcmp((string)$b['at'],(string)$a['at']));
    return array_slice($events,0,100);
}

function wholesale_portal_dashboard(PDO $pdo,int $org,int $accountId): array
{
    $ctx=wholesale_portal_customer_safe_context($pdo,$org,$accountId);if(!$ctx)return [];
    $ctx['catalog']=wholesale_portal_catalog($pdo,$org,$accountId);
    $ctx['orders']=wholesale_portal_safe_orders($pdo,$org,$accountId);
    $ctx['invoices']=wholesale_portal_safe_invoices($pdo,$org,$accountId);
    $ctx['history']=wholesale_portal_history($pdo,$org,$accountId);
    $receivables=0.0;$overdue=0.0;$credits=0.0;
    foreach($ctx['invoices'] as $i){$balance=(float)$i['balance'];if($balance>0){$receivables+=$balance;if((int)$i['daysOverdue']>0)$overdue+=$balance;}elseif($balance<0)$credits+=-$balance;}
    $ctx['summary']=['receivables'=>round($receivables,2),'overdue'=>round($overdue,2),'customerCredits'=>round($credits,2),'catalogItems'=>count($ctx['catalog'])];
    return $ctx;
}

function wholesale_portal_repeat_order(PDO $pdo,int $org,int $accountId,string $sourceOrderPublic,array $input,int $userId): array
{
    if(!wholesale_commerce_ready($pdo))throw new RuntimeException('Wholesale commerce is unavailable.');
    $q=$pdo->prepare("SELECT * FROM wholesale_orders WHERE organization_id=? AND wholesale_account_id=? AND public_id=? AND status='delivered' LIMIT 1");
    $q->execute([$org,$accountId,$sourceOrderPublic]);$source=$q->fetch();if(!$source)throw new InvalidArgumentException('Only a delivered order from this account can be repeated.');
    $lineQ=$pdo->prepare("SELECT oi.quantity,s.public_id sku_public_id,s.status sku_status,s.archived_at sku_archived FROM wholesale_order_items oi LEFT JOIN wholesale_skus s ON s.id=oi.wholesale_sku_id AND s.organization_id=oi.organization_id WHERE oi.organization_id=? AND oi.wholesale_order_id=? ORDER BY oi.line_number");
    $lineQ->execute([$org,(int)$source['id']]);$items=[];
    foreach($lineQ->fetchAll() as $line){
        if(!$line['sku_public_id']||$line['sku_status']!=='active'||$line['sku_archived']!==null)throw new InvalidArgumentException('This order contains an item that is no longer available for self-service reorder. Request help from the wholesale team.');
        $items[]=['skuId'=>$line['sku_public_id'],'quantity'=>(float)$line['quantity']];
    }
    if(!$items)throw new InvalidArgumentException('This order has no canonical SKU lines available to repeat.');
    $account=wholesale_commerce_account($pdo,$org,$accountId);
    $requested=wholesale_commerce_optional_date($input['requestedFor']??null,'Requested fulfillment date');
    $result=wholesale_commerce_create_order($pdo,$org,$account,[
        'items'=>$items,'status'=>'requested','deliveryFee'=>(float)$source['delivery_fee'],'taxRatePercent'=>(float)($source['tax_rate_percent']??0),
        'fulfillmentType'=>$source['fulfillment_type']?:$account['preferred_fulfillment'],'requestedFor'=>$requested,
        'customerNotes'=>'Customer portal repeat order from '.$source['order_number'].'.'
    ],$userId);
    if(!empty($account['wholesale_lead_id'])){
        $activity=$pdo->prepare("INSERT INTO wholesale_lead_activities (organization_id,wholesale_lead_id,activity_type,summary,details,created_by) VALUES (?,?,'reorder',?,?,?)");
        $activity->execute([$org,(int)$account['wholesale_lead_id'],'Customer repeated wholesale order',$source['order_number'].' repeated as '.$result['orderNumber'].'.',$userId]);
    }
    wholesale_portal_sync_account_knowledge($pdo,$org,$accountId,$userId);
    if(operations_wholesale_ready($pdo))operations_sync_wholesale_tasks($pdo,$org,$userId);
    if(function_exists('app_audit'))app_audit($pdo,$org,$userId,'wholesale.portal_repeat_order','wholesale_order',$result['publicId'],['sourceOrder'=>$sourceOrderPublic],['orderNumber'=>$result['orderNumber']]);
    return $result;
}

function wholesale_portal_catalog_request(PDO $pdo,int $org,int $accountId,array $input,int $userId): array
{
    $items=(array)($input['items']??[]);if(!$items)throw new InvalidArgumentException('Choose at least one catalog item.');
    $account=wholesale_commerce_account($pdo,$org,$accountId);$pricing=wholesale_commerce_resolve_lines($pdo,$org,$accountId,$items);
    $requested=wholesale_commerce_optional_date($input['requestedFor']??null,'Requested fulfillment date');
    $lines=[];foreach($pricing['lines'] as $line)$lines[]=$line['quantity'].' '.$line['sellUom'].' '.$line['name'].' @ $'.number_format((float)$line['unitPrice'],2);
    $subject='Catalog order request · '.count($pricing['lines']).' item'.(count($pricing['lines'])===1?'':'s').' · $'.number_format((float)$pricing['subtotal'],2).' subtotal';
    $details=implode("\n",$lines).($requested?"\nRequested for: ".$requested:'')."\nTax and delivery charges will be confirmed by the wholesale team.";
    $metadata=['source'=>'portal_catalog','items'=>$pricing['snapshot'],'estimatedSubtotal'=>$pricing['subtotal'],'priceList'=>$pricing['priceList']?['id'=>$pricing['priceList']['public_id'],'name'=>$pricing['priceList']['name'],'currency'=>$pricing['priceList']['currency']]:null,'requestedFor'=>$requested,'preferredFulfillment'=>$account['preferred_fulfillment']];
    $public=wholesale_portal_public_id('wreq');
    $q=$pdo->prepare("INSERT INTO wholesale_customer_requests (organization_id,wholesale_account_id,submitted_by,public_id,request_type,subject,details,metadata_json) VALUES (?,?,?,?, 'reorder',?,?,?)");
    $q->execute([$org,$accountId,$userId,$public,$subject,$details,json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    if(!empty($account['wholesale_lead_id'])){
        $activity=$pdo->prepare("INSERT INTO wholesale_lead_activities (organization_id,wholesale_lead_id,activity_type,summary,details,created_by) VALUES (?,?,'customer_request',?,?,?)");
        $activity->execute([$org,(int)$account['wholesale_lead_id'],'Customer submitted catalog order request',$details,$userId]);
    }
    wholesale_portal_sync_account_knowledge($pdo,$org,$accountId,$userId);
    if(function_exists('app_audit'))app_audit($pdo,$org,$userId,'wholesale.portal_catalog_request','wholesale_customer_request',$public,null,['estimatedSubtotal'=>$pricing['subtotal'],'lineCount'=>count($pricing['lines'])]);
    return ['requestId'=>$public,'estimatedSubtotal'=>$pricing['subtotal'],'lineCount'=>count($pricing['lines'])];
}

function wholesale_operations_account_360(PDO $pdo,int $org,string $accountPublic): array
{
    $account=wholesale_portal_account_row($pdo,$org,$accountPublic);if(!$account)throw new InvalidArgumentException('Wholesale account not found.');
    $accountId=(int)$account['id'];$safe=wholesale_portal_dashboard($pdo,$org,$accountId);
    $orders=[];
    $q=$pdo->prepare('SELECT public_id FROM wholesale_orders WHERE organization_id=? AND wholesale_account_id=? ORDER BY created_at DESC LIMIT 20');$q->execute([$org,$accountId]);
    foreach($q->fetchAll() as $row){$orders[]=wholesale_fulfillment_ready($pdo)?wholesale_fulfillment_order_detail($pdo,$org,(string)$row['public_id']):['order'=>['id'=>$row['public_id']]];}
    $invoices=[];$q=$pdo->prepare('SELECT public_id FROM wholesale_invoices WHERE organization_id=? AND wholesale_account_id=? ORDER BY created_at DESC LIMIT 50');$q->execute([$org,$accountId]);foreach($q->fetchAll() as $row)$invoices[]=wholesale_receivables_detail($pdo,$org,(string)$row['public_id']);
    $leadActivity=[];if(!empty($account['wholesale_lead_id'])){$q=$pdo->prepare('SELECT activity_type,summary,details,created_at FROM wholesale_lead_activities WHERE organization_id=? AND wholesale_lead_id=? ORDER BY created_at DESC LIMIT 100');$q->execute([$org,(int)$account['wholesale_lead_id']]);$leadActivity=$q->fetchAll();}
    return ['account'=>$account,'catalog'=>$safe['catalog'],'portalSummary'=>$safe['summary'],'orders'=>$orders,'invoices'=>$invoices,'requests'=>$safe['requests'],'history'=>$safe['history'],'leadActivity'=>$leadActivity];
}

function wholesale_operations_accounts(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT a.public_id,a.business_name,a.account_status,a.primary_email,a.payment_terms,a.preferred_fulfillment,
      (SELECT COUNT(*) FROM wholesale_orders o WHERE o.organization_id=a.organization_id AND o.wholesale_account_id=a.id AND o.status NOT IN ('delivered','cancelled')) open_orders,
      (SELECT COUNT(*) FROM wholesale_customer_requests r WHERE r.organization_id=a.organization_id AND r.wholesale_account_id=a.id AND r.status IN ('new','reviewing')) open_requests,
      (SELECT COALESCE(SUM(e.amount_delta),0) FROM wholesale_receivable_entries e WHERE e.organization_id=a.organization_id AND e.wholesale_account_id=a.id) ar_balance
      FROM wholesale_accounts a WHERE a.organization_id=? AND a.archived_at IS NULL ORDER BY a.business_name");
    $q->execute([$org]);return $q->fetchAll();
}
