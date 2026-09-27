<?php
declare(strict_types=1);

require_once __DIR__.'/kds-core.php';

function glasses_ready(PDO $pdo): bool
{
    foreach(['glasses_pairing_grants','glasses_devices','glasses_device_events'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_public_id(string $prefix): string
{
    return $prefix.'-'.bin2hex(random_bytes(12));
}

function glasses_transaction(PDO $pdo,callable $work): mixed
{
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $result=$work();
        if($owns)$pdo->commit();
        return $result;
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function glasses_json_object(mixed $value,int $maxBytes=16000): ?string
{
    if($value===null||$value===[]||$value==='')return null;
    if(!is_array($value))throw new InvalidArgumentException('Glasses metadata must be an object.');
    $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($json)>$maxBytes)throw new InvalidArgumentException('Glasses metadata is too large.');
    return $json;
}

function glasses_location(PDO $pdo,int $org,int $locationId): array
{
    $q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND id=? AND status='active' LIMIT 1");
    $q->execute([$org,$locationId]);
    $row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Active restaurant location was not found.');
    return ['id'=>(int)$row['id'],'name'=>(string)$row['name']];
}

function glasses_station(PDO $pdo,int $org,int $locationId,?string $stationPublicId): ?array
{
    $stationPublicId=trim((string)$stationPublicId);
    if($stationPublicId==='')return null;
    $q=$pdo->prepare("SELECT id,public_id,name FROM kds_stations WHERE organization_id=? AND location_id=? AND public_id=? AND status='active' LIMIT 1");
    $q->execute([$org,$locationId,$stationPublicId]);
    $row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Active kitchen station was not found at this location.');
    return ['id'=>(int)$row['id'],'publicId'=>(string)$row['public_id'],'name'=>(string)$row['name']];
}

function glasses_device_event(PDO $pdo,int $org,int $deviceId,string $eventType,?int $locationId,?int $stationId,mixed $metadata=null,?int $actorUserId=null): void
{
    $eventType=mb_substr(trim($eventType),0,80,'UTF-8');
    if($eventType==='')throw new InvalidArgumentException('Glasses event type is required.');
    $pdo->prepare('INSERT INTO glasses_device_events (organization_id,device_id,event_type,location_id,station_id,metadata_json,actor_user_id) VALUES (?,?,?,?,?,?,?)')
        ->execute([$org,$deviceId,$eventType,$locationId,$stationId,glasses_json_object($metadata),$actorUserId]);
}

function glasses_create_pairing_grant(PDO $pdo,int $org,int $locationId,?string $stationPublicId,int $userId,int $ttlMinutes=10): array
{
    if(!glasses_ready($pdo))throw new RuntimeException('Glasses plugin migration is not installed. Run upgrade.php.');
    $location=glasses_location($pdo,$org,$locationId);
    $station=glasses_station($pdo,$org,$locationId,$stationPublicId);
    $ttlMinutes=max(1,min(30,$ttlMinutes));
    $code=strtoupper(bin2hex(random_bytes(6)));
    $hash=hash('sha256',$code);
    $public=glasses_public_id('pair');
    $pdo->prepare('INSERT INTO glasses_pairing_grants (organization_id,public_id,location_id,station_id,code_hash,expires_at,created_by) VALUES (?,?,?,?,?,DATE_ADD(NOW(6),INTERVAL ? MINUTE),?)')
        ->execute([$org,$public,$locationId,$station['id']??null,$hash,$ttlMinutes,$userId]);
    return [
        'publicId'=>$public,
        'pairingCode'=>$code,
        'expiresInSeconds'=>$ttlMinutes*60,
        'location'=>$location,
        'station'=>$station,
    ];
}

function glasses_device_public(array $row): array
{
    return [
        'publicId'=>(string)$row['public_id'],
        'displayName'=>(string)$row['display_name'],
        'platform'=>(string)$row['platform'],
        'status'=>(string)$row['status'],
        'hardwareIdentifier'=>$row['hardware_identifier'],
        'locationId'=>(int)$row['location_id'],
        'locationName'=>$row['location_name']??null,
        'stationPublicId'=>$row['station_public_id']??null,
        'stationName'=>$row['station_name']??null,
        'sdkVersion'=>$row['sdk_version'],
        'appVersion'=>$row['app_version'],
        'systemVersion'=>$row['system_version'],
        'capabilities'=>json_decode((string)($row['capabilities_json']??'null'),true),
        'lastSeenAt'=>$row['last_seen_at'],
        'pairedAt'=>$row['paired_at'],
        'revokedAt'=>$row['revoked_at'],
    ];
}

function glasses_device_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT d.*,l.name location_name,s.public_id station_public_id,s.name station_name
        FROM glasses_devices d
        JOIN locations l ON l.id=d.location_id AND l.organization_id=d.organization_id
        LEFT JOIN kds_stations s ON s.id=d.station_id AND s.organization_id=d.organization_id
        WHERE d.organization_id=? AND d.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);
    $q->execute([$org,$publicId]);
    $row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Glasses device was not found.');
    return $row;
}

function glasses_pair_device(PDO $pdo,string $pairingCode,array $device): array
{
    if(!glasses_ready($pdo))throw new RuntimeException('Glasses plugin migration is not installed.');
    $pairingCode=strtoupper(trim($pairingCode));
    if(!preg_match('/^[A-F0-9]{12}$/',$pairingCode))throw new InvalidArgumentException('Pairing code is invalid.');
    $hash=hash('sha256',$pairingCode);
    return glasses_transaction($pdo,function()use($pdo,$hash,$device):array{
        $q=$pdo->prepare("SELECT * FROM glasses_pairing_grants WHERE code_hash=? AND consumed_at IS NULL AND expires_at>NOW(6) LIMIT 1 FOR UPDATE");
        $q->execute([$hash]);
        $grant=$q->fetch();
        if(!$grant)throw new InvalidArgumentException('Pairing code is invalid or expired.');
        $org=(int)$grant['organization_id'];
        $locationId=(int)$grant['location_id'];
        glasses_location($pdo,$org,$locationId);

        $hardware=mb_substr(trim((string)($device['hardwareIdentifier']??'')),0,190,'UTF-8')?:null;
        $name=mb_substr(trim((string)($device['displayName']??'INMO AIR3')),0,120,'UTF-8');
        if($name==='')$name='INMO AIR3';
        $platform=mb_substr(trim((string)($device['platform']??'inmo_air3')),0,40,'UTF-8')?:'inmo_air3';
        $sdk=mb_substr(trim((string)($device['sdkVersion']??'')),0,80,'UTF-8')?:null;
        $app=mb_substr(trim((string)($device['appVersion']??'')),0,80,'UTF-8')?:null;
        $system=mb_substr(trim((string)($device['systemVersion']??'')),0,80,'UTF-8')?:null;
        $capabilities=glasses_json_object($device['capabilities']??null);
        $token=bin2hex(random_bytes(32));
        $tokenHash=hash('sha256',$token);

        $existing=null;
        if($hardware!==null){
            $q=$pdo->prepare('SELECT * FROM glasses_devices WHERE organization_id=? AND hardware_identifier=? LIMIT 1 FOR UPDATE');
            $q->execute([$org,$hardware]);
            $existing=$q->fetch()?:null;
        }
        if($existing&&$existing['status']==='active')throw new InvalidArgumentException('This glasses device is already paired.');

        if($existing){
            $deviceId=(int)$existing['id'];
            $public=(string)$existing['public_id'];
            $pdo->prepare("UPDATE glasses_devices SET location_id=?,station_id=?,display_name=?,platform=?,status='active',token_hash=?,sdk_version=?,app_version=?,system_version=?,capabilities_json=?,last_seen_at=NOW(6),paired_at=NOW(6),paired_by=?,revoked_at=NULL,revoked_by=NULL,updated_at=NOW(6) WHERE id=? AND organization_id=?")
                ->execute([$locationId,$grant['station_id'],$name,$platform,$tokenHash,$sdk,$app,$system,$capabilities,(int)$grant['created_by'],$deviceId,$org]);
            $event='repaired';
        }else{
            $public=glasses_public_id('glasses');
            $pdo->prepare("INSERT INTO glasses_devices (organization_id,public_id,location_id,station_id,hardware_identifier,display_name,platform,status,token_hash,sdk_version,app_version,system_version,capabilities_json,last_seen_at,paired_by) VALUES (?,?,?,?,?,?,?,'active',?,?,?,?,?,NOW(6),?)")
                ->execute([$org,$public,$locationId,$grant['station_id'],$hardware,$name,$platform,$tokenHash,$sdk,$app,$system,$capabilities,(int)$grant['created_by']]);
            $deviceId=(int)$pdo->lastInsertId();
            $event='paired';
        }

        $pdo->prepare('UPDATE glasses_pairing_grants SET consumed_at=NOW(6) WHERE id=?')->execute([(int)$grant['id']]);
        glasses_device_event($pdo,$org,$deviceId,$event,$locationId,$grant['station_id']!==null?(int)$grant['station_id']:null,[
            'platform'=>$platform,'sdkVersion'=>$sdk,'appVersion'=>$app,'systemVersion'=>$system
        ],(int)$grant['created_by']);
        $row=glasses_device_row($pdo,$org,$public,false);
        return ['device'=>glasses_device_public($row),'deviceToken'=>$token];
    });
}

function glasses_authenticate_token(PDO $pdo,string $token): array
{
    $token=trim($token);
    if(!preg_match('/^[a-f0-9]{64}$/i',$token))throw new InvalidArgumentException('Device authentication failed.');
    $hash=hash('sha256',strtolower($token));
    $q=$pdo->prepare("SELECT d.*,l.name location_name,s.public_id station_public_id,s.name station_name
        FROM glasses_devices d
        JOIN locations l ON l.id=d.location_id AND l.organization_id=d.organization_id
        LEFT JOIN kds_stations s ON s.id=d.station_id AND s.organization_id=d.organization_id
        WHERE d.token_hash=? AND d.status='active' AND d.revoked_at IS NULL LIMIT 1");
    $q->execute([$hash]);
    $row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Device authentication failed.');
    return $row;
}

function glasses_heartbeat(PDO $pdo,array $device,array $input=[]): array
{
    $org=(int)$device['organization_id'];
    $id=(int)$device['id'];
    $sdk=isset($input['sdkVersion'])?mb_substr(trim((string)$input['sdkVersion']),0,80,'UTF-8'):$device['sdk_version'];
    $app=isset($input['appVersion'])?mb_substr(trim((string)$input['appVersion']),0,80,'UTF-8'):$device['app_version'];
    $system=isset($input['systemVersion'])?mb_substr(trim((string)$input['systemVersion']),0,80,'UTF-8'):$device['system_version'];
    $caps=array_key_exists('capabilities',$input)?glasses_json_object($input['capabilities']):$device['capabilities_json'];
    $pdo->prepare('UPDATE glasses_devices SET sdk_version=?,app_version=?,system_version=?,capabilities_json=?,last_seen_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=? AND status=\'active\'')
        ->execute([$sdk?:null,$app?:null,$system?:null,$caps,$org,$id]);
    glasses_device_event($pdo,$org,$id,'heartbeat',(int)$device['location_id'],$device['station_id']!==null?(int)$device['station_id']:null,[
        'sdkVersion'=>$sdk?:null,'appVersion'=>$app?:null,'systemVersion'=>$system?:null
    ],null);
    return glasses_device_public(glasses_device_row($pdo,$org,(string)$device['public_id'],false));
}

function glasses_devices(PDO $pdo,int $org,?int $locationId=null): array
{
    $sql="SELECT d.*,l.name location_name,s.public_id station_public_id,s.name station_name
        FROM glasses_devices d
        JOIN locations l ON l.id=d.location_id AND l.organization_id=d.organization_id
        LEFT JOIN kds_stations s ON s.id=d.station_id AND s.organization_id=d.organization_id
        WHERE d.organization_id=?";
    $args=[$org];
    if($locationId!==null){$sql.=' AND d.location_id=?';$args[]=$locationId;}
    $sql.=' ORDER BY d.status=\'active\' DESC,d.display_name,d.id';
    $q=$pdo->prepare($sql);$q->execute($args);
    return array_map('glasses_device_public',$q->fetchAll());
}

function glasses_device_assign(PDO $pdo,int $org,string $publicId,int $locationId,?string $stationPublicId,int $userId): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$locationId,$stationPublicId,$userId):array{
        $row=glasses_device_row($pdo,$org,$publicId,true);
        if((string)$row['status']!=='active')throw new InvalidArgumentException('Revoked glasses cannot be assigned.');
        glasses_location($pdo,$org,$locationId);
        $station=glasses_station($pdo,$org,$locationId,$stationPublicId);
        $pdo->prepare('UPDATE glasses_devices SET location_id=?,station_id=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')
            ->execute([$locationId,$station['id']??null,$org,(int)$row['id']]);
        glasses_device_event($pdo,$org,(int)$row['id'],'assignment_changed',$locationId,$station['id']??null,[
            'previousLocationId'=>(int)$row['location_id'],
            'previousStationPublicId'=>$row['station_public_id']??null,
            'stationPublicId'=>$station['publicId']??null,
        ],$userId);
        return glasses_device_public(glasses_device_row($pdo,$org,$publicId,false));
    });
}

