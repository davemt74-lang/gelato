<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-calibration-profiles.php';
require_once __DIR__.'/glasses-vision-profiles.php';

const GLASSES_VISION_CONFIDENCE_POLICY_SCHEMA='gelato.vision_confidence_policy.v1';
const GLASSES_VISION_CONFIDENCE_HARD_FLOOR=0.50;

function glasses_vision_confidence_policy_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_confidence_policies','glasses_vision_confidence_policy_events','glasses_vision_confidence_decisions'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_calibration_profile_ready($pdo)&&glasses_vision_profiles_ready($pdo);
}

function glasses_vision_confidence_threshold(mixed $value,string $label,float $minimum=GLASSES_VISION_CONFIDENCE_HARD_FLOOR): float
{
    $v=(float)$value;
    if(!is_finite($v)||$v<$minimum||$v>0.99)throw new InvalidArgumentException($label.' must be between '.number_format($minimum,2).' and 0.99.');
    return round($v,4);
}

function glasses_vision_confidence_class_rules(array $input): array
{
    $out=[];
    foreach($input as $label=>$rule){
        $normalized=glasses_vision_normalize_label((string)$label);
        if($normalized===''||!is_array($rule))throw new InvalidArgumentException('Confidence class rule is invalid.');
        $floor=glasses_vision_confidence_threshold($rule['minimumConfidence']??GLASSES_VISION_CONFIDENCE_HARD_FLOOR,'Class minimum confidence');
        $out[$normalized]=['minimumConfidence'=>$floor,'requireHumanReview'=>!empty($rule['requireHumanReview'])];
    }
    ksort($out,SORT_STRING);return $out;
}

function glasses_vision_confidence_context_rules(array $input): array
{
    $defaults=[
      'stable'=>['delta'=>0.0,'requireHumanReview'=>false],
      'context_shift'=>['delta'=>0.05,'requireHumanReview'=>false],
      'model_degradation'=>['delta'=>0.10,'requireHumanReview'=>true],
      'mixed'=>['delta'=>0.15,'requireHumanReview'=>true],
      'ambiguous'=>['delta'=>0.10,'requireHumanReview'=>true],
      'insufficient_context'=>['delta'=>0.10,'requireHumanReview'=>true],
    ];
    foreach($defaults as $state=>$base){
        if(!isset($input[$state]))continue;
        if(!is_array($input[$state]))throw new InvalidArgumentException('Confidence context rule is invalid.');
        $delta=(float)($input[$state]['delta']??$base['delta']);
        if(!is_finite($delta)||$delta<0||$delta>0.30)throw new InvalidArgumentException('Confidence context adjustment must be between 0 and 0.30.');
        $defaults[$state]=['delta'=>round($delta,4),'requireHumanReview'=>!empty($input[$state]['requireHumanReview'])];
    }
    return $defaults;
}

function glasses_vision_confidence_policy_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT p.*,m.public_id package_public_id,m.artifact_sha256 package_sha256 FROM glasses_vision_confidence_policies p
      LEFT JOIN glasses_vision_model_packages m ON m.id=p.package_id AND m.organization_id=p.organization_id
      WHERE p.organization_id=? AND p.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Confidence policy was not found.');
    return [
      'schema'=>GLASSES_VISION_CONFIDENCE_POLICY_SCHEMA,'publicId'=>$r['public_id'],'policyKey'=>$r['policy_key'],'detectorName'=>$r['detector_name'],
      'modelPackagePublicId'=>$r['package_public_id'],'modelPackageSha256'=>$r['package_sha256'],'status'=>$r['status'],
      'hardFloor'=>(float)$r['hard_floor'],'defaultThreshold'=>(float)$r['default_threshold'],
      'classRules'=>json_decode((string)$r['class_rules_json'],true)?:[],'contextRules'=>json_decode((string)$r['context_rules_json'],true)?:[],
      'guardrails'=>json_decode((string)$r['guardrails_json'],true)?:[],'policyHash'=>$r['policy_hash'],
      'activatedAt'=>$r['activated_at'],'retiredAt'=>$r['retired_at'],'createdAt'=>$r['created_at'],
    ];
}

