<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-device-health.php';

function v103_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}

$health=glasses_device_health_normalize([
 'camera'=>['state'=>'ready','source'=>'browser_camera','width'=>1280,'height'=>720],
 'framePipeline'=>['fps'=>4.25,'latencyMs'=>87.4,'queueDepth'=>2,'acceptedFrames'=>10,'processedFrames'=>8,'droppedFrames'=>1,'staleFrames'=>1,'timedOutFrames'=>0,'cancelledFrames'=>0,'failedFrames'=>0,'maxObservedQueueDepth'=>3,'lastProcessedSequence'=>8],
 'inference'=>['state'=>'ready','adapterId'=>'onnx','runtime'=>'onnx'],
 'model'=>['detectorName'=>'food_detector','packagePublicId'=>'vision-package-1','artifactSha256'=>hash('sha256','model'),'status'=>'ready'],
 'runtime'=>['state'=>'ready','adapterId'=>'simulator.v1','reconnectCount'=>2,'syncErrorCount'=>0],
 'hardware'=>['batteryPercent'=>null,'temperatureC'=>null],
 'errors'=>[['type'=>'VISION','message'=>'example','at'=>'2026-09-29T00:00:00Z']]
]);
v103_assert($health['schema']===GLASSES_DEVICE_HEALTH_SCHEMA,'Health schema mismatch.');
v103_assert($health['health']==='healthy','Healthy simulator state should remain healthy.');
v103_assert($health['framePipeline']['fps']===4.25&&$health['framePipeline']['processedFrames']===8,'Frame telemetry must normalize.');
v103_assert($health['runtime']['reconnectCount']===2,'Reconnect count must normalize.');
v103_assert($health['hardware']['batteryPercent']===null&&$health['hardware']['temperatureC']===null,'AIR3 battery/thermal must remain nullable before vendor SDK.');
v103_assert($health['hardware']['batterySource']==='vendor_sdk_pending'&&$health['hardware']['thermalSource']==='vendor_sdk_pending','Missing AIR3 telemetry must carry explicit SDK-pending provenance.');

$degraded=glasses_device_health_normalize([
 'camera'=>['state'=>'ready'],'framePipeline'=>['timedOutFrames'=>1],
 'inference'=>['state'=>'error'],'runtime'=>['state'=>'degraded']
]);
v103_assert($degraded['health']==='degraded','Timeout/error state must degrade device health.');

$bundle=glasses_device_health_bundle([
 'camera'=>['state'=>'ready'],'framePipeline'=>[],'inference'=>['state'=>'ready'],'runtime'=>['state'=>'ready']
],[
 'devicePublicId'=>'glasses-test','stationPublicId'=>'station-test','buildSessionPublicId'=>'build-test','kdsItemPublicId'=>'kds-test','simulator'=>true
]);
v103_assert($bundle['schema']==='gelato.glasses_diagnostic_bundle.v1','Diagnostic bundle schema mismatch.');
v103_assert($bundle['context']['devicePublicId']==='glasses-test'&&!empty($bundle['context']['simulator']),'Diagnostic context must preserve runtime identity.');
v103_assert($bundle['sdkBoundary']['air3VendorTelemetryInstalled']===false,'Diagnostic bundle must not pretend AIR3 telemetry is installed.');
foreach(['batteryPercent','temperatureC','nativeCameraHealth','nativeDisplayHealth'] as $field)
 v103_assert(in_array($field,$bundle['sdkBoundary']['pending'],true),'Missing SDK-pending diagnostic field '.$field);

$source=file_get_contents(__DIR__.'/../includes/glasses-device-health.php');
$sim=file_get_contents(__DIR__.'/../includes/glasses-simulator.php');
$js=file_get_contents(__DIR__.'/../assets/js/glasses-web-simulator.js');
$page=file_get_contents(__DIR__.'/../glasses-simulator.php');

v103_assert(str_contains($sim,"hardware.health.normalize")&&str_contains($sim,"hardware.diagnostics.bundle"),'Simulator dispatch must expose normalized health and diagnostic bundle actions.');
v103_assert(str_contains($js,'function rawDeviceHealthSnapshot(')&&str_contains($js,'function refreshDeviceHealth('),'Simulator must collect and normalize live health.');
v103_assert(str_contains($js,'function downloadDeviceDiagnostics('),'Simulator must export diagnostic bundles.');
v103_assert(str_contains($js,'state.reconnectCount+=1'),'Recovered live sync must increment reconnect telemetry.');
v103_assert(str_contains($page,'V10 DEVICE HEALTH')&&str_contains($page,'Download Diagnostics'),'Simulator UI must expose V10 health and diagnostics.');
v103_assert(str_contains($js,"setInterval(refreshDeviceHealth,5000)"),'Health snapshot must refresh periodically.');

foreach(['kds_transition(','glasses_build_confirm(','glasses_handoff_to_expo(','glasses_vision_model_rollout_','UPDATE glasses_'] as $forbidden)
 v103_assert(!str_contains($source,$forbidden),'Health/diagnostics layer must remain read-only: '.$forbidden);

echo "glasses-v10-device-health-ok\n";
