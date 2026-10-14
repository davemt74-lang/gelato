<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$api=$read('api/glasses-admin.php');$device=$read('includes/glasses-core.php');
$rt=$read('includes/glasses-employee-runtimes.php');$sim=$read('api/glasses-simulator.php');
$page=$read('glasses-simulator.php');$fleet=$read('glasses-fleet.php');
$client=$read('assets/js/glasses-fleet-page.js');
$runtimeClient=$read('assets/js/glasses-employee-runtimes.js');
$sql=$read('database/20261215_glasses_runtime_connections.sql');
$check=static function(bool $ok,string $msg):void{if(!$ok){fwrite(STDERR,"FAIL Section 274: ".$msg."\n");exit(1);}};
$check(str_contains($api,"pairing.status"),'pair status route');
$check(str_contains($api,"created_by"),'pairing creator check');
$check(str_contains($device,"paired_device_id=?"),'receipt persisted');
$check(str_contains($rt,"profile_confirmed_at=COALESCE"),'profile confirmation');
$check(str_contains($rt,"employee_user_id"),'employee binding');
$check(str_contains($sim,"runtime.profile_confirm"),'profile confirm route');
$check(str_contains($client,"pairing.create"),'pairing UI');
$check(str_contains($runtimeClient,"runtime.profile_confirm"),'confirmation UI');
$check(str_contains($fleet,"fleetPairStart"),'pairing UI relocated to Fleet Management');
$check(!str_contains($page,'glassesConnectionManager'),'no redundant pairing card in Runtime');
$check(str_contains($sql,"paired_device_id"),'migration');

$check(str_contains($device,"heartbeat_age_seconds"),'database-calculated heartbeat age');
$check(str_contains($device,'heartbeat_age_seconds'),'server-calculated physical device age');
$check(str_contains($api,"pairing.cancel"),'cancel pairing endpoint');
$check(str_contains($client,"refreshDevices()"),'fleet refreshes inventory after successful pairing');

echo "PASS Section 274 connection manager contracts\n";