function glasses_vision_confidence_policy_event(PDO $pdo,int $org,int $policyId,string $publicId,string $hash,string $type,array $evidence,int $actor): void
{
    $material=['schema'=>GLASSES_VISION_CONFIDENCE_POLICY_SCHEMA,'policyPublicId'=>$publicId,'policyHash'=>$hash,'eventType'=>$type,'evidence'=>$evidence];
    $eventHash=hash('sha256',glasses_vision_training_release_json($material));
    $pdo->prepare("INSERT INTO glasses_vision_confidence_policy_events (organization_id,policy_id,event_type,event_hash,evidence_json,actor_user_id) VALUES (?,?,?,?,?,?)")
      ->execute([$org,$policyId,$type,$eventHash,glasses_vision_training_release_json($material),$actor]);
}

function glasses_vision_confidence_policy_create(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_confidence_policy_ready($pdo))throw new RuntimeException('Vision Lab V8 confidence-policy migration is not installed.');
    $key=mb_substr(trim((string)($input['policyKey']??'')),0,160,'UTF-8');
    if($key===''||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,159}$/',$key))throw new InvalidArgumentException('Confidence policy key is invalid.');
    $detector=glasses_vision_normalize_detector((string)($input['detectorName']??''));
    if($detector==='*')throw new InvalidArgumentException('Adaptive confidence policies require a specific detector.');
    $package=null;
    if(trim((string)($input['modelPackagePublicId']??''))!==''){
        $package=glasses_vision_model_package_row($pdo,$org,(string)$input['modelPackagePublicId'],false);
        if((string)$package['status']!=='ready')throw new InvalidArgumentException('Confidence policy model package must be ready.');
        if(!hash_equals((string)$package['detector_name'],$detector))throw new InvalidArgumentException('Confidence policy detector does not match model package.');
    }
    $default=glasses_vision_confidence_threshold($input['defaultThreshold']??0.70,'Default confidence');
    $classRules=glasses_vision_confidence_class_rules(is_array($input['classRules']??null)?$input['classRules']:[]);
    $contextRules=glasses_vision_confidence_context_rules(is_array($input['contextRules']??null)?$input['contextRules']:[]);
    $guardrails=[
      'hardMinimumConfidence'=>GLASSES_VISION_CONFIDENCE_HARD_FLOOR,'mayLowerBelowLabelFloor'=>false,'mayLowerBelowPolicyFloor'=>false,
      'automaticModelChange'=>false,'automaticCalibrationChange'=>false,'automaticRolloutAction'=>false,'decisionOnly'=>true,
    ];
    $material=['schema'=>GLASSES_VISION_CONFIDENCE_POLICY_SCHEMA,'policyKey'=>$key,'detectorName'=>$detector,
      'model'=>$package?['publicId'=>$package['public_id'],'artifactSha256'=>$package['artifact_sha256']]:null,
      'hardFloor'=>GLASSES_VISION_CONFIDENCE_HARD_FLOOR,'defaultThreshold'=>$default,'classRules'=>$classRules,'contextRules'=>$contextRules,'guardrails'=>$guardrails];
    $hash=hash('sha256',glasses_vision_training_release_json($material));
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_confidence_policies WHERE organization_id=? AND policy_key=? AND policy_hash=? LIMIT 1");
    $q->execute([$org,$key,$hash]);$existing=$q->fetchColumn();if($existing)return glasses_vision_confidence_policy_row($pdo,$org,(string)$existing);
    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$key,$detector,$package,$default,$classRules,$contextRules,$guardrails,$hash):array{
        $public=glasses_public_id('vision-confidence-policy');
        $pdo->prepare("INSERT INTO glasses_vision_confidence_policies
          (organization_id,public_id,policy_key,detector_name,package_id,status,hard_floor,default_threshold,class_rules_json,context_rules_json,guardrails_json,policy_hash,created_by)
          VALUES (?,?,?,?,?,'draft',?,?,?,?,?,?,?)")
          ->execute([$org,$public,$key,$detector,$package?(int)$package['id']:null,GLASSES_VISION_CONFIDENCE_HARD_FLOOR,$default,
            glasses_vision_training_release_json($classRules),glasses_vision_training_release_json($contextRules),glasses_vision_training_release_json($guardrails),$hash,$actor]);
        $id=(int)$pdo->lastInsertId();
        glasses_vision_confidence_policy_event($pdo,$org,$id,$public,$hash,'created',['status'=>'draft'],$actor);
        if($package)glasses_vision_lineage_edge($pdo,$org,'model_package',(string)$package['public_id'],(string)$package['artifact_sha256'],'governed_by','confidence_policy',$public,$hash,['detectorName'=>$detector],$actor);
        return glasses_vision_confidence_policy_row($pdo,$org,$public);
    });
}

