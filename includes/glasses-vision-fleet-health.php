<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-active-perception.php';

const GLASSES_VISION_FLEET_HEALTH_SCHEMA='gelato.vision_fleet_health.v1';

function glasses_vision_fleet_health_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_fleet_health_analyses','glasses_vision_fleet_health_actions'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_model_health_ready($pdo)&&glasses_vision_context_drift_ready($pdo);
}

function glasses_vision_fleet_health_time(string $value,string $label): string
{
    $value=trim($value);if($value==='')throw new InvalidArgumentException($label.' is required.');
    try{$dt=new DateTimeImmutable($value);}catch(Throwable){throw new InvalidArgumentException($label.' is invalid.');}
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}

function glasses_vision_fleet_health_scope(PDO $pdo,int $org,array $input): array
{
    $rolloutPublic=trim((string)($input['rolloutPublicId']??''));$packagePublic=trim((string)($input['modelPackagePublicId']??''));
    $rollout=null;$package=null;
    if($rolloutPublic!==''){
        $rollout=glasses_vision_model_rollout_row($pdo,$org,$rolloutPublic,false);
        if(empty($rollout['target_public_id']))throw new InvalidArgumentException('Fleet rollout target model was not found.');
        $package=glasses_vision_model_package_row($pdo,$org,(string)$rollout['target_public_id'],false);
        if($packagePublic!==''&&!hash_equals($packagePublic,(string)$package['public_id']))throw new InvalidArgumentException('Fleet rollout target does not match the requested model package.');
    }elseif($packagePublic!==''){
        $package=glasses_vision_model_package_row($pdo,$org,$packagePublic,false);
    }else throw new InvalidArgumentException('Fleet analysis requires a rollout or model package.');
    $scopeMaterial=['modelPackagePublicId'=>$package['public_id'],'modelArtifactSha256'=>$package['artifact_sha256'],'rolloutPublicId'=>$rollout['public_id']??null];
    return [
      'package'=>$package,'rollout'=>$rollout,'scopeKey'=>hash('sha256',glasses_vision_training_release_json($scopeMaterial)),
      'scopeMaterial'=>$scopeMaterial
    ];
}

