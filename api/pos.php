<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/customer-crm-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/pos-floor-plan.php';
require_once __DIR__.'/../includes/online-order-lifecycle.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];
$membership=(int)$user['membership_id'];
$canUse=app_has_permission('pos.use',$user);
$crmAvailable=crm_ready($pdo);
$kdsAvailable=kds_ready($pdo);
$floorReady=pos_floor_plan_ready($pdo);

if(!$canUse)app_json_response(['ok'=>false,'message'=>'Native POS permission required.'],403);
if(!pos_ready($pdo))app_json_response(['ok'=>false,'message'=>'Native POS migration is not installed. Run upgrade.php.'],503);

function pos_api_locations(PDO $pdo,int $org,array $user): array
{
    return operational_filter_locations($pdo,$user,'pos.use',pos_locations($pdo,$org));
}

function pos_api_location(PDO $pdo,int $org,int $membership,array $user,array $input=[]): int
{
    $id=(int)($input['locationId']??$_GET['locationId']??0);
    if($id>0){
        pos_location($pdo,$org,$id);
        if(!operational_location_allowed($pdo,$user,'pos.use',$id))throw new DomainException('POS access is not assigned at that restaurant location.');
        return $id;
    }
    $primary=pos_primary_location_id($pdo,$org,$membership);
    if($primary&&operational_location_allowed($pdo,$user,'pos.use',$primary)){
        pos_location($pdo,$org,$primary);
        return $primary;
    }
    foreach(pos_api_locations($pdo,$org,$user) as $location)return (int)$location['id'];
    throw new DomainException('No active restaurant location is assigned for POS access.');
}

function pos_api_check_location(PDO $pdo,int $org,string $public,array $user): int
{
    $check=pos_check_base($pdo,$org,$public,false);
    $locationId=(int)$check['location_id'];
    if(!operational_location_allowed($pdo,$user,'pos.use',$locationId))throw new DomainException('POS access is not assigned for this check location.');
    return $locationId;
}

function pos_api_assert_ticket_mutable(PDO $pdo,int $org,string $public): void
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM pos_tenders t JOIN pos_checks c ON c.id=t.check_id AND c.organization_id=t.organization_id WHERE c.organization_id=? AND c.public_id=? AND t.status='captured'");
    $q->execute([$org,$public]);
    if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Ticket items and discounts are locked after the first captured tender. Complete payment or use a future tender-adjustment workflow.');
}

function pos_api_enrich_check(PDO $pdo,int $org,array $check,bool $crmAvailable,bool $kdsAvailable): array
{
    $check['customer']=$crmAvailable?crm_check_customer($pdo,$org,(string)$check['publicId']):null;
    $check['kitchen']=$kdsAvailable?kds_check_summary($pdo,$org,(string)$check['publicId']):['ready'=>false,'sent'=>0,'unsent'=>0,'unrouted'=>0,'items'=>[]];
    return $check;
}

function pos_api_permissions(PDO $pdo,array $user,int $locationId,bool $crmAvailable,bool $kdsAvailable,bool $floorReady): array
{
    $discount=app_has_permission('pos.discount',$user)&&operational_location_allowed($pdo,$user,'pos.discount',$locationId);
    $void=app_has_permission('pos.void',$user)&&operational_location_allowed($pdo,$user,'pos.void',$locationId);
    $manage=app_has_permission('pos.manage',$user)&&operational_location_allowed($pdo,$user,'pos.manage',$locationId);
    $customerLink=$crmAvailable&&app_has_permission('crm.pos_link',$user)&&operational_location_allowed($pdo,$user,'crm.pos_link',$locationId);
    $floorView=$floorReady&&app_has_permission('table_service.view',$user)&&operational_location_allowed($pdo,$user,'table_service.view',$locationId);
    $floorManage=$floorReady&&$manage
        &&app_has_permission('floorplans.view',$user)&&operational_location_allowed($pdo,$user,'floorplans.view',$locationId)
        &&app_has_permission('table_service.manage',$user)&&operational_location_allowed($pdo,$user,'table_service.manage',$locationId);
    $tableUse=table_service_ready($pdo)&&app_has_permission('table_service.use',$user)&&operational_location_allowed($pdo,$user,'table_service.use',$locationId);
    return [
        'discount'=>$discount,
        'void'=>$void,
        'manage'=>$manage,
        'customerLink'=>$customerLink,
        'crmAvailable'=>$crmAvailable,
        'kdsAvailable'=>$kdsAvailable,
        'floorView'=>$floorView,
        'floorManage'=>$floorManage,
        'tableUse'=>$tableUse,
    ];
}

