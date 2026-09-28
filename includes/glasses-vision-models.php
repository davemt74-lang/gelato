<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/glasses-build.php';
require_once __DIR__.'/glasses-vision-profiles.php';

function glasses_vision_models_ready(PDO $pdo): bool
{
    foreach([
        'glasses_vision_model_packages',
        'glasses_vision_model_rollouts',
        'glasses_vision_model_rollout_events',
        'glasses_vision_model_assignments',
        'glasses_vision_model_device_reports',
    ] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_vision_model_https_url(string $value): string
{
    $value=trim($value);
    if($value===''||strlen($value)>1000)throw new InvalidArgumentException('Model artifact URL is required.');
    $parts=parse_url($value);
    if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||trim((string)($parts['host']??''))==='')
        throw new InvalidArgumentException('Model artifact URL must use HTTPS.');
    return $value;
}

function glasses_vision_model_sha256(string $value): string
{
    $value=strtolower(trim($value));
    if(!preg_match('/^[a-f0-9]{64}$/',$value))throw new InvalidArgumentException('Model artifact SHA-256 must be 64 hexadecimal characters.');
    return $value;
}

function glasses_vision_model_runtime(string $value): string
{
    $value=strtolower(trim($value));
    $allowed=['onnx','tflite','unity_barracuda','vendor'];
    if(!in_array($value,$allowed,true))throw new InvalidArgumentException('Vision model runtime type is unsupported.');
    return $value;
}

function glasses_vision_model_platform(string $value): string
{
    $value=strtolower(trim($value));
    if($value===''||!preg_match('/^[a-z0-9._-]{2,40}$/',$value))throw new InvalidArgumentException('Vision model platform is invalid.');
    return $value;
}

function glasses_vision_model_version(?string $value): ?string
{
    $value=trim((string)$value);
    if($value==='')return null;
    if(strlen($value)>80||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,79}$/',$value))
        throw new InvalidArgumentException('Vision model compatibility version is invalid.');
    return $value;
}

function glasses_vision_model_semver_value(?string $value): ?string
{
    $value=trim((string)$value);
    if($value==='')return null;
    $value=preg_replace('/^[vV]/','',$value)??$value;
    if(!preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/',$value))return null;
    return $value;
}

function glasses_vision_model_version_at_least(?string $actual,?string $minimum): bool
{
    if($minimum===null||$minimum==='')return true;
    $actualParsed=glasses_vision_model_semver_value($actual);
    $minimumParsed=glasses_vision_model_semver_value($minimum);
    if($actualParsed===null||$minimumParsed===null)return false;
    return version_compare($actualParsed,$minimumParsed,'>=');
}

function glasses_vision_browser_inference_metadata(mixed $metadata,string $runtime): ?array
{
    if($runtime!=='onnx')return null;
    if(!is_array($metadata))return null;
    $browser=is_array($metadata['browserInference']??null)?$metadata['browserInference']:null;
    if($browser===null)return null;
    if((string)($browser['schema']??'')!=='gelato.browser_onnx_detector.v1')
        throw new InvalidArgumentException('Browser inference schema is unsupported.');
    if((string)($browser['decoder']??'')!=='yolo_v8')
        throw new InvalidArgumentException('Browser inference decoder is unsupported.');
    $input=is_array($browser['input']??null)?$browser['input']:[];
    $output=is_array($browser['output']??null)?$browser['output']:[];
    $width=(int)($input['width']??0);$height=(int)($input['height']??0);
    if($width<32||$width>4096||$height<32||$height>4096)
        throw new InvalidArgumentException('Browser inference input dimensions must be between 32 and 4096 pixels.');
    $inputName=mb_substr(trim((string)($input['name']??'')),0,160,'UTF-8');
    $outputName=mb_substr(trim((string)($output['name']??'')),0,160,'UTF-8');
    if($inputName===''||$outputName==='')throw new InvalidArgumentException('Browser inference input and output names are required.');
    $layout=(string)($input['layout']??'nchw');
    if(!in_array($layout,['nchw','nhwc'],true))throw new InvalidArgumentException('Browser inference input layout is invalid.');
    $outputLayout=(string)($output['layout']??'channels_first');
    if(!in_array($outputLayout,['channels_first','rows'],true))throw new InvalidArgumentException('Browser inference output layout is invalid.');
    $boxScale=(string)($output['boxScale']??'pixels');
    if(!in_array($boxScale,['pixels','normalized'],true))throw new InvalidArgumentException('Browser inference box scale is invalid.');
    $labels=array_values(array_filter(array_map(static fn($v)=>mb_substr(trim((string)$v),0,160,'UTF-8'),(array)($browser['labels']??[])),static fn($v)=>$v!==''));
    if(!$labels||count($labels)>1000)throw new InvalidArgumentException('Browser inference labels are required.');
    if(count($labels)!==count(array_unique($labels)))throw new InvalidArgumentException('Browser inference labels must be unique.');
    $nms=(float)($browser['nmsIou']??0.45);
    if(!is_finite($nms)||$nms<0.05||$nms>0.95)throw new InvalidArgumentException('Browser inference NMS IoU is invalid.');
    $max=(int)($browser['maxDetections']??25);
    if($max<1||$max>100)throw new InvalidArgumentException('Browser inference max detections must be between 1 and 100.');
    return [
        'schema'=>'gelato.browser_onnx_detector.v1',
        'decoder'=>'yolo_v8',
        'input'=>['name'=>$inputName,'width'=>$width,'height'=>$height,'layout'=>$layout],
        'output'=>['name'=>$outputName,'layout'=>$outputLayout,'boxScale'=>$boxScale],
        'labels'=>$labels,
        'nmsIou'=>round($nms,4),
        'maxDetections'=>$max,
    ];
}

function glasses_vision_model_package_public(array $row): array
{
    return [
        'publicId'=>(string)$row['public_id'],
        'detectorName'=>(string)$row['detector_name'],
        'modelName'=>(string)$row['model_name'],
        'modelVersion'=>(string)$row['model_version'],
        'runtimeType'=>(string)$row['runtime_type'],
        'platform'=>(string)$row['platform'],
        'artifactUrl'=>(string)$row['artifact_url'],
        'artifactSha256'=>(string)$row['artifact_sha256'],
        'artifactBytes'=>$row['artifact_bytes']!==null?(int)$row['artifact_bytes']:null,
        'hasArtifactBytes'=>$row['artifact_bytes']!==null,
        'minimumSdkVersion'=>$row['minimum_sdk_version'],
        'minimumAppVersion'=>$row['minimum_app_version'],
        'status'=>(string)$row['status'],
        'notes'=>(string)($row['notes']??''),
        'metadata'=>json_decode((string)($row['metadata_json']??'null'),true),
        'createdAt'=>$row['created_at']??null,
        'retiredAt'=>$row['retired_at']??null,
    ];
}

function glasses_vision_model_package_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT * FROM glasses_vision_model_packages WHERE organization_id=? AND public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);
    $q->execute([$org,trim($publicId)]);
    $row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision model package was not found.');
    return $row;
}