function glasses_vision_fleet_health_collect(PDO $pdo,int $org,array $scope,string $from,string $to): array
{
    $package=$scope['package'];
    $q=$pdo->prepare("SELECT h.public_id FROM glasses_vision_model_health_snapshots h
      WHERE h.organization_id=? AND h.package_id=? AND h.window_started_at=? AND h.window_ended_at=?
      ORDER BY h.location_id,h.station_id,h.device_id,h.id LIMIT 1000");
    $q->execute([$org,(int)$package['id'],$from,$to]);$snapshotPublics=$q->fetchAll(PDO::FETCH_COLUMN);

    $snapshots=[];$snapshotIds=[];$devices=[];$locations=[];$stations=[];$healthCounts=[];$affectedDevices=[];$affectedLocations=[];
    foreach($snapshotPublics as $public){
        $row=glasses_vision_model_health_row($pdo,$org,(string)$public);
        if(!glasses_vision_model_health_verify($pdo,$org,(string)$public)['passed'])
            throw new InvalidArgumentException('Fleet analysis requires intact model-health snapshots.');
        $idq=$pdo->prepare("SELECT id FROM glasses_vision_model_health_snapshots WHERE organization_id=? AND public_id=?");
        $idq->execute([$org,$public]);$snapshotIds[]=(int)$idq->fetchColumn();
        if($row['devicePublicId']!==null)$devices[$row['devicePublicId']]=true;
        if($row['locationId']!==null)$locations[(string)$row['locationId']]=true;
        if($row['stationPublicId']!==null)$stations[$row['stationPublicId']]=true;
        $state=(string)$row['healthState'];$healthCounts[$state]=($healthCounts[$state]??0)+1;
        if(in_array($state,['degraded','critical'],true)){
            if($row['devicePublicId']!==null)$affectedDevices[$row['devicePublicId']]=true;
            if($row['locationId']!==null)$affectedLocations[(string)$row['locationId']]=true;
        }
        $snapshots[]=[
          'publicId'=>$row['publicId'],'snapshotHash'=>$row['snapshotHash'],'sourceFingerprint'=>$row['sourceFingerprint'],
          'devicePublicId'=>$row['devicePublicId'],'locationId'=>$row['locationId'],'stationPublicId'=>$row['stationPublicId'],
          'healthState'=>$state,'metrics'=>$row['metrics']
        ];
    }

    $analyses=[];$contextCounts=[];$modelDegradationDevices=[];$modelDegradationLocations=[];
    if($snapshotIds){
        $marks=implode(',',array_fill(0,count($snapshotIds),'?'));
        $aq=$pdo->prepare("SELECT public_id FROM glasses_vision_context_drift_analyses WHERE organization_id=? AND health_snapshot_id IN ($marks) ORDER BY id");
        $aq->execute(array_merge([$org],$snapshotIds));
        foreach($aq->fetchAll(PDO::FETCH_COLUMN) as $public){
            $row=glasses_vision_context_drift_row($pdo,$org,(string)$public);
            if(!glasses_vision_context_drift_verify($pdo,$org,(string)$public)['passed'])
                throw new InvalidArgumentException('Fleet analysis requires intact context-drift analyses.');
            $class=(string)$row['classification'];$contextCounts[$class]=($contextCounts[$class]??0)+1;
            $hq=$pdo->prepare("SELECT d.public_id device_public_id,h.location_id
              FROM glasses_vision_model_health_snapshots h LEFT JOIN glasses_devices d ON d.id=h.device_id AND d.organization_id=h.organization_id
              WHERE h.organization_id=? AND h.public_id=? LIMIT 1");
            $hq->execute([$org,$row['healthSnapshotPublicId']]);$scopeRow=$hq->fetch()?:[];
            if(in_array($class,['model_degradation','mixed'],true)){
                if(!empty($scopeRow['device_public_id']))$modelDegradationDevices[(string)$scopeRow['device_public_id']]=true;
                if($scopeRow['location_id']!==null)$modelDegradationLocations[(string)$scopeRow['location_id']]=true;
            }
            $analyses[]=[
              'publicId'=>$row['publicId'],'analysisHash'=>$row['analysisHash'],'healthSnapshotPublicId'=>$row['healthSnapshotPublicId'],
              'classification'=>$class,'contextScore'=>$row['contextScore'],'performanceScore'=>$row['performanceScore'],'confidenceScore'=>$row['confidenceScore']
            ];
        }
    }
    ksort($healthCounts);ksort($contextCounts);
    return [
      'snapshots'=>$snapshots,'contextAnalyses'=>$analyses,
      'counts'=>[
        'snapshots'=>count($snapshots),'devices'=>count($devices),'locations'=>count($locations),'stations'=>count($stations),
        'healthStates'=>$healthCounts,'contextClassifications'=>$contextCounts,
        'affectedDevices'=>count($affectedDevices),'affectedLocations'=>count($affectedLocations),
        'modelDegradationDevices'=>count($modelDegradationDevices),'modelDegradationLocations'=>count($modelDegradationLocations),
      ]
    ];
}

function glasses_vision_fleet_health_score(array $collected,?array $rollout): array
{
    $c=$collected['counts'];$total=max(1,(int)$c['snapshots']);
    $critical=(int)($c['healthStates']['critical']??0);$degraded=(int)($c['healthStates']['degraded']??0);$watch=(int)($c['healthStates']['watch']??0);
    $modelDeg=(int)($c['contextClassifications']['model_degradation']??0)+(int)($c['contextClassifications']['mixed']??0);
    $contextShift=(int)($c['contextClassifications']['context_shift']??0);
    $affected=$critical+$degraded;$affectedRatio=round($affected/$total,6);$modelRatio=round($modelDeg/$total,6);$contextRatio=round($contextShift/$total,6);
    if((int)$c['snapshots']<2||(int)$c['devices']<2)$state='insufficient';
    elseif((int)$c['affectedLocations']>=2&&(int)$c['affectedDevices']>=2&&($critical>0||$modelRatio>=.40))$state='critical';
    elseif($affectedRatio>=.40||$modelRatio>=.40)$state='degraded';
    elseif($contextRatio>=.50&&$modelDeg===0)$state='context_shift';
    elseif($affected>0||$watch>0||$modelDeg>0)$state='watch';
    else $state='healthy';

    $rolloutStatus=$rollout?(string)$rollout['status']:null;
    if($state==='critical'&&in_array($rolloutStatus,['active','paused'],true))$recommendation='rollback_review';
    elseif($state==='degraded'&&$rolloutStatus==='active')$recommendation='pause_review';
    elseif(in_array($state,['critical','degraded'],true))$recommendation='investigate_model';
    elseif($state==='context_shift')$recommendation='investigate_context';
    elseif($state==='insufficient')$recommendation='collect_more_health';
    elseif($state==='watch')$recommendation='observe';
    else $recommendation='maintain';

    return [
      'fleetState'=>$state,'recommendation'=>$recommendation,
      'metrics'=>[
        'affectedRatio'=>$affectedRatio,'modelDegradationRatio'=>$modelRatio,'contextShiftRatio'=>$contextRatio,
        'criticalSnapshots'=>$critical,'degradedSnapshots'=>$degraded,'watchSnapshots'=>$watch
      ],
      'guardrails'=>[
        'automaticRollback'=>false,'automaticPause'=>false,'automaticPromotion'=>false,'explicitHumanReasonRequiredForRollback'=>true
      ]
    ];
}

function glasses_vision_fleet_health_analyze(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_fleet_health_ready($pdo))throw new RuntimeException('Vision Lab V8 fleet-health migration is not installed.');
    $scope=glasses_vision_fleet_health_scope($pdo,$org,$input);
    $from=glasses_vision_fleet_health_time((string)($input['windowStartedAt']??''),'Fleet window start');
    $to=glasses_vision_fleet_health_time((string)($input['windowEndedAt']??''),'Fleet window end');
    if($from>=$to)throw new InvalidArgumentException('Fleet window end must be after the start.');
    $fd=new DateTimeImmutable($from,new DateTimeZone('UTC'));$td=new DateTimeImmutable($to,new DateTimeZone('UTC'));
    if(($td->getTimestamp()-$fd->getTimestamp())>2678400)throw new InvalidArgumentException('Fleet health windows may not exceed 31 days.');

    $collected=glasses_vision_fleet_health_collect($pdo,$org,$scope,$from,$to);
    $result=glasses_vision_fleet_health_score($collected,$scope['rollout']);
    $evidence=[
      'schema'=>GLASSES_VISION_FLEET_HEALTH_SCHEMA,'scope'=>$scope['scopeMaterial'],'window'=>['startedAt'=>$from,'endedAt'=>$to],
      'snapshots'=>$collected['snapshots'],'contextAnalyses'=>$collected['contextAnalyses'],'counts'=>$collected['counts']
    ];
    $sourceFingerprint=hash('sha256',glasses_vision_training_release_json(['snapshots'=>$collected['snapshots'],'contextAnalyses'=>$collected['contextAnalyses']]));
    $hash=hash('sha256',glasses_vision_training_release_json(['evidence'=>$evidence,'result'=>$result,'sourceFingerprint'=>$sourceFingerprint]));

    $rolloutId=$scope['rollout']?(int)$scope['rollout']['id']:null;
    $q=$pdo->prepare("SELECT public_id,analysis_hash FROM glasses_vision_fleet_health_analyses
      WHERE organization_id=? AND scope_key=? AND window_started_at=? AND window_ended_at=? AND source_fingerprint=? LIMIT 1");
    $q->execute([$org,$scope['scopeKey'],$from,$to,$sourceFingerprint]);$existing=$q->fetch();
    if($existing){
        if(!hash_equals((string)$existing['analysis_hash'],$hash))throw new InvalidArgumentException('Fleet health analysis identity conflicts with different immutable evidence.');
        return glasses_vision_fleet_health_row($pdo,$org,(string)$existing['public_id']);
    }

    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$scope,$rolloutId,$from,$to,$sourceFingerprint,$result,$evidence,$hash):array{
        $public=glasses_public_id('vision-fleet-health');
        $pdo->prepare("INSERT INTO glasses_vision_fleet_health_analyses
          (organization_id,public_id,package_id,rollout_id,scope_key,window_started_at,window_ended_at,source_fingerprint,fleet_state,recommendation,evidence_json,result_json,analysis_hash,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,(int)$scope['package']['id'],$rolloutId,$scope['scopeKey'],$from,$to,$sourceFingerprint,$result['fleetState'],$result['recommendation'],
            glasses_vision_training_release_json($evidence),glasses_vision_training_release_json($result),$hash,$actor]);
        glasses_vision_lineage_edge($pdo,$org,'model_package',(string)$scope['package']['public_id'],(string)$scope['package']['artifact_sha256'],'fleet_health_analyzed_as','fleet_health_analysis',$public,$hash,
          ['fleetState'=>$result['fleetState'],'recommendation'=>$result['recommendation'],'rolloutPublicId'=>$scope['rollout']['public_id']??null],$actor);
        return glasses_vision_fleet_health_row($pdo,$org,$public);
    });
}

