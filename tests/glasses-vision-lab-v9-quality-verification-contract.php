<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-vision-quality-verification.php';

function v95_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
$pdo=app_pdo();
v95_assert(glasses_vision_quality_ready($pdo),'V9 quality-verification migration must be installed.');

$key='ingredient:42';
$base=[
 'context'=>[
  'recipePlan'=>['currentExpectedComponentKey'=>$key,'components'=>[['componentKey'=>$key,'displayName'=>'Cheese','status'=>'waiting','sortOrder'=>1,'expectedQuantity'=>1,'detectedQuantity'=>0,'unit'=>'portion','optional'=>false]]],
  'calibration'=>['regions'=>[[
    'regionKey'=>'build:1','regionType'=>'build_surface','displayName'=>'Build Surface',
    'metadata'=>['qualityRules'=>[
      'default'=>['minCoverage'=>.60,'minDistribution'=>.65,'maxEdgeOverflow'=>.15],
      $key=>['minCoverage'=>.72,'minDistribution'=>.75,'maxEdgeOverflow'=>.10]
    ]]
  ]]]
 ],
 'relationships'=>[],
 'entities'=>[
  ['entityKey'=>'pizza','kind'=>'product','label'=>'Pizza','componentKey'=>null,'confidence'=>.99,'attributes'=>[],'entityHash'=>hash('sha256','pizza')],
  ['entityKey'=>'cheese','kind'=>'ingredient','label'=>'cheese','componentKey'=>$key,'confidence'=>.97,
   'attributes'=>['placementEstimate'=>['coverage'=>.84,'distribution'=>.86,'edgeOverflow'=>.04,'confidence'=>.94]],'entityHash'=>hash('sha256','cheese')]
 ]
];

$pass=glasses_vision_quality_assess_scene($base);
v95_assert($pass['state']==='within_standard','Good coverage/distribution/edge placement must pass.');
v95_assert($pass['rule']['sourceRegionKey']==='build:1','Quality rule must come from governed build-surface calibration.');
v95_assert(abs((float)$pass['rule']['minCoverage']-.72)<.0001,'Component-specific calibration rule must override default.');
v95_assert($pass['score']>.80,'Passing geometry should retain a strong normalized score.');

$bad=$base;
$bad['entities'][1]['attributes']['placementEstimate']=['coverage'=>.55,'distribution'=>.60,'edgeOverflow'=>.18,'confidence'=>.95];
$fail=glasses_vision_quality_assess_scene($bad);
v95_assert($fail['state']==='needs_correction','Material placement-quality failures must request correction.');
v95_assert($fail['failedChecks']===['coverage','distribution','edge_overflow'],'Quality failures must be explicit and deterministic.');

$coverage=$base;$coverage['entities'][1]['attributes']['placementEstimate']=['coverage'=>.70,'distribution'=>.90,'edgeOverflow'=>.03,'confidence'=>.95];
$coverageFail=glasses_vision_quality_assess_scene($coverage);
v95_assert($coverageFail['state']==='needs_correction'&&$coverageFail['failedChecks']===['coverage'],'Coverage-only failure must not invent other defects.');

$occluded=$base;$occluded['entities']=[$base['entities'][1]];
$occ=glasses_vision_quality_assess_scene($occluded);
v95_assert($occ['state']==='insufficient'&&$occ['reason']==='product_not_visible','Occluded product must be insufficient, never a guessed quality failure.');

$low=$base;$low['entities'][1]['attributes']['placementEstimate']['confidence']=.50;
$lowResult=glasses_vision_quality_assess_scene($low);
v95_assert($lowResult['state']==='insufficient'&&$lowResult['reason']==='no_quality_evidence','Low-confidence placement evidence must be ignored.');

$none=$base;unset($none['entities'][1]['attributes']['placementEstimate']);
$noneResult=glasses_vision_quality_assess_scene($none);
v95_assert($noneResult['state']==='insufficient'&&$noneResult['reason']==='no_quality_evidence','Missing geometry evidence must remain insufficient.');

$default=$base;$default['context']['recipePlan']['currentExpectedComponentKey']='ingredient:77';$default['context']['recipePlan']['components'][0]['componentKey']='ingredient:77';$default['entities'][1]['componentKey']='ingredient:77';
$defaultResult=glasses_vision_quality_assess_scene($default);
v95_assert(abs((float)$defaultResult['rule']['minCoverage']-.60)<.0001,'Calibration default quality rule must be used when no component override exists.');

$dev=file_get_contents(__DIR__.'/../api/glasses-device.php');
$lab=file_get_contents(__DIR__.'/../api/glasses-vision-lab.php');
$page=file_get_contents(__DIR__.'/../glasses-vision-lab.php');
$source=file_get_contents(__DIR__.'/../includes/glasses-vision-quality-verification.php');
$migration=file_get_contents(__DIR__.'/../database/20261121_vision_lab_v9_quality_verification.sql');
foreach(['vision.quality.verify_scene','vision.quality.verify'] as $a)v95_assert(str_contains($dev,$a),'Device API missing '.$a);
foreach(['quality.verify_scene','quality.verify'] as $a)v95_assert(str_contains($lab,$a),'Vision Lab API missing '.$a);
v95_assert(str_contains($page,'Build Quality / Placement Verification'),'Vision Lab must expose the Section 5 panel.');
v95_assert(str_contains($migration,'CREATE TABLE glasses_vision_quality_verifications'),'Section 5 schema missing.');
v95_assert(str_contains($source,'current canonical build state'),'Quality verification must reject stale build context.');
v95_assert(str_contains($source,'verified_build_quality_as'),'Quality verification must retain scene lineage.');
v95_assert(str_contains($source,"hash('sha256'"),'Quality verification must persist immutable hashes.');
foreach(['glasses_build_confirm','glasses_build_observe','kds_transition','glasses_build_complete','pos_'] as $forbidden)
 v95_assert(!str_contains($source,$forbidden),'Quality verification must remain advisory-only: '.$forbidden);

echo "vision-lab-v9-quality-verification-ok\n";