function glasses_vision_confidence_policy_verify(PDO $pdo,int $org,string $publicId): array
{
    $p=glasses_vision_confidence_policy_row($pdo,$org,$publicId);
    $material=['schema'=>GLASSES_VISION_CONFIDENCE_POLICY_SCHEMA,'policyKey'=>$p['policyKey'],'detectorName'=>$p['detectorName'],
      'model'=>$p['modelPackagePublicId']!==null?['publicId'=>$p['modelPackagePublicId'],'artifactSha256'=>$p['modelPackageSha256']]:null,
      'hardFloor'=>$p['hardFloor'],'defaultThreshold'=>$p['defaultThreshold'],'classRules'=>$p['classRules'],'contextRules'=>$p['contextRules'],'guardrails'=>$p['guardrails']];
    $hash=hash('sha256',glasses_vision_training_release_json($material));
    return ['passed'=>hash_equals((string)$p['policyHash'],$hash),'policyHash'=>$p['policyHash'],'recomputedPolicyHash'=>$hash];
}

function glasses_vision_confidence_policy_status(PDO $pdo,int $org,string $publicId,string $status,int $actor): array
{
    if(!in_array($status,['active','retired'],true))throw new InvalidArgumentException('Confidence policy status is invalid.');
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$status,$actor):array{
        $q=$pdo->prepare("SELECT * FROM glasses_vision_confidence_policies WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Confidence policy was not found.');
        if((string)$r['status']===$status)return glasses_vision_confidence_policy_row($pdo,$org,$publicId);
        if((string)$r['status']==='retired'&&$status==='active')throw new InvalidArgumentException('Retired confidence policies cannot be reactivated.');
        if($status==='active'){
            if(!glasses_vision_confidence_policy_verify($pdo,$org,$publicId)['passed'])throw new InvalidArgumentException('Confidence policy failed integrity verification.');
            if((float)$r['hard_floor']<GLASSES_VISION_CONFIDENCE_HARD_FLOOR||(float)$r['default_threshold']<(float)$r['hard_floor'])
                throw new InvalidArgumentException('Confidence policy violates the hard safety floor.');
            if($r['package_id']!==null){
                $q2=$pdo->prepare("SELECT status FROM glasses_vision_model_packages WHERE organization_id=? AND id=?");$q2->execute([$org,(int)$r['package_id']]);
                if((string)$q2->fetchColumn()!=='ready')throw new InvalidArgumentException('Confidence policy model package is no longer ready.');
            }
            $pdo->prepare("UPDATE glasses_vision_confidence_policies SET status='active',activated_by=?,activated_at=COALESCE(activated_at,NOW(6)),retired_at=NULL WHERE organization_id=? AND id=?")
              ->execute([$actor,$org,(int)$r['id']]);
        }else{
            $pdo->prepare("UPDATE glasses_vision_confidence_policies SET status='retired',retired_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$org,(int)$r['id']]);
        }
        glasses_vision_confidence_policy_event($pdo,$org,(int)$r['id'],(string)$r['public_id'],(string)$r['policy_hash'],$status==='active'?'activated':'retired',['previousStatus'=>$r['status'],'status'=>$status],$actor);
        return glasses_vision_confidence_policy_row($pdo,$org,$publicId);
    });
}

