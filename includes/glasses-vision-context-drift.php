<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-model-health.php';

const GLASSES_VISION_CONTEXT_DRIFT_SCHEMA='gelato.vision_context_drift.v1';

function glasses_vision_context_drift_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_context_drift_analyses'");
    $q->execute();
    return (int)$q->fetchColumn()===1 && glasses_vision_model_health_ready($pdo);
}

function glasses_vision_context_drift_baseline(PDO $pdo,int $org,array $snapshot): ?array
{
    if($snapshot['locationId']===null)return null;
    $packageId=(int)$snapshot['_packageId'];$locationId=(int)$snapshot['locationId'];$stationId=$snapshot['_stationId'];
    $sql="SELECT b.*,p.public_id package_public_id,p.artifact_sha256 package_sha256,s.public_id station_public_id
      FROM glasses_vision_drift_baselines b
      JOIN glasses_vision_model_packages p ON p.id=b.package_id AND p.organization_id=b.organization_id
      LEFT JOIN kds_stations s ON s.id=b.station_id AND s.organization_id=b.organization_id
      WHERE b.organization_id=? AND b.package_id=? AND b.location_id=? AND b.status='active'
        AND b.established_at<=?";
    $args=[$org,$packageId,$locationId,$snapshot['windowEndedAt']];
    if($stationId===null)$sql.=" AND b.station_id IS NULL";
    else{$sql.=" AND b.station_id=?";$args[]=$stationId;}
    $sql.=" ORDER BY b.established_at DESC,b.id DESC LIMIT 1";
    $q=$pdo->prepare($sql);$q->execute($args);$row=$q->fetch();
    return $row?:null;
}

function glasses_vision_context_drift_snapshot_db(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT h.*,p.public_id package_public_id,p.artifact_sha256 package_sha256,s.public_id station_public_id,d.public_id device_public_id
      FROM glasses_vision_model_health_snapshots h
      JOIN glasses_vision_model_packages p ON p.id=h.package_id AND p.organization_id=h.organization_id
      LEFT JOIN kds_stations s ON s.id=h.station_id AND s.organization_id=h.organization_id
      LEFT JOIN glasses_devices d ON d.id=h.device_id AND d.organization_id=h.organization_id
      WHERE h.organization_id=? AND h.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Model health snapshot was not found.');
    $verification=glasses_vision_model_health_verify($pdo,$org,(string)$r['public_id']);
    if(!$verification['passed'])throw new InvalidArgumentException('Context drift analysis requires an intact model-health snapshot.');
    return [
      'publicId'=>$r['public_id'],'snapshotHash'=>$r['snapshot_hash'],'sourceFingerprint'=>$r['source_fingerprint'],
      'packagePublicId'=>$r['package_public_id'],'packageSha256'=>$r['package_sha256'],'_packageId'=>(int)$r['package_id'],
      'locationId'=>$r['location_id']!==null?(int)$r['location_id']:null,'stationPublicId'=>$r['station_public_id'],'_stationId'=>$r['station_id']!==null?(int)$r['station_id']:null,
      'devicePublicId'=>$r['device_public_id'],'_deviceId'=>$r['device_id']!==null?(int)$r['device_id']:null,
      'windowStartedAt'=>$r['window_started_at'],'windowEndedAt'=>$r['window_ended_at'],'healthState'=>$r['health_state'],
      'metrics'=>[
        'sampleCount'=>(int)$r['sample_count'],'observationCount'=>(int)$r['observation_count'],'productionErrorCount'=>(int)$r['production_error_count'],
        'correctionRate'=>(float)$r['correction_rate'],'lowConfidenceRate'=>(float)$r['low_confidence_rate'],'errorRate'=>(float)$r['error_rate'],
        'meanConfidence'=>$r['mean_confidence']!==null?(float)$r['mean_confidence']:null,'meanLatencyMs'=>$r['mean_latency_ms']!==null?(float)$r['mean_latency_ms']:null,
      ]
    ];
}

