<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-experiments.php';
require_once __DIR__.'/glasses-v11-dataset-assembly.php';

const GLASSES_V11_TRAINING_RUN_SCHEMA='gelato.vision_v11_training_run.v1';

function glasses_v11_training_run_ready(PDO $pdo): bool {
  foreach(['experiment_id','parent_run_id','attempt_no','dataset_hash_snapshot','assembly_hash_snapshot','release_hash_snapshot','qualification_hash_snapshot','config_hash','run_hash','artifacts_json','metrics_hash','failure_json'] as $column){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='glasses_vision_training_runs' AND column_name=?");
    $q->execute([$column]);if((int)$q->fetchColumn()!==1)return false;
  }
  return glasses_vision_experiment_ready($pdo)&&glasses_v11_dataset_assembly_ready($pdo);
}

function glasses_v11_training_run_sha(string $value,string $label): string {
  $value=strtolower(trim($value));
  if(!preg_match('/^[a-f0-9]{64}$/',$value))throw new InvalidArgumentException($label.' must be a SHA-256 hex digest.');
  return $value;
}

function glasses_v11_training_run_config(array $config): array {
  $normalized=glasses_vision_experiment_config($config);
  foreach(['epochs','batchSize','imageSize','seed'] as $key)if(!array_key_exists($key,$normalized))throw new InvalidArgumentException('Training configuration is incomplete.');
  return glasses_vision_training_release_canonicalize($normalized);
}

function glasses_v11_training_run_artifacts(array $items): array {
  $out=[];
  foreach($items as $item){
    if(!is_array($item))throw new InvalidArgumentException('Training artifact entry is invalid.');
    $name=mb_substr(trim((string)($item['name']??'')),0,190,'UTF-8');
    if($name==='')throw new InvalidArgumentException('Training artifact name is required.');
    $sha=glasses_v11_training_run_sha((string)($item['sha256']??''),'Training artifact SHA-256');
    $kind=mb_substr(trim((string)($item['kind']??'model')),0,40,'UTF-8')?:'model';
    $bytes=max(0,(int)($item['bytes']??0));
    $out[]=['name'=>$name,'kind'=>$kind,'sha256'=>$sha,'bytes'=>$bytes];
  }
  usort($out,static fn($a,$b)=>strcmp($a['name'],$b['name'])?:strcmp($a['sha256'],$b['sha256']));
  return $out;
}

