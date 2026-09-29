<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-training-qualification.php';
require_once __DIR__.'/glasses-vision-models.php';

const GLASSES_VISION_LINEAGE_SCHEMA='gelato.vision_lineage.v1';

function glasses_vision_lineage_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_training_runs','glasses_vision_lineage_edges'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_training_qualification_ready($pdo)&&glasses_vision_models_ready($pdo);
}

function glasses_vision_lineage_sha(?string $value): ?string
{
    $value=strtolower(trim((string)$value));
    if($value==='')return null;
    if(!preg_match('/^[a-f0-9]{64}$/',$value))throw new InvalidArgumentException('Lineage SHA-256 is invalid.');
    return $value;
}

function glasses_vision_lineage_edge(PDO $pdo,int $org,string $fromKind,string $fromPublic,?string $fromHash,string $relation,string $toKind,string $toPublic,?string $toHash,?array $evidence,?int $actor): array
{
    foreach([$fromKind,$relation,$toKind] as $v)if(!preg_match('/^[a-z0-9_.-]{2,64}$/',$v))throw new InvalidArgumentException('Lineage edge type is invalid.');
    $fromPublic=trim($fromPublic);$toPublic=trim($toPublic);
    if($fromPublic===''||$toPublic==='')throw new InvalidArgumentException('Lineage edge endpoints are required.');
    $fromHash=glasses_vision_lineage_sha($fromHash);$toHash=glasses_vision_lineage_sha($toHash);
    $evidenceJson=$evidence?glasses_vision_training_release_json($evidence):null;
    $q=$pdo->prepare("SELECT * FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind=? AND from_public_id=? AND relation=? AND to_kind=? AND to_public_id=? LIMIT 1");
    $q->execute([$org,$fromKind,$fromPublic,$relation,$toKind,$toPublic]);$row=$q->fetch();
    if($row){
        $storedEvidence=$row['evidence_json']!==null?glasses_vision_training_release_json(json_decode((string)$row['evidence_json'],true)?:[]):null;
        if(($row['from_hash']??null)!==$fromHash||($row['to_hash']??null)!==$toHash||$storedEvidence!==$evidenceJson)
            throw new InvalidArgumentException('Lineage edge identity already exists with different immutable evidence.');
    }else{
        $public=glasses_public_id('vision-lineage');
        $pdo->prepare("INSERT INTO glasses_vision_lineage_edges
          (organization_id,public_id,from_kind,from_public_id,from_hash,relation,to_kind,to_public_id,to_hash,evidence_json,actor_user_id)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,$fromKind,$fromPublic,$fromHash,$relation,$toKind,$toPublic,$toHash,$evidenceJson,$actor]);
        $q->execute([$org,$fromKind,$fromPublic,$relation,$toKind,$toPublic]);$row=$q->fetch();
    }
    return [
      'publicId'=>$row['public_id'],'from'=>['kind'=>$row['from_kind'],'publicId'=>$row['from_public_id'],'hash'=>$row['from_hash']],
      'relation'=>$row['relation'],'to'=>['kind'=>$row['to_kind'],'publicId'=>$row['to_public_id'],'hash'=>$row['to_hash']],
      'evidence'=>json_decode((string)($row['evidence_json']??'null'),true),'createdAt'=>$row['created_at'],
    ];
}