function glasses_vision_model_packages(PDO $pdo,int $org): array
{
    if(!glasses_vision_models_ready($pdo))return [];
    $q=$pdo->prepare("SELECT * FROM glasses_vision_model_packages WHERE organization_id=? ORDER BY status='ready' DESC,detector_name,model_name,created_at DESC,id DESC");
    $q->execute([$org]);
    return array_map('glasses_vision_model_package_public',$q->fetchAll());
}

function glasses_vision_model_package_create(PDO $pdo,int $org,array $input,int $userId): array
{
    if(!glasses_vision_models_ready($pdo))throw new RuntimeException('Vision model rollout migration is not installed.');

    $detector=glasses_vision_normalize_detector($input['detectorName']??'');
    if($detector==='*')throw new InvalidArgumentException('Model packages require a specific detector name.');

    $modelName=mb_substr(trim((string)($input['modelName']??'')),0,160,'UTF-8');
    $modelVersion=mb_substr(trim((string)($input['modelVersion']??'')),0,80,'UTF-8');
    if($modelName==='')throw new InvalidArgumentException('Model name is required.');
    if($modelVersion===''||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,79}$/',$modelVersion))
        throw new InvalidArgumentException('Model version is invalid.');

    $runtime=glasses_vision_model_runtime((string)($input['runtimeType']??''));
    $platform=glasses_vision_model_platform((string)($input['platform']??''));
    $artifactUrl=glasses_vision_model_https_url((string)($input['artifactUrl']??''));
    $sha=glasses_vision_model_sha256((string)($input['artifactSha256']??''));

    $bytes=null;
    if(array_key_exists('artifactBytes',$input)&&$input['artifactBytes']!==null&&$input['artifactBytes']!==''){
        $bytes=(int)$input['artifactBytes'];
        if($bytes<1||$bytes>10_000_000_000)throw new InvalidArgumentException('Model artifact byte size is invalid.');
    }

    $minimumSdk=glasses_vision_model_version($input['minimumSdkVersion']??null);
    $minimumApp=glasses_vision_model_version($input['minimumAppVersion']??null);
    $notes=mb_substr(trim((string)($input['notes']??'')),0,1000,'UTF-8')?:null;
    $metadataInput=is_array($input['metadata']??null)?$input['metadata']:[];
    $browserInference=glasses_vision_browser_inference_metadata($metadataInput,$runtime);
    if($browserInference!==null)$metadataInput['browserInference']=$browserInference;
    $metadata=glasses_json_object($metadataInput?:null,12000);
    $public=glasses_public_id('vision-model');

    try{
        $pdo->prepare("INSERT INTO glasses_vision_model_packages
            (organization_id,public_id,detector_name,model_name,model_version,runtime_type,platform,artifact_url,artifact_sha256,artifact_bytes,minimum_sdk_version,minimum_app_version,status,notes,metadata_json,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'ready',?,?,?)")
            ->execute([
                $org,$public,$detector,$modelName,$modelVersion,$runtime,$platform,$artifactUrl,$sha,$bytes,
                $minimumSdk,$minimumApp,$notes,$metadata,$userId
            ]);
    }catch(PDOException $e){
        if((string)$e->getCode()==='23000')throw new InvalidArgumentException('That detector/model/version package is already registered.');
        throw $e;
    }

    return glasses_vision_model_package_public(glasses_vision_model_package_row($pdo,$org,$public,false));
}

function glasses_vision_model_package_retire(PDO $pdo,int $org,string $publicId,int $userId): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$userId):array{
        $row=glasses_vision_model_package_row($pdo,$org,$publicId,true);
        if((string)$row['status']==='retired')return glasses_vision_model_package_public($row);

        $q=$pdo->prepare("SELECT COUNT(*) FROM glasses_vision_model_rollouts
            WHERE organization_id=?
              AND (
                (status IN ('active','paused') AND (target_package_id=? OR baseline_package_id=?))
                OR (status='rolled_back' AND baseline_package_id=?)
              )");
        $q->execute([$org,(int)$row['id'],(int)$row['id'],(int)$row['id']]);
        if((int)$q->fetchColumn()>0)
            throw new InvalidArgumentException('Model package is still required by an active, paused, or rollback assignment.');

        $pdo->prepare("UPDATE glasses_vision_model_packages
            SET status='retired',retired_by=?,retired_at=NOW(6)
            WHERE organization_id=? AND id=?")
            ->execute([$userId,$org,(int)$row['id']]);

        return glasses_vision_model_package_public(glasses_vision_model_package_row($pdo,$org,$publicId,false));
    });
}

