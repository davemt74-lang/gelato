<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-active-learning.php';

function glasses_vision_dataset_intelligence_ready(PDO $pdo): bool
{
    foreach ([
        'glasses_vision_sample_reviews',
        'glasses_vision_dataset_intelligence_snapshots',
        'glasses_vision_collection_plans',
    ] as $table) {
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if ((int)$q->fetchColumn()!==1) return false;
    }
    return glasses_vision_active_learning_ready($pdo);
}

function glasses_vision_dataset_intelligence_dataset_id(PDO $pdo,int $org,?string $publicId): ?int
{
    if ($publicId===null || trim($publicId)==='') return null;
    $q=$pdo->prepare("SELECT id FROM glasses_vision_dataset_versions WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);
    $id=$q->fetchColumn();
    if ($id===false) throw new InvalidArgumentException('Vision dataset was not found.');
    return (int)$id;
}

function glasses_vision_dataset_intelligence_scope(?int $datasetId,string $sampleAlias='s'): array
{
    if ($datasetId===null) return ['',[]];
    return [
        " JOIN glasses_vision_dataset_items di_scope ON di_scope.organization_id={$sampleAlias}.organization_id AND di_scope.sample_id={$sampleAlias}.id AND di_scope.dataset_id=? ",
        [$datasetId],
    ];
}

function glasses_vision_dataset_intelligence_record_review(PDO $pdo,int $org,string $samplePublic,int $reviewerId,string $decision,?string $canonicalLabel,string $notes=''): array
{
    if (!in_array($decision,['approve','reject','relabel','needs_adjudication'],true)) {
        throw new InvalidArgumentException('Invalid review decision.');
    }
    $q=$pdo->prepare("SELECT id,canonical_label FROM glasses_vision_training_samples WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$samplePublic]);
    $sample=$q->fetch();
    if (!$sample) throw new InvalidArgumentException('Vision training sample was not found.');

    $label=trim((string)$canonicalLabel);
    if ($decision==='relabel' && $label==='') throw new InvalidArgumentException('Relabel requires a canonical label.');
    if ($label==='') $label=(string)($sample['canonical_label']??'');

    $pdo->prepare("INSERT INTO glasses_vision_sample_reviews
      (organization_id,sample_id,reviewer_user_id,decision,canonical_label,notes)
      VALUES (?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE decision=VALUES(decision),canonical_label=VALUES(canonical_label),notes=VALUES(notes),created_at=NOW(6)")
      ->execute([$org,(int)$sample['id'],$reviewerId,$decision,$label!==''?$label:null,mb_substr(trim($notes),0,1000)]);

    $rq=$pdo->prepare("SELECT decision,COALESCE(canonical_label,'') label FROM glasses_vision_sample_reviews WHERE organization_id=? AND sample_id=? ORDER BY id");
    $rq->execute([$org,(int)$sample['id']]);
    $reviews=$rq->fetchAll();
    $signatures=[];
    foreach ($reviews as $review) $signatures[(string)$review['decision'].'|'.(string)$review['label']]=true;
    $disagrees=count($signatures)>1;

    if ($disagrees) {
        $pdo->prepare("UPDATE glasses_vision_training_samples SET review_status='needs_adjudication',updated_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([$org,(int)$sample['id']]);
    } elseif (count($reviews)>=2) {
        $first=$reviews[0];
        $consensusDecision=(string)$first['decision'];
        $consensusLabel=(string)$first['label'];
        $status=in_array($consensusDecision,['approve','relabel'],true)?'approved':($consensusDecision==='reject'?'rejected':'needs_adjudication');
        $pdo->prepare("UPDATE glasses_vision_training_samples SET review_status=?,review_outcome=?,canonical_label=CASE WHEN ?<>'' THEN ? ELSE canonical_label END,adjudicator_user_id=?,reviewed_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([$status,$consensusDecision,$consensusLabel,$consensusLabel,$reviewerId,$org,(int)$sample['id']]);
    }

    return ['samplePublicId'=>$samplePublic,'reviewCount'=>count($reviews),'hasDisagreement'=>$disagrees];
}

function glasses_vision_dataset_intelligence_metrics(PDO $pdo,int $org,?int $datasetId=null,int $minimumPerClass=20): array
{
    [$scope,$scopeArgs]=glasses_vision_dataset_intelligence_scope($datasetId,'s');
    $args=array_merge($scopeArgs,[$org]);

    $q=$pdo->prepare("SELECT
      COUNT(*) total,
      SUM(s.review_status='approved') approved,
      SUM(s.review_status='pending') pending,
      SUM(s.review_status='needs_adjudication') needs_adjudication,
      COUNT(DISTINCT s.canonical_label) classes,
      COUNT(DISTINCT s.operator_user_id) operators,
      COUNT(DISTINCT bs.device_id) devices,
      COUNT(DISTINCT k.location_id) locations,
      COUNT(DISTINCT k.station_id) stations,
      COUNT(DISTINCT s.source_type) source_types
      FROM glasses_vision_training_samples s {$scope}
      LEFT JOIN glasses_build_observations o ON o.id=s.observation_id
      LEFT JOIN glasses_build_sessions bs ON bs.id=o.build_session_id
      LEFT JOIN kds_order_items k ON k.id=bs.kds_order_item_id
      WHERE s.organization_id=?");
    $q->execute($args);
    $r=$q->fetch()?:[];

    $cq=$pdo->prepare("SELECT COALESCE(s.canonical_label,'(negative)') label,COUNT(*) total,SUM(s.review_status='approved') approved
      FROM glasses_vision_training_samples s {$scope}
      WHERE s.organization_id=?
      GROUP BY s.canonical_label
      ORDER BY approved ASC,total ASC,label");
    $cq->execute($args);
    $classes=[];
    foreach ($cq->fetchAll() as $row) {
        $approved=(int)$row['approved'];
        $classes[]=[
            'label'=>$row['label'],
            'total'=>(int)$row['total'],
            'approved'=>$approved,
            'gap'=>max(0,$minimumPerClass-$approved),
            'ready'=>$approved>=$minimumPerClass,
        ];
    }

    return [
        'total'=>(int)($r['total']??0),
        'approved'=>(int)($r['approved']??0),
        'pending'=>(int)($r['pending']??0),
        'needsAdjudication'=>(int)($r['needs_adjudication']??0),
        'classCount'=>(int)($r['classes']??0),
        'operatorDiversity'=>(int)($r['operators']??0),
        'deviceDiversity'=>(int)($r['devices']??0),
        'locationDiversity'=>(int)($r['locations']??0),
        'stationDiversity'=>(int)($r['stations']??0),
        'sourceTypeDiversity'=>(int)($r['source_types']??0),
        'minimumApprovedPerClass'=>$minimumPerClass,
        'classes'=>$classes,
    ];
}

function glasses_vision_dataset_intelligence_duplicates(PDO $pdo,int $org,?int $datasetId=null): array
{
    [$scope,$scopeArgs]=glasses_vision_dataset_intelligence_scope($datasetId,'s');
    $args=array_merge($scopeArgs,[$org]);
    $q=$pdo->prepare("SELECT s.source_type,s.source_reference,COUNT(*) n,GROUP_CONCAT(s.public_id ORDER BY s.public_id) sample_ids
      FROM glasses_vision_training_samples s {$scope}
      WHERE s.organization_id=? AND s.source_reference IS NOT NULL AND s.source_reference<>''
      GROUP BY s.source_type,s.source_reference
      HAVING COUNT(*)>1
      ORDER BY n DESC
      LIMIT 100");
    $q->execute($args);
    return array_map(static fn($r)=>[
        'sourceType'=>$r['source_type'],
        'sourceReference'=>$r['source_reference'],
        'count'=>(int)$r['n'],
        'samplePublicIds'=>explode(',',(string)$r['sample_ids']),
    ],$q->fetchAll());
}

function glasses_vision_dataset_intelligence_split_leakage(PDO $pdo,int $org,int $datasetId): array
{
    $q=$pdo->prepare("SELECT bs.public_id build_public_id,COUNT(DISTINCT di.split_name) split_count,
      GROUP_CONCAT(DISTINCT di.split_name ORDER BY di.split_name) splits,COUNT(*) samples
      FROM glasses_vision_dataset_items di
      JOIN glasses_vision_training_samples s ON s.id=di.sample_id AND s.organization_id=di.organization_id
      JOIN glasses_build_observations o ON o.id=s.observation_id
      JOIN glasses_build_sessions bs ON bs.id=o.build_session_id
      WHERE di.organization_id=? AND di.dataset_id=?
      GROUP BY bs.id,bs.public_id
      HAVING COUNT(DISTINCT di.split_name)>1
      ORDER BY samples DESC
      LIMIT 100");
    $q->execute([$org,$datasetId]);
    return array_map(static fn($r)=>[
        'buildPublicId'=>$r['build_public_id'],
        'splitCount'=>(int)$r['split_count'],
        'splits'=>explode(',',(string)$r['splits']),
        'samples'=>(int)$r['samples'],
    ],$q->fetchAll());
}

function glasses_vision_dataset_intelligence_disagreements(PDO $pdo,int $org,?int $datasetId=null): array
{
    [$scope,$scopeArgs]=glasses_vision_dataset_intelligence_scope($datasetId,'s');
    $args=array_merge($scopeArgs,[$org]);
    $q=$pdo->prepare("SELECT s.public_id sample_public_id,s.canonical_label,COUNT(r.id) review_count,
      COUNT(DISTINCT CONCAT(r.decision,'|',COALESCE(r.canonical_label,''))) signature_count,
      GROUP_CONCAT(DISTINCT CONCAT(u.display_name,':',r.decision,':',COALESCE(r.canonical_label,'')) ORDER BY u.display_name SEPARATOR ' || ') reviews
      FROM glasses_vision_training_samples s {$scope}
      JOIN glasses_vision_sample_reviews r ON r.sample_id=s.id AND r.organization_id=s.organization_id
      JOIN users u ON u.id=r.reviewer_user_id
      WHERE s.organization_id=?
      GROUP BY s.id,s.public_id,s.canonical_label
      HAVING review_count>1 AND signature_count>1
      ORDER BY review_count DESC,s.id DESC
      LIMIT 100");
    $q->execute($args);
    return array_map(static fn($r)=>[
        'samplePublicId'=>$r['sample_public_id'],
        'canonicalLabel'=>$r['canonical_label'],
        'reviewCount'=>(int)$r['review_count'],
        'reviews'=>$r['reviews'],
    ],$q->fetchAll());
}

function glasses_vision_dataset_intelligence_environment(PDO $pdo,int $org,?int $datasetId=null): array
{
    [$scope,$scopeArgs]=glasses_vision_dataset_intelligence_scope($datasetId,'s');
    $args=array_merge($scopeArgs,[$org]);
    $q=$pdo->prepare("SELECT
      COALESCE(l.name,'Unknown') location_name,
      COALESCE(ks.name,'No station') station_name,
      COALESCE(d.display_name,'Unknown device') device_name,
      COALESCE(u.display_name,'Unattributed') operator_name,
      JSON_UNQUOTE(JSON_EXTRACT(o.metadata_json,'$.lighting')) lighting,
      COUNT(*) n
      FROM glasses_vision_training_samples s {$scope}
      LEFT JOIN glasses_build_observations o ON o.id=s.observation_id
      LEFT JOIN glasses_build_sessions bs ON bs.id=o.build_session_id
      LEFT JOIN kds_order_items k ON k.id=bs.kds_order_item_id
      LEFT JOIN locations l ON l.id=k.location_id
      LEFT JOIN kds_stations ks ON ks.id=k.station_id
      LEFT JOIN glasses_devices d ON d.id=bs.device_id
      LEFT JOIN users u ON u.id=s.operator_user_id
      WHERE s.organization_id=? AND s.review_status='approved'
      GROUP BY l.name,ks.name,d.display_name,u.display_name,lighting
      ORDER BY n DESC
      LIMIT 250");
    $q->execute($args);

    $out=['locations'=>[],'stations'=>[],'devices'=>[],'operators'=>[],'lighting'=>[],'lightingInstrumented'=>false,'cameraDiversityInstrumented'=>false];
    foreach ($q->fetchAll() as $r) {
        $n=(int)$r['n'];
        $out['locations'][$r['location_name']]=($out['locations'][$r['location_name']]??0)+$n;
        $out['stations'][$r['station_name']]=($out['stations'][$r['station_name']]??0)+$n;
        $out['devices'][$r['device_name']]=($out['devices'][$r['device_name']]??0)+$n;
        $out['operators'][$r['operator_name']]=($out['operators'][$r['operator_name']]??0)+$n;
        if ($r['lighting']!==null && $r['lighting']!=='') $out['lighting'][$r['lighting']]=($out['lighting'][$r['lighting']]??0)+$n;
    }
    $out['lightingInstrumented']=count($out['lighting'])>0;
    return $out;
}

function glasses_vision_dataset_intelligence_menu_readiness(PDO $pdo,int $org,int $minimumPerIngredient=20,?int $datasetId=null): array
{
    if($datasetId===null){$aq=$pdo->prepare("SELECT canonical_label,COUNT(*) n FROM glasses_vision_training_samples WHERE organization_id=? AND review_status='approved' GROUP BY canonical_label");$aq->execute([$org]);}
    else{$aq=$pdo->prepare("SELECT s.canonical_label,COUNT(*) n FROM glasses_vision_training_samples s JOIN glasses_vision_dataset_items di ON di.organization_id=s.organization_id AND di.sample_id=s.id AND di.dataset_id=? WHERE s.organization_id=? AND s.review_status='approved' GROUP BY s.canonical_label");$aq->execute([$datasetId,$org]);}
    $approved=[];
    foreach ($aq->fetchAll() as $r) $approved[(string)$r['canonical_label']]=(int)$r['n'];

    $q=$pdo->prepare("SELECT mi.id,mi.name item_name,mii.ingredient_id,mii.display_name ingredient_name,mii.is_optional
      FROM menu_items mi
      JOIN menu_item_ingredients mii ON mii.menu_item_id=mi.id
      WHERE mi.organization_id=? AND mi.is_active=1
      ORDER BY mi.name,mii.sort_order,mii.ingredient_id");
    $q->execute([$org]);

    $items=[];
    foreach ($q->fetchAll() as $r) {
        $id=(int)$r['id'];
        if (!isset($items[$id])) $items[$id]=['menuItemId'=>$id,'name'=>$r['item_name'],'required'=>0,'readyRequired'=>0,'ingredients'=>[]];
        $key='ingredient:'.(int)$r['ingredient_id'];
        $count=$approved[$key]??0;
        $ready=$count>=$minimumPerIngredient;
        $optional=(bool)$r['is_optional'];
        $items[$id]['ingredients'][]=[
            'componentKey'=>$key,'name'=>$r['ingredient_name'],'optional'=>$optional,
            'approvedSamples'=>$count,'minimum'=>$minimumPerIngredient,'gap'=>max(0,$minimumPerIngredient-$count),'ready'=>$ready,
        ];
        if (!$optional) {
            $items[$id]['required']++;
            if ($ready) $items[$id]['readyRequired']++;
        }
    }
    foreach ($items as &$item) {
        $item['ready']=$item['required']>0 && $item['readyRequired']===$item['required'];
        $item['readinessPercent']=$item['required']>0?round(($item['readyRequired']/$item['required'])*100,1):0;
    }
    unset($item);
    return array_values($items);
}

function glasses_vision_dataset_intelligence_set_split(PDO $pdo,int $org,string $datasetPublic,string $samplePublic,string $split): array
{
    if(!in_array($split,['train','val','test'],true))throw new InvalidArgumentException('Dataset split must be train, val or test.');
    $q=$pdo->prepare("SELECT d.id,d.status,s.id sample_id FROM glasses_vision_dataset_versions d JOIN glasses_vision_training_samples s ON s.organization_id=d.organization_id AND s.public_id=? WHERE d.organization_id=? AND d.public_id=? LIMIT 1");
    $q->execute([$samplePublic,$org,$datasetPublic]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Dataset or sample was not found.');
    if((string)$row['status']!=='draft')throw new InvalidArgumentException('Frozen datasets are immutable.');
    $u=$pdo->prepare("UPDATE glasses_vision_dataset_items SET split_name=? WHERE organization_id=? AND dataset_id=? AND sample_id=?");
    $u->execute([$split,$org,(int)$row['id'],(int)$row['sample_id']]);
    if($u->rowCount()===0)throw new InvalidArgumentException('Sample is not part of this dataset.');
    return glasses_vision_lab_dataset_coverage($pdo,$org,$datasetPublic);
}

function glasses_vision_dataset_intelligence_consolidate_build_split(PDO $pdo,int $org,string $datasetPublic,string $buildPublic,string $split): array
{
    if(!in_array($split,['train','val','test'],true))throw new InvalidArgumentException('Dataset split must be train, val or test.');
    $datasetId=glasses_vision_dataset_intelligence_dataset_id($pdo,$org,$datasetPublic);
    if($datasetId===null)throw new InvalidArgumentException('Vision dataset was not found.');
    $dq=$pdo->prepare("SELECT status FROM glasses_vision_dataset_versions WHERE organization_id=? AND id=?");$dq->execute([$org,$datasetId]);
    if((string)$dq->fetchColumn()!=='draft')throw new InvalidArgumentException('Frozen datasets are immutable.');
    $q=$pdo->prepare("UPDATE glasses_vision_dataset_items di
      JOIN glasses_vision_training_samples s ON s.id=di.sample_id AND s.organization_id=di.organization_id
      JOIN glasses_build_observations o ON o.id=s.observation_id
      JOIN glasses_build_sessions bs ON bs.id=o.build_session_id
      SET di.split_name=?
      WHERE di.organization_id=? AND di.dataset_id=? AND bs.public_id=?");
    $q->execute([$split,$org,$datasetId,$buildPublic]);
    if($q->rowCount()===0)throw new InvalidArgumentException('No dataset samples matched that build session.');
    return ['datasetPublicId'=>$datasetPublic,'buildPublicId'=>$buildPublic,'split'=>$split,'updatedItems'=>$q->rowCount()];
}

function glasses_vision_dataset_intelligence_freeze_guard(PDO $pdo,int $org,string $datasetPublic): void
{
    $datasetId=glasses_vision_dataset_intelligence_dataset_id($pdo,$org,$datasetPublic);
    if($datasetId===null)return;
    $leakage=glasses_vision_dataset_intelligence_split_leakage($pdo,$org,$datasetId);
    if($leakage)throw new InvalidArgumentException('Dataset freeze blocked: build-session split leakage must be resolved.');
    $disagreements=glasses_vision_dataset_intelligence_disagreements($pdo,$org,$datasetId);
    if($disagreements)throw new InvalidArgumentException('Dataset freeze blocked: reviewer disagreement must be adjudicated.');
}

function glasses_vision_dataset_intelligence_analyze(PDO $pdo,int $org,?string $datasetPublic,int $actor,int $minimumPerClass=20): array
{
    if (!glasses_vision_dataset_intelligence_ready($pdo)) throw new RuntimeException('Vision Lab V4 migration is not installed.');
    $datasetId=glasses_vision_dataset_intelligence_dataset_id($pdo,$org,$datasetPublic);
    $metrics=glasses_vision_dataset_intelligence_metrics($pdo,$org,$datasetId,$minimumPerClass);
    $duplicates=glasses_vision_dataset_intelligence_duplicates($pdo,$org,$datasetId);
    $leakage=$datasetId!==null?glasses_vision_dataset_intelligence_split_leakage($pdo,$org,$datasetId):[];
    $disagreements=glasses_vision_dataset_intelligence_disagreements($pdo,$org,$datasetId);
    $environment=glasses_vision_dataset_intelligence_environment($pdo,$org,$datasetId);
    $menu=glasses_vision_dataset_intelligence_menu_readiness($pdo,$org,$minimumPerClass,$datasetId);

    $gaps=[];
    foreach ($metrics['classes'] as $class) {
        if (!$class['ready']) $gaps[]=['type'=>'class_coverage','key'=>$class['label'],'label'=>$class['label'],'gap'=>$class['gap'],'priority'=>min(100,60+$class['gap'])];
    }
    foreach ($menu as $item) {
        foreach ($item['ingredients'] as $ingredient) {
            if (!$ingredient['optional'] && !$ingredient['ready']) {
                $gaps[]=['type'=>'menu_ingredient','key'=>$ingredient['componentKey'],'label'=>$ingredient['name'],'menuItem'=>$item['name'],'gap'=>$ingredient['gap'],'priority'=>min(100,70+$ingredient['gap'])];
            }
        }
    }
    if (count($environment['locations'])<2) $gaps[]=['type'=>'location_diversity','key'=>'locations','gap'=>1,'priority'=>55];
    if (count($environment['operators'])<2) $gaps[]=['type'=>'operator_diversity','key'=>'operators','gap'=>1,'priority'=>50];
    if (!$environment['lightingInstrumented']) $gaps[]=['type'=>'lighting_metadata','key'=>'lighting','gap'=>1,'priority'=>45];
    if (!$environment['cameraDiversityInstrumented']) $gaps[]=['type'=>'camera_metadata','key'=>'camera','gap'=>1,'priority'=>45];
    foreach ($duplicates as $duplicate) $gaps[]=['type'=>'exact_provenance_duplicate','key'=>$duplicate['sourceType'].':'.$duplicate['sourceReference'],'gap'=>$duplicate['count']-1,'priority'=>75];
    foreach ($leakage as $row) $gaps[]=['type'=>'split_leakage','key'=>$row['buildPublicId'],'gap'=>$row['splitCount']-1,'priority'=>100];
    foreach ($disagreements as $row) $gaps[]=['type'=>'review_disagreement','key'=>$row['samplePublicId'],'gap'=>1,'priority'=>90];

    usort($gaps,static fn($a,$b)=>($b['priority']<=>$a['priority'])?:strcmp((string)$a['key'],(string)$b['key']));

    $readyMenuItems=count(array_filter($menu,static fn($x)=>$x['ready']));
    $readiness=[
        'menuItems'=>$menu,
        'readyMenuItems'=>$readyMenuItems,
        'totalMenuItems'=>count($menu),
        'hasValidationSplit'=>$datasetId===null?null:((glasses_vision_lab_dataset_coverage($pdo,$org,(string)$datasetPublic)['hasValidation']??false)===true),
        'hasTestSplit'=>$datasetId===null?null:((glasses_vision_lab_dataset_coverage($pdo,$org,(string)$datasetPublic)['hasTest']??false)===true),
        'splitLeakageClear'=>count($leakage)===0,
        'reviewDisagreementsClear'=>count($disagreements)===0,
    ];
    $readiness['datasetReleaseReady']=$datasetId===null?null:(
        $readiness['hasValidationSplit'] &&
        $readiness['hasTestSplit'] &&
        $readiness['splitLeakageClear'] &&
        $readiness['reviewDisagreementsClear']
    );
    $snapshot=glasses_public_id('vision-intel');
    $pdo->prepare("INSERT INTO glasses_vision_dataset_intelligence_snapshots
      (organization_id,public_id,dataset_id,snapshot_type,metrics_json,gaps_json,readiness_json,created_by)
      VALUES (?,?,?,?,?,?,?,?)")
      ->execute([
          $org,$snapshot,$datasetId,$datasetId===null?'organization':'dataset',
          json_encode(['coverage'=>$metrics,'environment'=>$environment,'duplicates'=>$duplicates,'splitLeakage'=>$leakage,'reviewDisagreements'=>$disagreements],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
          json_encode($gaps,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
          json_encode($readiness,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
          $actor?:null,
      ]);

    return [
        'publicId'=>$snapshot,'datasetPublicId'=>$datasetPublic,'coverage'=>$metrics,'environment'=>$environment,
        'duplicates'=>$duplicates,'splitLeakage'=>$leakage,'reviewDisagreements'=>$disagreements,'gaps'=>$gaps,'readiness'=>$readiness,
        'limitations'=>[
            'perceptualDuplicateDetection'=>false,
            'cameraAngleMetadata'=>false,
            'message'=>'Image-level near-duplicate and camera-angle diversity require governed image fingerprints/pose metadata that are not stored server-side yet.',
        ],
    ];
}

function glasses_vision_dataset_intelligence_collection_plan(PDO $pdo,int $org,string $snapshotPublic,string $title,int $actor): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_vision_dataset_intelligence_snapshots WHERE organization_id=? AND public_id=? LIMIT 1");
    $q->execute([$org,$snapshotPublic]);
    $snapshot=$q->fetch();
    if (!$snapshot) throw new InvalidArgumentException('Dataset intelligence snapshot was not found.');

    $gaps=json_decode((string)$snapshot['gaps_json'],true)?:[];
    $tasks=[];
    foreach (array_slice($gaps,0,50) as $gap) {
        $type=(string)($gap['type']??'coverage');
        $target=max(10,min(100,(int)($gap['gap']??20)));
        $tasks[]=[
            'type'=>$type,
            'key'=>$gap['key']??null,
            'label'=>$gap['label']??null,
            'menuItem'=>$gap['menuItem']??null,
            'priority'=>(int)($gap['priority']??50),
            'targetApprovedSamples'=>$type==='menu_ingredient'||$type==='class_coverage'?$target:0,
            'recommendedAction'=>match($type){
                'split_leakage'=>'Move all samples from the same build session into one split.',
                'review_disagreement'=>'Adjudicate reviewer disagreement before dataset release.',
                'exact_provenance_duplicate'=>'Remove or intentionally retain duplicate provenance with rationale.',
                'lighting_metadata'=>'Capture reviewed samples with explicit lighting metadata.',
                'camera_metadata'=>'Instrument/capture camera-angle or calibration-pose metadata.',
                'location_diversity'=>'Collect approved samples at another location.',
                'operator_diversity'=>'Collect approved samples from another operator/wearer.',
                default=>'Collect and approve additional representative examples.',
            },
        ];
    }

    $public=glasses_public_id('vision-plan');
    $payload=['schema'=>'gelato.vision_collection_plan.v1','snapshotPublicId'=>$snapshotPublic,'tasks'=>$tasks];
    $pdo->prepare("INSERT INTO glasses_vision_collection_plans
      (organization_id,public_id,dataset_id,title,status,source_snapshot_id,plan_json,created_by)
      VALUES (?,?,?,?,'draft',?,?,?)")
      ->execute([$org,$public,$snapshot['dataset_id']!==null?(int)$snapshot['dataset_id']:null,mb_substr(trim($title)!==''?$title:'Vision collection plan',0,190),(int)$snapshot['id'],json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actor]);

    return ['publicId'=>$public,'title'=>$title!==''?$title:'Vision collection plan','status'=>'draft','tasks'=>$tasks,'snapshotPublicId'=>$snapshotPublic];
}

function glasses_vision_dataset_intelligence_accept_plan(PDO $pdo,int $org,string $planPublic,int $actor): array
{
    return glasses_transaction($pdo,function()use($pdo,$org,$planPublic,$actor):array{
        $q=$pdo->prepare("SELECT * FROM glasses_vision_collection_plans WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,$planPublic]);
        $plan=$q->fetch();
        if (!$plan) throw new InvalidArgumentException('Vision collection plan was not found.');
        if ((string)$plan['status']==='accepted') {
            return ['publicId'=>$planPublic,'status'=>'accepted','missionsCreated'=>0];
        }
        $payload=json_decode((string)$plan['plan_json'],true)?:[];
        $missions=0;
        foreach ((array)($payload['tasks']??[]) as $task) {
            if (!in_array((string)($task['type']??''),['menu_ingredient','class_coverage','location_diversity','operator_diversity','lighting_metadata','camera_metadata'],true)) continue;
            $label=(string)($task['label']??$task['key']??'coverage');
            $target=max(20,(int)($task['targetApprovedSamples']??20));
            glasses_vision_lab_create_mission($pdo,$org,[
                'title'=>'Collection plan: '.mb_substr($label,0,150),
                'description'=>(string)($task['recommendedAction']??'Collect representative reviewed samples.'),
                'targetSamples'=>$target,
                'targetPositive'=>$target,
                'targetNegative'=>max(5,(int)round($target*.2)),
                'targetHardExamples'=>max(5,(int)round($target*.25)),
                'sourceType'=>'collection_plan',
                'sourceReference'=>$planPublic,
                'conditions'=>['task'=>$task],
            ],$actor);
            $missions++;
        }
        $pdo->prepare("UPDATE glasses_vision_collection_plans SET status='accepted',accepted_by=?,accepted_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")
          ->execute([$actor,$org,(int)$plan['id']]);
        return ['publicId'=>$planPublic,'status'=>'accepted','missionsCreated'=>$missions];
    });
}

function glasses_vision_dataset_intelligence_catalog(PDO $pdo,int $org): array
{
    if (!glasses_vision_dataset_intelligence_ready($pdo)) return ['ready'=>false,'latest'=>null,'plans'=>[]];

    $q=$pdo->prepare("SELECT s.public_id,s.snapshot_type,s.created_at,d.public_id dataset_public_id,d.name dataset_name,d.version_label
      FROM glasses_vision_dataset_intelligence_snapshots s
      LEFT JOIN glasses_vision_dataset_versions d ON d.id=s.dataset_id
      WHERE s.organization_id=?
      ORDER BY s.id DESC LIMIT 1");
    $q->execute([$org]);
    $latest=$q->fetch();

    $pq=$pdo->prepare("SELECT public_id,title,status,plan_json,created_at,accepted_at FROM glasses_vision_collection_plans WHERE organization_id=? ORDER BY id DESC LIMIT 50");
    $pq->execute([$org]);
    $plans=array_map(static fn($r)=>[
        'publicId'=>$r['public_id'],'title'=>$r['title'],'status'=>$r['status'],
        'taskCount'=>count((array)((json_decode((string)$r['plan_json'],true)?:[])['tasks']??[])),
        'createdAt'=>$r['created_at'],'acceptedAt'=>$r['accepted_at'],
    ],$pq->fetchAll());

    return [
        'ready'=>true,
        'latest'=>$latest?[
            'publicId'=>$latest['public_id'],'snapshotType'=>$latest['snapshot_type'],'datasetPublicId'=>$latest['dataset_public_id'],
            'datasetName'=>$latest['dataset_name'],'versionLabel'=>$latest['version_label'],'createdAt'=>$latest['created_at'],
        ]:null,
        'plans'=>$plans,
    ];
}