function pos_api_floor(PDO $pdo,int $org,int $locationId,bool $canFloorView): ?array
{
    return $canFloorView?pos_floor_plan_bootstrap($pdo,$org,$locationId):null;
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=pos_api_location($pdo,$org,$membership,$user);
        $permissions=pos_api_permissions($pdo,$user,$locationId,$crmAvailable,$kdsAvailable,$floorReady);
        $public=trim((string)($_GET['check']??''));
        if($public!==''){
            $checkLocation=pos_api_check_location($pdo,$org,$public,$user);
            if($checkLocation!==$locationId)throw new DomainException('That POS check belongs to a different restaurant location.');
            $check=pos_api_enrich_check($pdo,$org,pos_check_details($pdo,$org,$public),$crmAvailable,$kdsAvailable);
        }else $check=null;
        app_json_response([
            'ok'=>true,
            'locationId'=>$locationId,
            'locations'=>pos_api_locations($pdo,$org,$user),
            'settings'=>pos_settings($pdo,$org,$locationId),
            'menu'=>pos_menu($pdo,$org),
            'openChecks'=>pos_open_checks($pdo,$org,$locationId),
            'recentChecks'=>pos_recent_checks($pdo,$org,$locationId),
            'check'=>$check,
            'floorPlan'=>pos_api_floor($pdo,$org,$locationId,$permissions['floorView']),
            'permissions'=>$permissions,
        ]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }

    $input=app_json_input();
    app_verify_request_csrf($input);
    $action=(string)($input['action']??'');
    $public=trim((string)($input['checkPublicId']??''));

    if(in_array($action,['check.create','table.seat','floor.select','floor.sync','settings.save'],true)){
        $locationId=pos_api_location($pdo,$org,$membership,$user,$input);
        $permissions=pos_api_permissions($pdo,$user,$locationId,$crmAvailable,$kdsAvailable,$floorReady);

        if($action==='check.create'){
            $check=operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$input,$uid,$crmAvailable,$kdsAvailable):array{
                $check=pos_api_enrich_check($pdo,$org,pos_create_check($pdo,$org,$locationId,$input,$uid),$crmAvailable,$kdsAvailable);
                app_audit($pdo,$org,$uid,'pos.check_created','pos_check',$check['publicId'],null,['checkNumber'=>$check['checkNumber'],'locationId'=>$locationId,'serviceMode'=>$check['serviceMode'],'guestCount'=>$check['guestCount']]);
                return $check;
            });
            app_json_response(['ok'=>true,'check'=>$check,'openChecks'=>pos_open_checks($pdo,$org,$locationId),'floorPlan'=>pos_api_floor($pdo,$org,$locationId,$permissions['floorView'])],201);
        }

        if($action==='table.seat'){
            if(!$permissions['tableUse'])app_json_response(['ok'=>false,'message'=>'Table-service permission required at this location to seat from the floor plan.'],403);
            $tablePublicId=trim((string)($input['tablePublicId']??''));
            if($tablePublicId==='')throw new InvalidArgumentException('Choose a table from the floor plan.');
            $check=operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$input,$uid,$crmAvailable,$kdsAvailable):array{
                $check=table_service_seat($pdo,$org,$locationId,$tablePublicId,(int)($input['guestCount']??1),null,(string)($input['notes']??''),$uid);
                $check=pos_api_enrich_check($pdo,$org,$check,$crmAvailable,$kdsAvailable);
                app_audit($pdo,$org,$uid,'pos.table_seated','pos_check',(string)$check['publicId'],null,['locationId'=>$locationId,'tablePublicId'=>$tablePublicId,'guestCount'=>(int)($input['guestCount']??1)]);
                return $check;
            });
            app_json_response(['ok'=>true,'check'=>$check,'openChecks'=>pos_open_checks($pdo,$org,$locationId),'floorPlan'=>pos_api_floor($pdo,$org,$locationId,$permissions['floorView'])],201);
        }

        if($action==='floor.select'){
            if(!$permissions['floorManage'])app_json_response(['ok'=>false,'message'=>'POS, Floor Plan, and Table Service management permissions are required at this location.'],403);
            $plan=trim((string)($input['floorPlanPublicId']??''));
            operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$plan,$uid):void{
                pos_floor_plan_select($pdo,$org,$locationId,$plan!==''?$plan:null,$uid);
                app_audit($pdo,$org,$uid,'pos.floor_plan_selected','pos_floor_plan_settings',(string)$locationId,null,['floorPlanPublicId'=>$plan!==''?$plan:null]);
            });
            app_json_response(['ok'=>true,'floorPlan'=>pos_floor_plan_bootstrap($pdo,$org,$locationId)]);
        }

        if($action==='floor.sync'){
            if(!$permissions['floorManage'])app_json_response(['ok'=>false,'message'=>'POS, Floor Plan, and Table Service management permissions are required at this location.'],403);
            $result=operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$uid):array{
                $result=pos_floor_plan_sync_tables($pdo,$org,$locationId,$uid);
                app_audit($pdo,$org,$uid,'pos.floor_plan_synced','floor_plan',(string)$result['floorPlanPublicId'],null,['locationId'=>$locationId,'componentTables'=>$result['componentTables'],'created'=>$result['created'],'updated'=>$result['updated'],'orphaned'=>$result['orphaned']]);
                return $result;
            });
            app_json_response(['ok'=>true,'sync'=>$result,'floorPlan'=>$result['bootstrap']]);
        }

        if(!$permissions['manage'])app_json_response(['ok'=>false,'message'=>'POS management permission required at this location.'],403);
        $settings=operational_db_wrap($pdo,function()use($pdo,$org,$locationId,$input,$uid):array{
            $settings=pos_settings_save($pdo,$org,$locationId,$input,$uid);
            app_audit($pdo,$org,$uid,'pos.settings_updated','pos_settings',(string)$locationId,null,['taxRate'=>$settings['taxRate'],'serviceChargeRate'=>$settings['serviceChargeRate'],'defaultServiceMode'=>$settings['defaultServiceMode'],'makePrimary'=>!empty($input['makePrimary'])]);
            return $settings;
        });
        app_json_response(['ok'=>true,'settings'=>$settings]);
    }

    if($public==='')throw new InvalidArgumentException('Choose an open POS check.');
    $locationId=pos_api_check_location($pdo,$org,$public,$user);
    $permissions=pos_api_permissions($pdo,$user,$locationId,$crmAvailable,$kdsAvailable,$floorReady);

    if($action==='item.add'){
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$public,$input,$uid,$crmAvailable,$kdsAvailable):array{
            pos_api_assert_ticket_mutable($pdo,$org,$public);
            $check=pos_api_enrich_check($pdo,$org,pos_add_item($pdo,$org,$public,(int)($input['priceId']??0),(float)($input['quantity']??1),(string)($input['specialInstructions']??''),$uid),$crmAvailable,$kdsAvailable);
            app_audit($pdo,$org,$uid,'pos.item_added','pos_check',$public,null,['priceId'=>(int)($input['priceId']??0),'quantity'=>(float)($input['quantity']??1)]);
            return $check;
        });
        app_json_response(['ok'=>true,'check'=>$check]);
    }

    if($action==='item.update'){
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$public,$input,$uid,$crmAvailable,$kdsAvailable):array{
            pos_api_assert_ticket_mutable($pdo,$org,$public);
            $itemId=(int)($input['itemId']??0);
            if($kdsAvailable)kds_assert_pos_line_mutable($pdo,$org,$itemId);
            $check=pos_api_enrich_check($pdo,$org,pos_update_item($pdo,$org,$public,$itemId,(float)($input['quantity']??1),(string)($input['specialInstructions']??'')),$crmAvailable,$kdsAvailable);
            app_audit($pdo,$org,$uid,'pos.item_updated','pos_check',$public,null,['itemId'=>$itemId,'quantity'=>(float)($input['quantity']??1)]);
            return $check;
        });
        app_json_response(['ok'=>true,'check'=>$check]);
    }

    if($action==='item.void'){
        if(!$permissions['void'])app_json_response(['ok'=>false,'message'=>'POS void permission required at this location.'],403);
        $reason=(string)($input['reason']??'');
        $itemId=(int)($input['itemId']??0);
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$public,$reason,$itemId,$uid,$crmAvailable,$kdsAvailable):array{
            pos_api_assert_ticket_mutable($pdo,$org,$public);
            $check=pos_void_item($pdo,$org,$public,$itemId,$reason,$uid);
            if($kdsAvailable)kds_cancel_pos_line($pdo,$org,$itemId,$reason,$uid);
            online_order_lifecycle_sync_safe($pdo,$org,$public,$uid);
            $check=pos_api_enrich_check($pdo,$org,$check,$crmAvailable,$kdsAvailable);
            app_audit($pdo,$org,$uid,'pos.item_voided','pos_check',$public,null,['itemId'=>$itemId,'reason'=>mb_substr(trim($reason),0,500,'UTF-8')]);
            return $check;
        });
        app_json_response(['ok'=>true,'check'=>$check]);
    }

    if($action==='discount.set'){
        if(!$permissions['discount'])app_json_response(['ok'=>false,'message'=>'POS discount permission required at this location.'],403);
        $reason=(string)($input['reason']??'');
        $amount=(float)($input['amount']??0);
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$public,$reason,$amount,$uid,$crmAvailable,$kdsAvailable):array{
            pos_api_assert_ticket_mutable($pdo,$org,$public);
            $check=pos_api_enrich_check($pdo,$org,pos_apply_discount($pdo,$org,$public,$amount,$reason),$crmAvailable,$kdsAvailable);
            app_audit($pdo,$org,$uid,'pos.discount_updated','pos_check',$public,null,['amount'=>pos_money($amount),'reason'=>mb_substr(trim($reason),0,500,'UTF-8')]);
            return $check;
        });
        app_json_response(['ok'=>true,'check'=>$check]);
    }

    if($action==='kitchen.send'){
        if(!$kdsAvailable)app_json_response(['ok'=>false,'message'=>'Kitchen Display migration is not installed. Run upgrade.php.'],503);
        $result=operational_db_wrap($pdo,function()use($pdo,$org,$public,$input,$uid,$crmAvailable,$kdsAvailable):array{
            pos_api_assert_ticket_mutable($pdo,$org,$public);
            $summary=kds_send_check($pdo,$org,$public,$uid,!empty($input['hold']));
            online_order_lifecycle_sync_safe($pdo,$org,$public,$uid);
            $check=pos_api_enrich_check($pdo,$org,pos_check_details($pdo,$org,$public),$crmAvailable,$kdsAvailable);
            app_audit($pdo,$org,$uid,'pos.kitchen_sent','pos_check',$public,null,['sent'=>$summary['sent'],'unsent'=>$summary['unsent'],'unrouted'=>$summary['unrouted'],'held'=>!empty($input['hold'])]);
            return ['check'=>$check,'kitchen'=>$summary];
        });
        app_json_response(['ok'=>true]+$result);
    }

    if($action==='tender.record'){
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$public,$input,$uid,$crmAvailable,$kdsAvailable):array{
            $check=pos_api_enrich_check($pdo,$org,pos_record_tender($pdo,$org,$public,$input,$uid),$crmAvailable,$kdsAvailable);
            if($check['status']!=='open'&&table_service_ready($pdo))table_service_release_closed_check($pdo,$org,$public,$uid);
            online_order_lifecycle_sync_safe($pdo,$org,$public,$uid);
            app_audit($pdo,$org,$uid,'pos.tender_recorded','pos_check',$public,null,['tenderType'=>(string)($input['tenderType']??''),'amount'=>pos_money((float)($input['amount']??0)),'tipAmount'=>pos_money((float)($input['tipAmount']??0)),'checkStatus'=>$check['status']]);
            return $check;
        });
        $floor=pos_api_floor($pdo,$org,(int)$check['locationId'],$permissions['floorView']);
        app_json_response(['ok'=>true,'check'=>$check,'openChecks'=>pos_open_checks($pdo,$org,(int)$check['locationId']),'recentChecks'=>pos_recent_checks($pdo,$org,(int)$check['locationId']),'floorPlan'=>$floor],201);
    }

    if($action==='check.cancel'){
        if(!$permissions['void'])app_json_response(['ok'=>false,'message'=>'POS void permission required at this location.'],403);
        $reason=(string)($input['reason']??'');
        $check=operational_db_wrap($pdo,function()use($pdo,$org,$public,$reason,$uid,$crmAvailable,$kdsAvailable):array{
            $base=pos_check_base($pdo,$org,$public,false);
            $check=pos_cancel_check($pdo,$org,$public,$reason,$uid);
            if($kdsAvailable)kds_cancel_check($pdo,$org,(int)$base['id'],$reason,$uid);
            if(table_service_ready($pdo))table_service_release_closed_check($pdo,$org,$public,$uid);
            online_order_lifecycle_sync_safe($pdo,$org,$public,$uid);
            $check=pos_api_enrich_check($pdo,$org,$check,$crmAvailable,$kdsAvailable);
            app_audit($pdo,$org,$uid,'pos.check_cancelled','pos_check',$public,null,['reason'=>mb_substr(trim($reason),0,500,'UTF-8')]);
            return $check;
        });
        app_json_response(['ok'=>true,'check'=>$check,'openChecks'=>pos_open_checks($pdo,$org,(int)$check['locationId']),'floorPlan'=>pos_api_floor($pdo,$org,(int)$check['locationId'],$permissions['floorView'])]);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported POS action.'],422);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'POS could not complete the request.')],500);
}