function glasses_vision_model_rollout_event(
    PDO $pdo,int $org,int $rolloutId,string $eventType,
    ?string $previousStatus,?string $nextStatus,
    ?float $previousPercent,?float $nextPercent,
    int $userId,array $metadata=[]
): void {
    $pdo->prepare("INSERT INTO glasses_vision_model_rollout_events
        (organization_id,rollout_id,event_type,previous_status,next_status,previous_canary_percent,next_canary_percent,metadata_json,actor_user_id)
        VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([
            $org,$rolloutId,$eventType,$previousStatus,$nextStatus,$previousPercent,$nextPercent,
            glasses_json_object($metadata,12000),$userId
        ]);
}

function glasses_vision_model_rollout_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT r.*,
            tp.public_id target_public_id,tp.model_name target_model_name,tp.model_version target_model_version,
            tp.runtime_type target_runtime_type,tp.platform target_platform,tp.status target_status,
            bp.public_id baseline_public_id,bp.model_name baseline_model_name,bp.model_version baseline_model_version,
            l.name location_name,s.public_id station_public_id,s.name station_name
        FROM glasses_vision_model_rollouts r
        JOIN glasses_vision_model_packages tp ON tp.id=r.target_package_id AND tp.organization_id=r.organization_id
        LEFT JOIN glasses_vision_model_packages bp ON bp.id=r.baseline_package_id AND bp.organization_id=r.organization_id
        LEFT JOIN locations l ON l.id=r.location_id AND l.organization_id=r.organization_id
        LEFT JOIN kds_stations s ON s.id=r.station_id AND s.organization_id=r.organization_id
        WHERE r.organization_id=? AND r.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);
    $q->execute([$org,trim($publicId)]);
    $row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision model rollout was not found.');
    return $row;
}

function glasses_vision_model_rollout_public(array $row): array
{
    return [
        'publicId'=>(string)$row['public_id'],
        'detectorName'=>(string)$row['detector_name'],
        'targetPackagePublicId'=>(string)$row['target_public_id'],
        'targetLabel'=>(string)$row['target_model_name'].' '.(string)$row['target_model_version'],
        'baselinePackagePublicId'=>$row['baseline_public_id'],
        'baselineLabel'=>$row['baseline_public_id']!==null?((string)$row['baseline_model_name'].' '.(string)$row['baseline_model_version']):null,
        'locationId'=>$row['location_id']!==null?(int)$row['location_id']:null,
        'locationName'=>$row['location_name'],
        'stationPublicId'=>$row['station_public_id'],
        'stationName'=>$row['station_name'],
        'canaryPercent'=>(float)$row['canary_percent'],
        'status'=>(string)$row['status'],
        'notes'=>(string)($row['notes']??''),
        'createdAt'=>$row['created_at'],
        'activatedAt'=>$row['activated_at'],
        'pausedAt'=>$row['paused_at'],
        'rolledBackAt'=>$row['rolled_back_at'],
        'updatedAt'=>$row['updated_at'],
    ];
}

function glasses_vision_model_rollouts(PDO $pdo,int $org): array
{
    if(!glasses_vision_models_ready($pdo))return [];
    $q=$pdo->prepare("SELECT r.public_id FROM glasses_vision_model_rollouts r
        WHERE r.organization_id=? ORDER BY r.updated_at DESC,r.id DESC");
    $q->execute([$org]);
    $out=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $publicId)
        $out[]=glasses_vision_model_rollout_public(glasses_vision_model_rollout_row($pdo,$org,(string)$publicId,false));
    return $out;
}

