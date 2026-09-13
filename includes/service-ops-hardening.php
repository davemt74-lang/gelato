<?php
declare(strict_types=1);

require_once __DIR__.'/host-stand-core.php';
require_once __DIR__.'/table-service-core.php';

function service_ops_timezone(PDO $pdo,int $org,int $locationId): DateTimeZone
{
    $location=pos_location($pdo,$org,$locationId);
    try{return new DateTimeZone((string)$location['timezone']);}catch(Throwable){return new DateTimeZone('America/Phoenix');}
}

function service_ops_business_date(PDO $pdo,int $org,int $locationId): string
{
    return pos_clock($pdo,$org,$locationId)->format('Y-m-d');
}

function service_ops_parse_local_datetime(PDO $pdo,int $org,int $locationId,string $value): string
{
    $value=trim($value);
    if($value==='')throw new InvalidArgumentException('Reservation date and time are required.');
    $tz=service_ops_timezone($pdo,$org,$locationId);
    $formats=['!Y-m-d\TH:i','!Y-m-d H:i','!Y-m-d H:i:s'];
    foreach($formats as $format){
        $d=DateTimeImmutable::createFromFormat($format,$value,$tz);
        if($d instanceof DateTimeImmutable){
            $errors=DateTimeImmutable::getLastErrors();
            if($errors===false||(($errors['warning_count']??0)===0&&($errors['error_count']??0)===0))return $d->format('Y-m-d H:i:s');
        }
    }
    throw new InvalidArgumentException('Reservation date and time are invalid.');
}

function service_ops_table_row(PDO $pdo,int $org,int $locationId,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT t.*,a.public_id asset_public_id,a.operational_status asset_operational_status,a.condition_status asset_condition_status,a.archived_at asset_archived_at
          FROM service_tables t
          LEFT JOIN equipment_assets a ON a.id=t.equipment_asset_id AND a.organization_id=t.organization_id
          WHERE t.organization_id=? AND t.location_id=? AND t.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$locationId,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Service table was not found.');
    return $row;
}

function service_ops_assert_table_operable(PDO $pdo,int $org,int $locationId,string $publicId,bool $forUpdate=false): array
{
    $row=service_ops_table_row($pdo,$org,$locationId,$publicId,$forUpdate);
    if((string)$row['status']!=='active')throw new InvalidArgumentException((string)$row['name'].' is inactive.');
    if(empty($row['equipment_asset_id']))throw new InvalidArgumentException((string)$row['name'].' is not linked to a managed physical table asset. Sync it in Host Stand first.');
    if(!empty($row['asset_archived_at'])||(string)$row['asset_operational_status']!=='active'||(string)$row['asset_condition_status']==='poor'||(string)$row['state']==='out_of_service')throw new InvalidArgumentException((string)$row['name'].' is not physically available for service.');
    return $row;
}

