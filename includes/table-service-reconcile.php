<?php
declare(strict_types=1);

function table_service_reconcile_closed_checks(PDO $pdo,int $org,int $locationId,int $userId): int
{
    if(!table_service_ready($pdo))return 0;
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$userId){
        $q=$pdo->prepare("SELECT t.id table_id,t.active_check_id,c.public_id,c.status FROM service_tables t JOIN pos_checks c ON c.id=t.active_check_id AND c.organization_id=t.organization_id WHERE t.organization_id=? AND t.location_id=? AND t.active_check_id IS NOT NULL AND c.status<>'open' FOR UPDATE");
        $q->execute([$org,$locationId]);$rows=$q->fetchAll();
        foreach($rows as $row){$pdo->prepare("UPDATE service_tables SET active_check_id=NULL,state='dirty',assigned_user_id=NULL,seated_at=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,(int)$row['table_id']]);$pdo->prepare("UPDATE service_check_contexts SET status='closed',closed_at=COALESCE(closed_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=? AND status='active'")->execute([$userId,$org,(int)$row['active_check_id']]);table_service_event($pdo,$org,$locationId,(int)$row['table_id'],(int)$row['active_check_id'],null,'check_reconciled','Closed POS check released table.',['checkStatus'=>(string)$row['status']],$userId);}
        return count($rows);
    });
}
