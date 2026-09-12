<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/wholesale-portal.php';
require_once __DIR__ . '/../includes/operations-wholesale.php';

$user = app_require_permission($_SERVER['REQUEST_METHOD']==='GET' ? 'wholesale.view' : 'wholesale.manage');
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
if (!wholesale_portal_table_ready($pdo,'wholesale_accounts')) app_json_response(['ok'=>false,'message'=>'Wholesale customer portal migration is not installed. Run upgrade.php.'],503);

function wholesale_admin_account(PDO $pdo,int $organizationId,string $publicId): array
{
    $statement=$pdo->prepare("SELECT a.*,(SELECT COUNT(*) FROM wholesale_account_users au WHERE au.wholesale_account_id=a.id AND au.status='active') AS user_count,(SELECT COUNT(*) FROM wholesale_customer_requests r WHERE r.wholesale_account_id=a.id AND r.status IN ('new','reviewing')) AS open_request_count FROM wholesale_accounts a WHERE a.organization_id=? AND a.public_id=? AND a.archived_at IS NULL LIMIT 1");
    $statement->execute([$organizationId,$publicId]);
    $row=$statement->fetch();
    if(!$row) app_json_response(['ok'=>false,'message'=>'Wholesale customer account not found.'],404);
    return $row;
}

function wholesale_admin_detail(PDO $pdo,int $organizationId,array $account): array
{
    $accountId=(int)$account['id'];
    $users=$pdo->prepare("SELECT u.id,u.display_name,u.email,au.account_role,au.is_primary,au.status,u.last_login_at FROM wholesale_account_users au INNER JOIN users u ON u.id=au.user_id WHERE au.organization_id=? AND au.wholesale_account_id=? ORDER BY au.is_primary DESC,u.display_name");
    $users->execute([$organizationId,$accountId]);
    $invites=$pdo->prepare("SELECT id,email,contact_name,account_role,expires_at,accepted_at,revoked_at,created_at FROM wholesale_portal_invites WHERE organization_id=? AND wholesale_account_id=? ORDER BY created_at DESC LIMIT 20");
    $invites->execute([$organizationId,$accountId]);
    $quotes=$pdo->prepare("SELECT public_id,quote_number,status,items_json,subtotal,delivery_fee,tax_total,total,valid_until,customer_message,terms_text,sent_at,accepted_at,declined_at,created_at,updated_at FROM wholesale_quotes WHERE organization_id=? AND wholesale_account_id=? ORDER BY created_at DESC LIMIT 50");
    $quotes->execute([$organizationId,$accountId]);
    $orders=$pdo->prepare("SELECT public_id,order_number,status,items_json,subtotal,delivery_fee,tax_total,total,fulfillment_type,requested_for,promised_for,delivered_at,customer_notes,internal_notes,created_at,updated_at FROM wholesale_orders WHERE organization_id=? AND wholesale_account_id=? ORDER BY created_at DESC LIMIT 50");
    $orders->execute([$organizationId,$accountId]);
    $requests=$pdo->prepare("SELECT r.*,u.display_name AS submitted_by_name FROM wholesale_customer_requests r INNER JOIN users u ON u.id=r.submitted_by WHERE r.organization_id=? AND r.wholesale_account_id=? ORDER BY r.created_at DESC LIMIT 100");
    $requests->execute([$organizationId,$accountId]);
    $locations=$pdo->prepare("SELECT * FROM wholesale_account_locations WHERE organization_id=? AND wholesale_account_id=? ORDER BY is_primary DESC,name");
    $locations->execute([$organizationId,$accountId]);
    return ['account'=>$account,'users'=>$users->fetchAll(),'invites'=>$invites->fetchAll(),'quotes'=>$quotes->fetchAll(),'orders'=>$orders->fetchAll(),'requests'=>$requests->fetchAll(),'locations'=>$locations->fetchAll()];
}

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'list');
    if($action==='list'){
        $accounts=$pdo->prepare("SELECT a.public_id,a.business_name,a.account_status,a.primary_email,a.phone,a.business_type,a.price_tier,a.payment_terms,a.preferred_fulfillment,a.private_label_interest,a.updated_at,l.public_id AS lead_public_id,l.pipeline_stage,(SELECT COUNT(*) FROM wholesale_account_users au WHERE au.wholesale_account_id=a.id AND au.status='active') AS user_count,(SELECT COUNT(*) FROM wholesale_customer_requests r WHERE r.wholesale_account_id=a.id AND r.status IN ('new','reviewing')) AS open_request_count FROM wholesale_accounts a LEFT JOIN wholesale_leads l ON l.id=a.wholesale_lead_id WHERE a.organization_id=? AND a.archived_at IS NULL ORDER BY a.updated_at DESC");
        $accounts->execute([$organizationId]);
        $leads=$pdo->prepare("SELECT l.public_id,l.business_name,l.contact_name,l.email,l.pipeline_stage,l.business_type,l.location_text FROM wholesale_leads l LEFT JOIN wholesale_accounts a ON a.wholesale_lead_id=l.id AND a.archived_at IS NULL WHERE l.organization_id=? AND l.archived_at IS NULL AND a.id IS NULL ORDER BY FIELD(l.pipeline_stage,'won','negotiation','quoted','sample','qualified','new','lost'),l.updated_at DESC");
        $leads->execute([$organizationId]);
        app_json_response(['ok'=>true,'accounts'=>$accounts->fetchAll(),'availableLeads'=>$leads->fetchAll()]);
    }
    if($action==='detail'){
        $account=wholesale_admin_account($pdo,$organizationId,trim((string)($_GET['id']??'')));
        app_json_response(['ok'=>true]+wholesale_admin_detail($pdo,$organizationId,$account));
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported wholesale customer account action.'],422);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');

