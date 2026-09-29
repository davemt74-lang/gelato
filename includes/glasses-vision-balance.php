<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-curation.php';

function glasses_vision_balance_ready(PDO $pdo): bool
{
    return glasses_vision_curation_ready($pdo);
}

function glasses_vision_balance_label_is_negative(?string $label): bool
{
    $label=strtolower(trim((string)$label));
    return $label==='' || in_array($label,['negative','background','none','no_object','no-object','empty'],true);
}

function glasses_vision_balance_is_hard(array $row): bool
{
    $source=strtolower((string)($row['sourceType']??''));
    return str_contains($source,'correct') || str_contains($source,'hard') || str_contains($source,'active');
}

function glasses_vision_balance_bucket_pose(?float $pitch,?float $yaw,?float $roll): ?string
{
    if($pitch===null&&$yaw===null&&$roll===null)return null;
    $p=(int)(round(($pitch??0)/15)*15);
    $y=(int)(round(($yaw??0)/30)*30);
    $r=(int)(round(($roll??0)/15)*15);
    return 'p'.$p.'_y'.$y.'_r'.$r;
}

function glasses_vision_balance_media(PDO $pdo,int $org,int $datasetId): array
{
    $q=$pdo->prepare("SELECT m.sample_id,m.device_id,m.camera_device_key,m.camera_pitch,m.camera_yaw,m.camera_roll,m.distance_bucket,m.occlusion_bucket,m.exposure_state,m.quality_state,m.quality_flags_json,m.sha256
      FROM glasses_vision_dataset_items di
      JOIN glasses_vision_training_media m ON m.organization_id=di.organization_id AND m.sample_id=di.sample_id AND m.status='active'
      WHERE di.organization_id=? AND di.dataset_id=?");
    $q->execute([$org,$datasetId]);
    $rows=$q->fetchAll();
    $devices=[];$cameras=[];$poses=[];$distance=[];$occlusion=[];$exposure=[];$sha=[];$poor=0;$tooEasy=0;
    foreach($rows as $r){
        if($r['device_id']!==null)$devices[(string)$r['device_id']]=true;
        if($r['camera_device_key'])$cameras[(string)$r['camera_device_key']]=true;
        $pose=glasses_vision_balance_bucket_pose($r['camera_pitch']!==null?(float)$r['camera_pitch']:null,$r['camera_yaw']!==null?(float)$r['camera_yaw']:null,$r['camera_roll']!==null?(float)$r['camera_roll']:null);
        if($pose)$poses[$pose]=($poses[$pose]??0)+1;
        if($r['distance_bucket'])$distance[(string)$r['distance_bucket']]=($distance[(string)$r['distance_bucket']]??0)+1;
        if($r['occlusion_bucket'])$occlusion[(string)$r['occlusion_bucket']]=($occlusion[(string)$r['occlusion_bucket']]??0)+1;
        if($r['exposure_state'])$exposure[(string)$r['exposure_state']]=($exposure[(string)$r['exposure_state']]??0)+1;
        $sha[(string)$r['sha256']]=($sha[(string)$r['sha256']]??0)+1;
        if((string)$r['quality_state']==='poor')$poor++;
        $flags=json_decode((string)($r['quality_flags_json']??'[]'),true)?:[];
        if(in_array('too_easy',$flags,true))$tooEasy++;
    }
    return [
        'mediaCount'=>count($rows),'deviceDiversity'=>count($devices),'cameraDiversity'=>count($cameras),
        'poseDiversity'=>count($poses),'poseCoverage'=>$poses,'distanceCoverage'=>$distance,'occlusionCoverage'=>$occlusion,'exposureCoverage'=>$exposure,
        'poorMedia'=>$poor,'tooEasyMedia'=>$tooEasy,'exactDuplicateExtra'=>array_sum(array_map(static fn($n)=>max(0,$n-1),$sha)),
    ];
}

function glasses_vision_balance_distribution(array $rows,string $key): array
{
    $counts=[];
    foreach($rows as $r){
        $v=$r[$key]??null;
        if($v===null||$v==='')$v='unattributed';
        $counts[(string)$v]=($counts[(string)$v]??0)+1;
    }
    arsort($counts,SORT_NUMERIC);
    return $counts;
}

function glasses_vision_balance_overrepresented(array $distribution,int $total,float $threshold=.50): array
{
    if($total<1)return [];
    $out=[];
    foreach($distribution as $value=>$count){
        $share=$count/$total;
        if($share>$threshold)$out[]=['value'=>$value,'count'=>$count,'share'=>round($share,4)];
    }
    return $out;
}

function glasses_vision_balance_analyze(PDO $pdo,int $org,string $datasetPublic,array $input=[]): array
{
    $dataset=glasses_vision_curation_dataset($pdo,$org,$datasetPublic);
    $all=glasses_vision_curation_rows($pdo,$org,$datasetPublic);
    $rows=array_values(array_filter($all,static fn($r)=>($r['curationDecision']??'include')!=='exclude'&&($r['reviewStatus']??'')==='approved'));
    if(!$rows)throw new InvalidArgumentException('Dataset has no included approved samples to optimize.');

    $targetPerClass=max(1,min(100000,(int)($input['targetPerClass']??50)));
    $targetNegativeRatio=max(0,min(.50,(float)($input['targetNegativeRatio']??.10)));
    $targetHardRatio=max(0,min(.80,(float)($input['targetHardRatio']??.25)));
    $maxDominantShare=max(.20,min(.95,(float)($input['maxDominantShare']??.50)));

    $classes=[];$negative=0;$hard=0;
    foreach($rows as $r){
        $label=trim((string)($r['label']??''));
        if(glasses_vision_balance_label_is_negative($label)){$negative++;continue;}
        $classes[$label]=($classes[$label]??0)+1;
        if(glasses_vision_balance_is_hard($r))$hard++;
    }
    ksort($classes,SORT_STRING);
    $total=count($rows);$positive=max(1,$total-$negative);
    $negativeRatio=$negative/$total;$hardRatio=$hard/$positive;

    $operators=glasses_vision_balance_distribution($rows,'operatorName');
    $locations=glasses_vision_balance_distribution($rows,'locationName');
    $stations=glasses_vision_balance_distribution($rows,'stationName');
    $menuItems=glasses_vision_balance_distribution($rows,'menuItemName');
    $media=glasses_vision_balance_media($pdo,$org,(int)$dataset['id']);

    $recommendations=[];
    foreach($classes as $label=>$count){
        if($count<$targetPerClass)$recommendations[]=['action'=>'add','dimension'=>'class','key'=>$label,'amount'=>$targetPerClass-$count,'priority'=>95,'reason'=>'class_below_target'];
    }
    $needNeg=(int)max(0,ceil(($targetNegativeRatio*$total-$negative)/max(.0001,1-$targetNegativeRatio)));
    if($needNeg>0)$recommendations[]=['action'=>'add','dimension'=>'negative','key'=>'background/negative','amount'=>$needNeg,'priority'=>90,'reason'=>'negative_ratio_below_target'];
    $needHard=(int)max(0,ceil($targetHardRatio*$positive)-$hard);
    if($needHard>0)$recommendations[]=['action'=>'add','dimension'=>'hard_example','key'=>'hard_examples','amount'=>$needHard,'priority'=>88,'reason'=>'hard_example_ratio_below_target'];

    foreach(['operator'=>$operators,'location'=>$locations,'station'=>$stations,'menu_item'=>$menuItems] as $dimension=>$dist){
        foreach(glasses_vision_balance_overrepresented($dist,$total,$maxDominantShare) as $dom){
            $target=(int)floor($total*$maxDominantShare);
            $recommendations[]=['action'=>'review_or_reduce','dimension'=>$dimension,'key'=>$dom['value'],'amount'=>max(1,$dom['count']-$target),'priority'=>70,'reason'=>'overrepresented_environment','share'=>$dom['share']];
        }
    }
    if($media['deviceDiversity']<2)$recommendations[]=['action'=>'add','dimension'=>'device','key'=>'new_device','amount'=>1,'priority'=>72,'reason'=>'device_diversity_low'];
    if($media['cameraDiversity']<2)$recommendations[]=['action'=>'add','dimension'=>'camera','key'=>'new_camera_profile','amount'=>1,'priority'=>68,'reason'=>'camera_diversity_low'];
    if($media['poseDiversity']<3)$recommendations[]=['action'=>'add','dimension'=>'pose','key'=>'new_pose_buckets','amount'=>3-$media['poseDiversity'],'priority'=>66,'reason'=>'pose_diversity_low'];
    if(count($media['exposureCoverage'])<2)$recommendations[]=['action'=>'add','dimension'=>'lighting','key'=>'alternate_lighting','amount'=>1,'priority'=>64,'reason'=>'lighting_diversity_low'];
    if($media['exactDuplicateExtra']>0)$recommendations[]=['action'=>'remove','dimension'=>'duplicate','key'=>'exact_sha256_duplicates','amount'=>$media['exactDuplicateExtra'],'priority'=>100,'reason'=>'exact_duplicate'];
    if($media['poorMedia']>0)$recommendations[]=['action'=>'remove_or_recapture','dimension'=>'quality','key'=>'poor_media','amount'=>$media['poorMedia'],'priority'=>98,'reason'=>'hard_visual_quality_blocker'];
    if($media['tooEasyMedia']>0)$recommendations[]=['action'=>'deprioritize','dimension'=>'difficulty','key'=>'too_easy','amount'=>$media['tooEasyMedia'],'priority'=>45,'reason'=>'too_easy_overrepresentation'];
    usort($recommendations,static fn($a,$b)=>($b['priority']<=>$a['priority']) ?: strcmp($a['dimension'].'|'.$a['key'],$b['dimension'].'|'.$b['key']));

    $classValues=array_values($classes);
    $classMin=$classValues?min($classValues):0;$classMax=$classValues?max($classValues):0;
    $balanceIndex=$classMax>0?round($classMin/$classMax,4):0.0;

    return [
        'schema'=>'gelato.vision_dataset_balance.v1','datasetPublicId'=>$datasetPublic,'datasetStatus'=>$dataset['status'],
        'policy'=>['targetPerClass'=>$targetPerClass,'targetNegativeRatio'=>$targetNegativeRatio,'targetHardRatio'=>$targetHardRatio,'maxDominantShare'=>$maxDominantShare],
        'totals'=>['includedApproved'=>$total,'positive'=>$total-$negative,'negative'=>$negative,'hardExamples'=>$hard],
        'ratios'=>['negative'=>round($negativeRatio,4),'hard'=>round($hardRatio,4),'classBalanceIndex'=>$balanceIndex],
        'classes'=>$classes,
        'diversity'=>[
            'operators'=>$operators,'locations'=>$locations,'stations'=>$stations,'menuItems'=>$menuItems,
            'operatorCount'=>count($operators),'locationCount'=>count($locations),'stationCount'=>count($stations),'menuItemCount'=>count($menuItems),
            'media'=>$media,
        ],
        'overrepresented'=>[
            'operators'=>glasses_vision_balance_overrepresented($operators,$total,$maxDominantShare),
            'locations'=>glasses_vision_balance_overrepresented($locations,$total,$maxDominantShare),
            'stations'=>glasses_vision_balance_overrepresented($stations,$total,$maxDominantShare),
            'menuItems'=>glasses_vision_balance_overrepresented($menuItems,$total,$maxDominantShare),
        ],
        'recommendations'=>$recommendations,
        'mutationApplied'=>false,
    ];
}

function glasses_vision_balance_catalog(PDO $pdo,int $org): array
{
    return ['ready'=>glasses_vision_balance_ready($pdo),'schema'=>'gelato.vision_dataset_balance.v1'];
}
