<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/UpgradeService.php';
require_once __DIR__.'/../includes/glasses-vision-v9-release.php';

function v910_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$pdo=app_pdo();$root=dirname(__DIR__);
$manifest=glasses_vision_v9_release_manifest();
$readiness=glasses_vision_v9_release_readiness($pdo,$root);

v910_assert($manifest['release']==='v9.0-rc1','Release identifier must be V9 RC1.');
v910_assert(count($manifest['sections'])===9,'V9 RC1 must contain exactly Sections 1-9.');
v910_assert($readiness['ready']===true,'V9 RC1 readiness must be complete: '.json_encode($readiness['missing']));
v910_assert($readiness['sectionCount']===9,'Release readiness must see all nine sections.');

$upgrade=new UpgradeService($pdo,$root);
$upgrade->ensureTrackingTables();
$upgrade->assertAppliedChecksumsUnchanged();
v910_assert($upgrade->pendingMigrations()===[],'V9 RC1 must have no pending migrations.');
v910_assert($upgrade->applyPending(null)===[],'V9 RC1 upgrade must be idempotent.');

$runtimes=[
  1=>'includes/glasses-vision-scene.php',
  2=>'includes/glasses-vision-step-recognition.php',
  3=>'includes/glasses-vision-ingredient-prevention.php',
  4=>'includes/glasses-vision-quantity-verification.php',
  5=>'includes/glasses-vision-quality-verification.php',
  6=>'includes/glasses-vision-final-validation.php',
  7=>'includes/glasses-vision-handoff-confirmation.php',
  8=>'includes/glasses-vision-rework.php',
  9=>'includes/glasses-vision-kitchen-outcomes.php',
];
foreach($runtimes as $section=>$path){
    $source=file_get_contents($root.'/'.$path);
    v910_assert($source!==false,'Section '.$section.' runtime must be readable.');
    if($section!==7)v910_assert(!str_contains($source,'glasses_handoff_to_expo('),'Only Section 7 may invoke canonical Expo handoff; violation in Section '.$section);
    if($section<=6||$section>=8){
        v910_assert(!str_contains($source,'kds_transition('),'Non-handoff V9 runtime must not directly transition KDS; Section '.$section);
        v910_assert(!str_contains($source,'glasses_build_complete('),'Non-handoff V9 runtime must not directly complete build; Section '.$section);
    }
}
$handoff=file_get_contents($root.'/'.$runtimes[7]);
v910_assert(substr_count($handoff,'glasses_handoff_to_expo(')===1,'Section 7 must delegate exactly once to canonical Expo handoff.');
v910_assert(!str_contains(file_get_contents($root.'/includes/glasses-vision-scene.php'),'reserved_for_v9_section_2'),'RC1 must remove obsolete Section 2 placeholder.');

$deviceApi=file_get_contents($root.'/api/glasses-device.php');
foreach([
 'vision.scene.capture','vision.step.recognize','vision.ingredient_guard.assess','vision.quantity.verify_scene',
 'vision.quality.verify_scene','vision.final.validate_scene','vision.handoff.confirm','vision.rework.open','vision.rework.revalidate'
] as $action)v910_assert(str_contains($deviceApi,$action),'Device API missing V9 action '.$action);

$labApi=file_get_contents($root.'/api/glasses-vision-lab.php');
foreach(['ingredient_guard.assess','quantity.verify_scene','quality.verify_scene','final.validate_scene','outcomes.sync'] as $action)
 v910_assert(str_contains($labApi,$action),'Vision Lab API missing governed V9 action '.$action);
v910_assert(!str_contains($labApi,"vision.handoff.confirm"),'Vision Lab must not expose device-only consequential handoff confirmation.');
v910_assert(!str_contains($labApi,"vision.rework.open"),'Vision Lab must not expose device-only rework execution.');

$workflow=file_get_contents($root.'/.github/workflows/glasses-vision-lab-v9.yml');
for($n=1;$n<=9;$n++)v910_assert(str_contains($workflow,'V9 '),'V9 workflow must exist.');
foreach([
 'V9 live scene understanding contract','V9 recipe step recognition contract','V9 missing / wrong ingredient prevention contract',
 'V9 portion / quantity verification contract','V9 build quality / placement verification contract','V9 final product validation contract',
 'V9 governed human handoff confirmation contract','V9 exception recovery / rework / revalidation contract',
 'V9 production learning from kitchen outcomes contract'
] as $gate)v910_assert(str_contains($workflow,$gate),'Missing V9 release gate: '.$gate);
v910_assert(str_contains($workflow,'Upgrade idempotence'),'V9 release gate must enforce upgrade idempotence.');

$page=file_get_contents($root.'/glasses-vision-lab.php');
foreach([
 'Live Scene','Recipe Step','Missing / Wrong Ingredient Prevention','Portion / Quantity Verification',
 'Build Quality / Placement Verification','Final Product Validation / Presentation Quality',
 'Governed Human Confirmation & Kitchen Handoff','Exception Recovery, Rework & Revalidation',
 'Production Learning from Kitchen Outcomes'
] as $label)v910_assert(str_contains($page,$label),'Vision Lab release surface missing '.$label);

foreach([
 'glasses_vision_scene_capture','glasses_vision_step_recognize','glasses_vision_ingredient_guard_assess',
 'glasses_vision_quantity_verify_scene','glasses_vision_quality_verify_scene','glasses_vision_final_validate_scene',
 'glasses_vision_handoff_confirm','glasses_vision_rework_revalidate','glasses_vision_kitchen_outcome_sync'
] as $fn)v910_assert(function_exists($fn),'Release runtime missing '.$fn);

echo "vision-lab-v9-release-acceptance-ok\n";
