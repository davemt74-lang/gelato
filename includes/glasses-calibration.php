<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-core.php';

function glasses_station_calibration_ready(PDO $pdo): bool
{
    foreach(['glasses_station_calibrations','glasses_station_calibration_zones'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_station_calibration_station(PDO $pdo,int $org,int $locationId,string $stationPublicId): array
{
    $q=$pdo->prepare("SELECT id,public_id,name FROM kds_stations WHERE organization_id=? AND location_id=? AND public_id=? AND status='active' LIMIT 1");
    $q->execute([$org,$locationId,trim($stationPublicId)]);
    $row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Active kitchen station was not found at this location.');
    return ['id'=>(int)$row['id'],'publicId'=>(string)$row['public_id'],'name'=>(string)$row['name']];
}

function glasses_station_calibration_zone(PDO $pdo,int $org,array $zone,int $index): array
{
    $ingredientId=(int)($zone['ingredientId']??0);
    if($ingredientId<1)throw new InvalidArgumentException('Calibration zone ingredient is required.');

    $q=$pdo->prepare('SELECT id,canonical_name FROM ingredients WHERE organization_id=? AND id=? LIMIT 1');
    $q->execute([$org,$ingredientId]);
    $ingredient=$q->fetch();
    if(!$ingredient)throw new InvalidArgumentException('Calibration zone ingredient was not found.');

    $zoneKey=mb_substr(trim((string)($zone['zoneKey']??'')),0,160,'UTF-8');
    if($zoneKey==='')$zoneKey='ingredient:'.$ingredientId.':zone:'.($index+1);

    $displayName=mb_substr(trim((string)($zone['displayName']??$ingredient['canonical_name'])),0,180,'UTF-8');
    if($displayName==='')$displayName=(string)$ingredient['canonical_name'];

    $x=(float)($zone['x']??-1);
    $y=(float)($zone['y']??-1);
    $width=(float)($zone['width']??0);
    $height=(float)($zone['height']??0);

    foreach(['x'=>$x,'y'=>$y,'width'=>$width,'height'=>$height] as $name=>$value){
        if(!is_finite($value))throw new InvalidArgumentException('Calibration zone '.$name.' is invalid.');
    }
    if($x<0||$y<0||$width<=0||$height<=0||$x>1||$y>1||$x+$width>1.000001||$y+$height>1.000001)
        throw new InvalidArgumentException('Calibration zones must use positive normalized coordinates inside the camera frame.');

    $priority=max(-1000,min(1000,(int)($zone['priority']??0)));
    $metadata=isset($zone['metadata'])?glasses_json_object(is_array($zone['metadata'])?$zone['metadata']:null,8000):null;

    return [
        'ingredientId'=>$ingredientId,
        'zoneKey'=>$zoneKey,
        'displayName'=>$displayName,
        'x'=>round($x,6),
        'y'=>round($y,6),
        'width'=>round($width,6),
        'height'=>round($height,6),
        'priority'=>$priority,
        'metadataJson'=>$metadata,
    ];
}

function glasses_station_calibration_payload(PDO $pdo,int $org,string $publicId,?array $runtime=null): array
{
    $q=$pdo->prepare("SELECT c.*,s.public_id station_public_id,s.name station_name,l.name location_name
        FROM glasses_station_calibrations c
        JOIN kds_stations s ON s.id=c.station_id AND s.organization_id=c.organization_id
        JOIN locations l ON l.id=c.location_id AND l.organization_id=c.organization_id
        WHERE c.organization_id=? AND c.public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);
    $row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Station calibration was not found.');

    $q=$pdo->prepare("SELECT z.*,i.canonical_name
        FROM glasses_station_calibration_zones z
        JOIN ingredients i ON i.id=z.ingredient_id AND i.organization_id=z.organization_id
        WHERE z.organization_id=? AND z.calibration_id=?
        ORDER BY z.priority DESC,z.id");
    $q->execute([$org,(int)$row['id']]);
    $zones=[];
    foreach($q->fetchAll() as $zone){
        $zones[]=[
            'zoneKey'=>(string)$zone['zone_key'],
            'ingredientId'=>(int)$zone['ingredient_id'],
            'canonicalName'=>(string)$zone['canonical_name'],
            'displayName'=>(string)$zone['display_name'],
            'x'=>(float)$zone['x_norm'],
            'y'=>(float)$zone['y_norm'],
            'width'=>(float)$zone['width_norm'],
            'height'=>(float)$zone['height_norm'],
            'priority'=>(int)$zone['priority'],
            'metadata'=>json_decode((string)($zone['metadata_json']??'null'),true),
        ];
    }

    $compatibility=['compatible'=>true,'reasons'=>[]];
    if($runtime!==null){
        $platform=trim((string)($runtime['platform']??''));
        $width=(int)($runtime['frameWidth']??0);
        $height=(int)($runtime['frameHeight']??0);
        $pixelFormat=mb_strtolower(trim((string)($runtime['pixelFormat']??'')),'UTF-8');

        if($platform!==''&&!hash_equals((string)$row['platform'],$platform)){
            $compatibility['compatible']=false;
            $compatibility['reasons'][]='platform_mismatch';
        }
        if($width>0&&$width!==(int)$row['frame_width']){
            $compatibility['compatible']=false;
            $compatibility['reasons'][]='frame_width_mismatch';
        }
        if($height>0&&$height!==(int)$row['frame_height']){
            $compatibility['compatible']=false;
            $compatibility['reasons'][]='frame_height_mismatch';
        }
        if($pixelFormat!==''&&!hash_equals(mb_strtolower((string)$row['pixel_format'],'UTF-8'),$pixelFormat)){
            $compatibility['compatible']=false;
            $compatibility['reasons'][]='pixel_format_mismatch';
        }
    }

    return [
        'publicId'=>(string)$row['public_id'],
        'locationId'=>(int)$row['location_id'],
        'locationName'=>(string)$row['location_name'],
        'stationPublicId'=>(string)$row['station_public_id'],
        'stationName'=>(string)$row['station_name'],
        'version'=>(int)$row['version'],
        'status'=>(string)$row['status'],
        'platform'=>(string)$row['platform'],
        'frame'=>[
            'width'=>(int)$row['frame_width'],
            'height'=>(int)$row['frame_height'],
            'pixelFormat'=>(string)$row['pixel_format'],
        ],
        'sourceHash'=>(string)$row['source_hash'],
        'notes'=>(string)($row['notes']??''),
        'zones'=>$zones,
        'compatibility'=>$compatibility,
        'activatedAt'=>$row['activated_at'],
        'createdAt'=>$row['created_at'],
    ];
}

function glasses_station_calibration_save(
    PDO $pdo,
    int $org,
    int $locationId,
    string $stationPublicId,
    array $input,
    int $userId
): array {
    if(!glasses_station_calibration_ready($pdo))
        throw new RuntimeException('Glasses station-calibration migration is not installed.');

    $platform=mb_substr(trim((string)($input['platform']??'inmo_air3')),0,40,'UTF-8')?:'inmo_air3';
    $frameWidth=(int)($input['frameWidth']??0);
    $frameHeight=(int)($input['frameHeight']??0);
    $pixelFormat=mb_substr(mb_strtolower(trim((string)($input['pixelFormat']??'grayscale8')),'UTF-8'),0,40,'UTF-8');
    $notes=mb_substr(trim((string)($input['notes']??'')),0,1000,'UTF-8')?:null;
    $zonesInput=$input['zones']??null;

    if($frameWidth<16||$frameWidth>8192||$frameHeight<16||$frameHeight>8192)
        throw new InvalidArgumentException('Calibration camera dimensions are invalid.');
    if($pixelFormat==='')throw new InvalidArgumentException('Calibration pixel format is required.');
    if(!is_array($zonesInput)||count($zonesInput)<1)throw new InvalidArgumentException('At least one ingredient calibration zone is required.');
    if(count($zonesInput)>200)throw new InvalidArgumentException('Calibration contains too many ingredient zones.');

    return glasses_transaction($pdo,function()use(
        $pdo,$org,$locationId,$stationPublicId,$input,$userId,$platform,$frameWidth,$frameHeight,$pixelFormat,$notes,$zonesInput
    ):array{
        $location=glasses_location($pdo,$org,$locationId);
        $station=glasses_station_calibration_station($pdo,$org,$locationId,$stationPublicId);

        // Lock the station to serialize version assignment and active-profile replacement.
        $q=$pdo->prepare('SELECT id FROM kds_stations WHERE organization_id=? AND id=? LIMIT 1 FOR UPDATE');
        $q->execute([$org,$station['id']]);
        if(!(int)$q->fetchColumn())throw new InvalidArgumentException('Kitchen station is no longer available.');

        $zones=[];$zoneKeys=[];
        foreach(array_values($zonesInput) as $index=>$zoneInput){
            if(!is_array($zoneInput))throw new InvalidArgumentException('Calibration zone payload is invalid.');
            $zone=glasses_station_calibration_zone($pdo,$org,$zoneInput,$index);
            if(isset($zoneKeys[$zone['zoneKey']]))throw new InvalidArgumentException('Calibration zone keys must be unique.');
            $zoneKeys[$zone['zoneKey']]=true;
            $zones[]=$zone;
        }

        usort($zones,static fn(array $a,array $b):int=>strcmp($a['zoneKey'],$b['zoneKey']));
        $sourceMaterial=[
            'platform'=>$platform,
            'frameWidth'=>$frameWidth,
            'frameHeight'=>$frameHeight,
            'pixelFormat'=>$pixelFormat,
            'zones'=>array_map(static fn(array $z):array=>[
                'zoneKey'=>$z['zoneKey'],'ingredientId'=>$z['ingredientId'],'displayName'=>$z['displayName'],
                'x'=>$z['x'],'y'=>$z['y'],'width'=>$z['width'],'height'=>$z['height'],'priority'=>$z['priority'],
                'metadata'=>$z['metadataJson']!==null?json_decode($z['metadataJson'],true):null,
            ],$zones),
        ];
        $sourceHash=hash('sha256',json_encode($sourceMaterial,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));

        $q=$pdo->prepare('SELECT COALESCE(MAX(version),0) FROM glasses_station_calibrations WHERE organization_id=? AND station_id=? FOR UPDATE');
        $q->execute([$org,$station['id']]);
        $version=(int)$q->fetchColumn()+1;
        $public=glasses_public_id('station-cal');

        $pdo->prepare("UPDATE glasses_station_calibrations SET status='superseded',updated_at=NOW(6)
            WHERE organization_id=? AND station_id=? AND status='active'")
            ->execute([$org,$station['id']]);

        $pdo->prepare("INSERT INTO glasses_station_calibrations
            (organization_id,public_id,location_id,station_id,version,status,platform,frame_width,frame_height,pixel_format,source_hash,notes,created_by,activated_by,activated_at)
            VALUES (?,?,?,?,?,'active',?,?,?,?,?,?,?,?,NOW(6))")
            ->execute([
                $org,$public,$locationId,$station['id'],$version,$platform,$frameWidth,$frameHeight,$pixelFormat,
                $sourceHash,$notes,$userId,$userId
            ]);
        $calibrationId=(int)$pdo->lastInsertId();

        $insert=$pdo->prepare("INSERT INTO glasses_station_calibration_zones
            (organization_id,calibration_id,ingredient_id,zone_key,display_name,x_norm,y_norm,width_norm,height_norm,priority,metadata_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach($zones as $zone){
            $insert->execute([
                $org,$calibrationId,$zone['ingredientId'],$zone['zoneKey'],$zone['displayName'],
                $zone['x'],$zone['y'],$zone['width'],$zone['height'],$zone['priority'],$zone['metadataJson']
            ]);
        }

        return glasses_station_calibration_payload($pdo,$org,$public,null);
    });
}

function glasses_station_calibration_active(
    PDO $pdo,
    int $org,
    int $locationId,
    ?int $stationId,
    ?array $runtime=null
): ?array {
    if(!glasses_station_calibration_ready($pdo)||$stationId===null||$stationId<1)return null;

    $q=$pdo->prepare("SELECT public_id FROM glasses_station_calibrations
        WHERE organization_id=? AND location_id=? AND station_id=? AND status='active'
        ORDER BY version DESC,id DESC LIMIT 1");
    $q->execute([$org,$locationId,$stationId]);
    $public=$q->fetchColumn();
    if(!$public)return null;
    return glasses_station_calibration_payload($pdo,$org,(string)$public,$runtime);
}

function glasses_station_calibration_versions(PDO $pdo,int $org,int $locationId,string $stationPublicId): array
{
    $station=glasses_station_calibration_station($pdo,$org,$locationId,$stationPublicId);
    $q=$pdo->prepare("SELECT public_id FROM glasses_station_calibrations
        WHERE organization_id=? AND location_id=? AND station_id=?
        ORDER BY version DESC,id DESC");
    $q->execute([$org,$locationId,$station['id']]);
    $out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public)
        $out[]=glasses_station_calibration_payload($pdo,$org,(string)$public,null);
    return $out;
}
