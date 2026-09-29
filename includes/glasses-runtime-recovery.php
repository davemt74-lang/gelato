<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-device-health.php';

const GLASSES_RUNTIME_RECOVERY_SCHEMA='gelato.glasses_runtime_recovery.v1';

function glasses_runtime_recovery_policy(array $input=[]): array
{
    return [
      'schema'=>GLASSES_RUNTIME_RECOVERY_SCHEMA,
      'maxReconnectAttempts'=>max(1,min(20,(int)($input['maxReconnectAttempts']??8))),
      'baseRetryMs'=>max(250,min(10000,(int)($input['baseRetryMs']??1000))),
      'maxRetryMs'=>max(1000,min(60000,(int)($input['maxRetryMs']??10000))),
      'freshFrameBarrierMs'=>max(100,min(5000,(int)($input['freshFrameBarrierMs']??750))),
      'clearFrameQueueOnDisconnect'=>true,
      'clearTemporalTrackingOnDisconnect'=>true,
      'requireCanonicalWorkResync'=>true,
      'requireCanonicalBuildRehydrate'=>true,
      'suppressAutomaticObservationsUntilReady'=>true,
      'resumeExistingBuildOnly'=>true,
    ];
}

function glasses_runtime_recovery_decide(array $snapshot,array $policy=[]): array
{
    $p=glasses_runtime_recovery_policy($policy);
    $network=(string)($snapshot['networkState']??'online');
    $camera=(string)($snapshot['cameraState']??'ready');
    $inference=(string)($snapshot['inferenceState']??'ready');
    $build=(string)($snapshot['buildState']??'active');
    $kds=(string)($snapshot['kdsState']??'in_progress');
    $attempt=max(0,(int)($snapshot['reconnectAttempt']??0));

    $allowed=['online','offline','recovering'];
    if(!in_array($network,$allowed,true))throw new InvalidArgumentException('Recovery network state is invalid.');
    if(!in_array($camera,['ready','offline','recovering','unavailable'],true))throw new InvalidArgumentException('Recovery camera state is invalid.');
    if(!in_array($inference,['ready','offline','recovering','error'],true))throw new InvalidArgumentException('Recovery inference state is invalid.');

    $terminal=!in_array($kds,['queued','in_progress'],true)||!in_array($build,['active','none'],true);
    $state='ready';$reason='runtime_ready';$resume=false;
    if($terminal){$state='blocked';$reason='canonical_work_no_longer_resumable';}
    elseif($network!=='online'){$state='disconnected';$reason='network_unavailable';}
    elseif($camera!=='ready'){$state='recovering';$reason='camera_not_ready';}
    elseif($inference!=='ready'){$state='recovering';$reason='inference_not_ready';}
    elseif($build==='none'){$state='recovering';$reason='canonical_build_rehydrate_required';$resume=true;}

    $delay=min($p['maxRetryMs'],$p['baseRetryMs']*(2**min($attempt,6)));
    return [
      'schema'=>GLASSES_RUNTIME_RECOVERY_SCHEMA,
      'state'=>$state,
      'reason'=>$reason,
      'resumeBuild'=>$resume,
      'automaticObservationsAllowed'=>$state==='ready',
      'clearTransientVisionState'=>$state!=='ready',
      'retryAfterMs'=>$state==='ready'||$state==='blocked'?0:$delay,
      'policy'=>$p,
    ];
}

function glasses_runtime_recovery_contract(array $policy=[]): array
{
    return [
      'schema'=>GLASSES_RUNTIME_RECOVERY_SCHEMA,
      'states'=>['ready','disconnected','recovering','blocked'],
      'events'=>[
        'network_lost','network_restored','camera_lost','camera_restored',
        'inference_failed','inference_restored','app_resumed','canonical_work_resynced',
        'canonical_build_rehydrated','recovery_blocked'
      ],
      'policy'=>glasses_runtime_recovery_policy($policy),
      'canonicalResume'=>[
        'workSource'=>'glasses_current_work',
        'buildRehydrate'=>'build.get when session ID is known; build.start is idempotent for the same active KDS item/device',
        'observationIdempotency'=>'glasses_build_observe observation_key',
        'neverReplayQueuedFrames'=>true,
        'neverAutoHandoff'=>true,
      ],
    ];
}