function service_ops_staff_at_location(PDO $pdo,int $org,int $locationId,int $userId): array
{
    $q=$pdo->prepare("SELECT DISTINCT u.id,u.display_name,m.job_title
        FROM organization_memberships m
        JOIN users u ON u.id=m.user_id
        LEFT JOIN user_roles ur ON ur.membership_id=m.id AND ur.revoked_at IS NULL
        WHERE m.organization_id=? AND u.id=? AND m.status='active' AND u.status<>'archived'
          AND (m.primary_location_id IS NULL OR m.primary_location_id=? OR ur.location_id=? OR ur.location_id IS NULL)
        LIMIT 1");
    $q->execute([$org,$userId,$locationId,$locationId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('That staff member is not active at this location.');
    return ['id'=>(int)$row['id'],'name'=>(string)$row['display_name'],'jobTitle'=>$row['job_title']];
}

function service_ops_default_server(PDO $pdo,int $org,int $locationId,?int $sectionId,int $actorUserId): int
{
    if($sectionId!==null){
        $q=$pdo->prepare('SELECT assigned_user_id FROM service_section_assignments WHERE organization_id=? AND location_id=? AND section_id=? AND business_date=? LIMIT 1');
        $q->execute([$org,$locationId,$sectionId,service_ops_business_date($pdo,$org,$locationId)]);$assigned=(int)($q->fetchColumn()?:0);
        if($assigned>0){service_ops_staff_at_location($pdo,$org,$locationId,$assigned);return $assigned;}
    }
    service_ops_staff_at_location($pdo,$org,$locationId,$actorUserId);
    return $actorUserId;
}

function service_ops_sections_today(PDO $pdo,int $org,int $locationId,bool $activeOnly=true): array
{
    $sql='SELECT s.id,s.public_id,s.name,s.sort_order,s.status,a.assigned_user_id,u.display_name assigned_user_name FROM service_sections s LEFT JOIN service_section_assignments a ON a.section_id=s.id AND a.organization_id=s.organization_id AND a.business_date=? LEFT JOIN users u ON u.id=a.assigned_user_id WHERE s.organization_id=? AND s.location_id=?';
    if($activeOnly)$sql.=" AND s.status='active'";
    $sql.=' ORDER BY s.sort_order,s.name,s.id';$q=$pdo->prepare($sql);$q->execute([service_ops_business_date($pdo,$org,$locationId),$org,$locationId]);
    return array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'publicId'=>(string)$r['public_id'],'name'=>(string)$r['name'],'sortOrder'=>(int)$r['sort_order'],'status'=>(string)$r['status'],'assignedUserId'=>$r['assigned_user_id']!==null?(int)$r['assigned_user_id']:null,'assignedUserName'=>$r['assigned_user_name']],$q->fetchAll());
}

function service_ops_map(PDO $pdo,int $org,int $locationId,bool $activeOnly=true): array
{
    $map=table_service_map($pdo,$org,$locationId,$activeOnly);
    $map['sections']=service_ops_sections_today($pdo,$org,$locationId,$activeOnly);
    if(host_ready($pdo)){
        $physical=[];foreach(host_table_rows($pdo,$org,$locationId,$activeOnly) as $row)$physical[$row['publicId']]=$row;
        foreach($map['tables'] as &$table){
            $p=$physical[$table['publicId']]??null;
            $table['managedAsset']=$p?($p['managedAsset']??false):false;
            $table['physicalReady']=$p?($p['physicalReady']??false):false;
            if($p&&isset($p['asset']))$table['asset']=$p['asset'];
        }unset($table);
    }
    return $map;
}

function service_ops_seat(PDO $pdo,int $org,int $locationId,string $tablePublicId,int $partySize,?int $serverUserId,string $notes,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$partySize,$serverUserId,$notes,$userId){
        $table=service_ops_assert_table_operable($pdo,$org,$locationId,$tablePublicId,true);
        if($table['active_check_id']!==null)throw new InvalidArgumentException('This table already has an active check.');
        if($serverUserId!==null&&$serverUserId>0)service_ops_staff_at_location($pdo,$org,$locationId,$serverUserId);
        else $serverUserId=service_ops_default_server($pdo,$org,$locationId,$table['section_id']!==null?(int)$table['section_id']:null,$userId);
        return table_service_seat($pdo,$org,$locationId,$tablePublicId,$partySize,$serverUserId,$notes,$userId);
    });
}

function service_ops_transfer(PDO $pdo,int $org,string $checkPublicId,string $destinationTablePublicId,int $userId): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$checkPublicId,$destinationTablePublicId,$userId){
        $check=table_service_check_row($pdo,$org,$checkPublicId,true);
        service_ops_assert_table_operable($pdo,$org,(int)$check['location_id'],$destinationTablePublicId,true);
        return table_service_transfer($pdo,$org,$checkPublicId,$destinationTablePublicId,$userId);
    });
}

