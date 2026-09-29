<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-runtime-soak.php';

function v105_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}

$p=glasses_runtime_soak_policy();
v105_assert($p['schema']===GLASSES_RUNTIME_SOAK_SCHEMA,'Soak schema mismatch.');
v105_assert($p['cycles']===500&&$p['maxQueue']===3,'Soak defaults must target a meaningful bounded run.');
v105_assert($p['maxWorkerOverlap']===0&&$p['maxUnhandledErrors']===0&&$p['maxRecoveryFailures']===0,'Release soak must tolerate no worker overlap/unhandled/recovery failure.');
v105_assert($p['minRecoverySuccessRate']===1.0,'Release soak must require 100% recovery success.');
v105_assert($p['requireFinalReadyState']&&$p['requireEmptyQueue']&&$p['requireNoActiveWorker']&&$p['requireMonotonicDelivery'],'Release soak must enforce clean final state.');

$pass=glasses_runtime_soak_evaluate([
 'cycles'=>500,'framesProduced'=>620,'framesProcessed'=>480,'framesDropped'=>110,'framesStale'=>10,'framesTimedOut'=>20,
 'recoveryAttempts'=>18,'recoverySuccesses'=>18,'recoveryFailures'=>0,'maxObservedQueueDepth'=>3,'maxConcurrentWorkers'=>1,
 'stalledCycles'=>0,'unhandledErrors'=>0,'finalQueueDepth'=>0,'workerActive'=>false,'monotonicDelivery'=>true,'finalRecoveryState'=>'ready'
]);
v105_assert($pass['passed']===true&&$pass['score']===10.0,'Healthy soak report must score 10/10.');

$bad=glasses_runtime_soak_evaluate([
 'cycles'=>500,'recoveryAttempts'=>10,'recoverySuccesses'=>8,'recoveryFailures'=>2,
 'maxObservedQueueDepth'=>7,'maxConcurrentWorkers'=>2,'stalledCycles'=>1,'unhandledErrors'=>1,
 'finalQueueDepth'=>2,'workerActive'=>true,'monotonicDelivery'=>false,'finalRecoveryState'=>'recovering'
]);
v105_assert($bad['passed']===false&&$bad['score']<10,'Broken soak report must fail.');
foreach(['queue_bounded','queue_drained','worker_single','worker_idle','no_unhandled_errors','recovery_success_rate','recovery_failures','no_stalls','monotonic_delivery','final_ready'] as $check)
 v105_assert(array_key_exists($check,$bad['checks']),'Missing soak acceptance gate '.$check);

$source=file_get_contents(__DIR__.'/../includes/glasses-runtime-soak.php');
$sim=file_get_contents(__DIR__.'/../includes/glasses-simulator.php');
$js=file_get_contents(__DIR__.'/../assets/js/glasses-web-simulator.js');
$harness=file_get_contents(__DIR__.'/../assets/js/glasses-v10-soak-harness.js');
$page=file_get_contents(__DIR__.'/../glasses-simulator.php');

v105_assert(str_contains($sim,"hardware.soak.evaluate"),'Simulator must expose read-only soak evaluation.');
v105_assert(str_contains($js,'function runStationSoak(')&&str_contains($js,'function downloadStationSoakReport('),'Simulator must run/export soak acceptance.');
v105_assert(str_contains($page,'V10 STATION SOAK')&&str_contains($page,'Run Soak Acceptance'),'Simulator UI must expose station soak.');
v105_assert(str_contains($harness,'maxConcurrentWorkers')&&str_contains($harness,'monotonicDelivery'),'Harness must measure worker overlap and delivery ordering.');
v105_assert(!str_contains($harness,'build.observe')&&!str_contains($harness,'handoff.expo'),'Soak harness must remain non-consequential.');

foreach(['kds_transition(','glasses_build_confirm(','glasses_handoff_to_expo(','UPDATE glasses_'] as $forbidden)
 v105_assert(!str_contains($source,$forbidden),'Soak evaluator must be read-only: '.$forbidden);

echo "glasses-v10-station-soak-ok\n";
