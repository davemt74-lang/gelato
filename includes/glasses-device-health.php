<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-frame-pipeline.php';

const GLASSES_DEVICE_HEALTH_SCHEMA='gelato.glasses_device_health.v1';

function glasses_device_health_normalize(array $input): array
{
    $frame=is_array($input['framePipeline']??null)?$input['framePipeline']:[];
    $camera=is_array($input['camera']??null)?$input['camera']:[];
    $inference=is_array($input['inference']??null)?$input['inference']:[];
    $runtime=is_array($input['runtime']??null)?$input['runtime']:[];
    $model=is_array($input['model']??null)?$input['model']:[];
    $hardware=is_array($input['hardware']??null)?$input['hardware']:[];
    $errors=array_values(array_slice(array_filter((array)($input['errors']??[]),'is_array'),-50));

    $fps=max(0.0,min(240.0,(float)($frame['fps']??0)));
    $latency=max(0.0,min(60000.0,(float)($frame['latencyMs']??0)));
    $queue=max(0,min(100,(int)($frame['queueDepth']??0)));
    $dropped=max(0,(int)($frame['droppedFrames']??0));
    $stale=max(0,(int)($frame['staleFrames']??0));
    $timeouts=max(0,(int)($frame['timedOutFrames']??0));
    $failed=max(0,(int)($frame['failedFrames']??0));

    $cameraState=(string)($camera['state']??'unknown');
    $inferenceState=(string)($inference['state']??'unknown');
    $runtimeState=(string)($runtime['state']??'unknown');
    foreach([$cameraState,$inferenceState,$runtimeState] as $state){
        if(!preg_match('/^[a-z0-9_.-]{2,48}$/',$state))throw new InvalidArgumentException('Device health state is invalid.');
    }

    $battery=$hardware['batteryPercent']??null;
    if($battery!==null)$battery=max(0.0,min(100.0,(float)$battery));
    $thermal=$hardware['temperatureC']??null;
    if($thermal!==null)$thermal=max(-40.0,min(150.0,(float)$thermal));

    $degraded=$cameraState!=='ready'||$inferenceState==='error'||$runtimeState==='error'||$timeouts>0||$failed>0;
    $health=$degraded?'degraded':'healthy';

    return [
      'schema'=>GLASSES_DEVICE_HEALTH_SCHEMA,
      'health'=>$health,
      'capturedAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
      'camera'=>[
        'state'=>$cameraState,
        'source'=>$camera['source']??null,
        'width'=>isset($camera['width'])?(int)$camera['width']:null,
        'height'=>isset($camera['height'])?(int)$camera['height']:null,
      ],
      'framePipeline'=>[
        'fps'=>round($fps,2),'latencyMs'=>round($latency,2),'queueDepth'=>$queue,
        'acceptedFrames'=>max(0,(int)($frame['acceptedFrames']??0)),
        'processedFrames'=>max(0,(int)($frame['processedFrames']??0)),
        'droppedFrames'=>$dropped,'staleFrames'=>$stale,'timedOutFrames'=>$timeouts,
        'cancelledFrames'=>max(0,(int)($frame['cancelledFrames']??0)),'failedFrames'=>$failed,
        'maxObservedQueueDepth'=>max(0,(int)($frame['maxObservedQueueDepth']??0)),
        'lastProcessedSequence'=>max(0,(int)($frame['lastProcessedSequence']??0)),
      ],
      'inference'=>[
        'state'=>$inferenceState,
        'adapterId'=>$inference['adapterId']??null,
        'runtime'=>$inference['runtime']??null,
        'lastError'=>$inference['lastError']??null,
      ],
      'model'=>[
        'detectorName'=>$model['detectorName']??null,
        'packagePublicId'=>$model['packagePublicId']??null,
        'artifactSha256'=>$model['artifactSha256']??null,
        'status'=>$model['status']??null,
      ],
      'runtime'=>[
        'state'=>$runtimeState,
        'adapterId'=>$runtime['adapterId']??null,
        'reconnectCount'=>max(0,(int)($runtime['reconnectCount']??0)),
        'syncErrorCount'=>max(0,(int)($runtime['syncErrorCount']??0)),
      ],
      'hardware'=>[
        'batteryPercent'=>$battery,
        'temperatureC'=>$thermal,
        'batterySource'=>$hardware['batterySource']??'vendor_sdk_pending',
        'thermalSource'=>$hardware['thermalSource']??'vendor_sdk_pending',
      ],
      'errors'=>$errors,
    ];
}

function glasses_device_health_bundle(array $snapshot,array $extra=[]): array
{
    $health=glasses_device_health_normalize($snapshot);
    return [
      'schema'=>'gelato.glasses_diagnostic_bundle.v1',
      'generatedAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
      'health'=>$health,
      'context'=>[
        'devicePublicId'=>$extra['devicePublicId']??null,
        'stationPublicId'=>$extra['stationPublicId']??null,
        'buildSessionPublicId'=>$extra['buildSessionPublicId']??null,
        'kdsItemPublicId'=>$extra['kdsItemPublicId']??null,
        'simulator'=>!empty($extra['simulator']),
      ],
      'sdkBoundary'=>[
        'air3VendorTelemetryInstalled'=>false,
        'pending'=>['batteryPercent','temperatureC','nativeCameraHealth','nativeDisplayHealth'],
      ],
    ];
}