function service_ops_split(PDO $pdo,int $org,string $sourceCheckPublicId,array $itemIds,int $userId,?int $movedGuestCount=null): array
{
    return table_service_transaction($pdo,function()use($pdo,$org,$sourceCheckPublicId,$itemIds,$userId,$movedGuestCount){
        $source=table_service_check_row($pdo,$org,$sourceCheckPublicId,true);$oldGuests=max(0,(int)$source['guest_count']);
        $result=table_service_split($pdo,$org,$sourceCheckPublicId,$itemIds,$userId);
        $createdId=(int)$result['created']['id'];$sourceId=(int)$result['source']['id'];
        if($movedGuestCount===null){
            $q=$pdo->prepare("SELECT COUNT(DISTINCT moved.seat_number) FROM pos_check_items moved WHERE moved.organization_id=? AND moved.check_id=? AND moved.status='active' AND moved.seat_number IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pos_check_items remain WHERE remain.organization_id=moved.organization_id AND remain.check_id=? AND remain.status='active' AND remain.seat_number=moved.seat_number)");
            $q->execute([$org,$createdId,$sourceId]);$movedGuestCount=(int)$q->fetchColumn();
        }
        $movedGuestCount=max(0,min($oldGuests,$movedGuestCount));$remaining=max(0,$oldGuests-$movedGuestCount);
        $pdo->prepare('UPDATE pos_checks SET guest_count=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$movedGuestCount,$org,$createdId]);
        $pdo->prepare('UPDATE pos_checks SET guest_count=?,revision=revision+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$remaining,$org,$sourceId]);
        $pdo->prepare('UPDATE service_check_contexts SET party_size=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=?')->execute([$movedGuestCount,$userId,$org,$createdId]);
        $pdo->prepare('UPDATE service_check_contexts SET party_size=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND check_id=?')->execute([$remaining,$userId,$org,$sourceId]);
        table_service_event($pdo,$org,(int)$source['location_id'],null,$sourceId,null,'split_cover_allocation','Guest covers allocated across split checks.',['sourceGuests'=>$remaining,'createdGuests'=>$movedGuestCount,'originalGuests'=>$oldGuests],$userId);
        return ['source'=>table_service_detail($pdo,$org,$sourceCheckPublicId),'created'=>table_service_detail($pdo,$org,(string)$result['created']['publicId'])];
    });
}

function service_ops_reservation_conflict(PDO $pdo,int $org,int $tableId,string $start,string $end,?int $excludeId=null): bool
{
    $sql="SELECT COUNT(*) FROM guest_reservation_tables rt JOIN guest_reservations r ON r.id=rt.reservation_id WHERE rt.service_table_id=? AND r.organization_id=? AND r.status IN ('booked','confirmed','arrived') AND r.scheduled_at IS NOT NULL AND r.scheduled_at < ? AND DATE_ADD(r.scheduled_at,INTERVAL r.duration_minutes MINUTE) > ?";
    $args=[$tableId,$org,$end,$start];if($excludeId!==null){$sql.=' AND r.id<>?';$args[]=$excludeId;}$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn()>0;
}

function service_ops_availability(PDO $pdo,int $org,int $locationId,string $startAt,int $partySize,int $duration=90): array
{
    $local=service_ops_parse_local_datetime($pdo,$org,$locationId,$startAt);$tz=service_ops_timezone($pdo,$org,$locationId);$start=new DateTimeImmutable($local,$tz);$end=$start->modify('+'.max(15,min(480,$duration)).' minutes');$party=max(1,min(99,$partySize));$available=[];
    $now=pos_clock($pdo,$org,$locationId);
    foreach(host_table_rows($pdo,$org,$locationId,true) as $t){
        if(!$t['physicalReady']||(int)$t['capacity']<$party)continue;
        if($t['activeCheckId']!==null){$opened=$t['openedAt']?new DateTimeImmutable((string)$t['openedAt'],$tz):$now;$projectedClear=$opened->modify('+120 minutes');if($start<$projectedClear)continue;}
        if(service_ops_reservation_conflict($pdo,$org,(int)$t['id'],$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')))continue;
        $available[]=['type'=>'table','publicId'=>$t['publicId'],'name'=>$t['name'],'capacity'=>$t['capacity'],'tables'=>[$t['publicId']]];
    }
    foreach(host_combinations($pdo,$org,$locationId) as $c){
        if($c['capacity']<$party)continue;$ok=true;
        foreach($c['tables'] as $member){
            $row=host_table_row($pdo,$org,$locationId,(string)$member['publicId'],false);if(!host_asset_available($row)){$ok=false;break;}
            if($row['active_check_id']!==null){$q=$pdo->prepare('SELECT opened_at FROM pos_checks WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,(int)$row['active_check_id']]);$opened=$q->fetchColumn();$projectedClear=$opened?new DateTimeImmutable((string)$opened,$tz):$now;$projectedClear=$projectedClear->modify('+120 minutes');if($start<$projectedClear){$ok=false;break;}}
            if(service_ops_reservation_conflict($pdo,$org,(int)$member['id'],$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'))){$ok=false;break;}
        }
        if($ok)$available[]=['type'=>'combination','publicId'=>$c['publicId'],'name'=>$c['name'],'capacity'=>$c['capacity'],'tables'=>array_column($c['tables'],'publicId')];
    }
    usort($available,static fn(array $a,array $b):int=>($a['capacity']<=>$b['capacity'])?:strcmp($a['name'],$b['name']));return $available;
}

function service_ops_reservation_create(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    if((string)($input['type']??'reservation')==='reservation')$input['scheduledAt']=service_ops_parse_local_datetime($pdo,$org,$locationId,(string)($input['scheduledAt']??''));
    return host_reservation_save($pdo,$org,$locationId,$input,$userId);
}

function service_ops_reservation_status(PDO $pdo,int $org,string $publicId,string $target,int $userId): array
{
    $r=host_reservation_row($pdo,$org,$publicId,true);$current=(string)$r['status'];$type=(string)$r['reservation_type'];
    if($target===$current)return host_reservation_payload($pdo,$org,$r);
    $map=$type==='waitlist'
        ?['waiting'=>['arrived','cancelled'],'arrived'=>['cancelled']]
        :['booked'=>['confirmed','arrived','cancelled','no_show'],'confirmed'=>['arrived','cancelled','no_show'],'arrived'=>['cancelled','no_show']];
    if(!in_array($target,$map[$current]??[],true))throw new InvalidArgumentException('That reservation status transition is not allowed.');
    return host_reservation_status($pdo,$org,$publicId,$target,$userId);
}

function service_ops_reservation_assign(PDO $pdo,int $org,string $publicId,array $tablePublicIds,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$publicId,$tablePublicIds,$userId){
        $r=host_reservation_row($pdo,$org,$publicId,true);
        if($r['scheduled_at']===null){
            foreach(array_values(array_unique(array_filter(array_map('strval',$tablePublicIds)))) as $public){
                $table=host_table_row($pdo,$org,(int)$r['location_id'],$public,true);if(!host_asset_available($table))throw new InvalidArgumentException((string)$table['name'].' is not available as a physical table asset.');
                $q=$pdo->prepare("SELECT COUNT(*) FROM guest_reservation_tables rt JOIN guest_reservations other ON other.id=rt.reservation_id WHERE rt.service_table_id=? AND other.organization_id=? AND other.id<>? AND other.status IN ('waiting','arrived') AND other.scheduled_at IS NULL");
                $q->execute([(int)$table['id'],$org,(int)$r['id']]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException((string)$table['name'].' is already assigned to another active waitlist party.');
            }
        }
        return host_reservation_assign($pdo,$org,$publicId,$tablePublicIds,$userId);
    });
}

function service_ops_reservation_update(PDO $pdo,int $org,string $publicId,array $input,int $userId): array
{
    return host_transaction($pdo,function()use($pdo,$org,$publicId,$input,$userId){
        $r=host_reservation_row($pdo,$org,$publicId,true);if(in_array((string)$r['status'],['seated','completed','cancelled','no_show'],true))throw new InvalidArgumentException('A terminal reservation cannot be edited.');
        $guest=mb_substr(trim((string)($input['guestName']??$r['guest_name'])),0,180,'UTF-8');if($guest==='')throw new InvalidArgumentException('Guest name is required.');
        $email=array_key_exists('guestEmail',$input)?host_nullable((string)$input['guestEmail'],320):$r['guest_email'];if($email!==null&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Guest email address is invalid.');
        $phone=array_key_exists('guestPhone',$input)?host_nullable((string)$input['guestPhone'],64):$r['guest_phone'];$party=max(1,min(99,(int)($input['partySize']??$r['party_size'])));$duration=max(15,min(480,(int)($input['durationMinutes']??$r['duration_minutes'])));$notes=array_key_exists('notes',$input)?host_nullable((string)$input['notes'],8000):$r['notes'];
        $scheduled=$r['scheduled_at'];if((string)$r['reservation_type']==='reservation'&&array_key_exists('scheduledAt',$input))$scheduled=service_ops_parse_local_datetime($pdo,$org,(int)$r['location_id'],(string)$input['scheduledAt']);
        $tables=host_reservation_tables($pdo,$org,(int)$r['id']);if($tables)host_validate_assignment($pdo,$org,(int)$r['location_id'],array_column($tables,'publicId'),$party,$scheduled,$duration,(int)$r['id']);
        $pdo->prepare('UPDATE guest_reservations SET guest_name=?,guest_email=?,guest_phone=?,party_size=?,scheduled_at=?,duration_minutes=?,notes=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$guest,$email,$phone,$party,$scheduled,$duration,$notes,$userId,$org,(int)$r['id']]);
        host_reservation_event($pdo,$org,(int)$r['id'],'updated','Reservation details updated.',['partySize'=>$party,'scheduledAt'=>$scheduled,'durationMinutes'=>$duration],$userId);
        return host_reservation_payload($pdo,$org,host_reservation_row($pdo,$org,$publicId,false));
    });
}

function service_ops_managed_table_create(PDO $pdo,int $org,int $locationId,array $input,int $userId): array
{
    if(trim((string)($input['assetTag']??''))===''){
        $name=preg_replace('/[^A-Z0-9]+/','-',strtoupper(trim((string)($input['name']??'TABLE'))));$name=trim((string)$name,'-');
        $input['assetTag']='TABLE-'.$locationId.'-'.($name!==''?$name:strtoupper(substr(bin2hex(random_bytes(4)),0,8)));
    }
    return host_create_managed_table($pdo,$org,$locationId,$input,$userId);
}
