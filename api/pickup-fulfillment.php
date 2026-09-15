<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/pickup-fulfillment-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];

if(!pickup_fulfillment_can_view($user)) app_json_response(['ok'=>false,'message'=>'Online order operations permission required.'],403);
if(!pickup_fulfillment_ready($pdo)) app_json_response(['ok'=>false,'message'=>'Pickup fulfillment migration is not installed. Run upgrade.php.'],503);

function pf_api_view_permissions(): array
{
    return ['online_orders.fulfill','pos.use','pos.manage','kds.view','kds.update','kds.configure','crm.view','crm.manage'];
}

function pf_api_location_allowed(PDO $pdo,array $user,int $locationId,bool $fulfill=false): bool
{
    if($locationId<1)return false;
    if($fulfill){
        return app_has_permission('online_orders.fulfill',$user)
            && operational_location_allowed($pdo,$user,'online_orders.fulfill',$locationId);
    }
    foreach(pf_api_view_permissions() as $permission){
        if(app_has_permission($permission,$user)&&operational_location_allowed($pdo,$user,$permission,$locationId))return true;
    }
    return false;
}

function pf_api_locations(PDO $pdo,int $org,array $user): array
{
    $q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY is_primary DESC,sort_order,name,id");
    $q->execute([$org]);
    return array_values(array_filter($q->fetchAll(),static fn(array $row):bool=>pf_api_location_allowed($pdo,$user,(int)$row['id'],false)));
}

function pf_api_filter(array $input=[]): string
{
    $filter=trim((string)($input['filter']??$_GET['filter']??'active'));
    return in_array($filter,['active','ready','fulfilled','cancelled','all'],true)?$filter:'active';
}

function pf_api_location(PDO $pdo,int $org,array $user,array $input=[]): ?int
{
    $id=(int)($input['locationId']??$_GET['locationId']??0);
    if($id<1)return null;
    $q=$pdo->prepare("SELECT COUNT(*) FROM locations WHERE organization_id=? AND id=? AND status='active'");
    $q->execute([$org,$id]);
    if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('Choose an active restaurant location.');
    if(!pf_api_location_allowed($pdo,$user,$id,false))throw new DomainException('Pickup queue access is not assigned at that restaurant location.');
    return $id;
}

function pf_api_queue(PDO $pdo,int $org,array $user,?int $locationId,string $filter): array
{
    $rows=pickup_fulfillment_queue($pdo,$org,$locationId,$filter,250);
    if($locationId!==null)return $rows;
    return array_values(array_filter($rows,static fn(array $row):bool=>pf_api_location_allowed($pdo,$user,(int)$row['location_id'],false)));
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=pf_api_location($pdo,$org,$user);
        $filter=pf_api_filter();
        app_json_response([
            'ok'=>true,
            'locations'=>pf_api_locations($pdo,$org,$user),
            'locationId'=>$locationId,
            'filter'=>$filter,
            'canFulfill'=>pickup_fulfillment_can_fulfill($user),
            'csrfToken'=>app_csrf_token(),
            'orders'=>pf_api_queue($pdo,$org,$user,$locationId,$filter),
        ]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        header('Allow: GET, POST');
        app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }

    $input=app_json_input();
    app_verify_request_csrf($input);
    $action=trim((string)($input['action']??''));
    if($action!=='order.fulfill')app_json_response(['ok'=>false,'message'=>'Unsupported pickup action.'],422);
    if(!pickup_fulfillment_can_fulfill($user))app_json_response(['ok'=>false,'message'=>'Pickup fulfillment permission required.'],403);

    $public=trim((string)($input['orderPublicId']??''));
    if($public==='')throw new InvalidArgumentException('Choose a pickup order.');
    $order=pickup_fulfillment_order($pdo,$org,$public,false);
    if(!$order)throw new InvalidArgumentException('Pickup order was not found.');
    $locationId=(int)$order['location_id'];
    if(!pf_api_location_allowed($pdo,$user,$locationId,true))throw new DomainException('Pickup fulfillment is not assigned at this restaurant location.');

    $result=pickup_fulfillment_mark_handed($pdo,$org,$public,$uid,(string)($input['note']??''));
    $filter=pf_api_filter($input);
    $requestedLocation=(int)($input['locationId']??0);
    $queueLocation=$requestedLocation>0?$requestedLocation:null;
    if($queueLocation!==null&&!pf_api_location_allowed($pdo,$user,$queueLocation,false))$queueLocation=null;
    app_json_response([
        'ok'=>true,
        'fulfillment'=>$result,
        'orders'=>pf_api_queue($pdo,$org,$user,$queueLocation,$filter),
    ]);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'Pickup fulfillment could not complete the request.')],500);
}
