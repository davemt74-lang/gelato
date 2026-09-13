<?php
declare(strict_types=1);

require_once __DIR__.'/service-seatability.php';

function service_reservation_protection_setup_minutes(): int
{
    return 10;
}

function service_reservation_protection_grace_minutes(): int
{
    return 20;
}

function service_reservation_protection_turn_minutes(int $partySize): int
{
    $party=max(1,$partySize);
    if($party<=2)return 90;
    if($party<=4)return 105;
    return 120;
}

function service_reservation_protection_next(PDO $pdo,int $org,int $tableId,DateTimeImmutable $now): ?array
{
    $graceStart=$now->modify('-'.service_reservation_protection_grace_minutes().' minutes')->format('Y-m-d H:i:s');
    $q=$pdo->prepare("SELECT r.id,r.public_id,r.guest_name,r.party_size,r.scheduled_at,r.status
        FROM guest_reservation_tables rt
        JOIN guest_reservations r ON r.id=rt.reservation_id
        WHERE rt.service_table_id=?
          AND r.organization_id=?
          AND r.reservation_type='reservation'
          AND r.status IN ('booked','confirmed','arrived')
          AND r.scheduled_at IS NOT NULL
          AND (r.status='arrived' OR r.scheduled_at>=?)
        ORDER BY CASE WHEN r.status='arrived' THEN 0 ELSE 1 END,r.scheduled_at,r.id
        LIMIT 1");
    $q->execute([$tableId,$org,$graceStart]);
    $r=$q->fetch();
    if(!$r)return null;
    return [
        'id'=>(int)$r['id'],
        'publicId'=>(string)$r['public_id'],
        'guestName'=>(string)$r['guest_name'],
        'partySize'=>(int)$r['party_size'],
        'scheduledAt'=>(string)$r['scheduled_at'],
        'status'=>(string)$r['status'],
    ];
}

function service_reservation_protection_projected_clear(DateTimeImmutable $start,int $partySize): DateTimeImmutable
{
    return $start
        ->modify('+'.service_reservation_protection_turn_minutes($partySize).' minutes')
        ->modify('+'.service_seatability_cleanup_minutes().' minutes');
}

function service_reservation_protection_assert_window(PDO $pdo,int $org,int $locationId,array $table,int $partySize,DateTimeImmutable $projectedClear): ?array
{
    $now=pos_clock($pdo,$org,$locationId);
    $next=service_reservation_protection_next($pdo,$org,(int)$table['id'],$now);
    if(!$next)return null;
    $tz=service_ops_timezone($pdo,$org,$locationId);
    $reservationAt=new DateTimeImmutable((string)$next['scheduledAt'],$tz);
    $mustBeReadyBy=$projectedClear->modify('+'.service_reservation_protection_setup_minutes().' minutes');
    if($reservationAt<$mustBeReadyBy){
        throw new InvalidArgumentException((string)$table['name'].' is protected for the '.$reservationAt->format('g:i A').' reservation.');
    }
    return ['reservation'=>$next,'projectedClearAt'=>$projectedClear->format('Y-m-d H:i:s'),'mustBeReadyBy'=>$mustBeReadyBy->format('Y-m-d H:i:s')];
}

function service_reservation_protection_assert_walkin_tables(PDO $pdo,int $org,array $reservation,array $tablePublicIds): void
{
    if((string)$reservation['reservation_type']!=='waitlist')return;
    $locationId=(int)$reservation['location_id'];
    $party=max(1,(int)$reservation['party_size']);
    $projectedClear=service_reservation_protection_projected_clear(pos_clock($pdo,$org,$locationId),$party);
    $ids=array_values(array_unique(array_filter(array_map('strval',$tablePublicIds))));
    foreach($ids as $public){
        $table=service_seatability_assert_now($pdo,$org,$locationId,$public,true);
        service_reservation_protection_assert_window($pdo,$org,$locationId,$table,$party,$projectedClear);
    }
}

function service_reservation_protection_party_seat(PDO $pdo,int $org,int $locationId,string $tablePublicId,int $partySize,?int $serverUserId,string $notes,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$partySize,$serverUserId,$notes,$userId){
        $table=service_seatability_assert_now($pdo,$org,$locationId,$tablePublicId,true);
        $now=pos_clock($pdo,$org,$locationId);
        $projectedClear=service_reservation_protection_projected_clear($now,$partySize);
        service_reservation_protection_assert_window($pdo,$org,$locationId,$table,$partySize,$projectedClear);
        return service_visit_seat($pdo,$org,$locationId,$tablePublicId,$partySize,$serverUserId,$notes,$userId);
    });
}

