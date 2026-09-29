<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-retraining.php';
require_once __DIR__.'/glasses-vision-lineage.php';

const GLASSES_VISION_EXPERIMENT_SCHEMA='gelato.vision_model_improvement_experiment.v1';

function glasses_vision_experiment_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_model_experiments','glasses_vision_model_experiment_events'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_retraining_ready($pdo)&&glasses_vision_lineage_ready($pdo);
}

function glasses_vision_experiment_config(array $input): array
{
    $out=[
      'epochs'=>max(1,min(2000,(int)($input['epochs']??100))),
      'batchSize'=>max(1,min(1024,(int)($input['batchSize']??16))),
      'imageSize'=>max(128,min(4096,(int)($input['imageSize']??640))),
      'seed'=>(int)($input['seed']??74),
    ];
    if(isset($input['learningRate'])){$v=(float)$input['learningRate'];if($v<=0||$v>1)throw new InvalidArgumentException('Experiment learning rate is invalid.');$out['learningRate']=$v;}
    if(isset($input['weightDecay'])){$v=(float)$input['weightDecay'];if($v<0||$v>1)throw new InvalidArgumentException('Experiment weight decay is invalid.');$out['weightDecay']=$v;}
    if(isset($input['augmentations'])){
        if(!is_array($input['augmentations']))throw new InvalidArgumentException('Experiment augmentations must be an array.');
        $out['augmentations']=glasses_vision_training_release_canonicalize($input['augmentations']);
    }
    return $out;
}

function glasses_vision_experiment_event(PDO $pdo,int $org,int $experimentId,string $type,array $evidence,int $actor): void
{
    $pdo->prepare("INSERT INTO glasses_vision_model_experiment_events (organization_id,experiment_id,event_type,evidence_json,actor_user_id) VALUES (?,?,?,?,?)")
      ->execute([$org,$experimentId,$type,json_encode(glasses_vision_training_release_canonicalize($evidence),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor]);
}

function glasses_vision_experiment_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $q=$pdo->prepare("SELECT x.*,cp.public_id champion_public_id,cp.artifact_sha256 champion_sha,cp.model_name champion_name,cp.model_version champion_version,
      cp.detector_name champion_detector_name,cp.runtime_type champion_runtime_type,cp.platform champion_platform,
      d.public_id dataset_public_id,d.dataset_hash,d.status dataset_status,
      r.public_id release_public_id,r.release_hash,r.training_profile,r.status release_status,
      qf.public_id qualification_public_id,qf.qualification_hash,
      tr.public_id training_run_public_id,tr.output_sha256 training_run_output_sha,
      xp.public_id challenger_public_id,xp.artifact_sha256 challenger_sha,xp.model_name challenger_name,xp.model_version challenger_version,
      xp.detector_name challenger_detector_name,xp.runtime_type challenger_runtime_type,xp.platform challenger_platform
      FROM glasses_vision_model_experiments x
      JOIN glasses_vision_model_packages cp ON cp.id=x.champion_package_id AND cp.organization_id=x.organization_id
      JOIN glasses_vision_dataset_versions d ON d.id=x.candidate_dataset_id AND d.organization_id=x.organization_id
      JOIN glasses_vision_training_releases r ON r.id=x.training_release_id AND r.organization_id=x.organization_id
      JOIN glasses_vision_training_qualifications qf ON qf.id=x.qualification_id AND qf.organization_id=x.organization_id
      LEFT JOIN glasses_vision_training_runs tr ON tr.id=x.training_run_id AND tr.organization_id=x.organization_id
      LEFT JOIN glasses_vision_model_packages xp ON xp.id=x.challenger_package_id AND xp.organization_id=x.organization_id
      WHERE x.organization_id=? AND x.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,trim($publicId)]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision model experiment was not found.');
    return $row;
}

