<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/pos-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];$membership=(int)$user['membership_id'];
$canView=app_has_permission('kds.view',$user);$canUpdate=app_has_permission('kds.update',$user);$canConfigure=app_has_permission('kds.configure',$user);
if(!$canView)app_json_response(['ok'=>false,'message'=>'Kitchen Display permission required.'],403);
if(!kds_ready($pdo))app_json_response(['ok'=>false,'message'=>'Kitchen Display migration is not installed. Run upgrade.php.'],503);

function kds_api_location(PDO $pdo,int $org,int $membership,array $input=[]): int
{
    $id=(int)($input['locationId']??$_GET['locationId']??0);if($id>0){kds_location($pdo,$org,$id);return $id;}$q=$pdo->prepare('SELECT primary_location_id FROM organization_memberships WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,$membership]);$id=(int)($q->fetchColumn()?:0);if($id>0){kds_location($pdo,$org,$id);return $id;}$q=$pdo->prepare("SELECT id FROM locations WHERE organization_id=? AND status='active' ORDER BY id LIMIT 1");$q->execute([$org]);$id=(int)($q->fetchColumn()?:0);if(!$id)throw new InvalidArgumentException('Create an active restaurant location before using Kitchen Display.');return $id;
}
function kds_api_locations(PDO $pdo,int $org): array {$q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY name,id");$q->execute([$org]);return $q->fetchAll();}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=kds_api_location($pdo,$org,$membership);$station=trim((string)($_GET['station']??''));$includeCompleted=!empty($_GET['completed']);$board=kds_board($pdo,$org,$locationId,$station!==''?$station:null,$includeCompleted);
        app_json_response(['ok'=>true,'locationId'=>$locationId,'locations'=>kds_api_locations($pdo,$org),'board'=>$board,'catalog'=>$canConfigure?kds_menu_catalog($pdo,$org,$locationId):[],'permissions'=>['view'=>$canView,'update'=>$canUpdate,'configure'=>$canConfigure]]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    $in=app_json_input();app_verify_request_csrf($in);$action=(string)($in['action']??'');$locationId=kds_api_location($pdo,$org,$membership,$in);
    if($action==='item.transition'){
        if(!$canUpdate)app_json_response(['ok'=>false,'message'=>'Kitchen Display update permission required.'],403);$public=trim((string)($in['itemPublicId']??''));if($public==='')throw new InvalidArgumentException('Choose a kitchen item.');$to=(string)($in['status']??'');$item=kds_transition($pdo,$org,$public,$to,$uid,(string)($in['note']??''));app_audit($pdo,$org,$uid,'kds.item_status_changed','kds_order_item',$public,null,['toStatus'=>$to,'locationId'=>$locationId]);app_json_response(['ok'=>true,'item'=>$item,'board'=>kds_board($pdo,$org,$locationId,isset($in['station'])?(string)$in['station']:null)]);
    }
    if($action==='item.reassign'){
        if(!$canUpdate)app_json_response(['ok'=>false,'message'=>'Kitchen Display update permission required.'],403);$public=trim((string)($in['itemPublicId']??''));if($public==='')throw new InvalidArgumentException('Choose a kitchen item.');$station=isset($in['stationPublicId'])?trim((string)$in['stationPublicId']):null;$item=kds_reassign($pdo,$org,$public,$station,$uid);app_audit($pdo,$org,$uid,'kds.item_reassigned','kds_order_item',$public,null,['stationPublicId'=>$station,'locationId'=>$locationId]);app_json_response(['ok'=>true,'item'=>$item,'board'=>kds_board($pdo,$org,$locationId,isset($in['station'])?(string)$in['station']:null)]);
    }
    if($action==='station.save'){
        if(!$canConfigure)app_json_response(['ok'=>false,'message'=>'Kitchen Display configuration permission required.'],403);$station=kds_station_save($pdo,$org,$locationId,$in,$uid);app_audit($pdo,$org,$uid,'kds.station_saved','kds_station',(string)$station['public_id'],null,['locationId'=>$locationId,'name'=>$station['name'],'status'=>$station['status']]);app_json_response(['ok'=>true,'station'=>$station,'stations'=>kds_stations($pdo,$org,$locationId,false),'catalog'=>kds_menu_catalog($pdo,$org,$locationId)]);
    }
    if($action==='route.save'){
        if(!$canConfigure)app_json_response(['ok'=>false,'message'=>'Kitchen Display configuration permission required.'],403);$menuItem=(int)($in['menuItemId']??0);if($menuItem<=0)throw new InvalidArgumentException('Choose a menu item.');$station=isset($in['stationPublicId'])?trim((string)$in['stationPublicId']):null;$route=kds_route_save($pdo,$org,$locationId,$menuItem,$station,$uid);app_audit($pdo,$org,$uid,'kds.menu_route_saved','menu_item',(string)$menuItem,null,['locationId'=>$locationId,'stationPublicId'=>$station]);app_json_response(['ok'=>true,'route'=>$route,'catalog'=>kds_menu_catalog($pdo,$org,$locationId)]);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported Kitchen Display action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],500);}
