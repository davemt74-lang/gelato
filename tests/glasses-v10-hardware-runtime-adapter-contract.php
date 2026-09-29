<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-hardware-runtime.php';

function v101_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$adapter=glasses_hardware_runtime_adapter('simulator.v1');
v101_assert($adapter instanceof GlassesHardwareRuntimeAdapter,'Simulator must implement the production hardware runtime interface.');
v101_assert($adapter->adapterId()==='simulator.v1','Simulator adapter ID must be stable.');
v101_assert($adapter->platform()==='browser_simulator','Simulator platform must be explicit.');

$contract=glasses_hardware_runtime_contract();
v101_assert(($contract['schema']??'')===GLASSES_HARDWARE_RUNTIME_SCHEMA,'Hardware runtime schema mismatch.');
v101_assert(($contract['sdkBoundary']['vendorAdapterInstalled']??true)===false,'Vendor AIR3 adapter must remain explicitly unavailable without SDK files.');
v101_assert(($contract['sdkBoundary']['requiredAdapterId']??'')==='air3.vendor.v1','Future AIR3 adapter identity must be locked.');
foreach(['native_camera','native_display','native_input','vendor_inference'] as $blocked)
    v101_assert(in_array($blocked,$contract['sdkBoundary']['blockedBySdk']??[],true),'Missing SDK-blocked capability '.$blocked);
foreach(['v9_kitchen_intelligence','simulator','browser_camera','governed_onnx','pos_kds_sync'] as $available)
    v101_assert(in_array($available,$contract['sdkBoundary']['notBlockedBySdk']??[],true),'Missing non-SDK capability '.$available);

$cap=$adapter->capabilities();
foreach(['camera','display','input','inference','transport'] as $surface)
    v101_assert(isset($cap[$surface]),'Hardware contract missing '.$surface.' surface.');
v101_assert(!empty($cap['camera']['available'])&&!empty($cap['display']['available'])&&!empty($cap['input']['available'])&&!empty($cap['inference']['available']),'Simulator must implement all four hardware surfaces.');
v101_assert(($cap['inference']['vendorRuntimeAvailable']??true)===false,'Simulator must not pretend the proprietary runtime is available.');
v101_assert(in_array('onnx',$cap['inference']['runtimes']??[],true),'Governed ONNX must remain available without vendor SDK.');

$frame=$adapter->normalizeFrame([
 'frameKey'=>'frame-001','source'=>'browser_camera','width'=>1280,'height'=>720,'pixelFormat'=>'rgba8',
 'capturedAt'=>'2026-09-29T13:00:00Z',
 'detections'=>[
   ['label'=>'Cheese','confidence'=>.94,'bbox'=>[.1,.2,.3,.4],'attributes'=>['componentKey'=>'ingredient:1']],
   ['label'=>'bad','confidence'=>2,'bbox'=>[0,0,1,1]],
 ]
]);
v101_assert($frame['schema']==='gelato.glasses_frame.v1'&&$frame['adapterId']==='simulator.v1','Frame envelope must bind the simulator adapter.');
v101_assert(count($frame['detections'])===1&&$frame['detections'][0]['label']==='Cheese','Frame normalization must retain only valid detections.');

$bad=false;try{$adapter->normalizeFrame(['frameKey'=>'x','width'=>10,'height'=>10]);}catch(InvalidArgumentException){$bad=true;}
v101_assert($bad,'Invalid frame geometry must be rejected.');

$display=$adapter->normalizeDisplay(['surface'=>'right_lens','elements'=>[['type'=>'text','value'=>'NEXT']]]);
v101_assert($display['schema']==='gelato.glasses_display.v1'&&$display['surface']==='right_lens','Display envelope must normalize projection output.');

$input=$adapter->normalizeInput(['action'=>'confirm','value'=>'ready']);
v101_assert($input['schema']==='gelato.glasses_input.v1'&&$input['action']==='confirm','Input envelope must normalize explicit input.');

$fixture=$adapter->normalizeModelLoad(['runtime'=>'fixture','detectorName'=>'food-detector']);
v101_assert($fixture['runtime']==='fixture'&&$fixture['vendorSdkRequired']===false,'Fixture runtime must not require vendor SDK.');

$sha=hash('sha256','model');
$onnx=$adapter->normalizeModelLoad(['runtime'=>'onnx','detectorName'=>'food-detector','packagePublicId'=>'vision-model-test','artifactSha256'=>$sha]);
v101_assert($onnx['runtime']==='onnx'&&$onnx['artifactSha256']===$sha,'ONNX runtime must preserve governed artifact identity.');

$vendorRejected=false;
try{$adapter->normalizeModelLoad(['runtime'=>'vendor','detectorName'=>'food-detector']);}catch(InvalidArgumentException){$vendorRejected=true;}
v101_assert($vendorRejected,'Vendor inference must remain unavailable until the SDK adapter is installed.');

$sim=file_get_contents(__DIR__.'/../includes/glasses-simulator.php');
$api=file_get_contents(__DIR__.'/../api/glasses-simulator.php');
$page=file_get_contents(__DIR__.'/../glasses-simulator.php');
$js=file_get_contents(__DIR__.'/../assets/js/glasses-web-simulator.js');
v101_assert(str_contains($sim,"hardware.runtime")&&str_contains($sim,"hardware.frame.prepare"),'Simulator dispatch must expose hardware runtime and frame contract actions.');
v101_assert(str_contains($api,"hardwareRuntime"),'Simulator GET API must expose hardware runtime capabilities.');
v101_assert(str_contains($page,'V10 HARDWARE RUNTIME'),'Simulator UI must expose V10 hardware boundary status.');
v101_assert(str_contains($js,'renderHardwareRuntime'),'Simulator UI runtime must render hardware capabilities.');

$source=file_get_contents(__DIR__.'/../includes/glasses-hardware-runtime.php');
foreach(['kds_transition(','glasses_build_confirm(','glasses_handoff_to_expo(','glasses_vision_model_rollout_rollback'] as $forbidden)
    v101_assert(!str_contains($source,$forbidden),'Hardware adapter contract must not mutate kitchen/model authority: '.$forbidden);
v101_assert(!str_contains($source,'AIR3SDK')&&!str_contains($source,'InmoSdk')&&!str_contains($source,'vendor_model_loader'),'Section 1 must not invent proprietary SDK APIs.');

echo "glasses-v10-hardware-runtime-adapter-ok\n";