function glasses_vision_model_rollout_create(PDO $pdo,int $org,array $input,int $userId): array
{
    if(!glasses_vision_models_ready($pdo))throw new RuntimeException('Vision model rollout migration is not installed.');

    return glasses_transaction($pdo,function()use($pdo,$org,$input,$userId):array{
        $target=glasses_vision_model_package_row($pdo,$org,(string)($input['targetPackagePublicId']??''),true);
        $baseline=glasses_vision_model_package_row($pdo,$org,(string)($input['baselinePackagePublicId']??''),true);

        if((string)$target['status']!=='ready'||(string)$baseline['status']!=='ready')
            throw new InvalidArgumentException('Target and baseline model packages must both be ready.');
        if((int)$target['id']===(int)$baseline['id'])throw new InvalidArgumentException('Target and baseline packages must be different.');
        foreach(['detector_name','platform','runtime_type'] as $field){
            if((string)$target[$field]!== (string)$baseline[$field])
                throw new InvalidArgumentException('Target and baseline packages must use the same detector, platform, and runtime.');
        }

        $locationId=null;
        if(isset($input['locationId'])&&(int)$input['locationId']>0){
            $locationId=(int)$input['locationId'];
            glasses_location($pdo,$org,$locationId);
        }
        $stationId=null;
        if(trim((string)($input['stationPublicId']??''))!==''){
            if($locationId===null)throw new InvalidArgumentException('Station-scoped rollout requires a location.');
            $station=glasses_station($pdo,$org,$locationId,(string)$input['stationPublicId']);
            $stationId=(int)$station['id'];
        }

        $percent=(float)($input['canaryPercent']??0);
        if(!is_finite($percent)||$percent<0||$percent>100)throw new InvalidArgumentException('Canary percentage must be between 0 and 100.');
        $percent=round($percent,2);
        $notes=mb_substr(trim((string)($input['notes']??'')),0,1000,'UTF-8')?:null;
        $public=glasses_public_id('vision-rollout');

        $pdo->prepare("INSERT INTO glasses_vision_model_rollouts
            (organization_id,public_id,detector_name,target_package_id,baseline_package_id,location_id,station_id,canary_percent,status,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,'draft',?,?)")
            ->execute([
                $org,$public,(string)$target['detector_name'],(int)$target['id'],(int)$baseline['id'],
                $locationId,$stationId,$percent,$notes,$userId
            ]);
        $rollout=glasses_vision_model_rollout_row($pdo,$org,$public,false);
        glasses_vision_model_rollout_event(
            $pdo,$org,(int)$rollout['id'],'created',null,'draft',null,$percent,$userId,
            ['targetPackagePublicId'=>(string)$target['public_id'],'baselinePackagePublicId'=>(string)$baseline['public_id']]
        );
        return glasses_vision_model_rollout_public($rollout);
    });
}

function glasses_vision_model_rollout_conflict(PDO $pdo,int $org,array $rollout): bool
{
    $sql="SELECT COUNT(*) FROM glasses_vision_model_rollouts
        WHERE organization_id=? AND detector_name=? AND id<>?
          AND status IN ('active','paused')
          AND location_id ".($rollout['location_id']===null?'IS NULL':'=?')."
          AND station_id ".($rollout['station_id']===null?'IS NULL':'=?');
    $args=[$org,(string)$rollout['detector_name'],(int)$rollout['id']];
    if($rollout['location_id']!==null)$args[]=(int)$rollout['location_id'];
    if($rollout['station_id']!==null)$args[]=(int)$rollout['station_id'];
    $q=$pdo->prepare($sql);$q->execute($args);
    return (int)$q->fetchColumn()>0;
}

function glasses_vision_model_rollout_activate(PDO $pdo,int $org,string $publicId,int $userId): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$userId):array{
        $row=glasses_vision_model_rollout_row($pdo,$org,$publicId,true);
        $status=(string)$row['status'];
        if(!in_array($status,['draft','paused'],true))
            throw new InvalidArgumentException('Only draft or paused rollouts can be activated.');
        if((string)$row['target_status']!=='ready')
            throw new InvalidArgumentException('Target model package is not ready.');
        $baseline=glasses_vision_model_package_row($pdo,$org,(string)$row['baseline_public_id'],false);
        if((string)$baseline['status']!=='ready')throw new InvalidArgumentException('Baseline model package is not ready.');
        if(glasses_vision_model_rollout_conflict($pdo,$org,$row))
            throw new InvalidArgumentException('Another active or paused rollout already owns this detector and scope.');

        $percent=(float)$row['canary_percent'];
        $pdo->prepare("UPDATE glasses_vision_model_rollouts
            SET status='active',activated_by=?,activated_at=COALESCE(activated_at,NOW(6)),paused_at=NULL,paused_by=NULL,updated_at=NOW(6)
            WHERE organization_id=? AND id=?")
            ->execute([$userId,$org,(int)$row['id']]);
        glasses_vision_model_rollout_event($pdo,$org,(int)$row['id'],'activated',$status,'active',$percent,$percent,$userId);
        return glasses_vision_model_rollout_public(glasses_vision_model_rollout_row($pdo,$org,$publicId,false));
    });
}

