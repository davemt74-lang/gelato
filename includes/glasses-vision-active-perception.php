<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-confidence-policy.php';

const GLASSES_VISION_ACTIVE_PERCEPTION_SCHEMA='gelato.vision_active_perception.v1';

function glasses_vision_active_perception_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_active_perception_actions','glasses_vision_active_perception_events'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_confidence_policy_ready($pdo);
}

function glasses_vision_active_perception_context(PDO $pdo,int $org,string $decisionPublic): array
{
    $q=$pdo->prepare("SELECT d.*,dev.public_id device_public_id,b.public_id build_session_public_id,b.status build_status,
      m.public_id package_public_id,m.artifact_sha256 package_sha256,m.status package_status,
      a.public_id analysis_public_id,c.public_id calibration_public_id,p.public_id policy_public_id
      FROM glasses_vision_confidence_decisions d
      JOIN glasses_devices dev ON dev.id=d.device_id AND dev.organization_id=d.organization_id
      JOIN glasses_build_sessions b ON b.id=d.build_session_id AND b.organization_id=d.organization_id
      JOIN glasses_vision_model_packages m ON m.id=d.package_id AND m.organization_id=d.organization_id
      JOIN glasses_vision_confidence_policies p ON p.id=d.policy_id AND p.organization_id=d.organization_id
      LEFT JOIN glasses_vision_context_drift_analyses a ON a.id=d.context_drift_analysis_id AND a.organization_id=d.organization_id
      LEFT JOIN glasses_vision_calibration_selections c ON c.id=d.calibration_selection_id AND c.organization_id=d.organization_id
      WHERE d.organization_id=? AND d.public_id=? LIMIT 1");
    $q->execute([$org,trim($decisionPublic)]);$r=$q->fetch();
    if(!$r)throw new InvalidArgumentException('Confidence decision was not found.');
    if(!glasses_vision_confidence_decision_verify($pdo,$org,(string)$r['public_id'])['passed'])
        throw new InvalidArgumentException('Active perception requires an intact confidence decision.');
    if((string)$r['build_status']!=='active')throw new InvalidArgumentException('Active perception requires an active build session.');
    if((string)$r['package_status']!=='ready')throw new InvalidArgumentException('Active perception model package is no longer ready.');
    $decision=glasses_vision_confidence_decision_row($pdo,$org,(string)$r['public_id']);
    $aq=$pdo->prepare("SELECT assignment_key,package_id FROM glasses_vision_model_assignments
      WHERE organization_id=? AND device_id=? AND build_session_id=? AND detector_name=? AND action='apply'
      ORDER BY issued_at DESC,id DESC LIMIT 1");
    $aq->execute([$org,(int)$r['device_id'],(int)$r['build_session_id'],(string)$r['detector_name']]);$assignment=$aq->fetch();
    if(!$assignment||(int)($assignment['package_id']??0)!==(int)$r['package_id']||
       !hash_equals((string)($decision['evidence']['assignment']['assignmentKey']??''),(string)($assignment['assignment_key']??'')))
        throw new InvalidArgumentException('Confidence decision is stale because the active model assignment changed.');
    $created=new DateTimeImmutable((string)$r['created_at'],new DateTimeZone('UTC'));
    $expires=$created->modify('+10 minutes');
    if($expires<=new DateTimeImmutable('now',new DateTimeZone('UTC')))
        throw new InvalidArgumentException('Confidence decision is too old for active perception recovery.');
    return ['row'=>$r,'expiresAt'=>$expires->format('Y-m-d H:i:s.u')];
}

function glasses_vision_active_perception_instruction(PDO $pdo,int $org,array $r): array
{
    $decision=(string)$r['decision'];$classification='insufficient_context';$analysis=null;
    if(!empty($r['analysis_public_id'])){
        $analysis=glasses_vision_context_drift_row($pdo,$org,(string)$r['analysis_public_id']);
        if(!glasses_vision_context_drift_verify($pdo,$org,$analysis['publicId'])['passed'])
            throw new InvalidArgumentException('Active perception context analysis failed verification.');
        $classification=(string)$analysis['classification'];
    }
    $calibration=null;
    if(!empty($r['calibration_public_id'])){
        $calibration=glasses_vision_calibration_selection_row($pdo,$org,(string)$r['calibration_public_id']);
        if(!glasses_vision_calibration_selection_verify($pdo,$org,$calibration['publicId'])['passed'])
            throw new InvalidArgumentException('Active perception calibration selection failed verification.');
    }

    if($decision==='accept')throw new InvalidArgumentException('Accepted confidence decisions do not require active perception recovery.');
    $primary='capture_additional_frame';$steps=['pause_validation','capture_additional_frame'];$requiresHuman=false;$reason='collect_more_evidence';
    if(in_array($decision,['human_review','unmapped_hold'],true)){
        $primary='request_human_confirmation';$steps=['pause_validation','request_human_confirmation'];$requiresHuman=true;$reason=$decision;
    }elseif($decision==='below_threshold_hold'){
        if($classification==='context_shift'&&$calibration!==null&&in_array((string)$calibration['decision'],['profile','station_fallback'],true)){
            $primary='use_calibration_selection';$steps=['pause_validation','use_calibration_selection','capture_additional_frame'];$reason='verified_context_calibration_available';
        }elseif($classification==='context_shift'){
            $primary='change_viewpoint';$steps=['pause_validation','change_viewpoint','capture_additional_frame'];$reason='context_shift_without_verified_calibration';
        }elseif(in_array($classification,['model_degradation','mixed','ambiguous'],true)){
            $primary='request_human_confirmation';$steps=['pause_validation','request_human_confirmation'];$requiresHuman=true;$reason=$classification;
        }
    }
    return [
      'primaryAction'=>$primary,'steps'=>$steps,'requiresHuman'=>$requiresHuman,'reason'=>$reason,
      'validationGate'=>'hold','maxAttempts'=>1,
      'contextClassification'=>$classification,
      'calibrationSelection'=>$calibration?[
        'publicId'=>$calibration['publicId'],'selectionHash'=>$calibration['selectionHash'],
        'selectedProfilePublicId'=>$calibration['selectedProfilePublicId'],'fallbackCalibrationPublicId'=>$calibration['fallbackCalibrationPublicId'],
        'decision'=>$calibration['decision']
      ]:null,
      'guardrails'=>[
        'automaticBuildMutation'=>false,'automaticKdsMutation'=>false,'automaticRolloutAction'=>false,
        'automaticThresholdChange'=>false,'automaticRetraining'=>false,'humanConfirmationCannotBeDeviceResolved'=>true
      ],
    ];
}

function glasses_vision_active_perception_event(PDO $pdo,int $org,int $actionId,string $actionPublic,string $actionHash,string $type,array $evidence,?int $actor): void
{
    $material=['schema'=>GLASSES_VISION_ACTIVE_PERCEPTION_SCHEMA,'actionPublicId'=>$actionPublic,'actionHash'=>$actionHash,'eventType'=>$type,'evidence'=>$evidence];
    $eventHash=hash('sha256',glasses_vision_training_release_json($material));
    $pdo->prepare("INSERT INTO glasses_vision_active_perception_events (organization_id,action_id,event_type,event_hash,evidence_json,actor_user_id)
      VALUES (?,?,?,?,?,?)")
      ->execute([$org,$actionId,$type,$eventHash,glasses_vision_training_release_json($material),$actor]);
}

function glasses_vision_active_perception_plan(PDO $pdo,int $org,string $decisionPublic,array $device,?int $actor=null): array
{
    if(!glasses_vision_active_perception_ready($pdo))throw new RuntimeException('Vision Lab V8 active-perception migration is not installed.');
    $ctx=glasses_vision_active_perception_context($pdo,$org,$decisionPublic);$r=$ctx['row'];
    if((int)$r['device_id']!==(int)$device['id']||!hash_equals((string)$r['device_public_id'],(string)$device['public_id']))
        throw new InvalidArgumentException('Confidence decision does not belong to this device.');
    $instruction=glasses_vision_active_perception_instruction($pdo,$org,$r);
    $decision=glasses_vision_confidence_decision_row($pdo,$org,(string)$r['public_id']);
    $evidence=[
      'schema'=>GLASSES_VISION_ACTIVE_PERCEPTION_SCHEMA,
      'confidenceDecision'=>['publicId'=>$decision['publicId'],'decisionHash'=>$decision['decisionHash'],'decision'=>$decision['decision']],
      'policy'=>['publicId'=>$decision['policyPublicId'],'policyHash'=>$decision['evidence']['policy']['policyHash']??null],
      'devicePublicId'=>$r['device_public_id'],'buildSessionPublicId'=>$r['build_session_public_id'],
      'model'=>['publicId'=>$r['package_public_id'],'artifactSha256'=>$r['package_sha256']],
      'contextDriftAnalysis'=>!empty($r['analysis_public_id'])?['publicId'=>$r['analysis_public_id'],'analysisHash'=>$decision['evidence']['context']['analysisHash']??null]:null,
      'calibrationSelection'=>!empty($r['calibration_public_id'])?['publicId'=>$r['calibration_public_id'],'selectionHash'=>$decision['evidence']['calibrationSelection']['selectionHash']??null]:null,
      'expiresAt'=>$ctx['expiresAt'],
    ];
    $material=['evidence'=>$evidence,'instruction'=>$instruction];
    $hash=hash('sha256',glasses_vision_training_release_json($material));$key=$hash;
    $q=$pdo->prepare("SELECT public_id,action_hash FROM glasses_vision_active_perception_actions WHERE organization_id=? AND action_key=? LIMIT 1");
    $q->execute([$org,$key]);$existing=$q->fetch();
    if($existing){
        if(!hash_equals((string)$existing['action_hash'],$hash))throw new InvalidArgumentException('Active perception action identity conflicts with different immutable evidence.');
        return glasses_vision_active_perception_row($pdo,$org,(string)$existing['public_id']);
    }
    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$r,$key,$instruction,$evidence,$hash,$ctx):array{
        $public=glasses_public_id('vision-active-perception');
        $pdo->prepare("INSERT INTO glasses_vision_active_perception_actions
          (organization_id,public_id,confidence_decision_id,device_id,build_session_id,package_id,context_drift_analysis_id,calibration_selection_id,
           action_key,primary_action,status,requires_human,instruction_json,evidence_json,action_hash,expires_at,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,'issued',?,?,?,?,?,?,?)")
          ->execute([$org,$public,(int)$r['id'],(int)$r['device_id'],(int)$r['build_session_id'],(int)$r['package_id'],
            $r['context_drift_analysis_id']!==null?(int)$r['context_drift_analysis_id']:null,$r['calibration_selection_id']!==null?(int)$r['calibration_selection_id']:null,
            $key,$instruction['primaryAction'],$instruction['requiresHuman']?1:0,glasses_vision_training_release_json($instruction),
            glasses_vision_training_release_json($evidence),$hash,$ctx['expiresAt'],$actor]);
        $id=(int)$pdo->lastInsertId();
        glasses_vision_active_perception_event($pdo,$org,$id,$public,$hash,'issued',['primaryAction'=>$instruction['primaryAction'],'steps'=>$instruction['steps'],'expiresAt'=>$ctx['expiresAt']],$actor);
        glasses_vision_lineage_edge($pdo,$org,'confidence_decision',(string)$r['public_id'],(string)$r['decision_hash'],'recovery_planned_as','active_perception_action',$public,$hash,
          ['primaryAction'=>$instruction['primaryAction'],'requiresHuman'=>$instruction['requiresHuman']],$actor);
        return glasses_vision_active_perception_row($pdo,$org,$public);
    });
}

function glasses_vision_active_perception_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT a.*,d.public_id decision_public_id,dev.public_id device_public_id,b.public_id build_public_id,m.public_id package_public_id,
      x.public_id analysis_public_id,c.public_id calibration_public_id
      FROM glasses_vision_active_perception_actions a
      JOIN glasses_vision_confidence_decisions d ON d.id=a.confidence_decision_id AND d.organization_id=a.organization_id
      JOIN glasses_devices dev ON dev.id=a.device_id AND dev.organization_id=a.organization_id
      JOIN glasses_build_sessions b ON b.id=a.build_session_id AND b.organization_id=a.organization_id
      JOIN glasses_vision_model_packages m ON m.id=a.package_id AND m.organization_id=a.organization_id
      LEFT JOIN glasses_vision_context_drift_analyses x ON x.id=a.context_drift_analysis_id AND x.organization_id=a.organization_id
      LEFT JOIN glasses_vision_calibration_selections c ON c.id=a.calibration_selection_id AND c.organization_id=a.organization_id
      WHERE a.organization_id=? AND a.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Active perception action was not found.');
    return [
      'schema'=>GLASSES_VISION_ACTIVE_PERCEPTION_SCHEMA,'publicId'=>$r['public_id'],'confidenceDecisionPublicId'=>$r['decision_public_id'],
      'devicePublicId'=>$r['device_public_id'],'buildSessionPublicId'=>$r['build_public_id'],'modelPackagePublicId'=>$r['package_public_id'],
      'contextDriftAnalysisPublicId'=>$r['analysis_public_id'],'calibrationSelectionPublicId'=>$r['calibration_public_id'],
      'primaryAction'=>$r['primary_action'],'status'=>$r['status'],'requiresHuman'=>(bool)$r['requires_human'],
      'instruction'=>json_decode((string)$r['instruction_json'],true)?:[],'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],
      'actionHash'=>$r['action_hash'],'expiresAt'=>$r['expires_at'],'acknowledgedAt'=>$r['acknowledged_at'],'completedAt'=>$r['completed_at'],
      'completion'=>$r['completion_json']!==null?(json_decode((string)$r['completion_json'],true)?:[]):null,'completionHash'=>$r['completion_hash'],
      'createdAt'=>$r['created_at']
    ];
}

function glasses_vision_active_perception_assert_device(array $action,array $device): void
{
    if(!hash_equals((string)$action['devicePublicId'],(string)$device['public_id']))
        throw new InvalidArgumentException('Active perception action does not belong to this device.');
}

function glasses_vision_active_perception_acknowledge(PDO $pdo,int $org,string $publicId,array $device): array
{
    if(!glasses_vision_active_perception_ready($pdo))throw new RuntimeException('Vision Lab V8 active-perception migration is not installed.');
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$device):array{
        $q=$pdo->prepare("SELECT * FROM glasses_vision_active_perception_actions WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Active perception action was not found.');
        $action=glasses_vision_active_perception_row($pdo,$org,$publicId);glasses_vision_active_perception_assert_device($action,$device);
        if(in_array((string)$r['status'],['completed','failed','expired','cancelled'],true))return $action;
        if(new DateTimeImmutable((string)$r['expires_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC'))){
            $pdo->prepare("UPDATE glasses_vision_active_perception_actions SET status='expired' WHERE organization_id=? AND id=?")->execute([$org,(int)$r['id']]);
            glasses_vision_active_perception_event($pdo,$org,(int)$r['id'],$publicId,(string)$r['action_hash'],'expired',[] ,null);
            return glasses_vision_active_perception_row($pdo,$org,$publicId);
        }
        if((string)$r['status']==='issued'){
            $next=(int)$r['requires_human']===1?'awaiting_human':'acknowledged';
            $pdo->prepare("UPDATE glasses_vision_active_perception_actions SET status=?,acknowledged_at=NOW(6) WHERE organization_id=? AND id=?")
              ->execute([$next,$org,(int)$r['id']]);
            glasses_vision_active_perception_event($pdo,$org,(int)$r['id'],$publicId,(string)$r['action_hash'],'acknowledged',['status'=>$next],null);
        }
        return glasses_vision_active_perception_row($pdo,$org,$publicId);
    });
}

