<?php
declare(strict_types=1);
require_once __DIR__.'/purchasing-receiving.php';

final class PurchasingAgentPermissionException extends RuntimeException {}

function pac_has(array $user,string $permission): bool{return app_has_permission($permission,$user);}
function pac_require(array $user,string $permission,string $message): void{if(!pac_has($user,$permission))throw new PurchasingAgentPermissionException($message);}
function pac_pending_key(int $org,int $uid): string{return $org.':'.$uid;}
function pac_pending_get(int $org,int $uid): ?array{
    $key=pac_pending_key($org,$uid);$row=$_SESSION['purchasing_agent_pending'][$key]??null;
    if(!is_array($row)||($row['expires']??0)<time()){unset($_SESSION['purchasing_agent_pending'][$key]);return null;}
    return $row;
}
function pac_pending_store(int $org,int $uid,string $type,array $payload,string $summary): array{
    $row=['id'=>'pap-'.bin2hex(random_bytes(8)),'organizationId'=>$org,'userId'=>$uid,'type'=>$type,'payload'=>$payload,'summary'=>$summary,'expires'=>time()+600];
    $_SESSION['purchasing_agent_pending'][pac_pending_key($org,$uid)]=$row;return $row;
}
function pac_pending_clear(int $org,int $uid): void{unset($_SESSION['purchasing_agent_pending'][pac_pending_key($org,$uid)]);}
function pac_proposal_result(array $proposal,array $sources=['Purchasing + Receiving']): array{
    return ['ok'=>true,'skill'=>'purchasing.action_proposal','answer'=>$proposal['summary']."\n\nReply Confirm to execute this change, or Cancel to discard it.",'data'=>['requiresConfirmation'=>true,'proposal'=>['id'=>$proposal['id'],'type'=>$proposal['type'],'summary'=>$proposal['summary'],'expiresAt'=>date(DATE_ATOM,(int)$proposal['expires'])]],'sources'=>$sources];
}
function pac_clean_public_id(mixed $value): string{return preg_replace('/[^A-Za-z0-9_.:-]/','',trim((string)$value))??'';}
function pac_selected_order(PDO $pdo,int $org,array $context): ?array{
    $public=pac_clean_public_id($context['selectedPurchaseOrderPublicId']??'');
    return $public!==''?purchasing_order($pdo,$org,$public):null;
}
function pac_order_version(array $po): array{return ['status'=>(string)($po['status']??''),'updatedAt'=>(string)($po['updated_at']??'')];}
function pac_guard_order(array $po,array $expected): void{
    if((string)($expected['status']??'')!==(string)($po['status']??'')||(string)($expected['updatedAt']??'')!==(string)($po['updated_at']??''))throw new InvalidArgumentException('That purchase order changed after I proposed the action. Review the current PO and ask again.');
}
function pac_vendor_from_message(PDO $pdo,int $org,string $message): ?array{
    $lower=mb_strtolower($message,'UTF-8');$matches=[];
    foreach(purchasing_vendors($pdo,$org) as $vendor){$name=trim((string)$vendor['name']);if($name!==''&&str_contains($lower,mb_strtolower($name,'UTF-8')))$matches[]=$vendor;}
    usort($matches,static fn($a,$b)=>mb_strlen((string)$b['name'],'UTF-8')<=>mb_strlen((string)$a['name'],'UTF-8'));
    return $matches[0]??null;
}
function pac_vendor_suggestion_lines(PDO $pdo,int $org,string $vendorPublic): array{
    $lines=[];
    foreach(purchasing_suggestions($pdo,$org) as $row){
        if((string)($row['vendor']['id']??'')!==$vendorPublic)continue;
        $lines[]=['inventoryId'=>(string)$row['inventoryId'],'name'=>(string)$row['name'],'packs'=>(float)$row['suggestedPacks'],'packSize'=>(float)($row['vendor']['packSize']??1),'pricePerPack'=>$row['vendor']['pricePerPack']??null];
    }
    usort($lines,static fn($a,$b)=>strcmp($a['inventoryId'],$b['inventoryId']));
    return $lines;
}
function pac_lines_signature(array $lines): string{
    $normalized=[];foreach($lines as $line)$normalized[]=['inventoryId'=>(string)($line['inventoryId']??''),'packs'=>round((float)($line['packs']??0),4),'packSize'=>round((float)($line['packSize']??0),4),'pricePerPack'=>isset($line['pricePerPack'])&&$line['pricePerPack']!==null?round((float)$line['pricePerPack'],4):null];
    return hash('sha256',json_encode($normalized,JSON_UNESCAPED_SLASHES));
}
function pac_outstanding_lines(array $po): array{
    $lines=[];foreach((array)($po['items']??[]) as $item){$remaining=max(0,(float)$item['ordered_base_quantity']-(float)$item['received_base_quantity']);if($remaining>0.0001)$lines[]=['itemId'=>(string)$item['public_id'],'inventoryId'=>(string)$item['inventory_public_id'],'name'=>(string)$item['inventory_name'],'quantity'=>$remaining];}
    return $lines;
}
function pac_outstanding_signature(array $lines): string{
    $normalized=[];foreach($lines as $line)$normalized[]=['itemId'=>(string)$line['itemId'],'quantity'=>round((float)$line['quantity'],4)];return hash('sha256',json_encode($normalized,JSON_UNESCAPED_SLASHES));
}
function pac_order_answer(array $po): string{
    $parts=[];foreach((array)($po['items']??[]) as $item){$remaining=max(0,(float)$item['ordered_base_quantity']-(float)$item['received_base_quantity']);$parts[]=$item['inventory_name'].' '.rtrim(rtrim(number_format((float)$item['ordered_base_quantity'],2),'0'),'.').' '.$item['unit'].($remaining>0?' ('.rtrim(rtrim(number_format($remaining,2),'0'),'.').' outstanding)':' (received)');}
    return $po['order_number'].' · '.$po['vendor_name'].' · '.str_replace('_',' ',(string)$po['status']).' · $'.number_format((float)$po['total_amount'],2).($parts?' · '.implode('; ',$parts):'');
}
function pac_suggestion_answer(PDO $pdo,int $org): array{
    $rows=purchasing_suggestions($pdo,$org);if(!$rows)return ['answer'=>'No purchasing suggestions are currently generated from inventory, prep forecasts, commitments, and open orders.','count'=>0];
    $parts=[];foreach(array_slice($rows,0,8) as $row)$parts[]=$row['name'].' '.round((float)$row['needQuantity'],2).' '.$row['unit'].' → '.($row['vendor']['name']??'vendor not mapped').($row['vendor']?' ('.round((float)$row['suggestedPacks'],2).' packs)':'');
    return ['answer'=>count($rows).' purchasing suggestion'.(count($rows)===1?'':'s').': '.implode('; ',$parts).'.','count'=>count($rows)];
}
function pac_price_history(PDO $pdo,int $org,string $query): array{
    $q=$pdo->prepare("SELECT i.name inventory_name,v.name vendor_name,h.price_per_pack,h.pack_size,h.unit,h.source_type,h.created_at FROM vendor_price_history h JOIN inventory_items i ON i.id=h.inventory_item_id AND i.organization_id=h.organization_id JOIN vendors v ON v.id=h.vendor_id AND v.organization_id=h.organization_id WHERE h.organization_id=? AND i.name LIKE ? ORDER BY h.created_at DESC LIMIT 30");$q->execute([$org,'%'.trim($query).'%']);return $q->fetchAll();
}
function pac_extract_item_query(string $message): string{
    $value=preg_replace('/\b(?:what did we last pay for|last paid for|price history for|compare prices? for|compare vendor prices? for|price compare)\b/iu','',$message)??$message;
    return trim($value," \t\n\r\0\x0B?.!");
}
function pac_cancel_order(PDO $pdo,int $org,array $po,int $uid): array{
    if(in_array((string)$po['status'],['received','cancelled'],true))throw new InvalidArgumentException('This purchase order cannot be cancelled in its current status.');
    $stmt=$pdo->prepare("UPDATE purchase_orders SET status='cancelled',cancelled_at=NOW(6),cancelled_by=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND status=? AND archived_at IS NULL");$stmt->execute([$uid,$uid,$org,(int)$po['id'],(string)$po['status']]);if($stmt->rowCount()!==1)throw new InvalidArgumentException('That purchase order changed before cancellation.');
    purchasing_event($pdo,$org,(int)$po['id'],'cancelled','Purchase order cancelled through Gelato Agent.',$uid);return purchasing_order($pdo,$org,(string)$po['public_id'])??[];
}
function pac_execute_pending(PDO $pdo,int $org,int $uid,array $user,array $proposal): array{
    if((int)($proposal['organizationId']??0)!==$org||(int)($proposal['userId']??0)!==$uid)throw new PurchasingAgentPermissionException('This Purchasing Agent proposal does not belong to your session.');
    $type=(string)$proposal['type'];$payload=(array)$proposal['payload'];
    if(in_array($type,['draft_po','submit_po','cancel_po'],true))pac_require($user,'purchasing.manage','Purchasing management permission is required for this action.');
    if($type==='receive_po_full')pac_require($user,'receiving.manage','Receiving management permission is required for this action.');

    if($type==='draft_po'){
        $vendor=purchasing_vendor($pdo,$org,(string)$payload['vendorId']);if(!$vendor)throw new InvalidArgumentException('The proposed vendor is no longer available.');
        $lines=pac_vendor_suggestion_lines($pdo,$org,(string)$vendor['public_id']);if(!$lines)throw new InvalidArgumentException('There are no longer any current order suggestions mapped to that vendor.');
        if(pac_lines_signature($lines)!==(string)$payload['signature']){pac_pending_clear($org,$uid);throw new InvalidArgumentException('The purchasing suggestions changed after I proposed the PO. Review the current suggestions and ask again.');}
        $po=purchasing_create_order($pdo,$org,(string)$vendor['public_id'],$lines,$uid,null,'Drafted through Gelato Agent from current purchasing suggestions.');
        app_audit($pdo,$org,$uid,'purchasing.agent_po_created','purchase_order',(string)$po['public_id'],null,['proposalId'=>$proposal['id']]);
        return ['skill'=>'purchasing.action_confirmed','answer'=>'Confirmed. Created '.$po['order_number'].' as a draft for '.$po['vendor_name'].' with '.count($po['items']).' line'.(count($po['items'])===1?'':'s').'.','data'=>['action'=>'draft_po','purchaseOrderId'=>$po['public_id']],'sources'=>['Purchasing Suggestions','Purchase Orders']];
    }
    if(in_array($type,['submit_po','cancel_po','receive_po_full'],true)){
        $po=purchasing_order($pdo,$org,(string)$payload['purchaseOrderId']);if(!$po)throw new InvalidArgumentException('The selected purchase order no longer exists.');pac_guard_order($po,(array)$payload['expected']);
        if($type==='submit_po'){$saved=purchasing_submit($pdo,$org,(string)$po['public_id'],$uid);app_audit($pdo,$org,$uid,'purchasing.agent_po_submitted','purchase_order',(string)$po['public_id'],null,['proposalId'=>$proposal['id']]);return ['skill'=>'purchasing.action_confirmed','answer'=>'Confirmed. Marked '.$saved['order_number'].' submitted to '.$saved['vendor_name'].'.','data'=>['action'=>'submit_po','purchaseOrderId'=>$saved['public_id']],'sources'=>['Purchase Orders']];}
        if($type==='cancel_po'){$saved=pac_cancel_order($pdo,$org,$po,$uid);app_audit($pdo,$org,$uid,'purchasing.agent_po_cancelled','purchase_order',(string)$po['public_id'],null,['proposalId'=>$proposal['id']]);return ['skill'=>'purchasing.action_confirmed','answer'=>'Confirmed. Cancelled '.$saved['order_number'].'.','data'=>['action'=>'cancel_po','purchaseOrderId'=>$saved['public_id']],'sources'=>['Purchase Orders']];}
        $lines=pac_outstanding_lines($po);if(!$lines)throw new InvalidArgumentException('That purchase order has no outstanding quantity to receive.');if(pac_outstanding_signature($lines)!==(string)$payload['outstandingSignature']){pac_pending_clear($org,$uid);throw new InvalidArgumentException('The outstanding quantities changed after I proposed receiving the PO. Review the order and ask again.');}
        $receipt=purchasing_receive($pdo,$org,(string)$po['public_id'],array_map(static fn($line)=>['itemId'=>$line['itemId'],'quantity'=>$line['quantity']],$lines),$uid,'','Received in full through Gelato Agent after explicit confirmation.');app_audit($pdo,$org,$uid,'purchasing.agent_po_received','purchase_order',(string)$po['public_id'],null,['proposalId'=>$proposal['id'],'receiptId'=>$receipt['receiptId']??null]);return ['skill'=>'purchasing.action_confirmed','answer'=>'Confirmed. Recorded all remaining quantities on '.$po['order_number'].' as received in full. Receipt '.($receipt['receiptNumber']??'created').'.','data'=>['action'=>'receive_po_full','purchaseOrderId'=>$po['public_id'],'receiptId'=>$receipt['receiptId']??null],'sources'=>['Purchase Orders','Goods Receiving','Inventory']];
    }
    throw new InvalidArgumentException('That pending Purchasing Agent action is no longer supported.');
}

