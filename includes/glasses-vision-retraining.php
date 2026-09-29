<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-mining.php';
require_once __DIR__.'/glasses-vision-training-media.php';
require_once __DIR__.'/glasses-vision-lab.php';

const GLASSES_VISION_RETRAINING_SCHEMA='gelato.vision_retraining_candidate_batch.v1';

function glasses_vision_retraining_ready(PDO $pdo): bool
{
    foreach(['glasses_vision_retraining_batches','glasses_vision_retraining_batch_items'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;
    }
    return glasses_vision_mining_ready($pdo)&&glasses_vision_training_media_ready($pdo);
}

function glasses_vision_retraining_policy(array $requested=[]): array
{
    return [
      'maxCandidates'=>max(1,min(500,(int)($requested['maxCandidates']??100))),
      'requireApprovedSample'=>true,
      'requireActiveOptInMedia'=>true,
      'rejectPoorMedia'=>true,
      'dedupeExactSha'=>true,
      'dedupeCaptureGroup'=>true,
    ];
}

function glasses_vision_retraining_run(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_vision_mining_runs WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,trim($publicId)]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision mining run was not found.');
    return $row;
}

function glasses_vision_retraining_source(PDO $pdo,int $org,array $candidate): array
{
    $errorQ=$pdo->prepare("SELECT * FROM glasses_vision_production_errors WHERE organization_id=? AND id=? LIMIT 1");
    $errorQ->execute([$org,(int)$candidate['production_error_id']]);$error=$errorQ->fetch();
    if(!$error)throw new RuntimeException('Mined candidate production error is missing.');

    $snapshot=json_decode((string)$error['context_json'],true)?:[];
    $extra=is_array($snapshot['context']??null)?$snapshot['context']:[];
    $sample=null;
    if($error['observation_id']!==null){
        $sq=$pdo->prepare("SELECT * FROM glasses_vision_training_samples WHERE organization_id=? AND observation_id=? LIMIT 1");
        $sq->execute([$org,(int)$error['observation_id']]);$sample=$sq->fetch()?:null;
    }
    if(!$sample&&!empty($extra['samplePublicId'])){
        $sq=$pdo->prepare("SELECT * FROM glasses_vision_training_samples WHERE organization_id=? AND public_id=? LIMIT 1");
        $sq->execute([$org,(string)$extra['samplePublicId']]);$sample=$sq->fetch()?:null;
    }

    $media=null;
    if(!empty($extra['mediaPublicId'])){
        $mq=$pdo->prepare("SELECT * FROM glasses_vision_training_media WHERE organization_id=? AND public_id=? LIMIT 1");
        $mq->execute([$org,(string)$extra['mediaPublicId']]);$media=$mq->fetch()?:null;
        if($media&&$sample&&(int)($media['sample_id']??0)!==(int)$sample['id'])throw new InvalidArgumentException('Production-error media does not belong to its governed sample.');
    }
    if(!$media&&$sample){
        $mq=$pdo->prepare("SELECT * FROM glasses_vision_training_media
          WHERE organization_id=? AND sample_id=? AND status='active'
          ORDER BY FIELD(quality_state,'good','warning','review','poor'),created_at,id LIMIT 1");
        $mq->execute([$org,(int)$sample['id']]);$media=$mq->fetch()?:null;
    }

    $eligibility='eligible';$reason=null;
    if(!$sample){$eligibility='ineligible';$reason='missing_training_sample';}
    elseif((string)$sample['review_status']!=='approved'){$eligibility='ineligible';$reason='sample_not_approved';}
    elseif(!$media){$eligibility='ineligible';$reason='missing_active_training_media';}
    elseif((string)$media['status']!=='active'){$eligibility='ineligible';$reason='training_media_not_active';}
    elseif((string)$media['consent_basis']!=='training_media_opt_in'){$eligibility='ineligible';$reason='training_media_not_opted_in';}
    elseif((string)$media['quality_state']==='poor'){$eligibility='ineligible';$reason='poor_training_media';}

    return ['error'=>$error,'sample'=>$sample,'media'=>$media,'eligibility'=>$eligibility,'reason'=>$reason];
}

function glasses_vision_retraining_prepare(PDO $pdo,int $org,string $miningRunPublic,array $requested,int $actor): array
{
    if(!glasses_vision_retraining_ready($pdo))throw new RuntimeException('Vision Lab V7 retraining-candidate migration is not installed.');
    $run=glasses_vision_retraining_run($pdo,$org,$miningRunPublic);
    $policy=glasses_vision_retraining_policy($requested);
    $q=$pdo->prepare("SELECT c.*,e.public_id production_error_public_id,e.event_hash,p.public_id model_package_public_id
      FROM glasses_vision_mined_candidates c
      JOIN glasses_vision_production_errors e ON e.id=c.production_error_id AND e.organization_id=c.organization_id
      LEFT JOIN glasses_vision_model_packages p ON p.id=c.model_package_id AND p.organization_id=c.organization_id
      WHERE c.organization_id=? AND c.mining_run_id=? AND c.status='open'
      ORDER BY c.score DESC,c.id LIMIT ".$policy['maxCandidates']);
    $q->execute([$org,(int)$run['id']]);$rows=$q->fetchAll();

    $seenSha=[];$seenCapture=[];$items=[];
    foreach($rows as $candidate){
        $source=glasses_vision_retraining_source($pdo,$org,$candidate);
        $sample=$source['sample'];$media=$source['media'];$eligibility=$source['eligibility'];$reason=$source['reason'];
        if($eligibility==='eligible'&&$media){
            $sha=(string)$media['sha256'];$capture=trim((string)($media['capture_group']??''));
            if(isset($seenSha[$sha])){$eligibility='ineligible';$reason='duplicate_media_sha256';}
            elseif($capture!==''&&isset($seenCapture[$capture])){$eligibility='ineligible';$reason='duplicate_capture_group';}
            else{$seenSha[$sha]=true;if($capture!=='')$seenCapture[$capture]=true;}
        }
        $evidence=[
          'candidatePublicId'=>$candidate['public_id'],'candidateHash'=>$candidate['candidate_hash'],
          'productionErrorPublicId'=>$candidate['production_error_public_id'],'productionErrorHash'=>$candidate['event_hash'],
          'samplePublicId'=>$sample['public_id']??null,'sampleReviewStatus'=>$sample['review_status']??null,
          'mediaPublicId'=>$media['public_id']??null,'mediaSha256'=>$media['sha256']??null,
          'captureGroup'=>$media['capture_group']??null,'qualityState'=>$media['quality_state']??null,
          'consentBasis'=>$media['consent_basis']??null,'eligibility'=>$eligibility,'eligibilityReason'=>$reason,
        ];
        $items[]=[
          'candidateId'=>(int)$candidate['id'],'candidatePublicId'=>$candidate['public_id'],
          'productionErrorId'=>(int)$candidate['production_error_id'],'productionErrorPublicId'=>$candidate['production_error_public_id'],
          'sampleId'=>$sample?(int)$sample['id']:null,'samplePublicId'=>$sample['public_id']??null,
          'mediaId'=>$media?(int)$media['id']:null,'mediaPublicId'=>$media['public_id']??null,
          'eligibility'=>$eligibility,'eligibilityReason'=>$reason,
          'decision'=>$eligibility==='eligible'?'pending':'exclude',
          'decisionReason'=>$eligibility==='eligible'?null:$reason,
          'evidence'=>$evidence,'evidenceHash'=>hash('sha256',glasses_vision_training_release_json($evidence)),
        ];
    }
    $manifest=[
      'schema'=>GLASSES_VISION_RETRAINING_SCHEMA,'miningRunPublicId'=>$run['public_id'],'miningRunHash'=>$run['run_hash'],
      'policy'=>$policy,'items'=>array_map(static fn($i)=>[
        'candidatePublicId'=>$i['candidatePublicId'],'productionErrorPublicId'=>$i['productionErrorPublicId'],
        'samplePublicId'=>$i['samplePublicId'],'mediaPublicId'=>$i['mediaPublicId'],'eligibility'=>$i['eligibility'],
        'eligibilityReason'=>$i['eligibilityReason'],'evidenceHash'=>$i['evidenceHash']
      ],$items),
      'governance'=>['requiresExplicitReview'=>true,'automaticDatasetInclusion'=>false,'automaticTraining'=>false,'automaticRollout'=>false],
    ];
    $batchHash=hash('sha256',glasses_vision_training_release_json($manifest));
    $existing=$pdo->prepare("SELECT public_id FROM glasses_vision_retraining_batches WHERE organization_id=? AND batch_hash=? LIMIT 1");
    $existing->execute([$org,$batchHash]);$public=$existing->fetchColumn();
    if($public!==false)return glasses_vision_retraining_batch($pdo,$org,(string)$public);

    return glasses_transaction($pdo,function()use($pdo,$org,$actor,$run,$policy,$manifest,$batchHash,$items):array{
        $public=glasses_public_id('vision-retraining-batch');
        $pdo->prepare("INSERT INTO glasses_vision_retraining_batches
          (organization_id,public_id,mining_run_id,status,policy_json,manifest_json,batch_hash,created_by)
          VALUES (?,?,?,'draft',?,?,?,?)")
          ->execute([$org,$public,(int)$run['id'],json_encode($policy,JSON_UNESCAPED_SLASHES),json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$batchHash,$actor]);
        $batchId=(int)$pdo->lastInsertId();
        $insert=$pdo->prepare("INSERT INTO glasses_vision_retraining_batch_items
          (organization_id,batch_id,mined_candidate_id,production_error_id,training_sample_id,training_media_id,eligibility_status,eligibility_reason,decision,decision_reason,evidence_hash)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach($items as $i)$insert->execute([$org,$batchId,$i['candidateId'],$i['productionErrorId'],$i['sampleId'],$i['mediaId'],$i['eligibility'],$i['eligibilityReason'],$i['decision'],$i['decisionReason'],$i['evidenceHash']]);
        return glasses_vision_retraining_batch($pdo,$org,$public);
    });
}

function glasses_vision_retraining_item_public(array $r): array
{
    return [
      'publicId'=>$r['public_id'],'candidatePublicId'=>$r['candidate_public_id'],'candidateHash'=>$r['candidate_hash'],
      'productionErrorPublicId'=>$r['production_error_public_id'],'productionErrorHash'=>$r['event_hash'],
      'samplePublicId'=>$r['sample_public_id'],'sampleReviewStatus'=>$r['sample_review_status'],
      'mediaPublicId'=>$r['media_public_id'],'mediaSha256'=>$r['media_sha256'],'captureGroup'=>$r['capture_group'],
      'qualityState'=>$r['quality_state'],'eligibility'=>$r['eligibility_status'],'eligibilityReason'=>$r['eligibility_reason'],
      'decision'=>$r['decision'],'decisionReason'=>$r['decision_reason'],'evidenceHash'=>$r['evidence_hash'],
      'reviewedAt'=>$r['reviewed_at'],
    ];
}

function glasses_vision_retraining_items(PDO $pdo,int $org,int $batchId): array
{
    $q=$pdo->prepare("SELECT i.*,CONCAT('vision-retraining-item-',i.id) public_id,
      c.public_id candidate_public_id,c.candidate_hash,e.public_id production_error_public_id,e.event_hash,
      s.public_id sample_public_id,s.review_status sample_review_status,m.public_id media_public_id,m.sha256 media_sha256,m.capture_group,m.quality_state
      FROM glasses_vision_retraining_batch_items i
      JOIN glasses_vision_mined_candidates c ON c.id=i.mined_candidate_id AND c.organization_id=i.organization_id
      JOIN glasses_vision_production_errors e ON e.id=i.production_error_id AND e.organization_id=i.organization_id
      LEFT JOIN glasses_vision_training_samples s ON s.id=i.training_sample_id AND s.organization_id=i.organization_id
      LEFT JOIN glasses_vision_training_media m ON m.id=i.training_media_id AND m.organization_id=i.organization_id
      WHERE i.organization_id=? AND i.batch_id=? ORDER BY c.score DESC,i.id");
    $q->execute([$org,$batchId]);return array_map('glasses_vision_retraining_item_public',$q->fetchAll());
}

function glasses_vision_retraining_batch(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT b.*,r.public_id mining_run_public_id,r.run_hash,d.public_id dataset_public_id
      FROM glasses_vision_retraining_batches b
      JOIN glasses_vision_mining_runs r ON r.id=b.mining_run_id AND r.organization_id=b.organization_id
      LEFT JOIN glasses_vision_dataset_versions d ON d.id=b.dataset_id AND d.organization_id=b.organization_id
      WHERE b.organization_id=? AND b.public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Retraining candidate batch was not found.');
    $items=glasses_vision_retraining_items($pdo,$org,(int)$r['id']);
    $counts=['eligible'=>0,'ineligible'=>0,'pending'=>0,'include'=>0,'exclude'=>0];
    foreach($items as $i){$counts[$i['eligibility']==='eligible'?'eligible':'ineligible']++;if(isset($counts[$i['decision']]))$counts[$i['decision']]++;}
    return [
      'schema'=>GLASSES_VISION_RETRAINING_SCHEMA,'publicId'=>$r['public_id'],'status'=>$r['status'],
      'miningRunPublicId'=>$r['mining_run_public_id'],'miningRunHash'=>$r['run_hash'],'batchHash'=>$r['batch_hash'],
      'policy'=>json_decode((string)$r['policy_json'],true)?:[],'manifest'=>json_decode((string)$r['manifest_json'],true)?:[],
      'datasetPublicId'=>$r['dataset_public_id'],'counts'=>$counts,'items'=>$items,'createdAt'=>$r['created_at'],'builtAt'=>$r['built_at'],
    ];
}

function glasses_vision_retraining_review(PDO $pdo,int $org,string $batchPublic,string $itemPublic,string $decision,string $reason,int $actor): array
{
    $decision=strtolower(trim($decision));if(!in_array($decision,['include','exclude'],true))throw new InvalidArgumentException('Retraining candidate review decision is invalid.');
    $reason=mb_substr(trim($reason),0,1000,'UTF-8');if($decision==='exclude'&&$reason==='')throw new InvalidArgumentException('Exclusion reason is required.');
    return glasses_transaction($pdo,function()use($pdo,$org,$batchPublic,$itemPublic,$decision,$reason,$actor):array{
        $bq=$pdo->prepare("SELECT * FROM glasses_vision_retraining_batches WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $bq->execute([$org,$batchPublic]);$batch=$bq->fetch();if(!$batch)throw new InvalidArgumentException('Retraining candidate batch was not found.');
        if((string)$batch['status']!=='draft')throw new InvalidArgumentException('Built retraining batches are immutable.');
        if(!preg_match('/^vision-retraining-item-(\d+)$/',$itemPublic,$m))throw new InvalidArgumentException('Retraining candidate item is invalid.');
        $iq=$pdo->prepare("SELECT i.*,s.review_status,m.status media_status,m.consent_basis,m.quality_state
          FROM glasses_vision_retraining_batch_items i
          LEFT JOIN glasses_vision_training_samples s ON s.id=i.training_sample_id AND s.organization_id=i.organization_id
          LEFT JOIN glasses_vision_training_media m ON m.id=i.training_media_id AND m.organization_id=i.organization_id
          WHERE i.organization_id=? AND i.batch_id=? AND i.id=? LIMIT 1 FOR UPDATE");
        $iq->execute([$org,(int)$batch['id'],(int)$m[1]]);$item=$iq->fetch();if(!$item)throw new InvalidArgumentException('Retraining candidate item was not found.');
        if($decision==='include'){
            if((string)$item['eligibility_status']!=='eligible')throw new InvalidArgumentException('Only eligible retraining candidates may be included.');
            if((string)$item['review_status']!=='approved')throw new InvalidArgumentException('Included retraining sample is no longer approved.');
            if((string)$item['media_status']!=='active'||(string)$item['consent_basis']!=='training_media_opt_in'||(string)$item['quality_state']==='poor')
                throw new InvalidArgumentException('Included retraining media is no longer eligible.');
        }
        $pdo->prepare("UPDATE glasses_vision_retraining_batch_items SET decision=?,decision_reason=?,reviewed_by=?,reviewed_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([$decision,$reason!==''?$reason:null,$actor,$org,(int)$item['id']]);
        return glasses_vision_retraining_batch($pdo,$org,$batchPublic);
    });
}

function glasses_vision_retraining_build_dataset(PDO $pdo,int $org,string $batchPublic,string $name,string $version,int $actor): array
{
    $name=mb_substr(trim($name),0,190,'UTF-8');$version=mb_substr(trim($version),0,80,'UTF-8');
    if($name===''||$version==='')throw new InvalidArgumentException('Candidate dataset name and version are required.');
    return glasses_transaction($pdo,function()use($pdo,$org,$batchPublic,$name,$version,$actor):array{
        $q=$pdo->prepare("SELECT * FROM glasses_vision_retraining_batches WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$batchPublic]);$batch=$q->fetch();if(!$batch)throw new InvalidArgumentException('Retraining candidate batch was not found.');
        if((string)$batch['status']==='built'){
            $dq=$pdo->prepare("SELECT public_id FROM glasses_vision_dataset_versions WHERE organization_id=? AND id=? LIMIT 1");$dq->execute([$org,(int)$batch['dataset_id']]);
            return ['batch'=>glasses_vision_retraining_batch($pdo,$org,$batchPublic),'dataset'=>glasses_vision_lab_dataset($pdo,$org,(string)$dq->fetchColumn())];
        }
        $iq=$pdo->prepare("SELECT i.*,s.public_id sample_public_id,s.review_status,m.status media_status,m.consent_basis,m.quality_state
          FROM glasses_vision_retraining_batch_items i
          LEFT JOIN glasses_vision_training_samples s ON s.id=i.training_sample_id AND s.organization_id=i.organization_id
          LEFT JOIN glasses_vision_training_media m ON m.id=i.training_media_id AND m.organization_id=i.organization_id
          WHERE i.organization_id=? AND i.batch_id=? ORDER BY i.id FOR UPDATE");
        $iq->execute([$org,(int)$batch['id']]);$items=$iq->fetchAll();
        $included=[];$pending=0;
        foreach($items as $i){
            if((string)$i['eligibility_status']==='eligible'&&(string)$i['decision']==='pending')$pending++;
            if((string)$i['decision']!=='include')continue;
            if((string)$i['eligibility_status']!=='eligible'||(string)$i['review_status']!=='approved'||(string)$i['media_status']!=='active'||
               (string)$i['consent_basis']!=='training_media_opt_in'||(string)$i['quality_state']==='poor')
                throw new InvalidArgumentException('An included retraining candidate is no longer eligible.');
            $included[]=$i;
        }
        if($pending>0)throw new InvalidArgumentException('Every eligible retraining candidate must be explicitly reviewed before building a dataset.');
        if(!$included)throw new InvalidArgumentException('At least one eligible retraining candidate must be included.');
        $dataset=glasses_vision_lab_create_dataset($pdo,$org,['name'=>$name,'versionLabel'=>$version],$actor);
        foreach($included as $i)$dataset=glasses_vision_lab_add_dataset_sample($pdo,$org,(string)$dataset['publicId'],(string)$i['sample_public_id'],'train');
        $dq=$pdo->prepare("SELECT id FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=? LIMIT 1");$dq->execute([$org,$dataset['publicId']]);$datasetId=(int)$dq->fetchColumn();
        $pdo->prepare("UPDATE glasses_vision_retraining_batches SET status='built',dataset_id=?,built_by=?,built_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([$datasetId,$actor,$org,(int)$batch['id']]);
        glasses_vision_lineage_edge($pdo,$org,'mining_run',(string)glasses_vision_retraining_run($pdo,$org,(string)glasses_vision_retraining_batch($pdo,$org,$batchPublic)['miningRunPublicId'])['public_id'],(string)glasses_vision_retraining_batch($pdo,$org,$batchPublic)['miningRunHash'],'built_candidate_dataset','dataset',(string)$dataset['publicId'],null,['retrainingBatchPublicId'=>$batchPublic,'includedSamples'=>count($included)],$actor);
        return ['batch'=>glasses_vision_retraining_batch($pdo,$org,$batchPublic),'dataset'=>$dataset];
    });
}

function glasses_vision_retraining_catalog(PDO $pdo,int $org): array
{
    if(!glasses_vision_retraining_ready($pdo))return ['ready'=>false,'schema'=>GLASSES_VISION_RETRAINING_SCHEMA,'batches'=>[]];
    $q=$pdo->prepare("SELECT public_id FROM glasses_vision_retraining_batches WHERE organization_id=? ORDER BY id DESC LIMIT 50");$q->execute([$org]);
    $b=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $p)$b[]=glasses_vision_retraining_batch($pdo,$org,(string)$p);
    return ['ready'=>true,'schema'=>GLASSES_VISION_RETRAINING_SCHEMA,'batches'=>$b];
}
