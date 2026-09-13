<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/wholesale-fulfillment.php';
require_once __DIR__.'/../includes/operations-wholesale.php';

$user=app_require_permission($_SERVER['REQUEST_METHOD']==='GET'?'wholesale.view':'wholesale.manage');
$pdo=app_pdo();$org=(int)$user['organization_id'];$userId=(int)$user['id'];
if(!wholesale_fulfillment_ready($pdo))app_json_response(['ok'=>false,'message'=>'Wholesale allocation/fulfillment migration is not installed. Run upgrade.php.'],503);

function wholesale_fulfillment_api_detail(PDO $pdo,int $org,string $orderId): array
{
    $detail=wholesale_fulfillment_order_detail($pdo,$org,$orderId);
    $locationDbId=(int)($detail['order']['locationId']??0);
    $detail['order']['locationDbId']=$locationDbId?:null;
    if($locationDbId>0){
        $q=$pdo->prepare('SELECT public_id FROM wholesale_account_locations WHERE organization_id=? AND id=? LIMIT 1');
        $q->execute([$org,$locationDbId]);
        $detail['order']['locationId']=$q->fetchColumn()?:null;
    }else $detail['order']['locationId']=null;
    return $detail;
}

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'list');
    try{
        if($action==='list')app_json_response(['ok'=>true,'orders'=>wholesale_fulfillment_orders($pdo,$org)]);
        if($action==='detail'){
            $id=trim((string)($_GET['id']??''));if($id==='')throw new InvalidArgumentException('Wholesale order is required.');
            app_json_response(['ok'=>true,'detail'=>wholesale_fulfillment_api_detail($pdo,$org,$id)]);
        }
        if($action==='batch'){
            $id=trim((string)($_GET['id']??''));if($id==='')throw new InvalidArgumentException('Fulfillment batch is required.');
            $batch=wholesale_fulfillment_batch($pdo,$org,$id);
            app_json_response(['ok'=>true,'batch'=>wholesale_fulfillment_batch_payload($pdo,$org,$batch),'availability'=>wholesale_fulfillment_batch_availability($pdo,$org,$batch)]);
        }
        throw new InvalidArgumentException('Unsupported Wholesale fulfillment action.');
    }catch(InvalidArgumentException|RuntimeException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');
try{
    if($action==='plan.save'){
        $orderId=trim((string)($input['orderId']??''));if($orderId==='')throw new InvalidArgumentException('Wholesale order is required.');
        wholesale_fulfillment_save_order_plan($pdo,$org,$orderId,$input,$userId);
        operations_sync_wholesale_tasks($pdo,$org,$userId);
        app_json_response(['ok'=>true,'message'=>'Fulfillment location and windows saved.','detail'=>wholesale_fulfillment_api_detail($pdo,$org,$orderId)]);
    }
    if($action==='batch.create'){
        $orderId=trim((string)($input['orderId']??''));if($orderId==='')throw new InvalidArgumentException('Wholesale order is required.');
        $batch=wholesale_fulfillment_create_batch($pdo,$org,$orderId,$input,$userId);
        operations_sync_wholesale_tasks($pdo,$org,$userId);
        app_json_response(['ok'=>true,'message'=>'Fulfillment batch created.','batch'=>$batch,'detail'=>wholesale_fulfillment_api_detail($pdo,$org,$orderId)]);
    }
    $batchId=trim((string)($input['batchId']??''));if($batchId==='')throw new InvalidArgumentException('Fulfillment batch is required.');
    if($action==='batch.ready'||$action==='batch.dispatch'||$action==='batch.cancel'){
        $next=['batch.ready'=>'ready','batch.dispatch'=>'dispatched','batch.cancel'=>'cancelled'][$action];
        $batch=wholesale_fulfillment_set_status($pdo,$org,$batchId,$next,$userId);
        operations_sync_wholesale_tasks($pdo,$org,$userId);
        app_json_response(['ok'=>true,'message'=>'Fulfillment batch moved to '.$next.'.','batch'=>$batch,'detail'=>wholesale_fulfillment_api_detail($pdo,$org,(string)$batch['orderId'])]);
    }
    if($action==='batch.deliver'){
        if(!app_has_permission('inventory.manage',$user))app_json_response(['ok'=>false,'message'=>'Delivering a Wholesale fulfillment consumes physical inventory and requires inventory.manage permission.'],403);
        $batch=wholesale_fulfillment_deliver($pdo,$org,$batchId,$userId);
        operations_sync_wholesale_tasks($pdo,$org,$userId);
        app_json_response(['ok'=>true,'message'=>'Fulfillment delivered and canonical inventory consumed.','batch'=>$batch,'detail'=>wholesale_fulfillment_api_detail($pdo,$org,(string)$batch['orderId'])]);
    }
    throw new InvalidArgumentException('Unsupported Wholesale fulfillment action.');
}catch(InvalidArgumentException|RuntimeException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