function glasses_vision_confidence_assignment(PDO $pdo,int $org,array $device,array $session,string $detector): ?array
{
    $q=$pdo->prepare("SELECT a.*,p.public_id package_public_id,p.artifact_sha256 package_sha256,p.status package_status
      FROM glasses_vision_model_assignments a LEFT JOIN glasses_vision_model_packages p ON p.id=a.package_id AND p.organization_id=a.organization_id
      WHERE a.organization_id=? AND a.device_id=? AND a.build_session_id=? AND a.detector_name=? AND a.action='apply'
      ORDER BY a.issued_at DESC,a.id DESC LIMIT 1");
    $q->execute([$org,(int)$device['id'],(int)$session['id'],$detector]);return $q->fetch()?:null;
}

function glasses_vision_confidence_active_policy(PDO $pdo,int $org,string $detector,?int $packageId): array
{
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_confidence_policies
      WHERE organization_id=? AND detector_name=? AND status='active' AND (package_id=? OR package_id IS NULL)
      ORDER BY package_id IS NOT NULL DESC,activated_at DESC,id DESC LIMIT 1");
    $q->execute([$org,$detector,$packageId??0]);$public=$q->fetchColumn();
    if(!$public)throw new InvalidArgumentException('No active adaptive confidence policy is available for this detector/model.');
    $p=glasses_vision_confidence_policy_row($pdo,$org,(string)$public);
    if(!glasses_vision_confidence_policy_verify($pdo,$org,$p['publicId'])['passed'])throw new InvalidArgumentException('Active confidence policy failed integrity verification.');
    return $p;
}

function glasses_vision_confidence_decide(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_confidence_policy_ready($pdo))throw new RuntimeException('Vision Lab V8 confidence-policy migration is not installed.');
    $devicePublic=trim((string)($input['devicePublicId']??''));$sessionPublic=trim((string)($input['buildSessionPublicId']??''));
    $detector=glasses_vision_normalize_detector((string)($input['detectorName']??''));
    $label=glasses_vision_normalize_label((string)($input['modelLabel']??''));
    $confidence=(float)($input['confidence']??-1);
    if($devicePublic===''||$sessionPublic===''||$detector==='*'||$label===''||!is_finite($confidence)||$confidence<0||$confidence>1)
        throw new InvalidArgumentException('Adaptive confidence decision input is invalid.');

    $dq=$pdo->prepare("SELECT * FROM glasses_devices WHERE organization_id=? AND public_id=? LIMIT 1");$dq->execute([$org,$devicePublic]);$device=$dq->fetch();
    if(!$device)throw new InvalidArgumentException('Vision decision device was not found.');
    $session=glasses_build_session_row($pdo,$org,$sessionPublic,false);glasses_build_assert_device_session($device,$session);
    $assignment=glasses_vision_confidence_assignment($pdo,$org,$device,$session,$detector);
    $packageId=$assignment&&$assignment['package_id']!==null?(int)$assignment['package_id']:null;
    $policy=glasses_vision_confidence_active_policy($pdo,$org,$detector,$packageId);

    $runtimeProfile=glasses_vision_profile_for_build($pdo,$device,$sessionPublic,$detector);
    $mapping=null;foreach($runtimeProfile['mappings'] as $m)if((string)$m['normalizedLabel']===$label){$mapping=$m;break;}
    $labelFloor=$mapping&&$mapping['minimumConfidence']!==null?(float)$mapping['minimumConfidence']:GLASSES_VISION_CONFIDENCE_HARD_FLOOR;
    $componentKey=$mapping['componentKey']??null;

    $analysis=null;$state='insufficient_context';
    if(trim((string)($input['contextDriftAnalysisPublicId']??''))!==''){
        $analysis=glasses_vision_context_drift_row($pdo,$org,(string)$input['contextDriftAnalysisPublicId']);
        if(!glasses_vision_context_drift_verify($pdo,$org,$analysis['publicId'])['passed'])throw new InvalidArgumentException('Confidence decision requires an intact context-drift analysis.');
        $snap=glasses_vision_context_drift_snapshot_db($pdo,$org,(string)$analysis['healthSnapshotPublicId']);
        if($snap['_deviceId']===null||(int)$snap['_deviceId']!==(int)$device['id']||(int)$snap['_buildSessionId']!==(int)$session['id'])
            throw new InvalidArgumentException('Context-drift analysis does not belong to this device/build session.');
        if($assignment&&$assignment['package_public_id']!==null&&!hash_equals((string)$snap['packagePublicId'],(string)$assignment['package_public_id']))
            throw new InvalidArgumentException('Context-drift analysis model does not match assigned model.');
        $state=(string)$analysis['classification'];
    }
    $rule=$policy['contextRules'][$state]??$policy['contextRules']['insufficient_context'];
    $classRule=$policy['classRules'][$label]??['minimumConfidence'=>GLASSES_VISION_CONFIDENCE_HARD_FLOOR,'requireHumanReview'=>false];
    $base=max(GLASSES_VISION_CONFIDENCE_HARD_FLOOR,$policy['hardFloor'],$policy['defaultThreshold'],$labelFloor,(float)$classRule['minimumConfidence']);
    $effective=min(0.99,round($base+(float)$rule['delta'],4));
    $requiresHuman=!empty($classRule['requireHumanReview'])||!empty($rule['requireHumanReview']);
    if(!$mapping)$decision='unmapped_hold';
    elseif($confidence<$effective)$decision='below_threshold_hold';
    elseif($requiresHuman)$decision='human_review';
    else $decision='accept';

    $evidence=['schema'=>GLASSES_VISION_CONFIDENCE_POLICY_SCHEMA,'policy'=>['publicId'=>$policy['publicId'],'policyHash'=>$policy['policyHash']],
      'devicePublicId'=>$devicePublic,'buildSessionPublicId'=>$sessionPublic,'detectorName'=>$detector,
      'assignment'=>['assignmentKey'=>$assignment['assignment_key']??null,'modelPackagePublicId'=>$assignment['package_public_id']??null,'modelArtifactSha256'=>$assignment['package_sha256']??null],
      'labelProfileHash'=>$runtimeProfile['profileHash'],'modelLabel'=>(string)($input['modelLabel']??''),'normalizedLabel'=>$label,'componentKey'=>$componentKey,
      'modelConfidence'=>round($confidence,5),'labelFloor'=>round($labelFloor,4),'policyDefault'=>$policy['defaultThreshold'],'classFloor'=>(float)$classRule['minimumConfidence'],
      'context'=>['analysisPublicId'=>$analysis['publicId']??null,'analysisHash'=>$analysis['analysisHash']??null,'classification'=>$state,'delta'=>(float)$rule['delta']],
      'effectiveThreshold'=>$effective,'requireHumanReview'=>$requiresHuman,'decision'=>$decision,
    ];
    $hash=hash('sha256',glasses_vision_training_release_json($evidence));$key=$hash;
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_confidence_decisions WHERE organization_id=? AND decision_key=? LIMIT 1");$q->execute([$org,$key]);$existing=$q->fetchColumn();
    if($existing)return glasses_vision_confidence_decision_row($pdo,$org,(string)$existing);

    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$policy,$device,$session,$assignment,$analysis,$key,$detector,$label,$componentKey,$confidence,$labelFloor,$effective,$decision,$evidence,$hash):array{
        $pq=$pdo->prepare("SELECT id FROM glasses_vision_confidence_policies WHERE organization_id=? AND public_id=?");$pq->execute([$org,$policy['publicId']]);$policyId=(int)$pq->fetchColumn();
        $analysisId=null;if($analysis){$q=$pdo->prepare("SELECT id FROM glasses_vision_context_drift_analyses WHERE organization_id=? AND public_id=?");$q->execute([$org,$analysis['publicId']]);$analysisId=(int)$q->fetchColumn();}
        $calId=null;
        if(trim((string)($evidence['context']['analysisPublicId']??''))!==''){
            $q=$pdo->prepare("SELECT id FROM glasses_vision_calibration_selections WHERE organization_id=? AND device_id=? AND context_drift_analysis_id=? ORDER BY id DESC LIMIT 1");
            $q->execute([$org,(int)$device['id'],$analysisId]);$v=$q->fetchColumn();if($v!==false)$calId=(int)$v;
        }
        $public=glasses_public_id('vision-confidence-decision');
        $pdo->prepare("INSERT INTO glasses_vision_confidence_decisions
          (organization_id,public_id,policy_id,device_id,build_session_id,package_id,context_drift_analysis_id,calibration_selection_id,decision_key,detector_name,normalized_label,component_key,model_confidence,label_floor,effective_threshold,decision,evidence_json,decision_hash,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,$policyId,(int)$device['id'],(int)$session['id'],$assignment&&$assignment['package_id']!==null?(int)$assignment['package_id']:null,$analysisId,$calId,$key,$detector,$label,$componentKey,round($confidence,5),round($labelFloor,4),$effective,$decision,glasses_vision_training_release_json($evidence),$hash,$actor]);
        glasses_vision_lineage_edge($pdo,$org,'confidence_policy',$policy['publicId'],$policy['policyHash'],'decided_as','confidence_decision',$public,$hash,['decision'=>$decision,'effectiveThreshold'=>$effective],$actor);
        return glasses_vision_confidence_decision_row($pdo,$org,$public);
    });
}

