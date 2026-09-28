<?php
declare(strict_types=1);
require_once __DIR__.'/glasses-vision-lab.php';

function glasses_vision_active_learning_ready(PDO $pdo): bool {
    foreach(['glasses_vision_active_learning_candidates','glasses_vision_active_learning_scans'] as $t){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$t]); if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_lab_ready($pdo);
}
function glasses_vision_active_learning_candidate_key(string $type,string $sourceType,string $sourceReference): string {
    return hash('sha256',strtolower(trim($type)).'|'.strtolower(trim($sourceType)).'|'.trim($sourceReference));
}
function glasses_vision_active_learning_targets(string $type): array {
    return match($type){
        'correction'=>['samples'=>60,'positive'=>35,'negative'=>10,'hardExamples'=>30],
        'low_confidence'=>['samples'=>80,'positive'=>45,'negative'=>15,'hardExamples'=>50],
        'shadow_disagreement'=>['samples'=>100,'positive'=>50,'negative'=>20,'hardExamples'=>70],
        'drift_incident'=>['samples'=>120,'positive'=>60,'negative'=>20,'hardExamples'=>80],
        'canary_failure'=>['samples'=>100,'positive'=>50,'negative'=>20,'hardExamples'=>60],
        'rare_class'=>['samples'=>75,'positive'=>55,'negative'=>10,'hardExamples'=>25],
        default=>['samples'=>50,'positive'=>30,'negative'=>10,'hardExamples'=>20],
    };
}
function glasses_vision_active_learning_upsert(PDO $pdo,int $org,array $in): array {
    $type=(string)$in['candidateType'];$source=(string)$in['sourceType'];$ref=(string)$in['sourceReference'];
    $key=glasses_vision_active_learning_candidate_key($type,$source,$ref);
    $q=$pdo->prepare("SELECT id,public_id,status FROM glasses_vision_active_learning_candidates WHERE organization_id=? AND candidate_key=? LIMIT 1");
    $q->execute([$org,$key]);$existing=$q->fetch();
    if($existing)return ['created'=>false,'id'=>(int)$existing['id'],'publicId'=>$existing['public_id'],'status'=>$existing['status']];
    $public=glasses_public_id('vision-al');
    $targets=is_array($in['suggestedTargets']??null)?$in['suggestedTargets']:glasses_vision_active_learning_targets($type);
    $pdo->prepare("INSERT INTO glasses_vision_active_learning_candidates
      (organization_id,public_id,candidate_key,candidate_type,source_type,source_reference,observation_id,device_id,location_id,station_id,operator_user_id,canonical_label,priority_score,severity,reason,evidence_json,suggested_title,suggested_targets_json)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$org,$public,$key,$type,$source,$ref,$in['observationId']??null,$in['deviceId']??null,$in['locationId']??null,$in['stationId']??null,$in['operatorUserId']??null,$in['canonicalLabel']??null,max(0,min(100,(float)($in['priorityScore']??0))),(string)($in['severity']??'normal'),mb_substr((string)$in['reason'],0,1000),json_encode($in['evidence']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),mb_substr((string)($in['suggestedTitle']??'Vision training mission'),0,190),json_encode($targets,JSON_UNESCAPED_SLASHES)]);
    return ['created'=>true,'id'=>(int)$pdo->lastInsertId(),'publicId'=>$public,'status'=>'open'];
}
function glasses_vision_active_learning_wearer(PDO $pdo,int $org,int $deviceId,string $at): ?array {
    $q=$pdo->prepare("SELECT a.id,a.user_id FROM glasses_user_device_assignments a WHERE a.organization_id=? AND a.device_id=? AND a.assigned_at<=? AND (a.released_at IS NULL OR a.released_at>?) ORDER BY a.assigned_at DESC,a.id DESC LIMIT 1");
    $q->execute([$org,$deviceId,$at,$at]);$r=$q->fetch();return $r?:null;
}
function glasses_vision_active_learning_queue_observation(PDO $pdo,int $org,int $candidateId,string $observationKey,int $actor): bool {
    $sample=glasses_vision_lab_queue_observation($pdo,$org,$observationKey,null,$actor);
    $q=$pdo->prepare("SELECT id FROM glasses_vision_training_samples WHERE organization_id=? AND public_id=? LIMIT 1");$q->execute([$org,(string)$sample['publicId']]);$sampleId=(int)$q->fetchColumn();
    $pdo->prepare("UPDATE glasses_vision_active_learning_candidates SET queued_sample_id=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$sampleId,$org,$candidateId]);
    return true;
}
function glasses_vision_active_learning_scan(PDO $pdo,int $org,int $actor,string $trigger='manual'): array {
    if(!glasses_vision_active_learning_ready($pdo))throw new RuntimeException('Vision Lab V3 migration is not installed.');
    $created=0;$queued=0;$counts=['correction'=>0,'low_confidence'=>0,'shadow_disagreement'=>0,'drift_incident'=>0,'canary_failure'=>0,'rare_class'=>0];

    $q=$pdo->prepare("SELECT o.id,o.observation_key,o.component_key,o.confidence,o.created_at,s.device_id,k.location_id,k.station_id,d.display_name device_name
      FROM glasses_build_observations o
      JOIN glasses_build_sessions s ON s.id=o.build_session_id AND s.organization_id=o.organization_id
      JOIN kds_order_items k ON k.id=s.kds_order_item_id AND k.organization_id=s.organization_id
      JOIN glasses_devices d ON d.id=s.device_id
      JOIN glasses_observation_corrections c ON c.observation_id=o.id AND c.organization_id=o.organization_id
      WHERE o.organization_id=? GROUP BY o.id ORDER BY MAX(c.id) DESC LIMIT 1000");
    $q->execute([$org]);
    foreach($q->fetchAll() as $r){
        $wearer=glasses_vision_active_learning_wearer($pdo,$org,(int)$r['device_id'],(string)$r['created_at']);
        $res=glasses_vision_active_learning_upsert($pdo,$org,['candidateType'=>'correction','sourceType'=>'production_observation','sourceReference'=>(string)$r['observation_key'],'observationId'=>(int)$r['id'],'deviceId'=>(int)$r['device_id'],'locationId'=>(int)$r['location_id'],'stationId'=>$r['station_id']!==null?(int)$r['station_id']:null,'operatorUserId'=>$wearer?(int)$wearer['user_id']:null,'canonicalLabel'=>(string)$r['component_key'],'priorityScore'=>92,'severity'=>'high','reason'=>'Human correction indicates production evidence worth review.','evidence'=>['confidence'=>$r['confidence']!==null?(float)$r['confidence']:null],'suggestedTitle'=>'Corrected '.$r['component_key'].' examples']);
        if($res['created']){$created++;$counts['correction']++;if(glasses_vision_active_learning_queue_observation($pdo,$org,(int)$res['id'],(string)$r['observation_key'],$actor))$queued++;}
    }

    $q=$pdo->prepare("SELECT o.id,o.observation_key,o.component_key,o.confidence,o.created_at,s.device_id,k.location_id,k.station_id
      FROM glasses_build_observations o JOIN glasses_build_sessions s ON s.id=o.build_session_id AND s.organization_id=o.organization_id
      JOIN kds_order_items k ON k.id=s.kds_order_item_id AND k.organization_id=s.organization_id
      WHERE o.organization_id=? AND o.confidence IS NOT NULL AND o.confidence<0.75 ORDER BY o.confidence ASC,o.id DESC LIMIT 1000");
    $q->execute([$org]);
    foreach($q->fetchAll() as $r){
        $conf=(float)$r['confidence'];$wearer=glasses_vision_active_learning_wearer($pdo,$org,(int)$r['device_id'],(string)$r['created_at']);
        $score=min(95,72+((0.75-$conf)*60));
        $res=glasses_vision_active_learning_upsert($pdo,$org,['candidateType'=>'low_confidence','sourceType'=>'production_observation','sourceReference'=>(string)$r['observation_key'],'observationId'=>(int)$r['id'],'deviceId'=>(int)$r['device_id'],'locationId'=>(int)$r['location_id'],'stationId'=>$r['station_id']!==null?(int)$r['station_id']:null,'operatorUserId'=>$wearer?(int)$wearer['user_id']:null,'canonicalLabel'=>(string)$r['component_key'],'priorityScore'=>$score,'severity'=>$conf<0.5?'high':'warning','reason'=>'Production detection confidence is below the active-learning threshold.','evidence'=>['confidence'=>$conf,'threshold'=>0.75],'suggestedTitle'=>'Low-confidence '.$r['component_key'].' examples']);
        if($res['created']){$created++;$counts['low_confidence']++;if(glasses_vision_active_learning_queue_observation($pdo,$org,(int)$res['id'],(string)$r['observation_key'],$actor))$queued++;}
    }

    $q=$pdo->prepare("SELECT e.id,e.frame_key,e.critical_mismatch,e.champion_only_count,e.challenger_only_count,e.mean_iou,e.correction_alignment,e.created_at,r.device_id,b.location_id,b.station_id
      FROM glasses_vision_shadow_events e JOIN glasses_vision_shadow_runs r ON r.id=e.shadow_run_id AND r.organization_id=e.organization_id
      JOIN glasses_build_sessions bs ON bs.id=r.build_session_id AND bs.organization_id=r.organization_id
      JOIN kds_order_items b ON b.id=bs.kds_order_item_id AND b.organization_id=bs.organization_id
      WHERE e.organization_id=? AND (e.critical_mismatch=1 OR e.champion_only_count>0 OR e.challenger_only_count>0) ORDER BY e.critical_mismatch DESC,e.id DESC LIMIT 500");
    $q->execute([$org]);
    foreach($q->fetchAll() as $r){
        $critical=(int)$r['critical_mismatch']===1;$res=glasses_vision_active_learning_upsert($pdo,$org,['candidateType'=>'shadow_disagreement','sourceType'=>'shadow_event','sourceReference'=>'shadow-event-'.(int)$r['id'],'deviceId'=>(int)$r['device_id'],'locationId'=>(int)$r['location_id'],'stationId'=>$r['station_id']!==null?(int)$r['station_id']:null,'priorityScore'=>$critical?100:84,'severity'=>$critical?'critical':'high','reason'=>$critical?'Champion/challenger produced a critical mismatch.':'Champion and challenger disagree on production evidence.','evidence'=>['frameKey'=>$r['frame_key'],'championOnly'=>(int)$r['champion_only_count'],'challengerOnly'=>(int)$r['challenger_only_count'],'meanIou'=>$r['mean_iou']!==null?(float)$r['mean_iou']:null,'correctionAlignment'=>$r['correction_alignment']],'suggestedTitle'=>'Champion/challenger disagreement review']);
        if($res['created']){$created++;$counts['shadow_disagreement']++;}
    }

    $q=$pdo->prepare("SELECT id,public_id,severity,category,recovery_status,location_id,station_id,reasons_json FROM glasses_vision_drift_incidents WHERE organization_id=? AND recovery_status<>'resolved' ORDER BY FIELD(severity,'critical','high','warning') ASC,last_seen_at DESC LIMIT 250");
    $q->execute([$org]);
    foreach($q->fetchAll() as $r){
        $sev=(string)$r['severity'];$score=$sev==='critical'?100:($sev==='high'?92:78);
        $res=glasses_vision_active_learning_upsert($pdo,$org,['candidateType'=>'drift_incident','sourceType'=>'drift_incident','sourceReference'=>(string)$r['public_id'],'locationId'=>(int)$r['location_id'],'stationId'=>$r['station_id']!==null?(int)$r['station_id']:null,'priorityScore'=>$score,'severity'=>$sev,'reason'=>'Production drift requires targeted environment/model training evidence.','evidence'=>['category'=>$r['category'],'recoveryStatus'=>$r['recovery_status'],'reasons'=>json_decode((string)$r['reasons_json'],true)?:[]],'suggestedTitle'=>'Drift recovery training: '.$r['category']]);
        if($res['created']){$created++;$counts['drift_incident']++;}
    }

    $q=$pdo->prepare("SELECT cs.id,cs.sample_key,cs.device_id,cs.build_session_id,cs.validation_failed,cs.low_confidence_count,cs.unexpected_count,cs.runtime_error_count,b.location_id,b.station_id
      FROM glasses_vision_canary_samples cs JOIN glasses_build_sessions bs ON bs.id=cs.build_session_id AND bs.organization_id=cs.organization_id
      JOIN kds_order_items b ON b.id=bs.kds_order_item_id AND b.organization_id=bs.organization_id
      WHERE cs.organization_id=? AND (cs.validation_failed=1 OR cs.runtime_error_count>0 OR cs.low_confidence_count>=3 OR cs.unexpected_count>=2) ORDER BY cs.id DESC LIMIT 500");
    $q->execute([$org]);
    foreach($q->fetchAll() as $r){
        $critical=(int)$r['validation_failed']===1||(int)$r['runtime_error_count']>0;
        $res=glasses_vision_active_learning_upsert($pdo,$org,['candidateType'=>'canary_failure','sourceType'=>'canary_sample','sourceReference'=>(string)$r['sample_key'],'deviceId'=>(int)$r['device_id'],'locationId'=>(int)$r['location_id'],'stationId'=>$r['station_id']!==null?(int)$r['station_id']:null,'priorityScore'=>$critical?97:82,'severity'=>$critical?'critical':'high','reason'=>'Canary production behavior indicates training/evaluation coverage is needed.','evidence'=>['validationFailed'=>(bool)$r['validation_failed'],'lowConfidenceCount'=>(int)$r['low_confidence_count'],'unexpectedCount'=>(int)$r['unexpected_count'],'runtimeErrorCount'=>(int)$r['runtime_error_count']],'suggestedTitle'=>'Canary hard-example expansion']);
        if($res['created']){$created++;$counts['canary_failure']++;}
    }

    $q=$pdo->prepare("SELECT o.component_key,COUNT(*) production_count,
      (SELECT COUNT(*) FROM glasses_vision_training_samples ts WHERE ts.organization_id=o.organization_id AND ts.review_status='approved' AND ts.canonical_label=o.component_key) approved_count
      FROM glasses_build_observations o WHERE o.organization_id=? GROUP BY o.component_key HAVING production_count>=3 AND approved_count<20 ORDER BY approved_count ASC,production_count DESC LIMIT 100");
    $q->execute([$org]);
    foreach($q->fetchAll() as $r){
        $approved=(int)$r['approved_count'];$prod=(int)$r['production_count'];$score=min(88,65+min(20,$prod)-$approved);
        $res=glasses_vision_active_learning_upsert($pdo,$org,['candidateType'=>'rare_class','sourceType'=>'class_coverage','sourceReference'=>(string)$r['component_key'],'canonicalLabel'=>(string)$r['component_key'],'priorityScore'=>$score,'severity'=>$approved<5?'high':'warning','reason'=>'Production class has insufficient approved training coverage.','evidence'=>['productionObservations'=>$prod,'approvedSamples'=>$approved,'targetApprovedSamples'=>20],'suggestedTitle'=>'Expand '.$r['component_key'].' training coverage']);
        if($res['created']){$created++;$counts['rare_class']++;}
    }

    $scan=glasses_public_id('vision-al-scan');
    $pdo->prepare("INSERT INTO glasses_vision_active_learning_scans (organization_id,public_id,trigger_source,created_candidates,queued_samples,source_counts_json,created_by) VALUES (?,?,?,?,?,?,?)")
      ->execute([$org,$scan,mb_substr(trim($trigger),0,32),$created,$queued,json_encode($counts,JSON_UNESCAPED_SLASHES),$actor?:null]);
    return ['publicId'=>$scan,'createdCandidates'=>$created,'queuedSamples'=>$queued,'sourceCounts'=>$counts];
}
function glasses_vision_active_learning_candidates(PDO $pdo,int $org,string $status='open',int $limit=200): array {
    $limit=max(1,min(500,$limit));$where=$status==='all'?'':' AND c.status=?';$args=[$org];if($status!=='all')$args[]=$status;
    $q=$pdo->prepare("SELECT c.*,d.public_id device_public_id,d.display_name device_name,l.name location_name,s.name station_name,u.display_name operator_name,m.public_id mission_public_id,m.title mission_title,ts.public_id sample_public_id
      FROM glasses_vision_active_learning_candidates c LEFT JOIN glasses_devices d ON d.id=c.device_id LEFT JOIN locations l ON l.id=c.location_id LEFT JOIN kds_stations s ON s.id=c.station_id LEFT JOIN users u ON u.id=c.operator_user_id LEFT JOIN glasses_vision_training_missions m ON m.id=c.mission_id LEFT JOIN glasses_vision_training_samples ts ON ts.id=c.queued_sample_id
      WHERE c.organization_id=?".$where." ORDER BY c.priority_score DESC,c.generated_at DESC LIMIT ".$limit);
    $q->execute($args);return array_map(static function($r):array{return ['publicId'=>$r['public_id'],'candidateType'=>$r['candidate_type'],'sourceType'=>$r['source_type'],'sourceReference'=>$r['source_reference'],'canonicalLabel'=>$r['canonical_label'],'priorityScore'=>(float)$r['priority_score'],'severity'=>$r['severity'],'reason'=>$r['reason'],'evidence'=>json_decode((string)($r['evidence_json']??'null'),true)?:[],'suggestedTitle'=>$r['suggested_title'],'suggestedTargets'=>json_decode((string)($r['suggested_targets_json']??'null'),true)?:[],'status'=>$r['status'],'devicePublicId'=>$r['device_public_id'],'deviceName'=>$r['device_name'],'locationName'=>$r['location_name'],'stationName'=>$r['station_name'],'operatorName'=>$r['operator_name'],'missionPublicId'=>$r['mission_public_id'],'missionTitle'=>$r['mission_title'],'samplePublicId'=>$r['sample_public_id'],'generatedAt'=>$r['generated_at']];},$q->fetchAll());
}
function glasses_vision_active_learning_create_mission(PDO $pdo,int $org,string $candidatePublic,int $actor): array {
    return glasses_transaction($pdo,function()use($pdo,$org,$candidatePublic,$actor):array{
        $q=$pdo->prepare("SELECT * FROM glasses_vision_active_learning_candidates WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");$q->execute([$org,$candidatePublic]);$c=$q->fetch();if(!$c)throw new InvalidArgumentException('Active-learning candidate was not found.');if($c['mission_id']!==null)throw new InvalidArgumentException('Candidate already has a training mission.');
        $targets=json_decode((string)$c['suggested_targets_json'],true)?:glasses_vision_active_learning_targets((string)$c['candidate_type']);
        $devicePublic=null;if($c['device_id']!==null){$dq=$pdo->prepare("SELECT public_id FROM glasses_devices WHERE organization_id=? AND id=?");$dq->execute([$org,(int)$c['device_id']]);$devicePublic=$dq->fetchColumn()?:null;}
        $stationPublic=null;if($c['station_id']!==null){$sq=$pdo->prepare("SELECT public_id FROM kds_stations WHERE organization_id=? AND id=?");$sq->execute([$org,(int)$c['station_id']]);$stationPublic=$sq->fetchColumn()?:null;}
        $mission=glasses_vision_lab_create_mission($pdo,$org,['title'=>$c['suggested_title']?:'Active-learning mission','description'=>$c['reason'],'assignedUserId'=>$c['operator_user_id'],'assignedDevicePublicId'=>$devicePublic,'locationId'=>$c['location_id'],'stationPublicId'=>$stationPublic,'targetSamples'=>(int)($targets['samples']??0),'targetPositive'=>(int)($targets['positive']??0),'targetNegative'=>(int)($targets['negative']??0),'targetHardExamples'=>(int)($targets['hardExamples']??0),'sourceType'=>'active_learning','sourceReference'=>$c['public_id'],'conditions'=>['candidateType'=>$c['candidate_type'],'evidence'=>json_decode((string)($c['evidence_json']??'null'),true)?:[]]],$actor);
        $mq=$pdo->prepare("SELECT id FROM glasses_vision_training_missions WHERE organization_id=? AND public_id=?");$mq->execute([$org,(string)$mission['publicId']]);$missionId=(int)$mq->fetchColumn();
        $pdo->prepare("UPDATE glasses_vision_active_learning_candidates SET status='assigned',mission_id=?,acted_by=?,acted_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$missionId,$actor,$org,(int)$c['id']]);
        if($c['queued_sample_id']!==null)$pdo->prepare("UPDATE glasses_vision_training_samples SET mission_id=COALESCE(mission_id,?),updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$missionId,$org,(int)$c['queued_sample_id']]);
        return $mission;
    });
}
function glasses_vision_active_learning_dismiss(PDO $pdo,int $org,string $publicId,string $reason,int $actor): array {
    $reason=mb_substr(trim($reason),0,500);if($reason==='')throw new InvalidArgumentException('Dismissal reason is required.');
    $q=$pdo->prepare("UPDATE glasses_vision_active_learning_candidates SET status='dismissed',acted_by=?,acted_at=NOW(6),evidence_json=JSON_SET(COALESCE(evidence_json,JSON_OBJECT()),'$.dismissalReason',?),updated_at=NOW(6) WHERE organization_id=? AND public_id=? AND status='open'");
    $q->execute([$actor,$reason,$org,$publicId]);if($q->rowCount()!==1)throw new InvalidArgumentException('Open active-learning candidate was not found.');return ['publicId'=>$publicId,'status'=>'dismissed'];
}
function glasses_vision_active_learning_summary(PDO $pdo,int $org): array {
    $q=$pdo->prepare("SELECT status,COUNT(*) n FROM glasses_vision_active_learning_candidates WHERE organization_id=? GROUP BY status");$q->execute([$org]);$states=['open'=>0,'assigned'=>0,'dismissed'=>0,'resolved'=>0];foreach($q->fetchAll() as $r)$states[(string)$r['status']]=(int)$r['n'];
    $q=$pdo->prepare("SELECT candidate_type,COUNT(*) n,ROUND(AVG(priority_score),2) avg_priority FROM glasses_vision_active_learning_candidates WHERE organization_id=? AND status='open' GROUP BY candidate_type ORDER BY n DESC");$q->execute([$org]);
    return ['states'=>$states,'byType'=>array_map(static fn($r)=>['type'=>$r['candidate_type'],'count'=>(int)$r['n'],'averagePriority'=>(float)$r['avg_priority']],$q->fetchAll())];
}

function glasses_vision_active_learning_catalog(PDO $pdo,int $org): array {
    if(!glasses_vision_active_learning_ready($pdo))return ['ready'=>false,'summary'=>['states'=>['open'=>0,'assigned'=>0,'dismissed'=>0,'resolved'=>0],'byType'=>[]],'candidates'=>[],'lastScan'=>null];
    $q=$pdo->prepare("SELECT public_id,trigger_source,created_candidates,queued_samples,source_counts_json,created_at FROM glasses_vision_active_learning_scans WHERE organization_id=? ORDER BY id DESC LIMIT 1");$q->execute([$org]);$scan=$q->fetch();
    return ['ready'=>true,'summary'=>glasses_vision_active_learning_summary($pdo,$org),'candidates'=>glasses_vision_active_learning_candidates($pdo,$org,'open',200),'lastScan'=>$scan?['publicId'=>$scan['public_id'],'triggerSource'=>$scan['trigger_source'],'createdCandidates'=>(int)$scan['created_candidates'],'queuedSamples'=>(int)$scan['queued_samples'],'sourceCounts'=>json_decode((string)$scan['source_counts_json'],true)?:[],'createdAt'=>$scan['created_at']]:null];
}
