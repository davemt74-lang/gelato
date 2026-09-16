<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/pos-item-actions.php';
require_once __DIR__.'/../includes/customer-crm-core.php';
require_once __DIR__.'/../includes/kds-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$org=(int)$user['organization_id'];
$uid=(int)$user['id'];

if(!app_has_permission('pos.use',$user))app_json_response(['ok'=>false,'message'=>'Native POS permission required.'],403);
if(!pos_ready($pdo))app_json_response(['ok'=>false,'message'=>'Native POS migration is not installed. Run upgrade.php.'],503);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}

$input=app_json_input();
app_verify_request_csrf($input);
if((string)($input['action']??'remove')!=='remove')app_json_response(['ok'=>false,'message'=>'Unsupported POS item removal action.'],422);
$public=trim((string)($input['checkPublicId']??''));
$itemId=max(0,(int)($input['itemId']??0));
if($public===''||$itemId<1)app_json_response(['ok'=>false,'message'=>'Choose an active ticket item to remove.'],422);

try{
    $base=pos_check_base($pdo,$org,$public,false);
    $locationId=(int)$base['location_id'];
    if(!operational_location_allowed($pdo,$user,'pos.use',$locationId))app_json_response(['ok'=>false,'message'=>'POS access is not assigned for this check location.'],403);

    $result=pos_item_remove_unsent($pdo,$org,$public,$itemId,$uid);
    $details=$result['check'];
    $details['customer']=crm_ready($pdo)?crm_check_customer($pdo,$org,$public):null;
    $details['kitchen']=kds_ready($pdo)?kds_check_summary($pdo,$org,$public):['ready'=>false,'sent'=>0,'unsent'=>0,'unrouted'=>0,'items'=>[]];
    app_json_response(['ok'=>true,'check'=>$details,'message'=>(string)$result['line']['item_name_snapshot'].' removed from the ticket.']);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('POS item removal failed: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'The POS item could not be removed from this ticket.'],500);
}
