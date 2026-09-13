<?php
declare(strict_types=1);

function table_service_reconcile_closed_checks(PDO $pdo,int $org,int $locationId,int $userId): int
{
    if(!table_service_ready($pdo))return 0;
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$userId){
        $q=$pdo->prepare("SELECT cx.check_id,cx.table_id,c.status FROM service_check_contexts cx JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id WHERE cx.organization_id=? AND cx.location_id=? AND cx.status='active' AND c.status<>'open' FOR UPDATE");
        $q->execute([$org,$locationId]);$contexts=$q->fetchAll();$closed=0;
        foreach($contexts as $row){
            $checkId=(int)$row['check_id'];$tableId=$row['table_id']!==null?(int)$row['table_id']:null;
            $pdo->prepare("UPDATE service_check_contexts SET status='closed',closed_at=COALESCE(closed_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=? AND status='active'")->execute([$userId,$org,$checkId]);
            $released=false;
            if($tableId){
                $u=$pdo->prepare("UPDATE service_tables SET active_check_id=NULL,state='dirty',assigned_user_id=NULL,seated_at=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=? AND active_check_id=?");
                $u->execute([$userId,$org,$tableId,$checkId]);$released=$u->rowCount()>0;
            }
            table_service_event($pdo,$org,$locationId,$tableId,$checkId,null,'check_reconciled',$released?'Closed primary POS check released table.':'Closed secondary POS check reconciled without changing primary table occupancy.',['checkStatus'=>(string)$row['status'],'releasedTable'=>$released],$userId);
            $closed++;
        }
        return $closed;
    });
}
