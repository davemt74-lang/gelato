<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-vision-balance.php';

function glasses_vision_annotation_qa_ready(PDO $pdo): bool
{
    return glasses_vision_balance_ready($pdo);
}

function glasses_vision_annotation_qa_box(array $box): array
{
    $x=(float)($box['x']??-1);$y=(float)($box['y']??-1);$w=(float)($box['width']??0);$h=(float)($box['height']??0);
    $valid=$x>=0&&$y>=0&&$w>0&&$h>0&&$x+$w<=1.00001&&$y+$h<=1.00001;
    return ['valid'=>$valid,'x'=>$x,'y'=>$y,'width'=>$w,'height'=>$h,'area'=>$valid?$w*$h:0.0];
}

function glasses_vision_annotation_qa_guidance(string $label): array
{
    $key=strtolower(trim($label));
    if(glasses_vision_balance_label_is_negative($key))return [
        'label'=>$label,'mode'=>'negative','instructions'=>'Use no positive-object box. Keep the frame only when it is a deliberate background/negative example and contains none of the target class.',
    ];
    return [
        'label'=>$label,'mode'=>'object_detection',
        'instructions'=>'Draw a tight box around the visible target object only. Include visible edges; exclude unrelated plate, counter, hands, tools, and neighboring ingredients. Mark severe occlusion for review instead of guessing hidden extent.',
    ];
}

function glasses_vision_annotation_qa_sample(array $row): array
{
    $annotations=json_decode((string)($row['annotation_json']??'[]'),true);
    if(!is_array($annotations))$annotations=[];
    $canonical=trim((string)($row['canonical_label']??''));
    $negative=glasses_vision_balance_label_is_negative($canonical);
    $issues=[];$areas=[];$labels=[];
    foreach($annotations as $i=>$ann){
        if(!is_array($ann)){$issues[]='annotation_'.$i.'_not_object';continue;}
        $label=trim((string)($ann['label']??''));
        if($label==='')$issues[]='annotation_'.$i.'_missing_label';else$labels[]=$label;
        $box=glasses_vision_annotation_qa_box(is_array($ann['bbox']??null)?$ann['bbox']:[]);
        if(!$box['valid'])$issues[]='annotation_'.$i.'_invalid_box';
        else{
            $areas[]=$box['area'];
            if($box['area']<.001)$issues[]='annotation_'.$i.'_tiny_box';
            if($box['area']>.90)$issues[]='annotation_'.$i.'_oversized_box';
        }
    }
    if(!$negative&&$canonical===''&&(!$labels))$issues[]='missing_canonical_label';
    if(!$negative&&count($annotations)===0)$issues[]='missing_annotation';
    if($negative&&count($annotations)>0)$issues[]='negative_has_positive_boxes';
    if($canonical!==''&&$labels&&count(array_filter($labels,static fn($x)=>strcasecmp($x,$canonical)!==0))>0)$issues[]='annotation_label_mismatch';
    $complete=count($issues)===0;
    return ['complete'=>$complete,'issues'=>array_values(array_unique($issues)),'boxAreas'=>$areas,'annotationCount'=>count($annotations),'canonicalLabel'=>$canonical];
}

function glasses_vision_annotation_qa_median(array $values): float
{
    if(!$values)return 0.0;sort($values,SORT_NUMERIC);$n=count($values);$mid=intdiv($n,2);
    return $n%2?(float)$values[$mid]:((float)$values[$mid-1]+(float)$values[$mid])/2;
}

function glasses_vision_annotation_qa_agreement(array $reviews): array
{
    $bySample=[];
    foreach($reviews as $r)$bySample[(string)$r['sample_public_id']][]=$r;
    $reviewed=0;$agreed=0;$disagreements=[];
    foreach($bySample as $sample=>$rows){
        if(count($rows)<2)continue;
        $reviewed++;
        $signatures=[];
        foreach($rows as $r)$signatures[strtolower(trim((string)$r['decision'])).'|'.strtolower(trim((string)($r['canonical_label']??'')))]=true;
        if(count($signatures)===1)$agreed++;else$disagreements[]=['samplePublicId'=>$sample,'reviewCount'=>count($rows),'signatures'=>array_keys($signatures)];
    }
    return [
        'multiReviewedSamples'=>$reviewed,'agreedSamples'=>$agreed,'disagreementSamples'=>count($disagreements),
        'agreementRate'=>$reviewed?round($agreed/$reviewed,4):null,'disagreements'=>$disagreements,
    ];
}

