<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/kds-production.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/online-order-lifecycle.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];
$membership=(int)$user['membership_id'];
$canView=app_has_permission('kds.view',$user);
$canUpdate=app_has_permission('kds.update',$user);
$canConfigure=app_has_permission('kds.configure',$user);

if(!$canView)app_json_response(['ok'=>false,'message'=>'Kitchen Display permission required.'],403);
if(!kds_ready($pdo))app_json_response(['ok'=>false,'message'=>'Kitchen Display migration is not installed. Run upgrade.php.'],503);

function kds_api_location(PDO $pdo,int $org,int $membership,array $user,array $input=[]): int
{
    $id=(int)($input['locationId']??$_GET['locationId']??0);
    if($id>0){
        kds_location($pdo,$org,$id);
        if(!operational_location_allowed($pdo,$user,'kds.view',$id))throw new DomainException('Kitchen Display access is not assigned at that restaurant location.');
        return $id;
    }
    $q=$pdo->prepare('SELECT primary_location_id FROM organization_memberships WHERE organization_id=? AND id=? LIMIT 1');
    $q->execute([$org,$membership]);
    $id=(int)($q->fetchColumn()?:0);
    if($id>0&&operational_location_allowed($pdo,$user,'kds.view',$id)){
        kds_location($pdo,$org,$id);
        return $id;
    }
    foreach(kds_api_locations($pdo,$org,$user) as $location)return (int)$location['id'];
    throw new DomainException('No active restaurant location is assigned for Kitchen Display access.');
}

function kds_api_locations(PDO $pdo,int $org,array $user): array
{
    $q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY name,id");
    $q->execute([$org]);
    return operational_filter_locations($pdo,$user,'kds.view',$q->fetchAll());
}

function kds_api_station(array $input): ?string
{
    $station=trim((string)($input['station']??''));
    return $station!==''?$station:null;
}

function kds_api_board(PDO $pdo,int $org,int $locationId,array $input=[]): array
{
    $station=kds_api_station($input);
    $completed=!empty($input['completed']);
    return kds_production_board($pdo,$org,$locationId,$station,$completed);
}

