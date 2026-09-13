<?php
declare(strict_types=1);

require_once __DIR__.'/service-ops-hardening.php';

function service_ops_reservation_status_atomic(PDO $pdo,int $org,string $publicId,string $target,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$publicId,$target,$userId){
        $r=host_reservation_row($pdo,$org,$publicId,true);$current=(string)$r['status'];$type=(string)$r['reservation_type'];
        if($target===$current)return host_reservation_payload($pdo,$org,$r);
        $map=$type==='waitlist'
            ?['waiting'=>['arrived','cancelled'],'arrived'=>['cancelled']]
            :['booked'=>['confirmed','arrived','cancelled','no_show'],'confirmed'=>['arrived','cancelled','no_show'],'arrived'=>['cancelled','no_show']];
        if(!in_array($target,$map[$current]??[],true))throw new InvalidArgumentException('That reservation status transition is not allowed.');
        $sets=['status=?','updated_by=?','updated_at=NOW(6)'];$args=[$target,$userId];
        if($target==='arrived')$sets[]='arrived_at=COALESCE(arrived_at,NOW(6))';
        if($target==='cancelled')$sets[]='cancelled_at=COALESCE(cancelled_at,NOW(6))';
        if($target==='no_show')$sets[]='no_show_at=COALESCE(no_show_at,NOW(6))';
        $args[]=$org;$args[]=(int)$r['id'];
        $pdo->prepare('UPDATE guest_reservations SET '.implode(',',$sets).' WHERE organization_id=? AND id=?')->execute($args);
        host_reservation_event($pdo,$org,(int)$r['id'],'status_changed','Reservation status changed.',['from'=>$current,'to'=>$target],$userId);
        return host_reservation_payload($pdo,$org,host_reservation_row($pdo,$org,$publicId,false));
    });
}

function service_ops_host_seat(PDO $pdo,int $org,string $reservationPublicId,?int $serverUserId,int $userId): array
{
    $r=host_reservation_row($pdo,$org,$reservationPublicId,false);$locationId=(int)$r['location_id'];$tables=host_reservation_tables($pdo,$org,(int)$r['id']);
    if(!$tables)throw new InvalidArgumentException('Assign a table before seating this party.');
    $primary=service_ops_assert_table_operable($pdo,$org,$locationId,(string)$tables[0]['publicId'],false);
    if($serverUserId!==null&&$serverUserId>0)service_ops_staff_at_location($pdo,$org,$locationId,$serverUserId);
    else $serverUserId=service_ops_default_server($pdo,$org,$locationId,$primary['section_id']!==null?(int)$primary['section_id']:null,$userId);
    return host_seat($pdo,$org,$reservationPublicId,$serverUserId,$userId);
}

function service_ops_assign_server(PDO $pdo,int $org,string $checkPublicId,int $serverUserId,int $userId): array
{
    $check=table_service_check_row($pdo,$org,$checkPublicId,false);service_ops_staff_at_location($pdo,$org,(int)$check['location_id'],$serverUserId);
    return table_service_assign_server($pdo,$org,$checkPublicId,$serverUserId,$userId);
}

function service_ops_table_state(PDO $pdo,int $org,string $tablePublicId,string $state,int $userId): array
{
    $q=$pdo->prepare('SELECT location_id FROM service_tables WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,$tablePublicId]);$locationId=(int)$q->fetchColumn();if(!$locationId)throw new InvalidArgumentException('Table was not found.');
    if($state==='available')service_ops_assert_table_operable($pdo,$org,$locationId,$tablePublicId,false);
    return table_service_table_state($pdo,$org,$tablePublicId,$state,$userId);
}