function glasses_vision_fleet_health_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT f.*,p.public_id package_public_id,p.artifact_sha256 package_sha256,r.public_id rollout_public_id,r.status rollout_status,
      b.public_id baseline_package_public_id
      FROM glasses_vision_fleet_health_analyses f
      JOIN glasses_vision_model_packages p ON p.id=f.package_id AND p.organization_id=f.organization_id
      LEFT JOIN glasses_vision_model_rollouts r ON r.id=f.rollout_id AND r.organization_id=f.organization_id
      LEFT JOIN glasses_vision_model_packages b ON b.id=r.baseline_package_id AND b.organization_id=r.organization_id
      WHERE f.organization_id=? AND f.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Fleet health analysis was not found.');
    return [
      'schema'=>GLASSES_VISION_FLEET_HEALTH_SCHEMA,'publicId'=>$r['public_id'],'modelPackagePublicId'=>$r['package_public_id'],'modelArtifactSha256'=>$r['package_sha256'],
      'rolloutPublicId'=>$r['rollout_public_id'],'rolloutStatus'=>$r['rollout_status'],'baselinePackagePublicId'=>$r['baseline_package_public_id'],
      'windowStartedAt'=>$r['window_started_at'],'windowEndedAt'=>$r['window_ended_at'],'sourceFingerprint'=>$r['source_fingerprint'],
      'fleetState'=>$r['fleet_state'],'recommendation'=>$r['recommendation'],'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],
      'result'=>json_decode((string)$r['result_json'],true)?:[],'analysisHash'=>$r['analysis_hash'],'createdAt'=>$r['created_at']
    ];
}