function glasses_vision_context_drift_current(PDO $pdo,int $org,array $s): array
{
    $where=['organization_id=?','package_id=?','created_at>=?','created_at<?'];$args=[$org,$s['_packageId'],$s['windowStartedAt'],$s['windowEndedAt']];
    if($s['locationId']!==null){$where[]='location_id=?';$args[]=$s['locationId'];}
    if($s['_stationId']!==null){$where[]='station_id=?';$args[]=$s['_stationId'];}
    if($s['_deviceId']!==null){$where[]='device_id=?';$args[]=$s['_deviceId'];}
    $q=$pdo->prepare("SELECT sample_key,calibration_source_hash,menu_signature,ingredient_signature,frame_width,frame_height,pixel_format,
      brightness_mean,contrast_mean,camera_pitch,camera_yaw,camera_roll,confidence_mean,latency_mean_ms,observation_count,correction_count,low_confidence_count,created_at
      FROM glasses_vision_drift_samples WHERE ".implode(' AND ',$where)." ORDER BY id");
    $q->execute($args);$rows=$q->fetchAll();

    $means=['brightness'=>[],'contrast'=>[],'pitch'=>[],'yaw'=>[],'roll'=>[]];
    $sets=['calibration'=>[],'menu'=>[],'ingredient'=>[],'geometry'=>[]];
    $refs=[];
    foreach($rows as $r){
        foreach([['brightness','brightness_mean'],['contrast','contrast_mean'],['pitch','camera_pitch'],['yaw','camera_yaw'],['roll','camera_roll']] as [$k,$col])if($r[$col]!==null)$means[$k][]=(float)$r[$col];
        if($r['calibration_source_hash'])$sets['calibration'][(string)$r['calibration_source_hash']]=true;
        if($r['menu_signature'])$sets['menu'][(string)$r['menu_signature']]=true;
        if($r['ingredient_signature'])$sets['ingredient'][(string)$r['ingredient_signature']]=true;
        $geometry=implode('x',[(string)($r['frame_width']??''),(string)($r['frame_height']??''),(string)($r['pixel_format']??'')]);
        if($geometry!=='xx')$sets['geometry'][$geometry]=true;
        $e=[
          'sampleKey'=>$r['sample_key'],'calibrationSourceHash'=>$r['calibration_source_hash'],'menuSignature'=>$r['menu_signature'],
          'ingredientSignature'=>$r['ingredient_signature'],'frameWidth'=>$r['frame_width']!==null?(int)$r['frame_width']:null,
          'frameHeight'=>$r['frame_height']!==null?(int)$r['frame_height']:null,'pixelFormat'=>$r['pixel_format'],
          'brightnessMean'=>$r['brightness_mean']!==null?(float)$r['brightness_mean']:null,'contrastMean'=>$r['contrast_mean']!==null?(float)$r['contrast_mean']:null,
          'cameraPitch'=>$r['camera_pitch']!==null?(float)$r['camera_pitch']:null,'cameraYaw'=>$r['camera_yaw']!==null?(float)$r['camera_yaw']:null,
          'cameraRoll'=>$r['camera_roll']!==null?(float)$r['camera_roll']:null,'createdAt'=>$r['created_at'],
        ];
        $refs[]=['sampleKey'=>$r['sample_key'],'evidenceHash'=>hash('sha256',glasses_vision_training_release_json($e))];
    }
    $avg=static fn(array $v):?float=>$v?round(array_sum($v)/count($v),6):null;
    foreach($sets as $k=>$v){$sets[$k]=array_keys($v);sort($sets[$k],SORT_STRING);}
    $context=[
      'sampleCount'=>count($rows),'calibrationSourceHashes'=>$sets['calibration'],'menuSignatures'=>$sets['menu'],
      'ingredientSignatures'=>$sets['ingredient'],'geometries'=>$sets['geometry'],
      'brightnessMean'=>$avg($means['brightness']),'contrastMean'=>$avg($means['contrast']),
      'cameraPitchMean'=>$avg($means['pitch']),'cameraYawMean'=>$avg($means['yaw']),'cameraRollMean'=>$avg($means['roll']),
      'sampleRefs'=>$refs,
    ];
    return ['context'=>$context,'fingerprint'=>hash('sha256',glasses_vision_training_release_json($context))];
}

function glasses_vision_context_drift_baseline_evidence(?array $b): array
{
    if(!$b)return [];
    return [
      'packagePublicId'=>$b['package_public_id'],'packageArtifactSha256'=>$b['package_sha256'],'locationId'=>(int)$b['location_id'],
      'stationPublicId'=>$b['station_public_id'],'calibrationSourceHash'=>$b['calibration_source_hash'],'menuSignature'=>$b['menu_signature'],
      'ingredientSignature'=>$b['ingredient_signature'],'frameWidth'=>$b['frame_width']!==null?(int)$b['frame_width']:null,
      'frameHeight'=>$b['frame_height']!==null?(int)$b['frame_height']:null,'pixelFormat'=>$b['pixel_format'],
      'sampleCount'=>(int)$b['sample_count'],'brightnessMean'=>$b['brightness_mean']!==null?(float)$b['brightness_mean']:null,
      'contrastMean'=>$b['contrast_mean']!==null?(float)$b['contrast_mean']:null,'cameraPitchMean'=>$b['camera_pitch_mean']!==null?(float)$b['camera_pitch_mean']:null,
      'cameraYawMean'=>$b['camera_yaw_mean']!==null?(float)$b['camera_yaw_mean']:null,'cameraRollMean'=>$b['camera_roll_mean']!==null?(float)$b['camera_roll_mean']:null,
      'confidenceMean'=>$b['confidence_mean']!==null?(float)$b['confidence_mean']:null,'latencyMeanMs'=>$b['latency_mean_ms']!==null?(float)$b['latency_mean_ms']:null,
      'correctionRate'=>$b['correction_rate']!==null?(float)$b['correction_rate']:null,'lowConfidenceRate'=>$b['low_confidence_rate']!==null?(float)$b['low_confidence_rate']:null,
      'establishedAt'=>$b['established_at'],
    ];
}

function glasses_vision_context_drift_score(array $snapshot,array $current,?array $baseline): array
{
    if(!$baseline||$current['sampleCount']<3)return [
      'classification'=>'insufficient_context','contextScore'=>0.0,'performanceScore'=>0.0,
      'confidenceScore'=>$baseline?0.35:0.15,'signals'=>[],'explanation'=>'A comparable active baseline and at least three context samples are required.'
    ];

    $signals=[];$context=0.0;$performance=0.0;$complete=0;$possible=0;
    $addContext=static function(string $key,bool $changed,float $weight,mixed $baselineValue,mixed $currentValue)use(&$signals,&$context):void{
        if($changed)$context+=$weight;
        $signals[]=['key'=>$key,'kind'=>'context','changed'=>$changed,'weight'=>$weight,'baseline'=>$baselineValue,'current'=>$currentValue];
    };
    $differentSet=static fn(?string $base,array $values): bool=>$base!==null&&$values&&(!in_array($base,$values,true)||count($values)>1);

    $possible++;if($baseline['calibrationSourceHash']!==null&&$current['calibrationSourceHashes'])$complete++;
    $addContext('calibration_change',$differentSet($baseline['calibrationSourceHash'],$current['calibrationSourceHashes']),.20,$baseline['calibrationSourceHash'],$current['calibrationSourceHashes']);
    $possible++;if($baseline['menuSignature']!==null&&$current['menuSignatures'])$complete++;
    $addContext('menu_change',$differentSet($baseline['menuSignature'],$current['menuSignatures']),.12,$baseline['menuSignature'],$current['menuSignatures']);
    $possible++;if($baseline['ingredientSignature']!==null&&$current['ingredientSignatures'])$complete++;
    $addContext('ingredient_change',$differentSet($baseline['ingredientSignature'],$current['ingredientSignatures']),.12,$baseline['ingredientSignature'],$current['ingredientSignatures']);
    $geometry=($baseline['frameWidth']??'').'x'.($baseline['frameHeight']??'').'x'.($baseline['pixelFormat']??'');
    $possible++;if($baseline['frameWidth']!==null&&$baseline['frameHeight']!==null&&$baseline['pixelFormat']!==null&&$current['geometries'])$complete++;
    $addContext('camera_geometry_change',$geometry!=='xx'&&$current['geometries']&&(!in_array($geometry,$current['geometries'],true)||count($current['geometries'])>1),.16,$geometry,$current['geometries']);

    foreach([['brightness','brightnessMean',.10,.20],['contrast','contrastMean',.08,.20],['pitch','cameraPitchMean',.08,10.0],['yaw','cameraYawMean',.08,10.0],['roll','cameraRollMean',.06,10.0]] as [$key,$field,$weight,$threshold]){
        $possible++;$b=$baseline[$field]??null;$v=$current[$field]??null;if($b!==null&&$v!==null)$complete++;
        $delta=$b!==null&&$v!==null?abs((float)$v-(float)$b):0.0;$changed=$b!==null&&$v!==null&&$delta>=$threshold;
        $addContext($key.'_shift',$changed,$weight,$b,$v);
    }

    $perfSignals=[];
    $addPerf=static function(string $key,float $severity,float $weight,mixed $baselineValue,mixed $currentValue)use(&$perfSignals,&$performance):void{
        $severity=max(0,min(1,$severity));$performance+=$severity*$weight;
        $perfSignals[]=['key'=>$key,'kind'=>'performance','severity'=>round($severity,6),'weight'=>$weight,'baseline'=>$baselineValue,'current'=>$currentValue];
    };
    $bm=$snapshot['metrics'];
    $bConf=$baseline['confidenceMean'];$cConf=$bm['meanConfidence'];$possible++;if($bConf!==null&&$cConf!==null)$complete++;
    $addPerf('confidence_drop',$bConf!==null&&$cConf!==null?((float)$bConf-(float)$cConf)/.20:0,.25,$bConf,$cConf);
    $bLat=$baseline['latencyMeanMs'];$cLat=$bm['meanLatencyMs'];$possible++;if($bLat!==null&&$cLat!==null&&$bLat>0)$complete++;
    $addPerf('latency_increase',$bLat!==null&&$cLat!==null&&$bLat>0?(((float)$cLat-(float)$bLat)/(float)$bLat)/.50:0,.20,$bLat,$cLat);
    $bCorr=$baseline['correctionRate'];$possible++;if($bCorr!==null)$complete++;
    $addPerf('correction_rate_increase',$bCorr!==null?($bm['correctionRate']-(float)$bCorr)/.20:0,.20,$bCorr,$bm['correctionRate']);
    $bLow=$baseline['lowConfidenceRate'];$possible++;if($bLow!==null)$complete++;
    $addPerf('low_confidence_rate_increase',$bLow!==null?($bm['lowConfidenceRate']-(float)$bLow)/.30:0,.20,$bLow,$bm['lowConfidenceRate']);
    $possible++;$complete++;$addPerf('production_error_rate',$bm['errorRate']/.15,.15,0.0,$bm['errorRate']);

    $context=round(min(1,$context),6);$performance=round(min(1,$performance),6);
    $confidence=round(min(1,($possible?($complete/$possible):0)*min(1,$current['sampleCount']/10)),6);
    if($context>=.30&&$performance>=.30)$classification='mixed';
    elseif($context>=.30&&$performance<.30)$classification='context_shift';
    elseif($context<.20&&$performance>=.30)$classification='model_degradation';
    elseif($context<.20&&$performance<.20&&$snapshot['healthState']==='healthy')$classification='stable';
    else $classification='ambiguous';

    $explanation=match($classification){
      'context_shift'=>'Health changed alongside material environment/configuration changes without equivalent performance degradation.',
      'model_degradation'=>'Performance degraded while measured production context remained close to the established baseline.',
      'mixed'=>'Both production context and model-performance indicators changed materially.',
      'stable'=>'Context and performance remain close to the established baseline.',
      default=>'The evidence does not cleanly separate context change from model-performance change.',
    };
    return ['classification'=>$classification,'contextScore'=>$context,'performanceScore'=>$performance,'confidenceScore'=>$confidence,'signals'=>array_merge($signals,$perfSignals),'explanation'=>$explanation];
}

function glasses_vision_context_drift_analyze(PDO $pdo,int $org,string $snapshotPublic,int $actor): array
{
    if(!glasses_vision_context_drift_ready($pdo))throw new RuntimeException('Vision Lab V8 context-drift migration is not installed.');
    $snapshot=glasses_vision_context_drift_snapshot_db($pdo,$org,$snapshotPublic);
    $baselineRow=glasses_vision_context_drift_baseline($pdo,$org,$snapshot);$baseline=glasses_vision_context_drift_baseline_evidence($baselineRow);
    $currentBundle=glasses_vision_context_drift_current($pdo,$org,$snapshot);$current=$currentBundle['context'];
    $baselineFingerprint=hash('sha256',glasses_vision_training_release_json($baseline?:null));
    $scored=glasses_vision_context_drift_score($snapshot,$current,$baseline?:null);
    $evidence=[
      'schema'=>GLASSES_VISION_CONTEXT_DRIFT_SCHEMA,
      'healthSnapshot'=>['publicId'=>$snapshot['publicId'],'snapshotHash'=>$snapshot['snapshotHash'],'sourceFingerprint'=>$snapshot['sourceFingerprint']],
      'baseline'=>$baseline?:null,'currentContext'=>$current,
    ];
    $result=[
      'classification'=>$scored['classification'],'contextScore'=>$scored['contextScore'],'performanceScore'=>$scored['performanceScore'],
      'confidenceScore'=>$scored['confidenceScore'],'signals'=>$scored['signals'],'explanation'=>$scored['explanation'],
      'governance'=>['causalClaim'=>false,'automaticCalibration'=>false,'automaticThresholdChange'=>false,'automaticRolloutAction'=>false],
    ];
    $hash=hash('sha256',glasses_vision_training_release_json(['evidence'=>$evidence,'result'=>$result]));
    $q=$pdo->prepare("SELECT public_id,analysis_hash FROM glasses_vision_context_drift_analyses
      WHERE organization_id=? AND health_snapshot_id=(SELECT id FROM glasses_vision_model_health_snapshots WHERE organization_id=? AND public_id=?)
        AND baseline_fingerprint=? AND context_fingerprint=? LIMIT 1");
    $q->execute([$org,$org,$snapshot['publicId'],$baselineFingerprint,$currentBundle['fingerprint']]);$existing=$q->fetch();
    if($existing){
        if(!hash_equals((string)$existing['analysis_hash'],$hash))throw new InvalidArgumentException('Context-drift analysis identity conflicts with different immutable evidence.');
        return glasses_vision_context_drift_row($pdo,$org,(string)$existing['public_id']);
    }

    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$snapshot,$baselineRow,$baselineFingerprint,$currentBundle,$scored,$evidence,$result,$hash):array{
        $public=glasses_public_id('vision-context-drift');
        $snapshotId=(int)glasses_vision_context_drift_scalar($pdo,"SELECT id FROM glasses_vision_model_health_snapshots WHERE organization_id=? AND public_id=?",[$org,$snapshot['publicId']]);
        $pdo->prepare("INSERT INTO glasses_vision_context_drift_analyses
          (organization_id,public_id,health_snapshot_id,baseline_id,classification,context_score,performance_score,confidence_score,baseline_fingerprint,context_fingerprint,evidence_json,result_json,analysis_hash,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,$snapshotId,$baselineRow?(int)$baselineRow['id']:null,$scored['classification'],$scored['contextScore'],$scored['performanceScore'],$scored['confidenceScore'],
            $baselineFingerprint,$currentBundle['fingerprint'],glasses_vision_training_release_json($evidence),glasses_vision_training_release_json($result),$hash,$actor]);
        glasses_vision_lineage_edge($pdo,$org,'model_health_snapshot',$snapshot['publicId'],$snapshot['snapshotHash'],'context_analyzed_as','context_drift_analysis',$public,$hash,[
          'classification'=>$scored['classification'],'contextScore'=>$scored['contextScore'],'performanceScore'=>$scored['performanceScore'],'confidenceScore'=>$scored['confidenceScore']
        ],$actor);
        return glasses_vision_context_drift_row($pdo,$org,$public);
    });
}

