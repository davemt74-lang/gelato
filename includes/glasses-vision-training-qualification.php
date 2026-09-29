<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-training-release.php';

const GLASSES_VISION_TRAINING_QUALIFICATION_SCHEMA='gelato.vision_training_qualification.v1';

function glasses_vision_training_qualification_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_training_qualifications'");
    $q->execute();
    return (int)$q->fetchColumn()===1 && glasses_vision_training_release_ready($pdo);
}

function glasses_vision_training_qualification_floor(string $profile): array
{
    $common=[
        'minimumSamplesPerClass'=>20,
        'minimumNegatives'=>10,
        'minimumHardExamples'=>10,
        'minimumOperators'=>2,
        'minimumLocations'=>1,
        'minimumStations'=>1,
        'minimumDevices'=>1,
        'minimumPoseBuckets'=>3,
        'minimumLightingBuckets'=>2,
        'maximumLeakageGroups'=>0,
        'maximumUnresolvedDisagreements'=>0,
        'maximumIncompleteAnnotations'=>0,
        'maximumExactDuplicates'=>0,
        'maximumPoorMedia'=>0,
        'maximumCaptureGroupLeakage'=>0,
    ];
    return match($profile){
        'yolo11n_960','yolo11s_640'=>array_merge($common,['minimumSamplesPerClass'=>30,'minimumHardExamples'=>15]),
        default=>$common,
    };
}

function glasses_vision_training_qualification_policy(string $profile,array $requested=[]): array
{
    $floor=glasses_vision_training_qualification_floor($profile);
    foreach(['minimumSamplesPerClass','minimumNegatives','minimumHardExamples','minimumOperators','minimumLocations','minimumStations','minimumDevices','minimumPoseBuckets','minimumLightingBuckets'] as $key){
        if(array_key_exists($key,$requested))$floor[$key]=max($floor[$key],(int)$requested[$key]);
    }
    return $floor;
}

function glasses_vision_training_qualification_check(string $key,string $label,int|float $actual,int|float $required,string $operator='>='): array
{
    $passed=$operator==='>='?$actual>=$required:$actual<=$required;
    return ['key'=>$key,'label'=>$label,'actual'=>$actual,'required'=>$required,'operator'=>$operator,'passed'=>$passed];
}

function glasses_vision_training_qualification_leakage(PDO $pdo,int $org,int $planId): int
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM (
      SELECT group_key FROM glasses_vision_dataset_split_assignments
      WHERE organization_id=? AND split_plan_id=?
      GROUP BY group_key HAVING COUNT(DISTINCT split_name)>1
    ) x");
    $q->execute([$org,$planId]);return (int)$q->fetchColumn();
}