if($action==='create_account'){
    $leadId=trim((string)($input['leadId']??''));
    if($leadId==='') app_json_response(['ok'=>false,'message'=>'Select a wholesale lead to convert.'],422);
    try{$account=wholesale_portal_create_from_lead($pdo,$organizationId,$leadId,(int)$user['id']);}
    catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
    app_audit($pdo,$organizationId,(int)$user['id'],'wholesale.portal_account_created','wholesale_account',(string)$account['public_id'],null,['leadId'=>$leadId]);
    app_json_response(['ok'=>true,'message'=>'Wholesale customer account created.','accountId'=>$account['public_id']]);
}

$accountIdPublic=trim((string)($input['accountId']??''));
$account=wholesale_admin_account($pdo,$organizationId,$accountIdPublic);$accountId=(int)$account['id'];

if($action==='invite'){
    $email=trim((string)($input['email']??$account['primary_email']??''));$name=trim((string)($input['contactName']??''));$role=(string)($input['accountRole']??'buyer');
    try{$invite=wholesale_portal_create_invite($pdo,$organizationId,$accountId,$email,$name,$role,(int)$user['id']);}
    catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
    $url=app_url('wholesale-accept.php?token='.rawurlencode($invite['token']));
    $sent=wholesale_portal_send_invite_email($email,$name,(string)$account['business_name'],$url);
    app_audit($pdo,$organizationId,(int)$user['id'],'wholesale.portal_invited','wholesale_account',$accountIdPublic,null,['email'=>$email,'role'=>$role,'emailSent'=>$sent]);
    app_json_response(['ok'=>true,'message'=>$sent?'Invitation emailed.':'Invitation created. Copy the secure link to the buyer.','inviteUrl'=>$url,'emailSent'=>$sent]);
}

if($action==='update_account'){
    $status=(string)($input['accountStatus']??$account['account_status']);if(!in_array($status,['active','on_hold','closed'],true))app_json_response(['ok'=>false,'message'=>'Invalid account status.'],422);
    $packages=array_values(array_filter(array_map('trim',(array)($input['packagePreferences']??[]))));
    $update=$pdo->prepare("UPDATE wholesale_accounts SET account_status=?,primary_email=?,phone=?,website=?,billing_email=?,business_type=?,price_tier=?,payment_terms=?,preferred_fulfillment=?,flavor_preferences=?,package_preferences_json=?,private_label_interest=?,customer_notes=?,internal_notes=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?");
    $update->execute([$status,mb_substr(trim((string)($input['primaryEmail']??'')),0,254,'UTF-8')?:null,mb_substr(trim((string)($input['phone']??'')),0,50,'UTF-8')?:null,mb_substr(trim((string)($input['website']??'')),0,500,'UTF-8')?:null,mb_substr(trim((string)($input['billingEmail']??'')),0,254,'UTF-8')?:null,mb_substr(trim((string)($input['businessType']??'')),0,80,'UTF-8')?:null,mb_substr(trim((string)($input['priceTier']??'')),0,80,'UTF-8')?:null,mb_substr(trim((string)($input['paymentTerms']??'')),0,120,'UTF-8')?:null,mb_substr(trim((string)($input['preferredFulfillment']??'')),0,80,'UTF-8')?:null,mb_substr(trim((string)($input['flavorPreferences']??'')),0,10000,'UTF-8')?:null,json_encode($packages,JSON_THROW_ON_ERROR),(int)!empty($input['privateLabelInterest']),mb_substr(trim((string)($input['customerNotes']??'')),0,10000,'UTF-8')?:null,mb_substr(trim((string)($input['internalNotes']??'')),0,10000,'UTF-8')?:null,(int)$user['id'],$accountId,$organizationId]);
    wholesale_portal_sync_account_knowledge($pdo,$organizationId,$accountId,(int)$user['id']);
    app_audit($pdo,$organizationId,(int)$user['id'],'wholesale.portal_account_updated','wholesale_account',$accountIdPublic);
    app_json_response(['ok'=>true,'message'=>'Wholesale customer account updated.']);
}

