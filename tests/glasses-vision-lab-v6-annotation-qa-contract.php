<?php
declare(strict_types=1);
require __DIR__.'/../includes/glasses-vision-annotation-qa.php';
function v64_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}

$b=glasses_vision_annotation_qa_box(['x'=>.1,'y'=>.1,'width'=>.4,'height'=>.3]);
v64_assert($b['valid']&&abs($b['area']-.12)<.000001,'Normalized boxes must validate and expose area.');
v64_assert(!glasses_vision_annotation_qa_box(['x'=>.8,'y'=>.1,'width'=>.4,'height'=>.3])['valid'],'Boxes outside image bounds must fail.');

$good=glasses_vision_annotation_qa_sample(['canonical_label'=>'pepperoni','annotation_json'=>json_encode([['label'=>'pepperoni','bbox'=>['x'=>.1,'y'=>.1,'width'=>.3,'height'=>.3]]])]);
v64_assert($good['complete']===true,'Matching labeled object annotations must pass completeness.');
$missing=glasses_vision_annotation_qa_sample(['canonical_label'=>'pepperoni','annotation_json'=>'[]']);
v64_assert(!$missing['complete']&&in_array('missing_annotation',$missing['issues'],true),'Positive classes require an annotation.');
$neg=glasses_vision_annotation_qa_sample(['canonical_label'=>'background','annotation_json'=>json_encode([['label'=>'pepperoni','bbox'=>['x'=>.1,'y'=>.1,'width'=>.3,'height'=>.3]]])]);
v64_assert(!$neg['complete']&&in_array('negative_has_positive_boxes',$neg['issues'],true),'Negative frames must not contain positive boxes.');

$agreement=glasses_vision_annotation_qa_agreement([
 ['sample_public_id'=>'a','decision'=>'approve','canonical_label'=>'pepperoni'],
 ['sample_public_id'=>'a','decision'=>'approve','canonical_label'=>'pepperoni'],
 ['sample_public_id'=>'b','decision'=>'approve','canonical_label'=>'cheese'],
 ['sample_public_id'=>'b','decision'=>'relabel','canonical_label'=>'mozzarella'],
]);
v64_assert($agreement['multiReviewedSamples']===2&&$agreement['agreedSamples']===1&&abs($agreement['agreementRate']-.5)<.0001,'Reviewer agreement must compare decision + canonical label signatures.');
v64_assert(count($agreement['disagreements'])===1&&$agreement['disagreements'][0]['samplePublicId']==='b','Reviewer disagreement must enter adjudication evidence.');

v64_assert(abs(glasses_vision_annotation_qa_median([.1,.2,.3])-.2)<.0001,'Median box area must be deterministic.');
v64_assert(str_contains(glasses_vision_annotation_qa_guidance('pepperoni')['instructions'],'tight box'),'Positive class guidance must require tight visible boxes.');
v64_assert(glasses_vision_annotation_qa_guidance('background')['mode']==='negative','Negative classes need explicit no-positive-box guidance.');

$source=file_get_contents(__DIR__.'/../includes/glasses-vision-annotation-qa.php');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$js=file_get_contents(__DIR__.'/../assets/js/glasses-vision-annotation-qa.js');
v64_assert(str_contains($source,'class_box_area_outlier')&&str_contains($source,'annotation_label_mismatch'),'QA must check box consistency and annotation-label consistency.');
v64_assert(str_contains($source,"'adjudicationQueue'")&&str_contains($source,"'agreementRate'"),'QA must expose reviewer agreement and adjudication queue.');
v64_assert(str_contains($api,'annotation_qa.analyze')&&!str_contains($api,'kds_transition'),'Annotation QA API must not mutate production state.');
v64_assert(str_contains($page,'Annotation QA &amp; Agreement')&&str_contains($js,'Adjudication queue'),'Vision Lab must expose annotation QA and adjudication UI.');
// Anchor exact-head CI after documentation updates.
echo "vision-lab-v6-annotation-qa-ok\n";