function service_reservation_protection_visit_projection(PDO $pdo,int $org,string $visitGroupId,int $locationId): array
{
    $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN c.status='open' THEN c.guest_count ELSE 0 END),0) guests,MIN(c.opened_at) opened_at
        FROM service_check_contexts cx
        JOIN pos_checks c ON c.id=cx.check_id AND c.organization_id=cx.organization_id
        WHERE cx.organization_id=? AND cx.location_id=? AND cx.visit_group_id=?");
    $q->execute([$org,$locationId,$visitGroupId]);
    $r=$q->fetch()?:[];
    $party=max(1,(int)($r['guests']??0));
    $tz=service_ops_timezone($pdo,$org,$locationId);
    $start=!empty($r['opened_at'])?new DateTimeImmutable((string)$r['opened_at'],$tz):pos_clock($pdo,$org,$locationId);
    return ['partySize'=>$party,'projectedClear'=>service_reservation_protection_projected_clear($start,$party)];
}

function service_reservation_protection_transfer(PDO $pdo,int $org,string $checkPublicId,string $destinationTablePublicId,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$destinationTablePublicId,$userId){
        $check=table_service_check_row($pdo,$org,$checkPublicId,true);
        if($check['status']!=='open')throw new InvalidArgumentException('Only an open check can transfer tables.');
        service_visit_context($pdo,$org,(int)$check['id'],true);
        $group=service_visit_ensure_group($pdo,$org,(int)$check['id']);
        service_visit_lock_groups($pdo,$org,[$group]);
        $locationId=(int)$check['location_id'];
        $dest=service_seatability_assert_now($pdo,$org,$locationId,$destinationTablePublicId,true);
        $projection=service_reservation_protection_visit_projection($pdo,$org,$group,$locationId);
        service_reservation_protection_assert_window($pdo,$org,$locationId,$dest,(int)$projection['partySize'],$projection['projectedClear']);
        return service_visit_transfer_safe($pdo,$org,$checkPublicId,$destinationTablePublicId,$userId);
    });
}

function service_reservation_protection_reservation_create(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$locationId,$input,$userId){
        $reservation=service_seatability_reservation_create($pdo,$org,$locationId,$input,$userId);
        if((string)$reservation['type']==='waitlist'&&!empty($reservation['tables'])){
            $row=host_reservation_row($pdo,$org,(string)$reservation['publicId'],true);
            service_reservation_protection_assert_walkin_tables($pdo,$org,$row,array_column($reservation['tables'],'publicId'));
        }
        return $reservation;
    });
}

function service_reservation_protection_reservation_assign(PDO $pdo,int $org,string $reservationPublicId,array $tablePublicIds,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$reservationPublicId,$tablePublicIds,$userId){
        $row=host_reservation_row($pdo,$org,$reservationPublicId,true);
        if((string)$row['reservation_type']==='waitlist')service_reservation_protection_assert_walkin_tables($pdo,$org,$row,$tablePublicIds);
        return service_seatability_reservation_assign($pdo,$org,$reservationPublicId,$tablePublicIds,$userId);
    });
}

function service_reservation_protection_host_seat(PDO $pdo,int $org,string $reservationPublicId,?int $serverUserId,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$reservationPublicId,$serverUserId,$userId){
        $row=host_reservation_row($pdo,$org,$reservationPublicId,true);
        if((string)$row['reservation_type']==='waitlist'){
            $tables=host_reservation_tables($pdo,$org,(int)$row['id']);
            if(!$tables)throw new InvalidArgumentException('Assign a table before seating this party.');
            service_reservation_protection_assert_walkin_tables($pdo,$org,$row,array_column($tables,'publicId'));
        }
        return service_seatability_host_seat($pdo,$org,$reservationPublicId,$serverUserId,$userId);
    });
}

function service_reservation_protection_dashboard(PDO $pdo,int $org,int $locationId,string $date,int $userId): array
{
    $dashboard=service_seatability_dashboard($pdo,$org,$locationId,$date,$userId);
    $now=pos_clock($pdo,$org,$locationId);
    $tz=service_ops_timezone($pdo,$org,$locationId);
    foreach($dashboard['tables'] as &$table){
        $table['reservationProtected']=false;
        $table['nextReservationAt']=null;
        $table['nextReservationPublicId']=null;
        if(empty($table['reservableNow']))continue;
        $next=service_reservation_protection_next($pdo,$org,(int)$table['id'],$now);
        if(!$next)continue;
        $table['nextReservationAt']=$next['scheduledAt'];
        $table['nextReservationPublicId']=$next['publicId'];
        $projectedClear=service_reservation_protection_projected_clear($now,min(2,max(1,(int)$table['capacity'])));
        $mustBeReadyBy=$projectedClear->modify('+'.service_reservation_protection_setup_minutes().' minutes');
        $reservationAt=new DateTimeImmutable((string)$next['scheduledAt'],$tz);
        if($reservationAt<$mustBeReadyBy){
            $table['reservationProtected']=true;
            $table['reservableNow']=false;
        }
    }
    unset($table);
    return $dashboard;
}
