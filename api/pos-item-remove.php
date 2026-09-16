<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/operational-access.php';
require_once __DIR__.'/../includes/pos-core.php';
require_once __DIR__.'/../includes/customer-crm-core.php';
require_once __DIR__.'/../includes/kds-core.php';
require_once __DIR__.'/../includes/online-order-lifecycle.php';

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

function pos_remove_assert_unpaid(PDO $pdo,int $org,int $checkId): void
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM pos_tenders WHERE organization_id=? AND check_id=? AND status='captured'");
    $q->execute([$org,$checkId]);
    if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Ticket items are locked after the first captured tender.');
}

try{
    $base=pos_check_base($pdo,$org,$public,false);
    $locationId=(int)$base['location_id'];
    if(!operational_location_allowed($pdo,$user,'pos.use',$locationId))app_json_response(['ok'=>false,'message'=>'POS access is not assigned for this check location.'],403);

    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $check=pos_require_open_check($pdo,$org,$public,true);
        pos_remove_assert_unpaid($pdo,$org,(int)$check['id']);
        if(kds_ready($pdo))kds_assert_pos_line_mutable($pdo,$org,$itemId);
        $q=$pdo->prepare("SELECT item_name_snapshot,quantity,gross_amount FROM pos_check_items WHERE organization_id=? AND check_id=? AND id=? AND status='active' LIMIT 1 FOR UPDATE");
        $q->execute([$org,(int)$check['id'],$itemId]);$line=$q->fetch();
        if(!$line)throw new InvalidArgumentException('Active POS item was not found.');
        $pdo->prepare("DELETE FROM pos_check_items WHERE organization_id=? AND check_id=? AND id=? AND status='active'")->execute([$org,(int)$check['id'],$itemId]);
        pos_recalculate_check($pdo,$org,(int)$check['id']);
        online_order_lifecycle_sync_safe($pdo,$org,$public,$uid);
        app_audit($pdo,$org,$uid,'pos.item_removed','pos_check',$public,null,['itemId'=>$itemId,'itemName'=>(string)$line['item_name_snapshot'],'quantity'=>(float)$line['quantity'],'grossAmount'=>(float)$line['gross_amount'],'locationId'=>$locationId]);
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}

    $details=pos_check_details($pdo,$org,$public);
    $details['customer']=crm_ready($pdo)?crm_check_customer($pdo,$org,$public):null;
    $details['kitchen']=kds_ready($pdo)?kds_check_summary($pdo,$org,$public):['ready'=>false,'sent'=>0,'unsent'=>0,'unrouted'=>0,'items'=>[]];
    app_json_response(['ok'=>true,'check'=>$details,'message'=>(string)$line['item_name_snapshot'].' removed from the ticket.']);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('POS item removal failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'The POS item could not be removed from this ticket.'],500);}
