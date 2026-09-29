<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-cook-ux.php';

function v106_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}

$policy=glasses_cook_ux_policy();
v106_assert($policy['schema']===GLASSES_COOK_UX_SCHEMA,'Cook UX schema mismatch.');
v106_assert($policy['centerPersistentProjectionAllowed']===false,'Persistent center projection must be prohibited.');
v106_assert($policy['transientCenterEvidenceAllowed']===true,'Transient detection evidence may use the center scene.');
foreach(['top_status','left_orders','right_workflow'] as $zone)
 v106_assert(in_array($zone,$policy['persistentProjectionZones'],true),'Missing persistent projection zone '.$zone);
foreach(['current_item','next_step','ingredient_warning','portion_correction','placement_correction','rework_required','final_validation','explicit_handoff','stop_cancel','recovery_blocked'] as $state)
 v106_assert(in_array($state,$policy['requiredWorkflowStates'],true),'Missing cook workflow state '.$state);

$pass=glasses_cook_ux_evaluate([
 'statesCovered'=>$policy['requiredWorkflowStates'],
 'centerClear'=>true,'persistentCenterProjection'=>false,
 'currentItemVisible'=>true,'nextStepVisible'=>true,'warningsRightRail'=>true,
 'portionCorrectionVisible'=>true,'placementCorrectionVisible'=>true,'reworkVisible'=>true,
 'finalValidationVisible'=>true,'explicitHandoffRequired'=>true,'stopCancelsAutomatic'=>true,
 'recoveryBlocksActions'=>true
]);
v106_assert($pass['passed']===true&&$pass['score']===10.0,'Complete cook UX report must score 10/10.');

$fail=glasses_cook_ux_evaluate([
 'statesCovered'=>['current_item'],'centerClear'=>false,'persistentCenterProjection'=>true,
 'currentItemVisible'=>true
]);
v106_assert($fail['passed']===false&&$fail['score']<10,'Unsafe cook UX report must fail.');
v106_assert(in_array('next_step',$fail['missingStates'],true),'Incomplete state coverage must be reported.');

$page=file_get_contents(__DIR__.'/../glasses-simulator.php');
$css=file_get_contents(__DIR__.'/../assets/css/glasses-web-simulator.css');
$js=file_get_contents(__DIR__.'/../assets/js/glasses-web-simulator.js');
$harness=file_get_contents(__DIR__.'/../assets/js/glasses-v10-cook-ux-harness.js');
$sim=file_get_contents(__DIR__.'/../includes/glasses-simulator.php');

v106_assert(str_contains($page,'hudCenterClear'),'HUD must declare a clear center region.');
v106_assert(str_contains($page,'hud-next-card')&&str_contains($page,'hud-right-build'),'NEXT guidance must live inside the right workflow rail.');
v106_assert(!str_contains($page,'hud-leader-line'),'Persistent center leader-line projection must be removed.');
v106_assert(str_contains($page,'hudCookAlert'),'HUD must expose cook warning/rework state on the right rail.');
v106_assert(str_contains($css,'.hud-center-clear')&&str_contains($css,'.hud-next-card'),'Clear-center/right-next styling must exist.');
v106_assert(!str_contains($css,'.hud-next-center'),'Legacy center NEXT styling must be removed.');
v106_assert(str_contains($js,'function stopCookVision(')&&str_contains($js,'function resumeCookVision('),'STOP/resume interaction must exist.');
v106_assert(str_contains($js,"e.key==='Escape'"),'Escape must provide simulator STOP/cancel input.');
v106_assert(str_contains($js,'if(!state.cookUx.handoffArmed)'),'Expo handoff must require an explicit second confirmation.');
v106_assert(str_contains($js,"$('handoffExpo').textContent='Confirm Send to Expo'"),'Explicit handoff confirmation must be visible.');
v106_assert(str_contains($js,'state.cookUx.stopped')&&str_contains($js,'automaticObservationsAllowed'),'STOP must gate automatic observations.');
v106_assert(!str_contains($js,"bindRegionEditor('hudNextRegion')"),'NEXT must not be independently draggable into center view.');
v106_assert(str_contains($sim,"hardware.cook_ux.evaluate"),'Simulator dispatch must expose read-only UX acceptance evaluation.');
v106_assert(str_contains($harness,'portion_correction')&&str_contains($harness,'placement_correction')&&str_contains($harness,'rework_required'),'Harness must cover correction/rework states.');

$source=file_get_contents(__DIR__.'/../includes/glasses-cook-ux.php');
foreach(['kds_transition(','glasses_build_confirm(','glasses_handoff_to_expo(','UPDATE glasses_'] as $forbidden)
 v106_assert(!str_contains($source,$forbidden),'Cook UX evaluator must remain read-only: '.$forbidden);

echo "glasses-v10-cook-ux-ok\n";