function glasses_v11_training_run_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array {
  $q=$pdo->prepare("SELECT tr.*,
    x.public_id experiment_public_id,x.experiment_hash,x.status experiment_status,x.training_config_json experiment_config_json,
    r.public_id release_public_id,r.release_hash,r.status release_status,
    qf.public_id qualification_public_id,qf.qualification_hash,
    d.public_id dataset_public_id,d.dataset_hash,d.assembly_hash,d.status dataset_status,
    p.public_id parent_public_id
    FROM glasses_vision_training_runs tr
    JOIN glasses_vision_model_experiments x ON x.id=tr.experiment_id AND x.organization_id=tr.organization_id
    JOIN glasses_vision_training_releases r ON r.id=tr.training_release_id AND r.organization_id=tr.organization_id
    JOIN glasses_vision_training_qualifications qf ON qf.id=tr.qualification_id AND qf.organization_id=tr.organization_id
    JOIN glasses_vision_dataset_versions d ON d.id=r.dataset_id AND d.organization_id=tr.organization_id
    LEFT JOIN glasses_vision_training_runs p ON p.id=tr.parent_run_id AND p.organization_id=tr.organization_id
    WHERE tr.organization_id=? AND tr.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
  $q->execute([$org,trim($publicId)]);$r=$q->fetch();
  if(!$r)throw new InvalidArgumentException('V11 training run was not found.');
  return $r;
}

function glasses_v11_training_run_public(array $r): array {
  return [
    'schema'=>GLASSES_V11_TRAINING_RUN_SCHEMA,'publicId'=>$r['public_id'],'status'=>$r['status'],'runKey'=>$r['run_key'],
    'runHash'=>$r['run_hash'],'attemptNo'=>(int)$r['attempt_no'],'parentRunPublicId'=>$r['parent_public_id'],
    'trainer'=>$r['trainer'],'trainerVersion'=>$r['trainer_version'],
    'experiment'=>['publicId'=>$r['experiment_public_id'],'experimentHash'=>$r['experiment_hash'],'status'=>$r['experiment_status']],
    'dataset'=>['publicId'=>$r['dataset_public_id'],'datasetHash'=>$r['dataset_hash_snapshot'],'assemblyHash'=>$r['assembly_hash_snapshot']],
    'release'=>['publicId'=>$r['release_public_id'],'releaseHash'=>$r['release_hash_snapshot']],
    'qualification'=>['publicId'=>$r['qualification_public_id'],'qualificationHash'=>$r['qualification_hash_snapshot']],
    'config'=>json_decode((string)$r['config_json'],true)?:[],'configHash'=>$r['config_hash'],
    'metrics'=>json_decode((string)($r['metrics_json']??'null'),true),'metricsHash'=>$r['metrics_hash'],
    'artifacts'=>json_decode((string)($r['artifacts_json']??'[]'),true)?:[],'outputSha256'=>$r['output_sha256'],
    'failure'=>json_decode((string)($r['failure_json']??'null'),true),
    'startedAt'=>$r['started_at'],'completedAt'=>$r['completed_at'],'createdAt'=>$r['created_at'],
  ];
}

function glasses_v11_training_run_contract(PDO $pdo,int $org,string $experimentPublicId,array $input=[]): array {
  $x=glasses_vision_experiment_row($pdo,$org,$experimentPublicId,false);
  if(!in_array((string)$x['status'],['ready','running'],true))throw new InvalidArgumentException('Training runs require a ready or running model experiment.');
  if((string)$x['dataset_status']!=='frozen'||empty($x['dataset_hash']))throw new InvalidArgumentException('Training run requires a frozen immutable dataset.');
  $dq=$pdo->prepare("SELECT assembly_hash FROM glasses_vision_dataset_versions WHERE organization_id=? AND id=? LIMIT 1");
  $dq->execute([$org,(int)$x['candidate_dataset_id']]);$assemblyHash=$dq->fetchColumn();
  if(!is_string($assemblyHash)||strlen($assemblyHash)!==64)throw new InvalidArgumentException('V11 training run requires a V11 assembled dataset hash.');
  if((string)$x['release_status']!=='qualified')throw new InvalidArgumentException('Training run requires a qualified training release.');
  $config=glasses_v11_training_run_config(json_decode((string)$x['training_config_json'],true)?:[]);
  if(isset($input['config'])){
    if(!is_array($input['config']))throw new InvalidArgumentException('Training configuration override must be an object.');
    $requested=glasses_v11_training_run_config($input['config']);
    if(glasses_vision_training_release_json($requested)!==glasses_vision_training_release_json($config))throw new InvalidArgumentException('Training run configuration must exactly match the experiment contract.');
  }
  $trainer=mb_substr(trim((string)($input['trainer']??'gelato-yolo')),0,120,'UTF-8')?:'gelato-yolo';
  $trainerVersion=mb_substr(trim((string)($input['trainerVersion']??'v11')),0,80,'UTF-8')?:'v11';
  return ['experiment'=>$x,'config'=>$config,'configHash'=>hash('sha256',glasses_vision_training_release_json($config)),
    'datasetHash'=>(string)$x['dataset_hash'],'assemblyHash'=>$assemblyHash,'releaseHash'=>(string)$x['release_hash'],
    'qualificationHash'=>(string)$x['qualification_hash'],'trainer'=>$trainer,'trainerVersion'=>$trainerVersion];
}

function glasses_v11_training_run_create(PDO $pdo,int $org,array $input,int $actor): array {
  if(!glasses_v11_training_run_ready($pdo))throw new RuntimeException('V11 training-run orchestration migration is not installed.');
  $experimentPublic=trim((string)($input['experimentPublicId']??''));if($experimentPublic==='')throw new InvalidArgumentException('Experiment public ID is required.');
  $contract=glasses_v11_training_run_contract($pdo,$org,$experimentPublic,$input);
  $runKey=mb_substr(trim((string)($input['runKey']??'')),0,190,'UTF-8');
  if($runKey==='')$runKey='v11-'.$experimentPublic.'-'.substr(hash('sha256',glasses_vision_training_release_json([$contract['configHash'],$contract['releaseHash'],$contract['qualificationHash']])),0,16);
  $identity=['schema'=>GLASSES_V11_TRAINING_RUN_SCHEMA,'experimentHash'=>$contract['experiment']['experiment_hash'],
    'datasetHash'=>$contract['datasetHash'],'assemblyHash'=>$contract['assemblyHash'],'releaseHash'=>$contract['releaseHash'],
    'qualificationHash'=>$contract['qualificationHash'],'configHash'=>$contract['configHash'],'trainer'=>$contract['trainer'],
    'trainerVersion'=>$contract['trainerVersion'],'runKey'=>$runKey,'attemptNo'=>1,'parentRunPublicId'=>null];
  $runHash=hash('sha256',glasses_vision_training_release_json($identity));
  $existing=$pdo->prepare("SELECT public_id FROM glasses_vision_training_runs WHERE organization_id=? AND run_hash=? LIMIT 1");
  $existing->execute([$org,$runHash]);$ep=$existing->fetchColumn();
  if($ep!==false)return glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,(string)$ep));

  return glasses_transaction($pdo,function()use($pdo,$org,$actor,$contract,$runKey,$runHash):array{
    $public=glasses_public_id('vision-train-run');
    $x=$contract['experiment'];
    $pdo->prepare("INSERT INTO glasses_vision_training_runs
      (organization_id,public_id,training_release_id,qualification_id,experiment_id,parent_run_id,attempt_no,dataset_hash_snapshot,assembly_hash_snapshot,release_hash_snapshot,qualification_hash_snapshot,config_hash,run_hash,run_key,trainer,trainer_version,status,config_json,requested_by,created_by)
      VALUES (?,?,?,?,?,NULL,1,?,?,?,?,?,?,?,?,?,'planned',?,?,?)")
      ->execute([$org,$public,(int)$x['training_release_id'],(int)$x['qualification_id'],(int)$x['id'],$contract['datasetHash'],$contract['assemblyHash'],$contract['releaseHash'],$contract['qualificationHash'],$contract['configHash'],$runHash,$runKey,$contract['trainer'],$contract['trainerVersion'],json_encode($contract['config'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor,$actor]);
    glasses_vision_lineage_edge($pdo,$org,'model_experiment',(string)$x['public_id'],(string)$x['experiment_hash'],'scheduled_training_run','training_run',$public,$runHash,[
      'datasetHash'=>$contract['datasetHash'],'assemblyHash'=>$contract['assemblyHash'],'releaseHash'=>$contract['releaseHash'],'qualificationHash'=>$contract['qualificationHash'],'configHash'=>$contract['configHash']
    ],$actor);
    return glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,$public));
  });
}