function glasses_vision_context_drift_scalar(PDO $pdo,string $sql,array $args=[]): mixed
{
    $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();
}

function glasses_vision_context_drift_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT a.*,h.public_id health_public_id,h.snapshot_hash,b.established_at baseline_established_at
      FROM glasses_vision_context_drift_analyses a
      JOIN glasses_vision_model_health_snapshots h ON h.id=a.health_snapshot_id AND h.organization_id=a.organization_id
      LEFT JOIN glasses_vision_drift_baselines b ON b.id=a.baseline_id AND b.organization_id=a.organization_id
      WHERE a.organization_id=? AND a.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Context-drift analysis was not found.');
    return [
      'schema'=>GLASSES_VISION_CONTEXT_DRIFT_SCHEMA,'publicId'=>$r['public_id'],'healthSnapshotPublicId'=>$r['health_public_id'],'healthSnapshotHash'=>$r['snapshot_hash'],
      'classification'=>$r['classification'],'contextScore'=>(float)$r['context_score'],'performanceScore'=>(float)$r['performance_score'],
      'confidenceScore'=>(float)$r['confidence_score'],'baselineFingerprint'=>$r['baseline_fingerprint'],'contextFingerprint'=>$r['context_fingerprint'],
      'analysisHash'=>$r['analysis_hash'],'baselineEstablishedAt'=>$r['baseline_established_at'],
      'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],'result'=>json_decode((string)$r['result_json'],true)?:[],'createdAt'=>$r['created_at'],
    ];
}

