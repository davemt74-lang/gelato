<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/UpgradeService.php';
require_once __DIR__.'/../includes/glasses-v10-release.php';

function v108_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}

$pdo=app_pdo();$root=dirname(__DIR__);
$manifest=glasses_v10_release_manifest();
$ready=glasses_v10_release_readiness($pdo,$root);

v108_assert($manifest['release']==='v10.0-rc1','Release identifier must be V10 RC1.');
v108_assert(count($manifest['sections'])===7,'V10 RC1 must contain exactly Sections 1-7.');
v108_assert($ready['ready']===true,'V10 RC1 readiness must be complete: '.json_encode($ready['missing']));
v108_assert($ready['sectionCount']===7,'Release readiness must see all seven sections.');
v108_assert(($manifest['adapterBoundary']['currentAdapter']??'')==='simulator.v1','Simulator must remain the current pre-SDK adapter.');
v108_assert(($manifest['adapterBoundary']['futureVendorAdapter']??'')==='air3.vendor.v1','AIR3 vendor adapter identity must be locked.');
v108_assert(($manifest['adapterBoundary']['vendorSdkInstalled']??true)===false,'RC1 must not pretend the proprietary AIR3 SDK is installed.');

$upgrade=new UpgradeService($pdo,$root);
$upgrade->ensureTrackingTables();
$upgrade->assertAppliedChecksumsUnchanged();
v108_assert($upgrade->pendingMigrations()===[],'V10 RC1 must have no pending migrations.');
v108_assert($upgrade->applyPending(null)===[],'V10 RC1 upgrade must be idempotent.');

$adapter=glasses_hardware_runtime_adapter('simulator.v1');
v108_assert($adapter instanceof GlassesHardwareRuntimeAdapter,'Current adapter must implement the vendor-neutral interface.');
$contract=glasses_hardware_runtime_contract('simulator.v1');
v108_assert(($contract['sdkBoundary']['requiredAdapterId']??'')==='air3.vendor.v1','Hardware contract must expose future AIR3 adapter ID.');
v108_assert(($contract['sdkBoundary']['vendorAdapterInstalled']??true)===false,'Hardware contract must report vendor adapter absent.');

$unknownRejected=false;
try{glasses_hardware_runtime_adapter('air3.vendor.v1');}catch(InvalidArgumentException){$unknownRejected=true;}
v108_assert($unknownRejected,'air3.vendor.v1 must not resolve until the actual SDK adapter exists.');

foreach([
 'v9_kitchen_intelligence','browser_simulator','browser_camera','governed_onnx','frame_backpressure',
 'health_diagnostics','recovery','soak','cook_ux','pilot_governance'
] as $cap)v108_assert(in_array($cap,$manifest['adapterBoundary']['notBlockedBySdk'],true),'Non-SDK capability missing from RC1: '.$cap);
foreach([
 'native_camera','native_display','native_input','vendor_inference','battery_telemetry','thermal_telemetry','physical_hardware_acceptance'
] as $cap)v108_assert(in_array($cap,$manifest['adapterBoundary']['blockedBySdk'],true),'SDK-dependent capability missing from RC1 boundary: '.$cap);

$sectionFiles=[
 'includes/glasses-hardware-runtime.php','includes/glasses-frame-pipeline.php','includes/glasses-device-health.php',
 'includes/glasses-runtime-recovery.php','includes/glasses-runtime-soak.php','includes/glasses-cook-ux.php',
 'includes/glasses-production-pilot.php'
];
foreach($sectionFiles as $path){
  $src=file_get_contents($root.'/'.$path);
  v108_assert($src!==false,'V10 runtime must be readable: '.$path);
  if($path!=='includes/glasses-production-pilot.php'){
    v108_assert(!str_contains($src,'glasses_vision_model_rollout_rollback('),'Only pilot governance may delegate rollout rollback: '.$path);
  }
  v108_assert(!str_contains($src,'kds_transition('),'V10 runtime must not directly transition KDS: '.$path);
  v108_assert(!str_contains($src,'glasses_handoff_to_expo('),'V10 runtime must not bypass governed handoff: '.$path);
  v108_assert(!str_contains($src,'glasses_build_confirm('),'V10 runtime must not directly confirm build truth: '.$path);
}