function glasses_vision_training_qualification_evaluate(PDO $pdo,int $org,string $releasePublic,array $requested=[]): array
{
    $release=glasses_vision_training_release_row($pdo,$org,$releasePublic);
    $manifest=json_decode((string)$release['manifest_json'],true)?:[];
    $profile=(string)$release['training_profile'];
    $policy=glasses_vision_training_qualification_policy($profile,$requested);
    $datasetPublic=(string)$release['dataset_public_id'];

    $balance=glasses_vision_balance_analyze($pdo,$org,$datasetPublic,[
        'targetPerClass'=>$policy['minimumSamplesPerClass'],
        'targetNegativeRatio'=>0,
        'targetHardRatio'=>0,
        'maxDominantShare'=>.95,
    ]);
    $qa=glasses_vision_annotation_qa_analyze($pdo,$org,$datasetPublic);
    $visual=glasses_vision_training_media_dataset_quality($pdo,$org,$datasetPublic);
    $leakage=glasses_vision_training_qualification_leakage($pdo,$org,(int)$release['split_plan_id']);

    $classCounts=$balance['classes']??[];
    $minimumClassCount=$classCounts?min(array_values($classCounts)):0;
    $totals=$balance['totals']??[];
    $div=$balance['diversity']??[];$media=$div['media']??[];
    $blockers=$visual['releaseBlockers']??[];
    $curationRows=array_values(array_filter(glasses_vision_curation_rows($pdo,$org,$datasetPublic),static fn($r)=>($r['curationDecision']??'include')!=='exclude'&&($r['reviewStatus']??'')==='approved'));
    $operators=[];$locations=[];$stations=[];
    foreach($curationRows as $r){
        if(($r['operatorUserId']??null)!==null)$operators[(int)$r['operatorUserId']]=true;
        if(($r['locationId']??null)!==null)$locations[(int)$r['locationId']]=true;
        if(($r['stationId']??null)!==null)$stations[(int)$r['stationId']]=true;
    }

    $checks=[
        glasses_vision_training_qualification_check('samples_per_class','Minimum samples per class',(int)$minimumClassCount,(int)$policy['minimumSamplesPerClass']),
        glasses_vision_training_qualification_check('negative_examples','Minimum negative examples',(int)($totals['negative']??0),(int)$policy['minimumNegatives']),
        glasses_vision_training_qualification_check('hard_examples','Minimum hard examples',(int)($totals['hardExamples']??0),(int)$policy['minimumHardExamples']),
        glasses_vision_training_qualification_check('operator_diversity','Operator diversity',count($operators),(int)$policy['minimumOperators']),
        glasses_vision_training_qualification_check('location_diversity','Location diversity',count($locations),(int)$policy['minimumLocations']),
        glasses_vision_training_qualification_check('station_diversity','Station diversity',count($stations),(int)$policy['minimumStations']),
        glasses_vision_training_qualification_check('device_diversity','Device diversity',(int)($media['deviceDiversity']??0),(int)$policy['minimumDevices']),
        glasses_vision_training_qualification_check('pose_diversity','Pose bucket diversity',(int)($media['poseDiversity']??0),(int)$policy['minimumPoseBuckets']),
        glasses_vision_training_qualification_check('lighting_diversity','Lighting diversity',count($media['exposureCoverage']??[]),(int)$policy['minimumLightingBuckets']),
        glasses_vision_training_qualification_check('split_leakage','Protected-group leakage',$leakage,(int)$policy['maximumLeakageGroups'],'<='),
        glasses_vision_training_qualification_check('annotation_disagreements','Unresolved annotation disagreements',(int)($qa['summary']['unresolvedDisagreements']??0),(int)$policy['maximumUnresolvedDisagreements'],'<='),
        glasses_vision_training_qualification_check('annotation_completeness','Incomplete annotations',(int)($qa['summary']['incompleteSamples']??0),(int)$policy['maximumIncompleteAnnotations'],'<='),
        glasses_vision_training_qualification_check('exact_duplicates','Exact duplicate blockers',(int)($blockers['exactDuplicates']??0),(int)$policy['maximumExactDuplicates'],'<='),
        glasses_vision_training_qualification_check('poor_media','Hard visual quality blockers',(int)($blockers['poorMedia']??0),(int)$policy['maximumPoorMedia'],'<='),
        glasses_vision_training_qualification_check('capture_group_leakage','Capture-group media leakage',(int)($blockers['captureGroupLeakage']??0),(int)$policy['maximumCaptureGroupLeakage'],'<='),
    ];
    $passedCount=count(array_filter($checks,static fn($c)=>$c['passed']));
    $score=(int)round(100*$passedCount/max(1,count($checks)));
    $passed=$passedCount===count($checks);

    $attestation=[
        'schema'=>GLASSES_VISION_TRAINING_QUALIFICATION_SCHEMA,
        'releaseHash'=>(string)$release['release_hash'],
        'datasetHash'=>(string)($manifest['dataset']['datasetHash']??''),
        'splitPlanHash'=>(string)($manifest['splitPlan']['planHash']??''),
        'trainingProfile'=>$profile,
        'policy'=>$policy,
        'checks'=>$checks,
        'score'=>$score,
        'passed'=>$passed,
    ];
    $hash=hash('sha256',glasses_vision_training_release_json($attestation));
    $attestation['qualificationHash']=$hash;
    return [
        'schema'=>GLASSES_VISION_TRAINING_QUALIFICATION_SCHEMA,
        'releasePublicId'=>$releasePublic,
        'releaseHash'=>$release['release_hash'],
        'policy'=>$policy,'checks'=>$checks,'score'=>$score,'passed'=>$passed,'qualificationHash'=>$hash,
        'attestation'=>$attestation,
        'summary'=>[
            'classCounts'=>$classCounts,
            'negativeExamples'=>(int)($totals['negative']??0),
            'hardExamples'=>(int)($totals['hardExamples']??0),
            'leakageGroups'=>$leakage,
            'unresolvedDisagreements'=>(int)($qa['summary']['unresolvedDisagreements']??0),
            'visualBlockers'=>$blockers,
        ],
    ];
}

