<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/glasses-vision-models.php';
require_once __DIR__.'/glasses-calibration.php';

function glasses_vision_ops_ready(PDO $pdo): bool
{
    foreach([
        'glasses_devices','glasses_device_events','glasses_vision_model_assignments',
        'glasses_vision_model_packages','glasses_vision_model_device_reports',
        'glasses_station_calibrations','glasses_vision_drift_samples','glasses_vision_drift_incidents'
    ] as $table){
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_vision_ops_age_seconds(?string $value): ?int
{
    if(!$value)return null;
    $ts=strtotime($value);
    return $ts===false?null:max(0,time()-$ts);
}

function glasses_vision_ops_device_health(array $device): array
{
    if((string)$device['deviceStatus']==='revoked')return ['state'=>'revoked','priority'=>90,'reason'=>'Device revoked'];
    $age=glasses_vision_ops_age_seconds($device['lastSeenAt']??null);
    if($age===null||$age>900)return ['state'=>'offline','priority'=>100,'reason'=>$age===null?'Never seen':'No heartbeat for more than 15 minutes'];
    if($age>300)return ['state'=>'stale','priority'=>80,'reason'=>'Heartbeat older than 5 minutes'];
    if(($device['driftState']??'')==='critical')return ['state'=>'critical','priority'=>100,'reason'=>'Critical production drift'];
    if(in_array((string)($device['driftState']??''),['drifted','watch'],true))return ['state'=>'warning','priority'=>80,'reason'=>'Production drift needs attention'];
    if(!empty($device['unresolvedIncident']))return ['state'=>'warning','priority'=>75,'reason'=>'Unresolved drift recovery'];
    if((string)($device['runtimeState']??'')==='failed'||!empty($device['runtimeErrorCode']))return ['state'=>'warning','priority'=>85,'reason'=>'Latest model runtime report failed'];
    if(($device['assignmentAction']??'')==='hold')return ['state'=>'warning','priority'=>70,'reason'=>'Model assignment is on hold'];
    if(empty($device['calibrationPublicId'])&&$device['stationPublicId']!==null)return ['state'=>'warning','priority'=>65,'reason'=>'Station has no active calibration'];
    return ['state'=>'healthy','priority'=>10,'reason'=>'Heartbeat, model and calibration signals are current'];
}

function glasses_vision_ops_devices(PDO $pdo,int $org): array
{
    $sql="SELECT d.id,d.public_id,d.display_name,d.platform,d.status device_status,d.hardware_identifier,
      d.location_id,l.name location_name,d.station_id,s.public_id station_public_id,s.name station_name,
      d.sdk_version,d.app_version,d.system_version,d.last_seen_at,d.paired_at,
      a.id assignment_id,a.assignment_key,a.detector_name,a.action assignment_action,a.selection,a.rollout_status,
      a.canary_percent,a.issued_at assignment_issued_at,
      p.public_id package_public_id,p.model_name,p.model_version,p.runtime_type,p.status package_status,
      r.public_id rollout_public_id,r.status current_rollout_status,r.canary_percent current_canary_percent,
      rep.report_type,rep.runtime_state,rep.error_code runtime_error_code,rep.message runtime_message,rep.created_at report_created_at,
      cal.public_id calibration_public_id,cal.version calibration_version,cal.source_hash calibration_source_hash,
      ds.drift_state,ds.drift_score,ds.created_at drift_created_at,
      di.public_id incident_public_id,di.severity incident_severity,di.category incident_category,di.recovery_status,
      di.validation_stable_samples,di.last_seen_at incident_last_seen_at,
      ua.public_id wearer_assignment_public_id,ua.assignment_role wearer_role,u.id wearer_user_id,u.display_name wearer_user_name
    FROM glasses_devices d
    JOIN locations l ON l.organization_id=d.organization_id AND l.id=d.location_id
    LEFT JOIN kds_stations s ON s.organization_id=d.organization_id AND s.id=d.station_id
    LEFT JOIN glasses_user_device_assignments ua ON ua.id=(SELECT ua2.id FROM glasses_user_device_assignments ua2 WHERE ua2.organization_id=d.organization_id AND ua2.device_id=d.id AND ua2.released_at IS NULL ORDER BY ua2.assigned_at DESC,ua2.id DESC LIMIT 1)
    LEFT JOIN users u ON u.id=ua.user_id
    LEFT JOIN glasses_vision_model_assignments a ON a.id=(
      SELECT a2.id FROM glasses_vision_model_assignments a2
      WHERE a2.organization_id=d.organization_id AND a2.device_id=d.id
      ORDER BY a2.issued_at DESC,a2.id DESC LIMIT 1
    )
    LEFT JOIN glasses_vision_model_packages p ON p.organization_id=d.organization_id AND p.id=a.package_id
    LEFT JOIN glasses_vision_model_rollouts r ON r.organization_id=d.organization_id AND r.id=a.rollout_id
    LEFT JOIN glasses_vision_model_device_reports rep ON rep.id=(
      SELECT rr.id FROM glasses_vision_model_device_reports rr
      WHERE rr.organization_id=d.organization_id AND rr.device_id=d.id
      ORDER BY rr.created_at DESC,rr.id DESC LIMIT 1
    )
    LEFT JOIN glasses_station_calibrations cal ON cal.id=(
      SELECT c2.id FROM glasses_station_calibrations c2
      WHERE c2.organization_id=d.organization_id AND c2.location_id=d.location_id
        AND ((d.station_id IS NULL AND c2.station_id IS NULL) OR c2.station_id=d.station_id)
        AND c2.status='active'
      ORDER BY c2.version DESC,c2.id DESC LIMIT 1
    )
    LEFT JOIN glasses_vision_drift_samples ds ON ds.id=(
      SELECT ds2.id FROM glasses_vision_drift_samples ds2
      WHERE ds2.organization_id=d.organization_id AND ds2.device_id=d.id
      ORDER BY ds2.created_at DESC,ds2.id DESC LIMIT 1
    )
    LEFT JOIN glasses_vision_drift_incidents di ON di.id=(
      SELECT di2.id FROM glasses_vision_drift_incidents di2
      WHERE di2.organization_id=d.organization_id AND di2.location_id=d.location_id
        AND ((d.station_id IS NULL AND di2.station_id IS NULL) OR di2.station_id=d.station_id)
        AND di2.recovery_status<>'resolved'
      ORDER BY FIELD(di2.severity,'critical','high','warning') ASC,di2.last_seen_at DESC,di2.id DESC LIMIT 1
    )
    WHERE d.organization_id=?
    ORDER BY d.status='active' DESC,l.name,s.name,d.display_name,d.id";
    $q=$pdo->prepare($sql);$q->execute([$org]);$rows=$q->fetchAll();
    $out=[];
    foreach($rows as $row){
        $item=[
            'publicId'=>(string)$row['public_id'],'displayName'=>(string)$row['display_name'],'platform'=>(string)$row['platform'],
            'deviceStatus'=>(string)$row['device_status'],'hardwareIdentifier'=>$row['hardware_identifier'],
            'locationId'=>(int)$row['location_id'],'locationName'=>(string)$row['location_name'],
            'stationPublicId'=>$row['station_public_id'],'stationName'=>$row['station_name'],
            'sdkVersion'=>$row['sdk_version'],'appVersion'=>$row['app_version'],'systemVersion'=>$row['system_version'],
            'wearer'=>$row['wearer_user_id']!==null?['assignmentPublicId'=>$row['wearer_assignment_public_id'],'userId'=>(int)$row['wearer_user_id'],'userName'=>$row['wearer_user_name'],'role'=>$row['wearer_role']]:null,
            'lastSeenAt'=>$row['last_seen_at'],'pairedAt'=>$row['paired_at'],
            'assignmentKey'=>$row['assignment_key'],'detectorName'=>$row['detector_name'],'assignmentAction'=>$row['assignment_action'],
            'selection'=>$row['selection'],'assignmentRolloutStatus'=>$row['rollout_status'],'assignmentIssuedAt'=>$row['assignment_issued_at'],
            'packagePublicId'=>$row['package_public_id'],'modelName'=>$row['model_name'],'modelVersion'=>$row['model_version'],
            'runtimeType'=>$row['runtime_type'],'packageStatus'=>$row['package_status'],
            'rolloutPublicId'=>$row['rollout_public_id'],'rolloutStatus'=>$row['current_rollout_status'],
            'canaryPercent'=>$row['current_canary_percent']!==null?(float)$row['current_canary_percent']:null,
            'reportType'=>$row['report_type'],'runtimeState'=>$row['runtime_state'],'runtimeErrorCode'=>$row['runtime_error_code'],
            'runtimeMessage'=>$row['runtime_message'],'reportCreatedAt'=>$row['report_created_at'],
            'calibrationPublicId'=>$row['calibration_public_id'],'calibrationVersion'=>$row['calibration_version']!==null?(int)$row['calibration_version']:null,
            'calibrationSourceHash'=>$row['calibration_source_hash'],
            'driftState'=>$row['drift_state'],'driftScore'=>$row['drift_score']!==null?(float)$row['drift_score']:null,'driftCreatedAt'=>$row['drift_created_at'],
            'unresolvedIncident'=>$row['incident_public_id']?[
                'publicId'=>(string)$row['incident_public_id'],'severity'=>(string)$row['incident_severity'],
                'category'=>(string)$row['incident_category'],'recoveryStatus'=>(string)$row['recovery_status'],
                'validationStableSamples'=>(int)$row['validation_stable_samples'],'lastSeenAt'=>$row['incident_last_seen_at'],
            ]:null,
        ];
        $item['heartbeatAgeSeconds']=glasses_vision_ops_age_seconds($item['lastSeenAt']);
        $item['health']=glasses_vision_ops_device_health($item);
        $out[]=$item;
    }
    usort($out,static fn(array $a,array $b):int=>($b['health']['priority']<=>$a['health']['priority'])?:strcmp($a['displayName'],$b['displayName']));
    return $out;
}

function glasses_vision_ops_timeline(PDO $pdo,int $org,int $limit=80): array
{
    $limit=max(10,min(200,$limit));
    $events=[];
    $queries=[
      ["SELECT de.created_at at,'device' kind,de.event_type event_type,d.public_id device_public_id,d.display_name device_name,
          l.name location_name,s.name station_name,de.metadata_json metadata_json
        FROM glasses_device_events de JOIN glasses_devices d ON d.organization_id=de.organization_id AND d.id=de.device_id
        LEFT JOIN locations l ON l.organization_id=de.organization_id AND l.id=de.location_id
        LEFT JOIN kds_stations s ON s.organization_id=de.organization_id AND s.id=de.station_id
        WHERE de.organization_id=? ORDER BY de.created_at DESC,de.id DESC LIMIT ".$limit],
      ["SELECT rr.created_at at,'runtime' kind,rr.report_type event_type,d.public_id device_public_id,d.display_name device_name,
          l.name location_name,s.name station_name,
          JSON_OBJECT('runtimeState',rr.runtime_state,'errorCode',rr.error_code,'message',rr.message) metadata_json
        FROM glasses_vision_model_device_reports rr JOIN glasses_devices d ON d.organization_id=rr.organization_id AND d.id=rr.device_id
        JOIN locations l ON l.organization_id=d.organization_id AND l.id=d.location_id
        LEFT JOIN kds_stations s ON s.organization_id=d.organization_id AND s.id=d.station_id
        WHERE rr.organization_id=? ORDER BY rr.created_at DESC,rr.id DESC LIMIT ".$limit],
      ["SELECT di.last_seen_at at,'drift' kind,CONCAT('drift_',di.recovery_status) event_type,NULL device_public_id,NULL device_name,
          l.name location_name,s.name station_name,
          JSON_OBJECT('publicId',di.public_id,'severity',di.severity,'category',di.category,'driftState',di.drift_state,'recoveryStatus',di.recovery_status) metadata_json
        FROM glasses_vision_drift_incidents di JOIN locations l ON l.organization_id=di.organization_id AND l.id=di.location_id
        LEFT JOIN kds_stations s ON s.organization_id=di.organization_id AND s.id=di.station_id
        WHERE di.organization_id=? ORDER BY di.last_seen_at DESC,di.id DESC LIMIT ".$limit],
    ];
    foreach($queries as [$sql]){
        $q=$pdo->prepare($sql);$q->execute([$org]);
        foreach($q->fetchAll() as $row){
            $events[]=[
              'at'=>$row['at'],'kind'=>$row['kind'],'eventType'=>$row['event_type'],'devicePublicId'=>$row['device_public_id'],
              'deviceName'=>$row['device_name'],'locationName'=>$row['location_name'],'stationName'=>$row['station_name'],
              'metadata'=>json_decode((string)($row['metadata_json']??'null'),true),
            ];
        }
    }
    usort($events,static fn(array $a,array $b):int=>strcmp((string)$b['at'],(string)$a['at']));
    return array_slice($events,0,$limit);
}

function glasses_vision_ops_catalog(PDO $pdo,array $user): array
{
    $org=(int)$user['organization_id'];
    if(!glasses_vision_ops_ready($pdo))return ['ready'=>false,'devices'=>[],'summary'=>[],'needsAttention'=>[],'timeline'=>[],'locations'=>[]];
    $devices=glasses_vision_ops_devices($pdo,$org);
    $summary=['total'=>count($devices),'healthy'=>0,'warning'=>0,'critical'=>0,'offline'=>0,'stale'=>0,'revoked'=>0,'rolledBack'=>0,'activeRollouts'=>0,'openIncidents'=>0];
    foreach($devices as $d){$state=(string)$d['health']['state'];if(isset($summary[$state]))$summary[$state]++;if((string)($d['rolloutStatus']??'')==='rolled_back'||(string)($d['assignmentRolloutStatus']??'')==='rolled_back')$summary['rolledBack']++;}
    $q=$pdo->prepare("SELECT COUNT(*) FROM glasses_vision_model_rollouts WHERE organization_id=? AND status='active'");$q->execute([$org]);$summary['activeRollouts']=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM glasses_vision_drift_incidents WHERE organization_id=? AND recovery_status<>'resolved'");$q->execute([$org]);$summary['openIncidents']=(int)$q->fetchColumn();
    $needs=array_values(array_filter($devices,static fn(array $d):bool=>$d['health']['state']!=='healthy'));
    $lq=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY is_primary DESC,sort_order,name,id");$lq->execute([$org]);
    return [
      'ready'=>true,'generatedAt'=>gmdate('c'),'devices'=>$devices,'summary'=>$summary,
      'needsAttention'=>array_slice($needs,0,30),'timeline'=>glasses_vision_ops_timeline($pdo,$org,80),
      'locations'=>$lq->fetchAll(),'links'=>[
        'models'=>'glasses-vision-models.php','calibration'=>'glasses-calibration-studio.php','learning'=>'glasses-learning.php','lab'=>'glasses-vision-lab.php','labels'=>'glasses-vision-profiles.php'
      ],
    ];
}
