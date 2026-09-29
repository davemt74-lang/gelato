<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-evidence-review.php';

const GLASSES_VISION_PROMOTION_SCHEMA='gelato.vision_governed_promotion.v1';

function glasses_vision_promotion_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_model_promotions','glasses_vision_model_promotion_events'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_evidence_review_ready($pdo);
}

function glasses_vision_promotion_event(PDO $pdo,int $org,int $promotionId,string $type,array $evidence,int $actor): void
{
    $pdo->prepare("INSERT INTO glasses_vision_model_promotion_events (organization_id,promotion_id,event_type,evidence_json,actor_user_id) VALUES (?,?,?,?,?)")
      ->execute([$org,$promotionId,$type,json_encode(glasses_vision_training_release_canonicalize($evidence),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor]);
}

function glasses_vision_promotion_source(PDO $pdo,int $org,string $reviewPublic): array
{
    $q=$pdo->prepare("SELECT rv.*,x.public_id experiment_public_id,x.experiment_hash,x.status experiment_status,x.candidate_dataset_id,x.training_release_id,x.qualification_id,
      x.champion_package_id,x.challenger_package_id,
      d.public_id dataset_public_id,d.dataset_hash,d.status dataset_status,
      tr.public_id release_public_id,tr.release_hash,tr.status release_status,
      tq.public_id qualification_public_id,tq.qualification_hash,tq.passed qualification_passed,
      cp.public_id champion_public_id,cp.artifact_sha256 champion_sha,cp.status champion_status,
      xp.public_id challenger_public_id,xp.artifact_sha256 challenger_sha,xp.status challenger_status
      FROM glasses_vision_model_evidence_reviews rv
      JOIN glasses_vision_model_experiments x ON x.id=rv.experiment_id AND x.organization_id=rv.organization_id
      JOIN glasses_vision_dataset_versions d ON d.id=x.candidate_dataset_id AND d.organization_id=x.organization_id
      JOIN glasses_vision_training_releases tr ON tr.id=x.training_release_id AND tr.organization_id=x.organization_id
      JOIN glasses_vision_training_qualifications tq ON tq.id=x.qualification_id AND tq.organization_id=x.organization_id
      JOIN glasses_vision_model_packages cp ON cp.id=x.champion_package_id AND cp.organization_id=x.organization_id
      JOIN glasses_vision_model_packages xp ON xp.id=x.challenger_package_id AND xp.organization_id=x.organization_id
      WHERE rv.organization_id=? AND rv.public_id=? LIMIT 1");
    $q->execute([$org,trim($reviewPublic)]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Passing evidence review was not found.');
    $result=json_decode((string)$row['result_json'],true)?:[];
    $reviewEvidence=json_decode((string)$row['evidence_json'],true)?:[];
    $expectedReviewHash=hash('sha256',glasses_vision_training_release_json(['evidence'=>$reviewEvidence,'result'=>$result]));
    if(!hash_equals((string)$row['review_hash'],$expectedReviewHash))throw new InvalidArgumentException('Evidence review hash verification failed.');
    if((string)$row['status']!=='passed'||empty($result['promotionEligible']))throw new InvalidArgumentException('Promotion requires a passing promotion-eligible evidence review.');
    if((string)$row['experiment_status']!=='completed')throw new InvalidArgumentException('Promotion requires a completed model experiment.');
    if(empty($row['qualification_passed']))throw new InvalidArgumentException('Promotion qualification is no longer a passing attestation.');
    if(($reviewEvidence['experimentHash']??null)!==(string)$row['experiment_hash'])throw new InvalidArgumentException('Evidence review is not bound to the current experiment hash.');
    if((string)$row['dataset_status']!=='frozen'||empty($row['dataset_hash']))throw new InvalidArgumentException('Promotion candidate dataset must remain frozen and hash-addressed.');
    if((string)$row['release_status']!=='qualified')throw new InvalidArgumentException('Promotion training release must remain qualified.');
    if((string)$row['champion_status']!=='ready'||(string)$row['challenger_status']!=='ready')throw new InvalidArgumentException('Champion and challenger packages must remain ready.');

    $bq=$pdo->prepare("SELECT b.*,m.public_id mining_run_public_id,m.run_hash,m.source_fingerprint
      FROM glasses_vision_retraining_batches b
      JOIN glasses_vision_mining_runs m ON m.id=b.mining_run_id AND m.organization_id=b.organization_id
      WHERE b.organization_id=? AND b.dataset_id=? AND b.status='built' ORDER BY b.id DESC LIMIT 1");
    $bq->execute([$org,(int)$row['candidate_dataset_id']]);$batch=$bq->fetch();
    if(!$batch)throw new InvalidArgumentException('Continuous-learning promotion requires a built V7 retraining batch for the experiment dataset.');
    $batchManifest=json_decode((string)$batch['manifest_json'],true)?:[];
    if(!hash_equals((string)$batch['batch_hash'],hash('sha256',glasses_vision_training_release_json($batchManifest))))
        throw new InvalidArgumentException('Retraining batch hash verification failed.');
    $mq=$pdo->prepare("SELECT result_json FROM glasses_vision_mining_runs WHERE organization_id=? AND id=? LIMIT 1");
    $mq->execute([$org,(int)$batch['mining_run_id']]);$miningResult=json_decode((string)$mq->fetchColumn(),true)?:[];
    if(!hash_equals((string)$batch['run_hash'],hash('sha256',glasses_vision_training_release_json($miningResult))))
        throw new InvalidArgumentException('Mining run hash verification failed.');

    $eq=$pdo->prepare("SELECT e.public_id,e.event_hash,c.public_id candidate_public_id,c.candidate_hash,i.evidence_hash
      FROM glasses_vision_retraining_batch_items i
      JOIN glasses_vision_mined_candidates c ON c.id=i.mined_candidate_id AND c.organization_id=i.organization_id
      JOIN glasses_vision_production_errors e ON e.id=i.production_error_id AND e.organization_id=i.organization_id
      WHERE i.organization_id=? AND i.batch_id=? AND i.decision='include'
      ORDER BY e.public_id");
    $eq->execute([$org,(int)$batch['id']]);$errors=$eq->fetchAll();
    if(!$errors)throw new InvalidArgumentException('Continuous-learning promotion requires at least one included production-error source.');

    return ['review'=>$row,'reviewResult'=>$result,'batch'=>$batch,'productionErrors'=>$errors];
}

function glasses_vision_promotion_scope(PDO $pdo,int $org,array $input): array
{
    $locationId=null;$stationId=null;$stationPublic=null;
    if(isset($input['locationId'])&&(int)$input['locationId']>0){
        $locationId=(int)$input['locationId'];glasses_location($pdo,$org,$locationId);
    }
    if(trim((string)($input['stationPublicId']??''))!==''){
        if($locationId===null)throw new InvalidArgumentException('Station-scoped promotion requires a location.');
        $station=glasses_station($pdo,$org,$locationId,(string)$input['stationPublicId']);
        $stationId=(int)$station['id'];$stationPublic=(string)$station['publicId'];
    }
    return ['locationId'=>$locationId,'stationId'=>$stationId,'stationPublicId'=>$stationPublic];
}

function glasses_vision_promotion_authorize(PDO $pdo,int $org,string $reviewPublic,array $input,int $actor): array
{
    if(!glasses_vision_promotion_ready($pdo))throw new RuntimeException('Vision Lab V7 promotion migration is not installed.');
    $rationale=mb_substr(trim((string)($input['rationale']??'')),0,2000,'UTF-8');
    if($rationale==='')throw new InvalidArgumentException('Promotion rationale is required.');
    $source=glasses_vision_promotion_source($pdo,$org,$reviewPublic);$rv=$source['review'];$batch=$source['batch'];
    $scope=glasses_vision_promotion_scope($pdo,$org,$input);

    $errors=array_map(static fn($e)=>[
      'productionErrorPublicId'=>$e['public_id'],'productionErrorHash'=>$e['event_hash'],
      'candidatePublicId'=>$e['candidate_public_id'],'candidateHash'=>$e['candidate_hash'],'batchEvidenceHash'=>$e['evidence_hash']
    ],$source['productionErrors']);
    $audit=[
      'schema'=>GLASSES_VISION_PROMOTION_SCHEMA,
      'productionErrors'=>$errors,
      'miningRun'=>['publicId'=>$batch['mining_run_public_id'],'runHash'=>$batch['run_hash'],'sourceFingerprint'=>$batch['source_fingerprint']],
      'retrainingBatch'=>['publicId'=>$batch['public_id'],'batchHash'=>$batch['batch_hash']],
      'dataset'=>['publicId'=>$rv['dataset_public_id'],'datasetHash'=>$rv['dataset_hash']],
      'trainingRelease'=>['publicId'=>$rv['release_public_id'],'releaseHash'=>$rv['release_hash']],
      'qualification'=>['publicId'=>$rv['qualification_public_id'],'qualificationHash'=>$rv['qualification_hash']],
      'experiment'=>['publicId'=>$rv['experiment_public_id'],'experimentHash'=>$rv['experiment_hash']],
      'evidenceReview'=>['publicId'=>$rv['public_id'],'reviewHash'=>$rv['review_hash']],
      'champion'=>['publicId'=>$rv['champion_public_id'],'artifactSha256'=>$rv['champion_sha']],
      'challenger'=>['publicId'=>$rv['challenger_public_id'],'artifactSha256'=>$rv['challenger_sha']],
      'scope'=>$scope,'initialCanaryPercent'=>5.0,'rationale'=>$rationale,
      'governance'=>['draftRolloutOnly'=>true,'requiresShadowGate'=>true,'requiresCanaryGate'=>true,'automaticActivation'=>false],
    ];
    $hash=hash('sha256',glasses_vision_training_release_json($audit));

    $existing=$pdo->prepare("SELECT public_id,promotion_hash FROM glasses_vision_model_promotions WHERE organization_id=? AND evidence_review_id=? LIMIT 1");
    $existing->execute([$org,(int)$rv['id']]);$e=$existing->fetch();
    if($e){
        if(!hash_equals((string)$e['promotion_hash'],$hash))throw new InvalidArgumentException('Evidence review already has a different immutable promotion authorization.');
        return glasses_vision_promotion_row($pdo,$org,(string)$e['public_id']);
    }

    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$rationale,$source,$scope,$audit,$hash):array{
        $rv=$source['review'];$batch=$source['batch'];$public=glasses_public_id('vision-promotion');
        $pdo->prepare("INSERT INTO glasses_vision_model_promotions
          (organization_id,public_id,experiment_id,evidence_review_id,retraining_batch_id,mining_run_id,champion_package_id,challenger_package_id,location_id,station_id,status,initial_canary_percent,rationale,audit_json,promotion_hash,authorized_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,'authorized',5.00,?,?,?,?)")
          ->execute([$org,$public,(int)$rv['experiment_id'],(int)$rv['id'],(int)$batch['id'],(int)$batch['mining_run_id'],(int)$rv['champion_package_id'],(int)$rv['challenger_package_id'],$scope['locationId'],$scope['stationId'],$rationale,json_encode($audit,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$hash,$actor]);
        $id=(int)$pdo->lastInsertId();
        glasses_vision_promotion_event($pdo,$org,$id,'authorized',['promotionHash'=>$hash,'evidenceReviewPublicId'=>$rv['public_id'],'initialCanaryPercent'=>5.0],$actor);
        glasses_vision_lineage_edge($pdo,$org,'evidence_review',(string)$rv['public_id'],(string)$rv['review_hash'],'authorized_promotion','model_promotion',$public,$hash,['challengerPublicId'=>$rv['challenger_public_id']],$actor);
        return glasses_vision_promotion_row($pdo,$org,$public);
    });
}

function glasses_vision_promotion_create_rollout(PDO $pdo,int $org,string $publicId,int $actor): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$publicId,$actor):array{
        $p=glasses_vision_promotion_db_row($pdo,$org,$publicId,true);
        if((string)$p['status']==='draft_rollout_created'&&$p['rollout_public_id']!==null)
            return glasses_vision_promotion_row($pdo,$org,$publicId);
        if((string)$p['status']!=='authorized')throw new InvalidArgumentException('Only an authorized promotion may create its draft rollout.');

        $source=glasses_vision_promotion_source($pdo,$org,(string)$p['review_public_id']);
        if(!hash_equals((string)$p['promotion_hash'],hash('sha256',glasses_vision_training_release_json(json_decode((string)$p['audit_json'],true)?:[]))))
            throw new InvalidArgumentException('Promotion audit snapshot hash does not match its authorization.');

        $rollout=glasses_vision_model_rollout_create($pdo,$org,[
          'targetPackagePublicId'=>(string)$p['challenger_public_id'],
          'baselinePackagePublicId'=>(string)$p['champion_public_id'],
          'locationId'=>$p['location_id']!==null?(int)$p['location_id']:null,
          'stationPublicId'=>$p['station_public_id'],
          'canaryPercent'=>5.0,
          'notes'=>'V7 governed promotion '.$publicId.' — '.$p['rationale'],
        ],$actor);
        $rq=$pdo->prepare("SELECT id FROM glasses_vision_model_rollouts WHERE organization_id=? AND public_id=? LIMIT 1");$rq->execute([$org,$rollout['publicId']]);$rolloutId=(int)$rq->fetchColumn();
        $pdo->prepare("UPDATE glasses_vision_model_promotions SET status='draft_rollout_created',rollout_id=?,rollout_created_by=?,rollout_created_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([$rolloutId,$actor,$org,(int)$p['id']]);
        glasses_vision_promotion_event($pdo,$org,(int)$p['id'],'draft_rollout_created',['rolloutPublicId'=>$rollout['publicId'],'canaryPercent'=>$rollout['canaryPercent'],'status'=>$rollout['status']],$actor);
        glasses_vision_lineage_edge($pdo,$org,'model_promotion',$publicId,(string)$p['promotion_hash'],'created_draft_rollout','model_rollout',(string)$rollout['publicId'],null,['targetPackagePublicId'=>$p['challenger_public_id'],'baselinePackagePublicId'=>$p['champion_public_id']],$actor);
        return glasses_vision_promotion_row($pdo,$org,$publicId);
    });
}

function glasses_vision_promotion_db_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $q=$pdo->prepare("SELECT p.*,rv.public_id review_public_id,rv.review_hash,x.public_id experiment_public_id,x.experiment_hash,
      b.public_id batch_public_id,b.batch_hash,m.public_id mining_run_public_id,m.run_hash,m.source_fingerprint,
      cp.public_id champion_public_id,cp.artifact_sha256 champion_sha,
      xp.public_id challenger_public_id,xp.artifact_sha256 challenger_sha,
      r.public_id rollout_public_id,r.status rollout_status,r.canary_percent rollout_canary_percent,
      s.public_id station_public_id
      FROM glasses_vision_model_promotions p
      JOIN glasses_vision_model_evidence_reviews rv ON rv.id=p.evidence_review_id AND rv.organization_id=p.organization_id
      JOIN glasses_vision_model_experiments x ON x.id=p.experiment_id AND x.organization_id=p.organization_id
      JOIN glasses_vision_retraining_batches b ON b.id=p.retraining_batch_id AND b.organization_id=p.organization_id
      JOIN glasses_vision_mining_runs m ON m.id=p.mining_run_id AND m.organization_id=p.organization_id
      JOIN glasses_vision_model_packages cp ON cp.id=p.champion_package_id AND cp.organization_id=p.organization_id
      JOIN glasses_vision_model_packages xp ON xp.id=p.challenger_package_id AND xp.organization_id=p.organization_id
      LEFT JOIN glasses_vision_model_rollouts r ON r.id=p.rollout_id AND r.organization_id=p.organization_id
      LEFT JOIN kds_stations s ON s.id=p.station_id AND s.organization_id=p.organization_id
      WHERE p.organization_id=? AND p.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,trim($publicId)]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision model promotion was not found.');
    return $row;
}

function glasses_vision_promotion_verify(PDO $pdo,int $org,array $p): array
{
    $audit=json_decode((string)$p['audit_json'],true)?:[];$checks=[];
    $add=static function(string $key,bool $ok,mixed $expected,mixed $actual)use(&$checks):void{$checks[]=['key'=>$key,'passed'=>$ok,'expected'=>$expected,'actual'=>$actual];};
    $add('promotion_hash',hash_equals((string)$p['promotion_hash'],hash('sha256',glasses_vision_training_release_json($audit))),(string)$p['promotion_hash'],hash('sha256',glasses_vision_training_release_json($audit)));
    $add('review_hash',($audit['evidenceReview']['reviewHash']??null)===$p['review_hash'],$audit['evidenceReview']['reviewHash']??null,$p['review_hash']);
    $add('experiment_hash',($audit['experiment']['experimentHash']??null)===$p['experiment_hash'],$audit['experiment']['experimentHash']??null,$p['experiment_hash']);
    $add('batch_hash',($audit['retrainingBatch']['batchHash']??null)===$p['batch_hash'],$audit['retrainingBatch']['batchHash']??null,$p['batch_hash']);
    $add('mining_run_hash',($audit['miningRun']['runHash']??null)===$p['run_hash'],$audit['miningRun']['runHash']??null,$p['run_hash']);
    $add('champion_hash',($audit['champion']['artifactSha256']??null)===$p['champion_sha'],$audit['champion']['artifactSha256']??null,$p['champion_sha']);
    $add('challenger_hash',($audit['challenger']['artifactSha256']??null)===$p['challenger_sha'],$audit['challenger']['artifactSha256']??null,$p['challenger_sha']);
    foreach((array)($audit['productionErrors']??[]) as $i=>$e){
        $q=$pdo->prepare("SELECT event_hash FROM glasses_vision_production_errors WHERE organization_id=? AND public_id=? LIMIT 1");
        $q->execute([$org,(string)($e['productionErrorPublicId']??'')]);$actual=$q->fetchColumn();
        $add('production_error_'.$i,$actual!==false&&hash_equals((string)$e['productionErrorHash'],(string)$actual),$e['productionErrorHash']??null,$actual!==false?$actual:null);
    }
    return ['passed'=>count(array_filter($checks,static fn($c)=>!$c['passed']))===0,'checks'=>$checks];
}

function glasses_vision_promotion_row(PDO $pdo,int $org,string $publicId): array
{
    $p=glasses_vision_promotion_db_row($pdo,$org,$publicId,false);
    $eq=$pdo->prepare("SELECT event_type,evidence_json,created_at FROM glasses_vision_model_promotion_events WHERE organization_id=? AND promotion_id=? ORDER BY id");
    $eq->execute([$org,(int)$p['id']]);$events=array_map(static fn($e)=>['type'=>$e['event_type'],'evidence'=>json_decode((string)$e['evidence_json'],true)?:[],'createdAt'=>$e['created_at']],$eq->fetchAll());
    return [
      'schema'=>GLASSES_VISION_PROMOTION_SCHEMA,'publicId'=>$p['public_id'],'status'=>$p['status'],'promotionHash'=>$p['promotion_hash'],
      'rationale'=>$p['rationale'],'initialCanaryPercent'=>(float)$p['initial_canary_percent'],
      'evidenceReviewPublicId'=>$p['review_public_id'],'experimentPublicId'=>$p['experiment_public_id'],
      'retrainingBatchPublicId'=>$p['batch_public_id'],'miningRunPublicId'=>$p['mining_run_public_id'],
      'championPublicId'=>$p['champion_public_id'],'challengerPublicId'=>$p['challenger_public_id'],
      'scope'=>['locationId'=>$p['location_id']!==null?(int)$p['location_id']:null,'stationPublicId'=>$p['station_public_id']],
      'rollout'=>$p['rollout_public_id']!==null?['publicId'=>$p['rollout_public_id'],'status'=>$p['rollout_status'],'canaryPercent'=>(float)$p['rollout_canary_percent']]:null,
      'audit'=>json_decode((string)$p['audit_json'],true)?:[],'integrity'=>glasses_vision_promotion_verify($pdo,$org,$p),
      'events'=>$events,'authorizedAt'=>$p['authorized_at'],'rolloutCreatedAt'=>$p['rollout_created_at'],
    ];
}

function glasses_vision_promotion_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_promotion_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_PROMOTION_SCHEMA,'promotions'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_model_promotions WHERE organization_id=? ORDER BY id DESC LIMIT 50");$q->execute([$org]);
    $out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$out[]=glasses_vision_promotion_row($pdo,$org,(string)$p);
    return ['ready'=>true,'schema'=>GLASSES_VISION_PROMOTION_SCHEMA,'promotions'=>$out];
}
