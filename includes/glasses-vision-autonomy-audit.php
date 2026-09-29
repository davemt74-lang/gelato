<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-fleet-health.php';

const GLASSES_VISION_AUTONOMY_AUDIT_SCHEMA='gelato.vision_autonomy_audit.v1';

function glasses_vision_autonomy_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_autonomy_audits'");
    $q->execute();
    return (int)$q->fetchColumn()===1
      &&glasses_vision_active_perception_ready($pdo)
      &&glasses_vision_fleet_health_ready($pdo);
}

function glasses_vision_autonomy_package(PDO $pdo,int $org,string $publicId): array
{
    $p=glasses_vision_model_package_row($pdo,$org,$publicId,false);
    if(empty($p['artifact_sha256']))throw new InvalidArgumentException('Autonomy audit model package is missing its artifact hash.');
    return $p;
}

function glasses_vision_autonomy_runtime_bundle(PDO $pdo,int $org,string $decisionPublic): array
{
    $decision=glasses_vision_confidence_decision_row($pdo,$org,$decisionPublic);
    if(!glasses_vision_confidence_decision_verify($pdo,$org,$decisionPublic)['passed'])
        throw new InvalidArgumentException('Autonomy audit requires an intact confidence decision.');
    if($decision['modelPackagePublicId']===null)throw new InvalidArgumentException('Autonomy audit decision is not bound to a model package.');
    $package=glasses_vision_autonomy_package($pdo,$org,(string)$decision['modelPackagePublicId']);

    $context=null;$health=null;
    if($decision['contextDriftAnalysisPublicId']!==null){
        $context=glasses_vision_context_drift_row($pdo,$org,(string)$decision['contextDriftAnalysisPublicId']);
        if(!glasses_vision_context_drift_verify($pdo,$org,$context['publicId'])['passed'])
            throw new InvalidArgumentException('Autonomy audit requires an intact context analysis.');
        $health=glasses_vision_model_health_row($pdo,$org,(string)$context['healthSnapshotPublicId']);
        if(!glasses_vision_model_health_verify($pdo,$org,$health['publicId'])['passed'])
            throw new InvalidArgumentException('Autonomy audit requires an intact model-health snapshot.');
    }

    $calibration=null;
    if($decision['calibrationSelectionPublicId']!==null){
        $calibration=glasses_vision_calibration_selection_row($pdo,$org,(string)$decision['calibrationSelectionPublicId']);
        if(!glasses_vision_calibration_selection_verify($pdo,$org,$calibration['publicId'])['passed'])
            throw new InvalidArgumentException('Autonomy audit requires an intact calibration selection.');
    }

    $q=$pdo->prepare("SELECT a.public_id FROM glasses_vision_active_perception_actions a
      JOIN glasses_vision_confidence_decisions d ON d.id=a.confidence_decision_id AND d.organization_id=a.organization_id
      WHERE a.organization_id=? AND d.public_id=? ORDER BY a.id DESC LIMIT 1");
    $q->execute([$org,$decisionPublic]);$actionPublic=$q->fetchColumn();$action=null;
    if($actionPublic){
        $action=glasses_vision_active_perception_row($pdo,$org,(string)$actionPublic);
        if(!glasses_vision_active_perception_verify($pdo,$org,$action['publicId'])['passed'])
            throw new InvalidArgumentException('Autonomy audit requires an intact active-perception action.');
    }

    $authority='governed_runtime';$outcome=(string)$decision['decision'];
    if($action){
        if($action['requiresHuman']){
            $authority='human_required';
            if($action['status']==='completed'){
                $authority='human_authorized';$outcome='human_confirmed';
            }elseif($action['status']==='failed'){
                $authority='human_authorized';$outcome='human_rejected';
            }else $outcome='awaiting_human';
        }else{
            $authority='bounded_recovery';
            $outcome=match((string)$action['status']){
                'completed'=>'recovery_completed',
                'failed','expired','cancelled'=>'recovery_failed',
                default=>'recovery_in_progress',
            };
        }
    }elseif($decision['decision']==='human_review'){
        $authority='human_required';$outcome='human_review';
    }

    $evidence=[
      'schema'=>GLASSES_VISION_AUTONOMY_AUDIT_SCHEMA,'subjectKind'=>'confidence_decision',
      'confidenceDecision'=>['publicId'=>$decision['publicId'],'decisionHash'=>$decision['decisionHash']],
      'policy'=>['publicId'=>$decision['policyPublicId'],'policyHash'=>$decision['evidence']['policy']['policyHash']??null],
      'devicePublicId'=>$decision['evidence']['devicePublicId']??null,'buildSessionPublicId'=>$decision['evidence']['buildSessionPublicId']??null,
      'assignment'=>[
        'assignmentKey'=>$decision['evidence']['assignment']['assignmentKey']??null,
        'modelPackagePublicId'=>$decision['evidence']['assignment']['modelPackagePublicId']??null,
        'modelArtifactSha256'=>$decision['evidence']['assignment']['modelArtifactSha256']??null,
      ],
      'labelProfileHash'=>$decision['evidence']['labelProfileHash']??null,
      'normalizedLabel'=>$decision['normalizedLabel'],'componentKey'=>$decision['componentKey'],
      'model'=>['publicId'=>$package['public_id'],'artifactSha256'=>$package['artifact_sha256']],
      'healthSnapshot'=>$health?['publicId'=>$health['publicId'],'snapshotHash'=>$health['snapshotHash'],'sourceFingerprint'=>$health['sourceFingerprint']]:null,
      'contextDriftAnalysis'=>$context?['publicId'=>$context['publicId'],'analysisHash'=>$context['analysisHash'],'classification'=>$context['classification']]:null,
      'calibrationSelection'=>$calibration?['publicId'=>$calibration['publicId'],'selectionHash'=>$calibration['selectionHash'],'decision'=>$calibration['decision']]:null,
      'activePerceptionAction'=>$action?[
        'publicId'=>$action['publicId'],'actionHash'=>$action['actionHash'],'status'=>$action['status'],'primaryAction'=>$action['primaryAction'],
        'completionHash'=>$action['completionHash']
      ]:null,
    ];
    $thresholds=[
      'modelConfidence'=>$decision['modelConfidence'],'labelFloor'=>$decision['labelFloor'],
      'policyDefault'=>$decision['evidence']['policyDefault']??null,'classFloor'=>$decision['evidence']['classFloor']??null,
      'contextDelta'=>$decision['evidence']['context']['delta']??null,'effectiveThreshold'=>$decision['effectiveThreshold'],
    ];
    $why=[];
    $why[]='decision='.$decision['decision'];
    $why[]='confidence='.number_format((float)$decision['modelConfidence'],5,'.','');
    $why[]='effective_threshold='.number_format((float)$decision['effectiveThreshold'],4,'.','');
    if($context)$why[]='context='.$context['classification'];
    if($calibration)$why[]='calibration='.$calibration['decision'];
    if($action)$why[]='recovery='.$action['primaryAction'].':'.$action['status'];
    $explanation=[
      'summary'=>'Runtime perception decision '.$outcome.' under '.$authority.'.',
      'why'=>$why,'thresholds'=>$thresholds,
      'guardrails'=>[
        'auditOnly'=>true,'changesProductionState'=>false,'replaysWithoutExecution'=>true,
        'decisionAuthority'=>$authority,'humanRequired'=>$authority==='human_required',
      ],
    ];
    return ['subjectKind'=>'confidence_decision','subjectPublicId'=>$decisionPublic,'package'=>$package,'decision'=>$decision,
      'action'=>$action,'fleetAnalysis'=>null,'fleetAction'=>null,'authorityState'=>$authority,'outcome'=>$outcome,'evidence'=>$evidence,'explanation'=>$explanation];
}

function glasses_vision_autonomy_fleet_bundle(PDO $pdo,int $org,string $analysisPublic): array
{
    $analysis=glasses_vision_fleet_health_row($pdo,$org,$analysisPublic);
    if(!glasses_vision_fleet_health_verify($pdo,$org,$analysisPublic)['passed'])
        throw new InvalidArgumentException('Autonomy audit requires an intact fleet-health analysis.');
    $package=glasses_vision_autonomy_package($pdo,$org,(string)$analysis['modelPackagePublicId']);

    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_fleet_health_actions
      WHERE organization_id=? AND analysis_id=(SELECT id FROM glasses_vision_fleet_health_analyses WHERE organization_id=? AND public_id=?)
      ORDER BY id DESC LIMIT 1");
    $q->execute([$org,$org,$analysisPublic]);$actionPublic=$q->fetchColumn();$action=null;
    if($actionPublic){
        $action=glasses_vision_fleet_health_action_row($pdo,$org,(string)$actionPublic);
        if(!glasses_vision_fleet_health_action_verify($pdo,$org,$action['publicId'])['passed'])
            throw new InvalidArgumentException('Autonomy audit requires an intact fleet rollback action.');
    }
    $authority=$action?'human_authorized':($analysis['recommendation']==='rollback_review'?'human_required':'advisory');
    $outcome=$action?'rollback_executed':(string)$analysis['recommendation'];
    $evidence=[
      'schema'=>GLASSES_VISION_AUTONOMY_AUDIT_SCHEMA,'subjectKind'=>'fleet_health_analysis',
      'fleetHealthAnalysis'=>[
        'publicId'=>$analysis['publicId'],'analysisHash'=>$analysis['analysisHash'],'sourceFingerprint'=>$analysis['sourceFingerprint'],
        'fleetState'=>$analysis['fleetState'],'recommendation'=>$analysis['recommendation'],
      ],
      'model'=>['publicId'=>$package['public_id'],'artifactSha256'=>$package['artifact_sha256']],
      'rolloutPublicId'=>$analysis['rolloutPublicId'],'baselinePackagePublicId'=>$analysis['baselinePackagePublicId'],
      'fleetAction'=>$action?[
        'publicId'=>$action['publicId'],'actionHash'=>$action['actionHash'],'actionType'=>$action['actionType'],
        'reason'=>$action['reason']
      ]:null,
    ];
    $counts=$analysis['evidence']['counts']??[];
    $why=['fleet_state='.$analysis['fleetState'],'recommendation='.$analysis['recommendation']];
    if(isset($counts['devices']))$why[]='devices='.(int)$counts['devices'];
    if(isset($counts['locations']))$why[]='locations='.(int)$counts['locations'];
    if($action)$why[]='rollback_reason='.$action['reason'];
    $explanation=[
      'summary'=>'Fleet perception decision '.$outcome.' under '.$authority.'.',
      'why'=>$why,'fleetCounts'=>$counts,'fleetMetrics'=>$analysis['result']['metrics']??[],
      'guardrails'=>[
        'auditOnly'=>true,'changesProductionState'=>false,'replaysWithoutExecution'=>true,
        'automaticRollback'=>false,'decisionAuthority'=>$authority,
      ],
    ];
    return ['subjectKind'=>'fleet_health_analysis','subjectPublicId'=>$analysisPublic,'package'=>$package,'decision'=>null,'action'=>null,
      'fleetAnalysis'=>$analysis,'fleetAction'=>$action,'authorityState'=>$authority,'outcome'=>$outcome,'evidence'=>$evidence,'explanation'=>$explanation];
}

function glasses_vision_autonomy_capture(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_autonomy_ready($pdo))throw new RuntimeException('Vision Lab V8 autonomy-audit migration is not installed.');
    $decisionPublic=trim((string)($input['confidenceDecisionPublicId']??''));
    $fleetPublic=trim((string)($input['fleetHealthAnalysisPublicId']??''));
    if(($decisionPublic===''&&$fleetPublic==='')||($decisionPublic!==''&&$fleetPublic!==''))
        throw new InvalidArgumentException('Autonomy audit requires exactly one confidence decision or fleet-health analysis.');
    $bundle=$decisionPublic!==''?glasses_vision_autonomy_runtime_bundle($pdo,$org,$decisionPublic):glasses_vision_autonomy_fleet_bundle($pdo,$org,$fleetPublic);

    $material=[
      'schema'=>GLASSES_VISION_AUTONOMY_AUDIT_SCHEMA,'subjectKind'=>$bundle['subjectKind'],'subjectPublicId'=>$bundle['subjectPublicId'],
      'authorityState'=>$bundle['authorityState'],'outcome'=>$bundle['outcome'],'evidence'=>$bundle['evidence'],'explanation'=>$bundle['explanation']
    ];
    $hash=hash('sha256',glasses_vision_training_release_json($material));$key=$hash;
    $q=$pdo->prepare("SELECT public_id,audit_hash FROM glasses_vision_autonomy_audits WHERE organization_id=? AND audit_key=? LIMIT 1");
    $q->execute([$org,$key]);$existing=$q->fetch();
    if($existing){
        if(!hash_equals((string)$existing['audit_hash'],$hash))throw new InvalidArgumentException('Autonomy audit identity conflicts with different immutable evidence.');
        return glasses_vision_autonomy_row($pdo,$org,(string)$existing['public_id']);
    }

    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$bundle,$key,$hash):array{
        $decisionId=$perceptionId=$fleetAnalysisId=$fleetActionId=null;
        if($bundle['decision']){
            $q=$pdo->prepare("SELECT id FROM glasses_vision_confidence_decisions WHERE organization_id=? AND public_id=?");$q->execute([$org,$bundle['decision']['publicId']]);$decisionId=(int)$q->fetchColumn();
        }
        if($bundle['action']){
            $q=$pdo->prepare("SELECT id FROM glasses_vision_active_perception_actions WHERE organization_id=? AND public_id=?");$q->execute([$org,$bundle['action']['publicId']]);$perceptionId=(int)$q->fetchColumn();
        }
        if($bundle['fleetAnalysis']){
            $q=$pdo->prepare("SELECT id FROM glasses_vision_fleet_health_analyses WHERE organization_id=? AND public_id=?");$q->execute([$org,$bundle['fleetAnalysis']['publicId']]);$fleetAnalysisId=(int)$q->fetchColumn();
        }
        if($bundle['fleetAction']){
            $q=$pdo->prepare("SELECT id FROM glasses_vision_fleet_health_actions WHERE organization_id=? AND public_id=?");$q->execute([$org,$bundle['fleetAction']['publicId']]);$fleetActionId=(int)$q->fetchColumn();
        }
        $public=glasses_public_id('vision-autonomy-audit');
        $pdo->prepare("INSERT INTO glasses_vision_autonomy_audits
          (organization_id,public_id,subject_kind,subject_public_id,package_id,confidence_decision_id,active_perception_action_id,fleet_health_analysis_id,fleet_health_action_id,
           audit_key,authority_state,outcome,evidence_json,explanation_json,audit_hash,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,$bundle['subjectKind'],$bundle['subjectPublicId'],(int)$bundle['package']['id'],$decisionId,$perceptionId,$fleetAnalysisId,$fleetActionId,
            $key,$bundle['authorityState'],$bundle['outcome'],glasses_vision_training_release_json($bundle['evidence']),
            glasses_vision_training_release_json($bundle['explanation']),$hash,$actor]);
        $fromKind=$bundle['subjectKind']==='confidence_decision'?'confidence_decision':'fleet_health_analysis';
        $fromHash=$bundle['decision']?$bundle['decision']['decisionHash']:$bundle['fleetAnalysis']['analysisHash'];
        glasses_vision_lineage_edge($pdo,$org,$fromKind,$bundle['subjectPublicId'],$fromHash,'explained_as','autonomy_audit',$public,$hash,
          ['authorityState'=>$bundle['authorityState'],'outcome'=>$bundle['outcome']],$actor);
        return glasses_vision_autonomy_row($pdo,$org,$public);
    });
}

