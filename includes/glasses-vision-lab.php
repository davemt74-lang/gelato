<?php
declare(strict_types=1);
require_once __DIR__.'/glasses-learning.php';
require_once __DIR__.'/operational-access.php';

/** The Lab pools evidence and model governance across restaurant locations. */
function glasses_vision_lab_org_access(?PDO $pdo,array $user,string $permission): bool {
    if(in_array('*',$user['permissions']??[],true))return true;
    if(!in_array($permission,$user['permissions']??[],true))return false;
    return operational_location_scope($pdo??app_pdo(),$user,$permission)===null;
}

function glasses_vision_lab_ready(PDO $pdo): bool {
    foreach(['glasses_user_device_assignments','glasses_vision_training_missions','glasses_vision_training_samples','glasses_vision_dataset_versions','glasses_vision_dataset_items'] as $t){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$t]);if((int)$q->fetchColumn()!==1)return false;
    } return true;
}
function glasses_vision_lab_user(PDO $pdo,int $org,int $userId): array {
    $q=$pdo->prepare("SELECT u.id,u.display_name,u.email,m.employee_number,m.job_title FROM organization_memberships m JOIN users u ON u.id=m.user_id WHERE m.organization_id=? AND m.user_id=? AND m.status='active' AND u.status='active' AND u.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$userId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Active organization user was not found.');return $r;
}
function glasses_vision_lab_assign_device(PDO $pdo,int $org,string $devicePublicId,int $userId,string $role,string $source,int $actor): array {
    $role=in_array($role,['operator','trainer','reviewer','calibration_operator'],true)?$role:'operator';
    $source=in_array($source,['manual','shift','login','pin','badge'],true)?$source:'manual';
    $u=glasses_vision_lab_user($pdo,$org,$userId);$d=glasses_device_row($pdo,$org,$devicePublicId,false);
    return glasses_transaction($pdo,function()use($pdo,$org,$d,$u,$role,$source,$actor):array{
        $pdo->prepare("UPDATE glasses_user_device_assignments SET released_at=COALESCE(released_at,NOW(6)),release_reason=COALESCE(release_reason,'reassigned') WHERE organization_id=? AND device_id=? AND released_at IS NULL")->execute([$org,(int)$d['id']]);
        $public=glasses_public_id('glasses-user');
        $pdo->prepare("INSERT INTO glasses_user_device_assignments (organization_id,public_id,device_id,user_id,location_id,station_id,assignment_role,assignment_source,assigned_by) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$org,$public,(int)$d['id'],(int)$u['id'],(int)$d['location_id'],$d['station_id']!==null?(int)$d['station_id']:null,$role,$source,$actor]);
        return ['publicId'=>$public,'devicePublicId'=>(string)$d['public_id'],'userId'=>(int)$u['id'],'userName'=>(string)$u['display_name'],'role'=>$role,'source'=>$source];
    });
}
function glasses_vision_lab_release_device(PDO $pdo,int $org,string $publicId,string $reason,int $actor): array {
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$reason,$actor):array{
        $q=$pdo->prepare("SELECT a.*,d.public_id device_public_id,u.display_name user_name FROM glasses_user_device_assignments a JOIN glasses_devices d ON d.id=a.device_id AND d.organization_id=a.organization_id LEFT JOIN users u ON u.id=a.user_id AND EXISTS (SELECT 1 FROM organization_memberships m WHERE m.user_id=u.id AND m.organization_id=a.organization_id) WHERE a.organization_id=? AND a.public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Glasses user assignment was not found.');
        $pdo->prepare("UPDATE glasses_user_device_assignments SET released_at=COALESCE(released_at,NOW(6)),release_reason=? WHERE organization_id=? AND id=?")->execute([mb_substr(trim($reason),0,500),$org,(int)$r['id']]);
        return ['publicId'=>$publicId,'devicePublicId'=>$r['device_public_id'],'userName'=>$r['user_name'],'released'=>true];
    });
}
function glasses_vision_lab_assignments(PDO $pdo,int $org): array {
    $q=$pdo->prepare("SELECT a.public_id,a.assignment_role,a.assignment_source,a.assigned_at,a.released_at,a.release_reason,d.public_id device_public_id,d.display_name device_name,u.id user_id,u.display_name user_name,l.name location_name,s.name station_name
      FROM glasses_user_device_assignments a JOIN glasses_devices d ON d.id=a.device_id AND d.organization_id=a.organization_id
      JOIN locations l ON l.id=a.location_id AND l.organization_id=a.organization_id
      LEFT JOIN kds_stations s ON s.id=a.station_id AND s.organization_id=a.organization_id AND s.location_id=l.id
      LEFT JOIN users u ON u.id=a.user_id AND EXISTS (SELECT 1 FROM organization_memberships m WHERE m.user_id=u.id AND m.organization_id=a.organization_id)
      WHERE a.organization_id=? ORDER BY a.released_at IS NULL DESC,a.assigned_at DESC LIMIT 200");$q->execute([$org]);
    return array_map(static fn($r)=>['publicId'=>$r['public_id'],'role'=>$r['assignment_role'],'source'=>$r['assignment_source'],'assignedAt'=>$r['assigned_at'],'releasedAt'=>$r['released_at'],'devicePublicId'=>$r['device_public_id'],'deviceName'=>$r['device_name'],'userId'=>$r['user_id']!==null?(int)$r['user_id']:null,'userName'=>$r['user_name'],'locationName'=>$r['location_name'],'stationName'=>$r['station_name']],$q->fetchAll());
}
function glasses_vision_lab_create_mission(PDO $pdo,int $org,array $in,int $actor): array {
    $title=mb_substr(trim((string)($in['title']??'')),0,190);if($title==='')throw new InvalidArgumentException('Mission title is required.');
    $assignedUser=null;
    if(isset($in['assignedUserId'])&&$in['assignedUserId']!==''){
        $assignedUser=filter_var($in['assignedUserId'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$assignedUser)throw new InvalidArgumentException('Active organization user was not found.');
        glasses_vision_lab_user($pdo,$org,$assignedUser);
    }
    $device=null;
    if(!empty($in['assignedDevicePublicId']))$device=glasses_device_row($pdo,$org,(string)$in['assignedDevicePublicId'],false);
    $station=null;
    if(!empty($in['stationPublicId'])){
        $q=$pdo->prepare('SELECT id,location_id FROM kds_stations WHERE organization_id=? AND public_id=? LIMIT 1');
        $q->execute([$org,(string)$in['stationPublicId']]);$station=$q->fetch();
        if(!$station)throw new InvalidArgumentException('Vision mission station was not found.');
    }
    $location=$in['locationId']??null;
    if($location===null||$location==='')$location=$station['location_id']??$device['location_id']??null;
    if($location!==null){
        $location=filter_var($location,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $q=$pdo->prepare('SELECT id FROM locations WHERE organization_id=? AND id=?');
        $q->execute([$org,$location?:0]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Vision mission location was not found.');
    }
    if($station!==null&&(int)$station['location_id']!==$location)
        throw new InvalidArgumentException('Vision mission station is outside the selected location.');
    if($device!==null&&((int)$device['location_id']!==$location
        ||($station!==null&&$device['station_id']!==null&&(int)$station['id']!==(int)$device['station_id'])))
        throw new InvalidArgumentException('Assigned glasses are outside the selected mission location or station.');
    $public=glasses_public_id('vision-mission');$conditions=is_array($in['conditions']??null)?$in['conditions']:[];
    $pdo->prepare("INSERT INTO glasses_vision_training_missions (organization_id,public_id,title,description,status,source_type,source_reference,location_id,station_id,assigned_user_id,assigned_device_id,target_samples,target_positive,target_negative,target_hard_examples,target_conditions_json,created_by,due_at) VALUES (?,?,?,?,'active',?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$org,$public,$title,mb_substr(trim((string)($in['description']??'')),0,2000),(string)($in['sourceType']??'manual'),mb_substr(trim((string)($in['sourceReference']??'')),0,190)?:null,$location,$station['id']??null,$assignedUser,$device['id']??null,max(0,(int)($in['targetSamples']??0)),max(0,(int)($in['targetPositive']??0)),max(0,(int)($in['targetNegative']??0)),max(0,(int)($in['targetHardExamples']??0)),json_encode($conditions,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor,!empty($in['dueAt'])?(string)$in['dueAt']:null]);
    return glasses_vision_lab_mission($pdo,$org,$public);
}
function glasses_vision_lab_mission(PDO $pdo,int $org,string $publicId): array {
    $q=$pdo->prepare("SELECT m.*,u.id visible_user_id,u.display_name assigned_user_name,d.public_id assigned_device_public_id,d.display_name assigned_device_name,l.name location_name,s.name station_name,
      (SELECT COUNT(*) FROM glasses_vision_training_samples x WHERE x.organization_id=m.organization_id AND x.mission_id=m.id) sample_count,
      (SELECT COUNT(*) FROM glasses_vision_training_samples x WHERE x.organization_id=m.organization_id AND x.mission_id=m.id AND x.review_status='approved') approved_count
      FROM glasses_vision_training_missions m
      LEFT JOIN users u ON u.id=m.assigned_user_id AND EXISTS (SELECT 1 FROM organization_memberships om WHERE om.user_id=u.id AND om.organization_id=m.organization_id)
      LEFT JOIN glasses_devices d ON d.id=m.assigned_device_id AND d.organization_id=m.organization_id
      LEFT JOIN locations l ON l.id=m.location_id AND l.organization_id=m.organization_id
      LEFT JOIN kds_stations s ON s.id=m.station_id AND s.organization_id=m.organization_id AND (m.location_id IS NULL OR s.location_id=m.location_id)
      WHERE m.organization_id=? AND m.public_id=? LIMIT 1");$q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Vision training mission was not found.');
    return ['publicId'=>$r['public_id'],'title'=>$r['title'],'description'=>$r['description'],'status'=>$r['status'],'sourceType'=>$r['source_type'],'sourceReference'=>$r['source_reference'],'locationName'=>$r['location_name'],'stationName'=>$r['station_name'],'assignedUserId'=>$r['visible_user_id']!==null?(int)$r['visible_user_id']:null,'assignedUserName'=>$r['assigned_user_name'],'assignedDevicePublicId'=>$r['assigned_device_public_id'],'assignedDeviceName'=>$r['assigned_device_name'],'targetSamples'=>(int)$r['target_samples'],'targetPositive'=>(int)$r['target_positive'],'targetNegative'=>(int)$r['target_negative'],'targetHardExamples'=>(int)$r['target_hard_examples'],'sampleCount'=>(int)$r['sample_count'],'approvedCount'=>(int)$r['approved_count'],'dueAt'=>$r['due_at']];
}
function glasses_vision_lab_missions(PDO $pdo,int $org): array {$q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_missions WHERE organization_id=? ORDER BY FIELD(status,'active','draft','completed','cancelled'),created_at DESC LIMIT 100");$q->execute([$org]);return array_map(fn($id)=>glasses_vision_lab_mission($pdo,$org,(string)$id),$q->fetchAll(PDO::FETCH_COLUMN));}
function glasses_vision_lab_synthetic_observation_sql(string $alias='o'): string {
    if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$alias))throw new InvalidArgumentException('Invalid observation query alias.');
    // Only the browser's fabricated detection buttons used this source and key prefix.
    // Camera-targeted and automatic observations have their own provenance.
    return "(COALESCE(LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT($alias.metadata_json,'$.source')))),'')='web_glasses_simulator'
      OR COALESCE(JSON_TYPE(JSON_EXTRACT($alias.metadata_json,'$.synthetic'))='BOOLEAN'
        AND JSON_UNQUOTE(JSON_EXTRACT($alias.metadata_json,'$.synthetic'))='true',FALSE)
      OR COALESCE($alias.observation_key LIKE 'websim-%',FALSE))";
}
function glasses_vision_lab_queue_observation(PDO $pdo,int $org,string $observationKey,?string $missionPublic,int $actor): array {
    $q=$pdo->prepare("SELECT id FROM glasses_build_observations WHERE organization_id=? AND observation_key=? LIMIT 2");
    $q->execute([$org,$observationKey]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
    if(!$ids)throw new InvalidArgumentException('Vision observation was not found.');
    if(count($ids)>1)throw new InvalidArgumentException('Vision observation key matches more than one build. Use corrected evidence import to select the exact observation.');
    return glasses_vision_lab_queue_observation_by_id($pdo,$org,(int)$ids[0],$missionPublic,$actor);
}
function glasses_vision_lab_evidence_mission(PDO $pdo,int $org,?string $publicId): ?array {
    if($publicId===null)return null;
    $q=$pdo->prepare('SELECT id,location_id,station_id FROM glasses_vision_training_missions WHERE organization_id=? AND public_id=?');
    $q->execute([$org,$publicId]);$mission=$q->fetch();
    if(!$mission)throw new InvalidArgumentException('Vision training mission was not found.');
    return $mission;
}
function glasses_vision_lab_evidence_station_sql(): string {
    // Bound builds retain the station where evidence was captured after a reroute.
    return "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(s.context_json,'$.liveBinding.order.stationId')) AS UNSIGNED),k.station_id)";
}
function glasses_vision_lab_queue_observation_by_id(PDO $pdo,int $org,int $observationId,?string $missionPublic,int $actor): array {
    $synthetic=glasses_vision_lab_synthetic_observation_sql();
    $station=glasses_vision_lab_evidence_station_sql();
    $q=$pdo->prepare("SELECT o.id,o.observation_key,o.component_key,o.created_at,s.device_id,s.public_id build_public_id,k.location_id,$station AS evidence_station_id,$synthetic AS is_synthetic
      FROM glasses_build_observations o JOIN glasses_build_sessions s ON s.id=o.build_session_id AND s.organization_id=o.organization_id
      JOIN kds_order_items k ON k.id=s.kds_order_item_id AND k.organization_id=s.organization_id
      WHERE o.organization_id=? AND o.id=?");
    $q->execute([$org,$observationId]);$o=$q->fetch();if(!$o)throw new InvalidArgumentException('Vision observation was not found.');
    if((bool)$o['is_synthetic'])throw new InvalidArgumentException('Simulated browser observations cannot be queued as production evidence.');
    $observationKey=(string)$o['observation_key'];
    $mission=glasses_vision_lab_evidence_mission($pdo,$org,$missionPublic);
    if((isset($mission['location_id'])&&(int)$mission['location_id']!==(int)$o['location_id'])
        ||(isset($mission['station_id'])&&(int)$mission['station_id']!==(int)$o['evidence_station_id']))
        throw new InvalidArgumentException('Vision observation is outside the selected mission location or station.');
    $aq=$pdo->prepare("SELECT a.id,a.user_id FROM glasses_user_device_assignments a JOIN organization_memberships m ON m.user_id=a.user_id AND m.organization_id=a.organization_id
      WHERE a.organization_id=? AND a.device_id=? AND a.location_id=? AND a.assigned_at<=? AND (a.released_at IS NULL OR a.released_at>?) ORDER BY a.assigned_at DESC,a.id DESC LIMIT 1");$aq->execute([$org,(int)$o['device_id'],(int)$o['location_id'],$o['created_at'],$o['created_at']]);$a=$aq->fetch();
    $public=glasses_public_id('vision-sample');
    $pdo->prepare("INSERT INTO glasses_vision_training_samples (organization_id,public_id,mission_id,observation_id,source_type,source_reference,wearer_assignment_id,operator_user_id,review_status,canonical_label,created_by,provenance_json) VALUES (?,?,?,?,?,?,?,?, 'pending',?,?,?) ON DUPLICATE KEY UPDATE mission_id=COALESCE(VALUES(mission_id),mission_id)")
      ->execute([$org,$public,$mission['id']??null,(int)$o['id'],'production_observation',$observationKey,$a?(int)$a['id']:null,$a?(int)$a['user_id']:null,(string)$o['component_key'],$actor,json_encode(['observationKey'=>$observationKey,'buildSessionPublicId'=>$o['build_public_id']],JSON_UNESCAPED_SLASHES)]);
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_samples WHERE organization_id=? AND observation_id=? LIMIT 1");$q->execute([$org,(int)$o['id']]);return glasses_vision_lab_sample($pdo,$org,(string)$q->fetchColumn());
}
function glasses_vision_lab_sample(PDO $pdo,int $org,string $publicId): array {
    $q=$pdo->prepare("SELECT s.*,m.public_id mission_public_id,m.title mission_title,op.id visible_operator_id,op.display_name operator_name,an.display_name annotator_name,rv.display_name reviewer_name,ad.display_name adjudicator_name
      FROM glasses_vision_training_samples s LEFT JOIN glasses_vision_training_missions m ON m.id=s.mission_id AND m.organization_id=s.organization_id
      LEFT JOIN users op ON op.id=s.operator_user_id AND EXISTS (SELECT 1 FROM organization_memberships uo WHERE uo.user_id=op.id AND uo.organization_id=s.organization_id)
      LEFT JOIN users an ON an.id=s.annotator_user_id AND EXISTS (SELECT 1 FROM organization_memberships ua WHERE ua.user_id=an.id AND ua.organization_id=s.organization_id)
      LEFT JOIN users rv ON rv.id=s.reviewer_user_id AND EXISTS (SELECT 1 FROM organization_memberships ur WHERE ur.user_id=rv.id AND ur.organization_id=s.organization_id)
      LEFT JOIN users ad ON ad.id=s.adjudicator_user_id AND EXISTS (SELECT 1 FROM organization_memberships ud WHERE ud.user_id=ad.id AND ud.organization_id=s.organization_id)
      WHERE s.organization_id=? AND s.public_id=? LIMIT 1");$q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Vision training sample was not found.');
    return ['publicId'=>$r['public_id'],'missionPublicId'=>$r['mission_public_id'],'missionTitle'=>$r['mission_title'],'sourceType'=>$r['source_type'],'sourceReference'=>$r['source_reference'],'reviewStatus'=>$r['review_status'],'reviewOutcome'=>$r['review_outcome'],'canonicalLabel'=>$r['canonical_label'],'operatorUserId'=>$r['visible_operator_id']!==null?(int)$r['visible_operator_id']:null,'operatorName'=>$r['operator_name'],'annotatorName'=>$r['annotator_name'],'reviewerName'=>$r['reviewer_name'],'adjudicatorName'=>$r['adjudicator_name'],'notes'=>$r['notes'],'reviewedAt'=>$r['reviewed_at'],'createdAt'=>$r['created_at']];
}
function glasses_vision_lab_samples(PDO $pdo,int $org): array {$q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_samples WHERE organization_id=? ORDER BY FIELD(review_status,'needs_adjudication','pending','approved','rejected'),created_at DESC LIMIT 250");$q->execute([$org]);return array_map(fn($id)=>glasses_vision_lab_sample($pdo,$org,(string)$id),$q->fetchAll(PDO::FETCH_COLUMN));}
function glasses_vision_lab_review_sample(PDO $pdo,int $org,string $publicId,array $in,int $reviewer): array {
    $decision=(string)($in['decision']??'');if(!in_array($decision,['approve','reject','relabel','needs_adjudication'],true))throw new InvalidArgumentException('Invalid sample review decision.');
    $status=$decision==='approve'||$decision==='relabel'?'approved':($decision==='reject'?'rejected':'needs_adjudication');$label=mb_substr(trim((string)($in['canonicalLabel']??'')),0,190);
    if($decision==='relabel'&&$label==='')throw new InvalidArgumentException('Relabel requires a canonical label.');
    $pdo->prepare("UPDATE glasses_vision_training_samples SET review_status=?,review_outcome=?,canonical_label=CASE WHEN ?<>'' THEN ? ELSE canonical_label END,reviewer_user_id=?,notes=?,reviewed_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND public_id=?")
      ->execute([$status,$decision,$label,$label,$reviewer,mb_substr(trim((string)($in['notes']??'')),0,1000),$org,$publicId]);return glasses_vision_lab_sample($pdo,$org,$publicId);
}
function glasses_vision_lab_create_dataset(PDO $pdo,int $org,array $in,int $actor): array {
    $name=mb_substr(trim((string)($in['name']??'')),0,190);$version=mb_substr(trim((string)($in['versionLabel']??'')),0,80);if($name===''||$version==='')throw new InvalidArgumentException('Dataset name and version are required.');
    $public=glasses_public_id('vision-dataset');$pdo->prepare("INSERT INTO glasses_vision_dataset_versions (organization_id,public_id,name,version_label,created_by) VALUES (?,?,?,?,?)")->execute([$org,$public,$name,$version,$actor]);return glasses_vision_lab_dataset($pdo,$org,$public);
}
function glasses_vision_lab_dataset(PDO $pdo,int $org,string $publicId): array {
    $q=$pdo->prepare("SELECT d.*,(SELECT COUNT(*) FROM glasses_vision_dataset_items i WHERE i.dataset_id=d.id) items FROM glasses_vision_dataset_versions d WHERE d.organization_id=? AND d.public_id=? LIMIT 1");$q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Vision dataset was not found.');return ['publicId'=>$r['public_id'],'name'=>$r['name'],'versionLabel'=>$r['version_label'],'status'=>$r['status'],'datasetHash'=>$r['dataset_hash'],'rowCount'=>(int)$r['row_count'],'itemCount'=>(int)$r['items'],'classCount'=>(int)$r['class_count'],'quality'=>json_decode((string)($r['quality_json']??'null'),true),'frozenAt'=>$r['frozen_at']];
}
function glasses_vision_lab_datasets(PDO $pdo,int $org): array {$q=$pdo->prepare("SELECT public_id FROM glasses_vision_dataset_versions WHERE organization_id=? ORDER BY created_at DESC LIMIT 100");$q->execute([$org]);return array_map(fn($id)=>glasses_vision_lab_dataset($pdo,$org,(string)$id),$q->fetchAll(PDO::FETCH_COLUMN));}
function glasses_vision_lab_lock_dataset(PDO $pdo,int $org,string $publicId): array {
    if(!$pdo->inTransaction())throw new LogicException('A dataset lock requires an active transaction.');
    $q=$pdo->prepare('SELECT * FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE');
    $q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision dataset was not found.');
    return $row;
}
function glasses_vision_lab_add_dataset_sample(PDO $pdo,int $org,string $datasetPublic,string $samplePublic,string $split): array {
    if(!in_array($split,['train','val','test'],true))throw new InvalidArgumentException('Dataset split must be train, val or test.');
    return glasses_transaction($pdo,function()use($pdo,$org,$datasetPublic,$samplePublic,$split):array{
        $d=glasses_vision_lab_lock_dataset($pdo,$org,$datasetPublic);if($d['status']!=='draft')throw new InvalidArgumentException('Frozen datasets are immutable.');
        $synthetic=glasses_vision_lab_synthetic_observation_sql();
        $sq=$pdo->prepare("SELECT s.*,$synthetic AS is_synthetic FROM glasses_vision_training_samples s LEFT JOIN glasses_build_observations o ON o.id=s.observation_id AND o.organization_id=s.organization_id WHERE s.organization_id=? AND s.public_id=? LIMIT 1");$sq->execute([$org,$samplePublic]);$s=$sq->fetch();if(!$s)throw new InvalidArgumentException('Vision sample was not found.');if($s['review_status']!=='approved')throw new InvalidArgumentException('Only approved samples may enter a dataset.');
        if((bool)$s['is_synthetic'])throw new InvalidArgumentException('Simulated browser observations cannot enter a production dataset.');
        $sourceHash=hash('sha256',implode('|',[$s['public_id'],$s['canonical_label'],$s['review_outcome'],$s['updated_at']]));
        $pdo->prepare("INSERT IGNORE INTO glasses_vision_dataset_items (organization_id,dataset_id,sample_id,split_name,class_label,source_hash) VALUES (?,?,?,?,?,?)")->execute([$org,(int)$d['id'],(int)$s['id'],$split,$s['canonical_label'],$sourceHash]);return glasses_vision_lab_dataset($pdo,$org,$datasetPublic);
    });
}
function glasses_vision_lab_freeze_dataset(PDO $pdo,int $org,string $publicId,int $actor): array {
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$actor):array{
        $d=glasses_vision_lab_lock_dataset($pdo,$org,$publicId);
        if($d['status']==='frozen')return glasses_vision_lab_dataset($pdo,$org,$publicId);
        if($d['status']!=='draft')throw new InvalidArgumentException('Only draft datasets can be frozen.');
        $iq=$pdo->prepare("SELECT i.split_name,i.class_label,i.source_hash,s.public_id sample_public_id,s.source_type,s.source_reference,s.operator_user_id,s.annotator_user_id,s.reviewer_user_id,s.adjudicator_user_id,s.review_status FROM glasses_vision_dataset_items i JOIN glasses_vision_training_samples s ON s.id=i.sample_id AND s.organization_id=i.organization_id WHERE i.organization_id=? AND i.dataset_id=? ORDER BY s.public_id FOR UPDATE");$iq->execute([$org,(int)$d['id']]);$items=$iq->fetchAll();
        if(function_exists('glasses_vision_dataset_intelligence_freeze_guard'))glasses_vision_dataset_intelligence_freeze_guard($pdo,$org,$publicId);
        if(function_exists('glasses_vision_training_media_freeze_guard'))glasses_vision_training_media_freeze_guard($pdo,$org,$publicId);
        if(function_exists('glasses_vision_curation_freeze_guard'))glasses_vision_curation_freeze_guard($pdo,$org,$publicId);
        if(function_exists('glasses_v11_dataset_freeze_guard'))glasses_v11_dataset_freeze_guard($pdo,$org,$publicId);
        $synthetic=glasses_vision_lab_synthetic_observation_sql();
        $q=$pdo->prepare("SELECT 1 FROM glasses_vision_dataset_items i JOIN glasses_vision_training_samples s ON s.id=i.sample_id AND s.organization_id=i.organization_id JOIN glasses_build_observations o ON o.id=s.observation_id AND o.organization_id=s.organization_id WHERE i.organization_id=? AND i.dataset_id=? AND $synthetic LIMIT 1");
        $q->execute([$org,(int)$d['id']]);if($q->fetchColumn())throw new InvalidArgumentException('Dataset contains a simulated browser observation. Use a draft with verified evidence before freezing.');
        if(!$items)throw new InvalidArgumentException('Dataset must contain at least one approved sample.');
        foreach($items as &$item){if($item['review_status']!=='approved')throw new InvalidArgumentException('Only approved samples may be frozen in a dataset.');unset($item['review_status']);}unset($item);
        $classes=[];$splits=['train'=>0,'val'=>0,'test'=>0];$users=[];foreach($items as $i){if($i['class_label']!==null)$classes[(string)$i['class_label']]=true;$splits[$i['split_name']]++;foreach(['operator_user_id','annotator_user_id','reviewer_user_id','adjudicator_user_id'] as $k)if($i[$k]!==null)$users[(int)$i[$k]]=true;}
        $mediaManifest=function_exists('glasses_vision_training_media_export_manifest')?glasses_vision_training_media_export_manifest($pdo,$org,$publicId):null;
        $manifest=['schema'=>'gelato.vision_dataset.v3','items'=>$items,'splits'=>$splits,'classes'=>array_keys($classes),'contributorUserIds'=>array_keys($users),'trainingMedia'=>$mediaManifest];$hash=hash('sha256',json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $visualQuality=function_exists('glasses_vision_training_media_dataset_quality')?glasses_vision_training_media_dataset_quality($pdo,$org,$publicId):null;
        $quality=['approvedOnly'=>true,'classes'=>count($classes),'splits'=>$splits,'contributors'=>count($users),'hasValidation'=>$splits['val']>0,'hasTest'=>$splits['test']>0,'visualMedia'=>$visualQuality];
        $pdo->prepare("UPDATE glasses_vision_dataset_versions SET status='frozen',dataset_hash=?,row_count=?,class_count=?,quality_json=?,manifest_json=?,frozen_by=?,frozen_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([$hash,count($items),count($classes),json_encode($quality),json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor,$org,(int)$d['id']]);return glasses_vision_lab_dataset($pdo,$org,$publicId);
    });
}
function glasses_vision_lab_import_corrected(PDO $pdo,int $org,?string $missionPublic,int $actor,int $limit=500): array {
    $limit=max(1,min(5000,$limit));$synthetic=glasses_vision_lab_synthetic_observation_sql();
    $mission=glasses_vision_lab_evidence_mission($pdo,$org,$missionPublic);
    $station=glasses_vision_lab_evidence_station_sql();
    $q=$pdo->prepare("SELECT o.id FROM glasses_build_observations o JOIN glasses_observation_corrections c ON c.observation_id=o.id AND c.organization_id=o.organization_id
      JOIN glasses_build_sessions s ON s.id=o.build_session_id AND s.organization_id=o.organization_id
      JOIN kds_order_items k ON k.id=s.kds_order_item_id AND k.organization_id=s.organization_id
      LEFT JOIN glasses_vision_training_samples ts ON ts.organization_id=o.organization_id AND ts.observation_id=o.id
      WHERE o.organization_id=? AND ts.id IS NULL AND NOT $synthetic
        AND (? IS NULL OR k.location_id=?) AND (? IS NULL OR $station=?)
      GROUP BY o.id ORDER BY MAX(c.id) DESC LIMIT ".$limit);
    $locationId=$mission['location_id']??null;$stationId=$mission['station_id']??null;
    $q->execute([$org,$locationId,$locationId,$stationId,$stationId]);$count=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){glasses_vision_lab_queue_observation_by_id($pdo,$org,(int)$id,$missionPublic,$actor);$count++;}
    return ['imported'=>$count,'missionPublicId'=>$missionPublic];
}
function glasses_vision_lab_dataset_coverage(PDO $pdo,int $org,string $publicId): array {
    $q=$pdo->prepare("SELECT d.id FROM glasses_vision_dataset_versions d WHERE d.organization_id=? AND d.public_id=? LIMIT 1");$q->execute([$org,$publicId]);$id=(int)$q->fetchColumn();if(!$id)throw new InvalidArgumentException('Vision dataset was not found.');
    $cq=$pdo->prepare("SELECT COALESCE(class_label,'(negative)') label,COUNT(*) n FROM glasses_vision_dataset_items WHERE organization_id=? AND dataset_id=? GROUP BY class_label ORDER BY n DESC,label");$cq->execute([$org,$id]);$classes=$cq->fetchAll();
    $sq=$pdo->prepare("SELECT split_name,COUNT(*) n FROM glasses_vision_dataset_items WHERE organization_id=? AND dataset_id=? GROUP BY split_name");$sq->execute([$org,$id]);$splits=['train'=>0,'val'=>0,'test'=>0];foreach($sq->fetchAll() as $r)$splits[(string)$r['split_name']]=(int)$r['n'];
    $uq=$pdo->prepare("SELECT COUNT(DISTINCT x.user_id) FROM (SELECT operator_user_id user_id FROM glasses_vision_training_samples s JOIN glasses_vision_dataset_items i ON i.sample_id=s.id WHERE i.organization_id=? AND i.dataset_id=? UNION SELECT reviewer_user_id FROM glasses_vision_training_samples s JOIN glasses_vision_dataset_items i ON i.sample_id=s.id WHERE i.organization_id=? AND i.dataset_id=?) x WHERE x.user_id IS NOT NULL");$uq->execute([$org,$id,$org,$id]);
    return ['classes'=>array_map(static fn($r)=>['label'=>$r['label'],'count'=>(int)$r['n']],$classes),'splits'=>$splits,'contributors'=>(int)$uq->fetchColumn(),'hasValidation'=>$splits['val']>0,'hasTest'=>$splits['test']>0];
}
function glasses_vision_lab_catalog(PDO $pdo,array $user): array {
    if(!glasses_vision_lab_org_access($pdo,$user,'glasses.view'))throw new DomainException('Organization-wide AR glasses view permission is required for Vision Lab.');
    $org=(int)$user['organization_id'];if(!glasses_vision_lab_ready($pdo))return ['ready'=>false,'assignments'=>[],'missions'=>[],'samples'=>[],'datasets'=>[],'users'=>[],'devices'=>[]];
    $uq=$pdo->prepare("SELECT u.id,u.display_name,m.employee_number,m.job_title FROM organization_memberships m JOIN users u ON u.id=m.user_id WHERE m.organization_id=? AND m.status='active' AND u.status='active' ORDER BY u.display_name");$uq->execute([$org]);
    $dq=$pdo->prepare("SELECT public_id,display_name,location_id,station_id FROM glasses_devices WHERE organization_id=? AND status='active' ORDER BY display_name");$dq->execute([$org]);
    $datasets=glasses_vision_lab_datasets($pdo,$org);foreach($datasets as &$dataset)$dataset['coverage']=glasses_vision_lab_dataset_coverage($pdo,$org,(string)$dataset['publicId']);unset($dataset);
    $iq=$pdo->prepare("SELECT public_id,severity,category,recovery_status,reasons_json FROM glasses_vision_drift_incidents WHERE organization_id=? AND recovery_status<>'resolved' ORDER BY last_seen_at DESC LIMIT 50");$iq->execute([$org]);$incidents=array_map(static fn($r)=>['publicId'=>$r['public_id'],'severity'=>$r['severity'],'category'=>$r['category'],'recoveryStatus'=>$r['recovery_status'],'reasons'=>json_decode((string)$r['reasons_json'],true)?:[]],$iq->fetchAll());
    return ['ready'=>true,'assignments'=>glasses_vision_lab_assignments($pdo,$org),'missions'=>glasses_vision_lab_missions($pdo,$org),'samples'=>glasses_vision_lab_samples($pdo,$org),'datasets'=>$datasets,'users'=>$uq->fetchAll(),'devices'=>$dq->fetchAll(),'driftIncidents'=>$incidents,'canManage'=>glasses_vision_lab_org_access($pdo,$user,'glasses.manage')];
}