function glasses_v11_training_run_start(PDO $pdo,int $org,string $publicId,int $actor): array {
  return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$actor):array{
    $r=glasses_v11_training_run_row($pdo,$org,$publicId,true);
    if((string)$r['status']==='running')return glasses_v11_training_run_public($r);
    if((string)$r['status']!=='planned')throw new InvalidArgumentException('Only a planned training run may start.');
    if((string)$r['experiment_status']==='ready')glasses_vision_experiment_start($pdo,$org,(string)$r['experiment_public_id'],$actor);
    $pdo->prepare("UPDATE glasses_vision_training_runs SET status='running',started_by=?,started_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$actor,$org,(int)$r['id']]);
    return glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,$publicId));
  });
}

function glasses_v11_training_run_fail(PDO $pdo,int $org,string $publicId,array $failure,int $actor): array {
  $code=mb_substr(trim((string)($failure['code']??'')),0,80,'UTF-8');$message=mb_substr(trim((string)($failure['message']??'')),0,1000,'UTF-8');
  if($code===''||$message==='')throw new InvalidArgumentException('Failure code and message are required.');
  $payload=glasses_vision_training_release_canonicalize(['code'=>$code,'message'=>$message,'retryable'=>(bool)($failure['retryable']??true)]);
  return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$payload,$actor):array{
    $r=glasses_v11_training_run_row($pdo,$org,$publicId,true);if((string)$r['status']!=='running')throw new InvalidArgumentException('Only a running training run may fail.');
    $pdo->prepare("UPDATE glasses_vision_training_runs SET status='failed',failure_json=?,completed_by=?,completed_at=NOW(6) WHERE organization_id=? AND id=?")
      ->execute([json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor,$org,(int)$r['id']]);
    return glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,$publicId));
  });
}