function glasses_vision_confidence_decision_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT d.*,p.public_id policy_public_id,a.public_id analysis_public_id,c.public_id calibration_public_id,m.public_id package_public_id
      FROM glasses_vision_confidence_decisions d JOIN glasses_vision_confidence_policies p ON p.id=d.policy_id AND p.organization_id=d.organization_id
      LEFT JOIN glasses_vision_context_drift_analyses a ON a.id=d.context_drift_analysis_id AND a.organization_id=d.organization_id
      LEFT JOIN glasses_vision_calibration_selections c ON c.id=d.calibration_selection_id AND c.organization_id=d.organization_id
      LEFT JOIN glasses_vision_model_packages m ON m.id=d.package_id AND m.organization_id=d.organization_id
      WHERE d.organization_id=? AND d.public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Confidence decision was not found.');
    return ['schema'=>GLASSES_VISION_CONFIDENCE_POLICY_SCHEMA,'publicId'=>$r['public_id'],'policyPublicId'=>$r['policy_public_id'],
      'modelPackagePublicId'=>$r['package_public_id'],'contextDriftAnalysisPublicId'=>$r['analysis_public_id'],'calibrationSelectionPublicId'=>$r['calibration_public_id'],
      'detectorName'=>$r['detector_name'],'normalizedLabel'=>$r['normalized_label'],'componentKey'=>$r['component_key'],'modelConfidence'=>(float)$r['model_confidence'],
      'labelFloor'=>(float)$r['label_floor'],'effectiveThreshold'=>(float)$r['effective_threshold'],'decision'=>$r['decision'],
      'evidence'=>json_decode((string)$r['evidence_json'],true)?:[],'decisionHash'=>$r['decision_hash'],'createdAt'=>$r['created_at']];
}

