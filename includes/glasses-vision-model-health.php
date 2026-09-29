<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-feedback.php';
require_once __DIR__.'/glasses-vision-models.php';

const GLASSES_VISION_MODEL_HEALTH_SCHEMA='gelato.vision_model_health.v1';

function glasses_vision_model_health_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_model_health_snapshots'");
    $q->execute();
    return (int)$q->fetchColumn()===1 && glasses_vision_models_ready($pdo) && glasses_vision_feedback_ready($pdo);
}

function glasses_vision_model_health_time(string $value,string $label): string
{
    $value=trim($value);
    if($value==='')throw new InvalidArgumentException($label.' is required.');
    try{$dt=new DateTimeImmutable($value);}catch(Throwable){throw new InvalidArgumentException($label.' is invalid.');}
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}

function glasses_vision_model_health_scope(PDO $pdo,int $org,array $input): array
{
    $packagePublic=trim((string)($input['modelPackagePublicId']??''));
    if($packagePublic==='')throw new InvalidArgumentException('Model package is required.');
    $package=glasses_vision_model_package_row($pdo,$org,$packagePublic,false);

    $locationId=isset($input['locationId'])&&(int)$input['locationId']>0?(int)$input['locationId']:null;
    if($locationId!==null)glasses_location($pdo,$org,$locationId);

    $stationId=null;$stationPublic=null;
    if(trim((string)($input['stationPublicId']??''))!==''){
        if($locationId===null)throw new InvalidArgumentException('Station-scoped health requires a location.');
        $station=glasses_station($pdo,$org,$locationId,(string)$input['stationPublicId']);
        $stationId=(int)$station['id'];$stationPublic=(string)$station['publicId'];
    }

    $deviceId=null;$devicePublic=null;
    if(trim((string)($input['devicePublicId']??''))!==''){
        $q=$pdo->prepare("SELECT id,public_id FROM glasses_devices WHERE organization_id=? AND public_id=? LIMIT 1");
        $q->execute([$org,trim((string)$input['devicePublicId'])]);$device=$q->fetch();
        if(!$device)throw new InvalidArgumentException('Vision health device was not found.');
        $deviceId=(int)$device['id'];$devicePublic=(string)$device['public_id'];
    }

    return [
      'packageId'=>(int)$package['id'],'packagePublicId'=>(string)$package['public_id'],'packageSha256'=>(string)$package['artifact_sha256'],
      'detectorName'=>(string)$package['detector_name'],'modelName'=>(string)$package['model_name'],'modelVersion'=>(string)$package['model_version'],
      'locationId'=>$locationId,'stationId'=>$stationId,'stationPublicId'=>$stationPublic,'deviceId'=>$deviceId,'devicePublicId'=>$devicePublic,
    ];
}

function glasses_vision_model_health_where(array $scope,string $alias,string $packageColumn,string $locationColumn,string $stationColumn,string $deviceColumn): array
{
    $where=["$alias.organization_id=?","$alias.$packageColumn=?"];$args=[];
    if($scope['locationId']!==null){$where[]="$alias.$locationColumn=?";$args[]=$scope['locationId'];}
    if($scope['stationId']!==null){$where[]="$alias.$stationColumn=?";$args[]=$scope['stationId'];}
    if($scope['deviceId']!==null){$where[]="$alias.$deviceColumn=?";$args[]=$scope['deviceId'];}
    return [$where,$args];
}

