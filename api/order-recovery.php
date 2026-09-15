<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/order-recovery-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!order_recovery_can_view($user))app_json_response(['ok'=>false,'message'=>'Order recovery permission required.'],403);
if(!order_recovery_ready($pdo))app_json_response(['ok'=>false,'message'=>'Order recovery migration is not installed. Run upgrade.php.'],503);

function or_api_permissions_for(string $mode): array
{
    return match($mode){
        'manage'=>['order_recovery.manage'],
        'refund'=>['order_recovery.refund'],
        default=>['order_recovery.view','order_recovery.manage','order_recovery.refund'],
    };
}

function or_api_location_allowed(PDO $pdo,array $user,int $locationId,string $mode='view'): bool
{
    if($locationId<1)return false;
    foreach(or_api_permissions_for($mode) as $permission){
        if(app_has_permission($permission,$user)&&operational_location_allowed($pdo,$user,$permission,$locationId))return true;
    }
    return false;
}

function or_api_locations(PDO $pdo,int $org,array $user): array
{
    $q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY is_primary DESC,sort_order,name,id");$q->execute([$org]);
    return array_values(array_filter($q->fetchAll(),static fn(array $r):bool=>or_api_location_allowed($pdo,$user,(int)$r['id'],'view')));
}

function or_api_location(PDO $pdo,int $org,array $user,array $input=[]): ?int
{
    $id=(int)($input['locationId']??$_GET['locationId']??0);if($id<1)return null;
    $q=$pdo->prepare("SELECT COUNT(*) FROM locations WHERE organization_id=? AND id=? AND status='active'");$q->execute([$org,$id]);
    if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('Choose an active restaurant location.');
    if(!or_api_location_allowed($pdo,$user,$id,'view'))throw new DomainException('Order recovery access is not assigned at that restaurant location.');
    return $id;
}

function or_api_filter(array $input=[]): string
{
    $filter=strtolower(trim((string)($input['filter']??$_GET['filter']??'active')));
    return in_array($filter,['active','escalated','ready','completed','all'],true)?$filter:'active';
}

function or_api_order_for_action(PDO $pdo,int $org,array $user,string $public,string $mode): array
{
    if($public==='')throw new InvalidArgumentException('Choose an online order.');
    $order=order_recovery_order($pdo,$org,$public,false);
    if(!or_api_location_allowed($pdo,$user,(int)$order['location_id'],$mode))throw new DomainException('This recovery action is not assigned at that restaurant location.');
    return $order;
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $locationId=or_api_location($pdo,$org,$user);$filter=or_api_filter();$public=trim((string)($_GET['order']??''));
        $queue=order_recovery_queue($pdo,$org,$locationId,$filter,250);
        if($locationId===null)$queue=array_values(array_filter($queue,static fn(array $row):bool=>or_api_location_allowed($pdo,$user,(int)$row['location_id'],'view')));
        $detail=null;if($public!==''){$detail=order_recovery_detail($pdo,$org,$public);if(!or_api_location_allowed($pdo,$user,(int)$detail['location_id'],'view'))throw new DomainException('This order is outside your assigned recovery locations.');}
        app_json_response(['ok'=>true,'locations'=>or_api_locations($pdo,$org,$user),'locationId'=>$locationId,'filter'=>$filter,'orders'=>$queue,'detail'=>$detail,
            'permissions'=>['manage'=>order_recovery_can_manage($user),'refund'=>order_recovery_can_refund($user)],'csrfToken'=>app_csrf_token()]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    $input=app_json_input();app_verify_request_csrf($input);$action=trim((string)($input['action']??''));$public=trim((string)($input['orderPublicId']??''));

    if($action==='refund.record'){
        if(!order_recovery_can_refund($user))app_json_response(['ok'=>false,'message'=>'Manager refund permission required.'],403);
        or_api_order_for_action($pdo,$org,$user,$public,'refund');
        $result=operational_db_wrap($pdo,fn()=>order_recovery_refund($pdo,$org,$public,(float)($input['amount']??0),(string)($input['method']??'manual'),(string)($input['reason']??''),(string)($input['externalReference']??''),$uid));
        app_json_response(['ok'=>true,'result'=>$result]);
    }

    if(!order_recovery_can_manage($user))app_json_response(['ok'=>false,'message'=>'Order recovery management permission required.'],403);
    $order=or_api_order_for_action($pdo,$org,$user,$public,'manage');

    if($action==='exception.create'){
        $result=operational_db_wrap($pdo,fn()=>order_recovery_create_exception($pdo,$org,$public,(string)($input['type']??'other'),(string)($input['summary']??''),(string)($input['details']??''),(string)($input['severity']??'medium'),$uid,[
            'customerMessage'=>(string)($input['customerMessage']??''),'recoveryAmount'=>(float)($input['recoveryAmount']??0),'requiresManager'=>!empty($input['requiresManager']),
        ]));
    }elseif($action==='order.delay'){
        $result=operational_db_wrap($pdo,fn()=>order_recovery_delay($pdo,$org,$public,(string)($input['readyAt']??''),(string)($input['reason']??''),$uid,!array_key_exists('notifyCustomer',$input)||!empty($input['notifyCustomer'])));
    }elseif($action==='kitchen.remake'){
        $result=operational_db_wrap($pdo,fn()=>order_recovery_remake($pdo,$org,$public,(string)($input['kdsItemPublicId']??''),(string)($input['reason']??''),$uid));
    }elseif($action==='exception.escalate'){
        $exception=trim((string)($input['exceptionPublicId']??''));if($exception==='')throw new InvalidArgumentException('Choose an exception to escalate.');
        $row=order_recovery_exception_row($pdo,$org,$exception,false);if((int)$row['online_order_id']!==(int)$order['online_order_id'])throw new InvalidArgumentException('Exception does not belong to this order.');
        $result=operational_db_wrap($pdo,fn()=>order_recovery_escalate($pdo,$org,$exception,$uid,(string)($input['note']??'')));
    }elseif($action==='exception.resolve'){
        $exception=trim((string)($input['exceptionPublicId']??''));if($exception==='')throw new InvalidArgumentException('Choose an exception to resolve.');
        $row=order_recovery_exception_row($pdo,$org,$exception,false);if((int)$row['online_order_id']!==(int)$order['online_order_id'])throw new InvalidArgumentException('Exception does not belong to this order.');
        $result=operational_db_wrap($pdo,fn()=>order_recovery_resolve($pdo,$org,$exception,$uid,(string)($input['resolution']??'')));
    }else app_json_response(['ok'=>false,'message'=>'Unsupported order recovery action.'],422);

    app_json_response(['ok'=>true,'result'=>$result,'detail'=>order_recovery_detail($pdo,$org,$public)]);
}catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){app_json_response(['ok'=>false,'message'=>operational_safe_error($e,'Order recovery could not complete the request.')],500);}