function glasses_vision_fleet_health_verify(PDO $pdo,int $org,string $publicId): array
{
    $r=glasses_vision_fleet_health_row($pdo,$org,$publicId);
    $scope=glasses_vision_fleet_health_scope($pdo,$org,[
      'modelPackagePublicId'=>$r['modelPackagePublicId'],'rolloutPublicId'=>$r['rolloutPublicId']??''
    ]);
    $live=glasses_vision_fleet_health_collect($pdo,$org,$scope,(string)$r['windowStartedAt'],(string)$r['windowEndedAt']);
    $liveSource=hash('sha256',glasses_vision_training_release_json(['snapshots'=>$live['snapshots'],'contextAnalyses'=>$live['contextAnalyses']]));
    $storedSource=hash('sha256',glasses_vision_training_release_json([
      'snapshots'=>$r['evidence']['snapshots']??[],'contextAnalyses'=>$r['evidence']['contextAnalyses']??[]
    ]));
    $hash=hash('sha256',glasses_vision_training_release_json(['evidence'=>$r['evidence'],'result'=>$r['result'],'sourceFingerprint'=>$r['sourceFingerprint']]));
    $passed=hash_equals($r['analysisHash'],$hash)
      &&hash_equals($r['sourceFingerprint'],$storedSource)
      &&hash_equals($r['sourceFingerprint'],$liveSource);
    return [
      'passed'=>$passed,'analysisHash'=>$r['analysisHash'],'recomputedAnalysisHash'=>$hash,
      'sourceFingerprint'=>$r['sourceFingerprint'],'recomputedSourceFingerprint'=>$storedSource,'liveSourceFingerprint'=>$liveSource
    ];
}