function glasses_vision_model_health_collect(PDO $pdo,int $org,array $scope,string $from,string $to): array
{
    [$driftWhere,$driftArgs]=glasses_vision_model_health_where($scope,'s','package_id','location_id','station_id','device_id');
    $driftWhere[]='s.created_at>=?';$driftWhere[]='s.created_at<?';
    $dq=$pdo->prepare("SELECT s.id,s.sample_key,s.assignment_id,s.rollout_id,s.build_session_id,s.created_at,
      s.observation_count,s.correction_count,s.low_confidence_count,s.confidence_mean,s.latency_mean_ms,
      s.drift_state,s.drift_score,s.reasons_json
      FROM glasses_vision_drift_samples s WHERE ".implode(' AND ',$driftWhere)." ORDER BY s.id");
    $dq->execute(array_merge([$org,$scope['packageId']],$driftArgs,[$from,$to]));$drift=$dq->fetchAll();

    [$errorWhere,$errorArgs]=glasses_vision_model_health_where($scope,'e','model_package_id','location_id','station_id','device_id');
    $errorWhere[]='e.occurred_at>=?';$errorWhere[]='e.occurred_at<?';
    $eq=$pdo->prepare("SELECT e.public_id,e.event_hash,e.event_key,e.error_type,e.outcome,e.confidence,e.occurred_at
      FROM glasses_vision_production_errors e WHERE ".implode(' AND ',$errorWhere)." ORDER BY e.id");
    $eq->execute(array_merge([$org,$scope['packageId']],$errorArgs,[$from,$to]));$errors=$eq->fetchAll();

    $observations=0;$corrections=0;$lowConfidence=0;$critical=0;$warning=0;$weightedConfidence=0.0;$confidenceWeight=0;$weightedLatency=0.0;$latencyWeight=0;
    $driftEvidence=[];
    foreach($drift as $r){
        $obs=max(0,(int)$r['observation_count']);$observations+=$obs;$corrections+=max(0,(int)$r['correction_count']);$lowConfidence+=max(0,(int)$r['low_confidence_count']);
        if((string)$r['drift_state']==='critical')$critical++;
        elseif(in_array((string)$r['drift_state'],['warning','degraded','drifting'],true))$warning++;
        if($r['confidence_mean']!==null){$w=max(1,$obs);$weightedConfidence+=(float)$r['confidence_mean']*$w;$confidenceWeight+=$w;}
        if($r['latency_mean_ms']!==null){$w=max(1,$obs);$weightedLatency+=(float)$r['latency_mean_ms']*$w;$latencyWeight+=$w;}
        $driftEvidence[]=[
          'id'=>(int)$r['id'],'sampleKey'=>$r['sample_key'],'assignmentId'=>(int)$r['assignment_id'],
          'rolloutId'=>$r['rollout_id']!==null?(int)$r['rollout_id']:null,'buildSessionId'=>(int)$r['build_session_id'],
          'observationCount'=>$obs,'correctionCount'=>(int)$r['correction_count'],'lowConfidenceCount'=>(int)$r['low_confidence_count'],
          'confidenceMean'=>$r['confidence_mean']!==null?(float)$r['confidence_mean']:null,'latencyMeanMs'=>$r['latency_mean_ms']!==null?(float)$r['latency_mean_ms']:null,
          'driftState'=>$r['drift_state'],'driftScore'=>(float)$r['drift_score'],'reasons'=>json_decode((string)($r['reasons_json']??'null'),true),
          'createdAt'=>$r['created_at'],
        ];
    }

    $errorEvidence=array_map(static fn($r)=>[
      'publicId'=>$r['public_id'],'eventHash'=>$r['event_hash'],'eventKey'=>$r['event_key'],'errorType'=>$r['error_type'],
      'outcome'=>$r['outcome'],'confidence'=>$r['confidence']!==null?(float)$r['confidence']:null,'occurredAt'=>$r['occurred_at'],
    ],$errors);

    $errorCount=count($errors);$den=max(1,$observations);
    $metrics=[
      'sampleCount'=>count($drift),'observationCount'=>$observations,'productionErrorCount'=>$errorCount,
      'correctionCount'=>$corrections,'lowConfidenceCount'=>$lowConfidence,'criticalDriftCount'=>$critical,'warningDriftCount'=>$warning,
      'meanConfidence'=>$confidenceWeight>0?round($weightedConfidence/$confidenceWeight,6):null,
      'meanLatencyMs'=>$latencyWeight>0?round($weightedLatency/$latencyWeight,4):null,
      'errorRate'=>round($errorCount/$den,6),'correctionRate'=>round($corrections/$den,6),'lowConfidenceRate'=>round($lowConfidence/$den,6),
    ];
    $state='healthy';
    if(count($drift)===0&&$errorCount===0)$state='insufficient';
    elseif($critical>0)$state='critical';
    elseif($metrics['errorRate']>=0.15||$metrics['correctionRate']>=0.20||$metrics['lowConfidenceRate']>=0.35)$state='degraded';
    elseif($warning>0||$metrics['errorRate']>=0.05||$metrics['correctionRate']>=0.10||$metrics['lowConfidenceRate']>=0.20)$state='watch';

    $source=[
      'schema'=>GLASSES_VISION_MODEL_HEALTH_SCHEMA,
      'scope'=>[
        'modelPackagePublicId'=>$scope['packagePublicId'],'modelArtifactSha256'=>$scope['packageSha256'],
        'locationId'=>$scope['locationId'],'stationPublicId'=>$scope['stationPublicId'],'devicePublicId'=>$scope['devicePublicId'],
      ],
      'window'=>['startedAt'=>$from,'endedAt'=>$to],
      'driftSamples'=>$driftEvidence,'productionErrors'=>$errorEvidence,
    ];
    return ['metrics'=>$metrics,'healthState'=>$state,'source'=>$source,'sourceFingerprint'=>hash('sha256',glasses_vision_training_release_json($source))];
}

function glasses_vision_model_health_snapshot(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_model_health_ready($pdo))throw new RuntimeException('Vision Lab V8 model-health migration is not installed.');
    $scope=glasses_vision_model_health_scope($pdo,$org,$input);
    $from=glasses_vision_model_health_time((string)($input['windowStartedAt']??''),'Health window start');
    $to=glasses_vision_model_health_time((string)($input['windowEndedAt']??''),'Health window end');
    if($from>=$to)throw new InvalidArgumentException('Health window end must be after the start.');

    $collected=glasses_vision_model_health_collect($pdo,$org,$scope,$from,$to);$m=$collected['metrics'];
    $payload=[
      'schema'=>GLASSES_VISION_MODEL_HEALTH_SCHEMA,'sourceFingerprint'=>$collected['sourceFingerprint'],
      'scope'=>$collected['source']['scope'],'window'=>$collected['source']['window'],
      'metrics'=>$m,'healthState'=>$collected['healthState'],
    ];
    $hash=hash('sha256',glasses_vision_training_release_json($payload));

    $where="organization_id=? AND package_id=? AND window_started_at=? AND window_ended_at=? AND source_fingerprint=?";
    $args=[$org,$scope['packageId'],$from,$to,$collected['sourceFingerprint']];
    foreach([['location_id',$scope['locationId']],['station_id',$scope['stationId']],['device_id',$scope['deviceId']]] as [$column,$value]){
        if($value===null)$where.=" AND $column IS NULL";else{$where.=" AND $column=?";$args[]=$value;}
    }
    $q=$pdo->prepare("SELECT public_id,snapshot_hash FROM glasses_vision_model_health_snapshots WHERE $where LIMIT 1");
    $q->execute($args);$existing=$q->fetch();
    if($existing){
        if(!hash_equals((string)$existing['snapshot_hash'],$hash))throw new InvalidArgumentException('Model health snapshot identity conflicts with different immutable evidence.');
        return glasses_vision_model_health_row($pdo,$org,(string)$existing['public_id']);
    }

    $public=glasses_public_id('vision-health');
    $pdo->prepare("INSERT INTO glasses_vision_model_health_snapshots
      (organization_id,public_id,package_id,location_id,station_id,device_id,window_started_at,window_ended_at,source_fingerprint,
       sample_count,observation_count,production_error_count,correction_count,low_confidence_count,critical_drift_count,warning_drift_count,
       mean_confidence,mean_latency_ms,error_rate,correction_rate,low_confidence_rate,health_state,metrics_json,snapshot_hash,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$org,$public,$scope['packageId'],$scope['locationId'],$scope['stationId'],$scope['deviceId'],$from,$to,$collected['sourceFingerprint'],
        $m['sampleCount'],$m['observationCount'],$m['productionErrorCount'],$m['correctionCount'],$m['lowConfidenceCount'],$m['criticalDriftCount'],$m['warningDriftCount'],
        $m['meanConfidence'],$m['meanLatencyMs'],$m['errorRate'],$m['correctionRate'],$m['lowConfidenceRate'],$collected['healthState'],
        glasses_vision_training_release_json(['source'=>$collected['source'],'metrics'=>$m]),$hash,$actor]);
    glasses_vision_lineage_edge($pdo,$org,'model_package',$scope['packagePublicId'],$scope['packageSha256'],'health_snapshot','model_health_snapshot',$public,$hash,[
      'sourceFingerprint'=>$collected['sourceFingerprint'],'healthState'=>$collected['healthState'],'windowStartedAt'=>$from,'windowEndedAt'=>$to,
      'locationId'=>$scope['locationId'],'stationPublicId'=>$scope['stationPublicId'],'devicePublicId'=>$scope['devicePublicId']
    ],$actor);
    return glasses_vision_model_health_row($pdo,$org,$public);
}

function glasses_vision_model_health_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT h.*,p.public_id package_public_id,p.artifact_sha256 package_sha256,p.model_name,p.model_version,
      s.public_id station_public_id,d.public_id device_public_id
      FROM glasses_vision_model_health_snapshots h
      JOIN glasses_vision_model_packages p ON p.id=h.package_id AND p.organization_id=h.organization_id
      LEFT JOIN kds_stations s ON s.id=h.station_id AND s.organization_id=h.organization_id
      LEFT JOIN glasses_devices d ON d.id=h.device_id AND d.organization_id=h.organization_id
      WHERE h.organization_id=? AND h.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Model health snapshot was not found.');
    return [
      'schema'=>GLASSES_VISION_MODEL_HEALTH_SCHEMA,'publicId'=>$r['public_id'],'snapshotHash'=>$r['snapshot_hash'],'sourceFingerprint'=>$r['source_fingerprint'],
      'modelPackagePublicId'=>$r['package_public_id'],'modelArtifactSha256'=>$r['package_sha256'],'modelName'=>$r['model_name'],'modelVersion'=>$r['model_version'],
      'locationId'=>$r['location_id']!==null?(int)$r['location_id']:null,'stationPublicId'=>$r['station_public_id'],'devicePublicId'=>$r['device_public_id'],
      'windowStartedAt'=>$r['window_started_at'],'windowEndedAt'=>$r['window_ended_at'],'healthState'=>$r['health_state'],
      'metrics'=>[
        'sampleCount'=>(int)$r['sample_count'],'observationCount'=>(int)$r['observation_count'],'productionErrorCount'=>(int)$r['production_error_count'],
        'correctionCount'=>(int)$r['correction_count'],'lowConfidenceCount'=>(int)$r['low_confidence_count'],
        'criticalDriftCount'=>(int)$r['critical_drift_count'],'warningDriftCount'=>(int)$r['warning_drift_count'],
        'meanConfidence'=>$r['mean_confidence']!==null?(float)$r['mean_confidence']:null,'meanLatencyMs'=>$r['mean_latency_ms']!==null?(float)$r['mean_latency_ms']:null,
        'errorRate'=>(float)$r['error_rate'],'correctionRate'=>(float)$r['correction_rate'],'lowConfidenceRate'=>(float)$r['low_confidence_rate'],
      ],
      'evidence'=>json_decode((string)$r['metrics_json'],true)?:[],'createdAt'=>$r['created_at'],
    ];
}

