<?php
declare(strict_types=1);

require_once __DIR__.'/service-ops-floor.php';
require_once __DIR__.'/service-ops-reservation.php';
require_once __DIR__.'/service-visit-live.php';
require_once __DIR__.'/table-cleaning-lifecycle.php';

function service_seatability_cleanup_minutes(): int
{
    return 15;
}

function service_seatability_state_message(string $state,string $tableName): string
{
    return match($state){
        'dirty'=>$tableName.' must be reset before seating.',
        'cleaning'=>$tableName.' is being cleaned and is not ready for seating yet.',
        'blocked'=>$tableName.' is blocked and cannot be seated.',
        'out_of_service'=>$tableName.' is out of service.',
        default=>$tableName.' is not in an Available state for seating.',
    };
}

function service_seatability_validate_locked_now(array $row): array
{
    if((string)$row['status']!=='active')throw new InvalidArgumentException((string)$row['name'].' is inactive.');
    if(empty($row['equipment_asset_id']))throw new InvalidArgumentException((string)$row['name'].' is not linked to a managed physical table asset. Sync it in Host Stand first.');
    if(!empty($row['asset_archived_at'])||(string)$row['asset_operational_status']!=='active'||(string)$row['asset_condition_status']==='poor'||(string)$row['state']==='out_of_service')throw new InvalidArgumentException((string)$row['name'].' is not physically available for service.');
    if($row['active_check_id']!==null)throw new InvalidArgumentException((string)$row['name'].' already has an active check.');
    $state=(string)$row['state'];
    if($state!=='available')throw new InvalidArgumentException(service_seatability_state_message($state,(string)$row['name']));
    return $row;
}

function service_seatability_assert_now(PDO $pdo,int $org,int $locationId,string $tablePublicId,bool $forUpdate=true): array
{
    $row=service_ops_assert_table_operable($pdo,$org,$locationId,$tablePublicId,$forUpdate);
    return service_seatability_validate_locked_now($row);
}

function service_seatability_candidate_allowed(PDO $pdo,int $org,int $locationId,array $row,DateTimeImmutable $start,DateTimeImmutable $now): bool
{
    if(!host_asset_available($row))return false;
    $state=(string)$row['state'];
    if(in_array($state,['blocked','out_of_service'],true))return false;
    if($row['active_check_id']!==null)return true;
    if($state==='available')return true;
    if(in_array($state,['dirty','cleaning'],true))return $start >= $now->modify('+'.service_seatability_cleanup_minutes().' minutes');
    return false;
}

function service_seatability_dashboard(PDO $pdo,int $org,int $locationId,string $date,int $userId): array
{
    $dashboard=service_visit_dashboard($pdo,$org,$locationId,$date,$userId);
    $dashboard=table_cleaning_enrich_map($pdo,$org,$locationId,$dashboard);
    foreach($dashboard['tables'] as &$table){
        $table['reservableNow']=!empty($table['physicalReady'])
            && $table['activeCheckId']===null
            && (string)$table['state']==='available';
    }
    unset($table);
    return $dashboard;
}

function service_seatability_availability(PDO $pdo,int $org,int $locationId,string $startAt,int $partySize,int $duration=90): array
{
    $local=service_ops_parse_local_datetime($pdo,$org,$locationId,$startAt);
    $tz=service_ops_timezone($pdo,$org,$locationId);
    $start=new DateTimeImmutable($local,$tz);
    $now=pos_clock($pdo,$org,$locationId);
    $candidates=service_ops_availability($pdo,$org,$locationId,$local,$partySize,$duration);
    $out=[];
    foreach($candidates as $candidate){
        $ok=true;
        foreach((array)($candidate['tables']??[]) as $tablePublicId){
            $row=host_table_row($pdo,$org,$locationId,(string)$tablePublicId,false);
            if(!service_seatability_candidate_allowed($pdo,$org,$locationId,$row,$start,$now)){$ok=false;break;}
        }
        if($ok)$out[]=$candidate;
    }
    return $out;
}

function service_seatability_party_seat(PDO $pdo,int $org,int $locationId,string $tablePublicId,int $partySize,?int $serverUserId,string $notes,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$partySize,$serverUserId,$notes,$userId){
        service_seatability_assert_now($pdo,$org,$locationId,$tablePublicId,true);
        return service_visit_seat($pdo,$org,$locationId,$tablePublicId,$partySize,$serverUserId,$notes,$userId);
    });
}