function glasses_vision_model_rollout_advance(PDO $pdo,int $org,string $publicId,float $nextPercent,int $userId): array
{
    if(!is_finite($nextPercent)||$nextPercent<0||$nextPercent>100)
        throw new InvalidArgumentException('Canary percentage must be between 0 and 100.');
    $nextPercent=round($nextPercent,2);

    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$nextPercent,$userId):array{
        $row=glasses_vision_model_rollout_row($pdo,$org,$publicId,true);
        if((string)$row['status']!=='active')throw new InvalidArgumentException('Only an active rollout can advance.');
        $previous=(float)$row['canary_percent'];
        if($nextPercent<=$previous)throw new InvalidArgumentException('Canary advancement must increase the current percentage.');

        $pdo->prepare("UPDATE glasses_vision_model_rollouts SET canary_percent=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$nextPercent,$org,(int)$row['id']]);
        glasses_vision_model_rollout_event($pdo,$org,(int)$row['id'],'advanced','active','active',$previous,$nextPercent,$userId);
        return glasses_vision_model_rollout_public(glasses_vision_model_rollout_row($pdo,$org,$publicId,false));
    });
}

function glasses_vision_model_rollout_pause(PDO $pdo,int $org,string $publicId,int $userId): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$userId):array{
        $row=glasses_vision_model_rollout_row($pdo,$org,$publicId,true);
        if((string)$row['status']!=='active')throw new InvalidArgumentException('Only an active rollout can be paused.');
        $percent=(float)$row['canary_percent'];
        $pdo->prepare("UPDATE glasses_vision_model_rollouts SET status='paused',paused_by=?,paused_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$userId,$org,(int)$row['id']]);
        glasses_vision_model_rollout_event($pdo,$org,(int)$row['id'],'paused','active','paused',$percent,$percent,$userId);
        return glasses_vision_model_rollout_public(glasses_vision_model_rollout_row($pdo,$org,$publicId,false));
    });
}

function glasses_vision_model_rollout_rollback(PDO $pdo,int $org,string $publicId,int $userId,string $reason=''): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$userId,$reason):array{
        $row=glasses_vision_model_rollout_row($pdo,$org,$publicId,true);
        $status=(string)$row['status'];
        if(!in_array($status,['active','paused'],true))
            throw new InvalidArgumentException('Only active or paused rollouts can be rolled back.');
        if($row['baseline_package_id']===null)throw new InvalidArgumentException('Rollout has no baseline package to restore.');

        $percent=(float)$row['canary_percent'];
        $pdo->prepare("UPDATE glasses_vision_model_rollouts
            SET status='rolled_back',rolled_back_by=?,rolled_back_at=NOW(6),updated_at=NOW(6)
            WHERE organization_id=? AND id=?")
            ->execute([$userId,$org,(int)$row['id']]);
        glasses_vision_model_rollout_event(
            $pdo,$org,(int)$row['id'],'rolled_back',$status,'rolled_back',$percent,0.0,$userId,
            ['reason'=>mb_substr(trim($reason),0,1000,'UTF-8')]
        );
        return glasses_vision_model_rollout_public(glasses_vision_model_rollout_row($pdo,$org,$publicId,false));
    });
}

function glasses_vision_model_package_compatibility(array $package,array $device): array
{
    $reasons=[];
    if((string)$package['status']!=='ready')$reasons[]='package_not_ready';
    if(strtolower((string)$package['platform'])!==strtolower((string)$device['platform']))$reasons[]='platform_mismatch';

    if(!glasses_vision_model_version_at_least(
        $device['sdk_version']??null,
        $package['minimum_sdk_version']??null
    ))$reasons[]='sdk_version_too_old_or_unknown';

    if(!glasses_vision_model_version_at_least(
        $device['app_version']??null,
        $package['minimum_app_version']??null
    ))$reasons[]='app_version_too_old_or_unknown';

    $caps=json_decode((string)($device['capabilities_json']??'null'),true);
    $runtimes=is_array($caps)&&isset($caps['visionModelRuntimes'])&&is_array($caps['visionModelRuntimes'])
        ?array_map(static fn($v):string=>strtolower(trim((string)$v)),$caps['visionModelRuntimes'])
        :[];
    if(!$runtimes)$reasons[]='vision_runtime_capability_unreported';
    elseif(!in_array(strtolower((string)$package['runtime_type']),$runtimes,true))$reasons[]='runtime_not_supported';

    return ['compatible'=>$reasons===[],'reasons'=>$reasons];
}

function glasses_vision_model_canary_bucket(string $rolloutPublicId,string $devicePublicId): float
{
    $hex=substr(hash('sha256',$rolloutPublicId.'|'.$devicePublicId),0,8);
    $integer=(int)hexdec($hex);
    return round(($integer%10000)/100,2);
}

