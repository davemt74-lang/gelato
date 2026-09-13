<?php
declare(strict_types=1);

require_once __DIR__.'/service-ops-reservation.php';
require_once __DIR__.'/table-service-reconcile.php';
require_once __DIR__.'/customer-crm-core.php';

function service_visit_ready(PDO $pdo): bool
{
    if(!table_service_ready($pdo))return false;
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='service_check_contexts' AND column_name='visit_group_id'");
    return (int)$q->fetchColumn()===1;
}

function service_visit_public_id(): string
{
    return 'visit-'.bin2hex(random_bytes(12));
}

function service_visit_context(PDO $pdo,int $org,int $checkId,bool $forUpdate=false): array
{
    $q=$pdo->prepare('SELECT * FROM service_check_contexts WHERE organization_id=? AND check_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,$checkId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Table-service context was not found.');
    return $row;
}

function service_visit_ensure_group(PDO $pdo,int $org,int $checkId): string
{
    $context=service_visit_context($pdo,$org,$checkId,true);$group=trim((string)($context['visit_group_id']??''));
    if($group!=='')return $group;
    $group=service_visit_public_id();
    $pdo->prepare('UPDATE service_check_contexts SET visit_group_id=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$group,$org,(int)$context['id']]);
    return $group;
}

function service_visit_group_check_ids(PDO $pdo,int $org,string $group,bool $openOnly=false): array
{
    $sql='SELECT c.id FROM service_check_contexts cx JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id WHERE cx.organization_id=? AND cx.visit_group_id=?';
    if($openOnly)$sql.=" AND c.status='open'";
    $sql.=' ORDER BY c.id';$q=$pdo->prepare($sql);$q->execute([$org,$group]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}

function service_visit_open_checks(PDO $pdo,int $org,int $locationId): array
{
    $rows=pos_open_checks($pdo,$org,$locationId);if(!$rows)return [];
    $q=$pdo->prepare("SELECT c.public_id FROM service_check_contexts cx JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id WHERE cx.organization_id=? AND cx.location_id=? AND cx.status='active' AND c.status='open'");
    $q->execute([$org,$locationId]);$allowed=array_fill_keys(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)),true);
    return array_values(array_filter($rows,static fn(array $r):bool=>isset($allowed[(string)$r['public_id']])));
}

function service_visit_seat(PDO $pdo,int $org,int $locationId,string $tablePublicId,int $partySize,?int $serverUserId,string $notes,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$partySize,$serverUserId,$notes,$userId){
        $check=service_ops_seat($pdo,$org,$locationId,$tablePublicId,$partySize,$serverUserId,$notes,$userId);
        service_visit_ensure_group($pdo,$org,(int)$check['id']);
        return table_service_detail($pdo,$org,(string)$check['publicId']);
    });
}

function service_visit_host_seat(PDO $pdo,int $org,string $reservationPublicId,?int $serverUserId,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$reservationPublicId,$serverUserId,$userId){
        $result=service_ops_host_seat($pdo,$org,$reservationPublicId,$serverUserId,$userId);
        service_visit_ensure_group($pdo,$org,(int)$result['check']['id']);
        return ['reservation'=>host_reservation_payload($pdo,$org,host_reservation_row($pdo,$org,$reservationPublicId,false)),'check'=>table_service_detail($pdo,$org,(string)$result['check']['publicId'])];
    });
}