function glasses_vision_active_perception_complete(PDO $pdo,int $org,string $publicId,array $device,string $outcome,array $result): array
{
    $outcome=strtolower(trim($outcome));if(!in_array($outcome,['success','failed','insufficient'],true))throw new InvalidArgumentException('Active perception completion outcome is invalid.');
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$device,$outcome,$result):array{
        $q=$pdo->prepare("SELECT * FROM glasses_vision_active_perception_actions WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Active perception action was not found.');
        $action=glasses_vision_active_perception_row($pdo,$org,$publicId);glasses_vision_active_perception_assert_device($action,$device);
        if((int)$r['requires_human']===1)throw new InvalidArgumentException('Human-confirmation actions cannot be resolved by a device.');
        if((string)$r['status']==='completed'||(string)$r['status']==='failed')return $action;
        if(!in_array((string)$r['status'],['issued','acknowledged'],true))throw new InvalidArgumentException('Active perception action is not completable.');
        if(new DateTimeImmutable((string)$r['expires_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC')))
            throw new InvalidArgumentException('Active perception action has expired.');
        $result=array_slice($result,0,40,true);
        $resultJson=glasses_vision_training_release_json($result);
        if(strlen($resultJson)>12000)throw new InvalidArgumentException('Active perception completion evidence is too large.');
        $completion=['outcome'=>$outcome,'result'=>$result,'devicePublicId'=>$action['devicePublicId']];
        $completionHash=hash('sha256',glasses_vision_training_release_json(['actionHash'=>$action['actionHash'],'completion'=>$completion]));
        $status=$outcome==='success'?'completed':'failed';
        $pdo->prepare("UPDATE glasses_vision_active_perception_actions SET status=?,completed_at=NOW(6),completion_json=?,completion_hash=? WHERE organization_id=? AND id=?")
          ->execute([$status,glasses_vision_training_release_json($completion),$completionHash,$org,(int)$r['id']]);
        glasses_vision_active_perception_event($pdo,$org,(int)$r['id'],$publicId,(string)$r['action_hash'],'device_completed',['status'=>$status,'completionHash'=>$completionHash],null);
        return glasses_vision_active_perception_row($pdo,$org,$publicId);
    });
}