function glasses_vision_fleet_health_action_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT a.*,f.public_id analysis_public_id,f.analysis_hash,r.public_id rollout_public_id
      FROM glasses_vision_fleet_health_actions a
      JOIN glasses_vision_fleet_health_analyses f ON f.id=a.analysis_id AND f.organization_id=a.organization_id
      JOIN glasses_vision_model_rollouts r ON r.id=a.rollout_id AND r.organization_id=a.organization_id
      WHERE a.organization_id=? AND a.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Fleet health action was not found.');
    return [
      'schema'=>GLASSES_VISION_FLEET_HEALTH_SCHEMA,'publicId'=>$r['public_id'],'analysisPublicId'=>$r['analysis_public_id'],'analysisHash'=>$r['analysis_hash'],
      'rolloutPublicId'=>$r['rollout_public_id'],'actionType'=>$r['action_type'],'status'=>$r['status'],'reason'=>$r['reason'],
      'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],'actionHash'=>$r['action_hash'],'createdAt'=>$r['created_at']
    ];
}

function glasses_vision_fleet_health_execute_rollback(PDO $pdo,int $org,string $analysisPublic,string $reason,int $actor): array
{
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('Governed rollback requires a documented reason.');
    $analysis=glasses_vision_fleet_health_row($pdo,$org,$analysisPublic);
    if(!glasses_vision_fleet_health_verify($pdo,$org,$analysisPublic)['passed'])throw new InvalidArgumentException('Fleet health analysis failed integrity verification.');
    if($analysis['recommendation']!=='rollback_review')throw new InvalidArgumentException('Fleet evidence does not currently support rollback review.');
    if($analysis['rolloutPublicId']===null)throw new InvalidArgumentException('Fleet health analysis is not bound to a rollout.');

    $rollout=glasses_vision_model_rollout_row($pdo,$org,(string)$analysis['rolloutPublicId'],false);
    if(!in_array((string)$rollout['status'],['active','paused'],true))throw new InvalidArgumentException('Only an active or paused rollout can be rolled back.');
    if(!hash_equals((string)$rollout['target_public_id'],(string)$analysis['modelPackagePublicId']))
        throw new InvalidArgumentException('Fleet analysis target model no longer matches the rollout.');

    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_fleet_health_actions
      WHERE organization_id=? AND analysis_id=(SELECT id FROM glasses_vision_fleet_health_analyses WHERE organization_id=? AND public_id=?)
        AND action_type='rollback' LIMIT 1");
    $q->execute([$org,$org,$analysisPublic]);$existing=$q->fetchColumn();
    if($existing)return ['action'=>glasses_vision_fleet_health_action_row($pdo,$org,(string)$existing),'rollout'=>glasses_vision_model_rollout_public($rollout)];

    $rollback=glasses_vision_model_rollout_rollback($pdo,$org,(string)$analysis['rolloutPublicId'],$actor,'Fleet health '.$analysisPublic.': '.$reason);
    $evidence=[
      'schema'=>GLASSES_VISION_FLEET_HEALTH_SCHEMA,'analysisPublicId'=>$analysisPublic,'analysisHash'=>$analysis['analysisHash'],
      'rolloutPublicId'=>$analysis['rolloutPublicId'],'targetModelPackagePublicId'=>$analysis['modelPackagePublicId'],
      'baselinePackagePublicId'=>$analysis['baselinePackagePublicId'],'reason'=>$reason,'rolloutResult'=>[
        'status'=>$rollback['status']??null,'canaryPercent'=>$rollback['canaryPercent']??null
      ]
    ];
    $hash=hash('sha256',glasses_vision_training_release_json($evidence));
    $action=glasses_transaction($pdo,function()use($pdo,$org,$actor,$analysisPublic,$analysis,$evidence,$hash,$reason):array{
        $aq=$pdo->prepare("SELECT id FROM glasses_vision_fleet_health_analyses WHERE organization_id=? AND public_id=?");$aq->execute([$org,$analysisPublic]);$analysisId=(int)$aq->fetchColumn();
        $rq=$pdo->prepare("SELECT id FROM glasses_vision_model_rollouts WHERE organization_id=? AND public_id=?");$rq->execute([$org,$analysis['rolloutPublicId']]);$rolloutId=(int)$rq->fetchColumn();
        $public=glasses_public_id('vision-fleet-action');
        $pdo->prepare("INSERT INTO glasses_vision_fleet_health_actions
          (organization_id,public_id,analysis_id,rollout_id,action_type,status,reason,evidence_json,action_hash,actor_user_id)
          VALUES (?,?,?,?, 'rollback','completed',?,?,?,?,?)")
          ->execute([$org,$public,$analysisId,$rolloutId,$reason,glasses_vision_training_release_json($evidence),$hash,$actor]);
        glasses_vision_lineage_edge($pdo,$org,'fleet_health_analysis',$analysisPublic,$analysis['analysisHash'],'governed_rollback_as','fleet_health_action',$public,$hash,
          ['rolloutPublicId'=>$analysis['rolloutPublicId'],'reason'=>$reason],$actor);
        return glasses_vision_fleet_health_action_row($pdo,$org,$public);
    });
    return ['action'=>$action,'rollout'=>$rollback];
}

function glasses_vision_fleet_health_action_verify(PDO $pdo,int $org,string $publicId): array
{
    $a=glasses_vision_fleet_health_action_row($pdo,$org,$publicId);
    $hash=hash('sha256',glasses_vision_training_release_json($a['evidence']));
    $passed=hash_equals($a['actionHash'],$hash)&&glasses_vision_fleet_health_verify($pdo,$org,$a['analysisPublicId'])['passed'];
    return ['passed'=>$passed,'actionHash'=>$a['actionHash'],'recomputedActionHash'=>$hash];
}

function glasses_vision_fleet_health_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_fleet_health_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_FLEET_HEALTH_SCHEMA,'analyses'=>[],'actions'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_fleet_health_analyses WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
    $analyses=[];$states=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p){$a=glasses_vision_fleet_health_row($pdo,$org,(string)$p);$analyses[]=$a;$states[$a['fleetState']]=($states[$a['fleetState']]??0)+1;}
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_fleet_health_actions WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
    $actions=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$actions[]=glasses_vision_fleet_health_action_row($pdo,$org,(string)$p);
    ksort($states);return ['ready'=>true,'schema'=>GLASSES_VISION_FLEET_HEALTH_SCHEMA,'states'=>$states,'analyses'=>$analyses,'actions'=>$actions];
}