$hw=file_get_contents($root.'/includes/glasses-hardware-runtime.php');
foreach(['AIR3SDK','AIR3Sdk','InmoSdk','INMOSDK','vendor_model_loader','Air3Camera','Air3Display','Air3Inference'] as $invented)
 v108_assert(!str_contains($hw,$invented),'V10 must not invent proprietary AIR3 SDK API: '.$invented);

$soakReport=[
 'cycles'=>500,'framesProduced'=>620,'framesProcessed'=>480,'framesDropped'=>110,'framesStale'=>10,'framesTimedOut'=>20,
 'recoveryAttempts'=>18,'recoverySuccesses'=>18,'recoveryFailures'=>0,'maxObservedQueueDepth'=>3,'maxConcurrentWorkers'=>1,
 'stalledCycles'=>0,'unhandledErrors'=>0,'finalQueueDepth'=>0,'workerActive'=>false,'monotonicDelivery'=>true,'finalRecoveryState'=>'ready'
];
$soak=glasses_runtime_soak_evaluate($soakReport);
v108_assert($soak['passed']===true&&$soak['score']===10.0,'V10 RC1 representative soak acceptance must score 10/10.');

$ux=glasses_cook_ux_evaluate([
 'statesCovered'=>glasses_cook_ux_policy()['requiredWorkflowStates'],
 'centerClear'=>true,'persistentCenterProjection'=>false,'currentItemVisible'=>true,'nextStepVisible'=>true,
 'warningsRightRail'=>true,'portionCorrectionVisible'=>true,'placementCorrectionVisible'=>true,'reworkVisible'=>true,
 'finalValidationVisible'=>true,'explicitHandoffRequired'=>true,'stopCancelsAutomatic'=>true,'recoveryBlocksActions'=>true
]);
v108_assert($ux['passed']===true&&$ux['score']===10.0,'V10 RC1 cook UX acceptance must score 10/10.');

$pilotSource=file_get_contents($root.'/includes/glasses-production-pilot.php');
v108_assert(str_contains($pilotSource,"'air3.vendor.v1'"),'Production pilots must default to the real AIR3 adapter requirement.');
v108_assert(str_contains($pilotSource,'required_hardware_adapter_unavailable'),'Pilot readiness must block missing AIR3 adapter.');
v108_assert(str_contains($pilotSource,'glasses_vision_model_rollout_rollback'),'Pilot rollback must delegate to canonical rollout governance.');

$deviceApi=file_get_contents($root.'/api/glasses-device.php');
v108_assert(str_contains($deviceApi,'productionMode')&&str_contains($deviceApi,'glasses_production_pilot_assert_device_ready'),'Production model assignment must remain pilot-gated.');

$simJs=file_get_contents($root.'/assets/js/glasses-web-simulator.js');
v108_assert(str_contains($simJs,'function processFrameQueue('),'RC1 must retain bounded frame processing.');
v108_assert(str_contains($simJs,'function resumeRuntimeRecovery('),'RC1 must retain reconnect recovery.');
v108_assert(str_contains($simJs,'function stopCookVision('),'RC1 must retain explicit STOP behavior.');
v108_assert(!str_contains($simJs,'async function runVisionFrame()'),'Legacy direct frame loop must remain removed.');
v108_assert(!str_contains($simJs,"bindRegionEditor('hudNextRegion')"),'Legacy center NEXT calibration path must remain removed.');

$page=file_get_contents($root.'/glasses-simulator.php');
v108_assert(str_contains($page,'hudCenterClear'),'RC1 must preserve a clear center field of view.');
v108_assert(!str_contains($page,'hud-leader-line'),'Legacy persistent center leader line must remain removed.');

$workflow=file_get_contents($root.'/.github/workflows/glasses-v10.yml');
foreach([
 'V10 hardware runtime adapter contract','V10 frame pipeline and backpressure contract',
 'V10 device health telemetry and diagnostics contract','V10 disconnect reconnect recovery contract',
 'V10 station soak acceptance contract','V10 deterministic soak harness',
 'V10 cook on-lens UX acceptance contract','V10 cook UX deterministic harness',
 'V10 production pilot governance contract'
] as $gate)v108_assert(str_contains($workflow,$gate),'Missing V10 RC1 workflow gate: '.$gate);
v108_assert(str_contains($workflow,'V9 RC1 regression'),'V10 RC1 must retain V9 release regression.');

echo "glasses-v10-rc1-acceptance-ok\n";