function kds_api_assert_item_location(PDO $pdo,int $org,string $public,int $locationId): void
{
    $item=kds_item($pdo,$org,$public,false);
    if((int)$item['location_id']!==$locationId)throw new DomainException('That kitchen item belongs to a different restaurant location.');
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=kds_api_location($pdo,$org,$membership,$user);
        $updateAtLocation=$canUpdate&&operational_location_allowed($pdo,$user,'kds.update',$locationId);
        $configureAtLocation=$canConfigure&&operational_location_allowed($pdo,$user,'kds.configure',$locationId);
        $board=kds_production_board(
            $pdo,
            $org,
            $locationId,
            trim((string)($_GET['station']??''))!==''?trim((string)$_GET['station']):null,
            !empty($_GET['completed'])
        );
        app_json_response([
            'ok'=>true,
            'locationId'=>$locationId,
            'locations'=>kds_api_locations($pdo,$org,$user),
            'board'=>$board,
            'catalog'=>$configureAtLocation?kds_menu_catalog($pdo,$org,$locationId):[],
            'permissions'=>[
                'view'=>true,
                'update'=>$updateAtLocation,
                'configure'=>$configureAtLocation,
                'recallWindowSeconds'=>kds_production_recall_window_seconds(),
            ],
        ]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }

    $in=app_json_input();
    app_verify_request_csrf($in);
    $action=(string)($in['action']??'');
    $locationId=kds_api_location($pdo,$org,$membership,$user,$in);
    $updateAtLocation=$canUpdate&&operational_location_allowed($pdo,$user,'kds.update',$locationId);
    $configureAtLocation=$canConfigure&&operational_location_allowed($pdo,$user,'kds.configure',$locationId);

    if($action==='item.transition'){
        if(!$updateAtLocation)app_json_response(['ok'=>false,'message'=>'Kitchen Display update permission required at this location.'],403);
        $public=trim((string)($in['itemPublicId']??''));
        if($public==='')throw new InvalidArgumentException('Choose a kitchen item.');
        kds_api_assert_item_location($pdo,$org,$public,$locationId);
        $to=(string)($in['status']??'');
        $item=kds_transition($pdo,$org,$public,$to,$uid,(string)($in['note']??''));
        online_order_lifecycle_sync_by_check_id_safe($pdo,$org,(int)($item['check_id']??0),$uid);
        app_audit($pdo,$org,$uid,'kds.item_status_changed','kds_order_item',$public,null,['toStatus'=>$to,'locationId'=>$locationId]);
        app_json_response(['ok'=>true,'item'=>$item,'board'=>kds_api_board($pdo,$org,$locationId,$in)]);
    }

    if($action==='item.recall'){
        if(!$updateAtLocation)app_json_response(['ok'=>false,'message'=>'Kitchen Display update permission required at this location.'],403);
        $public=trim((string)($in['itemPublicId']??''));
        if($public==='')throw new InvalidArgumentException('Choose a kitchen item.');
        kds_api_assert_item_location($pdo,$org,$public,$locationId);
        $item=kds_production_recall_item($pdo,$org,$public,$uid);
        online_order_lifecycle_sync_by_check_id_safe($pdo,$org,(int)($item['check_id']??0),$uid);
        app_audit($pdo,$org,$uid,'kds.item_recalled','kds_order_item',$public,null,['locationId'=>$locationId]);
        app_json_response(['ok'=>true,'item'=>$item,'board'=>kds_api_board($pdo,$org,$locationId,$in)]);
    }

    if($action==='ticket.action'){
        if(!$updateAtLocation)app_json_response(['ok'=>false,'message'=>'Kitchen Display update permission required at this location.'],403);
        $checkPublicId=trim((string)($in['checkPublicId']??''));
        if($checkPublicId==='')throw new InvalidArgumentException('Choose a kitchen ticket.');
        $ticketAction=trim((string)($in['ticketAction']??''));
        $result=kds_production_ticket_action(
            $pdo,
            $org,
            $locationId,
            $checkPublicId,
            $ticketAction,
            kds_api_station($in),
            $uid
        );
        online_order_lifecycle_sync_safe($pdo,$org,$checkPublicId,$uid);
        app_audit($pdo,$org,$uid,'kds.ticket_action','pos_check',$checkPublicId,null,[
            'locationId'=>$locationId,
            'ticketAction'=>$ticketAction,
            'station'=>kds_api_station($in),
            'affected'=>(int)($result['affected']??0),
        ]);
        app_json_response(['ok'=>true,'result'=>$result,'board'=>kds_api_board($pdo,$org,$locationId,$in)]);
    }

    if($action==='item.reassign'){
        if(!$updateAtLocation)app_json_response(['ok'=>false,'message'=>'Kitchen Display update permission required at this location.'],403);
        $public=trim((string)($in['itemPublicId']??''));
        if($public==='')throw new InvalidArgumentException('Choose a kitchen item.');
        kds_api_assert_item_location($pdo,$org,$public,$locationId);
        $station=isset($in['stationPublicId'])?trim((string)$in['stationPublicId']):null;
        $item=kds_reassign($pdo,$org,$public,$station,$uid);
        app_audit($pdo,$org,$uid,'kds.item_reassigned','kds_order_item',$public,null,['stationPublicId'=>$station,'locationId'=>$locationId]);
        app_json_response(['ok'=>true,'item'=>$item,'board'=>kds_api_board($pdo,$org,$locationId,$in)]);
    }

    if($action==='station.save'){
        if(!$configureAtLocation)app_json_response(['ok'=>false,'message'=>'Kitchen Display configuration permission required at this location.'],403);
        $station=kds_station_save($pdo,$org,$locationId,$in,$uid);
        app_audit($pdo,$org,$uid,'kds.station_saved','kds_station',(string)$station['public_id'],null,['locationId'=>$locationId,'name'=>$station['name'],'status'=>$station['status']]);
        app_json_response([
            'ok'=>true,
            'station'=>$station,
            'stations'=>kds_stations($pdo,$org,$locationId,false),
            'catalog'=>kds_menu_catalog($pdo,$org,$locationId),
            'board'=>kds_api_board($pdo,$org,$locationId,$in),
        ]);
    }

    if($action==='route.save'){
        if(!$configureAtLocation)app_json_response(['ok'=>false,'message'=>'Kitchen Display configuration permission required at this location.'],403);
        $menuItem=(int)($in['menuItemId']??0);
        if($menuItem<=0)throw new InvalidArgumentException('Choose a menu item.');
        $station=isset($in['stationPublicId'])?trim((string)$in['stationPublicId']):null;
        $route=kds_route_save($pdo,$org,$locationId,$menuItem,$station,$uid);
        app_audit($pdo,$org,$uid,'kds.menu_route_saved','menu_item',(string)$menuItem,null,['locationId'=>$locationId,'stationPublicId'=>$station]);
        app_json_response([
            'ok'=>true,
            'route'=>$route,
            'catalog'=>kds_menu_catalog($pdo,$org,$locationId),
            'board'=>kds_api_board($pdo,$org,$locationId,$in),
        ]);
    }

    app_json_response(['ok'=>false,'message'=>'Unsupported Kitchen Display action.'],422);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'Kitchen Display could not complete the request.')],500);
}