<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-final-validation.php';

function v96_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
$pdo=app_pdo();
v96_assert(glasses_vision_final_ready($pdo),'V9 final-validation migration must be installed.');

$base=[
 'context'=>[
   'buildSession'=>['summary'=>['required'=>2,'confirmed'=>2,'verify'=>0,'unexpected'=>0,'accounted'=>true,'currentComponentKey'=>null]],
   'calibration'=>['regions'=>[[
     'regionKey'=>'build:1','regionType'=>'build_surface','metadata'=>['presentationRules'=>[
       'minimumPresentationScore'=>.85,
       'blockingDefects'=>['foreign_object','contamination','burned','damaged','missing_assembly','wrong_packaging'],
       'warningDefects'=>['messy_edge','uneven_cut']
     ]]
   ]]]
 ],
 'entities'=>[[
   'entityKey'=>'product','kind'=>'product','label'=>'Pizza','confidence'=>.99,
   'attributes'=>['presentationEstimate'=>['score'=>.92,'confidence'=>.95,'defects'=>[]]]
 ]]
];

$pass=glasses_vision_final_assess_scene($base);
v96_assert($pass['state']==='ready_candidate'&&$pass['readyCandidate']===true,'Good final presentation must become ready candidate.');
v96_assert(abs((float)$pass['rule']['minimumPresentationScore']-.85)<.0001,'Governed calibration threshold must be used.');

$low=$base;$low['entities'][0]['attributes']['presentationEstimate']['score']=.72;
$r=glasses_vision_final_assess_scene($low);
v96_assert($r['state']==='needs_correction'&&$r['reason']==='presentation_score_below_standard','Low presentation score must require correction.');

$defect=$base;$defect['entities'][0]['attributes']['presentationEstimate']['defects']=[['type'=>'foreign_object','confidence'=>.97]];
$r=glasses_vision_final_assess_scene($defect);
v96_assert($r['state']==='needs_correction'&&$r['reason']==='blocking_visual_defect','Blocking defect must require correction.');
v96_assert(($r['defects'][0]['severity']??'')==='blocking','Blocking defect classification must be explicit.');

$warn=$base;$warn['entities'][0]['attributes']['presentationEstimate']['defects']=[['type'=>'messy_edge','confidence'=>.92]];
$r=glasses_vision_final_assess_scene($warn);
v96_assert($r['state']==='ready_candidate'&&($r['defects'][0]['severity']??'')==='warning','Warning-only defect may remain a ready candidate.');

$notReady=$base;$notReady['context']['buildSession']['summary']['accounted']=false;
$r=glasses_vision_final_assess_scene($notReady);
v96_assert($r['state']==='not_ready_for_final_validation','Incomplete canonical build must not be final-validated.');

$occluded=$base;$occluded['entities']=[];
$r=glasses_vision_final_assess_scene($occluded);
v96_assert($r['state']==='insufficient'&&$r['reason']==='product_not_visible','Occluded final product must be insufficient.');

$weak=$base;$weak['entities'][0]['attributes']['presentationEstimate']['confidence']=.50;
$r=glasses_vision_final_assess_scene($weak);
v96_assert($r['state']==='insufficient'&&$r['reason']==='presentation_evidence_insufficient','Weak presentation evidence must be insufficient.');

$dev=file_get_contents(__DIR__.'/../api/glasses-device.php');
$lab=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$source=file_get_contents(__DIR__.'/../includes/glasses-vision-final-validation.php');
foreach(['vision.final.validate_scene','vision.final.verify'] as $a)v96_assert(str_contains($dev,$a),'Device API missing '.$a);
foreach(['final.validate_scene','final.verify'] as $a)v96_assert(str_contains($lab,$a),'Vision Lab API missing '.$a);
v96_assert(str_contains($page,'Final Product Validation / Presentation Quality'),'Vision Lab must expose Section 6.');
v96_assert(str_contains($source,'current canonical build state'),'Final validation must reject stale scene context.');
v96_assert(str_contains($source,'validated_final_product_as'),'Final validation must retain lineage.');
foreach(['glasses_build_confirm','glasses_build_observe','glasses_build_complete','kds_transition','pos_'] as $forbidden)
 v96_assert(!str_contains($source,$forbidden),'Final validation must not mutate canonical kitchen truth: '.$forbidden);

echo "vision-lab-v9-final-product-validation-ok\n";
