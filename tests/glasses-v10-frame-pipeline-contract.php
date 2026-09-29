<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-frame-pipeline.php';

function v102_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}

$p=glasses_frame_pipeline_policy();
v102_assert($p['schema']===GLASSES_FRAME_PIPELINE_SCHEMA,'Frame pipeline schema mismatch.');
v102_assert($p['maxQueue']===3&&$p['maxFrameAgeMs']===750&&$p['inferenceTimeoutMs']===1200,'Frame pipeline defaults must be production-safe.');
v102_assert($p['dropPolicy']==='drop_oldest','Default backpressure must prioritize newest camera evidence.');
v102_assert($p['preserveOrder']===true&&$p['singleInferenceWorker']===true,'Frame delivery must be ordered through one worker.');
v102_assert($p['cancelOnStop']===true&&$p['rejectStaleBeforeInference']===true&&$p['rejectStaleAfterInference']===true,'Cancellation and stale-frame gates must be enabled.');

$clamped=glasses_frame_pipeline_policy(['maxQueue'=>99,'maxFrameAgeMs'=>1,'inferenceTimeoutMs'=>99999,'dropPolicy'=>'drop_newest']);
v102_assert($clamped['maxQueue']===8&&$clamped['maxFrameAgeMs']===50&&$clamped['inferenceTimeoutMs']===10000,'Pipeline policy must clamp unsafe limits.');
v102_assert($clamped['dropPolicy']==='drop_newest','Explicit drop-newest policy must be supported.');

$bad=false;try{glasses_frame_pipeline_policy(['dropPolicy'=>'random']);}catch(InvalidArgumentException){$bad=true;}
v102_assert($bad,'Unknown frame-drop policies must be rejected.');

$contract=glasses_frame_pipeline_runtime_contract();
foreach(['acceptedFrames','processedFrames','droppedFrames','staleFrames','timedOutFrames','cancelledFrames','failedFrames','queueDepth','maxObservedQueueDepth','lastProcessedSequence'] as $metric)
 v102_assert(in_array($metric,$contract['metrics'],true),'Missing frame-pipeline metric '.$metric);
v102_assert(($contract['delivery']['target']??'')==='V9 temporal vision pipeline','Frame pipeline must explicitly deliver into V9.');
v102_assert(!empty($contract['delivery']['ordered'])&&!empty($contract['delivery']['duplicateSequenceRejected']),'Delivery must reject reordering/duplicates.');

$js=file_get_contents(__DIR__.'/../assets/js/glasses-web-simulator.js');
$page=file_get_contents(__DIR__.'/../glasses-simulator.php');
$sim=file_get_contents(__DIR__.'/../includes/glasses-simulator.php');
v102_assert(str_contains($js,'function enqueueVisionFrame('),'Simulator must have bounded frame enqueue.');
v102_assert(str_contains($js,'function processFrameQueue('),'Simulator must have a single queue worker.');
v102_assert(str_contains($js,'function captureVisionFrameSnapshot('),'Camera frames must be snapshotted before queueing.');
v102_assert(str_contains($js,"dropPolicy==='drop_newest'"),'Simulator must enforce configurable backpressure.');
v102_assert(str_contains($js,'FRAME_INFERENCE_TIMEOUT'),'Simulator must gate inference timeout.');
v102_assert(str_contains($js,'new AbortController()'),'Simulator must support logical inference cancellation.');
v102_assert(substr_count($js,'maxFrameAgeMs')>=3,'Simulator must check frame age before and after inference.');
v102_assert(str_contains($js,'frame.seq<=m.lastProcessedSequence'),'Simulator must reject duplicate/out-of-order delivery.');
v102_assert(str_contains($js,'m.droppedFrames++')&&str_contains($js,'m.staleFrames++')&&str_contains($js,'m.timedOutFrames++'),'Pipeline metrics must account for loss states.');
v102_assert(str_contains($js,'function injectFrameBurst(')&&str_contains($page,'Force timeout')&&str_contains($page,'Detector delay'),'Simulator must expose overload/fault controls.');
v102_assert(!str_contains($js,'async function runVisionFrame()'),'Legacy direct capture→inference loop must be removed.');
v102_assert(str_contains($sim,"hardware.frame_pipeline"),'Simulator dispatch must expose the frame-pipeline contract.');

$source=file_get_contents(__DIR__.'/../includes/glasses-frame-pipeline.php');
foreach(['kds_transition(','glasses_build_confirm(','glasses_handoff_to_expo(','glasses_vision_model_rollout_'] as $forbidden)
 v102_assert(!str_contains($source,$forbidden),'Frame pipeline policy must not mutate canonical authority: '.$forbidden);

echo "glasses-v10-frame-pipeline-ok\n";
