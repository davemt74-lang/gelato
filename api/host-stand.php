<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/host-stand-core.php';
require_once __DIR__.'/../includes/table-service-reconcile.php';
require_once __DIR__.'/../includes/service-ops-hardening.php';
require_once __DIR__.'/../includes/service-ops-atomic.php';
require_once __DIR__.'/../includes/service-ops-floor.php';
require_once __DIR__.'/../includes/service-ops-reservation.php';
require_once __DIR__.'/../includes/service-visit-core.php';
require_once __DIR__.'/../includes/service-visit-extensions.php';
require_once __DIR__.'/../includes/service-visit-live.php';
require_once __DIR__.'/../includes/service-seatability.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];$membership=(int)$user['membership_id'];
$rawHostView=app_has_permission('host.view',$user);$canUse=app_has_permission('host.use',$user);$canManage=app_has_permission('host.manage',$user);
$canView=$rawHostView&&(app_has_permission('table_service.view',$user)||$canUse||$canManage);
if(!$canView)app_json_response(['ok'=>false,'message'=>'Host Stand permission required.'],403);
if(!host_ready($pdo)||!service_visit_ready($pdo))app_json_response(['ok'=>false,'message'=>'Host Stand dining-visit migration is not installed. Run upgrade.php.'],503);

function host_api_location(PDO $pdo,int $org,int $membership,array $input=[]): int
{
    $id=(int)($input['locationId']??$_GET['locationId']??0);if($id>0){table_service_location($pdo,$org,$id);return $id;}$primary=pos_primary_location_id($pdo,$org,$membership);if($primary)return $primary;$locations=pos_locations($pdo,$org);if(!$locations)throw new InvalidArgumentException('Create an active restaurant location first.');return (int)$locations[0]['id'];
}
function host_api_date(PDO $pdo,int $org,int $locationId,string $value): string
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,service_ops_timezone($pdo,$org,$locationId));return $d&&$d->format('Y-m-d')===$value?$value:service_ops_business_date($pdo,$org,$locationId);
}
function host_api_reconcile(PDO $pdo,int $org,int $locationId,int $uid): void
{
    service_visit_reconcile_live($pdo,$org,$locationId,$uid);
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=host_api_location($pdo,$org,$membership);$date=host_api_date($pdo,$org,$locationId,(string)($_GET['date']??service_ops_business_date($pdo,$org,$locationId)));host_api_reconcile($pdo,$org,$locationId,$uid);$payload=['ok'=>true,'locations'=>pos_locations($pdo,$org),'date'=>$date,'permissions'=>['use'=>$canUse,'manage'=>$canManage],'dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)];
        $at=trim((string)($_GET['availabilityAt']??''));if($at!=='')$payload['availability']=service_seatability_availability($pdo,$org,$locationId,$at,(int)($_GET['partySize']??2),(int)($_GET['durationMinutes']??90));
        if((app_has_permission('crm.view',$user)||app_has_permission('crm.pos_link',$user))&&trim((string)($_GET['customerQuery']??''))!=='')$payload['customers']=crm_search($pdo,$org,(string)$_GET['customerQuery'],20,true);
        app_json_response($payload);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    $input=app_json_input();app_verify_request_csrf($input);$action=trim((string)($input['action']??''));$locationId=host_api_location($pdo,$org,$membership,$input);$date=host_api_date($pdo,$org,$locationId,(string)($input['date']??service_ops_business_date($pdo,$org,$locationId)));host_api_reconcile($pdo,$org,$locationId,$uid);
    if(in_array($action,['asset.sync_all','table.create','asset.update','table.place','combination.save'],true)){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Host Stand management permission required.'],403);
        if($action==='asset.sync_all'){$count=service_ops_sync_all_table_assets($pdo,$org,$locationId,$uid);app_audit($pdo,$org,$uid,'host.table_assets_synced','location',(string)$locationId,null,['created'=>$count]);app_json_response(['ok'=>true,'created'=>$count,'dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)]);}
        if($action==='table.create'){$table=service_ops_managed_table_create_safe($pdo,$org,$locationId,$input,$uid);app_audit($pdo,$org,$uid,'host.managed_table_created','service_table',(string)$table['publicId'],null,['assetId'=>$table['asset']['id'],'capacity'=>$table['capacity']]);app_json_response(['ok'=>true,'table'=>$table,'dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)],201);}
        $tablePublic=trim((string)($input['tablePublicId']??''));
        if($action==='asset.update'){$table=host_update_table_asset($pdo,$org,$locationId,$tablePublic,$input,$uid);app_audit($pdo,$org,$uid,'host.table_asset_updated','service_table',$tablePublic,null,['operationalStatus'=>$table['asset']['operationalStatus'],'conditionStatus'=>$table['asset']['conditionStatus']]);app_json_response(['ok'=>true,'table'=>$table,'dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)]);}
        if($action==='table.place'){$table=service_ops_place_table($pdo,$org,$locationId,$tablePublic,$input,$uid);app_audit($pdo,$org,$uid,'host.table_placed','service_table',$tablePublic,null,['floorPlanId'=>$table['asset']['floorPlanId'],'xFt'=>$table['asset']['xFt'],'yFt'=>$table['asset']['yFt']]);app_json_response(['ok'=>true,'table'=>$table,'dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)]);}
        if($action==='combination.save'){$combo=service_ops_combination_save($pdo,$org,$locationId,$input,$uid);app_audit($pdo,$org,$uid,'host.table_combination_saved','table_combination',(string)$combo['publicId'],null,['tables'=>array_column($combo['tables'],'publicId'),'capacity'=>$combo['capacity']]);app_json_response(['ok'=>true,'combination'=>$combo,'dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)]);}
    }
    if(!$canUse)app_json_response(['ok'=>false,'message'=>'Host Stand operating permission required.'],403);
    if($action==='reservation.create'){$reservation=service_seatability_reservation_create($pdo,$org,$locationId,$input,$uid);app_audit($pdo,$org,$uid,'host.reservation_created','guest_reservation',(string)$reservation['publicId'],null,['type'=>$reservation['type'],'partySize'=>$reservation['partySize'],'scheduledAt'=>$reservation['scheduledAt']]);app_json_response(['ok'=>true,'reservation'=>$reservation,'dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)],201);}
    $public=trim((string)($input['reservationPublicId']??''));if($public==='')throw new InvalidArgumentException('Choose a reservation or waitlist entry.');
    if($action==='reservation.update'){$reservation=service_ops_reservation_update($pdo,$org,$public,$input,$uid);app_audit($pdo,$org,$uid,'host.reservation_updated','guest_reservation',$public,null,['partySize'=>$reservation['partySize'],'scheduledAt'=>$reservation['scheduledAt']]);app_json_response(['ok'=>true,'reservation'=>$reservation,'dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)]);}
    if($action==='reservation.status'){$reservation=service_ops_reservation_status_atomic($pdo,$org,$public,(string)($input['status']??''),$uid);app_audit($pdo,$org,$uid,'host.reservation_status','guest_reservation',$public,null,['status'=>$reservation['status']]);app_json_response(['ok'=>true,'reservation'=>$reservation,'dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)]);}
    if($action==='reservation.assign'){$reservation=service_seatability_reservation_assign($pdo,$org,$public,(array)($input['tablePublicIds']??[]),$uid);app_audit($pdo,$org,$uid,'host.reservation_tables','guest_reservation',$public,null,['tables'=>array_column($reservation['tables'],'publicId')]);app_json_response(['ok'=>true,'reservation'=>$reservation,'dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)]);}
    if($action==='reservation.seat'){$result=service_seatability_host_seat($pdo,$org,$public,isset($input['serverUserId'])&&$input['serverUserId']!==''?(int)$input['serverUserId']:null,$uid);app_audit($pdo,$org,$uid,'host.reservation_seated','guest_reservation',$public,null,['checkPublicId'=>$result['check']['publicId'],'tables'=>array_column($result['reservation']['tables'],'publicId')]);app_json_response(['ok'=>true]+$result+['dashboard'=>service_seatability_dashboard($pdo,$org,$locationId,$date,$uid)]);}
    throw new InvalidArgumentException('Unsupported Host Stand action.');
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],500);}
