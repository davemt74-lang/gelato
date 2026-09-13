<?php
declare(strict_types=1);

require_once __DIR__.'/service-visit-core.php';

function service_visit_dashboard(PDO $pdo,int $org,int $locationId,string $date,int $userId): array
{
    table_service_reconcile_closed_checks($pdo,$org,$locationId,$userId);
    service_visit_reconcile_reservations($pdo,$org,$locationId,$userId);
    return [
        'location'=>table_service_location($pdo,$org,$locationId),
        'floorPlans'=>host_floor_plans($pdo,$org),
        'sections'=>service_ops_sections_today($pdo,$org,$locationId,true),
        'staff'=>table_service_staff($pdo,$org,$locationId),
        'tables'=>host_table_rows($pdo,$org,$locationId,true),
        'combinations'=>host_combinations($pdo,$org,$locationId),
        'reservations'=>host_reservations($pdo,$org,$locationId,$date),
    ];
}

function service_visit_attach_customer_safe(PDO $pdo,int $org,string $checkPublicId,?string $customerPublicId): ?array
{
    if(!service_visit_ready($pdo))return crm_attach_check($pdo,$org,$checkPublicId,$customerPublicId);
    $q=$pdo->prepare('SELECT id,status FROM pos_checks WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$checkPublicId]);$check=$q->fetch();
    if(!$check)throw new InvalidArgumentException('POS check was not found.');
    $context=table_service_context($pdo,$org,(int)$check['id'],false);
    if(!$context)return crm_attach_check($pdo,$org,$checkPublicId,$customerPublicId);
    return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$customerPublicId,$check){
        $locked=table_service_check_row($pdo,$org,$checkPublicId,true);if($locked['status']!=='open')throw new InvalidArgumentException('Customer can only be changed on an open POS check.');
        service_visit_context($pdo,$org,(int)$locked['id'],true);$group=service_visit_ensure_group($pdo,$org,(int)$locked['id']);
        $customerId=null;if($customerPublicId!==null&&trim($customerPublicId)!==''){$c=crm_customer_row($pdo,$org,trim($customerPublicId));if($c['status']!=='active')throw new InvalidArgumentException('Archived customers cannot be attached to a POS check.');$customerId=(int)$c['id'];}
        $settledQ=$pdo->prepare("SELECT DISTINCT c.customer_id FROM service_check_contexts cx JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id WHERE cx.organization_id=? AND cx.visit_group_id=? AND c.status='paid' AND c.customer_id IS NOT NULL");$settledQ->execute([$org,$group]);$settled=array_map('intval',$settledQ->fetchAll(PDO::FETCH_COLUMN));
        if(count($settled)>1||($settled&&$customerId!==$settled[0]))throw new InvalidArgumentException('Customer identity cannot be changed after another check in this dining visit has been paid.');
        $pdo->prepare("UPDATE pos_checks c JOIN service_check_contexts cx ON cx.check_id=c.id AND cx.organization_id=c.organization_id SET c.customer_id=?,c.revision=c.revision+1,c.updated_at=NOW(6) WHERE c.organization_id=? AND cx.visit_group_id=? AND c.status='open'")->execute([$customerId,$org,$group]);
        return crm_check_customer($pdo,$org,$checkPublicId);
    });
}

