<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-curation.php';
require_once __DIR__.'/glasses-v11-mining.php';

const GLASSES_V11_DATASET_ASSEMBLY_SCHEMA='gelato.vision_v11_dataset_assembly.v1';

function glasses_v11_dataset_assembly_ready(PDO $pdo): bool {
  foreach([
    ['glasses_vision_dataset_versions','assembly_hash'],
    ['glasses_vision_dataset_items','source_media_id'],
    ['glasses_vision_dataset_items','source_mined_candidate_id'],
    ['glasses_vision_dataset_items','training_value_score'],
    ['glasses_vision_dataset_items','provenance_json']
  ] as [$table,$column]){
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$table,$column]);if((int)$q->fetchColumn()!==1)return false;
  }
  return glasses_vision_curation_ready($pdo)&&glasses_v11_mining_ready($pdo);
}

function glasses_v11_dataset_assembly_policy(array $in=[]): array {
  return [
    'requireEligible'=>true,
    'requireApproved'=>true,
    'excludePoorMedia'=>($in['excludePoorMedia']??true)!==false,
    'minimumHardExampleScore'=>max(0,min(100,(int)($in['minimumHardExampleScore']??0))),
    'maxSamples'=>max(3,min(5000,(int)($in['maxSamples']??500))),
    'maxPerClass'=>max(1,min(1000,(int)($in['maxPerClass']??150))),
    'maxPerOperator'=>max(1,min(1000,(int)($in['maxPerOperator']??100))),
    'maxPerDevice'=>max(1,min(1000,(int)($in['maxPerDevice']??150))),
    'maxPerLocation'=>max(1,min(2000,(int)($in['maxPerLocation']??300))),
    'maxPerStation'=>max(1,min(2000,(int)($in['maxPerStation']??250))),
    'dedupeExactSha'=>true,
    'dedupePerceptualHash'=>true,
    'dedupeCaptureGroup'=>true,
    'splitPolicy'=>[
      'captureGroup'=>true,'buildSession'=>true,'operator'=>true,'location'=>true,'station'=>true,'menuItem'=>false,
      'seed'=>max(0,(int)($in['seed']??74)),
      'trainRatio'=>(float)($in['trainRatio']??0.70),'valRatio'=>(float)($in['valRatio']??0.15),'testRatio'=>(float)($in['testRatio']??0.15)
    ]
  ];
}