if($action==='quote'){
    $items=(array)($input['items']??[]);if(!$items)app_json_response(['ok'=>false,'message'=>'Add at least one quote item.'],422);
    $subtotal=max(0,(float)($input['subtotal']??0));$delivery=max(0,(float)($input['deliveryFee']??0));$tax=max(0,(float)($input['taxTotal']??0));$total=max(0,(float)($input['total']??($subtotal+$delivery+$tax)));
    $valid=trim((string)($input['validUntil']??''));if($valid!==''&&!DateTimeImmutable::createFromFormat('Y-m-d',$valid))app_json_response(['ok'=>false,'message'=>'Quote expiration date is invalid.'],422);
    $publicId=wholesale_portal_public_id('wquote');$number='Q-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));$status=(string)($input['status']??'sent');if(!in_array($status,['draft','sent'],true))$status='sent';
    $insert=$pdo->prepare("INSERT INTO wholesale_quotes (organization_id,wholesale_account_id,public_id,quote_number,status,items_json,subtotal,delivery_fee,tax_total,total,valid_until,customer_message,terms_text,sent_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?='sent',NOW(6),NULL),?,?)");
    $insert->execute([$organizationId,$accountId,$publicId,$number,$status,json_encode($items,JSON_THROW_ON_ERROR),$subtotal,$delivery,$tax,$total,$valid?:null,mb_substr(trim((string)($input['customerMessage']??'')),0,10000,'UTF-8')?:null,mb_substr(trim((string)($input['terms']??'')),0,10000,'UTF-8')?:null,$status,(int)$user['id'],(int)$user['id']]);
    wholesale_portal_sync_account_knowledge($pdo,$organizationId,$accountId,(int)$user['id']);
    app_audit($pdo,$organizationId,(int)$user['id'],'wholesale.quote_created','wholesale_quote',$publicId,null,['accountId'=>$accountIdPublic,'quoteNumber'=>$number,'total'=>$total]);
    app_json_response(['ok'=>true,'message'=>'Wholesale quote created.','quoteNumber'=>$number]);
}

if($action==='order'){
    $items=(array)($input['items']??[]);if(!$items)app_json_response(['ok'=>false,'message'=>'Add at least one order item.'],422);
    $subtotal=max(0,(float)($input['subtotal']??0));$delivery=max(0,(float)($input['deliveryFee']??0));$tax=max(0,(float)($input['taxTotal']??0));$total=max(0,(float)($input['total']??($subtotal+$delivery+$tax)));
    $status=(string)($input['status']??'confirmed');if(!in_array($status,['requested','confirmed','in_production','ready','out_for_delivery','delivered','cancelled'],true))$status='confirmed';
    $publicId=wholesale_portal_public_id('worder');$number='W-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
    $insert=$pdo->prepare("INSERT INTO wholesale_orders (organization_id,wholesale_account_id,public_id,order_number,status,items_json,subtotal,delivery_fee,tax_total,total,fulfillment_type,requested_for,promised_for,customer_notes,internal_notes,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $insert->execute([$organizationId,$accountId,$publicId,$number,$status,json_encode($items,JSON_THROW_ON_ERROR),$subtotal,$delivery,$tax,$total,mb_substr(trim((string)($input['fulfillmentType']??'')),0,40,'UTF-8')?:null,trim((string)($input['requestedFor']??''))?:null,trim((string)($input['promisedFor']??''))?:null,mb_substr(trim((string)($input['customerNotes']??'')),0,10000,'UTF-8')?:null,mb_substr(trim((string)($input['internalNotes']??'')),0,10000,'UTF-8')?:null,(int)$user['id'],(int)$user['id']]);
    wholesale_portal_sync_account_knowledge($pdo,$organizationId,$accountId,(int)$user['id']);
    if(operations_wholesale_ready($pdo))operations_sync_wholesale_tasks($pdo,$organizationId,(int)$user['id']);
    app_audit($pdo,$organizationId,(int)$user['id'],'wholesale.order_created','wholesale_order',$publicId,null,['accountId'=>$accountIdPublic,'orderNumber'=>$number,'total'=>$total]);
    app_json_response(['ok'=>true,'message'=>'Wholesale order created and added to Operations.','orderNumber'=>$number]);
}

if($action==='request_status'){
    $requestId=trim((string)($input['requestId']??''));$status=(string)($input['status']??'reviewing');if(!in_array($status,['new','reviewing','closed'],true))app_json_response(['ok'=>false,'message'=>'Invalid request status.'],422);
    $update=$pdo->prepare("UPDATE wholesale_customer_requests SET status=?,resolved_by=IF(?='closed',?,NULL),resolved_at=IF(?='closed',NOW(6),NULL),updated_at=NOW(6) WHERE organization_id=? AND wholesale_account_id=? AND public_id=?");
    $update->execute([$status,$status,(int)$user['id'],$status,$organizationId,$accountId,$requestId]);
    wholesale_portal_sync_account_knowledge($pdo,$organizationId,$accountId,(int)$user['id']);
    app_json_response(['ok'=>true,'message'=>'Customer request updated.']);
}
app_json_response(['ok'=>false,'message'=>'Unsupported wholesale customer account action.'],422);