function glasses_vision_model_health_verify(PDO $pdo,int $org,string $publicId): array
{
    $row=glasses_vision_model_health_row($pdo,$org,$publicId);
    $payload=[
      'schema'=>GLASSES_VISION_MODEL_HEALTH_SCHEMA,'sourceFingerprint'=>$row['sourceFingerprint'],
      'scope'=>[
        'modelPackagePublicId'=>$row['modelPackagePublicId'],'modelArtifactSha256'=>$row['modelArtifactSha256'],
        'locationId'=>$row['locationId'],'stationPublicId'=>$row['stationPublicId'],'devicePublicId'=>$row['devicePublicId'],
      ],
      'window'=>['startedAt'=>$row['windowStartedAt'],'endedAt'=>$row['windowEndedAt']],
      'metrics'=>$row['metrics'],'healthState'=>$row['healthState'],
    ];
    $current=hash('sha256',glasses_vision_training_release_json($payload));
    $source=(array)($row['evidence']['source']??[]);
    $sourceHash=hash('sha256',glasses_vision_training_release_json($source));
    return [
      'passed'=>hash_equals($row['snapshotHash'],$current)&&hash_equals($row['sourceFingerprint'],$sourceHash),
      'snapshotHash'=>$row['snapshotHash'],'recomputedSnapshotHash'=>$current,
      'sourceFingerprint'=>$row['sourceFingerprint'],'recomputedSourceFingerprint'=>$sourceHash,
    ];
}

function glasses_vision_model_health_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_model_health_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_MODEL_HEALTH_SCHEMA,'snapshots'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_health_snapshots WHERE organization_id=? ORDER BY window_ended_at DESC,id DESC LIMIT 100");
    $q->execute([$org]);$rows=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$rows[]=glasses_vision_model_health_row($pdo,$org,(string)$p);
    $states=[];foreach($rows as $r)$states[$r['healthState']]=($states[$r['healthState']]??0)+1;ksort($states);
    return ['ready'=>true,'schema'=>GLASSES_VISION_MODEL_HEALTH_SCHEMA,'states'=>$states,'snapshots'=>$rows];
}