function glasses_v11_dataset_assembly_candidates(PDO $pdo,int $org,array $policy): array {
  $q=$pdo->prepare("SELECT
      s.id sample_id,s.public_id sample_public_id,s.canonical_label,s.review_status,
      m.id media_id,m.public_id media_public_id,m.device_id,m.operator_user_id,m.build_session_id,m.capture_group,m.sha256,m.perceptual_hash,m.quality_state,m.training_eligibility,m.training_session_id,
      ts.location_id,ts.station_id,d.public_id device_public_id,
      mc.id mined_candidate_id,mc.public_id mined_candidate_public_id,mc.score hard_score,mc.status hard_status
    FROM glasses_vision_training_samples s
    JOIN glasses_vision_training_media m ON m.sample_id=s.id AND m.organization_id=s.organization_id AND m.status='active'
    LEFT JOIN glasses_training_sessions ts ON ts.id=m.training_session_id
    LEFT JOIN glasses_devices d ON d.id=m.device_id
    LEFT JOIN glasses_vision_mined_candidates mc ON mc.id=(
      SELECT x.id FROM glasses_vision_mined_candidates x
      WHERE x.organization_id=m.organization_id AND x.media_id=m.id AND x.status<>'dismissed'
      ORDER BY x.score DESC,x.id DESC LIMIT 1
    )
    WHERE s.organization_id=? AND m.training_session_id IS NOT NULL
      AND (?=0 OR m.training_eligibility='eligible')
      AND (?=0 OR s.review_status='approved')
      AND (?=0 OR m.quality_state<>'poor')
      AND COALESCE(mc.score,0)>=?
    ORDER BY COALESCE(mc.score,0) DESC,s.updated_at DESC,m.id DESC");
  $q->execute([
    $org,$policy['requireEligible']?1:0,$policy['requireApproved']?1:0,$policy['excludePoorMedia']?1:0,(int)$policy['minimumHardExampleScore']
  ]);
  return $q->fetchAll();
}

function glasses_v11_dataset_assembly_select(array $rows,array $policy): array {
  $selected=[];$seenSample=[];$seenSha=[];$seenPhash=[];$seenCapture=[];
  $class=[];$operator=[];$device=[];$location=[];$station=[];$suppressed=[];
  foreach($rows as $r){
    $sid=(int)$r['sample_id'];if(isset($seenSample[$sid])){$suppressed[]=['samplePublicId'=>$r['sample_public_id'],'reason'=>'duplicate_sample'];continue;}
    $sha=(string)$r['sha256'];$ph=(string)($r['perceptual_hash']??'');$capture=(string)($r['capture_group']??'');
    if($policy['dedupeExactSha']&&isset($seenSha[$sha])){$suppressed[]=['samplePublicId'=>$r['sample_public_id'],'reason'=>'exact_sha_duplicate'];continue;}
    if($policy['dedupePerceptualHash']&&$ph!==''&&isset($seenPhash[$ph])){$suppressed[]=['samplePublicId'=>$r['sample_public_id'],'reason'=>'perceptual_duplicate'];continue;}
    if($policy['dedupeCaptureGroup']&&$capture!==''&&isset($seenCapture[$capture])){$suppressed[]=['samplePublicId'=>$r['sample_public_id'],'reason'=>'capture_group_duplicate'];continue;}
    $label=(string)($r['canonical_label']??'(negative)');$op=(string)((int)($r['operator_user_id']??0));$dev=(string)((int)($r['device_id']??0));$loc=(string)((int)($r['location_id']??0));$st=(string)((int)($r['station_id']??0));
    foreach([
      ['key'=>$label,'counts'=>&$class,'max'=>$policy['maxPerClass'],'reason'=>'class_cap'],
      ['key'=>$op,'counts'=>&$operator,'max'=>$policy['maxPerOperator'],'reason'=>'operator_cap'],
      ['key'=>$dev,'counts'=>&$device,'max'=>$policy['maxPerDevice'],'reason'=>'device_cap'],
      ['key'=>$loc,'counts'=>&$location,'max'=>$policy['maxPerLocation'],'reason'=>'location_cap'],
      ['key'=>$st,'counts'=>&$station,'max'=>$policy['maxPerStation'],'reason'=>'station_cap']
    ] as &$cap){
      if(($cap['counts'][$cap['key']]??0)>=$cap['max']){$suppressed[]=['samplePublicId'=>$r['sample_public_id'],'reason'=>$cap['reason']];continue 2;}
    } unset($cap);
    $selected[]=$r;$seenSample[$sid]=true;$seenSha[$sha]=true;if($ph!=='')$seenPhash[$ph]=true;if($capture!=='')$seenCapture[$capture]=true;
    $class[$label]=($class[$label]??0)+1;$operator[$op]=($operator[$op]??0)+1;$device[$dev]=($device[$dev]??0)+1;$location[$loc]=($location[$loc]??0)+1;$station[$st]=($station[$st]??0)+1;
    if(count($selected)>=(int)$policy['maxSamples'])break;
  }
  return ['selected'=>$selected,'suppressed'=>$suppressed,'counts'=>['class'=>$class,'operator'=>$operator,'device'=>$device,'location'=>$location,'station'=>$station]];
}

function glasses_v11_dataset_assemble(PDO $pdo,int $org,array $in,int $actor): array {
  if(!glasses_v11_dataset_assembly_ready($pdo))throw new RuntimeException('V11 dataset assembly migration is not installed.');
  $name=mb_substr(trim((string)($in['name']??'')),0,190,'UTF-8');$version=mb_substr(trim((string)($in['versionLabel']??'')),0,80,'UTF-8');
  if($name===''||$version==='')throw new InvalidArgumentException('Dataset name and version are required.');
  $policy=glasses_v11_dataset_assembly_policy(is_array($in['policy']??null)?$in['policy']:[]);
  $rows=glasses_v11_dataset_assembly_candidates($pdo,$org,$policy);$selection=glasses_v11_dataset_assembly_select($rows,$policy);
  if(count($selection['selected'])<3)throw new InvalidArgumentException('V11 assembly requires at least three independent eligible approved samples.');

  return glasses_transaction($pdo,function()use($pdo,$org,$name,$version,$policy,$selection,$actor):array{
    $dataset=glasses_vision_lab_create_dataset($pdo,$org,['name'=>$name,'versionLabel'=>$version],$actor);
    $datasetRow=glasses_vision_curation_dataset($pdo,$org,$dataset['publicId'],true);
    $items=[];$add=$pdo->prepare("SELECT id FROM glasses_vision_dataset_items WHERE organization_id=? AND dataset_id=? AND sample_id=? LIMIT 1");
    foreach($selection['selected'] as $r){
      glasses_vision_lab_add_dataset_sample($pdo,$org,$dataset['publicId'],(string)$r['sample_public_id'],'train');
      $add->execute([$org,(int)$datasetRow['id'],(int)$r['sample_id']]);$itemId=(int)$add->fetchColumn();
      $provenance=[
        'schema'=>GLASSES_V11_DATASET_ASSEMBLY_SCHEMA,'mediaPublicId'=>$r['media_public_id'],'minedCandidatePublicId'=>$r['mined_candidate_public_id'],
        'trainingSessionId'=>$r['training_session_id']!==null?(int)$r['training_session_id']:null,'operatorUserId'=>$r['operator_user_id']!==null?(int)$r['operator_user_id']:null,
        'devicePublicId'=>$r['device_public_id'],'locationId'=>$r['location_id']!==null?(int)$r['location_id']:null,'stationId'=>$r['station_id']!==null?(int)$r['station_id']:null,
        'captureGroup'=>$r['capture_group'],'sha256'=>$r['sha256'],'perceptualHash'=>$r['perceptual_hash'],'qualityState'=>$r['quality_state'],
        'trainingEligibility'=>$r['training_eligibility'],'hardExampleScore'=>$r['hard_score']!==null?(int)$r['hard_score']:0
      ];
      $pdo->prepare("UPDATE glasses_vision_dataset_items SET source_media_id=?,source_mined_candidate_id=?,training_value_score=?,eligibility_snapshot=?,provenance_json=? WHERE organization_id=? AND id=?")
        ->execute([(int)$r['media_id'],$r['mined_candidate_id']!==null?(int)$r['mined_candidate_id']:null,$r['hard_score']!==null?(int)$r['hard_score']:0,$r['training_eligibility'],json_encode($provenance,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$org,$itemId]);
      $pdo->prepare("INSERT INTO glasses_vision_dataset_curation_events (organization_id,dataset_id,sample_id,decision,reason,source,actor_user_id) VALUES (?,?,?,'include','V11 governed assembly','v11_assembly',?)")
        ->execute([$org,(int)$datasetRow['id'],(int)$r['sample_id'],$actor]);
      $items[]=['samplePublicId'=>$r['sample_public_id'],'mediaPublicId'=>$r['media_public_id'],'hardExampleScore'=>$r['hard_score']!==null?(int)$r['hard_score']:0,'sha256'=>$r['sha256']];
    }
    usort($items,static fn($a,$b)=>strcmp($a['samplePublicId'],$b['samplePublicId']));
    $manifest=['schema'=>GLASSES_V11_DATASET_ASSEMBLY_SCHEMA,'datasetPublicId'=>$dataset['publicId'],'policy'=>$policy,'items'=>$items,'suppressed'=>$selection['suppressed'],'balance'=>$selection['counts']];
    $hash=hash('sha256',glasses_vision_training_release_json($manifest));
    $pdo->prepare("UPDATE glasses_vision_dataset_versions SET assembly_policy_json=?,assembly_manifest_json=?,assembly_hash=?,assembled_by=?,assembled_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")
      ->execute([json_encode($policy,JSON_UNESCAPED_SLASHES),json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$hash,$actor,$org,(int)$datasetRow['id']]);

    $sp=$policy['splitPolicy'];
    $plan=glasses_vision_curation_create_split_plan($pdo,$org,$dataset['publicId'],[
      'captureGroup'=>$sp['captureGroup'],'buildSession'=>$sp['buildSession'],'operator'=>$sp['operator'],'location'=>$sp['location'],'station'=>$sp['station'],'menuItem'=>$sp['menuItem'],
      'seed'=>$sp['seed'],'trainRatio'=>$sp['trainRatio'],'valRatio'=>$sp['valRatio'],'testRatio'=>$sp['testRatio']
    ],$actor);
    glasses_vision_curation_apply_split_plan($pdo,$org,$plan['publicId'],$actor);
    return glasses_v11_dataset_assembly_get($pdo,$org,$dataset['publicId']);
  });
}

function glasses_v11_dataset_assembly_get(PDO $pdo,int $org,string $datasetPublic): array {
  $q=$pdo->prepare("SELECT d.* FROM glasses_vision_dataset_versions d WHERE d.organization_id=? AND d.public_id=? LIMIT 1");$q->execute([$org,$datasetPublic]);$d=$q->fetch();
  if(!$d)throw new InvalidArgumentException('Vision dataset was not found.');
  $iq=$pdo->prepare("SELECT i.split_name,i.class_label,i.training_value_score,i.eligibility_snapshot,i.provenance_json,s.public_id sample_public_id,m.public_id media_public_id,mc.public_id mined_candidate_public_id
    FROM glasses_vision_dataset_items i JOIN glasses_vision_training_samples s ON s.id=i.sample_id
    LEFT JOIN glasses_vision_training_media m ON m.id=i.source_media_id
    LEFT JOIN glasses_vision_mined_candidates mc ON mc.id=i.source_mined_candidate_id
    WHERE i.organization_id=? AND i.dataset_id=? ORDER BY s.public_id");
  $iq->execute([$org,(int)$d['id']]);
  $items=array_map(static fn($r)=>[
    'samplePublicId'=>$r['sample_public_id'],'mediaPublicId'=>$r['media_public_id'],'minedCandidatePublicId'=>$r['mined_candidate_public_id'],
    'split'=>$r['split_name'],'classLabel'=>$r['class_label'],'trainingValueScore'=>$r['training_value_score']!==null?(int)$r['training_value_score']:null,
    'eligibilitySnapshot'=>$r['eligibility_snapshot'],'provenance'=>json_decode((string)($r['provenance_json']??'{}'),true)?:[]
  ],$iq->fetchAll());
  return [
    'schema'=>GLASSES_V11_DATASET_ASSEMBLY_SCHEMA,'publicId'=>$d['public_id'],'name'=>$d['name'],'versionLabel'=>$d['version_label'],'status'=>$d['status'],
    'assemblyHash'=>$d['assembly_hash'],'policy'=>json_decode((string)($d['assembly_policy_json']??'{}'),true)?:[],
    'manifest'=>json_decode((string)($d['assembly_manifest_json']??'{}'),true)?:[],'assembledAt'=>$d['assembled_at'],'items'=>$items,
    'coverage'=>glasses_vision_lab_dataset_coverage($pdo,$org,$datasetPublic),'splitPlans'=>glasses_vision_curation_split_plans($pdo,$org,$datasetPublic)
  ];
}

function glasses_v11_dataset_freeze_guard(PDO $pdo,int $org,string $datasetPublic): void {
  $d=glasses_vision_curation_dataset($pdo,$org,$datasetPublic);
  if($d['status']==='frozen')return;
  if(empty($d['assembly_hash']))return;
  $q=$pdo->prepare("SELECT COUNT(*) FROM glasses_vision_dataset_items WHERE organization_id=? AND dataset_id=? AND eligibility_snapshot<>'eligible'");
  $q->execute([$org,(int)$d['id']]);if((int)$q->fetchColumn()>0)throw new InvalidArgumentException('V11 dataset freeze blocked: every assembled item must remain eligibility-approved at assembly time.');
  $q=$pdo->prepare("SELECT COUNT(DISTINCT split_name) FROM glasses_vision_dataset_items WHERE organization_id=? AND dataset_id=?");$q->execute([$org,(int)$d['id']]);
  if((int)$q->fetchColumn()<3)throw new InvalidArgumentException('V11 dataset freeze blocked: train, validation and test splits are required.');
}

function glasses_v11_dataset_catalog(PDO $pdo,int $org): array {
  if(!glasses_v11_dataset_assembly_ready($pdo))return ['ready'=>false,'datasets'=>[]];
  $q=$pdo->prepare("SELECT public_id FROM glasses_vision_dataset_versions WHERE organization_id=? AND assembly_hash IS NOT NULL ORDER BY id DESC LIMIT 100");$q->execute([$org]);
  return ['ready'=>true,'schema'=>GLASSES_V11_DATASET_ASSEMBLY_SCHEMA,'datasets'=>array_map(fn($p)=>glasses_v11_dataset_assembly_get($pdo,$org,(string)$p),$q->fetchAll(PDO::FETCH_COLUMN))];
}
