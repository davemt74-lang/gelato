<?php
declare(strict_types=1);
/* Static invariants for existing Vision Lab/V11 runtime integration.
 * Database transactional acceptance is a separate pre-production gate. */
$root=dirname(__DIR__);
function runtimeCheck(bool $ok,string $message):void {
  if(!$ok){fwrite(STDERR,"FAIL glasses employee runtime: ".$message."\n");exit(1);}
}
$php=(string)file_get_contents($root.'/includes/glasses-employee-runtimes.php');
$api=(string)file_get_contents($root.'/api/glasses-simulator.php');
$dispatcher=(string)file_get_contents($root.'/includes/glasses-simulator.php');
$client=(string)file_get_contents($root.'/assets/js/glasses-employee-runtimes.js');
$web=(string)file_get_contents($root.'/assets/js/glasses-web-simulator.js');
$sql=(string)file_get_contents($root.'/database/20261214_glasses_employee_runtimes.sql');
runtimeCheck(str_contains($sql,'UNIQUE KEY uq_glasses_runtime_live_device'),'one active runtime per physical glasses');
runtimeCheck(str_contains($sql,'UNIQUE KEY uq_glasses_runtime_live_employee'),'one active runtime per employee');
runtimeCheck(str_contains($sql,'wearer_assignment_id'),'reuse canonical Vision Lab wearer records');
runtimeCheck(str_contains($php,"require_once __DIR__.'/glasses-vision-lab.php'"),'must reuse Vision Lab wearable assignment history');
runtimeCheck(str_contains($php,"require_once __DIR__.'/glasses-training-identity.php'"),'must use V11 training programs and sessions');
runtimeCheck(str_contains($php,"glasses_vision_lab_assign_device("),'must attach runtime to existing Vision Lab device identity');
runtimeCheck(str_contains($php,"glasses_training_session_start("),'training starts through canonical sessions');
runtimeCheck(str_contains($php,"glasses_training_session_end("),'training ends through canonical sessions');
runtimeCheck(str_contains($php,"SELECT r.public_id,r.device_id,r.employee_user_id"),'runtime catalog includes device ID for training assignment filtering');
runtimeCheck(str_contains($php,"'deviceId'=>(int)$"."row['device_id']"),'public runtime carries device ID');
runtimeCheck(str_contains($php,"a.location_id,a.station_id"),'training catalog includes location and station assignment');
runtimeCheck(str_contains($php,"$"."visibleEmployees"),'training sessions restricted to visible runtime employees');
runtimeCheck(str_contains($php,"$"."visibleDeviceIds"),'training sessions restricted to visible runtime devices');
runtimeCheck(str_contains($php,"om.status='active'"),'inactive employees excluded from runtime catalog');
runtimeCheck(str_contains($php,"a.released_at IS NULL"),'released wearer identity must not remain active');
runtimeCheck(str_contains($php,"Runtime is assigned to a different glasses device"),'server enforces device/runtime identity match');
runtimeCheck(str_contains($php,"glasses.manage"),'runtime create and end require management permission');
runtimeCheck(str_contains($php,"operational_location_allowed("),'location access checked server-side');
runtimeCheck(str_contains($dispatcher,"glasses_employee_runtime_assert_binding("),'simulator API uses runtime-device binding');
runtimeCheck(str_contains($api,"runtime.training_start") && str_contains($api,"runtime.training_end"),'training API uses canonical backend');
runtimeCheck(str_contains($client,"a.locationId==null||a.locationId===chosen.locationId"),'client filters programs by location');
runtimeCheck(str_contains($client,"a.stationId==null||a.stationId===chosen.stationId"),'client filters programs by station');
runtimeCheck(str_contains($web,"RUNTIME_STORAGE_SCOPE"),'camera and recovery state scoped to each runtime');
runtimeCheck(str_contains($client,"gelato-glasses-runtime-revoked"),'runtime revocation reaches simulator');
echo "glasses-employee-runtimes-contract=ok\n";
