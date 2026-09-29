<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-v11-model-evaluation.php';
require_once __DIR__.'/glasses-vision-models.php';

const GLASSES_V11_MODEL_RC_SCHEMA='gelato.vision_v11_model_release_candidate.v1';

function glasses_v11_model_rc_ready(PDO $pdo): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_model_release_candidates'");
  $q->execute();
  return (int)$q->fetchColumn()===1&&glasses_v11_model_evaluation_ready($pdo)&&glasses_vision_models_ready($pdo);
}

function glasses_v11_model_rc_row(PDO $pdo,int $org,string $publicId): array {
  $q=$pdo->prepare("SELECT rc.*,x.public_id experiment_public_id,x.experiment_hash,tr.public_id training_run_public_id,tr.run_hash,
    b.public_id benchmark_public_id,b.benchmark_hash,mp.public_id model_public_id,mp.detector_name,mp.model_name,mp.model_version,
    mp.runtime_type,mp.platform,mp.artifact_url,mp.artifact_sha256,mp.artifact_bytes,mp.minimum_sdk_version,mp.minimum_app_version,mp.status model_status,mp.metadata_json
    FROM glasses_vision_model_release_candidates rc
    JOIN glasses_vision_model_experiments x ON x.id=rc.experiment_id AND x.organization_id=rc.organization_id
    JOIN glasses_vision_training_runs tr ON tr.id=rc.training_run_id AND tr.organization_id=rc.organization_id
    JOIN glasses_vision_model_benchmarks b ON b.id=rc.benchmark_id AND b.organization_id=rc.organization_id
    JOIN glasses_vision_model_packages mp ON mp.id=rc.model_package_id AND mp.organization_id=rc.organization_id
    WHERE rc.organization_id=? AND rc.public_id=? LIMIT 1");
  $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('V11 model release candidate was not found.');
  return $r;
}

function glasses_v11_model_rc_public(array $r): array {
  return [
    'schema'=>GLASSES_V11_MODEL_RC_SCHEMA,'publicId'=>$r['public_id'],'status'=>$r['status'],'rcHash'=>$r['rc_hash'],'releaseNotes'=>$r['release_notes'],
    'experiment'=>['publicId'=>$r['experiment_public_id'],'experimentHash'=>$r['experiment_hash']],
    'trainingRun'=>['publicId'=>$r['training_run_public_id'],'runHash'=>$r['run_hash']],
    'benchmark'=>['publicId'=>$r['benchmark_public_id'],'benchmarkHash'=>$r['benchmark_hash']],
    'modelPackage'=>['publicId'=>$r['model_public_id'],'detectorName'=>$r['detector_name'],'modelName'=>$r['model_name'],'modelVersion'=>$r['model_version'],
      'runtimeType'=>$r['runtime_type'],'platform'=>$r['platform'],'artifactUrl'=>$r['artifact_url'],'artifactSha256'=>$r['artifact_sha256'],
      'artifactBytes'=>$r['artifact_bytes']!==null?(int)$r['artifact_bytes']:null,'minimumSdkVersion'=>$r['minimum_sdk_version'],'minimumAppVersion'=>$r['minimum_app_version'],'status'=>$r['model_status']],
    'runtimeCompatibility'=>json_decode((string)$r['runtime_compat_json'],true)?:[],
    'manifest'=>json_decode((string)$r['manifest_json'],true)?:[],
    'approvedAt'=>$r['approved_at'],'rejectedAt'=>$r['rejected_at'],'rejectionReason'=>$r['rejection_reason'],'createdAt'=>$r['created_at'],
  ];
}

function glasses_v11_model_rc_create(PDO $pdo,int $org,array $input,int $actor): array {
  if(!glasses_v11_model_rc_ready($pdo))throw new RuntimeException('V11 model release-candidate migration is not installed.');
  $benchmarkPublic=trim((string)($input['benchmarkPublicId']??''));if($benchmarkPublic==='')throw new InvalidArgumentException('Passing benchmark public ID is required.');
  $bq=$pdo->prepare("SELECT b.id benchmark_id,b.status benchmark_status,b.benchmark_hash,
    x.id experiment_id,x.public_id experiment_public_id,x.experiment_hash,x.training_run_id,x.challenger_package_id,
    tr.public_id training_run_public_id,tr.run_hash,tr.dataset_hash_snapshot,tr.assembly_hash_snapshot,tr.release_hash_snapshot,tr.qualification_hash_snapshot,tr.config_hash,tr.output_sha256,
    mp.id model_package_id,mp.public_id model_public_id,mp.detector_name,mp.model_name,mp.model_version,mp.runtime_type,mp.platform,mp.artifact_url,mp.artifact_sha256,mp.artifact_bytes,
    mp.minimum_sdk_version,mp.minimum_app_version,mp.status model_status,mp.metadata_json
    FROM glasses_vision_model_benchmarks b
    JOIN glasses_vision_model_experiments x ON x.id=b.experiment_id AND x.organization_id=b.organization_id
    JOIN glasses_vision_training_runs tr ON tr.id=b.training_run_id AND tr.organization_id=b.organization_id
    JOIN glasses_vision_model_packages mp ON mp.id=b.challenger_package_id AND mp.organization_id=b.organization_id
    WHERE b.organization_id=? AND b.public_id=? LIMIT 1");
  $bq->execute([$org,$benchmarkPublic]);$r=$bq->fetch();if(!$r)throw new InvalidArgumentException('Model benchmark was not found.');
  if((string)$r['benchmark_status']!=='passed')throw new InvalidArgumentException('Release candidate requires a passing benchmark.');
  if(empty($r['run_hash'])||empty($r['output_sha256']))throw new InvalidArgumentException('Release candidate requires a completed V11 training run.');
  if(!hash_equals((string)$r['output_sha256'],(string)$r['artifact_sha256']))throw new InvalidArgumentException('Release candidate model artifact does not match the training output.');
  if((string)$r['model_status']!=='ready')throw new InvalidArgumentException('Release candidate model package must be ready.');
  if((string)$r['artifact_sha256']===''||(string)$r['runtime_type']===''||(string)$r['platform']==='')throw new InvalidArgumentException('Release candidate model package is incomplete.');
  $notes=mb_substr(trim((string)($input['releaseNotes']??'')),0,4000,'UTF-8');if($notes==='')throw new InvalidArgumentException('Release notes are required.');

  $metadata=json_decode((string)($r['metadata_json']??'null'),true)?:[];
  $labels=array_values(array_map('strval',(array)($metadata['browserInference']['labels']??[])));
  if(!$labels){
    $bench=glasses_v11_model_evaluation_row($pdo,$org,$benchmarkPublic);
    $labels=array_keys((array)$bench['confusion']);
  }
  sort($labels,SORT_STRING);
  $runtimeCompat=[
    'runtimeType'=>$r['runtime_type'],'platform'=>$r['platform'],'minimumSdkVersion'=>$r['minimum_sdk_version'],'minimumAppVersion'=>$r['minimum_app_version'],
    'browserInference'=>$metadata['browserInference']??null,
  ];
  $manifest=[
    'schema'=>GLASSES_V11_MODEL_RC_SCHEMA,
    'experiment'=>['publicId'=>$r['experiment_public_id'],'experimentHash'=>$r['experiment_hash']],
    'trainingRun'=>['publicId'=>$r['training_run_public_id'],'runHash'=>$r['run_hash'],'configHash'=>$r['config_hash']],
    'dataset'=>['datasetHash'=>$r['dataset_hash_snapshot'],'assemblyHash'=>$r['assembly_hash_snapshot']],
    'trainingRelease'=>['releaseHash'=>$r['release_hash_snapshot'],'qualificationHash'=>$r['qualification_hash_snapshot']],
    'benchmark'=>['publicId'=>$benchmarkPublic,'benchmarkHash'=>$r['benchmark_hash']],
    'modelPackage'=>['publicId'=>$r['model_public_id'],'detectorName'=>$r['detector_name'],'modelName'=>$r['model_name'],'modelVersion'=>$r['model_version'],
      'runtimeType'=>$r['runtime_type'],'platform'=>$r['platform'],'artifactUrl'=>$r['artifact_url'],'artifactSha256'=>$r['artifact_sha256'],'artifactBytes'=>$r['artifact_bytes']],
    'runtimeCompatibility'=>$runtimeCompat,'labels'=>$labels,'releaseNotes'=>$notes,
    'governance'=>['productionReady'=>false,'requiresApproval'=>true,'requiresPromotionReview'=>true,'automaticActivation'=>false],
  ];
  $hash=hash('sha256',glasses_vision_training_release_json($manifest));
  $existing=$pdo->prepare("SELECT public_id FROM glasses_vision_model_release_candidates WHERE organization_id=? AND rc_hash=? LIMIT 1");$existing->execute([$org,$hash]);$ep=$existing->fetchColumn();
  if($ep!==false)return glasses_v11_model_rc_public(glasses_v11_model_rc_row($pdo,$org,(string)$ep));

  $public=glasses_public_id('vision-rc');
  $pdo->prepare("INSERT INTO glasses_vision_model_release_candidates
    (organization_id,public_id,experiment_id,training_run_id,benchmark_id,model_package_id,status,release_notes,runtime_compat_json,manifest_json,rc_hash,created_by)
    VALUES (?,?,?,?,?,?,'pending_approval',?,?,?,?,?)")
    ->execute([$org,$public,(int)$r['experiment_id'],(int)$r['training_run_id'],(int)$r['benchmark_id'],(int)$r['model_package_id'],$notes,
      glasses_vision_training_release_json($runtimeCompat),glasses_vision_training_release_json($manifest),$hash,$actor]);
  glasses_vision_lineage_edge($pdo,$org,'model_benchmark',$benchmarkPublic,(string)$r['benchmark_hash'],'packaged_as','model_release_candidate',$public,$hash,['modelPackagePublicId'=>$r['model_public_id']],$actor);
  return glasses_v11_model_rc_public(glasses_v11_model_rc_row($pdo,$org,$public));
}

function glasses_v11_model_rc_verify(PDO $pdo,int $org,string $publicId): array {
  $r=glasses_v11_model_rc_row($pdo,$org,$publicId);$manifest=json_decode((string)$r['manifest_json'],true)?:[];
  $checks=[
    ['key'=>'rc_hash','passed'=>hash_equals((string)$r['rc_hash'],hash('sha256',glasses_vision_training_release_json($manifest)))],
    ['key'=>'experiment_hash','passed'=>(string)($manifest['experiment']['experimentHash']??'')===(string)$r['experiment_hash']],
    ['key'=>'training_run_hash','passed'=>(string)($manifest['trainingRun']['runHash']??'')===(string)$r['run_hash']],
    ['key'=>'benchmark_hash','passed'=>(string)($manifest['benchmark']['benchmarkHash']??'')===(string)$r['benchmark_hash']],
    ['key'=>'artifact_hash','passed'=>(string)($manifest['modelPackage']['artifactSha256']??'')===(string)$r['artifact_sha256']],
  ];
  return ['passed'=>count(array_filter($checks,static fn($c)=>$c['passed']))===count($checks),'checks'=>$checks,'releaseCandidate'=>glasses_v11_model_rc_public($r)];
}

function glasses_v11_model_rc_approve(PDO $pdo,int $org,string $publicId,int $actor): array {
  return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$actor):array{
    $r=glasses_v11_model_rc_row($pdo,$org,$publicId);
    if((string)$r['status']==='approved')return glasses_v11_model_rc_public($r);
    if((string)$r['status']!=='pending_approval')throw new InvalidArgumentException('Only a pending release candidate may be approved.');
    $verification=glasses_v11_model_rc_verify($pdo,$org,$publicId);if(!$verification['passed'])throw new InvalidArgumentException('Release candidate integrity verification failed.');
    $b=glasses_v11_model_evaluation_row($pdo,$org,(string)$r['benchmark_public_id']);if((string)$b['status']!=='passed')throw new InvalidArgumentException('Release candidate benchmark is no longer passing.');
    if((string)$r['model_status']!=='ready')throw new InvalidArgumentException('Release candidate model package must remain ready.');
    $pdo->prepare("UPDATE glasses_vision_model_release_candidates SET status='approved',approved_by=?,approved_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$actor,$org,(int)$r['id']]);
    glasses_vision_lineage_edge($pdo,$org,'model_release_candidate',$publicId,(string)$r['rc_hash'],'approved_for_promotion','model_package',(string)$r['model_public_id'],(string)$r['artifact_sha256'],['automaticActivation'=>false],$actor);
    return glasses_v11_model_rc_public(glasses_v11_model_rc_row($pdo,$org,$publicId));
  });
}

function glasses_v11_model_rc_reject(PDO $pdo,int $org,string $publicId,string $reason,int $actor): array {
  $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('Rejection reason is required.');
  $r=glasses_v11_model_rc_row($pdo,$org,$publicId);if((string)$r['status']!=='pending_approval')throw new InvalidArgumentException('Only a pending release candidate may be rejected.');
  $pdo->prepare("UPDATE glasses_vision_model_release_candidates SET status='rejected',rejected_by=?,rejected_at=NOW(6),rejection_reason=? WHERE organization_id=? AND id=?")
    ->execute([$actor,$reason,$org,(int)$r['id']]);
  return glasses_v11_model_rc_public(glasses_v11_model_rc_row($pdo,$org,$publicId));
}

function glasses_v11_model_rc_latest_approved(PDO $pdo,int $org,int $experimentId): ?array {
  if(!glasses_v11_model_rc_ready($pdo))return null;
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_release_candidates WHERE organization_id=? AND experiment_id=? AND status='approved' ORDER BY id DESC LIMIT 1");
  $q->execute([$org,$experimentId]);$p=$q->fetchColumn();return $p!==false?glasses_v11_model_rc_public(glasses_v11_model_rc_row($pdo,$org,(string)$p)):null;
}

function glasses_v11_model_rc_catalog(PDO $pdo,int $org): array {
  if(!glasses_v11_model_rc_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_V11_MODEL_RC_SCHEMA,'releaseCandidates'=>[]];
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_release_candidates WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
  return ['ready'=>true,'schema'=>GLASSES_V11_MODEL_RC_SCHEMA,'releaseCandidates'=>array_map(fn($p)=>glasses_v11_model_rc_public(glasses_v11_model_rc_row($pdo,$org,(string)$p)),$q->fetchAll(PDO::FETCH_COLUMN))];
}