function service_seatability_transfer(PDO $pdo,int $org,string $checkPublicId,string $destinationTablePublicId,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$destinationTablePublicId,$userId){
        $check=table_service_check_row($pdo,$org,$checkPublicId,true);
        if($check['status']!=='open')throw new InvalidArgumentException('Only an open check can transfer tables.');
        service_visit_context($pdo,$org,(int)$check['id'],true);
        $group=service_visit_ensure_group($pdo,$org,(int)$check['id']);
        service_visit_lock_groups($pdo,$org,[$group]);
        service_seatability_assert_now($pdo,$org,(int)$check['location_id'],$destinationTablePublicId,true);
        return service_visit_transfer_safe($pdo,$org,$checkPublicId,$destinationTablePublicId,$userId);
    });
}

function service_seatability_combination_table_ids(PDO $pdo,int $org,int $locationId,string $combinationPublicId): array
{
    $q=$pdo->prepare("SELECT t.public_id
        FROM table_combinations c
        JOIN table_combination_members m ON m.combination_id=c.id
        JOIN service_tables t ON t.id=m.service_table_id AND t.organization_id=c.organization_id AND t.location_id=c.location_id
        WHERE c.organization_id=? AND c.location_id=? AND c.public_id=? AND c.status='active'
        ORDER BY m.is_primary DESC,m.sort_order,t.id");
    $q->execute([$org,$locationId,$combinationPublicId]);
    $ids=array_values(array_unique(array_filter(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)))));
    if(!$ids)throw new InvalidArgumentException('Active table combination was not found.');
    return $ids;
}

function service_seatability_assert_assignment_window(PDO $pdo,int $org,array $reservation,array $tablePublicIds,?array $lockedRows=null): void
{
    $locationId=(int)$reservation['location_id'];
    $ids=array_values(array_unique(array_filter(array_map('strval',$tablePublicIds))));
    if(!$ids)throw new InvalidArgumentException('Choose at least one table.');
    $locked=$lockedRows??service_ops_lock_tables_canonical($pdo,$org,$locationId,$ids);
    $tz=service_ops_timezone($pdo,$org,$locationId);
    $now=pos_clock($pdo,$org,$locationId);
    $scheduled=$reservation['scheduled_at']!==null?new DateTimeImmutable((string)$reservation['scheduled_at'],$tz):null;
    foreach($ids as $public){
        if(!isset($locked[$public]))throw new RuntimeException('A required table lock was not acquired.');
        $row=$locked[$public];
        if((string)$row['status']!=='active'||empty($row['equipment_asset_id'])||!empty($row['asset_archived_at'])||(string)$row['asset_operational_status']!=='active'||(string)$row['asset_condition_status']==='poor'||(string)$row['state']==='out_of_service')throw new InvalidArgumentException((string)$row['name'].' is not physically available for service.');
        $state=(string)$row['state'];
        if($scheduled===null){
            if($row['active_check_id']!==null)throw new InvalidArgumentException((string)$row['name'].' already has a seated party.');
            if($state!=='available')throw new InvalidArgumentException(service_seatability_state_message($state,(string)$row['name']));
            continue;
        }
        if(in_array($state,['blocked','out_of_service'],true))throw new InvalidArgumentException(service_seatability_state_message($state,(string)$row['name']));
        if($row['active_check_id']!==null){
            $q=$pdo->prepare('SELECT opened_at FROM pos_checks WHERE organization_id=? AND id=? LIMIT 1');
            $q->execute([$org,(int)$row['active_check_id']]);
            $opened=$q->fetchColumn();
            $projectedClear=$opened?new DateTimeImmutable((string)$opened,$tz):$now;
            if($scheduled<$projectedClear->modify('+120 minutes'))throw new InvalidArgumentException((string)$row['name'].' is projected to still be occupied at that reservation time.');
            continue;
        }
        if(in_array($state,['dirty','cleaning'],true)&&$scheduled<$now->modify('+'.service_seatability_cleanup_minutes().' minutes'))throw new InvalidArgumentException((string)$row['name'].' is still inside the table reset window for that reservation time.');
        if(!in_array($state,['available','dirty','cleaning'],true))throw new InvalidArgumentException(service_seatability_state_message($state,(string)$row['name']));
    }
}