function glasses_device_rename(PDO $pdo,int $org,string $publicId,string $name,int $userId): array
{
    $name=mb_substr(trim($name),0,120,'UTF-8');
    if($name==='')throw new InvalidArgumentException('Device name is required.');
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$name,$userId):array{
        $row=glasses_device_row($pdo,$org,$publicId,true);
        $pdo->prepare('UPDATE glasses_devices SET display_name=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')
            ->execute([$name,$org,(int)$row['id']]);
        glasses_device_event($pdo,$org,(int)$row['id'],'renamed',(int)$row['location_id'],$row['station_id']!==null?(int)$row['station_id']:null,['displayName'=>$name],$userId);
        return glasses_device_public(glasses_device_row($pdo,$org,$publicId,false));
    });
}

function glasses_device_revoke(PDO $pdo,int $org,string $publicId,int $userId): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$userId):array{
        $row=glasses_device_row($pdo,$org,$publicId,true);
        if((string)$row['status']==='revoked')return glasses_device_public($row);
        $pdo->prepare("UPDATE glasses_devices SET status='revoked',token_hash=SHA2(CONCAT('revoked:',public_id,':',UUID()),256),revoked_at=NOW(6),revoked_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$userId,$org,(int)$row['id']]);
        glasses_device_event($pdo,$org,(int)$row['id'],'revoked',(int)$row['location_id'],$row['station_id']!==null?(int)$row['station_id']:null,null,$userId);
        return glasses_device_public(glasses_device_row($pdo,$org,$publicId,false));
    });
}