function glasses_vision_active_perception_resolve_human(PDO $pdo,int $org,string $publicId,string $resolution,string $notes,int $actor): array
{
    $resolution=strtolower(trim($resolution));if(!in_array($resolution,['confirmed','rejected'],true))throw new InvalidArgumentException('Human confirmation resolution is invalid.');
    $notes=mb_substr(trim($notes),0,1000,'UTF-8');
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$resolution,$notes,$actor):array{
        $q=$pdo->prepare("SELECT * FROM glasses_vision_active_perception_actions WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Active perception action was not found.');
        if((int)$r['requires_human']!==1)throw new InvalidArgumentException('This active perception action does not require human confirmation.');
        if(in_array((string)$r['status'],['completed','failed'],true))return glasses_vision_active_perception_row($pdo,$org,$publicId);
        if(!in_array((string)$r['status'],['issued','awaiting_human','acknowledged'],true))throw new InvalidArgumentException('Human-confirmation action is not resolvable.');
        if(new DateTimeImmutable((string)$r['expires_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC')))
            throw new InvalidArgumentException('Active perception action has expired.');
        $completion=['resolution'=>$resolution,'notes'=>$notes];
        $hash=hash('sha256',glasses_vision_training_release_json(['actionHash'=>$r['action_hash'],'completion'=>$completion,'resolvedByUserId'=>$actor]));
        $status=$resolution==='confirmed'?'completed':'failed';
        $pdo->prepare("UPDATE glasses_vision_active_perception_actions SET status=?,completed_at=NOW(6),completion_json=?,completion_hash=?,resolved_by=? WHERE organization_id=? AND id=?")
          ->execute([$status,glasses_vision_training_release_json($completion),$hash,$actor,$org,(int)$r['id']]);
        glasses_vision_active_perception_event($pdo,$org,(int)$r['id'],$publicId,(string)$r['action_hash'],'human_resolved',['status'=>$status,'resolution'=>$resolution,'completionHash'=>$hash],$actor);
        return glasses_vision_active_perception_row($pdo,$org,$publicId);
    });
}

