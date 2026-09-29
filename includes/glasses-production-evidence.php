<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-training-identity.php';
require_once __DIR__.'/glasses-vision-training-media.php';
require_once __DIR__.'/glasses-vision-models.php';

const GLASSES_V11_PRODUCTION_EVIDENCE_SCHEMA='gelato.glasses_production_evidence.v1';

function glasses_v11_evidence_ready(PDO $pdo): bool {
  if(!glasses_training_ready($pdo)||!glasses_vision_training_media_ready($pdo))return false;
  foreach(['training_session_id','training_assignment_id','training_program_id','model_package_id','training_eligibility'] as $column){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='glasses_vision_training_media' AND column_name=?");
    $q->execute([$column]);if((int)$q->fetchColumn()!==1)return false;
  }
  return true;
}

function glasses_v11_evidence_session(PDO $pdo,int $org,array $device,?string $sessionPublic,string $capturedAt): array {
  if($sessionPublic!==null&&trim($sessionPublic)!==''){
    $session=glasses_training_session($pdo,$org,trim($sessionPublic));
    if((int)$session['device_id']!==(int)$device['id'])throw new InvalidArgumentException('Training session belongs to a different glasses device.');
    if((string)$session['status']!=='active')throw new InvalidArgumentException('Production evidence requires an active glasses training session.');
    $capture=new DateTimeImmutable($capturedAt,new DateTimeZone('UTC'));$started=new DateTimeImmutable((string)$session['started_at'],new DateTimeZone('UTC'));
    if($capture<$started->modify('-2 seconds'))throw new InvalidArgumentException('Production evidence capture predates the active training session.');
    return $session;
  }
  $q=$pdo->prepare("SELECT public_id FROM glasses_training_sessions
    WHERE organization_id=? AND device_id=? AND status='active' AND started_at<=?
    ORDER BY started_at DESC,id DESC LIMIT 1");
  $q->execute([$org,(int)$device['id'],$capturedAt]);$public=$q->fetchColumn();
  if($public===false)throw new InvalidArgumentException('Production evidence capture requires an active V11 glasses training session.');
  return glasses_training_session($pdo,$org,(string)$public);
}

function glasses_v11_evidence_model(PDO $pdo,int $org,?string $public): ?array {
  $public=trim((string)$public);if($public==='')return null;
  $row=glasses_vision_model_package_row($pdo,$org,$public,false);
  if((string)$row['status']!=='ready')throw new InvalidArgumentException('Production evidence model package must be ready.');
  return $row;
}

function glasses_v11_evidence_context(array $session,?array $model,array $input): array {
  $step=mb_substr(trim((string)($input['recipeStepKey']??'')),0,160,'UTF-8')?:null;
  $validation=mb_substr(trim((string)($input['validationOutcome']??'')),0,80,'UTF-8')?:null;
  if($validation!==null&&!preg_match('/^[a-z0-9_.-]{2,80}$/',$validation))throw new InvalidArgumentException('Validation outcome key is invalid.');
  $eligibility=(string)($input['trainingEligibility']??'review');
  if(!in_array($eligibility,['review','eligible','excluded'],true))throw new InvalidArgumentException('Training eligibility is invalid.');
  $exclusion=mb_substr(trim((string)($input['trainingExclusionReason']??'')),0,500,'UTF-8')?:null;
  if($eligibility==='excluded'&&$exclusion===null)throw new InvalidArgumentException('Excluded evidence requires an exclusion reason.');
  if($eligibility!=='excluded')$exclusion=null;
  $privacy=(string)($input['privacyScope']??'training_opt_in');
  if(!in_array($privacy,['training_opt_in','internal_review'],true))throw new InvalidArgumentException('Privacy scope is invalid.');
  return [
    'schema'=>GLASSES_V11_PRODUCTION_EVIDENCE_SCHEMA,
    'trainingSessionPublicId'=>$session['public_id'],
    'trainingAssignmentPublicId'=>$session['assignment_public_id'],
    'programPublicId'=>$session['program_public_id'],
    'operatorUserId'=>(int)$session['user_id'],
    'operatorName'=>$session['user_name'],
    'devicePublicId'=>$session['device_public_id'],
    'stationPublicId'=>$session['station_public_id'],
    'supervisorUserId'=>$session['supervisor_user_id']!==null?(int)$session['supervisor_user_id']:null,
    'mode'=>$session['mode'],
    'modelPackagePublicId'=>$model['public_id']??null,
    'modelArtifactSha256'=>$model['artifact_sha256']??null,
    'recipeStepKey'=>$step,
    'validationOutcome'=>$validation,
    'trainingEligibility'=>$eligibility,
    'trainingExclusionReason'=>$exclusion,
    'privacyScope'=>$privacy,
  ];
}

function glasses_v11_production_evidence_capture(PDO $pdo,array $device,array $input,int $actor): array {
  if(!glasses_v11_evidence_ready($pdo))throw new RuntimeException('V11 production evidence migration is not installed.');
  $org=(int)$device['organization_id'];
  if((string)$device['status']!=='active'||$device['revoked_at']!==null)throw new InvalidArgumentException('Active paired glasses are required.');
  $capturedAt=trim((string)($input['capturedAt']??''))?:gmdate('Y-m-d H:i:s');
  try{$captured=(new DateTimeImmutable($capturedAt))->setTimezone(new DateTimeZone('UTC'));}catch(Throwable){throw new InvalidArgumentException('Evidence capture time is invalid.');}
  $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
  if($captured>$now->modify('+5 seconds')||$captured<$now->modify('-10 minutes'))throw new InvalidArgumentException('Production evidence capture time is outside the accepted window.');
  $capturedSql=$captured->format('Y-m-d H:i:s');

  $session=glasses_v11_evidence_session($pdo,$org,$device,!empty($input['trainingSessionPublicId'])?(string)$input['trainingSessionPublicId']:null,$capturedSql);
  $model=glasses_v11_evidence_model($pdo,$org,!empty($input['modelPackagePublicId'])?(string)$input['modelPackagePublicId']:null);
  $input['trainingEligibility']='review';
  $context=glasses_v11_evidence_context($session,$model,$input);

  if(!empty($input['devicePublicId'])&&!hash_equals((string)$device['public_id'],(string)$input['devicePublicId']))throw new InvalidArgumentException('Evidence device identity cannot be overridden.');
  if(!empty($input['buildSessionPublicId'])){
    $build=glasses_vision_training_media_resolve_build($pdo,$org,(string)$input['buildSessionPublicId']);
    if((int)$build['device_id']!==(int)$device['id'])throw new InvalidArgumentException('Evidence build session belongs to another device.');
  }

  $mediaInput=$input;
  $mediaInput['trainingEligibility']='review';
  $mediaInput['devicePublicId']=$device['public_id'];
  $mediaInput['capturedAt']=$capturedSql;
  $mediaInput['consentBasis']='training_media_opt_in';
  $mediaInput['metadata']=is_array($input['metadata']??null)?$input['metadata']:[];
  $mediaInput['metadata']['v11ProductionEvidence']=$context;

  return glasses_transaction($pdo,function()use($pdo,$org,$mediaInput,$actor,$session,$model,$context):array{
    $media=glasses_vision_training_media_store($pdo,$org,$mediaInput,$actor);
    $q=$pdo->prepare("SELECT id,sample_id FROM glasses_vision_training_media WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
    $q->execute([$org,$media['publicId']]);$row=$q->fetch();if(!$row)throw new RuntimeException('Governed training media was not persisted.');

    $assignmentId=(int)$session['assignment_id'];
    $assignment=glasses_training_assignment($pdo,$org,(string)$session['assignment_public_id']);
    $programId=(int)$assignment['program_id'];

    $pdo->prepare("UPDATE glasses_vision_training_media SET
      operator_user_id=?,training_session_id=?,training_assignment_id=?,training_program_id=?,model_package_id=?,
      recipe_step_key=?,validation_outcome=?,training_eligibility=?,training_exclusion_reason=?,privacy_scope=?
      WHERE organization_id=? AND id=?")->execute([
        (int)$session['user_id'],(int)$session['id'],$assignmentId,$programId,$model?(int)$model['id']:null,
        $context['recipeStepKey'],$context['validationOutcome'],$context['trainingEligibility'],$context['trainingExclusionReason'],$context['privacyScope'],
        $org,(int)$row['id']
      ]);
    if($row['sample_id']!==null)$pdo->prepare("UPDATE glasses_vision_training_samples SET operator_user_id=? WHERE organization_id=? AND id=?")->execute([(int)$session['user_id'],$org,(int)$row['sample_id']]);
    glasses_vision_training_media_event($pdo,$org,(int)$row['id'],'v11_production_context_bound',$actor,$context);

    return glasses_v11_production_evidence_get($pdo,$org,$media['publicId']);
  });
}

function glasses_v11_production_evidence_get(PDO $pdo,int $org,string $public): array {
  $q=$pdo->prepare("SELECT m.*,ts.public_id training_session_public_id,ta.public_id training_assignment_public_id,tp.public_id training_program_public_id,
    mp.public_id model_package_public_id,mp.artifact_sha256 model_artifact_sha256,u.display_name operator_name,d.public_id device_public_id,
    ks.public_id station_public_id,b.public_id build_public_id,s.public_id sample_public_id
    FROM glasses_vision_training_media m
    LEFT JOIN glasses_training_sessions ts ON ts.id=m.training_session_id
    LEFT JOIN glasses_training_assignments ta ON ta.id=m.training_assignment_id
    LEFT JOIN glasses_training_programs tp ON tp.id=m.training_program_id
    LEFT JOIN glasses_vision_model_packages mp ON mp.id=m.model_package_id
    LEFT JOIN users u ON u.id=m.operator_user_id
    LEFT JOIN glasses_devices d ON d.id=m.device_id
    LEFT JOIN kds_stations ks ON ks.id=ts.station_id
    LEFT JOIN glasses_build_sessions b ON b.id=m.build_session_id
    LEFT JOIN glasses_vision_training_samples s ON s.id=m.sample_id
    WHERE m.organization_id=? AND m.public_id=? LIMIT 1");
  $q->execute([$org,$public]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Production evidence was not found.');
  return [
    'schema'=>GLASSES_V11_PRODUCTION_EVIDENCE_SCHEMA,'publicId'=>$r['public_id'],'samplePublicId'=>$r['sample_public_id'],
    'trainingSessionPublicId'=>$r['training_session_public_id'],'trainingAssignmentPublicId'=>$r['training_assignment_public_id'],
    'programPublicId'=>$r['training_program_public_id'],'operatorUserId'=>$r['operator_user_id']!==null?(int)$r['operator_user_id']:null,
    'operatorName'=>$r['operator_name'],'devicePublicId'=>$r['device_public_id'],'stationPublicId'=>$r['station_public_id'],
    'buildSessionPublicId'=>$r['build_public_id'],'modelPackagePublicId'=>$r['model_package_public_id'],'modelArtifactSha256'=>$r['model_artifact_sha256'],
    'recipeStepKey'=>$r['recipe_step_key'],'validationOutcome'=>$r['validation_outcome'],'trainingEligibility'=>$r['training_eligibility'],
    'trainingExclusionReason'=>$r['training_exclusion_reason'],'privacyScope'=>$r['privacy_scope'],'retentionUntil'=>$r['retention_until'],
    'qualityState'=>$r['quality_state'],'status'=>$r['status'],'capturedAt'=>$r['created_at']
  ];
}

function glasses_v11_production_evidence_set_eligibility(PDO $pdo,int $org,string $public,string $eligibility,string $reason,int $actor): array {
  if(!in_array($eligibility,['review','eligible','excluded'],true))throw new InvalidArgumentException('Training eligibility is invalid.');
  $reason=mb_substr(trim($reason),0,500,'UTF-8');
  if($eligibility==='excluded'&&$reason==='')throw new InvalidArgumentException('Excluding production evidence requires a reason.');
  return glasses_transaction($pdo,function()use($pdo,$org,$public,$eligibility,$reason,$actor):array{
    $q=$pdo->prepare("SELECT id,training_eligibility FROM glasses_vision_training_media WHERE organization_id=? AND public_id=? AND status='active' LIMIT 1 FOR UPDATE");
    $q->execute([$org,$public]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Production evidence was not found.');
    $pdo->prepare("UPDATE glasses_vision_training_media SET training_eligibility=?,training_exclusion_reason=? WHERE organization_id=? AND id=?")
      ->execute([$eligibility,$eligibility==='excluded'?$reason:null,$org,(int)$r['id']]);
    glasses_vision_training_media_event($pdo,$org,(int)$r['id'],'v11_training_eligibility_changed',$actor,['previous'=>$r['training_eligibility'],'next'=>$eligibility,'reason'=>$reason?:null]);
    return glasses_v11_production_evidence_get($pdo,$org,$public);
  });
}

function glasses_v11_production_evidence_catalog(PDO $pdo,int $org,int $limit=100): array {
  if(!glasses_v11_evidence_ready($pdo))return ['evidence'=>[]];
  $limit=max(1,min(500,$limit));
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_media WHERE organization_id=? AND training_session_id IS NOT NULL AND status='active' ORDER BY id DESC LIMIT ".$limit);
  $q->execute([$org]);
  return ['evidence'=>array_map(fn($id)=>glasses_v11_production_evidence_get($pdo,$org,(string)$id),$q->fetchAll(PDO::FETCH_COLUMN))];
}
