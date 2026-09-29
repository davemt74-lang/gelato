<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-runtime-recovery.php';

const GLASSES_RUNTIME_SOAK_SCHEMA='gelato.glasses_runtime_soak.v1';

function glasses_runtime_soak_policy(array $input=[]): array
{
    return [
      'schema'=>GLASSES_RUNTIME_SOAK_SCHEMA,
      'cycles'=>max(10,min(10000,(int)($input['cycles']??500))),
      'maxQueue'=>max(1,min(8,(int)($input['maxQueue']??3))),
      'maxQueueLeak'=>max(0,min(8,(int)($input['maxQueueLeak']??0))),
      'maxWorkerOverlap'=>0,
      'maxUnhandledErrors'=>0,
      'maxRecoveryFailures'=>max(0,min(100,(int)($input['maxRecoveryFailures']??0))),
      'maxStalledCycles'=>max(0,min(100,(int)($input['maxStalledCycles']??0))),
      'minRecoverySuccessRate'=>max(.5,min(1.0,(float)($input['minRecoverySuccessRate']??1.0))),
      'requireFinalReadyState'=>true,
      'requireEmptyQueue'=>true,
      'requireNoActiveWorker'=>true,
      'requireMonotonicDelivery'=>true,
    ];
}

function glasses_runtime_soak_evaluate(array $report,array $policy=[]): array
{
    $p=glasses_runtime_soak_policy($policy);
    $cycles=max(0,(int)($report['cycles']??0));
    $recoveryAttempts=max(0,(int)($report['recoveryAttempts']??0));
    $recoverySuccesses=max(0,(int)($report['recoverySuccesses']??0));
    $rate=$recoveryAttempts>0?$recoverySuccesses/$recoveryAttempts:1.0;
    $checks=[
      'cycles_completed'=>$cycles>=$p['cycles'],
      'queue_bounded'=>max(0,(int)($report['maxObservedQueueDepth']??0))<=$p['maxQueue'],
      'queue_drained'=>!$p['requireEmptyQueue']||(int)($report['finalQueueDepth']??0)<=$p['maxQueueLeak'],
      'worker_single'=>max(0,(int)($report['maxConcurrentWorkers']??0))<=1+$p['maxWorkerOverlap'],
      'worker_idle'=>!$p['requireNoActiveWorker']||empty($report['workerActive']),
      'no_unhandled_errors'=>max(0,(int)($report['unhandledErrors']??0))<=$p['maxUnhandledErrors'],
      'recovery_success_rate'=>$rate+0.000001>=$p['minRecoverySuccessRate'],
      'recovery_failures'=>max(0,(int)($report['recoveryFailures']??0))<=$p['maxRecoveryFailures'],
      'no_stalls'=>max(0,(int)($report['stalledCycles']??0))<=$p['maxStalledCycles'],
      'monotonic_delivery'=>!$p['requireMonotonicDelivery']||!empty($report['monotonicDelivery']),
      'final_ready'=>!$p['requireFinalReadyState']||(string)($report['finalRecoveryState']??'')==='ready',
    ];
    $passed=!in_array(false,$checks,true);
    return [
      'schema'=>GLASSES_RUNTIME_SOAK_SCHEMA,
      'passed'=>$passed,
      'score'=>round((array_sum(array_map(static fn($v)=>$v?1:0,$checks))/count($checks))*10,1),
      'checks'=>$checks,
      'recoverySuccessRate'=>round($rate,6),
      'policy'=>$p,
      'report'=>[
        'cycles'=>$cycles,
        'framesProduced'=>max(0,(int)($report['framesProduced']??0)),
        'framesProcessed'=>max(0,(int)($report['framesProcessed']??0)),
        'framesDropped'=>max(0,(int)($report['framesDropped']??0)),
        'framesStale'=>max(0,(int)($report['framesStale']??0)),
        'framesTimedOut'=>max(0,(int)($report['framesTimedOut']??0)),
        'recoveryAttempts'=>$recoveryAttempts,
        'recoverySuccesses'=>$recoverySuccesses,
        'recoveryFailures'=>max(0,(int)($report['recoveryFailures']??0)),
        'maxObservedQueueDepth'=>max(0,(int)($report['maxObservedQueueDepth']??0)),
        'maxConcurrentWorkers'=>max(0,(int)($report['maxConcurrentWorkers']??0)),
        'stalledCycles'=>max(0,(int)($report['stalledCycles']??0)),
        'unhandledErrors'=>max(0,(int)($report['unhandledErrors']??0)),
        'finalQueueDepth'=>max(0,(int)($report['finalQueueDepth']??0)),
        'workerActive'=>!empty($report['workerActive']),
        'monotonicDelivery'=>!empty($report['monotonicDelivery']),
        'finalRecoveryState'=>(string)($report['finalRecoveryState']??'unknown'),
      ],
    ];
}