function glasses_vision_training_qualification_attach(PDO $pdo,int $org,array $release,array $result): array
{
    if(empty($result['passed']))throw new InvalidArgumentException('Only a passing qualification may be attached to a training release.');
    $source=glasses_vision_training_release_artifact($pdo,$org,(string)$release['public_id']);
    $root=glasses_vision_training_release_storage_root().'/'.$org;
    $qhash=(string)$result['qualificationHash'];
    $relative=$org.'/'.(string)$release['public_id'].'-qualified-'.substr($qhash,0,16).'.zip';
    $target=glasses_vision_training_release_storage_root().'/'.$relative;
    $tmp=$target.'.tmp-'.bin2hex(random_bytes(4));
    if(!copy($source['path'],$tmp))throw new RuntimeException('Could not stage qualified training artifact.');
    try{
        $zip=new ZipArchive();
        if($zip->open($tmp)!==true)throw new RuntimeException('Could not open staged training release.');
        if($zip->locateName('qualification.json')!==false)$zip->deleteName('qualification.json');
        $body=json_encode(glasses_vision_training_release_canonicalize($result['attestation']),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n";
        if(!$zip->addFromString('qualification.json',$body)){ $zip->close(); throw new RuntimeException('Could not add qualification attestation.'); }
        if(method_exists($zip,'setMtimeName'))$zip->setMtimeName('qualification.json',946684800);
        if(!$zip->close())throw new RuntimeException('Could not finalize qualified training release.');
        if(!rename($tmp,$target))throw new RuntimeException('Could not publish qualified training release.');
        return ['relative'=>$relative,'path'=>$target,'sha256'=>hash_file('sha256',$target),'bytes'=>filesize($target)];
    }finally{if(is_file($tmp))@unlink($tmp);}
}

function glasses_vision_training_qualification_run(PDO $pdo,int $org,string $releasePublic,array $requested,int $actor): array
{
    if(!glasses_vision_training_qualification_ready($pdo))throw new RuntimeException('Vision Lab V6 qualification migration is not installed.');
    $release=glasses_vision_training_release_row($pdo,$org,$releasePublic);
    $result=glasses_vision_training_qualification_evaluate($pdo,$org,$releasePublic,$requested);
    $public=glasses_public_id('vision-qualification');
    $artifact=null;
    if($result['passed'])$artifact=glasses_vision_training_qualification_attach($pdo,$org,$release,$result);

    try{
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO glasses_vision_training_qualifications
          (organization_id,public_id,training_release_id,passed,score,policy_json,result_json,qualification_hash,actor_user_id)
          VALUES (?,?,?,?,?,?,?,?,?)")
          ->execute([$org,$public,(int)$release['id'],$result['passed']?1:0,(int)$result['score'],json_encode($result['policy'],JSON_UNESCAPED_SLASHES),json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(string)$result['qualificationHash'],$actor]);
        if($result['passed']){
            $pdo->prepare("UPDATE glasses_vision_training_releases SET status='qualified',artifact_relative_path=?,artifact_sha256=?,artifact_bytes=? WHERE organization_id=? AND id=?")
              ->execute([$artifact['relative'],$artifact['sha256'],$artifact['bytes'],$org,(int)$release['id']]);
        }elseif($release['status']!=='qualified'){
            $pdo->prepare("UPDATE glasses_vision_training_releases SET status='blocked' WHERE organization_id=? AND id=?")->execute([$org,(int)$release['id']]);
        }
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($artifact&&is_file($artifact['path']))@unlink($artifact['path']);
        throw $e;
    }
    if($artifact){
        $sourcePath=(string)($release['artifact_relative_path']??'');
        $old=glasses_vision_training_release_storage_root().'/'.ltrim($sourcePath,'/\\');
        if($sourcePath!==''&&$old!==$artifact['path']&&is_file($old))@unlink($old);
    }
    $result['publicId']=$public;
    $result['release']=glasses_vision_training_release_public(glasses_vision_training_release_row($pdo,$org,$releasePublic));
    return $result;
}

function glasses_vision_training_qualification_list(PDO $pdo,int $org,int $limit=50): array
{
    if(!glasses_vision_training_qualification_ready($pdo))return [];
    $limit=max(1,min(200,$limit));
    $q=$pdo->prepare("SELECT q.public_id,q.passed,q.score,q.qualification_hash,q.created_at,r.public_id release_public_id,r.release_hash
      FROM glasses_vision_training_qualifications q JOIN glasses_vision_training_releases r ON r.id=q.training_release_id
      WHERE q.organization_id=? ORDER BY q.id DESC LIMIT ".$limit);
    $q->execute([$org]);
    return array_map(static fn($r)=>[
        'publicId'=>$r['public_id'],'releasePublicId'=>$r['release_public_id'],'releaseHash'=>$r['release_hash'],
        'passed'=>(bool)$r['passed'],'score'=>(int)$r['score'],'qualificationHash'=>$r['qualification_hash'],'createdAt'=>$r['created_at'],
    ],$q->fetchAll());
}

function glasses_vision_training_qualification_catalog(PDO $pdo,int $org): array
{
    return [
        'ready'=>glasses_vision_training_qualification_ready($pdo),
        'schema'=>GLASSES_VISION_TRAINING_QUALIFICATION_SCHEMA,
        'policyFloors'=>array_map('glasses_vision_training_qualification_floor',array_keys(glasses_vision_training_release_profiles())),
        'qualifications'=>glasses_vision_training_qualification_list($pdo,$org),
    ];
}
