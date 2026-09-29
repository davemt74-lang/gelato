<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-hardware-runtime.php';

const GLASSES_FRAME_PIPELINE_SCHEMA='gelato.glasses_frame_pipeline.v1';

function glasses_frame_pipeline_policy(array $input=[]): array
{
    $maxQueue=max(1,min(8,(int)($input['maxQueue']??3)));
    $maxAge=max(50,min(5000,(int)($input['maxFrameAgeMs']??750)));
    $timeout=max(50,min(10000,(int)($input['inferenceTimeoutMs']??1200)));
    $drop=(string)($input['dropPolicy']??'drop_oldest');
    if(!in_array($drop,['drop_oldest','drop_newest'],true))throw new InvalidArgumentException('Frame drop policy is unsupported.');
    return [
      'schema'=>GLASSES_FRAME_PIPELINE_SCHEMA,
      'maxQueue'=>$maxQueue,
      'maxFrameAgeMs'=>$maxAge,
      'inferenceTimeoutMs'=>$timeout,
      'dropPolicy'=>$drop,
      'preserveOrder'=>true,
      'singleInferenceWorker'=>true,
      'cancelOnStop'=>true,
      'rejectStaleBeforeInference'=>true,
      'rejectStaleAfterInference'=>true,
    ];
}

function glasses_frame_pipeline_runtime_contract(array $policy=[]): array
{
    return [
      'schema'=>GLASSES_FRAME_PIPELINE_SCHEMA,
      'policy'=>glasses_frame_pipeline_policy($policy),
      'metrics'=>[
        'acceptedFrames','processedFrames','droppedFrames','staleFrames','timedOutFrames',
        'cancelledFrames','failedFrames','queueDepth','maxObservedQueueDepth','lastProcessedSequence',
      ],
      'delivery'=>[
        'target'=>'V9 temporal vision pipeline',
        'ordered'=>true,
        'duplicateSequenceRejected'=>true,
        'onlyCompletedInferenceDelivered'=>true,
      ],
      'faultInjection'=>[
        'simulatorOnly'=>true,
        'controls'=>['detectorDelayMs','forceTimeout','burstFrames'],
      ],
    ];
}