function glasses_vision_experiment_public(PDO $pdo,int $org,array $r): array
{
    $eq=$pdo->prepare("SELECT event_type,evidence_json,created_at FROM glasses_vision_model_experiment_events WHERE organization_id=? AND experiment_id=? ORDER BY id");
    $eq->execute([$org,(int)$r['id']]);$events=array_map(static fn($e)=>['type'=>$e['event_type'],'evidence'=>json_decode((string)$e['evidence_json'],true)?:[],'createdAt'=>$e['created_at']],$eq->fetchAll());
    return [
      'schema'=>GLASSES_VISION_EXPERIMENT_SCHEMA,'publicId'=>$r['public_id'],'status'=>$r['status'],'experimentHash'=>$r['experiment_hash'],
      'hypothesis'=>$r['hypothesis'],'trainingConfig'=>json_decode((string)$r['training_config_json'],true)?:[],
      'champion'=>['publicId'=>$r['champion_public_id'],'artifactSha256'=>$r['champion_sha'],'name'=>$r['champion_name'],'version'=>$r['champion_version'],'detectorName'=>$r['champion_detector_name'],'runtimeType'=>$r['champion_runtime_type'],'platform'=>$r['champion_platform']],
      'candidateDataset'=>['publicId'=>$r['dataset_public_id'],'datasetHash'=>$r['dataset_hash'],'status'=>$r['dataset_status']],
      'trainingRelease'=>['publicId'=>$r['release_public_id'],'releaseHash'=>$r['release_hash'],'profile'=>$r['training_profile'],'status'=>$r['release_status']],
      'qualification'=>['publicId'=>$r['qualification_public_id'],'qualificationHash'=>$r['qualification_hash']],
      'trainingRun'=>$r['training_run_id']!==null?['publicId'=>$r['training_run_public_id'],'outputSha256'=>$r['training_run_output_sha']]:null,
      'challenger'=>$r['challenger_package_id']!==null?['publicId'=>$r['challenger_public_id'],'artifactSha256'=>$r['challenger_sha'],'name'=>$r['challenger_name'],'version'=>$r['challenger_version'],'detectorName'=>$r['challenger_detector_name'],'runtimeType'=>$r['challenger_runtime_type'],'platform'=>$r['challenger_platform']]:null,
      'events'=>$events,'createdAt'=>$r['created_at'],'startedAt'=>$r['started_at'],'completedAt'=>$r['completed_at'],
    ];
}