function glasses_vision_autonomy_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT a.*,p.public_id package_public_id,p.artifact_sha256 package_sha256,
      d.public_id decision_public_id,x.public_id perception_public_id,f.public_id fleet_analysis_public_id,fa.public_id fleet_action_public_id
      FROM glasses_vision_autonomy_audits a
      JOIN glasses_vision_model_packages p ON p.id=a.package_id AND p.organization_id=a.organization_id
      LEFT JOIN glasses_vision_confidence_decisions d ON d.id=a.confidence_decision_id AND d.organization_id=a.organization_id
      LEFT JOIN glasses_vision_active_perception_actions x ON x.id=a.active_perception_action_id AND x.organization_id=a.organization_id
      LEFT JOIN glasses_vision_fleet_health_analyses f ON f.id=a.fleet_health_analysis_id AND f.organization_id=a.organization_id
      LEFT JOIN glasses_vision_fleet_health_actions fa ON fa.id=a.fleet_health_action_id AND fa.organization_id=a.organization_id
      WHERE a.organization_id=? AND a.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Autonomy audit was not found.');
    return [
      'schema'=>GLASSES_VISION_AUTONOMY_AUDIT_SCHEMA,'publicId'=>$r['public_id'],'subjectKind'=>$r['subject_kind'],'subjectPublicId'=>$r['subject_public_id'],
      'modelPackagePublicId'=>$r['package_public_id'],'modelArtifactSha256'=>$r['package_sha256'],
      'confidenceDecisionPublicId'=>$r['decision_public_id'],'activePerceptionActionPublicId'=>$r['perception_public_id'],
      'fleetHealthAnalysisPublicId'=>$r['fleet_analysis_public_id'],'fleetHealthActionPublicId'=>$r['fleet_action_public_id'],
      'authorityState'=>$r['authority_state'],'outcome'=>$r['outcome'],'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],
      'explanation'=>json_decode((string)$r['explanation_json'],true)?:[],'auditHash'=>$r['audit_hash'],'createdAt'=>$r['created_at']
    ];
}

