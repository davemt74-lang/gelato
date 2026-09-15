<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/pos-floor-operations.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];
$membership=(int)$user['membership_id'];

if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}

try{
    $input=app_json_input();
    app_verify_request_csrf($input);
    $locationId=(int)($input['locationId']??0);
    if($locationId<1)throw new InvalidArgumentException('Choose a POS location.');
    pos_location($pdo,$org,$locationId);
    if(!operational_location_allowed($pdo,$user,'pos.use',$locationId))throw new DomainException('POS access is not assigned at that restaurant location.');
    $allowed=app_has_permission('pos.manage',$user)
        &&operational_location_allowed($pdo,$user,'pos.manage',$locationId)
        &&app_has_permission('floorplans.view',$user)
        &&operational_location_allowed($pdo,$user,'floorplans.view',$locationId)
        &&app_has_permission('table_service.manage',$user)
        &&operational_location_allowed($pdo,$user,'table_service.manage',$locationId);
    if(!$allowed)app_json_response(['ok'=>false,'message'=>'POS, Floor Plan, and Table Service management permissions are required at this location.'],403);

    $result=pos_floor_operations_sync($pdo,$org,$locationId,$uid);
    app_audit($pdo,$org,$uid,'pos.floor_service_points_synced','floor_plan',(string)$result['floorPlanPublicId'],null,[
        'locationId'=>$locationId,
        'servicePoints'=>$result['servicePoints'],
        'created'=>$result['created'],
        'updated'=>$result['updated'],
        'orphaned'=>$result['orphaned'],
    ]);
    app_json_response(['ok'=>true,'sync'=>$result,'floorPlan'=>$result['bootstrap']]);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'The floor plan could not be synchronized with POS.')],500);
}