function glasses_vision_experiment_create(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_experiment_ready($pdo))throw new RuntimeException('Vision Lab V7 experiment migration is not installed.');
    $hypothesis=mb_substr(trim((string)($input['hypothesis']??'')),0,2000,'UTF-8');
    if($hypothesis==='')throw new InvalidArgumentException('Experiment hypothesis is required.');
    $champion=glasses_vision_model_package_row($pdo,$org,(string)($input['championModelPublicId']??''),false);
    if((string)$champion['status']!=='ready')throw new InvalidArgumentException('Experiment champion model must be ready.');

    $release=glasses_vision_training_release_row($pdo,$org,(string)($input['trainingReleasePublicId']??''));
    if((string)$release['status']!=='qualified')throw new InvalidArgumentException('Experiment requires a qualified training release.');
    $dq=$pdo->prepare("SELECT * FROM glasses_vision_dataset_versions WHERE organization_id=? AND id=? LIMIT 1");
    $dq->execute([$org,(int)$release['dataset_id']]);$dataset=$dq->fetch();
    if(!$dataset||(string)$dataset['status']!=='frozen'||empty($dataset['dataset_hash']))throw new InvalidArgumentException('Experiment candidate dataset must be frozen and hash-addressed.');

    $qq=$pdo->prepare("SELECT * FROM glasses_vision_training_qualifications WHERE organization_id=? AND training_release_id=? AND passed=1 ORDER BY id DESC LIMIT 1");
    $qq->execute([$org,(int)$release['id']]);$qualification=$qq->fetch();
    if(!$qualification)throw new InvalidArgumentException('Experiment training release has no passing qualification.');

    $config=glasses_vision_experiment_config(is_array($input['trainingConfig']??null)?$input['trainingConfig']:[]);
    $identity=[
      'schema'=>GLASSES_VISION_EXPERIMENT_SCHEMA,'hypothesis'=>$hypothesis,
      'champion'=>['publicId'=>$champion['public_id'],'artifactSha256'=>$champion['artifact_sha256']],
      'dataset'=>['publicId'=>$dataset['public_id'],'datasetHash'=>$dataset['dataset_hash']],
      'release'=>['publicId'=>$release['public_id'],'releaseHash'=>$release['release_hash'],'trainingProfile'=>$release['training_profile']],
      'qualification'=>['publicId'=>$qualification['public_id'],'qualificationHash'=>$qualification['qualification_hash']],
      'trainingConfig'=>$config,
    ];
    $hash=hash('sha256',glasses_vision_training_release_json($identity));
    $existing=$pdo->prepare("SELECT public_id FROM glasses_vision_model_experiments WHERE organization_id=? AND experiment_hash=? LIMIT 1");
    $existing->execute([$org,$hash]);$public=$existing->fetchColumn();
    if($public!==false)return glasses_vision_experiment_public($pdo,$org,glasses_vision_experiment_row($pdo,$org,(string)$public));

    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$hypothesis,$champion,$dataset,$release,$qualification,$config,$identity,$hash):array{
        $public=glasses_public_id('vision-experiment');
        $pdo->prepare("INSERT INTO glasses_vision_model_experiments
          (organization_id,public_id,hypothesis,champion_package_id,candidate_dataset_id,training_release_id,qualification_id,status,training_config_json,experiment_hash,created_by)
          VALUES (?,?,?,?,?,?,?,'ready',?,?,?)")
          ->execute([$org,$public,$hypothesis,(int)$champion['id'],(int)$dataset['id'],(int)$release['id'],(int)$qualification['id'],json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$hash,$actor]);
        $id=(int)$pdo->lastInsertId();
        glasses_vision_experiment_event($pdo,$org,$id,'created',$identity,$actor);
        glasses_vision_lineage_edge($pdo,$org,'model_package',(string)$champion['public_id'],(string)$champion['artifact_sha256'],'champion_for','model_experiment',$public,$hash,['hypothesis'=>$hypothesis],$actor);
        glasses_vision_lineage_edge($pdo,$org,'training_release',(string)$release['public_id'],(string)$release['release_hash'],'used_by_experiment','model_experiment',$public,$hash,['qualificationHash'=>$qualification['qualification_hash']],$actor);
        return glasses_vision_experiment_public($pdo,$org,glasses_vision_experiment_row($pdo,$org,$public));
    });
}

function glasses_vision_experiment_start(PDO $pdo,int $org,string $publicId,int $actor): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$actor):array{
        $r=glasses_vision_experiment_row($pdo,$org,$publicId,true);
        if((string)$r['status']==='running')return glasses_vision_experiment_public($pdo,$org,$r);
        if((string)$r['status']!=='ready')throw new InvalidArgumentException('Only a ready model experiment may start.');
        $pdo->prepare("UPDATE glasses_vision_model_experiments SET status='running',started_by=?,started_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([$actor,$org,(int)$r['id']]);
        glasses_vision_experiment_event($pdo,$org,(int)$r['id'],'started',['experimentHash'=>$r['experiment_hash']],$actor);
        return glasses_vision_experiment_public($pdo,$org,glasses_vision_experiment_row($pdo,$org,$publicId));
    });
}

function glasses_vision_experiment_config_matches(array $expected,array $actual): bool
{
    foreach($expected as $k=>$v){
        if(!array_key_exists($k,$actual))return false;
        if(glasses_vision_training_release_json([$v])!==glasses_vision_training_release_json([$actual[$k]]))return false;
    }
    return true;
}