function glasses_vision_lineage_release_by_hash(PDO $pdo,int $org,string $hash): array
{
    $hash=glasses_vision_lineage_sha($hash)??'';
    $q=$pdo->prepare("SELECT r.*,d.public_id dataset_public_id FROM glasses_vision_training_releases r JOIN glasses_vision_dataset_versions d ON d.id=r.dataset_id WHERE r.organization_id=? AND r.release_hash=? LIMIT 1");
    $q->execute([$org,$hash]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Training release hash was not found.');
    return $row;
}

function glasses_vision_lineage_training_run_row(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT tr.*,r.public_id release_public_id,r.release_hash,q.public_id qualification_public_id,q.qualification_hash
      FROM glasses_vision_training_runs tr
      JOIN glasses_vision_training_releases r ON r.id=tr.training_release_id AND r.organization_id=tr.organization_id
      LEFT JOIN glasses_vision_training_qualifications q ON q.id=tr.qualification_id AND q.organization_id=tr.organization_id
      WHERE tr.organization_id=? AND tr.public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision training run was not found.');
    return $row;
}

function glasses_vision_lineage_training_run_public(array $r): array
{
    return [
      'publicId'=>$r['public_id'],'releasePublicId'=>$r['release_public_id'],'releaseHash'=>$r['release_hash'],
      'qualificationPublicId'=>$r['qualification_public_id'],'qualificationHash'=>$r['qualification_hash'],
      'runKey'=>$r['run_key'],'trainer'=>$r['trainer'],'trainerVersion'=>$r['trainer_version'],'status'=>$r['status'],
      'config'=>json_decode((string)$r['config_json'],true)?:[],'metrics'=>json_decode((string)($r['metrics_json']??'null'),true),
      'outputSha256'=>$r['output_sha256'],'startedAt'=>$r['started_at'],'completedAt'=>$r['completed_at'],'createdAt'=>$r['created_at'],
    ];
}

function glasses_vision_lineage_register_training_run(PDO $pdo,int $org,array $input,int $actor): array
{
    if(!glasses_vision_lineage_ready($pdo))throw new RuntimeException('Vision Lab V6 lineage migration is not installed.');
    $release=glasses_vision_lineage_release_by_hash($pdo,$org,(string)($input['releaseHash']??''));
    if((string)$release['status']!=='qualified')throw new InvalidArgumentException('Training run requires a qualified V6 release.');
    $qhash=glasses_vision_lineage_sha((string)($input['qualificationHash']??''))??'';
    $qq=$pdo->prepare("SELECT * FROM glasses_vision_training_qualifications WHERE organization_id=? AND training_release_id=? AND qualification_hash=? AND passed=1 LIMIT 1");
    $qq->execute([$org,(int)$release['id'],$qhash]);$qualification=$qq->fetch();
    if(!$qualification)throw new InvalidArgumentException('Training run qualification does not match a passing release attestation.');
    $runKey=mb_substr(trim((string)($input['runKey']??'')),0,190,'UTF-8');
    $trainer=mb_substr(trim((string)($input['trainer']??'gelato-yolo')),0,120,'UTF-8');
    $trainerVersion=mb_substr(trim((string)($input['trainerVersion']??'')),0,80,'UTF-8')?:null;
    if($runKey==='')throw new InvalidArgumentException('Training run key is required.');
    $status=strtolower(trim((string)($input['status']??'completed')));
    if($status!=='completed')throw new InvalidArgumentException('Lineage registration accepts completed training runs only.');
    $output=glasses_vision_lineage_sha((string)($input['outputSha256']??''));
    if($output===null)throw new InvalidArgumentException('Completed training run requires output SHA-256.');
    $config=is_array($input['config']??null)?$input['config']:[];
    $metrics=is_array($input['metrics']??null)?$input['metrics']:null;
    $started=!empty($input['startedAt'])?(string)$input['startedAt']:null;$completed=!empty($input['completedAt'])?(string)$input['completedAt']:null;

    return glasses_transaction($pdo,function()use($pdo,$org,$release,$qualification,$runKey,$trainer,$trainerVersion,$status,$output,$config,$metrics,$started,$completed,$actor):array{
        $q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_runs WHERE organization_id=? AND run_key=? LIMIT 1");
        $q->execute([$org,$runKey]);$existing=$q->fetchColumn();
        if($existing){
            $row=glasses_vision_lineage_training_run_row($pdo,$org,(string)$existing);
            $storedConfig=glasses_vision_training_release_json(json_decode((string)$row['config_json'],true)?:[]);
            $incomingConfig=glasses_vision_training_release_json($config);
            $storedMetrics=$row['metrics_json']!==null?glasses_vision_training_release_json(json_decode((string)$row['metrics_json'],true)?:[]):null;
            $incomingMetrics=$metrics!==null?glasses_vision_training_release_json($metrics):null;
            if((int)$row['training_release_id']!==(int)$release['id']||(int)$row['qualification_id']!==(int)$qualification['id']||
               (string)$row['trainer']!==$trainer||(string)($row['trainer_version']??'')!==(string)($trainerVersion??'')||
               (string)$row['status']!==$status||(string)$row['output_sha256']!==(string)$output||
               $storedConfig!==$incomingConfig||$storedMetrics!==$incomingMetrics)
                throw new InvalidArgumentException('Training run key already exists with different immutable lineage evidence.');
            return glasses_vision_lineage_training_run_public($row);
        }
        $public=glasses_public_id('vision-train-run');
        $pdo->prepare("INSERT INTO glasses_vision_training_runs
          (organization_id,public_id,training_release_id,qualification_id,run_key,trainer,trainer_version,status,config_json,metrics_json,output_sha256,started_at,completed_at,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,(int)$release['id'],(int)$qualification['id'],$runKey,$trainer,$trainerVersion,$status,json_encode($config,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$metrics?json_encode($metrics,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$output,$started,$completed,$actor]);
        glasses_vision_lineage_edge($pdo,$org,'training_release',(string)$release['public_id'],(string)$release['release_hash'],'trained_as','training_run',$public,$output,[
          'qualificationHash'=>$qualification['qualification_hash'],'trainer'=>$trainer,'trainerVersion'=>$trainerVersion,'status'=>$status
        ],$actor);
        return glasses_vision_lineage_training_run_public(glasses_vision_lineage_training_run_row($pdo,$org,$public));
    });
}

function glasses_vision_lineage_bind_model(PDO $pdo,int $org,string $runPublic,string $modelPublic,int $actor): array
{
    $run=glasses_vision_lineage_training_run_row($pdo,$org,$runPublic);
    if((string)$run['status']!=='completed'||empty($run['output_sha256']))throw new InvalidArgumentException('Only a completed training run may bind a model package.');
    $model=glasses_vision_model_package_row($pdo,$org,$modelPublic,false);
    if(!hash_equals((string)$run['output_sha256'],(string)$model['artifact_sha256']))
        throw new InvalidArgumentException('Model artifact SHA-256 does not match the training-run output.');
    $edge=glasses_vision_lineage_edge($pdo,$org,'training_run',$runPublic,(string)$run['output_sha256'],'produced','model_package',$modelPublic,(string)$model['artifact_sha256'],[
      'modelName'=>$model['model_name'],'modelVersion'=>$model['model_version'],'runtimeType'=>$model['runtime_type'],'platform'=>$model['platform']
    ],$actor);
    glasses_vision_lineage_edge($pdo,$org,'training_release',(string)$run['release_public_id'],(string)$run['release_hash'],'trained_model','model_package',$modelPublic,(string)$model['artifact_sha256'],['trainingRunPublicId'=>$runPublic],$actor);
    return $edge;
}

function glasses_vision_lineage_edges(PDO $pdo,int $org,string $kind,string $publicId): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_vision_lineage_edges WHERE organization_id=? AND ((from_kind=? AND from_public_id=?) OR (to_kind=? AND to_public_id=?)) ORDER BY id");
    $q->execute([$org,$kind,$publicId,$kind,$publicId]);
    return array_map(static fn($r)=>[
      'publicId'=>$r['public_id'],'from'=>['kind'=>$r['from_kind'],'publicId'=>$r['from_public_id'],'hash'=>$r['from_hash']],
      'relation'=>$r['relation'],'to'=>['kind'=>$r['to_kind'],'publicId'=>$r['to_public_id'],'hash'=>$r['to_hash']],
      'evidence'=>json_decode((string)($r['evidence_json']??'null'),true),'createdAt'=>$r['created_at'],
    ],$q->fetchAll());
}

function glasses_vision_lineage_release_manifest(array $release): array
{
    return json_decode((string)$release['manifest_json'],true)?:[];
}

function glasses_vision_lineage_release_for_model(PDO $pdo,int $org,string $modelPublic): ?array
{
    $q=$pdo->prepare("SELECT r.*,d.public_id dataset_public_id
      FROM glasses_vision_lineage_edges e
      JOIN glasses_vision_training_releases r ON r.organization_id=e.organization_id AND r.public_id=e.from_public_id
      JOIN glasses_vision_dataset_versions d ON d.id=r.dataset_id
      WHERE e.organization_id=? AND e.from_kind='training_release' AND e.relation='trained_model' AND e.to_kind='model_package' AND e.to_public_id=?
      ORDER BY e.id DESC LIMIT 1");
    $q->execute([$org,$modelPublic]);$r=$q->fetch();return $r?:null;
}

function glasses_vision_lineage_model_trace(PDO $pdo,int $org,string $modelPublic): array
{
    $model=glasses_vision_model_package_row($pdo,$org,$modelPublic,false);
    $release=glasses_vision_lineage_release_for_model($pdo,$org,$modelPublic);
    $manifest=$release?glasses_vision_lineage_release_manifest($release):[];
    $rq=$pdo->prepare("SELECT tr.public_id FROM glasses_vision_training_runs tr JOIN glasses_vision_lineage_edges e ON e.organization_id=tr.organization_id AND e.from_kind='training_run' AND e.from_public_id=tr.public_id AND e.relation='produced' WHERE tr.organization_id=? AND e.to_kind='model_package' AND e.to_public_id=? ORDER BY tr.id DESC");
    $rq->execute([$org,$modelPublic]);$runs=[];foreach($rq->fetchAll(PDO::FETCH_COLUMN) as $p)$runs[]=glasses_vision_lineage_training_run_public(glasses_vision_lineage_training_run_row($pdo,$org,(string)$p));

    $roll=$pdo->prepare("SELECT r.public_id,r.status,r.canary_percent,r.created_at,r.activated_at,r.rolled_back_at FROM glasses_vision_model_rollouts r WHERE r.organization_id=? AND r.target_package_id=? ORDER BY r.id");
    $roll->execute([$org,(int)$model['id']]);$rollouts=$roll->fetchAll();
    $shadow=$pdo->prepare("SELECT sr.public_id,sr.status,sr.frame_count,sr.started_at,sr.completed_at,r.public_id rollout_public_id FROM glasses_vision_shadow_runs sr JOIN glasses_vision_model_rollouts r ON r.id=sr.rollout_id WHERE sr.organization_id=? AND sr.challenger_package_id=? ORDER BY sr.id");
    $shadow->execute([$org,(int)$model['id']]);$shadows=$shadow->fetchAll();
    $canary=$pdo->prepare("SELECT r.public_id rollout_public_id,MAX(h.created_at) last_snapshot,MAX(r.canary_percent) canary_percent,r.status FROM glasses_vision_model_rollouts r LEFT JOIN glasses_vision_canary_health_snapshots h ON h.rollout_id=r.id WHERE r.organization_id=? AND r.target_package_id=? GROUP BY r.id,r.public_id,r.status ORDER BY r.id");
    $canary->execute([$org,(int)$model['id']]);$canaries=$canary->fetchAll();
    $prod=$pdo->prepare("SELECT COUNT(*) assignment_count,COUNT(DISTINCT device_id) device_count,MAX(issued_at) last_assignment FROM glasses_vision_model_assignments WHERE organization_id=? AND package_id=? AND action='apply'");
    $prod->execute([$org,(int)$model['id']]);$production=$prod->fetch()?:[];

    return [
      'schema'=>GLASSES_VISION_LINEAGE_SCHEMA,'model'=>glasses_vision_model_package_public($model),
      'comparison'=>glasses_vision_model_comparison_metadata(json_decode((string)($model['metadata_json']??'null'),true)?:[]),
      'trainingRuns'=>$runs,
      'release'=>$release?[
        'publicId'=>$release['public_id'],'releaseHash'=>$release['release_hash'],'datasetPublicId'=>$release['dataset_public_id'],
        'dataset'=>$manifest['dataset']??null,'splitPlan'=>$manifest['splitPlan']??null,'trainingProfile'=>$manifest['trainingProfile']??null,
        'images'=>array_values(array_map(static fn($i)=>[
          'samplePublicId'=>$i['samplePublicId']??null,'mediaPublicId'=>$i['mediaPublicId']??null,'split'=>$i['split']??null,
          'imageSha256'=>$i['imageSha256']??null,'labelSha256'=>$i['labelSha256']??null,'canonicalLabel'=>$i['canonicalLabel']??null,
          'curationDecision'=>$i['curationDecision']??null,'curationReason'=>$i['curationReason']??null,
          'captureGroup'=>$i['captureGroup']??null,'buildSessionPublicId'=>$i['buildSessionPublicId']??null,
        ],$manifest['items']??[]))
      ]:null,
      'lifecycle'=>['rollouts'=>$rollouts,'shadowRuns'=>$shadows,'canary'=>$canaries,'production'=>$production],
      'edges'=>glasses_vision_lineage_edges($pdo,$org,'model_package',$modelPublic),
    ];
}

function glasses_vision_lineage_sample_trace(PDO $pdo,int $org,string $samplePublic): array
{
    $sq=$pdo->prepare("SELECT s.* FROM glasses_vision_training_samples s WHERE s.organization_id=? AND s.public_id=? LIMIT 1");
    $sq->execute([$org,$samplePublic]);$sample=$sq->fetch();if(!$sample)throw new InvalidArgumentException('Vision sample was not found.');
    $releases=[];$models=[];
    $q=$pdo->prepare("SELECT r.*,d.public_id dataset_public_id FROM glasses_vision_training_releases r JOIN glasses_vision_dataset_versions d ON d.id=r.dataset_id WHERE r.organization_id=? ORDER BY r.id");
    $q->execute([$org]);
    foreach($q->fetchAll() as $r){
        $m=glasses_vision_lineage_release_manifest($r);$matches=array_values(array_filter($m['items']??[],static fn($i)=>($i['samplePublicId']??null)===$samplePublic));
        if(!$matches)continue;
        $releases[]=['publicId'=>$r['public_id'],'releaseHash'=>$r['release_hash'],'datasetPublicId'=>$r['dataset_public_id'],'items'=>$matches];
        $mq=$pdo->prepare("SELECT DISTINCT to_public_id FROM glasses_vision_lineage_edges WHERE organization_id=? AND from_kind='training_release' AND from_public_id=? AND relation='trained_model' AND to_kind='model_package'");
        $mq->execute([$org,$r['public_id']]);foreach($mq->fetchAll(PDO::FETCH_COLUMN) as $mp)$models[(string)$mp]=glasses_vision_model_package_public(glasses_vision_model_package_row($pdo,$org,(string)$mp,false));
    }
    $curation=$pdo->prepare("SELECT d.public_id dataset_public_id,d.version_label,ce.decision,ce.reason,ce.created_at,u.display_name curator
      FROM glasses_vision_dataset_items di JOIN glasses_vision_dataset_versions d ON d.id=di.dataset_id
      LEFT JOIN glasses_vision_dataset_curation_events ce ON ce.id=(SELECT MAX(c2.id) FROM glasses_vision_dataset_curation_events c2 WHERE c2.organization_id=di.organization_id AND c2.dataset_id=di.dataset_id AND c2.sample_id=di.sample_id)
      LEFT JOIN users u ON u.id=ce.actor_user_id
      WHERE di.organization_id=? AND di.sample_id=? ORDER BY d.id");
    $curation->execute([$org,(int)$sample['id']]);
    return [
      'schema'=>GLASSES_VISION_LINEAGE_SCHEMA,'sample'=>['publicId'=>$sample['public_id'],'canonicalLabel'=>$sample['canonical_label'],'reviewStatus'=>$sample['review_status'],'sourceType'=>$sample['source_type']],
      'curation'=>$curation->fetchAll(),'releases'=>$releases,'models'=>array_values($models),
    ];
}

function glasses_vision_lineage_dataset_snapshot(PDO $pdo,int $org,string $datasetPublic): array
{
    $dataset=glasses_vision_curation_dataset($pdo,$org,$datasetPublic);
    $rows=glasses_vision_curation_rows($pdo,$org,$datasetPublic);$out=[];
    $q=$pdo->prepare("SELECT di.source_hash FROM glasses_vision_dataset_items di JOIN glasses_vision_training_samples s ON s.id=di.sample_id WHERE di.organization_id=? AND di.dataset_id=? AND s.public_id=? LIMIT 1");
    foreach($rows as $r){
        $q->execute([$org,(int)$dataset['id'],$r['samplePublicId']]);$sourceHash=$q->fetchColumn();
        $out[$r['samplePublicId']]=[
          'samplePublicId'=>$r['samplePublicId'],'label'=>$r['label'],'split'=>$r['split'],'curationDecision'=>$r['curationDecision'],
          'curationReason'=>$r['curationReason'],'sourceHash'=>$sourceHash?:null,'captureGroups'=>$r['captureGroups'],'buildPublicId'=>$r['buildPublicId']
        ];
    }
    ksort($out,SORT_STRING);return ['dataset'=>$dataset,'samples'=>$out];
}

function glasses_vision_lineage_dataset_diff(PDO $pdo,int $org,string $fromPublic,string $toPublic): array
{
    $a=glasses_vision_lineage_dataset_snapshot($pdo,$org,$fromPublic);$b=glasses_vision_lineage_dataset_snapshot($pdo,$org,$toPublic);
    $added=[];$removed=[];$changed=[];$all=array_unique(array_merge(array_keys($a['samples']),array_keys($b['samples'])));sort($all,SORT_STRING);
    foreach($all as $id){
        if(!isset($a['samples'][$id])){$added[]=$b['samples'][$id];continue;}
        if(!isset($b['samples'][$id])){$removed[]=$a['samples'][$id];continue;}
        $before=$a['samples'][$id];$after=$b['samples'][$id];$delta=[];
        foreach(['label','split','curationDecision','curationReason','sourceHash','captureGroups','buildPublicId'] as $k)if($before[$k]!==$after[$k])$delta[$k]=['from'=>$before[$k],'to'=>$after[$k]];
        if($delta)$changed[]=['samplePublicId'=>$id,'changes'=>$delta];
    }
    return [
      'schema'=>'gelato.vision_dataset_diff.v1',
      'from'=>['publicId'=>$a['dataset']['public_id'],'name'=>$a['dataset']['name'],'versionLabel'=>$a['dataset']['version_label'],'datasetHash'=>$a['dataset']['dataset_hash']],
      'to'=>['publicId'=>$b['dataset']['public_id'],'name'=>$b['dataset']['name'],'versionLabel'=>$b['dataset']['version_label'],'datasetHash'=>$b['dataset']['dataset_hash']],
      'summary'=>['added'=>count($added),'removed'=>count($removed),'changed'=>count($changed),'unchanged'=>count($all)-count($added)-count($removed)-count($changed)],
      'added'=>$added,'removed'=>$removed,'changed'=>$changed,
    ];
}

function glasses_vision_lineage_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_lineage_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_LINEAGE_SCHEMA,'trainingRuns'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_training_runs WHERE organization_id=? ORDER BY id DESC LIMIT 50");$q->execute([$org]);
    $runs=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$runs[]=glasses_vision_lineage_training_run_public(glasses_vision_lineage_training_run_row($pdo,$org,(string)$p));
    return ['ready'=>true,'schema'=>GLASSES_VISION_LINEAGE_SCHEMA,'trainingRuns'=>$runs,'modelPackages'=>glasses_vision_model_packages($pdo,$org)];
}