function glasses_v11_training_run_cancel(PDO $pdo,int $org,string $publicId,string $reason,int $actor): array {
  $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($reason==='')throw new InvalidArgumentException('Cancellation reason is required.');
  return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$reason,$actor):array{
    $r=glasses_v11_training_run_row($pdo,$org,$publicId,true);if(!in_array((string)$r['status'],['planned','running'],true))throw new InvalidArgumentException('Only a planned or running training run may be cancelled.');
    $failure=['code'=>'cancelled','message'=>$reason,'retryable'=>true];
    $pdo->prepare("UPDATE glasses_vision_training_runs SET status='cancelled',failure_json=?,completed_by=?,completed_at=NOW(6) WHERE organization_id=? AND id=?")
      ->execute([json_encode($failure,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor,$org,(int)$r['id']]);
    return glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,$publicId));
  });
}

function glasses_v11_training_run_complete(PDO $pdo,int $org,string $publicId,array $input,int $actor): array {
  $output=glasses_v11_training_run_sha((string)($input['outputSha256']??''),'Training output SHA-256');
  $metrics=is_array($input['metrics']??null)?glasses_vision_training_release_canonicalize($input['metrics']):[];
  $artifacts=glasses_v11_training_run_artifacts(is_array($input['artifacts']??null)?$input['artifacts']:[['name'=>'model','kind'=>'model','sha256'=>$output,'bytes'=>(int)($input['outputBytes']??0)]]);
  $found=false;foreach($artifacts as $a)if(hash_equals($output,$a['sha256'])){$found=true;break;}
  if(!$found)throw new InvalidArgumentException('Artifact manifest must contain the declared training output SHA-256.');
  $metricsHash=hash('sha256',glasses_vision_training_release_json($metrics));
  return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$output,$metrics,$artifacts,$metricsHash,$actor):array{
    $r=glasses_v11_training_run_row($pdo,$org,$publicId,true);
    if((string)$r['status']==='completed'){
      $storedMetrics=json_decode((string)($r['metrics_json']??'{}'),true)?:[];$storedArtifacts=json_decode((string)($r['artifacts_json']??'[]'),true)?:[];
      if(!hash_equals((string)$r['output_sha256'],$output)||glasses_vision_training_release_json($storedMetrics)!==glasses_vision_training_release_json($metrics)||glasses_vision_training_release_json($storedArtifacts)!==glasses_vision_training_release_json($artifacts))throw new InvalidArgumentException('Completed training run is immutable and cannot accept different results.');
      return glasses_v11_training_run_public($r);
    }
    if((string)$r['status']!=='running')throw new InvalidArgumentException('Only a running training run may complete.');
    $pdo->prepare("UPDATE glasses_vision_training_runs SET status='completed',metrics_json=?,artifacts_json=?,metrics_hash=?,output_sha256=?,failure_json=NULL,completed_by=?,completed_at=NOW(6) WHERE organization_id=? AND id=?")
      ->execute([json_encode($metrics,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($artifacts,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$metricsHash,$output,$actor,$org,(int)$r['id']]);
    glasses_vision_lineage_edge($pdo,$org,'training_release',(string)$r['release_public_id'],(string)$r['release_hash_snapshot'],'trained_as','training_run',$publicId,$output,[
      'experimentPublicId'=>$r['experiment_public_id'],'runHash'=>$r['run_hash'],'configHash'=>$r['config_hash'],'metricsHash'=>$metricsHash,'artifacts'=>$artifacts
    ],$actor);
    $updated=glasses_v11_training_run_row($pdo,$org,$publicId);
    glasses_vision_experiment_attach_run($pdo,$org,(string)$updated['experiment_public_id'],$publicId,$actor);
    return glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,$publicId));
  });
}