function glasses_vision_active_perception_verify(PDO $pdo,int $org,string $publicId): array
{
    $a=glasses_vision_active_perception_row($pdo,$org,$publicId);
    $hash=hash('sha256',glasses_vision_training_release_json(['evidence'=>$a['evidence'],'instruction'=>$a['instruction']]));
    $decision=glasses_vision_confidence_decision_row($pdo,$org,$a['confidenceDecisionPublicId']);
    $passed=hash_equals($a['actionHash'],$hash)
      &&glasses_vision_confidence_decision_verify($pdo,$org,$a['confidenceDecisionPublicId'])['passed']
      &&hash_equals((string)($a['evidence']['confidenceDecision']['decisionHash']??''),(string)$decision['decisionHash'])
      &&hash_equals((string)($a['evidence']['devicePublicId']??''),(string)$a['devicePublicId'])
      &&hash_equals((string)($a['evidence']['buildSessionPublicId']??''),(string)$a['buildSessionPublicId'])
      &&hash_equals((string)($a['evidence']['model']['publicId']??''),(string)$a['modelPackagePublicId'])
      &&hash_equals((string)($a['evidence']['expiresAt']??''),(string)$a['expiresAt'])
      &&hash_equals((string)($a['instruction']['primaryAction']??''),(string)$a['primaryAction']);
    if($a['contextDriftAnalysisPublicId']!==null)$passed=$passed&&glasses_vision_context_drift_verify($pdo,$org,$a['contextDriftAnalysisPublicId'])['passed'];
    if($a['calibrationSelectionPublicId']!==null)$passed=$passed&&glasses_vision_calibration_selection_verify($pdo,$org,$a['calibrationSelectionPublicId'])['passed'];
    $completionHash=null;
    if($a['completion']!==null){
        $q=$pdo->prepare("SELECT requires_human,resolved_by FROM glasses_vision_active_perception_actions WHERE organization_id=? AND public_id=? LIMIT 1");
        $q->execute([$org,$publicId]);$meta=$q->fetch()?:[];
        $material=['actionHash'=>$a['actionHash'],'completion'=>$a['completion']];
        if((int)($meta['requires_human']??0)===1)$material['resolvedByUserId']=$meta['resolved_by']!==null?(int)$meta['resolved_by']:0;
        $completionHash=hash('sha256',glasses_vision_training_release_json($material));
        $passed=$passed&&hash_equals((string)$a['completionHash'],$completionHash);
    }
    return ['passed'=>$passed,'actionHash'=>$a['actionHash'],'recomputedActionHash'=>$hash,'completionHash'=>$a['completionHash'],'recomputedCompletionHash'=>$completionHash];
}

function glasses_vision_active_perception_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_active_perception_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_ACTIVE_PERCEPTION_SCHEMA,'actions'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_active_perception_actions WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
    $actions=[];$states=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p){$a=glasses_vision_active_perception_row($pdo,$org,(string)$p);$actions[]=$a;$states[$a['status']]=($states[$a['status']]??0)+1;}
    ksort($states);return ['ready'=>true,'schema'=>GLASSES_VISION_ACTIVE_PERCEPTION_SCHEMA,'states'=>$states,'actions'=>$actions];
}
