<?php
declare(strict_types=1);

require_once __DIR__.'/service-ops-atomic.php';

function service_ops_order_table_ids(PDO $pdo,int $org,int $locationId,array $tablePublicIds): array
{
    $ids=array_values(array_unique(array_filter(array_map('strval',$tablePublicIds))));
    if(!$ids)return [];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT public_id FROM service_tables WHERE organization_id=? AND location_id=? AND public_id IN ($ph) ORDER BY id");
    $q->execute(array_merge([$org,$locationId],$ids));
    $ordered=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));
    if(count($ordered)!==count($ids))throw new InvalidArgumentException('One or more service tables were not found.');
    return $ordered;
}

function service_ops_lock_tables_canonical(PDO $pdo,int $org,int $locationId,array $tablePublicIds): array
{
    $rows=[];
    foreach(service_ops_order_table_ids($pdo,$org,$locationId,$tablePublicIds) as $public)$rows[$public]=host_table_row($pdo,$org,$locationId,$public,true);
    return $rows;
}

function service_ops_reservation_assign_safe(PDO $pdo,int $org,string $publicId,array $tablePublicIds,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$publicId,$tablePublicIds,$userId){
        $r=host_reservation_row($pdo,$org,$publicId,true);$locationId=(int)$r['location_id'];$ids=array_values(array_unique(array_filter(array_map('strval',$tablePublicIds))));
        if(!$ids)throw new InvalidArgumentException('Choose at least one table.');
        $locked=service_ops_lock_tables_canonical($pdo,$org,$locationId,$ids);
        $tz=service_ops_timezone($pdo,$org,$locationId);$scheduled=$r['scheduled_at']!==null?new DateTimeImmutable((string)$r['scheduled_at'],$tz):null;
        foreach($ids as $public){
            $table=$locked[$public];
            if(!host_asset_available($table))throw new InvalidArgumentException((string)$table['name'].' is not available as a physical table asset.');
            if($table['active_check_id']!==null){
                if($scheduled===null)throw new InvalidArgumentException((string)$table['name'].' already has a seated party.');
                $q=$pdo->prepare('SELECT opened_at FROM pos_checks WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,(int)$table['active_check_id']]);$opened=$q->fetchColumn();$projectedClear=$opened?new DateTimeImmutable((string)$opened,$tz):pos_clock($pdo,$org,$locationId);$projectedClear=$projectedClear->modify('+120 minutes');
                if($scheduled<$projectedClear)throw new InvalidArgumentException((string)$table['name'].' is projected to still be occupied at that reservation time.');
            }
            if($scheduled===null){
                $q=$pdo->prepare("SELECT COUNT(*) FROM guest_reservation_tables rt JOIN guest_reservations other ON other.id=rt.reservation_id WHERE rt.service_table_id=? AND other.organization_id=? AND other.id<>? AND other.status IN ('waiting','arrived') AND other.scheduled_at IS NULL");
                $q->execute([(int)$table['id'],$org,(int)$r['id']]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException((string)$table['name'].' is already assigned to another active waitlist party.');
            }
        }
        return host_reservation_assign($pdo,$org,$publicId,$ids,$userId);
    });
}

function service_ops_combination_save(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$input,$userId){
        $ids=array_values(array_unique(array_filter(array_map('strval',(array)($input['tablePublicIds']??[])))));if(count($ids)<2)throw new InvalidArgumentException('Choose at least two tables for a combination.');
        $locked=service_ops_lock_tables_canonical($pdo,$org,$locationId,$ids);
        foreach($ids as $public){$row=$locked[$public];if(empty($row['equipment_asset_id']))throw new InvalidArgumentException((string)$row['name'].' must be a managed physical table before it can be combined.');}
        return host_combination_save($pdo,$org,$locationId,$input,$userId);
    });
}
