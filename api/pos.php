<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/customer-crm-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];$membership=(int)$user['membership_id'];
$canUse=app_has_permission('pos.use',$user);$canDiscount=app_has_permission('pos.discount',$user);$canVoid=app_has_permission('pos.void',$user);$canManage=app_has_permission('pos.manage',$user);
$crmAvailable=crm_ready($pdo);$canCustomerLink=$crmAvailable&&app_has_permission('crm.pos_link',$user);
if(!$canUse)app_json_response(['ok'=>false,'message'=>'Native POS permission required.'],403);
if(!pos_ready($pdo))app_json_response(['ok'=>false,'message'=>'Native POS migration is not installed. Run upgrade.php.'],503);

function pos_api_location(PDO $pdo,int $org,int $membership,array $input=[]): int
{
    $id=(int)($input['locationId']??$_GET['locationId']??0);if($id>0){pos_location($pdo,$org,$id);return $id;}$primary=pos_primary_location_id($pdo,$org,$membership);if($primary)return $primary;$locations=pos_locations($pdo,$org);if(!$locations)throw new InvalidArgumentException('Create an active restaurant location before using POS.');return (int)$locations[0]['id'];
}
function pos_api_assert_ticket_mutable(PDO $pdo,int $org,string $public): void
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM pos_tenders t JOIN pos_checks c ON c.id=t.check_id AND c.organization_id=t.organization_id WHERE c.organization_id=? AND c.public_id=? AND t.status='captured'");$q->execute([$org,$public]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Ticket items and discounts are locked after the first captured tender. Complete payment or use a future tender-adjustment workflow.');
}
function pos_api_enrich_check(PDO $pdo,int $org,array $check,bool $crmAvailable): array
{
    $check['customer']=$crmAvailable?crm_check_customer($pdo,$org,(string)$check['publicId']):null;
    return $check;
}
function pos_api_permissions(bool $discount,bool $void,bool $manage,bool $customerLink,bool $crmAvailable): array
{
    return ['discount'=>$discount,'void'=>$void,'manage'=>$manage,'customerLink'=>$customerLink,'crmAvailable'=>$crmAvailable];
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=pos_api_location($pdo,$org,$membership);$public=trim((string)($_GET['check']??''));
        $check=$public!==''?pos_api_enrich_check($pdo,$org,pos_check_details($pdo,$org,$public),$crmAvailable):null;
        app_json_response(['ok'=>true,'locationId'=>$locationId,'locations'=>pos_locations($pdo,$org),'settings'=>pos_settings($pdo,$org,$locationId),'menu'=>pos_menu($pdo,$org),'openChecks'=>pos_open_checks($pdo,$org,$locationId),'recentChecks'=>pos_recent_checks($pdo,$org,$locationId),'check'=>$check,'permissions'=>pos_api_permissions($canDiscount,$canVoid,$canManage,$canCustomerLink,$crmAvailable)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    $input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');$public=trim((string)($input['checkPublicId']??''));
    if($action==='check.create'){
        $locationId=pos_api_location($pdo,$org,$membership,$input);$check=pos_api_enrich_check($pdo,$org,pos_create_check($pdo,$org,$locationId,$input,$uid),$crmAvailable);app_audit($pdo,$org,$uid,'pos.check_created','pos_check',$check['publicId'],null,['checkNumber'=>$check['checkNumber'],'locationId'=>$locationId,'serviceMode'=>$check['serviceMode'],'guestCount'=>$check['guestCount']]);app_json_response(['ok'=>true,'check'=>$check,'openChecks'=>pos_open_checks($pdo,$org,$locationId)],201);
    }
    if($action==='settings.save'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'POS management permission required.'],403);$locationId=pos_api_location($pdo,$org,$membership,$input);$settings=pos_settings_save($pdo,$org,$locationId,$input,$uid);app_audit($pdo,$org,$uid,'pos.settings_updated','pos_settings',(string)$locationId,null,['taxRate'=>$settings['taxRate'],'serviceChargeRate'=>$settings['serviceChargeRate'],'defaultServiceMode'=>$settings['defaultServiceMode'],'makePrimary'=>!empty($input['makePrimary'])]);app_json_response(['ok'=>true,'settings'=>$settings]);
    }
    if($public==='')throw new InvalidArgumentException('Choose an open POS check.');
    if($action==='item.add'){
        pos_api_assert_ticket_mutable($pdo,$org,$public);$check=pos_api_enrich_check($pdo,$org,pos_add_item($pdo,$org,$public,(int)($input['priceId']??0),(float)($input['quantity']??1),(string)($input['specialInstructions']??''),$uid),$crmAvailable);app_audit($pdo,$org,$uid,'pos.item_added','pos_check',$public,null,['priceId'=>(int)($input['priceId']??0),'quantity'=>(float)($input['quantity']??1)]);app_json_response(['ok'=>true,'check'=>$check]);
    }
    if($action==='item.update'){
        pos_api_assert_ticket_mutable($pdo,$org,$public);$check=pos_api_enrich_check($pdo,$org,pos_update_item($pdo,$org,$public,(int)($input['itemId']??0),(float)($input['quantity']??1),(string)($input['specialInstructions']??'')),$crmAvailable);app_audit($pdo,$org,$uid,'pos.item_updated','pos_check',$public,null,['itemId'=>(int)($input['itemId']??0),'quantity'=>(float)($input['quantity']??1)]);app_json_response(['ok'=>true,'check'=>$check]);
    }
    if($action==='item.void'){
        if(!$canVoid)app_json_response(['ok'=>false,'message'=>'POS void permission required.'],403);pos_api_assert_ticket_mutable($pdo,$org,$public);$reason=(string)($input['reason']??'');$check=pos_api_enrich_check($pdo,$org,pos_void_item($pdo,$org,$public,(int)($input['itemId']??0),$reason,$uid),$crmAvailable);app_audit($pdo,$org,$uid,'pos.item_voided','pos_check',$public,null,['itemId'=>(int)($input['itemId']??0),'reason'=>mb_substr(trim($reason),0,500,'UTF-8')]);app_json_response(['ok'=>true,'check'=>$check]);
    }
    if($action==='discount.set'){
        if(!$canDiscount)app_json_response(['ok'=>false,'message'=>'POS discount permission required.'],403);pos_api_assert_ticket_mutable($pdo,$org,$public);$reason=(string)($input['reason']??'');$amount=(float)($input['amount']??0);$check=pos_api_enrich_check($pdo,$org,pos_apply_discount($pdo,$org,$public,$amount,$reason),$crmAvailable);app_audit($pdo,$org,$uid,'pos.discount_updated','pos_check',$public,null,['amount'=>pos_money($amount),'reason'=>mb_substr(trim($reason),0,500,'UTF-8')]);app_json_response(['ok'=>true,'check'=>$check]);
    }
    if($action==='tender.record'){
        $check=pos_api_enrich_check($pdo,$org,pos_record_tender($pdo,$org,$public,$input,$uid),$crmAvailable);app_audit($pdo,$org,$uid,'pos.tender_recorded','pos_check',$public,null,['tenderType'=>(string)($input['tenderType']??''),'amount'=>pos_money((float)($input['amount']??0)),'tipAmount'=>pos_money((float)($input['tipAmount']??0)),'checkStatus'=>$check['status']]);app_json_response(['ok'=>true,'check'=>$check,'openChecks'=>pos_open_checks($pdo,$org,(int)$check['locationId']),'recentChecks'=>pos_recent_checks($pdo,$org,(int)$check['locationId'])],201);
    }
    if($action==='check.cancel'){
        if(!$canVoid)app_json_response(['ok'=>false,'message'=>'POS void permission required.'],403);$reason=(string)($input['reason']??'');$check=pos_api_enrich_check($pdo,$org,pos_cancel_check($pdo,$org,$public,$reason,$uid),$crmAvailable);app_audit($pdo,$org,$uid,'pos.check_cancelled','pos_check',$public,null,['reason'=>mb_substr(trim($reason),0,500,'UTF-8')]);app_json_response(['ok'=>true,'check'=>$check,'openChecks'=>pos_open_checks($pdo,$org,(int)$check['locationId'])]);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported POS action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],500);}
