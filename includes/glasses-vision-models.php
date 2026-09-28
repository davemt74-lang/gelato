<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/glasses-build.php';
require_once __DIR__.'/glasses-vision-profiles.php';
require_once __DIR__.'/glasses-calibration.php';

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

function glasses_vision_model_comparison_metadata(mixed $metadata): ?array
{
    if(!is_array($metadata))return null;
    $comparison=is_array($metadata['modelComparison']??null)?$metadata['modelComparison']:null;
    if($comparison===null)return null;
    if((string)($comparison['schema']??'')!=='gelato.vision_model_comparison.v1')
        throw new InvalidArgumentException('Model comparison schema is unsupported.');
    $hash=strtolower(trim((string)($comparison['goldenTestHash']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$hash))
        throw new InvalidArgumentException('Model comparison golden test hash is invalid.');
    $eligible=!empty($comparison['eligible']);
    $override=!empty($comparison['override']);
    $reason=mb_substr(trim((string)($comparison['overrideReason']??'')),0,1000,'UTF-8');
    if($override&&$reason==='')throw new InvalidArgumentException('Model comparison override requires a documented reason.');
    $regressions=array_values(array_map(static fn($v):string=>mb_substr(trim((string)$v),0,240,'UTF-8'),array_filter((array)($comparison['regressions']??[]),static fn($v)=>trim((string)$v)!=='')));
    $summary=is_array($comparison['summary']??null)?$comparison['summary']:[];
    return [
        'schema'=>'gelato.vision_model_comparison.v1',
        'eligible'=>$eligible,
        'override'=>$override,
        'overrideReason'=>$override?$reason:null,
        'goldenTestHash'=>$hash,
        'regressions'=>$regressions,
        'summary'=>[
            'map50Delta'=>(float)($summary['map50Delta']??0),
            'recallDelta'=>(float)($summary['recallDelta']??0),
            'demonstratedGain'=>!empty($summary['demonstratedGain']),
        ],
    ];
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
    $modelComparison=glasses_vision_model_comparison_metadata($metadataInput);
    if($modelComparison!==null)$metadataInput['modelComparison']=$modelComparison;
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
    ?int $userId,array $metadata=[]
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


function glasses_vision_shadow_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_shadow_runs','glasses_vision_shadow_events'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_vision_shadow_rollout_for_device(PDO $pdo,array $device,string $detector): ?array
{
    if(!glasses_vision_shadow_ready($pdo))return null;
    $org=(int)$device['organization_id'];$location=(int)$device['location_id'];$station=(int)($device['station_id']??0);
    $q=$pdo->prepare("SELECT r.public_id
        FROM glasses_vision_model_rollouts r
        JOIN glasses_vision_model_packages tp ON tp.id=r.target_package_id AND tp.organization_id=r.organization_id AND tp.status='ready'
        JOIN glasses_vision_model_packages bp ON bp.id=r.baseline_package_id AND bp.organization_id=r.organization_id AND bp.status='ready'
        WHERE r.organization_id=? AND r.detector_name=? AND r.status='draft'
          AND (r.location_id IS NULL OR r.location_id=?)
          AND (r.station_id IS NULL OR r.station_id=?)
        ORDER BY (r.station_id IS NOT NULL) DESC,(r.location_id IS NOT NULL) DESC,r.updated_at DESC,r.id DESC");
    $q->execute([$org,$detector,$location,$station]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){
        $row=glasses_vision_model_rollout_row($pdo,$org,(string)$public,false);
        $target=glasses_vision_model_package_row($pdo,$org,(string)$row['target_public_id'],false);
        $meta=json_decode((string)($target['metadata_json']??'null'),true);
        $comparison=glasses_vision_model_comparison_metadata(is_array($meta)?$meta:[]);
        if($comparison&&$comparison['eligible'])return $row;
    }
    return null;
}

function glasses_vision_shadow_summary(PDO $pdo,int $org,string $runPublicId): array
{
    $q=$pdo->prepare("SELECT sr.*,r.public_id rollout_public_id,
            cp.public_id champion_public_id,cp.model_name champion_name,cp.model_version champion_version,
            xp.public_id challenger_public_id,xp.model_name challenger_name,xp.model_version challenger_version
        FROM glasses_vision_shadow_runs sr
        JOIN glasses_vision_model_rollouts r ON r.id=sr.rollout_id AND r.organization_id=sr.organization_id
        JOIN glasses_vision_model_packages cp ON cp.id=sr.champion_package_id AND cp.organization_id=sr.organization_id
        JOIN glasses_vision_model_packages xp ON xp.id=sr.challenger_package_id AND xp.organization_id=sr.organization_id
        WHERE sr.organization_id=? AND sr.public_id=? LIMIT 1");
    $q->execute([$org,trim($runPublicId)]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Shadow evaluation run was not found.');
    $frames=(int)$row['frame_count'];$critical=(int)$row['critical_mismatch_count'];
    $champ=(int)$row['correction_champion_wins'];$chall=(int)$row['correction_challenger_wins'];
    $criticalRate=$frames>0?$critical/$frames:1.0;
    $eligible=$frames>=30&&$criticalRate<=0.10&&$chall>=$champ;
    return [
        'schema'=>'gelato.vision_shadow_evaluation.v1','publicId'=>(string)$row['public_id'],
        'rolloutPublicId'=>(string)$row['rollout_public_id'],'detectorName'=>(string)$row['detector_name'],
        'status'=>(string)$row['status'],'frameCount'=>$frames,'disagreementCount'=>(int)$row['disagreement_count'],
        'disagreementRate'=>$frames>0?round((int)$row['disagreement_count']/$frames,6):0.0,
        'criticalMismatchCount'=>$critical,'criticalMismatchRate'=>round($criticalRate,6),
        'correctionChampionWins'=>$champ,'correctionChallengerWins'=>$chall,'correctionTies'=>(int)$row['correction_ties'],
        'eligibleForCanary'=>$eligible,
        'champion'=>['publicId'=>(string)$row['champion_public_id'],'label'=>(string)$row['champion_name'].' '.(string)$row['champion_version']],
        'challenger'=>['publicId'=>(string)$row['challenger_public_id'],'label'=>(string)$row['challenger_name'].' '.(string)$row['challenger_version']],
        'startedAt'=>$row['started_at'],'completedAt'=>$row['completed_at'],
    ];
}

function glasses_vision_shadow_assignment(PDO $pdo,array $device,string $sessionPublicId,string $detector): array
{
    if(!glasses_vision_shadow_ready($pdo))throw new RuntimeException('Vision shadow evaluation migration is not installed.');
    $org=(int)$device['organization_id'];$session=glasses_build_session_row($pdo,$org,$sessionPublicId,false);
    glasses_build_assert_device_session($device,$session);$detector=glasses_vision_normalize_detector($detector);
    $rollout=glasses_vision_shadow_rollout_for_device($pdo,$device,$detector);
    if(!$rollout)return ['schema'=>'gelato.vision_shadow_assignment.v1','action'=>'hold','reason'=>'no_eligible_draft_challenger'];
    $champ=glasses_vision_model_package_row($pdo,$org,(string)$rollout['baseline_public_id'],false);
    $chall=glasses_vision_model_package_row($pdo,$org,(string)$rollout['target_public_id'],false);
    foreach([$champ,$chall] as $pkg){
        $compat=glasses_vision_model_package_compatibility($pkg,$device);
        if(!$compat['compatible'])return ['schema'=>'gelato.vision_shadow_assignment.v1','action'=>'hold','reason'=>'incompatible_package','compatibility'=>$compat];
    }
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_shadow_runs WHERE rollout_id=? AND device_id=? AND build_session_id=? LIMIT 1");
    $q->execute([(int)$rollout['id'],(int)$device['id'],(int)$session['id']]);$runPublic=$q->fetchColumn();
    if(!$runPublic){
        $runPublic=glasses_public_id('vision-shadow');
        $pdo->prepare("INSERT INTO glasses_vision_shadow_runs
            (organization_id,public_id,rollout_id,device_id,build_session_id,detector_name,champion_package_id,challenger_package_id)
            VALUES (?,?,?,?,?,?,?,?)")->execute([$org,$runPublic,(int)$rollout['id'],(int)$device['id'],(int)$session['id'],$detector,(int)$champ['id'],(int)$chall['id']]);
    }
    return [
        'schema'=>'gelato.vision_shadow_assignment.v1','action'=>'shadow','runPublicId'=>(string)$runPublic,
        'rolloutPublicId'=>(string)$rollout['public_id'],'detectorName'=>$detector,
        'champion'=>glasses_vision_model_package_public($champ),'challenger'=>glasses_vision_model_package_public($chall),
        'summary'=>glasses_vision_shadow_summary($pdo,$org,(string)$runPublic),
    ];
}

function glasses_vision_shadow_report(PDO $pdo,array $device,string $sessionPublicId,array $input): array
{
    if(!glasses_vision_shadow_ready($pdo))throw new RuntimeException('Vision shadow evaluation migration is not installed.');
    $org=(int)$device['organization_id'];$session=glasses_build_session_row($pdo,$org,$sessionPublicId,false);
    glasses_build_assert_device_session($device,$session);
    $runPublic=trim((string)($input['shadowRunPublicId']??''));$frameKey=mb_substr(trim((string)($input['frameKey']??'')),0,160,'UTF-8');
    if($runPublic===''||$frameKey==='')throw new InvalidArgumentException('Shadow run and frame key are required.');
    $q=$pdo->prepare("SELECT * FROM glasses_vision_shadow_runs WHERE organization_id=? AND public_id=? AND device_id=? AND build_session_id=? LIMIT 1");
    $q->execute([$org,$runPublic,(int)$device['id'],(int)$session['id']]);$run=$q->fetch();
    if(!$run)throw new InvalidArgumentException('Shadow evaluation run does not belong to this device/build.');
    if((string)$run['status']!=='running')throw new InvalidArgumentException('Shadow evaluation run is not active.');
    $alignment=strtolower(trim((string)($input['correctionAlignment']??'unknown')));
    if(!in_array($alignment,['unknown','champion','challenger','both','neither'],true))throw new InvalidArgumentException('Shadow correction alignment is invalid.');
    $ints=[];foreach(['championDetectionCount','challengerDetectionCount','matchedCount','championOnlyCount','challengerOnlyCount'] as $key){$v=(int)($input[$key]??0);if($v<0||$v>1000)throw new InvalidArgumentException('Shadow detection counts are invalid.');$ints[$key]=$v;}
    $critical=!empty($input['criticalMismatch']);$meanIou=max(0,min(1,(float)($input['meanIou']??0)));
    $champConf=max(0,min(1,(float)($input['championMeanConfidence']??0)));$challConf=max(0,min(1,(float)($input['challengerMeanConfidence']??0)));
    $metadata=is_array($input['metadata']??null)?$input['metadata']:[];
    $insert=$pdo->prepare("INSERT IGNORE INTO glasses_vision_shadow_events
        (organization_id,shadow_run_id,frame_key,champion_detection_count,challenger_detection_count,matched_count,champion_only_count,challenger_only_count,mean_iou,champion_mean_confidence,challenger_mean_confidence,correction_alignment,critical_mismatch,metadata_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $insert->execute([$org,(int)$run['id'],$frameKey,$ints['championDetectionCount'],$ints['challengerDetectionCount'],$ints['matchedCount'],$ints['championOnlyCount'],$ints['challengerOnlyCount'],$meanIou,$champConf,$challConf,$alignment,$critical?1:0,glasses_json_object($metadata,6000)]);
    if($insert->rowCount()>0){
        $disagree=($ints['championOnlyCount']+$ints['challengerOnlyCount'])>0?1:0;
        $champWin=$alignment==='champion'?1:0;$challWin=$alignment==='challenger'?1:0;$tie=$alignment==='both'?1:0;
        $pdo->prepare("UPDATE glasses_vision_shadow_runs SET frame_count=frame_count+1,disagreement_count=disagreement_count+?,
            correction_champion_wins=correction_champion_wins+?,correction_challenger_wins=correction_challenger_wins+?,
            correction_ties=correction_ties+?,critical_mismatch_count=critical_mismatch_count+?,updated_at=NOW(6)
            WHERE organization_id=? AND id=?")->execute([$disagree,$champWin,$challWin,$tie,$critical?1:0,$org,(int)$run['id']]);
    }
    return ['idempotent'=>$insert->rowCount()===0,'summary'=>glasses_vision_shadow_summary($pdo,$org,$runPublic)];
}

function glasses_vision_shadow_complete(PDO $pdo,array $device,string $sessionPublicId,string $runPublicId): array
{
    $org=(int)$device['organization_id'];$session=glasses_build_session_row($pdo,$org,$sessionPublicId,false);glasses_build_assert_device_session($device,$session);
    $q=$pdo->prepare("SELECT id FROM glasses_vision_shadow_runs WHERE organization_id=? AND public_id=? AND device_id=? AND build_session_id=? LIMIT 1");
    $q->execute([$org,trim($runPublicId),(int)$device['id'],(int)$session['id']]);$id=(int)$q->fetchColumn();
    if($id<1)throw new InvalidArgumentException('Shadow evaluation run does not belong to this device/build.');
    $pdo->prepare("UPDATE glasses_vision_shadow_runs SET status='completed',completed_at=COALESCE(completed_at,NOW(6)),updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$org,$id]);
    return glasses_vision_shadow_summary($pdo,$org,$runPublicId);
}

function glasses_vision_shadow_rollout_gate(PDO $pdo,int $org,int $rolloutId): array
{
    if(!glasses_vision_shadow_ready($pdo))return ['eligible'=>false,'reason'=>'shadow_migration_missing'];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_shadow_runs WHERE organization_id=? AND rollout_id=? AND status='completed' ORDER BY completed_at DESC,id DESC");
    $q->execute([$org,$rolloutId]);$runs=$q->fetchAll(PDO::FETCH_COLUMN);
    foreach($runs as $runPublic){$summary=glasses_vision_shadow_summary($pdo,$org,(string)$runPublic);if($summary['eligibleForCanary'])return ['eligible'=>true,'reason'=>'shadow_passed','summary'=>$summary];}
    return ['eligible'=>false,'reason'=>$runs?'shadow_failed':'shadow_required'];
}


function glasses_vision_canary_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_canary_samples','glasses_vision_canary_health_snapshots','glasses_vision_canary_package_holds'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_vision_canary_stages(): array
{
    return [5.0,10.0,25.0,50.0,100.0];
}

function glasses_vision_canary_next_stage(float $current): ?float
{
    foreach(glasses_vision_canary_stages() as $stage)if($stage>$current+0.0001)return $stage;
    return null;
}

function glasses_vision_canary_package_hold(PDO $pdo,int $org,int $packageId): ?array
{
    if(!glasses_vision_canary_ready($pdo))return null;
    $q=$pdo->prepare("SELECT * FROM glasses_vision_canary_package_holds WHERE organization_id=? AND package_id=? AND hold_until>NOW(6) LIMIT 1");
    $q->execute([$org,$packageId]);$row=$q->fetch();return $row?:null;
}

function glasses_vision_canary_metric_row(array $row): array
{
    $samples=(int)($row['samples']??0);$observations=(int)($row['observations']??0);$inferences=(int)($row['inferences']??0);
    $buildSamples=(int)($row['build_samples']??0);
    return [
        'samples'=>$samples,'devices'=>(int)($row['devices']??0),
        'correctionRate'=>$observations>0?round((int)$row['corrections']/$observations,6):0.0,
        'lowConfidenceRate'=>$observations>0?round((int)$row['low_confidence']/$observations,6):0.0,
        'unexpectedRate'=>$observations>0?round((int)$row['unexpected']/$observations,6):0.0,
        'validationFailureRate'=>$samples>0?round((int)$row['validation_failed']/$samples,6):0.0,
        'avgBuildDurationMs'=>$buildSamples>0?round((int)$row['build_duration_total']/$buildSamples,2):null,
        'avgInferenceLatencyMs'=>$inferences>0?round((int)$row['inference_latency_total']/$inferences,3):null,
        'timeoutRate'=>$inferences>0?round((int)$row['timeouts']/$inferences,6):0.0,
        'runtimeErrorRate'=>$inferences>0?round((int)$row['runtime_errors']/$inferences,6):0.0,
        'observations'=>$observations,'inferences'=>$inferences,
    ];
}

function glasses_vision_canary_health(PDO $pdo,int $org,string $rolloutPublicId): array
{
    $rollout=glasses_vision_model_rollout_row($pdo,$org,$rolloutPublicId,false);
    if(!glasses_vision_canary_ready($pdo))return [
        'schema'=>'gelato.vision_canary_health.v1','state'=>'unavailable','promotionEligible'=>false,'reasons'=>['canary_migration_missing']
    ];
    $q=$pdo->prepare("SELECT cohort,COUNT(*) samples,COUNT(DISTINCT device_id) devices,
        COALESCE(SUM(observation_count),0) observations,COALESCE(SUM(correction_count),0) corrections,
        COALESCE(SUM(low_confidence_count),0) low_confidence,COALESCE(SUM(unexpected_count),0) unexpected,
        COALESCE(SUM(validation_failed),0) validation_failed,
        COALESCE(SUM(CASE WHEN build_duration_ms IS NOT NULL THEN 1 ELSE 0 END),0) build_samples,
        COALESCE(SUM(build_duration_ms),0) build_duration_total,
        COALESCE(SUM(inference_count),0) inferences,COALESCE(SUM(inference_latency_ms),0) inference_latency_total,
        COALESCE(SUM(timeout_count),0) timeouts,COALESCE(SUM(runtime_error_count),0) runtime_errors
        FROM glasses_vision_canary_samples WHERE organization_id=? AND rollout_id=? GROUP BY cohort");
    $q->execute([$org,(int)$rollout['id']]);$rows=[];
    foreach($q->fetchAll() as $row)$rows[(string)$row['cohort']]=glasses_vision_canary_metric_row($row);
    $empty=glasses_vision_canary_metric_row([]);
    $baseline=$rows['baseline']??$empty;$target=$rows['target']??$empty;
    $enoughPromotion=$baseline['samples']>=20&&$target['samples']>=20&&$baseline['devices']>=2&&$target['devices']>=2;
    $enoughRollback=$baseline['samples']>=10&&$target['samples']>=10;
    $reasons=[];$rollback=[];$warnings=[];
    $rateRules=[
        'correctionRate'=>[0.03,0.08],'validationFailureRate'=>[0.05,0.10],
        'lowConfidenceRate'=>[0.10,0.20],'unexpectedRate'=>[0.05,0.10],
        'timeoutRate'=>[0.03,0.08],'runtimeErrorRate'=>[0.02,0.05],
    ];
    foreach($rateRules as $metric=>$limits){
        $delta=(float)$target[$metric]-(float)$baseline[$metric];
        if($delta>$limits[0])$reasons[]=$metric.'_regression';
        elseif($delta>$limits[0]/2)$warnings[]=$metric.'_warning';
        if($enoughRollback&&$delta>$limits[1])$rollback[]=$metric.'_severe_regression';
    }
    foreach(['avgInferenceLatencyMs'=>[1.35,1.75],'avgBuildDurationMs'=>[1.20,1.35]] as $metric=>$limits){
        $base=$baseline[$metric];$chall=$target[$metric];
        if($base!==null&&$chall!==null&&$base>0){
            $ratio=$chall/$base;
            if($ratio>$limits[0])$reasons[]=$metric.'_regression';
            elseif($ratio>1+(($limits[0]-1)/2))$warnings[]=$metric.'_warning';
            if($enoughRollback&&$ratio>$limits[1])$rollback[]=$metric.'_severe_regression';
        }
    }
    $state='collecting';
    if($rollback)$state='rollback_required';
    elseif($enoughPromotion&&$reasons)$state='warning';
    elseif($enoughPromotion)$state='healthy';
    $promotionEligible=$state==='healthy';
    return [
        'schema'=>'gelato.vision_canary_health.v1','rolloutPublicId'=>$rolloutPublicId,
        'state'=>$state,'promotionEligible'=>$promotionEligible,'reasons'=>array_values(array_unique(array_merge($reasons,$warnings))),
        'rollbackReasons'=>array_values(array_unique($rollback)),
        'baseline'=>$baseline,'target'=>$target,'currentCanaryPercent'=>(float)$rollout['canary_percent'],
        'nextStage'=>glasses_vision_canary_next_stage((float)$rollout['canary_percent']),
        'minimums'=>['promotionSamplesPerCohort'=>20,'promotionDevicesPerCohort'=>2,'rollbackSamplesPerCohort'=>10],
    ];
}

function glasses_vision_canary_snapshot(PDO $pdo,int $org,array $rollout,array $health,?string $autoAction=null): void
{
    if(!glasses_vision_canary_ready($pdo))return;
    $pdo->prepare("INSERT INTO glasses_vision_canary_health_snapshots
        (organization_id,rollout_id,health_state,target_samples,baseline_samples,target_devices,baseline_devices,metrics_json,reasons_json,auto_action)
        VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
            $org,(int)$rollout['id'],(string)$health['state'],(int)$health['target']['samples'],(int)$health['baseline']['samples'],
            (int)$health['target']['devices'],(int)$health['baseline']['devices'],
            glasses_json_object(['baseline'=>$health['baseline'],'target'=>$health['target'],'nextStage'=>$health['nextStage']],12000),
            glasses_json_object(['reasons'=>$health['reasons'],'rollbackReasons'=>$health['rollbackReasons']],6000),$autoAction
        ]);
}

function glasses_vision_canary_auto_rollback(PDO $pdo,int $org,string $rolloutPublicId,array $health): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$rolloutPublicId,$health):array{
        $row=glasses_vision_model_rollout_row($pdo,$org,$rolloutPublicId,true);
        if((string)$row['status']!=='active')return glasses_vision_model_rollout_public($row);
        if($row['baseline_package_id']===null)throw new RuntimeException('Automatic canary rollback requires a baseline package.');
        $reason='Automatic rollback: '.implode(', ',$health['rollbackReasons']??[]);
        $percent=(float)$row['canary_percent'];
        $pdo->prepare("UPDATE glasses_vision_model_rollouts SET status='rolled_back',rolled_back_by=NULL,rolled_back_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$org,(int)$row['id']]);
        $pdo->prepare("INSERT INTO glasses_vision_canary_package_holds
            (organization_id,package_id,source_rollout_id,reason,hold_until)
            VALUES (?,?,?,?,DATE_ADD(NOW(6),INTERVAL 24 HOUR))
            ON DUPLICATE KEY UPDATE source_rollout_id=VALUES(source_rollout_id),reason=VALUES(reason),hold_until=VALUES(hold_until),updated_at=NOW(6)")
            ->execute([$org,(int)$row['target_package_id'],(int)$row['id'],mb_substr($reason,0,1000,'UTF-8')]);
        glasses_vision_model_rollout_event($pdo,$org,(int)$row['id'],'auto_rolled_back','active','rolled_back',$percent,0.0,null,[
            'automatic'=>true,'reason'=>$reason,'health'=>$health,'packageHoldHours'=>24
        ]);
        return glasses_vision_model_rollout_public(glasses_vision_model_rollout_row($pdo,$org,$rolloutPublicId,false));
    });
}

function glasses_vision_canary_evaluate_and_enforce(PDO $pdo,int $org,string $rolloutPublicId): array
{
    $health=glasses_vision_canary_health($pdo,$org,$rolloutPublicId);
    $rollout=glasses_vision_model_rollout_row($pdo,$org,$rolloutPublicId,false);
    $action=null;
    if($health['state']==='rollback_required'&&(string)$rollout['status']==='active'){
        glasses_vision_canary_auto_rollback($pdo,$org,$rolloutPublicId,$health);$action='auto_rollback';
    }
    glasses_vision_canary_snapshot($pdo,$org,$rollout,$health,$action);
    if($action!==null)$health['autoAction']=$action;
    return $health;
}

function glasses_vision_canary_sample(PDO $pdo,array $device,array $input): array
{
    if(!glasses_vision_canary_ready($pdo))throw new RuntimeException('Canary production evaluation migration is not installed.');
    $org=(int)$device['organization_id'];$assignmentKey=strtolower(trim((string)($input['assignmentKey']??'')));
    if(!preg_match('/^[a-f0-9]{64}$/',$assignmentKey))throw new InvalidArgumentException('Canary sample requires a valid assignment key.');
    $sampleKey=mb_substr(trim((string)($input['sampleKey']??'')),0,190,'UTF-8');if($sampleKey==='')throw new InvalidArgumentException('Canary sample key is required.');
    $q=$pdo->prepare("SELECT a.*,r.public_id rollout_public_id,r.status rollout_status
        FROM glasses_vision_model_assignments a JOIN glasses_vision_model_rollouts r ON r.id=a.rollout_id AND r.organization_id=a.organization_id
        WHERE a.organization_id=? AND a.device_id=? AND a.assignment_key=? LIMIT 1");
    $q->execute([$org,(int)$device['id'],$assignmentKey]);$assignment=$q->fetch();
    if(!$assignment)throw new InvalidArgumentException('Canary sample references an assignment not issued to this device.');
    if((string)$assignment['action']!=='apply'||!in_array((string)$assignment['selection'],['baseline','target'],true))
        throw new InvalidArgumentException('Canary sample requires an applied baseline or target assignment.');
    if((string)$assignment['rollout_status']!=='active'&&(string)$assignment['rollout_status']!=='rolled_back')
        throw new InvalidArgumentException('Canary sample assignment is not from an active production rollout.');
    $session=glasses_build_session_row($pdo,$org,(string)($input['buildSessionPublicId']??''),false);glasses_build_assert_device_session($device,$session);
    if((int)$assignment['build_session_id']!==(int)$session['id'])throw new InvalidArgumentException('Canary sample build does not match the issued assignment.');
    $intFields=['observationCount'=>10000,'correctionCount'=>10000,'lowConfidenceCount'=>10000,'unexpectedCount'=>10000,'inferenceCount'=>100000,'inferenceLatencyMs'=>1000000000,'timeoutCount'=>100000,'runtimeErrorCount'=>100000];
    $v=[];foreach($intFields as $key=>$max){$n=(int)($input[$key]??0);if($n<0||$n>$max)throw new InvalidArgumentException('Canary production metric is out of range.');$v[$key]=$n;}
    if($v['correctionCount']>$v['observationCount']||$v['lowConfidenceCount']>$v['observationCount']||$v['unexpectedCount']>$v['observationCount'])
        throw new InvalidArgumentException('Canary observation-derived counts cannot exceed observation count.');
    if($v['timeoutCount']>$v['inferenceCount']||$v['runtimeErrorCount']>$v['inferenceCount'])
        throw new InvalidArgumentException('Canary inference failures cannot exceed inference count.');
    $duration=null;if(array_key_exists('buildDurationMs',$input)&&$input['buildDurationMs']!==null){$duration=(int)$input['buildDurationMs'];if($duration<0||$duration>86400000)throw new InvalidArgumentException('Canary build duration is invalid.');}
    $validationFailed=!empty($input['validationFailed'])?1:0;$cohort=(string)$assignment['selection'];
    $metadata=glasses_json_object(is_array($input['metadata']??null)?$input['metadata']:null,6000);
    $insert=$pdo->prepare("INSERT IGNORE INTO glasses_vision_canary_samples
        (organization_id,rollout_id,assignment_id,device_id,build_session_id,sample_key,cohort,observation_count,correction_count,low_confidence_count,unexpected_count,validation_failed,build_duration_ms,inference_count,inference_latency_ms,timeout_count,runtime_error_count,metadata_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $insert->execute([$org,(int)$assignment['rollout_id'],(int)$assignment['id'],(int)$device['id'],(int)$session['id'],$sampleKey,$cohort,
        $v['observationCount'],$v['correctionCount'],$v['lowConfidenceCount'],$v['unexpectedCount'],$validationFailed,$duration,
        $v['inferenceCount'],$v['inferenceLatencyMs'],$v['timeoutCount'],$v['runtimeErrorCount'],$metadata]);
    $health=glasses_vision_canary_evaluate_and_enforce($pdo,$org,(string)$assignment['rollout_public_id']);
    return ['idempotent'=>$insert->rowCount()===0,'cohort'=>$cohort,'health'=>$health];
}


function glasses_vision_drift_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_drift_baselines','glasses_vision_drift_samples','glasses_vision_drift_incidents'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_vision_drift_hash(?string $value): ?string
{
    $value=strtolower(trim((string)$value));if($value==='')return null;
    if(!preg_match('/^[a-f0-9]{64}$/',$value))throw new InvalidArgumentException('Vision drift signature must be SHA-256.');
    return $value;
}

function glasses_vision_drift_context(PDO $pdo,array $device,array $session): array
{
    $org=(int)$device['organization_id'];$components=glasses_build_components($pdo,$org,(int)$session['id'],false);
    $ingredientMaterial=[];
    foreach($components as $row){
        if((float)$row['expected_quantity']<=0)continue;
        $ingredientMaterial[]=[(string)$row['component_key'],(float)$row['expected_quantity'],(string)($row['unit']??''),(bool)$row['is_optional']];
    }
    $ingredientSignature=hash('sha256',json_encode($ingredientMaterial,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $menuMaterial=[
        'sourceRevision'=>$session['source_revision']??null,
        'buildDefinitionPublicId'=>$session['build_definition_public_id']??null,
        'buildDefinitionVersion'=>$session['build_definition_version']??null,
        'kdsPublicId'=>$session['kds_public_id']??null,
    ];
    $menuSignature=hash('sha256',json_encode($menuMaterial,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $calibration=glasses_station_calibration_active($pdo,$org,(int)$session['location_id'],$session['station_id']!==null?(int)$session['station_id']:null,null);
    return [
        'menuSignature'=>$menuSignature,'ingredientSignature'=>$ingredientSignature,
        'calibrationSourceHash'=>$calibration?glasses_vision_drift_hash((string)$calibration['sourceHash']):null,
        'calibrationPublicId'=>$calibration['publicId']??null,
    ];
}

function glasses_vision_drift_baseline_row(PDO $pdo,int $org,int $packageId,int $locationId,?int $stationId): ?array
{
    $sql="SELECT * FROM glasses_vision_drift_baselines WHERE organization_id=? AND package_id=? AND location_id=? AND ".($stationId===null?'station_id IS NULL':'station_id=?')." AND status='active' ORDER BY id DESC LIMIT 1";
    $q=$pdo->prepare($sql);$args=[$org,$packageId,$locationId];if($stationId!==null)$args[]=$stationId;$q->execute($args);$row=$q->fetch();return $row?:null;
}

function glasses_vision_drift_establish_baseline(PDO $pdo,int $org,array $assignment,array $session,array $ctx): ?array
{
    $packageId=(int)$assignment['package_id'];$locationId=(int)$session['location_id'];$stationId=$session['station_id']!==null?(int)$session['station_id']:null;
    if($existing=glasses_vision_drift_baseline_row($pdo,$org,$packageId,$locationId,$stationId))return $existing;
    $whereStation=$stationId===null?'station_id IS NULL':'station_id=?';
    $cutoffQ=$pdo->prepare("SELECT MAX(updated_at) FROM glasses_vision_drift_baselines WHERE organization_id=? AND package_id=? AND location_id=? AND ".($stationId===null?'station_id IS NULL':'station_id=?')." AND status='superseded'");
    $cutoffArgs=[$org,$packageId,$locationId];if($stationId!==null)$cutoffArgs[]=$stationId;$cutoffQ->execute($cutoffArgs);$cutoff=$cutoffQ->fetchColumn()?:null;
    $sql="SELECT COUNT(*) samples,
        AVG(brightness_mean) brightness_mean,AVG(contrast_mean) contrast_mean,
        AVG(camera_pitch) camera_pitch_mean,AVG(camera_yaw) camera_yaw_mean,AVG(camera_roll) camera_roll_mean,
        AVG(confidence_mean) confidence_mean,AVG(latency_mean_ms) latency_mean_ms,
        MAX(frame_width) frame_width,MAX(frame_height) frame_height,MAX(pixel_format) pixel_format,
        COALESCE(SUM(observation_count),0) observations,COALESCE(SUM(correction_count),0) corrections,
        COALESCE(SUM(low_confidence_count),0) low_confidence
        FROM (SELECT * FROM glasses_vision_drift_samples
          WHERE organization_id=? AND package_id=? AND location_id=? AND {$whereStation}
            AND drift_state='calibrating'
            AND ((calibration_source_hash IS NULL AND ? IS NULL) OR calibration_source_hash=?)
            AND menu_signature=? AND ingredient_signature=?
            AND (? IS NULL OR created_at>?)
          ORDER BY id DESC LIMIT 20) x";
    $args=[$org,$packageId,$locationId];if($stationId!==null)$args[]=$stationId;
    $args[]=$ctx['calibrationSourceHash'];$args[]=$ctx['calibrationSourceHash'];$args[]=$ctx['menuSignature'];$args[]=$ctx['ingredientSignature'];$args[]=$cutoff;$args[]=$cutoff;
    $q=$pdo->prepare($sql);$q->execute($args);$agg=$q->fetch();
    if(!$agg||(int)$agg['samples']<20)return null;
    $observations=(int)$agg['observations'];
    $pdo->prepare("INSERT INTO glasses_vision_drift_baselines
      (organization_id,package_id,location_id,station_id,detector_name,calibration_source_hash,menu_signature,ingredient_signature,
       frame_width,frame_height,pixel_format,sample_count,brightness_mean,contrast_mean,camera_pitch_mean,camera_yaw_mean,camera_roll_mean,
       confidence_mean,latency_mean_ms,correction_rate,low_confidence_rate)
      SELECT ?,?,?,?,?,?,?,?,?,?,?,20,?,?,?,?,?,?,?,?,?
      FROM glasses_vision_drift_samples
      WHERE organization_id=? AND package_id=? AND location_id=? AND ".($stationId===null?'station_id IS NULL':'station_id=?')."
      ORDER BY id DESC LIMIT 1")
      ->execute(array_merge([
        $org,$packageId,$locationId,$stationId,(string)$assignment['detector_name'],$ctx['calibrationSourceHash'],$ctx['menuSignature'],$ctx['ingredientSignature'],
        $agg['frame_width'],$agg['frame_height'],$agg['pixel_format'],
        $agg['brightness_mean'],$agg['contrast_mean'],$agg['camera_pitch_mean'],$agg['camera_yaw_mean'],$agg['camera_roll_mean'],
        $agg['confidence_mean'],$agg['latency_mean_ms'],$observations>0?(int)$agg['corrections']/$observations:0,$observations>0?(int)$agg['low_confidence']/$observations:0,
        $org,$packageId,$locationId
      ],$stationId===null?[]:[$stationId]));
    return glasses_vision_drift_baseline_row($pdo,$org,$packageId,$locationId,$stationId);
}

function glasses_vision_drift_compare(array $baseline,array $sample,array $ctx): array
{
    $reasons=[];$categories=[];$score=0.0;$critical=false;
    $add=function(string $reason,string $category,float $weight,bool $isCritical=false)use(&$reasons,&$categories,&$score,&$critical):void{
        $reasons[]=$reason;$categories[$category]=true;$score=min(1.0,$score+$weight);if($isCritical)$critical=true;
    };
    foreach(['calibration_source_hash'=>'calibration_changed','menu_signature'=>'menu_changed','ingredient_signature'=>'ingredient_set_changed'] as $field=>$reason){
        $base=$baseline[$field]??null;$cur=$field==='calibration_source_hash'?$ctx['calibrationSourceHash']:($field==='menu_signature'?$ctx['menuSignature']:$ctx['ingredientSignature']);
        if($base!==null&&$cur!==null&&!hash_equals((string)$base,(string)$cur))$add($reason,$field==='calibration_source_hash'?'environment':'data_domain',$field==='calibration_source_hash'?.35:.30,false);
    }
    if($baseline['frame_width']!==null&&$sample['frameWidth']!==null&&((int)$baseline['frame_width']!==(int)$sample['frameWidth']||(int)$baseline['frame_height']!==(int)$sample['frameHeight']))$add('frame_geometry_changed','camera_config',.65,true);
    if($baseline['pixel_format']!==null&&$sample['pixelFormat']!==null&&!hash_equals((string)$baseline['pixel_format'],(string)$sample['pixelFormat']))$add('pixel_format_changed','camera_config',.65,true);
    if($sample['brightnessMean']!==null&&$baseline['brightness_mean']!==null){$brightness=abs((float)$sample['brightnessMean']-(float)$baseline['brightness_mean']);if($brightness>.30)$add('lighting_shift_critical','lighting',.55,true);elseif($brightness>.18)$add('lighting_shift','lighting',.25);}
    if($sample['contrastMean']!==null&&$baseline['contrast_mean']!==null){$contrast=abs((float)$sample['contrastMean']-(float)$baseline['contrast_mean']);if($contrast>.30)$add('contrast_shift_critical','lighting',.50,true);elseif($contrast>.18)$add('contrast_shift','lighting',.22);}
    if($sample['cameraPitch']!==null&&$sample['cameraYaw']!==null&&$sample['cameraRoll']!==null&&$baseline['camera_pitch_mean']!==null&&$baseline['camera_yaw_mean']!==null&&$baseline['camera_roll_mean']!==null){$pose=max(abs((float)$sample['cameraPitch']-(float)$baseline['camera_pitch_mean']),abs((float)$sample['cameraYaw']-(float)$baseline['camera_yaw_mean']),abs((float)$sample['cameraRoll']-(float)$baseline['camera_roll_mean']));if($pose>25)$add('camera_pose_shift_critical','camera_pose',.55,true);elseif($pose>12)$add('camera_pose_shift','camera_pose',.28);}
    if($sample['confidenceMean']!==null&&$baseline['confidence_mean']!==null){$confDrop=(float)$baseline['confidence_mean']-(float)$sample['confidenceMean'];if($confDrop>.25)$add('confidence_collapse','model_quality',.55,true);elseif($confDrop>.12)$add('confidence_degradation','model_quality',.25);}
    $baseLatency=(float)($baseline['latency_mean_ms']??0);
    if($baseLatency>0&&$sample['latencyMeanMs']!==null){$ratio=(float)$sample['latencyMeanMs']/$baseLatency;if($ratio>2)$add('latency_spike_critical','runtime',.50,true);elseif($ratio>1.5)$add('latency_spike','runtime',.22);}
    $obs=max(0,(int)$sample['observationCount']);$corrRate=$obs>0?(int)$sample['correctionCount']/$obs:0;$lowRate=$obs>0?(int)$sample['lowConfidenceCount']/$obs:0;
    $corrDelta=$corrRate-(float)($baseline['correction_rate']??0);if($corrDelta>.15)$add('correction_rate_critical','model_quality',.55,true);elseif($corrDelta>.08)$add('correction_rate_drift','model_quality',.25);
    $lowDelta=$lowRate-(float)($baseline['low_confidence_rate']??0);if($lowDelta>.25)$add('low_confidence_critical','model_quality',.50,true);elseif($lowDelta>.15)$add('low_confidence_drift','model_quality',.22);
    $state=$critical?'critical':($score>=.45?'drifted':($score>=.18?'watch':'stable'));
    return ['schema'=>'gelato.vision_drift_evaluation.v1','state'=>$state,'score'=>round($score,6),'reasons'=>array_values(array_unique($reasons)),'categories'=>array_keys($categories)];
}

function glasses_vision_drift_incident(PDO $pdo,int $org,array $assignment,array $session,array $evaluation): void
{
    if(in_array($evaluation['state'],['stable','calibrating'],true))return;
    $category=$evaluation['categories'][0]??'mixed';$severity=$evaluation['state']==='critical'?'critical':($evaluation['state']==='drifted'?'high':'warning');
    $key=hash('sha256',implode('|',[(string)$assignment['package_id'],(string)$session['location_id'],(string)($session['station_id']??0),$category,implode(',',$evaluation['reasons'])]));
    $public=glasses_public_id('vision-drift');
    $pdo->prepare("INSERT INTO glasses_vision_drift_incidents
      (organization_id,public_id,package_id,rollout_id,location_id,station_id,incident_key,severity,drift_state,category,reasons_json,metadata_json)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE severity=VALUES(severity),drift_state=VALUES(drift_state),last_seen_at=NOW(6),resolved_at=NULL,
        recovery_status=CASE WHEN recovery_status IN ('resolved','validating') THEN 'reopened' ELSE recovery_status END,
        reopened_count=reopened_count+CASE WHEN recovery_status IN ('resolved','validating') THEN 1 ELSE 0 END,
        validation_stable_samples=CASE WHEN recovery_status IN ('resolved','validating') THEN 0 ELSE validation_stable_samples END,
        reasons_json=VALUES(reasons_json),metadata_json=VALUES(metadata_json)")
      ->execute([$org,$public,(int)$assignment['package_id'],$assignment['rollout_id']!==null?(int)$assignment['rollout_id']:null,(int)$session['location_id'],$session['station_id']!==null?(int)$session['station_id']:null,$key,$severity,$evaluation['state'],$category,glasses_json_object($evaluation['reasons'],4000),glasses_json_object(['score'=>$evaluation['score'],'categories'=>$evaluation['categories']],4000)]);
}

function glasses_vision_drift_auto_rollback(PDO $pdo,int $org,int $rolloutId,array $evaluation): void
{
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_rollouts WHERE organization_id=? AND id=? AND status='active' LIMIT 1");$q->execute([$org,$rolloutId]);$public=$q->fetchColumn();if(!$public)return;
    glasses_transaction($pdo,function()use($pdo,$org,$rolloutId,$public,$evaluation):void{
        $row=glasses_vision_model_rollout_row($pdo,$org,(string)$public,true);if((string)$row['status']!=='active'||$row['baseline_package_id']===null)return;
        $percent=(float)$row['canary_percent'];$reason='Production drift automatic rollback: '.implode(', ',$evaluation['reasons']);
        $pdo->prepare("UPDATE glasses_vision_model_rollouts SET status='rolled_back',rolled_back_by=NULL,rolled_back_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$org,$rolloutId]);
        if(glasses_vision_canary_ready($pdo))$pdo->prepare("INSERT INTO glasses_vision_canary_package_holds
          (organization_id,package_id,source_rollout_id,reason,hold_until) VALUES (?,?,?,?,DATE_ADD(NOW(6),INTERVAL 24 HOUR))
          ON DUPLICATE KEY UPDATE source_rollout_id=VALUES(source_rollout_id),reason=VALUES(reason),hold_until=VALUES(hold_until),updated_at=NOW(6)")
          ->execute([$org,(int)$row['target_package_id'],$rolloutId,mb_substr($reason,0,1000,'UTF-8')]);
        glasses_vision_model_rollout_event($pdo,$org,$rolloutId,'drift_auto_rolled_back','active','rolled_back',$percent,0.0,null,['automatic'=>true,'reason'=>$reason,'drift'=>$evaluation,'packageHoldHours'=>24]);
    });
}

function glasses_vision_drift_sample(PDO $pdo,array $device,array $input): array
{
    if(!glasses_vision_drift_ready($pdo))throw new RuntimeException('Vision drift migration is not installed.');
    $org=(int)$device['organization_id'];$assignmentKey=strtolower(trim((string)($input['assignmentKey']??'')));if(!preg_match('/^[a-f0-9]{64}$/',$assignmentKey))throw new InvalidArgumentException('Drift sample requires a valid assignment key.');
    $sampleKey=mb_substr(trim((string)($input['sampleKey']??'')),0,190,'UTF-8');if($sampleKey==='')throw new InvalidArgumentException('Drift sample key is required.');
    $q=$pdo->prepare("SELECT * FROM glasses_vision_model_assignments WHERE organization_id=? AND device_id=? AND assignment_key=? LIMIT 1");$q->execute([$org,(int)$device['id'],$assignmentKey]);$assignment=$q->fetch();
    if(!$assignment||(string)$assignment['action']!=='apply'||$assignment['package_id']===null)throw new InvalidArgumentException('Drift sample requires an applied model assignment issued to this device.');
    $session=glasses_build_session_row($pdo,$org,(string)($input['buildSessionPublicId']??''),false);glasses_build_assert_device_session($device,$session);if((int)$assignment['build_session_id']!==(int)$session['id'])throw new InvalidArgumentException('Drift sample build does not match the model assignment.');
    $bounded=function(string $key,float $min,float $max)use($input):?float{if(!array_key_exists($key,$input)||$input[$key]===null)return null;$v=(float)$input[$key];if(!is_finite($v)||$v<$min||$v>$max)throw new InvalidArgumentException('Vision drift metric '.$key.' is out of range.');return $v;};
    $sample=[
      'brightnessMean'=>$bounded('brightnessMean',0,1),'contrastMean'=>$bounded('contrastMean',0,1),
      'cameraPitch'=>$bounded('cameraPitch',-180,180),'cameraYaw'=>$bounded('cameraYaw',-180,180),'cameraRoll'=>$bounded('cameraRoll',-180,180),
      'confidenceMean'=>$bounded('confidenceMean',0,1),'latencyMeanMs'=>$bounded('latencyMeanMs',0,60000),
      'frameWidth'=>isset($input['frameWidth'])?(int)$input['frameWidth']:null,'frameHeight'=>isset($input['frameHeight'])?(int)$input['frameHeight']:null,
      'pixelFormat'=>isset($input['pixelFormat'])?mb_substr(mb_strtolower(trim((string)$input['pixelFormat']),'UTF-8'),0,40,'UTF-8'):null,
      'observationCount'=>max(0,min(10000,(int)($input['observationCount']??0))),'correctionCount'=>max(0,min(10000,(int)($input['correctionCount']??0))),'lowConfidenceCount'=>max(0,min(10000,(int)($input['lowConfidenceCount']??0))),
    ];
    if($sample['correctionCount']>$sample['observationCount']||$sample['lowConfidenceCount']>$sample['observationCount'])throw new InvalidArgumentException('Drift observation-derived counts cannot exceed observation count.');
    if(($sample['frameWidth']!==null&&($sample['frameWidth']<16||$sample['frameWidth']>8192))||($sample['frameHeight']!==null&&($sample['frameHeight']<16||$sample['frameHeight']>8192)))throw new InvalidArgumentException('Drift frame dimensions are invalid.');
    $ctx=glasses_vision_drift_context($pdo,$device,$session);
    $baseline=glasses_vision_drift_baseline_row($pdo,$org,(int)$assignment['package_id'],(int)$session['location_id'],$session['station_id']!==null?(int)$session['station_id']:null);
    $evaluation=$baseline?glasses_vision_drift_compare($baseline,$sample,$ctx):['schema'=>'gelato.vision_drift_evaluation.v1','state'=>'calibrating','score'=>0.0,'reasons'=>[],'categories'=>[]];
    $metadata=glasses_json_object(is_array($input['metadata']??null)?$input['metadata']:null,6000);
    $ins=$pdo->prepare("INSERT IGNORE INTO glasses_vision_drift_samples
      (organization_id,assignment_id,rollout_id,package_id,device_id,build_session_id,location_id,station_id,sample_key,calibration_source_hash,menu_signature,ingredient_signature,
       frame_width,frame_height,pixel_format,brightness_mean,contrast_mean,camera_pitch,camera_yaw,camera_roll,confidence_mean,latency_mean_ms,observation_count,correction_count,low_confidence_count,drift_state,drift_score,reasons_json,metadata_json)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([$org,(int)$assignment['id'],$assignment['rollout_id']!==null?(int)$assignment['rollout_id']:null,(int)$assignment['package_id'],(int)$device['id'],(int)$session['id'],(int)$session['location_id'],$session['station_id']!==null?(int)$session['station_id']:null,$sampleKey,$ctx['calibrationSourceHash'],$ctx['menuSignature'],$ctx['ingredientSignature'],
      $sample['frameWidth'],$sample['frameHeight'],$sample['pixelFormat'],$sample['brightnessMean'],$sample['contrastMean'],$sample['cameraPitch'],$sample['cameraYaw'],$sample['cameraRoll'],$sample['confidenceMean'],$sample['latencyMeanMs'],$sample['observationCount'],$sample['correctionCount'],$sample['lowConfidenceCount'],$evaluation['state'],$evaluation['score'],glasses_json_object($evaluation['reasons'],4000),$metadata]);
    if($ins->rowCount()===0)return ['idempotent'=>true,'evaluation'=>$evaluation,'baselineEstablished'=>$baseline!==null];
    if(!$baseline){$baseline=glasses_vision_drift_establish_baseline($pdo,$org,$assignment,$session,$ctx);if($baseline)$evaluation=['schema'=>'gelato.vision_drift_evaluation.v1','state'=>'stable','score'=>0.0,'reasons'=>['baseline_established'],'categories'=>[]];}
    if($baseline&&$evaluation['state']!=='calibrating')glasses_vision_drift_incident($pdo,$org,$assignment,$session,$evaluation);
    glasses_vision_drift_recovery_progress($pdo,$org,$assignment,$session,$evaluation);
    if($evaluation['state']==='critical'&&$assignment['rollout_id']!==null)glasses_vision_drift_auto_rollback($pdo,$org,(int)$assignment['rollout_id'],$evaluation);
    return ['idempotent'=>false,'evaluation'=>$evaluation,'baselineEstablished'=>$baseline!==null,'baselineSampleCount'=>$baseline?(int)$baseline['sample_count']:0];
}

function glasses_vision_drift_rollout_summary(PDO $pdo,int $org,string $rolloutPublicId): array
{
    if(!glasses_vision_drift_ready($pdo))return ['schema'=>'gelato.vision_drift_summary.v1','state'=>'unavailable','promotionBlocked'=>false,'reasons'=>[]];
    $rollout=glasses_vision_model_rollout_row($pdo,$org,$rolloutPublicId,false);
    $q=$pdo->prepare("SELECT drift_state,drift_score,reasons_json,created_at FROM glasses_vision_drift_samples WHERE organization_id=? AND rollout_id=? AND package_id=? ORDER BY id DESC LIMIT 1");
    $q->execute([$org,(int)$rollout['id'],(int)$rollout['target_package_id']]);$row=$q->fetch();
    if(!$row)return ['schema'=>'gelato.vision_drift_summary.v1','state'=>'unknown','promotionBlocked'=>false,'reasons'=>[],'latestAt'=>null];
    $state=(string)$row['drift_state'];
    return ['schema'=>'gelato.vision_drift_summary.v1','state'=>$state,'score'=>(float)$row['drift_score'],'promotionBlocked'=>in_array($state,['drifted','critical'],true),'reasons'=>json_decode((string)($row['reasons_json']??'[]'),true)?:[],'latestAt'=>$row['created_at']];
}


function glasses_vision_drift_recovery_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_drift_recovery_events'");
    $q->execute();return (int)$q->fetchColumn()===1;
}

function glasses_vision_drift_recommendation(string $category,array $reasons=[]): array
{
    return match($category){
        'lighting'=>['type'=>'recalibrate_environment','title'=>'Recalibrate lighting/environment','steps'=>['Verify station lighting','Re-run station calibration if optical zones shifted','Collect 20 new stable samples','Validate 10 stable post-fix samples']],
        'camera_pose','camera_config','environment'=>['type'=>'recalibrate_station','title'=>'Recalibrate station/camera','steps'=>['Restore camera position','Verify frame format','Create or activate corrected station calibration','Re-establish environment baseline','Validate 10 stable post-fix samples']],
        'data_domain'=>['type'=>'review_menu_domain','title'=>'Review menu and ingredient domain','steps'=>['Confirm menu/build-definition change is intentional','Review label-profile coverage','Collect hard examples for new ingredients/packaging','Retrain if detector coverage changed','Re-establish baseline only after domain review']],
        'runtime'=>['type'=>'repair_runtime','title'=>'Repair runtime/device path','steps'=>['Inspect timeout/error telemetry','Verify device/runtime versions','Repair runtime before validation','Do not reset baseline to hide runtime degradation']],
        'model_quality'=>['type'=>'retrain_model','title'=>'Collect evidence and retrain','steps'=>['Review corrections and hard examples','Export reviewed training data','Train challenger','Pass golden-set/shadow/canary gates','Do not reset baseline to hide model degradation']],
        default=>['type'=>'diagnose','title'=>'Diagnose mixed drift','steps'=>['Review drift reasons','Separate environment from model/runtime causes','Apply the matching remediation','Validate with stable production samples']],
    };
}

function glasses_vision_drift_incident_public(array $row): array
{
    $reasons=json_decode((string)($row['reasons_json']??'[]'),true)?:[];
    $recommendation=glasses_vision_drift_recommendation((string)$row['category'],$reasons);
    return [
        'publicId'=>(string)$row['public_id'],'severity'=>(string)$row['severity'],'driftState'=>(string)$row['drift_state'],
        'category'=>(string)$row['category'],'reasons'=>$reasons,'recoveryStatus'=>(string)$row['recovery_status'],
        'remediationType'=>$row['remediation_type'],'remediationNotes'=>(string)($row['remediation_notes']??''),
        'validationStableSamples'=>(int)$row['validation_stable_samples'],'validationRequiredSamples'=>10,
        'reopenedCount'=>(int)$row['reopened_count'],'calibrationPublicId'=>$row['calibration_public_id'],
        'activeLearningReference'=>$row['active_learning_reference'],'firstSeenAt'=>$row['first_seen_at'],'lastSeenAt'=>$row['last_seen_at'],
        'resolvedAt'=>$row['resolved_at'],'recommendation'=>$recommendation,
    ];
}

function glasses_vision_drift_incident_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT * FROM glasses_vision_drift_incidents WHERE organization_id=? AND public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,trim($publicId)]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision drift incident was not found.');
    return $row;
}

function glasses_vision_drift_incidents(PDO $pdo,int $org): array
{
    if(!glasses_vision_drift_recovery_ready($pdo))return [];
    $q=$pdo->prepare("SELECT * FROM glasses_vision_drift_incidents WHERE organization_id=? ORDER BY resolved_at IS NULL DESC,last_seen_at DESC,id DESC LIMIT 100");
    $q->execute([$org]);return array_map('glasses_vision_drift_incident_public',$q->fetchAll());
}

function glasses_vision_drift_recovery_event(PDO $pdo,int $org,int $incidentId,string $type,?string $previous,?string $next,?string $remediation,?string $notes,?int $actor,array $evidence=[]): void
{
    $pdo->prepare("INSERT INTO glasses_vision_drift_recovery_events
      (organization_id,incident_id,event_type,previous_status,next_status,remediation_type,notes,evidence_json,actor_user_id)
      VALUES (?,?,?,?,?,?,?,?,?)")->execute([$org,$incidentId,$type,$previous,$next,$remediation,$notes,glasses_json_object($evidence,12000),$actor]);
}

function glasses_vision_drift_recovery_action(PDO $pdo,int $org,string $publicId,string $action,array $input,int $userId): array
{
    if(!glasses_vision_drift_recovery_ready($pdo))throw new RuntimeException('Vision drift recovery migration is not installed.');
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$action,$input,$userId):array{
        $row=glasses_vision_drift_incident_row($pdo,$org,$publicId,true);$previous=(string)$row['recovery_status'];
        $notes=mb_substr(trim((string)($input['notes']??'')),0,2000,'UTF-8');
        $remediation=mb_substr(trim((string)($input['remediationType']??'')),0,48,'UTF-8');
        $next=$previous;$event=$action;$evidence=[];
        if($action==='diagnose'){
            $next='diagnosing';
        }elseif($action==='remediate'){
            if($remediation==='')throw new InvalidArgumentException('Remediation type is required.');
            $next='remediation_required';
            $calibration=mb_substr(trim((string)($input['calibrationPublicId']??'')),0,64,'UTF-8')?:null;
            $activeLearning=mb_substr(trim((string)($input['activeLearningReference']??'')),0,190,'UTF-8')?:null;
            $replacementPackageId=null;
            $replacementPublic=trim((string)($input['replacementPackagePublicId']??''));
            if($replacementPublic!=='')$replacementPackageId=(int)glasses_vision_model_package_row($pdo,$org,$replacementPublic,false)['id'];
            $pdo->prepare("UPDATE glasses_vision_drift_incidents SET remediation_type=?,remediation_notes=?,calibration_public_id=?,replacement_package_id=?,active_learning_reference=?,recovery_status=?,last_action_by=?,last_action_at=NOW(6) WHERE organization_id=? AND id=?")
                ->execute([$remediation,$notes?:null,$calibration,$replacementPackageId,$activeLearning,$next,$userId,$org,(int)$row['id']]);
            glasses_vision_drift_recovery_event($pdo,$org,(int)$row['id'],$event,$previous,$next,$remediation,$notes?:null,$userId,['calibrationPublicId'=>$calibration,'replacementPackagePublicId'=>$replacementPublic?:null,'activeLearningReference'=>$activeLearning]);
            return glasses_vision_drift_incident_public(glasses_vision_drift_incident_row($pdo,$org,$publicId,false));
        }elseif($action==='validate'){
            $remediation=(string)($row['remediation_type']??'');
            if($remediation==='')throw new InvalidArgumentException('Record a remediation before validation.');
            $reset=!empty($input['resetBaseline']);
            $allowedReset=['lighting','camera_pose','camera_config','environment','data_domain'];
            if($reset&&!in_array((string)$row['category'],$allowedReset,true))
                throw new InvalidArgumentException('Baseline reset is not allowed for unresolved model-quality or runtime drift.');
            if($reset){
                $q=$pdo->prepare("SELECT * FROM glasses_vision_drift_baselines WHERE organization_id=? AND package_id=? AND location_id=? AND ".($row['station_id']===null?'station_id IS NULL':'station_id=?')." AND status='active' ORDER BY id DESC LIMIT 1");
                $args=[$org,(int)$row['package_id'],(int)$row['location_id']];if($row['station_id']!==null)$args[]=(int)$row['station_id'];$q->execute($args);$baseline=$q->fetch();
                if($baseline){
                    $pdo->prepare("UPDATE glasses_vision_drift_baselines SET status='superseded',updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$org,(int)$baseline['id']]);
                    $evidence['supersededBaselineId']=(int)$baseline['id'];$evidence['supersededBaselineSampleCount']=(int)$baseline['sample_count'];
                }
            }
            $next='validating';
            $pdo->prepare("UPDATE glasses_vision_drift_incidents SET recovery_status=?,validation_started_at=NOW(6),validation_stable_samples=0,last_action_by=?,last_action_at=NOW(6) WHERE organization_id=? AND id=?")
                ->execute([$next,$userId,$org,(int)$row['id']]);
        }else{
            throw new InvalidArgumentException('Unsupported drift recovery action.');
        }
        $pdo->prepare("UPDATE glasses_vision_drift_incidents SET recovery_status=?,remediation_notes=COALESCE(NULLIF(?,''),remediation_notes),last_action_by=?,last_action_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$next,$notes,$userId,$org,(int)$row['id']]);
        glasses_vision_drift_recovery_event($pdo,$org,(int)$row['id'],$event,$previous,$next,$remediation?:null,$notes?:null,$userId,$evidence);
        return glasses_vision_drift_incident_public(glasses_vision_drift_incident_row($pdo,$org,$publicId,false));
    });
}

function glasses_vision_drift_recovery_progress(PDO $pdo,int $org,array $assignment,array $session,array $evaluation): void
{
    if(!glasses_vision_drift_recovery_ready($pdo))return;
    $q=$pdo->prepare("SELECT * FROM glasses_vision_drift_incidents WHERE organization_id=? AND package_id=? AND location_id=? AND ".($session['station_id']===null?'station_id IS NULL':'station_id=?')." AND recovery_status IN ('validating','resolved') ORDER BY last_seen_at DESC,id DESC");
    $args=[$org,(int)$assignment['package_id'],(int)$session['location_id']];if($session['station_id']!==null)$args[]=(int)$session['station_id'];$q->execute($args);
    foreach($q->fetchAll() as $incident){
        $status=(string)$incident['recovery_status'];
        if($evaluation['state']==='stable'){
            if($status!=='validating')continue;
            $count=(int)$incident['validation_stable_samples']+1;$next=$count>=10?'resolved':'validating';
            $pdo->prepare("UPDATE glasses_vision_drift_incidents SET validation_stable_samples=?,recovery_status=?,resolved_at=".($next==='resolved'?'NOW(6)':'NULL').",last_action_at=NOW(6) WHERE organization_id=? AND id=?")
                ->execute([$count,$next,$org,(int)$incident['id']]);
            if($next==='resolved')glasses_vision_drift_recovery_event($pdo,$org,(int)$incident['id'],'auto_resolved','validating','resolved',(string)($incident['remediation_type']??''),null,null,['stableSamples'=>$count]);
        }elseif(in_array($evaluation['state'],['watch','drifted','critical'],true)){
            $next='reopened';
            $pdo->prepare("UPDATE glasses_vision_drift_incidents SET recovery_status=?,validation_stable_samples=0,resolved_at=NULL,reopened_count=reopened_count+1,last_action_at=NOW(6) WHERE organization_id=? AND id=?")
                ->execute([$next,$org,(int)$incident['id']]);
            glasses_vision_drift_recovery_event($pdo,$org,(int)$incident['id'],'reopened',$status,$next,(string)($incident['remediation_type']??''),null,null,['drift'=>$evaluation]);
        }
    }
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
        if(glasses_vision_canary_ready($pdo)&&glasses_vision_canary_package_hold($pdo,$org,(int)$row['target_package_id']))
            throw new InvalidArgumentException('Target model package is in automatic rollback cooldown.');
        $targetPackage=glasses_vision_model_package_row($pdo,$org,(string)$row['target_public_id'],false);
        $targetMetadata=json_decode((string)($targetPackage['metadata_json']??'null'),true);
        if(is_array($targetMetadata)&&isset($targetMetadata['modelComparison'])){
            $comparison=glasses_vision_model_comparison_metadata($targetMetadata);
            if(!$comparison||!$comparison['eligible'])
                throw new InvalidArgumentException('Target model package failed champion/challenger eligibility.');
        }
        if(is_array($targetMetadata)&&isset($targetMetadata['modelComparison'])){
            $shadowGate=glasses_vision_shadow_rollout_gate($pdo,$org,(int)$row['id']);
            if(!$shadowGate['eligible'])
                throw new InvalidArgumentException('Target model package requires a passing live shadow evaluation before canary activation.');
        }
        if(is_array($targetMetadata)&&isset($targetMetadata['modelComparison'])&&glasses_vision_canary_ready($pdo)){
            if(abs((float)$row['canary_percent']-5.0)>0.0001)
                throw new InvalidArgumentException('Comparison-aware production rollout must begin at the 5% canary stage.');
        }
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
        if(glasses_vision_canary_ready($pdo)){
            $targetPackage=glasses_vision_model_package_row($pdo,$org,(string)$row['target_public_id'],false);
            $targetMetadata=json_decode((string)($targetPackage['metadata_json']??'null'),true);
            if(is_array($targetMetadata)&&isset($targetMetadata['modelComparison'])){
                $expected=glasses_vision_canary_next_stage($previous);
                if($expected===null||abs($nextPercent-$expected)>0.0001)
                    throw new InvalidArgumentException('Canary advancement must follow the governed 5/10/25/50/100 stage sequence.');
                $health=glasses_vision_canary_health($pdo,$org,$publicId);
                if(!$health['promotionEligible'])
                    throw new InvalidArgumentException('Canary health is not eligible for advancement.');
                if(glasses_vision_drift_ready($pdo)){
                    $drift=glasses_vision_drift_rollout_summary($pdo,$org,$publicId);
                    if($drift['promotionBlocked'])throw new InvalidArgumentException('Production drift blocks canary advancement until the environment/model issue is resolved.');
                }
            }
        }

        $pdo->prepare("UPDATE glasses_vision_model_rollouts SET canary_percent=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$nextPercent,$org,(int)$row['id']]);
        glasses_vision_model_rollout_event($pdo,$org,(int)$row['id'],'advanced','active','active',$previous,$nextPercent,$userId);
        return glasses_vision_model_rollout_public(glasses_vision_model_rollout_row($pdo,$org,$publicId,false));
    });
}

function glasses_vision_model_rollout_advance_override(PDO $pdo,int $org,string $publicId,float $nextPercent,int $userId,string $reason): array
{
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('Canary advancement override requires a documented reason.');
    if(!is_finite($nextPercent)||$nextPercent<0||$nextPercent>100)throw new InvalidArgumentException('Canary percentage must be between 0 and 100.');
    $nextPercent=round($nextPercent,2);
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$nextPercent,$userId,$reason):array{
        $row=glasses_vision_model_rollout_row($pdo,$org,$publicId,true);
        if((string)$row['status']!=='active')throw new InvalidArgumentException('Only an active rollout can advance.');
        $previous=(float)$row['canary_percent'];$expected=glasses_vision_canary_next_stage($previous);
        if($expected===null||abs($nextPercent-$expected)>0.0001)throw new InvalidArgumentException('Override must still follow the governed canary stage sequence.');
        $health=glasses_vision_canary_health($pdo,$org,$publicId);
        if($health['state']==='rollback_required')throw new InvalidArgumentException('Rollback-required canary health cannot be overridden for advancement.');
        $pdo->prepare("UPDATE glasses_vision_model_rollouts SET canary_percent=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$nextPercent,$org,(int)$row['id']]);
        glasses_vision_model_rollout_event($pdo,$org,(int)$row['id'],'advanced_override','active','active',$previous,$nextPercent,$userId,['reason'=>$reason,'health'=>$health]);
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
    $result=['rolloutPublicId'=>$publicId,'byType'=>$byType];
    if(glasses_vision_canary_ready($pdo))$result['canaryHealth']=glasses_vision_canary_health($pdo,$org,$publicId);
    if(glasses_vision_drift_ready($pdo))$result['drift']=glasses_vision_drift_rollout_summary($pdo,$org,$publicId);
    return $result;
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
        'driftIncidents'=>glasses_vision_drift_incidents($pdo,$org),
        'locations'=>array_map(static fn(array $location):array=>[
            'id'=>(int)$location['id'],'name'=>(string)$location['name'],'primary'=>(bool)$location['is_primary']
        ],$locations),
        'stationsByLocation'=>$stations,
    ];
}