function glasses_vision_experiment_attach_run(PDO $pdo,int $org,string $publicId,string $runPublicId,int $actor): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$runPublicId,$actor):array{
        $x=glasses_vision_experiment_row($pdo,$org,$publicId,true);
        if((string)$x['status']==='trained'&&(string)$x['training_run_public_id']===$runPublicId)return glasses_vision_experiment_public($pdo,$org,$x);
        if((string)$x['status']!=='running')throw new InvalidArgumentException('Experiment must be running before a training run can be attached.');
        $run=glasses_vision_lineage_training_run_row($pdo,$org,$runPublicId);
        if((string)$run['status']!=='completed'||empty($run['output_sha256']))throw new InvalidArgumentException('Experiment requires a completed training run.');
        if((int)$run['training_release_id']!==(int)$x['training_release_id'])throw new InvalidArgumentException('Training run does not belong to the experiment release.');
        if((int)$run['qualification_id']!==(int)$x['qualification_id'])throw new InvalidArgumentException('Training run does not use the experiment qualification attestation.');
        $expected=json_decode((string)$x['training_config_json'],true)?:[];$actual=json_decode((string)$run['config_json'],true)?:[];
        if(!glasses_vision_experiment_config_matches($expected,$actual))throw new InvalidArgumentException('Training run configuration does not match the experiment contract.');
        $pdo->prepare("UPDATE glasses_vision_model_experiments SET status='trained',training_run_id=? WHERE organization_id=? AND id=?")
          ->execute([(int)$run['id'],$org,(int)$x['id']]);
        glasses_vision_experiment_event($pdo,$org,(int)$x['id'],'training_run_attached',['trainingRunPublicId'=>$runPublicId,'outputSha256'=>$run['output_sha256']],$actor);
        glasses_vision_lineage_edge($pdo,$org,'model_experiment',$publicId,(string)$x['experiment_hash'],'trained_as','training_run',$runPublicId,(string)$run['output_sha256'],['releasePublicId'=>$x['release_public_id']],$actor);
        return glasses_vision_experiment_public($pdo,$org,glasses_vision_experiment_row($pdo,$org,$publicId));
    });
}

function glasses_vision_experiment_bind_challenger(PDO $pdo,int $org,string $publicId,string $modelPublicId,int $actor): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$modelPublicId,$actor):array{
        $x=glasses_vision_experiment_row($pdo,$org,$publicId,true);
        if((string)$x['status']==='completed'&&(string)$x['challenger_public_id']===$modelPublicId)return glasses_vision_experiment_public($pdo,$org,$x);
        if((string)$x['status']!=='trained'||$x['training_run_id']===null)throw new InvalidArgumentException('Experiment must have its completed training run before binding a challenger.');
        $model=glasses_vision_model_package_row($pdo,$org,$modelPublicId,false);
        if((string)$model['status']!=='ready')throw new InvalidArgumentException('Experiment challenger model must be ready.');
        if((string)$model['detector_name']!==(string)$x['champion_detector_name']||
           (string)$model['runtime_type']!==(string)$x['champion_runtime_type']||
           (string)$model['platform']!==(string)$x['champion_platform'])
            throw new InvalidArgumentException('Experiment challenger must match the champion detector, runtime, and platform family.');
        if(!hash_equals((string)$x['training_run_output_sha'],(string)$model['artifact_sha256']))throw new InvalidArgumentException('Challenger artifact SHA-256 does not match the experiment training output.');
        glasses_vision_lineage_bind_model($pdo,$org,(string)$x['training_run_public_id'],$modelPublicId,$actor);
        $pdo->prepare("UPDATE glasses_vision_model_experiments SET status='completed',challenger_package_id=?,completed_by=?,completed_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([(int)$model['id'],$actor,$org,(int)$x['id']]);
        glasses_vision_experiment_event($pdo,$org,(int)$x['id'],'challenger_bound',['modelPackagePublicId'=>$modelPublicId,'artifactSha256'=>$model['artifact_sha256']],$actor);
        glasses_vision_lineage_edge($pdo,$org,'model_experiment',$publicId,(string)$x['experiment_hash'],'produced_challenger','model_package',$modelPublicId,(string)$model['artifact_sha256'],['trainingRunPublicId'=>$x['training_run_public_id']],$actor);
        return glasses_vision_experiment_public($pdo,$org,glasses_vision_experiment_row($pdo,$org,$publicId));
    });
}

function glasses_vision_experiment_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_experiment_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_EXPERIMENT_SCHEMA,'experiments'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_experiments WHERE organization_id=? ORDER BY id DESC LIMIT 50");$q->execute([$org]);
    $out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$out[]=glasses_vision_experiment_public($pdo,$org,glasses_vision_experiment_row($pdo,$org,(string)$p));
    return ['ready'=>true,'schema'=>GLASSES_VISION_EXPERIMENT_SCHEMA,'experiments'=>$out];
}
