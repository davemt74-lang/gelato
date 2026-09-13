<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/wholesale-demand.php';

$user=app_require_permission($_SERVER['REQUEST_METHOD']==='GET'?'wholesale.view':'wholesale.manage');
$pdo=app_pdo();$org=(int)$user['organization_id'];
if(!wholesale_demand_ready($pdo))app_json_response(['ok'=>false,'message'=>'Wholesale demand commitment migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'forecast');
    if($action==='forecast')app_json_response(['ok'=>true,'forecast'=>wholesale_demand_forecast($pdo,$org,(int)($_GET['days']??7)),'profiles'=>wholesale_demand_production_profiles($pdo,$org)]);
    if($action==='order')app_json_response(['ok'=>true]+wholesale_demand_order_detail($pdo,$org,trim((string)($_GET['id']??''))));
    app_json_response(['ok'=>false,'message'=>'Unsupported wholesale demand action.'],422);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');
try{
    if($action==='sync'){
        $id=trim((string)($input['orderId']??''));$result=$id!==''?wholesale_demand_sync_order($pdo,$org,$id,(int)$user['id']):wholesale_demand_sync_organization($pdo,$org,(int)$user['id']);
        app_audit($pdo,$org,(int)$user['id'],'wholesale.demand_synced','wholesale_demand',$id!==''?$id:'organization',null,['result'=>$result]);
        app_json_response(['ok'=>true,'message'=>'Wholesale demand commitments rebuilt.','result'=>$result]);
    }
    if($action==='sku_production'){
        $profile=wholesale_demand_save_sku_production($pdo,$org,$input,(int)$user['id']);
        app_audit($pdo,$org,(int)$user['id'],'wholesale.sku_production_updated','wholesale_sku',(string)$profile['public_id'],null,['recipeYieldPerBatch'=>$profile['recipe_yield_per_batch'],'contentQuantity'=>$profile['content_quantity'],'contentUom'=>$profile['content_uom']]);
        app_json_response(['ok'=>true,'message'=>'Wholesale SKU production mapping updated.','profile'=>$profile]);
    }
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>'Wholesale demand update failed.'],500);}
app_json_response(['ok'=>false,'message'=>'Unsupported wholesale demand action.'],422);
