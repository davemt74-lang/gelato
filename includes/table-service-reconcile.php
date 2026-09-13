<?php
declare(strict_types=1);

function table_service_reconcile_closed_checks(PDO $pdo,int $org,int $locationId,int $userId): int
{
    if(!table_service_ready($pdo))return 0;
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$userId){
        // Pass 1: close every service context whose POS check is no longer open.
        // This includes secondary split checks that do not own the physical table pointer.
        $q=$pdo->prepare("SELECT cx.check_id,cx.table_id,c.status FROM service_check_contexts cx JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id WHERE cx.organization_id=? AND cx.location_id=? AND cx.status='active' AND c.status<>'open' FOR UPDATE");
        $q->execute([$org,$locationId]);$contexts=$q->fetchAll();$contextCount=0;
        foreach($contexts as $row){
            $checkId=(int)$row['check_id'];$tableId=$row['table_id']!==null?(int)$row['table_id']:null;
            $pdo->prepare("UPDATE service_check_contexts SET status='closed',closed_at=COALESCE(closed_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=? AND status='active'")->execute([$userId,$org,$checkId]);
            $owns=$pdo->prepare('SELECT COUNT(*) FROM service_tables WHERE organization_id=? AND location_id=? AND active_check_id=?');$owns->execute([$org,$locationId,$checkId]);$willRelease=(int)$owns->fetchColumn()>0;
            if(!$willRelease){
                table_service_event($pdo,$org,$locationId,$tableId,$checkId,null,'check_reconciled','Closed secondary POS check reconciled without changing primary table occupancy.',['checkStatus'=>(string)$row['status'],'releasedTable'=>false],$userId);
            }
            $contextCount++;
        }

        // Pass 2: release every physical table that points at a closed POS check.
        // Multiple pushed-together tables may intentionally share the same active_check_id.
        $q=$pdo->prepare("SELECT t.id table_id,t.active_check_id,c.status FROM service_tables t JOIN pos_checks c ON c.id=t.active_check_id AND c.organization_id=t.organization_id WHERE t.organization_id=? AND t.location_id=? AND t.active_check_id IS NOT NULL AND c.status<>'open' FOR UPDATE");
        $q->execute([$org,$locationId]);$tables=$q->fetchAll();$releasedCount=0;
        foreach($tables as $row){
            $tableId=(int)$row['table_id'];$checkId=(int)$row['active_check_id'];
            $u=$pdo->prepare("UPDATE service_tables SET active_check_id=NULL,state='dirty',assigned_user_id=NULL,seated_at=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND active_check_id=?");
            $u->execute([$userId,$org,$tableId,$checkId]);
            if($u->rowCount()>0){
                table_service_event($pdo,$org,$locationId,$tableId,$checkId,null,'check_reconciled','Closed POS check released physical table.',['checkStatus'=>(string)$row['status'],'releasedTable'=>true],$userId);
                $releasedCount++;
            }
        }

        // Preserve the historical contract: a secondary split reconciliation returns one,
        // while a combined-table close returns the number of physical tables released.
        return $releasedCount>0?$releasedCount:$contextCount;
    });
}