function glasses_vision_context_drift_verify(PDO $pdo,int $org,string $publicId): array
{
    $r=glasses_vision_context_drift_row($pdo,$org,$publicId);
    $hash=hash('sha256',glasses_vision_training_release_json(['evidence'=>$r['evidence'],'result'=>$r['result']]));
    $baselineFingerprint=hash('sha256',glasses_vision_training_release_json($r['evidence']['baseline']??null));
    $currentFingerprint=hash('sha256',glasses_vision_training_release_json($r['evidence']['currentContext']??[]));
    $snapshot=glasses_vision_context_drift_snapshot_db($pdo,$org,(string)$r['healthSnapshotPublicId']);
    $current=glasses_vision_context_drift_current($pdo,$org,$snapshot);
    $healthEvidence=is_array($r['evidence']['healthSnapshot']??null)?$r['evidence']['healthSnapshot']:[];
    $passed=hash_equals($r['analysisHash'],$hash)
      &&hash_equals((string)$r['baselineFingerprint'],$baselineFingerprint)
      &&hash_equals((string)$r['contextFingerprint'],$currentFingerprint)
      &&hash_equals((string)$r['contextFingerprint'],$current['fingerprint'])
      &&hash_equals((string)($healthEvidence['snapshotHash']??''),(string)$snapshot['snapshotHash'])
      &&hash_equals((string)($healthEvidence['sourceFingerprint']??''),(string)$snapshot['sourceFingerprint']);
    return [
      'passed'=>$passed,'analysisHash'=>$r['analysisHash'],'recomputedAnalysisHash'=>$hash,
      'baselineFingerprint'=>$r['baselineFingerprint'],'recomputedBaselineFingerprint'=>$baselineFingerprint,
      'contextFingerprint'=>$r['contextFingerprint'],'recomputedContextFingerprint'=>$currentFingerprint,'liveContextFingerprint'=>$current['fingerprint'],
    ];
}

function glasses_vision_context_drift_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_context_drift_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_CONTEXT_DRIFT_SCHEMA,'analyses'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_context_drift_analyses WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
    $out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$out[]=glasses_vision_context_drift_row($pdo,$org,(string)$p);
    $counts=[];foreach($out as $r)$counts[$r['classification']]=($counts[$r['classification']]??0)+1;ksort($counts);
    return ['ready'=>true,'schema'=>GLASSES_VISION_CONTEXT_DRIFT_SCHEMA,'classifications'=>$counts,'analyses'=>$out];
}
