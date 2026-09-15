<?php
declare(strict_types=1);

require_once __DIR__.'/online-order-core.php';
require_once __DIR__.'/menu-manager-core.php';

function menu_order_customization_context(PDO $pdo,int $organizationId,int $priceId,?int $locationId=null): array
{
    $context=online_order_customization_context($pdo,$organizationId,$priceId);
    $context['addOnGroups']=menu_manager_ready($pdo)?menu_manager_customization_addons($pdo,$organizationId,$priceId,'online_order',$locationId):[];
    return $context;
}

function menu_order_addons_raw(array $row): array
{
    $custom=is_array($row['customizations']??null)?$row['customizations']:[];$out=[];
    foreach(is_array($custom['addOns']??null)?$custom['addOns']:[] as $entry){
        if(!is_array($entry))continue;$optionId=(int)($entry['optionId']??0);$quantity=(int)($entry['quantity']??0);
        if($optionId>0&&$quantity>0)$out[]=['optionId'=>$optionId,'quantity'=>$quantity];
        if(count($out)>50)throw new InvalidArgumentException('Too many add-ons were selected for one item.');
    }
    return $out;
}

function menu_order_cart(array $raw): array
{
    if(!$raw||count($raw)>60)throw new InvalidArgumentException('Your cart must contain between 1 and 60 line items.');$items=[];
    foreach($raw as $row){
        if(!is_array($row))continue;$priceId=max(0,(int)($row['priceId']??0));$quantity=round((float)($row['quantity']??0),3);$instructions=mb_substr(trim((string)($row['instructions']??'')),0,300,'UTF-8');$custom=online_order_customizations($row);$custom['addOns']=menu_order_addons_raw($row);
        if($priceId<1||$quantity<=0||$quantity>20)throw new InvalidArgumentException('One of the cart items has an invalid quantity or menu option.');
        $signature=json_encode([$custom,$instructions],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$key=$priceId.'|'.hash('sha256',$signature);
        if(isset($items[$key])){$items[$key]['quantity']=round($items[$key]['quantity']+$quantity,3);if($items[$key]['quantity']>20)throw new InvalidArgumentException('A menu item quantity cannot exceed 20.');}
        else $items[$key]=['priceId'=>$priceId,'quantity'=>$quantity,'instructions'=>$instructions,'customizations'=>$custom];
    }
    if(!$items)throw new InvalidArgumentException('Add at least one item before placing the order.');return array_values($items);
}

function menu_order_validate_line(PDO $pdo,int $org,int $locationId,array $item,string $orderNote,bool $includeOrderNote): array
{
    if(menu_manager_ready($pdo)&&!menu_manager_price_channel_enabled($pdo,$org,(int)$item['priceId'],'online_order',$locationId))throw new InvalidArgumentException('One of the selected menu items is no longer available for online ordering at this location.');
    $instructions=online_order_line_instructions($pdo,$org,$item,$orderNote,$includeOrderNote);$raw=is_array($item['customizations']['addOns']??null)?$item['customizations']['addOns']:[];$addons=menu_manager_ready($pdo)?menu_manager_validate_addons($pdo,$org,(int)$item['priceId'],$raw,'online_order',$locationId):[];
    if($addons){$labels=[];foreach($addons as $addon)$labels[]=$addon['name'].((int)$addon['quantity']>1?' x'.(int)$addon['quantity']:'').' (+'.number_format((float)$addon['lineAmount'],2).')';$addonText='ADD-ONS: '.implode(', ',$labels);$instructions=mb_substr(trim($instructions)!==''?$instructions.' • '.$addonText:$addonText,0,1000,'UTF-8');}
    return ['instructions'=>$instructions,'addons'=>$addons];
}

function menu_order_submit_pickup(PDO $pdo,int $organizationId,array $account,array $input): array
{
    if(!online_order_ready($pdo))throw new RuntimeException('Online ordering is not installed. Run Upgrade first.');
    if(!app_has_permission('online_ordering.use',$account))throw new RuntimeException('Online ordering permission is required.');
    $userId=(int)($account['id']??0);$customerId=(int)($account['customer_id']??0);if($userId<1||$customerId<1)throw new RuntimeException('Your customer account is not linked to the restaurant CRM.');
    $locationId=max(0,(int)($input['locationId']??0));$location=online_order_location($pdo,$organizationId,$locationId);$idempotency=online_order_idempotency_key($input['idempotencyKey']??null);
    if($existing=online_order_existing($pdo,$organizationId,$customerId,$idempotency))return $existing+['duplicate'=>true];
    $cart=menu_order_cart(is_array($input['items']??null)?$input['items']:[]);$note=mb_substr(trim((string)($input['note']??'')),0,1000,'UTF-8');$readyAt=online_order_requested_ready_at($pdo,$organizationId,$location);$validated=[];
    foreach($cart as $index=>$item)$validated[$index]=menu_order_validate_line($pdo,$organizationId,$locationId,$item,$note,$index===0);
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $check=pos_create_check($pdo,$organizationId,$locationId,['serviceMode'=>'pickup','tableName'=>'Online Pickup','guestCount'=>1,'notes'=>$note!==''?'Online pickup: '.$note:'Online pickup order'],$userId);
        $pdo->prepare('UPDATE pos_checks SET customer_id=? WHERE organization_id=? AND id=?')->execute([$customerId,$organizationId,(int)$check['id']]);
        foreach($cart as $index=>$item){$check=pos_add_item($pdo,$organizationId,(string)$check['publicId'],(int)$item['priceId'],(float)$item['quantity'],(string)$validated[$index]['instructions'],$userId);if(!empty($validated[$index]['addons']))$check=menu_manager_apply_pos_addons($pdo,$organizationId,(string)$check['publicId'],(int)$item['priceId'],(float)$item['quantity'],$validated[$index]['addons']);}
        $orderPublic=customer_inbox_public_id('online-order');
        $pdo->prepare("INSERT INTO online_orders (organization_id,location_id,customer_id,user_id,pos_check_id,public_id,idempotency_key,service_mode,payment_mode,status,requested_ready_at,customer_note) VALUES (?,?,?,?,?,?,?,'pickup','pay_at_pickup','submitted',?,?)")->execute([$organizationId,$locationId,$customerId,$userId,(int)$check['id'],$orderPublic,$idempotency,$readyAt,$note?:null]);
        $kitchen=['ready'=>false,'sent'=>0,'unsent'=>count($cart),'unrouted'=>0,'items'=>[]];if(kds_ready($pdo))$kitchen=kds_send_check($pdo,$organizationId,(string)$check['publicId'],$userId,false);$lifecycle=online_order_lifecycle_sync($pdo,$organizationId,(string)$check['publicId'],$userId);
        $readyDisplay=(new DateTimeImmutable($readyAt))->format('g:i A');customer_inbox_send_direct($pdo,$organizationId,$customerId,['messageType'=>'order_update','locationId'=>$locationId,'title'=>'Order received — '.$check['checkNumber'],'previewText'=>'Your pickup order is in the restaurant workflow.','bodyText'=>'We received your pickup order for '.$location['name'].'. Estimated ready time: '.$readyDisplay.'. Payment is due when you pick up the order.','ctaLabel'=>'View order history','ctaUrl'=>'customer-account.php#orders'],$userId);
        try{app_audit($pdo,$organizationId,$userId,'online_order.submitted','online_order',$orderPublic,null,['checkPublicId'=>$check['publicId'],'customerId'=>$customerId,'locationId'=>$locationId,'total'=>$check['totalAmount'],'paymentMode'=>'pay_at_pickup','customizedLines'=>count(array_filter($validated,static fn(array $v):bool=>trim((string)$v['instructions'])!==''))]);}catch(Throwable){}
        if($owns)$pdo->commit();return ['public_id'=>$orderPublic,'status'=>(string)($lifecycle['order_status']??'submitted'),'payment_mode'=>'pay_at_pickup','requested_ready_at'=>$readyAt,'submitted_at'=>(new DateTimeImmutable())->format('Y-m-d H:i:s.u'),'check_public_id'=>$check['publicId'],'check_number'=>$check['checkNumber'],'subtotal'=>$check['subtotal'],'tax_amount'=>$check['taxAmount'],'service_charge_amount'=>$check['serviceChargeAmount'],'total_amount'=>$check['totalAmount'],'check_status'=>$check['status'],'location_name'=>$location['name'],'kitchen'=>$kitchen,'duplicate'=>false];
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