function service_seatability_reservation_create(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$input,$userId){
        $tablePublicIds=array_values(array_unique(array_filter(array_map('strval',(array)($input['tablePublicIds']??[])))));
        $combinationPublicId=trim((string)($input['combinationPublicId']??''));
        if($combinationPublicId!=='')$tablePublicIds=service_seatability_combination_table_ids($pdo,$org,$locationId,$combinationPublicId);
        unset($input['tablePublicIds'],$input['combinationPublicId']);
        $reservation=service_ops_reservation_create($pdo,$org,$locationId,$input,$userId);
        if(!$tablePublicIds)return $reservation;
        return service_seatability_reservation_assign($pdo,$org,(string)$reservation['publicId'],$tablePublicIds,$userId);
    });
}

function service_seatability_reservation_assign(PDO $pdo,int $org,string $reservationPublicId,array $tablePublicIds,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$reservationPublicId,$tablePublicIds,$userId){
        $ids=array_values(array_unique(array_filter(array_map('strval',$tablePublicIds))));
        if(!$ids)throw new InvalidArgumentException('Choose at least one table.');
        $peek=host_reservation_row($pdo,$org,$reservationPublicId,false);
        $locationId=(int)$peek['location_id'];
        $locked=service_ops_lock_tables_canonical($pdo,$org,$locationId,$ids);
        $r=host_reservation_row($pdo,$org,$reservationPublicId,true);
        if((int)$r['location_id']!==$locationId)throw new RuntimeException('Reservation location changed during assignment.');
        service_seatability_assert_assignment_window($pdo,$org,$r,$ids,$locked);
        return service_ops_reservation_assign_safe($pdo,$org,$reservationPublicId,$ids,$userId);
    });
}

function service_seatability_reservation_update(PDO $pdo,int $org,string $reservationPublicId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$reservationPublicId,$input,$userId){
        $peek=host_reservation_row($pdo,$org,$reservationPublicId,false);
        $snapshotTables=host_reservation_tables($pdo,$org,(int)$peek['id']);
        $scheduledChanged=(string)$peek['reservation_type']==='reservation'&&array_key_exists('scheduledAt',$input);
        $snapshotIds=array_map('strval',array_column($snapshotTables,'publicId'));
        $locked=[];
        if($scheduledChanged&&$snapshotIds)$locked=service_ops_lock_tables_canonical($pdo,$org,(int)$peek['location_id'],$snapshotIds);

        $before=host_reservation_row($pdo,$org,$reservationPublicId,true);
        if($scheduledChanged){
            $currentIds=service_ops_current_reservation_table_ids($pdo,$org,(int)$before['location_id'],(int)$before['id']);
            if($currentIds!==$snapshotIds)throw new InvalidArgumentException('Table assignment changed while updating this reservation. Refresh and try again.');
        }else{
            $currentIds=$snapshotIds;
        }

        $updated=service_ops_reservation_update($pdo,$org,$reservationPublicId,$input,$userId);
        if($scheduledChanged&&$currentIds){
            $after=host_reservation_row($pdo,$org,$reservationPublicId,false);
            service_seatability_assert_assignment_window($pdo,$org,$after,$currentIds,$locked);
        }
        return $updated;
    });
}

function service_seatability_host_seat(PDO $pdo,int $org,string $reservationPublicId,?int $serverUserId,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$reservationPublicId,$serverUserId,$userId){
        $peek=host_reservation_row($pdo,$org,$reservationPublicId,false);
        $locationId=(int)$peek['location_id'];
        $snapshotTables=host_reservation_tables($pdo,$org,(int)$peek['id']);
        if(!$snapshotTables)throw new InvalidArgumentException('Assign a table before seating this party.');
        $snapshotIds=array_map('strval',array_column($snapshotTables,'publicId'));
        $locked=service_ops_lock_tables_canonical($pdo,$org,$locationId,$snapshotIds);

        $r=host_reservation_row($pdo,$org,$reservationPublicId,true);
        $currentIds=service_ops_current_reservation_table_ids($pdo,$org,$locationId,(int)$r['id']);
        if($currentIds!==$snapshotIds)throw new InvalidArgumentException('Table assignment changed while seating this party. Refresh and try again.');
        foreach($snapshotTables as $table)service_seatability_validate_locked_now($locked[(string)$table['publicId']]);
        return service_visit_host_seat($pdo,$org,$reservationPublicId,$serverUserId,$userId);
    });
}