function glasses_vision_autonomy_verify(PDO $pdo,int $org,string $publicId): array
{
    $a=glasses_vision_autonomy_row($pdo,$org,$publicId);
    $material=['schema'=>GLASSES_VISION_AUTONOMY_AUDIT_SCHEMA,'subjectKind'=>$a['subjectKind'],'subjectPublicId'=>$a['subjectPublicId'],
      'authorityState'=>$a['authorityState'],'outcome'=>$a['outcome'],'evidence'=>$a['evidence'],'explanation'=>$a['explanation']];
    $hash=hash('sha256',glasses_vision_training_release_json($material));
    $passed=hash_equals($a['auditHash'],$hash)
      &&hash_equals((string)($a['evidence']['model']['publicId']??''),(string)$a['modelPackagePublicId'])
      &&hash_equals((string)($a['evidence']['model']['artifactSha256']??''),(string)$a['modelArtifactSha256']);
    if($a['subjectKind']==='confidence_decision'){
        $assignedPublic=(string)($a['evidence']['assignment']['modelPackagePublicId']??'');
        $assignedHash=(string)($a['evidence']['assignment']['modelArtifactSha256']??'');
        $passed=$passed&&hash_equals($assignedPublic,(string)$a['modelPackagePublicId'])&&hash_equals($assignedHash,(string)$a['modelArtifactSha256']);
    }
    if($a['subjectKind']==='confidence_decision'){
        $passed=$passed&&$a['confidenceDecisionPublicId']!==null
          &&hash_equals((string)$a['subjectPublicId'],(string)$a['confidenceDecisionPublicId']);
        if($passed){
            $decision=glasses_vision_confidence_decision_row($pdo,$org,(string)$a['confidenceDecisionPublicId']);
            $passed=$passed
              &&glasses_vision_confidence_decision_verify($pdo,$org,(string)$a['confidenceDecisionPublicId'])['passed']
              &&hash_equals((string)($a['evidence']['confidenceDecision']['decisionHash']??''),(string)$decision['decisionHash']);
        }
        if($passed&&$a['activePerceptionActionPublicId']!==null){
            $action=glasses_vision_active_perception_row($pdo,$org,(string)$a['activePerceptionActionPublicId']);
            $passed=$passed
              &&glasses_vision_active_perception_verify($pdo,$org,(string)$a['activePerceptionActionPublicId'])['passed']
              &&hash_equals((string)($a['evidence']['activePerceptionAction']['actionHash']??''),(string)$action['actionHash']);
        }
        $ctx=$a['evidence']['contextDriftAnalysis']??null;
        if($passed&&is_array($ctx)&&!empty($ctx['publicId'])){
            $live=glasses_vision_context_drift_row($pdo,$org,(string)$ctx['publicId']);
            $passed=$passed&&glasses_vision_context_drift_verify($pdo,$org,(string)$ctx['publicId'])['passed']
              &&hash_equals((string)($ctx['analysisHash']??''),(string)$live['analysisHash']);
        }
        $health=$a['evidence']['healthSnapshot']??null;
        if($passed&&is_array($health)&&!empty($health['publicId'])){
            $live=glasses_vision_model_health_row($pdo,$org,(string)$health['publicId']);
            $passed=$passed&&glasses_vision_model_health_verify($pdo,$org,(string)$health['publicId'])['passed']
              &&hash_equals((string)($health['snapshotHash']??''),(string)$live['snapshotHash'])
              &&hash_equals((string)($health['sourceFingerprint']??''),(string)$live['sourceFingerprint']);
        }
        $cal=$a['evidence']['calibrationSelection']??null;
        if($passed&&is_array($cal)&&!empty($cal['publicId'])){
            $live=glasses_vision_calibration_selection_row($pdo,$org,(string)$cal['publicId']);
            $passed=$passed&&glasses_vision_calibration_selection_verify($pdo,$org,(string)$cal['publicId'])['passed']
              &&hash_equals((string)($cal['selectionHash']??''),(string)$live['selectionHash']);
        }
    }elseif($a['subjectKind']==='fleet_health_analysis'){
        $passed=$passed&&$a['fleetHealthAnalysisPublicId']!==null
          &&hash_equals((string)$a['subjectPublicId'],(string)$a['fleetHealthAnalysisPublicId']);
        if($passed){
            $fleet=glasses_vision_fleet_health_row($pdo,$org,(string)$a['fleetHealthAnalysisPublicId']);
            $passed=$passed
              &&glasses_vision_fleet_health_verify($pdo,$org,(string)$a['fleetHealthAnalysisPublicId'])['passed']
              &&hash_equals((string)($a['evidence']['fleetHealthAnalysis']['analysisHash']??''),(string)$fleet['analysisHash'])
              &&hash_equals((string)($a['evidence']['fleetHealthAnalysis']['sourceFingerprint']??''),(string)$fleet['sourceFingerprint']);
        }
        if($passed&&$a['fleetHealthActionPublicId']!==null){
            $action=glasses_vision_fleet_health_action_row($pdo,$org,(string)$a['fleetHealthActionPublicId']);
            $passed=$passed&&glasses_vision_fleet_health_action_verify($pdo,$org,(string)$a['fleetHealthActionPublicId'])['passed']
              &&hash_equals((string)($a['evidence']['fleetAction']['actionHash']??''),(string)$action['actionHash']);
        }
    }else $passed=false;
    return ['passed'=>$passed,'auditHash'=>$a['auditHash'],'recomputedAuditHash'=>$hash];
}

