<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-runtime-recovery.php';

function v104_assert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}

$p=glasses_runtime_recovery_policy();
v104_assert($p['schema']===GLASSES_RUNTIME_RECOVERY_SCHEMA,'Recovery schema mismatch.');
v104_assert($p['clearFrameQueueOnDisconnect']===true&&$p['clearTemporalTrackingOnDisconnect']===true,'Disconnect must invalidate transient vision state.');
v104_assert($p['requireCanonicalWorkResync']===true&&$p['requireCanonicalBuildRehydrate']===true,'Recovery must reload canonical truth.');
v104_assert($p['suppressAutomaticObservationsUntilReady']===true,'Automatic observations must remain blocked through recovery.');
v104_assert($p['resumeExistingBuildOnly']===true,'Recovery must never invent replacement build truth.');

$ready=glasses_runtime_recovery_decide([
 'networkState'=>'online','cameraState'=>'ready','inferenceState'=>'ready','buildState'=>'active','kdsState'=>'in_progress'
]);
v104_assert($ready['state']==='ready'&&$ready['automaticObservationsAllowed']===true,'Healthy runtime must be ready.');

$offline=glasses_runtime_recovery_decide([
 'networkState'=>'offline','cameraState'=>'ready','inferenceState'=>'ready','buildState'=>'active','kdsState'=>'in_progress','reconnectAttempt'=>2
]);
v104_assert($offline['state']==='disconnected'&&$offline['automaticObservationsAllowed']===false,'Offline runtime must block observations.');
v104_assert($offline['clearTransientVisionState']===true&&$offline['retryAfterMs']>0,'Offline runtime must clear transient vision and back off.');

$camera=glasses_runtime_recovery_decide([
 'networkState'=>'online','cameraState'=>'offline','inferenceState'=>'ready','buildState'=>'active','kdsState'=>'in_progress'
]);
v104_assert($camera['state']==='recovering'&&$camera['reason']==='camera_not_ready','Camera loss must enter recovery.');

$rehydrate=glasses_runtime_recovery_decide([
 'networkState'=>'online','cameraState'=>'ready','inferenceState'=>'ready','buildState'=>'none','kdsState'=>'queued'
]);
v104_assert($rehydrate['state']==='recovering'&&$rehydrate['resumeBuild']===true,'Missing browser build state must rehydrate canonical build.');

$blocked=glasses_runtime_recovery_decide([
 'networkState'=>'online','cameraState'=>'ready','inferenceState'=>'ready','buildState'=>'none','kdsState'=>'ready'
]);
v104_assert($blocked['state']==='blocked'&&$blocked['automaticObservationsAllowed']===false,'Completed kitchen work must not be recreated.');

$contract=glasses_runtime_recovery_contract();
v104_assert(($contract['canonicalResume']['neverReplayQueuedFrames']??false)===true,'Recovery must never replay queued pre-disconnect frames.');
v104_assert(($contract['canonicalResume']['neverAutoHandoff']??false)===true,'Recovery must never auto-handoff.');
v104_assert(str_contains($contract['canonicalResume']['buildRehydrate'],'build.start is idempotent'),'Contract must document canonical idempotent build resume.');

$source=file_get_contents(__DIR__.'/../includes/glasses-runtime-recovery.php');
$sim=file_get_contents(__DIR__.'/../includes/glasses-simulator.php');
$js=file_get_contents(__DIR__.'/../assets/js/glasses-web-simulator.js');
$page=file_get_contents(__DIR__.'/../glasses-simulator.php');

v104_assert(str_contains($sim,"hardware.recovery.contract")&&str_contains($sim,"hardware.recovery.decide"),'Simulator must expose recovery contract actions.');
v104_assert(str_contains($js,'function invalidateTransientVision('),'Runtime recovery must clear transient vision state.');
v104_assert(str_contains($js,'state.framePipeline.queue=[]'),'Recovery must clear frame queue.');
v104_assert(str_contains($js,'clearTemporalRuntime()'),'Recovery must clear temporal tracking.');
v104_assert(str_contains($js,'function rehydrateCanonicalBuild('),'Runtime must rehydrate canonical build.');
v104_assert(str_contains($js,"api('build.get'"),'Known build session must resume through canonical build.get.');
v104_assert(str_contains($js,"api('build.start'"),'Unknown local session may use idempotent canonical build.start.');
v104_assert(str_contains($js,'RUNTIME_FRESH_BARRIER_MS'),'Recovery must require a fresh-frame barrier.');
v104_assert(str_contains($js,'if(!automaticObservationsAllowed())return false;'),'Automatic observations must be gated during recovery.');
v104_assert(str_contains($js,"window.addEventListener('offline'"),'Network loss must enter recovery.');
v104_assert(str_contains($js,"window.addEventListener('online'"),'Network restoration must attempt recovery.');
v104_assert(str_contains($js,"'camera_lost'"),'Camera loss must enter recovery.');
v104_assert(str_contains($js,"'app_resumed'"),'App resume must attempt canonical recovery.');
v104_assert(str_contains($js,'saveRecoveryResume()'),'Browser must persist resumable identifiers.');
v104_assert(str_contains($page,'V10 RUNTIME RECOVERY')&&str_contains($page,'Simulate Network Loss'),'Simulator must expose recovery status/fault controls.');

foreach(['kds_transition(','glasses_handoff_to_expo(','glasses_build_confirm(','UPDATE glasses_'] as $forbidden)
 v104_assert(!str_contains($source,$forbidden),'Recovery contract must not mutate canonical kitchen state: '.$forbidden);

echo "glasses-v10-runtime-recovery-ok\n";
