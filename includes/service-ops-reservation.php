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

function service_ops_current_scheduled_conflict(PDO $pdo,int $org,int $tableId,array $reservation): ?int
{
    if($reservation['scheduled_at']===null)return null;
    $start=(string)$reservation['scheduled_at'];
    $end=(new DateTimeImmutable($start))->modify('+'.max(15,(int)$reservation['duration_minutes']).' minutes')->format('Y-m-d H:i:s');
    $q=$pdo->prepare("SELECT other.id
        FROM guest_reservation_tables rt
        JOIN guest_reservations other ON other.id=rt.reservation_id
        WHERE rt.service_table_id=?
          AND other.organization_id=?
          AND other.id<>?
          AND other.status IN ('booked','confirmed','arrived')
          AND other.scheduled_at IS NOT NULL
          AND other.scheduled_at < ?
          AND DATE_ADD(other.scheduled_at,INTERVAL other.duration_minutes MINUTE) > ?
        ORDER BY other.scheduled_at,other.id
        LIMIT 1 FOR UPDATE");
    $q->execute([$tableId,$org,(int)$reservation['id'],$end,$start]);
    $id=$q->fetchColumn();
    return $id===false?null:(int)$id;
}

function service_ops_current_waitlist_conflict(PDO $pdo,int $org,int $tableId,int $reservationId): ?int
{
    $q=$pdo->prepare("SELECT other.id
        FROM guest_reservation_tables rt
        JOIN guest_reservations other ON other.id=rt.reservation_id
        WHERE rt.service_table_id=?
          AND other.organization_id=?
          AND other.id<>?
          AND other.status IN ('waiting','arrived')
          AND other.scheduled_at IS NULL
        ORDER BY other.id
        LIMIT 1 FOR UPDATE");
    $q->execute([$tableId,$org,$reservationId]);
    $id=$q->fetchColumn();
    return $id===false?null:(int)$id;
}

function service_ops_reservation_assign_safe(PDO $pdo,int $org,string $publicId,array $tablePublicIds,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$publicId,$tablePublicIds,$userId){
        $ids=array_values(array_unique(array_filter(array_map('strval',$tablePublicIds))));
        if(!$ids)throw new InvalidArgumentException('Choose at least one table.');

        // Global concurrency order for reservation/table mutations:
        // physical table rows -> reservation row -> conflicting reservation rows.
        // The initial reservation read is intentionally non-locking and is used only
        // to discover the immutable location needed to acquire the table locks.
        $peek=host_reservation_row($pdo,$org,$publicId,false);
        $locationId=(int)$peek['location_id'];
        $locked=service_ops_lock_tables_canonical($pdo,$org,$locationId,$ids);
        $r=host_reservation_row($pdo,$org,$publicId,true);
        if((int)$r['location_id']!==$locationId)throw new RuntimeException('Reservation location changed during assignment.');

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
                if(service_ops_current_waitlist_conflict($pdo,$org,(int)$table['id'],(int)$r['id'])!==null)throw new InvalidArgumentException((string)$table['name'].' is already assigned to another active waitlist party.');
            }elseif(service_ops_current_scheduled_conflict($pdo,$org,(int)$table['id'],$r)!==null){
                throw new InvalidArgumentException((string)$table['name'].' conflicts with another reservation.');
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