function glasses_vision_model_matching_rollout(PDO $pdo,array $device,string $detector): ?array
{
    $org=(int)$device['organization_id'];
    $locationId=(int)$device['location_id'];
    $stationId=$device['station_id']!==null?(int)$device['station_id']:null;

    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_rollouts
        WHERE organization_id=? AND detector_name=? AND status IN ('active','paused','rolled_back')
          AND (location_id IS NULL OR location_id=?)
          AND (station_id IS NULL OR station_id=?)
        ORDER BY
          CASE WHEN station_id IS NOT NULL THEN 2 WHEN location_id IS NOT NULL THEN 1 ELSE 0 END DESC,
          updated_at DESC,id DESC
        LIMIT 1");
    $q->execute([$org,$detector,$locationId,$stationId]);
    $public=$q->fetchColumn();
    return $public?glasses_vision_model_rollout_row($pdo,$org,(string)$public,false):null;
}

function glasses_vision_model_assignment_record(
    PDO $pdo,array $device,array $session,array $result,?array $rollout,?array $package
): void {
    $compatibility=is_array($result['compatibility']??null)?$result['compatibility']:[];
    $compatJson=json_encode($compatibility,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO glasses_vision_model_assignments
        (organization_id,device_id,build_session_id,assignment_key,detector_name,rollout_id,package_id,action,selection,rollout_status,canary_percent,canary_bucket,compatibility_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE assignment_key=VALUES(assignment_key)")
        ->execute([
            (int)$device['organization_id'],
            (int)$device['id'],
            (int)$session['id'],
            (string)$result['assignmentKey'],
            (string)$result['detectorName'],
            $rollout!==null?(int)$rollout['id']:null,
            $package!==null?(int)$package['id']:null,
            (string)$result['action'],
            (string)($result['selection']??'hold'),
            $rollout!==null?(string)$rollout['status']:null,
            $rollout!==null?(float)$rollout['canary_percent']:null,
            array_key_exists('canaryBucket',$result)?(float)$result['canaryBucket']:null,
            $compatJson,
        ]);
}

function glasses_vision_model_assignment(
    PDO $pdo,array $device,string $sessionPublicId,string $detectorName
): array {
    if(!glasses_vision_models_ready($pdo))
        throw new RuntimeException('Vision model rollout migrations are not installed.');

    $org=(int)$device['organization_id'];
    $session=glasses_build_session_row($pdo,$org,$sessionPublicId,false);
    glasses_build_assert_device_session($device,$session);

    $detector=glasses_vision_normalize_detector($detectorName);
    if($detector==='*')throw new InvalidArgumentException('Vision model assignment requires a specific detector name.');

    $rollout=glasses_vision_model_matching_rollout($pdo,$device,$detector);
    if(!$rollout){
        $result=[
            'schema'=>'gelato.vision_model_assignment.v1',
            'detectorName'=>$detector,
            'buildSessionPublicId'=>$sessionPublicId,
            'action'=>'hold',
            'reason'=>'no_rollout',
            'assignmentKey'=>hash('sha256',implode('|',[
                $detector,$sessionPublicId,(string)$device['public_id'],'hold','no_rollout'
            ])),
            'selection'=>'hold',
            'rollout'=>null,
            'package'=>null,
            'compatibility'=>['compatible'=>true,'reasons'=>[]],
        ];
        glasses_vision_model_assignment_record($pdo,$device,$session,$result,null,null);
        return $result;
    }

    $status=(string)$rollout['status'];
    $bucket=glasses_vision_model_canary_bucket((string)$rollout['public_id'],(string)$device['public_id']);
    $selection='hold';
    $package=null;

    if($status==='paused'){
        $selection='hold';
    }elseif($status==='rolled_back'){
        $selection='rollback';
        $package=glasses_vision_model_package_row($pdo,$org,(string)$rollout['baseline_public_id'],false);
    }else{
        $target=$bucket<(float)$rollout['canary_percent'];
        $selection=$target?'target':'baseline';
        $package=glasses_vision_model_package_row(
            $pdo,$org,$target?(string)$rollout['target_public_id']:(string)$rollout['baseline_public_id'],false
        );
    }

    $compatibility=$package!==null
        ?glasses_vision_model_package_compatibility($package,$device)
        :['compatible'=>true,'reasons'=>[]];

    $action=$package===null?'hold':($compatibility['compatible']?'apply':'hold');
    $packagePublic=$package!==null?(string)$package['public_id']:'';
    $assignmentKey=hash('sha256',implode('|',[
        $sessionPublicId,(string)$rollout['public_id'],(string)$device['public_id'],$status,(string)$rollout['canary_percent'],
        $selection,$packagePublic,$action
    ]));

    $result=[
        'schema'=>'gelato.vision_model_assignment.v1',
        'detectorName'=>$detector,
        'buildSessionPublicId'=>$sessionPublicId,
        'action'=>$action,
        'reason'=>$action==='hold'&&$package!==null?'incompatible_package':($status==='paused'?'rollout_paused':null),
        'assignmentKey'=>$assignmentKey,
        'selection'=>$selection,
        'canaryBucket'=>$bucket,
        'rollout'=>[
            'publicId'=>(string)$rollout['public_id'],
            'status'=>$status,
            'canaryPercent'=>(float)$rollout['canary_percent'],
        ],
        'package'=>$package!==null?glasses_vision_model_package_public($package):null,
        'compatibility'=>$compatibility,
    ];
    glasses_vision_model_assignment_record($pdo,$device,$session,$result,$rollout,$package);
    return $result;
}

function glasses_vision_model_report(PDO $pdo,array $device,array $input): array
{
    if(!glasses_vision_models_ready($pdo))
        throw new RuntimeException('Vision model rollout migrations are not installed.');

    $org=(int)$device['organization_id'];
    $assignmentKey=strtolower(trim((string)($input['assignmentKey']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$assignmentKey))
        throw new InvalidArgumentException('Vision model report requires a valid assignment key.');

    $reportKey=mb_substr(trim((string)($input['reportKey']??'')),0,190,'UTF-8');
    $reportType=trim((string)($input['reportType']??''));
    $runtimeState=mb_substr(trim((string)($input['runtimeState']??'')),0,40,'UTF-8')?:null;
    $errorCode=mb_substr(trim((string)($input['errorCode']??'')),0,120,'UTF-8')?:null;
    $message=mb_substr(trim((string)($input['message']??'')),0,1000,'UTF-8')?:null;
    if($reportKey==='')throw new InvalidArgumentException('Model report key is required.');

    $allowed=['assignment_seen','download_started','downloaded','verified','activated','failed','rollback_activated'];
    if(!in_array($reportType,$allowed,true))throw new InvalidArgumentException('Vision model report type is invalid.');
    $expectedReportKey=$assignmentKey.($reportType==='assignment_seen'?':seen':':'.$reportType);
    if(!hash_equals($expectedReportKey,$reportKey))
        throw new InvalidArgumentException('Model report key does not match the issued assignment and report type.');

    return glasses_transaction($pdo,function()use(
        $pdo,$device,$org,$input,$assignmentKey,$reportKey,$reportType,$runtimeState,$errorCode,$message
    ):array{
        $q=$pdo->prepare("SELECT * FROM glasses_vision_model_device_reports WHERE device_id=? AND report_key=? LIMIT 1");
        $q->execute([(int)$device['id'],$reportKey]);
        if($existing=$q->fetch())return [
            'reportKey'=>(string)$existing['report_key'],
            'reportType'=>(string)$existing['report_type'],
            'createdAt'=>$existing['created_at'],
            'idempotent'=>true,
        ];

        $q=$pdo->prepare("SELECT * FROM glasses_vision_model_assignments
            WHERE organization_id=? AND device_id=? AND assignment_key=? LIMIT 1");
        $q->execute([$org,(int)$device['id'],$assignmentKey]);
        $assignment=$q->fetch();
        if(!$assignment)throw new InvalidArgumentException('Vision model report references an assignment that was not issued to this device.');

        $rolloutId=$assignment['rollout_id']!==null?(int)$assignment['rollout_id']:null;
        $packageId=$assignment['package_id']!==null?(int)$assignment['package_id']:null;

        $rollout=null;
        $rolloutPublic=trim((string)($input['rolloutPublicId']??''));
        if($rolloutId!==null){
            $q=$pdo->prepare("SELECT * FROM glasses_vision_model_rollouts WHERE organization_id=? AND id=? LIMIT 1");
            $q->execute([$org,$rolloutId]);
            $rollout=$q->fetch();
            if(!$rollout)throw new InvalidArgumentException('Issued rollout assignment no longer exists.');
            if($rolloutPublic===''||!hash_equals((string)$rollout['public_id'],$rolloutPublic))
                throw new InvalidArgumentException('Reported rollout does not match the issued assignment.');
        }elseif($rolloutPublic!==''){
            throw new InvalidArgumentException('Issued assignment did not include a rollout.');
        }

        $package=null;
        $packagePublic=trim((string)($input['packagePublicId']??''));
        if($packageId!==null){
            $q=$pdo->prepare("SELECT * FROM glasses_vision_model_packages WHERE organization_id=? AND id=? LIMIT 1");
            $q->execute([$org,$packageId]);
            $package=$q->fetch();
            if(!$package)throw new InvalidArgumentException('Issued model package no longer exists.');
            if($packagePublic===''||!hash_equals((string)$package['public_id'],$packagePublic))
                throw new InvalidArgumentException('Reported model package does not match the issued assignment.');
        }elseif($packagePublic!==''){
            throw new InvalidArgumentException('Issued assignment did not include a package.');
        }

        $reportedSha=null;
        if(trim((string)($input['artifactSha256']??''))!=='')
            $reportedSha=glasses_vision_model_sha256((string)$input['artifactSha256']);

        if(in_array($reportType,['download_started','downloaded','verified','activated','rollback_activated'],true)){
            if((string)$assignment['action']!=='apply'||!$package)
                throw new InvalidArgumentException('This issued assignment did not authorize model application.');
        }

        if(in_array($reportType,['verified','activated','rollback_activated'],true)){
            if(!$rollout)throw new InvalidArgumentException('Verified or activated model reports require an issued rollout.');
            if($reportedSha===null)throw new InvalidArgumentException('Verified or activated model reports require the artifact SHA-256.');
            if(!hash_equals((string)$package['artifact_sha256'],$reportedSha))
                throw new InvalidArgumentException('Reported model checksum does not match the registered package.');

            $compatibility=glasses_vision_model_package_compatibility($package,$device);
            if(!$compatibility['compatible'])
                throw new InvalidArgumentException('Device cannot verify or activate an incompatible model package.');

            if($reportType==='rollback_activated'&&(string)$assignment['selection']!=='rollback')
                throw new InvalidArgumentException('Rollback activation requires an issued rollback assignment.');
            if($reportType==='rollback_activated'&&(int)$package['id']!==(int)$rollout['baseline_package_id'])
                throw new InvalidArgumentException('Rollback activation must report the declared baseline package.');
            if($reportType==='activated'&&(string)$assignment['selection']==='rollback')
                throw new InvalidArgumentException('Rollback assignments must use rollback_activated telemetry.');
        }

        $metadata=glasses_json_object(is_array($input['metadata']??null)?$input['metadata']:null,12000);
        $pdo->prepare("INSERT INTO glasses_vision_model_device_reports
            (organization_id,device_id,assignment_id,rollout_id,package_id,report_key,report_type,runtime_state,artifact_sha256,error_code,message,metadata_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $org,(int)$device['id'],(int)$assignment['id'],$rolloutId,$packageId,$reportKey,$reportType,$runtimeState,$reportedSha,
                $errorCode,$message,$metadata
            ]);

        glasses_device_event(
            $pdo,$org,(int)$device['id'],'vision_model_'.$reportType,
            (int)$device['location_id'],$device['station_id']!==null?(int)$device['station_id']:null,
            [
                'assignmentKey'=>$assignmentKey,
                'rolloutPublicId'=>$rolloutPublic?:null,
                'packagePublicId'=>$packagePublic?:null,
                'runtimeState'=>$runtimeState,
                'errorCode'=>$errorCode,
            ],
            null
        );

        return [
            'reportKey'=>$reportKey,
            'reportType'=>$reportType,
            'createdAt'=>(new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'idempotent'=>false,
        ];
    });
}

function glasses_vision_model_rollout_metrics(PDO $pdo,int $org,string $publicId): array
{
    $rollout=glasses_vision_model_rollout_row($pdo,$org,$publicId,false);
    $q=$pdo->prepare("SELECT report_type,COUNT(*) total,COUNT(DISTINCT device_id) devices
        FROM glasses_vision_model_device_reports
        WHERE organization_id=? AND rollout_id=?
        GROUP BY report_type");
    $q->execute([$org,(int)$rollout['id']]);
    $byType=[];
    foreach($q->fetchAll() as $row)$byType[(string)$row['report_type']]=[
        'reports'=>(int)$row['total'],'devices'=>(int)$row['devices']
    ];
    return ['rolloutPublicId'=>$publicId,'byType'=>$byType];
}

function glasses_vision_model_catalog(PDO $pdo,array $user): array
{
    $org=(int)($user['organization_id']??0);
    if($org<1)throw new InvalidArgumentException('Organization context is required.');
    require_once __DIR__.'/operational-access.php';

    $q=$pdo->prepare("SELECT id,name,is_primary,sort_order FROM locations WHERE organization_id=? AND status='active' ORDER BY is_primary DESC,sort_order,name,id");
    $q->execute([$org]);
    $locations=operational_filter_locations($pdo,$user,'glasses.view',$q->fetchAll());

    $stations=[];
    foreach($locations as $location){
        $locationId=(int)$location['id'];
        $stations[(string)$locationId]=array_map(
            static fn(array $station):array=>[
                'publicId'=>(string)$station['public_id'],'name'=>(string)$station['name']
            ],
            kds_stations($pdo,$org,$locationId,true)
        );
    }

    $rollouts=glasses_vision_model_rollouts($pdo,$org);
    $metrics=[];
    foreach($rollouts as $rollout)$metrics[(string)$rollout['publicId']]=glasses_vision_model_rollout_metrics($pdo,$org,(string)$rollout['publicId']);

    return [
        'ready'=>glasses_vision_models_ready($pdo),
        'canManage'=>app_has_permission('glasses.manage',$user),
        'packages'=>glasses_vision_model_packages($pdo,$org),
        'rollouts'=>$rollouts,
        'metricsByRollout'=>$metrics,
        'locations'=>array_map(static fn(array $location):array=>[
            'id'=>(int)$location['id'],'name'=>(string)$location['name'],'primary'=>(bool)$location['is_primary']
        ],$locations),
        'stationsByLocation'=>$stations,
    ];
}
