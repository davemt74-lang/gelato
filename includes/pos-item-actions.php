<?php
declare(strict_types=1);

require_once __DIR__.'/pos-core.php';
require_once __DIR__.'/kds-core.php';
require_once __DIR__.'/online-order-lifecycle.php';

function pos_item_action_assert_unpaid(PDO $pdo,int $org,int $checkId): void
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM pos_tenders WHERE organization_id=? AND check_id=? AND status='captured'");
    $q->execute([$org,$checkId]);
    if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Ticket items are locked after the first captured tender.');
}

function pos_item_remove_unsent(PDO $pdo,int $org,string $checkPublicId,int $itemId,int $userId): array
{
    if($itemId<1)throw new InvalidArgumentException('Choose an active ticket item to remove.');
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $check=pos_require_open_check($pdo,$org,$checkPublicId,true);
        pos_item_action_assert_unpaid($pdo,$org,(int)$check['id']);
        if(kds_ready($pdo))kds_assert_pos_line_mutable($pdo,$org,$itemId);
        $q=$pdo->prepare("SELECT item_name_snapshot,quantity,gross_amount FROM pos_check_items WHERE organization_id=? AND check_id=? AND id=? AND status='active' LIMIT 1 FOR UPDATE");
        $q->execute([$org,(int)$check['id'],$itemId]);
        $line=$q->fetch();
        if(!$line)throw new InvalidArgumentException('Active POS item was not found.');
        $pdo->prepare("DELETE FROM pos_check_items WHERE organization_id=? AND check_id=? AND id=? AND status='active'")->execute([$org,(int)$check['id'],$itemId]);
        pos_recalculate_check($pdo,$org,(int)$check['id']);
        online_order_lifecycle_sync_safe($pdo,$org,$checkPublicId,$userId);
        app_audit($pdo,$org,$userId,'pos.item_removed','pos_check',$checkPublicId,null,[
            'itemId'=>$itemId,
            'itemName'=>(string)$line['item_name_snapshot'],
            'quantity'=>(float)$line['quantity'],
            'grossAmount'=>(float)$line['gross_amount'],
            'locationId'=>(int)$check['location_id'],
        ]);
        if($owns)$pdo->commit();
        return ['line'=>$line,'check'=>pos_check_details($pdo,$org,$checkPublicId)];
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