function service_visit_split(PDO $pdo,int $org,string $sourceCheckPublicId,array $itemIds,int $userId,?int $movedGuestCount=null): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$sourceCheckPublicId,$itemIds,$userId,$movedGuestCount){
        $source=table_service_check_row($pdo,$org,$sourceCheckPublicId,true);$sourceId=(int)$source['id'];$group=service_visit_ensure_group($pdo,$org,$sourceId);$customerId=$source['customer_id']!==null?(int)$source['customer_id']:null;
        $result=service_ops_split($pdo,$org,$sourceCheckPublicId,$itemIds,$userId,$movedGuestCount);$createdId=(int)$result['created']['id'];
        $pdo->prepare('UPDATE service_check_contexts SET visit_group_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=?')->execute([$group,$userId,$org,$createdId]);
        if($customerId!==null)$pdo->prepare('UPDATE pos_checks SET customer_id=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$customerId,$org,$createdId]);
        table_service_event($pdo,$org,(int)$source['location_id'],null,$createdId,null,'visit_split_linked','Split check linked to the same dining visit.',['visitGroupId'=>$group,'sourceCheckPublicId'=>$sourceCheckPublicId],$userId);
        return ['source'=>table_service_detail($pdo,$org,$sourceCheckPublicId),'created'=>table_service_detail($pdo,$org,(string)$result['created']['publicId'])];
    });
}

function service_visit_group_customer_ids(PDO $pdo,int $org,array $groups): array
{
    $groups=array_values(array_unique(array_filter(array_map('strval',$groups))));if(!$groups)return [];$ph=implode(',',array_fill(0,count($groups),'?'));
    $q=$pdo->prepare("SELECT DISTINCT c.customer_id FROM service_check_contexts cx JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id WHERE cx.organization_id=? AND cx.visit_group_id IN ($ph) AND c.customer_id IS NOT NULL");
    $q->execute(array_merge([$org],$groups));return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}