function glasses_v11_training_run_rerun(PDO $pdo,int $org,string $publicId,array $input,int $actor): array {
  $parent=glasses_v11_training_run_row($pdo,$org,$publicId,false);
  if(!in_array((string)$parent['status'],['failed','cancelled'],true))throw new InvalidArgumentException('Only failed or cancelled runs may be retried.');
  $runKey=mb_substr(trim((string)($input['runKey']??($parent['run_key'].'-retry-'.((int)$parent['attempt_no']+1)))),0,190,'UTF-8');
  $attempt=(int)$parent['attempt_no']+1;
  $identity=['schema'=>GLASSES_V11_TRAINING_RUN_SCHEMA,'experimentHash'=>$parent['experiment_hash'],'datasetHash'=>$parent['dataset_hash_snapshot'],
    'assemblyHash'=>$parent['assembly_hash_snapshot'],'releaseHash'=>$parent['release_hash_snapshot'],'qualificationHash'=>$parent['qualification_hash_snapshot'],
    'configHash'=>$parent['config_hash'],'trainer'=>$parent['trainer'],'trainerVersion'=>$parent['trainer_version'],'runKey'=>$runKey,'attemptNo'=>$attempt,'parentRunPublicId'=>$parent['public_id']];
  $runHash=hash('sha256',glasses_vision_training_release_json($identity));
  $existing=$pdo->prepare("SELECT public_id FROM glasses_vision_training_runs WHERE organization_id=? AND run_hash=? LIMIT 1");$existing->execute([$org,$runHash]);$ep=$existing->fetchColumn();
  if($ep!==false)return glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,(string)$ep));
  return glasses_transaction($pdo,function()use($pdo,$org,$parent,$actor,$runKey,$attempt,$runHash):array{
    $public=glasses_public_id('vision-train-run');
    $pdo->prepare("INSERT INTO glasses_vision_training_runs
      (organization_id,public_id,training_release_id,qualification_id,experiment_id,parent_run_id,attempt_no,dataset_hash_snapshot,assembly_hash_snapshot,release_hash_snapshot,qualification_hash_snapshot,config_hash,run_hash,run_key,trainer,trainer_version,status,config_json,requested_by,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'planned',?,?,?)")
      ->execute([$org,$public,(int)$parent['training_release_id'],(int)$parent['qualification_id'],(int)$parent['experiment_id'],(int)$parent['id'],$attempt,$parent['dataset_hash_snapshot'],$parent['assembly_hash_snapshot'],$parent['release_hash_snapshot'],$parent['qualification_hash_snapshot'],$parent['config_hash'],$runHash,$runKey,$parent['trainer'],$parent['trainer_version'],$parent['config_json'],$actor,$actor]);
    glasses_vision_lineage_edge($pdo,$org,'training_run',(string)$parent['public_id'],(string)$parent['run_hash'],'retried_as','training_run',$public,$runHash,['attemptNo'=>$attempt],$actor);
    return glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,$public));
  });
}

function glasses_v11_training_run_compare(PDO $pdo,int $org,string $leftPublic,string $rightPublic): array {
  $a=glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,$leftPublic));$b=glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,$rightPublic));
  if(($a['experiment']['publicId']??null)!==($b['experiment']['publicId']??null))throw new InvalidArgumentException('Training run comparison requires runs from the same experiment.');
  $metricKeys=array_values(array_unique(array_merge(array_keys(is_array($a['metrics'])?$a['metrics']:[]),array_keys(is_array($b['metrics'])?$b['metrics']:[]))));sort($metricKeys,SORT_STRING);
  $metricDiff=[];foreach($metricKeys as $k){$av=$a['metrics'][$k]??null;$bv=$b['metrics'][$k]??null;$metricDiff[$k]=['from'=>$av,'to'=>$bv,'delta'=>(is_numeric($av)&&is_numeric($bv))?(float)$bv-(float)$av:null];}
  return ['schema'=>'gelato.vision_v11_training_run_compare.v1','experiment'=>$a['experiment'],'from'=>$a,'to'=>$b,'sameConfigHash'=>$a['configHash']===$b['configHash'],'sameDatasetHash'=>$a['dataset']['datasetHash']===$b['dataset']['datasetHash'],'sameAssemblyHash'=>$a['dataset']['assemblyHash']===$b['dataset']['assemblyHash'],'metrics'=>$metricDiff];
}

function glasses_v11_training_run_catalog(PDO $pdo,int $org): array {
  if(!glasses_v11_training_run_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_V11_TRAINING_RUN_SCHEMA,'runs'=>[]];
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_runs WHERE organization_id=? AND experiment_id IS NOT NULL AND run_hash IS NOT NULL ORDER BY id DESC LIMIT 100");$q->execute([$org]);
  return ['ready'=>true,'schema'=>GLASSES_V11_TRAINING_RUN_SCHEMA,'runs'=>array_map(fn($p)=>glasses_v11_training_run_public(glasses_v11_training_run_row($pdo,$org,(string)$p)),$q->fetchAll(PDO::FETCH_COLUMN))];
}