function purchasing_agent_handle(PDO $pdo,array $user,array $input): array{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];$message=trim((string)($input['message']??''));if($message===''||mb_strlen($message,'UTF-8')>1800)throw new InvalidArgumentException('Ask Gelato a purchasing question no longer than 1,800 characters.');
    pac_require($user,'purchasing.view','Purchasing view permission is required.');
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];$selected=($context['module']??'')==='purchasing'?pac_selected_order($pdo,$org,$context):null;$pending=pac_pending_get($org,$uid);

    if(preg_match('/^(?:confirm|yes|yes please|do it|go ahead|execute|apply)(?:\s+(?:it|that|change|action))?[.!]?$/i',$message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Purchasing Agent action to confirm.');$result=pac_execute_pending($pdo,$org,$uid,$user,$pending);pac_pending_clear($org,$uid);app_audit($pdo,$org,$uid,'purchasing.agent_action_confirmed','purchasing_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);return ['ok'=>true]+$result;
    }
    if(preg_match('/^(?:cancel|cancel it|discard|never mind|nevermind|stop)[.!]?$/i',$message)&&$pending){pac_pending_clear($org,$uid);app_audit($pdo,$org,$uid,'purchasing.agent_action_discarded','purchasing_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);return ['ok'=>true,'skill'=>'purchasing.action_cancelled','answer'=>'Cancelled. I did not change purchasing or inventory.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Purchasing + Receiving']];}

    if($selected&&preg_match('/\b(?:show|describe|details?|status|what is|what\x27s|about)\s+(?:this|selected)\s+(?:po|purchase order)\b/i',$message))return ['ok'=>true,'skill'=>'purchasing.context_order','answer'=>pac_order_answer($selected).'.','data'=>['purchaseOrderId'=>$selected['public_id']],'sources'=>['Purchase Orders']];
    if($selected&&preg_match('/\b(?:outstanding|remaining|missing|still due|still coming)\b/i',$message)){$lines=pac_outstanding_lines($selected);$answer=$lines?implode('; ',array_map(static fn($line)=>$line['name'].' '.round((float)$line['quantity'],2),$lines)).' outstanding on '.$selected['order_number'].'.':'Nothing remains outstanding on '.$selected['order_number'].'.';return ['ok'=>true,'skill'=>'purchasing.context_outstanding','answer'=>$answer,'data'=>['purchaseOrderId'=>$selected['public_id'],'outstandingLines'=>count($lines)],'sources'=>['Purchase Orders','Goods Receiving']];}

    if($selected&&preg_match('/\bsubmit\s+(?:this|selected)\s+(?:po|purchase order)\b/i',$message)){
        pac_require($user,'purchasing.manage','Purchasing management permission is required to submit purchase orders.');if($selected['status']!=='draft')throw new InvalidArgumentException('Only a draft purchase order can be submitted.');$summary='Proposed action: submit '.$selected['order_number'].' to '.$selected['vendor_name'].' for $'.number_format((float)$selected['total_amount'],2).'.';$proposal=pac_pending_store($org,$uid,'submit_po',['purchaseOrderId'=>$selected['public_id'],'expected'=>pac_order_version($selected)],$summary);app_audit($pdo,$org,$uid,'purchasing.agent_action_proposed','purchasing_agent_proposal',(string)$proposal['id'],null,['type'=>'submit_po']);return pac_proposal_result($proposal,['Purchase Orders']);
    }
    if($selected&&preg_match('/\b(?:cancel|void)\s+(?:this|selected)\s+(?:po|purchase order)\b/i',$message)){
        pac_require($user,'purchasing.manage','Purchasing management permission is required to cancel purchase orders.');if(in_array($selected['status'],['received','cancelled'],true))throw new InvalidArgumentException('This purchase order cannot be cancelled in its current status.');$summary='Proposed action: cancel '.$selected['order_number'].' for '.$selected['vendor_name'].'.';$proposal=pac_pending_store($org,$uid,'cancel_po',['purchaseOrderId'=>$selected['public_id'],'expected'=>pac_order_version($selected)],$summary);app_audit($pdo,$org,$uid,'purchasing.agent_action_proposed','purchasing_agent_proposal',(string)$proposal['id'],null,['type'=>'cancel_po']);return pac_proposal_result($proposal,['Purchase Orders']);
    }
    if($selected&&preg_match('/\b(?:receive|received|record)\b.*\b(?:all remaining|everything|in full|complete shipment|full shipment)\b|\b(?:everything|full shipment)\b.*\b(?:arrived|received)\b/i',$message)){
        pac_require($user,'receiving.manage','Receiving management permission is required to receive purchase orders.');if(!in_array($selected['status'],['submitted','partially_received'],true))throw new InvalidArgumentException('Only a submitted or partially received purchase order can be received.');$lines=pac_outstanding_lines($selected);if(!$lines)throw new InvalidArgumentException('This purchase order has no outstanding quantity.');$summary='Proposed action: record every remaining quantity on '.$selected['order_number'].' as received in full with no damage or shortage exceptions. This will increase usable inventory.';$proposal=pac_pending_store($org,$uid,'receive_po_full',['purchaseOrderId'=>$selected['public_id'],'expected'=>pac_order_version($selected),'outstandingSignature'=>pac_outstanding_signature($lines)],$summary);app_audit($pdo,$org,$uid,'purchasing.agent_action_proposed','purchasing_agent_proposal',(string)$proposal['id'],null,['type'=>'receive_po_full']);return pac_proposal_result($proposal,['Purchase Orders','Goods Receiving','Inventory']);
    }

    if(preg_match('/\b(?:create|build|make|draft)\b.*\b(?:purchase order|po)\b/i',$message)){
        pac_require($user,'purchasing.manage','Purchasing management permission is required to draft purchase orders.');$vendor=pac_vendor_from_message($pdo,$org,$message);if(!$vendor)throw new InvalidArgumentException('Name the vendor for the draft PO so I can match the current purchasing suggestions.');$lines=pac_vendor_suggestion_lines($pdo,$org,(string)$vendor['public_id']);if(!$lines)throw new InvalidArgumentException('There are no current purchasing suggestions mapped to '.$vendor['name'].'.');$estimated=0.0;foreach($lines as $line)if($line['pricePerPack']!==null)$estimated+=(float)$line['pricePerPack']*(float)$line['packs'];$summary='Proposed action: create a draft PO for '.$vendor['name'].' from '.count($lines).' current purchasing suggestion'.(count($lines)===1?'':'s').' (estimated $'.number_format($estimated,2).').';$proposal=pac_pending_store($org,$uid,'draft_po',['vendorId'=>$vendor['public_id'],'signature'=>pac_lines_signature($lines)],$summary);app_audit($pdo,$org,$uid,'purchasing.agent_action_proposed','purchasing_agent_proposal',(string)$proposal['id'],null,['type'=>'draft_po','vendorId'=>$vendor['public_id']]);return pac_proposal_result($proposal,['Purchasing Suggestions','Vendor Catalog','Purchase Orders']);
    }

    if(preg_match('/\b(?:what did we last pay for|last paid for|price history for)\b/i',$message)){$query=pac_extract_item_query($message);if($query==='')throw new InvalidArgumentException('Name the inventory item whose price history you want.');$history=pac_price_history($pdo,$org,$query);if(!$history)return ['ok'=>true,'skill'=>'purchasing.price_history','answer'=>'No vendor price history matched “'.$query.'”.','data'=>['matches'=>0],'sources'=>['Vendor Price History']];$parts=[];foreach(array_slice($history,0,8) as $row)$parts[]=$row['vendor_name'].' $'.number_format((float)$row['price_per_pack'],2).' on '.date('M j',strtotime($row['created_at']));return ['ok'=>true,'skill'=>'purchasing.price_history','answer'=>$row['inventory_name'].': '.implode('; ',$parts).'.','data'=>['matches'=>count($history)],'sources'=>['Vendor Price History']];}
    if(preg_match('/\b(compare|price|cheapest|vendor price)\b/i',$message)){$query=pac_extract_item_query($message);$rows=purchasing_price_compare($pdo,$org,$query);if(!$rows)return ['ok'=>true,'skill'=>'purchasing.price_compare','answer'=>'No active vendor catalog prices matched “'.$query.'”.','data'=>['matches'=>0],'sources'=>['Vendor Catalog']];$parts=[];foreach(array_slice($rows,0,8) as $row)$parts[]=$row['vendor_name'].' $'.number_format((float)$row['price_per_pack'],2).'/pack'.($row['unit_price']!==null?' ($'.number_format((float)$row['unit_price'],2).'/base unit)':'');return ['ok'=>true,'skill'=>'purchasing.price_compare','answer'=>$rows[0]['inventory_name'].': '.implode('; ',$parts).'.','data'=>['matches'=>count($rows)],'sources'=>['Vendor Catalog']];}
    if(preg_match('/\b(receiv|receipt|invoice|delivery histor)\b/i',$message)){$rows=purchasing_receipts($pdo,$org,12);if(!$rows)return ['ok'=>true,'skill'=>'purchasing.receiving','answer'=>'No goods receipts are recorded yet.','data'=>['receipts'=>0],'sources'=>['Goods Receiving']];$parts=[];foreach($rows as $row)$parts[]=$row['receipt_number'].' '.$row['vendor_name'].' '.date('M j',strtotime($row['received_at']));return ['ok'=>true,'skill'=>'purchasing.receiving','answer'=>'Recent receiving: '.implode('; ',$parts).'.','data'=>['receipts'=>count($rows)],'sources'=>['Goods Receiving']];}
    if(preg_match('/\b(?:what.*order|restock|low stock|inventory pressure|need to buy|need to order|should buy|suggestions?)\b/i',$message)){$result=pac_suggestion_answer($pdo,$org);return ['ok'=>true,'skill'=>'purchasing.suggestions','answer'=>$result['answer'],'data'=>['suggestions'=>$result['count']],'sources'=>['Inventory','Prep Forecasts','Purchasing Suggestions']];}
    if(preg_match('/\b(?:open|draft|purchase orders?|po status|orders?)\b/i',$message)){$rows=purchasing_orders($pdo,$org);$open=array_values(array_filter($rows,static fn($row)=>in_array($row['status'],['draft','submitted','partially_received'],true)));if(!$open)return ['ok'=>true,'skill'=>'purchasing.orders','answer'=>'There are no draft or open purchase orders.','data'=>['orders'=>0],'sources'=>['Purchase Orders']];$parts=[];foreach(array_slice($open,0,10) as $po)$parts[]=$po['order_number'].' '.$po['vendor_name'].' '.str_replace('_',' ',$po['status']).' $'.number_format((float)$po['total_amount'],2);return ['ok'=>true,'skill'=>'purchasing.orders','answer'=>'Open purchasing: '.implode('; ',$parts).'.','data'=>['orders'=>count($open)],'sources'=>['Purchase Orders']];}

    $summary=purchasing_summary($pdo,$org);return ['ok'=>true,'skill'=>'purchasing.summary','answer'=>'Purchasing: '.$summary['suggestions'].' suggestions, '.$summary['unmapped'].' without a vendor map, '.$summary['drafts'].' draft POs, '.$summary['openOrders'].' open submitted orders, and $'.number_format((float)$summary['committed'],2).' committed on open orders.','data'=>$summary,'sources'=>['Purchasing','Inventory','Purchase Orders']];
}