function service_visit_merge(PDO $pdo,int $org,string $sourceCheckPublicId,string $targetCheckPublicId,int $userId): array
{
    if($sourceCheckPublicId===$targetCheckPublicId)throw new InvalidArgumentException('Choose two different checks to merge.');
    return table_service_transaction($pdo,function()use($pdo,$org,$sourceCheckPublicId,$targetCheckPublicId,$userId){
        $source=table_service_check_row($pdo,$org,$sourceCheckPublicId,true);$target=table_service_check_row($pdo,$org,$targetCheckPublicId,true);
        if($source['status']!=='open'||$target['status']!=='open')throw new InvalidArgumentException('Both checks must be open.');
        if((int)$source['location_id']!==(int)$target['location_id'])throw new InvalidArgumentException('Checks at different locations cannot be merged.');
        table_service_payment_guard($pdo,$org,(int)$source['id']);table_service_payment_guard($pdo,$org,(int)$target['id']);
        $sourceContext=service_visit_context($pdo,$org,(int)$source['id'],true);$targetContext=service_visit_context($pdo,$org,(int)$target['id'],true);
        $sourceGroup=service_visit_ensure_group($pdo,$org,(int)$source['id']);$targetGroup=service_visit_ensure_group($pdo,$org,(int)$target['id']);
        $customerIds=service_visit_group_customer_ids($pdo,$org,[$sourceGroup,$targetGroup]);
        if(count($customerIds)>1)throw new InvalidArgumentException('Checks linked to different CRM customers cannot be merged.');
        $unifiedCustomer=$customerIds[0]??null;$combinedGuests=(int)$source['guest_count']+(int)$target['guest_count'];
        if($combinedGuests>99)throw new InvalidArgumentException('Merged party exceeds the supported 99-guest check limit.');
        $tables=$pdo->prepare('SELECT id FROM service_tables WHERE organization_id=? AND location_id=? AND active_check_id=? FOR UPDATE');$tables->execute([$org,(int)$source['location_id'],(int)$source['id']]);$sourceTableIds=array_map('intval',$tables->fetchAll(PDO::FETCH_COLUMN));
        $reservationQ=$pdo->prepare("SELECT id FROM guest_reservations WHERE organization_id=? AND seated_check_id=? AND status='seated' FOR UPDATE");$reservationQ->execute([$org,(int)$source['id']]);$reservationIds=array_map('intval',$reservationQ->fetchAll(PDO::FETCH_COLUMN));
        table_service_merge($pdo,$org,$sourceCheckPublicId,$targetCheckPublicId,$userId);
        if($sourceGroup!==$targetGroup)$pdo->prepare('UPDATE service_check_contexts SET visit_group_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND visit_group_id=?')->execute([$targetGroup,$userId,$org,$sourceGroup]);
        $pdo->prepare('UPDATE pos_checks SET guest_count=?,customer_id=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$combinedGuests,$unifiedCustomer,$org,(int)$target['id']]);
        $pdo->prepare('UPDATE service_check_contexts SET party_size=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=?')->execute([$combinedGuests,$userId,$org,(int)$target['id']]);
        if($sourceTableIds){$ph=implode(',',array_fill(0,count($sourceTableIds),'?'));$args=array_merge([(int)$target['id'],$targetContext['server_user_id'],$userId,$org],$sourceTableIds);$pdo->prepare("UPDATE service_tables SET active_check_id=?,assigned_user_id=COALESCE(?,assigned_user_id),state='seated',seated_at=COALESCE(seated_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id IN ($ph)")->execute($args);}
        if($reservationIds){$ph=implode(',',array_fill(0,count($reservationIds),'?'));$args=array_merge([(int)$target['id'],$userId,$org],$reservationIds);$pdo->prepare("UPDATE guest_reservations SET seated_check_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id IN ($ph)")->execute($args);foreach($reservationIds as $reservationId)host_reservation_event($pdo,$org,$reservationId,'check_merged','Reservation moved to the surviving POS check.',['sourceCheckPublicId'=>$sourceCheckPublicId,'targetCheckPublicId'=>$targetCheckPublicId,'visitGroupId'=>$targetGroup],$userId);}
        table_service_event($pdo,$org,(int)$source['location_id'],$targetContext['table_id']!==null?(int)$targetContext['table_id']:null,(int)$target['id'],null,'visit_checks_merged','Dining-visit checks merged with covers and visit identity preserved.',['sourceCheckPublicId'=>$sourceCheckPublicId,'targetCheckPublicId'=>$targetCheckPublicId,'visitGroupId'=>$targetGroup,'combinedGuests'=>$combinedGuests],$userId);
        return table_service_detail($pdo,$org,$targetCheckPublicId);
    });
}

function service_visit_transfer(PDO $pdo,int $org,string $checkPublicId,string $destinationTablePublicId,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$destinationTablePublicId,$userId){
        $check=table_service_check_row($pdo,$org,$checkPublicId,true);if($check['status']!=='open')throw new InvalidArgumentException('Only an open check can transfer tables.');
        $context=service_visit_context($pdo,$org,(int)$check['id'],true);$group=service_visit_ensure_group($pdo,$org,(int)$check['id']);
        $q=$pdo->prepare("SELECT COUNT(*) FROM pos_tenders t JOIN service_check_contexts cx ON cx.check_id=t.check_id AND cx.organization_id=t.organization_id WHERE t.organization_id=? AND cx.visit_group_id=? AND t.status='captured'");$q->execute([$org,$group]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Table transfer is locked after any check in this dining visit has captured payment.');
        $dest=service_ops_assert_table_operable($pdo,$org,(int)$check['location_id'],$destinationTablePublicId,true);$visitCheckIds=service_visit_group_check_ids($pdo,$org,$group,false);
        if(!$visitCheckIds)throw new RuntimeException('Dining visit has no checks.');$phChecks=implode(',',array_fill(0,count($visitCheckIds),'?'));
        $args=array_merge([$org],$visitCheckIds);$q=$pdo->prepare("SELECT COALESCE(SUM(guest_count),0) FROM pos_checks WHERE organization_id=? AND id IN ($phChecks) AND status IN ('open','paid')");$q->execute($args);$visitGuests=max(1,(int)$q->fetchColumn());
        if((int)$dest['capacity']<$visitGuests)throw new InvalidArgumentException((string)$dest['name'].' does not have enough capacity for the full '.$visitGuests.'-guest dining visit.');
        if($dest['active_check_id']!==null&&!in_array((int)$dest['active_check_id'],$visitCheckIds,true))throw new InvalidArgumentException('Destination table already has another active check.');
        $q=$pdo->prepare("SELECT id FROM service_tables WHERE organization_id=? AND location_id=? AND active_check_id IN ($phChecks) FOR UPDATE");$q->execute(array_merge([$org,(int)$check['location_id']],$visitCheckIds));$oldTableIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        foreach($oldTableIds as $tableId){if($tableId===(int)$dest['id'])continue;$pdo->prepare("UPDATE service_tables SET active_check_id=NULL,state='dirty',assigned_user_id=NULL,seated_at=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$userId,$org,$tableId]);}
        $pdo->prepare("UPDATE service_tables SET active_check_id=?,assigned_user_id=?,state='seated',seated_at=COALESCE(seated_at,NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([(int)$check['id'],$context['server_user_id'],$userId,$org,(int)$dest['id']]);
        $pdo->prepare('UPDATE service_check_contexts SET table_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND visit_group_id=?')->execute([(int)$dest['id'],$userId,$org,$group]);
        $pdo->prepare("UPDATE pos_checks c JOIN service_check_contexts cx ON cx.check_id=c.id AND cx.organization_id=c.organization_id SET c.table_name=?,c.revision=c.revision+1,c.updated_at=NOW(6) WHERE c.organization_id=? AND cx.visit_group_id=? AND c.status='open'")->execute([(string)$dest['name'],$org,$group]);
        $rq=$pdo->prepare("SELECT r.id FROM guest_reservations r JOIN service_check_contexts cx ON cx.check_id=r.seated_check_id AND cx.organization_id=r.organization_id WHERE r.organization_id=? AND cx.visit_group_id=? AND r.status='seated' FOR UPDATE");$rq->execute([$org,$group]);$reservationIds=array_map('intval',$rq->fetchAll(PDO::FETCH_COLUMN));
        foreach($reservationIds as $reservationId){$pdo->prepare('DELETE FROM guest_reservation_tables WHERE organization_id=? AND reservation_id=?')->execute([$org,$reservationId]);$pdo->prepare('INSERT INTO guest_reservation_tables (organization_id,reservation_id,service_table_id) VALUES (?,?,?)')->execute([$org,$reservationId,(int)$dest['id']]);host_reservation_event($pdo,$org,$reservationId,'table_transferred','Seated reservation moved with its dining visit.',['destinationTablePublicId'=>$destinationTablePublicId,'visitGroupId'=>$group],$userId);}
        table_service_event($pdo,$org,(int)$check['location_id'],(int)$dest['id'],(int)$check['id'],null,'visit_table_transferred','Dining visit transferred to a new table.',['fromTableIds'=>$oldTableIds,'toTableId'=>(int)$dest['id'],'visitGroupId'=>$group,'visitGuests'=>$visitGuests],$userId);
        return table_service_detail($pdo,$org,$checkPublicId);
    });
}

function service_visit_reconcile_reservations(PDO $pdo,int $org,int $locationId,int $userId): int
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$userId){
        $q=$pdo->prepare("SELECT r.id,r.seated_check_id FROM guest_reservations r WHERE r.organization_id=? AND r.location_id=? AND r.status='seated' AND r.seated_check_id IS NOT NULL FOR UPDATE");$q->execute([$org,$locationId]);$rows=$q->fetchAll();$changed=0;
        foreach($rows as $r){
            $cx=$pdo->prepare('SELECT visit_group_id FROM service_check_contexts WHERE organization_id=? AND check_id=? LIMIT 1');$cx->execute([$org,(int)$r['seated_check_id']]);$group=trim((string)($cx->fetchColumn()?:''));
            if($group===''){
                $s=$pdo->prepare('SELECT status FROM pos_checks WHERE organization_id=? AND id=? LIMIT 1');$s->execute([$org,(int)$r['seated_check_id']]);$status=(string)$s->fetchColumn();if(!in_array($status,['paid','cancelled'],true))continue;$newStatus=$status==='paid'?'completed':'cancelled';
            }else{
                $s=$pdo->prepare("SELECT SUM(c.status='open') open_count,SUM(c.status='paid') paid_count FROM service_check_contexts cx JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id WHERE cx.organization_id=? AND cx.visit_group_id=?");$s->execute([$org,$group]);$m=$s->fetch()?:[];if((int)($m['open_count']??0)>0){$open=$pdo->prepare("SELECT c.id FROM service_check_contexts cx JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id WHERE cx.organization_id=? AND cx.visit_group_id=? AND c.status='open' ORDER BY c.id LIMIT 1");$open->execute([$org,$group]);$openId=(int)($open->fetchColumn()?:0);if($openId>0&&(int)$r['seated_check_id']!==$openId)$pdo->prepare('UPDATE guest_reservations SET seated_check_id=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$openId,$userId,$org,(int)$r['id']]);continue;}$newStatus=(int)($m['paid_count']??0)>0?'completed':'cancelled';
            }
            $field=$newStatus==='completed'?'completed_at':'cancelled_at';$pdo->prepare("UPDATE guest_reservations SET status=?,{$field}=COALESCE({$field},NOW(6)),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$newStatus,$userId,$org,(int)$r['id']]);host_reservation_event($pdo,$org,(int)$r['id'],'visit_reconciled','Reservation reconciled after every check in the dining visit became terminal.',['reservationStatus'=>$newStatus,'visitGroupId'=>$group?:null],$userId);$changed++;
        }
        return $changed;
    });
}

function service_visit_crm_profile(PDO $pdo,int $org,string $publicId): array
{
    $profile=crm_profile($pdo,$org,$publicId);if(!service_visit_ready($pdo))return $profile;$customer=crm_customer_row($pdo,$org,$publicId);
    $q=$pdo->prepare("SELECT COUNT(DISTINCT CASE WHEN cx.visit_group_id IS NOT NULL AND cx.visit_group_id<>'' THEN CONCAT('visit:',cx.visit_group_id) ELSE CONCAT('check:',c.id) END) visits FROM pos_checks c LEFT JOIN service_check_contexts cx ON cx.organization_id=c.organization_id AND cx.check_id=c.id WHERE c.organization_id=? AND c.customer_id=? AND c.status='paid'");$q->execute([$org,(int)$customer['id']]);$profile['metrics']['visits']=(int)$q->fetchColumn();return $profile;
}

function service_visit_attach_customer(PDO $pdo,int $org,string $checkPublicId,?string $customerPublicId): ?array
{
    if(!service_visit_ready($pdo))return crm_attach_check($pdo,$org,$checkPublicId,$customerPublicId);
    return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$customerPublicId){
        $check=table_service_check_row($pdo,$org,$checkPublicId,true);if($check['status']!=='open')throw new InvalidArgumentException('Customer can only be changed on an open POS check.');
        $context=table_service_context($pdo,$org,(int)$check['id'],true);if(!$context)return crm_attach_check($pdo,$org,$checkPublicId,$customerPublicId);$group=service_visit_ensure_group($pdo,$org,(int)$check['id']);
        $customerId=null;if($customerPublicId!==null&&trim($customerPublicId)!==''){$c=crm_customer_row($pdo,$org,trim($customerPublicId));if($c['status']!=='active')throw new InvalidArgumentException('Archived customers cannot be attached to a POS check.');$customerId=(int)$c['id'];}
        $q=$pdo->prepare("SELECT DISTINCT c.customer_id FROM service_check_contexts cx JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id WHERE cx.organization_id=? AND cx.visit_group_id=? AND c.status='paid' AND c.customer_id IS NOT NULL");$q->execute([$org,$group]);$settled=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if(count($settled)>1||($settled&&$customerId!==$settled[0]))throw new InvalidArgumentException('Customer identity cannot be changed after another check in this dining visit has been paid.');
        $pdo->prepare("UPDATE pos_checks c JOIN service_check_contexts cx ON cx.check_id=c.id AND cx.organization_id=c.organization_id SET c.customer_id=?,c.revision=c.revision+1,c.updated_at=NOW(6) WHERE c.organization_id=? AND cx.visit_group_id=? AND c.status='open'")->execute([$customerId,$org,$group]);
        return crm_check_customer($pdo,$org,$checkPublicId);
    });
}