function glasses_vision_confidence_decision_verify(PDO $pdo,int $org,string $publicId): array
{
    $d=glasses_vision_confidence_decision_row($pdo,$org,$publicId);
    $hash=hash('sha256',glasses_vision_training_release_json($d['evidence']));
    $passed=hash_equals($d['decisionHash'],$hash)&&glasses_vision_confidence_policy_verify($pdo,$org,$d['policyPublicId'])['passed'];
    if($d['contextDriftAnalysisPublicId']!==null)$passed=$passed&&glasses_vision_context_drift_verify($pdo,$org,$d['contextDriftAnalysisPublicId'])['passed'];
    if($d['calibrationSelectionPublicId']!==null)$passed=$passed&&glasses_vision_calibration_selection_verify($pdo,$org,$d['calibrationSelectionPublicId'])['passed'];
    return ['passed'=>$passed,'decisionHash'=>$d['decisionHash'],'recomputedDecisionHash'=>$hash];
}

function glasses_vision_confidence_policy_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_confidence_policy_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_CONFIDENCE_POLICY_SCHEMA,'policies'=>[],'decisions'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_confidence_policies WHERE organization_id=? ORDER BY status='active' DESC,id DESC");$q->execute([$org]);
    $p=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $x)$p[]=glasses_vision_confidence_policy_row($pdo,$org,(string)$x);
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_confidence_decisions WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
    $d=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $x)$d[]=glasses_vision_confidence_decision_row($pdo,$org,(string)$x);
    return ['ready'=>true,'schema'=>GLASSES_VISION_CONFIDENCE_POLICY_SCHEMA,'hardFloor'=>GLASSES_VISION_CONFIDENCE_HARD_FLOOR,'policies'=>$p,'decisions'=>$d];
}