function service_visit_transfer_safe(PDO $pdo,int $org,string $checkPublicId,string $destinationTablePublicId,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$destinationTablePublicId,$userId){
        $check=table_service_check_row($pdo,$org,$checkPublicId,true);if($check['status']!=='open')throw new InvalidArgumentException('Only an open check can transfer tables.');
        $context=service_visit_context($pdo,$org,(int)$check['id'],true);$group=service_visit_ensure_group($pdo,$org,(int)$check['id']);
        $q=$pdo->prepare("SELECT COUNT(*) FROM pos_tenders t JOIN service_check_contexts cx ON cx.check_id=t.check_id AND cx.organization_id=t.organization_id WHERE t.organization_id=? AND cx.visit_group_id=? AND t.status='captured'");$q->execute([$org,$group]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Table transfer is locked after any check in this dining visit has captured payment.');
        $dest=service_ops_assert_table_operable($pdo,$org,(int)$check['location_id'],$destinationTablePublicId,true);$visitCheckIds=service_visit_group_check_ids($pdo,$org,$group,false);
        if(!$visitCheckIds)throw new RuntimeException('Dining visit has no checks.');$phChecks=implode(',',array_fill(0,count($visitCheckIds),'?'));
        $q=$pdo->prepare("SELECT COALESCE(SUM(guest_count),0) FROM pos_checks WHERE organization_id=? AND id IN ($phChecks) AND status IN ('open','paid')");$q->execute(array_merge([$org],$visitCheckIds));$visitGuests=max(1,(int)$q->fetchColumn());
        if((int)$dest['capacity']<$visitGuests)throw new InvalidArgumentException((string)$dest['name'].' does not have enough capacity for the full '.$visitGuests.'-guest dining visit.');
        if($dest['active_check_id']!==null&&!in_array((int)$dest['active_check_id'],$visitCheckIds,true))throw new InvalidArgumentException('Destination table already has another active check.');
        $q=$pdo->prepare("SELECT id FROM service_tables WHERE organization_id=? AND location_id=? AND active_check_id IN ($phChecks) FOR UPDATE");$q->execute(array_merge([$org,(int)$check['location_id']],$visitCheckIds));$oldTableIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        foreach($oldTableIds as $tableId){if($tableId===(int)$dest['id'])continue;$pdo->prepare("UPDATE service_tables SET active_check_id=NULL,state='dirty',assigned_user_id=NULL,seated_at=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,$tableId]);}
        $pdo->prepare("UPDATE service_tables SET active_check_id=?,assigned_user_id=?,state='seated',seated_at=COALESCE(seated_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([(int)$check['id'],$context['server_user_id'],$userId,$org,(int)$dest['id']]);
        $pdo->prepare("UPDATE service_check_contexts cx JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id SET cx.table_id=?,cx.updated_by=?,cx.updated_at=NOW(6) WHERE cx.organization_id=? AND cx.visit_group_id=? AND c.status='open'")->execute([(int)$dest['id'],$userId,$org,$group]);
        $pdo->prepare("UPDATE pos_checks c JOIN service_check_contexts cx ON cx.check_id=c.id AND cx.organization_id=c.organization_id SET c.table_name=?,c.revision=c.revision+1,c.updated_at=NOW(6) WHERE c.organization_id=? AND cx.visit_group_id=? AND c.status='open'")->execute([(string)$dest['name'],$org,$group]);
        $rq=$pdo->prepare("SELECT r.id FROM guest_reservations r JOIN service_check_contexts cx ON cx.check_id=r.seated_check_id AND cx.organization_id=r.organization_id WHERE r.organization_id=? AND cx.visit_group_id=? AND r.status='seated' FOR UPDATE");$rq->execute([$org,$group]);$reservationIds=array_map('intval',$rq->fetchAll(PDO::FETCH_COLUMN));
        foreach($reservationIds as $reservationId){$pdo->prepare('DELETE FROM guest_reservation_tables WHERE organization_id=? AND reservation_id=?')->execute([$org,$reservationId]);$pdo->prepare('INSERT INTO guest_reservation_tables (organization_id,reservation_id,service_table_id) VALUES (?,?,?)')->execute([$org,$reservationId,(int)$dest['id']]);host_reservation_event($pdo,$org,$reservationId,'table_transferred','Seated reservation moved with its dining visit.',['destinationTablePublicId'=>$destinationTablePublicId,'visitGroupId'=>$group],$userId);}
        table_service_event($pdo,$org,(int)$check['location_id'],(int)$dest['id'],(int)$check['id'],null,'visit_table_transferred','Dining visit transferred to a new table.',['fromTableIds'=>$oldTableIds,'toTableId'=>(int)$dest['id'],'visitGroupId'=>$group,'visitGuests'=>$visitGuests],$userId);
        return table_service_detail($pdo,$org,$checkPublicId);
    });
}