function glasses_vision_autonomy_replay(PDO $pdo,int $org,string $publicId): array
{
    $a=glasses_vision_autonomy_row($pdo,$org,$publicId);
    $verification=glasses_vision_autonomy_verify($pdo,$org,$publicId);
    if(!$verification['passed'])throw new InvalidArgumentException('Autonomy audit failed integrity verification.');
    return [
      'schema'=>GLASSES_VISION_AUTONOMY_AUDIT_SCHEMA,'publicId'=>$a['publicId'],'replayOnly'=>true,'changesProductionState'=>false,
      'subjectKind'=>$a['subjectKind'],'subjectPublicId'=>$a['subjectPublicId'],'authorityState'=>$a['authorityState'],'outcome'=>$a['outcome'],
      'explanation'=>$a['explanation'],'evidence'=>$a['evidence'],'auditHash'=>$a['auditHash']
    ];
}

function glasses_vision_autonomy_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_autonomy_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_AUTONOMY_AUDIT_SCHEMA,'audits'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_autonomy_audits WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
    $audits=[];$outcomes=[];$authorities=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){
        $a=glasses_vision_autonomy_row($pdo,$org,(string)$public);$audits[]=$a;
        $outcomes[$a['outcome']]=($outcomes[$a['outcome']]??0)+1;$authorities[$a['authorityState']]=($authorities[$a['authorityState']]??0)+1;
    }
    ksort($outcomes);ksort($authorities);
    return ['ready'=>true,'schema'=>GLASSES_VISION_AUTONOMY_AUDIT_SCHEMA,'outcomes'=>$outcomes,'authorityStates'=>$authorities,'audits'=>$audits];
}
