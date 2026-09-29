<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-v11-canary-release.php';
require_once __DIR__.'/glasses-vision-retraining.php';
require_once __DIR__.'/glasses-v11-dataset-assembly.php';

const GLASSES_V11_PRODUCTION_LEARNING_SCHEMA='gelato.vision_v11_production_learning_cycle.v1';

function glasses_v11_production_learning_ready(PDO $pdo): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_learning_cycles'");$q->execute();
  return (int)$q->fetchColumn()===1&&glasses_v11_canary_release_ready($pdo)&&glasses_vision_retraining_ready($pdo)&&glasses_v11_dataset_assembly_ready($pdo);
}

function glasses_v11_production_learning_policy(array $in=[]): array {
  return [
    'minimumProductionAgeHours'=>max(0,(int)($in['minimumProductionAgeHours']??24)),
    'minimumMinedCandidates'=>max(1,(int)($in['minimumMinedCandidates']??5)),
    'requireExplicitRetrainingReview'=>true,
    'requireV11AssembledFrozenDataset'=>true,
    'automaticDatasetInclusion'=>false,
    'automaticTraining'=>false,
    'automaticExperiment'=>false,
    'automaticRollout'=>false,
  ];
}

function glasses_v11_production_learning_row(PDO $pdo,int $org,string $publicId): array {
  $q=$pdo->prepare("SELECT c.*,a.public_id acceptance_public_id,a.acceptance_hash,mp.public_id model_public_id,mp.artifact_sha256,
    mr.public_id mining_public_id,mr.run_hash,rb.public_id batch_public_id,rb.batch_hash,d.public_id dataset_public_id,d.dataset_hash,d.assembly_hash,d.status dataset_status
    FROM glasses_vision_learning_cycles c
    JOIN glasses_vision_production_acceptances a ON a.id=c.production_acceptance_id AND a.organization_id=c.organization_id
    JOIN glasses_vision_model_packages mp ON mp.id=c.model_package_id AND mp.organization_id=c.organization_id
    LEFT JOIN glasses_vision_mining_runs mr ON mr.id=c.mining_run_id AND mr.organization_id=c.organization_id
    LEFT JOIN glasses_vision_retraining_batches rb ON rb.id=c.retraining_batch_id AND rb.organization_id=c.organization_id
    LEFT JOIN glasses_vision_dataset_versions d ON d.id=c.dataset_id AND d.organization_id=c.organization_id
    WHERE c.organization_id=? AND c.public_id=? LIMIT 1");
  $q->execute([$org,trim($publicId)]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('V11 production learning cycle was not found.');
  return [
    'schema'=>GLASSES_V11_PRODUCTION_LEARNING_SCHEMA,'publicId'=>$r['public_id'],'status'=>$r['status'],'cycleHash'=>$r['cycle_hash'],
    'productionAcceptance'=>['publicId'=>$r['acceptance_public_id'],'acceptanceHash'=>$r['acceptance_hash']],
    'productionModel'=>['publicId'=>$r['model_public_id'],'artifactSha256'=>$r['artifact_sha256']],
    'sourceWindow'=>['start'=>$r['source_window_start'],'end'=>$r['source_window_end']],
    'policy'=>json_decode((string)$r['policy_json'],true)?:[],'manifest'=>json_decode((string)$r['manifest_json'],true)?:[],
    'miningRun'=>$r['mining_public_id']?['publicId'=>$r['mining_public_id'],'runHash'=>$r['run_hash']]:null,
    'retrainingBatch'=>$r['batch_public_id']?['publicId'=>$r['batch_public_id'],'batchHash'=>$r['batch_hash']]:null,
    'dataset'=>$r['dataset_public_id']?['publicId'=>$r['dataset_public_id'],'datasetHash'=>$r['dataset_hash'],'assemblyHash'=>$r['assembly_hash'],'status'=>$r['dataset_status']]:null,
    'readyAt'=>$r['ready_at'],'createdAt'=>$r['created_at'],
  ];
}

function glasses_v11_production_learning_start(PDO $pdo,int $org,array $input,int $actor): array {
  if(!glasses_v11_production_learning_ready($pdo))throw new RuntimeException('V11 production-learning migration is not installed.');
  $acceptancePublic=trim((string)($input['productionAcceptancePublicId']??''));if($acceptancePublic==='')throw new InvalidArgumentException('Production acceptance public ID is required.');
  $a=glasses_v11_production_acceptance_row($pdo,$org,$acceptancePublic);if((string)$a['status']!=='accepted')throw new InvalidArgumentException('Learning cycle requires an accepted production model.');
  $aq=$pdo->prepare("SELECT a.id,a.accepted_at,a.release_candidate_id,rc.model_package_id FROM glasses_vision_production_acceptances a JOIN glasses_vision_model_release_candidates rc ON rc.id=a.release_candidate_id AND rc.organization_id=a.organization_id WHERE a.organization_id=? AND a.public_id=? LIMIT 1");
  $aq->execute([$org,$acceptancePublic]);$ar=$aq->fetch();if(!$ar)throw new InvalidArgumentException('Production acceptance lineage is incomplete.');
  $policy=glasses_v11_production_learning_policy(is_array($input['policy']??null)?$input['policy']:[]);
  $start=trim((string)($input['sourceWindowStart']??$ar['accepted_at']));$end=trim((string)($input['sourceWindowEnd']??gmdate('Y-m-d H:i:s.u')));
  try{$s=new DateTimeImmutable($start);$e=new DateTimeImmutable($end);$accepted=new DateTimeImmutable((string)$ar['accepted_at']);}catch(Throwable){throw new InvalidArgumentException('Learning-cycle evidence window is invalid.');}
  if($s<$accepted)throw new InvalidArgumentException('Learning cycle cannot consume evidence from before production acceptance.');
  if($e<=$s)throw new InvalidArgumentException('Learning-cycle evidence window must end after it starts.');
  if(($e->getTimestamp()-$accepted->getTimestamp())<($policy['minimumProductionAgeHours']*3600))throw new InvalidArgumentException('Production model has not accumulated the minimum learning window.');
  $manifest=['schema'=>GLASSES_V11_PRODUCTION_LEARNING_SCHEMA,'productionAcceptance'=>['publicId'=>$a['publicId'],'acceptanceHash'=>$a['acceptanceHash']],
    'productionModel'=>$a['manifest']['targetPackage']??null,'sourceWindow'=>['start'=>$s->format('Y-m-d H:i:s.u'),'end'=>$e->format('Y-m-d H:i:s.u')],'policy'=>$policy,
    'governance'=>['humanReviewRequired'=>true,'automaticDatasetInclusion'=>false,'automaticTraining'=>false,'automaticExperiment'=>false,'automaticRollout'=>false]];
  $hash=hash('sha256',glasses_vision_training_release_json($manifest));
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_learning_cycles WHERE organization_id=? AND cycle_hash=? LIMIT 1");$q->execute([$org,$hash]);$ep=$q->fetchColumn();
  if($ep!==false)return glasses_v11_production_learning_row($pdo,$org,(string)$ep);
  $public=glasses_public_id('vision-learning-cycle');
  $pdo->prepare("INSERT INTO glasses_vision_learning_cycles (organization_id,public_id,production_acceptance_id,model_package_id,status,source_window_start,source_window_end,policy_json,manifest_json,cycle_hash,created_by) VALUES (?,?,?,?,'collecting',?,?,?,?,?,?)")
    ->execute([$org,$public,(int)$ar['id'],(int)$ar['model_package_id'],$s->format('Y-m-d H:i:s.u'),$e->format('Y-m-d H:i:s.u'),glasses_vision_training_release_json($policy),glasses_vision_training_release_json($manifest),$hash,$actor]);
  glasses_vision_lineage_edge($pdo,$org,'production_acceptance',$a['publicId'],$a['acceptanceHash'],'opened_learning_cycle','production_learning_cycle',$public,$hash,['sourceWindow'=>$manifest['sourceWindow']],$actor);
  return glasses_v11_production_learning_row($pdo,$org,$public);
}

function glasses_v11_production_learning_attach_mining(PDO $pdo,int $org,string $cyclePublic,string $miningPublic,int $actor): array {
  $cycle=glasses_v11_production_learning_row($pdo,$org,$cyclePublic);if(!in_array($cycle['status'],['collecting','mined'],true))throw new InvalidArgumentException('Learning cycle is not accepting a mining run.');
  $run=glasses_vision_mining_run_row($pdo,$org,$miningPublic);
  $cq=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN c.model_package_id=? THEN 1 ELSE 0 END) matching,SUM(CASE WHEN c.model_package_id IS NOT NULL AND c.model_package_id<>? THEN 1 ELSE 0 END) mismatched
    FROM glasses_vision_mined_candidates c JOIN glasses_vision_learning_cycles lc ON lc.organization_id=c.organization_id
    WHERE lc.organization_id=? AND lc.public_id=? AND c.mining_run_id=?");
  $mq=$pdo->prepare("SELECT id FROM glasses_vision_model_packages WHERE organization_id=? AND public_id=? LIMIT 1");$mq->execute([$org,$cycle['productionModel']['publicId']]);$modelId=(int)$mq->fetchColumn();
  $rq=$pdo->prepare("SELECT id FROM glasses_vision_mining_runs WHERE organization_id=? AND public_id=? LIMIT 1");$rq->execute([$org,$miningPublic]);$runId=(int)$rq->fetchColumn();
  $cq->execute([$modelId,$modelId,$org,$cyclePublic,$runId]);$counts=$cq->fetch();
  $policy=$cycle['policy'];if((int)$counts['mismatched']>0)throw new InvalidArgumentException('Learning cycle mining run contains candidates from a different model package.');
  if((int)$counts['matching']<(int)$policy['minimumMinedCandidates'])throw new InvalidArgumentException('Learning cycle mining run does not meet the minimum accepted-model candidate count.');
  $pdo->prepare("UPDATE glasses_vision_learning_cycles SET mining_run_id=?,status='mined' WHERE organization_id=? AND public_id=?")->execute([$runId,$org,$cyclePublic]);
  glasses_vision_lineage_edge($pdo,$org,'production_learning_cycle',$cyclePublic,$cycle['cycleHash'],'mined_from_production','mining_run',$miningPublic,(string)$run['run_hash'],['matchingCandidates'=>(int)$counts['matching']],$actor);
  return glasses_v11_production_learning_row($pdo,$org,$cyclePublic);
}

function glasses_v11_production_learning_attach_batch(PDO $pdo,int $org,string $cyclePublic,string $batchPublic,int $actor): array {
  $cycle=glasses_v11_production_learning_row($pdo,$org,$cyclePublic);if($cycle['status']!=='mined'||!$cycle['miningRun'])throw new InvalidArgumentException('Learning cycle requires an attached mining run before retraining review.');
  $batch=glasses_vision_retraining_batch($pdo,$org,$batchPublic);
  if($batch['miningRunPublicId']!==$cycle['miningRun']['publicId'])throw new InvalidArgumentException('Retraining batch does not belong to the learning-cycle mining run.');
  if($batch['counts']['pending']>0)throw new InvalidArgumentException('Every eligible retraining candidate must be explicitly reviewed before attaching the batch.');
  if($batch['counts']['include']<1)throw new InvalidArgumentException('Learning cycle requires at least one explicitly included retraining candidate.');
  $q=$pdo->prepare("SELECT id FROM glasses_vision_retraining_batches WHERE organization_id=? AND public_id=? LIMIT 1");$q->execute([$org,$batchPublic]);$batchId=(int)$q->fetchColumn();
  $pdo->prepare("UPDATE glasses_vision_learning_cycles SET retraining_batch_id=?,status='reviewed' WHERE organization_id=? AND public_id=?")->execute([$batchId,$org,$cyclePublic]);
  glasses_vision_lineage_edge($pdo,$org,'production_learning_cycle',$cyclePublic,$cycle['cycleHash'],'reviewed_as','retraining_batch',$batchPublic,$batch['batchHash'],['includedCandidates'=>$batch['counts']['include']],$actor);
  return glasses_v11_production_learning_row($pdo,$org,$cyclePublic);
}

function glasses_v11_production_learning_attach_dataset(PDO $pdo,int $org,string $cyclePublic,string $datasetPublic,int $actor): array {
  $cycle=glasses_v11_production_learning_row($pdo,$org,$cyclePublic);if(!in_array($cycle['status'],['reviewed','dataset_ready'],true)||!$cycle['retrainingBatch'])throw new InvalidArgumentException('Learning cycle requires an explicitly reviewed retraining batch before dataset attachment.');
  $bq=$pdo->prepare("SELECT dataset_id FROM glasses_vision_retraining_batches WHERE organization_id=? AND public_id=? LIMIT 1");$bq->execute([$org,$cycle['retrainingBatch']['publicId']]);$batchDataset=(int)$bq->fetchColumn();
  $dq=$pdo->prepare("SELECT * FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=? LIMIT 1");$dq->execute([$org,$datasetPublic]);$d=$dq->fetch();if(!$d)throw new InvalidArgumentException('Learning-cycle dataset was not found.');
  if($batchDataset<1||(int)$d['id']!==$batchDataset)throw new InvalidArgumentException('Dataset must be the canonical dataset built from the attached retraining batch.');
  $ready=(string)$d['status']==='frozen'&&is_string($d['dataset_hash'])&&strlen((string)$d['dataset_hash'])===64&&is_string($d['assembly_hash'])&&strlen((string)$d['assembly_hash'])===64;
  $status=$ready?'ready_for_experiment':'dataset_ready';
  $pdo->prepare("UPDATE glasses_vision_learning_cycles SET dataset_id=?,status=?,ready_by=?,ready_at=? WHERE organization_id=? AND public_id=?")
    ->execute([(int)$d['id'],$status,$ready?$actor:null,$ready?gmdate('Y-m-d H:i:s.u'):null,$org,$cyclePublic]);
  $updated=glasses_v11_production_learning_row($pdo,$org,$cyclePublic);
  glasses_vision_lineage_edge($pdo,$org,'retraining_batch',$cycle['retrainingBatch']['publicId'],$cycle['retrainingBatch']['batchHash'],'closed_loop_dataset','dataset',$datasetPublic,$d['dataset_hash'],['assemblyHash'=>$d['assembly_hash'],'readyForExperiment'=>$ready],$actor);
  return $updated;
}

function glasses_v11_production_learning_refresh(PDO $pdo,int $org,string $cyclePublic,int $actor): array {
  $cycle=glasses_v11_production_learning_row($pdo,$org,$cyclePublic);if(!$cycle['dataset'])return $cycle;
  if($cycle['status']==='ready_for_experiment')return $cycle;
  return glasses_v11_production_learning_attach_dataset($pdo,$org,$cyclePublic,$cycle['dataset']['publicId'],$actor);
}

function glasses_v11_production_learning_catalog(PDO $pdo,int $org): array {
  if(!glasses_v11_production_learning_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_V11_PRODUCTION_LEARNING_SCHEMA,'cycles'=>[]];
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_learning_cycles WHERE organization_id=? ORDER BY id DESC LIMIT 100");$q->execute([$org]);
  return ['ready'=>true,'schema'=>GLASSES_V11_PRODUCTION_LEARNING_SCHEMA,'cycles'=>array_map(fn($p)=>glasses_v11_production_learning_row($pdo,$org,(string)$p),$q->fetchAll(PDO::FETCH_COLUMN))];
}
