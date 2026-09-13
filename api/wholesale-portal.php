<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/wholesale-portal-operations.php';

$user=app_require_permission('wholesale_portal.view');
$pdo=app_pdo();
$organizationId=(int)$user['organization_id'];
$account=wholesale_portal_require_account($pdo,$user);
$accountId=(int)$account['id'];

if($_SERVER['REQUEST_METHOD']==='GET'){
    $context=wholesale_portal_dashboard($pdo,$organizationId,$accountId);
    if(isset($context['account']))unset($context['account']['id'],$context['account']['organization_id'],$context['account']['internal_notes'],$context['account']['created_by'],$context['account']['updated_by'],$context['account']['archived_at'],$context['account']['wholesale_lead_id']);
    $context['portal']=['user'=>['displayName'=>$user['display_name'],'email'=>$user['email'],'accountRole'=>$account['account_role']],'w5Ready'=>wholesale_portal_operations_ready($pdo)];
    app_json_response(['ok'=>true]+$context);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');

if($action==='profile'){
    if(!app_has_permission('wholesale_portal.profile_edit',$user))app_json_response(['ok'=>false,'message'=>'You do not have permission to edit this wholesale profile.'],403);
    $packages=array_values(array_filter(array_map(static fn($v)=>mb_substr(trim((string)$v),0,100,'UTF-8'),(array)($input['packagePreferences']??[]))));
    $billing=trim((string)($input['billingEmail']??''));if($billing!==''&&!filter_var($billing,FILTER_VALIDATE_EMAIL))app_json_response(['ok'=>false,'message'=>'Billing email is invalid.'],422);
    $update=$pdo->prepare("UPDATE wholesale_accounts SET phone=?,website=?,billing_email=?,preferred_fulfillment=?,flavor_preferences=?,package_preferences_json=?,private_label_interest=?,customer_notes=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?");
    $update->execute([mb_substr(trim((string)($input['phone']??'')),0,50,'UTF-8')?:null,mb_substr(trim((string)($input['website']??'')),0,500,'UTF-8')?:null,$billing?:null,mb_substr(trim((string)($input['preferredFulfillment']??'')),0,80,'UTF-8')?:null,mb_substr(trim((string)($input['flavorPreferences']??'')),0,10000,'UTF-8')?:null,json_encode($packages,JSON_THROW_ON_ERROR),(int)!empty($input['privateLabelInterest']),mb_substr(trim((string)($input['customerNotes']??'')),0,10000,'UTF-8')?:null,(int)$user['id'],$accountId,$organizationId]);
    wholesale_portal_sync_account_knowledge($pdo,$organizationId,$accountId,(int)$user['id']);
    app_audit($pdo,$organizationId,(int)$user['id'],'wholesale.portal_profile_updated','wholesale_account',(string)$account['public_id']);
    app_json_response(['ok'=>true,'message'=>'Wholesale profile updated.']);
}

if($action==='request'){
    if(!app_has_permission('wholesale_portal.requests',$user))app_json_response(['ok'=>false,'message'=>'You do not have permission to submit wholesale requests.'],403);
    $type=(string)($input['requestType']??'support');if(!in_array($type,['reorder','sample','product','delivery','support'],true))$type='support';
    $subject=mb_substr(trim((string)($input['subject']??'')),0,220,'UTF-8');$details=mb_substr(trim((string)($input['details']??'')),0,10000,'UTF-8');if($subject===''||$details==='')app_json_response(['ok'=>false,'message'=>'Enter a subject and request details.'],422);
    $publicId=wholesale_portal_public_id('wreq');$metadata=(array)($input['metadata']??[]);
    $insert=$pdo->prepare("INSERT INTO wholesale_customer_requests (organization_id,wholesale_account_id,submitted_by,public_id,request_type,subject,details,metadata_json) VALUES (?,?,?,?,?,?,?,?)");$insert->execute([$organizationId,$accountId,(int)$user['id'],$publicId,$type,$subject,$details,$metadata?json_encode($metadata,JSON_THROW_ON_ERROR):null]);
    if(!empty($account['wholesale_lead_id'])){$activity=$pdo->prepare("INSERT INTO wholesale_lead_activities (organization_id,wholesale_lead_id,activity_type,summary,details,created_by) VALUES (?,?,'customer_request',?,?,?)");$activity->execute([$organizationId,(int)$account['wholesale_lead_id'],'Customer portal request: '.$subject,$details,(int)$user['id']]);}
    wholesale_portal_sync_account_knowledge($pdo,$organizationId,$accountId,(int)$user['id']);
    app_audit($pdo,$organizationId,(int)$user['id'],'wholesale.portal_request_created','wholesale_customer_request',$publicId,null,['type'=>$type,'subject'=>$subject]);
    app_json_response(['ok'=>true,'message'=>'Your request was sent to the wholesale team.','requestId'=>$publicId]);
}

if($action==='repeat_order'){
    if(!app_has_permission('wholesale_portal.orders',$user))app_json_response(['ok'=>false,'message'=>'You do not have permission to place wholesale reorders.'],403);
    $sourceOrder=trim((string)($input['orderId']??''));if($sourceOrder==='')app_json_response(['ok'=>false,'message'=>'Order ID is required.'],422);
    $result=wholesale_portal_repeat_order($pdo,$organizationId,$accountId,$sourceOrder,$input,(int)$user['id']);
    app_json_response(['ok'=>true,'message'=>'Repeat order '.$result['orderNumber'].' was created as a requested order using current catalog pricing.','order'=>$result]);
}

if($action==='catalog_request'){
    if(!app_has_permission('wholesale_portal.requests',$user))app_json_response(['ok'=>false,'message'=>'You do not have permission to submit catalog requests.'],403);
    $result=wholesale_portal_catalog_request($pdo,$organizationId,$accountId,$input,(int)$user['id']);
    app_json_response(['ok'=>true,'message'=>'Your catalog order request was sent to the wholesale team. Tax and delivery will be confirmed before fulfillment.']+$result);
}

if($action==='accept_quote'){
    if(!app_has_permission('wholesale_portal.quotes',$user))app_json_response(['ok'=>false,'message'=>'You do not have permission to accept quotes.'],403);
    $quoteId=trim((string)($input['quoteId']??''));if($quoteId==='')app_json_response(['ok'=>false,'message'=>'Quote ID is required.'],422);
    $commerceReady=wholesale_commerce_ready($pdo);
    $pdo->beginTransaction();
    try{
        $statement=$pdo->prepare("SELECT * FROM wholesale_quotes WHERE organization_id=? AND wholesale_account_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $statement->execute([$organizationId,$accountId,$quoteId]);$quote=$statement->fetch();
        if(!$quote||$quote['status']!=='sent'){if($pdo->inTransaction())$pdo->rollBack();app_json_response(['ok'=>false,'message'=>'This quote is unavailable or is no longer awaiting acceptance.'],409);}
        if($quote['valid_until']&&strtotime((string)$quote['valid_until'])<strtotime(date('Y-m-d'))){if($pdo->inTransaction())$pdo->rollBack();app_json_response(['ok'=>false,'message'=>'This quote has expired. Contact the wholesale team for an updated quote.'],409);}
        $existing=$pdo->prepare('SELECT order_number FROM wholesale_orders WHERE source_quote_id=? LIMIT 1 FOR UPDATE');$existing->execute([(int)$quote['id']]);$existingOrder=$existing->fetchColumn();
        if($existingOrder){if($pdo->inTransaction())$pdo->rollBack();app_json_response(['ok'=>false,'message'=>'This quote has already created order '.$existingOrder.'.'],409);}
        $pdo->prepare("UPDATE wholesale_quotes SET status='accepted',accepted_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=? AND status='sent'")->execute([(int)$user['id'],(int)$quote['id'],$organizationId]);
        $orderPublic=wholesale_portal_public_id('worder');$orderNumber='W-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
        if($commerceReady){
            $insert=$pdo->prepare("INSERT INTO wholesale_orders (organization_id,wholesale_account_id,price_list_id,source_quote_id,public_id,order_number,status,items_json,subtotal,delivery_fee,tax_total,tax_rate_percent,total,fulfillment_type,customer_notes,created_by,updated_by) VALUES (?,?,?,?,?,?,'requested',?,?,?,?,?,?,?,?,?,?)");
            $insert->execute([$organizationId,$accountId,$quote['price_list_id']??null,(int)$quote['id'],$orderPublic,$orderNumber,$quote['items_json'],$quote['subtotal'],$quote['delivery_fee'],$quote['tax_total'],$quote['tax_rate_percent']??0,$quote['total'],$account['preferred_fulfillment'],'Created automatically when customer accepted '.$quote['quote_number'].'.',(int)$user['id'],(int)$user['id']]);
            $orderDbId=(int)$pdo->lastInsertId();
            wholesale_commerce_copy_quote_lines_to_order($pdo,$organizationId,(int)$quote['id'],$orderDbId);
            wholesale_commerce_quote_event($pdo,$organizationId,(int)$quote['id'],'accepted','Customer accepted wholesale quote.',(int)$user['id'],['orderNumber'=>$orderNumber]);
            wholesale_commerce_order_event($pdo,$organizationId,$orderDbId,'created_from_quote','Wholesale order created from accepted quote.',(int)$user['id'],['quoteNumber'=>$quote['quote_number']]);
        }else{
            $insert=$pdo->prepare("INSERT INTO wholesale_orders (organization_id,wholesale_account_id,source_quote_id,public_id,order_number,status,items_json,subtotal,delivery_fee,tax_total,total,fulfillment_type,customer_notes,created_by,updated_by) VALUES (?,?,?,?,?,'requested',?,?,?,?,?,?,?,?,?)");
            $insert->execute([$organizationId,$accountId,(int)$quote['id'],$orderPublic,$orderNumber,$quote['items_json'],$quote['subtotal'],$quote['delivery_fee'],$quote['tax_total'],$quote['total'],$account['preferred_fulfillment'],'Created automatically when customer accepted '.$quote['quote_number'].'.',(int)$user['id'],(int)$user['id']]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(!empty($account['wholesale_lead_id'])){$activity=$pdo->prepare("INSERT INTO wholesale_lead_activities (organization_id,wholesale_lead_id,activity_type,summary,details,created_by) VALUES (?,?,'quote','Customer accepted wholesale quote',?,?)");$activity->execute([$organizationId,(int)$account['wholesale_lead_id'],$quote['quote_number'].' accepted; order '.$orderNumber.' created.',(int)$user['id']]);}
    wholesale_portal_sync_account_knowledge($pdo,$organizationId,$accountId,(int)$user['id']);
    if(operations_wholesale_ready($pdo))operations_sync_wholesale_tasks($pdo,$organizationId,(int)$user['id']);
    app_audit($pdo,$organizationId,(int)$user['id'],'wholesale.quote_accepted','wholesale_quote',$quoteId,null,['orderNumber'=>$orderNumber]);
    app_json_response(['ok'=>true,'message'=>'Quote accepted. Your order request has been created and added to Operations.','orderNumber'=>$orderNumber]);
}
app_json_response(['ok'=>false,'message'=>'Unsupported wholesale portal action.'],422);
