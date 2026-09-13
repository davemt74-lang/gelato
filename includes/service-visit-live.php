<?php
declare(strict_types=1);

require_once __DIR__.'/service-visit-extensions.php';
require_once __DIR__.'/service-ops-floor.php';

function service_visit_reconcile_live(PDO $pdo,int $org,int $locationId,int $userId): void
{
    service_visit_reconcile_tables($pdo,$org,$locationId,$userId);
    service_visit_reconcile_reservations($pdo,$org,$locationId,$userId);
}

function service_visit_merge_safe(PDO $pdo,int $org,string $sourceCheckPublicId,string $targetCheckPublicId,int $userId): array
{
    if($sourceCheckPublicId===$targetCheckPublicId)throw new InvalidArgumentException('Choose two different checks to merge.');
    return table_service_transaction($pdo,function()use($pdo,$org,$sourceCheckPublicId,$targetCheckPublicId,$userId){
        $source=table_service_check_row($pdo,$org,$sourceCheckPublicId,true);$target=table_service_check_row($pdo,$org,$targetCheckPublicId,true);
        if($source['status']!=='open'||$target['status']!=='open')throw new InvalidArgumentException('Both checks must be open.');
        if((int)$source['location_id']!==(int)$target['location_id'])throw new InvalidArgumentException('Checks at different locations cannot be merged.');
        service_visit_context($pdo,$org,(int)$source['id'],true);service_visit_context($pdo,$org,(int)$target['id'],true);
        $sourceGroup=service_visit_ensure_group($pdo,$org,(int)$source['id']);$targetGroup=service_visit_ensure_group($pdo,$org,(int)$target['id']);
        $groups=array_values(array_unique([$sourceGroup,$targetGroup]));$ph=implode(',',array_fill(0,count($groups),'?'));
        $q=$pdo->prepare("SELECT COUNT(*) FROM pos_tenders t JOIN service_check_contexts cx ON cx.check_id=t.check_id AND cx.organization_id=t.organization_id WHERE t.organization_id=? AND cx.visit_group_id IN ($ph) AND t.status='captured'");$q->execute(array_merge([$org],$groups));
        if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('Checks cannot be merged after any check in either dining visit has captured payment.');
        $customers=service_visit_group_customer_ids($pdo,$org,$groups);if(count($customers)>1)throw new InvalidArgumentException('Checks linked to different CRM customers cannot be merged.');$customerId=$customers[0]??null;
        $result=service_visit_merge($pdo,$org,$sourceCheckPublicId,$targetCheckPublicId,$userId);
        $targetRow=table_service_check_row($pdo,$org,$targetCheckPublicId,false);$group=service_visit_ensure_group($pdo,$org,(int)$targetRow['id']);
        $pdo->prepare("UPDATE pos_checks c JOIN service_check_contexts cx ON cx.check_id=c.id AND cx.organization_id=c.organization_id SET c.customer_id=?,c.revision=c.revision+1,c.updated_at=NOW(6) WHERE c.organization_id=? AND cx.visit_group_id=? AND c.status='open'")->execute([$customerId,$org,$group]);
        $pdo->prepare("UPDATE guest_reservations r JOIN service_check_contexts cx ON cx.check_id=r.seated_check_id AND cx.organization_id=r.organization_id SET r.customer_id=COALESCE(r.customer_id,?),r.updated_at=NOW(6) WHERE r.organization_id=? AND cx.visit_group_id=? AND r.status='seated'")->execute([$customerId,$org,$group]);
        return table_service_detail($pdo,$org,$targetCheckPublicId);
    });
}