function glasses_vision_annotation_qa_analyze(PDO $pdo,int $org,?string $datasetPublic=null): array
{
    $datasetId=glasses_vision_dataset_intelligence_dataset_id($pdo,$org,$datasetPublic);
    $scope=$datasetId===null?'':" JOIN glasses_vision_dataset_items di ON di.organization_id=s.organization_id AND di.sample_id=s.id AND di.dataset_id=? ";
    $args=$datasetId===null?[$org]:[$datasetId,$org];
    $q=$pdo->prepare("SELECT s.id,s.public_id,s.canonical_label,s.annotation_json,s.review_status,s.source_type,s.source_reference
      FROM glasses_vision_training_samples s {$scope}
      WHERE s.organization_id=? ORDER BY s.id");
    $q->execute($args);$rows=$q->fetchAll();

    $sampleQa=[];$classAreas=[];$incomplete=0;$invalidBoxes=0;
    foreach($rows as $row){
        $qa=glasses_vision_annotation_qa_sample($row);
        if(!$qa['complete'])$incomplete++;
        foreach($qa['issues'] as $issue)if(str_contains($issue,'invalid_box')||str_contains($issue,'tiny_box')||str_contains($issue,'oversized_box'))$invalidBoxes++;
        foreach($qa['boxAreas'] as $area)if($qa['canonicalLabel']!=='')$classAreas[$qa['canonicalLabel']][]=$area;
        $sampleQa[(string)$row['public_id']]=[
            'samplePublicId'=>$row['public_id'],'label'=>$row['canonical_label'],'reviewStatus'=>$row['review_status'],
            'sourceType'=>$row['source_type'],'sourceReference'=>$row['source_reference'],
            'complete'=>$qa['complete'],'issues'=>$qa['issues'],'annotationCount'=>$qa['annotationCount'],
        ];
    }

    $classMedians=[];foreach($classAreas as $label=>$areas)$classMedians[$label]=glasses_vision_annotation_qa_median($areas);
    $boxOutliers=0;
    foreach($rows as $row){
        $label=(string)($row['canonical_label']??'');$median=$classMedians[$label]??0;
        if($median<=0)continue;
        $qa=glasses_vision_annotation_qa_sample($row);
        foreach($qa['boxAreas'] as $area)if($area<$median*.25||$area>$median*4){$boxOutliers++;$sampleQa[(string)$row['public_id']]['issues'][]='class_box_area_outlier';$sampleQa[(string)$row['public_id']]['complete']=false;}
        $sampleQa[(string)$row['public_id']]['issues']=array_values(array_unique($sampleQa[(string)$row['public_id']]['issues']));
    }

    $reviewScope=$datasetId===null?'':" JOIN glasses_vision_dataset_items di_r ON di_r.organization_id=r.organization_id AND di_r.sample_id=r.sample_id AND di_r.dataset_id=? ";
    $rq=$pdo->prepare("SELECT s.public_id sample_public_id,r.reviewer_user_id,r.decision,r.canonical_label,r.created_at
      FROM glasses_vision_sample_reviews r
      JOIN glasses_vision_training_samples s ON s.id=r.sample_id AND s.organization_id=r.organization_id
      {$reviewScope}
      WHERE r.organization_id=? AND r.correction_id IS NULL ORDER BY r.sample_id,r.id");
    $rq->execute($args);$reviews=$rq->fetchAll();
    $agreement=glasses_vision_annotation_qa_agreement($reviews);

    $adjudication=[];
    foreach($sampleQa as $qa)if($qa['reviewStatus']==='needs_adjudication'||in_array($qa['samplePublicId'],array_column($agreement['disagreements'],'samplePublicId'),true))$adjudication[]=$qa;
    $guidance=[];foreach(array_keys($classAreas) as $label)$guidance[] = glasses_vision_annotation_qa_guidance($label);
    if(!$guidance)$guidance[] = glasses_vision_annotation_qa_guidance('target object');

    $total=count($rows);$problemSamples=count(array_filter($sampleQa,static fn($x)=>!$x['complete']));
    $completeness=$total?max(0,1-($problemSamples/$total)):0;
    $agreementFactor=$agreement['agreementRate']??1.0;
    $score=(int)round(100*max(0,min(1,($completeness*.70)+($agreementFactor*.30))));

    return [
        'schema'=>'gelato.vision_annotation_qa.v1','datasetPublicId'=>$datasetPublic,
        'summary'=>[
            'samples'=>$total,'completeSamples'=>$total-$problemSamples,'incompleteSamples'=>$problemSamples,
            'boxIssueCount'=>$invalidBoxes+$boxOutliers,'boxOutliers'=>$boxOutliers,'unresolvedDisagreements'=>count($adjudication),'qaScore'=>$score,
        ],
        'agreement'=>$agreement,'classBoxAreaMedians'=>$classMedians,'samples'=>array_values($sampleQa),
        'adjudicationQueue'=>$adjudication,'guidance'=>$guidance,
    ];
}

function glasses_vision_annotation_qa_catalog(PDO $pdo,int $org): array
{
    return ['ready'=>glasses_vision_annotation_qa_ready($pdo),'schema'=>'gelato.vision_annotation_qa.v1'];
}
