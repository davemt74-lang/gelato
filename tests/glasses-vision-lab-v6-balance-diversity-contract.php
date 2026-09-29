<?php
declare(strict_types=1);

require __DIR__.'/../includes/glasses-vision-balance.php';

function v63_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}

v63_assert(glasses_vision_balance_label_is_negative('background'),'Background must be classified as a negative example.');
v63_assert(glasses_vision_balance_label_is_negative(''),'Empty canonical labels must be treated as negative/unlabeled evidence.');
v63_assert(!glasses_vision_balance_label_is_negative('pepperoni'),'Named food classes must remain positive.');
v63_assert(glasses_vision_balance_is_hard(['sourceType'=>'corrected_production']),'Corrected production evidence must count as hard evidence.');
v63_assert(glasses_vision_balance_is_hard(['sourceType'=>'active_learning_hard']),'Active/hard learning evidence must count as hard evidence.');
v63_assert(!glasses_vision_balance_is_hard(['sourceType'=>'manual']),'Ordinary manual evidence must not be promoted to hard evidence.');
v63_assert(glasses_vision_balance_bucket_pose(16,31,14)==='p15_y30_r15','Pose bucketing must be deterministic.');

$dist=glasses_vision_balance_distribution([
 ['operatorName'=>'A'],['operatorName'=>'A'],['operatorName'=>'A'],['operatorName'=>'B'],
],'operatorName');
$over=glasses_vision_balance_overrepresented($dist,4,.50);
v63_assert(count($over)===1&&$over[0]['value']==='A'&&abs($over[0]['share']-.75)<.0001,'Dominant environment detection must report overrepresented values.');

$source=file_get_contents(__DIR__.'/../includes/glasses-vision-balance.php');
$api=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$js=file_get_contents(__DIR__.'/../assets/js/glasses-vision-balance.js');

v63_assert(str_contains($source,"'mutationApplied'=>false"),'Optimizer must explicitly state that analysis applies no mutations.');
v63_assert(str_contains($source,"reason'=>'class_below_target")&&str_contains($source,"reason'=>'negative_ratio_below_target")&&str_contains($source,"reason'=>'hard_example_ratio_below_target"),'Optimizer must cover class, negative and hard-example deficits.');
v63_assert(str_contains($source,"reason'=>'overrepresented_environment")&&str_contains($source,"reason'=>'pose_diversity_low")&&str_contains($source,"reason'=>'lighting_diversity_low"),'Optimizer must cover environment, pose and lighting diversity.');
v63_assert(str_contains($source,"reason'=>'exact_duplicate")&&str_contains($source,"reason'=>'hard_visual_quality_blocker"),'Optimizer must surface duplicate and hard-quality removals.');
v63_assert(str_contains($api,"balance.analyze")&&!str_contains($api,'glasses_build_observe')&&!str_contains($api,'kds_transition'),'Balance API must be exposed without production truth mutation.');
v63_assert(str_contains($page,'Dataset Balance &amp; Diversity Optimizer')&&str_contains($page,'vlBalanceAnalyze'),'Vision Lab must expose the optimizer workspace.');
v63_assert(str_contains($js,'No curation or split membership is changed automatically.')&&str_contains($js,"targetHardRatio:.25"),'UI must explain advisory behavior and deterministic default policy.');

// Release contract also anchors exact-head CI after documentation changes.
echo "vision-lab-v6-balance-diversity-ok\n";
